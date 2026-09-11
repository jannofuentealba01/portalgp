<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    msp2Redirect('garantias/index.php');
}

$resumen = null;
$movimientos = [];
$error = null;

try {
    $statement = $conn->prepare('SELECT * FROM dbo.msp_vw_garantias_control_integral WHERE id_garantia=:id');
    $statement->execute([':id' => (int) $id]);
    $resumen = $statement->fetch();
    if (!$resumen) {
        throw new RuntimeException('La garantía no existe.');
    }

    $historyStatement = $conn->prepare(
        'SELECT * FROM dbo.msp_vw_garantia_historial_integral WHERE id_garantia=:id ORDER BY fecha,id_origen'
    );
    $historyStatement->execute([':id' => (int) $id]);
    $movimientos = $historyStatement->fetchAll() ?: [];
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException($exception, 'msp.garantias.ficha', 'No fue posible cargar la ficha.');
}

function gfMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, ',', '.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ficha de garantía | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div>
            <p class="text-muted mb-1">MSP / Garantías</p>
            <h1 class="h3 mb-1">Ficha integral de garantía #<?php echo (int) $id; ?></h1>
            <?php if ($resumen): ?>
                <p class="text-muted mb-0"><?php echo msp2Escape((string) $resumen['nombre_locatario']); ?> · Contrato #<?php echo (int) $resumen['id_contrato_arriendo']; ?> · Local <?php echo msp2Escape((string) $resumen['cdo_local']); ?></p>
            <?php endif; ?>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>">Volver a Garantías</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
    <?php elseif ($resumen): ?>
        <section class="gp-indicator-strip mb-3" aria-label="Saldos de la garantía">
            <?php foreach (['monto_pactado' => 'Pactada', 'monto_recibido' => 'Recibida', 'monto_reservado' => 'Reservada', 'monto_aplicado' => 'Aplicada a deudas', 'monto_devuelto' => 'Devuelta', 'monto_disponible' => 'Disponible'] as $key => $label): ?>
                <div class="gp-indicator">
                    <span class="gp-indicator-label"><?php echo msp2Escape($label); ?></span>
                    <strong class="gp-indicator-value<?php echo $key === 'monto_disponible' ? ' text-success' : ''; ?>"><?php echo gfMonto($resumen[$key]); ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Historial relacionado</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-history-table">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Operación / concepto</th>
                        <th>Medio / cuenta</th>
                        <th>Documento / referencia</th>
                        <th>Estado / monto</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($movimientos === []): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Sin movimientos.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($movimientos as $movimiento): ?>
                        <?php
                        $origen = $movimiento['id_documento']
                            ? 'Documento #' . (int) $movimiento['id_documento']
                            : ($movimiento['id_cargo'] ? 'Cargo #' . (int) $movimiento['id_cargo'] : '-');
                        ?>
                        <tr>
                            <td><?php echo msp2Escape(substr((string) $movimiento['fecha'], 0, 10)); ?></td>
                            <td>
                                <div class="fw-semibold"><?php echo msp2Escape((string) $movimiento['tipo']); ?></div>
                                <div><?php echo msp2Escape((string) $movimiento['concepto']); ?></div>
                                <?php if (trim((string) ($movimiento['observaciones'] ?? '')) !== ''): ?>
                                    <div class="small text-muted"><?php echo msp2Escape((string) $movimiento['observaciones']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo msp2Escape((string) ($movimiento['medio'] ?? '-')); ?></div>
                                <div class="small text-muted"><?php echo msp2Escape((string) ($movimiento['cuenta'] ?? '')); ?></div>
                            </td>
                            <td>
                                <div><?php echo msp2Escape($origen); ?></div>
                                <div class="small text-muted">Ref. <?php echo msp2Escape((string) ($movimiento['referencia'] ?? '-')); ?></div>
                            </td>
                            <td>
                                <span class="badge text-bg-secondary"><?php echo msp2Escape((string) $movimiento['estado']); ?></span>
                                <div class="fw-semibold mt-1 <?php echo $movimiento['signo'] === '+' ? 'text-success' : 'text-danger'; ?>"><?php echo msp2Escape((string) $movimiento['signo']); ?> <?php echo gfMonto($movimiento['monto']); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
