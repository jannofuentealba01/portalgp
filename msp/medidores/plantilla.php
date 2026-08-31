<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

msp2RequireAccess();

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible generar la plantilla Excel. Ejecuta `composer install` en la raíz del proyecto e intenta nuevamente.');
    msp2Redirect('catalogos/medidores.php');
}

try {
    $previousLevel = error_reporting();
    $previousDisplayErrors = (string) ini_get('display_errors');
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');

    try {
        $stmt = $conn->query(
            "SELECT
                cod_local,
                codigo_servicio,
                nombre_servicio,
                codigo_medidor,
                alias_medidor,
                lectura_anterior,
                fecha_hasta_consumo_anterior
             FROM dbo.msp_vw_plantilla_lecturas
             ORDER BY " . msp2LocalCodeNaturalOrderSql('cod_local') . ", codigo_servicio ASC, codigo_medidor ASC"
        );
        $rows = $stmt->fetchAll();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Lecturas');

        $headers = [
            'cod_local',
            'codigo_servicio',
            'nombre_servicio',
            'codigo_medidor',
            'alias_medidor',
            'lectura_anterior',
            'fecha_hasta_consumo_anterior',
            'lectura_actual',
            'fecha_hasta_consumo',
            'fecha_lectura',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($row['cod_local'] ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowIndex, (string) ($row['codigo_servicio'] ?? ''));
            $sheet->setCellValueByColumnAndRow(3, $rowIndex, (string) ($row['nombre_servicio'] ?? ''));
            $sheet->setCellValueByColumnAndRow(4, $rowIndex, (string) ($row['codigo_medidor'] ?? ''));
            $sheet->setCellValueByColumnAndRow(5, $rowIndex, (string) ($row['alias_medidor'] ?? ''));
            $sheet->setCellValueByColumnAndRow(6, $rowIndex, $row['lectura_anterior'] !== null ? (float) $row['lectura_anterior'] : null);
            $sheet->setCellValueByColumnAndRow(7, $rowIndex, (string) ($row['fecha_hasta_consumo_anterior'] ?? ''));
            $rowIndex++;
        }

        if ($rowIndex === 2) {
            $sheet->setCellValue('A2', 'L01');
            $sheet->setCellValue('B2', 'LUZ');
            $sheet->setCellValue('C2', 'Luz');
            $sheet->setCellValue('D2', 'L01-LUZ-01');
            $sheet->setCellValue('E2', 'Local 01 Luz 1');
            $sheet->setCellValue('F2', 123.45);
            $sheet->setCellValue('G2', '2025-01-31');
        }

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_medidores_tpl_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible crear archivo temporal para la plantilla.');
        }

        $writer = new Xlsx($spreadsheet);
        msp2SaveSpreadsheetXlsx($writer, $tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $filename = 'plantilla_lecturas_medidores_msp.xlsx';

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
    msp2SetFlash('danger', 'No fue posible descargar la plantilla Excel en este momento.');
    msp2Redirect('catalogos/medidores.php');
}
