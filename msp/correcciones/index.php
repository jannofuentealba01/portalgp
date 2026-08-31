<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$idContrato = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idCorreccion = filter_input(INPUT_GET, 'id_correccion', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$resultado = null;
$correccion = null;
$correcciones = [];
$error = null;
$prefillLectura = null;
$prefillPeriodo = (string) ($_GET['periodo_facturacion'] ?? '');
$prefillServicio = strtoupper((string) ($_GET['servicio'] ?? ''));
$prefillRegistro = filter_input(INPUT_GET, 'id_registro_origen', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$prefillLocal = filter_input(INPUT_GET, 'id_local', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$periodosDisponibles = [];
$lecturasDisponibles = [];
$arriendosDisponibles = [];
$cargaOpcionesError = null;
$buscarContrato = trim((string) ($_GET['buscar_contrato'] ?? ''));
$contratosEncontrados = [];
$errorBusquedaContrato = null;

if (($idContrato === false || $idContrato === null) && $buscarContrato !== '') {
    try {
        $like = '%' . $buscarContrato . '%';
        $stmtBuscarContrato = $conn->prepare(
            'SELECT TOP(30) c.id_contrato_arriendo,c.estado_contrato,t.nombre_comercial,
                    a.nombre_locatario,a.rut
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
             WHERE CAST(c.id_contrato_arriendo AS NVARCHAR(20)) LIKE :contrato
                OR ISNULL(t.nombre_comercial,N\'\') LIKE :tienda
                OR ISNULL(a.nombre_locatario,N\'\') LIKE :arrendatario
                OR ISNULL(a.rut,N\'\') LIKE :rut
             ORDER BY c.id_contrato_arriendo DESC'
        );
        $stmtBuscarContrato->execute([
            ':contrato'=>$like, ':tienda'=>$like, ':arrendatario'=>$like, ':rut'=>$like,
        ]);
        $contratosEncontrados = $stmtBuscarContrato->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($contratosEncontrados) === 1) {
            $idContrato = (int) $contratosEncontrados[0]['id_contrato_arriendo'];
        } elseif ($contratosEncontrados === []) {
            $errorBusquedaContrato = 'No se encontraron contratos para la búsqueda indicada.';
        }
    } catch (Throwable $e) {
        error_log('[MSP][Correcciones][buscar_contrato] '.$e->getMessage());
        $errorBusquedaContrato = 'No fue posible buscar contratos en este momento.';
    }
}

if ($prefillRegistro !== null) {
    try {
        $stmtPrefill = $conn->prepare('SELECT lm.id_lectura,lm.periodo_facturacion,lm.lectura_actual,m.id_local,ts.codigo_servicio
            FROM dbo.msp_lecturas_medidores lm
            INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
            INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio
            WHERE lm.id_lectura=:id');
        $stmtPrefill->execute([':id' => $prefillRegistro]);
        $prefillLectura = $stmtPrefill->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($prefillLectura) {
            $prefillPeriodo = substr((string) $prefillLectura['periodo_facturacion'], 0, 7);
            $prefillServicio = strtoupper((string) $prefillLectura['codigo_servicio']);
            $prefillLocal = (int) $prefillLectura['id_local'];
        }
    } catch (Throwable) {
        $prefillLectura = null;
    }
}
if ($idContrato !== false && $idContrato !== null) {
    try {
        $stmtPeriodos = $conn->prepare("SELECT DISTINCT CONVERT(char(7),lm.periodo_facturacion,126) periodo FROM dbo.msp_lecturas_medidores lm INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local WHERE cl.id_contrato_arriendo=:id ORDER BY periodo DESC");
        $stmtPeriodos->execute([':id' => (int) $idContrato]);
        $periodosDisponibles = array_values(array_filter(array_map(static fn(array $r): string => (string) $r['periodo'], $stmtPeriodos->fetchAll(PDO::FETCH_ASSOC))));
    } catch (Throwable $e) { error_log('[MSP][Correcciones][periodos] '.$e->getMessage()); $periodosDisponibles = []; $cargaOpcionesError = 'No fue posible cargar los períodos disponibles.'; }

    try {
        $stmtLecturas = $conn->prepare(
            "SELECT DISTINCT lm.id_lectura,CONVERT(char(7),lm.periodo_facturacion,126) periodo,
                    lm.lectura_anterior,lm.lectura_actual,lm.consumo_informado,
                    m.id_local,m.codigo_medidor,l.cdo_local,UPPER(ts.codigo_servicio) codigo_servicio
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio
             INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local
             WHERE cl.id_contrato_arriendo=:id
               AND lm.periodo_facturacion>=cl.fecha_inicio
               AND (cl.fecha_termino IS NULL OR lm.periodo_facturacion<=EOMONTH(cl.fecha_termino))
             ORDER BY periodo DESC,l.cdo_local,codigo_servicio,m.codigo_medidor"
        );
        $stmtLecturas->execute([':id' => (int) $idContrato]);
        $lecturasDisponibles = $stmtLecturas->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('[MSP][Correcciones][lecturas] '.$e->getMessage()); $lecturasDisponibles = []; $cargaOpcionesError = 'No fue posible cargar las lecturas disponibles.'; }

    if (msp2TableExists($conn, 'msp_arriendo_local_snapshot_periodo')) {
        try {
            $stmtArriendos = $conn->prepare(
                "SELECT s.id_snapshot_arriendo,s.id_contrato_local,s.id_local,
                        CONVERT(char(7),s.periodo_facturacion,126) periodo,
                        s.monto_neto_clp,s.monto_total_clp,s.estado_snapshot,s.es_congelado,l.cdo_local
                 FROM dbo.msp_arriendo_local_snapshot_periodo s
                 INNER JOIN dbo.msp_locales l ON l.id_local=s.id_local
                 WHERE s.id_contrato_arriendo=:id
                   AND s.estado_snapshot IN (1,2,3)
                 ORDER BY s.periodo_facturacion DESC,l.cdo_local"
            );
            $stmtArriendos->execute([':id' => (int) $idContrato]);
            $arriendosDisponibles = $stmtArriendos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { error_log('[MSP][Correcciones][arriendos] '.$e->getMessage()); $arriendosDisponibles = []; $cargaOpcionesError = 'No fue posible cargar los arriendos mensuales.'; }
    }
}

if ($idContrato !== false && $idContrato !== null) {
    try {
        require_once dirname(__DIR__) . '/services/CorreccionesService.php';
        $resultado = CorreccionesService::analizarPorContrato($conn, (int) $idContrato);
    } catch (Throwable $e) {
        error_log('[MSP][Correcciones][analisis] '.$e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible analizar la corrección.';
    }
}

if (isset($_GET['listar']) && $_GET['listar'] === '1') {
    try {
        require_once dirname(__DIR__) . '/services/CorreccionesService.php';
        $correcciones = CorreccionesService::listar($conn, [
            'estado' => (string) ($_GET['estado'] ?? ''),
            'id_contrato_arriendo' => (int) ($_GET['filtro_contrato'] ?? 0),
            'codigo_operacion' => (string) ($_GET['codigo'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('[MSP][Correcciones][listado] '.$e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible listar las correcciones.';
    }
}

if ($idCorreccion !== false && $idCorreccion !== null) {
    try {
        require_once dirname(__DIR__) . '/services/CorreccionesService.php';
        $correccion = CorreccionesService::obtener($conn, (int) $idCorreccion);
    } catch (Throwable $e) {
        error_log('[MSP][Correcciones][detalle] '.$e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible abrir la corrección.';
    }
}

function corrMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 0, ',', '.');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Correcciones | MSP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3" data-gp-commandbar>
        <div>
            <p class="text-muted mb-1">MSP / Operación</p>
            <h1 class="h3 mb-1">Correcciones selectivas</h1>
            <p class="text-muted mb-0">Corrige un registro puntual sin borrar ni reabrir el período completo.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">Volver al menú MSP</a>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">1. Seleccionar contrato</div>
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <div class="col-lg-8">
                    <label class="form-label" for="buscarContrato">Contrato, tienda, arrendatario o RUT</label>
                    <input type="text" class="form-control" id="buscarContrato" name="buscar_contrato" value="<?php echo msp2Escape($buscarContrato); ?>" placeholder="Buscar..." autocomplete="off">
                </div>
                <div class="col-lg-4 d-grid">
                    <button class="btn btn-primary">Buscar contrato</button>
                </div>
            </form>
            <?php if ($errorBusquedaContrato !== null): ?><div class="alert alert-warning mt-3 mb-0"><?php echo msp2Escape($errorBusquedaContrato); ?></div><?php endif; ?>
            <?php if (count($contratosEncontrados) > 1): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Contrato</th><th>Tienda</th><th>Arrendatario</th><th>RUT</th><th></th></tr></thead>
                        <tbody><?php foreach ($contratosEncontrados as $contratoEncontrado): ?><tr>
                            <td>#<?php echo (int)$contratoEncontrado['id_contrato_arriendo']; ?></td>
                            <td><?php echo msp2Escape((string)$contratoEncontrado['nombre_comercial']); ?></td>
                            <td><?php echo msp2Escape((string)$contratoEncontrado['nombre_locatario']); ?></td>
                            <td><?php echo msp2Escape((string)$contratoEncontrado['rut']); ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?id_contrato_arriendo='.(int)$contratoEncontrado['id_contrato_arriendo'])); ?>">Seleccionar</a></td>
                        </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3" id="corrBuscarSolicitudesCard">
        <div class="card-header bg-white fw-semibold">Buscar solicitudes registradas</div>
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <input type="hidden" name="listar" value="1">
                <div class="col-lg-3">
                    <label class="form-label">Estado</label>
                    <select class="form-select" name="estado">
                        <option value="">Todos</option>
                        <?php foreach (['BORRADOR','ANALIZADA','PENDIENTE_APROBACION','APROBADA','EJECUTANDO','EJECUTADA','RECHAZADA','CANCELADA','ERROR'] as $estadoOpt): ?>
                            <option value="<?php echo msp2Escape($estadoOpt); ?>" <?php echo ((string) ($_GET['estado'] ?? '') === $estadoOpt) ? 'selected' : ''; ?>><?php echo msp2Escape($estadoOpt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Contrato</label>
                    <input type="number" class="form-control" name="filtro_contrato" value="<?php echo msp2Escape((string) ($_GET['filtro_contrato'] ?? '')); ?>" min="1">
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Código</label>
                    <input type="text" class="form-control" name="codigo" value="<?php echo msp2Escape((string) ($_GET['codigo'] ?? '')); ?>" placeholder="UUID">
                </div>
                <div class="col-lg-3 d-grid">
                    <button class="btn btn-outline-dark">Listar solicitudes</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
    <?php elseif ($resultado !== null): ?>
        <?php
        $contrato = $resultado['contrato'];
        $clasificacion = $resultado['clasificacion'];
        $dep = $resultado['dependencias'];
        $periodosPorEntidad = ['lectura' => [], 'cargo' => [], 'arriendo' => []];
        foreach ($lecturasDisponibles as $itemPeriodo) {
            $periodoItem = (string) ($itemPeriodo['periodo'] ?? '');
            if ($periodoItem !== '') { $periodosPorEntidad['lectura'][$periodoItem] = true; }
        }
        $serviciosLecturaDisponibles = [];
        foreach ($lecturasDisponibles as $lecturaServicio) {
            $codigoServicioDisponible = strtoupper((string) ($lecturaServicio['codigo_servicio'] ?? ''));
            if ($codigoServicioDisponible !== '') { $serviciosLecturaDisponibles[$codigoServicioDisponible] = true; }
        }
        foreach (($dep['cargos'] ?? []) as $itemPeriodo) {
            $periodoItem = substr((string) (($itemPeriodo['periodo_referencia'] ?? null) ?: ($itemPeriodo['fecha_cargo'] ?? '')), 0, 7);
            if ($periodoItem !== '') { $periodosPorEntidad['cargo'][$periodoItem] = true; }
        }
        foreach ($arriendosDisponibles as $itemPeriodo) {
            $periodoItem = (string) ($itemPeriodo['periodo'] ?? '');
            if ($periodoItem !== '') { $periodosPorEntidad['arriendo'][$periodoItem] = true; }
        }
        $todosPeriodos = array_values(array_unique(array_merge(
            array_keys($periodosPorEntidad['lectura']),
            array_keys($periodosPorEntidad['cargo']),
            array_keys($periodosPorEntidad['arriendo'])
        )));
        rsort($todosPeriodos);
        $localesFormulario = [];
        foreach (($dep['locales'] ?? []) as $localDep) {
            $idLocalForm = (int) ($localDep['id_local'] ?? 0);
            if ($idLocalForm > 0) { $localesFormulario[$idLocalForm] = (string) ($localDep['cdo_local'] ?? ''); }
        }
        ?>
        <div class="row g-3 mb-3" id="corrResumenContrato">
            <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Contrato</div><div class="fw-semibold">#<?php echo (int) ($contrato['id_contrato_arriendo'] ?? 0); ?></div><div class="small text-muted"><?php echo msp2Escape((string) ($contrato['nombre_locatario'] ?? '-')); ?></div><div class="small text-muted">RUT <?php echo msp2Escape((string) ($contrato['rut'] ?? '-')); ?></div></div></div></div>
            <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Contexto general</div><div class="fw-semibold">Evaluación preliminar</div><div class="small text-muted">La clasificación definitiva se calculará usando únicamente el registro que selecciones, no todos los pagos del contrato.</div></div></div></div>
            <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Estado contrato</div><div class="fw-semibold">#<?php echo (int) ($contrato['estado_contrato'] ?? 0); ?></div><div class="small text-muted">Tienda <?php echo msp2Escape((string) ($contrato['nombre_comercial'] ?? '-')); ?></div></div></div></div>
        </div>

        <div class="row g-3 mb-3" id="corrContextoTecnico">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Contexto del contrato</div>
                    <div class="card-body">
                        <ul class="mb-0">
                            <li>Locales activos: <?php echo count($dep['locales'] ?? []); ?></li>
                            <li>Documentos: <?php echo count($dep['documentos'] ?? []); ?></li>
                            <li>Pagos: <?php echo count($dep['pagos'] ?? []); ?></li>
                            <li>Garantías: <?php echo count($dep['garantias'] ?? []); ?></li>
                            <li>Cargos: <?php echo count($dep['cargos'] ?? []); ?></li>
                            <li>Tesorería: <?php echo count($dep['tesoreria'] ?? []); ?></li>
                            <li>Contabilidad: <?php echo count($dep['contabilidad'] ?? []); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Qué no modificaría una corrección simple</div>
                    <div class="card-body">
                        <p class="mb-2">Este análisis es de solo lectura. Los elementos que ya existen y deben preservarse son:</p>
                        <ul class="mb-0">
                            <li>Pagos reales</li>
                            <li>Conciliaciones</li>
                            <li>Asientos contables</li>
                            <li>Garantías recibidas o aplicadas</li>
                            <li>Cierres mensuales</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3" id="corrDetalleResumenCard">
            <div class="card-header bg-white fw-semibold">Detalle resumido</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Locales</div><div class="fw-semibold"><?php echo count($dep['locales'] ?? []); ?></div><div class="small text-muted">Historial de ocupación y relación contractual.</div></div></div>
                    <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Documentos</div><div class="fw-semibold"><?php echo count($dep['documentos'] ?? []); ?></div><div class="small text-muted">Base para decidir si existe regeneración controlada.</div></div></div>
                    <div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Pagos / garantía / tesorería</div><div class="fw-semibold"><?php echo count($dep['pagos'] ?? []); ?> / <?php echo count($dep['garantias'] ?? []); ?> / <?php echo count($dep['tesoreria'] ?? []); ?></div><div class="small text-muted">Si hay efectos reales, la corrección deja de ser simple.</div></div></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3" id="corrFormularioCard">
            <div class="card-header bg-white fw-semibold">2. Seleccionar el registro y su valor correcto</div>
            <div class="card-body">
                <?php if ($cargaOpcionesError !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($cargaOpcionesError); ?></div><?php endif; ?>
                <form class="row g-3" method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) ($contrato['id_contrato_arriendo'] ?? 0); ?>">
                    <input type="hidden" name="modulo_origen" value="correcciones/index.php">
                    <div class="col-lg-6">
                        <label class="form-label">¿Qué quieres corregir?</label>
                        <select class="form-select" name="entidad_afectada" id="corrEntidad" required>
                            <option value="lectura">Lectura o consumo de servicio</option>
                            <option value="cargo">Multa o cargo adicional</option>
                            <option value="arriendo">Arriendo de un período</option>
                        </select>
                    </div>
                    <div class="col-lg-6" id="corrServicioGrupo">
                        <label class="form-label">Servicio</label>
                        <select class="form-select" name="servicio" id="corrServicio">
                            <option value="">Selecciona el servicio</option>
                            <option value="LUZ" <?php echo $prefillServicio === 'LUZ' ? 'selected' : ''; ?> <?php echo isset($serviciosLecturaDisponibles['LUZ']) ? '' : 'disabled'; ?>>Luz<?php echo isset($serviciosLecturaDisponibles['LUZ']) ? '' : ' · sin lecturas'; ?></option>
                            <option value="AGUA" <?php echo $prefillServicio === 'AGUA' ? 'selected' : ''; ?> <?php echo isset($serviciosLecturaDisponibles['AGUA']) ? '' : 'disabled'; ?>>Agua<?php echo isset($serviciosLecturaDisponibles['AGUA']) ? '' : ' · sin lecturas'; ?></option>
                            <option value="GAS" <?php echo $prefillServicio === 'GAS' ? 'selected' : ''; ?> <?php echo isset($serviciosLecturaDisponibles['GAS']) ? '' : 'disabled'; ?>>Gas<?php echo isset($serviciosLecturaDisponibles['GAS']) ? '' : ' · sin lecturas'; ?></option>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Período</label>
                        <select class="form-select" name="periodo_facturacion" id="corrPeriodo" required>
                            <option value="">Selecciona un período disponible</option>
                            <?php foreach ($todosPeriodos as $periodo): ?>
                                <?php $entidadesPeriodo = array_keys(array_filter($periodosPorEntidad, static fn(array $items): bool => isset($items[$periodo]))); ?>
                                <option value="<?php echo msp2Escape($periodo); ?>"
                                    data-entidades="<?php echo msp2Escape(implode(' ', $entidadesPeriodo)); ?>"
                                    <?php echo $prefillPeriodo === $periodo ? 'selected' : ''; ?>><?php echo msp2Escape($periodo); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="corrPeriodoAyuda">Solo se habilitan meses que contienen registros corregibles.</div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Local</label>
                        <select class="form-select" name="id_local" id="corrLocal">
                            <option value="">-</option>
                            <?php foreach ($localesFormulario as $idLocalForm => $codigoLocalForm): ?>
                                <option value="<?php echo $idLocalForm; ?>" <?php echo $prefillLocal === $idLocalForm ? 'selected' : ''; ?>>Local <?php echo msp2Escape($codigoLocalForm); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" id="corrRegistroLabel">Lectura a corregir</label>
                        <select class="form-select" name="id_registro_origen" id="corrRegistro" required>
                            <option value="">Selecciona local, período y servicio</option>
                            <?php foreach ($lecturasDisponibles as $lecturaOpt): ?>
                                <option value="<?php echo (int) $lecturaOpt['id_lectura']; ?>"
                                    data-entidad="lectura"
                                    data-local="<?php echo (int) $lecturaOpt['id_local']; ?>"
                                    data-periodo="<?php echo msp2Escape((string) $lecturaOpt['periodo']); ?>"
                                    data-servicio="<?php echo msp2Escape((string) $lecturaOpt['codigo_servicio']); ?>"
                                    data-anterior="<?php echo msp2Escape((string) $lecturaOpt['lectura_anterior']); ?>"
                                    data-actual="<?php echo msp2Escape((string) $lecturaOpt['lectura_actual']); ?>"
                                    data-consumo="<?php echo msp2Escape((string) $lecturaOpt['consumo_informado']); ?>"
                                    <?php echo $prefillRegistro === (int) $lecturaOpt['id_lectura'] ? 'selected' : ''; ?>>
                                    <?php echo msp2Escape((string) $lecturaOpt['cdo_local'].' · '.(string) $lecturaOpt['codigo_servicio'].' · '.(string) $lecturaOpt['codigo_medidor']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php foreach (($dep['cargos'] ?? []) as $cargoOpt): ?>
                                <option value="<?php echo (int) $cargoOpt['id_cargo_contrato_local']; ?>"
                                    data-entidad="cargo"
                                    data-local="<?php echo (int) ($cargoOpt['id_local'] ?? 0); ?>"
                                    data-periodo="<?php echo msp2Escape(substr((string) (($cargoOpt['periodo_referencia'] ?? null) ?: ($cargoOpt['fecha_cargo'] ?? '')), 0, 7)); ?>"
                                    data-anterior="<?php echo msp2Escape((string) ($cargoOpt['monto_cargo'] ?? '')); ?>"
                                    data-descripcion="<?php echo msp2Escape((string) ($cargoOpt['descripcion_cargo'] ?? '')); ?>">
                                    Local <?php echo msp2Escape((string) ($cargoOpt['cdo_local'] ?? '')); ?> · #<?php echo (int) $cargoOpt['id_cargo_contrato_local']; ?> · <?php echo msp2Escape((string) ($cargoOpt['descripcion_cargo'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php foreach ($arriendosDisponibles as $arriendoOpt): ?>
                                <option value="<?php echo (int) $arriendoOpt['id_snapshot_arriendo']; ?>"
                                    data-entidad="arriendo"
                                    data-local="<?php echo (int) $arriendoOpt['id_local']; ?>"
                                    data-periodo="<?php echo msp2Escape((string) $arriendoOpt['periodo']); ?>"
                                    data-anterior="<?php echo msp2Escape((string) $arriendoOpt['monto_neto_clp']); ?>"
                                    data-total="<?php echo msp2Escape((string) $arriendoOpt['monto_total_clp']); ?>">
                                    Local <?php echo msp2Escape((string) $arriendoOpt['cdo_local']); ?> · arriendo del mes
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label">Valor registrado actualmente</label>
                        <textarea class="form-control" name="valor_anterior" id="corrValorAnterior" rows="3" readonly placeholder="Se completará al seleccionar el registro"><?php echo $prefillLectura ? msp2Escape('Lectura actual registrada: '.(string) $prefillLectura['lectura_actual']) : ''; ?></textarea>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" id="corrValorNuevoLabel">Nueva lectura correcta</label>
                        <input class="form-control" type="number" min="0" step="0.0001" name="valor_nuevo" id="corrValorNuevo" required placeholder="Ingresa el valor correcto">
                        <div class="form-text" id="corrValorNuevoAyuda">Ingresa la lectura acumulada que debería haber quedado registrada.</div>
                    </div>
                    <div class="col-lg-12">
                        <label class="form-label">Motivo</label>
                        <input type="text" class="form-control" name="motivo" maxlength="500" required placeholder="Describe la corrección requerida">
                    </div>
                    <div class="col-12 d-grid d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">Analizar y registrar corrección</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="fw-semibold mb-2">Acciones de corrección</div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?listar=1')); ?>">Ver solicitudes</a>
                    <a class="btn btn-outline-dark btn-sm" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?listar=1&estado=BORRADOR')); ?>">Borradores</a>
                </div>
            </div>
        </div>
    <?php elseif ($correccion !== null): ?>
        <?php
        $estadoCorreccion = strtoupper((string) ($correccion['estado_correccion'] ?? ''));
        $nivelCorreccion = strtoupper((string) ($correccion['nivel_correcion'] ?? ''));
        $tipoCorreccion = strtoupper((string) ($correccion['tipo_correccion'] ?? ''));
        $puedeAprobar = $nivelCorreccion === 'EDICION_SIMPLE'
            && in_array($estadoCorreccion, ['BORRADOR','ANALIZADA','PENDIENTE_APROBACION','ERROR'], true);
        $puedeEjecutar = $estadoCorreccion === 'APROBADA'
            && $nivelCorreccion === 'EDICION_SIMPLE'
            && in_array($tipoCorreccion, ['LECTURA','CARGO','ARRIENDO_PERIODO'], true);
        $analisisCorreccion = json_decode((string) ($correccion['resultado_analisis'] ?? ''), true);
        if (!is_array($analisisCorreccion)) { $analisisCorreccion = []; }
        $registroExacto = is_array($analisisCorreccion['registro_exacto'] ?? null) ? $analisisCorreccion['registro_exacto'] : [];
        $mensajesNivel = [
            'REGENERACION_CONTROLADA' => 'El registro ya forma parte de un documento sin pagos. Requiere actualizar ese documento de forma controlada.',
            'AJUSTE_FINANCIERO' => 'El registro ya tiene pagos, saldo o garantía relacionada. La diferencia debe resolverse mediante un ajuste financiero.',
            'AUTORIZACION' => 'El registro tiene contabilidad o efectos protegidos y requiere autorización y una reversa o ajuste formal.',
            'REVISION' => 'El estado actual del registro requiere una revisión antes de permitir la corrección.',
        ];
        $labelTipoCorreccion = ['LECTURA'=>'Lectura o consumo','CARGO'=>'Multa o cargo adicional','ARRIENDO_PERIODO'=>'Arriendo de un período'][$tipoCorreccion] ?? $tipoCorreccion;
        ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <p class="text-muted mb-1">Solicitud #<?php echo (int) ($correccion['id_correccion'] ?? 0); ?></p>
                        <h2 class="h5 mb-1"><?php echo msp2Escape($labelTipoCorreccion); ?></h2>
                        <div class="text-muted small"><?php echo msp2Escape((string) ($correccion['estado_correccion'] ?? '')); ?> · <?php echo msp2Escape((string) ($correccion['nivel_correcion'] ?? '')); ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($puedeAprobar): ?><form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>">
                            <?php msp2CsrfField(); ?>
                            <input type="hidden" name="accion" value="aplicar_simple">
                            <input type="hidden" name="id_correccion" value="<?php echo (int) ($correccion['id_correccion'] ?? 0); ?>">
                            <button class="btn btn-success btn-sm">Aplicar corrección</button>
                        </form><?php endif; ?>
                        <?php if ($puedeEjecutar): ?><form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>">
                            <?php msp2CsrfField(); ?>
                            <input type="hidden" name="accion" value="ejecutar">
                            <input type="hidden" name="id_correccion" value="<?php echo (int) ($correccion['id_correccion'] ?? 0); ?>">
                            <button class="btn btn-dark btn-sm">Confirmar corrección</button>
                        </form><?php endif; ?>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="small text-muted">Motivo</div><div class="fw-semibold"><?php echo msp2Escape((string) ($correccion['motivo'] ?? '')); ?></div></div></div>
                    <div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="small text-muted">Registro seleccionado</div><div class="fw-semibold"><?php echo msp2Escape($labelTipoCorreccion); ?> #<?php echo (int) ($correccion['id_registro_origen'] ?? 0); ?></div><div class="small text-muted">Período <?php echo msp2Escape(substr((string)($correccion['periodo_facturacion'] ?? '-'),0,7)); ?> · Local #<?php echo (int)($correccion['id_local'] ?? 0); ?></div></div></div>
                </div>
                <div class="alert <?php echo $nivelCorreccion === 'EDICION_SIMPLE' ? 'alert-success' : 'alert-warning'; ?> mt-3 mb-0">
                    <div class="fw-semibold"><?php echo msp2Escape(str_replace('_',' ',$nivelCorreccion)); ?></div>
                    <?php if ($nivelCorreccion === 'EDICION_SIMPLE'): ?>
                        Esta corrección puede ejecutarse de forma selectiva y no reabre el período completo.
                        <?php if ($estadoCorreccion !== 'EJECUTADA'): ?><strong>El valor todavía no cambiará hasta presionar “Aplicar corrección”.</strong><?php endif; ?>
                    <?php else: ?>
                        <?php echo msp2Escape($mensajesNivel[$nivelCorreccion] ?? 'La solicitud requiere una estrategia controlada antes de ejecutarse.'); ?> La solicitud queda auditada y no se habilita una modificación insegura.
                    <?php endif; ?>
                </div>
                <?php if (!empty($correccion['eventos']) && is_array($correccion['eventos'])): ?>
                    <div class="mt-3">
                        <div class="fw-semibold mb-2">Eventos</div>
                        <div class="list-group">
                            <?php foreach ($correccion['eventos'] as $evento): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo msp2Escape((string) ($evento['tipo_evento'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($evento['detalle'] ?? '')); ?></div>
                                        </div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($evento['fecha_evento'] ?? '')); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($correcciones !== []): ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Código</th>
                            <th>Estado</th>
                            <th>Nivel</th>
                            <th>Contrato</th>
                            <th>Motivo</th>
                            <th>Solicitud</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($correcciones as $corr): ?>
                            <tr>
                                <td>#<?php echo (int) ($corr['id_correccion'] ?? 0); ?></td>
                                <td><code><?php echo msp2Escape((string) ($corr['codigo_operacion'] ?? '')); ?></code></td>
                                <td><?php echo msp2Escape((string) ($corr['estado_correccion'] ?? '')); ?></td>
                                <td><?php echo msp2Escape((string) ($corr['nivel_correcion'] ?? '')); ?></td>
                                <td>#<?php echo (int) ($corr['id_contrato_arriendo'] ?? 0); ?></td>
                                <td><?php echo msp2Escape((string) ($corr['motivo'] ?? '')); ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?php echo msp2Escape(msp2Url('correcciones/index.php?id_correccion=' . (int) ($corr['id_correccion'] ?? 0))); ?>">Abrir</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">Ingresa un contrato para ver el impacto de corrección.</div>
    <?php endif; ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const main=document.querySelector('main.gp-main'), formularioCard=document.getElementById('corrFormularioCard');
    const resumenContrato=document.getElementById('corrResumenContrato'), contextoTecnico=document.getElementById('corrContextoTecnico');
    const buscarSolicitudes=document.getElementById('corrBuscarSolicitudesCard');
    if(formularioCard&&resumenContrato) resumenContrato.insertAdjacentElement('afterend',formularioCard);
    if(main&&contextoTecnico) main.appendChild(contextoTecnico);
    if(main&&buscarSolicitudes) main.appendChild(buscarSolicitudes);
    const entidad=document.getElementById('corrEntidad');
    const servicio=document.getElementById('corrServicio'), servicioGrupo=document.getElementById('corrServicioGrupo');
    const periodo=document.getElementById('corrPeriodo'), local=document.getElementById('corrLocal');
    const registro=document.getElementById('corrRegistro'), registroLabel=document.getElementById('corrRegistroLabel');
    const anterior=document.getElementById('corrValorAnterior'), nuevo=document.getElementById('corrValorNuevo');
    const nuevoLabel=document.getElementById('corrValorNuevoLabel'), nuevoAyuda=document.getElementById('corrValorNuevoAyuda');
    if(!entidad||!registro)return;
    const options=[...registro.options].slice(1);
    const periodOptions=[...periodo.options].slice(1), localOptions=[...local.options].slice(1);
    const serviceOptions=[...servicio.options].slice(1);
    function registrosCompatibles(ignoreLocal=false){
        const e=entidad.value, esLectura=e==='lectura';
        return options.filter(o=>{
            const entidades=(o.dataset.entidad||'').split(' ');
            return entidades.includes(e)
                &&(!esLectura||(!servicio.value||o.dataset.servicio===servicio.value))
                &&(!periodo.value||o.dataset.periodo===periodo.value)
                &&(ignoreLocal||!local.value||o.dataset.local===local.value);
        });
    }
    function filtrar(){
        const e=entidad.value, esLectura=e==='lectura';
        servicioGrupo.hidden=!esLectura; servicio.required=esLectura;
        if(esLectura && !servicio.value){const habilitados=serviceOptions.filter(o=>!o.disabled);if(habilitados.length===1)servicio.value=habilitados[0].value;}
        periodOptions.forEach(o=>{const disponible=(o.dataset.entidades||'').split(' ').includes(e);o.disabled=!disponible;o.hidden=false;});
        if(periodo.selectedOptions[0]?.disabled) periodo.value='';
        localOptions.forEach(o=>{const disponible=registrosCompatibles(true).some(r=>r.dataset.local===o.value);o.disabled=!disponible;o.hidden=false;});
        if(local.selectedOptions[0]?.disabled) local.value='';
        if(!local.value){const habilitados=localOptions.filter(o=>!o.disabled);if(habilitados.length===1)local.value=habilitados[0].value;}
        registroLabel.textContent=esLectura?'Medidor y lectura registrada':(e==='arriendo'?'Arriendo registrado':'Multa o cargo registrado');
        if(nuevoLabel) nuevoLabel.textContent=esLectura?'Nueva lectura correcta':(e==='arriendo'?'Nuevo arriendo neto correcto (CLP)':'Nuevo monto correcto (CLP)');
        if(nuevoAyuda) nuevoAyuda.textContent=esLectura?'Ingresa la lectura acumulada correcta; el consumo se recalcula automáticamente.':(e==='arriendo'?'Este ajuste corresponde solo al local y mes seleccionados.':'Ingresa el monto total correcto del cargo o multa.');
        if(nuevo){nuevo.step=esLectura?'0.0001':'0.01';nuevo.min=esLectura?'0':'0.01';}
        options.forEach(o=>{const visible=registrosCompatibles().includes(o);o.hidden=!visible;o.disabled=!visible;});
        if(registro.selectedOptions[0]?.disabled){registro.value='';anterior.value='';}
    }
    function precargar(){
        const o=registro.selectedOptions[0]; if(!o||!o.value){anterior.value='';return;}
        if(entidad.value==='lectura') anterior.value=`Lectura anterior: ${o.dataset.anterior||'-'}\nLectura actual registrada: ${o.dataset.actual||'-'}\nConsumo registrado: ${o.dataset.consumo||'-'}`;
        else if(entidad.value==='arriendo') anterior.value=`Arriendo neto registrado: $ ${o.dataset.anterior||'-'}\nTotal registrado: $ ${o.dataset.total||'-'}`;
        else anterior.value=`Monto registrado: $ ${o.dataset.anterior||'-'}\nConcepto: ${o.dataset.descripcion||'-'}`;
    }
    [entidad,servicio,periodo,local].forEach(el=>el?.addEventListener('change',()=>{filtrar();precargar();}));
    registro.addEventListener('change',precargar); filtrar(); precargar();
    if(nuevo) nuevo.placeholder='Ingresa solamente el valor correcto';
});
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>

