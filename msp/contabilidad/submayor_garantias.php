<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$q = msp2NormalizeText((string) ($_GET['q'] ?? ''));
$rows = [];
$error = null;
$params = [];
$where = '1=1';

if ($q !== '') {
    $where = '(nombre_locatario LIKE :q OR cdo_local LIKE :q OR CAST(id_contrato_arriendo AS NVARCHAR(20)) LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

try {
    $statement = $conn->prepare(
        "SELECT * FROM dbo.msp_vw_garantias_submayor_contable
         WHERE $where AND (monto_recibido > 0 OR saldo_pasivo_contable <> 0)
         ORDER BY nombre_locatario, cdo_local"
    );
    $statement->execute($params);
    $rows = $statement->fetchAll() ?: [];
} catch (Throwable $exception) {
    $error = 'No fue posible cargar el submayor.';
}

function sgMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, ',', '.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Submayor de garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <h1 class="h3 mb-0">Submayor de garantías</h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>">Volver a Garantías</a>
    </div>

    <form class="row g-2 mb-3">
        <div class="col-lg-10">
            <input name="q" class="form-control" value="<?php echo msp2Escape($q); ?>" placeholder="Buscar arrendatario, contrato o local">
        </div>
        <div class="col-lg-2 d-grid"><button class="btn btn-primary">Buscar</button></div>
    </form>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo msp2Escape((string) $error); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-subledger-table">
                <thead class="table-light">
                <tr>
                    <th>Arrendatario / local</th>
                    <th>Contrato</th>
                    <th class="text-end">Recibido</th>
                    <th>Egresos</th>
                    <th>Saldos</th>
                    <th>Cuadre</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Sin movimientos.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo msp2Escape((string) $row['nombre_locatario']); ?></div>
                            <div class="small text-muted">Local <?php echo msp2Escape((string) $row['cdo_local']); ?> · Garantía #<?php echo (int) $row['id_garantia']; ?></div>
                        </td>
                        <td>#<?php echo (int) $row['id_contrato_arriendo']; ?></td>
                        <td class="text-end fw-semibold"><?php echo sgMonto($row['monto_recibido']); ?></td>
                        <td>
                            <div class="gp-data-pair"><span>Aplicado</span><strong><?php echo sgMonto($row['monto_aplicado']); ?></strong></div>
                            <div class="gp-data-pair"><span>Devuelto</span><strong><?php echo sgMonto($row['monto_devuelto']); ?></strong></div>
                        </td>
                        <td>
                            <div class="gp-data-pair"><span>Operativo</span><strong><?php echo sgMonto($row['monto_disponible']); ?></strong></div>
                            <div class="gp-data-pair"><span>Pasivo</span><strong><?php echo sgMonto($row['saldo_pasivo_contable']); ?></strong></div>
                        </td>
                        <td>
                            <span class="badge text-bg-<?php echo $row['estado_cuadre'] === 'CUADRADO' ? 'success' : 'danger'; ?>"><?php echo msp2Escape((string) $row['estado_cuadre']); ?></span>
                            <div class="small text-muted mt-1">Diferencia: <?php echo sgMonto($row['diferencia']); ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>
