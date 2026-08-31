<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    echo 'No se encontro vendor/autoload.php para cargar DomPDF.';
    exit();
}
require_once $autoloadPath;
if (!class_exists(\Dompdf\Dompdf::class)) {
    http_response_code(500);
    echo 'DomPDF no esta disponible en el proyecto.';
    exit();
}

$filtroPeriodo = trim((string) ($_GET['periodo'] ?? 'all'));
if ($filtroPeriodo !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) !== 1) {
    $filtroPeriodo = 'all';
}
$hoy = date('Y-m-d');
$corteAging = trim((string) ($_GET['corte_aging'] ?? $hoy));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $corteAging) !== 1) {
    $corteAging = $hoy;
}
if ($filtroPeriodo !== 'all') {
    $dtCorteMes = DateTimeImmutable::createFromFormat('Y-m-d', $filtroPeriodo . '-01');
    $corteDocumentos = $dtCorteMes ? $dtCorteMes->modify('last day of this month')->format('Y-m-d') : $hoy;
} else {
    $corteDocumentos = $corteAging;
}

function agPdfFmtMonto(mixed $v): string
{
    return number_format((float) ($v ?? 0), 2, ',', '.');
}

function agPdfFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed ? $parsed->format('d-m-Y') : (string) $value;
}

function agPdfLocalSortTuple(string $raw): array
{
    $code = strtoupper(trim($raw));
    if ($code === '') {
        return [5, 999, '', 999999, '', $code];
    }

    if (preg_match('/^([A-Z])-([0-9]+)([A-Z]?)$/', $code, $m) === 1) {
        $block = $m[1];
        $num = (int) $m[2];
        $suffix = $m[3] ?? '';
        return [0, ord($block), $block, $num, $suffix, $code];
    }

    if (preg_match('/^[A-Z]$/', $code) === 1) {
        return [1, ord($code), $code, 0, '', $code];
    }

    if (preg_match('/^[0-9]+$/', $code) === 1) {
        return [2, (int) $code, '', (int) $code, '', $code];
    }

    $namedRank = match (true) {
        $code === 'PELUQUERIA' => 0,
        $code === 'GYM' => 1,
        $code === 'OBRA' => 2,
        $code === 'MODULAR' => 3,
        str_starts_with($code, 'ESPACIO') => 4,
        default => 999,
    };
    if ($namedRank !== 999) {
        return [3, $namedRank, '', 0, '', $code];
    }

    return [4, 999, '', 999999, '', $code];
}

function agPdfLocalSortCompare(string $a, string $b): int
{
    $ka = agPdfLocalSortTuple($a);
    $kb = agPdfLocalSortTuple($b);
    $len = min(count($ka), count($kb));
    for ($i = 0; $i < $len; $i++) {
        if ($ka[$i] === $kb[$i]) {
            continue;
        }
        return ($ka[$i] <=> $kb[$i]);
    }
    return 0;
}

try {
    $required = ['msp_documentos_cobro', 'msp_tiendas', 'msp_arrendatarios', 'msp_documentos_cobro_detalle', 'msp_tipo_item_documento'];
    foreach ($required as $t) {
        if (!msp2TableExists($conn, $t)) {
            throw new RuntimeException('Falta tabla `' . $t . '` para aging PDF.');
        }
    }

    $wherePeriodo = '1=1';
    $paramsBase = [':corte_documentos' => $corteDocumentos];
    if ($filtroPeriodo !== 'all') {
        $wherePeriodo = 'dc.periodo_facturacion = :periodo';
        $paramsBase[':periodo'] = $filtroPeriodo . '-01';
    }

    $sqlBase =
        "FROM dbo.msp_documentos_cobro dc
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda = dc.id_tienda
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
         WHERE {$wherePeriodo}
           AND dc.fecha_emision <= :corte_documentos
           AND dc.estado_documento <> 5
           AND dc.saldo_pendiente > 0";

    $stmtArr = $conn->prepare(
        "SELECT
            a.id_arrendatario,
            COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)) AS nombre_arrendatario,
            a.rut,
            ROUND(SUM(dc.monto_total), 2) AS amount_total,
            ROUND(SUM(dc.saldo_pendiente), 2) AS open_balance_total
         {$sqlBase}
         GROUP BY a.id_arrendatario, a.nombre_locatario, a.nombre_representante, a.rut"
    );
    foreach ($paramsBase as $k => $v) {
        $stmtArr->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmtArr->execute();
    $arrRows = $stmtArr->fetchAll();

    $stmtDetalle = $conn->prepare(
        "SELECT
            a.id_arrendatario,
            dc.id_documento_cobro,
            COALESCE(dc.numero_documento, CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
            dc.fecha_emision,
            dc.fecha_vencimiento,
            DATEDIFF(DAY, dc.fecha_vencimiento, :corte_det) AS dias_atraso,
            dc.monto_total,
            dc.saldo_pendiente
         {$sqlBase}
         ORDER BY a.id_arrendatario ASC, dc.fecha_vencimiento ASC, dc.id_documento_cobro ASC"
    );
    foreach ($paramsBase as $k => $v) {
        $stmtDetalle->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmtDetalle->bindValue(':corte_det', $corteAging, PDO::PARAM_STR);
    $stmtDetalle->execute();
    $detRows = $stmtDetalle->fetchAll();

    $detallePorArr = [];
    $docIds = [];
    foreach ($detRows as $r) {
        $arrId = (int) ($r['id_arrendatario'] ?? 0);
        if (!isset($detallePorArr[$arrId])) {
            $detallePorArr[$arrId] = [];
        }
        $detallePorArr[$arrId][] = $r;
        $docId = (int) ($r['id_documento_cobro'] ?? 0);
        if ($docId > 0) {
            $docIds[$docId] = $docId;
        }
    }

    $localesPorDoc = [];
    if ($docIds !== []) {
        $docIds = array_values($docIds);
        $ph = implode(', ', array_fill(0, count($docIds), '?'));
        $stmtLocDoc = $conn->prepare(
            "WITH detalle_local AS (
                SELECT
                    dcd.id_documento_cobro,
                    COALESCE(
                        loc_serv.cdo_local,
                        CASE
                            WHEN tid.codigo_item = N'ARRIENDO' AND dcd.descripcion_item LIKE N'Arriendo local %'
                                THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                            ELSE NULL
                        END,
                        N'SIN LOCAL'
                    ) AS cdo_local
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                LEFT JOIN dbo.msp_cobros_servicios cs
                    ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                LEFT JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_lectura = cs.id_lectura
                LEFT JOIN dbo.msp_medidores m
                    ON m.id_medidor = lm.id_medidor
                LEFT JOIN dbo.msp_locales loc_serv
                    ON loc_serv.id_local = m.id_local
                WHERE dcd.id_documento_cobro IN ($ph)
            )
            SELECT DISTINCT id_documento_cobro, cdo_local FROM detalle_local"
        );
        foreach ($docIds as $idx => $docId) {
            $stmtLocDoc->bindValue($idx + 1, $docId, PDO::PARAM_INT);
        }
        $stmtLocDoc->execute();
        foreach ($stmtLocDoc->fetchAll() as $locRow) {
            $docId = (int) ($locRow['id_documento_cobro'] ?? 0);
            $cdo = trim((string) ($locRow['cdo_local'] ?? 'SIN LOCAL'));
            if ($docId <= 0 || $cdo === '') {
                continue;
            }
            if (!isset($localesPorDoc[$docId])) {
                $localesPorDoc[$docId] = [];
            }
            $localesPorDoc[$docId][$cdo] = $cdo;
        }
        foreach ($localesPorDoc as $docId => $set) {
            $codes = array_values($set);
            usort($codes, static fn(string $a, string $b): int => agPdfLocalSortCompare($a, $b));
            $localesPorDoc[$docId] = $codes;
        }
    }

    $localesPorArr = [];
    foreach ($detallePorArr as $arrId => $docList) {
        $set = [];
        foreach ($docList as $d) {
            $docId = (int) ($d['id_documento_cobro'] ?? 0);
            foreach (($localesPorDoc[$docId] ?? []) as $cdo) {
                $set[$cdo] = $cdo;
            }
        }
        $codes = array_values($set);
        usort($codes, static fn(string $a, string $b): int => agPdfLocalSortCompare($a, $b));
        $localesPorArr[(int) $arrId] = $codes;
    }

    usort(
        $arrRows,
        static function (array $a, array $b) use ($localesPorArr): int {
            $arrA = (int) ($a['id_arrendatario'] ?? 0);
            $arrB = (int) ($b['id_arrendatario'] ?? 0);
            $firstA = (string) (($localesPorArr[$arrA][0] ?? 'ZZZ'));
            $firstB = (string) (($localesPorArr[$arrB][0] ?? 'ZZZ'));
            $cmp = agPdfLocalSortCompare($firstA, $firstB);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) ($a['nombre_arrendatario'] ?? ''), (string) ($b['nombre_arrendatario'] ?? ''));
        }
    );

    $periodoLabel = $filtroPeriodo === 'all' ? 'Todos' : $filtroPeriodo;
    $agingAlLabel = agPdfFmtFecha($corteAging);
    $corteDocsLabel = agPdfFmtFecha($corteDocumentos);
    $now = new DateTimeImmutable('now');

    $grandAmount = 0.0;
    $grandOpen = 0.0;
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 10px; }
            .header { text-align: center; margin-bottom: 8px; }
            .title { font-size: 16px; font-weight: 700; }
            .sub { font-size: 11px; color: #444; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 4px 5px; border-bottom: 1px solid #ddd; }
            th { background: #f1f5f9; text-align: left; }
            .num { text-align: right; white-space: nowrap; }
            .group { background: #eef4fb; font-weight: 700; }
            .total-row { font-weight: 700; background: #f8fafc; }
            .grand-total { font-weight: 700; border-top: 2px solid #111; }
        </style>
    </head>
    <body>
        <div class="header">
            <div><?php echo msp2Escape($now->format('H:i')); ?> | <?php echo msp2Escape($now->format('d-m-y')); ?></div>
            <div class="title">Aging de Deudores - MSP</div>
            <div class="sub">Periodo: <?php echo msp2Escape($periodoLabel); ?> | Corte documentos: <?php echo msp2Escape($corteDocsLabel); ?> | Aging al: <?php echo msp2Escape($agingAlLabel); ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Locales / Arrendatario</th>
                    <th>Tipo</th>
                    <th>Fecha</th>
                    <th>Num</th>
                    <th class="num">Aging</th>
                    <th class="num">Monto</th>
                    <th class="num">Saldo Pendiente</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($arrRows as $arr): ?>
                    <?php
                        $arrId = (int) ($arr['id_arrendatario'] ?? 0);
                        $localesTxt = implode(' / ', $localesPorArr[$arrId] ?? ['SIN LOCAL']);
                        $amountTotal = (float) ($arr['amount_total'] ?? 0);
                        $openTotal = (float) ($arr['open_balance_total'] ?? 0);
                        $grandAmount += $amountTotal;
                        $grandOpen += $openTotal;
                    ?>
                    <tr class="group">
                        <td colspan="7"><?php echo msp2Escape($localesTxt); ?> | <?php echo msp2Escape((string) ($arr['nombre_arrendatario'] ?? '')); ?></td>
                    </tr>
                    <?php foreach (($detallePorArr[$arrId] ?? []) as $doc): ?>
                        <?php
                            $dias = max(0, (int) ($doc['dias_atraso'] ?? 0));
                        ?>
                        <tr>
                            <td></td>
                            <td>Factura</td>
                            <td><?php echo msp2Escape(agPdfFmtFecha((string) ($doc['fecha_emision'] ?? ''))); ?></td>
                            <td><?php echo msp2Escape((string) ($doc['numero_documento'] ?? '')); ?></td>
                            <td class="num"><?php echo msp2Escape((string) $dias); ?></td>
                            <td class="num"><?php echo msp2Escape(agPdfFmtMonto($doc['monto_total'] ?? 0)); ?></td>
                            <td class="num"><?php echo msp2Escape(agPdfFmtMonto($doc['saldo_pendiente'] ?? 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="5">Total <?php echo msp2Escape((string) ($arr['nombre_arrendatario'] ?? '')); ?></td>
                        <td class="num"><?php echo msp2Escape(agPdfFmtMonto($amountTotal)); ?></td>
                        <td class="num"><?php echo msp2Escape(agPdfFmtMonto($openTotal)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="grand-total">
                    <td colspan="5">TOTAL</td>
                    <td class="num"><?php echo msp2Escape(agPdfFmtMonto($grandAmount)); ?></td>
                    <td class="num"><?php echo msp2Escape(agPdfFmtMonto($grandOpen)); ?></td>
                </tr>
            </tbody>
        </table>
    </body>
    </html>
    <?php

    $html = (string) ob_get_clean();

    $dompdf = new \Dompdf\Dompdf([
        'isRemoteEnabled' => false,
        'defaultFont' => 'DejaVu Sans',
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $periodoFile = $filtroPeriodo === 'all' ? 'todos' : str_replace('-', '_', $filtroPeriodo);
    $filename =
        'aging_deudores_'
        . $periodoFile
        . '_docs_'
        . str_replace('-', '', $corteDocumentos)
        . '_aging_'
        . str_replace('-', '', $corteAging)
        . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $dompdf->output();
    exit();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'No fue posible generar Aging PDF. Detalle: ' . msp2Escape($e->getMessage());
}
