<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

function rpcPagoContratoImportPreviewSessionKey(): string
{
    return 'msp2_rpc_import_preview';
}

function rpcPagoContratoImportIsAdminUser(PDO $conn): bool
{
    $usuario = $_SESSION['usuario'] ?? null;
    if (!is_array($usuario)) {
        return false;
    }

    $isMainAdminRoleName = static function (string $roleName): bool {
        return $roleName === 'administrador';
    };

    foreach (['rol', 'role', 'nombre_rol'] as $key) {
        $roleName = mb_strtolower(trim((string) ($usuario[$key] ?? '')), 'UTF-8');
        if ($roleName !== '' && $isMainAdminRoleName($roleName)) {
            return true;
        }
    }

    $sessionRoles = $usuario['roles'] ?? null;
    if (is_array($sessionRoles)) {
        foreach ($sessionRoles as $role) {
            $roleName = mb_strtolower(trim((string) $role), 'UTF-8');
            if ($roleName !== '' && $isMainAdminRoleName($roleName)) {
                return true;
            }
        }
    }

    foreach (['rol_id', 'id_rol'] as $key) {
        if ((int) ($usuario[$key] ?? 0) === 1) {
            return true;
        }
    }

    $idUsuario = (int) ($usuario['id'] ?? 0);
    if ($idUsuario <= 0 || !msp2TableExists($conn, 'cr_usuarios') || !msp2TableExists($conn, 'cr_roles')) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT TOP 1
                u.rol_id,
                r.nombre_rol
             FROM dbo.cr_usuarios u
             LEFT JOIN dbo.cr_roles r
                ON r.id = u.rol_id
             WHERE u.id = :id_usuario"
        );
        $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return false;
        }

        if ((int) ($row['rol_id'] ?? 0) === 1) {
            return true;
        }

        $roleName = mb_strtolower(trim((string) ($row['nombre_rol'] ?? '')), 'UTF-8');
        return $roleName !== '' && $isMainAdminRoleName($roleName);
    } catch (Throwable) {
        return false;
    }
}

function rpcPagoContratoImportPreviewRead(): ?array
{
    $payload = $_SESSION[rpcPagoContratoImportPreviewSessionKey()] ?? null;
    return is_array($payload) ? $payload : null;
}

function rpcPagoContratoImportPreviewWrite(array $payload): void
{
    $_SESSION[rpcPagoContratoImportPreviewSessionKey()] = $payload;
}

function rpcPagoContratoImportPreviewClear(): void
{
    unset($_SESSION[rpcPagoContratoImportPreviewSessionKey()]);
}

function rpcPagoContratoImportSummary(array $payload): array
{
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $ok = 0;
    $error = 0;
    $totalMonto = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['status'] ?? '') === 'OK') {
            $ok++;
            $totalMonto += (float) ($row['monto_pagado'] ?? 0);
        } else {
            $error++;
        }
    }

    return [
        'ok_rows' => $ok,
        'error_rows' => $error,
        'total_monto' => round($totalMonto, 2),
    ];
}

function rpcPagoContratoImportCellString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function rpcPagoContratoImportHeaderMap(array $row): array
{
    $map = [];
    foreach ($row as $index => $value) {
        $header = rpcPagoContratoImportNormalizeHeader(rpcPagoContratoImportCellString($value));
        if ($header === '') {
            continue;
        }
        $map[$header] = (int) $index;
    }

    return $map;
}

function rpcPagoContratoImportNormalizeHeader(string $header): string
{
    $value = mb_strtolower(trim($header), 'UTF-8');
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($conv) && $conv !== '') {
            $value = $conv;
        }
    }
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function rpcPagoContratoImportFindColumn(array $headerMap, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        $key = rpcPagoContratoImportNormalizeHeader($alias);
        if ($key !== '' && array_key_exists($key, $headerMap)) {
            return (int) $headerMap[$key];
        }
    }

    return null;
}

/**
 * Localiza la fila de encabezados aunque el archivo incluya un título o filas
 * informativas antes de la tabla. Retorna [índice de fila, mapa de columnas].
 */
function rpcPagoContratoImportLocateHeaderRow(array $rows, int $maxRows = 20): array
{
    $requiredAliases = [
        ['arrendatario', 'id_arrendatario', 'rut_arrendatario', 'rut'],
        ['contrato', 'id_contrato', 'id_contrato_arriendo'],
        ['monto', 'monto_pagado'],
        ['fecha', 'fecha_pago'],
        ['medio_de_pago', 'medio_pago', 'medio'],
    ];

    $bestIndex = null;
    $bestMap = [];
    $bestScore = -1;
    $limit = min(max(0, $maxRows), count($rows));

    for ($index = 0; $index < $limit; $index++) {
        $row = $rows[$index] ?? null;
        if (!is_array($row)) {
            continue;
        }

        $map = rpcPagoContratoImportHeaderMap($row);
        $score = 0;
        foreach ($requiredAliases as $aliases) {
            if (rpcPagoContratoImportFindColumn($map, $aliases) !== null) {
                $score++;
            }
        }

        if ($score > $bestScore) {
            $bestIndex = $index;
            $bestMap = $map;
            $bestScore = $score;
        }
        if ($score === count($requiredAliases)) {
            return [$index, $map];
        }
    }

    return [$bestIndex, $bestMap];
}

function rpcPagoContratoImportParseDate(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_int($value) || is_float($value)) {
        try {
            $dt = PhpSpreadsheetDate::excelToDateTimeObject((float) $value);
            return $dt->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    $raw = rpcPagoContratoImportCellString($value);
    if ($raw === '') {
        return null;
    }

    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($dt !== false && $dt->format($fmt) === $raw) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function rpcPagoContratoImportParseAmount(mixed $value): array
{
    if (is_int($value) || is_float($value)) {
        $num = (float) $value;
        if ($num <= 0) {
            return [false, null];
        }
        return [true, number_format($num, 2, '.', '')];
    }

    $raw = str_replace(['$', 'CLP', 'clp', ' '], '', rpcPagoContratoImportCellString($value));
    [$ok, $norm] = msp2NormalizeDecimalInput($raw, 2);
    if (!$ok || $norm === null || (float) $norm <= 0) {
        return [false, null];
    }

    return [true, $norm];
}

function rpcPagoContratoImportNormalizeMedioPago(string $raw): ?string
{
    $value = mb_strtoupper(trim($raw), 'UTF-8');
    if ($value === '') {
        return null;
    }

    return match ($value) {
        'TRANSFERENCIA', 'TRANSFER', 'TRANSFERENCIA BANCARIA' => 'Transferencia',
        'EFECTIVO', 'CASH' => 'Efectivo',
        'CHEQUE', 'CHEQ' => 'Cheque',
        default => null,
    };
}

function rpcPagoContratoImportBuildQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}
