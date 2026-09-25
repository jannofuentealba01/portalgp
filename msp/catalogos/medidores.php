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
$tiposServicio = [];
$localesCatalogo = [];
$medidores = [];
$tablaExiste = false;

$filtroTexto = msp2SearchQuery($_GET['filtroTexto'] ?? '');
$filtroServicio = trim((string) ($_GET['filtroServicio'] ?? ''));
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

$estadosMedidores = [
    1 => 'Activo',
    2 => 'Retirado',
    3 => 'Inactivo',
];

try {
    $requiredTables = ['msp_medidores', 'msp_tipos_servicio', 'msp_locales'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para la gestión de medidores: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/db/msp_cobro_servicios.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de medidores.';
}

if ($tablaExiste) {
    try {
        $tiposStmt = $conn->query(
            "SELECT id_tipo_servicio, codigo_servicio, nombre_servicio
             FROM dbo.msp_tipos_servicio
             WHERE UPPER(codigo_servicio) IN ('AGUA', 'LUZ', 'GAS')
             ORDER BY CASE UPPER(codigo_servicio)
                        WHEN 'AGUA' THEN 1
                        WHEN 'LUZ' THEN 2
                        WHEN 'GAS' THEN 3
                        ELSE 100
                      END, nombre_servicio ASC"
        );
        $tiposServicio = $tiposStmt->fetchAll();

        $localesStmt = $conn->query(
            'SELECT id_local, cdo_local, desc_local
             FROM dbo.msp_locales
             ORDER BY ' . msp2LocalCodeNaturalOrderSql('cdo_local')
        );
        $localesCatalogo = $localesStmt->fetchAll();

        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $search = msp2BuildSearchCondition($filtroTexto, [
                'm.id_medidor',
                'm.codigo_medidor',
                'm.alias_medidor',
                'm.numero_serie',
                'l.cdo_local',
                "REPLACE(REPLACE(l.cdo_local,N'-',N''),N'.',N'')",
                'l.desc_local',
                'ts.codigo_servicio',
                'ts.nombre_servicio',
            ], 'medidores_buscar', 'm.id_medidor');
            $conditions[] = $search['sql'];
            $params = array_merge($params, $search['params']);
        }

        if ($filtroServicio !== '' && ctype_digit($filtroServicio)) {
            $conditions[] = 'm.id_tipo_servicio = :id_tipo_servicio';
            $params[':id_tipo_servicio'] = (int) $filtroServicio;
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'm.estado_medidor = :estado_medidor';
            $params[':estado_medidor'] = (int) $filtroEstado;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $stmt = $conn->prepare(
            "SELECT
                m.id_medidor,
                m.codigo_medidor,
                m.alias_medidor,
                m.numero_serie,
                m.valor_inicial,
                m.fecha_instalacion,
                m.fecha_retiro,
                m.estado_medidor,
                m.id_local,
                m.id_tipo_servicio,
                l.cdo_local,
                l.desc_local,
                ts.codigo_servicio,
                ts.nombre_servicio
             FROM dbo.msp_medidores m
             INNER JOIN dbo.msp_locales l ON l.id_local = m.id_local
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = m.id_tipo_servicio
             WHERE $whereClause
             ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ", ts.nombre_servicio ASC, m.codigo_medidor ASC"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        $medidores = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = pgpPublicException($exception, 'msp.catalogos.medidores', 'No fue posible cargar los medidores.');
    }
}

function msp2EstadoMedidorLabel(int $estado, array $labels): string
{
    return $labels[$estado] ?? 'Sin estado';
}

function msp2EstadoMedidorBadgeCatalog(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo' => 'bg-success',
        'retirado' => 'bg-secondary',
        'inactivo' => 'bg-warning text-dark',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Medidores</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="box-container-wide">
        <header class="d-flex justify-content-between align-items-center gap-2 mb-3" data-gp-commandbar>
            <a href="<?php echo msp2Escape(msp2Url('locales_tiendas/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Locales y tiendas
            </a>
            <div>
                <h1 class="form-title text-center mb-0">Medidores</h1>
            </div>
            <div class="d-flex gap-2 medidores-actions">
                <a href="#listado-medidores" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Listado de medidores
                </a>
                <a href="<?php echo msp2Escape(msp2Url('medidores/plantilla.php')); ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla
                </a>
                <a href="<?php echo msp2Escape(msp2Url('locales/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Gestionar locales
                </a>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form class="row g-2 mb-3 gp-filter-bar" method="get">
                <div class="col-12 col-lg-6">
                    <input type="search" class="form-control" name="filtroTexto" placeholder="Local, servicio, código, alias, serie o #ID" value="<?php echo msp2Escape($filtroTexto); ?>">
                </div>
                <div class="col-12 col-md-2 gp-secondary-filter-field">
                    <select class="form-select" name="filtroServicio">
                        <option value="">Servicio</option>
                        <?php foreach ($tiposServicio as $tipo): ?>
                            <option value="<?php echo (int) $tipo['id_tipo_servicio']; ?>" <?php echo $filtroServicio === (string) $tipo['id_tipo_servicio'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $tipo['nombre_servicio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <select class="form-select" name="filtroEstado">
                        <option value="">Estado</option>
                        <?php foreach ($estadosMedidores as $estadoId => $estadoLabel): ?>
                            <option value="<?php echo $estadoId; ?>" <?php echo $filtroEstado === (string) $estadoId ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estadoLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-4 d-flex gap-2" data-gp-filter-actions>
                    <button type="submit" class="btn btn-primary flex-grow-1">Buscar</button>
                    <?php if ($filtroTexto !== '' || $filtroServicio !== '' || $filtroEstado !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('catalogos/medidores.php')); ?>" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                </div>
            </form>

            <div class="table-responsive gp-table-shell" id="listado-medidores">
                <table class="table table-bordered table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-meters-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th data-gp-column-kind="short">#</th>
                            <th>Local / servicio</th>
                            <th>Medidor</th>
                            <th data-gp-column-kind="number">Valor inicial</th>
                            <th data-gp-column-kind="state">Estado</th>
                            <th data-gp-column-kind="actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($medidores === []): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Sin medidores disponibles.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medidores as $index => $medidor): ?>
                                <?php
                                $estadoLabel = msp2EstadoMedidorLabel((int) ($medidor['estado_medidor'] ?? 0), $estadosMedidores);
                                $estadoBadge = msp2EstadoMedidorBadgeCatalog($estadoLabel);
                                $localLabel = (string) ($medidor['cdo_local'] ?? '');
                                if (!empty($medidor['desc_local'])) {
                                    $localLabel .= ' - ' . (string) $medidor['desc_local'];
                                }
                                ?>
                                <tr>
                                    <td data-gp-label="#" class="text-center"><?php echo $index + 1; ?></td>
                                    <td data-gp-label="Local / servicio" class="gp-cell-description"><strong><?php echo msp2Escape($localLabel); ?></strong><div class="small text-muted"><?php echo msp2Escape((string) ($medidor['nombre_servicio'] ?? $medidor['codigo_servicio'] ?? '')); ?></div></td>
                                    <td data-gp-label="Medidor" class="gp-cell-description"><strong class="text-uppercase"><?php echo msp2Escape((string) ($medidor['codigo_medidor'] ?? '')); ?></strong><?php if (trim((string) ($medidor['alias_medidor'] ?? '')) !== ''): ?><div><?php echo msp2Escape((string) $medidor['alias_medidor']); ?></div><?php endif; ?><div class="small text-muted">Serie: <?php echo msp2Escape((string) (($medidor['numero_serie'] ?? '') !== '' ? $medidor['numero_serie'] : '-')); ?></div></td>
                                    <td data-gp-label="Valor inicial" class="gp-cell-number"><?php echo msp2Escape(msp2FormatoDecimal($medidor['valor_inicial'] ?? null, 0)); ?></td>
                                    <td data-gp-label="Estado" class="gp-cell-state">
                                        <span class="badge <?php echo $estadoBadge; ?>">
                                            <?php echo msp2Escape($estadoLabel); ?>
                                        </span>
                                    </td>
                                    <td data-gp-label="Acciones" class="gp-cell-actions">
                                        <button type="button" class="btn btn-outline-primary btn-sm js-edit-medidor"
                                            data-bs-toggle="modal" data-bs-target="#modalEditarMedidor"
                                            data-id="<?php echo (int) ($medidor['id_medidor'] ?? 0); ?>"
                                            data-local="<?php echo (int) ($medidor['id_local'] ?? 0); ?>"
                                            data-servicio="<?php echo (int) ($medidor['id_tipo_servicio'] ?? 0); ?>"
                                            data-codigo="<?php echo msp2Escape((string) ($medidor['codigo_medidor'] ?? '')); ?>"
                                            data-serie="<?php echo msp2Escape((string) ($medidor['numero_serie'] ?? '')); ?>"
                                            data-valor="<?php echo msp2Escape((string) ($medidor['valor_inicial'] ?? '')); ?>"
                                            data-instalacion="<?php echo msp2Escape(!empty($medidor['fecha_instalacion']) ? substr((string) $medidor['fecha_instalacion'], 0, 10) : ''); ?>"
                                            data-retiro="<?php echo msp2Escape(!empty($medidor['fecha_retiro']) ? substr((string) $medidor['fecha_retiro'], 0, 10) : ''); ?>"
                                            data-estado="<?php echo (int) ($medidor['estado_medidor'] ?? 0); ?>">
                                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Editar
                                        </button>
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
<div class="modal fade" id="modalEditarMedidor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('medidores/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar medidor</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="redirect_to" value="catalogos/medidores.php">
                <input type="hidden" name="id_medidor" id="edit_medidor_id">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="edit_medidor_local" class="form-label">Local</label>
                        <select class="form-select" name="id_local" id="edit_medidor_local" required>
                            <option value="">Seleccionar local</option>
                            <?php foreach ($localesCatalogo as $localCatalogo): ?>
                                <option value="<?php echo (int) $localCatalogo['id_local']; ?>">
                                    <?php echo msp2Escape((string) $localCatalogo['cdo_local'] . (!empty($localCatalogo['desc_local']) ? ' - ' . (string) $localCatalogo['desc_local'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_medidor_servicio" class="form-label">Servicio</label>
                        <select class="form-select" name="id_tipo_servicio" id="edit_medidor_servicio" required>
                            <option value="">Seleccionar servicio</option>
                            <?php foreach ($tiposServicio as $tipo): ?>
                                <option value="<?php echo (int) $tipo['id_tipo_servicio']; ?>"><?php echo msp2Escape((string) $tipo['nombre_servicio']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_medidor_codigo" class="form-label">Código</label>
                        <input type="text" class="form-control" name="codigo_medidor" id="edit_medidor_codigo" maxlength="100" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_medidor_serie" class="form-label">Número de serie</label>
                        <input type="text" class="form-control" name="numero_serie" id="edit_medidor_serie" maxlength="100">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_medidor_valor" class="form-label">Valor inicial</label>
                        <input type="text" class="form-control" name="valor_inicial" id="edit_medidor_valor" inputmode="decimal">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_medidor_instalacion" class="form-label">Fecha de instalación</label>
                        <input type="date" class="form-control" name="fecha_instalacion" id="edit_medidor_instalacion">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_medidor_retiro" class="form-label">Fecha de retiro</label>
                        <input type="date" class="form-control" name="fecha_retiro" id="edit_medidor_retiro">
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_medidor_estado" class="form-label">Estado</label>
                        <select class="form-select" name="estado_medidor" id="edit_medidor_estado" required>
                            <?php foreach ($estadosMedidores as $estadoId => $estadoLabel): ?>
                                <option value="<?php echo (int) $estadoId; ?>"><?php echo msp2Escape($estadoLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
document.querySelectorAll('.js-edit-medidor').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('edit_medidor_id').value = button.dataset.id || '';
        document.getElementById('edit_medidor_local').value = button.dataset.local || '';
        document.getElementById('edit_medidor_servicio').value = button.dataset.servicio || '';
        document.getElementById('edit_medidor_codigo').value = button.dataset.codigo || '';
        document.getElementById('edit_medidor_serie').value = button.dataset.serie || '';
        document.getElementById('edit_medidor_valor').value = button.dataset.valor || '';
        document.getElementById('edit_medidor_instalacion').value = button.dataset.instalacion || '';
        document.getElementById('edit_medidor_retiro').value = button.dataset.retiro || '';
        document.getElementById('edit_medidor_estado').value = button.dataset.estado || '1';
    });
});
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
