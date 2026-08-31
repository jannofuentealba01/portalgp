<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2AsignacionDescuentoRedirect(): never
{
    $default = 'contratos/descuentos_arriendo.php';
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '') {
        msp2Redirect($default);
    }

    $parts = parse_url($redirectTo);
    if (!is_array($parts)) {
        msp2Redirect($default);
    }

    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($path !== $default) {
        msp2Redirect($default);
    }

    $queryRaw = (string) ($parts['query'] ?? '');
    if ($queryRaw === '') {
        msp2Redirect($path);
    }

    $query = [];
    parse_str($queryRaw, $query);
    if (!is_array($query)) {
        msp2Redirect($path);
    }

    $sanitized = [];
    if (isset($query['filtroTexto']) && is_scalar($query['filtroTexto'])) {
        $filtroTexto = msp2NormalizeText((string) $query['filtroTexto']);
        if ($filtroTexto !== '') {
            $sanitized['filtroTexto'] = $filtroTexto;
        }
    }

    if (isset($query['filtroEstado']) && is_scalar($query['filtroEstado'])) {
        $filtroEstado = strtolower(trim((string) $query['filtroEstado']));
        if (in_array($filtroEstado, ['activos', 'desasignados', 'catalogo_inactivo', 'todos'], true)) {
            $sanitized['filtroEstado'] = $filtroEstado;
        }
    }

    if (isset($query['lineas']) && is_scalar($query['lineas'])) {
        $lineas = (int) $query['lineas'];
        if (in_array($lineas, [10, 25, 50, 100], true)) {
            $sanitized['lineas'] = $lineas;
        }
    }

    if (isset($query['pagina']) && is_scalar($query['pagina'])) {
        $paginaRaw = trim((string) $query['pagina']);
        if (ctype_digit($paginaRaw)) {
            $sanitized['pagina'] = max(1, (int) $paginaRaw);
        }
    }

    msp2Redirect($sanitized === [] ? $path : ($path . '?' . http_build_query($sanitized)));
}

function msp2AsignacionRangosSolapan(?string $desdeA, ?string $hastaA, ?string $desdeB, ?string $hastaB): bool
{
    if ($desdeA === null || $desdeB === null) {
        return false;
    }

    $startA = strtotime($desdeA);
    $startB = strtotime($desdeB);
    $endA = $hastaA !== null ? strtotime($hastaA) : PHP_INT_MAX;
    $endB = $hastaB !== null ? strtotime($hastaB) : PHP_INT_MAX;

    if ($startA === false || $startB === false || $endA === false || $endB === false) {
        return true;
    }

    return $startA <= $endB && $startB <= $endA;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/descuentos_arriendo.php');
}

$accion = strtolower(trim((string) ($_POST['accion'] ?? '')));
if (!in_array($accion, ['asociar', 'desasignar'], true)) {
    msp2SetFlash('warning', 'Acción inválida para asignación de descuentos.');
    msp2AsignacionDescuentoRedirect();
}

$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idDescuentoArriendo = filter_input(INPUT_POST, 'id_descuento_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idContratoArriendo === false || $idContratoArriendo === null || $idDescuentoArriendo === false || $idDescuentoArriendo === null) {
    msp2SetFlash('warning', 'Debes indicar contrato y descuento válidos.');
    msp2AsignacionDescuentoRedirect();
}

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_locales',
        'msp_descuento_arriendo',
        'msp_descuento_arriendo_contrato_local',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para asignar descuentos: `' . implode('`, `', $missingTables) . '`.');
    }

    $contratoExisteStmt = $conn->prepare(
        'SELECT TOP (1) 1
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $contratoExisteStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $contratoExisteStmt->execute();
    if ($contratoExisteStmt->fetchColumn() === false) {
        throw new RuntimeException('No existe el contrato seleccionado.');
    }

    if ($accion === 'desasignar') {
        $updateStmt = $conn->prepare(
            "UPDATE dcl
             SET
                estado_asignacion = 2,
                fecha_desasignacion = SYSDATETIME(),
                observaciones = COALESCE(NULLIF(dcl.observaciones, N''), N'Desasignación desde contratos/descuentos_arriendo.php')
             FROM dbo.msp_descuento_arriendo_contrato_local dcl
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_local = dcl.id_contrato_local
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
               AND dcl.id_descuento_arriendo = :id_descuento_arriendo
               AND dcl.estado_asignacion = 1"
        );
        $updateStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
        $updateStmt->bindValue(':id_descuento_arriendo', (int) $idDescuentoArriendo, PDO::PARAM_INT);
        $updateStmt->execute();

        $affected = $updateStmt->rowCount();
        if ($affected > 0) {
            msp2SetFlash('success', 'Descuento desasignado del contrato. Locales afectados: ' . $affected . '.');
        } else {
            msp2SetFlash('warning', 'No había asignaciones activas para desasignar.');
        }
        msp2AsignacionDescuentoRedirect();
    }

    $descuentoStmt = $conn->prepare(
        "SELECT TOP (1)
            id_descuento_arriendo,
            nombre_descuento,
            CONVERT(CHAR(10), periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), periodo_hasta, 126) AS periodo_hasta
         FROM dbo.msp_descuento_arriendo
         WHERE id_descuento_arriendo = :id_descuento_arriendo
           AND estado_descuento = 1"
    );
    $descuentoStmt->bindValue(':id_descuento_arriendo', (int) $idDescuentoArriendo, PDO::PARAM_INT);
    $descuentoStmt->execute();
    $descuento = $descuentoStmt->fetch();
    if ($descuento === false) {
        throw new RuntimeException('El descuento seleccionado no existe o está inactivo.');
    }

    $periodoDesde = substr((string) ($descuento['periodo_desde'] ?? ''), 0, 10);
    $periodoHastaRaw = substr((string) ($descuento['periodo_hasta'] ?? ''), 0, 10);
    $periodoHasta = $periodoHastaRaw !== '' ? $periodoHastaRaw : null;
    if ($periodoDesde === '') {
        throw new RuntimeException('El descuento seleccionado no tiene período desde válido.');
    }

    $localesStmt = $conn->prepare(
        "SELECT
            cl.id_contrato_local,
            l.cdo_local
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
           AND cl.estado_relacion IN (1,2)
         ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
    );
    $localesStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $localesStmt->execute();
    $locales = $localesStmt->fetchAll();
    if ($locales === []) {
        throw new RuntimeException('El contrato seleccionado no tiene contrato-local activos.');
    }

    $activeDiscountsStmt = $conn->prepare(
        "SELECT
            d.id_descuento_arriendo,
            d.nombre_descuento,
            CONVERT(CHAR(10), d.periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), d.periodo_hasta, 126) AS periodo_hasta
         FROM dbo.msp_descuento_arriendo_contrato_local dcl
         INNER JOIN dbo.msp_descuento_arriendo d
            ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
         WHERE dcl.id_contrato_local = :id_contrato_local
           AND dcl.estado_asignacion = 1
           AND d.estado_descuento = 1"
    );
    $insertStmt = $conn->prepare(
        "INSERT INTO dbo.msp_descuento_arriendo_contrato_local (
            id_descuento_arriendo,
            id_contrato_local,
            estado_asignacion,
            observaciones
         ) VALUES (
            :id_descuento_arriendo,
            :id_contrato_local,
            1,
            :observaciones
         )"
    );

    $toInsert = [];
    $omitidosDuplicados = 0;
    foreach ($locales as $local) {
        $idContratoLocal = (int) ($local['id_contrato_local'] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }

        $activeDiscountsStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $activeDiscountsStmt->execute();

        $alreadyActive = false;
        while (($active = $activeDiscountsStmt->fetch()) !== false) {
            $activeId = (int) ($active['id_descuento_arriendo'] ?? 0);
            if ($activeId === (int) $idDescuentoArriendo) {
                $alreadyActive = true;
                continue;
            }

            $activeDesde = substr((string) ($active['periodo_desde'] ?? ''), 0, 10);
            $activeHastaRaw = substr((string) ($active['periodo_hasta'] ?? ''), 0, 10);
            $activeHasta = $activeHastaRaw !== '' ? $activeHastaRaw : null;
            if (msp2AsignacionRangosSolapan($periodoDesde, $periodoHasta, $activeDesde, $activeHasta)) {
                $localCode = trim((string) ($local['cdo_local'] ?? ''));
                $activeName = trim((string) ($active['nombre_descuento'] ?? ''));
                throw new RuntimeException(
                    'El descuento se solapa con `' . ($activeName !== '' ? $activeName : ('#' . $activeId)) . '` en el local '
                    . ($localCode !== '' ? $localCode : ('contrato-local #' . $idContratoLocal)) . '.'
                );
            }
        }

        if ($alreadyActive) {
            $omitidosDuplicados++;
            continue;
        }

        $toInsert[] = $idContratoLocal;
    }

    if ($toInsert === []) {
        msp2SetFlash('warning', 'El descuento ya estaba activo en todos los locales actuales del contrato.');
        msp2AsignacionDescuentoRedirect();
    }

    $conn->beginTransaction();
    $insertados = 0;
    foreach ($toInsert as $idContratoLocal) {
        $insertStmt->bindValue(':id_descuento_arriendo', (int) $idDescuentoArriendo, PDO::PARAM_INT);
        $insertStmt->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $insertStmt->bindValue(':observaciones', 'Asignación a contrato completo desde contratos/descuentos_arriendo.php', PDO::PARAM_STR);
        $insertStmt->execute();
        $insertados++;
    }
    $conn->commit();

    $msg = 'Descuento asociado al contrato. Locales asignados: ' . $insertados . '.';
    if ($omitidosDuplicados > 0) {
        $msg .= ' Locales ya asociados omitidos: ' . $omitidosDuplicados . '.';
    }
    msp2SetFlash('success', $msg);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible guardar la asignación del descuento.');
}

msp2AsignacionDescuentoRedirect();
