<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

header('Content-Type: application/json; charset=UTF-8');

function msp2GuardarPeriodoContratoJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function msp2GuardarPeriodoContratoParseMonthToFirstDay(string $periodoYm): ?string
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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    msp2GuardarPeriodoContratoJson([
        'ok' => false,
        'message' => 'Método no permitido.',
    ], 405);
}

$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$anio = filter_input(INPUT_POST, 'anio', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2000, 'max_range' => 2100],
]);
$rowsPayload = $_POST['rows'] ?? null;

if ($idContrato === false || $idContrato === null || $anio === false || $anio === null || !is_array($rowsPayload)) {
    msp2GuardarPeriodoContratoJson([
        'ok' => false,
        'message' => 'Parámetros inválidos para guardar.',
    ], 422);
}

try {
    $requiredTables = [
        'msp_contrato_locales',
        'msp_contratos_arriendo',
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
        throw new RuntimeException('Faltan tablas para guardar arriendo anual: `' . implode('`, `', $missingTables) . '`.');
    }

    $stmtContrato = $conn->prepare(
        'SELECT TOP (1) id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtContrato->execute();
    if ((int) $stmtContrato->fetchColumn() <= 0) {
        throw new RuntimeException('El contrato no existe.');
    }

    $stmtModalidadDinamica = $conn->prepare(
        "SELECT TOP (1) id_modalidad_arriendo
         FROM dbo.msp_tipo_modalidad_arriendo
         WHERE codigo_modalidad = N'DINAMICO_MENSUAL'
           AND activo = 1"
    );
    $stmtModalidadDinamica->execute();
    $idModalidadDinamica = (int) $stmtModalidadDinamica->fetchColumn();
    if ($idModalidadDinamica <= 0) {
        throw new RuntimeException('No existe modalidad activa DINAMICO_MENSUAL en catálogo.');
    }

    $stmtModalidadVigente = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         SELECT TOP (1) regla.id_modalidad_arriendo
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
         OUTER APPLY (
            SELECT TOP (1) rr.id_modalidad_arriendo
            FROM dbo.msp_contrato_local_arriendo_regla rr
            WHERE rr.id_contrato_local = cl.id_contrato_local
              AND rr.estado_regla = 1
              AND rr.fecha_inicio <= EOMONTH(@periodo)
              AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= @periodo)
            ORDER BY
                CASE WHEN rr.es_default = 1 THEN 1 ELSE 0 END DESC,
                rr.prioridad DESC,
                rr.id_regla_arriendo DESC
         ) regla
         WHERE cl.id_contrato_local = :id_contrato_local
           AND cl.id_contrato_arriendo = :id_contrato_arriendo
           AND cl.estado_relacion IN (1,2)
           AND cl.fecha_inicio <= EOMONTH(@periodo)
           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
           AND ca.fecha_inicio <= EOMONTH(@periodo)
           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
           AND ca.estado_contrato IN (1,2,3)"
    );

    $stmtUpdatePeriodo = $conn->prepare(
        "UPDATE dbo.msp_contrato_local_arriendo_periodo
         SET
            valor_periodo_uf = :valor_periodo_uf,
            valor_periodo_clp = :valor_periodo_clp,
            descuento_periodo_clp = :descuento_periodo_clp,
            origen_carga = 2,
            estado_periodo = 1,
            observaciones = :observaciones,
            fecha_actualizacion = SYSDATETIME()
         WHERE id_contrato_local = :id_contrato_local
           AND periodo_facturacion = :periodo"
    );

    $stmtInsertPeriodo = $conn->prepare(
        "INSERT INTO dbo.msp_contrato_local_arriendo_periodo (
            id_contrato_local,
            periodo_facturacion,
            valor_periodo_uf,
            valor_periodo_clp,
            descuento_periodo_clp,
            origen_carga,
            estado_periodo,
            observaciones
         ) VALUES (
            :id_contrato_local,
            :periodo,
            :valor_periodo_uf,
            :valor_periodo_clp,
            :descuento_periodo_clp,
            2,
            1,
            :observaciones
         )"
    );

    $conn->beginTransaction();

    $insertados = 0;
    $actualizados = 0;
    $omitidos = 0;

    foreach ($rowsPayload as $rowData) {
        if (!is_array($rowData)) {
            continue;
        }

        $idContratoLocal = isset($rowData['id_contrato_local']) && is_numeric((string) $rowData['id_contrato_local'])
            ? (int) $rowData['id_contrato_local']
            : 0;
        $periodoYm = trim((string) ($rowData['periodo'] ?? ''));
        $periodoFacturacion = msp2GuardarPeriodoContratoParseMonthToFirstDay($periodoYm);

        if ($idContratoLocal <= 0 || $periodoFacturacion === null) {
            continue;
        }
        if ((int) substr($periodoYm, 0, 4) !== $anio) {
            continue;
        }

        $valorUfRaw = trim((string) ($rowData['valor_periodo_uf'] ?? ''));
        $valorClpRaw = trim((string) ($rowData['valor_periodo_clp'] ?? ''));
        $descuentoRaw = trim((string) ($rowData['descuento_periodo_clp'] ?? ''));

        [$okUf, $valorUf] = msp2NormalizeDecimalInput($valorUfRaw, 2);
        if (!$okUf) {
            throw new RuntimeException('Valor UF inválido para contrato-local #' . $idContratoLocal . ' (' . $periodoYm . ').');
        }
        [$okClp, $valorClp] = msp2NormalizeDecimalInput($valorClpRaw, 0);
        if (!$okClp) {
            throw new RuntimeException('Valor CLP inválido para contrato-local #' . $idContratoLocal . ' (' . $periodoYm . ').');
        }
        [$okDesc, $descuentoClp] = msp2NormalizeDecimalInput($descuentoRaw, 0);
        if (!$okDesc) {
            throw new RuntimeException('Descuento CLP inválido para contrato-local #' . $idContratoLocal . ' (' . $periodoYm . ').');
        }

        if ($valorUf === null && $valorClp === null) {
            $omitidos++;
            continue;
        }

        $stmtModalidadVigente->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtModalidadVigente->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtModalidadVigente->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtModalidadVigente->execute();
        $idModalidadActual = (int) $stmtModalidadVigente->fetchColumn();
        if ($idModalidadActual <= 0) {
            throw new RuntimeException('El contrato-local #' . $idContratoLocal . ' no pertenece al contrato o no está vigente en ' . $periodoYm . '.');
        }
        if ($idModalidadActual !== $idModalidadDinamica) {
            throw new RuntimeException('El contrato-local #' . $idContratoLocal . ' no tiene modalidad DINAMICO_MENSUAL en ' . $periodoYm . '.');
        }

        $descuentoFinal = $descuentoClp ?? '0';
        $observaciones = 'Carga manual UI contratos/index.php (vista anual dinámico mensual)';

        $stmtUpdatePeriodo->bindValue(':valor_periodo_uf', $valorUf, $valorUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpdatePeriodo->bindValue(':valor_periodo_clp', $valorClp, $valorClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpdatePeriodo->bindValue(':descuento_periodo_clp', $descuentoFinal, PDO::PARAM_STR);
        $stmtUpdatePeriodo->bindValue(':observaciones', $observaciones, PDO::PARAM_STR);
        $stmtUpdatePeriodo->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtUpdatePeriodo->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtUpdatePeriodo->execute();

        if ($stmtUpdatePeriodo->rowCount() > 0) {
            $actualizados++;
            continue;
        }

        $stmtInsertPeriodo->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtInsertPeriodo->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtInsertPeriodo->bindValue(':valor_periodo_uf', $valorUf, $valorUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertPeriodo->bindValue(':valor_periodo_clp', $valorClp, $valorClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertPeriodo->bindValue(':descuento_periodo_clp', $descuentoFinal, PDO::PARAM_STR);
        $stmtInsertPeriodo->bindValue(':observaciones', $observaciones, PDO::PARAM_STR);
        $stmtInsertPeriodo->execute();
        $insertados++;
    }

    $conn->commit();

    $partes = [];
    if ($insertados > 0) {
        $partes[] = 'insertados: ' . $insertados;
    }
    if ($actualizados > 0) {
        $partes[] = 'actualizados: ' . $actualizados;
    }
    if ($omitidos > 0) {
        $partes[] = 'omitidos: ' . $omitidos;
    }
    if ($partes === []) {
        $partes[] = 'sin cambios';
    }

    msp2GuardarPeriodoContratoJson([
        'ok' => true,
        'message' => 'Carga anual guardada (' . implode(', ', $partes) . ').',
        'insertados' => $insertados,
        'actualizados' => $actualizados,
        'omitidos' => $omitidos,
    ]);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2GuardarPeriodoContratoJson([
        'ok' => false,
        'message' => $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'No fue posible guardar la carga anual.',
    ], 500);
}
