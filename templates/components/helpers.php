<?php
declare(strict_types=1);

if (!function_exists('gpComponentEscape')) {
    function gpComponentEscape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('gpComponentAttrs')) {
    /**
     * @param array<string, mixed> $attrs
     */
    function gpComponentAttrs(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $name => $value) {
            $attr = trim((string) $name);
            if ($attr === '' || $value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = $attr;
                continue;
            }

            $parts[] = $attr . '="' . gpComponentEscape($value) . '"';
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }
}

if (!function_exists('gpComponentVariant')) {
    function gpComponentVariant(string $type, string $fallback = 'info'): string
    {
        return match (strtolower(trim($type))) {
            'success' => 'success',
            'error', 'danger' => 'danger',
            'warning' => 'warning',
            'primary' => 'primary',
            'secondary' => 'secondary',
            'info' => 'info',
            default => $fallback,
        };
    }
}

if (!function_exists('gpComponentIconForVariant')) {
    function gpComponentIconForVariant(string $type): string
    {
        return match (strtolower(trim($type))) {
            'success' => 'bi-check-circle-fill',
            'error', 'danger' => 'bi-x-octagon-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            default => 'bi-info-circle-fill',
        };
    }
}
