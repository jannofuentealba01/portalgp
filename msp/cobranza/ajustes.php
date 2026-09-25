<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$idTienda = filter_input(INPUT_GET, 'id_tienda', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idArrendatario = filter_input(INPUT_GET, 'id_arrendatario', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idContrato = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idTienda = is_int($idTienda) ? $idTienda : 0;
$idArrendatario = is_int($idArrendatario) ? $idArrendatario : 0;
$idContrato = is_int($idContrato) ? $idContrato : 0;
$contexto = null;
$contextoError = null;

if ($idTienda > 0) {
    try {
        $conditions = ['t.id_tienda = :id_tienda'];
        $params = [':id_tienda' => $idTienda];
        if ($idArrendatario > 0) {
            $conditions[] = 't.id_arrendatario = :id_arrendatario';
            $params[':id_arrendatario'] = $idArrendatario;
        }
        if ($idContrato > 0) {
            $conditions[] = 'ca.id_contrato_arriendo = :id_contrato';
            $params[':id_contrato'] = $idContrato;
        }
        $stmt = $conn->prepare(
            "SELECT TOP 1
                t.id_tienda,
                t.id_arrendatario,
                t.nombre_comercial,
                a.nombre_locatario,
                a.rut,
                ca.id_contrato_arriendo
             FROM dbo.msp_tiendas t
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
             LEFT JOIN dbo.msp_contratos_arriendo ca
               ON ca.id_tienda = t.id_tienda
              AND ca.id_arrendatario = t.id_arrendatario
              AND ca.estado_contrato IN (1,2,3)
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY ca.id_contrato_arriendo DESC"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->execute();
        $contexto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($contexto)) {
            $contextoError = 'La tienda, el arrendatario o el contrato indicado no forman una relación vigente.';
        } else {
            $idArrendatario = (int) $contexto['id_arrendatario'];
            $idContrato = (int) ($contexto['id_contrato_arriendo'] ?? 0);
        }
    } catch (Throwable) {
        $contextoError = 'No fue posible validar el contexto de la tienda seleccionada.';
        $contexto = null;
    }
}

$contextQuery = [];
if (is_array($contexto)) {
    $contextQuery = [
        'id_tienda' => (int) $contexto['id_tienda'],
        'id_arrendatario' => (int) $contexto['id_arrendatario'],
    ];
    if ($idContrato > 0) {
        $contextQuery['id_contrato_arriendo'] = $idContrato;
    }
}
$returnToAjustes = 'cobranza/ajustes.php' . ($contextQuery !== []
    ? '?' . http_build_query($contextQuery, '', '&', PHP_QUERY_RFC3986)
    : '');
$destinationQuery = $contextQuery;
$destinationQuery['return_to'] = $returnToAjustes;
$destinationSuffix = '?' . http_build_query($destinationQuery, '', '&', PHP_QUERY_RFC3986);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ajustes de cobranza | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <header class="msp-hub-header" data-gp-commandbar>
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(is_array($contexto) ? msp2Url('tiendas/index.php') : msp2Url('msp_menu.php')); ?>">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?php echo is_array($contexto) ? 'Volver a tiendas' : 'Volver al menú MSP'; ?>
            </a>
        </div>
        <div>
            <h1>Ajustes de cobranza</h1>
        </div>
        <div></div>
    </header>

    <?php if ($contextoError !== null): ?>
        <div class="alert alert-warning py-2"><?php echo msp2Escape($contextoError); ?> Se muestran los ajustes generales.</div>
    <?php elseif (is_array($contexto)): ?>
        <div class="alert alert-info py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span>
                <strong><?php echo msp2Escape((string) $contexto['nombre_comercial']); ?></strong>
                · <?php echo msp2Escape((string) $contexto['nombre_locatario']); ?>
                · RUT <?php echo msp2Escape(msp2RutFormatDisplay((string) $contexto['rut'])); ?>
            </span>
            <?php if ($idContrato > 0): ?><span>Contrato <strong>#<?php echo $idContrato; ?></strong></span><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="msp-hub-grid">
        <article class="msp-hub-card">
            <div class="msp-hub-icon bg-danger-subtle text-danger"><i class="bi bi-plus-circle" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Cargos adicionales</h2>
                <p>Registra multas, reparaciones y otros conceptos extraordinarios asociados a una tienda.</p>
                <a class="btn btn-danger btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/cargos_extra.php' . $destinationSuffix)); ?>">Gestionar cargos</a>
            </div>
        </article>
        <article class="msp-hub-card">
            <div class="msp-hub-icon bg-success-subtle text-success"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Saldo a favor</h2>
                <p>Administra excedentes, rebajas y aplicaciones disponibles para la tienda.</p>
                <a class="btn btn-success btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/saldo_favor_manual.php' . $destinationSuffix)); ?>">Gestionar saldos</a>
            </div>
        </article>
    </div>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
