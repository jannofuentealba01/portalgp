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
$tiposServicio = [];
$medidores = [];
$tablaExiste = false;

$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$filtroServicio = trim((string) ($_GET['filtroServicio'] ?? ''));
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

$estadosMedidores = [
    1 => 'Activo',
    2 => 'Retirado',
    3 => 'Inactivo',
];

try {
    $requiredTables = ['msp_medidores', 'msp_tipos_servicio', 'msp_locales'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para la gestión de medidores: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/db/msp_cobro_servicios.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de medidores.';
}

if ($tablaExiste) {
    try {
        $tiposStmt = $conn->query(
            "SELECT id_tipo_servicio, codigo_servicio, nombre_servicio
             FROM dbo.msp_tipos_servicio
             WHERE UPPER(codigo_servicio) IN ('AGUA', 'LUZ', 'GAS')
             ORDER BY CASE UPPER(codigo_servicio)
                        WHEN 'AGUA' THEN 1
                        WHEN 'LUZ' THEN 2
                        WHEN 'GAS' THEN 3
                        ELSE 100
                      END, nombre_servicio ASC"
        );
        $tiposServicio = $tiposStmt->fetchAll();

        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $conditions[] = "(m.codigo_medidor LIKE :filtro OR m.alias_medidor LIKE :filtro OR l.cdo_local LIKE :filtro)";
            $params[':filtro'] = '%' . $filtroTexto . '%';
        }

        if ($filtroServicio !== '' && ctype_digit($filtroServicio)) {
            $conditions[] = 'm.id_tipo_servicio = :id_tipo_servicio';
            $params[':id_tipo_servicio'] = (int) $filtroServicio;
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'm.estado_medidor = :estado_medidor';
            $params[':estado_medidor'] = (int) $filtroEstado;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $stmt = $conn->prepare(
            "SELECT
                m.id_medidor,
                m.codigo_medidor,
                m.alias_medidor,
                m.numero_serie,
                m.valor_inicial,
                m.fecha_instalacion,
                m.fecha_retiro,
                m.estado_medidor,
                l.cdo_local,
                l.desc_local,
                ts.codigo_servicio,
                ts.nombre_servicio
             FROM dbo.msp_medidores m
             INNER JOIN dbo.msp_locales l ON l.id_local = m.id_local
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = m.id_tipo_servicio
             WHERE $whereClause
             ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ", ts.nombre_servicio ASC, m.codigo_medidor ASC"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        $medidores = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los medidores. Detalle técnico: ' . $exception->getMessage();
    }
}

function msp2EstadoMedidorLabel(int $estado, array $labels): string
{
    return $labels[$estado] ?? 'Sin estado';
}

function msp2EstadoMedidorBadgeCatalog(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo' => 'bg-success',
        'retirado' => 'bg-secondary',
        'inactivo' => 'bg-warning text-dark',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Medidores</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .medidores-actions { flex-wrap:nowrap !important; white-space:nowrap; }
        .medidores-actions .btn { padding-inline:.65rem; }
    </style>
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="box-container-wide">
        <header class="d-flex justify-content-between align-items-center gap-2 mb-3" data-gp-commandbar>
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
            </a>
            <div>
                <h1 class="form-title text-center mb-0">Medidores</h1>
            </div>
            <div class="d-flex gap-2 medidores-actions">
                <a href="<?php echo msp2Escape(msp2Url('medidores/plantilla.php')); ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla
                </a>
                <a href="<?php echo msp2Escape(msp2Url('locales/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-speedometer2 me-1" aria-hidden="true"></i>Gestionar locales
                </a>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form class="row g-2 mb-3" method="get">
                <div class="col-12 col-md-5">
                    <input type="text" class="form-control" name="filtroTexto" placeholder="Buscar por local, código o alias" value="<?php echo msp2Escape($filtroTexto); ?>">
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" name="filtroServicio">
                        <option value="">Servicio</option>
                        <?php foreach ($tiposServicio as $tipo): ?>
                            <option value="<?php echo (int) $tipo['id_tipo_servicio']; ?>" <?php echo $filtroServicio === (string) $tipo['id_tipo_servicio'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $tipo['nombre_servicio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <select class="form-select" name="filtroEstado">
                        <option value="">Estado</option>
                        <?php foreach ($estadosMedidores as $estadoId => $estadoLabel): ?>
                            <option value="<?php echo $estadoId; ?>" <?php echo $filtroEstado === (string) $estadoId ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estadoLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light text-center">
                        <tr>
                            <th style="width: 80px;">#</th>
                            <th>Local</th>
                            <th>Servicio</th>
                            <th>Código</th>
                            <th>Alias</th>
                            <th>Serie</th>
                            <th>Valor inicial</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($medidores === []): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">Sin medidores disponibles.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medidores as $index => $medidor): ?>
                                <?php
                                $estadoLabel = msp2EstadoMedidorLabel((int) ($medidor['estado_medidor'] ?? 0), $estadosMedidores);
                                $estadoBadge = msp2EstadoMedidorBadgeCatalog($estadoLabel);
                                $localLabel = (string) ($medidor['cdo_local'] ?? '');
                                if (!empty($medidor['desc_local'])) {
                                    $localLabel .= ' - ' . (string) $medidor['desc_local'];
                                }
                                ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo msp2Escape($localLabel); ?></td>
                                    <td><?php echo msp2Escape((string) ($medidor['nombre_servicio'] ?? $medidor['codigo_servicio'] ?? '')); ?></td>
                                    <td class="text-uppercase"><?php echo msp2Escape((string) ($medidor['codigo_medidor'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape((string) ($medidor['alias_medidor'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape((string) ($medidor['numero_serie'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape(msp2FormatoDecimal($medidor['valor_inicial'] ?? null, 0)); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $estadoBadge; ?>">
                                            <?php echo msp2Escape($estadoLabel); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
