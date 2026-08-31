<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/cobranza/mail_templates/vale_pago_pdf.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/comprobante_gastos_pdf.php';
require_once dirname(__DIR__) . '/cobros/mail_templates/vale_cobro_email.php';
require_once dirname(__DIR__) . '/documentos_cobro/vale_lib.php';

function msp2ArchivosPdfConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $defaultRoot = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'msp_storage' . DIRECTORY_SEPARATOR . 'pagos_contrato_pdf';
    $customConfig = [];
    $configPath = dirname(__DIR__) . '/config/storage.php';
    if (is_file($configPath)) {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $customConfig = $loaded;
        }
    }

    $root = trim((string) ($customConfig['pago_contrato_pdf_root'] ?? ''));
    $config = [
        'root_dir' => $root !== '' ? $root : $defaultRoot,
    ];

    return $config;
}

function msp2ArchivosPdfRootDir(): string
{
    return rtrim((string) (msp2ArchivosPdfConfig()['root_dir'] ?? ''), "\\/");
}

function msp2ArchivosPdfRequireDompdf(): void
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        $autoloadCandidates = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];

        foreach ($autoloadCandidates as $autoloadPath) {
            if (is_file($autoloadPath)) {
                require_once $autoloadPath;
                break;
            }
        }
    }

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('DomPDF no está disponible en el proyecto.');
    }
}

function msp2ArchivosPdfEnsureDir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }

    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No fue posible crear la carpeta de respaldo de PDFs.');
    }
}

function msp2ArchivosPdfTypeDbValue(string $type): string
{
    return match ($type) {
        'comprobante_gastos' => 'COMPROBANTE_GASTOS',
        'vale_cobro' => 'VALE_COBRO',
        default => 'VALE_PAGO',
    };
}

function msp2ArchivosPdfTypeUiLabel(string $typeDb): string
{
    return match (strtoupper(trim($typeDb))) {
        'COMPROBANTE_GASTOS' => 'Comprobante de gastos',
        'VALE_COBRO' => 'Vale de cobro',
        default => 'Vale de pago',
    };
}

function msp2ArchivosPdfPayloadJson(array $item): string
{
    $json = json_encode([
        'type' => (string) ($item['type'] ?? ''),
        'module' => (string) ($item['module'] ?? ''),
        'pago_data' => is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [],
        'arr_data' => is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [],
        'doc_data' => is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [],
        'source_id_documento_cobro' => (int) ($item['source_id_documento_cobro'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json) || $json === '') {
        throw new RuntimeException('No fue posible serializar la metadata del archivo.');
    }

    return $json;
}

function msp2ArchivosPdfDecodePayload(?string $payloadJson): array
{
    if ($payloadJson === null || trim($payloadJson) === '') {
        return [];
    }

    $decoded = json_decode($payloadJson, true);
    return is_array($decoded) ? $decoded : [];
}

function msp2ArchivosPdfLocalCodesFromValeData(array $data): string
{
    $locals = [];
    foreach (['arriendo', 'electricidad', 'gas', 'agua'] as $key) {
        foreach ((array) ($data[$key] ?? []) as $detail) {
            $code = trim((string) ($detail['cdo_local'] ?? ''));
            if ($code !== '') {
                $locals[$code] = true;
            }
        }
    }

    if ($locals === []) {
        return '';
    }

    $codes = array_keys($locals);
    usort($codes, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));
    return implode(' / ', $codes);
}

function msp2ArchivosPdfHydrateValeCobroItem(PDO $conn, array $item): array
{
    $sourceId = max(
        (int) ($item['source_id_documento_cobro'] ?? 0),
        (int) ($item['id_documento_cobro'] ?? 0),
        (int) (($item['doc_data']['id_documento_cobro'] ?? 0))
    );
    if ($sourceId <= 0) {
        throw new RuntimeException('Vale de cobro sin documento de origen.');
    }

    $data = msp2DocumentoCobroValeData($conn, $sourceId);
    $documento = is_array($data['documento'] ?? null) ? $data['documento'] : [];
    $periodoYm = substr(trim((string) ($documento['periodo_facturacion'] ?? '')), 0, 7);
    $arrName = trim((string) ($documento['nombre_arrendatario_snapshot'] ?? ''));
    $numeroDocumento = trim((string) ($documento['numero_documento'] ?? ''));
    $localesContrato = msp2ArchivosPdfLocalCodesFromValeData($data);
    $fechaEmision = substr(trim((string) ($documento['fecha_emision'] ?? '')), 0, 10);

    $item['module'] = trim((string) ($item['module'] ?? '')) !== '' ? (string) $item['module'] : 'DOCUMENTO_COBRO';
    $item['id_pago'] = (int) ($item['id_pago'] ?? 0);
    $item['id_documento_cobro'] = $sourceId;
    $item['id_contrato_arriendo'] = (int) ($item['id_contrato_arriendo'] ?? 0);
    $item['id_arrendatario'] = (int) ($item['id_arrendatario'] ?? 0);
    $item['source_id_documento_cobro'] = $sourceId;
    $item['pago_data'] = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $item['arr_data'] = array_merge(
        ['nombre_arrendatario' => $arrName],
        is_array($item['arr_data'] ?? null) ? $item['arr_data'] : []
    );
    $item['doc_data'] = array_merge([
        'id_documento_cobro' => $sourceId,
        'periodo_ym' => $periodoYm,
        'numero_documento' => $numeroDocumento,
        'locales_contrato' => $localesContrato,
        'fecha_referencia' => $fechaEmision,
    ], is_array($item['doc_data'] ?? null) ? $item['doc_data'] : []);

    return $item;
}

function msp2ArchivosPdfNormalizeItem(PDO $conn, array $item): array
{
    $type = trim((string) ($item['type'] ?? ''));
    if ($type === 'vale_cobro') {
        return msp2ArchivosPdfHydrateValeCobroItem($conn, $item);
    }

    $item['module'] = trim((string) ($item['module'] ?? '')) !== '' ? (string) $item['module'] : 'PAGO_CONTRATO';
    $item['pago_data'] = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $item['arr_data'] = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $item['doc_data'] = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];
    $item['id_pago'] = (int) ($item['id_pago'] ?? ($item['pago_data']['id_pago'] ?? 0));
    $item['id_documento_cobro'] = (int) ($item['id_documento_cobro'] ?? ($item['doc_data']['id_documento_cobro'] ?? 0));
    $item['id_contrato_arriendo'] = (int) ($item['id_contrato_arriendo'] ?? ($item['doc_data']['id_contrato_arriendo'] ?? 0));
    $item['id_arrendatario'] = (int) ($item['id_arrendatario'] ?? ($item['arr_data']['id_arrendatario'] ?? 0));

    return $item;
}

function msp2ArchivosPdfFetchValeCobroMailContext(PDO $conn, int $idDocumentoCobro): array
{
    if ($idDocumentoCobro <= 0) {
        throw new RuntimeException('Documento inválido para vale de cobro.');
    }

    $hasArrCorreos = msp2TableExists($conn, 'msp_arrendatarios_correos');
    $correoJoin = $hasArrCorreos
        ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac
                ON ac.id_arrendatario = a.id_arrendatario'
        : '';
    $correoSelect = $hasArrCorreos
        ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
        : "'' AS correo_principal";

    $stmt = $conn->prepare(
        "SELECT
            dc.id_documento_cobro,
            COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
            dc.monto_total,
            dc.saldo_pendiente,
            CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
            a.id_arrendatario,
            COALESCE(
                NULLIF(LTRIM(RTRIM(dc.nombre_arrendatario_snapshot)), ''),
                NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                NULLIF(LTRIM(RTRIM(a.rut)), ''),
                CONCAT(N'Arrendatario #', a.id_arrendatario)
            ) AS nombre_arrendatario,
            COALESCE(NULLIF(LTRIM(RTRIM(dc.rut_arrendatario_snapshot)), ''), LTRIM(RTRIM(a.rut)), '') AS rut,
            $correoSelect
         FROM dbo.msp_documentos_cobro dc
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
         $correoJoin
         WHERE dc.id_documento_cobro = :id_documento
         GROUP BY
            dc.id_documento_cobro,
            dc.numero_documento,
            dc.monto_total,
            dc.saldo_pendiente,
            dc.periodo_facturacion,
            dc.nombre_arrendatario_snapshot,
            dc.rut_arrendatario_snapshot,
            a.id_arrendatario,
            a.nombre_locatario,
            a.nombre_representante,
            a.rut"
    );
    $stmt->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('No fue posible cargar contexto de vale de cobro.');
    }

    return [
        'arr_row' => [
            'id_arrendatario' => (int) ($row['id_arrendatario'] ?? 0),
            'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
            'rut' => (string) ($row['rut'] ?? ''),
            'correo_principal' => trim((string) ($row['correo_principal'] ?? '')),
        ],
        'doc_row' => [
            'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
            'numero_documento' => (string) ($row['numero_documento'] ?? ''),
            'monto_total' => (float) ($row['monto_total'] ?? 0),
            'saldo_pendiente' => (float) ($row['saldo_pendiente'] ?? 0),
        ],
        'periodo_ym' => (string) ($row['periodo_ym'] ?? ''),
    ];
}

function msp2ArchivosPdfCompactValeCobroHtml(string $html): string
{
    $search = [
        'max-width:720px',
        'font-size:28px',
        'font-size:38px',
        'font-size:46px',
        'font-size:13px;',
        'font-size:12px',
        'padding:12px',
        'padding:8px',
        'padding:6px 8px',
        'height:18px',
        'height:8px',
        'border:4px solid #000',
        'border:3px solid #000',
        'margin:0 auto 20px auto',
    ];
    $replace = [
        'max-width:660px',
        'font-size:18px',
        'font-size:23px',
        'font-size:30px',
        'font-size:10px;',
        'font-size:9px',
        'padding:8px',
        'padding:5px',
        'padding:4px 5px',
        'height:8px',
        'height:4px',
        'border:2px solid #000',
        'border:1px solid #000',
        'margin:0 auto 10px auto',
    ];

    $compacted = str_replace($search, $replace, $html);

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
        . '@page{margin:18px 22px;}'
        . 'body{font-family:Arial,DejaVu Sans,sans-serif;color:#111;margin:0;padding:0;}'
        . 'table{page-break-inside:avoid;}'
        . '</style></head><body>' . $compacted . '</body></html>';
}

function msp2ArchivosPdfBuildPdf(PDO $conn, string $type, array $item): array
{
    $pagoData = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $arrData = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];

    if ($type === 'comprobante_gastos') {
        [$filename, $html] = rpBuildComprobanteGastosPdfPayload($pagoData, $arrData, $docData);
        msp2ArchivosPdfRequireDompdf();
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return [
            'filename' => $filename,
            'bytes' => $pdf->output(),
            'mime_type' => 'application/pdf',
        ];
    }

    if ($type === 'vale_cobro') {
        $sourceId = max(
            (int) ($item['source_id_documento_cobro'] ?? 0),
            (int) ($item['id_documento_cobro'] ?? 0),
            (int) (($docData['id_documento_cobro'] ?? 0))
        );
        if ($sourceId <= 0) {
            throw new RuntimeException('Vale de cobro sin documento válido.');
        }
        $context = msp2ArchivosPdfFetchValeCobroMailContext($conn, $sourceId);
        [$filename] = msp2BuildDocumentoCobroValePdf($conn, $sourceId);
        [, $html] = omBuildCobroEmailContent(
            $conn,
            (array) ($context['arr_row'] ?? []),
            [(array) ($context['doc_row'] ?? [])],
            (string) ($context['periodo_ym'] ?? '')
        );
        $html = msp2ArchivosPdfCompactValeCobroHtml($html);
        msp2ArchivosPdfRequireDompdf();
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return [
            'filename' => $filename,
            'bytes' => $pdf->output(),
            'mime_type' => 'application/pdf',
        ];
    }

    [$filename, $html] = rpBuildValePagoPdfPayload($pagoData, $arrData, $docData);
    msp2ArchivosPdfRequireDompdf();
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->setPaper('A4', 'portrait');
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->render();

    return [
        'filename' => $filename,
        'bytes' => $pdf->output(),
        'mime_type' => 'application/pdf',
    ];
}

function msp2ArchivosPdfEstimateFilename(PDO $conn, array $item): string
{
    $type = trim((string) ($item['type'] ?? ''));
    $pagoData = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $arrData = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];

    if ($type === 'comprobante_gastos') {
        [$filename] = rpBuildComprobanteGastosPdfPayload($pagoData, $arrData, $docData);
        return trim((string) $filename) !== '' ? (string) $filename : 'comprobante_gastos.pdf';
    }

    if ($type === 'vale_cobro') {
        $sourceId = max(
            (int) ($item['source_id_documento_cobro'] ?? 0),
            (int) ($item['id_documento_cobro'] ?? 0),
            (int) (($docData['id_documento_cobro'] ?? 0))
        );
        if ($sourceId <= 0) {
            return 'vale_cobro.pdf';
        }
        $data = msp2DocumentoCobroValeData($conn, $sourceId);
        $documento = is_array($data['documento'] ?? null) ? $data['documento'] : [];
        return valeBuildFilename(
            $documento,
            (array) ($data['arriendo'] ?? []),
            (array) ($data['electricidad'] ?? []),
            (array) ($data['gas'] ?? []),
            (array) ($data['agua'] ?? [])
        );
    }

    [$filename] = rpBuildValePagoPdfPayload($pagoData, $arrData, $docData);
    return trim((string) $filename) !== '' ? (string) $filename : 'vale_pago.pdf';
}

function msp2ArchivosPdfRelativePath(array $item, string $filename): string
{
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];
    $periodoYm = trim((string) ($docData['periodo_ym'] ?? ''));
    $year = preg_match('/^\d{4}-\d{2}$/', $periodoYm) === 1 ? substr($periodoYm, 0, 4) : 'sin_periodo';
    $month = preg_match('/^\d{4}-\d{2}$/', $periodoYm) === 1 ? substr($periodoYm, 5, 2) : '00';
    $filenameSafe = str_replace(['\\', '/'], '_', $filename);

    return $year . '/' . $month . '/' . $filenameSafe;
}

function msp2ArchivosPdfAbsolutePath(string $relativePath): string
{
    return msp2ArchivosPdfRootDir() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, "\\/"));
}

function msp2ArchivosPdfWriteFile(string $absolutePath, string $bytes): void
{
    msp2ArchivosPdfEnsureDir(dirname($absolutePath));
    if (@file_put_contents($absolutePath, $bytes) === false) {
        throw new RuntimeException('No fue posible escribir el archivo PDF en disco.');
    }
}

function msp2ArchivosPdfArchiveOne(PDO $conn, array $item): array
{
    if (!msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        throw new RuntimeException('Falta la tabla dbo.msp_pago_contrato_archivos.');
    }

    $item = msp2ArchivosPdfNormalizeItem($conn, $item);
    $type = trim((string) ($item['type'] ?? ''));
    $pagoData = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $arrData = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];

    $rendered = msp2ArchivosPdfBuildPdf($conn, $type, $item);
    $filename = trim((string) ($rendered['filename'] ?? ''));
    $bytes = (string) ($rendered['bytes'] ?? '');
    if ($filename === '' || $bytes === '') {
        throw new RuntimeException('No fue posible generar el PDF de respaldo.');
    }

    $relativePath = msp2ArchivosPdfRelativePath($item, $filename);
    $absolutePath = msp2ArchivosPdfAbsolutePath($relativePath);
    msp2ArchivosPdfWriteFile($absolutePath, $bytes);

    $hash = hash('sha256', $bytes);
    $byteCount = strlen($bytes);
    $payloadJson = msp2ArchivosPdfPayloadJson($item);
    $tipoArchivoDb = msp2ArchivosPdfTypeDbValue($type);
    $userId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
    $periodoYm = trim((string) ($docData['periodo_ym'] ?? ''));
    $fechaReferencia = trim((string) ($pagoData['fecha_pago'] ?? ($docData['fecha_referencia'] ?? '')));
    $numeroDocumento = trim((string) ($docData['numero_documento'] ?? ''));
    $arrendatarioNombre = trim((string) ($arrData['nombre_arrendatario'] ?? ''));
    $locales = trim((string) ($docData['locales_contrato'] ?? ''));

    $stmtExisting = $conn->prepare(
        "SELECT TOP 1 id_pago_contrato_archivo
         FROM dbo.msp_pago_contrato_archivos
         WHERE id_pago = :id_pago
           AND id_documento_cobro = :id_documento_cobro
           AND tipo_archivo = :tipo_archivo"
    );
    $stmtExisting->bindValue(':id_pago', (int) ($item['id_pago'] ?? 0), PDO::PARAM_INT);
    $stmtExisting->bindValue(':id_documento_cobro', (int) ($item['id_documento_cobro'] ?? 0), PDO::PARAM_INT);
    $stmtExisting->bindValue(':tipo_archivo', $tipoArchivoDb, PDO::PARAM_STR);
    $stmtExisting->execute();
    $existingId = (int) ($stmtExisting->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $stmtUpsert = $conn->prepare(
            "UPDATE dbo.msp_pago_contrato_archivos
             SET id_contrato_arriendo = :id_contrato_arriendo,
                 id_arrendatario = :id_arrendatario,
                 modulo_origen = :modulo_origen,
                 periodo_ym = :periodo_ym,
                 fecha_pago = :fecha_pago,
                 numero_documento = :numero_documento,
                 arrendatario_nombre = :arrendatario_nombre,
                 locales = :locales,
                 nombre_archivo = :nombre_archivo,
                 ruta_relativa = :ruta_relativa,
                 mime_type = :mime_type,
                 hash_sha256 = :hash_sha256,
                 bytes_archivo = :bytes_archivo,
                 payload_json = :payload_json,
                 estado_archivo = :estado_archivo,
                 id_usuario = :id_usuario,
                 fecha_generacion = SYSDATETIME(),
                 updated_at = SYSDATETIME()
             WHERE id_pago_contrato_archivo = :id_pago_contrato_archivo"
        );
    } else {
        $stmtUpsert = $conn->prepare(
            "INSERT INTO dbo.msp_pago_contrato_archivos (
                id_pago, id_documento_cobro, id_contrato_arriendo, id_arrendatario,
                modulo_origen, tipo_archivo, periodo_ym, fecha_pago, numero_documento,
                arrendatario_nombre, locales, nombre_archivo, ruta_relativa,
                mime_type, hash_sha256, bytes_archivo, payload_json,
                estado_archivo, id_usuario, fecha_generacion, fecha_registro, updated_at
             ) VALUES (
                :id_pago, :id_documento_cobro, :id_contrato_arriendo, :id_arrendatario,
                :modulo_origen, :tipo_archivo, :periodo_ym, :fecha_pago, :numero_documento,
                :arrendatario_nombre, :locales, :nombre_archivo, :ruta_relativa,
                :mime_type, :hash_sha256, :bytes_archivo, :payload_json,
                :estado_archivo, :id_usuario, SYSDATETIME(), SYSDATETIME(), SYSDATETIME()
             )"
        );
        $stmtUpsert->bindValue(':id_pago', (int) ($item['id_pago'] ?? 0), PDO::PARAM_INT);
        $stmtUpsert->bindValue(':id_documento_cobro', (int) ($item['id_documento_cobro'] ?? 0), PDO::PARAM_INT);
        $stmtUpsert->bindValue(':tipo_archivo', $tipoArchivoDb, PDO::PARAM_STR);
    }

    $stmtUpsert->bindValue(':id_contrato_arriendo', (int) ($item['id_contrato_arriendo'] ?? 0), PDO::PARAM_INT);
    $stmtUpsert->bindValue(':id_arrendatario', (int) ($item['id_arrendatario'] ?? 0), PDO::PARAM_INT);
    $stmtUpsert->bindValue(':modulo_origen', trim((string) ($item['module'] ?? 'PAGO_CONTRATO')) ?: 'PAGO_CONTRATO', PDO::PARAM_STR);
    $stmtUpsert->bindValue(':periodo_ym', $periodoYm !== '' ? $periodoYm : null, $periodoYm !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':fecha_pago', $fechaReferencia !== '' ? $fechaReferencia : null, $fechaReferencia !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':numero_documento', $numeroDocumento !== '' ? $numeroDocumento : null, $numeroDocumento !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':arrendatario_nombre', $arrendatarioNombre !== '' ? $arrendatarioNombre : null, $arrendatarioNombre !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':locales', $locales !== '' ? $locales : null, $locales !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':nombre_archivo', $filename, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':ruta_relativa', $relativePath, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':mime_type', (string) ($rendered['mime_type'] ?? 'application/pdf'), PDO::PARAM_STR);
    $stmtUpsert->bindValue(':hash_sha256', $hash, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':bytes_archivo', $byteCount, PDO::PARAM_INT);
    $stmtUpsert->bindValue(':payload_json', $payloadJson, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':estado_archivo', $existingId > 0 ? 'REGENERADO' : 'ACTIVO', PDO::PARAM_STR);
    $stmtUpsert->bindValue(':id_usuario', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    if ($existingId > 0) {
        $stmtUpsert->bindValue(':id_pago_contrato_archivo', $existingId, PDO::PARAM_INT);
    }
    $stmtUpsert->execute();

    if ($existingId <= 0) {
        $existingId = (int) $conn->lastInsertId();
    }

    return [
        'id_pago_contrato_archivo' => $existingId,
        'nombre_archivo' => $filename,
        'ruta_relativa' => $relativePath,
        'bytes_archivo' => $byteCount,
    ];
}

function msp2ArchivosPdfArchiveMany(PDO $conn, array $items): array
{
    $saved = [];
    $errors = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        try {
            $saved[] = msp2ArchivosPdfArchiveOne($conn, $item);
        } catch (Throwable $exception) {
            $errors[] = [
                'index' => $index,
                'message' => $exception->getMessage(),
            ];
        }
    }

    return [
        'saved' => $saved,
        'errors' => $errors,
    ];
}

function msp2ArchivosPdfRegisterMetadataOnly(PDO $conn, array $item): array
{
    if (!msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        throw new RuntimeException('Falta la tabla dbo.msp_pago_contrato_archivos.');
    }

    $item = msp2ArchivosPdfNormalizeItem($conn, $item);
    $type = trim((string) ($item['type'] ?? ''));
    $pagoData = is_array($item['pago_data'] ?? null) ? $item['pago_data'] : [];
    $arrData = is_array($item['arr_data'] ?? null) ? $item['arr_data'] : [];
    $docData = is_array($item['doc_data'] ?? null) ? $item['doc_data'] : [];

    $filename = msp2ArchivosPdfEstimateFilename($conn, $item);
    $relativePath = msp2ArchivosPdfRelativePath($item, $filename);
    $payloadJson = msp2ArchivosPdfPayloadJson($item);
    $tipoArchivoDb = msp2ArchivosPdfTypeDbValue($type);
    $userId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
    $periodoYm = trim((string) ($docData['periodo_ym'] ?? ''));
    $fechaReferencia = trim((string) ($pagoData['fecha_pago'] ?? ($docData['fecha_referencia'] ?? '')));
    $numeroDocumento = trim((string) ($docData['numero_documento'] ?? ''));
    $arrendatarioNombre = trim((string) ($arrData['nombre_arrendatario'] ?? ''));
    $locales = trim((string) ($docData['locales_contrato'] ?? ''));

    $stmtExisting = $conn->prepare(
        "SELECT TOP 1 id_pago_contrato_archivo
         FROM dbo.msp_pago_contrato_archivos
         WHERE id_pago = :id_pago
           AND id_documento_cobro = :id_documento_cobro
           AND tipo_archivo = :tipo_archivo"
    );
    $stmtExisting->bindValue(':id_pago', (int) ($item['id_pago'] ?? 0), PDO::PARAM_INT);
    $stmtExisting->bindValue(':id_documento_cobro', (int) ($item['id_documento_cobro'] ?? 0), PDO::PARAM_INT);
    $stmtExisting->bindValue(':tipo_archivo', $tipoArchivoDb, PDO::PARAM_STR);
    $stmtExisting->execute();
    $existingId = (int) ($stmtExisting->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $stmtUpsert = $conn->prepare(
            "UPDATE dbo.msp_pago_contrato_archivos
             SET id_contrato_arriendo = :id_contrato_arriendo,
                 id_arrendatario = :id_arrendatario,
                 modulo_origen = :modulo_origen,
                 periodo_ym = :periodo_ym,
                 fecha_pago = :fecha_pago,
                 numero_documento = :numero_documento,
                 arrendatario_nombre = :arrendatario_nombre,
                 locales = :locales,
                 nombre_archivo = :nombre_archivo,
                 ruta_relativa = :ruta_relativa,
                 mime_type = :mime_type,
                 payload_json = :payload_json,
                 estado_archivo = :estado_archivo,
                 id_usuario = :id_usuario,
                 updated_at = SYSDATETIME()
             WHERE id_pago_contrato_archivo = :id_pago_contrato_archivo"
        );
    } else {
        $stmtUpsert = $conn->prepare(
            "INSERT INTO dbo.msp_pago_contrato_archivos (
                id_pago, id_documento_cobro, id_contrato_arriendo, id_arrendatario,
                modulo_origen, tipo_archivo, periodo_ym, fecha_pago, numero_documento,
                arrendatario_nombre, locales, nombre_archivo, ruta_relativa,
                mime_type, hash_sha256, bytes_archivo, payload_json,
                estado_archivo, id_usuario, fecha_generacion, fecha_registro, updated_at
             ) VALUES (
                :id_pago, :id_documento_cobro, :id_contrato_arriendo, :id_arrendatario,
                :modulo_origen, :tipo_archivo, :periodo_ym, :fecha_pago, :numero_documento,
                :arrendatario_nombre, :locales, :nombre_archivo, :ruta_relativa,
                :mime_type, :hash_sha256, :bytes_archivo, :payload_json,
                :estado_archivo, :id_usuario, SYSDATETIME(), SYSDATETIME(), SYSDATETIME()
             )"
        );
        $stmtUpsert->bindValue(':id_pago', (int) ($item['id_pago'] ?? 0), PDO::PARAM_INT);
        $stmtUpsert->bindValue(':id_documento_cobro', (int) ($item['id_documento_cobro'] ?? 0), PDO::PARAM_INT);
        $stmtUpsert->bindValue(':tipo_archivo', $tipoArchivoDb, PDO::PARAM_STR);
        $stmtUpsert->bindValue(':hash_sha256', str_repeat('0', 64), PDO::PARAM_STR);
        $stmtUpsert->bindValue(':bytes_archivo', 0, PDO::PARAM_INT);
    }

    $stmtUpsert->bindValue(':id_contrato_arriendo', (int) ($item['id_contrato_arriendo'] ?? 0), PDO::PARAM_INT);
    $stmtUpsert->bindValue(':id_arrendatario', (int) ($item['id_arrendatario'] ?? 0), PDO::PARAM_INT);
    $stmtUpsert->bindValue(':modulo_origen', trim((string) ($item['module'] ?? 'PAGO_CONTRATO')) ?: 'PAGO_CONTRATO', PDO::PARAM_STR);
    $stmtUpsert->bindValue(':periodo_ym', $periodoYm !== '' ? $periodoYm : null, $periodoYm !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':fecha_pago', $fechaReferencia !== '' ? $fechaReferencia : null, $fechaReferencia !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':numero_documento', $numeroDocumento !== '' ? $numeroDocumento : null, $numeroDocumento !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':arrendatario_nombre', $arrendatarioNombre !== '' ? $arrendatarioNombre : null, $arrendatarioNombre !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':locales', $locales !== '' ? $locales : null, $locales !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpsert->bindValue(':nombre_archivo', $filename, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':ruta_relativa', $relativePath, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':mime_type', 'application/pdf', PDO::PARAM_STR);
    $stmtUpsert->bindValue(':payload_json', $payloadJson, PDO::PARAM_STR);
    $stmtUpsert->bindValue(':estado_archivo', 'FALTANTE', PDO::PARAM_STR);
    $stmtUpsert->bindValue(':id_usuario', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    if ($existingId > 0) {
        $stmtUpsert->bindValue(':id_pago_contrato_archivo', $existingId, PDO::PARAM_INT);
    }
    $stmtUpsert->execute();

    if ($existingId <= 0) {
        $existingId = (int) $conn->lastInsertId();
    }

    return [
        'id_pago_contrato_archivo' => $existingId,
        'nombre_archivo' => $filename,
        'ruta_relativa' => $relativePath,
        'bytes_archivo' => 0,
        'estado_archivo' => 'FALTANTE',
    ];
}

function msp2ArchivosPdfRegisterMetadataMany(PDO $conn, array $items): array
{
    $saved = [];
    $errors = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }
        try {
            $saved[] = msp2ArchivosPdfRegisterMetadataOnly($conn, $item);
        } catch (Throwable $exception) {
            $errors[] = [
                'index' => $index,
                'message' => $exception->getMessage(),
            ];
        }
    }

    return [
        'saved' => $saved,
        'errors' => $errors,
    ];
}

/**
 * Registra en una sola operación la metadata pendiente de vales de cobro.
 *
 * La operación mensual puede producir más de cien documentos. Pasarlos por
 * msp2ArchivosPdfRegisterMetadataOnly() provoca varias consultas de detalle por
 * documento y puede agotar el tiempo máximo de PHP. Este camino masivo conserva
 * el respaldo como FALTANTE para materialización diferida, sin renderizar PDFs
 * ni volver a consultar cada documento individualmente.
 */
function msp2ArchivosPdfRegisterValeCobroMetadataIds(PDO $conn, array $documentIds): array
{
    if (!msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        return [
            'saved' => [],
            'errors' => [['index' => 0, 'message' => 'Falta la tabla dbo.msp_pago_contrato_archivos.']],
        ];
    }

    $ids = [];
    foreach ($documentIds as $documentId) {
        $id = (int) $documentId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if ($ids === []) {
        return ['saved' => [], 'errors' => []];
    }

    $idPlaceholders = [];
    foreach ($ids as $index => $_id) {
        $idPlaceholders[] = ':doc_' . $index;
    }
    $fetchStmt = $conn->prepare(
        "SELECT
            dc.id_documento_cobro,
            CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
            CONVERT(CHAR(10), dc.fecha_emision, 23) AS fecha_referencia,
            COALESCE(NULLIF(LTRIM(RTRIM(dc.numero_documento)), ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
            t.id_arrendatario,
            COALESCE(
                NULLIF(LTRIM(RTRIM(dc.nombre_arrendatario_snapshot)), ''),
                NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                NULLIF(LTRIM(RTRIM(a.rut)), ''),
                CONCAT(N'Arrendatario #', a.id_arrendatario)
            ) AS nombre_arrendatario
         FROM dbo.msp_documentos_cobro dc
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
         WHERE dc.id_documento_cobro IN (" . implode(', ', $idPlaceholders) . ')'
    );
    foreach ($ids as $index => $id) {
        $fetchStmt->bindValue(':doc_' . $index, $id, PDO::PARAM_INT);
    }
    $fetchStmt->execute();
    $documents = $fetchStmt->fetchAll() ?: [];
    if ($documents === []) {
        return [
            'saved' => [],
            'errors' => [['index' => 0, 'message' => 'No se encontraron documentos para registrar su metadata PDF.']],
        ];
    }

    $sourceRows = [];
    $bindings = [];
    foreach ($documents as $index => $document) {
        $idDocumento = (int) ($document['id_documento_cobro'] ?? 0);
        $periodoYm = trim((string) ($document['periodo_ym'] ?? ''));
        $numeroDocumento = trim((string) ($document['numero_documento'] ?? ''));
        $arrendatarioNombre = trim((string) ($document['nombre_arrendatario'] ?? ''));
        $fechaReferencia = trim((string) ($document['fecha_referencia'] ?? ''));
        $idArrendatario = (int) ($document['id_arrendatario'] ?? 0);
        $filename = ($periodoYm !== '' ? $periodoYm : 'sin_periodo')
            . '_vale_cobro_doc_' . $idDocumento . '.pdf';
        $relativePath = ($periodoYm !== '' ? substr($periodoYm, 0, 4) . '/' . substr($periodoYm, 5, 2) : 'sin_periodo/00')
            . '/' . $filename;
        $payload = msp2ArchivosPdfPayloadJson([
            'type' => 'vale_cobro',
            'module' => 'DOCUMENTO_COBRO',
            'id_pago' => 0,
            'id_documento_cobro' => $idDocumento,
            'id_arrendatario' => $idArrendatario,
            'source_id_documento_cobro' => $idDocumento,
            'arr_data' => ['nombre_arrendatario' => $arrendatarioNombre],
            'doc_data' => [
                'id_documento_cobro' => $idDocumento,
                'periodo_ym' => $periodoYm,
                'numero_documento' => $numeroDocumento,
                'fecha_referencia' => $fechaReferencia,
                'locales_contrato' => '',
            ],
        ]);

        $keys = [
            'id_documento' => $idDocumento,
            'id_arrendatario' => $idArrendatario,
            'periodo' => $periodoYm,
            'fecha' => $fechaReferencia,
            'numero' => $numeroDocumento,
            'arrendatario' => $arrendatarioNombre,
            'archivo' => $filename,
            'ruta' => $relativePath,
            'payload' => $payload,
        ];
        $placeholders = [];
        foreach ($keys as $key => $value) {
            $placeholder = ':' . $key . '_' . $index;
            $placeholders[] = $placeholder;
            $bindings[$placeholder] = $value;
        }
        $sourceRows[] = '(' . implode(', ', $placeholders) . ')';
    }

    $userId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
    $mergeSql = "MERGE dbo.msp_pago_contrato_archivos AS target
        USING (VALUES " . implode(', ', $sourceRows) . ") AS source
            (id_documento_cobro, id_arrendatario, periodo_ym, fecha_referencia, numero_documento,
             arrendatario_nombre, nombre_archivo, ruta_relativa, payload_json)
        ON target.id_pago = 0
       AND target.id_documento_cobro = source.id_documento_cobro
       AND target.tipo_archivo = N'VALE_COBRO'
        WHEN MATCHED THEN UPDATE SET
            target.id_arrendatario = source.id_arrendatario,
            target.modulo_origen = N'DOCUMENTO_COBRO',
            target.periodo_ym = NULLIF(source.periodo_ym, ''),
            target.fecha_pago = NULLIF(source.fecha_referencia, ''),
            target.numero_documento = NULLIF(source.numero_documento, ''),
            target.arrendatario_nombre = NULLIF(source.arrendatario_nombre, ''),
            target.nombre_archivo = source.nombre_archivo,
            target.ruta_relativa = source.ruta_relativa,
            target.mime_type = N'application/pdf',
            target.payload_json = source.payload_json,
            target.estado_archivo = CASE WHEN target.bytes_archivo > 0 THEN target.estado_archivo ELSE N'FALTANTE' END,
            target.id_usuario = :usuario_update,
            target.updated_at = SYSDATETIME()
        WHEN NOT MATCHED THEN INSERT
            (id_pago, id_documento_cobro, id_contrato_arriendo, id_arrendatario,
             modulo_origen, tipo_archivo, periodo_ym, fecha_pago, numero_documento,
             arrendatario_nombre, locales, nombre_archivo, ruta_relativa, mime_type,
             hash_sha256, bytes_archivo, payload_json, estado_archivo, id_usuario,
             fecha_generacion, fecha_registro, updated_at)
        VALUES
            (0, source.id_documento_cobro, 0, source.id_arrendatario,
             N'DOCUMENTO_COBRO', N'VALE_COBRO', NULLIF(source.periodo_ym, ''), NULLIF(source.fecha_referencia, ''),
             NULLIF(source.numero_documento, ''), NULLIF(source.arrendatario_nombre, ''), NULL,
             source.nombre_archivo, source.ruta_relativa, N'application/pdf',
             REPLICATE('0', 64), 0, source.payload_json, N'FALTANTE', :usuario_insert,
             SYSDATETIME(), SYSDATETIME(), SYSDATETIME())
        OUTPUT inserted.id_pago_contrato_archivo, inserted.nombre_archivo, inserted.ruta_relativa,
               inserted.bytes_archivo, inserted.estado_archivo;";
    $mergeStmt = $conn->prepare($mergeSql);
    foreach ($bindings as $placeholder => $value) {
        $isInteger = str_starts_with($placeholder, ':id_documento_') || str_starts_with($placeholder, ':id_arrendatario_');
        $mergeStmt->bindValue($placeholder, $value, $isInteger ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $mergeStmt->bindValue(':usuario_update', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $mergeStmt->bindValue(':usuario_insert', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $mergeStmt->execute();

    return [
        'saved' => $mergeStmt->fetchAll() ?: [],
        'errors' => [],
    ];
}

function msp2ArchivosPdfFindById(PDO $conn, int $idArchivo): ?array
{
    if ($idArchivo <= 0 || !msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM dbo.msp_pago_contrato_archivos
         WHERE id_pago_contrato_archivo = :id"
    );
    $stmt->bindValue(':id', $idArchivo, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/**
 * @return array{rows_deleted:int,files_deleted:int,files_errors:int}
 */
function msp2ArchivosPdfPurgeOrphans(PDO $conn): array
{
    if (!msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        return [
            'rows_deleted' => 0,
            'files_deleted' => 0,
            'files_errors' => 0,
        ];
    }

    $selectStmt = $conn->query(
        "SELECT
            a.id_pago_contrato_archivo,
            LTRIM(RTRIM(ISNULL(a.ruta_relativa, ''))) AS ruta_relativa
         FROM dbo.msp_pago_contrato_archivos a
         LEFT JOIN dbo.msp_pagos p
            ON p.id_pago = a.id_pago
         LEFT JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = a.id_documento_cobro
         WHERE dc.id_documento_cobro IS NULL
            OR (UPPER(ISNULL(a.tipo_archivo, '')) <> 'VALE_COBRO' AND p.id_pago IS NULL)"
    );
    $rows = $selectStmt !== false ? ($selectStmt->fetchAll() ?: []) : [];
    if ($rows === []) {
        return [
            'rows_deleted' => 0,
            'files_deleted' => 0,
            'files_errors' => 0,
        ];
    }

    $idMap = [];
    $rutasMap = [];
    foreach ($rows as $row) {
        $idArchivo = (int) ($row['id_pago_contrato_archivo'] ?? 0);
        if ($idArchivo <= 0) {
            continue;
        }
        $idMap[$idArchivo] = true;
        $ruta = trim((string) ($row['ruta_relativa'] ?? ''));
        if ($ruta !== '') {
            $rutasMap[$ruta] = true;
        }
    }

    $ids = array_map('intval', array_keys($idMap));
    if ($ids === []) {
        return [
            'rows_deleted' => 0,
            'files_deleted' => 0,
            'files_errors' => 0,
        ];
    }

    $rowsDeleted = 0;
    foreach (array_chunk($ids, 400) as $chunk) {
        $placeholders = [];
        $deleteStmt = null;
        $sql = 'DELETE FROM dbo.msp_pago_contrato_archivos WHERE id_pago_contrato_archivo IN (';
        foreach ($chunk as $index => $idArchivo) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
        }
        $sql .= implode(', ', $placeholders) . ')';
        $deleteStmt = $conn->prepare($sql);
        foreach ($chunk as $index => $idArchivo) {
            $deleteStmt->bindValue(':id_' . $index, (int) $idArchivo, PDO::PARAM_INT);
        }
        $deleteStmt->execute();
        $rowsDeleted += (int) $deleteStmt->rowCount();
    }

    $filesDeleted = 0;
    $filesErrors = 0;
    $countByRutaStmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM dbo.msp_pago_contrato_archivos
         WHERE ruta_relativa = :ruta"
    );
    foreach (array_keys($rutasMap) as $rutaRelativa) {
        $countByRutaStmt->bindValue(':ruta', $rutaRelativa, PDO::PARAM_STR);
        $countByRutaStmt->execute();
        $stillReferenced = (int) ($countByRutaStmt->fetchColumn() ?: 0);
        if ($stillReferenced > 0) {
            continue;
        }
        if (!function_exists('msp2ArchivosPdfAbsolutePath')) {
            continue;
        }
        $absolutePath = msp2ArchivosPdfAbsolutePath($rutaRelativa);
        if (!is_file($absolutePath)) {
            continue;
        }
        if (@unlink($absolutePath)) {
            $filesDeleted++;
        } else {
            $filesErrors++;
        }
    }

    return [
        'rows_deleted' => $rowsDeleted,
        'files_deleted' => $filesDeleted,
        'files_errors' => $filesErrors,
    ];
}

/**
 * @return array{ok:bool,is_orphan:bool,reason:string}
 */
function msp2ArchivosPdfValidateRegenerationOrigin(PDO $conn, array $row): array
{
    $tipoArchivo = strtoupper(trim((string) ($row['tipo_archivo'] ?? '')));
    $idPago = (int) ($row['id_pago'] ?? 0);
    $idDocumento = (int) ($row['id_documento_cobro'] ?? 0);

    if ($idDocumento <= 0) {
        return [
            'ok' => false,
            'is_orphan' => true,
            'reason' => 'El respaldo no tiene documento de origen válido.',
        ];
    }

    if (!msp2TableExists($conn, 'msp_documentos_cobro')) {
        return [
            'ok' => false,
            'is_orphan' => true,
            'reason' => 'No existe la tabla de documentos para validar el origen.',
        ];
    }

    $docStmt = $conn->prepare(
        "SELECT TOP 1 1
         FROM dbo.msp_documentos_cobro
         WHERE id_documento_cobro = :id_documento"
    );
    $docStmt->bindValue(':id_documento', $idDocumento, PDO::PARAM_INT);
    $docStmt->execute();
    if ($docStmt->fetchColumn() === false) {
        return [
            'ok' => false,
            'is_orphan' => true,
            'reason' => 'El documento de origen ya no existe (respaldo huérfano).',
        ];
    }

    // Vale de cobro depende solo de documento; vale de pago/comprobante requieren pago vivo.
    if ($tipoArchivo !== 'VALE_COBRO') {
        if ($idPago <= 0) {
            return [
                'ok' => false,
                'is_orphan' => true,
                'reason' => 'El respaldo no tiene pago de origen válido.',
            ];
        }
        if (!msp2TableExists($conn, 'msp_pagos')) {
            return [
                'ok' => false,
                'is_orphan' => true,
                'reason' => 'No existe la tabla de pagos para validar el origen.',
            ];
        }

        $pagoStmt = $conn->prepare(
            "SELECT TOP 1 1
             FROM dbo.msp_pagos
             WHERE id_pago = :id_pago"
        );
        $pagoStmt->bindValue(':id_pago', $idPago, PDO::PARAM_INT);
        $pagoStmt->execute();
        if ($pagoStmt->fetchColumn() === false) {
            return [
                'ok' => false,
                'is_orphan' => true,
                'reason' => 'El pago de origen ya no existe (respaldo huérfano).',
            ];
        }
    }

    return [
        'ok' => true,
        'is_orphan' => false,
        'reason' => '',
    ];
}

function msp2ArchivosPdfRefreshMaterialized(PDO $conn, array $row, bool $force = false): array
{
    $relativePath = trim((string) ($row['ruta_relativa'] ?? ''));
    if ($relativePath === '') {
        throw new RuntimeException('El respaldo no tiene una ruta válida.');
    }

    $absolutePath = msp2ArchivosPdfAbsolutePath($relativePath);
    if (!$force && is_file($absolutePath)) {
        return [
            'absolute_path' => $absolutePath,
            'filename' => trim((string) ($row['nombre_archivo'] ?? 'documento.pdf')),
            'mime_type' => trim((string) ($row['mime_type'] ?? 'application/pdf')),
        ];
    }

    $payload = msp2ArchivosPdfDecodePayload((string) ($row['payload_json'] ?? ''));
    $typeDb = strtoupper(trim((string) ($row['tipo_archivo'] ?? 'VALE_PAGO')));
    $type = match ($typeDb) {
        'COMPROBANTE_GASTOS' => 'comprobante_gastos',
        'VALE_COBRO' => 'vale_cobro',
        default => 'vale_pago',
    };
    $item = [
        'type' => $type,
        'module' => (string) ($row['modulo_origen'] ?? ($payload['module'] ?? '')),
        'id_pago' => (int) ($row['id_pago'] ?? 0),
        'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
        'id_contrato_arriendo' => (int) ($row['id_contrato_arriendo'] ?? 0),
        'id_arrendatario' => (int) ($row['id_arrendatario'] ?? 0),
        'source_id_documento_cobro' => (int) ($payload['source_id_documento_cobro'] ?? 0),
        'pago_data' => is_array($payload['pago_data'] ?? null) ? $payload['pago_data'] : [],
        'arr_data' => is_array($payload['arr_data'] ?? null) ? $payload['arr_data'] : [],
        'doc_data' => is_array($payload['doc_data'] ?? null) ? $payload['doc_data'] : [],
    ];
    $item = msp2ArchivosPdfNormalizeItem($conn, $item);
    $rendered = msp2ArchivosPdfBuildPdf($conn, $type, $item);
    $filename = trim((string) ($rendered['filename'] ?? 'documento.pdf'));
    $bytes = (string) ($rendered['bytes'] ?? '');
    if ($bytes === '') {
        throw new RuntimeException('No fue posible regenerar el PDF respaldado.');
    }

    msp2ArchivosPdfWriteFile($absolutePath, $bytes);
    $hash = hash('sha256', $bytes);
    $byteCount = strlen($bytes);
    $stmt = $conn->prepare(
        "UPDATE dbo.msp_pago_contrato_archivos
         SET nombre_archivo = :nombre_archivo,
             mime_type = :mime_type,
             hash_sha256 = :hash_sha256,
             bytes_archivo = :bytes_archivo,
             estado_archivo = :estado_archivo,
             fecha_generacion = SYSDATETIME(),
             updated_at = SYSDATETIME()
         WHERE id_pago_contrato_archivo = :id"
    );
    $stmt->bindValue(':nombre_archivo', $filename, PDO::PARAM_STR);
    $stmt->bindValue(':mime_type', (string) ($rendered['mime_type'] ?? 'application/pdf'), PDO::PARAM_STR);
    $stmt->bindValue(':hash_sha256', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':bytes_archivo', $byteCount, PDO::PARAM_INT);
    $stmt->bindValue(':estado_archivo', 'REGENERADO', PDO::PARAM_STR);
    $stmt->bindValue(':id', (int) ($row['id_pago_contrato_archivo'] ?? 0), PDO::PARAM_INT);
    $stmt->execute();

    return [
        'absolute_path' => $absolutePath,
        'filename' => $filename,
        'mime_type' => (string) ($rendered['mime_type'] ?? 'application/pdf'),
    ];
}

function msp2ArchivosPdfRedirectListUrl(): string
{
    return 'pagos/archivos_pdf.php';
}

function msp2ArchivosPdfDownloadUrl(int $idArchivo, string $disposition = 'attachment'): string
{
    return msp2Url('pagos/descargar_archivo_pdf.php?id_archivo=' . $idArchivo . '&disposition=' . urlencode($disposition));
}
