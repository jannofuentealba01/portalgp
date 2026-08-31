<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2GuardarDescuentoRedirect(int $idContratoArriendo = 0): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo !== '') {
        $parts = parse_url($redirectTo);
        if (is_array($parts)) {
            $path = ltrim((string) ($parts['path'] ?? ''), '/');
            if ($path === 'contratos/descuentos_arriendo.php') {
                $queryRaw = (string) ($parts['query'] ?? '');
                $query = [];
                if ($queryRaw !== '') {
                    parse_str($queryRaw, $query);
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
        }
    }

    $query = $idContratoArriendo > 0 ? ('?id_contrato_arriendo=' . $idContratoArriendo) : '';
    msp2Redirect('contratos/descuentos_arriendo.php' . $query);
}

function msp2PeriodoMesToDate(?string $ym): ?string
{
    $raw = trim((string) $ym);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}$/', $raw) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m', $raw);
    if ($dt === false || $dt->format('Y-m') !== $raw) {
        return null;
    }
    return $dt->format('Y-m-01');
}

function msp2BuildDiscountCode(string $name): string
{
    $base = strtoupper(trim($name));
    $base = preg_replace('/[^A-Z0-9]+/', '_', $base ?? '') ?? '';
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'DESCUENTO';
    }
    if (strlen($base) > 28) {
        $base = substr($base, 0, 28);
    }
    return $base . '_' . gmdate('YmdHis');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2GuardarDescuentoRedirect();
}

$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContratoArriendo = ($idContratoArriendo === false || $idContratoArriendo === null) ? 0 : (int) $idContratoArriendo;

$accion = strtolower(trim((string) ($_POST['accion'] ?? '')));
if (!in_array($accion, ['crear', 'actualizar'], true)) {
    msp2SetFlash('warning', 'Acción inválida para descuentos de arriendo.');
    msp2GuardarDescuentoRedirect($idContratoArriendo);
}

try {
    $requiredTables = ['msp_descuento_arriendo'];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para guardar descuentos: `' . implode('`, `', $missingTables) . '`.');
    }

    $nombre = trim((string) ($_POST['nombre_descuento'] ?? ''));
    if ($nombre === '' || mb_strlen($nombre) > 150) {
        throw new RuntimeException('Debes indicar un nombre de descuento válido (máximo 150 caracteres).');
    }

    $tipoMonto = strtoupper(trim((string) ($_POST['tipo_monto'] ?? '')));
    if (!in_array($tipoMonto, ['UF_FIJO', 'CLP_FIJO'], true)) {
        throw new RuntimeException('El tipo de monto debe ser UF_FIJO o CLP_FIJO.');
    }

    [$okValor, $valor] = msp2NormalizeDecimalInput(trim((string) ($_POST['valor_descuento'] ?? '')), 6);
    if (!$okValor || $valor === null || (float) $valor <= 0) {
        throw new RuntimeException('El valor del descuento debe ser mayor a cero.');
    }

    $periodoDesde = msp2PeriodoMesToDate((string) ($_POST['periodo_desde'] ?? ''));
    if ($periodoDesde === null) {
        throw new RuntimeException('Debes indicar un período desde válido.');
    }

    $periodoHasta = msp2PeriodoMesToDate((string) ($_POST['periodo_hasta'] ?? ''));
    if ($periodoHasta !== null && $periodoHasta < $periodoDesde) {
        throw new RuntimeException('El período hasta no puede ser menor al período desde.');
    }

    $observaciones = trim((string) ($_POST['observaciones'] ?? ''));
    if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
        throw new RuntimeException('Las observaciones no pueden superar 500 caracteres.');
    }

    if ($accion === 'crear') {
        $codigo = strtoupper(trim((string) ($_POST['codigo_descuento'] ?? '')));
        if ($codigo === '') {
            $codigo = msp2BuildDiscountCode($nombre);
        }
        $codigo = preg_replace('/[^A-Z0-9_\-]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '_-');
        if ($codigo === '' || mb_strlen($codigo) > 40) {
            throw new RuntimeException('El código del descuento es inválido (máximo 40 caracteres alfanuméricos).');
        }

        $estado = 1;
        $insertStmt = $conn->prepare(
            "INSERT INTO dbo.msp_descuento_arriendo (
                codigo_descuento,
                nombre_descuento,
                tipo_monto,
                valor_descuento,
                periodo_desde,
                periodo_hasta,
                estado_descuento,
                observaciones
             ) VALUES (
                :codigo_descuento,
                :nombre_descuento,
                :tipo_monto,
                :valor_descuento,
                :periodo_desde,
                :periodo_hasta,
                :estado_descuento,
                :observaciones
             )"
        );
        $insertStmt->bindValue(':codigo_descuento', $codigo, PDO::PARAM_STR);
        $insertStmt->bindValue(':nombre_descuento', $nombre, PDO::PARAM_STR);
        $insertStmt->bindValue(':tipo_monto', $tipoMonto, PDO::PARAM_STR);
        $insertStmt->bindValue(':valor_descuento', $valor, PDO::PARAM_STR);
        $insertStmt->bindValue(':periodo_desde', $periodoDesde, PDO::PARAM_STR);
        $insertStmt->bindValue(':periodo_hasta', $periodoHasta, $periodoHasta !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->bindValue(':estado_descuento', $estado, PDO::PARAM_INT);
        $insertStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insertStmt->execute();

        msp2SetFlash('success', 'Descuento creado correctamente.');
        msp2GuardarDescuentoRedirect($idContratoArriendo);
    }

    $idDescuento = filter_input(INPUT_POST, 'id_descuento_arriendo', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($idDescuento === false || $idDescuento === null) {
        throw new RuntimeException('Debes indicar un descuento válido para actualizar.');
    }

    $estado = filter_input(INPUT_POST, 'estado_descuento', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2],
    ]);
    if ($estado === false || $estado === null) {
        $estado = 1;
    }

    $updateStmt = $conn->prepare(
        "UPDATE dbo.msp_descuento_arriendo
         SET
            nombre_descuento = :nombre_descuento,
            tipo_monto = :tipo_monto,
            valor_descuento = :valor_descuento,
            periodo_desde = :periodo_desde,
            periodo_hasta = :periodo_hasta,
            estado_descuento = :estado_descuento,
            observaciones = :observaciones,
            fecha_actualizacion = SYSDATETIME()
         WHERE id_descuento_arriendo = :id_descuento_arriendo"
    );
    $updateStmt->bindValue(':id_descuento_arriendo', (int) $idDescuento, PDO::PARAM_INT);
    $updateStmt->bindValue(':nombre_descuento', $nombre, PDO::PARAM_STR);
    $updateStmt->bindValue(':tipo_monto', $tipoMonto, PDO::PARAM_STR);
    $updateStmt->bindValue(':valor_descuento', $valor, PDO::PARAM_STR);
    $updateStmt->bindValue(':periodo_desde', $periodoDesde, PDO::PARAM_STR);
    $updateStmt->bindValue(':periodo_hasta', $periodoHasta, $periodoHasta !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $updateStmt->bindValue(':estado_descuento', (int) $estado, PDO::PARAM_INT);
    $updateStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $updateStmt->execute();

    if ($updateStmt->rowCount() <= 0) {
        msp2SetFlash('warning', 'No se detectaron cambios en el descuento.');
    } else {
        msp2SetFlash('success', 'Descuento actualizado correctamente.');
    }
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible guardar el descuento.');
}

msp2GuardarDescuentoRedirect($idContratoArriendo);
