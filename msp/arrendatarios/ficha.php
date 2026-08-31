<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/Ficha360Service.php';

msp2RequireAccess();

$idArrendatario = filter_input(INPUT_GET, 'id_arrendatario', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ficha = null;
$error = null;
if ($idArrendatario === false || $idArrendatario === null) {
    $error = 'Debes indicar un arrendatario válido.';
} else {
    try {
        $ficha = (new Ficha360Service($conn))->obtener((int) $idArrendatario);
    } catch (Throwable $e) {
        $error = $e->getMessage() ?: 'No fue posible cargar la Ficha 360°.';
    }
}

function f360Monto(mixed $valor): string
{
    return '$ ' . number_format((float) $valor, 0, ',', '.');
}

function f360Fecha(mixed $valor): string
{
    $raw = trim((string) $valor);
    if ($raw === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable(substr($raw, 0, 10)))->format('d-m-Y');
    } catch (Throwable) {
        return $raw;
    }
}

function f360Periodo(mixed $valor): string
{
    $raw = trim((string) $valor);
    return strlen($raw) >= 7 ? substr($raw, 5, 2) . '-' . substr($raw, 0, 4) : '-';
}

function f360Estado(int $estado): array
{
    return match ($estado) {
        1 => ['Borrador', 'secondary'],
        2 => ['Vigente', 'success'],
        3 => ['En proceso de cierre', 'warning'],
        4 => ['Terminado', 'dark'],
        5 => ['Anulado', 'danger'],
        default => ['Sin estado', 'secondary'],
    };
}

$arr = $ficha['arrendatario'] ?? [];
$totales = $ficha['totales'] ?? [];
$contratos = $ficha['contratos'] ?? [];
$actividad = $ficha['actividad'] ?? ['pagos' => [], 'gestiones' => [], 'compromisos' => [], 'correcciones' => [], 'historial' => []];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ficha 360° | MSP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .f360-metric{border:1px solid #dce3ec;border-radius:.75rem;background:#fff;height:100%;padding:1rem}
        .f360-contract{border:1px solid #b8c2cf;border-left:5px solid #164b7d}
        .f360-local{display:inline-flex;align-items:center;padding:.3rem .65rem;border:1px solid #9eabb9;border-radius:999px;background:#f7f9fb;font-weight:600}
        .f360-section-title{font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:#5d6b7a;font-weight:700}
        .f360-money{white-space:nowrap}
        .f360-anchor{scroll-margin-top:1rem}
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <p class="text-muted mb-1">MSP / Arrendatarios</p>
            <h1 class="h2 mb-1">Ficha 360°</h1>
            <p class="text-muted mb-0">Situación contractual, operacional y financiera consolidada.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-dark btn-sm" href="<?php echo msp2Escape(msp2Url('arrendatarios/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver</a>
            <?php if ($ficha !== null): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php?id_arrendatario=' . (int) $idArrendatario)); ?>"><i class="bi bi-receipt me-1"></i>Documentos</a>
                <a class="btn btn-success btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/index.php?abrirNuevo=1&idArrendatario=' . (int) $idArrendatario)); ?>"><i class="bi bi-plus-circle me-1"></i>Nuevo contrato</a>
                <form method="post" action="<?php echo msp2Escape(msp2Url('arrendatarios/eliminar.php')); ?>" class="d-inline" data-confirm-message="¿Eliminar el arrendatario &quot;<?php echo msp2Escape((string) ($arr['nombre_locatario'] ?? '')); ?>&quot;? Esta acción solo procederá si no tiene tiendas ni dependencias." data-confirm-title="Confirmar eliminación" data-confirm-variant="danger">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="id_arrendatario" value="<?php echo (int) $idArrendatario; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Eliminar arrendatario</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
    <?php else: ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-lg-5">
                        <div class="f360-section-title">Arrendatario</div>
                        <h2 class="h4 mb-1"><?php echo msp2Escape((string) ($arr['nombre_locatario'] ?? '-')); ?></h2>
                        <div class="text-muted">RUT <?php echo msp2Escape((string) ($arr['rut'] ?? '-')); ?> · <?php echo msp2Escape((string) ($arr['desc_estado'] ?? 'Sin estado')); ?></div>
                        <?php if (trim((string) ($arr['nombre_representante'] ?? '')) !== ''): ?><div class="small mt-2"><strong>Representante:</strong> <?php echo msp2Escape((string) $arr['nombre_representante']); ?></div><?php endif; ?>
                        <div class="small"><strong>Dirección:</strong> <?php echo msp2Escape(trim((string) ($arr['direccion'] ?? '')) ?: '-'); ?><?php echo !empty($arr['desc_comuna']) ? ', ' . msp2Escape((string) $arr['desc_comuna']) : ''; ?></div>
                    </div>
                    <div class="col-lg-4">
                        <div class="f360-section-title">Contacto</div>
                        <?php foreach (($arr['correos'] ?? []) as $contacto): ?><div><i class="bi bi-envelope me-1"></i><?php echo msp2Escape((string) $contacto['valor']); ?><?php if ((int) $contacto['es_principal'] === 1): ?> <span class="badge text-bg-primary">Principal</span><?php endif; ?></div><?php endforeach; ?>
                        <?php foreach (($arr['telefonos'] ?? []) as $contacto): ?><div><i class="bi bi-telephone me-1"></i><?php echo msp2Escape((string) $contacto['valor']); ?><?php if ((int) $contacto['es_principal'] === 1): ?> <span class="badge text-bg-secondary">Principal</span><?php endif; ?></div><?php endforeach; ?>
                        <?php if (($arr['correos'] ?? []) === [] && ($arr['telefonos'] ?? []) === []): ?><span class="text-muted">Sin contactos registrados.</span><?php endif; ?>
                    </div>
                    <div class="col-lg-3">
                        <div class="f360-section-title">Navegación rápida</div>
                        <div class="d-grid gap-2 mt-1">
                            <a class="btn btn-outline-primary btn-sm" href="#contratos">Contratos y locales</a>
                            <a class="btn btn-outline-primary btn-sm" href="#finanzas">Resumen financiero</a>
                            <a class="btn btn-outline-primary btn-sm" href="#operacion">Medidores y lecturas</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section id="finanzas" class="f360-anchor mb-4">
            <div class="row g-3">
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Contratos</div><div class="h4 mb-0"><?php echo (int) ($totales['contratos'] ?? 0); ?></div><div class="small text-muted"><?php echo (int) ($totales['vigentes'] ?? 0); ?> vigentes</div></div></div>
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Locales asociados</div><div class="h4 mb-0"><?php echo (int) ($totales['locales'] ?? 0); ?></div></div></div>
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Deuda total</div><div class="h4 mb-0 f360-money text-danger"><?php echo msp2Escape(f360Monto($totales['deuda_total'] ?? 0)); ?></div><div class="small text-muted"><?php echo (int) ($totales['documentos_pendientes'] ?? 0); ?> documentos</div></div></div>
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Deuda vencida</div><div class="h4 mb-0 f360-money"><?php echo msp2Escape(f360Monto($totales['deuda_vencida'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Garantía disponible</div><div class="h4 mb-0 f360-money text-success"><?php echo msp2Escape(f360Monto($totales['garantia_disponible'] ?? 0)); ?></div><div class="small text-muted">Recibida <?php echo msp2Escape(f360Monto($totales['garantia_recibida'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-xl-2"><div class="f360-metric"><div class="text-muted small">Saldo a favor</div><div class="h4 mb-0 f360-money text-primary"><?php echo msp2Escape(f360Monto($totales['saldo_favor'] ?? 0)); ?></div></div></div>
            </div>
        </section>

        <section id="actividad" class="f360-anchor mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h4 mb-0">Actividad transversal</h2><span class="text-muted small">Últimos movimientos del arrendatario y sus contratos.</span></div>
            <div class="row g-3">
                <div class="col-xl-4"><div class="card shadow-sm h-100"><div class="card-header bg-white fw-semibold">Últimos pagos</div><div class="card-body p-0"><div class="list-group list-group-flush">
                    <?php if (($actividad['pagos'] ?? []) === []): ?><div class="list-group-item text-muted">Sin pagos registrados.</div><?php endif; ?>
                    <?php foreach (array_slice($actividad['pagos'] ?? [], 0, 6) as $pago): ?><div class="list-group-item"><div class="d-flex justify-content-between gap-2"><span><?php echo msp2Escape((string) ($pago['titulo'] ?? 'Pago')); ?></span><strong><?php echo msp2Escape(f360Monto($pago['monto'] ?? 0)); ?></strong></div><div class="small text-muted">Contrato #<?php echo (int) ($pago['id_contrato_arriendo'] ?? 0); ?> · <?php echo msp2Escape(f360Fecha($pago['fecha_evento'] ?? null)); ?></div></div><?php endforeach; ?>
                </div></div></div></div>
                <div class="col-xl-4"><div class="card shadow-sm h-100"><div class="card-header bg-white fw-semibold">Cobranza y compromisos</div><div class="card-body p-0"><div class="list-group list-group-flush">
                    <?php if (($actividad['compromisos'] ?? []) === [] && ($actividad['gestiones'] ?? []) === []): ?><div class="list-group-item text-muted">Sin gestiones ni compromisos registrados.</div><?php endif; ?>
                    <?php foreach (array_slice($actividad['compromisos'] ?? [], 0, 4) as $compromiso): ?><div class="list-group-item"><div class="d-flex justify-content-between"><span>Compromiso #<?php echo (int) $compromiso['id_compromiso_pago']; ?></span><span class="badge text-bg-<?php echo ($compromiso['estado'] ?? '') === 'INCUMPLIDO' ? 'danger' : 'secondary'; ?>"><?php echo msp2Escape((string) $compromiso['estado']); ?></span></div><div class="small">$ <?php echo msp2Escape(number_format((float) ($compromiso['monto_comprometido'] ?? 0), 0, ',', '.')); ?> · vence <?php echo msp2Escape(f360Fecha($compromiso['fecha_comprometida'] ?? null)); ?></div><div class="small text-muted">Contrato #<?php echo (int) ($compromiso['id_contrato_arriendo'] ?? 0); ?></div></div><?php endforeach; ?>
                    <?php foreach (array_slice($actividad['gestiones'] ?? [], 0, 3) as $gestion): ?><div class="list-group-item"><div class="fw-semibold"><?php echo msp2Escape((string) ($gestion['tipo_nombre'] ?? 'Gestión')); ?></div><div class="small text-muted">Contrato #<?php echo (int) ($gestion['id_contrato_arriendo'] ?? 0); ?> · <?php echo msp2Escape(f360Fecha($gestion['fecha_gestion'] ?? null)); ?></div></div><?php endforeach; ?>
                </div></div></div></div>
                <div class="col-xl-4"><div class="card shadow-sm h-100"><div class="card-header bg-white fw-semibold">Correcciones e historial</div><div class="card-body p-0"><div class="list-group list-group-flush">
                    <?php if (($actividad['correcciones'] ?? []) === [] && ($actividad['historial'] ?? []) === []): ?><div class="list-group-item text-muted">Sin correcciones ni eventos contractuales.</div><?php endif; ?>
                    <?php foreach (array_slice($actividad['correcciones'] ?? [], 0, 4) as $correccion): ?><div class="list-group-item"><div class="d-flex justify-content-between"><span><?php echo msp2Escape((string) $correccion['tipo_correccion']); ?></span><span class="badge text-bg-warning text-dark"><?php echo msp2Escape((string) $correccion['estado_correccion']); ?></span></div><div class="small text-muted">Contrato #<?php echo (int) ($correccion['id_contrato_arriendo'] ?? 0); ?> · <?php echo msp2Escape(f360Fecha($correccion['fecha_solicitud'] ?? null)); ?></div><a class="small" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?id_contrato_arriendo=' . (int) ($correccion['id_contrato_arriendo'] ?? 0) . '&id_correccion=' . (int) $correccion['id_correccion'])); ?>">Abrir corrección</a></div><?php endforeach; ?>
                    <?php foreach (array_slice($actividad['historial'] ?? [], 0, 4) as $evento): ?>
                        <?php
                        $tipoHistorial = strtoupper(trim((string) ($evento['tipo_evento'] ?? '')));
                        $detalleHistorial = trim((string) ($evento['detalle'] ?? ''));
                        if ($tipoHistorial === 'CREACION' || $tipoHistorial === 'CREACIÓN') {
                            $tipoHistorial = 'CREACIÓN';
                            // La ruta técnica de importación no aporta contexto al usuario final.
                            $detalleHistorial = 'Contrato creado';
                        }
                        ?>
                        <div class="list-group-item"><div class="fw-semibold"><?php echo msp2Escape($tipoHistorial !== '' ? $tipoHistorial : 'Evento'); ?></div><div class="small text-muted">Contrato #<?php echo (int) ($evento['id_contrato_arriendo'] ?? 0); ?> · <?php echo msp2Escape(f360Fecha($evento['fecha_evento'] ?? null)); ?></div><?php if ($detalleHistorial !== ''): ?><div class="small"><?php echo msp2Escape($detalleHistorial); ?></div><?php endif; ?></div>
                    <?php endforeach; ?>
                </div></div></div></div>
            </div>
        </section>

        <section id="contratos" class="f360-anchor">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h4 mb-0">Contratos</h2><span class="text-muted small">Cada tarjeta conserva el contexto de su contrato.</span></div>
            <?php if ($contratos === []): ?><div class="alert alert-info">Este arrendatario todavía no tiene contratos.</div><?php endif; ?>
            <?php foreach ($contratos as $indiceContrato => $detalle):
                $contrato = $detalle['contrato'] ?? [];
                $idContrato = (int) ($contrato['id_contrato_arriendo'] ?? 0);
                [$estadoLabel, $estadoColor] = f360Estado((int) ($contrato['estado_contrato'] ?? 0));
                $resumen = $detalle['resumen'] ?? [];
                $garantia = $detalle['garantia_totales'] ?? [];
                $documentos = array_slice(array_reverse($detalle['documentos'] ?? []), 0, 3);
            ?>
                <article class="card shadow-sm f360-contract mb-3">
                    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h5 mb-0">Contrato #<?php echo $idContrato; ?></h3><span class="badge text-bg-<?php echo $estadoColor; ?>"><?php echo msp2Escape($estadoLabel); ?></span></div>
                            <div class="small text-muted"><?php echo msp2Escape((string) ($contrato['nombre_comercial'] ?? '-')); ?> · <?php echo msp2Escape(f360Fecha($contrato['fecha_inicio'] ?? null)); ?> a <?php echo msp2Escape(f360Fecha($contrato['fecha_termino_efectiva'] ?? $contrato['fecha_termino_pactada'] ?? null)); ?></div>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <a class="btn btn-primary btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContrato)); ?>">Ver ficha contrato</a>
                            <a class="btn btn-outline-danger btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/gestionar.php?id_contrato=' . $idContrato)); ?>">Gestionar cobranza</a>
                            <a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/registrar_pago_contrato.php?id_contrato_arriendo=' . $idContrato . '&id_arrendatario=' . (int) ($contrato['id_arrendatario'] ?? $idArrendatario) . '&contexto_contrato=1')); ?>">Registrar pago</a>
                            <a class="btn btn-outline-warning btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/aplicaciones.php?id_contrato_arriendo=' . $idContrato)); ?>">Garantía</a>
                            <a class="btn btn-outline-dark btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/liquidacion_final.php?id_contrato_arriendo=' . $idContrato)); ?>">Liquidación</a>
                            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?id_contrato_arriendo=' . $idContrato)); ?>">Correcciones</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($detalle['error'])): ?><div class="alert alert-warning">No fue posible agregar todo el detalle: <?php echo msp2Escape((string) $detalle['error']); ?></div><?php endif; ?>
                        <div class="row g-3 mb-3">
                            <div class="col-lg-4"><div class="f360-section-title">Locales</div><div class="d-flex flex-wrap gap-2 mt-2"><?php foreach (($detalle['locales'] ?? []) as $local): ?><span class="f360-local"><i class="bi bi-shop me-1"></i><?php echo msp2Escape((string) $local['cdo_local']); ?></span><?php endforeach; ?><?php if (($detalle['locales'] ?? []) === []): ?><span class="text-muted">Sin locales.</span><?php endif; ?></div></div>
                            <div class="col-lg-4"><div class="f360-section-title">Cobranza</div><div class="d-flex justify-content-between"><span>Deuda total</span><strong class="text-danger"><?php echo msp2Escape(f360Monto($resumen['deuda_total'] ?? 0)); ?></strong></div><div class="d-flex justify-content-between"><span>Vencida</span><strong><?php echo msp2Escape(f360Monto($resumen['deuda_vencida'] ?? 0)); ?></strong></div><div class="d-flex justify-content-between"><span>Saldo a favor</span><strong><?php echo msp2Escape(f360Monto($resumen['saldo_favor'] ?? 0)); ?></strong></div></div>
                            <div class="col-lg-4"><div class="f360-section-title">Garantía</div><div class="d-flex justify-content-between"><span>Pactada</span><strong><?php echo msp2Escape(f360Monto($garantia['pactado'] ?? 0)); ?></strong></div><div class="d-flex justify-content-between"><span>Recibida</span><strong><?php echo msp2Escape(f360Monto($garantia['recibido'] ?? 0)); ?></strong></div><div class="d-flex justify-content-between"><span>Disponible</span><strong class="text-success"><?php echo msp2Escape(f360Monto($garantia['disponible'] ?? 0)); ?></strong></div></div>
                        </div>

                        <div class="row g-3">
                            <div class="col-xl-7">
                                <div class="f360-section-title mb-2">Últimos documentos</div>
                                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Documento</th><th>Período</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th></th></tr></thead><tbody>
                                <?php if ($documentos === []): ?><tr><td colspan="5" class="text-muted">Sin documentos emitidos.</td></tr><?php endif; ?>
                                <?php foreach ($documentos as $doc): ?><tr><td><?php echo msp2Escape((string) ($doc['numero_documento'] ?? ('#' . (int) $doc['id_documento_cobro']))); ?></td><td><?php echo msp2Escape(f360Periodo($doc['periodo_facturacion'] ?? null)); ?></td><td class="text-end"><?php echo msp2Escape(f360Monto($doc['monto_total'] ?? 0)); ?></td><td class="text-end fw-semibold"><?php echo msp2Escape(f360Monto($doc['saldo_pendiente'] ?? 0)); ?></td><td><a class="btn btn-outline-primary btn-sm py-0" href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php?filtroDocumento=' . (int) $doc['id_documento_cobro'])); ?>">Ver</a></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                            </div>
                            <div class="col-xl-5 f360-anchor"<?php echo $indiceContrato === 0 ? ' id="operacion"' : ''; ?>>
                                <div class="f360-section-title mb-2">Operación: última lectura por medidor</div>
                                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Local</th><th>Servicio</th><th>Período</th><th class="text-end">Consumo</th></tr></thead><tbody>
                                <?php if (($detalle['operacion'] ?? []) === []): ?><tr><td colspan="4" class="text-muted">Sin medidores o lecturas registradas.</td></tr><?php endif; ?>
                                <?php foreach (($detalle['operacion'] ?? []) as $medidor): ?><tr><td><strong><?php echo msp2Escape((string) $medidor['cdo_local']); ?></strong><div class="small text-muted"><?php echo msp2Escape((string) $medidor['codigo_medidor']); ?></div></td><td><?php echo msp2Escape((string) $medidor['codigo_servicio']); ?></td><td><?php echo msp2Escape(f360Periodo($medidor['periodo_facturacion'] ?? null)); ?></td><td class="text-end"><?php echo $medidor['consumo_informado'] === null ? '-' : msp2Escape(number_format((float) $medidor['consumo_informado'], 2, ',', '.') . ' ' . (string) $medidor['unidad_medida']); ?></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
