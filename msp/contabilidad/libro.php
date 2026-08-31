<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$loadError = null;
$rows = [];
$periodosDisponibles = [];
$cuentasDisponibles = [];
$resumen = [
    'debe' => 0.0,
    'haber' => 0.0,
    'asientos' => 0,
    'lineas' => 0,
];

$filtroPeriodo = trim((string) ($_GET['periodo'] ?? 'all'));
if ($filtroPeriodo !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) !== 1) {
    $filtroPeriodo = 'all';
}

$filtroCuenta = trim((string) ($_GET['cuenta'] ?? 'all'));
if ($filtroCuenta !== 'all' && preg_match('/^[0-9A-Za-z\.\-_]+$/', $filtroCuenta) !== 1) {
    $filtroCuenta = 'all';
}

function lbFmtMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function lbFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed instanceof DateTime ? $parsed->format('d-m-Y') : $value;
}

function lbEstadoLabel(int $estado): string
{
    return match ($estado) {
        1 => 'Contabilizado',
        2 => 'Reversado',
        3 => 'Reversa',
        default => 'N/D',
    };
}

function lbEstadoBadge(int $estado): string
{
    return match ($estado) {
        1 => 'text-bg-success',
        2 => 'text-bg-secondary',
        3 => 'text-bg-warning',
        default => 'text-bg-light',
    };
}

try {
    $stmtView = $conn->query("SELECT OBJECT_ID(N'dbo.msp_acc_vw_libro_diario', N'V')");
    $viewDisponible = (int) $stmtView->fetchColumn() > 0;
    if (!$viewDisponible) {
        $loadError = 'No existe la vista contable `dbo.msp_acc_vw_libro_diario`. Ejecuta `db/patch_contabilidad_doble_partida.sql`.';
    }
} catch (PDOException) {
    $loadError = 'No fue posible validar la capa contable.';
}

if ($loadError === null) {
    try {
        $periodosDisponibles = $conn->query(
            "SELECT DISTINCT CONCAT(anio, N'-', RIGHT(CONCAT(N'0', mes), 2)) AS periodo_ym
             FROM dbo.msp_acc_vw_libro_diario
             ORDER BY periodo_ym DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($filtroPeriodo !== 'all' && !in_array($filtroPeriodo, $periodosDisponibles, true)) {
            $filtroPeriodo = 'all';
        }

        $cuentasDisponibles = $conn->query(
            "SELECT DISTINCT codigo_cuenta, nombre_cuenta
             FROM dbo.msp_acc_vw_libro_diario
             ORDER BY codigo_cuenta ASC"
        )->fetchAll();

        $where = ['1=1'];
        $params = [];
        if ($filtroPeriodo !== 'all') {
            $where[] = "CONCAT(anio, N'-', RIGHT(CONCAT(N'0', mes), 2)) = :periodo";
            $params[':periodo'] = $filtroPeriodo;
        }
        if ($filtroCuenta !== 'all') {
            $where[] = 'codigo_cuenta = :cuenta';
            $params[':cuenta'] = $filtroCuenta;
        }

        $sqlWhere = implode(' AND ', $where);

        $stmtResumen = $conn->prepare(
            "SELECT
                ROUND(ISNULL(SUM(debe), 0), 2) AS debe,
                ROUND(ISNULL(SUM(haber), 0), 2) AS haber,
                COUNT(DISTINCT id_asiento_contable) AS asientos,
                COUNT(*) AS lineas
             FROM dbo.msp_acc_vw_libro_diario
             WHERE {$sqlWhere}"
        );
        foreach ($params as $key => $value) {
            $stmtResumen->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtResumen->execute();
        $resumenRow = $stmtResumen->fetch();
        if (is_array($resumenRow)) {
            $resumen = array_merge($resumen, $resumenRow);
        }

        $stmtRows = $conn->prepare(
            "SELECT TOP (500)
                id_asiento_contable,
                fecha_contable,
                CONCAT(anio, N'-', RIGHT(CONCAT(N'0', mes), 2)) AS periodo_ym,
                codigo_movimiento,
                glosa,
                estado_asiento,
                tabla_origen,
                id_origen,
                linea,
                codigo_cuenta,
                nombre_cuenta,
                debe,
                haber,
                glosa_detalle,
                id_documento_cobro,
                id_pago,
                id_garantia
             FROM dbo.msp_acc_vw_libro_diario
             WHERE {$sqlWhere}
             ORDER BY fecha_contable DESC, id_asiento_contable DESC, linea ASC"
        );
        foreach ($params as $key => $value) {
            $stmtRows->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtRows->execute();
        $rows = $stmtRows->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar el libro diario contable: ' . $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>MSP | Libro Diario Contable</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="container-fluid py-4">
    <div class="container bg-white rounded shadow-sm p-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <h1 class="h4 mb-1">Libro Diario Contable</h1>
                <p class="text-muted mb-0">Asientos de doble partida generados desde documentos, pagos, garantías y saldos a favor.</p>
            </div>
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Menú MSP
            </a>
        </div>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning mb-0"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <form class="row g-2 align-items-end mb-3" method="get">
                <div class="col-12 col-md-3">
                    <label for="periodo" class="form-label">Período</label>
                    <select class="form-select" id="periodo" name="periodo">
                        <option value="all">Todos</option>
                        <?php foreach ($periodosDisponibles as $periodo): ?>
                            <option value="<?php echo msp2Escape((string) $periodo); ?>" <?php echo $filtroPeriodo === (string) $periodo ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $periodo); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-5">
                    <label for="cuenta" class="form-label">Cuenta</label>
                    <select class="form-select" id="cuenta" name="cuenta">
                        <option value="all">Todas</option>
                        <?php foreach ($cuentasDisponibles as $cuenta): ?>
                            <?php $codigoCuenta = (string) ($cuenta['codigo_cuenta'] ?? ''); ?>
                            <option value="<?php echo msp2Escape($codigoCuenta); ?>" <?php echo $filtroCuenta === $codigoCuenta ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($codigoCuenta . ' - ' . (string) ($cuenta['nombre_cuenta'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
                <div class="col-12 col-md-2">
                    <a href="<?php echo msp2Escape(msp2Url('contabilidad/libro.php')); ?>" class="btn btn-outline-secondary w-100">Limpiar</a>
                </div>
            </form>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Debe</div>
                        <div class="fw-semibold"><?php echo msp2Escape(lbFmtMonto($resumen['debe'] ?? 0)); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Haber</div>
                        <div class="fw-semibold"><?php echo msp2Escape(lbFmtMonto($resumen['haber'] ?? 0)); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Asientos</div>
                        <div class="fw-semibold"><?php echo (int) ($resumen['asientos'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Diferencia</div>
                        <div class="fw-semibold"><?php echo msp2Escape(lbFmtMonto(((float) ($resumen['debe'] ?? 0)) - ((float) ($resumen['haber'] ?? 0)))); ?></div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Período</th>
                            <th>Asiento</th>
                            <th>Movimiento</th>
                            <th>Cuenta</th>
                            <th class="text-end">Debe</th>
                            <th class="text-end">Haber</th>
                            <th>Origen</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No hay asientos para los filtros seleccionados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $estado = (int) ($row['estado_asiento'] ?? 0); ?>
                            <tr>
                                <td><?php echo msp2Escape(lbFmtFecha((string) ($row['fecha_contable'] ?? ''))); ?></td>
                                <td><?php echo msp2Escape((string) ($row['periodo_ym'] ?? '')); ?></td>
                                <td>#<?php echo (int) ($row['id_asiento_contable'] ?? 0); ?> / <?php echo (int) ($row['linea'] ?? 0); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo msp2Escape((string) ($row['codigo_movimiento'] ?? '')); ?></div>
                                    <div class="small text-muted"><?php echo msp2Escape((string) ($row['glosa'] ?? '')); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo msp2Escape((string) ($row['codigo_cuenta'] ?? '')); ?></div>
                                    <div class="small text-muted"><?php echo msp2Escape((string) ($row['nombre_cuenta'] ?? '')); ?></div>
                                </td>
                                <td class="text-end"><?php echo msp2Escape(lbFmtMonto($row['debe'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo msp2Escape(lbFmtMonto($row['haber'] ?? 0)); ?></td>
                                <td>
                                    <div class="small"><?php echo msp2Escape((string) ($row['tabla_origen'] ?? '')); ?> #<?php echo (int) ($row['id_origen'] ?? 0); ?></div>
                                    <div class="small text-muted">
                                        Doc <?php echo (int) ($row['id_documento_cobro'] ?? 0); ?> |
                                        Pago <?php echo (int) ($row['id_pago'] ?? 0); ?> |
                                        Gar <?php echo (int) ($row['id_garantia'] ?? 0); ?>
                                    </div>
                                </td>
                                <td><span class="badge <?php echo msp2Escape(lbEstadoBadge($estado)); ?>"><?php echo msp2Escape(lbEstadoLabel($estado)); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted">Se muestran hasta 500 líneas ordenadas por fecha y asiento.</div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
