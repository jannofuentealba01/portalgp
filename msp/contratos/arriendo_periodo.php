<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$rows = [];

function msp2ArriendoPeriodoParseMonthToFirstDay(string $periodoYm): ?string
{
    if (preg_match('/^\d{4}-\d{2}$/', $periodoYm) !== 1) {
        return null;
    }

    $periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($periodoDate === false || $periodoDate->format('Y-m') !== $periodoYm) {
        return null;
    }

    return $periodoDate->format('Y-m-01');
}

function msp2ArriendoPeriodoFormatInputValue(mixed $value, int $decimals): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (!is_numeric((string) $value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

function msp2ArriendoPeriodoBuildQuery(array $params): string
{
    return http_build_query($params);
}

$periodoYm = trim((string) ($_GET['periodo'] ?? ''));
if ($periodoYm === '') {
    $periodoYm = (new DateTimeImmutable('today'))->format('Y-m');
}

$periodoFacturacion = msp2ArriendoPeriodoParseMonthToFirstDay($periodoYm);
if ($periodoFacturacion === null) {
    $periodoYm = (new DateTimeImmutable('today'))->format('Y-m');
    $periodoFacturacion = msp2ArriendoPeriodoParseMonthToFirstDay($periodoYm);
}

$filtroTexto = msp2NormalizeText((string) ($_GET['filtro'] ?? ''));
$soloPendientes = (string) ($_GET['solo_pendientes'] ?? '') === '1';

$resumenTotal = 0;
$resumenCargados = 0;
$resumenPendientes = 0;

try {
    if ($periodoFacturacion === null) {
        throw new RuntimeException('El período seleccionado no es válido.');
    }

    $requiredTables = [
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_locales',
        'msp_contrato_local_arriendo_regla',
        'msp_tipo_modalidad_arriendo',
        'msp_contrato_local_arriendo_periodo',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para arriendo dinámico mensual: `' . implode('`, `', $missingTables) . '`.');
    }

    $stmt = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         DECLARE @filtro NVARCHAR(200) = :filtro;
         DECLARE @filtro_like NVARCHAR(220) = :filtro_like;
         DECLARE @solo_pendientes BIT = :solo_pendientes;
         SELECT
            cl.id_contrato_local,
            ca.id_contrato_arriendo,
            ca.id_tienda,
            t.nombre_comercial,
            a.nombre_locatario,
            l.cdo_local,
            l.desc_local,
            regla.id_regla_arriendo,
            regla.descuento_mensual_clp AS descuento_regla_clp,
            ap.id_arriendo_periodo,
            ap.valor_periodo_uf,
            ap.valor_periodo_clp,
            ap.descuento_periodo_clp
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = ca.id_tienda
         LEFT JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = ca.id_arrendatario
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         OUTER APPLY (
            SELECT TOP (1)
                rr.id_regla_arriendo,
                rr.descuento_mensual_clp,
                tm.codigo_modalidad
            FROM dbo.msp_contrato_local_arriendo_regla rr
            INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
            WHERE rr.id_contrato_local = cl.id_contrato_local
              AND rr.estado_regla = 1
              AND rr.fecha_inicio <= EOMONTH(@periodo)
              AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= @periodo)
            ORDER BY
                CASE WHEN rr.es_default = 1 THEN 1 ELSE 0 END DESC,
                rr.prioridad DESC,
                rr.id_regla_arriendo DESC
         ) regla
         LEFT JOIN dbo.msp_contrato_local_arriendo_periodo ap
            ON ap.id_contrato_local = cl.id_contrato_local
           AND ap.periodo_facturacion = @periodo
           AND ap.estado_periodo = 1
         WHERE cl.estado_relacion IN (1,2)
           AND cl.fecha_inicio <= EOMONTH(@periodo)
           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
           AND ca.fecha_inicio <= EOMONTH(@periodo)
           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
           AND ca.estado_contrato IN (1,2,3)
           AND ISNULL(regla.codigo_modalidad, N'UF_ESTATICO') = N'DINAMICO_MENSUAL'
           AND (
                @filtro = N''
                OR t.nombre_comercial LIKE @filtro_like
                OR ISNULL(a.nombre_locatario, N'') LIKE @filtro_like
                OR l.cdo_local LIKE @filtro_like
                OR CAST(ca.id_contrato_arriendo AS NVARCHAR(20)) LIKE @filtro_like
                OR CAST(cl.id_contrato_local AS NVARCHAR(20)) LIKE @filtro_like
           )
           AND (@solo_pendientes = 0 OR ap.id_arriendo_periodo IS NULL)
         ORDER BY
            t.nombre_comercial ASC,
            " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ",
            cl.id_contrato_local ASC"
    );
    $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmt->bindValue(':filtro', $filtroTexto, PDO::PARAM_STR);
    $stmt->bindValue(':filtro_like', '%' . $filtroTexto . '%', PDO::PARAM_STR);
    $stmt->bindValue(':solo_pendientes', $soloPendientes ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();

    while (($row = $stmt->fetch()) !== false) {
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }

        $idPeriodo = (int) ($row['id_arriendo_periodo'] ?? 0);
        $tienePeriodo = $idPeriodo > 0;
        if ($tienePeriodo) {
            $resumenCargados++;
        } else {
            $resumenPendientes++;
        }
        $resumenTotal++;

        $descuentoRegla = msp2ArriendoPeriodoFormatInputValue($row['descuento_regla_clp'] ?? null, 2);
        $descuentoPeriodo = msp2ArriendoPeriodoFormatInputValue($row['descuento_periodo_clp'] ?? null, 2);
        if ($descuentoPeriodo === '' && $descuentoRegla !== '') {
            $descuentoPeriodo = $descuentoRegla;
        }

        $rows[] = [
            'id_contrato_local' => $idContratoLocal,
            'id_contrato_arriendo' => (int) ($row['id_contrato_arriendo'] ?? 0),
            'id_tienda' => (int) ($row['id_tienda'] ?? 0),
            'nombre_comercial' => (string) ($row['nombre_comercial'] ?? ''),
            'nombre_locatario' => (string) ($row['nombre_locatario'] ?? ''),
            'cdo_local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
            'desc_local' => (string) ($row['desc_local'] ?? ''),
            'tiene_periodo' => $tienePeriodo,
            'valor_periodo_uf' => msp2ArriendoPeriodoFormatInputValue($row['valor_periodo_uf'] ?? null, 6),
            'valor_periodo_clp' => msp2ArriendoPeriodoFormatInputValue($row['valor_periodo_clp'] ?? null, 2),
            'descuento_periodo_clp' => $descuentoPeriodo,
        ];
    }
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        $loadError = $exception->getMessage();
    } else {
        $loadError = 'No fue posible cargar la configuración mensual de arriendo.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Arriendo dinámico mensual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a contratos
            </a>
            <a href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Facturación mensual
            </a>
        </div>

        <h1 class="h3 mb-1">Arriendo dinámico mensual</h1>
        <p class="text-muted mb-4">Carga manual de valores por periodo para locales con modalidad <code>DINAMICO_MENSUAL</code>.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-12 col-md-2">
                            <label for="periodo" class="form-label">Periodo</label>
                            <input type="month" id="periodo" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label for="filtro" class="form-label">Filtro</label>
                            <input type="text" id="filtro" name="filtro" value="<?php echo msp2Escape($filtroTexto); ?>" class="form-control" placeholder="Tienda, local, contrato o contrato-local">
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" value="1" id="solo_pendientes" name="solo_pendientes" <?php echo $soloPendientes ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="solo_pendientes">
                                    Solo pendientes de carga
                                </label>
                            </div>
                        </div>
                        <div class="col-6 col-md-1 d-grid">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                        </div>
                        <div class="col-6 col-md-1 d-grid">
                            <a href="?<?php echo msp2ArriendoPeriodoBuildQuery(['periodo' => $periodoYm]); ?>" class="btn btn-outline-secondary">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-12 col-md-4">
                    <div class="alert alert-light border mb-0">Total dinámicos: <strong><?php echo number_format($resumenTotal, 0, ',', '.'); ?></strong></div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="alert alert-success border mb-0">Cargados: <strong><?php echo number_format($resumenCargados, 0, ',', '.'); ?></strong></div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="alert alert-warning border mb-0">Pendientes: <strong><?php echo number_format($resumenPendientes, 0, ',', '.'); ?></strong></div>
                </div>
            </div>

            <div class="alert alert-info">
                Ingresa <strong>UF</strong> o <strong>CLP</strong> por local para el período. Si completas ambos campos en una fila, el cálculo mensual prioriza el valor <strong>CLP</strong>. El descuento es monto mensual en CLP.
            </div>

            <?php if ($rows === []): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center text-muted py-4">
                        No hay contrato-locales en modalidad dinámica para este filtro/período.
                    </div>
                </div>
            <?php else: ?>
                <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_arriendo_periodo.php')); ?>">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                    <input type="hidden" name="filtro" value="<?php echo msp2Escape($filtroTexto); ?>">
                    <input type="hidden" name="solo_pendientes" value="<?php echo $soloPendientes ? '1' : '0'; ?>">

                    <div class="card shadow-sm border-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>Contrato-local</th>
                                    <th>Tienda / Arrendatario</th>
                                    <th>Local</th>
                                    <th class="text-end">Valor UF período</th>
                                    <th class="text-end">Valor CLP período</th>
                                    <th class="text-end">Descuento CLP mensual</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center">Limpiar</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0); ?>
                                    <tr>
                                        <td>
                                            <div><strong>#<?php echo $idContratoLocal; ?></strong></div>
                                            <div class="small text-muted">Contrato #<?php echo (int) ($row['id_contrato_arriendo'] ?? 0); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo msp2Escape((string) ($row['nombre_comercial'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($row['nombre_locatario'] ?? '-')); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo msp2Escape((string) ($row['cdo_local'] ?? '')); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($row['desc_local'] ?? '')); ?></div>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end"
                                                name="rows[<?php echo $idContratoLocal; ?>][valor_periodo_uf]"
                                                value="<?php echo msp2Escape((string) ($row['valor_periodo_uf'] ?? '')); ?>"
                                                placeholder="0.000000"
                                                inputmode="decimal">
                                        </td>
                                        <td style="min-width: 160px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end"
                                                name="rows[<?php echo $idContratoLocal; ?>][valor_periodo_clp]"
                                                value="<?php echo msp2Escape((string) ($row['valor_periodo_clp'] ?? '')); ?>"
                                                placeholder="0,00"
                                                inputmode="decimal">
                                        </td>
                                        <td style="min-width: 170px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end"
                                                name="rows[<?php echo $idContratoLocal; ?>][descuento_periodo_clp]"
                                                value="<?php echo msp2Escape((string) ($row['descuento_periodo_clp'] ?? '')); ?>"
                                                placeholder="0,00"
                                                inputmode="decimal">
                                        </td>
                                        <td class="text-center">
                                            <?php if ((bool) ($row['tiene_periodo'] ?? false)): ?>
                                                <span class="badge text-bg-success">Cargado</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-warning text-dark">Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((bool) ($row['tiene_periodo'] ?? false)): ?>
                                                <div class="form-check d-inline-block">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        value="1"
                                                        id="limpiar_<?php echo $idContratoLocal; ?>"
                                                        name="rows[<?php echo $idContratoLocal; ?>][limpiar]">
                                                    <label class="form-check-label small text-muted" for="limpiar_<?php echo $idContratoLocal; ?>">Sí</label>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar carga mensual
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
