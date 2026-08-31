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
$tablaExiste = false;
$feriados = [];

$anioActual = (int) date('Y');
$anioFiltroRaw = trim((string) ($_GET['anio'] ?? ''));
$anioFiltro = ctype_digit($anioFiltroRaw) ? (int) $anioFiltroRaw : $anioActual;
$mostrarInactivos = isset($_GET['mostrar_inactivos']) && $_GET['mostrar_inactivos'] === '1';
$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$editarFecha = trim((string) ($_GET['editar'] ?? ''));
$editarFecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $editarFecha) ? $editarFecha : '';

function feriadosResolveCaFile(): ?string
{
    $caFile = dirname(__DIR__) . '/config/cacert.pem';
    return is_file($caFile) ? $caFile : null;
}

function feriadosFetchUrl(string $url, int $timeoutSeconds = 8): array
{
    $result = [
        'ok' => false,
        'payload' => null,
        'http_code' => 0,
        'error' => null,
    ];

    $caFile = feriadosResolveCaFile();
    if (function_exists('curl_init')) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
            ],
        ]);
        if ($caFile !== null) {
            curl_setopt($curl, CURLOPT_CAINFO, $caFile);
        }
        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $result['http_code'] = $httpCode;
        if ($err) {
            $result['error'] = $err;
        }
        if (is_string($response) && $response !== '') {
            $result['ok'] = true;
            $result['payload'] = $response;
        }
        return $result;
    }

    $contextOptions = [
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "Accept: application/json\r\n",
        ],
    ];
    if ($caFile !== null) {
        $contextOptions['ssl'] = [
            'cafile' => $caFile,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ];
    }
    $context = stream_context_create($contextOptions);

    $response = @file_get_contents($url, false, $context);
    $statusCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('/^HTTP\\/[0-9\\.]+\\s+(\\d{3})/', $headerLine, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
    }
    $result['http_code'] = $statusCode;
    if (is_string($response) && $response !== '') {
        $result['ok'] = true;
        $result['payload'] = $response;
    }
    return $result;
}

function feriadosParseBoostr(?string $payload): array
{
    if ($payload === null || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'success') {
        return [];
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }

    return $data;
}

function feriadosUpsert(PDO $conn, array $rows, string $fuente): int
{
    if ($rows === []) {
        return 0;
    }

    $stmt = $conn->prepare(
        "DECLARE @fecha DATE = :fecha;
         DECLARE @titulo NVARCHAR(200) = :titulo;
         DECLARE @tipo NVARCHAR(80) = :tipo;
         DECLARE @inalienable BIT = :inalienable;
         DECLARE @fuente NVARCHAR(40) = :fuente;
         IF EXISTS (SELECT 1 FROM dbo.msp_feriados WHERE fecha = @fecha)
            UPDATE dbo.msp_feriados
            SET titulo = @titulo,
                tipo = @tipo,
                inalienable = @inalienable,
                fuente = @fuente,
                activo = 1,
                updated_at = SYSDATETIME()
            WHERE fecha = @fecha
         ELSE
            INSERT INTO dbo.msp_feriados (
                fecha, titulo, tipo, inalienable, fuente, activo, created_at, updated_at
            ) VALUES (
                @fecha, @titulo, @tipo, @inalienable, @fuente, 1, SYSDATETIME(), SYSDATETIME()
            )"
    );

    $affected = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fecha = $row['date'] ?? $row['fecha'] ?? null;
        if (!is_string($fecha)) {
            continue;
        }
        $fecha = substr($fecha, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            continue;
        }

        $titulo = (string) ($row['title'] ?? $row['titulo'] ?? '');
        if ($titulo === '') {
            $titulo = 'Feriado';
        }
        $tipo = $row['type'] ?? $row['tipo'] ?? null;
        $inalienable = (int) (($row['inalienable'] ?? $row['irrenunciable'] ?? 0) ? 1 : 0);

        $stmt->bindValue(':fecha', $fecha, PDO::PARAM_STR);
        $stmt->bindValue(':titulo', $titulo, PDO::PARAM_STR);
        $stmt->bindValue(':tipo', $tipo !== null ? (string) $tipo : null, $tipo !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':inalienable', $inalienable, PDO::PARAM_INT);
        $stmt->bindValue(':fuente', $fuente, PDO::PARAM_STR);
        $stmt->execute();
        $affected++;
    }

    return $affected;
}

function feriadosRedirect(): never
{
    $query = $_GET;
    unset($query['editar']);
    msp2Redirect('catalogos/feriados.php' . ($query ? ('?' . http_build_query($query)) : ''));
}

try {
    $tablaExiste = msp2TableExists($conn, 'msp_feriados');

    if (!$tablaExiste) {
        $loadError = 'La tabla `msp_feriados` no existe todavía. Ejecuta `msp/db/msp_feriados.sql` antes de continuar.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de feriados.';
}

if ($tablaExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    try {
        if ($accion === 'guardar') {
            $fecha = trim((string) ($_POST['fecha'] ?? ''));
            $titulo = trim((string) ($_POST['titulo'] ?? ''));
            $tipo = trim((string) ($_POST['tipo'] ?? ''));
            $inalienable = isset($_POST['inalienable']) ? 1 : 0;
            $activo = isset($_POST['activo']) ? 1 : 0;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                throw new RuntimeException('Fecha inválida.');
            }
            if ($titulo === '') {
                throw new RuntimeException('Debe ingresar un título.');
            }

            $stmt = $conn->prepare(
                "DECLARE @fecha DATE = :fecha;
                 DECLARE @titulo NVARCHAR(200) = :titulo;
                 DECLARE @tipo NVARCHAR(80) = :tipo;
                 DECLARE @inalienable BIT = :inalienable;
                 DECLARE @fuente NVARCHAR(40) = :fuente;
                 DECLARE @activo BIT = :activo;
                 IF EXISTS (SELECT 1 FROM dbo.msp_feriados WHERE fecha = @fecha)
                    UPDATE dbo.msp_feriados
                    SET titulo = @titulo,
                        tipo = @tipo,
                        inalienable = @inalienable,
                        fuente = @fuente,
                        activo = @activo,
                        updated_at = SYSDATETIME()
                    WHERE fecha = @fecha
                 ELSE
                    INSERT INTO dbo.msp_feriados (
                        fecha, titulo, tipo, inalienable, fuente, activo, created_at, updated_at
                    ) VALUES (
                        @fecha, @titulo, @tipo, @inalienable, @fuente, @activo, SYSDATETIME(), SYSDATETIME()
                    )"
            );
            $stmt->bindValue(':fecha', $fecha, PDO::PARAM_STR);
            $stmt->bindValue(':titulo', $titulo, PDO::PARAM_STR);
            $stmt->bindValue(':tipo', $tipo !== '' ? $tipo : null, $tipo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':inalienable', $inalienable, PDO::PARAM_INT);
            $stmt->bindValue(':fuente', 'manual', PDO::PARAM_STR);
            $stmt->bindValue(':activo', $activo, PDO::PARAM_INT);
            $stmt->execute();

            msp2SetFlash('success', 'Feriado guardado correctamente.');
            feriadosRedirect();
        }

        if ($accion === 'toggle') {
            $fecha = trim((string) ($_POST['fecha'] ?? ''));
            $activo = isset($_POST['activo']) ? 1 : 0;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
                throw new RuntimeException('Fecha inválida.');
            }

            $stmt = $conn->prepare(
                'UPDATE dbo.msp_feriados
                 SET activo = :activo,
                     updated_at = SYSDATETIME()
                 WHERE fecha = :fecha'
            );
            $stmt->bindValue(':fecha', $fecha, PDO::PARAM_STR);
            $stmt->bindValue(':activo', $activo, PDO::PARAM_INT);
            $stmt->execute();

            msp2SetFlash('success', $activo ? 'Feriado activado.' : 'Feriado desactivado.');
            feriadosRedirect();
        }

        if ($accion === 'sync_boostr') {
            $anio = trim((string) ($_POST['anio_sync'] ?? ''));
            if (!ctype_digit($anio)) {
                throw new RuntimeException('Año inválido.');
            }
            $anioInt = (int) $anio;
            if ($anioInt < 1900 || $anioInt > 2100) {
                throw new RuntimeException('Año fuera de rango.');
            }

            $rows = [];
            $source = 'boostr';
            $localFile = dirname(__DIR__) . '/config/holidays/' . $anioInt . '.json';
            if (is_file($localFile)) {
                $localPayload = @file_get_contents($localFile);
                $rows = feriadosParseBoostr(is_string($localPayload) ? $localPayload : null);
                if ($rows !== []) {
                    $source = 'local';
                }
            }

            if ($rows === []) {
                $url = 'https://api.boostr.cl/holidays/' . $anioInt . '.json';
                if ($anioInt === (int) date('Y')) {
                    $url = 'https://api.boostr.cl/holidays.json';
                }
                $fetch = feriadosFetchUrl($url);
                $payload = $fetch['payload'] ?? null;
                $rows = feriadosParseBoostr($payload);
                if ($rows === []) {
                    $detail = [];
                    if (!empty($fetch['http_code'])) {
                        $detail[] = 'HTTP ' . (int) $fetch['http_code'];
                    }
                    if (!empty($fetch['error'])) {
                        $detail[] = (string) $fetch['error'];
                    }
                    if (is_string($payload) && $payload !== '') {
                        $decoded = json_decode($payload, true);
                        if (is_array($decoded) && ($decoded['status'] ?? null) === 'error') {
                            $msg = $decoded['message'] ?? $decoded['data'] ?? $decoded['error'] ?? null;
                            if (is_string($msg) && $msg !== '') {
                                $detail[] = $msg;
                            }
                        }
                    }
                    $detailMsg = $detail !== [] ? (' (' . implode(' | ', $detail) . ')') : '';
                    throw new RuntimeException('No fue posible obtener feriados desde Boostr' . $detailMsg . '.');
                }
            }

            $actualizados = feriadosUpsert($conn, $rows, $source);
            $mensajeFuente = $source === 'local' ? 'archivo local' : 'Boostr';
            msp2SetFlash('success', 'Sincronización completada desde ' . $mensajeFuente . '. Feriados procesados: ' . $actualizados . '.');
            feriadosRedirect();
        }
    } catch (Throwable $e) {
        msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible ejecutar la acción.');
        feriadosRedirect();
    }
}

if ($tablaExiste) {
    try {
        $conditions = [];
        $params = [];

        if ($anioFiltro > 0) {
            $conditions[] = 'YEAR(fecha) = :anio';
            $params[':anio'] = $anioFiltro;
        }
        if (!$mostrarInactivos) {
            $conditions[] = 'activo = 1';
        }
        if ($filtroTexto !== '') {
            $conditions[] = '(titulo LIKE :filtro OR tipo LIKE :filtro)';
            $params[':filtro'] = '%' . $filtroTexto . '%';
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $stmt = $conn->prepare(
            "SELECT fecha, titulo, tipo, inalienable, fuente, activo, updated_at
             FROM dbo.msp_feriados
             WHERE $whereClause
             ORDER BY fecha ASC"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();
        $feriados = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los feriados. Detalle técnico: ' . $exception->getMessage();
    }
}

$feriadoEdit = null;
if ($tablaExiste && $editarFecha !== '') {
    $stmt = $conn->prepare('SELECT fecha, titulo, tipo, inalienable, activo FROM dbo.msp_feriados WHERE fecha = :fecha');
    $stmt->bindValue(':fecha', $editarFecha, PDO::PARAM_STR);
    $stmt->execute();
    $feriadoEdit = $stmt->fetch() ?: null;
    if ($feriadoEdit === null) {
        $editarFecha = '';
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Feriados</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('catalogo_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a catálogos
            </a>
        </div>

        <p class="section-kicker text-center">MSP / Catálogos</p>
        <h1 class="form-title text-center mb-2">Feriados</h1>
        <p class="text-muted text-center mb-4">Mantén el calendario de feriados para el cálculo de vencimientos.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-6">
                    <form method="post" class="border rounded p-3 bg-white">
                        <input type="hidden" name="accion" value="guardar">
                        <h2 class="h6 mb-3"><?php echo $editarFecha !== '' ? 'Editar feriado' : 'Agregar feriado'; ?></h2>
                        <div class="row g-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Fecha</label>
                                <input type="date" class="form-control" name="fecha" value="<?php echo msp2Escape($feriadoEdit['fecha'] ?? ''); ?>" <?php echo $editarFecha !== '' ? 'readonly' : ''; ?> required>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label">Título</label>
                                <input type="text" class="form-control" name="titulo" value="<?php echo msp2Escape($feriadoEdit['titulo'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Tipo</label>
                                <input type="text" class="form-control" name="tipo" value="<?php echo msp2Escape($feriadoEdit['tipo'] ?? ''); ?>" placeholder="Civil, religioso, etc.">
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="inalienable" id="inalienable" <?php echo !empty($feriadoEdit) && (int) ($feriadoEdit['inalienable'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="inalienable">Irrenunciable</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="activo" id="activo" <?php echo empty($feriadoEdit) || (int) ($feriadoEdit['activo'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="activo">Activo</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">Guardar</button>
                            <?php if ($editarFecha !== ''): ?>
                                <a href="<?php echo msp2Escape(msp2Url('catalogos/feriados.php')); ?>" class="btn btn-outline-secondary">Cancelar edición</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="col-12 col-lg-6">
                    <form method="post" class="border rounded p-3 bg-white">
                        <input type="hidden" name="accion" value="sync_boostr">
                        <h2 class="h6 mb-3">Sincronizar desde Boostr</h2>
                        <div class="row g-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Año</label>
                                <input type="number" class="form-control" name="anio_sync" min="1900" max="2100" value="<?php echo (int) $anioFiltro; ?>" required>
                            </div>
                            <div class="col-12 col-md-8 d-flex align-items-end">
                                <button type="submit" class="btn btn-outline-primary">Sincronizar feriados</button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">La sincronización agrega o actualiza feriados del año indicado.</div>
                    </form>
                </div>
            </div>

            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label">Año</label>
                    <input type="number" class="form-control" name="anio" min="1900" max="2100" value="<?php echo (int) $anioFiltro; ?>">
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label">Buscar</label>
                    <input type="text" class="form-control" name="filtroTexto" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Título o tipo">
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="mostrar_inactivos" id="mostrar_inactivos" value="1" <?php echo $mostrarInactivos ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="mostrar_inactivos">Mostrar inactivos</label>
                    </div>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 110px;">Fecha</th>
                            <th>Título</th>
                            <th style="width: 140px;">Tipo</th>
                            <th style="width: 110px;">Irrenunciable</th>
                            <th style="width: 110px;">Activo</th>
                            <th style="width: 120px;">Fuente</th>
                            <th style="width: 140px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($feriados === []): ?>
                            <tr>
                                <td colspan="7" class="text-muted">Sin feriados para los filtros actuales.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feriados as $row): ?>
                                <tr>
                                    <td><?php echo msp2Escape((string) ($row['fecha'] ?? '')); ?></td>
                                    <td class="text-start"><?php echo msp2Escape((string) ($row['titulo'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape((string) ($row['tipo'] ?? '-')); ?></td>
                                    <td><?php echo (int) ($row['inalienable'] ?? 0) === 1 ? 'Sí' : 'No'; ?></td>
                                    <td><?php echo (int) ($row['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo'; ?></td>
                                    <td><?php echo msp2Escape((string) ($row['fuente'] ?? '-')); ?></td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                                            <a href="<?php echo msp2Escape(msp2Url('catalogos/feriados.php?editar=' . urlencode((string) ($row['fecha'] ?? '')))); ?>" class="btn btn-outline-secondary btn-sm">Editar</a>
                                            <form method="post">
                                                <input type="hidden" name="accion" value="toggle">
                                                <input type="hidden" name="fecha" value="<?php echo msp2Escape((string) ($row['fecha'] ?? '')); ?>">
                                                <input type="hidden" name="activo" value="<?php echo (int) ($row['activo'] ?? 0) === 1 ? '0' : '1'; ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                                    <?php echo (int) ($row['activo'] ?? 0) === 1 ? 'Desactivar' : 'Activar'; ?>
                                                </button>
                                            </form>
                                        </div>
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
