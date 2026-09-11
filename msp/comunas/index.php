<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/templates/components/section_header.php';
require_once dirname(__DIR__, 2) . '/templates/components/crud_table.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}
$tablaExiste = false;
$comunas = [];
$loadError = null;
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroTexto = msp2SearchQuery($_GET['filtroTexto'] ?? '');

/**
 * @return array<int, array{page:int|null,label:string,active?:bool}>
 */
function msp2ComunasBuildPaginationItems(int $paginaActual, int $totalPaginas): array
{
    if ($totalPaginas <= 1) {
        return [];
    }

    $pages = [1, $totalPaginas];
    for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++) {
        $pages[] = $i;
    }

    $pages = array_values(array_unique($pages));
    sort($pages);

    $items = [];
    $last = null;
    foreach ($pages as $page) {
        if ($last !== null && $page > $last + 1) {
            $items[] = ['page' => null, 'label' => '...'];
        }
        $items[] = ['page' => $page, 'label' => (string) $page, 'active' => $page === $paginaActual];
        $last = $page;
    }

    return $items;
}

try {
    $tablaExiste = msp2TableExists($conn, 'msp_comunas');

    if (!$tablaExiste) {
        $loadError = 'La tabla `msp_comunas` no existe todavía. Ejecuta `msp/msp_a1.sql` antes de continuar.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base de comunas.';
}

if ($tablaExiste) {
    try {
        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $search = msp2BuildSearchCondition($filtroTexto, ['desc_comuna', 'id_comuna'], 'comunas_buscar', 'id_comuna');
            $conditions[] = $search['sql'];
            $params = array_merge($params, $search['params']);
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_comunas WHERE $whereClause");

        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $countStmt->execute();

        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $stmt = $conn->prepare(
            "SELECT id_comuna, desc_comuna
             FROM dbo.msp_comunas
             WHERE $whereClause
             ORDER BY desc_comuna ASC
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $comunas = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = pgpPublicException($exception, 'msp.comunas.index', 'No fue posible cargar las comunas.');
    }
}

if ($tablaExiste) {
    $paginationItems = msp2ComunasBuildPaginationItems($paginaActual, $totalPaginas);
}

$queryBase = $_GET;
unset($queryBase['pagina']);

function buildMsp2ComunasQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }

    $query = http_build_query($merged);
    return $query === '' ? '' : ('?' . $query);
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Comunas</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <?php
        gpRenderSectionHeader([
            'kicker' => 'MSP / Catálogos',
            'title' => 'Comunas',
            'description' => 'Catálogo base para el registro de arrendatarios.',
            'back_url' => msp2Url('catalogo_menu.php'),
            'back_label' => 'Volver a catálogos',
            'help_text' => 'Administra el catálogo de comunas que se utiliza en el registro y clasificación de arrendatarios en MSP.',
            'help_aria_label' => 'Información de la sección Comunas',
        ]);
        ?>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-6">
                    <label for="filtroTexto" class="form-label">Buscar comuna</label>
                    <input type="search" id="filtroTexto" name="filtroTexto" class="form-control" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Nombre o #ID">
                </div>
                <div class="col-12 col-md-2">
                    <label for="lineas" class="form-label">Líneas</label>
                    <select id="lineas" name="lineas" class="form-select">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">Buscar</button>
                    <?php if ($filtroTexto !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('comunas/index.php')); ?>" title="Limpiar" aria-label="Limpiar"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </div>
            </form>

            <?php
            gpRenderCrudTable([
                'meta_left' => static function () use ($comunas): void {
                    echo '<strong>' . msp2Escape((string) count($comunas)) . ' comuna(s) en la vista actual</strong>';
                },
                'meta_right' => static function (): void {
                    gpRenderCrudPrimaryAction([
                        'label' => 'Agregar comuna',
                        'icon' => 'bi bi-plus-circle',
                        'attrs' => [
                            'type' => 'button',
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#modalCrearComuna',
                        ],
                    ]);
                },
                'table_class' => 'table table-bordered table-hover align-middle text-center mb-0',
                'headers' => [
                    [
                        'label' => '#',
                        'attrs' => ['style' => 'width: 70px;'],
                    ],
                    'Nombre comuna',
                    [
                        'label' => 'Acciones',
                        'attrs' => ['style' => 'width: 140px;'],
                    ],
                ],
                'rows' => $comunas,
                'row_context' => [
                    'pagina_actual' => $paginaActual,
                    'lineas_por_pagina' => $lineasPorPagina,
                ],
                'row_render' => static function (array $comuna, int $index, array $ctx): void {
                    $numero = (((int) ($ctx['pagina_actual'] ?? 1)) - 1) * ((int) ($ctx['lineas_por_pagina'] ?? 25)) + $index + 1;
                    ?>
                    <tr>
                        <td><?php echo $numero; ?></td>
                        <td class="text-start"><?php echo msp2Escape($comuna['desc_comuna'] ?? ''); ?></td>
                        <td>
                            <div class="d-flex justify-content-center">
                                <?php
                                gpRenderCrudActionsMenu([
                                    'items' => [
                                        [
                                            'type' => 'button',
                                            'label' => 'Editar comuna',
                                            'icon' => 'bi bi-pencil-square',
                                            'class' => 'dropdown-item js-edit-comuna',
                                            'attrs' => [
                                                'type' => 'button',
                                                'data-bs-toggle' => 'modal',
                                                'data-bs-target' => '#modalEditarComuna',
                                                'data-id' => (string) ((int) ($comuna['id_comuna'] ?? 0)),
                                                'data-desc' => (string) ($comuna['desc_comuna'] ?? ''),
                                            ],
                                        ],
                                        ['type' => 'divider'],
                                        [
                                            'type' => 'form',
                                            'label' => 'Eliminar comuna',
                                            'icon' => 'bi bi-trash',
                                            'form_attrs' => [
                                                'method' => 'post',
                                                'action' => msp2Url('comunas/eliminar.php'),
                                                'data-confirm-message' => '¿Eliminar la comuna "' . (string) ($comuna['desc_comuna'] ?? '') . '"?',
                                                'data-confirm-title' => 'Confirmar eliminación',
                                                'data-confirm-variant' => 'danger',
                                            ],
                                            'fields' => [
                                                'id_comuna' => (string) ((int) ($comuna['id_comuna'] ?? 0)),
                                            ],
                                            'button_class' => 'dropdown-item text-danger',
                                        ],
                                    ],
                                ]);
                                ?>
                            </div>
                        </td>
                    </tr>
                    <?php
                },
                'empty_message' => $filtroTexto === '' ? 'No hay comunas registradas todavía.' : 'Sin resultados para los filtros actuales.',
                'empty_colspan' => 3,
                'pagination' => [
                    'enabled' => true,
                    'total_records' => $totalRegistros,
                    'current_page' => $paginaActual,
                    'total_pages' => $totalPaginas,
                    'items' => $paginationItems,
                    'aria_label' => 'Paginación de comunas',
                    'build_url' => static fn (int $page): string => buildMsp2ComunasQuery($queryBase, ['pagina' => $page]),
                ],
            ]);
            ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalCrearComuna" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('comunas/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Agregar comuna</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="crear_desc_comuna" class="form-label">Nombre comuna</label>
                    <input type="text" class="form-control" id="crear_desc_comuna" name="desc_comuna" maxlength="150" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar comuna</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditarComuna" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('comunas/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar comuna</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_comuna" id="edit_id_comuna">
                <div class="mb-3">
                    <label for="edit_desc_comuna" class="form-label">Nombre comuna</label>
                    <input type="text" class="form-control" id="edit_desc_comuna" name="desc_comuna" maxlength="150" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    document.querySelectorAll('.js-edit-comuna').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_comuna').value = button.dataset.id || '';
            document.getElementById('edit_desc_comuna').value = button.dataset.desc || '';
        });
    });

})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
