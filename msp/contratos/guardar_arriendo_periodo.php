<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2GuardarArriendoPeriodoParseMonthToFirstDay(string $periodoYm): ?string
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

function msp2GuardarArriendoPeriodoRedirect(string $periodoYm, string $filtroTexto = '', bool $soloPendientes = false): never
{
    $params = ['periodo' => $periodoYm];
    if ($filtroTexto !== '') {
        $params['filtro'] = $filtroTexto;
    }
    if ($soloPendientes) {
        $params['solo_pendientes'] = '1';
    }

    msp2Redirect('contratos/arriendo_periodo.php?' . http_build_query($params));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/arriendo_periodo.php');
}

$periodoYm = trim((string) ($_POST['periodo'] ?? ''));
$filtroTexto = msp2NormalizeText((string) ($_POST['filtro'] ?? ''));
$soloPendientes = (string) ($_POST['solo_pendientes'] ?? '') === '1';

$periodoFacturacion = msp2GuardarArriendoPeriodoParseMonthToFirstDay($periodoYm);
if ($periodoFacturacion === null) {
    msp2SetFlash('warning', 'El período enviado no es válido.');
    msp2Redirect('contratos/arriendo_periodo.php');
}

$rowsPayload = $_POST['rows'] ?? null;
if (!is_array($rowsPayload)) {
    msp2SetFlash('warning', 'No se recibieron filas para guardar.');
    msp2GuardarArriendoPeriodoRedirect($periodoYm, $filtroTexto, $soloPendientes);
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
        throw new RuntimeException('Faltan tablas para guardar arriendo mensual: `' . implode('`, `', $missingTables) . '`.');
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
           AND cl.estado_relacion IN (1,2)
           AND cl.fecha_inicio <= EOMONTH(@periodo)
           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
           AND ca.fecha_inicio <= EOMONTH(@periodo)
           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
           AND ca.estado_contrato IN (1,2,3)"
    );

    $stmtDeletePeriodo = $conn->prepare(
        "DELETE FROM dbo.msp_contrato_local_arriendo_periodo
         WHERE id_contrato_local = :id_contrato_local
           AND periodo_facturacion = :periodo"
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
    $limpiados = 0;
    $omitidos = 0;

    foreach ($rowsPayload as $rowKey => $rowData) {
        if (!is_array($rowData)) {
            continue;
        }

        $idContratoLocal = is_numeric((string) $rowKey) ? (int) $rowKey : 0;
        if ($idContratoLocal <= 0 && isset($rowData['id_contrato_local']) && is_numeric((string) $rowData['id_contrato_local'])) {
            $idContratoLocal = (int) $rowData['id_contrato_local'];
        }
        if ($idContratoLocal <= 0) {
            continue;
        }

        $valorUfRaw = trim((string) ($rowData['valor_periodo_uf'] ?? ''));
        $valorClpRaw = trim((string) ($rowData['valor_periodo_clp'] ?? ''));
        $descuentoRaw = trim((string) ($rowData['descuento_periodo_clp'] ?? ''));
        $limpiar = (string) ($rowData['limpiar'] ?? '') === '1';

        [$okUf, $valorUf] = msp2NormalizeDecimalInput($valorUfRaw, 6);
        if (!$okUf) {
            throw new RuntimeException('Valor UF inválido para contrato-local #' . $idContratoLocal . '.');
        }

        [$okClp, $valorClp] = msp2NormalizeDecimalInput($valorClpRaw, 2);
        if (!$okClp) {
            throw new RuntimeException('Valor CLP inválido para contrato-local #' . $idContratoLocal . '.');
        }

        [$okDesc, $descuentoClp] = msp2NormalizeDecimalInput($descuentoRaw, 2);
        if (!$okDesc) {
            throw new RuntimeException('Descuento CLP inválido para contrato-local #' . $idContratoLocal . '.');
        }

        $hasBaseValue = $valorUf !== null || $valorClp !== null;
        if (!$limpiar && !$hasBaseValue) {
            $omitidos++;
            continue;
        }

        $stmtModalidadVigente->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtModalidadVigente->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtModalidadVigente->execute();
        $idModalidadActual = (int) $stmtModalidadVigente->fetchColumn();
        if ($idModalidadActual <= 0) {
            throw new RuntimeException('El contrato-local #' . $idContratoLocal . ' no está vigente para el período seleccionado.');
        }
        if ($idModalidadActual !== $idModalidadDinamica) {
            throw new RuntimeException('El contrato-local #' . $idContratoLocal . ' no tiene modalidad DINAMICO_MENSUAL vigente.');
        }

        if ($limpiar) {
            $stmtDeletePeriodo->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
            $stmtDeletePeriodo->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $stmtDeletePeriodo->execute();
            if ($stmtDeletePeriodo->rowCount() > 0) {
                $limpiados++;
            } else {
                $omitidos++;
            }
            continue;
        }

        $descuentoFinal = $descuentoClp ?? number_format(0, 2, '.', '');
        $observaciones = 'Carga manual UI contratos/arriendo_periodo';

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

    $resumen = [];
    if ($insertados > 0) {
        $resumen[] = 'insertados: ' . $insertados;
    }
    if ($actualizados > 0) {
        $resumen[] = 'actualizados: ' . $actualizados;
    }
    if ($limpiados > 0) {
        $resumen[] = 'limpiados: ' . $limpiados;
    }
    if ($omitidos > 0) {
        $resumen[] = 'omitidos: ' . $omitidos;
    }

    if ($resumen === []) {
        msp2SetFlash('warning', 'No se detectaron cambios para guardar.');
    } else {
        msp2SetFlash('success', 'Carga mensual guardada (' . implode(', ', $resumen) . ').');
    }
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible guardar la carga mensual.');
}

msp2GuardarArriendoPeriodoRedirect($periodoYm, $filtroTexto, $soloPendientes);
