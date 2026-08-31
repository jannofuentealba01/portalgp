<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

msp2RequireAccess();

function ceMonthYearLabel(string $periodoYm): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return strtoupper($periodoYm);
    }

    $months = [
        1 => 'ENERO',
        2 => 'FEBRERO',
        3 => 'MARZO',
        4 => 'ABRIL',
        5 => 'MAYO',
        6 => 'JUNIO',
        7 => 'JULIO',
        8 => 'AGOSTO',
        9 => 'SEPTIEMBRE',
        10 => 'OCTUBRE',
        11 => 'NOVIEMBRE',
        12 => 'DICIEMBRE',
    ];

    return ($months[(int) $d->format('n')] ?? strtoupper($periodoYm)) . ' ' . $d->format('Y');
}

function ceFileMonthYearLabel(string $periodoYm): string
{
    $label = mb_strtolower(ceMonthYearLabel($periodoYm), 'UTF-8');
    return str_replace(' ', '_', $label);
}

function ceFmtDdMm(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'DD-MM';
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m') : 'DD-MM';
}

function ceFmtNum(mixed $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function ceFmtAPagar(mixed $value, int $decimals = 0): string
{
    $num = (float) $value;
    if (abs($num) < 0.000001) {
        return '-';
    }

    return ceFmtNum($num, $decimals);
}

function ceNormalizeWorksheetCellTypes(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
{
    foreach ($sheet->getCoordinates() as $coordinate) {
        $cell = $sheet->getCell($coordinate);
        $value = $cell->getValue();
        if (is_object($value)) {
            continue;
        }
        if (!is_float($value)) {
            continue;
        }

        $dataType = $cell->getDataType();
        if ($dataType === DataType::TYPE_STRING || $dataType === DataType::TYPE_STRING2 || $dataType === DataType::TYPE_NULL) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
        }
    }
}

function ceGroupKeyFromLocal(string $codLocal): string
{
    $local = strtoupper(trim($codLocal));
    if ($local === '') {
        return '-';
    }

    if (preg_match('/^([A-Z]+)-/', $local, $m) === 1) {
        return $m[1];
    }

    $parts = explode('-', $local, 2);
    return trim((string) ($parts[0] ?? '')) !== '' ? trim((string) $parts[0]) : $local;
}

function ceGroupKeyFromRow(array $row): string
{
    $idDocumento = (int) ($row['id_documento_cobro'] ?? 0);
    $idTiendaDoc = (int) ($row['id_tienda_doc'] ?? 0);
    $idTienda = (int) ($row['id_tienda'] ?? 0);
    $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
    $idOcupacion = (int) ($row['id_ocupacion_local'] ?? 0);
    $idTiendaOcupacion = (int) ($row['id_tienda_ocupacion'] ?? 0);
    $idLocal = (int) ($row['id_local'] ?? 0);

    // Prioridad de agrupacion de negocio:
    // 1) Documento/Tienda resultante del cobro ya generado
    // 2) Contrato + Tienda
    // 3) Ocupacion vigente
    // 4) Local
    if ($idDocumento > 0) {
        return 'D' . $idDocumento;
    }
    if ($idTiendaDoc > 0) {
        return 'TD' . $idTiendaDoc;
    }
    if ($idContrato > 0) {
        return 'C' . $idContrato . '|T' . $idTienda;
    }
    if ($idOcupacion > 0) {
        return 'O' . $idOcupacion;
    }
    if ($idTiendaOcupacion > 0) {
        return 'TO' . $idTiendaOcupacion;
    }
    if ($idTienda > 0) {
        return 'T' . $idTienda;
    }
    if ($idLocal > 0) {
        return 'L' . $idLocal;
    }

    return ceGroupKeyFromLocal((string) ($row['cod_local'] ?? ''));
}

function ceResolveHeaderDates(array $rows, ?string $fechaProceso): array
{
    $fechaActual = 'DD-MM';
    $fechaAnterior = 'DD-MM';

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fechaActualRaw = (string) ($row['fecha_actual'] ?? '');
        $fechaAnteriorRaw = (string) ($row['fecha_anterior'] ?? '');
        if ($fechaActual === 'DD-MM' && $fechaActualRaw !== '') {
            $fechaActual = ceFmtDdMm($fechaActualRaw);
        }
        if ($fechaAnterior === 'DD-MM' && $fechaAnteriorRaw !== '') {
            $fechaAnterior = ceFmtDdMm($fechaAnteriorRaw);
        }
        if ($fechaActual !== 'DD-MM' && $fechaAnterior !== 'DD-MM') {
            break;
        }
    }

    if ($fechaActual === 'DD-MM' && $fechaProceso !== null && $fechaProceso !== '') {
        $fechaActual = ceFmtDdMm($fechaProceso);
    }

    if ($fechaAnterior === 'DD-MM') {
        $base = DateTimeImmutable::createFromFormat('Y-m-d', substr((string) ($fechaProceso ?? ''), 0, 10));
        if ($base !== false) {
            $fechaAnterior = $base->modify('-1 month')->format('d-m');
        }
    }

    return [$fechaAnterior, $fechaActual];
}

function ceNextPeriodoYm(string $periodoYm): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return $periodoYm;
    }

    return $d->modify('+1 month')->format('Y-m');
}

function ceBuildAddendumRows(array $rows): array
{
    $addRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $addRows[] = [
            'cod_local' => (string) ($row['cod_local'] ?? ''),
            'lectura_anterior' => (float) ($row['lectura_actual'] ?? 0),
        ];
    }

    return $addRows;
}

function ceFetchReportDataset(PDO $conn, string $periodoConsumoYm): array
{
    $procesoStmt = $conn->prepare(
        "SELECT TOP (1)
            p.id_proceso_cobro,
            p.fecha_emision_origen,
            pg.factor,
            pg.valor_litro,
            c.periodo_facturacion
         FROM dbo.msp_procesos_cobro_servicio p
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         LEFT JOIN dbo.msp_proceso_cobro_gas pg
            ON pg.id_proceso_cobro = p.id_proceso_cobro
         LEFT JOIN dbo.msp_cierre_mensual c
            ON c.id_cierre_mensual = p.id_cierre_mensual
         WHERE UPPER(ts.codigo_servicio) = 'GAS'
           AND EXISTS (
                SELECT 1
                FROM dbo.msp_lecturas_medidores lm_check
                WHERE lm_check.id_proceso_cobro = p.id_proceso_cobro
                  AND CONVERT(CHAR(7), lm_check.fecha_hasta_consumo, 126) = :periodo_consumo
           )
         ORDER BY p.id_proceso_cobro DESC"
    );
    $procesoStmt->bindValue(':periodo_consumo', $periodoConsumoYm, PDO::PARAM_STR);
    $procesoStmt->execute();
    $proceso = $procesoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $idProceso = (int) ($proceso['id_proceso_cobro'] ?? 0);
    if ($idProceso <= 0) {
        throw new RuntimeException('No existe proceso de GAS para el período de consumo seleccionado.');
    }
    $factorProceso = round((float) ($proceso['factor'] ?? 0), 6);
    $valorLitroProceso = round((float) ($proceso['valor_litro'] ?? 0), 6);
    if ($factorProceso <= 0 || $valorLitroProceso <= 0) {
        throw new RuntimeException('Debes guardar FACTOR y VALOR_LITRO válidos en el paso de GAS.');
    }

    $periodoRefRaw = substr((string) ($proceso['periodo_facturacion'] ?? ''), 0, 10);
    $periodoRef = $periodoRefRaw !== '' ? $periodoRefRaw : ($periodoConsumoYm . '-01');

    $hasContratoTables =
        msp2TableExists($conn, 'msp_contrato_locales')
        && msp2TableExists($conn, 'msp_contratos_arriendo');
    $hasOcupacionTable = msp2TableExists($conn, 'msp_ocupacion_locales');
    $hasDocumentoTables =
        msp2TableExists($conn, 'msp_cobros_servicios')
        && msp2TableExists($conn, 'msp_documentos_cobro_detalle')
        && msp2TableExists($conn, 'msp_documentos_cobro');

    $lecturasSql = "DECLARE @periodo_ref DATE = :periodo_ref;
         SELECT
            loc.id_local,
            loc.cdo_local AS cod_local,
            m.codigo_medidor,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            cs.monto_total,
            COALESCE(lm.fecha_desde_consumo, prev.fecha_hasta_consumo) AS fecha_anterior,
            lm.fecha_hasta_consumo AS fecha_actual";
    if ($hasDocumentoTables) {
        $lecturasSql .= ",
            dctx.id_documento_cobro,
            dctx.id_tienda_doc";
    } else {
        $lecturasSql .= ",
            CAST(NULL AS INT) AS id_documento_cobro,
            CAST(NULL AS INT) AS id_tienda_doc";
    }
    if ($hasContratoTables) {
        $lecturasSql .= ",
            cctx.id_contrato_arriendo,
            cctx.id_tienda,
            " . ($hasOcupacionTable ? "octx.id_ocupacion_local" : "CAST(NULL AS INT) AS id_ocupacion_local");
    } else {
        $lecturasSql .= ",
            CAST(NULL AS INT) AS id_contrato_arriendo,
            CAST(NULL AS INT) AS id_tienda,
            CAST(NULL AS INT) AS id_ocupacion_local";
    }
    if ($hasOcupacionTable) {
        $lecturasSql .= ",
            oany.id_tienda AS id_tienda_ocupacion";
    } else {
        $lecturasSql .= ",
            CAST(NULL AS INT) AS id_tienda_ocupacion";
    }

    $lecturasSql .= "
         FROM dbo.msp_lecturas_medidores lm
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         LEFT JOIN dbo.msp_cobros_servicios cs
            ON cs.id_lectura = lm.id_lectura
         OUTER APPLY (
            SELECT TOP (1)
                lprev.fecha_hasta_consumo
            FROM dbo.msp_lecturas_medidores lprev
            WHERE lprev.id_medidor = lm.id_medidor
              AND (
                    lprev.fecha_hasta_consumo < lm.fecha_hasta_consumo
                    OR (lprev.fecha_hasta_consumo = lm.fecha_hasta_consumo AND lprev.id_lectura < lm.id_lectura)
              )
            ORDER BY lprev.fecha_hasta_consumo DESC, lprev.id_lectura DESC
         ) prev";
    if ($hasDocumentoTables) {
        $lecturasSql .= "
         OUTER APPLY (
            SELECT TOP (1)
                dcd.id_documento_cobro,
                dc.id_tienda AS id_tienda_doc
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dcd.id_cobro_servicio = cs.id_cobro_servicio
              AND dc.periodo_facturacion = @periodo_ref
            ORDER BY dcd.id_documento_cobro DESC
         ) dctx";
    }
    if ($hasContratoTables) {
        $lecturasSql .= "
         OUTER APPLY (
            SELECT TOP (1)
                cl.id_contrato_arriendo,
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_ref)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_ref)
              AND ca.fecha_inicio <= EOMONTH(@periodo_ref)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_ref)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_ref THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_ref THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_ref THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
         ) cctx";
    }
    if ($hasContratoTables && $hasOcupacionTable) {
        $lecturasSql .= "
         OUTER APPLY (
            SELECT TOP (1)
                ol.id_ocupacion_local
            FROM dbo.msp_ocupacion_locales ol
            WHERE ol.id_local = m.id_local
              AND ol.id_tienda = cctx.id_tienda
              AND ol.fecha_inicio <= EOMONTH(@periodo_ref)
              AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= @periodo_ref)
            ORDER BY ol.fecha_inicio DESC, ol.id_ocupacion_local DESC
         ) octx";
    }
    if ($hasOcupacionTable) {
        $lecturasSql .= "
         OUTER APPLY (
            SELECT TOP (1)
                ol.id_tienda
            FROM dbo.msp_ocupacion_locales ol
            WHERE ol.id_local = m.id_local
              AND ol.fecha_inicio <= EOMONTH(@periodo_ref)
              AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= @periodo_ref)
            ORDER BY ol.fecha_inicio DESC, ol.id_ocupacion_local DESC
         ) oany";
    }

    $lecturasSql .= "
         WHERE lm.id_proceso_cobro = :id_proceso
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC";

    $lecturasStmt = $conn->prepare($lecturasSql);
    $lecturasStmt->bindValue(':periodo_ref', $periodoRef, PDO::PARAM_STR);
    $lecturasStmt->bindValue(':id_proceso', $idProceso, PDO::PARAM_INT);
    $lecturasStmt->execute();
    $dbRows = $lecturasStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($dbRows === []) {
        throw new RuntimeException('No hay lecturas de GAS registradas para este proceso.');
    }

    $rows = [];
    foreach ($dbRows as $row) {
        $lecturaAnterior = is_numeric((string) ($row['lectura_anterior'] ?? null)) ? (float) $row['lectura_anterior'] : 0.0;
        $lecturaActual = is_numeric((string) ($row['lectura_actual'] ?? null)) ? (float) $row['lectura_actual'] : 0.0;
        $consumidoLectura = max(0.0, round($lecturaActual - $lecturaAnterior, 4));
        $consumidoCobrado = is_numeric((string) ($row['consumo_cobrado'] ?? null)) ? (float) $row['consumo_cobrado'] : null;
        $montoTotalCobro = is_numeric((string) ($row['monto_total'] ?? null)) ? (float) $row['monto_total'] : null;

        $consumido = $consumidoCobrado !== null ? max(0.0, round($consumidoCobrado, 4)) : $consumidoLectura;
        $aPagar = $montoTotalCobro !== null
            ? round(max(0.0, $montoTotalCobro), 2)
            : round($consumido * $factorProceso * $valorLitroProceso, 2);

        $rows[] = [
            'id_local' => (int) ($row['id_local'] ?? 0),
            'cod_local' => (string) ($row['cod_local'] ?? ''),
            'lectura_anterior' => $lecturaAnterior,
            'lectura_actual' => $lecturaActual,
            'total_consumido' => $consumido,
            'factor' => $factorProceso,
            'valor_litro' => $valorLitroProceso,
            'a_pagar' => $aPagar,
            'fecha_anterior' => (string) ($row['fecha_anterior'] ?? ''),
            'fecha_actual' => (string) ($row['fecha_actual'] ?? ''),
            'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
            'id_tienda_doc' => (int) ($row['id_tienda_doc'] ?? 0),
            'id_tienda' => (int) ($row['id_tienda'] ?? 0),
            'id_contrato_arriendo' => (int) ($row['id_contrato_arriendo'] ?? 0),
            'id_ocupacion_local' => (int) ($row['id_ocupacion_local'] ?? 0),
            'id_tienda_ocupacion' => (int) ($row['id_tienda_ocupacion'] ?? 0),
        ];
    }

    return [
        'rows' => $rows,
        'factor' => $factorProceso,
        'valor_litro' => $valorLitroProceso,
        'fecha_proceso' => (string) ($proceso['fecha_emision_origen'] ?? ''),
    ];
}

$servicio = strtoupper(trim((string) ($_GET['servicio'] ?? 'GAS')));
$periodoYm = trim((string) ($_GET['periodo'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
$includeNextAddendum = in_array(
    strtolower(trim((string) ($_GET['anadido_siguiente'] ?? '0'))),
    ['1', 'true', 'si', 'yes'],
    true
);

if ($servicio !== 'GAS') {
    http_response_code(400);
    echo 'Este reporte es solo para GAS.';
    exit();
}

if (preg_match('/^\d{4}-\d{2}$/', $periodoYm) !== 1) {
    http_response_code(400);
    echo 'Periodo inválido. Debe venir como YYYY-MM.';
    exit();
}

if (!in_array($format, ['xlsx', 'pdf', 'debug'], true)) {
    http_response_code(400);
    echo 'Formato inválido. Usa format=xlsx, format=pdf o format=debug.';
    exit();
}

$title = 'CONSUMO GAS ' . ceMonthYearLabel($periodoYm);

try {
    $dataset = ceFetchReportDataset($conn, $periodoYm);
    $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
    $totalAPagar = 0.0;
    foreach ($rows as $sumRow) {
        $totalAPagar += (float) ($sumRow['a_pagar'] ?? 0);
    }
    $totalAPagar = round($totalAPagar, 2);
    $fechaProceso = (string) ($dataset['fecha_proceso'] ?? '');
    [$fechaAnteriorHdr, $fechaActualHdr] = ceResolveHeaderDates($rows, $fechaProceso !== '' ? $fechaProceso : null);
    $headers = [
        'Local',
        'med. ant. (' . $fechaAnteriorHdr . ')',
        'med. act. (' . $fechaActualHdr . ')',
        'TOTAL CONSUMIDO',
        'FACTOR',
        'VALOR_LITRO',
        'A PAGAR',
    ];
    $nextPeriodoYm = ceNextPeriodoYm($periodoYm);
    $nextPeriodoPdfTitle = 'CONSUMO GAS ' . (
        preg_match('/^\d{4}-\d{2}$/', $nextPeriodoYm) === 1
            ? ceMonthYearLabel($nextPeriodoYm)
            : strtoupper($nextPeriodoYm)
    );
    $addendumHeaders = [
        'Local',
        'med. ant. (' . $fechaActualHdr . ')',
        'med. act.',
    ];
    $addendumRows = $includeNextAddendum ? ceBuildAddendumRows($rows) : [];

    if ($format === 'debug') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="UTF-8"><title>Debug agrupación gas</title>'
            . '<style>body{font-family:Arial,sans-serif;font-size:13px;padding:16px;}'
            . 'table{border-collapse:collapse;width:100%;margin-top:12px;}'
            . 'th,td{border:1px solid #999;padding:6px;}th{background:#f2f2f2;}'
            . '.muted{color:#666;}</style></head><body>';
        echo '<h2>Debug agrupación consumo gas</h2>';
        echo '<div><strong>Servicio:</strong> GAS</div>';
        echo '<div><strong>Periodo:</strong> ' . msp2Escape($periodoYm) . '</div>';
        echo '<div class="muted">Si `group_key` cambia fila a fila, la agrupación cambia de color.</div>';
        echo '<table><thead><tr>'
            . '<th>#</th>'
            . '<th>cod_local</th>'
            . '<th>id_local</th>'
            . '<th>id_tienda</th>'
            . '<th>id_tienda_doc</th>'
            . '<th>id_contrato_arriendo</th>'
            . '<th>id_ocupacion_local</th>'
            . '<th>id_tienda_ocupacion</th>'
            . '<th>id_documento_cobro</th>'
            . '<th>group_key</th>'
            . '</tr></thead><tbody>';
        $idx = 1;
        foreach ($rows as $dbgRow) {
            echo '<tr>'
                . '<td>' . $idx++ . '</td>'
                . '<td>' . msp2Escape((string) ($dbgRow['cod_local'] ?? '')) . '</td>'
                . '<td>' . (int) ($dbgRow['id_local'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_tienda'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_tienda_doc'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_contrato_arriendo'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_ocupacion_local'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_tienda_ocupacion'] ?? 0) . '</td>'
                . '<td>' . (int) ($dbgRow['id_documento_cobro'] ?? 0) . '</td>'
                . '<td>' . msp2Escape(ceGroupKeyFromRow($dbgRow)) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
        echo '</body></html>';
        exit();
    }

    if ($format === 'xlsx') {
        msp2LoadSpreadsheetLibrary();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumo gas');

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        foreach ($headers as $idx => $header) {
            $sheet->setCellValueByColumnAndRow($idx + 1, 3, $header);
        }
        $sheet->getStyle('A3:G3')->getFont()->setBold(true);
        $sheet->getStyle('A3:G3')->getFont()->getColor()->setRGB('000000');
        $sheet->getStyle('A3:G3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A3:G3')->getFill()->getStartColor()->setRGB('FF8989');

        $rowIndex = 4;
        $previousGroupKey = null;
        $useGrayGroup = false;
        foreach ($rows as $row) {
            $groupKey = ceGroupKeyFromRow($row);
            if ($previousGroupKey !== null && $groupKey !== $previousGroupKey) {
                $useGrayGroup = !$useGrayGroup;
            }
            $previousGroupKey = $groupKey;

            $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($row['cod_local'] ?? ''));
            $sheet->setCellValueExplicitByColumnAndRow(
                2,
                $rowIndex,
                (float) ($row['lectura_anterior'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $sheet->setCellValueExplicitByColumnAndRow(
                3,
                $rowIndex,
                (float) ($row['lectura_actual'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $sheet->setCellValueExplicitByColumnAndRow(
                4,
                $rowIndex,
                (float) ($row['total_consumido'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $sheet->setCellValueExplicitByColumnAndRow(
                5,
                $rowIndex,
                (float) ($row['factor'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $sheet->setCellValueExplicitByColumnAndRow(
                6,
                $rowIndex,
                (float) ($row['valor_litro'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $aPagarRow = (float) ($row['a_pagar'] ?? 0);
            if (abs($aPagarRow) < 0.000001) {
                $sheet->setCellValueByColumnAndRow(7, $rowIndex, '-');
            } else {
                $sheet->setCellValueExplicitByColumnAndRow(
                    7,
                    $rowIndex,
                    $aPagarRow,
                    DataType::TYPE_NUMERIC
                );
            }
            if ($useGrayGroup) {
                $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
                $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->getFill()->getStartColor()->setRGB('BFC8A2');
            }
            $rowIndex++;
        }

        $sheet->mergeCells('A' . $rowIndex . ':F' . $rowIndex);
        $sheet->setCellValue('A' . $rowIndex, 'TOTAL A PAGAR');
        if (abs($totalAPagar) < 0.000001) {
            $sheet->setCellValue('G' . $rowIndex, '-');
        } else {
            $sheet->setCellValueExplicit(
                'G' . $rowIndex,
                $totalAPagar,
                DataType::TYPE_NUMERIC
            );
        }
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->getFont()->setBold(true);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('A' . $rowIndex . ':G' . $rowIndex)->getFill()->getStartColor()->setRGB('F8E5E2');
        $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0');

        if ($addendumRows !== []) {
            $sheet->getStyle('B4:D' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E4:F' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('G4:G' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('A3:G' . $rowIndex)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setRGB('000000');

            $rowIndex += 2;
            $sheet->mergeCells('A' . $rowIndex . ':C' . $rowIndex);
            $sheet->setCellValue('A' . $rowIndex, $nextPeriodoPdfTitle);
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A' . $rowIndex)->getAlignment()->setHorizontal('left');

            $rowIndex++;
            foreach ($addendumHeaders as $idx => $header) {
                $sheet->setCellValueByColumnAndRow($idx + 1, $rowIndex, $header);
            }
            $sheet->getStyle('A' . $rowIndex . ':C' . $rowIndex)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowIndex . ':C' . $rowIndex)->getFont()->getColor()->setRGB('000000');
            $sheet->getStyle('A' . $rowIndex . ':C' . $rowIndex)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
            $sheet->getStyle('A' . $rowIndex . ':C' . $rowIndex)->getFill()->getStartColor()->setRGB('FFE2A8');

            $rowIndex++;
            $addendumStartRow = $rowIndex;
            foreach ($addendumRows as $addRow) {
                $sheet->setCellValueByColumnAndRow(1, $rowIndex, (string) ($addRow['cod_local'] ?? ''));
                $sheet->setCellValueByColumnAndRow(2, $rowIndex, (float) ($addRow['lectura_anterior'] ?? 0));
                $sheet->setCellValueByColumnAndRow(3, $rowIndex, '');
                $rowIndex++;
            }
            $addendumEndRow = $rowIndex - 1;
            if ($addendumEndRow >= $addendumStartRow) {
                $sheet->getStyle('B' . $addendumStartRow . ':B' . $addendumEndRow)->getNumberFormat()->setFormatCode('#,##0');
                $sheet->getStyle('A' . ($addendumStartRow - 1) . ':C' . $addendumEndRow)->getBorders()->getAllBorders()
                    ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                    ->getColor()->setRGB('000000');
            }
        } else {
            $sheet->getStyle('B4:D' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('E4:F' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('G4:G' . max(4, $rowIndex - 1))->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyle('A3:G' . $rowIndex)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
                ->getColor()->setRGB('000000');
        }
        $sheet->freezePane('A4');
        for ($i = 1; $i <= count($headers); $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        ceNormalizeWorksheetCellTypes($sheet);

        $tmpFile = tempnam(sys_get_temp_dir(), 'msp2_consumo_gas_');
        if ($tmpFile === false) {
            throw new RuntimeException('No fue posible generar archivo temporal.');
        }
        $writer = new Xlsx($spreadsheet);
        $prevReporting = error_reporting();
        error_reporting($prevReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        try {
            msp2SaveSpreadsheetXlsx($writer, $tmpFile);
        } finally {
            error_reporting($prevReporting);
        }
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = 'consumo_gas_' . ceFileMonthYearLabel($periodoYm) . '.xlsx';
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
    }

    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('No se encontró vendor/autoload.php para generar PDF.');
    }
    require_once $autoloadPath;
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('DomPDF no está disponible en el proyecto.');
    }

    $bodyRows = '';
    $previousGroupKeyPdf = null;
    $useGrayGroupPdf = false;
    foreach ($rows as $row) {
        $groupKeyPdf = ceGroupKeyFromRow($row);
        if ($previousGroupKeyPdf !== null && $groupKeyPdf !== $previousGroupKeyPdf) {
            $useGrayGroupPdf = !$useGrayGroupPdf;
        }
        $previousGroupKeyPdf = $groupKeyPdf;

        $rowClass = $useGrayGroupPdf ? ' class="grp-alt"' : '';
        $bodyRows .= '<tr' . $rowClass . '>'
            . '<td>' . msp2Escape((string) ($row['cod_local'] ?? '')) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($row['lectura_anterior'] ?? 0, 0)) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($row['lectura_actual'] ?? 0, 0)) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($row['total_consumido'] ?? 0, 0)) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($row['factor'] ?? 0, 2)) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($row['valor_litro'] ?? 0, 2)) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtAPagar($row['a_pagar'] ?? 0, 0)) . '</td>'
            . '</tr>';
    }
    $bodyRows .= '<tr>'
        . '<td colspan="6" style="font-weight:bold;background:#f8e5e2;">TOTAL A PAGAR</td>'
        . '<td class="num" style="font-weight:bold;background:#f8e5e2;">' . msp2Escape(ceFmtAPagar($totalAPagar, 0)) . '</td>'
        . '</tr>';
    $addendumBodyRows = '';
    foreach ($addendumRows as $addRow) {
        $addendumBodyRows .= '<tr>'
            . '<td>' . msp2Escape((string) ($addRow['cod_local'] ?? '')) . '</td>'
            . '<td class="num">' . msp2Escape(ceFmtNum($addRow['lectura_anterior'] ?? 0, 0)) . '</td>'
            . '<td class="num"></td>'
            . '</tr>';
    }

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
        . 'body{font-family:DejaVu Sans, Arial, sans-serif;font-size:11px;color:#111;}'
        . 'h1{font-size:17px;margin:0 0 10px 0;text-align:center;}'
        . 'h2{font-size:14px;margin:18px 0 8px 0;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #222;padding:6px 7px;}'
        . 'th{background:#ff8989;text-align:center;font-size:10px;}'
        . 'table.addendum th{background:#ffe2a8;}'
        . 'td.num{text-align:right;}'
        . 'tr.grp-alt td{background:#bfc8a2;}'
        . '.meta{margin-top:8px;font-size:10px;color:#444;}'
        . '.addendum-page{page-break-before:always;}'
        . '</style></head><body>'
        . '<h1>' . msp2Escape($title) . '</h1>'
        . '<table><thead><tr>'
        . '<th>' . msp2Escape($headers[0]) . '</th>'
        . '<th>' . msp2Escape($headers[1]) . '</th>'
        . '<th>' . msp2Escape($headers[2]) . '</th>'
        . '<th>' . msp2Escape($headers[3]) . '</th>'
        . '<th>' . msp2Escape($headers[4]) . '</th>'
        . '<th>' . msp2Escape($headers[5]) . '</th>'
        . '<th>' . msp2Escape($headers[6]) . '</th>'
        . '</tr></thead><tbody>'
        . $bodyRows
        . '</tbody></table>'
        . '<div class="meta">A PAGAR = TOTAL CONSUMO x FACTOR x VALOR_LITRO</div>'
        . ($addendumBodyRows !== ''
            ? '<div class="addendum-page">'
                . '<h1>' . msp2Escape($nextPeriodoPdfTitle) . '</h1>'
                . '<table class="addendum"><thead><tr>'
                . '<th>' . msp2Escape($addendumHeaders[0]) . '</th>'
                . '<th>' . msp2Escape($addendumHeaders[1]) . '</th>'
                . '<th>' . msp2Escape($addendumHeaders[2]) . '</th>'
                . '</tr></thead><tbody>'
                . $addendumBodyRows
                . '</tbody></table>'
                . '</div>'
            : '')
        . '</body></html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'consumo_gas_' . ceFileMonthYearLabel($periodoYm) . '.pdf';
    $pdfOutput = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . (string) strlen($pdfOutput));
    echo $pdfOutput;
    exit();
} catch (Throwable $e) {
    http_response_code(422);
    echo 'No fue posible generar el reporte: ' . $e->getMessage();
    exit();
}
