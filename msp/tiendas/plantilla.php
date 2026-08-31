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
    msp2Redirect('tiendas/index.php');
}

try {
    $previousLevel = error_reporting();
    $previousDisplayErrors = (string) ini_get('display_errors');
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    ini_set('display_errors', '0');

    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tiendas');

        $headers = [
            'rut_arrendatario',
            'nombre_comercial',
            'cod_locales',
            'rubro',
            'estado_tienda',
            'fecha_inicio_tienda',
            'fecha_inicio_ocupacion',
            'fecha_termino_ocupacion',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
        }

        $sheet->setCellValue('A2', '21.217.950-7');
        $sheet->setCellValue('B2', 'Tienda Ejemplo');
        $sheet->setCellValue('C2', 'A-1;A-2');
        $sheet->setCellValue('D2', 'Alimentos y Bebidas');
        $sheet->setCellValue('E2', 'Activo');
        $sheet->setCellValue('F2', '10-2025');
        $sheet->setCellValue('G2', '');
        $sheet->setCellValue('H2', '');

        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_tiendas_tpl_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible crear archivo temporal para la plantilla.');
        }

        $writer = new Xlsx($spreadsheet);
        msp2SaveSpreadsheetXlsx($writer, $tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $filename = 'plantilla_importacion_tiendas_msp2.xlsx';

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
    msp2Redirect('tiendas/index.php');
}
