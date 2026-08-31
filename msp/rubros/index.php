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
$rubros = [];
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
$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);

/**
 * @return array<int, array{page:int|null,label:string,active?:bool}>
 */
function msp2RubrosBuildPaginationItems(int $paginaActual, int $totalPaginas): array
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
    $tablaExiste = msp2TableExists($conn, 'msp_rubros');

    if (!$tablaExiste) {
        $loadError = 'La tabla `msp_rubros` no existe todavía. Ejecuta `msp/msp_a1.sql` antes de continuar.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base de rubros.';
}

if ($tablaExiste) {
    try {
        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $conditions[] = "(ISNULL(nombre_rubro, '') LIKE :filtro OR CAST(id_rubro AS NVARCHAR(10)) LIKE :filtro)";
            $params[':filtro'] = '%' . $filtroTexto . '%';
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_rubros WHERE $whereClause");

        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $countStmt->execute();

        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $stmt = $conn->prepare(
            "SELECT id_rubro, nombre_rubro
             FROM dbo.msp_rubros
             WHERE $whereClause
             ORDER BY nombre_rubro ASC
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $rubros = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los rubros. Detalle técnico: ' . $exception->getMessage();
    }
}

if ($tablaExiste) {
    $paginationItems = msp2RubrosBuildPaginationItems($paginaActual, $totalPaginas);
}

$queryBase = $_GET;
unset($queryBase['pagina']);

function buildMsp2RubrosQuery(array $base, array $override = []): string
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
    <title>MSP | Rubros</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
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
            'title' => 'Rubros',
            'back_url' => msp2Url('catalogo_menu.php'),
            'back_label' => 'Volver a catálogos',
            'help_text' => 'Administra el catálogo de rubros comerciales que se utiliza para clasificar tiendas en el módulo MSP.',
            'help_aria_label' => 'Información de la sección Rubros',
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
                    <label for="filtroTexto" class="form-label">Buscar rubro</label>
                    <input type="text" id="filtroTexto" name="filtroTexto" class="form-control" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Buscar por nombre o ID">
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
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <?php
            gpRenderCrudTable([
                'meta_left' => static function () use ($rubros): void {
                    echo '<strong>' . msp2Escape((string) count($rubros)) . ' rubro(s) en la vista actual</strong>';
                },
                'meta_right' => static function (): void {
                    gpRenderCrudPrimaryAction([
                        'label' => 'Agregar rubro',
                        'icon' => 'bi bi-plus-circle',
                        'attrs' => [
                            'type' => 'button',
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#modalCrearRubro',
                        ],
                    ]);
                },
                'table_class' => 'table table-bordered table-hover align-middle text-center mb-0',
                'headers' => [
                    [
                        'label' => '#',
                        'attrs' => ['style' => 'width: 70px;'],
                    ],
                    'Nombre rubro',
                    [
                        'label' => 'Acciones',
                        'attrs' => ['style' => 'width: 140px;'],
                    ],
                ],
                'rows' => $rubros,
                'row_context' => [
                    'pagina_actual' => $paginaActual,
                    'lineas_por_pagina' => $lineasPorPagina,
                ],
                'row_render' => static function (array $rubro, int $index, array $ctx): void {
                    $numero = (((int) ($ctx['pagina_actual'] ?? 1)) - 1) * ((int) ($ctx['lineas_por_pagina'] ?? 25)) + $index + 1;
                    ?>
                    <tr>
                        <td><?php echo $numero; ?></td>
                        <td class="text-start"><?php echo msp2Escape($rubro['nombre_rubro'] ?? ''); ?></td>
                        <td>
                            <div class="d-flex justify-content-center">
                                <?php
                                gpRenderCrudActionsMenu([
                                    'items' => [
                                        [
                                            'type' => 'button',
                                            'label' => 'Editar rubro',
                                            'icon' => 'bi bi-pencil-square',
                                            'class' => 'dropdown-item js-edit-rubro',
                                            'attrs' => [
                                                'type' => 'button',
                                                'data-bs-toggle' => 'modal',
                                                'data-bs-target' => '#modalEditarRubro',
                                                'data-id' => (string) ((int) ($rubro['id_rubro'] ?? 0)),
                                                'data-nombre' => (string) ($rubro['nombre_rubro'] ?? ''),
                                            ],
                                        ],
                                        ['type' => 'divider'],
                                        [
                                            'type' => 'form',
                                            'label' => 'Eliminar rubro',
                                            'icon' => 'bi bi-trash',
                                            'form_attrs' => [
                                                'method' => 'post',
                                                'action' => msp2Url('rubros/eliminar.php'),
                                                'data-confirm-message' => '¿Eliminar el rubro "' . (string) ($rubro['nombre_rubro'] ?? '') . '"?',
                                                'data-confirm-title' => 'Confirmar eliminación',
                                                'data-confirm-variant' => 'danger',
                                            ],
                                            'fields' => [
                                                'id_rubro' => (string) ((int) ($rubro['id_rubro'] ?? 0)),
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
                'empty_message' => $filtroTexto === '' ? 'No hay rubros registrados todavía.' : 'Sin resultados para los filtros actuales.',
                'empty_colspan' => 3,
                'pagination' => [
                    'enabled' => true,
                    'total_records' => $totalRegistros,
                    'current_page' => $paginaActual,
                    'total_pages' => $totalPaginas,
                    'items' => $paginationItems,
                    'aria_label' => 'Paginación de rubros',
                    'build_url' => static fn (int $page): string => buildMsp2RubrosQuery($queryBase, ['pagina' => $page]),
                ],
            ]);
            ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalCrearRubro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('rubros/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Agregar rubro</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="crear_nombre_rubro" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="crear_nombre_rubro" name="nombre_rubro" maxlength="150" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar rubro</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditarRubro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('rubros/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar rubro</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_rubro" id="edit_id_rubro">
                <div class="mb-3">
                    <label for="edit_nombre_rubro" class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="edit_nombre_rubro" name="nombre_rubro" maxlength="150" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    document.querySelectorAll('.js-edit-rubro').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_rubro').value = button.dataset.id || '';
            document.getElementById('edit_nombre_rubro').value = button.dataset.nombre || '';
        });
    });

})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
