<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

header('Content-Type: application/json; charset=UTF-8');

function msp2PeriodoContratoJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function msp2PeriodoContratoParseMonthToFirstDay(string $periodoYm): ?string
{
    if (preg_match('/^\d{4}-\d{2}$/', $periodoYm) !== 1) {
        return null;
    }

    $periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($periodoDate === false || $periodoDate->format('Y-m') !== $periodoYm) {
        return null;
    }

    return $periodoDate->format('Y-m-01');
}

function msp2PeriodoContratoFmt(mixed $value, int $decimals): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric((string) $value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    msp2PeriodoContratoJson([
        'ok' => false,
        'message' => 'Método no permitido.',
    ], 405);
}

$idContrato = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$anio = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2000, 'max_range' => 2100],
]);
if ($anio === false || $anio === null) {
    $anio = (int) (new DateTimeImmutable('today'))->format('Y');
}

if ($idContrato === false || $idContrato === null) {
    msp2PeriodoContratoJson([
        'ok' => false,
        'message' => 'Parámetros inválidos.',
    ], 422);
}

try {
    $requiredTables = [
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_locales',
        'msp_contrato_local_arriendo_regla',
        'msp_tipo_modalidad_arriendo',
        'msp_contrato_local_arriendo_periodo',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para arriendo dinámico anual: `' . implode('`, `', $missingTables) . '`.');
    }

    $stmtContrato = $conn->prepare(
        'SELECT TOP (1)
            ca.id_contrato_arriendo,
            t.nombre_comercial,
            a.nombre_locatario
         FROM dbo.msp_contratos_arriendo ca
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = ca.id_tienda
         LEFT JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = ca.id_arrendatario
         WHERE ca.id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contratoRow = $stmtContrato->fetch();
    if ($contratoRow === false) {
        throw new RuntimeException('El contrato no existe.');
    }

    $stmt = $conn->prepare(
        "DECLARE @anio INT = :anio;
         WITH meses AS (
            SELECT DATEFROMPARTS(@anio, v.mes, 1) AS periodo_facturacion
            FROM (VALUES (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12)) AS v(mes)
         )
         SELECT
            cl.id_contrato_local,
            l.cdo_local,
            l.desc_local,
            m.periodo_facturacion,
            regla.descuento_mensual_clp AS descuento_regla_clp,
            ap.id_arriendo_periodo,
            ap.valor_periodo_uf,
            ap.valor_periodo_clp,
            ap.descuento_periodo_clp
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         CROSS JOIN meses m
         OUTER APPLY (
            SELECT TOP (1)
                rr.descuento_mensual_clp,
                tm.codigo_modalidad
            FROM dbo.msp_contrato_local_arriendo_regla rr
            INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
            WHERE rr.id_contrato_local = cl.id_contrato_local
              AND rr.estado_regla = 1
              AND rr.fecha_inicio <= EOMONTH(m.periodo_facturacion)
              AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= m.periodo_facturacion)
            ORDER BY
                CASE WHEN rr.es_default = 1 THEN 1 ELSE 0 END DESC,
                rr.prioridad DESC,
                rr.id_regla_arriendo DESC
         ) regla
         LEFT JOIN dbo.msp_contrato_local_arriendo_periodo ap
            ON ap.id_contrato_local = cl.id_contrato_local
           AND ap.periodo_facturacion = m.periodo_facturacion
           AND ap.estado_periodo = 1
         WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
           AND cl.estado_relacion IN (1,2)
           AND cl.fecha_inicio <= EOMONTH(m.periodo_facturacion)
           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= m.periodo_facturacion)
           AND ca.fecha_inicio <= EOMONTH(m.periodo_facturacion)
           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= m.periodo_facturacion)
           AND ca.estado_contrato IN (1,2,3)
           AND ISNULL(regla.codigo_modalidad, N'UF_ESTATICO') = N'DINAMICO_MENSUAL'
         ORDER BY
            " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ",
            m.periodo_facturacion ASC,
            cl.id_contrato_local ASC"
    );
    $stmt->bindValue(':anio', $anio, PDO::PARAM_INT);
    $stmt->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    while (($row = $stmt->fetch()) !== false) {
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }

        $periodoRaw = trim((string) ($row['periodo_facturacion'] ?? ''));
        $periodoYm = '';
        if ($periodoRaw !== '') {
            try {
                $periodoYm = (new DateTimeImmutable($periodoRaw))->format('Y-m');
            } catch (Throwable) {
                $periodoYm = substr($periodoRaw, 0, 7);
            }
        }
        if ($periodoYm === '') {
            continue;
        }

        $descuentoRegla = msp2PeriodoContratoFmt($row['descuento_regla_clp'] ?? null, 0);
        $descuentoPeriodo = msp2PeriodoContratoFmt($row['descuento_periodo_clp'] ?? null, 0);
        if ($descuentoPeriodo === '' && $descuentoRegla !== '') {
            $descuentoPeriodo = $descuentoRegla;
        }

        $rows[] = [
            'row_key' => $idContratoLocal . '_' . str_replace('-', '', $periodoYm),
            'id_contrato_local' => $idContratoLocal,
            'cdo_local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
            'desc_local' => (string) ($row['desc_local'] ?? ''),
            'periodo' => $periodoYm,
            'periodo_label' => $periodoYm,
            'tiene_periodo' => ((int) ($row['id_arriendo_periodo'] ?? 0)) > 0,
            'valor_periodo_uf' => msp2PeriodoContratoFmt($row['valor_periodo_uf'] ?? null, 2),
            'valor_periodo_clp' => msp2PeriodoContratoFmt($row['valor_periodo_clp'] ?? null, 0),
            'descuento_periodo_clp' => $descuentoPeriodo !== '' ? $descuentoPeriodo : '0',
        ];
    }

    msp2PeriodoContratoJson([
        'ok' => true,
        'anio' => $anio,
        'contrato' => [
            'id_contrato_arriendo' => (int) ($contratoRow['id_contrato_arriendo'] ?? 0),
            'nombre_comercial' => (string) ($contratoRow['nombre_comercial'] ?? ''),
            'nombre_locatario' => (string) ($contratoRow['nombre_locatario'] ?? ''),
        ],
        'rows' => $rows,
    ]);
} catch (Throwable $exception) {
    msp2PeriodoContratoJson([
        'ok' => false,
        'message' => $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'No fue posible cargar la configuración anual de arriendo.',
    ], 500);
}
