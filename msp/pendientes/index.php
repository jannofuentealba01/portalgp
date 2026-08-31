<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/PendientesService.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$usuarioActual = (int) ($_SESSION['usuario']['id'] ?? 0);
$vista = strtolower(trim((string) ($_GET['vista'] ?? 'todos')));
if (!in_array($vista, ['todos', 'hoy', 'criticos', 'mis'], true)) {
    $vista = 'todos';
}

$filtros = [
    'buscar' => trim((string) ($_GET['buscar'] ?? '')),
    'modulo' => strtoupper(trim((string) ($_GET['modulo'] ?? ''))),
    'prioridad' => strtoupper(trim((string) ($_GET['prioridad'] ?? ''))),
    'estado' => strtoupper(trim((string) ($_GET['estado'] ?? ''))),
    'desde' => trim((string) ($_GET['desde'] ?? '')),
    'hasta' => trim((string) ($_GET['hasta'] ?? '')),
    'periodo' => trim((string) ($_GET['periodo'] ?? '')),
    'arrendatario' => trim((string) ($_GET['arrendatario'] ?? '')),
    'contrato' => trim((string) ($_GET['contrato'] ?? '')),
    'local' => trim((string) ($_GET['local'] ?? '')),
    'agrupar' => true,
];
if (preg_match('/^\d{4}-\d{2}$/', $filtros['periodo']) === 1) {
    $filtros['periodo'] .= '-01';
}
if ($filtros['estado'] === 'POSPUESTO') {
    $filtros['incluir_pospuestos'] = true;
}
if ($vista === 'criticos') {
    $filtros['prioridad'] = 'CRITICA';
}
if ($vista === 'mis') {
    $filtros['mis_tareas'] = true;
    $filtros['usuario_id'] = $usuarioActual;
}

$pendientes = [];
$resumen = ['total' => 0, 'CRITICA' => 0, 'ALTA' => 0, 'NORMAL' => 0, 'INFORMATIVA' => 0, 'por_modulo' => []];
$diagnosticos = [];
$errorCarga = null;
try {
    $motor = new PendientesService($conn);
    $resumen = $motor->resumen();
    $pendientes = $motor->buscar($filtros);
    if ($vista === 'hoy') {
        $hoy = date('Y-m-d');
        $pendientes = array_values(array_filter($pendientes, static function (array $item) use ($hoy): bool {
            return (($item['fecha_limite'] ?? null) !== null && (string) $item['fecha_limite'] <= $hoy)
                || (string) ($item['fecha_origen'] ?? '') === $hoy;
        }));
    }
    $diagnosticos = $motor->diagnosticos();
} catch (Throwable $exception) {
    $errorCarga = 'No fue posible cargar la Bandeja de pendientes.';
}

$usuarios = [];
try {
    $usuarios = $conn->query(
        "SELECT id,nombre_completo,UserName FROM dbo.cr_usuarios WHERE estado_id=1 ORDER BY nombre_completo,UserName"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
    $usuarios = [];
}

$modulos = [
    'GARANTIA' => 'Garantías',
    'OPERACION_MENSUAL' => 'Operación mensual',
    'LECTURAS' => 'Lecturas y servicios',
    'COBRANZA' => 'Cobranza',
    'TESORERIA' => 'Tesorería y caja',
    'CONTRATOS' => 'Contratos',
    'LOCALES' => 'Locales',
    'CONTABILIDAD' => 'Contabilidad',
];
$prioridadClases = [
    'CRITICA' => ['danger', 'Crítica'],
    'ALTA' => ['warning', 'Alta'],
    'NORMAL' => ['primary', 'Normal'],
    'INFORMATIVA' => ['secondary', 'Informativa'],
];

function pendientesFecha(?string $fecha): string
{
    if ($fecha === null || trim($fecha) === '') {
        return '-';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($fecha, 0, 10));
    return $date instanceof DateTimeImmutable ? $date->format('d-m-Y') : $fecha;
}

function pendientesMonto(mixed $monto): string
{
    return '$ ' . number_format((float) $monto, 0, ',', '.');
}

function pendientesUrlAccion(string $url): string
{
    if ($url === '') {
        return '#';
    }
    return str_starts_with($url, '/') ? $url : msp2Url($url);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bandeja de pendientes MSP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .pending-shell { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; }
        .pending-shell .pending-page-header { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 1rem; margin-bottom: .85rem; }
        .pending-page-header .pending-back { grid-column: 1; grid-row: 1; justify-self: start; }
        .pending-page-header h1 { grid-column: 2; grid-row: 1; justify-self: center; margin: 0; color: #003399; font-size: 1.75rem; line-height: 1.2; font-weight: 600; text-align: center; }
        .pending-page-header .pending-updated { grid-column: 3; grid-row: 1; justify-self: end; white-space: nowrap; }
        .pending-kpi { border: 1px solid #dbe4ef; border-radius: .65rem; background: #fff; padding: .7rem .8rem; height: 100%; }
        .pending-kpi strong { display: block; font-size: 1.45rem; line-height: 1; margin-top: .25rem; }
        .pending-card { border: 1px solid #ced8e5; border-left-width: 5px; border-radius: .65rem; background: #fff; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); }
        .pending-card.priority-CRITICA { border-left-color: #b42318; }
        .pending-card.priority-ALTA { border-left-color: #d97706; }
        .pending-card.priority-NORMAL { border-left-color: #2563eb; }
        .pending-card.priority-INFORMATIVA { border-left-color: #64748b; }
        .pending-context { display: flex; flex-wrap: wrap; gap: .45rem; }
        .pending-context span { border: 1px solid #dbe4ef; border-radius: 999px; padding: .25rem .55rem; background: #f8fafc; font-size: .8rem; color: #475569; }
        .pending-quick .nav-link { border-radius: 999px; color: #334155; font-weight: 600; }
        .pending-quick .nav-link.active { background: #123f72; color: #fff; }
        .pending-management { background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .pending-detail-row { border-bottom: 1px solid #e2e8f0; padding: .65rem 0; }
        .pending-detail-row:last-child { border-bottom: 0; }
        @media (max-width: 850px) {
            .pending-shell .pending-page-header { display: flex; flex-direction: column; align-items: stretch; gap: .6rem; }
            .pending-page-header h1 { align-self: center; order: 1; }
            .pending-page-header .pending-back { align-self: flex-start; order: 2; }
            .pending-page-header .pending-updated { align-self: flex-start; order: 3; font-size: .72rem; }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index pending-shell">
        <header class="msp-management-page-header pending-page-header">
            <div class="pending-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Bandeja de pendientes</h1>
            <span class="pending-updated text-muted small">Actualizado: <?php echo msp2Escape(date('d-m-Y H:i')); ?></span>
        </header>

        <?php msp2RenderFlash($flash); ?>
        <?php if ($errorCarga !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($errorCarga); ?></div><?php endif; ?>
        <?php if ($diagnosticos !== []): ?>
            <div class="alert alert-warning">
                <strong>Algunos módulos no pudieron revisarse.</strong>
                <?php foreach ($diagnosticos as $diagnostico): ?>
                    <div class="small"><?php echo msp2Escape($modulos[(string) $diagnostico['modulo']] ?? (string) $diagnostico['modulo']); ?> no está disponible temporalmente.</div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <ul class="nav pending-quick gap-2 mb-3">
            <?php foreach (['todos' => 'Todos', 'hoy' => 'Hoy', 'criticos' => 'Críticos', 'mis' => 'Mis tareas'] as $codigo => $etiqueta): ?>
                <li class="nav-item"><a class="nav-link <?php echo $vista === $codigo ? 'active' : ''; ?>" href="?vista=<?php echo msp2Escape($codigo); ?>"><?php echo msp2Escape($etiqueta); ?></a></li>
            <?php endforeach; ?>
        </ul>

        <div class="row g-2 mb-3">
            <?php foreach (['CRITICA' => 'Críticos', 'ALTA' => 'Prioridad alta', 'NORMAL' => 'Normales', 'INFORMATIVA' => 'Informativos'] as $codigo => $etiqueta): ?>
                <div class="col-6 col-xl-3"><div class="pending-kpi">
                    <span class="text-muted small"><?php echo msp2Escape($etiqueta); ?></span>
                    <strong class="text-<?php echo msp2Escape($prioridadClases[$codigo][0]); ?>"><?php echo (int) ($resumen[$codigo] ?? 0); ?></strong>
                </div></div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Requiere atención</h2><span class="text-muted"><?php echo count($pendientes); ?> resultado(s)</span>
        </div>

        <?php if ($pendientes === [] && $errorCarga === null): ?>
            <div class="text-center bg-white border rounded-3 p-5"><i class="bi bi-check-circle-fill text-success fs-1"></i><h2 class="h5 mt-3">No hay pendientes para esta vista</h2><p class="text-muted mb-0">Los filtros actuales no muestran tareas que requieran intervención.</p></div>
        <?php endif; ?>

        <div class="d-grid gap-3">
        <?php foreach ($pendientes as $index => $item): ?>
            <?php
            $prioridad = (string) ($item['prioridad'] ?? 'NORMAL');
            $estado = (string) ($item['estado_bandeja'] ?? 'ABIERTO');
            $detalleId = 'detalle-' . $index;
            $gestionId = 'gestion-' . $index;
            $detalles = (array) ($item['detalles'] ?? []);
            $urlAccionPendiente = (string) ($item['url_accion'] ?? '');
            if (($item['modulo_origen'] ?? '') === 'COBRANZA' && $urlAccionPendiente !== '') {
                $returnQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
                $returnPath = 'pendientes/index.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');
                $urlAccionPendiente .= (str_contains($urlAccionPendiente, '?') ? '&' : '?') . 'return_to=' . rawurlencode($returnPath);
            }
            ?>
            <article class="pending-card priority-<?php echo msp2Escape($prioridad); ?> overflow-hidden">
                <div class="p-3 p-lg-4">
                    <div class="d-flex flex-wrap justify-content-between gap-3">
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                <span class="badge text-bg-<?php echo msp2Escape($prioridadClases[$prioridad][0] ?? 'secondary'); ?>"><?php echo msp2Escape($prioridadClases[$prioridad][1] ?? $prioridad); ?></span>
                                <span class="badge text-bg-light border"><?php echo msp2Escape($modulos[(string) ($item['modulo_origen'] ?? '')] ?? (string) ($item['modulo_origen'] ?? 'Otro')); ?></span>
                                <span class="badge text-bg-<?php echo $estado === 'EN_REVISION' ? 'info' : ($estado === 'POSPUESTO' ? 'secondary' : 'light'); ?>"><?php echo msp2Escape(str_replace('_', ' ', $estado)); ?></span>
                            </div>
                            <h3 class="h5 mb-1"><?php echo msp2Escape((string) $item['titulo']); ?></h3>
                            <p class="text-muted mb-3"><?php echo msp2Escape((string) $item['descripcion']); ?></p>
                            <div class="pending-context">
                                <?php if (!empty($item['arrendatario'])): ?><span><i class="bi bi-person me-1"></i><?php echo msp2Escape((string) $item['arrendatario']); ?></span><?php endif; ?>
                                <?php if (!empty($item['contrato'])): ?><span>Contrato <?php echo msp2Escape((string) $item['contrato']); ?></span><?php endif; ?>
                                <?php if (!empty($item['local'])): ?><span>Local <?php echo msp2Escape((string) $item['local']); ?></span><?php endif; ?>
                                <?php if (!empty($item['periodo'])): ?><span>Período <?php echo msp2Escape(substr((string) $item['periodo'], 0, 7)); ?></span><?php endif; ?>
                                <?php if (($item['monto'] ?? null) !== null): ?><span class="fw-semibold"><?php echo msp2Escape(pendientesMonto($item['monto'])); ?></span><?php endif; ?>
                                <?php if (!empty($item['fecha_limite'])): ?><span>Plazo <?php echo msp2Escape(pendientesFecha((string) $item['fecha_limite'])); ?></span><?php endif; ?>
                                <?php if (!empty($item['usuario_asignado'])): ?><span><i class="bi bi-person-check me-1"></i><?php echo msp2Escape((string) $item['usuario_asignado']); ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($item['comentario_interno'])): ?><div class="small text-muted mt-2"><i class="bi bi-chat-left-text me-1"></i><?php echo msp2Escape((string) $item['comentario_interno']); ?></div><?php endif; ?>
                        </div>
                        <div class="d-flex flex-column gap-2" style="min-width: 190px;">
                            <?php if ($urlAccionPendiente !== ''): ?><a class="btn btn-primary" href="<?php echo msp2Escape(pendientesUrlAccion($urlAccionPendiente)); ?>"><?php echo msp2Escape((string) $item['accion_principal']); ?><i class="bi bi-arrow-right ms-1"></i></a><?php endif; ?>
                            <?php if (count($detalles) > 1): ?><button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $detalleId; ?>"><i class="bi bi-list-ul me-1"></i>Ver <?php echo count($detalles); ?> casos</button><?php endif; ?>
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $gestionId; ?>"><i class="bi bi-person-gear me-1"></i>Gestionar tarea</button>
                        </div>
                    </div>
                </div>

                <?php if (count($detalles) > 1): ?><div class="collapse px-4 pb-3" id="<?php echo $detalleId; ?>"><div class="border rounded-3 p-3 bg-light">
                    <?php foreach ($detalles as $detalle): ?><div class="pending-detail-row d-flex flex-wrap justify-content-between gap-2"><div><strong><?php echo msp2Escape((string) ($detalle['arrendatario'] ?? $detalle['tienda'] ?? $detalle['titulo'])); ?></strong><div class="small text-muted"><?php echo msp2Escape(implode(' · ', array_filter(['Contrato ' . ($detalle['contrato'] ?? ''), 'Local ' . ($detalle['local'] ?? '')], static fn (string $v): bool => !str_ends_with($v, ' ')))); ?></div></div><?php if (($detalle['monto'] ?? null) !== null): ?><strong><?php echo msp2Escape(pendientesMonto($detalle['monto'])); ?></strong><?php endif; ?></div><?php endforeach; ?>
                </div></div><?php endif; ?>

                <div class="collapse pending-management p-3 p-lg-4" id="<?php echo $gestionId; ?>">
                    <div class="row g-3">
                        <div class="col-12 col-xl-4">
                            <form method="post" action="accion.php" class="d-grid gap-2">
                                <?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php">
                                <label class="form-label mb-0">Asignar responsable</label>
                                <select class="form-select" name="id_usuario_asignado" required><option value="">Seleccionar usuario</option><?php foreach ($usuarios as $usuario): ?><option value="<?php echo (int) $usuario['id']; ?>" <?php echo (int) ($item['id_usuario_asignado'] ?? 0) === (int) $usuario['id'] ? 'selected' : ''; ?>><?php echo msp2Escape((string) ($usuario['nombre_completo'] ?: $usuario['UserName'])); ?></option><?php endforeach; ?></select>
                                <button class="btn btn-outline-primary" name="accion" value="ASIGNAR">Asignar</button>
                            </form>
                        </div>
                        <div class="col-12 col-xl-4">
                            <form method="post" action="accion.php" class="d-grid gap-2">
                                <?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php">
                                <label class="form-label mb-0">Posponer hasta</label><input type="date" class="form-control" name="pospuesto_hasta" min="<?php echo date('Y-m-d'); ?>" required>
                                <button class="btn btn-outline-secondary" name="accion" value="POSPONER">Posponer</button>
                            </form>
                        </div>
                        <div class="col-12 col-xl-4">
                            <form method="post" action="accion.php" class="d-grid gap-2">
                                <?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php">
                                <label class="form-label mb-0">Comentario interno</label><input class="form-control" name="comentario" maxlength="1000" placeholder="Antecedente o seguimiento" required>
                                <button class="btn btn-outline-secondary" name="accion" value="COMENTAR">Guardar comentario</button>
                            </form>
                        </div>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <form method="post" action="accion.php"><?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php"><button class="btn btn-sm btn-info" name="accion" value="TOMAR_REVISION"><i class="bi bi-eye me-1"></i>Tomar en revisión</button></form>
                            <?php if ($estado === 'POSPUESTO'): ?><form method="post" action="accion.php"><?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php"><button class="btn btn-sm btn-outline-primary" name="accion" value="REABRIR">Reabrir ahora</button></form><?php endif; ?>
                            <?php if (!empty($item['id_usuario_asignado'])): ?><form method="post" action="accion.php"><?php msp2CsrfField(); ?><input type="hidden" name="pendiente_clave" value="<?php echo msp2Escape((string) $item['id']); ?>"><input type="hidden" name="redirect_to" value="pendientes/index.php"><button class="btn btn-sm btn-outline-danger" name="accion" value="LIBERAR_ASIGNACION">Liberar asignación</button></form><?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary" href="historial.php?clave=<?php echo rawurlencode((string) $item['id']); ?>"><i class="bi bi-clock-history me-1"></i>Ver historial</a>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
