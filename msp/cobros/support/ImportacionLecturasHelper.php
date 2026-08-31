<?php
declare(strict_types=1);

function omCellToString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function omFindColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $headers)) {
            return (int) $headers[$alias];
        }
    }

    return null;
}

function omFindColumnPrefix(array $headers, array $prefixes): ?int
{
    foreach ($headers as $key => $index) {
        foreach ($prefixes as $prefix) {
            if ($key === $prefix || str_starts_with((string) $key, $prefix . '_')) {
                return (int) $index;
            }
        }
    }

    return null;
}

function omNormalizeCode(string $value): string
{
    $normalized = mb_strtoupper(trim($value), 'UTF-8');
    $normalized = str_replace([' ', "\t", "\n", "\r"], '', $normalized);
    $normalized = strtr($normalized, [
        'Á' => 'A',
        'É' => 'E',
        'Í' => 'I',
        'Ó' => 'O',
        'Ú' => 'U',
        'Ü' => 'U',
        'Ñ' => 'N',
    ]);

    return $normalized;
}

function omParseSpreadsheetDate(mixed $value, string $fallback): array
{
    $raw = omCellToString($value);
    if ($raw === '') {
        return [true, $fallback];
    }

    if (is_numeric($raw)) {
        try {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
            return [true, $date->format('Y-m-d')];
        } catch (Throwable $e) {
            return [false, null];
        }
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'd.m.Y'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $raw);
        if ($parsed !== false && $parsed->format($format) === $raw) {
            return [true, $parsed->format('Y-m-d')];
        }
    }

    return [false, null];
}

function omPreviewSessionKey(string $periodoYm, string $codigoServicio): string
{
    return $periodoYm . '|' . strtoupper($codigoServicio);
}

function omPreviewSessionRead(string $periodoYm, string $codigoServicio): ?array
{
    $store = $_SESSION['msp2_import_preview_files'] ?? null;
    if (!is_array($store)) {
        return null;
    }

    $key = omPreviewSessionKey($periodoYm, $codigoServicio);
    $entry = $store[$key] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $path = (string) ($entry['path'] ?? '');
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $json = @file_get_contents($path);
    if ($json === false || $json === '') {
        return null;
    }

    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

function omPreviewSessionClear(string $periodoYm, string $codigoServicio): void
{
    $store = $_SESSION['msp2_import_preview_files'] ?? null;
    if (!is_array($store)) {
        return;
    }

    $key = omPreviewSessionKey($periodoYm, $codigoServicio);
    $entry = $store[$key] ?? null;
    if (is_array($entry)) {
        $path = (string) ($entry['path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    unset($store[$key]);
    $_SESSION['msp2_import_preview_files'] = $store;
}

function omPreviewSessionWrite(string $periodoYm, string $codigoServicio, array $payload): void
{
    omPreviewSessionClear($periodoYm, $codigoServicio);

    $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_preview_');
    if ($tmpFile === false) {
        throw new RuntimeException('No fue posible crear almacenamiento temporal de previsualización.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        @unlink($tmpFile);
        throw new RuntimeException('No fue posible serializar la previsualización.');
    }

    if (@file_put_contents($tmpFile, $json) === false) {
        @unlink($tmpFile);
        throw new RuntimeException('No fue posible guardar la previsualización temporal.');
    }

    $store = $_SESSION['msp2_import_preview_files'] ?? [];
    if (!is_array($store)) {
        $store = [];
    }

    $key = omPreviewSessionKey($periodoYm, $codigoServicio);
    $store[$key] = [
        'path' => $tmpFile,
        'created_at' => time(),
    ];
    $_SESSION['msp2_import_preview_files'] = $store;
}

function omSelectedServicesFromPost(array $allowedCodes): array
{
    $raw = $_POST['servicios'] ?? null;
    $strictSelection = isset($_POST['servicios_presentes']) && (string) $_POST['servicios_presentes'] === '1';

    if ($raw === null) {
        return $strictSelection ? [] : $allowedCodes;
    }

    $list = is_array($raw) ? $raw : [$raw];
    $selectedMap = [];
    foreach ($list as $item) {
        $code = strtoupper(trim((string) $item));
        if (in_array($code, $allowedCodes, true)) {
            $selectedMap[$code] = true;
        }
    }

    $ordered = [];
    foreach ($allowedCodes as $code) {
        if (isset($selectedMap[$code])) {
            $ordered[] = $code;
        }
    }

    return $ordered;
}
