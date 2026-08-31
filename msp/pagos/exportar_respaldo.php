<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

msp2RequireAccess();

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` e intenta nuevamente.');
    msp2Redirect('pagos/index.php');
}

$filters = msp2PagosNormalizeFilters($_GET);
$filters['filtroEstado'] = '1';
$estadoPago = msp2PagosEstadoMap();
$filterSql = msp2PagosBuildFilters($filters, $estadoPago);
$whereClause = $filterSql['where'];
$params = $filterSql['params'];

try {
    $pagosStmt = $conn->prepare(
        "SELECT
            p.id_pago,
            p.id_documento_cobro,
            p.fecha_pago,
            p.monto_pagado,
            p.monto_saldo_favor_generado,
            p.aplica_desde_saldo_favor,
            p.estado_pago,
            p.medio_pago,
            p.referencia_pago,
            p.observaciones,
            dc.id_tienda,
            dc.periodo_facturacion,
            dc.numero_documento,
            dc.nombre_arrendatario_snapshot,
            dc.rut_arrendatario_snapshot,
            t.nombre_comercial
         FROM dbo.msp_pagos p
         INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = p.id_documento_cobro
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         WHERE $whereClause
           AND p.estado_pago = 1
         ORDER BY p.fecha_pago ASC, p.id_pago ASC"
    );
    msp2PagosBindParams($pagosStmt, $params);
    $pagosStmt->execute();
    $pagosRows = $pagosStmt->fetchAll() ?: [];

    if ($pagosRows === []) {
        msp2SetFlash('warning', 'No hay pagos aplicados para exportar con los filtros actuales.');
        msp2Redirect('pagos/index.php?' . msp2PagosBuildQuery($_GET));
    }

    $detalleStmt = $conn->prepare(
        "SELECT
            pdc.id_pago,
            tid.codigo_item,
            tid.nombre_item,
            pdc.monto_aplicado
         FROM dbo.msp_pagos_detalle_concepto pdc
         INNER JOIN dbo.msp_pagos p
            ON p.id_pago = pdc.id_pago
         INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = p.id_documento_cobro
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = pdc.id_tipo_item_documento
         WHERE $whereClause
           AND p.estado_pago = 1
         ORDER BY p.fecha_pago ASC, p.id_pago ASC, tid.codigo_item ASC"
    );
    msp2PagosBindParams($detalleStmt, $params);
    $detalleStmt->execute();
    $detalleRows = $detalleStmt->fetchAll() ?: [];
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible preparar el respaldo de pagos.');
    msp2Redirect('pagos/index.php?' . msp2PagosBuildQuery($_GET));
}

$detalleByPago = [];
foreach ($detalleRows as $row) {
    $idPago = (int) ($row['id_pago'] ?? 0);
    if ($idPago <= 0) {
        continue;
    }

    if (!isset($detalleByPago[$idPago])) {
        $detalleByPago[$idPago] = [];
    }
    $detalleByPago[$idPago][] = $row;
}

try {
    $previousLevel = error_reporting();
    $previousDisplayErrors = (string) ini_get('display_errors');
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');

    try {
        $spreadsheet = new Spreadsheet();

        $readmeSheet = $spreadsheet->getActiveSheet();
        $readmeSheet->setTitle(msp2PagosBackupSheetReadme());
        $readmeRows = [
            ['Formato', msp2PagosBackupVersion()],
            ['Objetivo', 'Respaldo reimportable de pagos aplicados del módulo MSP.'],
            ['Regla 1', 'La hoja Pagos contiene una fila por pago aplicado.'],
            ['Regla 2', 'La hoja DetalleConceptos contiene la distribución por concepto enlazada por pago_uid.'],
            ['Regla 3', 'La reimportación resuelve el documento por id_tienda + periodo_facturacion.'],
            ['Regla 4', 'orden_replay define el orden exacto de recreación de pagos.'],
            ['Regla 5', 'No editar encabezados ni nombres de hojas.'],
            ['Generado', date('Y-m-d H:i:s')],
        ];
        foreach ($readmeRows as $index => $row) {
            $readmeSheet->setCellValue('A' . ($index + 1), $row[0]);
            $readmeSheet->setCellValue('B' . ($index + 1), $row[1]);
        }
        $readmeSheet->getStyle('A1:A8')->getFont()->setBold(true);
        $readmeSheet->getColumnDimension('A')->setAutoSize(true);
        $readmeSheet->getColumnDimension('B')->setWidth(90);

        $pagosSheet = $spreadsheet->createSheet();
        $pagosSheet->setTitle(msp2PagosBackupSheetPagos());
        $pagosHeaders = msp2PagosBackupHeadersPagos();
        foreach ($pagosHeaders as $index => $header) {
            $pagosSheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        $pagosSheet->getStyle('A1:T1')->getFont()->setBold(true);
        $pagosSheet->freezePane('A2');

        $detalleSheet = $spreadsheet->createSheet();
        $detalleSheet->setTitle(msp2PagosBackupSheetDetalle());
        $detalleHeaders = msp2PagosBackupHeadersDetalle();
        foreach ($detalleHeaders as $index => $header) {
            $detalleSheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }
        $detalleSheet->getStyle('A1:F1')->getFont()->setBold(true);
        $detalleSheet->freezePane('A2');

        $pagoRowIndex = 2;
        $detalleRowIndex = 2;

        foreach ($pagosRows as $order => $pago) {
            $idPago = (int) ($pago['id_pago'] ?? 0);
            $pagoUid = msp2PagosBackupMakeUid($idPago);
            $detallePago = $detalleByPago[$idPago] ?? [];
            $montoAplicadoDocumento = 0.0;
            foreach ($detallePago as $detalleItem) {
                $montoAplicadoDocumento += (float) ($detalleItem['monto_aplicado'] ?? 0);
            }

            $pagosData = [
                msp2PagosBackupVersion(),
                $pagoUid,
                $order + 1,
                $idPago,
                (int) ($pago['id_documento_cobro'] ?? 0),
                (int) ($pago['id_tienda'] ?? 0),
                (string) ($pago['nombre_comercial'] ?? ''),
                substr((string) ($pago['periodo_facturacion'] ?? ''), 0, 10),
                (string) ($pago['numero_documento'] ?? ''),
                (string) ($pago['rut_arrendatario_snapshot'] ?? ''),
                (string) ($pago['nombre_arrendatario_snapshot'] ?? ''),
                substr((string) ($pago['fecha_pago'] ?? ''), 0, 10),
                round((float) ($pago['monto_pagado'] ?? 0), 2),
                round($montoAplicadoDocumento, 2),
                round((float) ($pago['monto_saldo_favor_generado'] ?? 0), 2),
                (int) ($pago['aplica_desde_saldo_favor'] ?? 0),
                (string) ($pago['medio_pago'] ?? ''),
                (string) ($pago['referencia_pago'] ?? ''),
                (string) ($pago['observaciones'] ?? ''),
                'Aplicado',
            ];

            foreach ($pagosData as $columnIndex => $value) {
                $pagosSheet->setCellValueByColumnAndRow($columnIndex + 1, $pagoRowIndex, $value);
            }

            foreach ($detallePago as $detalleOrder => $detalleItem) {
                $detalleData = [
                    msp2PagosBackupVersion(),
                    $pagoUid,
                    $detalleOrder + 1,
                    (string) ($detalleItem['codigo_item'] ?? ''),
                    (string) ($detalleItem['nombre_item'] ?? ''),
                    round((float) ($detalleItem['monto_aplicado'] ?? 0), 2),
                ];
                foreach ($detalleData as $columnIndex => $value) {
                    $detalleSheet->setCellValueByColumnAndRow($columnIndex + 1, $detalleRowIndex, $value);
                }
                $detalleRowIndex++;
            }

            $pagoRowIndex++;
        }

        foreach (range('A', 'T') as $column) {
            $pagosSheet->getColumnDimension($column)->setAutoSize(true);
        }
        foreach (range('A', 'F') as $column) {
            $detalleSheet->getColumnDimension($column)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_pagos_backup_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible crear archivo temporal para el respaldo.');
        }

        $writer = new Xlsx($spreadsheet);
        msp2SaveSpreadsheetXlsx($writer, $tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $filename = 'msp_pagos_backup_' . date('Ymd_His') . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        header('Expires: 0');
        header('Content-Length: ' . (string) filesize($tmpFile));

        readfile($tmpFile);
        @unlink($tmpFile);
        exit();
    } finally {
        error_reporting($previousLevel);
        ini_set('display_errors', $previousDisplayErrors);
    }
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible descargar el respaldo de pagos en este momento.');
    msp2Redirect('pagos/index.php?' . msp2PagosBuildQuery($_GET));
}
