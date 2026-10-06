<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/devolucion_service.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$error = null;
$garantias = [];
$cuentas = [];
$historial = [];
$integracionDisponible = false;
$cajasRegistro = [];
if (($_GET['nueva'] ?? '') === '1') {
    unset($_SESSION['msp2_devolucion_reintento']);
}
$reintento = $_SESSION['msp2_devolucion_reintento'] ?? [];
if (!is_array($reintento) || (int) ($reintento['id_usuario'] ?? 0) !== (int) $_SESSION['usuario']['id']) {
    $reintento = [];
}
$idSolicitud = (string) ($reintento['id_solicitud'] ?? bin2hex(random_bytes(16)));
$campo = static fn(string $name, string $default = ''): string => msp2Escape((string) ($reintento[$name] ?? $default));
$idGarantiaSeleccionada = filter_input(INPUT_GET, 'id_garantia_tienda', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$idContratoSeleccionado = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;

function gdMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, ',', '.');
}

try {
    $integracionDisponible = msp2DevolucionAdminDisponible($conn);
    $statement = $conn->prepare(
        "SELECT g.id_garantia_tienda,g.id_contrato_arriendo,g.nombre_locatario,g.rut,g.nombre_comercial,g.locales,
                g.monto_pactado,g.monto_recibido,g.monto_reservado,g.monto_aplicado,g.total_devuelto AS monto_devuelto,g.monto_disponible
         FROM dbo.msp_vw_garantias_tienda_resumen g
         WHERE g.estado_garantia<>6 AND ((g.monto_disponible>0 AND g.monto_reservado=0) OR g.id_garantia_tienda=:reintento)
         ORDER BY g.nombre_locatario,g.nombre_comercial,g.locales"
    );
    $statement->execute([':reintento' => (int) ($reintento['id_garantia_tienda'] ?? 0)]);
    $garantias = $statement->fetchAll() ?: [];
    $cuentas = $conn->query(
        "SELECT * FROM dbo.msp_vw_tesoreria_saldos
         WHERE tipo_cuenta IN(N'CAJA',N'BANCO') AND activo=1
         ORDER BY tipo_cuenta DESC,banco,nombre_cuenta"
    )->fetchAll() ?: [];
    $cajasRegistro = array_values(array_filter($cuentas, static fn(array $cuenta): bool => $cuenta['tipo_cuenta'] === 'CAJA'));
    $historial = $conn->query(
        "SELECT TOP(100) d.*,r.nombre_locatario,r.rut,r.nombre_comercial,r.locales,
                tc.nombre_cuenta,tc.banco banco_origen
         FROM dbo.msp_garantia_devoluciones d
         JOIN dbo.msp_vw_garantias_tienda_resumen r ON r.id_garantia_tienda=d.id_garantia_tienda
         JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=d.id_cuenta_tesoreria
         ORDER BY d.fecha_devolucion DESC,d.id_devolucion_garantia DESC"
    )->fetchAll() ?: [];
    if ($idGarantiaSeleccionada <= 0 && $idContratoSeleccionado > 0) {
        foreach ($garantias as $garantia) {
            if ((int) $garantia['id_contrato_arriendo'] === $idContratoSeleccionado) {
                $idGarantiaSeleccionada = (int) $garantia['id_garantia_tienda'];
                break;
            }
        }
    }
    if ($reintento !== []) {
        $idGarantiaSeleccionada = (int) $reintento['id_garantia_tienda'];
    }
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException($exception, 'msp.garantias.devoluciones', 'No fue posible cargar las devoluciones.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Devolución de garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div><p class="text-muted mb-1">MSP / Garantías</p><h1 class="h3 mb-0">Devolución de garantía</h1></div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a Garantías</a>
    </header>

    <?php if (is_array($flash)): ?>
        <div class="alert alert-<?php echo msp2Escape((string) ($flash['type'] ?? 'info')); ?>"><?php echo msp2Escape((string) ($flash['message'] ?? '')); ?></div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>
    <?php if (!$integracionDisponible): ?><div class="alert alert-warning">La integración de devoluciones y caja administrativa aún no está instalada en este ambiente. No se habilitará el envío hasta completar las migraciones.</div><?php endif; ?>
    <?php if ($reintento !== []): ?><div class="alert alert-info">Se conserva la solicitud anterior para reintentar sin duplicar movimientos. Si necesitas una operación distinta, <a href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php?nueva=1')); ?>">inicia una nueva devolución</a>.</div><?php endif; ?>

    <div class="card shadow-sm gd-return-card">
        <div class="card-header fw-semibold">Emitir devolución</div>
        <div class="card-body">
            <form method="post" action="<?php echo msp2Escape(msp2Url('garantias/registrar_devolucion.php')); ?>" class="row g-2" id="formDevolucion" data-reintento="<?php echo $reintento !== [] ? '1' : '0'; ?>">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="id_solicitud" value="<?php echo msp2Escape($idSolicitud); ?>">
                <div class="col-12 col-lg-6"><label class="form-label">Garantía</label><select name="id_garantia_tienda" id="gd_garantia" class="form-select" required><option value="">Seleccionar arrendatario y tienda</option><?php foreach ($garantias as $garantia): ?><option value="<?php echo (int) $garantia['id_garantia_tienda']; ?>" data-max="<?php echo msp2Escape((string) $garantia['monto_disponible']); ?>" data-pactado="<?php echo msp2Escape((string) $garantia['monto_pactado']); ?>" data-recibido="<?php echo msp2Escape((string) $garantia['monto_recibido']); ?>" data-aplicado="<?php echo msp2Escape((string) $garantia['monto_aplicado']); ?>" data-devuelto="<?php echo msp2Escape((string) $garantia['monto_devuelto']); ?>" data-beneficiario="<?php echo msp2Escape((string) $garantia['nombre_locatario']); ?>" data-rut="<?php echo msp2Escape((string) $garantia['rut']); ?>" <?php echo $idGarantiaSeleccionada === (int) $garantia['id_garantia_tienda'] ? 'selected' : ''; ?>><?php echo msp2Escape($garantia['nombre_locatario'] . ' · ' . $garantia['nombre_comercial'] . ' · Contrato #' . $garantia['id_contrato_arriendo'] . ' · Locales ' . $garantia['locales'] . ' · Disponible ' . gdMonto($garantia['monto_disponible'])); ?></option><?php endforeach; ?></select></div>
                <div id="gd_resumen" class="col-12 d-none gp-operation-summary"><div class="row text-center g-2"><?php foreach (['pactado' => 'Pactada', 'recibido' => 'Recibida', 'aplicado' => 'Aplicada a deudas', 'devuelto' => 'Devuelta antes', 'disponible' => 'Disponible', 'posterior' => 'Saldo posterior'] as $id => $label): ?><div class="col-6 col-md"><small><?php echo $label; ?></small><strong class="d-block" id="gd_<?php echo $id; ?>"></strong></div><?php endforeach; ?></div></div>

                <div class="col-6 col-lg-3"><label class="form-label" for="gd_forma">Forma de devolución</label><select name="forma_devolucion" id="gd_forma" class="form-select" required><option value="PARCIAL">Devolución parcial</option><option value="TOTAL" <?php echo ($reintento['forma_devolucion'] ?? '') === 'TOTAL' ? 'selected' : ''; ?>>Todo el saldo disponible</option></select></div>
                <div class="col-6 col-lg-3"><label class="form-label">Fecha</label><input type="date" name="fecha_devolucion" class="form-control" value="<?php echo $campo('fecha_devolucion', date('Y-m-d')); ?>" required></div>
                <div class="col-6 col-lg-3"><label class="form-label">Monto</label><input type="number" name="monto_devolucion" id="gd_monto" class="form-control" min="0.01" step="0.01" value="<?php echo $campo('monto_devolucion'); ?>" required></div>

                <div class="col-12 col-md-2"><label class="form-label">RUT beneficiario</label><input name="rut_beneficiario" id="gd_rut" maxlength="20" class="form-control" value="<?php echo $campo('rut_beneficiario'); ?>"></div>
                <div class="col-12 col-md-4"><label class="form-label">Beneficiario</label><input name="beneficiario" id="gd_beneficiario" maxlength="200" class="form-control" value="<?php echo $campo('beneficiario'); ?>" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="gd_medio">Devolver desde</label><select name="medio_devolucion" id="gd_medio" class="form-select" required><option value="TRANSFERENCIA">Banco · transferencia</option><option value="EFECTIVO" <?php echo ($reintento['medio_devolucion'] ?? '') === 'EFECTIVO' ? 'selected' : ''; ?>>Caja · efectivo</option></select></div>
                <div class="col-6 col-md-4"><label class="form-label" for="gd_cuenta">Cuenta de origen del dinero</label><select name="id_cuenta_tesoreria" id="gd_cuenta" class="form-select" required><option value="">Seleccionar</option><?php foreach ($cuentas as $cuenta): ?><option value="<?php echo (int) $cuenta['id_cuenta_tesoreria']; ?>" data-tipo="<?php echo msp2Escape((string) $cuenta['tipo_cuenta']); ?>" data-moneda="<?php echo msp2Escape((string) $cuenta['moneda']); ?>" <?php echo (int) ($reintento['id_cuenta_tesoreria'] ?? 0) === (int) $cuenta['id_cuenta_tesoreria'] ? 'selected' : ''; ?>><?php echo msp2Escape((($cuenta['banco'] ?? '') !== '' ? $cuenta['banco'] . ' · ' : '') . $cuenta['nombre_cuenta'] . ' · ' . gdMonto($cuenta['saldo_actual'])); ?></option><?php endforeach; ?></select><div class="form-text">Aquí se descuenta el dinero real.</div></div>
                <div class="col-12 transferencia"><label class="form-label" for="gd_caja_admin">Caja de registro administrativo</label><select name="id_cuenta_caja_admin" id="gd_caja_admin" class="form-select"><option value="">Seleccionar caja</option><?php foreach ($cajasRegistro as $caja): ?><option value="<?php echo (int) $caja['id_cuenta_tesoreria']; ?>" data-moneda="<?php echo msp2Escape((string) $caja['moneda']); ?>" <?php echo (int) ($reintento['id_cuenta_caja_admin'] ?? 0) === (int) $caja['id_cuenta_tesoreria'] ? 'selected' : ''; ?>><?php echo msp2Escape((string) $caja['nombre_cuenta']); ?></option><?php endforeach; ?></select><div class="form-text">Se registran una entrada y una salida iguales. Sin movimiento de efectivo ni cambio del saldo de caja.</div></div>
                <div class="col-12 col-md-3 transferencia"><label class="form-label">Banco destino</label><input name="banco_destino" class="form-control" maxlength="120" value="<?php echo $campo('banco_destino'); ?>"></div>
                <div class="col-12 col-md-3 transferencia"><label class="form-label">Cuenta destino</label><input name="cuenta_destino" class="form-control" maxlength="100" value="<?php echo $campo('cuenta_destino'); ?>"></div>
                <div class="col-12 col-md-6 transferencia"><label class="form-label">Referencia transferencia</label><input name="referencia_transferencia" class="form-control" maxlength="200" value="<?php echo $campo('referencia_transferencia'); ?>"></div>
                <div class="col-12 col-lg-8"><label class="form-label">Motivo y autorización</label><input name="motivo_autorizacion" class="form-control" maxlength="500" placeholder="Indica por qué se devuelve y quién autorizó" value="<?php echo $campo('motivo_autorizacion'); ?>" required></div>
                <div class="col-12 col-lg-4"><label class="form-label">Observaciones</label><textarea name="observaciones" class="form-control" rows="1" maxlength="500"><?php echo $campo('observaciones'); ?></textarea></div>
                <div class="col-12 text-end"><button type="submit" class="btn btn-danger btn-sm" id="gd_emitir" <?php echo !$integracionDisponible || $cuentas === [] || $error ? 'disabled' : ''; ?>>Emitir devolución</button></div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header fw-semibold">Historial de devoluciones</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-returns-table">
                <thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / local</th><th>Medio / origen</th><th>Referencia / motivo</th><th>Monto / estado</th></tr></thead>
                <tbody>
                <?php if ($historial === []): ?><tr class="gp-table-empty-row"><td colspan="5" class="gp-table-empty-cell">Sin devoluciones.</td></tr><?php endif; ?>
                <?php foreach ($historial as $devolucion): ?>
                    <tr>
                        <td data-gp-label="Fecha"><?php echo msp2Escape(substr((string) $devolucion['fecha_devolucion'], 0, 10)); ?></td>
                        <td data-gp-label="Arrendatario / tienda"><div class="fw-semibold"><?php echo msp2Escape((string) $devolucion['nombre_locatario']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $devolucion['rut']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $devolucion['nombre_comercial']); ?> · Locales <?php echo msp2Escape((string) $devolucion['locales']); ?></div></td>
                        <td data-gp-label="Medio / origen"><div class="fw-semibold"><?php echo msp2Escape((string) $devolucion['medio_devolucion']); ?></div><div class="small text-muted"><?php echo msp2Escape(trim((string) ($devolucion['banco_origen'] ?? '') . ' · ' . $devolucion['nombre_cuenta'], ' ·')); ?></div></td>
                        <td data-gp-label="Referencia / motivo"><div><?php echo msp2Escape((string) ($devolucion['referencia_transferencia'] ?? '-')); ?></div><div class="small text-muted"><?php echo msp2Escape((string) ($devolucion['motivo_autorizacion'] ?? $devolucion['observaciones'] ?? '-')); ?></div></td>
                        <td data-gp-label="Monto / estado"><div class="fw-semibold garantia-importe-principal"><?php echo gdMonto($devolucion['monto_devolucion']); ?></div><span class="badge text-bg-secondary"><?php echo msp2Escape((string) $devolucion['estado_devolucion']); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
(()=>{
    const garantia=document.getElementById('gd_garantia');
    const monto=document.getElementById('gd_monto');
    const medio=document.getElementById('gd_medio');
    const cuenta=document.getElementById('gd_cuenta');
    const beneficiario=document.getElementById('gd_beneficiario');
    const rut=document.getElementById('gd_rut');
    const resumen=document.getElementById('gd_resumen');
    const money=value=>'$ '+Number(value||0).toLocaleString('es-CL');
    function posterior(){const option=garantia.selectedOptions[0];document.getElementById('gd_posterior').textContent=money(Math.max(0,Number(option?.dataset.max||0)-Number(monto.value||0)));}
    const form=document.getElementById('formDevolucion');
    const forma=document.getElementById('gd_forma');
    const cajaAdmin=document.getElementById('gd_caja_admin');
    let conservarReintento=form.dataset.reintento==='1';
    function sincronizarForma(){monto.readOnly=forma.value==='TOTAL';if(monto.readOnly&&!conservarReintento)monto.value=garantia.selectedOptions[0]?.dataset.max||'';posterior();}
    function seleccionar(){const option=garantia.selectedOptions[0];if(!option?.value){resumen.classList.add('d-none');return;}resumen.classList.remove('d-none');['pactado','recibido','aplicado','devuelto'].forEach(key=>document.getElementById('gd_'+key).textContent=money(option.dataset[key]));document.getElementById('gd_disponible').textContent=money(option.dataset.max);monto.max=conservarReintento?String(Math.max(Number(option.dataset.max),Number(monto.value))):option.dataset.max;if(!conservarReintento){beneficiario.value=option.dataset.beneficiario||'';rut.value=option.dataset.rut||'';}sincronizarForma();conservarReintento=false;}
    function sincronizarMedio(){const transferencia=medio.value==='TRANSFERENCIA';const tipo=transferencia?'BANCO':'CAJA';Array.from(cuenta.options).forEach(option=>{if(!option.value)return;option.hidden=option.dataset.tipo!==tipo;option.disabled=option.hidden;});if(cuenta.selectedOptions[0]?.dataset.tipo!==tipo)cuenta.value='';document.querySelectorAll('.transferencia').forEach(element=>{element.classList.toggle('d-none',!transferencia);element.querySelectorAll('input,select').forEach(input=>{input.required=transferencia;input.disabled=!transferencia;});});const moneda=cuenta.selectedOptions[0]?.dataset.moneda;Array.from(cajaAdmin.options).forEach(option=>{if(!option.value)return;option.disabled=!!moneda&&option.dataset.moneda!==moneda;option.hidden=option.disabled;});if(cajaAdmin.selectedOptions[0]?.disabled)cajaAdmin.value='';const disponibles=Array.from(cajaAdmin.options).filter(option=>option.value&&!option.disabled);if(!cajaAdmin.value&&disponibles.length===1)cajaAdmin.value=disponibles[0].value;}
    garantia.addEventListener('change',seleccionar);
    monto.addEventListener('input',posterior);
    medio.addEventListener('change',sincronizarMedio);
    cuenta.addEventListener('change',sincronizarMedio);
    forma.addEventListener('change',sincronizarForma);
    let enviando=false;
    form.addEventListener('submit',event=>{if(enviando){event.preventDefault();return;}if(!confirm('¿Confirmas la devolución desde '+(medio.value==='TRANSFERENCIA'?'banco, con registro administrativo en caja sin movimiento de efectivo':'caja, con salida real de efectivo')+'?')){event.preventDefault();return;}enviando=true;document.getElementById('gd_emitir').disabled=true;});
    window.addEventListener('pageshow',event=>{if(event.persisted){enviando=false;document.getElementById('gd_emitir').disabled=<?php echo !$integracionDisponible || $cuentas === [] || $error ? 'true' : 'false'; ?>;}});
    sincronizarMedio();
    seleccionar();
})();
</script>
<?php msp2RenderCsrfAutoFieldScript(); ?>
</body>
</html>
