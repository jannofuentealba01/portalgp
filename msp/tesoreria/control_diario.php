<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$error = null;
$cuentas = [];
$cajas = [];
$bancos = [];
$movimientos = [];
$depositos = [];
$fecha = trim((string) ($_GET['fecha'] ?? date('Y-m-d')));
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
if (!$date || $date->format('Y-m-d') !== $fecha) {
    $fecha = date('Y-m-d');
}

function tdMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

try {
    foreach (['msp_tesoreria_cuentas', 'msp_tesoreria_movimientos', 'msp_tesoreria_depositos', 'msp_vw_tesoreria_saldos'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta instalar msp/db/patch_tesoreria_depositos.sql.');
        }
    }

    $cuentas = $conn->query(
        "SELECT * FROM dbo.msp_vw_tesoreria_saldos
         WHERE activo=1
         ORDER BY CASE tipo_cuenta WHEN N'CAJA' THEN 0 ELSE 1 END,nombre_cuenta"
    )->fetchAll() ?: [];
    foreach ($cuentas as $cuenta) {
        if (($cuenta['tipo_cuenta'] ?? '') === 'CAJA') {
            $cajas[] = $cuenta;
        } else {
            $bancos[] = $cuenta;
        }
    }

    $statement = $conn->prepare(
        "SELECT m.*,c.nombre_cuenta,c.tipo_cuenta,c.banco
         FROM dbo.msp_tesoreria_movimientos m
         JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=m.id_cuenta_tesoreria
         WHERE m.fecha_movimiento=:fecha
         ORDER BY m.fecha_registro DESC,m.id_movimiento_tesoreria DESC"
    );
    $statement->execute([':fecha' => $fecha]);
    $movimientos = $statement->fetchAll() ?: [];

    $statement = $conn->prepare(
        "SELECT d.*,cc.nombre_cuenta caja,cb.nombre_cuenta cuenta_banco,cb.banco
         FROM dbo.msp_tesoreria_depositos d
         JOIN dbo.msp_tesoreria_cuentas cc ON cc.id_cuenta_tesoreria=d.id_cuenta_caja
         JOIN dbo.msp_tesoreria_cuentas cb ON cb.id_cuenta_tesoreria=d.id_cuenta_banco
         WHERE d.fecha_deposito=:fecha
         ORDER BY d.id_deposito_tesoreria DESC"
    );
    $statement->execute([':fecha' => $fecha]);
    $depositos = $statement->fetchAll() ?: [];
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException($exception, 'msp.tesoreria.control_diario', 'No fue posible cargar tesorería.');
}

$entradaDia = 0.0;
$salidaDia = 0.0;
foreach ($movimientos as $movimiento) {
    if (($movimiento['estado_movimiento'] ?? '') !== 'VIGENTE') {
        continue;
    }
    if (($movimiento['naturaleza'] ?? '') === 'E') {
        $entradaDia += (float) $movimiento['monto'];
    } else {
        $salidaDia += (float) $movimiento['monto'];
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tesorería diaria | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main container-fluid py-3 px-lg-4 td-main">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><p class="text-muted mb-1">MSP / Tesorería</p><h1 class="h3 mb-0">Control diario de caja y bancos</h1></div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a Garantías</a>
            <a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2Url('tesoreria/conciliacion.php')); ?>">Conciliar / cerrar caja</a>
        </div>
    </div>

    <?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string) ($flash['type'] ?? 'info')); ?>"><?php echo msp2Escape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <div class="card td-control-summary mb-3">
        <div class="td-control-summary-inner">
            <form method="get" class="td-date-form"><div class="td-date-field"><label class="form-label">Fecha de control</label><input type="date" name="fecha" class="form-control" value="<?php echo msp2Escape($fecha); ?>"></div><button class="btn btn-primary">Consultar</button></form>
            <div class="td-day-metrics">
                <div class="td-day-metric td-day-metric--in"><small>Entradas del día</small><strong class="text-success"><?php echo msp2Escape(tdMonto($entradaDia)); ?></strong></div>
                <div class="td-day-metric td-day-metric--out"><small>Salidas del día</small><strong class="text-danger"><?php echo msp2Escape(tdMonto($salidaDia)); ?></strong></div>
                <div class="td-day-metric td-day-metric--net"><small>Flujo neto del día</small><strong><?php echo msp2Escape(tdMonto($entradaDia - $salidaDia)); ?></strong></div>
            </div>
        </div>
    </div>

    <section class="mb-3" aria-labelledby="td-saldos-title">
        <h2 class="h6 mb-2" id="td-saldos-title">Saldos actuales</h2>
        <div class="td-account-strip">
            <?php if ($cuentas === []): ?><div class="text-muted small">No hay cuentas activas configuradas.</div><?php endif; ?>
            <?php foreach ($cuentas as $cuenta): ?>
                <article class="td-account <?php echo $cuenta['tipo_cuenta'] === 'CAJA' ? 'td-account--cash' : ''; ?>">
                    <div class="td-account-head"><div class="td-account-name"><?php echo msp2Escape((string) $cuenta['nombre_cuenta']); ?></div><span class="badge text-bg-<?php echo $cuenta['tipo_cuenta'] === 'CAJA' ? 'warning' : 'primary'; ?>"><?php echo msp2Escape((string) $cuenta['tipo_cuenta']); ?></span></div>
                    <div class="td-account-meta"><?php echo msp2Escape(trim((string) ($cuenta['banco'] ?? '') . ' ' . (string) ($cuenta['numero_cuenta'] ?? ''))); ?></div>
                    <div class="td-account-balance"><?php echo msp2Escape(tdMonto($cuenta['saldo_actual'])); ?></div>
                    <div class="td-account-meta">Movimiento: <?php echo msp2Escape(substr((string) ($cuenta['ultima_fecha_movimiento'] ?? '-'), 0, 10)); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="row g-3 align-items-start">
        <div class="col-xl-7">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">Movimientos del <?php echo msp2Escape((new DateTimeImmutable($fecha))->format('d-m-Y')); ?></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards td-movements-table">
                        <thead class="table-light"><tr><th>Cuenta</th><th>Operación / medio</th><th>Referencia</th><th class="text-end">Movimiento</th><th>Estado</th></tr></thead>
                        <tbody>
                        <?php if ($movimientos === []): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin movimientos para esta fecha.</td></tr><?php endif; ?>
                        <?php foreach ($movimientos as $movimiento): ?>
                            <tr>
                                <td><div class="fw-semibold"><?php echo msp2Escape((string) $movimiento['nombre_cuenta']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) ($movimiento['tipo_cuenta'] ?? '')); ?></div></td>
                                <td><div><?php echo msp2Escape(str_replace('_', ' ', (string) $movimiento['tipo_movimiento'])); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $movimiento['medio_pago']); ?></div></td>
                                <td><?php echo msp2Escape((string) ($movimiento['referencia'] ?? '-')); ?></td>
                                <td class="text-end fw-semibold <?php echo $movimiento['naturaleza'] === 'E' ? 'text-success' : 'text-danger'; ?>"><?php echo $movimiento['naturaleza'] === 'E' ? '+' : '-'; ?> <?php echo msp2Escape(tdMonto($movimiento['monto'])); ?></td>
                                <td><span class="badge text-bg-secondary"><?php echo msp2Escape((string) $movimiento['estado_movimiento']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($depositos !== []): ?>
                <details class="card shadow-sm mt-3 td-collapsible">
                    <summary class="card-header fw-semibold">Depósitos del día (<?php echo count($depositos); ?>)</summary>
                    <div class="table-responsive"><table class="table table-sm mb-0 gp-table-compact gp-table-mobile-cards td-deposits-table"><thead><tr><th>Depósito</th><th>Origen / destino</th><th>Referencia</th><th>Monto / estado</th></tr></thead><tbody><?php foreach ($depositos as $deposito): ?><tr><td>#<?php echo (int) $deposito['id_deposito_tesoreria']; ?></td><td><div><?php echo msp2Escape((string) $deposito['caja']); ?></div><div class="small text-muted">a <?php echo msp2Escape(($deposito['banco'] ?? '') . ' · ' . $deposito['cuenta_banco']); ?></div></td><td><?php echo msp2Escape((string) $deposito['referencia_deposito']); ?></td><td><div class="fw-semibold"><?php echo msp2Escape(tdMonto($deposito['monto_deposito'])); ?></div><span class="badge text-bg-secondary"><?php echo msp2Escape((string) $deposito['estado_deposito']); ?></span></td></tr><?php endforeach; ?></tbody></table></div>
                </details>
            <?php endif; ?>
        </div>

        <div class="col-xl-5">
            <div class="card shadow-sm td-deposit-card">
                <div class="card-header fw-semibold">Depositar efectivo en banco</div>
                <div class="card-body">
                    <form method="post" action="<?php echo msp2Escape(msp2Url('tesoreria/registrar_deposito.php')); ?>" class="row g-2">
                        <?php msp2CsrfField(); ?>
                        <div class="col-md-6"><label class="form-label">Caja origen</label><select name="id_cuenta_caja" class="form-select" required><option value="">Seleccionar</option><?php foreach ($cajas as $cuenta): ?><option value="<?php echo (int) $cuenta['id_cuenta_tesoreria']; ?>"><?php echo msp2Escape($cuenta['nombre_cuenta'] . ' · ' . tdMonto($cuenta['saldo_actual'])); ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Banco destino</label><select name="id_cuenta_banco" class="form-select" required><option value="">Seleccionar</option><?php foreach ($bancos as $cuenta): ?><option value="<?php echo (int) $cuenta['id_cuenta_tesoreria']; ?>"><?php echo msp2Escape(($cuenta['banco'] ?? '') . ' · ' . $cuenta['nombre_cuenta']); ?></option><?php endforeach; ?></select><?php if ($bancos === []): ?><div class="form-text text-danger">No hay cuentas bancarias configuradas.</div><?php endif; ?></div>
                        <div class="col-md-6"><label class="form-label">Fecha</label><input type="date" name="fecha_deposito" class="form-control" value="<?php echo msp2Escape($fecha); ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Monto</label><input type="number" name="monto_deposito" class="form-control" min="0.01" step="0.01" required></div>
                        <div class="col-12"><label class="form-label">N.º comprobante / referencia</label><input name="referencia_deposito" class="form-control" maxlength="200" required></div>
                        <div class="col-12"><label class="form-label">Observaciones</label><textarea name="observaciones" class="form-control" rows="1" maxlength="500"></textarea></div>
                        <div class="col-12 text-end"><button class="btn btn-success" <?php echo $bancos === [] ? 'disabled' : ''; ?>>Registrar depósito</button></div>
                    </form>
                </div>
            </div>

            <details class="card shadow-sm mt-3 td-collapsible">
                <summary class="card-header fw-semibold">Agregar cuenta bancaria</summary>
                <div class="card-body"><form method="post" action="<?php echo msp2Escape(msp2Url('garantias/guardar_cuenta_banco.php')); ?>" class="row g-2 align-items-end"><?php msp2CsrfField(); ?><div class="col-12"><label class="form-label">Nombre interno</label><input name="nombre_cuenta" class="form-control" maxlength="150" placeholder="Cuenta corriente principal" required></div><div class="col-md-6"><label class="form-label">Banco</label><input name="banco" class="form-control" maxlength="120" required></div><div class="col-md-6"><label class="form-label">Número de cuenta</label><input name="numero_cuenta" class="form-control" maxlength="80" required></div><div class="col-12 text-end"><button class="btn btn-outline-primary">Guardar cuenta</button></div></form></div>
            </details>
        </div>
    </div>
</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
