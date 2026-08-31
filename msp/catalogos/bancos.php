<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$tablaExiste = false;
$bancos = [];

$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$mostrarInactivos = isset($_GET['mostrar_inactivos']) && $_GET['mostrar_inactivos'] === '1';
$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

function bancosRedirect(): never
{
    $query = $_GET;
    unset($query['editar']);
    msp2Redirect('catalogos/bancos.php' . ($query ? ('?' . http_build_query($query)) : ''));
}

try {
    $tablaExiste = msp2TableExists($conn, 'msp_bancos');
    if (!$tablaExiste) {
        $loadError = 'La tabla `msp_bancos` no existe todavía. Ejecuta `msp/db/patch_catalogo_bancos.sql` antes de continuar.';
    }
} catch (PDOException) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de bancos.';
}

if ($tablaExiste && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    try {
        if ($accion === 'guardar') {
            $idBanco = filter_input(INPUT_POST, 'id_banco', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $nombreBanco = msp2NormalizeText($_POST['nombre_banco'] ?? null);
            $codigoBanco = msp2NormalizeText($_POST['codigo_banco'] ?? null);
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($nombreBanco === '') {
                throw new RuntimeException('Debes ingresar el nombre del banco.');
            }
            if (mb_strlen($nombreBanco) > 120) {
                throw new RuntimeException('El nombre del banco no puede superar 120 caracteres.');
            }
            if ($codigoBanco !== '' && mb_strlen($codigoBanco) > 20) {
                throw new RuntimeException('El código de banco no puede superar 20 caracteres.');
            }

            $dupSql = 'SELECT COUNT(*) FROM dbo.msp_bancos WHERE nombre_banco = :nombre';
            if ($idBanco !== false && $idBanco !== null) {
                $dupSql .= ' AND id_banco <> :id_banco';
            }
            $dupStmt = $conn->prepare($dupSql);
            $dupStmt->bindValue(':nombre', $nombreBanco, PDO::PARAM_STR);
            if ($idBanco !== false && $idBanco !== null) {
                $dupStmt->bindValue(':id_banco', $idBanco, PDO::PARAM_INT);
            }
            $dupStmt->execute();
            if ((int) $dupStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe un banco con ese nombre.');
            }

            if ($idBanco !== false && $idBanco !== null) {
                $stmt = $conn->prepare(
                    'UPDATE dbo.msp_bancos
                     SET nombre_banco = :nombre,
                         codigo_banco = :codigo,
                         activo = :activo,
                         updated_at = SYSDATETIME()
                     WHERE id_banco = :id_banco'
                );
                $stmt->bindValue(':id_banco', $idBanco, PDO::PARAM_INT);
                $stmt->bindValue(':nombre', $nombreBanco, PDO::PARAM_STR);
                $stmt->bindValue(':codigo', $codigoBanco !== '' ? $codigoBanco : null, $codigoBanco !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':activo', $activo, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->rowCount() <= 0) {
                    throw new RuntimeException('El banco que intentas editar ya no existe.');
                }
                msp2SetFlash('success', 'Banco actualizado correctamente.');
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO dbo.msp_bancos (nombre_banco, codigo_banco, activo, created_at, updated_at)
                     VALUES (:nombre, :codigo, :activo, SYSDATETIME(), SYSDATETIME())'
                );
                $stmt->bindValue(':nombre', $nombreBanco, PDO::PARAM_STR);
                $stmt->bindValue(':codigo', $codigoBanco !== '' ? $codigoBanco : null, $codigoBanco !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':activo', $activo, PDO::PARAM_INT);
                $stmt->execute();
                msp2SetFlash('success', 'Banco creado correctamente.');
            }

            bancosRedirect();
        }

        if ($accion === 'toggle') {
            $idBanco = filter_input(INPUT_POST, 'id_banco', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($idBanco === false || $idBanco === null) {
                throw new RuntimeException('Banco inválido.');
            }

            $stmt = $conn->prepare(
                'UPDATE dbo.msp_bancos
                 SET activo = :activo,
                     updated_at = SYSDATETIME()
                 WHERE id_banco = :id_banco'
            );
            $stmt->bindValue(':activo', $activo, PDO::PARAM_INT);
            $stmt->bindValue(':id_banco', $idBanco, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('El banco indicado no existe.');
            }

            msp2SetFlash('success', $activo ? 'Banco activado.' : 'Banco desactivado.');
            bancosRedirect();
        }

        if ($accion === 'eliminar') {
            $idBanco = filter_input(INPUT_POST, 'id_banco', FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($idBanco === false || $idBanco === null) {
                throw new RuntimeException('Banco inválido.');
            }

            $stmt = $conn->prepare('DELETE FROM dbo.msp_bancos WHERE id_banco = :id_banco');
            $stmt->bindValue(':id_banco', $idBanco, PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('El banco indicado no existe.');
            }

            msp2SetFlash('success', 'Banco eliminado correctamente.');
            bancosRedirect();
        }
    } catch (Throwable $e) {
        msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible procesar la solicitud.');
        bancosRedirect();
    }
}

$bancoEdit = null;
if ($tablaExiste) {
    try {
        $where = ['1=1'];
        $params = [];

        if (!$mostrarInactivos) {
            $where[] = 'activo = 1';
        }
        if ($filtroTexto !== '') {
            $where[] = '(nombre_banco LIKE :filtro OR ISNULL(codigo_banco, \'\') LIKE :filtro)';
            $params[':filtro'] = '%' . $filtroTexto . '%';
        }

        $stmt = $conn->prepare(
            'SELECT id_banco, nombre_banco, codigo_banco, activo, updated_at
             FROM dbo.msp_bancos
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY nombre_banco ASC'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $bancos = $stmt->fetchAll();

        if ($editarId !== false && $editarId !== null) {
            $editStmt = $conn->prepare(
                'SELECT id_banco, nombre_banco, codigo_banco, activo
                 FROM dbo.msp_bancos
                 WHERE id_banco = :id_banco'
            );
            $editStmt->bindValue(':id_banco', $editarId, PDO::PARAM_INT);
            $editStmt->execute();
            $bancoEdit = $editStmt->fetch() ?: null;
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar bancos. Detalle técnico: ' . $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Bancos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('catalogo_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a catálogos
            </a>
        </div>

        <p class="section-kicker text-center">MSP / Catálogos</p>
        <h1 class="form-title text-center mb-2">Bancos</h1>
        <p class="text-muted text-center mb-4">Catálogo para pagos con cheque en cobranza.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-5">
                    <form method="post" class="border rounded p-3 bg-white">
                        <input type="hidden" name="accion" value="guardar">
                        <?php if (is_array($bancoEdit)): ?>
                            <input type="hidden" name="id_banco" value="<?php echo (int) ($bancoEdit['id_banco'] ?? 0); ?>">
                        <?php endif; ?>
                        <h2 class="h6 mb-3"><?php echo is_array($bancoEdit) ? 'Editar banco' : 'Agregar banco'; ?></h2>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Nombre banco</label>
                                <input type="text" class="form-control" name="nombre_banco" maxlength="120"
                                       value="<?php echo msp2Escape((string) ($bancoEdit['nombre_banco'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Código (opcional)</label>
                                <input type="text" class="form-control" name="codigo_banco" maxlength="20"
                                       value="<?php echo msp2Escape((string) ($bancoEdit['codigo_banco'] ?? '')); ?>">
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="activo_banco" name="activo"
                                        <?php echo !is_array($bancoEdit) || (int) ($bancoEdit['activo'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="activo_banco">Activo</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">Guardar</button>
                            <?php if (is_array($bancoEdit)): ?>
                                <a href="<?php echo msp2Escape(msp2Url('catalogos/bancos.php')); ?>" class="btn btn-outline-secondary">Cancelar edición</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="col-12 col-lg-7">
                    <form method="get" class="border rounded p-3 bg-white">
                        <h2 class="h6 mb-3">Filtros</h2>
                        <div class="row g-2">
                            <div class="col-12 col-md-8">
                                <label class="form-label">Buscar</label>
                                <input type="text" class="form-control" name="filtroTexto" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Nombre o código">
                            </div>
                            <div class="col-12 col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="mostrar_inactivos" name="mostrar_inactivos" value="1" <?php echo $mostrarInactivos ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="mostrar_inactivos">Mostrar inactivos</label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-outline-primary btn-sm">Aplicar filtros</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center">
                    <thead class="table-light">
                    <tr>
                        <th style="width: 70px;">#</th>
                        <th class="text-start">Banco</th>
                        <th style="width: 150px;">Código</th>
                        <th style="width: 100px;">Estado</th>
                        <th style="width: 190px;">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($bancos === []): ?>
                        <tr>
                            <td colspan="5" class="text-muted">No hay bancos para los filtros actuales.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bancos as $index => $banco): ?>
                            <?php
                            $idBanco = (int) ($banco['id_banco'] ?? 0);
                            $nombreBanco = (string) ($banco['nombre_banco'] ?? '');
                            $activoBanco = (int) ($banco['activo'] ?? 0) === 1;
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td class="text-start"><?php echo msp2Escape($nombreBanco); ?></td>
                                <td><?php echo msp2Escape((string) ($banco['codigo_banco'] ?? '-')); ?></td>
                                <td><?php echo $activoBanco ? 'Activo' : 'Inactivo'; ?></td>
                                <td>
                                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                                        <a href="<?php echo msp2Escape(msp2Url('catalogos/bancos.php?editar=' . $idBanco)); ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="accion" value="toggle">
                                            <input type="hidden" name="id_banco" value="<?php echo $idBanco; ?>">
                                            <input type="hidden" name="activo" value="<?php echo $activoBanco ? '0' : '1'; ?>">
                                            <button type="submit" class="btn btn-outline-warning btn-sm"><?php echo $activoBanco ? 'Desactivar' : 'Activar'; ?></button>
                                        </form>
                                        <form method="post" class="d-inline"
                                              data-confirm-message="¿Eliminar el banco &quot;<?php echo msp2Escape($nombreBanco); ?>&quot;?"
                                              data-confirm-title="Confirmar eliminación"
                                              data-confirm-variant="danger">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_banco" value="<?php echo $idBanco; ?>">
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
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__) . '/templates/components/confirm_action_modal.php'; ?>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
