<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

msp2RequireAccess();

$periodoYm = trim((string) ($_GET['periodo'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
if (preg_match('/^\d{4}-\d{2}$/', $periodoYm) !== 1 || !in_array($format, ['xlsx', 'pdf'], true)) {
    http_response_code(400);
    exit('Parámetros de reporte inválidos.');
}

$months = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];
$periodDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
$periodLabel = $periodDate instanceof DateTimeImmutable
    ? (($months[(int) $periodDate->format('n')] ?? $periodoYm) . ' ' . $periodDate->format('Y'))
    : strtoupper($periodoYm);

try {
    $processStmt = $conn->prepare(
        "SELECT TOP (1)
            p.id_proceso_cobro,
            p.fecha_emision_origen,
            pa.servicio_agua_potable,
            pa.servicio_alcantarillado,
            pa.tratamiento_aguas_servidas,
            pa.sobreconsumo,
            pa.interes_pf_plazo,
            pa.divisor,
            pa.cargo_fijo
         FROM dbo.msp_procesos_cobro_servicio p
         INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
         LEFT JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro=p.id_proceso_cobro
         WHERE UPPER(ts.codigo_servicio)=N'AGUA'
           AND EXISTS (
               SELECT 1 FROM dbo.msp_lecturas_medidores lm
               WHERE lm.id_proceso_cobro=p.id_proceso_cobro
                 AND CONVERT(CHAR(7),lm.fecha_hasta_consumo,126)=:periodo
           )
         ORDER BY p.id_proceso_cobro DESC"
    );
    $processStmt->execute([':periodo' => $periodoYm]);
    $process = $processStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $processId = (int) ($process['id_proceso_cobro'] ?? 0);
    if ($processId <= 0) {
        throw new RuntimeException('No existe proceso de AGUA para el período seleccionado.');
    }

    $divisor = (float) ($process['divisor'] ?? 0);
    $fixedCharge = (float) ($process['cargo_fijo'] ?? 0);
    $variableRate = 0.0;
    foreach (['servicio_agua_potable', 'servicio_alcantarillado', 'tratamiento_aguas_servidas', 'sobreconsumo', 'interes_pf_plazo'] as $component) {
        $variableRate += (float) ($process[$component] ?? 0);
    }

    $readingsStmt = $conn->prepare(
        "SELECT
            loc.cdo_local AS cod_local,
            m.codigo_medidor,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            cs.monto_total
         FROM dbo.msp_lecturas_medidores lm
         INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
         INNER JOIN dbo.msp_locales loc ON loc.id_local=m.id_local
         LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
         WHERE lm.id_proceso_cobro=:id
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ",m.codigo_medidor"
    );
    $readingsStmt->execute([':id' => $processId]);
    $rows = [];
    $totalConsumption = 0.0;
    $totalAmount = 0.0;
    foreach ($readingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $previous = (float) ($row['lectura_anterior'] ?? 0);
        $current = (float) ($row['lectura_actual'] ?? 0);
        $consumption = is_numeric((string) ($row['consumo_cobrado'] ?? null))
            ? max(0.0, (float) $row['consumo_cobrado'])
            : max(0.0, $current - $previous);
        $amount = is_numeric((string) ($row['monto_total'] ?? null))
            ? max(0.0, (float) $row['monto_total'])
            : ($divisor > 0 ? (($consumption * $variableRate / $divisor) + ($fixedCharge / $divisor)) : 0.0);
        $rows[] = [(string) ($row['cod_local'] ?? ''), (string) ($row['codigo_medidor'] ?? ''), $previous, $current, $consumption, round($amount, 2)];
        $totalConsumption += $consumption;
        $totalAmount += $amount;
    }

    $fileBase = 'consumo_agua_' . $periodoYm;
    if ($format === 'xlsx') {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Consumo agua');
        $sheet->mergeCells('A1:F1')->setCellValue('A1', 'CONSUMO DE AGUA - ' . $periodLabel);
        $sheet->fromArray([
            ['Consumo total (m³)', $totalConsumption, 'Monto total', $totalAmount, 'Divisor', $divisor],
            ['Tarifa variable', $variableRate, 'Cargo fijo', $fixedCharge, 'Fecha proceso', substr((string) ($process['fecha_emision_origen'] ?? ''), 0, 10)],
            [],
            ['Local', 'Medidor', 'Lectura anterior', 'Lectura actual', 'Consumo (m³)', 'Monto'],
        ], null, 'A3');
        $sheet->fromArray($rows, null, 'A7');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(15)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F766E');
        $sheet->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A6:F6')->getFont()->setBold(true);
        $sheet->getStyle('A6:F6')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEFEA');
        $sheet->getStyle('C7:F' . max(7, 6 + count($rows)))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new Xlsx($book))->save('php://output');
        exit;
    }

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('Dompdf no está disponible para generar el PDF.');
    }
    $bodyRows = '';
    foreach ($rows as $row) {
        $bodyRows .= '<tr><td>' . htmlspecialchars((string) $row[0]) . '</td><td>' . htmlspecialchars((string) $row[1]) . '</td><td class="n">' . number_format((float) $row[2], 2, ',', '.') . '</td><td class="n">' . number_format((float) $row[3], 2, ',', '.') . '</td><td class="n">' . number_format((float) $row[4], 2, ',', '.') . '</td><td class="n">$ ' . number_format((float) $row[5], 0, ',', '.') . '</td></tr>';
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#172b4d}h1{text-align:center;color:#0f766e;font-size:18px}.summary{margin:12px 0;padding:8px;background:#eef8f5}.summary span{display:inline-block;width:31%;margin:3px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:5px}th{background:#ddefea}.n{text-align:right}</style></head><body><h1>Consumo de agua - ' . htmlspecialchars($periodLabel) . '</h1><div class="summary"><span><b>Consumo:</b> ' . number_format($totalConsumption, 2, ',', '.') . ' m³</span><span><b>Monto:</b> $ ' . number_format($totalAmount, 0, ',', '.') . '</span><span><b>Divisor:</b> ' . number_format($divisor, 2, ',', '.') . '</span><span><b>Tarifa variable:</b> $ ' . number_format($variableRate, 0, ',', '.') . '</span><span><b>Cargo fijo:</b> $ ' . number_format($fixedCharge, 0, ',', '.') . '</span></div><table><thead><tr><th>Local</th><th>Medidor</th><th>Lectura anterior</th><th>Lectura actual</th><th>Consumo (m³)</th><th>Monto</th></tr></thead><tbody>' . $bodyRows . '</tbody></table></body></html>';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($options);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'landscape');
    $pdf->render();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $output = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileBase . '.pdf"');
    header('Content-Length: ' . strlen($output));
    echo $output;
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'No fue posible generar el reporte de agua. Detalle: ' . htmlspecialchars($exception->getMessage());
}
