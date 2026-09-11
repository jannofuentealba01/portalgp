<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$flash = msp2PullFlash();
$rows = [];
$historial = [];
$error = null;

try {
    $rows = $conn->query(
        "SELECT TOP(200) * FROM (
            SELECT r.id_garantia,N'RECEPCION' tipo,r.id_recepcion_garantia id_origen,r.fecha_recepcion fecha,
                   r.monto_recibido monto,a.nombre_locatario,l.cdo_local,N'Garantía recibida' concepto
            FROM dbo.msp_garantia_recepciones r
            JOIN dbo.msp_garantias g ON g.id_garantia=r.id_garantia
            JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
            JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
            JOIN dbo.msp_locales l ON l.id_local=g.id_local
            WHERE r.estado_recepcion=N'CONFIRMADA'
            UNION ALL
            SELECT d.id_garantia,N'DEVOLUCION',d.id_devolucion_garantia,d.fecha_devolucion,
                   d.monto_devolucion,a.nombre_locatario,l.cdo_local,N'Devolución emitida'
            FROM dbo.msp_garantia_devoluciones d
            JOIN dbo.msp_garantias g ON g.id_garantia=d.id_garantia
            JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
            JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
            JOIN dbo.msp_locales l ON l.id_local=g.id_local
            WHERE d.estado_devolucion=N'EMITIDA'
            UNION ALL
            SELECT m.id_garantia,N'APLICACION',m.id_movimiento_garantia,m.fecha_movimiento,
                   m.monto_movimiento,a.nombre_locatario,l.cdo_local,COALESCE(m.categoria_aplicacion,N'Aplicación')
            FROM dbo.msp_movimientos_garantia m
            JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
            JOIN dbo.msp_garantias g ON g.id_garantia=m.id_garantia
            JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
            JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
            JOIN dbo.msp_locales l ON l.id_local=g.id_local
            WHERE t.codigo_movimiento=N'APLICACION_CARGO'
        ) x
        ORDER BY fecha DESC,id_origen DESC"
    )->fetchAll() ?: [];
    $historial = $conn->query(
        'SELECT TOP(100) r.*,a.nombre_locatario,l.cdo_local
         FROM dbo.msp_garantia_reversas r
         JOIN dbo.msp_garantias g ON g.id_garantia=r.id_garantia
         JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
         JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         JOIN dbo.msp_locales l ON l.id_local=g.id_local
         ORDER BY r.fecha_registro DESC'
    )->fetchAll() ?: [];
} catch (Throwable $exception) {
    $error = 'No fue posible cargar las reversas.';
}

function grvMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, ',', '.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reversas de garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div><p class="text-muted mb-1">MSP / Garantías</p><h1 class="h3 mb-1">Reversas controladas</h1><p class="text-muted mb-0">Compensa errores sin borrar el movimiento original.</p></div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a Garantías</a>
    </header>

    <?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string) ($flash['type'] ?? 'info')); ?>"><?php echo msp2Escape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header fw-semibold">Operaciones reversables</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-reversible-table">
                <thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / local</th><th>Operación</th><th>Monto</th><th>Revertir</th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?><tr class="gp-table-empty-row"><td colspan="5" class="gp-table-empty-cell">No existen operaciones reversables.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td data-gp-label="Fecha"><?php echo msp2Escape(substr((string) $row['fecha'], 0, 10)); ?></td>
                        <td data-gp-label="Arrendatario / local"><div class="fw-semibold"><?php echo msp2Escape((string) $row['nombre_locatario']); ?></div><div class="small text-muted">Garantía #<?php echo (int) $row['id_garantia']; ?> · Local <?php echo msp2Escape((string) $row['cdo_local']); ?></div></td>
                        <td data-gp-label="Operación"><div class="fw-semibold"><?php echo msp2Escape((string) $row['tipo']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $row['concepto']); ?></div></td>
                        <td data-gp-label="Monto" class="fw-semibold garantia-importe-principal"><?php echo grvMonto($row['monto']); ?></td>
                        <td data-gp-label="Revertir">
                            <form method="post" action="<?php echo msp2Escape(msp2Url('garantias/revertir.php')); ?>" class="garantia-reversa-form">
                                <?php msp2CsrfField(); ?>
                                <input type="hidden" name="tipo_origen" value="<?php echo msp2Escape((string) $row['tipo']); ?>">
                                <input type="hidden" name="id_origen" value="<?php echo (int) $row['id_origen']; ?>">
                                <input type="hidden" name="id_garantia" value="<?php echo (int) $row['id_garantia']; ?>">
                                <input name="motivo" class="form-control form-control-sm" maxlength="500" placeholder="Motivo obligatorio" aria-label="Motivo de la reversa" required>
                                <button class="btn btn-outline-danger btn-sm" onclick="return confirm('¿Confirmas la reversa?');">Revertir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header fw-semibold">Historial de reversas</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-reversals-history-table">
                <thead class="table-light"><tr><th>Fecha</th><th>Arrendatario / local</th><th>Origen / motivo</th><th>Monto</th></tr></thead>
                <tbody>
                <?php if ($historial === []): ?><tr class="gp-table-empty-row"><td colspan="4" class="gp-table-empty-cell">Sin reversas.</td></tr><?php endif; ?>
                <?php foreach ($historial as $reversa): ?>
                    <tr>
                        <td data-gp-label="Fecha"><?php echo msp2Escape(substr((string) $reversa['fecha_reversa'], 0, 10)); ?></td>
                        <td data-gp-label="Arrendatario / local"><div class="fw-semibold"><?php echo msp2Escape((string) $reversa['nombre_locatario']); ?></div><div class="small text-muted">Local <?php echo msp2Escape((string) $reversa['cdo_local']); ?></div></td>
                        <td data-gp-label="Origen / motivo"><div class="fw-semibold"><?php echo msp2Escape((string) $reversa['tipo_origen'] . ' #' . $reversa['id_origen']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $reversa['motivo']); ?></div></td>
                        <td data-gp-label="Monto" class="fw-semibold garantia-importe-principal"><?php echo grvMonto($reversa['monto_reversa']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php msp2RenderCsrfAutoFieldScript(); ?>
</body>
</html>
