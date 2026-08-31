<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$tablaExiste = false;
$loadError = null;

$requiredTables = [
    'msp_reglas_cobro_auto',
    'msp_tipo_item_documento',
];

try {
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    $tablaExiste = $missingTables === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas para reglas automaticas: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/db/patch_reglas_cobro_auto.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de reglas de cobro.';
}

function reglasRedirect(array $params = []): never
{
    $path = 'cobros/reglas_cobro_auto.php';
    if ($params !== []) {
        $path .= '?' . http_build_query($params);
    }

    msp2Redirect($path);
}

function reglasParseDate(?string $raw): ?string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        return null;
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablaExiste) {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    try {
        if ($accion === 'guardar_regla') {
            $idRegla = filter_input(INPUT_POST, 'id_regla_cobro_auto', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idRegla === false || $idRegla === null) {
                $idRegla = 0;
            }

            $codigoReglaRaw = mb_strtoupper(trim((string) ($_POST['codigo_regla'] ?? '')), 'UTF-8');
            $codigoRegla = preg_replace('/[^A-Z0-9_]/', '', $codigoReglaRaw ?? '');
            if (!is_string($codigoRegla) || $codigoRegla === '' || mb_strlen($codigoRegla, 'UTF-8') > 60) {
                throw new RuntimeException('Codigo de regla invalido. Usa solo A-Z, 0-9 y _.');
            }

            $nombreRegla = trim((string) ($_POST['nombre_regla'] ?? ''));
            if ($nombreRegla === '' || mb_strlen($nombreRegla, 'UTF-8') > 120) {
                throw new RuntimeException('Nombre de regla invalido (maximo 120 caracteres).');
            }

            $descripcionRegla = trim((string) ($_POST['descripcion_regla'] ?? ''));
            if ($descripcionRegla === '') {
                $descripcionRegla = null;
            } elseif (mb_strlen($descripcionRegla, 'UTF-8') > 200) {
                throw new RuntimeException('La descripcion no puede exceder 200 caracteres.');
            }

            $idTipoItem = filter_input(INPUT_POST, 'id_tipo_item_documento', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idTipoItem === false || $idTipoItem === null) {
                throw new RuntimeException('Debes seleccionar un tipo de item.');
            }

            [$montoOk, $montoUnitario] = msp2NormalizeDecimalInput((string) ($_POST['monto_unitario'] ?? ''), 2);
            if (!$montoOk || $montoUnitario === null) {
                throw new RuntimeException('Monto unitario invalido.');
            }

            $fechaInicio = reglasParseDate((string) ($_POST['fecha_inicio_vigencia'] ?? ''));
            if ($fechaInicio === null) {
                throw new RuntimeException('Fecha de inicio de vigencia invalida.');
            }

            $fechaFinInput = trim((string) ($_POST['fecha_fin_vigencia'] ?? ''));
            $fechaFin = $fechaFinInput === '' ? null : reglasParseDate($fechaFinInput);
            if ($fechaFinInput !== '' && $fechaFin === null) {
                throw new RuntimeException('Fecha de termino de vigencia invalida.');
            }
            if ($fechaFin !== null && $fechaFin < $fechaInicio) {
                throw new RuntimeException('La fecha fin no puede ser menor que la fecha inicio.');
            }

            $diasGracia = filter_input(INPUT_POST, 'dias_gracia', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 3650],
            ]);
            if ($diasGracia === false || $diasGracia === null) {
                throw new RuntimeException('Dias de gracia invalidos.');
            }

            $ordenAplicacion = filter_input(INPUT_POST, 'orden_aplicacion', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 9999],
            ]);
            if ($ordenAplicacion === false || $ordenAplicacion === null) {
                throw new RuntimeException('Orden de aplicacion invalido.');
            }

            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($idRegla > 0) {
                $update = $conn->prepare(
                    "UPDATE dbo.msp_reglas_cobro_auto
                     SET
                        codigo_regla = :codigo,
                        nombre_regla = :nombre,
                        descripcion_regla = :descripcion,
                        id_tipo_item_documento = :id_tipo,
                        modo_calculo = N'DIARIO_FIJO',
                        monto_unitario = :monto,
                        fecha_inicio_vigencia = :fecha_inicio,
                        fecha_fin_vigencia = :fecha_fin,
                        dias_gracia = :dias_gracia,
                        orden_aplicacion = :orden_aplicacion,
                        activo = :activo,
                        fecha_actualizacion = SYSDATETIME()
                     WHERE id_regla_cobro_auto = :id"
                );
                $update->bindValue(':id', $idRegla, PDO::PARAM_INT);
                $update->bindValue(':codigo', $codigoRegla, PDO::PARAM_STR);
                $update->bindValue(':nombre', $nombreRegla, PDO::PARAM_STR);
                $update->bindValue(':descripcion', $descripcionRegla, $descripcionRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $update->bindValue(':id_tipo', (int) $idTipoItem, PDO::PARAM_INT);
                $update->bindValue(':monto', $montoUnitario, PDO::PARAM_STR);
                $update->bindValue(':fecha_inicio', $fechaInicio, PDO::PARAM_STR);
                $update->bindValue(':fecha_fin', $fechaFin, $fechaFin !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $update->bindValue(':dias_gracia', $diasGracia, PDO::PARAM_INT);
                $update->bindValue(':orden_aplicacion', $ordenAplicacion, PDO::PARAM_INT);
                $update->bindValue(':activo', $activo, PDO::PARAM_INT);
                $update->execute();

                if ($update->rowCount() <= 0) {
                    throw new RuntimeException('No se encontro la regla para actualizar.');
                }

                msp2SetFlash('success', 'Regla actualizada correctamente.');
            } else {
                $insert = $conn->prepare(
                    "INSERT INTO dbo.msp_reglas_cobro_auto (
                        codigo_regla,
                        nombre_regla,
                        descripcion_regla,
                        id_tipo_item_documento,
                        modo_calculo,
                        monto_unitario,
                        fecha_inicio_vigencia,
                        fecha_fin_vigencia,
                        dias_gracia,
                        orden_aplicacion,
                        activo
                     )
                     VALUES (
                        :codigo,
                        :nombre,
                        :descripcion,
                        :id_tipo,
                        N'DIARIO_FIJO',
                        :monto,
                        :fecha_inicio,
                        :fecha_fin,
                        :dias_gracia,
                        :orden_aplicacion,
                        :activo
                     )"
                );
                $insert->bindValue(':codigo', $codigoRegla, PDO::PARAM_STR);
                $insert->bindValue(':nombre', $nombreRegla, PDO::PARAM_STR);
                $insert->bindValue(':descripcion', $descripcionRegla, $descripcionRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insert->bindValue(':id_tipo', (int) $idTipoItem, PDO::PARAM_INT);
                $insert->bindValue(':monto', $montoUnitario, PDO::PARAM_STR);
                $insert->bindValue(':fecha_inicio', $fechaInicio, PDO::PARAM_STR);
                $insert->bindValue(':fecha_fin', $fechaFin, $fechaFin !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insert->bindValue(':dias_gracia', $diasGracia, PDO::PARAM_INT);
                $insert->bindValue(':orden_aplicacion', $ordenAplicacion, PDO::PARAM_INT);
                $insert->bindValue(':activo', $activo, PDO::PARAM_INT);
                $insert->execute();

                msp2SetFlash('success', 'Regla creada correctamente.');
            }

            reglasRedirect();
        } elseif ($accion === 'cambiar_estado') {
            $idRegla = filter_input(INPUT_POST, 'id_regla_cobro_auto', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idRegla === false || $idRegla === null) {
                throw new RuntimeException('Regla invalida.');
            }
            $nuevoEstado = isset($_POST['activar']) ? 1 : 0;
            $up = $conn->prepare(
                'UPDATE dbo.msp_reglas_cobro_auto
                 SET activo = :activo, fecha_actualizacion = SYSDATETIME()
                 WHERE id_regla_cobro_auto = :id'
            );
            $up->bindValue(':activo', $nuevoEstado, PDO::PARAM_INT);
            $up->bindValue(':id', (int) $idRegla, PDO::PARAM_INT);
            $up->execute();
            msp2SetFlash('success', $nuevoEstado === 1 ? 'Regla activada.' : 'Regla desactivada.');
            reglasRedirect();
        } elseif ($accion === 'eliminar_regla') {
            $idRegla = filter_input(INPUT_POST, 'id_regla_cobro_auto', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idRegla === false || $idRegla === null) {
                throw new RuntimeException('Regla invalida.');
            }

            $usoStmt = $conn->prepare(
                'SELECT COUNT(*)
                 FROM dbo.msp_cargos_auto_generados
                 WHERE id_regla_cobro_auto = :id'
            );
            $usoStmt->bindValue(':id', (int) $idRegla, PDO::PARAM_INT);
            $usoStmt->execute();
            $usos = (int) $usoStmt->fetchColumn();
            if ($usos > 0) {
                throw new RuntimeException('No se puede eliminar la regla porque ya tiene cargos generados. Desactivala.');
            }

            $del = $conn->prepare('DELETE FROM dbo.msp_reglas_cobro_auto WHERE id_regla_cobro_auto = :id');
            $del->bindValue(':id', (int) $idRegla, PDO::PARAM_INT);
            $del->execute();
            if ($del->rowCount() <= 0) {
                throw new RuntimeException('No se encontro la regla para eliminar.');
            }
            msp2SetFlash('success', 'Regla eliminada.');
            reglasRedirect();
        }
    } catch (Throwable $exception) {
        msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible guardar la regla.');
        $editIdOnError = filter_input(INPUT_POST, 'id_regla_cobro_auto', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        reglasRedirect($editIdOnError ? ['edit' => (int) $editIdOnError] : []);
    }
}

$tiposItem = [];
$reglas = [];
$reglaEdicion = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($tablaExiste) {
    try {
        $tiposStmt = $conn->query(
            'SELECT id_tipo_item_documento, codigo_item, nombre_item
             FROM dbo.msp_tipo_item_documento
             ORDER BY nombre_item ASC'
        );
        $tiposItem = $tiposStmt->fetchAll();

        $reglasStmt = $conn->query(
            "SELECT
                r.id_regla_cobro_auto,
                r.codigo_regla,
                r.nombre_regla,
                r.descripcion_regla,
                r.id_tipo_item_documento,
                r.modo_calculo,
                r.monto_unitario,
                r.fecha_inicio_vigencia,
                r.fecha_fin_vigencia,
                r.dias_gracia,
                r.orden_aplicacion,
                r.activo,
                r.fecha_actualizacion,
                tid.codigo_item,
                tid.nombre_item
             FROM dbo.msp_reglas_cobro_auto r
             INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = r.id_tipo_item_documento
             ORDER BY r.codigo_regla ASC, r.fecha_inicio_vigencia DESC, r.id_regla_cobro_auto DESC"
        );
        $reglas = $reglasStmt->fetchAll();

        if ($editId !== false && $editId !== null) {
            $editStmt = $conn->prepare(
                'SELECT *
                 FROM dbo.msp_reglas_cobro_auto
                 WHERE id_regla_cobro_auto = :id'
            );
            $editStmt->bindValue(':id', (int) $editId, PDO::PARAM_INT);
            $editStmt->execute();
            $reglaEdicion = $editStmt->fetch() ?: null;
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar las reglas automaticas. Detalle tecnico: ' . $exception->getMessage();
    }
}

$formData = [
    'id_regla_cobro_auto' => (int) ($reglaEdicion['id_regla_cobro_auto'] ?? 0),
    'codigo_regla' => (string) ($reglaEdicion['codigo_regla'] ?? 'MORA_DIARIA_FIJA'),
    'nombre_regla' => (string) ($reglaEdicion['nombre_regla'] ?? ''),
    'descripcion_regla' => (string) ($reglaEdicion['descripcion_regla'] ?? ''),
    'id_tipo_item_documento' => (int) ($reglaEdicion['id_tipo_item_documento'] ?? 0),
    'monto_unitario' => number_format((float) ($reglaEdicion['monto_unitario'] ?? 1000), 2, ',', ''),
    'fecha_inicio_vigencia' => substr((string) ($reglaEdicion['fecha_inicio_vigencia'] ?? date('Y-m-01')), 0, 10),
    'fecha_fin_vigencia' => $reglaEdicion !== null ? substr((string) ($reglaEdicion['fecha_fin_vigencia'] ?? ''), 0, 10) : '',
    'dias_gracia' => (int) ($reglaEdicion['dias_gracia'] ?? 0),
    'orden_aplicacion' => (int) ($reglaEdicion['orden_aplicacion'] ?? 100),
    'activo' => isset($reglaEdicion['activo']) ? ((int) $reglaEdicion['activo'] === 1) : true,
];
$totalReglas = count($reglas);
$totalActivas = 0;
foreach ($reglas as $reglaRow) {
    if ((int) ($reglaRow['activo'] ?? 0) === 1) {
        $totalActivas++;
    }
}
$totalInactivas = $totalReglas - $totalActivas;
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Reglas de Cobro Automatico</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
                </a>
                <a href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-magic me-1" aria-hidden="true"></i>Operacion mensual
                </a>
            </div>
        </div>

        <h1 class="h4 mb-2">Reglas de cobro automatico</h1>
        <p class="text-muted mb-4">La tabla es la vista principal. Crear/editar se hace en panel lateral.</p>

        <?php msp2RenderFlash($flash); ?>

        <?php if (!$tablaExiste): ?>
            <div class="alert alert-warning mb-0"><?php echo msp2Escape($loadError ?? 'Estructura no disponible.'); ?></div>
        <?php else: ?>
            <?php if ($loadError !== null): ?>
                <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="small text-muted">Reglas totales</div>
                            <div class="h4 mb-0"><?php echo (int) $totalReglas; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="small text-muted">Activas</div>
                            <div class="h4 mb-0 text-success"><?php echo (int) $totalActivas; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="small text-muted">Inactivas</div>
                            <div class="h4 mb-0 text-secondary"><?php echo (int) $totalInactivas; ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h6 mb-0">Reglas registradas</h2>
                        <div class="d-flex gap-2">
                            <?php if ($formData['id_regla_cobro_auto'] > 0): ?>
                                <a href="<?php echo msp2Escape(msp2Url('cobros/reglas_cobro_auto.php')); ?>" class="btn btn-outline-secondary btn-sm">Cancelar edicion</a>
                            <?php endif; ?>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                data-bs-toggle="offcanvas"
                                data-bs-target="#reglaOffcanvas"
                                aria-controls="reglaOffcanvas">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva regla
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Item</th>
                                <th class="text-end">Monto</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($reglas === []): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">Sin reglas registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reglas as $regla): ?>
                                    <?php
                                    $idRegla = (int) ($regla['id_regla_cobro_auto'] ?? 0);
                                    $activo = (int) ($regla['activo'] ?? 0) === 1;
                                    $vigencia = substr((string) ($regla['fecha_inicio_vigencia'] ?? ''), 0, 10);
                                    $vigenciaFin = substr((string) ($regla['fecha_fin_vigencia'] ?? ''), 0, 10);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo msp2Escape((string) ($regla['codigo_regla'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($regla['nombre_regla'] ?? '')); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo msp2Escape((string) ($regla['nombre_item'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($regla['codigo_item'] ?? '')); ?></div>
                                        </td>
                                        <td class="text-end">$ <?php echo number_format((float) ($regla['monto_unitario'] ?? 0), 2, ',', '.'); ?></td>
                                        <td>
                                            <div><?php echo msp2Escape($vigencia !== '' ? $vigencia : '-'); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape($vigenciaFin !== '' ? ('hasta ' . $vigenciaFin) : 'sin termino'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $activo ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo $activo ? 'Activa' : 'Inactiva'; ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <a href="<?php echo msp2Escape(msp2Url('cobros/reglas_cobro_auto.php') . '?edit=' . $idRegla); ?>" class="btn btn-outline-primary btn-sm">Editar</a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="accion" value="cambiar_estado">
                                                    <input type="hidden" name="id_regla_cobro_auto" value="<?php echo $idRegla; ?>">
                                                    <?php if ($activo): ?>
                                                        <button type="submit" class="btn btn-outline-warning btn-sm">Desactivar</button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="activar" value="1">
                                                        <button type="submit" class="btn btn-outline-success btn-sm">Activar</button>
                                                    <?php endif; ?>
                                                </form>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Eliminar esta regla? Esta accion no se puede deshacer.');">
                                                    <input type="hidden" name="accion" value="eliminar_regla">
                                                    <input type="hidden" name="id_regla_cobro_auto" value="<?php echo $idRegla; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Eliminar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="offcanvas offcanvas-end" tabindex="-1" id="reglaOffcanvas" aria-labelledby="reglaOffcanvasLabel">
                <div class="offcanvas-header">
                    <h2 class="offcanvas-title h5" id="reglaOffcanvasLabel"><?php echo $formData['id_regla_cobro_auto'] > 0 ? 'Editar regla' : 'Nueva regla'; ?></h2>
                    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
                </div>
                <div class="offcanvas-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="accion" value="guardar_regla">
                        <input type="hidden" name="id_regla_cobro_auto" value="<?php echo (int) $formData['id_regla_cobro_auto']; ?>">

                        <div class="col-12">
                            <label class="form-label">Codigo</label>
                            <input type="text" class="form-control" name="codigo_regla" maxlength="60" required value="<?php echo msp2Escape($formData['codigo_regla']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Nombre</label>
                            <input type="text" class="form-control" name="nombre_regla" maxlength="120" required value="<?php echo msp2Escape($formData['nombre_regla']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descripcion</label>
                            <textarea class="form-control" name="descripcion_regla" rows="2" maxlength="200"><?php echo msp2Escape($formData['descripcion_regla']); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tipo de item documento</label>
                            <select class="form-select" name="id_tipo_item_documento" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($tiposItem as $tipo): ?>
                                    <?php $tipoId = (int) ($tipo['id_tipo_item_documento'] ?? 0); ?>
                                    <option value="<?php echo $tipoId; ?>" <?php echo $formData['id_tipo_item_documento'] === $tipoId ? 'selected' : ''; ?>>
                                        <?php echo msp2Escape((string) ($tipo['nombre_item'] ?? '') . ' (' . (string) ($tipo['codigo_item'] ?? '') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Monto unitario</label>
                            <input type="text" class="form-control" name="monto_unitario" required value="<?php echo msp2Escape($formData['monto_unitario']); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Dias de gracia</label>
                            <input type="number" class="form-control" name="dias_gracia" min="0" max="3650" required value="<?php echo (int) $formData['dias_gracia']; ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Inicio vigencia</label>
                            <input type="date" class="form-control" name="fecha_inicio_vigencia" required value="<?php echo msp2Escape($formData['fecha_inicio_vigencia']); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Fin vigencia</label>
                            <input type="date" class="form-control" name="fecha_fin_vigencia" value="<?php echo msp2Escape($formData['fecha_fin_vigencia']); ?>">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Orden aplicacion</label>
                            <input type="number" class="form-control" name="orden_aplicacion" min="1" max="9999" required value="<?php echo (int) $formData['orden_aplicacion']; ?>">
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="activo" id="activo_regla" value="1" <?php echo $formData['activo'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="activo_regla">Regla activa</label>
                            </div>
                        </div>

                        <div class="col-12 d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $formData['id_regla_cobro_auto'] > 0 ? 'Actualizar regla' : 'Crear regla'; ?>
                            </button>
                            <?php if ($formData['id_regla_cobro_auto'] > 0): ?>
                                <a href="<?php echo msp2Escape(msp2Url('cobros/reglas_cobro_auto.php')); ?>" class="btn btn-outline-secondary">Nueva</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const shouldOpenForm = <?php echo $formData['id_regla_cobro_auto'] > 0 ? 'true' : 'false'; ?>;
    if (!shouldOpenForm) {
        return;
    }
    const offcanvasElement = document.getElementById('reglaOffcanvas');
    if (!offcanvasElement || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
        return;
    }
    const offcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasElement);
    offcanvas.show();
});
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
