<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$q = msp2SearchQuery($_GET['q'] ?? '');
$resultados = [];
$error = null;
$totales = ['garantias' => 0, 'pactado' => 0.0, 'recibido' => 0.0, 'disponible' => 0.0];

function msp2GarantiasHubMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

try {
    if (!msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        throw new RuntimeException('No está disponible la vista integral de garantías.');
    }

    $totalesRow = $conn->query(
        'SELECT COUNT(DISTINCT id_contrato_arriendo) garantias, ISNULL(SUM(monto_pactado),0) pactado,
                ISNULL(SUM(monto_recibido),0) recibido, ISNULL(SUM(monto_aplicado),0) aplicado, ISNULL(SUM(monto_devuelto),0) devuelto, ISNULL(SUM(monto_disponible),0) disponible
         FROM dbo.msp_vw_garantias_control_integral'
    )->fetch() ?: [];
    $totales = [
        'garantias' => (int) ($totalesRow['garantias'] ?? 0),
        'pactado' => (float) ($totalesRow['pactado'] ?? 0),
        'recibido' => (float) ($totalesRow['recibido'] ?? 0),
        'disponible' => (float) ($totalesRow['disponible'] ?? 0),
    ];

    if ($q !== '') {
        $search = msp2BuildSearchCondition($q, [
            'g.nombre_locatario',
            'g.rut',
            "REPLACE(REPLACE(REPLACE(g.rut,N'.',N''),N'-',N''),N' ',N'')",
            'g.nombre_comercial',
            'g.cdo_local',
            "REPLACE(REPLACE(g.cdo_local,N'-',N''),N'.',N'')",
            'g.desc_local_busqueda',
            'g.id_contrato_arriendo',
            'g.ids_garantia_busqueda',
        ], 'garantias_buscar', "EXISTS(SELECT 1 FROM dbo.msp_vw_garantias_control_integral gx WHERE gx.id_contrato_arriendo=g.id_contrato_arriendo AND gx.id_garantia={{id}})");
        $stmt = $conn->prepare(
            "WITH garantia_consolidada AS (
                SELECT
                    MIN(id_garantia) AS id_garantia,
                    id_contrato_arriendo,
                    nombre_locatario,
                    rut,
                    nombre_comercial,
                    STRING_AGG(CONVERT(NVARCHAR(MAX),cdo_local),N' / ') WITHIN GROUP (ORDER BY cdo_local) AS cdo_local,
                    STRING_AGG(CONVERT(NVARCHAR(MAX),ISNULL(desc_local,N'')),N' ') AS desc_local_busqueda,
                    STRING_AGG(CONVERT(NVARCHAR(MAX),id_garantia),N' ') AS ids_garantia_busqueda,
                    SUM(monto_pactado) AS monto_pactado,
                    SUM(monto_recibido) AS monto_recibido,
                    SUM(monto_aplicado) AS monto_aplicado,
                    SUM(monto_devuelto) AS monto_devuelto,
                    SUM(monto_disponible) AS monto_disponible,
                    MAX(alerta_nivel) AS alerta_nivel,
                    CASE WHEN MAX(alerta_nivel)=0 THEN N'OK' ELSE N'REVISAR' END AS alerta_codigo
                FROM dbo.msp_vw_garantias_control_integral
                GROUP BY id_contrato_arriendo,nombre_locatario,rut,nombre_comercial
            )
            SELECT g.*
            FROM garantia_consolidada g
            WHERE {$search['sql']}
            ORDER BY g.alerta_nivel DESC,g.nombre_locatario,g.nombre_comercial"
        );
        foreach ($search['params'] as $param => $value) {
            $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        $resultados = $stmt->fetchAll() ?: [];
        usort($resultados, static function (array $a, array $b) use ($q): int {
            $comparacion = msp2SearchRelevance($q, [
                (string) ($a['nombre_comercial'] ?? ''),
                (string) ($a['nombre_locatario'] ?? ''),
                (string) ($a['rut'] ?? ''),
                (string) ($a['cdo_local'] ?? ''),
            ]) <=> msp2SearchRelevance($q, [
                (string) ($b['nombre_comercial'] ?? ''),
                (string) ($b['nombre_locatario'] ?? ''),
                (string) ($b['rut'] ?? ''),
                (string) ($b['cdo_local'] ?? ''),
            ]);
            if ($comparacion !== 0) return $comparacion;
            $alerta = (int)($b['alerta_nivel'] ?? 0) <=> (int)($a['alerta_nivel'] ?? 0);
            if ($alerta !== 0) return $alerta;
            return strcasecmp((string)($a['nombre_comercial'] ?? ''), (string)($b['nombre_comercial'] ?? ''));
        });
    }
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'No fue posible cargar el módulo de garantías.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div>
            <h1 class="h3 mb-1">Garantías</h1>
        </div>
        <div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('contabilidad/submayor_garantias.php')); ?>"><i class="bi bi-journal-check me-1"></i>Submayor contable</a><a class="btn btn-outline-danger btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/reversas.php')); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Reversas</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url()); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a MSP</a></div>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3"><i class="bi bi-search me-1"></i>Buscar garantías</h2>
            <form method="get" class="row g-2 gp-filter-bar">
                <div class="col-lg-9"><input class="form-control" type="search" name="q" value="<?php echo msp2Escape($q); ?>" placeholder="Arrendatario, RUT, contrato, garantía, tienda o local; ej.: ivon A-1 o #7" autofocus></div>
                <div class="col-lg-3 d-flex gap-2" data-gp-filter-actions><button class="btn btn-primary flex-grow-1">Buscar</button><?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>">Limpiar</a><?php endif; ?></div>
            </form>
            
        </div>
    </div>

    <?php if ($q !== ''): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">Resultados para “<?php echo msp2Escape($q); ?>” (<?php echo count($resultados); ?>)</div>

            <div class="table-responsive garantias-resultados-wrap"><table class="table table-hover align-middle mb-0 garantias-resultados-table gp-table-compact gp-table-mobile-cards msp-guarantees-hub-table">
                <colgroup>
                    <col style="width:24%"><col style="width:15%"><col style="width:16%"><col style="width:15%"><col style="width:12%"><col style="width:8%"><col style="width:10%">
                </colgroup>
                <thead class="table-light"><tr><th>Arrendatario</th><th>Tienda / locales</th><th>Recepción</th><th>Egresos</th><th class="text-end">Disponible</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php if ($resultados === []): ?><tr class="gp-table-empty-row"><td colspan="7" class="gp-table-empty-cell">No se encontraron garantías.</td></tr><?php endif; ?>
                <?php foreach ($resultados as $row): $pendienteRecepcion=max(0,(float)$row['monto_pactado']-(float)$row['monto_recibido']); ?>
                    <tr>
                        <td data-gp-label="Arrendatario" class="garantia-arrendatario" data-gp-allow-wrap="true"><div class="fw-semibold"><?php echo msp2Escape((string) $row['nombre_locatario']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $row['rut']); ?></div></td>
                        <td data-gp-label="Tienda / locales" data-gp-allow-wrap="true"><div class="fw-semibold"><?php echo msp2Escape((string) $row['nombre_comercial']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $row['cdo_local']); ?></div></td>
                        <td data-gp-label="Recepción"><span class="gp-data-pair"><span>Pactado</span><strong><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_pactado'])); ?></strong></span><span class="gp-data-pair"><span>Recibido</span><strong><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_recibido'])); ?></strong></span></td>
                        <td data-gp-label="Egresos"><span class="gp-data-pair"><span>Aplicado</span><strong class="text-warning-emphasis"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_aplicado'])); ?></strong></span><span class="gp-data-pair"><span>Devuelto</span><strong class="text-danger"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_devuelto'])); ?></strong></span></td>
                        <td data-gp-label="Disponible" class="text-end garantia-disponible"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_disponible'])); ?></td>
                        <td data-gp-label="Estado"><span class="badge text-bg-<?php echo ($row['alerta_codigo'] ?? '') === 'OK' ? 'success' : 'warning'; ?>"><?php echo msp2Escape(str_replace('_', ' ', (string) $row['alerta_codigo'])); ?></span></td>
                        <td data-gp-label="Acciones" class="garantia-acciones gp-cell-actions"><div class="d-flex flex-wrap gap-1"><?php if($pendienteRecepcion>0 || (float)$row['monto_pactado']<=0): ?><a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/recepciones.php?id_contrato_arriendo='.(int)$row['id_contrato_arriendo'])); ?>">Recibir</a><?php else: ?><span class="badge text-bg-success align-self-center">Completa</span><?php endif; ?><a class="btn btn-outline-danger btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php')); ?>">Devolver</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/ficha.php?id=' . (int) $row['id_garantia'])); ?>">Historial</a></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>

    <nav class="gp-functional-surface garantia-options-grid mb-4" aria-label="Operaciones de garantías">
        <div>
            <div class="garantia-option"><div class="garantia-option-body">
                <div class="garantia-option-icon bg-success-subtle text-success mb-2"><i class="bi bi-shield-plus"></i></div>
                <h2 class="h5">Recepción de garantías</h2>
                <p class="text-muted flex-grow-1">Registrar ingresos por efectivo, transferencia o cheque y adjuntar comprobantes.</p>
                <a class="btn btn-success" href="<?php echo msp2Escape(msp2Url('garantias/recepciones.php')); ?>">Ingresar a recepción</a>
            </div></div>
        </div>
        <div>
            <div class="garantia-option"><div class="garantia-option-body">
                <div class="garantia-option-icon bg-primary-subtle text-primary mb-2"><i class="bi bi-bank"></i></div>
                <h2 class="h5">Control diario de tesorería</h2>
                <p class="text-muted flex-grow-1">Revisar caja y bancos, movimientos diarios y depósitos de efectivo.</p>
                <div class="d-grid gap-2"><a class="btn btn-primary" href="<?php echo msp2Escape(msp2Url('tesoreria/control_diario.php')); ?>">Ingresar a tesorería</a><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('tesoreria/conciliacion.php')); ?>">Conciliación y cierre</a></div>
            </div></div>
        </div>
        <div>
            <div class="garantia-option"><div class="garantia-option-body">
                <div class="garantia-option-icon bg-danger-subtle text-danger mb-2"><i class="bi bi-arrow-return-left"></i></div>
                <h2 class="h5">Devolución de garantías</h2>
                <p class="text-muted flex-grow-1">Emitir devoluciones por transferencia, efectivo o cheque y consultar su historial.</p>
                <a class="btn btn-danger" href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php')); ?>">Ingresar a devoluciones</a>
            </div></div>
        </div>
        <div>
            <div class="garantia-option"><div class="garantia-option-body">
                <div class="garantia-option-icon bg-warning-subtle text-warning-emphasis mb-2"><i class="bi bi-shield-check"></i></div>
                <h2 class="h5">Aplicación contra cargos</h2>
                <p class="text-muted flex-grow-1">Reservar o aplicar parte de una garantía recibida sobre cargos pendientes del mismo contrato y local.</p>
                <a class="btn btn-warning" href="<?php echo msp2Escape(msp2Url('garantias/aplicaciones.php')); ?>">Gestionar aplicaciones</a>
            </div></div>
        </div>
        <?php if (msp2CurrentUserHasPermission('MSP Reportes')): ?>
        <div>
            <div class="garantia-option"><div class="garantia-option-body">
                <div class="garantia-option-icon bg-info-subtle text-info-emphasis mb-2"><i class="bi bi-clipboard-data"></i></div>
                <h2 class="h5">Control integral de garantías</h2>
                <p class="text-muted flex-grow-1">Consultar montos pactados, recibidos, disponibles, reservados, aplicados y devueltos, junto con sus alertas.</p>
                <a class="btn btn-primary" href="<?php echo msp2Escape(msp2Url('garantias/reporte.php')); ?>">Ingresar al control</a>
            </div></div>
        </div>
        <?php endif; ?>
    </nav>


</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>





