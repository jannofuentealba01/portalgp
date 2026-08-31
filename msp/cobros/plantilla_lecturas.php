<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

msp2RequireAccess();

function msp2TplParseDate(mixed $value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }

    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $date !== false ? $date : null;
}

function msp2TplFloatOrNull(mixed $value): ?float
{
    if (is_float($value) || is_int($value)) {
        return (float) $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $raw = trim($value);
    if ($raw === '') {
        return null;
    }

    if (is_numeric($raw)) {
        return (float) $raw;
    }

    $rawWithDotDecimal = str_replace(',', '.', $raw);
    if (is_numeric($rawWithDotDecimal)) {
        return (float) $rawWithDotDecimal;
    }

    return null;
}

function msp2TplAddMonthsClamped(DateTimeImmutable $date, int $months): DateTimeImmutable
{
    $targetBase = $date->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
    $targetYear = (int) $targetBase->format('Y');
    $targetMonth = (int) $targetBase->format('m');
    $targetLastDay = (int) $targetBase->format('t');
    $targetDay = min((int) $date->format('d'), $targetLastDay);

    return $targetBase->setDate($targetYear, $targetMonth, $targetDay);
}

function msp2TplResolveHeaderDates(
    array $rows,
    ?DateTimeImmutable $fechaMedicionProceso,
    DateTimeImmutable $periodoConsumoDate
): array {
    $fechaReferenciaAnterior = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $fechaReferenciaAnterior = msp2TplParseDate($row['fecha_hasta_consumo_anterior'] ?? null);
        if ($fechaReferenciaAnterior === null) {
            $fechaReferenciaAnterior = msp2TplParseDate($row['fecha_valor_inicial'] ?? null);
        }
        if ($fechaReferenciaAnterior !== null) {
            break;
        }
    }

    $fechaReferenciaActual = $fechaMedicionProceso;

    if ($fechaReferenciaActual === null) {
        $fechaReferenciaActual = $periodoConsumoDate->modify('last day of this month');
    }

    if ($fechaReferenciaAnterior === null) {
        $fechaReferenciaAnterior = msp2TplAddMonthsClamped($fechaReferenciaActual, -1);
    }

    return [$fechaReferenciaAnterior, $fechaReferenciaActual];
}

$servicio = strtoupper(trim((string) ($_GET['servicio'] ?? '')));
$periodoYm = trim((string) ($_GET['periodo'] ?? ''));

if (!in_array($servicio, ['AGUA', 'LUZ', 'GAS'], true)) {
    msp2SetFlash('warning', 'Servicio inválido para generar plantilla.');
    msp2Redirect('cobros/operacion_mensual.php');
}

$periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
if ($periodoDate === false || $periodoDate->format('Y-m') !== $periodoYm) {
    $periodoYm = (new DateTimeImmutable('today'))->format('Y-m');
    $periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
}

if ($periodoDate === false) {
    msp2SetFlash('warning', 'Período inválido para generar plantilla.');
    msp2Redirect('cobros/operacion_mensual.php');
}

$periodoFacturacion = $periodoDate->format('Y-m-01');
$fechaHastaSugerida = $periodoDate->modify('last day of this month')->format('Y-m-d');
$aguaPeriodoConsumo = $periodoDate->modify('-2 months');
$aguaPeriodoConsumoYm = $aguaPeriodoConsumo->format('Y-m');
$aguaFechaInicioConsumo = $aguaPeriodoConsumo->format('Y-m-01');
$periodoConsumoDate = match ($servicio) {
    'LUZ', 'GAS' => $periodoDate->modify('-1 month'),
    'AGUA' => $periodoDate->modify('-2 months'),
    default => $periodoDate,
};
$periodoConsumoYm = $periodoConsumoDate->format('Y-m');

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $e) {
    msp2SetFlash('danger', 'No fue posible generar la plantilla Excel. Ejecuta `composer install` en la raíz del proyecto e intenta nuevamente.');
    msp2Redirect('cobros/operacion_mensual.php?periodo=' . urlencode($periodoYm));
}

try {
    $fechaMedicionProceso = null;
    $fechaProcesoStmt = $conn->prepare(
        "SELECT TOP (1)
            p.fecha_emision_origen
         FROM dbo.msp_procesos_cobro_servicio p
         INNER JOIN dbo.msp_cierre_mensual c
            ON c.id_cierre_mensual = p.id_cierre_mensual
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         WHERE c.periodo_facturacion = :periodo
           AND UPPER(ts.codigo_servicio) = :servicio
         ORDER BY p.id_proceso_cobro DESC"
    );
    $fechaProcesoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $fechaProcesoStmt->bindValue(':servicio', $servicio, PDO::PARAM_STR);
    $fechaProcesoStmt->execute();
    $fechaMedicionProceso = msp2TplParseDate($fechaProcesoStmt->fetchColumn());

    $filtroUltimaLectura = 'lm.periodo_facturacion < :periodo';
    if ($servicio === 'AGUA') {
        $filtroUltimaLectura = 'lm.fecha_hasta_consumo < :agua_inicio_consumo';
    }

    $stmt = $conn->prepare(
        "SELECT
            loc.cdo_local AS cod_local,
            ts.codigo_servicio,
            ts.nombre_servicio,
            m.codigo_medidor,
            m.alias_medidor,
            ult.lectura_actual AS lectura_anterior_real,
            ult.fecha_hasta_consumo AS fecha_hasta_consumo_anterior,
            m.fecha_instalacion AS fecha_valor_inicial,
            m.valor_inicial
         FROM dbo.msp_medidores m
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = m.id_tipo_servicio
         OUTER APPLY (
            SELECT TOP (1)
                lm.lectura_actual,
                lm.fecha_hasta_consumo
            FROM dbo.msp_lecturas_medidores lm
            WHERE lm.id_medidor = m.id_medidor
              AND {$filtroUltimaLectura}
            ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
         ) ult
         WHERE UPPER(ts.codigo_servicio) = :servicio
           AND m.estado_medidor = 1
           AND m.fecha_retiro IS NULL
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
    );
    if ($servicio === 'AGUA') {
        $stmt->bindValue(':agua_inicio_consumo', $aguaFechaInicioConsumo, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    }
    $stmt->bindValue(':servicio', $servicio, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($servicio . ' ' . $periodoConsumoYm);

    $headerLecturaAnterior = 'lectura_anterior (DD-MM)';
    $headerLecturaActual = 'lectura_actual (DD-MM)';
    if ($servicio === 'AGUA' || $servicio === 'LUZ' || $servicio === 'GAS') {
        [$fechaReferenciaAnterior, $fechaReferenciaActual] = msp2TplResolveHeaderDates(
            $rows,
            $fechaMedicionProceso,
            $periodoConsumoDate
        );

        $headerLecturaAnterior = 'lectura_anterior (' . $fechaReferenciaAnterior->format('d-m') . ')';
        $headerLecturaActual = 'lectura_actual (' . $fechaReferenciaActual->format('d-m') . ')';
    }

    if ($servicio === 'AGUA' || $servicio === 'LUZ' || $servicio === 'GAS') {
        $headers = [
            'cod_local',
            'codigo_medidor',
            $headerLecturaAnterior,
            $headerLecturaActual,
        ];
    } else {
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
            'observaciones',
        ];
    }

    foreach ($headers as $idx => $header) {
        $sheet->setCellValueByColumnAndRow($idx + 1, 1, $header);
    }

    $rowIndex = 2;
    foreach ($rows as $row) {
        $lecturaAnterior = msp2TplFloatOrNull($row['lectura_anterior_real'] ?? null);
        if ($lecturaAnterior === null) {
            $lecturaAnterior = msp2TplFloatOrNull($row['valor_inicial'] ?? null);
        }
        if ($lecturaAnterior === null) {
            $lecturaAnterior = 0.0;
        }

        if ($servicio === 'AGUA' || $servicio === 'LUZ' || $servicio === 'GAS') {
            $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($row['cod_local'] ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowIndex, (string) ($row['codigo_medidor'] ?? ''));
            $sheet->setCellValueByColumnAndRow(3, $rowIndex, $lecturaAnterior);
            $sheet->setCellValueByColumnAndRow(4, $rowIndex, '');
        } else {
            $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($row['cod_local'] ?? ''));
            $sheet->setCellValueByColumnAndRow(2, $rowIndex, (string) ($row['codigo_servicio'] ?? $servicio));
            $sheet->setCellValueByColumnAndRow(3, $rowIndex, (string) ($row['nombre_servicio'] ?? $servicio));
            $sheet->setCellValueByColumnAndRow(4, $rowIndex, (string) ($row['codigo_medidor'] ?? ''));
            $sheet->setCellValueByColumnAndRow(5, $rowIndex, (string) ($row['alias_medidor'] ?? ''));
            $sheet->setCellValueByColumnAndRow(6, $rowIndex, $lecturaAnterior);
            $sheet->setCellValueByColumnAndRow(7, $rowIndex, (string) ($row['fecha_hasta_consumo_anterior'] ?? ''));
            $sheet->setCellValueByColumnAndRow(8, $rowIndex, '');
            $sheet->setCellValueByColumnAndRow(9, $rowIndex, $fechaHastaSugerida);
            $sheet->setCellValueByColumnAndRow(10, $rowIndex, $fechaHastaSugerida);
            $sheet->setCellValueByColumnAndRow(11, $rowIndex, '');
        }
        $rowIndex++;
    }

    if ($rowIndex === 2) {
        $sheet->setCellValue('A2', 'SIN-DATOS');
        if ($servicio !== 'AGUA' && $servicio !== 'LUZ' && $servicio !== 'GAS') {
            $sheet->setCellValue('B2', $servicio);
        }
    }

    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
    for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex++) {
        $column = Coordinate::stringFromColumnIndex($columnIndex);
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_tpl_srv_');
    if ($tmpFile === false) {
        throw new RuntimeException('No fue posible crear un archivo temporal para la plantilla.');
    }

    $writer = new Xlsx($spreadsheet);
    msp2SaveSpreadsheetXlsx($writer, $tmpFile);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    $filename = sprintf(
        'lecturas_%s_%s.xlsx',
        strtolower($servicio),
        $periodoConsumoYm
    );

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
} catch (Throwable $e) {
    msp2SetFlash('danger', 'No fue posible generar la plantilla del servicio. Detalle técnico: ' . $e->getMessage());
    msp2Redirect('cobros/operacion_mensual.php?periodo=' . urlencode($periodoYm));
}
