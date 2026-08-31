<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

$q = msp2NormalizeText((string) ($_GET['q'] ?? ''));
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
        $stmt = $conn->prepare(
            "SELECT TOP (100)
                    MIN(id_garantia) AS id_garantia,id_contrato_arriendo,nombre_locatario,rut,nombre_comercial,
                    STRING_AGG(cdo_local,N' / ') WITHIN GROUP (ORDER BY cdo_local) AS cdo_local,
                    SUM(monto_pactado) AS monto_pactado,SUM(monto_recibido) AS monto_recibido,
                    SUM(monto_aplicado) AS monto_aplicado,SUM(monto_devuelto) AS monto_devuelto,
                    SUM(monto_disponible) AS monto_disponible,MAX(alerta_nivel) AS alerta_nivel,
                    CASE WHEN MAX(alerta_nivel)=0 THEN N'OK' ELSE N'REVISAR' END AS alerta_codigo
             FROM dbo.msp_vw_garantias_control_integral
             WHERE nombre_locatario LIKE :q_nombre
                OR rut LIKE :q_rut
                OR nombre_comercial LIKE :q_tienda
                OR cdo_local LIKE :q_local
                OR desc_local LIKE :q_desc
                OR CAST(id_contrato_arriendo AS NVARCHAR(20)) LIKE :q_contrato
                OR CAST(id_garantia AS NVARCHAR(20)) LIKE :q_garantia
             GROUP BY id_contrato_arriendo,nombre_locatario,rut,nombre_comercial
             ORDER BY MAX(alerta_nivel) DESC,nombre_locatario,nombre_comercial"
        );
        foreach ([':q_nombre', ':q_rut', ':q_tienda', ':q_local', ':q_desc', ':q_contrato', ':q_garantia'] as $param) {
            $stmt->bindValue($param, '%' . $q . '%', PDO::PARAM_STR);
        }
        $stmt->execute();
        $resultados = $stmt->fetchAll() ?: [];
        $normalizarBusqueda = static function (string $valor): string {
            $valor = mb_strtolower(trim($valor), 'UTF-8');
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            return preg_replace('/\s+/', ' ', is_string($ascii) ? strtolower($ascii) : $valor) ?? $valor;
        };
        $consultaNormalizada = $normalizarBusqueda($q);
        $relevancia = static function (array $fila) use ($normalizarBusqueda, $consultaNormalizada): int {
            $camposPrioritarios = [
                $normalizarBusqueda((string)($fila['nombre_comercial'] ?? '')),
                $normalizarBusqueda((string)($fila['nombre_locatario'] ?? '')),
            ];
            foreach ($camposPrioritarios as $campo) if ($campo === $consultaNormalizada) return 0;
            foreach ($camposPrioritarios as $campo) if (str_starts_with($campo, $consultaNormalizada)) return 1;
            foreach ($camposPrioritarios as $campo) {
                foreach (preg_split('/\s+/', $campo) ?: [] as $palabra) if (str_starts_with($palabra, $consultaNormalizada)) return 2;
            }
            foreach ($camposPrioritarios as $campo) if (str_contains($campo, $consultaNormalizada)) return 3;
            return 4;
        };
        usort($resultados, static function (array $a, array $b) use ($relevancia): int {
            $comparacion = $relevancia($a) <=> $relevancia($b);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .garantia-option-card { transition: transform .15s ease, box-shadow .15s ease; }
        .garantia-option-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(15, 23, 42, .12) !important; }
        .garantia-option-icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 12px; font-size: 1.45rem; }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Garantías</h1>
        </div>
        <div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('contabilidad/submayor_garantias.php')); ?>"><i class="bi bi-journal-check me-1"></i>Submayor contable</a><a class="btn btn-outline-danger btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/reversas.php')); ?>"><i class="bi bi-arrow-counterclockwise me-1"></i>Reversas</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url()); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a MSP</a></div>
    </div>

    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3"><i class="bi bi-search me-1"></i>Buscar garantías</h2>
            <form method="get" class="row g-2">
                <div class="col-lg-10"><input class="form-control" type="search" name="q" value="<?php echo msp2Escape($q); ?>" placeholder="Escribe cualquier parte: arrendatario, RUT, contrato, garantía, tienda o local" autofocus></div>
                <div class="col-lg-2 d-grid"><button class="btn btn-dark">Buscar</button></div>
            </form>
            
        </div>
    </div>

    <?php if ($q !== ''): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">Resultados para “<?php echo msp2Escape($q); ?>” (<?php echo count($resultados); ?>)</div>

            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Arrendatario</th><th>Locales</th><th class="text-end">Pactado</th><th class="text-end">Recibido</th><th class="text-end">Aplicado</th><th class="text-end">Devuelto</th><th class="text-end">Disponible</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php if ($resultados === []): ?><tr><td colspan="9" class="text-center text-muted py-4">No se encontraron garantías.</td></tr><?php endif; ?>
                <?php foreach ($resultados as $row): $pendienteRecepcion=max(0,(float)$row['monto_pactado']-(float)$row['monto_recibido']); ?>
                    <tr>
                        <td><div class="fw-semibold"><?php echo msp2Escape((string) $row['nombre_locatario']); ?></div><div class="small text-muted"><?php echo msp2Escape((string) $row['rut']); ?></div></td>
                        <td class="fw-semibold"><?php echo msp2Escape((string) $row['cdo_local']); ?></td>
                        <td class="text-end"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_pactado'])); ?></td>
                        <td class="text-end"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_recibido'])); ?></td>
                        <td class="text-end text-warning-emphasis"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_aplicado'])); ?></td>
                        <td class="text-end text-danger"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_devuelto'])); ?></td>
                        <td class="text-end fw-semibold"><?php echo msp2Escape(msp2GarantiasHubMonto($row['monto_disponible'])); ?></td>
                        <td><span class="badge text-bg-<?php echo ($row['alerta_codigo'] ?? '') === 'OK' ? 'success' : 'warning'; ?>"><?php echo msp2Escape(str_replace('_', ' ', (string) $row['alerta_codigo'])); ?></span></td>
                        <td><div class="d-flex flex-wrap gap-1"><?php if($pendienteRecepcion>0 || (float)$row['monto_pactado']<=0): ?><a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/recepciones.php?id_contrato_arriendo='.(int)$row['id_contrato_arriendo'])); ?>">Recibir</a><?php else: ?><span class="badge text-bg-success align-self-center">Garantía completa</span><?php endif; ?><a class="btn btn-outline-danger btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php')); ?>">Devolver</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/ficha.php?id=' . (int) $row['id_garantia'])); ?>">Historial</a></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-3">
            <div class="card garantia-option-card border-0 shadow-sm h-100"><div class="card-body d-flex flex-column">
                <div class="garantia-option-icon bg-success-subtle text-success mb-3"><i class="bi bi-shield-plus"></i></div>
                <h2 class="h5">Recepción de garantías</h2>
                <p class="text-muted flex-grow-1">Registrar ingresos por efectivo, transferencia o cheque y adjuntar comprobantes.</p>
                <a class="btn btn-success" href="<?php echo msp2Escape(msp2Url('garantias/recepciones.php')); ?>">Ingresar a recepción</a>
            </div></div>
        </div>
        <div class="col-lg-3">
            <div class="card garantia-option-card border-0 shadow-sm h-100"><div class="card-body d-flex flex-column">
                <div class="garantia-option-icon bg-primary-subtle text-primary mb-3"><i class="bi bi-bank"></i></div>
                <h2 class="h5">Control diario de tesorería</h2>
                <p class="text-muted flex-grow-1">Revisar caja y bancos, movimientos diarios y depósitos de efectivo.</p>
                <div class="d-grid gap-2"><a class="btn btn-primary" href="<?php echo msp2Escape(msp2Url('tesoreria/control_diario.php')); ?>">Ingresar a tesorería</a><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('tesoreria/conciliacion.php')); ?>">Conciliación y cierre</a></div>
            </div></div>
        </div>
        <div class="col-lg-3">
            <div class="card garantia-option-card border-0 shadow-sm h-100"><div class="card-body d-flex flex-column">
                <div class="garantia-option-icon bg-danger-subtle text-danger mb-3"><i class="bi bi-arrow-return-left"></i></div>
                <h2 class="h5">Devolución de garantías</h2>
                <p class="text-muted flex-grow-1">Emitir devoluciones por transferencia o cheque y consultar su historial.</p>
                <a class="btn btn-danger" href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php')); ?>">Ingresar a devoluciones</a>
            </div></div>
        </div>
        <div class="col-lg-3">
            <div class="card garantia-option-card border-0 shadow-sm h-100"><div class="card-body d-flex flex-column">
                <div class="garantia-option-icon bg-warning-subtle text-warning-emphasis mb-3"><i class="bi bi-shield-check"></i></div>
                <h2 class="h5">Aplicación contra cargos</h2>
                <p class="text-muted flex-grow-1">Reservar o aplicar parte de una garantía recibida sobre cargos pendientes del mismo contrato y local.</p>
                <a class="btn btn-warning" href="<?php echo msp2Escape(msp2Url('garantias/aplicaciones.php')); ?>">Gestionar aplicaciones</a>
            </div></div>
        </div>
    </div>


</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>





