<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CierreMensualService.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}
$tablaExiste = false;
$loadError = null;
$registros = [];

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

$estadosCierre = CierreMensualService::estados();

try {
    if (!msp2TableExists($conn, 'msp_cierre_mensual')) {
        $loadError = 'Falta la tabla `msp_cierre_mensual`. Ejecuta `msp/db/msp_cobro_servicios.sql`.';
    } else {
        $tablaExiste = true;
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de cierre mensual.';
}

$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

if ($tablaExiste) {
    try {
        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $conditions[] = "CONVERT(CHAR(7), c.periodo_facturacion, 126) LIKE :filtro";
            $params[':filtro'] = '%' . $filtroTexto . '%';
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'c.estado_cierre = :estado_cierre';
            $params[':estado_cierre'] = (int) $filtroEstado;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $countStmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM dbo.msp_cierre_mensual c
             WHERE $whereClause"
        );

        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $countStmt->execute();
        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $stmt = $conn->prepare(
            "SELECT
                c.id_cierre_mensual,
                c.periodo_facturacion,
                c.fecha_valor_uf,
                c.valor_uf,
                c.estado_cierre,
                c.observaciones,
                c.fecha_registro
             FROM dbo.msp_cierre_mensual c
             WHERE $whereClause
             ORDER BY c.periodo_facturacion DESC, c.id_cierre_mensual DESC
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $registros = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los cierres mensuales. Detalle técnico: ' . $exception->getMessage();
    }
}

if ($tablaExiste && $totalPaginas > 1) {
    $pages = [1];
    $start = max(2, $paginaActual - 2);
    $end = min($totalPaginas - 1, $paginaActual + 2);

    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }

    if ($totalPaginas > 1) {
        $pages[] = $totalPaginas;
    }

    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = null;
    foreach ($pages as $page) {
        if ($prev !== null && $page > $prev + 1) {
            $paginationItems[] = 'ellipsis';
        }
        $paginationItems[] = $page;
        $prev = $page;
    }
}

function cierreFormatoPeriodo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('m-Y');
}

function cierreFormatoFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('d-m-Y');
}

function cierreEstadoBadge(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'borrador' => 'bg-secondary',
        'calculado' => 'bg-info text-dark',
        'revisado' => 'bg-primary',
        'cerrado' => 'bg-success',
        'anulado' => 'bg-danger',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Cierre Mensual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .cierre-shell { width:100%; min-width:0; }
        .cierre-table-wrap { width:100%; overflow:visible; }
        .cierre-table { width:100%; table-layout:fixed; margin-bottom:0; }
        .cierre-table th,
        .cierre-table td { padding:.42rem .45rem; font-size:clamp(.76rem,.82vw,.9rem); vertical-align:middle; }
        .cierre-table th { white-space:nowrap; }
        .cierre-table td:not(.cierre-actions) { overflow:hidden; }
        .cierre-nowrap { white-space:nowrap; }
        .cierre-observacion { display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; cursor:help; }
        .cierre-actions .d-flex { flex-wrap:wrap; }
        .cierre-actions .btn { white-space:nowrap; }
        .cierre-observation-tooltip { --bs-tooltip-max-width:min(430px, calc(100vw - 24px)); }
        .cierre-observation-tooltip .tooltip-inner { padding:.65rem .8rem; text-align:left; white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.35; }
        @media (max-width:767.98px) {
            .cierre-table thead { display:none; }
            .cierre-table,
            .cierre-table tbody,
            .cierre-table tr,
            .cierre-table td { display:block; width:100%; }
            .cierre-table tr { margin-bottom:.75rem; border:1px solid var(--color-border); border-radius:10px; overflow:hidden; background:var(--color-surface); }
            .cierre-table td { display:grid; grid-template-columns:8.5rem minmax(0,1fr); gap:.65rem; padding:.42rem .65rem; border-width:0 0 1px; text-align:left !important; }
            .cierre-table td:last-child { border-bottom:0; }
            .cierre-table td::before { content:attr(data-label); font-weight:700; color:var(--color-text-muted); }
            .cierre-actions .d-flex { justify-content:flex-start !important; }
        }
    </style>
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="cierre-shell">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
            </a>

        </div>

        <h1 class="form-title text-center mb-2">Cierre mensual</h1>
        <p class="text-muted text-center mb-4">Define periodos de cobro, valor UF y estado del ciclo.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form class="row g-2 mb-3" method="get">
                <div class="col-12 col-md-5">
                    <input type="text" class="form-control" name="filtroTexto" placeholder="Periodo (YYYY-MM)" value="<?php echo msp2Escape($filtroTexto); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" name="filtroEstado">
                        <option value="">Estado</option>
                        <?php foreach ($estadosCierre as $estadoId => $estadoLabel): ?>
                            <option value="<?php echo $estadoId; ?>" <?php echo $filtroEstado === (string) $estadoId ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estadoLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select class="form-select" name="lineas">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?> filas
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <div class="d-flex justify-content-end mb-3">
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearCierre">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo cierre
                </button>
            </div>

            <div class="cierre-table-wrap">
                <table class="table table-bordered table-hover align-middle cierre-table">
                    <colgroup>
                        <col style="width:4%">
                        <col style="width:9%">
                        <col style="width:11%">
                        <col style="width:11%">
                        <col style="width:10%">
                        <col style="width:25%">
                        <col style="width:30%">
                    </colgroup>
                    <thead class="table-light text-center">
                        <tr>
                            <th>#</th>
                            <th>Periodo</th>
                            <th>Fecha UF</th>
                            <th>Valor UF</th>
                            <th>Estado</th>
                            <th>Observaciones</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($registros === []): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">Sin cierres registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $index => $row): ?>
                                <?php
                                $estadoId = (int) ($row['estado_cierre'] ?? 0);
                                $estadoLabel = $estadosCierre[$estadoId] ?? 'Sin estado';
                                ?>
                                <tr>
                                    <td class="text-center cierre-nowrap" data-label="#"><?php echo (($paginaActual - 1) * $lineasPorPagina) + $index + 1; ?></td>
                                    <td class="cierre-nowrap" data-label="Periodo"><?php echo msp2Escape(cierreFormatoPeriodo((string) ($row['periodo_facturacion'] ?? ''))); ?></td>
                                    <td class="cierre-nowrap" data-label="Fecha UF"><?php echo msp2Escape(cierreFormatoFecha((string) ($row['fecha_valor_uf'] ?? ''))); ?></td>
                                    <td class="text-end cierre-nowrap" data-label="Valor UF"><?php echo msp2Escape(msp2FormatoDecimal($row['valor_uf'] ?? null, 4)); ?></td>
                                    <td class="text-center" data-label="Estado">
                                        <span class="badge <?php echo cierreEstadoBadge($estadoLabel); ?>">
                                            <?php echo msp2Escape($estadoLabel); ?>
                                        </span>
                                    </td>
                                    <?php $observacionCompleta = trim((string) ($row['observaciones'] ?? '')); ?>
                                    <td data-label="Observaciones">
                                        <?php if ($observacionCompleta === ''): ?>
                                            <span class="text-muted">—</span>
                                        <?php else: ?>
                                            <span
                                                class="cierre-observacion"
                                                tabindex="0"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto"
                                                data-bs-boundary="viewport"
                                                data-bs-custom-class="cierre-observation-tooltip"
                                                data-bs-title="<?php echo msp2Escape($observacionCompleta); ?>"
                                                aria-label="Observación completa: <?php echo msp2Escape($observacionCompleta); ?>">
                                                <?php echo msp2Escape(mb_strimwidth($observacionCompleta, 0, 70, '…', 'UTF-8')); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center cierre-actions" data-label="Acciones">
                                        <div class="d-flex flex-wrap justify-content-center gap-1">
                                            <?php if ($estadoId === CierreMensualService::BORRADOR): ?>
                                            <button
                                                class="btn btn-outline-primary btn-sm js-edit-cierre"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarCierre"
                                                data-id="<?php echo (int) $row['id_cierre_mensual']; ?>"
                                                data-periodo="<?php echo msp2Escape((string) $row['periodo_facturacion']); ?>"
                                                data-fecha-uf="<?php echo msp2Escape((string) $row['fecha_valor_uf']); ?>"
                                                data-valor-uf="<?php echo msp2Escape((string) $row['valor_uf']); ?>"
                                                data-estado="<?php echo $estadoId; ?>"
                                                data-observaciones="<?php echo msp2Escape((string) ($row['observaciones'] ?? '')); ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($estadoId === CierreMensualService::CALCULADO): ?>
                                                <form method="post" action="<?php echo msp2Escape(msp2Url('cierre_mensual/transicionar.php')); ?>">
                                                    <input type="hidden" name="id_cierre_mensual" value="<?php echo (int) $row['id_cierre_mensual']; ?>">
                                                    <input type="hidden" name="estado_esperado" value="2">
                                                    <input type="hidden" name="estado_destino" value="5">
                                                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-square me-1"></i>Revisar</button>
                                                </form>
                                            <?php elseif ($estadoId === CierreMensualService::REVISADO): ?>
                                                <form method="post" action="<?php echo msp2Escape(msp2Url('cierre_mensual/transicionar.php')); ?>" data-confirm-message="¿Cerrar este período revisado?">
                                                    <input type="hidden" name="id_cierre_mensual" value="<?php echo (int) $row['id_cierre_mensual']; ?>">
                                                    <input type="hidden" name="estado_esperado" value="5">
                                                    <input type="hidden" name="estado_destino" value="3">
                                                    <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-lock me-1"></i>Cerrar</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($estadoId, [2, 3, 4, 5], true)): ?>
                                                <button class="btn btn-outline-warning btn-sm js-draft-cierre" type="button" data-bs-toggle="modal" data-bs-target="#modalVolverBorrador" data-id="<?php echo (int) $row['id_cierre_mensual']; ?>" data-estado="<?php echo $estadoId; ?>">
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Borrador
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($estadoId === CierreMensualService::BORRADOR): ?>
                                            <form
                                                method="post"
                                                action="<?php echo msp2Escape(msp2Url('cierre_mensual/eliminar.php')); ?>"
                                                class="d-inline"
                                                data-confirm-message="¿Eliminar el cierre <?php echo msp2Escape(cierreFormatoPeriodo((string) $row['periodo_facturacion'])); ?>?"
                                                data-confirm-title="Confirmar eliminación"
                                                data-confirm-variant="danger">
                                                <input type="hidden" name="id_cierre_mensual" value="<?php echo (int) $row['id_cierre_mensual']; ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Paginación cierre mensual">
                    <ul class="pagination justify-content-center">
                        <?php foreach ($paginationItems as $item): ?>
                            <?php if ($item === 'ellipsis'): ?>
                                <li class="page-item disabled"><span class="page-link">…</span></li>
                            <?php else: ?>
                                <li class="page-item <?php echo $item === $paginaActual ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo msp2Escape(http_build_query(array_merge($_GET, ['pagina' => $item]))); ?>"><?php echo $item; ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalCrearCierre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('cierre_mensual/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Nuevo cierre mensual</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Periodo (YYYY-MM)</label>
                    <input type="month" class="form-control" name="periodo" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha valor UF</label>
                    <input type="date" class="form-control" name="fecha_valor_uf" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Valor UF</label>
                    <input type="number" step="0.000001" min="0" class="form-control" name="valor_uf" required>
                </div>
                <div class="alert alert-light border py-2">El período se crea en estado <strong>Borrador</strong>.</div>
                <div class="mb-0">
                    <label class="form-label">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cierre</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalEditarCierre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('cierre_mensual/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar cierre mensual</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_cierre_mensual" id="edit_id_cierre">
                <div class="mb-3">
                    <label class="form-label">Periodo (YYYY-MM)</label>
                    <input type="month" class="form-control" name="periodo" id="edit_periodo" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Fecha valor UF</label>
                    <input type="date" class="form-control" name="fecha_valor_uf" id="edit_fecha_uf" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Valor UF</label>
                    <input type="number" step="0.000001" min="0" class="form-control" name="valor_uf" id="edit_valor_uf" required>
                </div>
                <div class="alert alert-light border py-2">Solo pueden editarse datos de un período en estado <strong>Borrador</strong>.</div>
                <div class="mb-0">
                    <label class="form-label">Observaciones</label>
                    <textarea class="form-control" name="observaciones" rows="2" maxlength="1000" id="edit_observaciones"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalVolverBorrador" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('cierre_mensual/transicionar.php')); ?>">
            <div class="modal-header"><h2 class="modal-title fs-5">Volver a Borrador</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <input type="hidden" name="id_cierre_mensual" id="draft_id">
                <input type="hidden" name="estado_esperado" id="draft_estado">
                <input type="hidden" name="estado_destino" value="1">
                <label class="form-label" for="draft_motivo">Motivo del cambio</label>
                <textarea class="form-control" id="draft_motivo" name="motivo" maxlength="500" rows="3" required></textarea>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning" type="submit">Volver a Borrador</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        bootstrap.Tooltip.getOrCreateInstance(element, {
            placement: 'auto',
            boundary: 'viewport',
            container: 'body',
            trigger: 'hover focus',
        });
    });

    const toIsoMonth = (raw) => {
        const value = String(raw || '').trim();
        const direct = value.match(/^(\d{4}-\d{2})/);
        if (direct) {
            return direct[1];
        }
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return '';
        }
        const y = parsed.getFullYear();
        const m = String(parsed.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    };

    const toIsoDate = (raw) => {
        const value = String(raw || '').trim();
        const direct = value.match(/^(\d{4}-\d{2}-\d{2})/);
        if (direct) {
            return direct[1];
        }
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return '';
        }
        const y = parsed.getFullYear();
        const m = String(parsed.getMonth() + 1).padStart(2, '0');
        const d = String(parsed.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };

    const toNumberInputValue = (raw) => {
        const value = String(raw || '').trim();
        if (value === '') {
            return '';
        }
        if (/^-?\d+(?:\.\d+)?$/.test(value)) {
            return value;
        }
        const normalized = value
            .replace(/\s/g, '')
            .replace(/\./g, '')
            .replace(',', '.');
        if (/^-?\d+(?:\.\d+)?$/.test(normalized)) {
            return normalized;
        }
        return value;
    };

    document.querySelectorAll('.js-edit-cierre').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_cierre').value = button.dataset.id || '';
            document.getElementById('edit_periodo').value = toIsoMonth(button.dataset.periodo || '');
            document.getElementById('edit_fecha_uf').value = toIsoDate(button.dataset.fechaUf || '');
            document.getElementById('edit_valor_uf').value = toNumberInputValue(button.dataset.valorUf || '');
            document.getElementById('edit_observaciones').value = button.dataset.observaciones || '';
        });
    });

    document.querySelectorAll('.js-draft-cierre').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('draft_id').value = button.dataset.id || '';
            document.getElementById('draft_estado').value = button.dataset.estado || '';
            document.getElementById('draft_motivo').value = '';
        });
    });

})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
