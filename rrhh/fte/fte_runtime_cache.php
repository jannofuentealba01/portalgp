<?php
declare(strict_types=1);

/** Short-lived source cache, never a replacement for an approved monthly snapshot. */
function fte_runtime_cache_now(array $config): int
{
    return isset($config['_runtime_cache_clock']) && is_callable($config['_runtime_cache_clock'])
        ? (int)$config['_runtime_cache_clock']() : time();
}

function fte_runtime_cache_directory(array $config): ?string
{
    if (empty($config['runtime_cache_enabled'])) {
        return null;
    }
    $dir = trim((string)($config['runtime_cache_storage_dir'] ?? ''));
    if ($dir === '') {
        $dir = PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/portal_data/fte/runtime-cache'
            : sys_get_temp_dir() . '/portalgp-fte-runtime-cache';
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $resolved = realpath($dir);
    if ($resolved === false) {
        return null;
    }
    $normalized = strtolower(str_replace('\\', '/', $resolved));
    foreach ([dirname(__DIR__, 2), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $root) {
        if ($root === '') {
            continue;
        }
        $realRoot = realpath($root);
        if ($realRoot !== false) {
            $public = rtrim(strtolower(str_replace('\\', '/', $realRoot)), '/');
            if ($normalized === $public || str_starts_with($normalized, $public . '/')) {
                return null; // Refuse to put personnel data in a public checkout.
            }
        }
    }
    return $resolved;
}

function fte_runtime_cache_read(array $config, string $key, int $ttl): ?array
{
    if ($ttl <= 0 || (!empty($config['_refresh_buk_cache']) && str_starts_with($key, 'buk:'))) {
        return null;
    }
    $dir = fte_runtime_cache_directory($config);
    if ($dir === null) {
        return null;
    }
    $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $size = @filesize($path);
    if ($size === false || $size > 32 * 1024 * 1024) {
        return null;
    }
    $raw = @file_get_contents($path);
    $entry = is_string($raw) ? json_decode($raw, true) : null;
    $now = fte_runtime_cache_now($config);
    if (!is_array($entry) || ($entry['version'] ?? null) !== 1
        || !hash_equals(hash('sha256', $key), (string)($entry['key'] ?? ''))
        || !is_array($entry['data'] ?? null) || (int)($entry['created_at'] ?? 0) > $now
        || min((int)($entry['expires_at'] ?? 0), (int)($entry['created_at'] ?? 0) + $ttl) <= $now) {
        return null; // Expired/corrupt caches are never a stale fallback.
    }
    $entry['expires_at'] = min((int)$entry['expires_at'], (int)$entry['created_at'] + $ttl);
    return $entry;
}

function fte_runtime_cache_write(array $config, string $key, array $data, int $ttl, ?int $createdAt = null): bool
{
    $createdAt ??= fte_runtime_cache_now($config);
    if ($ttl <= 0 || $createdAt + $ttl <= fte_runtime_cache_now($config)) {
        return false;
    }
    $dir = fte_runtime_cache_directory($config);
    if ($dir === null) {
        return false;
    }
    $payload = json_encode(['version' => 1, 'key' => hash('sha256', $key),
        'created_at' => $createdAt, 'expires_at' => $createdAt + $ttl, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($payload) || strlen($payload) > 32 * 1024 * 1024) {
        return false;
    }
    $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true; // Only the atomic write is locked, never a provider request.
}

function fte_buk_cache_scope(array $config): string
{
    return hash('sha256', rtrim((string)($config['buk_base_url'] ?? ''), '/') . '|'
        . (string)($config['buk_country'] ?? '') . '|' . (string)($config['buk_token'] ?? ''));
}

function fte_geo_unmatched_cache_key(array $config, string $identifier): string
{
    return 'geo:unmatched:' . fte_geovictoria_cache_scope($config) . '|' . hash('sha256', $identifier);
}

function fte_geovictoria_cache_scope(array $config): string
{
    return hash('sha256', (string)($config['geovictoria_base_url'] ?? '') . '|'
        . (string)($config['geovictoria_user'] ?? '') . '|' . (string)($config['geovictoria_password'] ?? ''));
}

/** Called only after permissions (and CSRF for writes) have been checked. */
function fte_release_session_for_external_work(array &$config): void
{
    if (empty($config['release_session_during_queries'])) {
        return;
    }
    $started = hrtime(true);
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new RuntimeException('No fue posible liberar la sesion antes de consultar fuentes FTE.');
    }
    $config['_fte_session_detached'] = true;
    // $_SESSION remains a request-local copy. Never reopen or write back the
    // whole session: another request may have logged out or changed it.
    fte_performance_phase($config, 'session_lock_release', $started);
}
