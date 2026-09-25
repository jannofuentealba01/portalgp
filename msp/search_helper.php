<?php
declare(strict_types=1);

/**
 * Normaliza una consulta visible sin alterar su contenido significativo.
 */
function msp2SearchQuery(mixed $value, int $maxLength = 200): string
{
    $query = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    if (mb_strlen($query, 'UTF-8') > $maxLength) {
        $query = mb_substr($query, 0, $maxLength, 'UTF-8');
    }
    return $query;
}

/**
 * Separa palabras, conserva el orden ingresado y limita la complejidad SQL.
 *
 * @return list<string>
 */
function msp2SearchTokens(string $query, int $maxTokens = 10): array
{
    $query = msp2SearchQuery($query);
    if ($query === '') {
        return [];
    }

    $tokens = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_slice($tokens, 0, max(1, $maxTokens)));
}

function msp2SearchEscapeLike(string $value): string
{
    return str_replace(['~', '%', '_', '['], ['~~', '~%', '~_', '~['], $value);
}

/**
 * Construye una condición parametrizada en la que todas las palabras deben
 * aparecer, aunque puedan hacerlo en columnas distintas y en cualquier orden.
 * Las expresiones de campo son constantes definidas por el código, nunca datos
 * enviados por el usuario.
 *
 * @param list<string> $fieldExpressions
 * @return array{sql:string,params:array<string,int|string>,tokens:list<string>,exact_id:?int}
 */
function msp2BuildSearchCondition(
    string $query,
    array $fieldExpressions,
    string $parameterPrefix = 'msp_search',
    ?string $exactIdExpression = null
): array {
    $query = msp2SearchQuery($query);
    $parameterPrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $parameterPrefix) ?: 'msp_search';

    if ($exactIdExpression !== null && preg_match('/^#\s*([1-9][0-9]*)$/D', $query, $match) === 1) {
        $parameter = ':' . $parameterPrefix . '_exact_id';
        $exactSql = str_contains($exactIdExpression, '{{id}}')
            ? str_replace('{{id}}', $parameter, $exactIdExpression)
            : $exactIdExpression . ' = ' . $parameter;
        return [
            'sql' => $exactSql,
            'params' => [$parameter => (int) $match[1]],
            'tokens' => [],
            'exact_id' => (int) $match[1],
        ];
    }

    $fields = array_values(array_filter(array_map(
        static fn(mixed $field): string => trim((string) $field),
        $fieldExpressions
    ), static fn(string $field): bool => $field !== ''));
    $tokens = msp2SearchTokens($query);
    if ($tokens === [] || $fields === []) {
        return ['sql' => '1=1', 'params' => [], 'tokens' => $tokens, 'exact_id' => null];
    }

    $tokenConditions = [];
    $params = [];
    foreach ($tokens as $index => $token) {
        $fieldConditions = [];
        foreach ($fields as $fieldIndex => $field) {
            $parameter = ':' . $parameterPrefix . '_' . $index . '_' . $fieldIndex;
            $fieldConditions[] = "ISNULL(CONVERT(NVARCHAR(MAX), {$field}),N'') COLLATE Modern_Spanish_CI_AI LIKE {$parameter} ESCAPE N'~'";
            $params[$parameter] = '%' . msp2SearchEscapeLike($token) . '%';
        }
        $tokenConditions[] = '(' . implode(' OR ', $fieldConditions) . ')';
    }

    return [
        'sql' => '(' . implode(' AND ', $tokenConditions) . ')',
        'params' => $params,
        'tokens' => $tokens,
        'exact_id' => null,
    ];
}

function msp2SearchNormalizeComparable(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = is_string($ascii) ? strtolower($ascii) : $value;
    $value = str_replace(['.', '-'], '', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

/** @param list<string> $fields */
function msp2SearchRelevance(string $query, array $fields): int
{
    $needle = msp2SearchNormalizeComparable($query);
    if ($needle === '') {
        return 0;
    }
    $normalizedFields = array_map(
        static fn(string $field): string => msp2SearchNormalizeComparable($field),
        $fields
    );
    foreach ($normalizedFields as $field) {
        if ($field === $needle) {
            return 0;
        }
    }
    foreach ($normalizedFields as $field) {
        if (str_starts_with($field, $needle)) {
            return 1;
        }
    }

    $tokens = msp2SearchTokens($needle);
    $combined = implode(' ', $normalizedFields);
    $allAtWordStart = $tokens !== [];
    foreach ($tokens as $token) {
        if (preg_match('/(?:^|\s)' . preg_quote($token, '/') . '/', $combined) !== 1) {
            $allAtWordStart = false;
            break;
        }
    }
    if ($allAtWordStart) {
        return 2;
    }
    foreach ($tokens as $token) {
        if (!str_contains($combined, $token)) {
            return 4;
        }
    }
    return 3;
}

function msp2RenderSearchAssets(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    echo '<script' . pgpCspNonceAttribute() . ' src="/portalgp/msp/assets/search.js"></script>';
}
