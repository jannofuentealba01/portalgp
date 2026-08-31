<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pago_contrato_import_helper.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

msp2RequireAccess();

if (!rpcPagoContratoImportIsAdminUser($conn)) {
    msp2SetFlash('warning', 'La plantilla de importación de pagos está disponible solo para administradores.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible generar la plantilla Excel. Ejecuta `composer install` en la raíz del proyecto.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

try {
    $previousLevel = error_reporting();
    $previousDisplayErrors = (string) ini_get('display_errors');
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');

    try {
        $contratos = $conn->query(
            "SELECT
                c.id_contrato_arriendo,
                a.id_arrendatario,
                a.rut,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS arrendatario,
                COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS tienda,
                COUNT(*) AS documentos_pendientes,
                ROUND(SUM(dc.saldo_pendiente), 2) AS saldo_pendiente
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_contratos_arriendo c
                ON c.id_contrato_arriendo = dc.id_contrato_arriendo
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = c.id_arrendatario
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             WHERE dc.estado_documento IN (2,3)
               AND dc.saldo_pendiente > 0
             GROUP BY
                c.id_contrato_arriendo,
                a.id_arrendatario,
                a.rut,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut),
                COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda))
             ORDER BY arrendatario ASC, c.id_contrato_arriendo ASC"
        )->fetchAll() ?: [];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('PortalGP - Mercado San Pedro')
            ->setTitle('Plantilla de importación de pagos por contrato')
            ->setSubject('Carga masiva de pagos por contrato');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pagos');
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:G501');

        $headers = ['Arrendatario', 'Contrato', 'Monto', 'Fecha', 'Medio de pago', 'Ref', 'Banco'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '17365D']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->getStyle('A2:G501')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFDF2']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'D9E2F3']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('B2:B501')->getNumberFormat()->setFormatCode('0');
        $sheet->getStyle('C2:C501')->getNumberFormat()->setFormatCode('$#,##0.00');
        $sheet->getStyle('D2:D501')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        $medioValidation = new DataValidation();
        $medioValidation->setType(DataValidation::TYPE_LIST);
        $medioValidation->setErrorStyle(DataValidation::STYLE_STOP);
        $medioValidation->setAllowBlank(false);
        $medioValidation->setShowDropDown(true);
        $medioValidation->setShowErrorMessage(true);
        $medioValidation->setErrorTitle('Medio inválido');
        $medioValidation->setError('Selecciona Transferencia, Efectivo o Cheque.');
        $medioValidation->setFormula1('"Transferencia,Efectivo,Cheque"');
        for ($row = 2; $row <= 501; $row++) {
            $sheet->getCell('E' . $row)->setDataValidation(clone $medioValidation);
        }

        $sheet->getColumnDimension('A')->setWidth(29);
        $sheet->getColumnDimension('B')->setWidth(13);
        $sheet->getColumnDimension('C')->setWidth(17);
        $sheet->getColumnDimension('D')->setWidth(15);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(24);
        $sheet->getColumnDimension('G')->setWidth(24);

        $reference = $spreadsheet->createSheet();
        $reference->setTitle('Contratos con deuda');
        $reference->setShowGridlines(false);
        $reference->freezePane('A2');
        $referenceHeaders = ['Contrato', 'ID arrendatario', 'RUT', 'Arrendatario', 'Tienda', 'Documentos pendientes', 'Saldo pendiente'];
        $reference->fromArray($referenceHeaders, null, 'A1');
        if ($contratos !== []) {
            $referenceRows = array_map(static fn(array $row): array => [
                (int) ($row['id_contrato_arriendo'] ?? 0),
                (int) ($row['id_arrendatario'] ?? 0),
                (string) ($row['rut'] ?? ''),
                (string) ($row['arrendatario'] ?? ''),
                (string) ($row['tienda'] ?? ''),
                (int) ($row['documentos_pendientes'] ?? 0),
                (float) ($row['saldo_pendiente'] ?? 0),
            ], $contratos);
            $reference->fromArray($referenceRows, null, 'A2');
        }
        $referenceLastRow = max(2, count($contratos) + 1);
        $reference->setAutoFilter('A1:G' . $referenceLastRow);
        $reference->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '548235']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $reference->getStyle('G2:G' . $referenceLastRow)->getNumberFormat()->setFormatCode('$#,##0.00');
        foreach (['A' => 12, 'B' => 18, 'C' => 16, 'D' => 45, 'E' => 42, 'F' => 23, 'G' => 20] as $column => $width) {
            $reference->getColumnDimension($column)->setWidth($width);
        }

        $instructions = $spreadsheet->createSheet();
        $instructions->setTitle('Instrucciones');
        $instructions->setShowGridlines(false);
        $instructions->setCellValue('A1', 'Plantilla de importación de pagos por contrato');
        $instructions->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $instructions->getRowDimension(1)->setRowHeight(30);
        $instructionRows = [
            ['Paso', 'Indicación'],
            [1, 'En la hoja Pagos, completa una fila por cada pago recibido. No cambies los encabezados.'],
            [2, 'Arrendatario: usa el RUT, el ID o un nombre que coincida con el contrato.'],
            [3, 'Contrato: usa el ID numérico indicado en la hoja Contratos con deuda.'],
            [4, 'Monto: ingresa un número mayor que cero, sin texto adicional.'],
            [5, 'Fecha: usa una fecha válida; se recomienda el formato AAAA-MM-DD.'],
            [6, 'Medio de pago: selecciona Transferencia, Efectivo o Cheque.'],
            [7, 'Ref es opcional, excepto para Cheque: allí debes indicar el número del cheque.'],
            [8, 'Banco es obligatorio solo cuando el medio de pago sea Cheque.'],
            [9, 'Guarda el archivo como XLSX y súbelo en Previsualizar importación antes de confirmar.'],
            [10, 'El pago se distribuirá por antigüedad entre los documentos pendientes del contrato.'],
        ];
        $instructions->fromArray($instructionRows, null, 'A3');
        $instructions->getStyle('A3:B3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B9BD5']],
        ]);
        $instructions->getStyle('A4:A13')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $instructions->getStyle('B4:B13')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $instructions->getColumnDimension('A')->setWidth(10);
        $instructions->getColumnDimension('B')->setWidth(95);
        for ($row = 4; $row <= 13; $row++) {
            $instructions->getRowDimension($row)->setRowHeight(30);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $tmpFile = tempnam(sys_get_temp_dir(), 'msp_pagos_contrato_tpl_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal de la plantilla.');
        }

        $writer = new Xlsx($spreadsheet);
        msp2SaveSpreadsheetXlsx($writer, $tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $filename = 'plantilla_importacion_pagos_contrato_msp.xlsx';
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
} catch (Throwable $exception) {
    error_log('[MSP][Plantilla pagos contrato] ' . $exception->getMessage());
    msp2SetFlash('danger', 'No fue posible descargar la plantilla de pagos por contrato.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}
