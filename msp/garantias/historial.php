<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/caja_admin_historial.php';

msp2RequireAccess('MSP Reportes');

const MSP2_GARANTIAS_HISTORIAL_POR_PAGINA = 50;

function msp2GarantiasHistorialFecha(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
}

function msp2GarantiasHistorialMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function msp2GarantiasHistorialPatronBusqueda(string $value): string
{
    // SQL Server interpreta %, _, [ y el propio caracter de escape dentro de LIKE.
    // Se escapan para que la busqueda sea siempre por el texto escrito por el usuario.
    $escaped = str_replace(
        ['~', '%', '_', '['],
        ['~~', '~%', '~_', '~['],
        $value
    );

    return '%' . $escaped . '%';
}

function msp2GarantiasHistorialFechaVisible(mixed $value): string
{
    $raw = substr((string) $value, 0, 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return $date === false ? $raw : $date->format('d-m-Y');
}

function msp2GarantiasHistorialUrl(array $base, int $page): string
{
    $base['pagina'] = max(1, $page);
    return msp2Url('garantias/historial.php?' . http_build_query($base));
}

function msp2GarantiasHistorialPaginas(int $current, int $total): array
{
    if ($total <= 1) {
        return [];
    }

    $pages = [1, $total];
    for ($page = max(2, $current - 2); $page <= min($total - 1, $current + 2); $page++) {
        $pages[] = $page;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $items = [];
    $previous = null;
    foreach ($pages as $page) {
        if ($previous !== null && $page > $previous + 1) {
            $items[] = 'ellipsis';
        }
        $items[] = $page;
        $previous = $page;
    }
    return $items;
}

$tipos = [
    'RECEPCION' => 'Recepciones',
    'RESERVA' => 'Reservas',
    'LIBERACION_RESERVA' => 'Liberaciones de reserva',
    'APLICACION_CARGO' => 'Aplicaciones a cargos',
    'DEVOLUCION' => 'Devoluciones',
    'AJUSTE_POSITIVO' => 'Ajustes positivos',
    'AJUSTE_NEGATIVO' => 'Ajustes negativos',
    'REVERSA_RECEPCION' => 'Reversas de recepción',
    'REVERSA_DEVOLUCION' => 'Reversas de devolución',
    'REVERSA_APLICACION' => 'Reversas de aplicación',
];

$tiposMovimiento = [
    'RECEPCION' => 'Recepción',
    'RESERVA' => 'Reserva',
    'LIBERACION_RESERVA' => 'Liberación',
    'APLICACION_CARGO' => 'Cargo aplicado',
    'DEVOLUCION' => 'Devolución',
    'AJUSTE_POSITIVO' => 'Ajuste positivo',
    'AJUSTE_NEGATIVO' => 'Ajuste negativo',
    'REVERSA_RECEPCION' => 'Reversa de recepción',
    'REVERSA_DEVOLUCION' => 'Reversa de devolución',
    'REVERSA_APLICACION' => 'Reversa de aplicación',
];

$query = msp2NormalizeText((string) ($_GET['q'] ?? ''));
$tipo = strtoupper(trim((string) ($_GET['tipo'] ?? '')));
if (!isset($tipos[$tipo])) {
    $tipo = '';
}
$desde = msp2GarantiasHistorialFecha((string) ($_GET['desde'] ?? ''));
$hasta = msp2GarantiasHistorialFecha((string) ($_GET['hasta'] ?? ''));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$error = null;
$warning = null;
$movimientos = [];
$totalMovimientos = 0;
$totalArrendatarios = 0;
$totalPaginas = 1;
$fechaMinima = null;
$fechaMaxima = null;

if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    $warning = 'La fecha inicial no puede ser posterior a la fecha final.';
    $desde = '';
    $hasta = '';
}

$conditions = ['1=1'];
$params = [];
if ($query !== '') {
    $like = msp2GarantiasHistorialPatronBusqueda($query);
    $conditions[] = '(ISNULL(nombre_arrendatario,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_nombre ESCAPE N\'~\'
        OR ISNULL(rut,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_rut ESCAPE N\'~\'
        OR ISNULL(tienda,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_tienda ESCAPE N\'~\'
        OR ISNULL(locales,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_locales ESCAPE N\'~\'
        OR ISNULL(local_destino,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_local_destino ESCAPE N\'~\'
        OR ISNULL(referencia,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_referencia ESCAPE N\'~\'
        OR ISNULL(medio,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_medio ESCAPE N\'~\'
        OR ISNULL(cuenta,N\'\') COLLATE Latin1_General_100_CI_AI LIKE :q_cuenta ESCAPE N\'~\'
        OR CAST(id_contrato_arriendo AS NVARCHAR(20)) LIKE :q_contrato ESCAPE N\'~\'
        OR CAST(id_garantia_tienda AS NVARCHAR(20)) LIKE :q_garantia ESCAPE N\'~\')';
    foreach (['q_nombre','q_rut','q_tienda','q_locales','q_local_destino','q_referencia','q_medio','q_cuenta','q_contrato','q_garantia'] as $parameter) {
        $params[':' . $parameter] = $like;
    }
}
if ($tipo !== '') {
    $conditions[] = 'codigo_evento=:tipo';
    $params[':tipo'] = $tipo;
}
if ($desde !== '') {
    $conditions[] = 'fecha_evento>=:desde';
    $params[':desde'] = $desde;
}
if ($hasta !== '') {
    $conditions[] = 'fecha_evento<=:hasta';
    $params[':hasta'] = $hasta;
}
$where = implode(' AND ', $conditions);

try {
    $summaryStatement = $conn->prepare(
        'SELECT COUNT(*) AS movimientos,
                COUNT(DISTINCT id_arrendatario) AS arrendatarios,
                MIN(fecha_evento) AS fecha_minima,
                MAX(fecha_evento) AS fecha_maxima
         FROM dbo.msp_vw_garantias_historial_arrendatario
         WHERE ' . $where
    );
    $summaryStatement->execute($params);
    $summary = $summaryStatement->fetch() ?: [];
    $totalMovimientos = (int) ($summary['movimientos'] ?? 0);
    $totalArrendatarios = (int) ($summary['arrendatarios'] ?? 0);
    $fechaMinima = $summary['fecha_minima'] ?? null;
    $fechaMaxima = $summary['fecha_maxima'] ?? null;
    $totalPaginas = max(1, (int) ceil($totalMovimientos / MSP2_GARANTIAS_HISTORIAL_POR_PAGINA));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * MSP2_GARANTIAS_HISTORIAL_POR_PAGINA;

    $dataStatement = $conn->prepare(
        'WITH historial AS (
            SELECT h.*,
                   r.monto_pactado,
                   SUM(CASE
                       WHEN h.codigo_evento=N\'RECEPCION\' THEN h.monto_entrada
                       WHEN h.codigo_evento=N\'REVERSA_RECEPCION\' THEN -h.monto_salida
                       ELSE 0
                   END) OVER (
                       PARTITION BY h.id_garantia_tienda
                       ORDER BY h.fecha_evento,h.prioridad_evento,h.fecha_registro,
                                h.origen_evento,h.id_evento
                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                   ) AS monto_recepcion_acumulado
            FROM dbo.msp_vw_garantias_historial_arrendatario h
            INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
                ON r.id_garantia_tienda=h.id_garantia_tienda
        ), filtrado AS (
            SELECT h.*,
                   local_orden.local_orden,
                   ROW_NUMBER() OVER (
                       PARTITION BY h.id_arrendatario
                       ORDER BY h.fecha_evento,h.prioridad_evento,h.fecha_registro,
                                h.id_garantia_tienda,h.origen_evento,h.id_evento
                   ) AS secuencia_arrendatario,
                   COUNT(*) OVER (PARTITION BY h.id_arrendatario) AS total_arrendatario
            FROM historial h
            CROSS APPLY (VALUES (
                COALESCE(
                    NULLIF(LTRIM(RTRIM(h.locales)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.local_destino)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.tienda)),N\'\'),
                    NULLIF(LTRIM(RTRIM(h.nombre_arrendatario)),N\'\'),
                    N\'\'
                )
            )) AS local_base(local_base)
            CROSS APPLY (VALUES (
                LTRIM(RTRIM(LEFT(
                    local_base.local_base,
                    CHARINDEX(N\'/\',local_base.local_base+N\'/\')-1
                )))
            )) AS local_orden(local_orden)
            WHERE ' . $where . '
        )
        SELECT *
        FROM filtrado
        ORDER BY
                 CASE
                     WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' THEN 0
                     WHEN local_orden LIKE N\'[0-9]%\' THEN 1
                     ELSE 2
                 END,
                 CASE
                     WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\'
                     THEN LEFT(local_orden,CHARINDEX(N\'-\',local_orden)-1)
                     ELSE N\'\'
                 END COLLATE Latin1_General_100_CI_AI,
                 CASE
                     WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' THEN
                         TRY_CONVERT(INT,LEFT(
                             SUBSTRING(local_orden,CHARINDEX(N\'-\',local_orden)+1,100),
                             PATINDEX(N\'%[^0-9]%\',SUBSTRING(local_orden,CHARINDEX(N\'-\',local_orden)+1,100)+N\'X\')-1
                         ))
                     WHEN local_orden LIKE N\'[0-9]%\' THEN
                         TRY_CONVERT(INT,LEFT(
                             local_orden,
                             PATINDEX(N\'%[^0-9]%\',local_orden+N\'X\')-1
                         ))
                     ELSE 2147483647
                 END,
                 CASE
                     WHEN local_orden LIKE N\'[A-Za-z]%-%[0-9]%\' OR local_orden LIKE N\'[0-9]%\' THEN N\'\'
                     ELSE local_orden
                 END COLLATE Latin1_General_100_CI_AI,
                 local_orden COLLATE Latin1_General_100_CI_AI,
                 locales COLLATE Latin1_General_100_CI_AI,
                 fecha_evento,prioridad_evento,fecha_registro,
                 nombre_arrendatario,rut,id_arrendatario,
                 id_garantia_tienda,origen_evento,id_evento
        OFFSET :offset ROWS FETCH NEXT :limite ROWS ONLY'
    );
    foreach ($params as $name => $value) {
        $dataStatement->bindValue($name, $value, PDO::PARAM_STR);
    }
    $dataStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStatement->bindValue(':limite', MSP2_GARANTIAS_HISTORIAL_POR_PAGINA, PDO::PARAM_INT);
    $dataStatement->execute();
    $movimientos = $dataStatement->fetchAll() ?: [];
    $dataStatement->closeCursor();
    $cajaAdministrativa = msp2GarantiasCajaAdminHistorial($conn, array_column(
        array_filter($movimientos, static fn(array $row): bool => $row['codigo_evento'] === 'DEVOLUCION'), 'id_evento'
    ));
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException(
        $exception,
        'msp.garantias.historial',
        'No fue posible cargar el historial completo de garantías.'
    );
}

$queryBase = array_filter([
    'q' => $query,
    'tipo' => $tipo,
    'desde' => $desde,
    'hasta' => $hasta,
], static fn(mixed $value): bool => $value !== '');
$paginationItems = msp2GarantiasHistorialPaginas($pagina, $totalPaginas);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout gp-module-msp bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-3 px-lg-4 msp-guarantee-ledger">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div>
            <p class="text-muted mb-1">MSP / Garantías</p>
            <h1 class="h3 mb-1">Historial de garantías</h1>
            <p class="text-muted mb-0">Registro completo ordenado por local y cronológicamente dentro de cada local.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/control_historial.php')); ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Control e historial
        </a>
    </header>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
    <?php endif; ?>
    <?php if ($warning !== null): ?>
        <div class="alert alert-warning"><?php echo msp2Escape($warning); ?></div>
    <?php endif; ?>

    <form method="get" class="card card-body shadow-sm mb-3" aria-label="Filtros del historial">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-xl-4">
                <label class="form-label" for="historial_q">Buscar dentro del registro</label>
                <div class="input-group">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
                    <input id="historial_q" type="search" name="q" value="<?php echo msp2Escape($query); ?>" class="form-control" placeholder="Ej.: franc, RUT, tienda, contrato o local" autocomplete="off">
                </div>
                <div class="form-text">Busca coincidencias parciales sin distinguir mayúsculas ni acentos.</div>
            </div>
            <div class="col-12 col-md-4 col-xl-3">
                <label class="form-label" for="historial_tipo">Movimiento</label>
                <select id="historial_tipo" name="tipo" class="form-select">
                    <option value="">Todos los movimientos</option>
                    <?php foreach ($tipos as $codigo => $label): ?>
                        <option value="<?php echo msp2Escape($codigo); ?>" <?php echo $tipo === $codigo ? 'selected' : ''; ?>><?php echo msp2Escape($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label" for="historial_desde">Desde</label>
                <input id="historial_desde" type="date" name="desde" value="<?php echo msp2Escape($desde); ?>" class="form-control">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label" for="historial_hasta">Hasta</label>
                <input id="historial_hasta" type="date" name="hasta" value="<?php echo msp2Escape($hasta); ?>" class="form-control">
            </div>
            <div class="col-12 col-md-2 col-xl-1 d-grid gap-1">
                <button class="btn btn-primary" type="submit">Buscar</button>
                <?php if ($query !== '' || $tipo !== '' || $desde !== '' || $hasta !== ''): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/historial.php')); ?>">Limpiar</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <section class="gp-indicator-strip mb-3" aria-label="Resumen del historial">
        <div class="gp-indicator">
            <span class="gp-indicator-label">Movimientos</span>
            <strong class="gp-indicator-value"><?php echo $totalMovimientos; ?></strong>
        </div>
        <div class="gp-indicator">
            <span class="gp-indicator-label">Arrendatarios</span>
            <strong class="gp-indicator-value"><?php echo $totalArrendatarios; ?></strong>
        </div>
        <div class="gp-indicator">
            <span class="gp-indicator-label">Periodo visible</span>
            <strong class="gp-indicator-value msp-guarantee-ledger-period">
                <?php echo $fechaMinima ? msp2Escape(msp2GarantiasHistorialFechaVisible($fechaMinima)) : '—'; ?>
                <span aria-hidden="true">→</span>
                <?php echo $fechaMaxima ? msp2Escape(msp2GarantiasHistorialFechaVisible($fechaMaxima)) : '—'; ?>
            </strong>
        </div>
        <div class="gp-indicator">
            <span class="gp-indicator-label">Página</span>
            <strong class="gp-indicator-value"><?php echo $pagina; ?> de <?php echo $totalPaginas; ?></strong>
        </div>
    </section>

    <?php if ($error === null && $movimientos === []): ?>
        <div class="card shadow-sm"><div class="card-body text-center text-muted py-5">No existen movimientos que coincidan con los filtros.</div></div>
    <?php endif; ?>

    <?php if ($movimientos !== []): ?>
        <section class="card shadow-sm msp-guarantee-ledger-group">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-guarantee-ledger-table" data-gp-table-sticky="false">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Tienda / contrato</th>
                        <th>Movimiento</th>
                        <th class="text-end">Recepción</th>
                        <th>Saldo de garantía</th>
                        <th class="text-end">Egreso</th>
                        <th>Documento / referencia</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($movimientos as $movimiento): ?>
                            <?php
                            $codigoEvento = (string) $movimiento['codigo_evento'];
                            $nombreMovimiento = $tiposMovimiento[$codigoEvento] ?? str_replace('_', ' ', $codigoEvento);
                            if ($codigoEvento === 'RECEPCION') {
                                $montoPactado = (float) ($movimiento['monto_pactado'] ?? 0);
                                $montoRecepcionAcumulado = (float) ($movimiento['monto_recepcion_acumulado'] ?? 0);
                                $nombreMovimiento = $montoPactado > 0.009 && $montoRecepcionAcumulado + 0.009 >= $montoPactado
                                    ? 'Pago total'
                                    : 'Abono parcial';
                            }
                            $referenceParts = [];
                            if (!empty($movimiento['id_documento_cobro'])) {
                                $referenceParts[] = 'Documento #' . (int) $movimiento['id_documento_cobro'];
                            }
                            if (!empty($movimiento['id_pago'])) {
                                $referenceParts[] = 'Pago #' . (int) $movimiento['id_pago'];
                            }
                            if (!empty($movimiento['id_cargo_contrato_local'])) {
                                $referenceParts[] = 'Cargo contrato-local #' . (int) $movimiento['id_cargo_contrato_local'];
                            } elseif (!empty($movimiento['id_cargo_salida'])) {
                                $referenceParts[] = 'Cargo de salida #' . (int) $movimiento['id_cargo_salida'];
                            }
                            ?>
                            <tr>
                                <td data-gp-label="Fecha" class="text-nowrap"><?php echo msp2Escape(msp2GarantiasHistorialFechaVisible($movimiento['fecha_evento'])); ?></td>
                                <td data-gp-label="Tienda / contrato" data-gp-allow-wrap="true">
                                    <div class="fw-semibold"><?php echo msp2Escape((string) $movimiento['tienda']); ?></div>
                                    <div class="small text-muted">Contrato #<?php echo (int) $movimiento['id_contrato_arriendo']; ?> · Garantía #<?php echo (int) $movimiento['id_garantia_tienda']; ?></div>
                                    <div class="small text-muted">Locales <?php echo msp2Escape((string) ($movimiento['locales'] ?: '—')); ?></div>
                                    <?php if (!empty($movimiento['local_destino'])): ?>
                                        <div class="small">Destino: <?php echo msp2Escape((string) $movimiento['local_destino']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-gp-label="Movimiento">
                                    <span class="msp-guarantee-ledger-movement"><?php echo msp2Escape($nombreMovimiento); ?></span>
                                    <div class="small text-muted mt-1"><?php echo msp2Escape((string) $movimiento['estado_evento']); ?></div>
                                    <?php if ($codigoEvento === 'DEVOLUCION'): ?>
                                        <?php echo msp2GarantiasCajaAdminDetalle($cajaAdministrativa[(int) $movimiento['id_evento']] ?? []); ?>
                                    <?php endif; ?>
                                </td>
                                <td data-gp-label="Recepción" class="text-end fw-semibold text-success text-nowrap msp-guarantee-ledger-amount">
                                    <?php echo (float) $movimiento['monto_entrada'] > 0 ? msp2Escape(msp2GarantiasHistorialMonto($movimiento['monto_entrada'])) : '—'; ?>
                                </td>
                                <td data-gp-label="Saldo de garantía" class="msp-guarantee-ledger-balance">
                                    <span class="msp-guarantee-ledger-pair msp-guarantee-ledger-pair--total"><span>Disponible</span><strong><?php echo msp2Escape(msp2GarantiasHistorialMonto($movimiento['saldo_disponible_garantia'])); ?></strong></span>
                                    <span class="msp-guarantee-ledger-pair"><span>Reservado</span><strong><?php echo msp2Escape(msp2GarantiasHistorialMonto($movimiento['saldo_reservado_garantia'])); ?></strong></span>
                                    <span class="msp-guarantee-ledger-pair"><span>Total</span><strong><?php echo msp2Escape(msp2GarantiasHistorialMonto($movimiento['saldo_total_garantia'])); ?></strong></span>
                                </td>
                                <td data-gp-label="Egreso" class="text-end fw-semibold text-danger text-nowrap msp-guarantee-ledger-amount">
                                    <?php echo (float) $movimiento['monto_salida'] > 0 ? msp2Escape(msp2GarantiasHistorialMonto($movimiento['monto_salida'])) : '—'; ?>
                                </td>
                                <td data-gp-label="Documento / referencia" data-gp-allow-wrap="true">
                                    <?php if ($referenceParts !== []): ?><div><?php echo msp2Escape(implode(' · ', $referenceParts)); ?></div><?php endif; ?>
                                    <div class="small text-muted">Ref. <?php echo msp2Escape((string) ($movimiento['referencia'] ?: '—')); ?></div>
                                    <?php if (!empty($movimiento['medio'])): ?><div class="small text-muted"><?php echo msp2Escape((string) $movimiento['medio']); ?><?php echo !empty($movimiento['cuenta']) ? ' · ' . msp2Escape((string) $movimiento['cuenta']) : ''; ?></div><?php endif; ?>
                                </td>
                            </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($totalPaginas > 1): ?>
        <nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3" aria-label="Paginación del historial de garantías">
            <div class="small text-muted">Máximo <?php echo MSP2_GARANTIAS_HISTORIAL_POR_PAGINA; ?> movimientos por página.</div>
            <ul class="pagination pagination-sm flex-wrap mb-0">
                <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo msp2Escape(msp2GarantiasHistorialUrl($queryBase, max(1, $pagina - 1))); ?>">Anterior</a>
                </li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if ($item === 'ellipsis'): ?>
                        <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php else: ?>
                        <li class="page-item <?php echo (int) $item === $pagina ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo msp2Escape(msp2GarantiasHistorialUrl($queryBase, (int) $item)); ?>"><?php echo (int) $item; ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo msp2Escape(msp2GarantiasHistorialUrl($queryBase, min($totalPaginas, $pagina + 1))); ?>">Siguiente</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
