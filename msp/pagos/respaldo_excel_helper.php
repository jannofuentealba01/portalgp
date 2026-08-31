<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function msp2PagosBackupVersion(): string
{
    return 'MSP_PAGOS_BACKUP_V1';
}

function msp2PagosBackupSheetPagos(): string
{
    return 'Pagos';
}

function msp2PagosBackupSheetDetalle(): string
{
    return 'DetalleConceptos';
}

function msp2PagosBackupSheetReadme(): string
{
    return 'README';
}

function msp2PagosBackupHeadersPagos(): array
{
    return [
        'version',
        'pago_uid',
        'orden_replay',
        'id_pago_origen',
        'id_documento_origen',
        'id_tienda',
        'nombre_comercial',
        'periodo_facturacion',
        'numero_documento',
        'rut_arrendatario',
        'nombre_arrendatario',
        'fecha_pago',
        'monto_pagado',
        'monto_aplicado_documento',
        'monto_saldo_favor_generado',
        'aplica_desde_saldo_favor',
        'medio_pago',
        'referencia_pago',
        'observaciones',
        'estado_pago',
    ];
}

function msp2PagosBackupHeadersDetalle(): array
{
    return [
        'version',
        'pago_uid',
        'orden_concepto',
        'codigo_item',
        'nombre_item',
        'monto_aplicado',
    ];
}

function msp2PagosEstadoMap(): array
{
    return [
        1 => ['label' => 'Aplicado', 'badge' => 'text-bg-success'],
        2 => ['label' => 'Anulado', 'badge' => 'text-bg-secondary'],
    ];
}

function msp2PagosNormalizeFilters(array $source): array
{
    return [
        'filtroDocumento' => msp2NormalizeText($source['filtroDocumento'] ?? null),
        'filtroTienda' => msp2NormalizeText($source['filtroTienda'] ?? null),
        'filtroArrendatario' => msp2NormalizeText($source['filtroArrendatario'] ?? null),
        'filtroEstado' => trim((string) ($source['filtroEstado'] ?? '')),
    ];
}

function msp2PagosBuildFilters(array $filters, ?array $allowedEstadoMap = null): array
{
    $estadoMap = $allowedEstadoMap ?? msp2PagosEstadoMap();
    $conditions = [];
    $params = [];

    $filtroDocumento = trim((string) ($filters['filtroDocumento'] ?? ''));
    $filtroTienda = trim((string) ($filters['filtroTienda'] ?? ''));
    $filtroArrendatario = trim((string) ($filters['filtroArrendatario'] ?? ''));
    $filtroEstado = trim((string) ($filters['filtroEstado'] ?? ''));

    if ($filtroDocumento !== '') {
        $conditions[] = "(ISNULL(dc.numero_documento, '') LIKE :filtro_documento_num OR CAST(dc.id_documento_cobro AS NVARCHAR(20)) LIKE :filtro_documento_id)";
        $params[':filtro_documento_num'] = '%' . $filtroDocumento . '%';
        $params[':filtro_documento_id'] = '%' . $filtroDocumento . '%';
    }

    if ($filtroTienda !== '') {
        $conditions[] = "ISNULL(t.nombre_comercial, '') LIKE :filtro_tienda";
        $params[':filtro_tienda'] = '%' . $filtroTienda . '%';
    }

    if ($filtroArrendatario !== '') {
        $conditions[] = "(ISNULL(dc.nombre_arrendatario_snapshot, '') LIKE :filtro_arrendatario_nombre OR ISNULL(dc.rut_arrendatario_snapshot, '') LIKE :filtro_arrendatario_rut)";
        $params[':filtro_arrendatario_nombre'] = '%' . $filtroArrendatario . '%';
        $params[':filtro_arrendatario_rut'] = '%' . $filtroArrendatario . '%';
    }

    if ($filtroEstado !== '' && ctype_digit($filtroEstado) && isset($estadoMap[(int) $filtroEstado])) {
        $conditions[] = 'p.estado_pago = :filtro_estado';
        $params[':filtro_estado'] = (int) $filtroEstado;
    }

    return [
        'where' => $conditions === [] ? '1=1' : implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function msp2PagosBindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function msp2PagosBuildQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2PagosPreviewSessionKey(): string
{
    return 'msp2_pagos_backup_import_preview';
}

function msp2PagosPreviewSessionRead(): ?array
{
    $payload = $_SESSION[msp2PagosPreviewSessionKey()] ?? null;
    return is_array($payload) ? $payload : null;
}

function msp2PagosPreviewSessionWrite(array $payload): void
{
    $_SESSION[msp2PagosPreviewSessionKey()] = $payload;
}

function msp2PagosPreviewSessionClear(): void
{
    unset($_SESSION[msp2PagosPreviewSessionKey()]);
}

function msp2PagosBackupMakeUid(int $idPago): string
{
    return 'PAGO-' . $idPago;
}

function msp2PagosBackupCellString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function msp2PagosBackupParseDecimal(mixed $value, int $scale = 2): array
{
    if ($value === null || $value === '') {
        return [false, null];
    }

    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
        if ($number < 0) {
            return [false, null];
        }

        return [true, number_format($number, $scale, '.', '')];
    }

    $raw = str_replace(['$', 'CLP', 'clp'], '', msp2PagosBackupCellString($value));
    return msp2NormalizeDecimalInput($raw, $scale);
}

function msp2PagosBackupParseInt(mixed $value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }

    $raw = msp2PagosBackupCellString($value);
    if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
        return null;
    }

    return (int) $raw;
}

function msp2PagosBackupParseDate(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    if (is_int($value) || is_float($value)) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
            return $dt->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    $raw = msp2PagosBackupCellString($value);
    if ($raw === '') {
        return null;
    }

    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt !== false && $dt->format($format) === $raw) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function msp2PagosBackupParseBoolFlag(mixed $value): ?int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        return ((int) $value) === 1 ? 1 : 0;
    }

    $raw = mb_strtolower(msp2PagosBackupCellString($value), 'UTF-8');
    if ($raw === '') {
        return null;
    }

    if (in_array($raw, ['1', 'si', 'sí', 'true', 'verdadero', 'yes'], true)) {
        return 1;
    }

    if (in_array($raw, ['0', 'no', 'false', 'falso'], true)) {
        return 0;
    }

    return null;
}

function msp2PagosBackupBuildHeaderMap(array $row): array
{
    $map = [];
    foreach ($row as $index => $value) {
        $header = msp2PagosBackupCellString($value);
        if ($header === '') {
            continue;
        }

        $map[$header] = $index;
    }

    return $map;
}

function msp2PagosBackupReadSheets(string $path): array
{
    return msp2WithSpreadsheetCompatibility(static function () use ($path): array {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);

        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($path);
        $sheets = [];

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheets[$worksheet->getTitle()] = $worksheet->toArray(null, true, true, false);
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $sheets;
    });
}

function msp2PagosBackupPreviewSummary(array $payload): array
{
    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $ok = 0;
    $error = 0;
    $documentKeys = [];
    $total = 0.0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if (($row['status'] ?? '') === 'OK') {
            $ok++;
            $documentKey = (string) ($row['document_key'] ?? '');
            if ($documentKey !== '') {
                $documentKeys[$documentKey] = true;
            }
            $total += (float) ($row['monto_pagado'] ?? 0);
        } else {
            $error++;
        }
    }

    return [
        'ok_rows' => $ok,
        'error_rows' => $error,
        'document_count' => count($documentKeys),
        'total_monto_pagado' => round($total, 2),
    ];
}
