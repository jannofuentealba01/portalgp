<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/documentos_cobro/vale_lib.php';
require_once dirname(__DIR__) . '/pagos/archivos_pdf_helper.php';
require_once __DIR__ . '/mail_templates/vale_cobro_email.php';
require_once __DIR__ . '/support/OperacionMensualCommon.php';
require_once __DIR__ . '/support/ImportacionLecturasHelper.php';
require_once __DIR__ . '/services/OperacionMensualService.php';
require_once __DIR__ . '/services/ImportacionLecturasService.php';
require_once __DIR__ . '/services/EnvioDemoService.php';
require_once __DIR__ . '/services/EnvioLotesProgramadosService.php';
require_once __DIR__ . '/services/DocumentosCobroService.php';
require_once __DIR__ . '/services/PoolDocumentosPeriodoService.php';
require_once dirname(__DIR__) . '/services/CierreMensualService.php';
require_once dirname(__DIR__) . '/templates/components/monto_clp_input.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$loadError = null;
$tablaExiste = false;
$toastFlash = null;
$undoToast = null;
$autoApplySnapshot = null;
$completionHintSnapshot = null;
$stagePostGenerationSnapshot = null;
$stageGenerationSnapshot = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flashMeta = $flash['meta'] ?? null;
    if (is_array($flashMeta) && is_array($flashMeta['undo'] ?? null)) {
        $undoToast = $flashMeta['undo'];
    }
    if (is_array($flashMeta) && is_array($flashMeta['auto_apply'] ?? null)) {
        $autoApplySnapshot = $flashMeta['auto_apply'];
    }
    if (is_array($flashMeta) && is_array($flashMeta['completion_hint'] ?? null)) {
        $completionHintSnapshot = $flashMeta['completion_hint'];
    }
    if (is_array($flashMeta) && is_array($flashMeta['stage_post_generation'] ?? null)) {
        $stagePostGenerationSnapshot = $flashMeta['stage_post_generation'];
    }
    if (is_array($flashMeta) && is_array($flashMeta['stage_generation'] ?? null)) {
        $stageGenerationSnapshot = $flashMeta['stage_generation'];
    }
    $flash = null;
}

$serviceCodes = ['AGUA', 'LUZ', 'GAS'];
$serviceIdByCode = [];
$serviceNameByCode = [];

$periodoQuery = trim((string) ($_GET['periodo'] ?? ''));
if ($periodoQuery !== '' && !preg_match('/^(?:\d{4}-\d{2}|\d{2}-\d{4})$/', $periodoQuery)) {
    $periodoQuery = '';
} elseif ($periodoQuery !== '') {
    $periodoQuery = omNormalizePeriodoYm($periodoQuery);
}
$focusAnchorQuery = trim((string) ($_GET['focus'] ?? ''));
if ($focusAnchorQuery !== '' && preg_match('/^[a-z0-9_-]{2,40}$/i', $focusAnchorQuery) !== 1) {
    $focusAnchorQuery = '';
}
$manualAdjustTab = trim((string) ($_GET['manual_tab'] ?? 'cargo_extra'));
if (!in_array($manualAdjustTab, ['cargo_extra', 'saldo_favor'], true)) {
    $manualAdjustTab = 'cargo_extra';
}
$stepRaw = trim((string) ($_GET['step'] ?? '1'));
$hasExplicitStepQuery = isset($_GET['step']) && ctype_digit($stepRaw);
$activeStep = ctype_digit($stepRaw) ? (int) $stepRaw : 1;
if ($activeStep < 1 || $activeStep > 6) {
    $activeStep = 1;
}
$steps = [
    1 => ['title' => 'Periodo', 'subtitle' => 'Cargar o crear periodo', 'anchor' => 'paso-1'],
    2 => ['title' => 'Ajuste Manual', 'subtitle' => 'Cargos y saldo', 'anchor' => 'paso-5'],
    3 => ['title' => 'LUZ', 'subtitle' => 'Consumo de electricidad', 'anchor' => 'servicio-luz'],
    4 => ['title' => 'GAS', 'subtitle' => 'Consumo de gas', 'anchor' => 'servicio-gas'],
    5 => ['title' => 'AGUA', 'subtitle' => 'Consumo de agua', 'anchor' => 'servicio-agua'],
    6 => ['title' => 'Lotes', 'subtitle' => 'Vista previa y programación', 'anchor' => 'paso-6'],
];
$progressPercent = (int) round((($activeStep - 1) / 5) * 100);

function omSelfRoute(): string
{
    $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'operacion_mensual.php'));
    if (!preg_match('/^operacion_mensual(?:_v3)?\.php$/', $scriptName)) {
        $scriptName = 'operacion_mensual.php';
    }

    return 'cobros/' . $scriptName;
}

function omRedirectPeriodo(string $periodo): never
{
    msp2Redirect(omSelfRoute() . '?periodo=' . urlencode($periodo));
}

function omRedirectPeriodoConFoco(string $periodo, ?string $focusAnchor = null): never
{
    $params = ['periodo' => $periodo];
    $fragment = '';
    if ($focusAnchor !== null && preg_match('/^[a-z0-9_-]{2,40}$/i', $focusAnchor) === 1) {
        $params['focus'] = $focusAnchor;
        $fragment = '#' . $focusAnchor;
    }

    msp2Redirect(omSelfRoute() . '?' . http_build_query($params) . $fragment);
}

function omRedirectManualAdjustTab(string $periodo, string $tab = 'cargo_extra'): never
{
    $tabSafe = $tab === 'saldo_favor' ? 'saldo_favor' : 'cargo_extra';
    $params = [
        'periodo' => $periodo,
        'step' => 2,
        'focus' => 'paso-5',
        'manual_tab' => $tabSafe,
    ];
    msp2Redirect(omSelfRoute() . '?' . http_build_query($params) . '#paso-5');
}

function omServiceAnchor(string $codigoServicio): string
{
    return 'servicio-' . strtolower($codigoServicio);
}

function omNextFocusAfterStage(string $etapa): string
{
    return match (strtoupper(trim($etapa))) {
        'LUZ' => 'servicio-gas',
        'GAS' => 'servicio-agua',
        'AGUA' => 'paso-6',
        default => 'paso-6',
    };
}

function omDocsServiceProfileByStage(string $etapa): string
{
    // En etapas de completitud (pasos 3/4/5) la base documental debe
    // regenerarse en modo acumulado para todo el periodo; la segmentacion
    // por perfil se aplica al momento de seleccionar candidatos para lote.
    return 'ALL';
}

function omNormalizePeriodoYm(string $periodoYm): string
{
    $value = trim($periodoYm);
    if (preg_match('/^\d{2}-\d{4}$/', $value) === 1) {
        return substr($value, 3, 4) . '-' . substr($value, 0, 2);
    }

    return $value;
}

function omFmtPeriodoYm(string $periodoYm): string
{
    $normalized = omNormalizePeriodoYm($periodoYm);
    $d = DateTimeImmutable::createFromFormat('!Y-m', $normalized);
    if ($d === false || $d->format('Y-m') !== $normalized) {
        return $periodoYm;
    }

    return $d->format('m-Y');
}

function omParseMonthToFirstDay(string $periodoYm): ?string
{
    $normalized = omNormalizePeriodoYm($periodoYm);
    $d = DateTimeImmutable::createFromFormat('!Y-m', $normalized);
    if ($d === false || $d->format('Y-m') !== $normalized) {
        return null;
    }

    return $d->format('Y-m-01');
}

function omCierreEstadoLabel(int $estado): string
{
    return CierreMensualService::etiqueta($estado);
}

function omFetchCierreByPeriodo(PDO $conn, string $periodoFacturacion): ?array
{
    $stmt = $conn->prepare(
        'SELECT TOP 1 id_cierre_mensual, estado_cierre, observaciones
         FROM dbo.msp_cierre_mensual
         WHERE periodo_facturacion = :periodo'
    );
    $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function omFetchCierreById(PDO $conn, int $idCierre): ?array
{
    $stmt = $conn->prepare(
        'SELECT TOP 1 id_cierre_mensual, periodo_facturacion, estado_cierre, observaciones
         FROM dbo.msp_cierre_mensual
         WHERE id_cierre_mensual = :id_cierre'
    );
    $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function omRequirePeriodoBorradorForMutation(PDO $conn, string $periodoFacturacion, bool $allowCalculado = false): array
{
    $cierre = omFetchCierreByPeriodo($conn, $periodoFacturacion);
    if (!is_array($cierre)) {
        throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
    }

    $estado = (int) ($cierre['estado_cierre'] ?? 0);
    if ($estado === 1 || ($allowCalculado && $estado === 2)) {
        return $cierre;
    }
    if ($estado === 3) {
        throw new RuntimeException('El período está cerrado. Reábrelo a Borrador para recalcular.');
    }
    if ($estado === 4) {
        throw new RuntimeException('El período está anulado y no admite recalculo/corrección.');
    }
    if ($estado === CierreMensualService::REVISADO) {
        throw new RuntimeException('El período está revisado. Devuélvelo a Borrador e indica el motivo para corregirlo.');
    }

    throw new RuntimeException('El período está en estado Calculado. Reábrelo a Borrador para recalcular.');
}

function omMarkCierreCalculadoIfBorrador(PDO $conn, int $idCierre): void
{
    if ($idCierre <= 0) {
        return;
    }

    $row = omFetchCierreById($conn, $idCierre);
    if (!is_array($row)) {
        return;
    }

    $estadoActual = (int) ($row['estado_cierre'] ?? 0);
    if ($estadoActual !== 1) {
        return;
    }

    (new CierreMensualService($conn))->transicionar(
        $idCierre,
        CierreMensualService::BORRADOR,
        CierreMensualService::CALCULADO,
        'Cálculo de operación mensual completado',
        isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null
    );
}

function omSwitchCierreToBorradorIfCalculado(PDO $conn, int $idCierre, string $motivo = ''): bool
{
    if ($idCierre <= 0) {
        return false;
    }

    $row = omFetchCierreById($conn, $idCierre);
    if (!is_array($row)) {
        return false;
    }

    $estadoActual = (int) ($row['estado_cierre'] ?? 0);
    if ($estadoActual !== 2) {
        return false;
    }

    (new CierreMensualService($conn))->transicionar(
        $idCierre,
        CierreMensualService::CALCULADO,
        CierreMensualService::BORRADOR,
        trim('Reapertura temporal para generación. ' . $motivo),
        isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null
    );

    return true;
}

function omRestoreCalculadoIfWasTemporal(PDO $conn, int $idCierre, bool $wasTemporal): void
{
    if (!$wasTemporal || $idCierre <= 0) {
        return;
    }

    $row = omFetchCierreById($conn, $idCierre);
    if (!is_array($row)) {
        return;
    }

    $estadoActual = (int) ($row['estado_cierre'] ?? 0);
    if ($estadoActual !== 1) {
        return;
    }

    (new CierreMensualService($conn))->transicionar(
        $idCierre,
        CierreMensualService::BORRADOR,
        CierreMensualService::CALCULADO,
        'Cálculo restaurado después de generación temporal',
        isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null
    );
}

function omMesNombreEs(string $periodoYm): string
{
    $normalized = omNormalizePeriodoYm($periodoYm);
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $normalized) !== 1) {
        return '';
    }

    $monthNames = [
        '01' => 'enero',
        '02' => 'febrero',
        '03' => 'marzo',
        '04' => 'abril',
        '05' => 'mayo',
        '06' => 'junio',
        '07' => 'julio',
        '08' => 'agosto',
        '09' => 'septiembre',
        '10' => 'octubre',
        '11' => 'noviembre',
        '12' => 'diciembre',
    ];

    $month = substr($normalized, 5, 2);
    return $monthNames[$month] ?? '';
}

function omServiceStepBaseTitle(string $codigoServicio): string
{
    $codigo = strtoupper(trim($codigoServicio));

    return match ($codigo) {
        'LUZ' => 'Luz eléctrica',
        'GAS' => 'Gas',
        'AGUA' => 'Agua',
        default => $codigo !== '' ? $codigo : 'Servicio',
    };
}

function omServiceStepPeriodoConsumoYm(string $codigoServicio, string $periodoFacturacion): string
{
    if ($periodoFacturacion === '') {
        return '';
    }

    $codigo = strtoupper(trim($codigoServicio));
    if ($codigo === 'AGUA') {
        $aguaInfo = omAguaPeriodoConsumo($periodoFacturacion);
        return is_array($aguaInfo) ? (string) ($aguaInfo['periodo_ym'] ?? '') : '';
    }

    $window = omServiceMeasurementWindow($codigo, $periodoFacturacion);
    return is_array($window) ? (string) ($window['periodo_ym'] ?? '') : '';
}

function omServiceStepUi(string $codigoServicio, string $periodoFacturacion): array
{
    $codigo = strtoupper(trim($codigoServicio));
    $index = match ($codigo) {
        'LUZ' => 3,
        'GAS' => 4,
        'AGUA' => 5,
        default => 3,
    };
    $anchor = match ($codigo) {
        'LUZ' => 'servicio-luz',
        'GAS' => 'servicio-gas',
        'AGUA' => 'servicio-agua',
        default => omServiceAnchor($codigo),
    };

    $baseTitle = omServiceStepBaseTitle($codigo);
    $periodoConsumoYm = omServiceStepPeriodoConsumoYm($codigo, $periodoFacturacion);
    $mesConsumo = omMesNombreEs($periodoConsumoYm);
    $title = $mesConsumo !== '' ? ($baseTitle . ' mes de ' . $mesConsumo) : $baseTitle;
    $subtitle = $mesConsumo !== '' ? ('Consumo de ' . $mesConsumo) : 'Consumo del servicio';

    return [
        'code' => $codigo,
        'index' => $index,
        'anchor' => $anchor,
        'title' => $title,
        'subtitle' => $subtitle,
        'periodo_ym' => $periodoConsumoYm,
        'mes' => $mesConsumo,
    ];
}

function omArchiveValeCobroForDocumentIds(PDO $conn, array $documentIds): array
{
    $items = [];
    foreach ($documentIds as $documentId) {
        $idDocumentoCobro = (int) $documentId;
        if ($idDocumentoCobro <= 0) {
            continue;
        }
        $items[] = [
            'module' => 'DOCUMENTO_COBRO',
            'type' => 'vale_cobro',
            'id_pago' => 0,
            'id_documento_cobro' => $idDocumentoCobro,
            'source_id_documento_cobro' => $idDocumentoCobro,
        ];
    }

    if ($items === []) {
        return [
            'saved' => [],
            'errors' => [],
        ];
    }

    return msp2ArchivosPdfRegisterValeCobroMetadataIds($conn, $documentIds);
}

function omManualAdjustmentWindow(string $periodoFacturacion): ?array
{
    $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
        return null;
    }

    $prevMonth = $periodoDate->modify('first day of previous month');
    if ($prevMonth === false) {
        return null;
    }

    $minDate = $prevMonth->format('Y-m-01');
    $maxDate = $prevMonth->modify('last day of this month')->format('Y-m-d');

    return [
        'min' => $minDate,
        'max' => $maxDate,
        'default' => $maxDate,
    ];
}

function omFetchCompletionHintByStage(PDO $conn, string $periodoFacturacion, string $etapa): array
{
    $base = [
        'tiendas' => 0,
        'arrendatarios' => 0,
    ];
    $etapaNorm = strtoupper(trim($etapa));
    if (!in_array($etapaNorm, ['LUZ', 'GAS', 'AGUA'], true)) {
        return $base;
    }

    $requiredTables = [
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_medidores',
        'msp_tipos_servicio',
        'msp_lecturas_medidores',
        'msp_procesos_cobro_servicio',
        'msp_cierre_mensual',
        'msp_tiendas',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            return $base;
        }
    }

    $filtroEtapa = match ($etapaNorm) {
        'LUZ' => "spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 0",
        'GAS' => "spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 0",
        'AGUA' => "(spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 1) OR (spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 1)",
        default => '1 = 0',
    };
    $filtroLecturas = match ($etapaNorm) {
        'LUZ' => 'lpt.luz_ok = 1',
        'GAS' => 'lpt.luz_ok = 1 AND lpt.gas_ok = 1',
        'AGUA' => 'lpt.luz_ok = 1 AND lpt.agua_ok = 1 AND (tobj.has_gas = 0 OR lpt.gas_ok = 1)',
        default => '1 = 0',
    };

    try {
        $stmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             ;WITH servicios_por_tienda AS (
                SELECT
                    ca.id_tienda,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS has_luz,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS has_gas,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS has_agua
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_medidores m
                    ON m.id_local = cl.id_local
                   AND m.estado_medidor = 1
                   AND m.fecha_retiro IS NULL
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = m.id_tipo_servicio
                WHERE cl.estado_relacion = 1
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
                GROUP BY ca.id_tienda
             ),
             tiendas_objetivo AS (
                SELECT
                    spt.id_tienda,
                    spt.has_gas
                FROM servicios_por_tienda spt
                WHERE $filtroEtapa
             ),
             lecturas_por_tienda AS (
                SELECT
                    ca.id_tienda,
                    MAX(CASE WHEN UPPER(tsp.codigo_servicio) = N'LUZ' AND lm.lectura_actual IS NOT NULL THEN 1 ELSE 0 END) AS luz_ok,
                    MAX(CASE WHEN UPPER(tsp.codigo_servicio) = N'GAS' AND lm.lectura_actual IS NOT NULL THEN 1 ELSE 0 END) AS gas_ok,
                    MAX(CASE WHEN UPPER(tsp.codigo_servicio) = N'AGUA' AND lm.lectura_actual IS NOT NULL THEN 1 ELSE 0 END) AS agua_ok
                FROM dbo.msp_lecturas_medidores lm
                INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                   AND p.estado_proceso <> 4
                INNER JOIN dbo.msp_cierre_mensual c
                    ON c.id_cierre_mensual = p.id_cierre_mensual
                INNER JOIN dbo.msp_tipos_servicio tsp
                    ON tsp.id_tipo_servicio = p.id_tipo_servicio
                INNER JOIN dbo.msp_medidores m
                    ON m.id_medidor = lm.id_medidor
                   AND m.estado_medidor = 1
                   AND m.fecha_retiro IS NULL
                INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_local = m.id_local
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                WHERE c.periodo_facturacion = @periodo
                  AND lm.lectura_actual IS NOT NULL
                  AND cl.estado_relacion = 1
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
                GROUP BY ca.id_tienda
             ),
             tiendas_completas AS (
                SELECT tobj.id_tienda
                FROM tiendas_objetivo tobj
                INNER JOIN lecturas_por_tienda lpt
                    ON lpt.id_tienda = tobj.id_tienda
                WHERE $filtroLecturas
             )
             SELECT
                COUNT(*) AS tiendas,
                COUNT(DISTINCT t.id_arrendatario) AS arrendatarios
             FROM tiendas_completas tc
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = tc.id_tienda;"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return $base;
        }

        $base['tiendas'] = max(0, (int) ($row['tiendas'] ?? 0));
        $base['arrendatarios'] = max(0, (int) ($row['arrendatarios'] ?? 0));
    } catch (Throwable $e) {
        return $base;
    }

    return $base;
}

function omBuildCompletionHintMetaForServicio(
    PDO $conn,
    string $codigoServicio,
    string $periodoYm,
    string $periodoFacturacion
): array {
    $etapaCompletitud = strtoupper(trim($codigoServicio));
    if (!in_array($etapaCompletitud, ['LUZ', 'GAS', 'AGUA'], true)) {
        return [];
    }

    $summary = omFetchCompletionHintByStage($conn, $periodoFacturacion, $etapaCompletitud);
    $tiendas = (int) ($summary['tiendas'] ?? 0);
    if ($tiendas <= 0) {
        return [];
    }

    return [
        'completion_hint' => [
            'servicio' => $etapaCompletitud,
            'periodo' => $periodoYm,
            'tiendas' => $tiendas,
            'arrendatarios' => max(0, (int) ($summary['arrendatarios'] ?? 0)),
        ],
    ];
}

function omBuildSaldoFavorSuggestions(PDO $conn, string $periodoFacturacion, ?array $allowedDocumentoIds = null): array
{
    $base = [
        'disponible' => false,
        'sugerencias' => [],
        'por_tienda' => [],
        'total_sugerido' => 0.0,
        'docs_sugeridos' => 0,
        'tiendas_sugeridas' => 0,
    ];

    $required = ['msp_documentos_cobro', 'msp_tiendas'];
    foreach ($required as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            return $base;
        }
    }

    $base['disponible'] = true;
    $usePeriodoItems = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');
    if (!$usePeriodoItems && !msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
        $base['disponible'] = false;
        return $base;
    }

    $stmtDocs = $conn->prepare(
        "SELECT
            dc.id_documento_cobro,
            dc.id_tienda,
            COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
            dc.fecha_vencimiento,
            dc.saldo_pendiente,
            COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda
         FROM dbo.msp_documentos_cobro dc
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         WHERE dc.periodo_facturacion = :periodo
           AND dc.estado_documento <> 5
           AND dc.saldo_pendiente > 0
         ORDER BY dc.id_tienda ASC, dc.fecha_vencimiento ASC, dc.id_documento_cobro ASC"
    );
    $stmtDocs->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmtDocs->execute();
    $docsRows = $stmtDocs->fetchAll() ?: [];
    if (is_array($allowedDocumentoIds)) {
        $allowedMap = [];
        foreach ($allowedDocumentoIds as $idDoc) {
            $idDocInt = (int) $idDoc;
            if ($idDocInt > 0) {
                $allowedMap[$idDocInt] = true;
            }
        }
        if ($allowedMap === []) {
            $docsRows = [];
        } else {
            $docsRows = array_values(array_filter(
                $docsRows,
                static function (array $row) use ($allowedMap): bool {
                    $idDocumento = (int) ($row['id_documento_cobro'] ?? 0);
                    return $idDocumento > 0 && isset($allowedMap[$idDocumento]);
                }
            ));
        }
    }
    if ($docsRows === []) {
        return $base;
    }

    $tiendaIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['id_tienda'] ?? 0),
        $docsRows
    ), static fn(int $id): bool => $id > 0)));
    if ($tiendaIds === []) {
        return $base;
    }

    $saldoByTienda = [];
    $placeholders = [];
    foreach ($tiendaIds as $index => $idTienda) {
        $placeholders[] = ':tid_' . $index;
    }
    $loadLegacySaldoByTienda = static function () use ($conn, $tiendaIds, $placeholders): array {
        if (!msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
            return [];
        }
        $legacy = [];
        $stmtSaldos = $conn->prepare(
            "SELECT id_tienda, saldo_disponible
             FROM dbo.msp_saldos_favor_tienda
             WHERE id_tienda IN (" . implode(', ', $placeholders) . ")
               AND saldo_disponible > 0"
        );
        foreach ($tiendaIds as $index => $idTienda) {
            $stmtSaldos->bindValue(':tid_' . $index, $idTienda, PDO::PARAM_INT);
        }
        $stmtSaldos->execute();
        while (($saldoRow = $stmtSaldos->fetch()) !== false) {
            $idTienda = (int) ($saldoRow['id_tienda'] ?? 0);
            if ($idTienda <= 0) {
                continue;
            }
            $legacy[$idTienda] = round((float) ($saldoRow['saldo_disponible'] ?? 0), 2);
        }
        return $legacy;
    };
    $hasPeriodoAplicaciones = $usePeriodoItems && msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');

    if ($hasPeriodoAplicaciones) {
        $stmtItemsPeriodo = $conn->prepare(
            "SELECT
                sfpi.id_saldo_favor_periodo_item,
                sfpi.id_tienda,
                sfpi.fecha_movimiento,
                ROUND(
                    sfpi.monto_original
                    - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                    2
                ) AS monto_disponible_item
             FROM dbo.msp_saldo_favor_periodo_items sfpi
             LEFT JOIN dbo.msp_saldo_favor_periodo_aplicaciones sfa
                ON sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
             WHERE sfpi.periodo_facturacion = :periodo
               AND sfpi.estado_item = 1
               AND sfpi.id_tienda IN (" . implode(', ', $placeholders) . ")
             GROUP BY
                sfpi.id_saldo_favor_periodo_item,
                sfpi.id_tienda,
                sfpi.fecha_movimiento,
                sfpi.monto_original
             HAVING ROUND(
                sfpi.monto_original
                - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                2
             ) > 0
             ORDER BY sfpi.id_tienda ASC, sfpi.fecha_movimiento ASC, sfpi.id_saldo_favor_periodo_item ASC"
        );
        $stmtItemsPeriodo->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        foreach ($tiendaIds as $index => $idTienda) {
            $stmtItemsPeriodo->bindValue(':tid_' . $index, $idTienda, PDO::PARAM_INT);
        }
        $stmtItemsPeriodo->execute();
        $itemsRows = $stmtItemsPeriodo->fetchAll() ?: [];

        if ($itemsRows !== []) {
            $globalSaldoByTienda = $loadLegacySaldoByTienda();
            $itemsByTienda = [];
            $saldoItemsByTienda = [];
            foreach ($itemsRows as $itemRow) {
                $idTienda = (int) ($itemRow['id_tienda'] ?? 0);
                $idItem = (int) ($itemRow['id_saldo_favor_periodo_item'] ?? 0);
                $montoDisponibleItem = round((float) ($itemRow['monto_disponible_item'] ?? 0), 2);
                if ($idTienda <= 0 || $idItem <= 0 || $montoDisponibleItem <= 0.005) {
                    continue;
                }
                if (!isset($itemsByTienda[$idTienda])) {
                    $itemsByTienda[$idTienda] = [];
                }
                $itemsByTienda[$idTienda][] = [
                    'id_saldo_favor_periodo_item' => $idItem,
                    'monto_disponible' => $montoDisponibleItem,
                ];
                $saldoItemsByTienda[$idTienda] = round((float) ($saldoItemsByTienda[$idTienda] ?? 0) + $montoDisponibleItem, 2);
            }

            if ($itemsByTienda !== []) {
                $saldoCapByTienda = [];
                foreach ($saldoItemsByTienda as $idTienda => $montoItems) {
                    if (isset($globalSaldoByTienda[$idTienda])) {
                        $saldoCapByTienda[$idTienda] = round(min((float) $montoItems, (float) $globalSaldoByTienda[$idTienda]), 2);
                    } else {
                        $saldoCapByTienda[$idTienda] = round((float) $montoItems, 2);
                    }
                }

                $tiendaInicial = $saldoCapByTienda;
                $itemIndexByTienda = [];
                $sugerencias = [];
                foreach ($docsRows as $docRow) {
                    $idTienda = (int) ($docRow['id_tienda'] ?? 0);
                    if ($idTienda <= 0 || !isset($itemsByTienda[$idTienda])) {
                        continue;
                    }
                    $saldoDocRestante = round((float) ($docRow['saldo_pendiente'] ?? 0), 2);
                    if ($saldoDocRestante <= 0.005) {
                        continue;
                    }

                    $tiendaCap = round((float) ($saldoCapByTienda[$idTienda] ?? 0), 2);
                    if ($tiendaCap <= 0.005) {
                        continue;
                    }

                    $index = (int) ($itemIndexByTienda[$idTienda] ?? 0);
                    $itemsTienda = &$itemsByTienda[$idTienda];
                    while ($saldoDocRestante > 0.005 && $tiendaCap > 0.005) {
                        if (!isset($itemsTienda[$index])) {
                            break;
                        }
                        $itemData = $itemsTienda[$index];
                        $montoItemDisponible = round((float) ($itemData['monto_disponible'] ?? 0), 2);
                        if ($montoItemDisponible <= 0.005) {
                            $index++;
                            continue;
                        }

                        $montoAplicar = round(min($saldoDocRestante, $montoItemDisponible, $tiendaCap), 2);
                        if ($montoAplicar <= 0.005) {
                            break;
                        }

                        $sugerencias[] = [
                            'id_documento_cobro' => (int) ($docRow['id_documento_cobro'] ?? 0),
                            'id_tienda' => $idTienda,
                            'nombre_tienda' => (string) ($docRow['nombre_tienda'] ?? ''),
                            'numero_documento' => (string) ($docRow['numero_documento'] ?? ''),
                            'fecha_vencimiento' => (string) ($docRow['fecha_vencimiento'] ?? ''),
                            'saldo_documento' => round((float) ($docRow['saldo_pendiente'] ?? 0), 2),
                            'monto_aplicar' => $montoAplicar,
                            'id_saldo_favor_periodo_item' => (int) ($itemData['id_saldo_favor_periodo_item'] ?? 0),
                        ];

                        $saldoDocRestante = round($saldoDocRestante - $montoAplicar, 2);
                        $tiendaCap = round($tiendaCap - $montoAplicar, 2);
                        $montoItemDisponible = round($montoItemDisponible - $montoAplicar, 2);
                        $itemsTienda[$index]['monto_disponible'] = $montoItemDisponible;
                        if ($montoItemDisponible <= 0.005) {
                            $index++;
                        }
                    }
                    unset($itemsTienda);
                    $itemIndexByTienda[$idTienda] = $index;
                    $saldoCapByTienda[$idTienda] = round(max(0.0, $tiendaCap), 2);
                }

                if ($sugerencias !== []) {
                    $porTienda = [];
                    $docsByTienda = [];
                    $total = 0.0;
                    foreach ($sugerencias as $row) {
                        $idTienda = (int) ($row['id_tienda'] ?? 0);
                        $idDocumento = (int) ($row['id_documento_cobro'] ?? 0);
                        if (!isset($porTienda[$idTienda])) {
                            $porTienda[$idTienda] = [
                                'id_tienda' => $idTienda,
                                'nombre_tienda' => (string) ($row['nombre_tienda'] ?? ''),
                                'saldo_inicial' => (float) ($tiendaInicial[$idTienda] ?? 0),
                                'saldo_final' => (float) ($saldoCapByTienda[$idTienda] ?? 0),
                                'monto_aplicar' => 0.0,
                                'docs' => 0,
                            ];
                            $docsByTienda[$idTienda] = [];
                        }
                        $porTienda[$idTienda]['monto_aplicar'] = round((float) $porTienda[$idTienda]['monto_aplicar'] + (float) ($row['monto_aplicar'] ?? 0), 2);
                        if ($idDocumento > 0 && !isset($docsByTienda[$idTienda][$idDocumento])) {
                            $docsByTienda[$idTienda][$idDocumento] = true;
                            $porTienda[$idTienda]['docs']++;
                        }
                        $total += (float) ($row['monto_aplicar'] ?? 0);
                    }

                    $base['sugerencias'] = $sugerencias;
                    $base['por_tienda'] = array_values($porTienda);
                    $base['total_sugerido'] = round($total, 2);
                    $base['docs_sugeridos'] = count(array_unique(array_map(
                        static fn(array $s): int => (int) ($s['id_documento_cobro'] ?? 0),
                        $sugerencias
                    )));
                    $base['tiendas_sugeridas'] = count($porTienda);
                    return $base;
                }
            }
        }

        // Con trazabilidad por aplicaciones habilitada, no volvemos al cálculo legacy por tienda.
        return $base;
    }

    if ($usePeriodoItems) {
        $stmtPeriodoItems = $conn->prepare(
            "SELECT
                sfpi.id_tienda,
                ROUND(SUM(sfpi.monto_original), 2) AS total_periodo
             FROM dbo.msp_saldo_favor_periodo_items sfpi
             WHERE sfpi.periodo_facturacion = :periodo
               AND sfpi.estado_item = 1
               AND sfpi.id_tienda IN (" . implode(', ', $placeholders) . ")
             GROUP BY sfpi.id_tienda"
        );
        $stmtPeriodoItems->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        foreach ($tiendaIds as $index => $idTienda) {
            $stmtPeriodoItems->bindValue(':tid_' . $index, $idTienda, PDO::PARAM_INT);
        }
        $stmtPeriodoItems->execute();

        $periodTotalByTienda = [];
        while (($periodRow = $stmtPeriodoItems->fetch()) !== false) {
            $idTienda = (int) ($periodRow['id_tienda'] ?? 0);
            if ($idTienda <= 0) {
                continue;
            }
            $periodTotalByTienda[$idTienda] = round((float) ($periodRow['total_periodo'] ?? 0), 2);
        }
        if ($periodTotalByTienda === []) {
            $saldoByTienda = $loadLegacySaldoByTienda();
        } else {
            $aplicadoByTienda = [];
            if (
                msp2TableExists($conn, 'msp_pagos')
                && msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor')
            ) {
                $montoPagoExpr = msp2ColumnExists($conn, 'msp_pagos', 'monto_pagado')
                    ? 'p.monto_pagado'
                    : (msp2ColumnExists($conn, 'msp_pagos', 'monto_pago') ? 'p.monto_pago' : '0');
                $montoAplicadoExpr = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado')
                    ? 'CASE
                            WHEN ISNULL(p.monto_saldo_favor_generado, 0) > 0 THEN p.monto_saldo_favor_generado
                            ELSE ' . $montoPagoExpr . '
                       END'
                    : $montoPagoExpr;
                $estadoPagoFilter = msp2ColumnExists($conn, 'msp_pagos', 'estado_pago')
                    ? ' AND p.estado_pago = 1'
                    : '';

                $stmtAplicado = $conn->prepare(
                    "SELECT
                        dc.id_tienda,
                        ROUND(SUM(" . $montoAplicadoExpr . "), 2) AS total_aplicado
                     FROM dbo.msp_pagos p
                     INNER JOIN dbo.msp_documentos_cobro dc
                        ON dc.id_documento_cobro = p.id_documento_cobro
                     WHERE dc.periodo_facturacion = :periodo
                       AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1"
                       . $estadoPagoFilter . "
                       AND dc.id_tienda IN (" . implode(', ', $placeholders) . ")
                     GROUP BY dc.id_tienda"
                );
                $stmtAplicado->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                foreach ($tiendaIds as $index => $idTienda) {
                    $stmtAplicado->bindValue(':tid_' . $index, $idTienda, PDO::PARAM_INT);
                }
                $stmtAplicado->execute();
                while (($apRow = $stmtAplicado->fetch()) !== false) {
                    $idTienda = (int) ($apRow['id_tienda'] ?? 0);
                    if ($idTienda <= 0) {
                        continue;
                    }
                    $aplicadoByTienda[$idTienda] = round((float) ($apRow['total_aplicado'] ?? 0), 2);
                }
            }

            $saldoGlobalByTienda = [];
            if (msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
                $stmtGlobal = $conn->prepare(
                    "SELECT id_tienda, saldo_disponible
                     FROM dbo.msp_saldos_favor_tienda
                     WHERE id_tienda IN (" . implode(', ', $placeholders) . ")
                       AND saldo_disponible > 0"
                );
                foreach ($tiendaIds as $index => $idTienda) {
                    $stmtGlobal->bindValue(':tid_' . $index, $idTienda, PDO::PARAM_INT);
                }
                $stmtGlobal->execute();
                while (($globalRow = $stmtGlobal->fetch()) !== false) {
                    $idTienda = (int) ($globalRow['id_tienda'] ?? 0);
                    if ($idTienda <= 0) {
                        continue;
                    }
                    $saldoGlobalByTienda[$idTienda] = round((float) ($globalRow['saldo_disponible'] ?? 0), 2);
                }
            }

            foreach ($periodTotalByTienda as $idTienda => $montoPeriodo) {
                $montoAplicado = round((float) ($aplicadoByTienda[$idTienda] ?? 0), 2);
                $disponiblePeriodo = round(max(0.0, $montoPeriodo - $montoAplicado), 2);
                if (isset($saldoGlobalByTienda[$idTienda])) {
                    $disponiblePeriodo = round(min($disponiblePeriodo, (float) $saldoGlobalByTienda[$idTienda]), 2);
                }
                if ($disponiblePeriodo <= 0.005) {
                    continue;
                }
                $saldoByTienda[$idTienda] = $disponiblePeriodo;
            }
        }
    } else {
        $saldoByTienda = $loadLegacySaldoByTienda();
    }
    if ($saldoByTienda === []) {
        return $base;
    }

    $tiendaInicial = $saldoByTienda;
    $sugerencias = [];

    foreach ($docsRows as $docRow) {
        $idTienda = (int) ($docRow['id_tienda'] ?? 0);
        if ($idTienda <= 0) {
            continue;
        }
        $saldoDisponible = (float) ($saldoByTienda[$idTienda] ?? 0.0);
        if ($saldoDisponible <= 0.005) {
            continue;
        }
        $saldoDoc = round((float) ($docRow['saldo_pendiente'] ?? 0), 2);
        if ($saldoDoc <= 0.005) {
            continue;
        }
        $montoAplicar = round(min($saldoDisponible, $saldoDoc), 2);
        if ($montoAplicar <= 0.005) {
            continue;
        }

        $sugerencias[] = [
            'id_documento_cobro' => (int) ($docRow['id_documento_cobro'] ?? 0),
            'id_tienda' => $idTienda,
            'nombre_tienda' => (string) ($docRow['nombre_tienda'] ?? ''),
            'numero_documento' => (string) ($docRow['numero_documento'] ?? ''),
            'fecha_vencimiento' => (string) ($docRow['fecha_vencimiento'] ?? ''),
            'saldo_documento' => $saldoDoc,
            'monto_aplicar' => $montoAplicar,
        ];

        $saldoByTienda[$idTienda] = round($saldoDisponible - $montoAplicar, 2);
    }

    if ($sugerencias === []) {
        return $base;
    }

    $porTienda = [];
    $total = 0.0;
    foreach ($sugerencias as $row) {
        $idTienda = (int) ($row['id_tienda'] ?? 0);
        if (!isset($porTienda[$idTienda])) {
            $porTienda[$idTienda] = [
                'id_tienda' => $idTienda,
                'nombre_tienda' => (string) ($row['nombre_tienda'] ?? ''),
                'saldo_inicial' => (float) ($tiendaInicial[$idTienda] ?? 0),
                'saldo_final' => (float) ($saldoByTienda[$idTienda] ?? 0),
                'monto_aplicar' => 0.0,
                'docs' => 0,
            ];
        }
        $porTienda[$idTienda]['monto_aplicar'] = round((float) $porTienda[$idTienda]['monto_aplicar'] + (float) ($row['monto_aplicar'] ?? 0), 2);
        $porTienda[$idTienda]['docs']++;
        $total += (float) ($row['monto_aplicar'] ?? 0);
    }

    $base['sugerencias'] = $sugerencias;
    $base['por_tienda'] = array_values($porTienda);
    $base['total_sugerido'] = round($total, 2);
    $base['docs_sugeridos'] = count(array_unique(array_map(
        static fn(array $s): int => (int) ($s['id_documento_cobro'] ?? 0),
        $sugerencias
    )));
    $base['tiendas_sugeridas'] = count($porTienda);

    return $base;
}

function omFetchFirstScalar(PDOStatement $stmt): mixed
{
    $firstValue = false;
    try {
        while (true) {
            $columnCount = 0;
            try {
                $columnCount = $stmt->columnCount();
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
            }

            if ($columnCount > 0) {
                while (true) {
                    try {
                        $value = $stmt->fetchColumn();
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'contains no fields')) {
                            break;
                        }
                        throw $exception;
                    }
                    if ($value === false) {
                        break;
                    }
                    if ($firstValue === false) {
                        $firstValue = $value;
                    }
                }
            }

            try {
                if (!$stmt->nextRowset()) {
                    break;
                }
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
                break;
            }
        }
    } finally {
        try {
            $stmt->closeCursor();
        } catch (Throwable) {
            // El cursor puede quedar ya cerrado si el driver descarta rowsets intermedios.
        }
    }
    return $firstValue;
}

function omFetchFirstAssoc(PDOStatement $stmt): ?array
{
    $firstRow = null;
    try {
        while (true) {
            $columnCount = 0;
            try {
                $columnCount = $stmt->columnCount();
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
            }

            if ($columnCount > 0) {
                while (true) {
                    try {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'contains no fields')) {
                            break;
                        }
                        throw $exception;
                    }
                    if ($row === false) {
                        break;
                    }
                    if ($firstRow === null && is_array($row)) {
                        $firstRow = $row;
                    }
                }
            }

            try {
                if (!$stmt->nextRowset()) {
                    break;
                }
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
                break;
            }
        }
    } finally {
        try {
            $stmt->closeCursor();
        } catch (Throwable) {
            // El cursor puede quedar ya cerrado si el driver descarta rowsets intermedios.
        }
    }
    return $firstRow;
}

function omApplySaldoFavorPeriodoAuto(
    PDO $conn,
    string $periodoYm,
    string $periodoFacturacion,
    ?array $allowedDocumentoIds = null,
    ?int $idLoteEnvioOrigen = null
): array
{
    $periodoYmUi = omFmtPeriodoYm($periodoYm);
    $resultado = [
        'disponible' => false,
        'aplicados' => 0,
        'monto_aplicado' => 0.0,
        'aplicaciones' => [],
        'errores' => [],
        'estado' => 'info',
        'mensaje' => 'No hubo documentos elegibles para aplicar saldo a favor.',
    ];

    $preview = omBuildSaldoFavorSuggestions($conn, $periodoFacturacion, $allowedDocumentoIds);
    $resultado['disponible'] = (bool) ($preview['disponible'] ?? false);
    if (!$resultado['disponible']) {
        $resultado['estado'] = 'warning';
        $resultado['mensaje'] = 'El módulo de saldo a favor no está disponible en este ambiente.';
        return $resultado;
    }

    $sugerencias = is_array($preview['sugerencias'] ?? null) ? $preview['sugerencias'] : [];
    if ($sugerencias === []) {
        $resultado['estado'] = 'info';
        $resultado['mensaje'] = 'No hay saldo a favor disponible para aplicar en el período.';
        return $resultado;
    }

    $fechaAplicacion = (new DateTimeImmutable('today'))->format('Y-m-d');
    $hasPeriodoAplicaciones = msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');
    $hasPeriodoAplicacionesLoteColumn = $hasPeriodoAplicaciones
        && msp2ColumnExists($conn, 'msp_saldo_favor_periodo_aplicaciones', 'id_lote_envio_origen');
    $aplicados = 0;
    $omitidosSinSaldo = 0;
    $documentosAplicadosMap = [];
    $montoAplicado = 0.0;
    $aplicaciones = [];
    $errores = [];

    $stmtAplicar = $conn->prepare(
        'EXEC dbo.msp_aplicar_saldo_favor_documento
            @id_documento_cobro = :id_documento_cobro,
            @fecha_pago = :fecha_pago,
            @monto_aplicar = :monto_aplicar,
            @observaciones = :observaciones'
    );
    $tiendasConSugerencia = [];
    foreach ($sugerencias as $rowSug) {
        $idTiendaSug = (int) ($rowSug['id_tienda'] ?? 0);
        if ($idTiendaSug > 0) {
            $tiendasConSugerencia[$idTiendaSug] = true;
        }
    }
    foreach (array_keys($tiendasConSugerencia) as $idTiendaSync) {
        omEnsureSaldoFavorGlobalForTienda($conn, $periodoFacturacion, (int) $idTiendaSync);
    }

    $stmtSaldoDisponibleDoc = null;
    if (msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
        $stmtSaldoDisponibleDoc = $conn->prepare(
            "SELECT
                ISNULL(sf.saldo_disponible, 0) AS saldo_disponible
             FROM dbo.msp_documentos_cobro dc
             LEFT JOIN dbo.msp_saldos_favor_tienda sf
                ON sf.id_tienda = dc.id_tienda
             WHERE dc.id_documento_cobro = :id_documento"
        );
    }

    foreach ($sugerencias as $sug) {
        $idDocumento = (int) ($sug['id_documento_cobro'] ?? 0);
        $montoSugerido = round((float) ($sug['monto_aplicar'] ?? 0), 2);
        $idSaldoFavorPeriodoItem = (int) ($sug['id_saldo_favor_periodo_item'] ?? 0);
        if ($idDocumento <= 0 || $montoSugerido <= 0.005) {
            continue;
        }

        try {
            $montoAplicarReal = $montoSugerido;
            if ($stmtSaldoDisponibleDoc instanceof PDOStatement) {
                $stmtSaldoDisponibleDoc->bindValue(':id_documento', $idDocumento, PDO::PARAM_INT);
                $stmtSaldoDisponibleDoc->execute();
                $saldoDispRow = $stmtSaldoDisponibleDoc->fetch() ?: [];
                $saldoDisponibleDoc = round((float) ($saldoDispRow['saldo_disponible'] ?? 0), 2);
                if ($saldoDisponibleDoc <= 0.005) {
                    $omitidosSinSaldo++;
                    continue;
                }
                $montoAplicarReal = round(min($montoSugerido, $saldoDisponibleDoc), 2);
                if ($montoAplicarReal <= 0.005) {
                    $omitidosSinSaldo++;
                    continue;
                }
            }

            if ($hasPeriodoAplicaciones) {
                $conn->beginTransaction();
            }
            $stmtAplicar->bindValue(':id_documento_cobro', $idDocumento, PDO::PARAM_INT);
            $stmtAplicar->bindValue(':fecha_pago', $fechaAplicacion, PDO::PARAM_STR);
            $stmtAplicar->bindValue(':monto_aplicar', (string) $montoAplicarReal, PDO::PARAM_STR);
            $stmtAplicar->bindValue(
                ':observaciones',
                'Aplicación automática desde operación mensual ' . $periodoYmUi,
                PDO::PARAM_STR
            );
            $stmtAplicar->execute();
            $resAplicar = omFetchFirstAssoc($stmtAplicar) ?? [];
            $montoReal = isset($resAplicar['monto_aplicado']) ? (float) $resAplicar['monto_aplicado'] : $montoSugerido;
            $idPagoGenerado = isset($resAplicar['id_pago_generado']) ? (int) $resAplicar['id_pago_generado'] : 0;

            if ($hasPeriodoAplicaciones && $montoReal > 0.005 && $idSaldoFavorPeriodoItem > 0) {
                $insertColumns = '
                            id_saldo_favor_periodo_item,
                            periodo_facturacion,
                            id_tienda,
                            id_documento_cobro,
                            id_pago,
                            fecha_aplicacion,
                            monto_aplicado,
                            estado_aplicacion,
                            observaciones';
                $insertValues = '
                            :id_item,
                            :periodo,
                            :id_tienda,
                            :id_documento,
                            :id_pago,
                            :fecha_aplicacion,
                            :monto_aplicado,
                            1,
                            :observaciones';
                if ($hasPeriodoAplicacionesLoteColumn) {
                    $insertColumns .= ',
                            id_lote_envio_origen';
                    $insertValues .= ',
                            :id_lote_envio_origen';
                }
                $insAplicacionStmt = $conn->prepare(
                    'INSERT INTO dbo.msp_saldo_favor_periodo_aplicaciones
                        (
' . $insertColumns . '
                        )
                     VALUES
                        (
' . $insertValues . '
                        )'
                );
                $insAplicacionStmt->bindValue(':id_item', $idSaldoFavorPeriodoItem, PDO::PARAM_INT);
                $insAplicacionStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insAplicacionStmt->bindValue(':id_tienda', (int) ($sug['id_tienda'] ?? 0), PDO::PARAM_INT);
                $insAplicacionStmt->bindValue(':id_documento', $idDocumento, PDO::PARAM_INT);
                if ($idPagoGenerado > 0) {
                    $insAplicacionStmt->bindValue(':id_pago', $idPagoGenerado, PDO::PARAM_INT);
                } else {
                    $insAplicacionStmt->bindValue(':id_pago', null, PDO::PARAM_NULL);
                }
                $insAplicacionStmt->bindValue(':fecha_aplicacion', $fechaAplicacion, PDO::PARAM_STR);
                $insAplicacionStmt->bindValue(':monto_aplicado', (string) round($montoReal, 2), PDO::PARAM_STR);
                $insAplicacionStmt->bindValue(
                    ':observaciones',
                    'Aplicación automática desde operación mensual ' . $periodoYmUi,
                    PDO::PARAM_STR
                );
                if ($hasPeriodoAplicacionesLoteColumn) {
                    if (($idLoteEnvioOrigen ?? 0) > 0) {
                        $insAplicacionStmt->bindValue(':id_lote_envio_origen', (int) $idLoteEnvioOrigen, PDO::PARAM_INT);
                    } else {
                        $insAplicacionStmt->bindValue(':id_lote_envio_origen', null, PDO::PARAM_NULL);
                    }
                }
                $insAplicacionStmt->execute();
            }

            if ($hasPeriodoAplicaciones && $conn->inTransaction()) {
                $conn->commit();
            }

            if ($montoReal > 0.005) {
                $aplicados++;
                $documentosAplicadosMap[$idDocumento] = true;
                $montoAplicado = round($montoAplicado + $montoReal, 2);
                $aplicaciones[] = [
                    'id_documento_cobro' => $idDocumento,
                    'nombre_tienda' => (string) ($sug['nombre_tienda'] ?? ''),
                    'numero_documento' => (string) ($sug['numero_documento'] ?? ''),
                    'fecha_vencimiento' => (string) ($sug['fecha_vencimiento'] ?? ''),
                    'saldo_documento' => round((float) ($sug['saldo_documento'] ?? 0), 2),
                    'monto_sugerido' => $montoSugerido,
                    'monto_aplicado' => round($montoReal, 2),
                    'id_pago_generado' => $idPagoGenerado,
                    'id_saldo_favor_periodo_item' => $idSaldoFavorPeriodoItem > 0 ? $idSaldoFavorPeriodoItem : null,
                    'id_lote_envio_origen' => ($idLoteEnvioOrigen ?? 0) > 0 ? (int) $idLoteEnvioOrigen : null,
                ];
            }
        } catch (Throwable $itemEx) {
            if ($hasPeriodoAplicaciones && $conn->inTransaction()) {
                $conn->rollBack();
            }
            $errorText = mb_strtolower((string) $itemEx->getMessage(), 'UTF-8');
            $esSinSaldoDisponible = (
                str_contains($errorText, 'la tienda no tiene saldo a favor disponible')
                || (str_contains($errorText, 'saldo a favor') && str_contains($errorText, 'no tiene'))
                || (str_contains($errorText, 'ck_msp_saldos_favor_tienda_saldo') && str_contains($errorText, 'saldo_disponible'))
                || (str_contains($errorText, 'merge') && str_contains($errorText, 'saldo_disponible'))
            );
            if ($esSinSaldoDisponible) {
                $omitidosSinSaldo++;
                continue;
            }
            $errores[] = '#' . $idDocumento . ': ' . $itemEx->getMessage();
        }
    }

    $resultado['aplicados'] = $aplicados;
    $resultado['omitidos_sin_saldo'] = $omitidosSinSaldo;
    $resultado['documentos_aplicados'] = count($documentosAplicadosMap);
    $resultado['monto_aplicado'] = round($montoAplicado, 2);
    $resultado['aplicaciones'] = $aplicaciones;
    $resultado['errores'] = $errores;

    if ($aplicados <= 0) {
        if ($errores !== []) {
            $resultado['estado'] = 'danger';
            $resultado['mensaje'] = 'No fue posible aplicar saldo a favor. ' . implode(' | ', array_slice($errores, 0, 2));
        } elseif ($omitidosSinSaldo > 0) {
            $resultado['estado'] = 'info';
            $resultado['mensaje'] =
                'No se aplicó saldo a favor automático. '
                . $omitidosSinSaldo
                . ' documento(s) no tenían saldo disponible al momento de aplicar.';
        } else {
            $resultado['estado'] = 'info';
            $resultado['mensaje'] = 'No hubo documentos elegibles para aplicar saldo a favor.';
        }
    } elseif ($errores !== []) {
        $resultado['estado'] = 'warning';
        $resultado['mensaje'] =
            'Saldo a favor aplicado parcialmente. Documentos: ' . count($documentosAplicadosMap)
            . ' | Aplicaciones: ' . $aplicados
            . ' | Monto: $ ' . number_format($montoAplicado, 2, ',', '.')
            . '. Algunos errores: ' . implode(' | ', array_slice($errores, 0, 2));
        if ($omitidosSinSaldo > 0) {
            $resultado['mensaje'] .= ' | Omitidos sin saldo: ' . $omitidosSinSaldo . '.';
        }
    } else {
        $resultado['estado'] = 'success';
        $resultado['mensaje'] =
            'Saldo a favor aplicado correctamente. Documentos: ' . count($documentosAplicadosMap)
            . ' | Aplicaciones: ' . $aplicados
            . ' | Monto: $ ' . number_format($montoAplicado, 2, ',', '.');
        if ($omitidosSinSaldo > 0) {
            $resultado['mensaje'] .= ' | Omitidos sin saldo: ' . $omitidosSinSaldo . '.';
        }
    }

    return $resultado;
}

function omEnsureSaldoFavorGlobalForTienda(PDO $conn, string $periodoFacturacion, int $idTienda): float
{
    if ($idTienda <= 0 || !msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
        return 0.0;
    }

    $hasMovimientos = msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda');
    if (!$hasMovimientos) {
        $stmtLegacy = $conn->prepare(
            "SELECT ROUND(ISNULL(sf.saldo_disponible, 0), 2) AS saldo_disponible
             FROM dbo.msp_saldos_favor_tienda sf
             WHERE sf.id_tienda = :id_tienda"
        );
        $stmtLegacy->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtLegacy->execute();
        $rowLegacy = $stmtLegacy->fetch() ?: [];
        return round(max(0.0, (float) ($rowLegacy['saldo_disponible'] ?? 0)), 2);
    }

    $fetchSaldoMovimientos = static function () use ($conn, $idTienda): float {
        $stmtMov = $conn->prepare(
            "SELECT ROUND(ISNULL(SUM(msf.monto_movimiento), 0), 2) AS saldo_mov
             FROM dbo.msp_movimientos_saldo_favor_tienda msf
             WHERE msf.id_tienda = :id_tienda"
        );
        $stmtMov->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtMov->execute();
        $rowMov = $stmtMov->fetch() ?: [];
        return round((float) ($rowMov['saldo_mov'] ?? 0), 2);
    };

    $saldoMovimientos = $fetchSaldoMovimientos();
    $saldoPeriodoPendiente = 0.0;
    if (msp2TableExists($conn, 'msp_saldo_favor_periodo_items')) {
        $hasAplicaciones = msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');
        if ($hasAplicaciones) {
            $stmtPeriodo = $conn->prepare(
                "SELECT
                    ROUND(
                        ISNULL(SUM(
                            sfpi.monto_original
                            - ISNULL(ap.total_aplicado, 0)
                        ), 0),
                        2
                    ) AS saldo_periodo
                 FROM dbo.msp_saldo_favor_periodo_items sfpi
                 OUTER APPLY (
                    SELECT SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END) AS total_aplicado
                    FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
                    WHERE sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
                 ) ap
                 WHERE sfpi.periodo_facturacion = :periodo
                   AND sfpi.id_tienda = :id_tienda
                   AND sfpi.estado_item = 1
                   AND (sfpi.monto_original - ISNULL(ap.total_aplicado, 0)) > 0"
            );
        } else {
            $stmtPeriodo = $conn->prepare(
                "SELECT
                    ROUND(ISNULL(SUM(sfpi.monto_original), 0), 2) AS saldo_periodo
                 FROM dbo.msp_saldo_favor_periodo_items sfpi
                 WHERE sfpi.periodo_facturacion = :periodo
                   AND sfpi.id_tienda = :id_tienda
                   AND sfpi.estado_item = 1
                   AND sfpi.monto_original > 0"
            );
        }
        $stmtPeriodo->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtPeriodo->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtPeriodo->execute();
        $rowPeriodo = $stmtPeriodo->fetch() ?: [];
        $saldoPeriodoPendiente = round((float) ($rowPeriodo['saldo_periodo'] ?? 0), 2);
    }

    // Si por alguna razon hay pendiente del periodo mayor al saldo de movimientos,
    // regulariza creando un movimiento positivo (en vez de forzar msp_saldos_favor_tienda directo).
    $deltaRegularizacion = round($saldoPeriodoPendiente - $saldoMovimientos, 2);
    if ($deltaRegularizacion > 0.005) {
        $stmtRegulariza = $conn->prepare(
            "INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
             VALUES
                (:id_tienda, :fecha_mov, 5, :monto, :obs)"
        );
        $stmtRegulariza->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtRegulariza->bindValue(':fecha_mov', (new DateTimeImmutable('today'))->format('Y-m-d'), PDO::PARAM_STR);
        $stmtRegulariza->bindValue(':monto', (string) $deltaRegularizacion, PDO::PARAM_STR);
        $stmtRegulariza->bindValue(
            ':obs',
            'Regularización automática saldo período ' . $periodoFacturacion,
            PDO::PARAM_STR
        );
        $stmtRegulariza->execute();
        $saldoMovimientos = $fetchSaldoMovimientos();
    }

    $saldoObjetivo = round(max(0.0, $saldoMovimientos), 2);
    $updStmt = $conn->prepare(
        "UPDATE dbo.msp_saldos_favor_tienda
         SET saldo_disponible = :saldo,
             fecha_actualizacion = SYSDATETIME()
         WHERE id_tienda = :id_tienda"
    );
    $updStmt->bindValue(':saldo', (string) $saldoObjetivo, PDO::PARAM_STR);
    $updStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $updStmt->execute();
    if ($updStmt->rowCount() <= 0) {
        $insStmt = $conn->prepare(
            "INSERT INTO dbo.msp_saldos_favor_tienda (id_tienda, saldo_disponible, fecha_actualizacion)
             VALUES (:id_tienda, :saldo, SYSDATETIME())"
        );
        $insStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $insStmt->bindValue(':saldo', (string) $saldoObjetivo, PDO::PARAM_STR);
        $insStmt->execute();
    }

    return $saldoObjetivo;
}

function omBuildExtraChargesPendingSnapshot(PDO $conn, string $periodoFacturacion, int $limitRows = 30): array
{
    $base = [
        'disponible' => false,
        'pendientes_count' => 0,
        'pendientes_total' => 0.0,
        'rows' => [],
    ];

    if (
        !msp2TableExists($conn, 'msp_cargos_salida')
        || !msp2TableExists($conn, 'msp_tipos_cargo_salida')
        || !msp2TableExists($conn, 'msp_contratos_arriendo')
    ) {
        return $base;
    }

    $base['disponible'] = true;

    $countStmt = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         SELECT
            COUNT(*) AS pendientes_count,
            ROUND(SUM(cs.monto_cargo), 2) AS pendientes_total
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
         WHERE cs.estado_cargo IN (1, 2)
           AND cs.id_documento_cobro IS NULL
           AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
           AND cs.monto_cargo > 0"
    );
    $countStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $countStmt->execute();
    $countRow = $countStmt->fetch() ?: [];
    $base['pendientes_count'] = (int) ($countRow['pendientes_count'] ?? 0);
    $base['pendientes_total'] = round((float) ($countRow['pendientes_total'] ?? 0), 2);

    if ($base['pendientes_count'] <= 0) {
        return $base;
    }

    $safeLimit = max(1, min(100, (int) $limitRows));
    $rowsStmt = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         SELECT TOP (" . $safeLimit . ")
            cs.id_cargo_salida,
            cs.fecha_cargo,
            cs.descripcion_cargo,
            cs.monto_cargo,
            tc.nombre_tipo_cargo,
            loc.cdo_local,
            t.nombre_comercial
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
         LEFT JOIN dbo.msp_locales loc
            ON loc.id_local = cs.id_local
         LEFT JOIN dbo.msp_tiendas t
            ON t.id_tienda = ca.id_tienda
         WHERE cs.estado_cargo IN (1, 2)
           AND cs.id_documento_cobro IS NULL
           AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
           AND cs.monto_cargo > 0
         ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
    );
    $rowsStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $rowsStmt->execute();
    $base['rows'] = $rowsStmt->fetchAll() ?: [];

    return $base;
}

function omCancelSaldoFavorPeriodoAplicaciones(PDO $conn, string $periodoFacturacion): int
{
    if (!msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
        return 0;
    }

    $stmt = $conn->prepare(
        "DELETE sfa
         FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
         WHERE sfa.periodo_facturacion = :periodo
           AND (
                sfa.estado_aplicacion = 1
                OR sfa.id_pago IS NOT NULL
                OR sfa.id_documento_cobro IS NOT NULL
           )"
    );
    $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->rowCount();
}

function omFetchPagosPeriodoBreakdown(PDO $conn, string $periodoFacturacion): array
{
    $base = [
        'total' => 0,
        'manual' => 0,
        'saldo_auto' => 0,
        'saldo_auto_apps_sin_pago' => 0,
    ];

    if (msp2TableExists($conn, 'msp_pagos') && msp2TableExists($conn, 'msp_documentos_cobro')) {
        $hasAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
        $exprSaldoAuto = $hasAplicaSaldoFavor
            ? "SUM(CASE WHEN ISNULL(p.aplica_desde_saldo_favor, 0) = 1 THEN 1 ELSE 0 END)"
            : '0';
        $exprManual = $hasAplicaSaldoFavor
            ? "SUM(CASE WHEN ISNULL(p.aplica_desde_saldo_favor, 0) = 1 THEN 0 ELSE 1 END)"
            : 'COUNT(*)';
        $estadoPagoFilter = msp2ColumnExists($conn, 'msp_pagos', 'estado_pago')
            ? ' AND p.estado_pago = 1'
            : '';

        $stmt = $conn->prepare(
            "SELECT
                COUNT(*) AS total_pagos,
                " . $exprManual . " AS manual_pagos,
                " . $exprSaldoAuto . " AS saldo_auto_pagos
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE dc.periodo_facturacion = :periodo"
             . $estadoPagoFilter
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch() ?: [];

        $base['total'] = (int) ($row['total_pagos'] ?? 0);
        $base['manual'] = (int) ($row['manual_pagos'] ?? 0);
        $base['saldo_auto'] = (int) ($row['saldo_auto_pagos'] ?? 0);
    }

    if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
        $stmtApps = $conn->prepare(
            "SELECT
                COUNT(*) AS apps_activas_sin_pago
             FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
             WHERE sfa.periodo_facturacion = :periodo
               AND sfa.estado_aplicacion = 1
               AND ISNULL(sfa.monto_aplicado, 0) > 0
               AND sfa.id_pago IS NULL"
        );
        $stmtApps->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmtApps->execute();
        $appsRow = $stmtApps->fetch() ?: [];
        $appsSinPago = (int) ($appsRow['apps_activas_sin_pago'] ?? 0);
        if ($appsSinPago > 0) {
            $base['saldo_auto_apps_sin_pago'] = $appsSinPago;
            $base['saldo_auto'] += $appsSinPago;
            $base['total'] += $appsSinPago;
        }
    }

    return $base;
}

function omPurgeSaldoFavorAutoPagosPeriodo(PDO $conn, string $periodoFacturacion): int
{
    if (
        !msp2TableExists($conn, 'msp_pagos')
        || !msp2TableExists($conn, 'msp_documentos_cobro')
        || !msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor')
    ) {
        return 0;
    }

    $startedTx = false;
    if (!$conn->inTransaction()) {
        $conn->beginTransaction();
        $startedTx = true;
    }

    try {
        if (msp2TableExists($conn, 'msp_pagos_detalle_concepto')) {
            $delDetalle = $conn->prepare(
                "DELETE pdc
                 FROM dbo.msp_pagos_detalle_concepto pdc
                 INNER JOIN dbo.msp_pagos p
                    ON p.id_pago = pdc.id_pago
                 INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_documento_cobro = p.id_documento_cobro
                 WHERE dc.periodo_facturacion = :periodo
                   AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1"
            );
            $delDetalle->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $delDetalle->execute();
        }

        $delPagos = $conn->prepare(
            "DELETE p
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE dc.periodo_facturacion = :periodo
               AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1"
        );
        $delPagos->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $delPagos->execute();
        $borrados = (int) $delPagos->rowCount();

        if ($startedTx && $conn->inTransaction()) {
            $conn->commit();
        }

        return $borrados;
    } catch (Throwable $e) {
        if ($startedTx && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function omBuildExtraChargesAppliedSnapshot(PDO $conn, string $periodoFacturacion, int $limitRows = 30): array
{
    $base = [
        'disponible' => false,
        'aplicados_count' => 0,
        'aplicados_total' => 0.0,
        'rows' => [],
    ];

    if (
        !msp2TableExists($conn, 'msp_cargos_salida')
        || !msp2TableExists($conn, 'msp_tipos_cargo_salida')
        || !msp2TableExists($conn, 'msp_documentos_cobro')
    ) {
        return $base;
    }

    $base['disponible'] = true;

    $countStmt = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         SELECT
            COUNT(*) AS aplicados_count,
            ROUND(SUM(cs.monto_cargo), 2) AS aplicados_total
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = cs.id_documento_cobro
         WHERE dc.periodo_facturacion = @periodo"
    );
    $countStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $countStmt->execute();
    $countRow = $countStmt->fetch() ?: [];
    $base['aplicados_count'] = (int) ($countRow['aplicados_count'] ?? 0);
    $base['aplicados_total'] = round((float) ($countRow['aplicados_total'] ?? 0), 2);

    if ($base['aplicados_count'] <= 0) {
        return $base;
    }

    $safeLimit = max(1, min(100, (int) $limitRows));
    $rowsStmt = $conn->prepare(
        "DECLARE @periodo DATE = :periodo;
         SELECT TOP (" . $safeLimit . ")
            cs.id_cargo_salida,
            cs.fecha_cargo,
            cs.descripcion_cargo,
            cs.monto_cargo,
            tc.nombre_tipo_cargo,
            loc.cdo_local,
            t.nombre_comercial,
            dc.id_documento_cobro,
            dc.numero_documento
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
         INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = cs.id_documento_cobro
           AND dc.periodo_facturacion = @periodo
         LEFT JOIN dbo.msp_locales loc
            ON loc.id_local = cs.id_local
         LEFT JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
    );
    $rowsStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $rowsStmt->execute();
    $base['rows'] = $rowsStmt->fetchAll() ?: [];

    return $base;
}

function omAguaPeriodoConsumo(string $periodoFacturacion): ?array
{
    $window = omServiceMeasurementWindow('AGUA', $periodoFacturacion);
    if (!is_array($window)) {
        return null;
    }

    return [
        'periodo_ym' => (string) ($window['periodo_ym'] ?? ''),
        'fecha_inicio' => (string) ($window['min'] ?? ''),
        'fecha_hasta' => (string) ($window['max'] ?? ''),
    ];
}

function omServiceMeasurementWindow(string $codigoServicio, string $periodoFacturacion): ?array
{
    $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
        return null;
    }

    $codigo = strtoupper(trim($codigoServicio));
    $offsetMonths = match ($codigo) {
        'LUZ', 'GAS' => -1,
        'AGUA' => -2,
        default => null,
    };
    if ($offsetMonths === null) {
        return null;
    }

    $targetMonth = $periodoDate->modify(($offsetMonths > 0 ? '+' : '') . $offsetMonths . ' months');
    if ($targetMonth === false) {
        return null;
    }

    $minDate = $targetMonth->format('Y-m-01');
    $baseMaxDateObj = $targetMonth->modify('last day of this month');
    if ($baseMaxDateObj === false) {
        return null;
    }
    $baseMaxDate = $baseMaxDateObj->format('Y-m-d');
    $maxDate = $baseMaxDate;

    // GAS: admitir facturas tardías hasta 5 días calendario del mes siguiente.
    if ($codigo === 'GAS') {
        $gasMaxDateObj = $baseMaxDateObj->modify('+5 days');
        if ($gasMaxDateObj === false) {
            return null;
        }
        $maxDate = $gasMaxDateObj->format('Y-m-d');
    }

    return [
        'servicio' => $codigo,
        'periodo_ym' => $targetMonth->format('Y-m'),
        'min' => $minDate,
        'max' => $maxDate,
        'default' => $baseMaxDate,
    ];
}

function omResolveServiceReadingDefaults(string $codigoServicio, string $periodoFacturacion, ?string $fechaMedicionProceso = null): array
{
    $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
        throw new RuntimeException('Periodo de lecturas invalido.');
    }

    $measurementWindow = omServiceMeasurementWindow($codigoServicio, $periodoFacturacion);
    $fechaHastaDefault = $periodoDate->modify('last day of this month')->format('Y-m-d');
    $fechaLecturaDefault = $fechaHastaDefault;
    $aguaPeriodoConsumo = null;

    if (is_array($measurementWindow)) {
        $fechaHastaDefault = (string) ($measurementWindow['default'] ?? $fechaHastaDefault);
        $fechaLecturaDefault = $fechaHastaDefault;
    }

    if ($codigoServicio === 'AGUA') {
        $aguaPeriodoConsumo = omAguaPeriodoConsumo($periodoFacturacion);
        if (!is_array($aguaPeriodoConsumo)) {
            throw new RuntimeException('No fue posible resolver el periodo de consumo para AGUA.');
        }
    } elseif (
        $fechaMedicionProceso !== null
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMedicionProceso) === 1
    ) {
        $measurementMin = is_array($measurementWindow) ? (string) ($measurementWindow['min'] ?? '') : '';
        $measurementMax = is_array($measurementWindow) ? (string) ($measurementWindow['max'] ?? '') : '';
        if (
            $measurementMin !== ''
            && $measurementMax !== ''
            && $fechaMedicionProceso >= $measurementMin
            && $fechaMedicionProceso <= $measurementMax
        ) {
            $fechaHastaDefault = $fechaMedicionProceso;
            $fechaLecturaDefault = $fechaMedicionProceso;
        }
    }

    return [
        'fecha_hasta_consumo' => $fechaHastaDefault,
        'fecha_lectura' => $fechaLecturaDefault,
        'agua_periodo_consumo' => $aguaPeriodoConsumo,
    ];
}

function omFetchDirectReadingSeedRows(PDO $conn, string $codigoServicio, int $idTipoServicio, string $periodoFacturacion): array
{
    $defaults = omResolveServiceReadingDefaults($codigoServicio, $periodoFacturacion);
    $filtroUltimaLectura = 'lm.periodo_facturacion < :periodo';
    if ($codigoServicio === 'AGUA') {
        $aguaPeriodoConsumo = $defaults['agua_periodo_consumo'] ?? null;
        if (!is_array($aguaPeriodoConsumo)) {
            throw new RuntimeException('No fue posible resolver la referencia previa para AGUA.');
        }
        $filtroUltimaLectura = 'lm.fecha_hasta_consumo < :agua_inicio_consumo';
    }

    $stmt = $conn->prepare(
        "SELECT
            m.id_medidor,
            loc.cdo_local AS cod_local,
            m.codigo_medidor,
            ult.lectura_actual AS lectura_anterior_real,
            ult.fecha_hasta_consumo AS fecha_hasta_consumo_anterior,
            m.valor_inicial
         FROM dbo.msp_medidores m
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = m.id_tipo_servicio
         OUTER APPLY (
            SELECT TOP (1)
                lm.lectura_actual,
                lm.fecha_hasta_consumo
            FROM dbo.msp_lecturas_medidores lm
            WHERE lm.id_medidor = m.id_medidor
              AND {$filtroUltimaLectura}
            ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
         ) ult
         WHERE UPPER(ts.codigo_servicio) = :servicio
           AND m.id_tipo_servicio = :id_tipo
           AND m.estado_medidor = 1
           AND m.fecha_retiro IS NULL
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
    );

    if ($codigoServicio === 'AGUA') {
        $aguaPeriodoConsumo = $defaults['agua_periodo_consumo'] ?? null;
        $stmt->bindValue(':agua_inicio_consumo', (string) ($aguaPeriodoConsumo['fecha_inicio'] ?? ''), PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    }

    $stmt->bindValue(':servicio', $codigoServicio, PDO::PARAM_STR);
    $stmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function omDecimal(?string $raw, int $scale, bool $required = false): array
{
    [$ok, $value] = msp2NormalizeDecimalInput($raw, $scale);
    if (!$ok) {
        return [false, null];
    }

    if ($required && $value === null) {
        return [false, null];
    }

    return [true, $value];
}

try {
    $requiredTables = [
        'msp_cierre_mensual',
        'msp_tipos_servicio',
        'msp_procesos_cobro_servicio',
        'msp_proceso_cobro_luz',
        'msp_proceso_cobro_gas',
        'msp_proceso_cobro_agua',
        'msp_import_lotes',
        'msp_import_lecturas',
        'msp_lecturas_medidores',
        'msp_cobros_servicios',
        'msp_documentos_cobro',
    ];

    $missing = [];
    foreach ($requiredTables as $table) {
        if (!msp2TableExists($conn, $table)) {
            $missing[] = $table;
        }
    }

    if ($missing !== []) {
        $loadError = 'Faltan tablas para operacion mensual: `' . implode('`, `', $missing) . '`. Ejecuta `msp/db/msp_instalar_full.sql`.';
    } else {
        $tablaExiste = true;
    }
} catch (PDOException $e) {
    $loadError = 'No fue posible validar la estructura de operacion mensual.';
}

if ($tablaExiste) {
    $tiposStmt = $conn->query(
        "SELECT id_tipo_servicio, codigo_servicio, nombre_servicio
         FROM dbo.msp_tipos_servicio
         WHERE UPPER(codigo_servicio) IN ('AGUA','LUZ','GAS')"
    );
    while (($r = $tiposStmt->fetch()) !== false) {
        $code = strtoupper((string) ($r['codigo_servicio'] ?? ''));
        if ($code === '') {
            continue;
        }
        $serviceIdByCode[$code] = (int) $r['id_tipo_servicio'];
        $serviceNameByCode[$code] = (string) ($r['nombre_servicio'] ?? $code);
    }
}

if ($tablaExiste && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if (in_array($accion, ['revisar_periodo', 'cerrar_periodo', 'reabrir_periodo'], true)
        && !msp2CurrentUserHasPermission('MSP Cierre Mensual')) {
        msp2SetFlash('danger', 'No tienes permiso para revisar, cerrar o reabrir períodos mensuales.');
        msp2Redirect(omSelfRoute());
    }

    if ($accion === 'guardar_cierre') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoYmUi = omFmtPeriodoYm($periodoYm);
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $modoCierreRaw = strtolower(trim((string) ($_POST['modo_cierre'] ?? '')));
        $idCierreForm = filter_input(INPUT_POST, 'id_cierre_mensual', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $modoCierre = in_array($modoCierreRaw, ['create', 'edit'], true)
            ? $modoCierreRaw
            : (($idCierreForm !== false && $idCierreForm !== null) ? 'edit' : 'create');
        $fechaUf = trim((string) ($_POST['fecha_valor_uf'] ?? ''));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido. Usa AAAA-MM.');
            msp2Redirect(omSelfRoute());
        }

        $dateUf = DateTimeImmutable::createFromFormat('Y-m-d', $fechaUf);
        if ($dateUf === false || $dateUf->format('Y-m-d') !== $fechaUf) {
            msp2SetFlash('warning', 'Fecha valor UF invalida.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-1');
        }

        [$okUf, $valorUf] = omDecimal(is_string($_POST['valor_uf'] ?? null) ? (string) $_POST['valor_uf'] : null, 2, true);
        if (!$okUf || $valorUf === null) {
            msp2SetFlash('warning', 'Valor UF invalido.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-1');
        }
        $observaciones = mb_substr($observaciones, 0, 1000, 'UTF-8');

        $periodoSaved = false;

        try {
            $checkStmt = $conn->prepare(
                'SELECT id_cierre_mensual, estado_cierre
                 FROM dbo.msp_cierre_mensual
                 WHERE periodo_facturacion = :periodo'
            );
            $checkStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $checkStmt->execute();
            $cierreRow = $checkStmt->fetch() ?: null;
            $idCierre = is_array($cierreRow) ? ($cierreRow['id_cierre_mensual'] ?? false) : false;
            $estadoCierreActual = is_array($cierreRow) ? (int) ($cierreRow['estado_cierre'] ?? 1) : 1;

            if ($modoCierre === 'edit') {
                if ($idCierreForm === false || $idCierreForm === null) {
                    msp2SetFlash('warning', 'Para actualizar debes seleccionar un periodo creado.');
                    omRedirectPeriodoConFoco($periodoYm, 'paso-1');
                }

                if ($idCierre === false) {
                    msp2SetFlash('warning', 'El periodo ingresado no existe. Selecciona un periodo creado o crea uno nuevo.');
                    omRedirectPeriodoConFoco($periodoYm, 'paso-1');
                }

                if ((int) $idCierre !== (int) $idCierreForm) {
                    msp2SetFlash('warning', 'Ya existe un periodo creado para ' . $periodoYmUi . '. Selecciona ese periodo para editarlo.');
                    omRedirectPeriodoConFoco($periodoYm, 'paso-1');
                }

                if ($estadoCierreActual !== CierreMensualService::BORRADOR) {
                    msp2SetFlash('warning', 'Para modificar los datos del período primero debes devolverlo a Borrador e indicar el motivo.');
                    omRedirectPeriodoConFoco($periodoYm, 'paso-1');
                }

                $upd = $conn->prepare(
                    'UPDATE dbo.msp_cierre_mensual
                     SET fecha_valor_uf = :fecha_uf,
                         valor_uf = :valor_uf,
                         observaciones = :obs
                     WHERE id_cierre_mensual = :id'
                );
                $upd->bindValue(':id', (int) $idCierreForm, PDO::PARAM_INT);
                $upd->bindValue(':fecha_uf', $fechaUf, PDO::PARAM_STR);
                $upd->bindValue(':valor_uf', $valorUf, PDO::PARAM_STR);
                $upd->bindValue(':obs', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $upd->execute();
                msp2SetFlash('success', 'Periodo actualizado correctamente.');
                $periodoSaved = true;
            } else {
                if ($idCierre !== false) {
                    msp2SetFlash('warning', 'Ya existe un periodo creado para ' . $periodoYmUi . '. Selecciona ese periodo para editarlo.');
                } else {
                    $ins = $conn->prepare(
                        'INSERT INTO dbo.msp_cierre_mensual
                            (periodo_facturacion, fecha_valor_uf, valor_uf, estado_cierre, observaciones)
                         VALUES
                            (:periodo, :fecha_uf, :valor_uf, :estado, :obs)'
                    );
                    $ins->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                    $ins->bindValue(':fecha_uf', $fechaUf, PDO::PARAM_STR);
                    $ins->bindValue(':valor_uf', $valorUf, PDO::PARAM_STR);
                    $ins->bindValue(':estado', 1, PDO::PARAM_INT);
                    $ins->bindValue(':obs', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $ins->execute();
                    msp2SetFlash('success', 'Periodo creado correctamente.');
                    $periodoSaved = true;
                }
            }
        } catch (PDOException $e) {
            $err = $e->getMessage();
            if (
                str_contains($err, 'UQ_msp_cierre_mensual_periodo')
                || str_contains($err, '2601')
                || str_contains($err, '2627')
            ) {
                msp2SetFlash('warning', 'Ya existe un periodo creado para ' . $periodoYmUi . '. Selecciona ese periodo para editarlo.');
            } else {
                msp2SetFlash('danger', 'No fue posible guardar el periodo.');
            }
        }

        if ($periodoSaved) {
            omRedirectManualAdjustTab($periodoYm, 'cargo_extra');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-1');
    }

    if ($accion === 'revisar_periodo') {
        $periodoYm=trim((string)($_POST['periodo']??''));
        $periodoFacturacion=omParseMonthToFirstDay($periodoYm);
        if ($periodoFacturacion===null) {
            msp2SetFlash('warning','Periodo inválido para revisar.');
            msp2Redirect(omSelfRoute());
        }
        try {
            $cierre=omFetchCierreByPeriodo($conn,$periodoFacturacion);
            if (!is_array($cierre)) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            (new CierreMensualService($conn))->transicionar(
                (int)$cierre['id_cierre_mensual'],
                (int)$cierre['estado_cierre'],
                CierreMensualService::REVISADO,
                'Revisión manual confirmada',
                isset($_SESSION['usuario']['id'])?(int)$_SESSION['usuario']['id']:null
            );
            msp2SetFlash('success','Período marcado como Revisado. Ya puede cerrarse o volver a Borrador si detectas errores.');
        } catch (Throwable $e) {
            msp2SetFlash('danger',$e instanceof RuntimeException?$e->getMessage():'No fue posible revisar el período.');
        }
        omRedirectPeriodoConFoco($periodoYm,'paso-1');
    }

    if ($accion === 'cerrar_periodo') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para cerrar.');
            msp2Redirect(omSelfRoute());
        }

        try {
            $cierre = omFetchCierreByPeriodo($conn, $periodoFacturacion);
            if (!is_array($cierre)) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }

            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            $estadoActual = (int) ($cierre['estado_cierre'] ?? 0);
            if ($estadoActual !== CierreMensualService::REVISADO) {
                throw new RuntimeException(
                    'Solo puedes cerrar un período en estado Revisado. Estado actual: '
                    . omCierreEstadoLabel($estadoActual) . '.'
                );
            }
            (new CierreMensualService($conn))->transicionar(
                $idCierre,$estadoActual,CierreMensualService::CERRADO,'Cierre mensual confirmado',
                isset($_SESSION['usuario']['id'])?(int)$_SESSION['usuario']['id']:null
            );
            msp2SetFlash('success', 'Período cerrado correctamente. El cálculo quedó congelado.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cerrar el período.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-1');
    }

    if ($accion === 'reabrir_periodo') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $motivoReapertura = trim((string) ($_POST['motivo_reapertura'] ?? ''));
        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para reabrir.');
            msp2Redirect(omSelfRoute());
        }

        try {
            $cierre = omFetchCierreByPeriodo($conn, $periodoFacturacion);
            if (!is_array($cierre)) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }

            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            $estadoActual = (int) ($cierre['estado_cierre'] ?? 0);
            if (!in_array($estadoActual, [2, 3, 4, 5], true)) {
                throw new RuntimeException(
                    'Solo puedes devolver a Borrador períodos Calculados, Revisados, Cerrados o Anulados. Estado actual: '
                    . omCierreEstadoLabel($estadoActual) . '.'
                );
            }

            if ($motivoReapertura === '') {
                throw new RuntimeException('Debes indicar el motivo para devolver el período a Borrador.');
            }
            (new CierreMensualService($conn))->transicionar(
                $idCierre,$estadoActual,CierreMensualService::BORRADOR,$motivoReapertura,
                isset($_SESSION['usuario']['id'])?(int)$_SESSION['usuario']['id']:null
            );
            msp2SetFlash(
                'success',
                $estadoActual === 4
                    ? 'Período anulado restaurado correctamente a Borrador.'
                    : 'Período reabierto a Borrador.'
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible reabrir el período.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-1');
    }

    if ($accion === 'guardar_servicio') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));
        $autoExcel = omPostFlag('auto_excel');
        $isAjaxRequest = omIsAjaxRequest();

        if ($periodoFacturacion === null || !in_array($codigoServicio, $serviceCodes, true)) {
            if ($isAjaxRequest) {
                omJsonResponse([
                    'ok' => false,
                    'message' => 'Datos invalidos para guardar servicio.',
                ], 422);
            }
            msp2SetFlash('warning', 'Datos invalidos para guardar servicio.');
            msp2Redirect(omSelfRoute());
        }

        $saveOk = false;
        $saveMessage = 'No fue posible guardar el servicio.';

        try {
            $conn->beginTransaction();

            $cierreStmt = $conn->prepare('SELECT id_cierre_mensual FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = :periodo');
            $cierreStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $cierreStmt->execute();
            $idCierre = $cierreStmt->fetchColumn();

            if ($idCierre === false) {
                throw new RuntimeException('Debes crear primero el cierre del periodo.');
            }

            $idTipoServicio = $serviceIdByCode[$codigoServicio] ?? 0;
            if ($idTipoServicio <= 0) {
                throw new RuntimeException('Tipo de servicio no encontrado.');
            }

            $numeroFactura = msp2NormalizeText($_POST['numero_factura_origen'] ?? null);
            $fechaEmision = trim((string) ($_POST['fecha_emision_origen'] ?? ''));
            $fechaVenc = trim((string) ($_POST['fecha_vencimiento_origen'] ?? ''));
            $periodoMedicionActual = trim((string) ($_POST['periodo_medicion_actual'] ?? ''));
            $fechaMedicionActual = trim((string) ($_POST['fecha_medicion_actual'] ?? ''));
            $obsProceso = msp2NormalizeText($_POST['observaciones_proceso'] ?? null);

            $fechaEmisionValue = null;
            if ($fechaEmision !== '') {
                $d1 = DateTimeImmutable::createFromFormat('Y-m-d', $fechaEmision);
                if ($d1 === false || $d1->format('Y-m-d') !== $fechaEmision) {
                    throw new RuntimeException('Fecha emision invalida.');
                }
                $fechaEmisionValue = $fechaEmision;
            }

            $fechaVencValue = null;
            if ($fechaVenc !== '') {
                $d2 = DateTimeImmutable::createFromFormat('Y-m-d', $fechaVenc);
                if ($d2 === false || $d2->format('Y-m-d') !== $fechaVenc) {
                    throw new RuntimeException('Fecha vencimiento invalida.');
                }
                $fechaVencValue = $fechaVenc;
            }

            if ($codigoServicio === 'AGUA') {
                // Para agua se trabaja sin fecha de vencimiento de origen.
                $fechaVencValue = null;
            }

            if ($codigoServicio === 'LUZ' || $codigoServicio === 'GAS') {
                $measurementWindow = omServiceMeasurementWindow($codigoServicio, $periodoFacturacion);
                if (!is_array($measurementWindow)) {
                    throw new RuntimeException('No fue posible resolver la ventana de medición para ' . $codigoServicio . '.');
                }
                $measurementMin = (string) ($measurementWindow['min'] ?? '');
                $measurementMax = (string) ($measurementWindow['max'] ?? '');
                if ($measurementMin === '' || $measurementMax === '') {
                    throw new RuntimeException('No fue posible resolver el rango de fechas de medición para ' . $codigoServicio . '.');
                }

                $fechaMedicionValue = null;
                if ($fechaMedicionActual !== '') {
                    $medicionDate = DateTimeImmutable::createFromFormat('Y-m-d', $fechaMedicionActual);
                    if ($medicionDate === false || $medicionDate->format('Y-m-d') !== $fechaMedicionActual) {
                        throw new RuntimeException('Debes ingresar una fecha de medicion valida para ' . $codigoServicio . ' (DD-MM-YYYY).');
                    }
                    $fechaMedicionValue = $fechaMedicionActual;
                } else {
                    // Compatibilidad temporal con el campo antiguo (YYYY-MM).
                    $periodoMedicionValue = omParseMonthToFirstDay($periodoMedicionActual);
                    if ($periodoMedicionValue === null) {
                        throw new RuntimeException('Debes ingresar la fecha de medicion actual para ' . $codigoServicio . ' (DD-MM-YYYY).');
                    }
                    $fechaMedicionValue = $periodoMedicionValue;
                }

                if ($fechaMedicionValue < $measurementMin || $fechaMedicionValue > $measurementMax) {
                    throw new RuntimeException(
                        'La fecha de medición de ' . $codigoServicio
                        . ' debe estar entre ' . omFmtFecha($measurementMin) . ' y ' . omFmtFecha($measurementMax) . '.'
                    );
                }

                // Para luz y gas no se usa factura ni fecha de vencimiento.
                $numeroFactura = '';
                $fechaEmisionValue = $fechaMedicionValue;
                $fechaVencValue = null;
            }

            $procStmt = $conn->prepare(
                'SELECT id_proceso_cobro, estado_proceso
                 FROM dbo.msp_procesos_cobro_servicio
                 WHERE id_cierre_mensual = :id_cierre AND id_tipo_servicio = :id_tipo'
            );
            $procStmt->bindValue(':id_cierre', (int) $idCierre, PDO::PARAM_INT);
            $procStmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
            $procStmt->execute();
            $proc = $procStmt->fetch();

            $idProceso = 0;
            if ($proc === false) {
                $insProc = $conn->prepare(
                    'INSERT INTO dbo.msp_procesos_cobro_servicio
                        (id_cierre_mensual, id_tipo_servicio, numero_factura_origen, fecha_emision_origen, fecha_vencimiento_origen, estado_proceso, observaciones)
                     VALUES
                        (:id_cierre, :id_tipo, :num, :femi, :fven, 1, :obs)'
                );
                $insProc->bindValue(':id_cierre', (int) $idCierre, PDO::PARAM_INT);
                $insProc->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
                $insProc->bindValue(':num', $numeroFactura !== '' ? $numeroFactura : null, $numeroFactura !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insProc->bindValue(':femi', $fechaEmisionValue, $fechaEmisionValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insProc->bindValue(':fven', $fechaVencValue, $fechaVencValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insProc->bindValue(':obs', $obsProceso !== '' ? $obsProceso : null, $obsProceso !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insProc->execute();
                $idProceso = (int) $conn->lastInsertId();
            } else {
                $idProceso = (int) ($proc['id_proceso_cobro'] ?? 0);
                $updProc = $conn->prepare(
                    'UPDATE dbo.msp_procesos_cobro_servicio
                     SET numero_factura_origen = :num,
                         fecha_emision_origen = :femi,
                         fecha_vencimiento_origen = :fven,
                         observaciones = :obs
                     WHERE id_proceso_cobro = :id'
                );
                $updProc->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $updProc->bindValue(':num', $numeroFactura !== '' ? $numeroFactura : null, $numeroFactura !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updProc->bindValue(':femi', $fechaEmisionValue, $fechaEmisionValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updProc->bindValue(':fven', $fechaVencValue, $fechaVencValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updProc->bindValue(':obs', $obsProceso !== '' ? $obsProceso : null, $obsProceso !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $updProc->execute();
            }

            if ($idProceso <= 0) {
                throw new RuntimeException('No se pudo resolver el proceso de cobro.');
            }

            if ($codigoServicio === 'LUZ') {
                [$ok, $valorKwh] = omDecimal((string) ($_POST['valor_kwh'] ?? ''), 2, true);
                if (!$ok || $valorKwh === null) {
                    throw new RuntimeException('Valor kWh invalido.');
                }

                $stmt = $conn->prepare('SELECT id_proceso_cobro FROM dbo.msp_proceso_cobro_luz WHERE id_proceso_cobro = :id');
                $stmt->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetchColumn() === false) {
                    $ins = $conn->prepare('INSERT INTO dbo.msp_proceso_cobro_luz (id_proceso_cobro, valor_kwh) VALUES (:id, :valor)');
                } else {
                    $ins = $conn->prepare('UPDATE dbo.msp_proceso_cobro_luz SET valor_kwh = :valor WHERE id_proceso_cobro = :id');
                }
                $ins->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $ins->bindValue(':valor', $valorKwh, PDO::PARAM_STR);
                $ins->execute();
            }

            if ($codigoServicio === 'GAS') {
                [$okFactor, $factor] = omDecimal((string) ($_POST['factor'] ?? ''), 2, true);
                [$okLitro, $litro] = omDecimal((string) ($_POST['valor_litro'] ?? ''), 2, true);
                if (!$okFactor || !$okLitro || $factor === null || $litro === null) {
                    throw new RuntimeException('Factor o valor litro invalido.');
                }

                $stmt = $conn->prepare('SELECT id_proceso_cobro FROM dbo.msp_proceso_cobro_gas WHERE id_proceso_cobro = :id');
                $stmt->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetchColumn() === false) {
                    $ins = $conn->prepare('INSERT INTO dbo.msp_proceso_cobro_gas (id_proceso_cobro, factor, valor_litro) VALUES (:id, :factor, :litro)');
                } else {
                    $ins = $conn->prepare('UPDATE dbo.msp_proceso_cobro_gas SET factor = :factor, valor_litro = :litro WHERE id_proceso_cobro = :id');
                }
                $ins->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $ins->bindValue(':factor', $factor, PDO::PARAM_STR);
                $ins->bindValue(':litro', $litro, PDO::PARAM_STR);
                $ins->execute();
            }

            if ($codigoServicio === 'AGUA') {
                $fields = [
                    'lectura_general_anterior' => [0, true],
                    'lectura_general_actual' => [0, true],
                    'servicio_agua_potable' => [2, true],
                    'servicio_alcantarillado' => [2, true],
                    'tratamiento_aguas_servidas' => [2, true],
                    'sobreconsumo' => [2, false],
                    'interes_pf_plazo' => [2, false],
                    'cargo_fijo' => [2, true],
                ];
                $v = [];
                foreach ($fields as $field => [$scale, $required]) {
                    if ($field === 'lectura_general_anterior' || $field === 'lectura_general_actual') {
                        [$okField, $valField] = omIntegerInput((string) ($_POST[$field] ?? ''), $required);
                    } else {
                        [$okField, $valField] = omDecimal((string) ($_POST[$field] ?? ''), $scale, $required);
                    }

                    if (!$okField) {
                        throw new RuntimeException('Valor invalido en campo ' . $field . '.');
                    }
                    $v[$field] = $valField;
                }

                if ($v['lectura_general_anterior'] === null || $v['lectura_general_actual'] === null) {
                    throw new RuntimeException('Debes ingresar lectura general anterior y actual para AGUA.');
                }

                if ((float) $v['lectura_general_actual'] < (float) $v['lectura_general_anterior']) {
                    throw new RuntimeException('La lectura general actual no puede ser menor que la anterior.');
                }

                $consumoCalculado = (float) $v['lectura_general_actual'] - (float) $v['lectura_general_anterior'];
                if ($consumoCalculado <= 0) {
                    throw new RuntimeException('El consumo de agua debe ser mayor que cero (actual - anterior).');
                }

                $v['divisor'] = number_format($consumoCalculado, 6, '.', '');
                $v['monto_total_factura'] = null;
                $v['sobreconsumo'] = $v['sobreconsumo'] ?? '0.000000';
                $v['interes_pf_plazo'] = $v['interes_pf_plazo'] ?? '0.000000';

                $stmt = $conn->prepare('SELECT id_proceso_cobro FROM dbo.msp_proceso_cobro_agua WHERE id_proceso_cobro = :id');
                $stmt->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $stmt->execute();

                if ($stmt->fetchColumn() === false) {
                    $sql = 'INSERT INTO dbo.msp_proceso_cobro_agua
                        (id_proceso_cobro, lectura_general_anterior, lectura_general_actual, servicio_agua_potable, servicio_alcantarillado, tratamiento_aguas_servidas, sobreconsumo, interes_pf_plazo, divisor, cargo_fijo, monto_total_factura)
                        VALUES
                        (:id, :lga, :lgt, :sap, :sal, :tas, :sob, :ipf, :div, :cf, :mtf)';
                } else {
                    $sql = 'UPDATE dbo.msp_proceso_cobro_agua
                        SET lectura_general_anterior = :lga,
                            lectura_general_actual = :lgt,
                            servicio_agua_potable = :sap,
                            servicio_alcantarillado = :sal,
                            tratamiento_aguas_servidas = :tas,
                            sobreconsumo = :sob,
                            interes_pf_plazo = :ipf,
                            divisor = :div,
                            cargo_fijo = :cf,
                            monto_total_factura = :mtf
                        WHERE id_proceso_cobro = :id';
                }

                $ins = $conn->prepare($sql);
                $ins->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $ins->bindValue(':lga', $v['lectura_general_anterior'], $v['lectura_general_anterior'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $ins->bindValue(':lgt', $v['lectura_general_actual'], $v['lectura_general_actual'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $ins->bindValue(':sap', $v['servicio_agua_potable'], PDO::PARAM_STR);
                $ins->bindValue(':sal', $v['servicio_alcantarillado'], PDO::PARAM_STR);
                $ins->bindValue(':tas', $v['tratamiento_aguas_servidas'], PDO::PARAM_STR);
                $ins->bindValue(':sob', $v['sobreconsumo'], $v['sobreconsumo'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $ins->bindValue(':ipf', $v['interes_pf_plazo'], $v['interes_pf_plazo'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $ins->bindValue(':div', $v['divisor'], PDO::PARAM_STR);
                $ins->bindValue(':cf', $v['cargo_fijo'], PDO::PARAM_STR);
                $ins->bindValue(':mtf', $v['monto_total_factura'], $v['monto_total_factura'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $ins->execute();
            }

            if ($codigoServicio !== 'AGUA' && $fechaEmisionValue !== null) {
                $updLecturasFecha = $conn->prepare(
                    'UPDATE dbo.msp_lecturas_medidores
                     SET fecha_hasta_consumo = :fecha_hasta,
                         fecha_lectura = :fecha_lectura,
                         fecha_actualizacion = SYSDATETIME()
                     WHERE id_proceso_cobro = :id'
                );
                $updLecturasFecha->bindValue(':fecha_hasta', $fechaEmisionValue, PDO::PARAM_STR);
                $updLecturasFecha->bindValue(':fecha_lectura', $fechaEmisionValue, PDO::PARAM_STR);
                $updLecturasFecha->bindValue(':id', $idProceso, PDO::PARAM_INT);
                $updLecturasFecha->execute();
            }

            $conn->commit();
            $saveOk = true;
            $saveMessage = 'Parametros de ' . $codigoServicio . ' guardados correctamente.';
            if (!$isAjaxRequest) {
                msp2SetFlash('success', $saveMessage);
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $saveMessage = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible guardar el servicio.';
            if (!$isAjaxRequest) {
                msp2SetFlash('danger', $saveMessage);
            }
        }

        if ($isAjaxRequest) {
            $autoExcelUrl = null;
            if ($saveOk && $autoExcel && $codigoServicio === 'LUZ') {
                $autoExcelUrl = msp2Url('cobros/plantilla_lecturas.php?servicio=' . urlencode($codigoServicio) . '&periodo=' . urlencode($periodoYm));
            }

            omJsonResponse([
                'ok' => $saveOk,
                'message' => $saveMessage,
                'codigo_servicio' => $codigoServicio,
                'periodo' => $periodoYm,
                'has_proceso' => $saveOk,
                'auto_excel_url' => $autoExcelUrl,
            ], $saveOk ? 200 : 422);
        }

        if ($autoExcel && $codigoServicio === 'LUZ') {
            $params = [
                'periodo' => $periodoYm,
                'auto_excel' => '1',
                'auto_servicio' => $codigoServicio,
            ];
            msp2Redirect(omSelfRoute() . '?' . http_build_query($params) . '#' . omServiceAnchor($codigoServicio));
        }

        omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
    }

    if ($accion === 'descartar_preview_importacion') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));
        if ($periodoYm !== '' && in_array($codigoServicio, $serviceCodes, true)) {
            omPreviewSessionClear($periodoYm, $codigoServicio);
            msp2SetFlash('success', 'Previsualización descartada.');
        }
        omRedirectPeriodoConFoco(
            $periodoYm !== '' ? $periodoYm : (new DateTimeImmutable('today'))->format('Y-m'),
            in_array($codigoServicio, $serviceCodes, true) ? omServiceAnchor($codigoServicio) : 'paso-2'
        );
    }

    if ($accion === 'preparar_lecturas_directas') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));
        $idTipoServicio = (int) ($serviceIdByCode[$codigoServicio] ?? 0);

        if ($codigoServicio === 'LUZ') {
            msp2SetFlash('warning', 'Ajuste directo no está habilitado para LUZ. Usa la carga por Excel.');
            omRedirectPeriodoConFoco(
                $periodoYm !== '' ? $periodoYm : (new DateTimeImmutable('today'))->format('Y-m'),
                omServiceAnchor('LUZ')
            );
        }

        if ($periodoFacturacion === null || !in_array($codigoServicio, $serviceCodes, true) || $idTipoServicio <= 0) {
            msp2SetFlash('warning', 'Datos invalidos para preparar lectura directa.');
            msp2Redirect(omSelfRoute());
        }

        try {
            $procStmt = $conn->prepare(
                'SELECT fecha_emision_origen
                 FROM dbo.msp_cierre_mensual c
                 INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_cierre_mensual = c.id_cierre_mensual
                 WHERE c.periodo_facturacion = :periodo
                   AND p.id_tipo_servicio = :id_tipo'
            );
            $procStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $procStmt->bindValue(':id_tipo', $idTipoServicio, PDO::PARAM_INT);
            $procStmt->execute();
            $fechaMedicionProceso = (string) ($procStmt->fetchColumn() ?: '');

            $seedRows = omFetchDirectReadingSeedRows($conn, $codigoServicio, $idTipoServicio, $periodoFacturacion);
            $defaults = omResolveServiceReadingDefaults(
                $codigoServicio,
                $periodoFacturacion,
                $fechaMedicionProceso !== '' ? substr($fechaMedicionProceso, 0, 10) : null
            );

            $insertadas = ImportacionLecturasService::prepararLecturasDirectas(
                $conn,
                $codigoServicio,
                $idTipoServicio,
                $periodoFacturacion,
                $seedRows,
                $defaults
            );

            if ($insertadas > 0) {
                msp2SetFlash('success', 'Se prepararon ' . $insertadas . ' lecturas para edición directa en ' . $codigoServicio . '.');
            } else {
                msp2SetFlash('success', 'Las lecturas directas de ' . $codigoServicio . ' ya estaban preparadas.');
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible preparar la lectura directa.');
        }

        omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
    }

    if ($accion === 'actualizar_lecturas_directas') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));
        $idTipoServicio = (int) ($serviceIdByCode[$codigoServicio] ?? 0);
        $lecturasActuales = $_POST['lecturas_actuales'] ?? null;

        if (
            $periodoFacturacion === null
            || !in_array($codigoServicio, $serviceCodes, true)
            || $idTipoServicio <= 0
            || !is_array($lecturasActuales)
        ) {
            msp2SetFlash('warning', 'Datos invalidos para actualizar lecturas directas.');
            msp2Redirect(omSelfRoute());
        }

        try {
            $updatedCount = ImportacionLecturasService::actualizarLecturasDirectas(
                $conn,
                $codigoServicio,
                $idTipoServicio,
                $periodoFacturacion,
                $lecturasActuales
            );

            $flashMeta = omBuildCompletionHintMetaForServicio($conn, $codigoServicio, $periodoYm, $periodoFacturacion);
            msp2SetFlash(
                'success',
                'Lecturas directas actualizadas para ' . $codigoServicio . ': ' . $updatedCount . ' fila(s).',
                $flashMeta
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible actualizar las lecturas directas.');
        }

        omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
    }

    if ($accion === 'confirmar_importacion') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));

        if ($periodoFacturacion === null || !in_array($codigoServicio, $serviceCodes, true)) {
            msp2SetFlash('warning', 'Datos inválidos para confirmar importación.');
            msp2Redirect(omSelfRoute());
        }

        $previewPayload = omPreviewSessionRead($periodoYm, $codigoServicio);
        if (!is_array($previewPayload)) {
            msp2SetFlash('warning', 'No hay previsualización pendiente para confirmar.');
            omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
        }

        $validRows = $previewPayload['valid_rows'] ?? [];
        $originalName = (string) ($previewPayload['original_name'] ?? 'importacion.xlsx');
        $reemplazarLecturas = ((int) ($previewPayload['reemplazar'] ?? 0)) === 1 ? 1 : 0;
        $idTipoServicio = (int) ($serviceIdByCode[$codigoServicio] ?? 0);

        if (!is_array($validRows) || $validRows === [] || $idTipoServicio <= 0) {
            msp2SetFlash('danger', 'La previsualización no es válida. Vuelve a cargar el Excel.');
            omPreviewSessionClear($periodoYm, $codigoServicio);
            omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
        }

        try {
            $usuarioCarga = (string) ($_SESSION['usuario']['usuario'] ?? $_SESSION['usuario']['nombre'] ?? $_SESSION['usuario']['id'] ?? '');
            $resConfirm = ImportacionLecturasService::confirmarImportacion(
                $conn,
                $codigoServicio,
                $idTipoServicio,
                $periodoFacturacion,
                $validRows,
                $originalName,
                $reemplazarLecturas,
                $usuarioCarga
            );
            omPreviewSessionClear($periodoYm, $codigoServicio);

            $flashMeta = omBuildCompletionHintMetaForServicio($conn, $codigoServicio, $periodoYm, $periodoFacturacion);
            msp2SetFlash(
                'success',
                'Importación confirmada para ' . $codigoServicio . ': ' . (int) ($resConfirm['lecturas_insertadas'] ?? 0)
                . ' lecturas (lote #' . (int) ($resConfirm['id_lote'] ?? 0) . ').',
                $flashMeta
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible confirmar la importación.');
        }

        omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
    }

    if ($accion === 'importar_lecturas') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['codigo_servicio'] ?? '')));

        if ($periodoFacturacion === null || !in_array($codigoServicio, $serviceCodes, true)) {
            msp2SetFlash('warning', 'Datos invalidos para previsualizar importacion.');
            msp2Redirect(omSelfRoute());
        }

        try {
            msp2LoadSpreadsheetLibrary();
        } catch (Throwable $e) {
            msp2SetFlash('danger', 'No fue posible cargar la libreria de Excel. Ejecuta `composer install` e intenta nuevamente.');
            omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
        }

        [$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['archivo_lecturas'] ?? null, msp2ImportUploadMaxBytes());
        if (!$uploadOk || !is_array($uploadMeta)) {
            msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo Excel valido.');
            omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
        }
        $originalName = (string) ($uploadMeta['name'] ?? 'importacion.xlsx');
        $uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

        $idTipoServicio = $serviceIdByCode[$codigoServicio] ?? 0;
        if ($idTipoServicio <= 0) {
            msp2SetFlash('danger', 'No se encontro el tipo de servicio para importar.');
            omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
        }

        $reemplazarLecturas = isset($_POST['reemplazar_lecturas']) && $_POST['reemplazar_lecturas'] === '1' ? 1 : 0;
        $confirmarAuto = omPostFlag('confirmar_auto');

        try {
            $previewPayload = ImportacionLecturasService::previsualizarImportacion(
                $conn,
                $codigoServicio,
                (int) $idTipoServicio,
                $periodoYm,
                $periodoFacturacion,
                $uploadTmpPath,
                $originalName,
                $reemplazarLecturas
            );
            omPreviewSessionWrite($periodoYm, $codigoServicio, $previewPayload);

            if ($confirmarAuto) {
                $params = [
                    'periodo' => $periodoYm,
                    'auto_confirm_import' => '1',
                    'auto_servicio' => $codigoServicio,
                ];
                msp2Redirect(omSelfRoute() . '?' . http_build_query($params) . '#' . omServiceAnchor($codigoServicio));
            }

            msp2SetFlash(
                'success',
                'Previsualización lista para ' . $codigoServicio . ': '
                . count((array) ($previewPayload['valid_rows'] ?? []))
                . ' filas. Revisa y confirma la importación.'
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible generar la previsualizacion de lecturas.');
        }

        omRedirectPeriodoConFoco($periodoYm, omServiceAnchor($codigoServicio));
    }

    if ($accion === 'crear_cargo_extra') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? omManualAdjustmentWindow($periodoFacturacion) : null;
        $targetRaw = trim((string) ($_POST['target_contrato_local'] ?? ''));
        $idTipoCargo = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaCargo = trim((string) ($_POST['fecha_cargo'] ?? ''));
        $descripcionCargo = mb_substr(msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? '')), 0, 500, 'UTF-8');
        [$okMonto, $montoCargo] = omDecimal((string) ($_POST['monto_cargo'] ?? ''), 2, true);
        $observacionesCargo = mb_substr(msp2NormalizeText((string) ($_POST['observaciones_cargo'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para registrar cargo extra.');
            msp2Redirect(omSelfRoute());
        }

        if (!msp2TableExists($conn, 'msp_cargos_salida') || !msp2TableExists($conn, 'msp_tipos_cargo_salida')) {
            msp2SetFlash('warning', 'La tabla de cargos extra no está disponible en este ambiente.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (!preg_match('/^(\d+):(\d+)$/', $targetRaw, $parts)) {
            msp2SetFlash('warning', 'Debes seleccionar un local/contrato válido para el cargo.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        $idContrato = (int) ($parts[1] ?? 0);
        $idLocal = (int) ($parts[2] ?? 0);

        if ($idTipoCargo === false || $idTipoCargo === null) {
            msp2SetFlash('warning', 'Debes seleccionar el tipo de cargo.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCargo) !== 1) {
            $fechaCargo = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaCargo < $manualAdjustMin || $fechaCargo > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . omFmtFecha($manualAdjustMin) . ' y ' . omFmtFecha($manualAdjustMax) . '.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (!$okMonto || $montoCargo === null || (float) $montoCargo <= 0) {
            msp2SetFlash('warning', 'El monto del cargo debe ser mayor a 0.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        try {
            $validTargetStmt = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 SELECT TOP 1 1
                 FROM dbo.msp_contratos_arriendo ca
                 INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                 WHERE ca.id_contrato_arriendo = :id_contrato
                   AND cl.id_local = :id_local
                   AND cl.estado_relacion = 1
                   AND cl.fecha_inicio <= EOMONTH(@periodo)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                   AND ca.fecha_inicio <= EOMONTH(@periodo)
                   AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                   AND ca.estado_contrato IN (1,2,3)"
            );
            $validTargetStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $validTargetStmt->bindValue(':id_contrato', $idContrato, PDO::PARAM_INT);
            $validTargetStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $validTargetStmt->execute();
            $targetOk = $validTargetStmt->fetchColumn() !== false;

            if (!$targetOk) {
                throw new RuntimeException('El local/contrato seleccionado no está vigente para el período.');
            }

            $validTipoStmt = $conn->prepare(
                "SELECT TOP 1 1
                 FROM dbo.msp_tipos_cargo_salida
                 WHERE id_tipo_cargo_salida = :id_tipo
                   AND activo = 1"
            );
            $validTipoStmt->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $validTipoStmt->execute();
            if ($validTipoStmt->fetchColumn() === false) {
                throw new RuntimeException('El tipo de cargo seleccionado no está activo.');
            }

            $insCargoStmt = $conn->prepare(
                "INSERT INTO dbo.msp_cargos_salida (
                    id_contrato_arriendo,
                    id_local,
                    id_tipo_cargo_salida,
                    fecha_cargo,
                    origen_cargo,
                    periodo_referencia,
                    descripcion_cargo,
                    monto_cargo,
                    es_estimado,
                    estado_cargo,
                    observaciones
                 ) VALUES (
                    :id_contrato,
                    :id_local,
                    :id_tipo,
                    :fecha_cargo,
                    4,
                    :periodo,
                    :descripcion,
                    :monto,
                    0,
                    1,
                    :observaciones
                 )"
            );
            $insCargoStmt->bindValue(':id_contrato', $idContrato, PDO::PARAM_INT);
            $insCargoStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $insCargoStmt->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $insCargoStmt->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
            $insCargoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $insCargoStmt->bindValue(':descripcion', $descripcionCargo, PDO::PARAM_STR);
            $insCargoStmt->bindValue(':monto', $montoCargo, PDO::PARAM_STR);
            $insCargoStmt->bindValue(':observaciones', $observacionesCargo !== '' ? $observacionesCargo : null, $observacionesCargo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insCargoStmt->execute();

            msp2SetFlash(
                'success',
                'Cargo extra registrado correctamente y quedará pendiente para el documento.',
                ['disable_success_burst' => true]
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible registrar el cargo extra.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-5');
    }

    if ($accion === 'crear_saldo_favor') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? omManualAdjustmentWindow($periodoFacturacion) : null;
        $idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaMovimiento = trim((string) ($_POST['fecha_movimiento'] ?? ''));
        [$okMonto, $montoSaldoFavor] = omDecimal((string) ($_POST['saldo_favor_monto'] ?? ''), 2, true);
        $observaciones = mb_substr(msp2NormalizeText((string) ($_POST['observaciones'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para registrar saldo a favor.');
            msp2Redirect(omSelfRoute());
        }

        if (!msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda') || !msp2TableExists($conn, 'msp_tiendas')) {
            msp2SetFlash('warning', 'El flujo de saldo a favor no está disponible en este ambiente.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        if ($idTienda === false || $idTienda === null) {
            msp2SetFlash('warning', 'Debes seleccionar una tienda válida.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMovimiento) !== 1) {
            $fechaMovimiento = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaMovimiento < $manualAdjustMin || $fechaMovimiento > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . omFmtFecha($manualAdjustMin) . ' y ' . omFmtFecha($manualAdjustMax) . '.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        if (!$okMonto || $montoSaldoFavor === null || (float) $montoSaldoFavor <= 0) {
            msp2SetFlash('warning', 'El monto de saldo a favor debe ser mayor a 0.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        try {
            $checkTiendaStmt = $conn->prepare('SELECT TOP 1 1 FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
            $checkTiendaStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
            $checkTiendaStmt->execute();
            if ($checkTiendaStmt->fetchColumn() === false) {
                throw new RuntimeException('La tienda seleccionada no existe.');
            }

            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');
            $conn->beginTransaction();

            $insSaldoStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 5, :monto, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insSaldoStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
            $insSaldoStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
            $insSaldoStmt->bindValue(':monto', (string) $montoSaldoFavor, PDO::PARAM_STR);
            $insSaldoStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insSaldoStmt->execute();
            $idMovimientoSaldoFavorCreado = (int) (omFetchFirstScalar($insSaldoStmt) ?: 0);
            if ($idMovimientoSaldoFavorCreado <= 0) {
                throw new RuntimeException('No fue posible identificar el movimiento creado de saldo a favor.');
            }

            if ($hasPeriodoItemsTable) {
                $insPeriodoItemStmt = $conn->prepare(
                    'INSERT INTO dbo.msp_saldo_favor_periodo_items
                        (periodo_facturacion, id_tienda, fecha_movimiento, monto_original, id_movimiento_saldo_favor, observaciones)
                     VALUES
                        (:periodo, :id_tienda, :fecha_movimiento, :monto_original, :id_movimiento, :observaciones)'
                );
                $insPeriodoItemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':monto_original', (string) $montoSaldoFavor, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_movimiento', $idMovimientoSaldoFavorCreado, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insPeriodoItemStmt->execute();
            }

            $conn->commit();

            msp2SetFlash('success', 'Saldo a favor manual registrado correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible registrar el saldo a favor manual.');
        }

        omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
    }

    if ($accion === 'actualizar_saldo_favor_manual') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? omManualAdjustmentWindow($periodoFacturacion) : null;
        $idMovimientoSaldoFavor = filter_input(INPUT_POST, 'id_movimiento_saldo_favor', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idTiendaNueva = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaMovimientoNueva = trim((string) ($_POST['fecha_movimiento'] ?? ''));
        [$okMontoNuevo, $montoSaldoFavorNuevo] = omDecimal((string) ($_POST['saldo_favor_monto'] ?? ''), 2, true);
        $observacionesNuevas = mb_substr(msp2NormalizeText((string) ($_POST['observaciones'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para editar saldo a favor.');
            msp2Redirect(omSelfRoute());
        }

        if (
            $idMovimientoSaldoFavor === false
            || $idMovimientoSaldoFavor === null
            || $idTiendaNueva === false
            || $idTiendaNueva === null
            || !msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
            || !msp2TableExists($conn, 'msp_tiendas')
            || !msp2TableExists($conn, 'msp_saldos_favor_tienda')
        ) {
            msp2SetFlash('warning', 'No fue posible identificar el ingreso manual a editar.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMovimientoNueva) !== 1) {
            $fechaMovimientoNueva = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if (
            $manualAdjustMin === ''
            || $manualAdjustMax === ''
            || $fechaMovimientoNueva < $manualAdjustMin
            || $fechaMovimientoNueva > $manualAdjustMax
        ) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . omFmtFecha($manualAdjustMin) . ' y ' . omFmtFecha($manualAdjustMax) . '.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        if (!$okMontoNuevo || $montoSaldoFavorNuevo === null || (float) $montoSaldoFavorNuevo <= 0) {
            msp2SetFlash('warning', 'El monto de saldo a favor debe ser mayor a 0.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        try {
            $checkTiendaNuevaStmt = $conn->prepare('SELECT TOP 1 1 FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
            $checkTiendaNuevaStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
            $checkTiendaNuevaStmt->execute();
            if ($checkTiendaNuevaStmt->fetchColumn() === false) {
                throw new RuntimeException('La tienda seleccionada no existe.');
            }

            $conn->beginTransaction();
            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');

            $movStmt = $conn->prepare(
                'SELECT
                    id_movimiento_saldo_favor,
                    id_tienda,
                    fecha_movimiento,
                    monto_movimiento,
                    observaciones
                 FROM dbo.msp_movimientos_saldo_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_movimiento_saldo_favor = :id_mov
                   AND tipo_movimiento = 5
                   AND monto_movimiento > 0'
            );
            $movStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
            $movStmt->execute();
            $movRow = $movStmt->fetch() ?: null;

            if (!is_array($movRow)) {
                throw new RuntimeException('El movimiento manual seleccionado no existe o ya no está disponible.');
            }

            $idTiendaActual = (int) ($movRow['id_tienda'] ?? 0);
            $fechaMovimientoActual = substr((string) ($movRow['fecha_movimiento'] ?? ''), 0, 10);
            $montoMovimientoActual = round((float) ($movRow['monto_movimiento'] ?? 0), 2);
            $observacionesActuales = mb_substr(trim((string) ($movRow['observaciones'] ?? '')), 0, 500, 'UTF-8');

            if ($idTiendaActual <= 0 || $montoMovimientoActual <= 0) {
                throw new RuntimeException('El movimiento manual seleccionado no es válido para edición.');
            }

            if ($fechaMovimientoActual < $manualAdjustMin || $fechaMovimientoActual > $manualAdjustMax) {
                throw new RuntimeException('Solo puedes editar ingresos manuales de la ventana de ajuste actual.');
            }

            if (!$hasPeriodoItemsTable) {
                $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
                $checkReversaStmt = $conn->prepare(
                    'SELECT TOP 1 1
                     FROM dbo.msp_movimientos_saldo_favor_tienda
                     WHERE id_tienda = :id_tienda
                       AND CHARINDEX(:marker, ISNULL(observaciones, \'\')) > 0'
                );
                $checkReversaStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
                $checkReversaStmt->bindValue(':marker', $reversaMarker, PDO::PARAM_STR);
                $checkReversaStmt->execute();
                if ($checkReversaStmt->fetchColumn() !== false) {
                    throw new RuntimeException('Ese ingreso manual ya fue revertido anteriormente.');
                }
            }

            $saldoStmt = $conn->prepare(
                'SELECT saldo_disponible
                 FROM dbo.msp_saldos_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_tienda = :id_tienda'
            );
            $saldoStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
            $saldoStmt->execute();
            $saldoDisponibleActual = round((float) ($saldoStmt->fetchColumn() ?: 0), 2);
            if ($saldoDisponibleActual < $montoMovimientoActual) {
                throw new RuntimeException('No se puede editar: el monto ya fue usado total o parcialmente.');
            }

            $idItemPeriodo = 0;
            if ($hasPeriodoItemsTable) {
                $itemStmt = $conn->prepare(
                    'SELECT TOP 1 id_saldo_favor_periodo_item
                     FROM dbo.msp_saldo_favor_periodo_items WITH (UPDLOCK, HOLDLOCK)
                     WHERE id_movimiento_saldo_favor = :id_mov
                       AND periodo_facturacion = :periodo
                       AND estado_item = 1'
                );
                $itemStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
                $itemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $itemStmt->execute();
                $idItemPeriodo = (int) ($itemStmt->fetchColumn() ?: 0);
                if ($idItemPeriodo <= 0) {
                    throw new RuntimeException('El ingreso manual no pertenece al periodo seleccionado o ya fue revertido.');
                }

                if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
                    $itemAplicacionesStmt = $conn->prepare(
                        'SELECT COUNT(*)
                         FROM dbo.msp_saldo_favor_periodo_aplicaciones
                         WHERE id_saldo_favor_periodo_item = :id_item
                           AND estado_aplicacion = 1'
                    );
                    $itemAplicacionesStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                    $itemAplicacionesStmt->execute();
                    $aplicacionesActivasItem = (int) ($itemAplicacionesStmt->fetchColumn() ?: 0);
                    if ($aplicacionesActivasItem > 0) {
                        throw new RuntimeException('No se puede editar: el ingreso manual ya tiene aplicaciones activas en documentos.');
                    }
                }
            }

            $montoNuevoValue = round((float) $montoSaldoFavorNuevo, 2);
            if (
                $idTiendaActual === (int) $idTiendaNueva
                && $fechaMovimientoActual === $fechaMovimientoNueva
                && abs($montoMovimientoActual - $montoNuevoValue) <= 0.0001
                && $observacionesActuales === $observacionesNuevas
            ) {
                $conn->rollBack();
                msp2SetFlash('info', 'No se detectaron cambios para actualizar en el ingreso manual.');
                omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
            }

            $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
            $obsReversa = 'Ajuste manual (reversa) de ingreso #' . (int) $idMovimientoSaldoFavor . ' ' . $reversaMarker;
            $insReversaStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 3, :monto_reversa, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insReversaStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
            $insReversaStmt->bindValue(':fecha_movimiento', $fechaMovimientoActual, PDO::PARAM_STR);
            $insReversaStmt->bindValue(':monto_reversa', (string) (-1 * $montoMovimientoActual), PDO::PARAM_STR);
            $insReversaStmt->bindValue(':observaciones', $obsReversa, PDO::PARAM_STR);
            $insReversaStmt->execute();
            $idMovimientoReversa = (int) (omFetchFirstScalar($insReversaStmt) ?: 0);
            if ($idMovimientoReversa <= 0) {
                throw new RuntimeException('No fue posible registrar la reversa del ingreso manual original.');
            }

            if ($hasPeriodoItemsTable && $idItemPeriodo > 0) {
                $updItemStmt = $conn->prepare(
                    'UPDATE dbo.msp_saldo_favor_periodo_items
                     SET estado_item = 5,
                         id_movimiento_reversa = :id_mov_reversa,
                         fecha_actualizacion = SYSDATETIME()
                     WHERE id_saldo_favor_periodo_item = :id_item
                       AND estado_item = 1'
                );
                $updItemStmt->bindValue(':id_mov_reversa', $idMovimientoReversa, PDO::PARAM_INT);
                $updItemStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                $updItemStmt->execute();
                if ($updItemStmt->rowCount() <= 0) {
                    throw new RuntimeException('No fue posible cerrar el ingreso manual original.');
                }
            }

            $insNuevoIngresoStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 5, :monto, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insNuevoIngresoStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
            $insNuevoIngresoStmt->bindValue(':fecha_movimiento', $fechaMovimientoNueva, PDO::PARAM_STR);
            $insNuevoIngresoStmt->bindValue(':monto', (string) $montoNuevoValue, PDO::PARAM_STR);
            $insNuevoIngresoStmt->bindValue(':observaciones', $observacionesNuevas !== '' ? $observacionesNuevas : null, $observacionesNuevas !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insNuevoIngresoStmt->execute();
            $idMovimientoNuevo = (int) (omFetchFirstScalar($insNuevoIngresoStmt) ?: 0);
            if ($idMovimientoNuevo <= 0) {
                throw new RuntimeException('No fue posible registrar el ingreso manual actualizado.');
            }

            if ($hasPeriodoItemsTable) {
                $insPeriodoItemStmt = $conn->prepare(
                    'INSERT INTO dbo.msp_saldo_favor_periodo_items
                        (periodo_facturacion, id_tienda, fecha_movimiento, monto_original, id_movimiento_saldo_favor, observaciones)
                     VALUES
                        (:periodo, :id_tienda, :fecha_movimiento, :monto_original, :id_movimiento, :observaciones)'
                );
                $insPeriodoItemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':fecha_movimiento', $fechaMovimientoNueva, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':monto_original', (string) $montoNuevoValue, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_movimiento', $idMovimientoNuevo, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':observaciones', $observacionesNuevas !== '' ? $observacionesNuevas : null, $observacionesNuevas !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insPeriodoItemStmt->execute();
            }

            $conn->commit();
            msp2SetFlash('success', 'Ingreso manual actualizado correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible actualizar el ingreso manual.');
        }

        omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
    }

    if ($accion === 'revertir_saldo_favor_manual' || $accion === 'cancelar_saldo_favor_manual') {
        $esCancelacionSaldoManual = $accion === 'cancelar_saldo_favor_manual';
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? omManualAdjustmentWindow($periodoFacturacion) : null;
        $motivoCancelacion = mb_substr(msp2NormalizeText((string) ($_POST['confirm_reason'] ?? '')), 0, 500, 'UTF-8');
        $idMovimientoSaldoFavor = filter_input(INPUT_POST, 'id_movimiento_saldo_favor', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para revertir saldo a favor.');
            msp2Redirect(omSelfRoute());
        }

        if (
            $idMovimientoSaldoFavor === false
            || $idMovimientoSaldoFavor === null
            || !msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
            || !msp2TableExists($conn, 'msp_tiendas')
            || !msp2TableExists($conn, 'msp_saldos_favor_tienda')
        ) {
            msp2SetFlash('warning', 'No fue posible identificar el ingreso manual a revertir.');
            omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
        }

        try {
            $conn->beginTransaction();
            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');

            $movStmt = $conn->prepare(
                'SELECT
                    id_movimiento_saldo_favor,
                    id_tienda,
                    fecha_movimiento,
                    monto_movimiento
                 FROM dbo.msp_movimientos_saldo_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_movimiento_saldo_favor = :id_mov
                   AND tipo_movimiento = 5
                   AND monto_movimiento > 0'
            );
            $movStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
            $movStmt->execute();
            $movRow = $movStmt->fetch() ?: null;

            if (!is_array($movRow)) {
                throw new RuntimeException('El movimiento manual seleccionado no existe o ya no está disponible.');
            }

            $idTienda = (int) ($movRow['id_tienda'] ?? 0);
            $fechaMovimiento = substr((string) ($movRow['fecha_movimiento'] ?? ''), 0, 10);
            $montoMovimiento = round((float) ($movRow['monto_movimiento'] ?? 0), 2);
            if ($idTienda <= 0 || $montoMovimiento <= 0) {
                throw new RuntimeException('El movimiento manual seleccionado no es válido para reversa.');
            }

            $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
            $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
            if (
                $manualAdjustMin === ''
                || $manualAdjustMax === ''
                || $fechaMovimiento < $manualAdjustMin
                || $fechaMovimiento > $manualAdjustMax
            ) {
                throw new RuntimeException('Solo puedes revertir ingresos manuales de la ventana de ajuste actual.');
            }

            $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
            $checkReversaStmt = $conn->prepare(
                'SELECT TOP 1 1
                 FROM dbo.msp_movimientos_saldo_favor_tienda
                 WHERE id_tienda = :id_tienda
                   AND CHARINDEX(:marker, ISNULL(observaciones, \'\')) > 0'
            );
            $checkReversaStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $checkReversaStmt->bindValue(':marker', $reversaMarker, PDO::PARAM_STR);
            $checkReversaStmt->execute();
            if ($checkReversaStmt->fetchColumn() !== false) {
                throw new RuntimeException('Ese ingreso manual ya fue revertido anteriormente.');
            }

            $saldoStmt = $conn->prepare(
                'SELECT saldo_disponible
                 FROM dbo.msp_saldos_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_tienda = :id_tienda'
            );
            $saldoStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $saldoStmt->execute();
            $saldoDisponibleActual = round((float) ($saldoStmt->fetchColumn() ?: 0), 2);
            if ($saldoDisponibleActual < $montoMovimiento) {
                throw new RuntimeException('No se puede revertir: el monto ya fue usado total o parcialmente.');
            }

            $idItemPeriodo = 0;
            if ($hasPeriodoItemsTable) {
                $itemStmt = $conn->prepare(
                    'SELECT TOP 1 id_saldo_favor_periodo_item
                     FROM dbo.msp_saldo_favor_periodo_items WITH (UPDLOCK, HOLDLOCK)
                     WHERE id_movimiento_saldo_favor = :id_mov
                       AND periodo_facturacion = :periodo
                       AND estado_item = 1'
                );
                $itemStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
                $itemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $itemStmt->execute();
                $idItemPeriodo = (int) ($itemStmt->fetchColumn() ?: 0);
                if ($idItemPeriodo <= 0) {
                    throw new RuntimeException('El ingreso manual no pertenece al periodo seleccionado o ya fue revertido.');
                }

                if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
                    $itemAplicacionesStmt = $conn->prepare(
                        'SELECT COUNT(*)
                         FROM dbo.msp_saldo_favor_periodo_aplicaciones
                         WHERE id_saldo_favor_periodo_item = :id_item
                           AND estado_aplicacion = 1'
                    );
                    $itemAplicacionesStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                    $itemAplicacionesStmt->execute();
                    $aplicacionesActivasItem = (int) ($itemAplicacionesStmt->fetchColumn() ?: 0);
                    if ($aplicacionesActivasItem > 0) {
                        throw new RuntimeException('No se puede revertir: el ingreso manual ya tiene aplicaciones activas en documentos.');
                    }
                }
            }

            $obsReversa = 'Reversa manual de ingreso #' . (int) $idMovimientoSaldoFavor . ' ' . $reversaMarker;
            if ($motivoCancelacion !== '') {
                $obsReversa .= ' | Motivo: ' . $motivoCancelacion;
            }
            $insReversaStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 3, :monto_reversa, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insReversaStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $insReversaStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
            $insReversaStmt->bindValue(':monto_reversa', (string) (-1 * $montoMovimiento), PDO::PARAM_STR);
            $insReversaStmt->bindValue(':observaciones', $obsReversa, PDO::PARAM_STR);
            $insReversaStmt->execute();
            $idMovimientoReversa = (int) (omFetchFirstScalar($insReversaStmt) ?: 0);
            if ($idMovimientoReversa <= 0) {
                throw new RuntimeException('No fue posible registrar la reversa del ingreso manual.');
            }

            if ($hasPeriodoItemsTable && $idItemPeriodo > 0) {
                $updItemStmt = $conn->prepare(
                    'UPDATE dbo.msp_saldo_favor_periodo_items
                     SET estado_item = 5,
                         id_movimiento_reversa = :id_mov_reversa,
                         fecha_actualizacion = SYSDATETIME()
                     WHERE id_saldo_favor_periodo_item = :id_item
                       AND estado_item = 1'
                );
                $updItemStmt->bindValue(':id_mov_reversa', $idMovimientoReversa, PDO::PARAM_INT);
                $updItemStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                $updItemStmt->execute();
                if ($updItemStmt->rowCount() <= 0) {
                    throw new RuntimeException('No fue posible marcar el ingreso manual como revertido.');
                }
            }

            $conn->commit();
            msp2SetFlash('success', $esCancelacionSaldoManual ? 'Ingreso manual eliminado correctamente.' : 'Ingreso manual revertido correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : ($esCancelacionSaldoManual ? 'No fue posible eliminar el ingreso manual.' : 'No fue posible revertir el ingreso manual.'));
        }

        omRedirectManualAdjustTab($periodoYm, 'saldo_favor');
    }

    if ($accion === 'actualizar_cargo_extra') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? omManualAdjustmentWindow($periodoFacturacion) : null;
        $idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idTipoCargo = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaCargo = trim((string) ($_POST['fecha_cargo'] ?? ''));
        $descripcionCargo = mb_substr(msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? '')), 0, 500, 'UTF-8');
        $observacionesCargo = mb_substr(msp2NormalizeText((string) ($_POST['observaciones_cargo'] ?? '')), 0, 500, 'UTF-8');
        [$okMonto, $montoCargo] = omDecimal((string) ($_POST['monto_cargo'] ?? ''), 2, true);

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido.');
            msp2Redirect(omSelfRoute());
        }

        if ($idCargoSalida === false || $idCargoSalida === null) {
            msp2SetFlash('warning', 'Cargo invalido.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (!msp2TableExists($conn, 'msp_cargos_salida') || !msp2TableExists($conn, 'msp_tipos_cargo_salida')) {
            msp2SetFlash('warning', 'La tabla de cargos extra no está disponible en este ambiente.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if ($idTipoCargo === false || $idTipoCargo === null) {
            msp2SetFlash('warning', 'Debes seleccionar el tipo de cargo.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCargo) !== 1) {
            $fechaCargo = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaCargo < $manualAdjustMin || $fechaCargo > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . omFmtFecha($manualAdjustMin) . ' y ' . omFmtFecha($manualAdjustMax) . '.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (!$okMonto || $montoCargo === null || (float) $montoCargo <= 0) {
            msp2SetFlash('warning', 'El monto del cargo debe ser mayor a 0.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        try {
            $updCargoStmt = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 UPDATE dbo.msp_cargos_salida
                 SET id_tipo_cargo_salida = :id_tipo,
                     fecha_cargo = :fecha_cargo,
                     descripcion_cargo = :descripcion,
                     monto_cargo = :monto,
                     observaciones = :observaciones
                 WHERE id_cargo_salida = :id_cargo
                   AND id_documento_cobro IS NULL
                   AND estado_cargo IN (1,2)
                   AND ISNULL(periodo_referencia, @periodo) = @periodo"
            );
            $updCargoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $updCargoStmt->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $updCargoStmt->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
            $updCargoStmt->bindValue(':descripcion', $descripcionCargo, PDO::PARAM_STR);
            $updCargoStmt->bindValue(':monto', $montoCargo, PDO::PARAM_STR);
            $updCargoStmt->bindValue(':observaciones', $observacionesCargo !== '' ? $observacionesCargo : null, $observacionesCargo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updCargoStmt->bindValue(':id_cargo', (int) $idCargoSalida, PDO::PARAM_INT);
            $updCargoStmt->execute();

            if ($updCargoStmt->rowCount() <= 0) {
                throw new RuntimeException('El cargo ya no está pendiente o no pertenece al período seleccionado.');
            }

            msp2SetFlash('success', 'Cargo extra actualizado correctamente.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible actualizar el cargo extra.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-5');
    }

    if ($accion === 'cancelar_cargo_extra') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $motivoCancelacion = mb_substr(msp2NormalizeText((string) ($_POST['confirm_reason'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido.');
            msp2Redirect(omSelfRoute());
        }

        if ($idCargoSalida === false || $idCargoSalida === null) {
            msp2SetFlash('warning', 'Cargo invalido.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        if (!msp2TableExists($conn, 'msp_cargos_salida')) {
            msp2SetFlash('warning', 'La tabla de cargos extra no está disponible en este ambiente.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-5');
        }

        try {
            $cancelCargoStmt = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 DECLARE @motivo NVARCHAR(500) = :motivo;
                 UPDATE dbo.msp_cargos_salida
                 SET estado_cargo = 5,
                     observaciones = CASE
                        WHEN @motivo = '' THEN observaciones
                        WHEN observaciones IS NULL OR LTRIM(RTRIM(observaciones)) = '' THEN @motivo
                        ELSE CONCAT(observaciones, N' | Cancelado: ', @motivo)
                     END
                 WHERE id_cargo_salida = :id_cargo
                   AND id_documento_cobro IS NULL
                   AND estado_cargo IN (1,2)
                   AND ISNULL(periodo_referencia, @periodo) = @periodo"
            );
            $cancelCargoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $cancelCargoStmt->bindValue(':motivo', $motivoCancelacion, PDO::PARAM_STR);
            $cancelCargoStmt->bindValue(':id_cargo', (int) $idCargoSalida, PDO::PARAM_INT);
            $cancelCargoStmt->execute();

            if ($cancelCargoStmt->rowCount() <= 0) {
                throw new RuntimeException('El cargo ya no está pendiente o no pertenece al período seleccionado.');
            }

            msp2SetFlash('success', 'Cargo extra cancelado correctamente.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cancelar el cargo extra.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-5');
    }

    if ($accion === 'generar_cobros' || $accion === 'generar_documentos') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $redirectFocus = 'paso-3';
        $autoDocs = $accion === 'generar_cobros' && omPostFlag('auto_docs');
        $autoRep = omPostFlag('reemplazar') ? 1 : 0;
        $autoAplicarCargosExtra = omPostFlag('aplicar_cargos_extra');
        $docsServiceProfile = strtoupper(trim((string) ($_POST['perfil_servicios_docs'] ?? 'ALL')));
        if (!in_array($docsServiceProfile, ['ALL', 'LUZ_ONLY', 'LUZ_GAS', 'LUZ_AGUA', 'LUZ_GAS_AGUA', 'LUZ_CON_AGUA'], true)) {
            $docsServiceProfile = 'ALL';
        }
        $holidayDatesAuto = [];
            $yearPeriodoAuto = (int) substr($periodoFacturacion, 0, 4);
            if ($yearPeriodoAuto > 0) {
                $holidayDatesAuto = omFetchHolidayDatesForYear($conn, $yearPeriodoAuto);
            }
        $autoDias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 120],
        ]);
        if ($autoDias === false || $autoDias === null) {
            $autoDias = 5;
        }
        $autoDiasCalendario = omBusinessDaysOffsetFromMonthStart($periodoFacturacion, (int) $autoDias, $holidayDatesAuto);
        if ($autoDiasCalendario === null) {
            $autoDiasCalendario = (int) $autoDias;
        }

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido para generacion.');
            msp2Redirect(omSelfRoute());
        }

        try {
            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion, true);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            $reopenedTemporal = omSwitchCierreToBorradorIfCalculado($conn, $idCierre, 'Generación de cobros/documentos');

            try {
                if ($accion === 'generar_cobros') {
                    $selectedServices = omSelectedServicesFromPost($serviceCodes);
                    if ($selectedServices === []) {
                        throw new RuntimeException('Debes seleccionar al menos un servicio para generar cobros.');
                    }

                    $cobrosGenerados = OperacionMensualService::generarCobros(
                        $conn,
                        (int) $idCierre,
                        omPostFlag('reemplazar'),
                        $selectedServices
                    );
                    $mensajeCobros = 'Cobros generados (' . implode(', ', $selectedServices) . '): ' . (int) $cobrosGenerados . '.';

                    if ($autoDocs) {
                        try {
                            $saldoPreviewAuto = omBuildSaldoFavorSuggestions($conn, $periodoFacturacion);
                            $extraPreviewAuto = $autoAplicarCargosExtra
                                ? omBuildExtraChargesPendingSnapshot($conn, $periodoFacturacion, 30)
                                : ['disponible' => false, 'pendientes_count' => 0, 'pendientes_total' => 0.0, 'rows' => []];
                            $saldoAplicacionesAnuladasPrevias = 0;
                            if ($autoRep === 1) {
                                $saldoAplicacionesAnuladasPrevias = omCancelSaldoFavorPeriodoAplicaciones(
                                    $conn,
                                    $periodoFacturacion
                                );
                            }
                            $docsData = DocumentosCobroService::generateDocumentsForCierre(
                                $conn,
                                (int) $idCierre,
                                (int) $autoDiasCalendario,
                                (int) $autoRep,
                                $autoAplicarCargosExtra,
                                $docsServiceProfile
                            );
                            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                            }
                            $mensajeGeneracion =
                                $mensajeCobros
                                . ' Documentos: ' . (int) ($docsData['documentos_generados'] ?? 0)
                                . ' | Items: ' . (int) ($docsData['items_generados'] ?? 0)
                                . ' | Items recompuestos: ' . (int) ($docsData['items_recompuestos'] ?? 0) . '.';
                            $flashGeneracion = 'success';
                            if ($saldoAplicacionesAnuladasPrevias > 0) {
                                $mensajeGeneracion .= ' Aplicaciones previas de saldo anuladas: ' . $saldoAplicacionesAnuladasPrevias . '.';
                            }
                            $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion);
                            $saldoAuto['aplicaciones_anuladas_previas'] = $saldoAplicacionesAnuladasPrevias;
                            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                                $mensajeGeneracion .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                                    $flashGeneracion = 'warning';
                                }
                            }
                            $extraAppliedAuto = $autoAplicarCargosExtra
                                ? omBuildExtraChargesAppliedSnapshot($conn, $periodoFacturacion, 30)
                                : ['disponible' => false, 'aplicados_count' => 0, 'aplicados_total' => 0.0, 'rows' => []];
                            $saldoPreviewSnapshot = [
                                'disponible' => (bool) ($saldoPreviewAuto['disponible'] ?? false),
                                'docs_sugeridos' => (int) ($saldoPreviewAuto['docs_sugeridos'] ?? 0),
                                'tiendas_sugeridas' => (int) ($saldoPreviewAuto['tiendas_sugeridas'] ?? 0),
                                'total_sugerido' => round((float) ($saldoPreviewAuto['total_sugerido'] ?? 0), 2),
                                'sugerencias' => array_slice(is_array($saldoPreviewAuto['sugerencias'] ?? null) ? $saldoPreviewAuto['sugerencias'] : [], 0, 30),
                                'por_tienda' => array_slice(is_array($saldoPreviewAuto['por_tienda'] ?? null) ? $saldoPreviewAuto['por_tienda'] : [], 0, 20),
                            ];
                            $extraPreviewSnapshot = [
                                'disponible' => (bool) ($extraPreviewAuto['disponible'] ?? false),
                                'pendientes_count' => (int) ($extraPreviewAuto['pendientes_count'] ?? 0),
                                'pendientes_total' => round((float) ($extraPreviewAuto['pendientes_total'] ?? 0), 2),
                                'rows' => array_slice(is_array($extraPreviewAuto['rows'] ?? null) ? $extraPreviewAuto['rows'] : [], 0, 20),
                            ];
                            msp2SetFlash(
                                $flashGeneracion,
                                $mensajeGeneracion,
                                [
                                    'auto_apply' => [
                                        'periodo' => $periodoYm,
                                        'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                                        'saldo_preview' => $saldoPreviewSnapshot,
                                        'saldo_result' => $saldoAuto,
                                        'extra_preview' => $extraPreviewSnapshot,
                                        'extra_applied' => $extraAppliedAuto,
                                    ],
                                ]
                            );
                            $redirectFocus = 'paso-6';
                        } catch (Throwable $docsError) {
                            msp2SetFlash(
                                'warning',
                                $mensajeCobros . ' No fue posible generar documentos en el mismo paso: ' . $docsError->getMessage()
                            );
                        }
                    } else {
                        msp2SetFlash('success', $mensajeCobros);
                    }
                } else {
                    $holidayDatesDoc = [];
                    $yearPeriodoDoc = (int) substr($periodoFacturacion, 0, 4);
                    if ($yearPeriodoDoc > 0) {
                        $holidayDatesDoc = omFetchHolidayDatesForYear($conn, $yearPeriodoDoc);
                    }
                    $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 0, 'max_range' => 120],
                    ]);
                    if ($dias === false || $dias === null) {
                        $dias = 5;
                    }
                    $diasCalendario = omBusinessDaysOffsetFromMonthStart($periodoFacturacion, (int) $dias, $holidayDatesDoc);
                    if ($diasCalendario === null) {
                        $diasCalendario = (int) $dias;
                    }
                    $rep = omPostFlag('reemplazar') ? 1 : 0;
                    $aplicarCargosExtra = omPostFlag('aplicar_cargos_extra');
                    $saldoPreviewAuto = omBuildSaldoFavorSuggestions($conn, $periodoFacturacion);
                    $extraPreviewAuto = $aplicarCargosExtra
                        ? omBuildExtraChargesPendingSnapshot($conn, $periodoFacturacion, 30)
                        : ['disponible' => false, 'pendientes_count' => 0, 'pendientes_total' => 0.0, 'rows' => []];
                    $saldoAplicacionesAnuladasPrevias = 0;
                    if ($rep === 1) {
                        $saldoAplicacionesAnuladasPrevias = omCancelSaldoFavorPeriodoAplicaciones(
                            $conn,
                            $periodoFacturacion
                        );
                    }
                    $docsData = DocumentosCobroService::generateDocumentsForCierre(
                        $conn,
                        (int) $idCierre,
                        (int) $diasCalendario,
                        (int) $rep,
                        $aplicarCargosExtra,
                        $docsServiceProfile
                    );
                    if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                        PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                    }
                    $docsGenerados = (int) ($docsData['documentos_generados'] ?? 0);
                    $itemsGenerados = (int) ($docsData['items_generados'] ?? 0);
                    $itemsRecompuestos = (int) ($docsData['items_recompuestos'] ?? 0);
                    $mensajeGeneracion =
                        'Documentos generados: ' . (int) $docsGenerados
                        . ' | Items: ' . (int) $itemsGenerados
                        . ' | Items recompuestos: ' . $itemsRecompuestos . '.';
                    $flashGeneracion = 'success';
                    if ($saldoAplicacionesAnuladasPrevias > 0) {
                        $mensajeGeneracion .= ' Aplicaciones previas de saldo anuladas: ' . $saldoAplicacionesAnuladasPrevias . '.';
                    }
                    $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion);
                    $saldoAuto['aplicaciones_anuladas_previas'] = $saldoAplicacionesAnuladasPrevias;
                    if ((bool) ($saldoAuto['disponible'] ?? false)) {
                        $mensajeGeneracion .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                        $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                        if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                            $flashGeneracion = 'warning';
                        }
                    }
                    $extraAppliedAuto = $aplicarCargosExtra
                        ? omBuildExtraChargesAppliedSnapshot($conn, $periodoFacturacion, 30)
                        : ['disponible' => false, 'aplicados_count' => 0, 'aplicados_total' => 0.0, 'rows' => []];
                    $saldoPreviewSnapshot = [
                        'disponible' => (bool) ($saldoPreviewAuto['disponible'] ?? false),
                        'docs_sugeridos' => (int) ($saldoPreviewAuto['docs_sugeridos'] ?? 0),
                        'tiendas_sugeridas' => (int) ($saldoPreviewAuto['tiendas_sugeridas'] ?? 0),
                        'total_sugerido' => round((float) ($saldoPreviewAuto['total_sugerido'] ?? 0), 2),
                        'sugerencias' => array_slice(is_array($saldoPreviewAuto['sugerencias'] ?? null) ? $saldoPreviewAuto['sugerencias'] : [], 0, 30),
                        'por_tienda' => array_slice(is_array($saldoPreviewAuto['por_tienda'] ?? null) ? $saldoPreviewAuto['por_tienda'] : [], 0, 20),
                    ];
                    $extraPreviewSnapshot = [
                        'disponible' => (bool) ($extraPreviewAuto['disponible'] ?? false),
                        'pendientes_count' => (int) ($extraPreviewAuto['pendientes_count'] ?? 0),
                        'pendientes_total' => round((float) ($extraPreviewAuto['pendientes_total'] ?? 0), 2),
                        'rows' => array_slice(is_array($extraPreviewAuto['rows'] ?? null) ? $extraPreviewAuto['rows'] : [], 0, 20),
                    ];
                    msp2SetFlash(
                        $flashGeneracion,
                        $mensajeGeneracion,
                        [
                            'auto_apply' => [
                                'periodo' => $periodoYm,
                                'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                                'saldo_preview' => $saldoPreviewSnapshot,
                                'saldo_result' => $saldoAuto,
                                'extra_preview' => $extraPreviewSnapshot,
                                'extra_applied' => $extraAppliedAuto,
                            ],
                        ]
                    );
                    $redirectFocus = 'paso-6';
                }
            } finally {
                omRestoreCalculadoIfWasTemporal($conn, $idCierre, $reopenedTemporal);
            }

            omMarkCierreCalculadoIfBorrador($conn, $idCierre);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '50055') || str_contains($message, 'Ya existen documentos para ese periodo_facturacion')) {
                msp2SetFlash('warning', 'Ya existen documentos del periodo. Marca `Reemplazar documentos` y vuelve a ejecutar.');
            } elseif (str_contains($message, '50034') || str_contains($message, 'Ya existen documentos emitidos para este periodo_facturacion')) {
                msp2SetFlash('warning', 'No puedes regenerar cobros con documentos ya emitidos. Regenera documentos con `Reemplazar documentos`.');
            } elseif (str_contains($message, '50038') || str_contains($message, 'cerrado')) {
                msp2SetFlash('warning', 'El período está cerrado. Reábrelo a Borrador para recalcular.');
            } elseif (str_contains($message, '50039') || str_contains($message, 'Borrador')) {
                msp2SetFlash('warning', 'Solo puedes recalcular en período Borrador. Reabre el período y vuelve a intentar.');
            } else {
                msp2SetFlash('danger', $e instanceof RuntimeException ? $message : 'No fue posible ejecutar la generacion.');
            }
        }

        omRedirectPeriodoConFoco($periodoYm, $redirectFocus);
    }

    if ($accion === 'borrar_generacion') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $borrarDocumentos = omPostFlag('borrar_documentos');
        $borrarCobros = omPostFlag('borrar_cobros');
        $borrarPagos = omPostFlag('borrar_pagos');
        $borrarCargosSalidaAsociados = omPostFlag('borrar_cargos_salida_asociados');
        $cancelarLotesProgramados = omPostFlag('cancelar_lotes_programados');

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido para corregir generacion.');
            msp2Redirect(omSelfRoute());
        }

        if (!$borrarDocumentos && !$borrarCobros && !$borrarPagos && !$borrarCargosSalidaAsociados && !$cancelarLotesProgramados) {
            msp2SetFlash('warning', 'Selecciona al menos una capa para corregir (cancelar lotes, pagos, cobros, documentos y/o cargos de salida asociados).');
            omRedirectPeriodoConFoco($periodoYm, 'paso-3');
        }

        try {
            $lotesCancelados = 0;
            $saldoAplicacionesAnuladas = 0;
            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }

            if ($cancelarLotesProgramados && EnvioLotesProgramadosService::isAvailable($conn)) {
                $lotesCancelados = EnvioLotesProgramadosService::cancelActiveLotesByPeriodo($conn, $periodoFacturacion);
            }

            $resDelete = OperacionMensualService::borrarGeneracion(
                $conn,
                (int) $idCierre,
                $borrarDocumentos,
                $borrarCobros,
                $borrarPagos,
                $borrarCargosSalidaAsociados
            );
            $docsBorrados = (int) ($resDelete['docs_borrados'] ?? 0);
            $itemsBorrados = (int) ($resDelete['items_borrados'] ?? 0);
            $cobrosBorrados = (int) ($resDelete['cobros_borrados'] ?? 0);
            $pagosBorrados = (int) ($resDelete['pagos_borrados'] ?? 0);
            $cargosSalidaDesvinculados = (int) ($resDelete['cargos_salida_desvinculados'] ?? 0);
            $saldoAplicacionesAnuladas = (int) ($resDelete['saldo_favor_aplicaciones_desvinculadas'] ?? 0);
            $pagoContratoDetalleBorrado = (int) ($resDelete['pago_contrato_detalle_borrado'] ?? 0);
            $archivosPdfBorrados = (int) ($resDelete['archivos_pdf_borrados'] ?? 0);

            msp2SetFlash(
                'success',
                'Corrección aplicada: lotes activos cancelados ' . $lotesCancelados
                . ' | aplicaciones saldo anuladas ' . $saldoAplicacionesAnuladas
                . ' | detalle pago contrato borrado ' . $pagoContratoDetalleBorrado
                . ' | respaldo PDFs borrado ' . $archivosPdfBorrados
                . ' | cargos de salida desvinculados ' . $cargosSalidaDesvinculados
                . ' | pagos borrados ' . $pagosBorrados
                . ' | documentos borrados ' . $docsBorrados
                . ' | items borrados ' . $itemsBorrados
                . ' | cobros borrados ' . $cobrosBorrados . '.'
            );
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, '50092')) {
                msp2SetFlash('warning', 'No se pueden borrar documentos: existen pagos asociados en ese periodo.');
            } elseif (str_contains($message, '50093')) {
                msp2SetFlash('warning', 'No se pueden borrar documentos: existen cargos de salida asociados.');
            } elseif (str_contains($message, '50094')) {
                msp2SetFlash('warning', 'No se pueden borrar documentos: existen movimientos de garantía asociados.');
            } elseif (str_contains($message, '50095')) {
                msp2SetFlash('warning', 'No se pueden borrar cobros mientras existan documentos que los referencian. Borra primero documentos.');
	            } elseif (str_contains($message, '50096')) {
	                msp2SetFlash('warning', 'No se pueden borrar pagos del periodo: existen movimientos de garantía asociados.');
	            } elseif (str_contains($message, '50075')) {
	                msp2SetFlash('warning', 'No se pueden revertir pagos del periodo porque parte del excedente ya fue utilizado como saldo a favor en otros documentos. Primero debes anular esas aplicaciones.');
            } elseif (str_contains($message, 'FK_msp_pcod_pago')) {
                msp2SetFlash('warning', 'No se pudieron borrar pagos porque existen detalles de pago por contrato vinculados. Intenta nuevamente con la corrección completa del período.');
            } elseif (str_contains($message, '50038') || str_contains($message, 'cerrado')) {
                msp2SetFlash('warning', 'El período está cerrado. Reábrelo a Borrador para usar la zona de corrección.');
            } elseif (str_contains($message, '50039') || str_contains($message, 'Borrador')) {
                msp2SetFlash('warning', 'La corrección solo está habilitada para períodos en Borrador.');
            } else {
                msp2SetFlash('danger', $e instanceof RuntimeException ? $message : 'No fue posible corregir la generacion.');
            }
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-3');
    }

    if ($accion === 'generar_documentos_sin_servicio') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 120],
        ]);
        if ($dias === false || $dias === null) {
            $dias = 5;
        }

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para generar documentos sin servicio.');
            msp2Redirect(omSelfRoute());
        }

        $reopenedTemporal = false;
        try {
            if (!PoolDocumentosPeriodoService::isAvailable($conn)) {
                throw new RuntimeException('Pool de documentos no disponible en este ambiente.');
            }

            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion, true);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            $reopenedTemporal = omSwitchCierreToBorradorIfCalculado($conn, $idCierre, 'Generar documentos sin servicio');

            PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            $targetTiendasSinServicio = PoolDocumentosPeriodoService::fetchTargetTiendasSinServicio($conn, $periodoFacturacion);
            if ($targetTiendasSinServicio === []) {
                msp2SetFlash('info', 'No hay tiendas sin servicio pendientes de materializar en este período.');
                omRedirectManualAdjustTab($periodoYm, 'cargo_extra');
            }

            $docsData = DocumentosCobroService::generateDocumentsForCierre(
                $conn,
                $idCierre,
                (int) $dias,
                1,
                true,
                'ALL',
                $targetTiendasSinServicio
            );
            PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            $statsSinServicio = PoolDocumentosPeriodoService::fetchSinServicioStats($conn, $periodoFacturacion);
            omMarkCierreCalculadoIfBorrador($conn, $idCierre);
            omRestoreCalculadoIfWasTemporal($conn, $idCierre, $reopenedTemporal);

            msp2SetFlash(
                'success',
                'Sin servicio: documentos generados ' . (int) ($docsData['documentos_generados'] ?? 0)
                . ' | items recompuestos ' . (int) ($docsData['items_recompuestos'] ?? 0)
                . ' | tiendas documentadas ' . (int) ($statsSinServicio['tiendas_documentadas'] ?? 0)
                . ' de ' . (int) ($statsSinServicio['tiendas_objetivo'] ?? 0) . '.'
            );
        } catch (Throwable $e) {
            omRestoreCalculadoIfWasTemporal($conn, (int) ($idCierre ?? 0), $reopenedTemporal);
            msp2SetFlash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No fue posible generar documentos para tiendas sin servicio.'
            );
        }

        omRedirectManualAdjustTab($periodoYm, 'cargo_extra');
    }

    if ($accion === 'generar_y_programar_sin_servicio') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 120],
        ]);
        $programadoPara = trim((string) ($_POST['lote_programado_para'] ?? ''));
        $modoDestino = strtolower(trim((string) ($_POST['lote_modo_destino'] ?? 'real')));
        $demoDestino = trim((string) ($_POST['lote_demo_destino'] ?? ''));
        $batchSize = filter_input(INPUT_POST, 'lote_batch_size', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $clientUtcOffsetMin = filter_input(INPUT_POST, 'lote_client_utc_offset_min', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -840, 'max_range' => 840],
        ]);
        if ($clientUtcOffsetMin === false || $clientUtcOffsetMin === null) {
            $clientUtcOffsetMin = null;
        }
        $createdByUserId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
        if ($dias === false || $dias === null) {
            $dias = 5;
        }

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para generar y programar sin servicio.');
            msp2Redirect(omSelfRoute());
        }

        $reopenedTemporal = false;
        try {
            if (!PoolDocumentosPeriodoService::isAvailable($conn)) {
                throw new RuntimeException('Pool de documentos no disponible en este ambiente.');
            }
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados. Ejecuta el patch correspondiente.');
            }

            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion, true);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            $reopenedTemporal = omSwitchCierreToBorradorIfCalculado($conn, $idCierre, 'Generar y programar sin servicio');

            PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            $targetTiendasSinServicio = PoolDocumentosPeriodoService::fetchTargetTiendasSinServicio($conn, $periodoFacturacion);
            if ($targetTiendasSinServicio === []) {
                throw new RuntimeException('No hay tiendas sin servicio pendientes de materializar en este período.');
            }

            $docsData = DocumentosCobroService::generateDocumentsForCierre(
                $conn,
                $idCierre,
                (int) $dias,
                1,
                true,
                'ALL',
                $targetTiendasSinServicio
            );
            PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            $statsSinServicio = PoolDocumentosPeriodoService::fetchSinServicioStats($conn, $periodoFacturacion);
            $batchReal = ($batchSize === false || $batchSize === null)
                ? max(1, min(100, (int) ($statsSinServicio['tiendas_objetivo'] ?? 1)))
                : (int) $batchSize;

            $resLote = EnvioLotesProgramadosService::createScheduledLoteDinamico(
                $conn,
                $periodoYm,
                $periodoFacturacion,
                'SIN_SERVICIO',
                $programadoPara,
                $batchReal,
                $modoDestino,
                $demoDestino !== '' ? $demoDestino : null,
                $createdByUserId,
                $clientUtcOffsetMin
            );
            $idLoteCreado = (int) ($resLote['id_lote_envio'] ?? 0);
            $docIdsLoteSaldo = EnvioLotesProgramadosService::fetchDocumentIdsByLote($conn, $idLoteCreado);
            if ($docIdsLoteSaldo !== []) {
                omArchiveValeCobroForDocumentIds($conn, $docIdsLoteSaldo);
                omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsLoteSaldo, $idLoteCreado);
            }
            omMarkCierreCalculadoIfBorrador($conn, $idCierre);
            omRestoreCalculadoIfWasTemporal($conn, $idCierre, $reopenedTemporal);

            msp2SetFlash(
                'success',
                'Sin servicio: documentos generados ' . (int) ($docsData['documentos_generados'] ?? 0)
                . ' | items recompuestos ' . (int) ($docsData['items_recompuestos'] ?? 0)
                . ' | lote #' . (int) ($resLote['id_lote_envio'] ?? 0)
                . ' programado | destinatarios ' . (int) ($resLote['total_destinatarios'] ?? 0)
                . ' | pendientes ' . (int) ($resLote['pendientes'] ?? 0)
                . ' | omitidos ' . (int) ($resLote['omitidos'] ?? 0) . '.'
            );
        } catch (Throwable $e) {
            omRestoreCalculadoIfWasTemporal($conn, (int) ($idCierre ?? 0), $reopenedTemporal);
            msp2SetFlash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No fue posible generar y programar sin servicio.'
            );
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'generar_etapa_completitud') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $etapaCompletitud = strtoupper(trim((string) ($_POST['etapa_completitud'] ?? '')));
        $abrirPromptLote = omPostFlag('abrir_prompt_lote');
        $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 120],
        ]);
        if ($dias === false || $dias === null) {
            $dias = 5;
        }

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para generar por etapa de completitud.');
            msp2Redirect(omSelfRoute());
        }
        if (!in_array($etapaCompletitud, ['LUZ', 'GAS', 'AGUA'], true)) {
            msp2SetFlash('warning', 'Etapa inválida para generación por completitud.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        $allowedByStage = match ($etapaCompletitud) {
            'LUZ' => ['LUZ'],
            'GAS' => ['LUZ', 'GAS'],
            'AGUA' => ['LUZ', 'GAS', 'AGUA'],
            default => [],
        };
        $requiredLecturasByStage = match ($etapaCompletitud) {
            'LUZ' => ['LUZ'],
            'GAS' => ['LUZ', 'GAS'],
            'AGUA' => ['LUZ', 'AGUA'],
            default => [],
        };
        $sinServicioFusion = [
            'integrado' => $etapaCompletitud === 'LUZ',
            'disponible' => false,
            'documentos_generados' => 0,
            'items_recompuestos' => 0,
            'tiendas_objetivo' => 0,
            'tiendas_documentadas' => 0,
            'tiendas_pendientes' => 0,
            'lote' => null,
            'saldo_msg' => '',
            'saldo_warn' => false,
            'pdf_saved' => 0,
            'pdf_errors' => 0,
        ];
        $reopenedTemporal = false;
        try {
            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion, true);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            $reopenedTemporal = omSwitchCierreToBorradorIfCalculado($conn, $idCierre, 'Generar etapa completitud ' . $etapaCompletitud);

            $saldoAplicacionesAnuladasPrevias = 0;
            $pagosSaldoFavorAutoDepurados = 0;
            $pagosPeriodo = omFetchPagosPeriodoBreakdown($conn, $periodoFacturacion);
            $pagosManualPeriodo = (int) ($pagosPeriodo['manual'] ?? 0);
            if ($pagosManualPeriodo > 0) {
                throw new RuntimeException(
                    'No puedes generar nuevas etapas de completitud porque el período ya tiene pagos manuales registrados ('
                    . $pagosManualPeriodo
                    . '). Primero revierte/borrar pagos del período en la zona de corrección.'
                );
            }
            $pagosSaldoAutoPeriodo = (int) ($pagosPeriodo['saldo_auto'] ?? 0);
            if ($pagosSaldoAutoPeriodo > 0) {
                // Fase de estabilizacion:
                // no revertir aplicaciones/pagos automaticos por cambiar de etapa.
                // el saldo se aplica por lote y se corrige manualmente en paso 2 cuando corresponda.
            }

            if (EnvioLotesProgramadosService::isAvailable($conn)) {
                $blockingLote = EnvioLotesProgramadosService::fetchBlockingCompletitudLoteForStage(
                    $conn,
                    $periodoFacturacion,
                    $etapaCompletitud
                );
                if (is_array($blockingLote)) {
                    throw new RuntimeException(
                        'La etapa ' . $etapaCompletitud
                        . ' está bloqueada por lote #' . (int) ($blockingLote['id_lote_envio'] ?? 0)
                        . ' (' . (string) ($blockingLote['estado_label'] ?? 'Desconocido') . '). '
                        . 'Cancela ese lote en Paso 6 antes de regenerar la etapa.'
                    );
                }
            }

            $procStmt = $conn->prepare(
                "SELECT UPPER(ts.codigo_servicio) AS codigo_servicio
                 FROM dbo.msp_procesos_cobro_servicio p
                 INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                 WHERE p.id_cierre_mensual = :id_cierre
                   AND p.estado_proceso <> 4"
            );
            $procStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $procStmt->execute();

            $servicesPresent = [];
            $servicesWithLecturas = [];
            while (($procRow = $procStmt->fetch()) !== false) {
                $codeProc = strtoupper((string) ($procRow['codigo_servicio'] ?? ''));
                if (in_array($codeProc, ['LUZ', 'GAS', 'AGUA'], true)) {
                    $servicesPresent[$codeProc] = true;
                }
            }

            $lectStmt = $conn->prepare(
                "SELECT
                    UPPER(ts.codigo_servicio) AS codigo_servicio,
                    COUNT(lm.id_lectura) AS lecturas
                 FROM dbo.msp_procesos_cobro_servicio p
                 INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                 LEFT JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_proceso_cobro = p.id_proceso_cobro
                 WHERE p.id_cierre_mensual = :id_cierre
                   AND p.estado_proceso <> 4
                 GROUP BY UPPER(ts.codigo_servicio)"
            );
            $lectStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $lectStmt->execute();
            while (($lectRow = $lectStmt->fetch()) !== false) {
                $codeLect = strtoupper((string) ($lectRow['codigo_servicio'] ?? ''));
                if (!in_array($codeLect, ['LUZ', 'GAS', 'AGUA'], true)) {
                    continue;
                }
                if ((int) ($lectRow['lecturas'] ?? 0) > 0) {
                    $servicesWithLecturas[$codeLect] = true;
                }
            }

            $missingLecturas = [];
            foreach ($requiredLecturasByStage as $requiredCode) {
                if (!isset($servicesPresent[$requiredCode]) || !isset($servicesWithLecturas[$requiredCode])) {
                    $missingLecturas[] = $requiredCode;
                }
            }
            if ($missingLecturas !== []) {
                throw new RuntimeException(
                    'No puedes generar etapa ' . $etapaCompletitud
                    . ' sin lecturas cargadas para: ' . implode(', ', $missingLecturas) . '.'
                );
            }

            $selectedServices = [];
            if (isset($servicesPresent[$etapaCompletitud])) {
                $selectedServices[] = $etapaCompletitud;
            }
            if ($selectedServices === []) {
                throw new RuntimeException('No hay procesos guardados para los servicios requeridos en esta etapa.');
            }

            $docsReseteadosMsg = '';
            $legacyCobrosLockDetected = false;
            $legacyCobrosLockMessage = '';

            $cobrosGenerados = 0;
            try {
                $cobrosGenerados = OperacionMensualService::generarCobros($conn, $idCierre, false, $selectedServices);
            } catch (Throwable $cobrosEx) {
                $msgCobrosEx = (string) $cobrosEx->getMessage();
                $isDocsLock = str_contains($msgCobrosEx, '50034')
                    || str_contains($msgCobrosEx, 'Ya existen documentos emitidos para este periodo_facturacion');
                if (!$isDocsLock) {
                    throw $cobrosEx;
                }
                $legacyCobrosLockDetected = true;
                $legacyCobrosLockMessage = $msgCobrosEx;
                $cobrosGenerados = 0;
            }
            $docsServiceProfileByStage = omDocsServiceProfileByStage($etapaCompletitud);
            $targetTiendasMaterializacion = [];
            $usaPoolMaterializacion = false;
            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                $usaPoolMaterializacion = true;
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                $targetTiendasMaterializacion = PoolDocumentosPeriodoService::fetchTargetTiendasForStageMaterialization(
                    $conn,
                    $periodoFacturacion,
                    $etapaCompletitud
                );
            }
            if ($usaPoolMaterializacion && $targetTiendasMaterializacion === []) {
                $docsData = [
                    'documentos_generados' => 0,
                    'items_generados' => 0,
                    'items_recompuestos' => 0,
                ];
            } else {
                $docsData = DocumentosCobroService::generateDocumentsForCierre(
                    $conn,
                    $idCierre,
                    (int) $dias,
                    1,
                    true,
                    $docsServiceProfileByStage,
                    $targetTiendasMaterializacion
                );
            }
            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            }
            if ($etapaCompletitud === 'LUZ' && PoolDocumentosPeriodoService::isAvailable($conn)) {
                $sinServicioFusion['disponible'] = true;
                $targetTiendasSinServicio = PoolDocumentosPeriodoService::fetchTargetTiendasSinServicio($conn, $periodoFacturacion);
                if ($targetTiendasSinServicio !== []) {
                    $docsSinServicio = DocumentosCobroService::generateDocumentsForCierre(
                        $conn,
                        $idCierre,
                        (int) $dias,
                        1,
                        true,
                        'ALL',
                        $targetTiendasSinServicio
                    );
                    $sinServicioFusion['documentos_generados'] = (int) ($docsSinServicio['documentos_generados'] ?? 0);
                    $sinServicioFusion['items_recompuestos'] = (int) ($docsSinServicio['items_recompuestos'] ?? 0);
                }
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                $sinServicioStatsMerge = PoolDocumentosPeriodoService::fetchSinServicioStats($conn, $periodoFacturacion);
                $sinServicioFusion['tiendas_objetivo'] = (int) ($sinServicioStatsMerge['tiendas_objetivo'] ?? 0);
                $sinServicioFusion['tiendas_documentadas'] = (int) ($sinServicioStatsMerge['tiendas_documentadas'] ?? 0);
                $sinServicioFusion['tiendas_pendientes'] = (int) ($sinServicioStatsMerge['tiendas_pendientes'] ?? 0);
            }
            if ($legacyCobrosLockDetected) {
                $summaryAfterLock = EnvioLotesProgramadosService::fetchCompletionSummaryByStage($conn, $periodoFacturacion, $etapaCompletitud, true);
                if (!((bool) ($summaryAfterLock['tiene_candidatos'] ?? false))) {
                    throw new RuntimeException(
                        'No se pudieron recalcular cobros de la etapa ' . $etapaCompletitud
                        . ' porque la BD sigue bloqueando por documentos emitidos (50034). '
                        . 'Ejecuta `db/patch_operacion_mensual_sp.sql` y vuelve a intentar. '
                        . 'Detalle: ' . $legacyCobrosLockMessage
                    );
                }
            }
            $saldoAuto = [
                'disponible' => true,
                'estado' => 'info',
                'mensaje' => '',
            ];
            $summaryStage = EnvioLotesProgramadosService::fetchCompletionSummaryByStage($conn, $periodoFacturacion, $etapaCompletitud, true);
            $archiveResult = [
                'saved' => [],
                'errors' => [],
            ];
            $docIdsStageArchive = EnvioLotesProgramadosService::fetchCompletionDocumentIdsByStage(
                $conn,
                $periodoFacturacion,
                $etapaCompletitud
            );
            if ($docIdsStageArchive !== []) {
                $archiveResult = omArchiveValeCobroForDocumentIds($conn, $docIdsStageArchive);
            }
            $docIdsSinServicioArchive = [];
            if ($etapaCompletitud === 'LUZ' && (bool) ($sinServicioFusion['disponible'] ?? false)) {
                $docIdsSinServicioArchive = EnvioLotesProgramadosService::fetchDynamicDocumentIdsByService(
                    $conn,
                    $periodoFacturacion,
                    'SIN_SERVICIO'
                );
                if ($docIdsSinServicioArchive !== []) {
                    $archiveSinServicio = omArchiveValeCobroForDocumentIds($conn, $docIdsSinServicioArchive);
                    $sinServicioFusion['pdf_saved'] = count((array) ($archiveSinServicio['saved'] ?? []));
                    $sinServicioFusion['pdf_errors'] = count((array) ($archiveSinServicio['errors'] ?? []));
                }
            }
            $docIdsSaldoAuto = array_values(array_unique(array_merge($docIdsStageArchive, $docIdsSinServicioArchive)));
            if ($docIdsSaldoAuto !== []) {
                $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsSaldoAuto, null);
            } else {
                $saldoAuto['mensaje'] = 'No hubo documentos elegibles para aplicar saldo a favor en la generación de esta etapa.';
            }
            $poolStats = PoolDocumentosPeriodoService::isAvailable($conn)
                ? PoolDocumentosPeriodoService::fetchPoolStats($conn, $periodoFacturacion)
                : ['disponible' => false];
            $stageDocsInline = (int) ($docsData['documentos_generados'] ?? 0);
            $stageItemsInline = (int) ($docsData['items_recompuestos'] ?? 0);
            $stageListosInline = (int) ($summaryStage['arrendatarios'] ?? 0);
            if ($etapaCompletitud === 'LUZ' && (bool) ($sinServicioFusion['disponible'] ?? false)) {
                $stageDocsInline += (int) ($sinServicioFusion['documentos_generados'] ?? 0);
                $stageItemsInline += (int) ($sinServicioFusion['items_recompuestos'] ?? 0);
                $stageListosInline += (int) ($sinServicioFusion['tiendas_documentadas'] ?? 0);
            }

            $msg = 'Etapa ' . $etapaCompletitud
                . ': cobros generados/recalculados ' . $cobrosGenerados
                . $docsReseteadosMsg
                . ' | documentos generados ' . (int) ($docsData['documentos_generados'] ?? 0)
                . ' | items recompuestos ' . (int) ($docsData['items_recompuestos'] ?? 0)
                . ' | listos para programar: ' . (int) ($summaryStage['documentos'] ?? 0)
                . ' documentos (' . (int) ($summaryStage['arrendatarios'] ?? 0) . ' arrendatarios).';
            if ((bool) ($poolStats['disponible'] ?? false)) {
                $msg .= ' Pool: total ' . (int) ($poolStats['pool_total'] ?? 0)
                    . ' | pendientes ' . (int) ($poolStats['pool_pendientes'] ?? 0)
                    . ' | documentados ' . (int) ($poolStats['pool_documentados'] ?? 0)
                    . ' | loteados ' . (int) ($poolStats['pool_loteados'] ?? 0) . '.';
            }
            if ($etapaCompletitud === 'LUZ') {
                if ((bool) ($sinServicioFusion['disponible'] ?? false)) {
                    $msg .= ' SIN_SERVICIO: documentos generados ' . (int) ($sinServicioFusion['documentos_generados'] ?? 0)
                        . ' | items recompuestos ' . (int) ($sinServicioFusion['items_recompuestos'] ?? 0)
                        . ' | tiendas documentadas ' . (int) ($sinServicioFusion['tiendas_documentadas'] ?? 0)
                        . ' de ' . (int) ($sinServicioFusion['tiendas_objetivo'] ?? 0)
                        . ' (pendientes ' . (int) ($sinServicioFusion['tiendas_pendientes'] ?? 0) . ').';
                    if ((int) ($sinServicioFusion['pdf_saved'] ?? 0) > 0) {
                        $msg .= ' PDFs SIN_SERVICIO registrados: ' . (int) ($sinServicioFusion['pdf_saved'] ?? 0) . '.';
                    }
                    if ((int) ($sinServicioFusion['pdf_errors'] ?? 0) > 0) {
                        $msg .= ' Algunos PDFs SIN_SERVICIO no se pudieron registrar.';
                    }
                } else {
                    $msg .= ' SIN_SERVICIO: no integrado en este ambiente (pool no disponible).';
                }
            }
            $flashType = 'success';
            if ($saldoAplicacionesAnuladasPrevias > 0) {
                $msg .= ' Aplicaciones previas de saldo anuladas: ' . $saldoAplicacionesAnuladasPrevias . '.';
            }
            if ($pagosSaldoFavorAutoDepurados > 0) {
                $msg .= ' Pagos automáticos de saldo depurados: ' . $pagosSaldoFavorAutoDepurados . '.';
            }
            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                $msg .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                    $flashType = 'warning';
                }
            }
            if ($legacyCobrosLockDetected) {
                $msg .= ' Aviso: la capa de cobros no se recalculó porque la BD devolvió bloqueo legacy 50034; se continuó con los datos ya existentes.';
                $flashType = 'warning';
            }
            if (($archiveResult['saved'] ?? []) !== []) {
                $msg .= ' PDFs registrados en archivo: ' . count((array) ($archiveResult['saved'] ?? [])) . '.';
            }
            if (($archiveResult['errors'] ?? []) !== []) {
                $msg .= ' Algunos vales de cobro no se pudieron registrar en archivo.';
                $flashType = 'warning';
            }
            if ((int) ($sinServicioFusion['pdf_errors'] ?? 0) > 0) {
                $flashType = 'warning';
            }
            $flashMeta = [];
            $flashMeta['stage_generation'] = [
                'servicio' => $etapaCompletitud,
                'periodo' => $periodoYm,
                'cobros_generados' => $cobrosGenerados,
                'documentos_generados' => $stageDocsInline,
                'items_recompuestos' => $stageItemsInline,
                'arrendatarios' => $stageListosInline,
            ];
            $lotesDisponibles = EnvioLotesProgramadosService::isAvailable($conn);
            if ($abrirPromptLote && $lotesDisponibles && ((bool) ($summaryStage['tiene_candidatos'] ?? false))) {
                $flashMeta['stage_post_generation'] = [
                    'servicio' => $etapaCompletitud,
                    'periodo' => $periodoYm,
                    'documentos' => (int) ($summaryStage['documentos'] ?? 0),
                    'arrendatarios' => (int) ($summaryStage['arrendatarios'] ?? 0),
                    'next_focus' => omNextFocusAfterStage($etapaCompletitud),
                ];
            }
            omMarkCierreCalculadoIfBorrador($conn, $idCierre);
            omRestoreCalculadoIfWasTemporal($conn, $idCierre, $reopenedTemporal);
            msp2SetFlash($flashType, $msg, $flashMeta);
        } catch (Throwable $e) {
            omRestoreCalculadoIfWasTemporal($conn, (int) ($idCierre ?? 0), $reopenedTemporal);
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible generar la etapa de completitud.');
        }

        $focusStage = $etapaCompletitud === 'LUZ' ? 'servicio-luz' : ($etapaCompletitud === 'GAS' ? 'servicio-gas' : 'servicio-agua');
        omRedirectPeriodoConFoco($periodoYm, $focusStage);
    }

    if ($accion === 'generar_y_programar_etapa_completitud') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $etapaCompletitud = strtoupper(trim((string) ($_POST['etapa_completitud'] ?? '')));
        $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 120],
        ]);
        $programadoPara = trim((string) ($_POST['lote_programado_para'] ?? ''));
        $modoDestino = strtolower(trim((string) ($_POST['lote_modo_destino'] ?? 'real')));
        $demoDestino = trim((string) ($_POST['lote_demo_destino'] ?? ''));
        $focusAfter = trim((string) ($_POST['focus_after'] ?? omNextFocusAfterStage($etapaCompletitud)));
        $batchSizeAutoInput = filter_input(INPUT_POST, 'lote_batch_size_auto', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $clientUtcOffsetMin = filter_input(INPUT_POST, 'lote_client_utc_offset_min', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -840, 'max_range' => 840],
        ]);
        if ($clientUtcOffsetMin === false || $clientUtcOffsetMin === null) {
            $clientUtcOffsetMin = null;
        }
        $createdByUserId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;

        if ($dias === false || $dias === null) {
            $dias = 5;
        }

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para generar y programar por completitud.');
            msp2Redirect(omSelfRoute());
        }
        if (!in_array($etapaCompletitud, ['LUZ', 'GAS', 'AGUA'], true)) {
            msp2SetFlash('warning', 'Etapa inválida para generación y programación por completitud.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }
        if (!in_array($focusAfter, ['servicio-luz', 'servicio-gas', 'servicio-agua', 'paso-6'], true)) {
            $focusAfter = omNextFocusAfterStage($etapaCompletitud);
        }

        $allowedByStage = match ($etapaCompletitud) {
            'LUZ' => ['LUZ'],
            'GAS' => ['LUZ', 'GAS'],
            'AGUA' => ['LUZ', 'GAS', 'AGUA'],
            default => [],
        };
        $requiredLecturasByStage = match ($etapaCompletitud) {
            'LUZ' => ['LUZ'],
            'GAS' => ['LUZ', 'GAS'],
            'AGUA' => ['LUZ', 'AGUA'],
            default => [],
        };
        $sinServicioFusion = [
            'integrado' => $etapaCompletitud === 'LUZ',
            'disponible' => false,
            'documentos_generados' => 0,
            'items_recompuestos' => 0,
            'tiendas_objetivo' => 0,
            'tiendas_documentadas' => 0,
            'tiendas_pendientes' => 0,
            'lote' => null,
            'saldo_msg' => '',
            'saldo_warn' => false,
            'pdf_saved' => 0,
            'pdf_errors' => 0,
        ];

        $reopenedTemporal = false;
        try {
            $cierre = omRequirePeriodoBorradorForMutation($conn, $periodoFacturacion, true);
            $idCierre = (int) ($cierre['id_cierre_mensual'] ?? 0);
            if ($idCierre <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }
            $reopenedTemporal = omSwitchCierreToBorradorIfCalculado($conn, $idCierre, 'Generar y programar etapa ' . $etapaCompletitud);

            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados. Ejecuta el patch correspondiente.');
            }

            $saldoAplicacionesAnuladasPrevias = 0;
            $pagosSaldoFavorAutoDepurados = 0;
            $pagosPeriodo = omFetchPagosPeriodoBreakdown($conn, $periodoFacturacion);
            $pagosManualPeriodo = (int) ($pagosPeriodo['manual'] ?? 0);
            if ($pagosManualPeriodo > 0) {
                throw new RuntimeException(
                    'No puedes generar nuevas etapas de completitud porque el período ya tiene pagos manuales registrados ('
                    . $pagosManualPeriodo
                    . '). Primero revierte/borrar pagos del período en la zona de corrección.'
                );
            }
            $pagosSaldoAutoPeriodo = (int) ($pagosPeriodo['saldo_auto'] ?? 0);
            if ($pagosSaldoAutoPeriodo > 0) {
                // Fase de estabilizacion:
                // no revertir aplicaciones/pagos automaticos por cambiar de etapa.
                // el saldo se aplica por lote y se corrige manualmente en paso 2 cuando corresponda.
            }

            $blockingLote = EnvioLotesProgramadosService::fetchBlockingCompletitudLoteForStage(
                $conn,
                $periodoFacturacion,
                $etapaCompletitud
            );
            if (is_array($blockingLote)) {
                throw new RuntimeException(
                    'La etapa ' . $etapaCompletitud
                    . ' está bloqueada por lote #' . (int) ($blockingLote['id_lote_envio'] ?? 0)
                    . ' (' . (string) ($blockingLote['estado_label'] ?? 'Desconocido') . '). '
                    . 'Cancela ese lote en Paso 6 antes de regenerar la etapa.'
                );
            }

            $procStmt = $conn->prepare(
                "SELECT UPPER(ts.codigo_servicio) AS codigo_servicio
                 FROM dbo.msp_procesos_cobro_servicio p
                 INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                 WHERE p.id_cierre_mensual = :id_cierre
                   AND p.estado_proceso <> 4"
            );
            $procStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $procStmt->execute();

            $servicesPresent = [];
            $servicesWithLecturas = [];
            while (($procRow = $procStmt->fetch()) !== false) {
                $codeProc = strtoupper((string) ($procRow['codigo_servicio'] ?? ''));
                if (in_array($codeProc, ['LUZ', 'GAS', 'AGUA'], true)) {
                    $servicesPresent[$codeProc] = true;
                }
            }

            $lectStmt = $conn->prepare(
                "SELECT
                    UPPER(ts.codigo_servicio) AS codigo_servicio,
                    COUNT(lm.id_lectura) AS lecturas
                 FROM dbo.msp_procesos_cobro_servicio p
                 INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                 LEFT JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_proceso_cobro = p.id_proceso_cobro
                 WHERE p.id_cierre_mensual = :id_cierre
                   AND p.estado_proceso <> 4
                 GROUP BY UPPER(ts.codigo_servicio)"
            );
            $lectStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $lectStmt->execute();
            while (($lectRow = $lectStmt->fetch()) !== false) {
                $codeLect = strtoupper((string) ($lectRow['codigo_servicio'] ?? ''));
                if (!in_array($codeLect, ['LUZ', 'GAS', 'AGUA'], true)) {
                    continue;
                }
                if ((int) ($lectRow['lecturas'] ?? 0) > 0) {
                    $servicesWithLecturas[$codeLect] = true;
                }
            }

            $missingLecturas = [];
            foreach ($requiredLecturasByStage as $requiredCode) {
                if (!isset($servicesPresent[$requiredCode]) || !isset($servicesWithLecturas[$requiredCode])) {
                    $missingLecturas[] = $requiredCode;
                }
            }
            if ($missingLecturas !== []) {
                throw new RuntimeException(
                    'No puedes generar etapa ' . $etapaCompletitud
                    . ' sin lecturas cargadas para: ' . implode(', ', $missingLecturas) . '.'
                );
            }

            $selectedServices = [];
            if (isset($servicesPresent[$etapaCompletitud])) {
                $selectedServices[] = $etapaCompletitud;
            }
            if ($selectedServices === []) {
                throw new RuntimeException('No hay procesos guardados para los servicios requeridos en esta etapa.');
            }

            $docsReseteadosMsg = '';
            $legacyCobrosLockDetected = false;
            $legacyCobrosLockMessage = '';

            $cobrosGenerados = 0;
            try {
                $cobrosGenerados = OperacionMensualService::generarCobros($conn, $idCierre, false, $selectedServices);
            } catch (Throwable $cobrosEx) {
                $msgCobrosEx = (string) $cobrosEx->getMessage();
                $isDocsLock = str_contains($msgCobrosEx, '50034')
                    || str_contains($msgCobrosEx, 'Ya existen documentos emitidos para este periodo_facturacion');
                if (!$isDocsLock) {
                    throw $cobrosEx;
                }
                $legacyCobrosLockDetected = true;
                $legacyCobrosLockMessage = $msgCobrosEx;
                $cobrosGenerados = 0;
            }
            $docsServiceProfileByStage = omDocsServiceProfileByStage($etapaCompletitud);
            $targetTiendasMaterializacion = [];
            $usaPoolMaterializacion = false;
            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                $usaPoolMaterializacion = true;
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                $targetTiendasMaterializacion = PoolDocumentosPeriodoService::fetchTargetTiendasForStageMaterialization(
                    $conn,
                    $periodoFacturacion,
                    $etapaCompletitud
                );
            }
            if ($usaPoolMaterializacion && $targetTiendasMaterializacion === []) {
                $docsData = [
                    'documentos_generados' => 0,
                    'items_generados' => 0,
                    'items_recompuestos' => 0,
                ];
            } else {
                $docsData = DocumentosCobroService::generateDocumentsForCierre(
                    $conn,
                    $idCierre,
                    (int) $dias,
                    1,
                    true,
                    $docsServiceProfileByStage,
                    $targetTiendasMaterializacion
                );
            }
            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            }
            if ($etapaCompletitud === 'LUZ' && PoolDocumentosPeriodoService::isAvailable($conn)) {
                $sinServicioFusion['disponible'] = true;
                $targetTiendasSinServicio = PoolDocumentosPeriodoService::fetchTargetTiendasSinServicio($conn, $periodoFacturacion);
                if ($targetTiendasSinServicio !== []) {
                    $docsSinServicio = DocumentosCobroService::generateDocumentsForCierre(
                        $conn,
                        $idCierre,
                        (int) $dias,
                        1,
                        true,
                        'ALL',
                        $targetTiendasSinServicio
                    );
                    $sinServicioFusion['documentos_generados'] = (int) ($docsSinServicio['documentos_generados'] ?? 0);
                    $sinServicioFusion['items_recompuestos'] = (int) ($docsSinServicio['items_recompuestos'] ?? 0);
                }
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
                $sinServicioStatsMerge = PoolDocumentosPeriodoService::fetchSinServicioStats($conn, $periodoFacturacion);
                $sinServicioFusion['tiendas_objetivo'] = (int) ($sinServicioStatsMerge['tiendas_objetivo'] ?? 0);
                $sinServicioFusion['tiendas_documentadas'] = (int) ($sinServicioStatsMerge['tiendas_documentadas'] ?? 0);
                $sinServicioFusion['tiendas_pendientes'] = (int) ($sinServicioStatsMerge['tiendas_pendientes'] ?? 0);
            }
            if ($legacyCobrosLockDetected) {
                $summaryAfterLock = EnvioLotesProgramadosService::fetchCompletionSummaryByStage($conn, $periodoFacturacion, $etapaCompletitud, true);
                if (!((bool) ($summaryAfterLock['tiene_candidatos'] ?? false))) {
                    throw new RuntimeException(
                        'No se pudieron recalcular cobros de la etapa ' . $etapaCompletitud
                        . ' porque la BD sigue bloqueando por documentos emitidos (50034). '
                        . 'Ejecuta `db/patch_operacion_mensual_sp.sql` y vuelve a intentar. '
                        . 'Detalle: ' . $legacyCobrosLockMessage
                    );
                }
            }
            $saldoAuto = [
                'disponible' => false,
                'estado' => 'info',
                'mensaje' => '',
            ];
            $summaryStage = EnvioLotesProgramadosService::fetchCompletionSummaryByStage($conn, $periodoFacturacion, $etapaCompletitud, true);
            $poolStats = PoolDocumentosPeriodoService::isAvailable($conn)
                ? PoolDocumentosPeriodoService::fetchPoolStats($conn, $periodoFacturacion)
                : ['disponible' => false];
            $saldoStageSuffix = '';
            $saldoStageWarn = false;
            if ($saldoAplicacionesAnuladasPrevias > 0) {
                $saldoStageSuffix .= ' Aplicaciones previas de saldo anuladas: ' . $saldoAplicacionesAnuladasPrevias . '.';
            }
            if ($pagosSaldoFavorAutoDepurados > 0) {
                $saldoStageSuffix .= ' Pagos automáticos de saldo depurados: ' . $pagosSaldoFavorAutoDepurados . '.';
            }
            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                $saldoStageSuffix .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                    $saldoStageWarn = true;
                }
            }
            if ($legacyCobrosLockDetected) {
                $saldoStageSuffix .= ' Aviso: la capa de cobros no se recalculó porque la BD devolvió bloqueo legacy 50034; se continuó con datos existentes.';
                $saldoStageWarn = true;
            }

            $batchSizeAuto = max(1, min(100, (int) ($summaryStage['arrendatarios'] ?? 0)));
            if ($batchSizeAuto <= 1 && $batchSizeAutoInput !== false && $batchSizeAutoInput !== null) {
                $batchSizeAuto = (int) $batchSizeAutoInput;
            }

            if (!((bool) ($summaryStage['tiene_candidatos'] ?? false))) {
                msp2SetFlash(
                    'warning',
                    'Etapa ' . $etapaCompletitud
                    . ': cobros generados/recalculados ' . $cobrosGenerados
                    . $docsReseteadosMsg
                    . ' | documentos generados ' . (int) ($docsData['documentos_generados'] ?? 0)
                    . ' | items recompuestos ' . (int) ($docsData['items_recompuestos'] ?? 0)
                    . '. No hay arrendatarios completos para programar lote en este momento.'
                    . $saldoStageSuffix
                );
                omRedirectPeriodoConFoco($periodoYm, $focusAfter);
            }

            $resLote = EnvioLotesProgramadosService::createScheduledLoteCompletitud(
                $conn,
                $periodoYm,
                $periodoFacturacion,
                $etapaCompletitud,
                $programadoPara,
                $batchSizeAuto,
                $modoDestino,
                $demoDestino !== '' ? $demoDestino : null,
                $createdByUserId,
                $clientUtcOffsetMin,
                true
            );
            $idLoteCreado = (int) ($resLote['id_lote_envio'] ?? 0);
            $docIdsLoteSaldo = EnvioLotesProgramadosService::fetchDocumentIdsByLote($conn, $idLoteCreado);
            $archiveResult = [
                'saved' => [],
                'errors' => [],
            ];
            if ($docIdsLoteSaldo !== []) {
                $archiveResult = omArchiveValeCobroForDocumentIds($conn, $docIdsLoteSaldo);
            }
            if ($docIdsLoteSaldo !== []) {
                $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsLoteSaldo, $idLoteCreado);
            } else {
                $saldoAuto = [
                    'disponible' => true,
                    'estado' => 'info',
                    'mensaje' => 'No hubo documentos del lote elegibles para aplicar saldo a favor.',
                ];
            }
            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                $saldoStageSuffix .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                    $saldoStageWarn = true;
                }
            }
            if ($etapaCompletitud === 'LUZ' && (bool) ($sinServicioFusion['disponible'] ?? false)) {
                $sinServicioDocumentadas = (int) ($sinServicioFusion['tiendas_documentadas'] ?? 0);
                if ($sinServicioDocumentadas > 0) {
                    $sinServicioBatch = max(1, min(100, (int) ($sinServicioFusion['tiendas_objetivo'] ?? 1)));
                    $resLoteSinServicio = EnvioLotesProgramadosService::createScheduledLoteDinamico(
                        $conn,
                        $periodoYm,
                        $periodoFacturacion,
                        'SIN_SERVICIO',
                        $programadoPara,
                        $sinServicioBatch,
                        $modoDestino,
                        $demoDestino !== '' ? $demoDestino : null,
                        $createdByUserId,
                        $clientUtcOffsetMin
                    );
                    $sinServicioFusion['lote'] = $resLoteSinServicio;
                    $idLoteSinServicio = (int) ($resLoteSinServicio['id_lote_envio'] ?? 0);
                    $docIdsLoteSinServicio = EnvioLotesProgramadosService::fetchDocumentIdsByLote($conn, $idLoteSinServicio);
                    if ($docIdsLoteSinServicio !== []) {
                        $archiveSinServicio = omArchiveValeCobroForDocumentIds($conn, $docIdsLoteSinServicio);
                        $sinServicioFusion['pdf_saved'] = count((array) ($archiveSinServicio['saved'] ?? []));
                        $sinServicioFusion['pdf_errors'] = count((array) ($archiveSinServicio['errors'] ?? []));
                        $saldoSinServicio = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsLoteSinServicio, $idLoteSinServicio);
                        if ((bool) ($saldoSinServicio['disponible'] ?? false)) {
                            $sinServicioFusion['saldo_msg'] = (string) ($saldoSinServicio['mensaje'] ?? '');
                            $estadoSaldoSinServicio = (string) ($saldoSinServicio['estado'] ?? 'info');
                            if ($estadoSaldoSinServicio === 'warning' || $estadoSaldoSinServicio === 'danger') {
                                $sinServicioFusion['saldo_warn'] = true;
                                $saldoStageWarn = true;
                            }
                        }
                    }
                }
            }

            $msg = 'Etapa ' . $etapaCompletitud
                . ': cobros generados/recalculados ' . $cobrosGenerados
                . $docsReseteadosMsg
                . ' | documentos generados ' . (int) ($docsData['documentos_generados'] ?? 0)
                . ' | items recompuestos ' . (int) ($docsData['items_recompuestos'] ?? 0)
                . ' | lote #' . (int) ($resLote['id_lote_envio'] ?? 0)
                . ' programado con batch auto ' . $batchSizeAuto
                . ' | destinatarios ' . (int) ($resLote['total_destinatarios'] ?? 0)
                . ' | documentos ' . (int) ($resLote['documentos_programados'] ?? 0)
                . ' | pendientes ' . (int) ($resLote['pendientes'] ?? 0)
                . ' | omitidos ' . (int) ($resLote['omitidos'] ?? 0) . '.'
                . $saldoStageSuffix;
            if ($etapaCompletitud === 'LUZ') {
                if ((bool) ($sinServicioFusion['disponible'] ?? false)) {
                    $msg .= ' SIN_SERVICIO: documentos generados ' . (int) ($sinServicioFusion['documentos_generados'] ?? 0)
                        . ' | items recompuestos ' . (int) ($sinServicioFusion['items_recompuestos'] ?? 0)
                        . ' | tiendas documentadas ' . (int) ($sinServicioFusion['tiendas_documentadas'] ?? 0)
                        . ' de ' . (int) ($sinServicioFusion['tiendas_objetivo'] ?? 0)
                        . ' (pendientes ' . (int) ($sinServicioFusion['tiendas_pendientes'] ?? 0) . ').';
                    if (is_array($sinServicioFusion['lote'] ?? null)) {
                        $msg .= ' Lote SIN_SERVICIO #' . (int) (($sinServicioFusion['lote']['id_lote_envio'] ?? 0))
                            . ' | destinatarios ' . (int) (($sinServicioFusion['lote']['total_destinatarios'] ?? 0))
                            . ' | pendientes ' . (int) (($sinServicioFusion['lote']['pendientes'] ?? 0))
                            . ' | omitidos ' . (int) (($sinServicioFusion['lote']['omitidos'] ?? 0)) . '.';
                        if ((int) ($sinServicioFusion['pdf_saved'] ?? 0) > 0) {
                            $msg .= ' PDFs SIN_SERVICIO registrados: ' . (int) ($sinServicioFusion['pdf_saved'] ?? 0) . '.';
                        }
                        if ((int) ($sinServicioFusion['pdf_errors'] ?? 0) > 0) {
                            $msg .= ' Algunos PDFs SIN_SERVICIO no se pudieron registrar.';
                            $saldoStageWarn = true;
                        }
                        if (trim((string) ($sinServicioFusion['saldo_msg'] ?? '')) !== '') {
                            $msg .= ' ' . trim((string) ($sinServicioFusion['saldo_msg'] ?? ''));
                        }
                    } else {
                        $msg .= ' SIN_SERVICIO: sin lote creado (sin casos documentados para programar).';
                    }
                } else {
                    $msg .= ' SIN_SERVICIO: no integrado en este ambiente (pool no disponible).';
                }
            }
            if (($archiveResult['saved'] ?? []) !== []) {
                $msg .= ' PDFs registrados en archivo: ' . count((array) ($archiveResult['saved'] ?? [])) . '.';
            }
            if (($archiveResult['errors'] ?? []) !== []) {
                $msg .= ' Algunos vales de cobro no se pudieron registrar en archivo.';
                $saldoStageWarn = true;
            }
            if ((bool) ($poolStats['disponible'] ?? false)) {
                $msg .= ' Pool: total ' . (int) ($poolStats['pool_total'] ?? 0)
                    . ' | pendientes ' . (int) ($poolStats['pool_pendientes'] ?? 0)
                    . ' | documentados ' . (int) ($poolStats['pool_documentados'] ?? 0)
                    . ' | loteados ' . (int) ($poolStats['pool_loteados'] ?? 0) . '.';
            }

            omMarkCierreCalculadoIfBorrador($conn, $idCierre);
            omRestoreCalculadoIfWasTemporal($conn, $idCierre, $reopenedTemporal);
            if ((int) ($resLote['pendientes'] ?? 0) <= 0) {
                msp2SetFlash(
                    'warning',
                    $msg . ' El lote se creó sin destinatarios pendientes (correos inválidos/omitidos).'
                );
            } else {
                msp2SetFlash($saldoStageWarn ? 'warning' : 'success', $msg);
            }
        } catch (Throwable $e) {
            omRestoreCalculadoIfWasTemporal($conn, (int) ($idCierre ?? 0), $reopenedTemporal);
            msp2SetFlash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No fue posible generar y programar la etapa de completitud.'
            );
        }

        omRedirectPeriodoConFoco($periodoYm, $focusAfter);
    }

    if ($accion === 'programar_lote_completitud') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $etapaCompletitud = strtoupper(trim((string) ($_POST['etapa_completitud'] ?? '')));
        $focusAfter = trim((string) ($_POST['focus_after'] ?? 'paso-6'));
        $programadoPara = trim((string) ($_POST['lote_programado_para'] ?? ''));
        $batchSize = filter_input(INPUT_POST, 'lote_batch_size', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $modoDestino = strtolower(trim((string) ($_POST['lote_modo_destino'] ?? 'real')));
        $demoDestino = trim((string) ($_POST['lote_demo_destino'] ?? ''));
        $clientUtcOffsetMin = filter_input(INPUT_POST, 'lote_client_utc_offset_min', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -840, 'max_range' => 840],
        ]);
        if ($clientUtcOffsetMin === false || $clientUtcOffsetMin === null) {
            $clientUtcOffsetMin = null;
        }
        $createdByUserId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para programar lote por completitud.');
            msp2Redirect(omSelfRoute());
        }

        if (!in_array($etapaCompletitud, ['LUZ', 'GAS', 'AGUA'], true)) {
            msp2SetFlash('warning', 'Etapa inválida para programar lote por completitud.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }
        if (!in_array($focusAfter, ['servicio-luz', 'servicio-gas', 'servicio-agua', 'paso-6'], true)) {
            $focusAfter = 'paso-6';
        }

        if ($batchSize === false || $batchSize === null) {
            $batchSize = 10;
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados. Ejecuta el patch correspondiente.');
            }

            $resLote = EnvioLotesProgramadosService::createScheduledLoteCompletitud(
                $conn,
                $periodoYm,
                $periodoFacturacion,
                $etapaCompletitud,
                $programadoPara,
                (int) $batchSize,
                $modoDestino,
                $demoDestino !== '' ? $demoDestino : null,
                $createdByUserId,
                $clientUtcOffsetMin
            );
            $idLoteCreado = (int) ($resLote['id_lote_envio'] ?? 0);
            $docIdsLoteSaldo = EnvioLotesProgramadosService::fetchDocumentIdsByLote($conn, $idLoteCreado);
            $archiveResult = [
                'saved' => [],
                'errors' => [],
            ];
            if ($docIdsLoteSaldo !== []) {
                $archiveResult = omArchiveValeCobroForDocumentIds($conn, $docIdsLoteSaldo);
            }
            $saldoAuto = [
                'disponible' => false,
                'estado' => 'info',
                'mensaje' => '',
            ];
            if ($docIdsLoteSaldo !== []) {
                $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsLoteSaldo, $idLoteCreado);
            }

            $msg = 'Lote por completitud #' . (int) ($resLote['id_lote_envio'] ?? 0)
                . ' | Etapa: ' . msp2NormalizeText($etapaCompletitud)
                . ' | Destinatarios: ' . (int) ($resLote['total_destinatarios'] ?? 0)
                . ' | Documentos: ' . (int) ($resLote['documentos_programados'] ?? 0)
                . ' | Pendientes: ' . (int) ($resLote['pendientes'] ?? 0)
                . ' | Omitidos: ' . (int) ($resLote['omitidos'] ?? 0) . '.';
            $flashType = 'success';
            if (($archiveResult['saved'] ?? []) !== []) {
                $msg .= ' PDFs registrados en archivo: ' . count((array) ($archiveResult['saved'] ?? [])) . '.';
            }
            if (($archiveResult['errors'] ?? []) !== []) {
                $msg .= ' Algunos vales de cobro no se pudieron registrar en archivo.';
                $flashType = 'warning';
            }
            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                $msg .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                    $flashType = 'warning';
                }
            }
            if ((int) ($resLote['pendientes'] ?? 0) <= 0) {
                msp2SetFlash(
                    'warning',
                    $msg . ' El lote se creó sin destinatarios pendientes (correos inválidos/omitidos), por eso puede verse en estado Con error.'
                );
            } else {
                msp2SetFlash($flashType, $msg);
            }
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible programar el lote por completitud.');
        }

        omRedirectPeriodoConFoco($periodoYm, $focusAfter);
    }

    if ($accion === 'programar_lote_servicio') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $codigoServicio = strtoupper(trim((string) ($_POST['lote_codigo_servicio'] ?? '')));
        $programadoPara = trim((string) ($_POST['lote_programado_para'] ?? ''));
        $batchSize = filter_input(INPUT_POST, 'lote_batch_size', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $modoDestino = strtolower(trim((string) ($_POST['lote_modo_destino'] ?? 'real')));
        $demoDestino = trim((string) ($_POST['lote_demo_destino'] ?? ''));
        $clientUtcOffsetMin = filter_input(INPUT_POST, 'lote_client_utc_offset_min', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => -840, 'max_range' => 840],
        ]);
        if ($clientUtcOffsetMin === false || $clientUtcOffsetMin === null) {
            $clientUtcOffsetMin = null;
        }
        $createdByUserId = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para programar lote.');
            msp2Redirect(omSelfRoute());
        }

        if ($batchSize === false || $batchSize === null) {
            $batchSize = 10;
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados. Ejecuta el patch correspondiente.');
            }

            $resLote = EnvioLotesProgramadosService::createScheduledLoteDinamico(
                $conn,
                $periodoYm,
                $periodoFacturacion,
                $codigoServicio,
                $programadoPara,
                (int) $batchSize,
                $modoDestino,
                $demoDestino !== '' ? $demoDestino : null,
                $createdByUserId,
                $clientUtcOffsetMin
            );
            $idLoteCreado = (int) ($resLote['id_lote_envio'] ?? 0);
            $docIdsLoteSaldo = EnvioLotesProgramadosService::fetchDocumentIdsByLote($conn, $idLoteCreado);
            $archiveResult = [
                'saved' => [],
                'errors' => [],
            ];
            if ($docIdsLoteSaldo !== []) {
                $archiveResult = omArchiveValeCobroForDocumentIds($conn, $docIdsLoteSaldo);
            }
            $saldoAuto = [
                'disponible' => false,
                'estado' => 'info',
                'mensaje' => '',
            ];
            if ($docIdsLoteSaldo !== []) {
                $saldoAuto = omApplySaldoFavorPeriodoAuto($conn, $periodoYm, $periodoFacturacion, $docIdsLoteSaldo, $idLoteCreado);
            }

            $programadoParaRaw = (string) ($resLote['programado_para'] ?? '');
            $programadoParaFmt = omFmtFecha(substr($programadoParaRaw, 0, 10));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $programadoParaRaw) === 1) {
                $programadoParaFmt .= ' ' . substr($programadoParaRaw, 11, 5);
            }

            $msg = 'Lote #' . (int) ($resLote['id_lote_envio'] ?? 0)
                . ' programado para ' . $programadoParaFmt
                . ' | Servicio: ' . (string) ($resLote['codigo_servicio'] ?? '-')
                . ' | Destinatarios: ' . (int) ($resLote['total_destinatarios'] ?? 0)
                . ' | Pendientes: ' . (int) ($resLote['pendientes'] ?? 0)
                . ' | Omitidos: ' . (int) ($resLote['omitidos'] ?? 0) . '.';
            $flashType = 'success';
            if (($archiveResult['saved'] ?? []) !== []) {
                $msg .= ' PDFs registrados en archivo: ' . count((array) ($archiveResult['saved'] ?? [])) . '.';
            }
            if (($archiveResult['errors'] ?? []) !== []) {
                $msg .= ' Algunos vales de cobro no se pudieron registrar en archivo.';
                $flashType = 'warning';
            }
            if ((bool) ($saldoAuto['disponible'] ?? false)) {
                $msg .= ' ' . (string) ($saldoAuto['mensaje'] ?? '');
                $estadoSaldo = (string) ($saldoAuto['estado'] ?? 'info');
                if ($estadoSaldo === 'warning' || $estadoSaldo === 'danger') {
                    $flashType = 'warning';
                }
            }
            $flashMeta = $flashType === 'success'
                ? ['enable_success_burst' => true]
                : [];
            msp2SetFlash($flashType, $msg, $flashMeta);
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible programar el lote.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'ejecutar_lotes_programados') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para ejecutar lotes.');
            msp2Redirect(omSelfRoute());
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados. Ejecuta el patch correspondiente.');
            }

            $resExec = EnvioLotesProgramadosService::processDueLotes($conn, 5, null, 'web-manual');
            $lotesProcesados = (int) ($resExec['lotes_procesados'] ?? 0);
            $msg = 'Ejecución manual: lotes procesados ' . $lotesProcesados
                . ' | enviados ' . (int) ($resExec['destinatarios_enviados'] ?? 0)
                . ' | fallidos ' . (int) ($resExec['destinatarios_fallidos'] ?? 0)
                . ' | omitidos ' . (int) ($resExec['destinatarios_omitidos'] ?? 0) . '.';
            if ($lotesProcesados > 0) {
                $autoClose = omTryAutoClosePeriodoIfReady($conn, $periodoFacturacion, 'web-manual:ejecutar_lotes_programados');
                if ((bool) ($autoClose['eligible'] ?? false)) {
                    $msg .= ' El período está listo para revisión manual; no se cerró automáticamente.';
                }
            }
            if ($lotesProcesados === 0) {
                $msg .= ' No había lotes vencidos en este momento. '
                    . 'Si quieres ejecutar uno puntual sin esperar hora programada, usa el botón de forzar (icono play) en la fila del lote.';
                msp2SetFlash('warning', $msg);
            } else {
                msp2SetFlash('success', $msg, ['enable_success_burst' => true]);
            }
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible ejecutar lotes programados.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'forzar_lote_programado') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $idLote = filter_input(INPUT_POST, 'id_lote_envio', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para ejecutar lote.');
            msp2Redirect(omSelfRoute());
        }
        if ($idLote === false || $idLote === null) {
            msp2SetFlash('warning', 'Lote inválido para ejecutar.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados.');
            }

            $resExec = EnvioLotesProgramadosService::forceProcessLoteNow(
                $conn,
                (int) $idLote,
                $periodoFacturacion,
                null,
                'web-manual-force'
            );

            $msg = 'Lote #' . (int) ($resExec['id_lote_envio'] ?? 0)
                . ' ejecutado manualmente'
                . ' | enviados ' . (int) ($resExec['enviados_batch'] ?? 0)
                . ' | fallidos ' . (int) ($resExec['fallidos_batch'] ?? 0)
                . ' | omitidos ' . (int) ($resExec['omitidos_batch'] ?? 0)
                . ' | procesados ' . (int) ($resExec['procesados'] ?? 0)
                . '/' . (int) ($resExec['total_destinatarios'] ?? 0) . '.';
            $autoClose = omTryAutoClosePeriodoIfReady($conn, $periodoFacturacion, 'web-manual:forzar_lote_programado');
            if ((bool) ($autoClose['eligible'] ?? false)) {
                $msg .= ' El período está listo para revisión manual; no se cerró automáticamente.';
            }
            msp2SetFlash('success', $msg, ['enable_success_burst' => true]);
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible ejecutar el lote programado.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'cancelar_lote_programado') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $idLote = filter_input(INPUT_POST, 'id_lote_envio', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para cancelar lote.');
            msp2Redirect(omSelfRoute());
        }
        if ($idLote === false || $idLote === null) {
            msp2SetFlash('warning', 'Lote inválido para cancelar.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados.');
            }

            EnvioLotesProgramadosService::cancelarLote($conn, (int) $idLote, $periodoFacturacion);
            msp2SetFlash('success', 'Lote #' . (int) $idLote . ' cancelado correctamente.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cancelar el lote.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'eliminar_lote_programado') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        $idLote = filter_input(INPUT_POST, 'id_lote_envio', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo inválido para eliminar lote.');
            msp2Redirect(omSelfRoute());
        }
        if ($idLote === false || $idLote === null) {
            msp2SetFlash('warning', 'Lote inválido para eliminar.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        try {
            if (!EnvioLotesProgramadosService::isAvailable($conn)) {
                throw new RuntimeException('La base de datos no tiene habilitados los lotes programados.');
            }

            $resDeleteLote = EnvioLotesProgramadosService::deleteLotePermanently($conn, (int) $idLote, $periodoFacturacion);
            msp2SetFlash(
                'success',
                'Lote #' . (int) ($resDeleteLote['id_lote_envio'] ?? 0)
                . ' eliminado del sistema'
                . ' | destinatarios borrados: ' . (int) ($resDeleteLote['destinatarios_eliminados'] ?? 0)
                . ' | vínculos doc-lote borrados: ' . (int) ($resDeleteLote['docs_eliminados'] ?? 0) . '.'
            );
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible eliminar el lote.');
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }

    if ($accion === 'enviar_demo_batch' && omIsAjaxRequest()) {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));
        $batchSize = 1;

        try {
            if ($jobId !== '' && isset($_SESSION['msp2_demo_send_jobs'][$jobId])) {
                $job = (array) $_SESSION['msp2_demo_send_jobs'][$jobId];
            } else {
                $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
                $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
                if ($periodoFacturacion === null) {
                    omJsonResponse(['ok' => false, 'message' => 'Periodo invalido para envio demo.'], 422);
                }

                $arrIdsInput = $_POST['demo_arrendatario_ids'] ?? [];
                if (!is_array($arrIdsInput)) {
                    $arrIdsInput = [];
                }

                $job = EnvioDemoService::createDemoJob(
                    $conn,
                    $periodoYm,
                    $periodoFacturacion,
                    (string) ($_POST['demo_destino'] ?? ''),
                    $arrIdsInput,
                    10
                );

                $jobId = bin2hex(random_bytes(8));
                $_SESSION['msp2_demo_send_jobs'][$jobId] = $job;
            }

            $batchResult = EnvioDemoService::processDemoJobBatch($conn, $job, $batchSize);
            $job = (array) ($batchResult['job'] ?? []);
            $done = ((bool) ($batchResult['done'] ?? false)) === true;
            $message = (string) ($batchResult['message'] ?? '');

            if ($done) {
                if (((int) ($batchResult['failed'] ?? 0)) <= 0) {
                    msp2SetFlash('success', $message, ['enable_success_burst' => true]);
                }
                unset($_SESSION['msp2_demo_send_jobs'][$jobId]);
            } else {
                $_SESSION['msp2_demo_send_jobs'][$jobId] = $job;
            }

            omJsonResponse([
                'ok' => true,
                'job_id' => $jobId,
                'done' => $done,
                'sent' => (int) ($batchResult['sent'] ?? 0),
                'failed' => (int) ($batchResult['failed'] ?? 0),
                'processed' => (int) ($batchResult['processed'] ?? 0),
                'total' => (int) ($batchResult['total'] ?? 0),
                'percent' => (int) ($batchResult['percent'] ?? 0),
                'message' => $message,
                'errors' => array_values((array) ($job['errors'] ?? [])),
                'periodo' => (string) ($job['periodo_ym'] ?? ''),
            ]);
        } catch (Throwable $e) {
            if ($jobId !== '') {
                unset($_SESSION['msp2_demo_send_jobs'][$jobId]);
            }
            omJsonResponse([
                'ok' => false,
                'message' => $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible ejecutar el envio demo de correos.',
            ], 422);
        }
    }

    if ($accion === 'enviar_demo') {
        $periodoYm = trim((string) ($_POST['periodo'] ?? ''));
        $periodoFacturacion = omParseMonthToFirstDay($periodoYm);
        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido para envio demo.');
            msp2Redirect(omSelfRoute());
        }

        $demoDestino = mb_strtolower(msp2NormalizeText((string) ($_POST['demo_destino'] ?? '')), 'UTF-8');
        if (filter_var($demoDestino, FILTER_VALIDATE_EMAIL) === false) {
            msp2SetFlash('warning', 'Debes indicar un correo destino demo valido.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        $arrIdsInput = $_POST['demo_arrendatario_ids'] ?? [];
        if (!is_array($arrIdsInput)) {
            $arrIdsInput = [];
        }
        if ($arrIdsInput === []) {
            msp2SetFlash('warning', 'Selecciona al menos un arrendatario para el envio demo.');
            omRedirectPeriodoConFoco($periodoYm, 'paso-6');
        }

        try {
            $resEnvio = EnvioDemoService::enviarDemoSincrono(
                $conn,
                $periodoYm,
                $periodoFacturacion,
                $demoDestino,
                $arrIdsInput,
                10
            );

            msp2SetFlash(((int) ($resEnvio['failed'] ?? 0)) > 0 ? 'warning' : 'success', (string) ($resEnvio['message'] ?? ''));
        } catch (Throwable $e) {
            msp2SetFlash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No fue posible ejecutar el envio demo de correos.'
            );
        }

        omRedirectPeriodoConFoco($periodoYm, 'paso-6');
    }
}

$periodos = [];
$periodoActualYm = $periodoQuery;
$selectedCierre = null;
$prefillCierre = null;
$servicios = [];
$prefillByCode = [];
$previewLecturasByCode = [];
$pendingPreviewByCode = [];

$summary = [
    'cobros' => 0,
    'documentos' => 0,
    'pagos' => 0,
    'pagos_total' => 0,
    'pagos_manual' => 0,
    'pagos_saldo_auto' => 0,
    'cargos_salida_asociados' => 0,
    'total_documentado' => 0.0,
    'total_saldo' => 0.0,
];
$extraCharges = [
    'pendientes' => [],
    'pendientes_total' => 0.0,
    'pendientes_count' => 0,
    'aplicados' => [],
    'aplicados_total' => 0.0,
    'aplicados_count' => 0,
    'disponible' => false,
];
$extraChargeTypes = [];
$extraChargeTargets = [];
$saldoFavorManualFlow = [
    'disponible' => false,
    'tiendas' => [],
    'locales_por_tienda' => [],
    'resumen' => [],
    'manual_rows' => [],
    'total_disponible' => 0.0,
    'total_ingresado_periodo' => 0.0,
];

$status = [
    'uf_ok' => false,
    'procesos_ok' => false,
    'lecturas_ok' => false,
    'cobros_ok' => false,
    'documentos_ok' => false,
];

$envioArrendatarios = [];
$envioTotales = [
    'arrendatarios' => 0,
    'documentos' => 0,
];
$envioDocsByArr = [];
$lotesProgramadosDisponibles = false;
$lotesProgramados = [];
$completionSummaryByStage = [
    'LUZ' => ['etapa' => 'LUZ', 'arrendatarios' => 0, 'documentos' => 0, 'tiene_candidatos' => false],
    'GAS' => ['etapa' => 'GAS', 'arrendatarios' => 0, 'documentos' => 0, 'tiene_candidatos' => false],
    'AGUA' => ['etapa' => 'AGUA', 'arrendatarios' => 0, 'documentos' => 0, 'tiene_candidatos' => false],
];
$completionTotals = [
    'arrendatarios' => 0,
    'documentos' => 0,
    'etapas_con_candidatos' => 0,
];
$lotesStageStats = [
    'LUZ' => ['lotes' => 0, 'activos' => 0, 'completados' => 0, 'cancelados' => 0, 'con_error' => 0, 'enviados' => 0],
    'GAS' => ['lotes' => 0, 'activos' => 0, 'completados' => 0, 'cancelados' => 0, 'con_error' => 0, 'enviados' => 0],
    'AGUA' => ['lotes' => 0, 'activos' => 0, 'completados' => 0, 'cancelados' => 0, 'con_error' => 0, 'enviados' => 0],
];
$sinServicioLotesStats = ['lotes' => 0, 'activos' => 0, 'completados' => 0, 'cancelados' => 0, 'con_error' => 0, 'enviados' => 0];
$sinServicioBlockingLote = null;
$poolSegmentationDiagnostics = [
    'disponible' => false,
    'combinaciones' => [],
    'etapas' => [],
];
$sinServicioStats = [
    'disponible' => false,
    'tiendas_objetivo' => 0,
    'tiendas_documentadas' => 0,
    'tiendas_pendientes' => 0,
];
$sqlServerUtcOffsetMinutes = 0;
$stageBlockingLotes = [
    'LUZ' => null,
    'GAS' => null,
    'AGUA' => null,
];
$nonCancelledStageLotesCount = 0;
$mailConfig = omMailConfig();
$demoDestinoDefault = trim((string) (($mailConfig['demo']['to'] ?? '')));
$envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);
$diasVencimientoDefault = 5;
$saldoFavorFlow = [
    'disponible' => false,
    'sugerencias' => [],
    'por_tienda' => [],
    'total_sugerido' => 0.0,
    'docs_sugeridos' => 0,
    'tiendas_sugeridas' => 0,
];
$saldoFavorAppliedFlow = [
    'disponible' => false,
    'rows' => [],
    'count' => 0,
    'total' => 0.0,
    'columns_ok' => false,
];

if ($tablaExiste) {
    try {
        $periodosStmt = $conn->query(
            "SELECT id_cierre_mensual, periodo_facturacion, valor_uf, estado_cierre
             FROM dbo.msp_cierre_mensual
             ORDER BY periodo_facturacion DESC"
        );
        while (($row = $periodosStmt->fetch()) !== false) {
            $periodos[] = $row;
        }

        $periodoActual = omParseMonthToFirstDay($periodoActualYm);

        if ($periodoActual !== null) {
            try {
                $tzOffsetStmt = $conn->query('SELECT DATEPART(TZOFFSET, SYSDATETIMEOFFSET()) AS tzoffset_min');
                if ($tzOffsetStmt !== false) {
                    $tzOffsetRow = $tzOffsetStmt->fetch();
                    if ($tzOffsetRow !== false) {
                        $tzOffset = (int) ($tzOffsetRow['tzoffset_min'] ?? 0);
                        if ($tzOffset >= -840 && $tzOffset <= 840) {
                            $sqlServerUtcOffsetMinutes = $tzOffset;
                        }
                    }
                }
            } catch (Throwable $ignore) {
                $sqlServerUtcOffsetMinutes = 0;
            }

            $cierreStmt = $conn->prepare('SELECT * FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = :periodo');
            $cierreStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
            $cierreStmt->execute();
            $selectedCierre = $cierreStmt->fetch() ?: null;

            if (
                $selectedCierre !== null
                && !$hasExplicitStepQuery
                && in_array((int) ($selectedCierre['estado_cierre'] ?? 0), [2,5], true)
            ) {
                $activeStep = 6;
            }

            if ($selectedCierre === null) {
                $prefillCierreStmt = $conn->prepare(
                    'SELECT TOP 1 fecha_valor_uf, valor_uf
                     FROM dbo.msp_cierre_mensual
                     WHERE periodo_facturacion < :periodo
                     ORDER BY periodo_facturacion DESC'
                );
                $prefillCierreStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                $prefillCierreStmt->execute();
                $prefillCierre = $prefillCierreStmt->fetch() ?: null;
            }

            $servSql = "
                SELECT
                    ts.id_tipo_servicio,
                    ts.codigo_servicio,
                    ts.nombre_servicio,
                    ts.unidad_medida,
                    p.id_proceso_cobro,
                    p.numero_factura_origen,
                    p.fecha_emision_origen,
                    p.fecha_vencimiento_origen,
                    p.estado_proceso,
                    p.observaciones,
                    pl.valor_kwh,
                    pg.factor,
                    pg.valor_litro,
                    pa.lectura_general_anterior,
                    pa.lectura_general_actual,
                    pa.servicio_agua_potable,
                    pa.servicio_alcantarillado,
                    pa.tratamiento_aguas_servidas,
                    pa.sobreconsumo,
                    pa.interes_pf_plazo,
                    pa.divisor,
                    pa.cargo_fijo,
                    pa.monto_total_factura,
                    ISNULL(lm.cantidad_lecturas, 0) AS cantidad_lecturas,
                    il.id_lote AS ultimo_lote_id,
                    il.fecha_carga AS ultimo_lote_fecha
                FROM dbo.msp_tipos_servicio ts
                LEFT JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_cierre_mensual = :id_cierre
                   AND p.id_tipo_servicio = ts.id_tipo_servicio
                LEFT JOIN dbo.msp_proceso_cobro_luz pl ON pl.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN (
                    SELECT id_proceso_cobro, COUNT(*) AS cantidad_lecturas
                    FROM dbo.msp_lecturas_medidores
                    GROUP BY id_proceso_cobro
                ) lm ON lm.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN (
                    SELECT id_tipo_servicio, MAX(id_lote) AS id_lote
                    FROM dbo.msp_import_lotes
                    WHERE periodo_facturacion = :periodo
                    GROUP BY id_tipo_servicio
                ) il_last ON il_last.id_tipo_servicio = ts.id_tipo_servicio
                LEFT JOIN dbo.msp_import_lotes il ON il.id_lote = il_last.id_lote
                WHERE UPPER(ts.codigo_servicio) IN ('AGUA','LUZ','GAS')
                ORDER BY CASE UPPER(ts.codigo_servicio)
                    WHEN 'AGUA' THEN 1
                    WHEN 'LUZ' THEN 2
                    WHEN 'GAS' THEN 3
                    ELSE 99
                END";

            $servStmt = $conn->prepare($servSql);
            $servStmt->bindValue(':id_cierre', (int) ($selectedCierre['id_cierre_mensual'] ?? 0), PDO::PARAM_INT);
            $servStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
            $servStmt->execute();
            $servicios = $servStmt->fetchAll();

            foreach ($serviceCodes as $code) {
                $prefillStmt = $conn->prepare(
                    "SELECT TOP 1
                        p.numero_factura_origen,
                        p.fecha_emision_origen,
                        p.fecha_vencimiento_origen,
                        p.observaciones,
                        pl.valor_kwh,
                        pg.factor,
                        pg.valor_litro,
                        pa.lectura_general_anterior,
                        pa.lectura_general_actual,
                        pa.servicio_agua_potable,
                        pa.servicio_alcantarillado,
                        pa.tratamiento_aguas_servidas,
                        pa.sobreconsumo,
                        pa.interes_pf_plazo,
                        pa.divisor,
                        pa.cargo_fijo,
                        pa.monto_total_factura
                     FROM dbo.msp_procesos_cobro_servicio p
                     INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = p.id_tipo_servicio
                     INNER JOIN dbo.msp_cierre_mensual c ON c.id_cierre_mensual = p.id_cierre_mensual
                     LEFT JOIN dbo.msp_proceso_cobro_luz pl ON pl.id_proceso_cobro = p.id_proceso_cobro
                     LEFT JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro = p.id_proceso_cobro
                     LEFT JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro = p.id_proceso_cobro
                     WHERE UPPER(ts.codigo_servicio) = :code
                       AND c.periodo_facturacion < :periodo
                     ORDER BY c.periodo_facturacion DESC"
                );
                $prefillStmt->bindValue(':code', $code, PDO::PARAM_STR);
                $prefillStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                $prefillStmt->execute();
                $prefillByCode[$code] = $prefillStmt->fetch() ?: null;

                $pendingPayload = omPreviewSessionRead($periodoActualYm, $code);
                if (is_array($pendingPayload)) {
                    $pendingRows = $pendingPayload['valid_rows'] ?? [];
                    if (is_array($pendingRows) && $pendingRows !== []) {
                        $pendingPreviewByCode[$code] = [
                            'rows_total' => count($pendingRows),
                            'rows_preview' => array_slice($pendingRows, 0, 200),
                            'original_name' => (string) ($pendingPayload['original_name'] ?? ''),
                            'reemplazar' => ((int) ($pendingPayload['reemplazar'] ?? 0)) === 1,
                            'created_at' => (int) ($pendingPayload['created_at'] ?? 0),
                        ];
                    }
                }
            }

            foreach ($servicios as $servicioRow) {
                $codeSrv = strtoupper((string) ($servicioRow['codigo_servicio'] ?? ''));
                if (!in_array($codeSrv, $serviceCodes, true)) {
                    continue;
                }

                $idProcesoPreview = (int) ($servicioRow['id_proceso_cobro'] ?? 0);
                if ($idProcesoPreview > 0) {
                    $lecturasStmt = $conn->prepare(
                        'SELECT
                            lm.id_lectura,
                            loc.cdo_local AS cod_local,
                            m.codigo_medidor,
                            lm.lectura_anterior,
                            lm.lectura_actual,
                            lm.fecha_hasta_consumo,
                            lm.fecha_lectura,
                            lm.observaciones
                         FROM dbo.msp_lecturas_medidores lm
                         INNER JOIN dbo.msp_medidores m ON m.id_medidor = lm.id_medidor
                         INNER JOIN dbo.msp_locales loc ON loc.id_local = m.id_local
                         WHERE lm.id_proceso_cobro = :id_proceso
                         ORDER BY ' . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ', m.codigo_medidor ASC'
                    );
                    $lecturasStmt->bindValue(':id_proceso', $idProcesoPreview, PDO::PARAM_INT);
                    $lecturasStmt->execute();
                    $previewLecturasByCode[$codeSrv] = $lecturasStmt->fetchAll();
                }
            }

            if ($selectedCierre !== null) {
                $cobrosStmt = $conn->prepare(
                    'SELECT COUNT(*)
                     FROM dbo.msp_cobros_servicios cs
                     INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
                     INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
                     WHERE p.id_cierre_mensual = :id'
                );
                $cobrosStmt->bindValue(':id', (int) $selectedCierre['id_cierre_mensual'], PDO::PARAM_INT);
                $cobrosStmt->execute();
                $summary['cobros'] = (int) $cobrosStmt->fetchColumn();

                $docsStmt = $conn->prepare(
                    'SELECT COUNT(*) AS c, SUM(monto_total) AS total, SUM(saldo_pendiente) AS saldo
                     FROM dbo.msp_documentos_cobro
                     WHERE periodo_facturacion = :periodo'
                );
                $docsStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                $docsStmt->execute();
                $docSum = $docsStmt->fetch();
                if ($docSum) {
                    $summary['documentos'] = (int) ($docSum['c'] ?? 0);
                    $summary['total_documentado'] = (float) ($docSum['total'] ?? 0);
                    $summary['total_saldo'] = (float) ($docSum['saldo'] ?? 0);
                }

                $pagosBreakdown = omFetchPagosPeriodoBreakdown($conn, $periodoActual);
                $summary['pagos_total'] = (int) ($pagosBreakdown['total'] ?? 0);
                $summary['pagos_manual'] = (int) ($pagosBreakdown['manual'] ?? 0);
                $summary['pagos_saldo_auto'] = (int) ($pagosBreakdown['saldo_auto'] ?? 0);
                // En zona de corrección, "pagos" debe reflejar todo lo reversible del período
                // (manual + automático/saldo a favor).
                $summary['pagos'] = $summary['pagos_total'];

                $saldoFavorFlow = omBuildSaldoFavorSuggestions($conn, $periodoActual);

                if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
                    $saldoFavorAppliedFlow['disponible'] = true;
                    $saldoFavorAppliedFlow['columns_ok'] = true;
                    $saldoAplicadoStmt = $conn->prepare(
                        "SELECT
                            sfa.id_saldo_favor_periodo_aplicacion AS id_pago,
                            sfa.fecha_aplicacion AS fecha_pago,
                            sfa.id_documento_cobro,
                            COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                            dc.fecha_vencimiento,
                            COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                            ROUND(sfa.monto_aplicado, 2) AS monto_aplicado
                         FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
                         INNER JOIN dbo.msp_documentos_cobro dc
                            ON dc.id_documento_cobro = sfa.id_documento_cobro
                         INNER JOIN dbo.msp_tiendas t
                            ON t.id_tienda = sfa.id_tienda
                         WHERE sfa.periodo_facturacion = :periodo
                           AND sfa.estado_aplicacion = 1
                           AND sfa.monto_aplicado > 0
                         ORDER BY sfa.fecha_aplicacion ASC, sfa.id_saldo_favor_periodo_aplicacion ASC"
                    );
                    $saldoAplicadoStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $saldoAplicadoStmt->execute();
                    $saldoFavorAppliedFlow['rows'] = $saldoAplicadoStmt->fetchAll() ?: [];
                } elseif (msp2TableExists($conn, 'msp_pagos')) {
                    $saldoFavorAppliedFlow['disponible'] = true;
                    $hasAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
                    $hasMontoSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');
                    $hasMontoPagado = msp2ColumnExists($conn, 'msp_pagos', 'monto_pagado');
                    $hasMontoPago = msp2ColumnExists($conn, 'msp_pagos', 'monto_pago');
                    $saldoFavorAppliedFlow['columns_ok'] = $hasAplicaSaldoFavor;

                    if ($hasAplicaSaldoFavor) {
                        $montoPagoExpr = $hasMontoPagado
                            ? 'ISNULL(p.monto_pagado, 0)'
                            : ($hasMontoPago ? 'ISNULL(p.monto_pago, 0)' : '0');
                        $montoAplicadoExpr = $hasMontoSaldoFavor
                            ? "CASE
                                WHEN ISNULL(p.monto_saldo_favor_generado, 0) > 0 THEN p.monto_saldo_favor_generado
                                ELSE $montoPagoExpr
                               END"
                            : $montoPagoExpr;
                        $estadoPagoFilter = msp2ColumnExists($conn, 'msp_pagos', 'estado_pago')
                            ? ' AND p.estado_pago = 1'
                            : '';

                        $saldoAplicadoStmt = $conn->prepare(
                            "SELECT
                                p.id_pago,
                                p.fecha_pago,
                                p.id_documento_cobro,
                                COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                                dc.fecha_vencimiento,
                                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                                ROUND($montoAplicadoExpr, 2) AS monto_aplicado
                             FROM dbo.msp_pagos p
                             INNER JOIN dbo.msp_documentos_cobro dc
                                ON dc.id_documento_cobro = p.id_documento_cobro
                             INNER JOIN dbo.msp_tiendas t
                                ON t.id_tienda = dc.id_tienda
                             WHERE dc.periodo_facturacion = :periodo"
                             . $estadoPagoFilter . "
                               AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1
                               AND ($montoAplicadoExpr) > 0
                             ORDER BY p.fecha_pago ASC, p.id_pago ASC"
                        );
                        $saldoAplicadoStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                        $saldoAplicadoStmt->execute();
                        $saldoFavorAppliedFlow['rows'] = $saldoAplicadoStmt->fetchAll() ?: [];
                    }
                }

                if (($saldoFavorAppliedFlow['rows'] ?? []) !== []) {
                    $saldoFavorAppliedFlow['count'] = count($saldoFavorAppliedFlow['rows']);
                    $totalSaldoAplicado = 0.0;
                    foreach ($saldoFavorAppliedFlow['rows'] as $saldoAplicadoRow) {
                        $totalSaldoAplicado += (float) ($saldoAplicadoRow['monto_aplicado'] ?? 0);
                    }
                    $saldoFavorAppliedFlow['total'] = round($totalSaldoAplicado, 2);
                }

                if (msp2TableExists($conn, 'msp_cargos_salida')) {
                    $cargosSalidaAsocStmt = $conn->prepare(
                        'SELECT COUNT(*)
                         FROM dbo.msp_cargos_salida cs
                         INNER JOIN dbo.msp_documentos_cobro dc
                            ON dc.id_documento_cobro = cs.id_documento_cobro
                         WHERE dc.periodo_facturacion = :periodo'
                    );
                    $cargosSalidaAsocStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $cargosSalidaAsocStmt->execute();
                    $summary['cargos_salida_asociados'] = (int) $cargosSalidaAsocStmt->fetchColumn();
                }

                if (
                    msp2TableExists($conn, 'msp_cargos_salida')
                    && msp2TableExists($conn, 'msp_tipos_cargo_salida')
                    && msp2TableExists($conn, 'msp_contratos_arriendo')
                    && msp2TableExists($conn, 'msp_contrato_locales')
                    && msp2TableExists($conn, 'msp_locales')
                ) {
                    $extraCharges['disponible'] = true;
                    $tiposExtraStmt = $conn->query(
                        "SELECT
                            id_tipo_cargo_salida,
                            codigo_tipo_cargo,
                            nombre_tipo_cargo
                         FROM dbo.msp_tipos_cargo_salida
                         WHERE activo = 1
                         ORDER BY nombre_tipo_cargo ASC"
                    );
                    $extraChargeTypes = $tiposExtraStmt->fetchAll() ?: [];
                    $extraHasArrendatarios = msp2TableExists($conn, 'msp_arrendatarios');

                    $targetsStmt = $conn->prepare(
                        "DECLARE @periodo DATE = :periodo;
                         ;WITH targets_raw AS (
                            SELECT
                                cl.id_contrato_arriendo,
                                cl.id_local,
                                loc.cdo_local,
                                ca.id_tienda,
                                t.nombre_comercial,
                                " . ($extraHasArrendatarios
                                    ? "COALESCE(
                                            NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                                            NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                                            CONCAT(N'Arrendatario #', a.id_arrendatario)
                                        )"
                                    : "NULL") . " AS nombre_arrendatario,
                                ROW_NUMBER() OVER (
                                    PARTITION BY cl.id_contrato_arriendo, cl.id_local
                                    ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
                                ) AS rn
                            FROM dbo.msp_contrato_locales cl
                            INNER JOIN dbo.msp_contratos_arriendo ca
                                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                            INNER JOIN dbo.msp_tiendas t
                                ON t.id_tienda = ca.id_tienda
                            " . ($extraHasArrendatarios
                                ? "LEFT JOIN dbo.msp_arrendatarios a
                                   ON a.id_arrendatario = t.id_arrendatario"
                                : "") . "
                            INNER JOIN dbo.msp_locales loc
                                ON loc.id_local = cl.id_local
                            WHERE cl.estado_relacion = 1
                              AND cl.fecha_inicio <= EOMONTH(@periodo)
                              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                              AND ca.fecha_inicio <= EOMONTH(@periodo)
                              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                              AND ca.estado_contrato IN (1,2,3)
                         )
                         SELECT
                            id_contrato_arriendo,
                            id_local,
                            cdo_local,
                            id_tienda,
                            nombre_comercial,
                            nombre_arrendatario
                         FROM targets_raw
                         WHERE rn = 1
                         ORDER BY " . msp2LocalCodeNaturalOrderSql('cdo_local') . ", nombre_comercial ASC"
                    );
                    $targetsStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $targetsStmt->execute();
                    $extraChargeTargets = $targetsStmt->fetchAll() ?: [];

                    $extrasStmt = $conn->prepare(
                        "DECLARE @periodo DATE = :periodo;
                         SELECT
                            cs.id_cargo_salida,
                            cs.id_tipo_cargo_salida,
                            cs.fecha_cargo,
                            cs.descripcion_cargo,
                            cs.observaciones,
                            cs.monto_cargo,
                            tc.codigo_tipo_cargo,
                            tc.nombre_tipo_cargo,
                            loc.cdo_local,
                            t.id_tienda,
                            t.nombre_comercial,
                            " . ($extraHasArrendatarios
                                ? "COALESCE(
                                        NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                                        NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                                        CONCAT(N'Arrendatario #', a.id_arrendatario)
                                    )"
                                : "NULL") . " AS nombre_arrendatario
                         FROM dbo.msp_cargos_salida cs
                         INNER JOIN dbo.msp_tipos_cargo_salida tc
                            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
                         LEFT JOIN dbo.msp_locales loc
                            ON loc.id_local = cs.id_local
                         INNER JOIN dbo.msp_tiendas t
                            ON t.id_tienda = ca.id_tienda
                         " . ($extraHasArrendatarios
                            ? "LEFT JOIN dbo.msp_arrendatarios a
                               ON a.id_arrendatario = t.id_arrendatario"
                            : "") . "
                         WHERE cs.estado_cargo IN (1, 2)
                           AND cs.id_documento_cobro IS NULL
                           AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
                           AND cs.monto_cargo > 0
                         ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
                    );
                    $extrasStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $extrasStmt->execute();
                    $extraCharges['pendientes'] = $extrasStmt->fetchAll() ?: [];
                    $extraCharges['pendientes_count'] = count($extraCharges['pendientes']);
                    $extraTotal = 0.0;
                    foreach ($extraCharges['pendientes'] as $extraRow) {
                        $extraTotal += (float) ($extraRow['monto_cargo'] ?? 0);
                    }
                    $extraCharges['pendientes_total'] = $extraTotal;

                    $extrasAplicadosStmt = $conn->prepare(
                        "DECLARE @periodo DATE = :periodo;
                         SELECT
                            cs.id_cargo_salida,
                            cs.fecha_cargo,
                            cs.descripcion_cargo,
                            cs.monto_cargo,
                            tc.codigo_tipo_cargo,
                            tc.nombre_tipo_cargo,
                            loc.cdo_local,
                            t.id_tienda,
                            t.nombre_comercial,
                            dc.id_documento_cobro,
                            dc.numero_documento
                         FROM dbo.msp_cargos_salida cs
                         INNER JOIN dbo.msp_tipos_cargo_salida tc
                            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
                         INNER JOIN dbo.msp_documentos_cobro dc
                            ON dc.id_documento_cobro = cs.id_documento_cobro
                           AND dc.periodo_facturacion = @periodo
                         LEFT JOIN dbo.msp_locales loc
                            ON loc.id_local = cs.id_local
                         LEFT JOIN dbo.msp_tiendas t
                            ON t.id_tienda = dc.id_tienda
                         ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
                    );
                    $extrasAplicadosStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $extrasAplicadosStmt->execute();
                    $extraCharges['aplicados'] = $extrasAplicadosStmt->fetchAll() ?: [];
                    $extraCharges['aplicados_count'] = count($extraCharges['aplicados']);
                    $extraAplicadoTotal = 0.0;
                    foreach ($extraCharges['aplicados'] as $extraAplicadoRow) {
                        $extraAplicadoTotal += (float) ($extraAplicadoRow['monto_cargo'] ?? 0);
                    }
                    $extraCharges['aplicados_total'] = $extraAplicadoTotal;
                }
            }

            if (
                msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
                && msp2TableExists($conn, 'msp_tiendas')
            ) {
                $saldoFavorManualFlow['disponible'] = true;

                if (msp2TableExists($conn, 'msp_arrendatarios')) {
                    $saldoTiendasStmt = $conn->query(
                        'SELECT t.id_tienda, t.nombre_comercial, a.nombre_locatario
                         FROM dbo.msp_tiendas t
                         LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
                         ORDER BY t.nombre_comercial ASC'
                    );
                } else {
                    $saldoTiendasStmt = $conn->query(
                        'SELECT t.id_tienda, t.nombre_comercial, NULL AS nombre_locatario
                         FROM dbo.msp_tiendas t
                         ORDER BY t.nombre_comercial ASC'
                    );
                }
                $saldoFavorManualFlow['tiendas'] = $saldoTiendasStmt->fetchAll() ?: [];

                if (
                    msp2TableExists($conn, 'msp_contratos_arriendo')
                    && msp2TableExists($conn, 'msp_contrato_locales')
                    && msp2TableExists($conn, 'msp_locales')
                ) {
                    $localesStmt = $conn->prepare(
                        "DECLARE @periodo DATE = :periodo;
                         SELECT
                            ca.id_tienda,
                            l.cdo_local
                         FROM dbo.msp_contrato_locales cl
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                         INNER JOIN dbo.msp_locales l
                            ON l.id_local = cl.id_local
                         WHERE cl.estado_relacion = 1
                           AND cl.fecha_inicio <= EOMONTH(@periodo)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                           AND ca.fecha_inicio <= EOMONTH(@periodo)
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                           AND ca.estado_contrato IN (1,2,3)
                         ORDER BY ca.id_tienda ASC, " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
                    );
                    $localesStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $localesStmt->execute();
                    while (($localRow = $localesStmt->fetch()) !== false) {
                        $localTiendaId = (int) ($localRow['id_tienda'] ?? 0);
                        $localCode = msp2NormalizeLocalCode((string) ($localRow['cdo_local'] ?? ''));
                        if ($localTiendaId <= 0 || $localCode === '') {
                            continue;
                        }
                        if (!isset($saldoFavorManualFlow['locales_por_tienda'][$localTiendaId])) {
                            $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId] = [];
                        }
                        $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId][] = $localCode;
                    }

                    foreach ($saldoFavorManualFlow['locales_por_tienda'] as $localTiendaId => $localCodesRaw) {
                        if (!is_array($localCodesRaw) || $localCodesRaw === []) {
                            continue;
                        }
                        $localCodesMap = [];
                        foreach ($localCodesRaw as $localRaw) {
                            $localNorm = msp2NormalizeLocalCode((string) $localRaw);
                            $localKey = msp2LocalCodeKey($localNorm);
                            if ($localKey === '' || isset($localCodesMap[$localKey])) {
                                continue;
                            }
                            $localCodesMap[$localKey] = $localNorm;
                        }
                        $localCodesSorted = array_values($localCodesMap);
                        usort($localCodesSorted, static fn (string $a, string $b): int => msp2CompareLocalCode($a, $b));
                        $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId] = $localCodesSorted;
                    }
                }

                $saldoDisponiblePorTienda = [];
                if (msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
                    $saldoDisponibleStmt = $conn->query(
                        'SELECT id_tienda, saldo_disponible
                         FROM dbo.msp_saldos_favor_tienda
                         WHERE saldo_disponible > 0'
                    );
                    while (($saldoRow = $saldoDisponibleStmt->fetch()) !== false) {
                        $saldoTiendaId = (int) ($saldoRow['id_tienda'] ?? 0);
                        if ($saldoTiendaId <= 0) {
                            continue;
                        }
                        $saldoDisponiblePorTienda[$saldoTiendaId] = round((float) ($saldoRow['saldo_disponible'] ?? 0), 2);
                    }
                }

                $ingresadoPeriodoPorTienda = [];
                $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');
                $hasPeriodoAplicacionesTable = $hasPeriodoItemsTable && msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');
                if ($hasPeriodoItemsTable) {
                    if ($hasPeriodoAplicacionesTable) {
                        $ingresadoPeriodoStmt = $conn->prepare(
                            'SELECT
                                sfpi.id_tienda,
                                ROUND(SUM(
                                    sfpi.monto_original
                                    - ISNULL(sfa_res.total_aplicado_activo, 0)
                                ), 2) AS total_ingresado
                             FROM dbo.msp_saldo_favor_periodo_items sfpi
                             OUTER APPLY (
                                SELECT ROUND(SUM(sfa.monto_aplicado), 2) AS total_aplicado_activo
                                FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
                                WHERE sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
                                  AND sfa.estado_aplicacion = 1
                             ) sfa_res
                             WHERE sfpi.periodo_facturacion = :periodo
                               AND sfpi.estado_item = 1
                             GROUP BY sfpi.id_tienda'
                        );
                    } else {
                        $ingresadoPeriodoStmt = $conn->prepare(
                            'SELECT id_tienda, ROUND(SUM(monto_original), 2) AS total_ingresado
                             FROM dbo.msp_saldo_favor_periodo_items
                             WHERE periodo_facturacion = :periodo
                               AND estado_item = 1
                             GROUP BY id_tienda'
                        );
                    }
                    $ingresadoPeriodoStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $ingresadoPeriodoStmt->execute();
                    while (($ingRow = $ingresadoPeriodoStmt->fetch()) !== false) {
                        $ingTiendaId = (int) ($ingRow['id_tienda'] ?? 0);
                        if ($ingTiendaId <= 0) {
                            continue;
                        }
                        $ingresadoPeriodoPorTienda[$ingTiendaId] = round((float) ($ingRow['total_ingresado'] ?? 0), 2);
                    }
                } else {
                    $ingresadoPeriodoStmt = $conn->prepare(
                        'DECLARE @periodo DATE = :periodo;
                         SELECT id_tienda, ROUND(SUM(monto_movimiento), 2) AS total_ingresado
                         FROM dbo.msp_movimientos_saldo_favor_tienda
                         WHERE tipo_movimiento = 5
                           AND fecha_movimiento >= @periodo
                           AND fecha_movimiento <= EOMONTH(@periodo)
                         GROUP BY id_tienda'
                    );
                    $ingresadoPeriodoStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $ingresadoPeriodoStmt->execute();
                    while (($ingRow = $ingresadoPeriodoStmt->fetch()) !== false) {
                        $ingTiendaId = (int) ($ingRow['id_tienda'] ?? 0);
                        if ($ingTiendaId <= 0) {
                            continue;
                        }
                        $ingresadoPeriodoPorTienda[$ingTiendaId] = round((float) ($ingRow['total_ingresado'] ?? 0), 2);
                    }
                }

                $tiendaInfoById = [];
                foreach ($saldoFavorManualFlow['tiendas'] as $tiendaRow) {
                    $tiendaIdRow = (int) ($tiendaRow['id_tienda'] ?? 0);
                    if ($tiendaIdRow <= 0) {
                        continue;
                    }
                    $tiendaInfoById[$tiendaIdRow] = $tiendaRow;
                }

                if ($hasPeriodoItemsTable) {
                    if ($hasPeriodoAplicacionesTable) {
                        $manualRowsStmt = $conn->prepare(
                            'SELECT
                                sfpi.id_saldo_favor_periodo_item,
                                sfpi.id_movimiento_saldo_favor,
                                sfpi.id_tienda,
                                sfpi.fecha_movimiento,
                                sfpi.monto_original AS monto_movimiento,
                                ROUND(
                                    sfpi.monto_original
                                    - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                                    2
                                ) AS monto_pendiente,
                                sfpi.observaciones
                             FROM dbo.msp_saldo_favor_periodo_items sfpi
                             LEFT JOIN dbo.msp_saldo_favor_periodo_aplicaciones sfa
                                ON sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
                             WHERE sfpi.periodo_facturacion = :periodo
                               AND sfpi.estado_item = 1
                             GROUP BY
                                sfpi.id_saldo_favor_periodo_item,
                                sfpi.id_movimiento_saldo_favor,
                                sfpi.id_tienda,
                                sfpi.fecha_movimiento,
                                sfpi.monto_original,
                                sfpi.observaciones
                             HAVING ROUND(
                                sfpi.monto_original
                                - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                                2
                             ) > 0
                             ORDER BY sfpi.fecha_movimiento DESC, sfpi.id_saldo_favor_periodo_item DESC'
                        );
                    } else {
                        $manualRowsStmt = $conn->prepare(
                            'SELECT
                                id_movimiento_saldo_favor,
                                id_tienda,
                                fecha_movimiento,
                                monto_original AS monto_movimiento,
                                monto_original AS monto_pendiente,
                                observaciones
                             FROM dbo.msp_saldo_favor_periodo_items
                             WHERE periodo_facturacion = :periodo
                               AND estado_item = 1
                             ORDER BY fecha_movimiento DESC, id_saldo_favor_periodo_item DESC'
                        );
                    }
                    $manualRowsStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                    $manualRowsStmt->execute();
                    while (($manualRow = $manualRowsStmt->fetch()) !== false) {
                        $manualTiendaId = (int) ($manualRow['id_tienda'] ?? 0);
                        if ($manualTiendaId <= 0) {
                            continue;
                        }

                        $manualMovId = (int) ($manualRow['id_movimiento_saldo_favor'] ?? 0);
                        $montoMovimientoRow = round((float) ($manualRow['monto_movimiento'] ?? 0), 2);
                        $montoPendienteRow = round((float) ($manualRow['monto_pendiente'] ?? $montoMovimientoRow), 2);
                        if ($montoPendienteRow <= 0.005) {
                            continue;
                        }
                        $tiendaInfo = $tiendaInfoById[$manualTiendaId] ?? [];
                        $arrLabel = trim((string) ($tiendaInfo['nombre_locatario'] ?? ''));
                        if ($arrLabel === '') {
                            $arrLabel = trim((string) ($tiendaInfo['nombre_comercial'] ?? ''));
                        }
                        if ($arrLabel === '') {
                            $arrLabel = 'Arrendatario #' . $manualTiendaId;
                        }

                        $saldoFavorManualFlow['manual_rows'][] = [
                            'id_movimiento_saldo_favor' => $manualMovId,
                            'id_tienda' => $manualTiendaId,
                            'fecha_movimiento' => substr((string) ($manualRow['fecha_movimiento'] ?? ''), 0, 10),
                            'monto_movimiento' => $montoMovimientoRow,
                            'monto_pendiente' => $montoPendienteRow,
                            'observaciones' => (string) ($manualRow['observaciones'] ?? ''),
                            'locales' => $saldoFavorManualFlow['locales_por_tienda'][$manualTiendaId] ?? [],
                            'nombre_arrendatario' => $arrLabel,
                            'saldo_disponible' => round((float) ($saldoDisponiblePorTienda[$manualTiendaId] ?? 0), 2),
                        ];
                    }
                } else {
                    $reversasManualById = [];
                    $reversasStmt = $conn->query(
                        "SELECT observaciones
                         FROM dbo.msp_movimientos_saldo_favor_tienda
                         WHERE tipo_movimiento = 3
                           AND (
                                CHARINDEX('[REVERSA_MANUAL:', ISNULL(observaciones, '')) > 0
                                OR observaciones LIKE 'Reversa manual de ingreso #%'
                           )"
                    );
                    while (($reversaRow = $reversasStmt->fetch()) !== false) {
                        $obsReversa = (string) ($reversaRow['observaciones'] ?? '');
                        if (preg_match('/\[REVERSA_MANUAL:(\d+)\]/', $obsReversa, $m) === 1) {
                            $reversasManualById[(int) ($m[1] ?? 0)] = true;
                        } elseif (preg_match('/Reversa manual de ingreso #(\d+)/i', $obsReversa, $m) === 1) {
                            $reversasManualById[(int) ($m[1] ?? 0)] = true;
                        }
                    }

                    $manualWindowRows = omManualAdjustmentWindow($periodoActual);
                    if (is_array($manualWindowRows)) {
                        $windowMin = (string) ($manualWindowRows['min'] ?? '');
                        $windowMax = (string) ($manualWindowRows['max'] ?? '');
                        if ($windowMin !== '' && $windowMax !== '') {
                            $manualRowsStmt = $conn->prepare(
                                'SELECT
                                    id_movimiento_saldo_favor,
                                    id_tienda,
                                    fecha_movimiento,
                                    monto_movimiento,
                                    observaciones
                                 FROM dbo.msp_movimientos_saldo_favor_tienda
                                 WHERE tipo_movimiento = 5
                                   AND monto_movimiento > 0
                                   AND fecha_movimiento >= :fecha_min
                                   AND fecha_movimiento <= :fecha_max
                                 ORDER BY fecha_movimiento DESC, id_movimiento_saldo_favor DESC'
                            );
                            $manualRowsStmt->bindValue(':fecha_min', $windowMin, PDO::PARAM_STR);
                            $manualRowsStmt->bindValue(':fecha_max', $windowMax, PDO::PARAM_STR);
                            $manualRowsStmt->execute();
                            while (($manualRow = $manualRowsStmt->fetch()) !== false) {
                                $manualTiendaId = (int) ($manualRow['id_tienda'] ?? 0);
                                if ($manualTiendaId <= 0) {
                                    continue;
                                }

                                $manualMovId = (int) ($manualRow['id_movimiento_saldo_favor'] ?? 0);
                                $tiendaInfo = $tiendaInfoById[$manualTiendaId] ?? [];
                                $arrLabel = trim((string) ($tiendaInfo['nombre_locatario'] ?? ''));
                                if ($arrLabel === '') {
                                    $arrLabel = trim((string) ($tiendaInfo['nombre_comercial'] ?? ''));
                                }
                                if ($arrLabel === '') {
                                    $arrLabel = 'Arrendatario #' . $manualTiendaId;
                                }

                                if (isset($reversasManualById[$manualMovId])) {
                                    continue;
                                }

                                $saldoFavorManualFlow['manual_rows'][] = [
                                    'id_movimiento_saldo_favor' => $manualMovId,
                                    'id_tienda' => $manualTiendaId,
                                    'fecha_movimiento' => substr((string) ($manualRow['fecha_movimiento'] ?? ''), 0, 10),
                                    'monto_movimiento' => round((float) ($manualRow['monto_movimiento'] ?? 0), 2),
                                    'monto_pendiente' => round((float) ($manualRow['monto_movimiento'] ?? 0), 2),
                                    'observaciones' => (string) ($manualRow['observaciones'] ?? ''),
                                    'locales' => $saldoFavorManualFlow['locales_por_tienda'][$manualTiendaId] ?? [],
                                    'nombre_arrendatario' => $arrLabel,
                                    'saldo_disponible' => round((float) ($saldoDisponiblePorTienda[$manualTiendaId] ?? 0), 2),
                                ];
                            }
                        }
                    }
                }

                foreach ($saldoFavorManualFlow['tiendas'] as $tiendaRow) {
                    $tiendaId = (int) ($tiendaRow['id_tienda'] ?? 0);
                    if ($tiendaId <= 0) {
                        continue;
                    }

                    $saldoDisponible = (float) ($saldoDisponiblePorTienda[$tiendaId] ?? 0.0);
                    $ingresadoPeriodo = (float) ($ingresadoPeriodoPorTienda[$tiendaId] ?? 0.0);
                    if ($saldoDisponible <= 0 && $ingresadoPeriodo <= 0) {
                        continue;
                    }

                    $saldoFavorManualFlow['resumen'][] = [
                        'id_tienda' => $tiendaId,
                        'nombre_comercial' => (string) ($tiendaRow['nombre_comercial'] ?? ''),
                        'nombre_locatario' => (string) ($tiendaRow['nombre_locatario'] ?? ''),
                        'locales' => $saldoFavorManualFlow['locales_por_tienda'][$tiendaId] ?? [],
                        'saldo_disponible' => $saldoDisponible,
                        'ingresado_periodo' => $ingresadoPeriodo,
                    ];
                    $saldoFavorManualFlow['total_disponible'] += $saldoDisponible;
                    $saldoFavorManualFlow['total_ingresado_periodo'] += $ingresadoPeriodo;
                }

                usort(
                    $saldoFavorManualFlow['resumen'],
                    static function (array $a, array $b): int {
                        $cmp = ((float) ($b['saldo_disponible'] ?? 0)) <=> ((float) ($a['saldo_disponible'] ?? 0));
                        if ($cmp !== 0) {
                            return $cmp;
                        }
                        return strcasecmp((string) ($a['nombre_comercial'] ?? ''), (string) ($b['nombre_comercial'] ?? ''));
                    }
                );
            }

            $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
            $correoSelect = $correoTableExiste
                ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
                : "'' AS correo_principal";
            $correoJoin = $correoTableExiste
                ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
                : '';

            $envioSql = "
                SELECT
                    a.id_arrendatario,
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                        NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                        NULLIF(LTRIM(RTRIM(a.rut)), ''),
                        CONCAT('Arrendatario #', a.id_arrendatario)
                    ) AS nombre_arrendatario,
                    LTRIM(RTRIM(a.rut)) AS rut,
                    $correoSelect,
                    COUNT(dc.id_documento_cobro) AS documentos
                FROM dbo.msp_arrendatarios a
                INNER JOIN dbo.msp_tiendas t ON t.id_arrendatario = a.id_arrendatario
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_tienda = t.id_tienda
                $correoJoin
                WHERE dc.periodo_facturacion = :periodo
                  AND dc.estado_documento <> 5
                GROUP BY a.id_arrendatario, a.nombre_locatario, a.nombre_representante, a.rut
                ORDER BY nombre_arrendatario ASC";
            $envioStmt = $conn->prepare($envioSql);
            $envioStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
            $envioStmt->execute();
            $envioArrendatarios = $envioStmt->fetchAll() ?: [];
            $envioTotales['arrendatarios'] = count($envioArrendatarios);
            $totalDocs = 0;
            foreach ($envioArrendatarios as $envioRow) {
                $totalDocs += (int) ($envioRow['documentos'] ?? 0);
            }
            $envioTotales['documentos'] = $totalDocs;

            if ($envioArrendatarios !== []) {
                $docsStmt = $conn->prepare(
                    'SELECT
                        a.id_arrendatario,
                        dc.id_documento_cobro,
                        dc.numero_documento,
                        dc.monto_total,
                        dc.saldo_pendiente
                     FROM dbo.msp_arrendatarios a
                     INNER JOIN dbo.msp_tiendas t ON t.id_arrendatario = a.id_arrendatario
                     INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_tienda = t.id_tienda
                     WHERE dc.periodo_facturacion = :periodo
                       AND dc.estado_documento <> 5
                     ORDER BY a.id_arrendatario, dc.id_documento_cobro'
                );
                $docsStmt->bindValue(':periodo', $periodoActual, PDO::PARAM_STR);
                $docsStmt->execute();
                $seenDocsByArr = [];
                while (($docRow = $docsStmt->fetch()) !== false) {
                    $arrId = (int) ($docRow['id_arrendatario'] ?? 0);
                    $docId = (int) ($docRow['id_documento_cobro'] ?? 0);
                    if ($arrId <= 0) {
                        continue;
                    }
                    if ($docId <= 0 || isset($seenDocsByArr[$arrId][$docId])) {
                        continue;
                    }
                    $seenDocsByArr[$arrId][$docId] = true;
                    $envioDocsByArr[$arrId][] = $docRow;
                }
            }

            $lotesProgramadosDisponibles = EnvioLotesProgramadosService::isAvailable($conn);
            if ($lotesProgramadosDisponibles) {
                $lotesProgramados = EnvioLotesProgramadosService::fetchLotesByPeriodo($conn, $periodoActual, 40, false);
                foreach (['LUZ', 'GAS', 'AGUA'] as $stageCode) {
                    $completionSummaryByStage[$stageCode] = EnvioLotesProgramadosService::fetchCompletionSummaryByStage(
                        $conn,
                        $periodoActual,
                        $stageCode
                    );
                }
            }
            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                $poolSegmentationDiagnostics = PoolDocumentosPeriodoService::fetchSegmentationDiagnostics($conn, $periodoActual);
                $sinServicioStats = PoolDocumentosPeriodoService::fetchSinServicioStats($conn, $periodoActual);
            }
            foreach (['LUZ', 'GAS', 'AGUA'] as $stageCode) {
                $stageSummary = $completionSummaryByStage[$stageCode] ?? [];
                $completionTotals['arrendatarios'] += (int) ($stageSummary['arrendatarios'] ?? 0);
                $completionTotals['documentos'] += (int) ($stageSummary['documentos'] ?? 0);
                if ((bool) ($stageSummary['tiene_candidatos'] ?? false)) {
                    $completionTotals['etapas_con_candidatos']++;
                }
            }

            foreach ($lotesProgramados as $loteStageRow) {
                $codigoStage = strtoupper(trim((string) ($loteStageRow['codigo_servicio'] ?? '')));
                if ($codigoStage === 'SIN_SERVICIO') {
                    $idLoteSinServicio = (int) ($loteStageRow['id_lote_envio'] ?? 0);
                    $estadoSinServicio = (int) ($loteStageRow['estado_lote'] ?? 0);
                    $sinServicioLotesStats['lotes']++;
                    $sinServicioLotesStats['enviados'] += (int) ($loteStageRow['enviados'] ?? 0);
                    if ($estadoSinServicio !== 5 && !is_array($sinServicioBlockingLote)) {
                        $sinServicioBlockingLote = [
                            'id_lote_envio' => $idLoteSinServicio,
                            'estado_lote' => $estadoSinServicio,
                            'estado_label' => EnvioLotesProgramadosService::buildEstadoLabel($estadoSinServicio),
                        ];
                    }
                    if ($estadoSinServicio === 1 || $estadoSinServicio === 2) {
                        $sinServicioLotesStats['activos']++;
                    } elseif ($estadoSinServicio === 3) {
                        $sinServicioLotesStats['completados']++;
                    } elseif ($estadoSinServicio === 4) {
                        $sinServicioLotesStats['con_error']++;
                    } elseif ($estadoSinServicio === 5) {
                        $sinServicioLotesStats['cancelados']++;
                    }
                    continue;
                }
                if (!isset($lotesStageStats[$codigoStage])) {
                    continue;
                }
                $idLoteStage = (int) ($loteStageRow['id_lote_envio'] ?? 0);
                $estadoStage = (int) ($loteStageRow['estado_lote'] ?? 0);
                $lotesStageStats[$codigoStage]['lotes']++;
                $lotesStageStats[$codigoStage]['enviados'] += (int) ($loteStageRow['enviados'] ?? 0);
                if ($estadoStage !== 5) {
                    if (!is_array($stageBlockingLotes[$codigoStage] ?? null)) {
                        $stageBlockingLotes[$codigoStage] = [
                            'id_lote_envio' => $idLoteStage,
                            'estado_lote' => $estadoStage,
                            'estado_label' => EnvioLotesProgramadosService::buildEstadoLabel($estadoStage),
                        ];
                    }
                }
                if ($estadoStage === 1 || $estadoStage === 2) {
                    $lotesStageStats[$codigoStage]['activos']++;
                } elseif ($estadoStage === 3) {
                    $lotesStageStats[$codigoStage]['completados']++;
                } elseif ($estadoStage === 4) {
                    $lotesStageStats[$codigoStage]['con_error']++;
                } elseif ($estadoStage === 5) {
                    $lotesStageStats[$codigoStage]['cancelados']++;
                }
            }

            $nonCancelledStageLotesCount = 0;
            foreach (['LUZ', 'GAS', 'AGUA'] as $stageCountCode) {
                if (is_array($stageBlockingLotes[$stageCountCode] ?? null)) {
                    $nonCancelledStageLotesCount++;
                }
            }

            $uf = (float) ($selectedCierre['valor_uf'] ?? 0);
            $status['uf_ok'] = $uf > 0;

            $procCount = 0;
            $lectCount = 0;
            foreach ($servicios as $s) {
                if ((int) ($s['id_proceso_cobro'] ?? 0) > 0) {
                    $procCount++;
                }
                if ((int) ($s['cantidad_lecturas'] ?? 0) > 0) {
                    $lectCount++;
                }
            }
            $status['procesos_ok'] = $procCount > 0;
            $status['lecturas_ok'] = $lectCount > 0;
            $status['proc_label'] = "$procCount / 3 listos";
            $status['lect_label'] = "$lectCount / 3 cargados";
            $status['cobros_ok'] = $summary['cobros'] > 0;
            $status['cargos_extra_label'] = (string) ((int) ($extraCharges['pendientes_count'] ?? 0));
            $status['cargos_extra_ok'] = ((int) ($extraCharges['pendientes_count'] ?? 0)) === 0;
            $status['documentos_ok'] = $summary['documentos'] > 0;
            $status['saldo_favor_ok'] = ((int) ($saldoFavorFlow['docs_sugeridos'] ?? 0)) === 0;
            $status['saldo_favor_label'] = (string) ((int) ($saldoFavorFlow['docs_sugeridos'] ?? 0));
        }
    } catch (PDOException $e) {
        $loadError = 'No fue posible cargar el flujo mensual. Detalle tecnico: ' . $e->getMessage();
    }
}

$periodoNuevoDefaultYm = $periodoActualYm !== ''
    ? $periodoActualYm
    : (new DateTimeImmutable('today'))->format('Y-m');
$periodoActualYmUi = omFmtPeriodoYm($periodoActualYm);
$wizardEnabled = $selectedCierre !== null;
if (!$wizardEnabled) {
    $activeStep = 1;
}

$progressPercent = (int) round((($activeStep - 1) / 5) * 100);

$serviceDisplayOrder = ['LUZ', 'GAS', 'AGUA'];
$serviciosByCode = [];
foreach ($servicios as $servicioRow) {
    $code = strtoupper((string) ($servicioRow['codigo_servicio'] ?? ''));
    if ($code !== '') {
        $serviciosByCode[$code] = $servicioRow;
    }
}
$periodoFacturacionActual = omParseMonthToFirstDay($periodoActualYm) ?? '';
$serviceStepMetaByCode = [];
foreach ($serviceDisplayOrder as $serviceStepCode) {
    $serviceStepMetaByCode[$serviceStepCode] = omServiceStepUi($serviceStepCode, $periodoFacturacionActual);
}
$steps = [
    1 => ['title' => 'Periodo', 'subtitle' => 'Cargar o crear periodo', 'anchor' => 'paso-1'],
    2 => ['title' => 'Ajuste Manual', 'subtitle' => 'Cargos y saldo', 'anchor' => 'paso-5'],
    3 => [
        'title' => (string) ($serviceStepMetaByCode['LUZ']['title'] ?? omServiceStepBaseTitle('LUZ')),
        'subtitle' => (string) ($serviceStepMetaByCode['LUZ']['subtitle'] ?? 'Consumo del servicio'),
        'anchor' => 'servicio-luz',
    ],
    4 => [
        'title' => (string) ($serviceStepMetaByCode['GAS']['title'] ?? omServiceStepBaseTitle('GAS')),
        'subtitle' => (string) ($serviceStepMetaByCode['GAS']['subtitle'] ?? 'Consumo del servicio'),
        'anchor' => 'servicio-gas',
    ],
    5 => [
        'title' => (string) ($serviceStepMetaByCode['AGUA']['title'] ?? omServiceStepBaseTitle('AGUA')),
        'subtitle' => (string) ($serviceStepMetaByCode['AGUA']['subtitle'] ?? 'Consumo del servicio'),
        'anchor' => 'servicio-agua',
    ],
    6 => ['title' => 'Lotes', 'subtitle' => 'Vista previa y programación', 'anchor' => 'paso-6'],
];

$defaultFecha = (new DateTimeImmutable('today'))->format('Y-m-d');
$manualAdjustDateMin = $defaultFecha;
$manualAdjustDateMax = $defaultFecha;
$manualAdjustDateDefault = $defaultFecha;
$periodoManualWindow = omParseMonthToFirstDay($periodoActualYm);
if ($periodoManualWindow !== null) {
    $manualWindow = omManualAdjustmentWindow($periodoManualWindow);
    if (is_array($manualWindow)) {
        $manualAdjustDateMin = (string) ($manualWindow['min'] ?? $defaultFecha);
        $manualAdjustDateMax = (string) ($manualWindow['max'] ?? $defaultFecha);
        $manualAdjustDateDefault = (string) ($manualWindow['default'] ?? $defaultFecha);
    }
}
$manualAdjustDateRangeUi = omFmtFecha($manualAdjustDateMin) . ' al ' . omFmtFecha($manualAdjustDateMax);
$defaultLoteProgramadoAt = (new DateTimeImmutable('now +15 minutes'))->format('Y-m-d\TH:i');
$fechaValorUfDefault = $defaultFecha;
if ($selectedCierre !== null && !empty($selectedCierre['fecha_valor_uf'])) {
    $fechaValorUfDefault = substr((string) $selectedCierre['fecha_valor_uf'], 0, 10);
} elseif (preg_match('/^\d{4}-\d{2}$/', $periodoNuevoDefaultYm) === 1) {
    $fechaValorUfDefault = $periodoNuevoDefaultYm . '-01';
}
$estadosCierre = CierreMensualService::estados();
$hasCreatedPeriods = $periodos !== [];
$periodoFormMode = $selectedCierre !== null ? 'edit' : 'create';
if (!$hasCreatedPeriods) {
    $periodoFormMode = 'create';
}
$mostrarFormularioPeriodo = !$hasCreatedPeriods || $selectedCierre !== null;
$periodoFormTitle = $periodoFormMode === 'edit' ? '1) Editar periodo y UF' : '1) Crear periodo y UF';
$periodoFormModeLabel = $periodoFormMode === 'edit' ? 'Modo edicion' : 'Modo creacion';
$periodoFormSubmitLabel = $periodoFormMode === 'edit' ? 'Actualizar periodo' : 'Crear periodo';
$periodoInputHelp = $periodoFormMode === 'edit'
    ? 'Periodo bloqueado en edicion. Usa "Crear nuevo periodo" para registrar otro mes.'
    : 'Selecciona el mes que quieres crear.';
$selectedEstadoCierreId = (int) ($selectedCierre['estado_cierre'] ?? 1);
$selectedEstadoCierreLabel = omCierreEstadoLabel($selectedEstadoCierreId);
$canAdministrarCierre = msp2CurrentUserHasPermission('MSP Cierre Mensual');
$canRevisarPeriodo = $canAdministrarCierre && $selectedCierre !== null && $selectedEstadoCierreId === CierreMensualService::CALCULADO;
$canCerrarPeriodo = $canAdministrarCierre && $selectedCierre !== null && $selectedEstadoCierreId === CierreMensualService::REVISADO;
$canReabrirPeriodo = $canAdministrarCierre && $selectedCierre !== null && in_array($selectedEstadoCierreId, [2, 3, 4, 5], true);
$isPeriodoAnulado = $selectedCierre !== null && $selectedEstadoCierreId === 4;
$isPeriodoEditableForMutation = $selectedCierre !== null && $selectedEstadoCierreId === 1;
$isPeriodoGenerableForMutation = $selectedCierre !== null && in_array($selectedEstadoCierreId, [1, 2], true);

$completionHintModal = null;
if (is_array($completionHintSnapshot)) {
    $completionHintServicio = strtoupper(trim((string) ($completionHintSnapshot['servicio'] ?? '')));
    $completionHintTiendas = max(0, (int) ($completionHintSnapshot['tiendas'] ?? 0));
    if (in_array($completionHintServicio, ['LUZ', 'GAS', 'AGUA'], true) && $completionHintTiendas > 0) {
        $completionHintModal = [
            'servicio' => $completionHintServicio,
            'periodo' => (string) ($completionHintSnapshot['periodo'] ?? $periodoActualYm),
            'tiendas' => $completionHintTiendas,
            'arrendatarios' => max(0, (int) ($completionHintSnapshot['arrendatarios'] ?? 0)),
        ];
    }
}

$stageLotePromptModal = null;
if (is_array($stagePostGenerationSnapshot) && $lotesProgramadosDisponibles) {
    $stagePromptServicio = strtoupper(trim((string) ($stagePostGenerationSnapshot['servicio'] ?? '')));
    $stagePromptDocs = max(0, (int) ($stagePostGenerationSnapshot['documentos'] ?? 0));
    if (in_array($stagePromptServicio, ['LUZ', 'GAS', 'AGUA'], true) && $stagePromptDocs > 0) {
        $nextFocus = (string) ($stagePostGenerationSnapshot['next_focus'] ?? omNextFocusAfterStage($stagePromptServicio));
        if (!in_array($nextFocus, ['servicio-luz', 'servicio-gas', 'servicio-agua', 'paso-6'], true)) {
            $nextFocus = omNextFocusAfterStage($stagePromptServicio);
        }
        $nextFocusLabel = match ($nextFocus) {
            'servicio-luz' => 'Paso 3. ' . (string) ($serviceStepMetaByCode['LUZ']['title'] ?? omServiceStepBaseTitle('LUZ')),
            'servicio-gas' => 'Paso 4. ' . (string) ($serviceStepMetaByCode['GAS']['title'] ?? omServiceStepBaseTitle('GAS')),
            'servicio-agua' => 'Paso 5. ' . (string) ($serviceStepMetaByCode['AGUA']['title'] ?? omServiceStepBaseTitle('AGUA')),
            'paso-6' => 'Paso 6. Lotes',
            default => 'siguiente paso',
        };
        $nextStep = match ($nextFocus) {
            'servicio-luz' => 3,
            'servicio-gas' => 4,
            'servicio-agua' => 5,
            default => 6,
        };
        $nextUrl = msp2Url(
            omSelfRoute()
            . '?periodo=' . urlencode($periodoActualYm)
            . '&step=' . $nextStep
            . '&focus=' . urlencode($nextFocus)
        ) . '#' . $nextFocus;

        $stageLotePromptModal = [
            'servicio' => $stagePromptServicio,
            'periodo' => (string) ($stagePostGenerationSnapshot['periodo'] ?? $periodoActualYm),
            'documentos' => $stagePromptDocs,
            'arrendatarios' => max(0, (int) ($stagePostGenerationSnapshot['arrendatarios'] ?? 0)),
            'next_focus' => $nextFocus,
            'next_focus_label' => $nextFocusLabel,
            'next_url' => $nextUrl,
        ];
    }
}

$stageGenerationInline = null;
if (is_array($stageGenerationSnapshot)) {
    $stageGenServicio = strtoupper(trim((string) ($stageGenerationSnapshot['servicio'] ?? '')));
    $stageGenPeriodo = trim((string) ($stageGenerationSnapshot['periodo'] ?? ''));
    if (
        in_array($stageGenServicio, ['LUZ', 'GAS', 'AGUA'], true)
        && $stageGenPeriodo !== ''
        && omNormalizePeriodoYm($stageGenPeriodo) === omNormalizePeriodoYm($periodoActualYm)
    ) {
        $stageGenerationInline = [
            'servicio' => $stageGenServicio,
            'cobros_generados' => max(0, (int) ($stageGenerationSnapshot['cobros_generados'] ?? 0)),
            'documentos_generados' => max(0, (int) ($stageGenerationSnapshot['documentos_generados'] ?? 0)),
            'items_recompuestos' => max(0, (int) ($stageGenerationSnapshot['items_recompuestos'] ?? 0)),
            'arrendatarios' => max(0, (int) ($stageGenerationSnapshot['arrendatarios'] ?? 0)),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Generar Facturación</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .omw-shell {
            background: radial-gradient(circle at 85% 10%, rgba(11, 58, 110, 0.08), transparent 38%),
                        linear-gradient(180deg, #f8fbff 0%, #eef2f7 100%);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 1.25rem;
        }

        .omw-header {
            border-radius: var(--radius-md);
            background: linear-gradient(120deg, #0b3a6e 0%, #1f4f85 50%, #2d648f 100%);
            color: #fff;
            padding: 1.1rem;
        }

        .omw-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: #fff;
            font-size: 0.78rem;
            padding: 0.2rem 0.6rem;
        }

        .omw-step-list {
            display: flex;
            align-items: flex-start;
            overflow-x: auto;
            padding: 0.15rem 0.1rem 0.35rem;
            gap: 0;
            scrollbar-width: thin;
        }

        .omw-step-btn {
            position: relative;
            flex: 1 0 135px;
            min-width: 135px;
            border: 0;
            background: transparent;
            color: #4b5b6f;
            text-align: center;
            padding: 0 0.35rem;
            transition: color 0.2s ease;
        }

        .omw-step-btn::after {
            content: "";
            position: absolute;
            top: 1.1rem;
            left: calc(50% + 1.15rem);
            width: calc(100% - 2.3rem);
            height: 2px;
            background: #d5dfec;
            transition: background-color 0.2s ease;
        }

        .omw-step-btn:last-child::after {
            display: none;
        }

        .omw-step-dot {
            width: 2.3rem;
            height: 2.3rem;
            border-radius: 999px;
            border: 2px solid #9cb1cc;
            background: #fff;
            color: #5f6f83;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.2s ease;
            margin-bottom: 0.45rem;
        }

        .omw-step-dot i {
            font-size: 0.95rem;
            transition: color 0.2s ease;
        }

        .omw-step-meta {
            display: block;
            line-height: 1.25;
        }

        .omw-step-title {
            display: block;
            font-size: 0.79rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .omw-step-subtitle {
            display: block;
            color: #7a8798;
            font-size: 0.69rem;
            margin-top: 0.1rem;
        }

        .omw-step-btn.is-active {
            color: #0b3a6e;
        }

        .omw-step-btn.is-active .omw-step-dot {
            border-color: #0b3a6e;
            background: #0b3a6e;
            color: #fff;
            box-shadow: 0 0 0 4px rgba(11, 58, 110, 0.18);
        }

        .omw-step-btn.is-active .omw-step-subtitle {
            color: #0b3a6e;
        }

        .omw-step-btn.is-done {
            color: #146c43;
        }

        .omw-step-btn.is-done .omw-step-dot {
            border-color: #198754;
            background: #e9f7ef;
            color: #198754;
        }

        .omw-step-btn.is-done .omw-step-dot::after {
            content: "\F26E";
            font-family: "bootstrap-icons";
            position: absolute;
            right: -0.35rem;
            bottom: -0.32rem;
            width: 1rem;
            height: 1rem;
            border-radius: 999px;
            background: #198754;
            color: #fff;
            border: 2px solid #fff;
            font-size: 0.6rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 4px rgba(25, 135, 84, 0.35);
        }

        .omw-step-btn.is-done::after {
            background: #198754;
        }

        .omw-step-btn.is-done .omw-step-subtitle {
            color: #146c43;
        }

        .omw-step-pane {
            display: none;
        }

        .omw-step-pane.is-active {
            display: block;
            animation: omwFade 0.25s ease;
        }

        .omw-footer-nav {
            border-top: 1px dashed #c7d3e1;
            padding-top: 0.9rem;
            margin-top: 0.9rem;
        }

        .omw-confirm-shell {
            border: 1px solid #d5dfec;
            border-radius: var(--radius-md);
            background: #fff;
            box-shadow: var(--shadow-sm);
            padding: 1rem;
        }

        .omw-confirm-shell-head {
            margin-bottom: 0.8rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid #e6edf6;
        }

        .omw-confirm-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(280px, 1fr);
            gap: 1rem;
            align-items: start;
        }

        .omw-confirm-main {
            min-width: 0;
        }

        .omw-confirm-side {
            min-width: 0;
            border-left: 1px solid #e1e9f4;
            padding-left: 0.95rem;
        }

        .omw-service-grid {
            display: grid;
            gap: 0.7rem;
            margin-bottom: 1rem;
        }

        .omw-process-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid #d4dce8;
            border-radius: 999px;
            padding: 0.16rem 0.56rem;
            background: #f7f9fc;
            color: #4f6077;
            font-weight: 600;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }

        .omw-process-pill-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 999px;
            background: #8b99ae;
            flex-shrink: 0;
        }

        .omw-process-pill.is-ready {
            border-color: #a8d9ba;
            background: #e8f8ee;
            color: #146c43;
        }

        .omw-process-pill.is-ready .omw-process-pill-dot {
            background: #198754;
            box-shadow: 0 0 0 2px rgba(25, 135, 84, 0.15);
        }

        .omw-readings-panel {
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .omw-readings-panel.is-locked {
            position: relative;
            overflow: hidden;
            background: #e6ebf2 !important;
            border-color: #9aa8bc !important;
            box-shadow: inset 0 0 0 1px rgba(82, 99, 123, 0.2);
        }

        .omw-readings-panel.is-locked > :not([data-process-panel-head]):not(.omw-readings-lock) {
            filter: grayscale(0.35);
            opacity: 0.24;
            pointer-events: none;
            user-select: none;
        }

        .omw-readings-lock {
            display: none;
            position: absolute;
            inset: 0;
            align-items: center;
            justify-content: center;
            z-index: 2;
            pointer-events: none;
        }

        .omw-readings-lock-card {
            min-width: 280px;
            max-width: 92%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.45rem;
            text-align: center;
            border: 1px solid #c6d2e2;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 12px 24px rgba(31, 50, 76, 0.18);
            padding: 1.05rem 1.3rem;
        }

        .omw-readings-lock-icon {
            width: 3.15rem;
            height: 3.15rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #9fb3cc;
            background: linear-gradient(180deg, #f3f7fd 0%, #e3ebf6 100%);
            color: #3d4f67;
        }

        .omw-readings-lock-icon i {
            font-size: 1.65rem;
            line-height: 1;
        }

        .omw-readings-lock-label {
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #2b3f59;
        }

        .omw-readings-lock-help {
            font-size: 0.8rem;
            color: #586a83;
            margin: 0;
        }

        .omw-readings-panel.is-locked .omw-readings-lock {
            display: flex;
        }

        .omw-readings-panel.is-ready {
            border-color: #c9d9f4 !important;
            background: #f8fbff !important;
        }

        .omw-select-card {
            display: block;
            cursor: pointer;
            margin: 0;
        }

        .omw-select-card-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .omw-select-card-ui {
            border: 1px solid #d0dced;
            border-radius: 0.8rem;
            background: #fff;
            padding: 0.78rem 0.82rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            transition: all 0.2s ease;
        }

        .omw-select-card-input:checked + .omw-select-card-ui {
            border-color: #2a64d6;
            box-shadow: 0 0 0 2px rgba(42, 100, 214, 0.16);
            background: #f3f7ff;
        }

        .omw-select-card-input:disabled + .omw-select-card-ui {
            opacity: 0.62;
            background: #f7f9fc;
            cursor: not-allowed;
        }

        .omw-select-card-input:focus-visible + .omw-select-card-ui {
            outline: 2px solid #2a64d6;
            outline-offset: 1px;
        }

        .omw-select-card-left {
            display: flex;
            align-items: center;
            gap: 0.72rem;
            min-width: 0;
        }

        .omw-select-check {
            width: 1.28rem;
            height: 1.28rem;
            border: 2px solid #c0cedf;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: transparent;
            background: #fff;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .omw-select-card-input:checked + .omw-select-card-ui .omw-select-check {
            border-color: #2a64d6;
            background: #2a64d6;
            color: #fff;
        }

        .omw-select-service-icon {
            width: 2rem;
            height: 2rem;
            border-radius: 0.55rem;
            background: #eff4fc;
            color: #3b5b86;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .omw-select-service-info {
            min-width: 0;
        }

        .omw-select-service-title {
            font-size: 0.98rem;
            font-weight: 700;
            color: #20324b;
            margin: 0;
            line-height: 1.2;
        }

        .omw-select-service-sub {
            margin: 0.16rem 0 0;
            color: #728196;
            font-size: 0.81rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .omw-select-state {
            border-radius: 999px;
            font-size: 0.72rem;
            padding: 0.2rem 0.48rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.22rem;
            flex-shrink: 0;
        }

        .omw-select-state.is-ready {
            background: #dff4e8;
            color: #146c43;
        }

        .omw-select-state.is-pending {
            background: #fff4d9;
            color: #8a5a00;
        }

        .omw-confirm-options {
            border: 1px solid #d5dfec;
            border-radius: 0.75rem;
            background: #f8fbff;
            padding: 0.82rem;
        }

        .omw-confirm-side-title {
            margin-bottom: 0.75rem;
            font-weight: 700;
            color: #283b57;
        }

        .omw-confirm-kpi {
            border: 1px solid #d5dfec;
            background: #f7faff;
            border-radius: 0.7rem;
            padding: 0.65rem 0.72rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.65rem;
            margin-bottom: 0.55rem;
        }

        .omw-confirm-kpi .label {
            color: #6f8098;
            font-size: 0.77rem;
            margin: 0;
        }

        .omw-confirm-kpi .value {
            color: #20324b;
            font-weight: 700;
            font-size: 1.03rem;
            margin: 0;
        }

        .omw-confirm-selected-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
        }

        .omw-confirm-checklist {
            display: grid;
            gap: 0.45rem;
            margin-bottom: 0.75rem;
        }

        .omw-confirm-check-item {
            border: 1px solid #d5dfec;
            border-radius: 0.65rem;
            background: #fbfdff;
            padding: 0.5rem 0.58rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .omw-confirm-check-item.is-ok {
            border-color: #b9e5c8;
            background: #ecf9f1;
        }

        .omw-confirm-check-item.is-pending {
            border-color: #ecd9ad;
            background: #fff8e8;
        }

        .omw-confirm-check-left {
            display: flex;
            align-items: center;
            gap: 0.45rem;
            min-width: 0;
        }

        .omw-confirm-check-icon {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 999px;
            background: #e8eef8;
            color: #3d5b86;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            flex-shrink: 0;
        }

        .omw-confirm-check-item.is-ok .omw-confirm-check-icon {
            background: #198754;
            color: #fff;
        }

        .omw-confirm-check-item.is-pending .omw-confirm-check-icon {
            background: #e3b04f;
            color: #fff;
        }

        .omw-confirm-check-label {
            color: #53657f;
            font-size: 0.76rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .omw-confirm-check-value {
            color: #22344e;
            font-size: 0.82rem;
            font-weight: 700;
            margin-left: 0.5rem;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .omw-confirm-results-title {
            margin: 0.65rem 0 0.45rem;
            font-size: 0.78rem;
            color: #6f8098;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
        }

        .omw-confirm-results-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.45rem;
        }

        .omw-confirm-result-item {
            border: 1px solid #d5dfec;
            border-radius: 0.65rem;
            background: #f7faff;
            padding: 0.5rem 0.58rem;
        }

        .omw-confirm-result-label {
            margin: 0;
            color: #6f8098;
            font-size: 0.74rem;
        }

        .omw-confirm-result-value {
            margin: 0.12rem 0 0;
            color: #22344e;
            font-size: 0.95rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .omw-confirm-cta {
            width: 100%;
            min-height: 5rem;
            padding: 1.1rem 1.8rem;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: 0.015em;
            border-radius: 0.95rem;
            background: linear-gradient(135deg, #2a64d6 0%, #0b3a6e 100%);
            border-color: #0b3a6e;
            box-shadow: 0 18px 36px rgba(11, 58, 110, 0.34);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
        }

        .omw-confirm-cta-label {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            line-height: 1.05;
            text-transform: uppercase;
        }

        .omw-confirm-cta-line-main {
            font-size: 1.05em;
        }

        .omw-confirm-cta-line-sub {
            font-size: 0.82em;
            opacity: 0.96;
            letter-spacing: 0.06em;
            margin-top: 0.08rem;
        }

        .omw-confirm-cta:hover,
        .omw-confirm-cta:focus {
            background: linear-gradient(135deg, #255bc6 0%, #082f5a 100%);
            border-color: #082f5a;
            box-shadow: 0 20px 40px rgba(8, 47, 90, 0.38);
        }

        .omw-confirm-cta:disabled {
            background: #8fa5c4;
            border-color: #8fa5c4;
            box-shadow: none;
        }

        @keyframes omwFade {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .omw-picker-btn {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 2rem;
        }

        .omw-date-range-hint {
            font-size: 0.72rem;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .omw-required-mark {
            color: #dc3545;
            margin-left: 0.2rem;
        }

        .omw-optional-mark {
            color: #7a8798;
            font-size: 0.78rem;
            margin-left: 0.35rem;
            font-weight: 500;
        }

        @media (max-width: 991px) {
            .omw-shell {
                padding: 0.85rem;
            }

            .omw-step-btn {
                flex-basis: 122px;
                min-width: 122px;
            }

            .omw-confirm-layout {
                grid-template-columns: 1fr;
            }

            .omw-confirm-side {
                border-left: 0;
                border-top: 1px solid #e1e9f4;
                padding-left: 0;
                padding-top: 0.85rem;
            }

            .omw-confirm-cta {
                min-height: 4.4rem;
                font-size: 1.18rem;
            }
        }

        @media (max-width: 575px) {
            .omw-step-btn {
                flex-basis: 106px;
                min-width: 106px;
                padding: 0 0.2rem;
            }

            .omw-step-title {
                font-size: 0.72rem;
            }

            .omw-step-subtitle {
                display: none;
            }
        }
    </style>
    <?php msp2RenderMontoClpAssets(); ?>
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="omw-shell">
            <div class="omw-header mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2" data-gp-commandbar>
                    <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                    </a>
                    <div>
                        <h1 class="h4 mb-1">Generar documento de cobro</h1>
                    </div>
                    <span class="omw-chip"><i class="bi bi-calendar3"></i>Periodo <?php echo msp2Escape($periodoActualYmUi); ?></span>
                </div>
            </div>

            <div class="omw-step-list mb-3" role="tablist" aria-label="Pasos wizard">
                <?php foreach ($steps as $index => $step): ?>
                    <?php
                    $isActive = $index === $activeStep;
                    $isDone = $index < $activeStep;
                    $icon = match ($index) {
                        1 => 'bi-calendar3',
                        2 => 'bi-receipt-cutoff',
                        3 => 'bi-lightning-charge',
                        4 => 'bi-fire',
                        5 => 'bi-droplet',
                        6 => 'bi-envelope-paper',
                        default => 'bi-circle',
                    };
                    $btnClass = 'omw-step-btn';
                    if ($isActive) {
                        $btnClass .= ' is-active';
                    } elseif ($isDone) {
                        $btnClass .= ' is-done';
                    }
                    $stepDisabled = !$wizardEnabled && $index !== 1;
                    ?>
                    <button
                        type="button"
                        class="<?php echo msp2Escape($btnClass); ?>"
                        data-step-target="<?php echo $index; ?>"
                        data-step-anchor="<?php echo msp2Escape($step['anchor']); ?>"
                        role="tab"
                        aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        aria-disabled="<?php echo $stepDisabled ? 'true' : 'false'; ?>"
                        <?php echo $stepDisabled ? 'disabled' : ''; ?>>
                        <span class="omw-step-dot">
                            <i class="bi <?php echo msp2Escape($icon); ?> omw-step-icon" data-step-icon></i>
                        </span>
                        <span class="omw-step-meta">
                            <span class="omw-step-title">Paso <?php echo $index; ?>. <?php echo msp2Escape($step['title']); ?></span>
                            <small class="omw-step-subtitle"><?php echo msp2Escape($step['subtitle']); ?></small>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="small text-muted mb-3"><span class="text-danger">*</span> Campo obligatorio <span class="mx-1">|</span> <span class="omw-optional-mark">(opcional)</span> Campo no obligatorio</div>

            <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>
            <?php include dirname(__DIR__) . '/templates/components/undo_toast.php'; ?>
            <?php if (is_array($flash)): ?>
                <?php include dirname(__DIR__) . '/templates/flash.php'; ?>
            <?php endif; ?>
            <?php if (is_array($completionHintModal)): ?>
                <div class="modal fade" id="modal_completion_hint_stage" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2 class="modal-title fs-5">Contratos completos detectados</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <?php
                                $hintStage = (string) ($completionHintModal['servicio'] ?? 'LUZ');
                                $hintStepMeta = $serviceStepMetaByCode[$hintStage] ?? omServiceStepUi($hintStage, $periodoFacturacionActual);
                                $hintStageTitle = (string) ($hintStepMeta['title'] ?? $hintStage);
                                $hintAnchor = (string) ($hintStepMeta['anchor'] ?? omServiceAnchor($hintStage));
                                $hintStepNum = (int) ($hintStepMeta['index'] ?? 3);
                                $hintStepLabel = 'Paso ' . $hintStepNum . '. ' . (string) ($hintStepMeta['title'] ?? $hintStage);
                                $hintStageModalId = 'modal_generar_programar_' . strtolower($hintStage);
                                $hintStageModalSelector = '#' . $hintStageModalId;
                                ?>
                                <p class="mb-2">
                                    Ya puedes generar cobros por etapa <strong><?php echo msp2Escape($hintStageTitle); ?></strong> para contratos/tiendas completos.
                                </p>
                                <p class="mb-0 small text-muted">
                                    Tiendas completas: <strong><?php echo (int) ($completionHintModal['tiendas'] ?? 0); ?></strong>
                                    | Arrendatarios: <strong><?php echo (int) ($completionHintModal['arrendatarios'] ?? 0); ?></strong>
                                </p>
                                <p class="mt-2 mb-0 small text-muted">
                                    Si ya registraste ajustes manuales en el Paso 2, quedarán considerados al generar esta etapa.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Más tarde</button>
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="<?php echo msp2Escape($hintStageModalSelector); ?>">
                                    Configurar generación + lote (<?php echo msp2Escape($hintStepLabel); ?>)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (is_array($stageLotePromptModal)): ?>
                <div class="modal fade" id="modal_stage_lote_after_generation" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2 class="modal-title fs-5">Etapa generada: programa lote parcial</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <?php
                                $stagePrompt = (string) ($stageLotePromptModal['servicio'] ?? 'LUZ');
                                $stagePromptMeta = $serviceStepMetaByCode[$stagePrompt] ?? omServiceStepUi($stagePrompt, $periodoFacturacionActual);
                                $stagePromptTitle = (string) ($stagePromptMeta['title'] ?? $stagePrompt);
                                $stagePromptNextLabel = (string) ($stageLotePromptModal['next_focus_label'] ?? 'siguiente paso');
                                $stagePromptNextUrl = (string) ($stageLotePromptModal['next_url'] ?? '#');
                                ?>
                                <p class="mb-2">
                                    La etapa <strong><?php echo msp2Escape($stagePromptTitle); ?></strong> ya dejó documentos completos listos.
                                </p>
                                <p class="mb-0 small text-muted">
                                    Documentos: <strong><?php echo (int) ($stageLotePromptModal['documentos'] ?? 0); ?></strong>
                                    | Arrendatarios: <strong><?php echo (int) ($stageLotePromptModal['arrendatarios'] ?? 0); ?></strong>
                                </p>
                                <p class="mt-2 mb-0 small text-muted">
                                    Puedes programar el lote parcial ahora y luego continuar con <?php echo msp2Escape($stagePromptNextLabel); ?>.
                                </p>
                            </div>
                            <div class="modal-footer">
                                <a href="<?php echo msp2Escape($stagePromptNextUrl); ?>" class="btn btn-outline-secondary">Continuar a <?php echo msp2Escape($stagePromptNextLabel); ?></a>
                                <form method="post" class="m-0">
                                    <input type="hidden" name="accion" value="programar_lote_completitud">
                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                    <input type="hidden" name="etapa_completitud" value="<?php echo msp2Escape($stagePrompt); ?>">
                                    <input type="hidden" name="lote_programado_para" value="<?php echo msp2Escape($defaultLoteProgramadoAt); ?>">
                                    <input type="hidden" name="lote_client_utc_offset_min" value="">
                                    <input type="hidden" name="focus_after" value="<?php echo msp2Escape((string) ($stageLotePromptModal['next_focus'] ?? 'paso-6')); ?>">
                                    <div class="row g-2 align-items-end mb-2">
                                        <div class="col-12 col-md-3">
                                            <label class="form-label small mb-1">Batch size</label>
                                            <input type="number" name="lote_batch_size" class="form-control form-control-sm" min="1" max="100" value="10" required>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label small mb-1">Destino</label>
                                            <select name="lote_modo_destino" class="form-select form-select-sm">
                                                <option value="real" <?php echo $envioArrendatariosHabilitado ? '' : 'disabled'; ?>>Real</option>
                                                <option value="demo" <?php echo $envioArrendatariosHabilitado ? '' : 'selected'; ?>>Demo</option>
                                            </select>
                                            <?php if (!$envioArrendatariosHabilitado): ?>
                                                <div class="form-text">El envío real está bloqueado desde Configuración Correos.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label small mb-1">Correo demo (si aplica)</label>
                                            <input type="email" name="lote_demo_destino" class="form-control form-control-sm" value="<?php echo msp2Escape($demoDestinoDefault); ?>" placeholder="demo@correo.cl">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-success">Programar lote parcial ahora</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-12">
            <?php if ($loadError !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <?php echo msp2Escape($loadError); ?>
                </div>
            <?php else: ?>
            <section class="omw-step-pane <?php echo $activeStep === 1 ? 'is-active' : ''; ?>" data-step-pane="1" id="paso-1">
            <h2 class="h5 mb-3">Paso 1. Periodo</h2>
            <?php if ($hasCreatedPeriods): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end" id="form_periodo_selector">
                        <div class="col-12 col-md-8">
                            <?php
                            $periodoSelectOptions = [];
                            $monthNamesEs = [
                                '01' => 'Enero',
                                '02' => 'Febrero',
                                '03' => 'Marzo',
                                '04' => 'Abril',
                                '05' => 'Mayo',
                                '06' => 'Junio',
                                '07' => 'Julio',
                                '08' => 'Agosto',
                                '09' => 'Septiembre',
                                '10' => 'Octubre',
                                '11' => 'Noviembre',
                                '12' => 'Diciembre',
                            ];
                            foreach ($periodos as $p) {
                                $pYm = substr((string) ($p['periodo_facturacion'] ?? ''), 0, 7);
                                if ($pYm === '') {
                                    continue;
                                }
                                $pYear = substr($pYm, 0, 4);
                                $pMonth = substr($pYm, 5, 2);
                                $pLabel = isset($monthNamesEs[$pMonth]) && preg_match('/^\d{4}$/', $pYear) === 1
                                    ? ($monthNamesEs[$pMonth] . '-' . $pYear)
                                    : $pYm;
                                $periodoSelectOptions[] = [
                                    'value' => $pYm,
                                    'label' => $pLabel,
                                    'search' => mb_strtolower($pYm . ' ' . $pLabel, 'UTF-8'),
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12',
                                'label' => 'Periodos creados',
                                'input_name' => 'periodo',
                                'input_id' => 'periodo_selector_value',
                                'picker_id' => 'periodo_picker',
                                'button_id' => 'periodo_dropdown_btn',
                                'filter_id' => 'periodo_dropdown_filter',
                                'list_id' => 'periodo_dropdown_list',
                                'error_id' => 'periodo_picker_error',
                                'error_message' => 'Debes seleccionar un período.',
                                'button_placeholder' => 'Seleccionar...',
                                'filter_placeholder' => 'Buscar periodo',
                                'empty_message' => 'No hay periodos creados.',
                                'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start',
                                'required' => true,
                                'value' => $periodoActualYm,
                                'options' => $periodoSelectOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12 col-md-4 d-grid">
                            <button type="button" class="btn btn-outline-primary" id="btn_crear_periodo">Crear nuevo periodo</button>
                        </div>
                    </form>
                    <p class="small text-muted mb-0 mt-2">Selecciona un periodo creado para editarlo o pulsa crear nuevo para registrar otro mes.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-info mb-4" role="alert">
                No hay periodos creados. Crea el primer periodo para iniciar la operacion mensual.
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-4 <?php echo $mostrarFormularioPeriodo ? '' : 'd-none'; ?>" id="card_periodo_form">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h2 class="h5 mb-0" id="periodo_form_title"><?php echo msp2Escape($periodoFormTitle); ?></h2>
                        <span class="badge text-bg-primary" id="periodo_form_mode_badge"><?php echo msp2Escape($periodoFormModeLabel); ?></span>
                    </div>
                    <p class="small text-muted mb-3" id="periodo_input_help"><?php echo msp2Escape($periodoInputHelp); ?></p>
                    <?php if ($selectedCierre === null && $prefillCierre !== null): ?>
                        <div class="alert alert-info py-2 mb-3">
                            Se precargó UF desde el último período cerrado disponible.
                        </div>
                    <?php endif; ?>
                    <form method="post" class="row g-2 align-items-end" id="form_periodo_uf">
                        <input type="hidden" name="accion" value="guardar_cierre">
                        <input type="hidden" name="modo_cierre" id="modo_cierre" value="<?php echo msp2Escape($periodoFormMode); ?>">
                        <input type="hidden" name="id_cierre_mensual" id="id_cierre_mensual" value="<?php echo msp2Escape((string) ($selectedCierre['id_cierre_mensual'] ?? '')); ?>">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Periodo</label>
                            <input type="month" class="form-control" name="periodo" id="periodo_input" value="<?php echo msp2Escape($periodoNuevoDefaultYm); ?>" <?php echo $periodoFormMode === 'edit' ? 'readonly' : ''; ?> required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Fecha valor UF</label>
                            <input type="date" class="form-control" name="fecha_valor_uf" id="fecha_valor_uf_input" value="<?php echo msp2Escape($fechaValorUfDefault); ?>" required>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Valor UF</label>
                            <input type="text" class="form-control" name="valor_uf" id="valor_uf_input" value="<?php echo msp2Escape(omFormatInputDecimal($selectedCierre['valor_uf'] ?? $prefillCierre['valor_uf'] ?? '', 2)); ?>" required>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Estado</label>
                            <input type="hidden" name="estado_cierre" value="<?php echo (int) $selectedEstadoCierreId; ?>">
                            <input type="text" class="form-control" value="<?php echo msp2Escape($selectedEstadoCierreLabel); ?>" readonly>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label">Observaciones</label>
                            <input type="text" class="form-control" name="observaciones" maxlength="1000" value="<?php echo msp2Escape((string) ($selectedCierre['observaciones'] ?? '')); ?>">
                        </div>
                        <div class="col-12 d-grid d-md-flex justify-content-md-end">
                            <button type="submit" class="btn btn-success" id="periodo_form_submit_btn"><?php echo msp2Escape($periodoFormSubmitLabel); ?></button>
                        </div>
                    </form>
                    <?php if ($selectedCierre !== null): ?>
                        <div class="border-top pt-3 mt-3">
                            <div class="small text-muted mb-2">
                                Flujo protegido: Borrador → Calculado → Revisado → Cerrado. Desde Calculado o Revisado puedes volver a Borrador indicando el motivo.
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <?php if ($canRevisarPeriodo): ?>
                                    <form method="post" class="d-flex" data-confirm-message="¿Confirmas que revisaste los cálculos y documentos del período?" data-confirm-title="Marcar período revisado" data-confirm-variant="primary">
                                        <input type="hidden" name="accion" value="revisar_periodo">
                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Marcar como Revisado</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canCerrarPeriodo): ?>
                                    <form method="post" class="d-flex">
                                        <input type="hidden" name="accion" value="cerrar_periodo">
                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                        <button type="submit" class="btn btn-outline-success">Cerrar período</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canReabrirPeriodo): ?>
                                    <form method="post" class="d-flex flex-wrap gap-2 align-items-center"<?php if ($isPeriodoAnulado): ?> data-confirm-message="El período anulado volverá a Borrador y podrá recalcularse. Esta acción quedará registrada. ¿Deseas continuar?" data-confirm-title="Restaurar período anulado" data-confirm-variant="warning"<?php endif; ?>>
                                        <input type="hidden" name="accion" value="reabrir_periodo">
                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                        <input
                                            type="text"
                                            name="motivo_reapertura"
                                            class="form-control form-control-sm"
                                            style="max-width:320px;"
                                            maxlength="300"
                                            placeholder="Motivo para volver a Borrador (obligatorio)"
                                            required>
                                        <button type="submit" class="btn btn-outline-warning btn-sm"><?php echo $isPeriodoAnulado ? 'Restaurar a Borrador' : 'Reabrir a Borrador'; ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </section>

            <?php foreach ($serviceDisplayOrder as $displayCode): ?>
                <?php
                $servicio = $serviciosByCode[$displayCode] ?? null;
                if ($servicio === null) {
                    continue;
                }
                $code = strtoupper((string) ($servicio['codigo_servicio'] ?? ''));
                $prefill = $prefillByCode[$code] ?? null;
                $hasProceso = ((int) ($servicio['id_proceso_cobro'] ?? 0)) > 0;
                $source = $hasProceso ? $servicio : [];
                $serviceMeasurementWindow = omServiceMeasurementWindow($code, (string) $periodoActual);
                $serviceMeasurementPeriodYm = is_array($serviceMeasurementWindow) ? (string) ($serviceMeasurementWindow['periodo_ym'] ?? '') : '';
                $serviceMeasurementPeriodUi = omFmtPeriodoYm($serviceMeasurementPeriodYm);
                $serviceMeasurementMin = is_array($serviceMeasurementWindow) ? (string) ($serviceMeasurementWindow['min'] ?? '') : '';
                $serviceMeasurementMax = is_array($serviceMeasurementWindow) ? (string) ($serviceMeasurementWindow['max'] ?? '') : '';
                $serviceMeasurementDefault = is_array($serviceMeasurementWindow) ? (string) ($serviceMeasurementWindow['default'] ?? '') : '';
                $serviceMeasurementRangeUi = ($serviceMeasurementMin !== '' && $serviceMeasurementMax !== '')
                    ? (omFmtFecha($serviceMeasurementMin) . ' al ' . omFmtFecha($serviceMeasurementMax))
                    : '';
                $vNumero = (string) ($source['numero_factura_origen'] ?? '');
                $vFemiRaw = substr((string) ($source['fecha_emision_origen'] ?? ''), 0, 10);
                if ($code === 'LUZ' || $code === 'GAS') {
                    $fallbackMedicion = $serviceMeasurementDefault !== '' ? $serviceMeasurementDefault : $defaultFecha;
                    if (
                        preg_match('/^\d{4}-\d{2}-\d{2}$/', $vFemiRaw) === 1
                        && $serviceMeasurementMin !== ''
                        && $serviceMeasurementMax !== ''
                        && $vFemiRaw >= $serviceMeasurementMin
                        && $vFemiRaw <= $serviceMeasurementMax
                    ) {
                        $vFemi = $vFemiRaw;
                    } else {
                        $vFemi = $fallbackMedicion;
                    }
                } else {
                    $vFemi = preg_match('/^\d{4}-\d{2}-\d{2}$/', $vFemiRaw) === 1 ? $vFemiRaw : $defaultFecha;
                }
                $vFven = substr((string) ($source['fecha_vencimiento_origen'] ?? ''), 0, 10);
                $vFechaMedicion = preg_match('/^\d{4}-\d{2}-\d{2}$/', $vFemi) === 1
                    ? $vFemi
                    : ($serviceMeasurementDefault !== '' ? $serviceMeasurementDefault : $defaultFecha);
                $vObs = (string) ($source['observaciones'] ?? '');

                $vKwh = omFormatInputDecimal($source['valor_kwh'] ?? '', 2);
                $vFactor = omFormatInputDecimal($source['factor'] ?? '', 2);
                $vLitro = omFormatInputDecimal($source['valor_litro'] ?? '', 2);
                $vLga = omFormatInputDecimal($source['lectura_general_anterior'] ?? '', 0, true);
                $vLgt = omFormatInputDecimal($source['lectura_general_actual'] ?? '', 0, true);
                $vSap = omFormatInputDecimal($source['servicio_agua_potable'] ?? '', 2);
                $vSal = omFormatInputDecimal($source['servicio_alcantarillado'] ?? '', 2);
                $vTas = omFormatInputDecimal($source['tratamiento_aguas_servidas'] ?? '', 2);
                $vSob = omFormatInputDecimal($source['sobreconsumo'] ?? '', 2);
                $vIpf = omFormatInputDecimal($source['interes_pf_plazo'] ?? '', 2);
                $vCf = omFormatInputDecimal($source['cargo_fijo'] ?? '', 2);

                if ($code === 'AGUA' && is_array($prefill)) {
                    $prevActual = omFormatInputDecimal($prefill['lectura_general_actual'] ?? ($prefill['lectura_general_anterior'] ?? ''), 0, true);
                    if ($prevActual !== '' && (!$hasProceso || $vLgt === '')) {
                        $vLga = $prevActual;
                    }
                    if (!$hasProceso) {
                        $vLgt = '';
                    }
                }

                $aguaPeriodoConsumoYm = '';
                $aguaPeriodoConsumoUi = '';
                $aguaFechaHastaConsumo = '';
                if ($code === 'AGUA') {
                    $aguaConsumoInfo = omAguaPeriodoConsumo((string) $periodoActual);
                    if (is_array($aguaConsumoInfo)) {
                        $aguaPeriodoConsumoYm = (string) ($aguaConsumoInfo['periodo_ym'] ?? '');
                        $aguaPeriodoConsumoUi = omFmtPeriodoYm($aguaPeriodoConsumoYm);
                        $aguaFechaHastaConsumo = (string) ($aguaConsumoInfo['fecha_hasta'] ?? '');
                    }
                }

                $pendingPreview = $pendingPreviewByCode[$code] ?? null;
                $previewLecturasRows = $previewLecturasByCode[$code] ?? [];
                $hasLecturasRegistradas = ((int) ($servicio['cantidad_lecturas'] ?? 0)) > 0;
                $serviceStepMeta = $serviceStepMetaByCode[$code] ?? omServiceStepUi($code, (string) $periodoActual);
                $stepIndex = (int) ($serviceStepMeta['index'] ?? 3);
                $stepId = (string) ($serviceStepMeta['anchor'] ?? omServiceAnchor($code));
                $serviceStepTitle = (string) ($serviceStepMeta['title'] ?? $code);
                $completionStageSummary = $completionSummaryByStage[$code] ?? ['arrendatarios' => 0, 'documentos' => 0, 'tiene_candidatos' => false];
                $consumoReporteExcelUrl = null;
                $consumoReportePdfUrl = null;
                $periodoConsumoReporteYm = trim((string) ($serviceStepMeta['periodo_ym'] ?? ''));
                if (
                    $hasProceso
                    && $hasLecturasRegistradas
                    && $periodoConsumoReporteYm !== ''
                    && ($code === 'LUZ' || $code === 'GAS')
                ) {
                    if ($code === 'LUZ') {
                        $consumoReporteExcelUrl = msp2Url(
                            'cobros/reporte_consumo_electrico.php?servicio=LUZ&periodo=' . urlencode($periodoConsumoReporteYm) . '&format=xlsx'
                        );
                        $consumoReportePdfUrl = msp2Url(
                            'cobros/reporte_consumo_electrico.php?servicio=LUZ&periodo=' . urlencode($periodoConsumoReporteYm) . '&format=pdf'
                        );
                    } else {
                        $consumoReporteExcelUrl = msp2Url(
                            'cobros/reporte_consumo_gas.php?servicio=GAS&periodo=' . urlencode($periodoConsumoReporteYm) . '&format=xlsx'
                        );
                        $consumoReportePdfUrl = msp2Url(
                            'cobros/reporte_consumo_gas.php?servicio=GAS&periodo=' . urlencode($periodoConsumoReporteYm) . '&format=pdf'
                        );
                    }
                }
                ?>
                <section class="omw-step-pane <?php echo $activeStep === $stepIndex ? 'is-active' : ''; ?>" data-step-pane="<?php echo $stepIndex; ?>" id="<?php echo msp2Escape($stepId); ?>">
                    <h2 class="h5 mb-3">Paso <?php echo $stepIndex; ?>. <?php echo msp2Escape($serviceStepTitle); ?></h2>
                    <?php if ($selectedCierre === null): ?>
                        <div class="alert alert-warning mb-0">Primero guarda el periodo para habilitar los servicios.</div>
                    <?php else: ?>
                        <div class="card border-0 shadow-sm" data-service-card="<?php echo msp2Escape($code); ?>">
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <h2 class="h5 mb-0"><?php echo msp2Escape((string) ($servicio['nombre_servicio'] ?? $code)); ?></h2>
                                    <div class="small text-muted d-flex flex-wrap align-items-center gap-2">
                                        <span class="omw-process-pill <?php echo $hasProceso ? 'is-ready' : ''; ?>" data-service-process-pill>
                                            <span class="omw-process-pill-dot" aria-hidden="true"></span>
                                            Proceso: <strong data-service-process-state><?php echo $hasProceso ? 'Creado' : 'Nuevo'; ?></strong>
                                        </span>
                                        <span>Lecturas: <strong><?php echo (int) ($servicio['cantidad_lecturas'] ?? 0); ?></strong></span>
                                        <?php if ($code === 'AGUA' && $aguaPeriodoConsumoYm !== ''): ?>
                                            <span>Consumo cobrado: <strong><?php echo msp2Escape($aguaPeriodoConsumoUi); ?></strong></span>
                                        <?php endif; ?>
                                        <?php if (!$hasProceso && $prefill !== null && $code === 'AGUA'): ?>
                                            <span>Prefill: <strong>Lectura anterior desde periodo previo</strong></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="alert d-none py-2 mb-3" data-service-feedback role="alert"></div>

                                <form method="post" class="row g-2" data-async-service-form="1" data-service-code="<?php echo msp2Escape($code); ?>">
                                    <input type="hidden" name="accion" value="guardar_servicio">
                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                    <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">

                                    <?php if ($code === 'LUZ' || $code === 'GAS'): ?>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Fecha medicion actual</label>
                                            <input
                                                type="date"
                                                class="form-control"
                                                name="fecha_medicion_actual"
                                                value="<?php echo msp2Escape($vFechaMedicion); ?>"
                                                min="<?php echo msp2Escape($serviceMeasurementMin); ?>"
                                                max="<?php echo msp2Escape($serviceMeasurementMax); ?>"
                                                required>
                                            <?php if ($serviceMeasurementRangeUi !== ''): ?>
                                                <div class="form-text omw-date-range-hint" title="Rango permitido: <?php echo msp2Escape($serviceMeasurementRangeUi); ?>"><?php echo msp2Escape($serviceMeasurementRangeUi); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">N factura (origen)</label>
                                            <input type="text" class="form-control" name="numero_factura_origen" maxlength="50" value="<?php echo msp2Escape($vNumero); ?>">
                                        </div>
                                        <div class="col-12 <?php echo $code === 'AGUA' ? 'col-md-8' : 'col-md-4'; ?>">
                                            <label class="form-label">Fecha emision</label>
                                            <input type="date" class="form-control" name="fecha_emision_origen" value="<?php echo msp2Escape($vFemi); ?>">
                                        </div>
                                        <?php if ($code !== 'AGUA'): ?>
                                            <div class="col-12 col-md-4">
                                                <label class="form-label">Fecha vencimiento</label>
                                                <input type="date" class="form-control" name="fecha_vencimiento_origen" value="<?php echo msp2Escape($vFven); ?>">
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($code === 'LUZ'): ?>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-6',
                                            'id' => 'valor_kwh_luz',
                                            'name' => 'valor_kwh',
                                            'label' => 'Valor kWh',
                                            'value' => $vKwh,
                                            'hint' => '',
                                        ]); ?>
                                    <?php endif; ?>

                                    <?php if ($code === 'GAS'): ?>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Factor</label>
                                            <input type="text" class="form-control" name="factor" value="<?php echo msp2Escape($vFactor); ?>" required>
                                        </div>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-6',
                                            'id' => 'valor_litro_gas',
                                            'name' => 'valor_litro',
                                            'label' => 'Valor litro',
                                            'value' => $vLitro,
                                            'hint' => '',
                                        ]); ?>
                                    <?php endif; ?>

                                    <?php if ($code === 'AGUA'): ?>
                                        <?php
                                        $vLgaNum = trim($vLga);
                                        $vLgtNum = trim($vLgt);
                                        $vConsumoAuto = '';
                                        if (is_numeric($vLgaNum) && is_numeric($vLgtNum)) {
                                            $consumoTmp = (int) $vLgtNum - (int) $vLgaNum;
                                            if ($consumoTmp > 0) {
                                                $vConsumoAuto = (string) $consumoTmp;
                                            }
                                        }
                                        ?>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Lectura general anterior</label>
                                            <input type="text" class="form-control" name="lectura_general_anterior" value="<?php echo msp2Escape($vLga); ?>" data-agua-anterior required>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Lectura general actual</label>
                                            <input type="text" class="form-control" name="lectura_general_actual" value="<?php echo msp2Escape($vLgt); ?>" data-agua-actual required>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Consumo (divisor automatico)</label>
                                            <input type="text" class="form-control" value="<?php echo msp2Escape($vConsumoAuto); ?>" data-agua-consumo readonly>
                                        </div>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-4',
                                            'id' => 'servicio_agua_potable_agua',
                                            'name' => 'servicio_agua_potable',
                                            'label' => 'Servicio agua potable',
                                            'value' => $vSap,
                                            'hint' => '',
                                        ]); ?>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-4',
                                            'id' => 'servicio_alcantarillado_agua',
                                            'name' => 'servicio_alcantarillado',
                                            'label' => 'Servicio alcantarillado',
                                            'value' => $vSal,
                                            'hint' => '',
                                        ]); ?>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-4',
                                            'id' => 'tratamiento_aguas_servidas_agua',
                                            'name' => 'tratamiento_aguas_servidas',
                                            'label' => 'Tratamiento aguas servidas',
                                            'value' => $vTas,
                                            'hint' => '',
                                        ]); ?>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">Sobreconsumo</label>
                                            <input type="text" class="form-control" name="sobreconsumo" value="<?php echo msp2Escape($vSob); ?>">
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">Interes P.F. plazo</label>
                                            <input type="text" class="form-control" name="interes_pf_plazo" value="<?php echo msp2Escape($vIpf); ?>">
                                        </div>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-md-3',
                                            'id' => 'cargo_fijo_agua',
                                            'name' => 'cargo_fijo',
                                            'label' => 'Cargo fijo',
                                            'value' => $vCf,
                                            'hint' => '',
                                        ]); ?>
                                        <div class="col-12">
                                            <div class="small text-muted">
                                                Fórmula AGUA aplicada por medidor:
                                                <strong>monto = ((consumo_local * (SAP + SAL + TAS)) / divisor) + CargoFijo</strong>.
                                                El <code>divisor</code> se calcula automáticamente como <code>lectura_general_actual - lectura_general_anterior</code>.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-12">
                                        <label class="form-label">Observaciones proceso</label>
                                        <input type="text" class="form-control" name="observaciones_proceso" maxlength="1000" value="<?php echo msp2Escape($vObs); ?>">
                                    </div>

                                    <div class="col-12 d-flex justify-content-end align-items-center flex-wrap gap-2">
                                        <button type="submit" class="btn btn-outline-primary">Guardar parametros <?php echo msp2Escape($code); ?></button>
                                    </div>
                                </form>

                                <div class="border rounded p-3 bg-light mt-3 omw-readings-panel <?php echo $hasProceso ? 'is-ready' : 'is-locked'; ?>" data-process-panel aria-disabled="<?php echo $hasProceso ? 'false' : 'true'; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2" data-process-panel-head>
                                        <h3 class="h6 mb-0">Cargar lecturas <?php echo msp2Escape($code); ?></h3>
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="badge <?php echo $hasProceso ? 'text-bg-success' : 'text-bg-secondary'; ?>" data-process-panel-badge>
                                                <?php echo $hasProceso ? 'Habilitado' : 'Bloqueado'; ?>
                                            </span>
                                            <div class="d-flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                class="btn btn-success"
                                                data-open-excel="<?php echo msp2Escape(msp2Url('cobros/plantilla_lecturas.php?servicio=' . urlencode($code) . '&periodo=' . urlencode($periodoActualYm))); ?>"
                                                data-enable-on-process
                                                <?php echo $hasProceso ? '' : 'disabled'; ?>>
                                                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Generar Excel
                                            </button>
                                            <?php if ($code !== 'LUZ'): ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="accion" value="preparar_lecturas_directas">
                                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                    <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">
                                                    <button type="submit" class="btn btn-outline-primary" data-enable-on-process <?php echo $hasProceso ? '' : 'disabled'; ?>>
                                                        Carga directa
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="omw-readings-lock" aria-hidden="true">
                                        <div class="omw-readings-lock-card">
                                            <span class="omw-readings-lock-icon">
                                                <i class="bi bi-lock-fill"></i>
                                            </span>
                                            <span class="omw-readings-lock-label">Proceso bloqueado</span>
                                            <p class="omw-readings-lock-help">Guarda parámetros para habilitar carga y confirmación de lecturas.</p>
                                        </div>
                                    </div>
                                    <?php if ($code === 'LUZ'): ?>
                                        <div class="small text-muted mb-2">
                                            Flujo recomendado:
                                            <ol class="mb-0 ps-3">
                                                <li>Guardar parametros de LUZ.</li>
                                                <li>Generar la plantilla Excel.</li>
                                                <li>Cargar el archivo y confirmar la importacion.</li>
                                                <li>Si hace falta, realizar ajuste fino en la tabla de lecturas directas.</li>
                                            </ol>
                                        </div>
                                    <?php else: ?>
                                        <p class="small text-muted mb-2">
                                            Puedes trabajar con Excel o preparar una carga directa para editar <code>lectura_actual</code> desde la tabla inferior.
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($code === 'LUZ'): ?>
                                        <p class="small text-muted mb-2">
                                            Excel esperado: <code>cod_local</code>, <code>codigo_medidor</code>, <code>lectura_anterior</code>, <code>lectura_actual</code>.
                                            Luego puedes corregir <code>lectura_actual</code> en la tabla directa antes de guardar.
                                        </p>
                                    <?php elseif ($code === 'AGUA'): ?>
                                        <p class="small text-muted mb-2">
                                            Columnas esperadas: <code>cod_local</code>, <code>codigo_medidor</code>, <code>lectura_anterior</code>, <code>lectura_actual</code>.
                                            La plantilla puede incluir fecha en el encabezado, por ejemplo: <code>lectura_anterior (22-11)</code> y <code>lectura_actual (22-12)</code>.
                                            Se procesan todas las filas con datos que agregues bajo el encabezado. Cada local debe tener un medidor de agua activo en Gestión de locales; si tiene uno solo, el sistema lo reconocerá también por el código del local.
                                        </p>
                                    <?php else: ?>
                                        <p class="small text-muted mb-2">
                                            Columnas esperadas: <code>cod_local</code>, <code>codigo_medidor</code> o <code>alias_medidor</code>, <code>lectura_actual</code>.
                                            Opcional: <code>lectura_anterior</code>, <code>observaciones</code>. Las fechas de lectura se fijan automáticamente por período.
                                        </p>
                                    <?php endif; ?>
                                    <div class="alert alert-warning py-2 mb-2 <?php echo $hasProceso ? 'd-none' : ''; ?>" data-process-warning>Primero guarda los parámetros del servicio para crear el proceso.</div>
                                    <form method="post" enctype="multipart/form-data" class="row g-2">
                                        <input type="hidden" name="accion" value="importar_lecturas">
                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                        <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">
                                        <div class="col-12 col-md-8">
                                            <label class="form-label">Archivo Excel</label>
                                            <input type="file" class="form-control" name="archivo_lecturas" accept=".xlsx,.xls,.csv" data-enable-on-process <?php echo $hasProceso ? 'required' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-12 col-md-4 d-flex align-items-end">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="reemplazar_<?php echo msp2Escape($code); ?>" name="reemplazar_lecturas" value="1" data-enable-on-process <?php echo $hasProceso ? '' : 'disabled'; ?>>
                                                <label class="form-check-label" for="reemplazar_<?php echo msp2Escape($code); ?>">Reemplazar lecturas existentes</label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-grid d-md-flex justify-content-md-end gap-2">
                                            <button type="submit" class="btn btn-success" name="confirmar_auto" value="1" data-enable-on-process <?php echo $hasProceso ? '' : 'disabled'; ?>>
                                                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Cargar Excel y aplicar lecturas
                                            </button>
                                        </div>
                                    </form>

                                    <?php if ($pendingPreview !== null): ?>
                                        <div class="alert alert-info py-2 mt-2 mb-2">
                                            Preview pendiente: <strong><?php echo msp2Escape($pendingPreview['original_name'] !== '' ? $pendingPreview['original_name'] : 'archivo'); ?></strong>
                                            (<?php echo (int) $pendingPreview['rows_total']; ?> filas).
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 mb-2">
                                            <form method="post" data-auto-confirm-form="<?php echo msp2Escape($code); ?>">
                                                <input type="hidden" name="accion" value="confirmar_importacion">
                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">
                                                <button type="submit" class="btn btn-success">Confirmar importación</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="accion" value="descartar_preview_importacion">
                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">
                                                <button type="submit" class="btn btn-outline-danger">Descartar preview</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <div class="row g-3 mt-1">
                                        <?php if ($pendingPreview !== null): ?>
                                            <div class="col-12">
                                                <div class="border rounded p-3 bg-light">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h4 class="h6 mb-0">Preview pendiente de confirmar</h4>
                                                        <small class="text-muted"><?php echo (int) ($pendingPreview['rows_total'] ?? 0); ?> filas (máx 200)</small>
                                                    </div>
                                                    <div class="table-responsive" style="max-height: 240px; overflow: auto;">
                                                        <table class="table table-sm table-striped align-middle mb-0">
                                                            <thead class="table-light">
                                                            <tr>
                                                                <th>Fila</th>
                                                                <th>Local</th>
                                                                <th>Medidor</th>
                                                                <th>Anterior</th>
                                                                <th>Actual</th>
                                                            </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php $pendingRows = $pendingPreview['rows_preview'] ?? []; ?>
                                                            <?php if (!is_array($pendingRows) || $pendingRows === []): ?>
                                                                <tr><td colspan="5" class="text-muted">No hay filas en la previsualización pendiente.</td></tr>
                                                            <?php else: ?>
                                                                <?php foreach ($pendingRows as $rowPreview): ?>
                                                                    <tr>
                                                                        <td><?php echo (int) ($rowPreview['fila_origen'] ?? 0); ?></td>
                                                                        <td><?php echo msp2Escape((string) ($rowPreview['cod_local'] ?? '')); ?></td>
                                                                        <td><?php echo msp2Escape((string) ($rowPreview['codigo_medidor'] ?? '')); ?></td>
                                                                        <td><?php echo msp2Escape(omFmtNum($rowPreview['lectura_anterior'] ?? null, 0)); ?></td>
                                                                        <td><?php echo msp2Escape(omFmtNum($rowPreview['lectura_actual'] ?? null, 0)); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-12">
                                            <div class="border rounded p-3 bg-light">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <h4 class="h6 mb-0">Lecturas registradas</h4>
                                                    <small class="text-muted"><?php echo count($previewLecturasRows); ?> filas</small>
                                                </div>
                                                <?php if ($previewLecturasRows === []): ?>
                                                    <div class="text-muted">Sin lecturas registradas para el proceso.</div>
                                                <?php else: ?>
                                                    <form method="post" data-direct-reading-form="<?php echo msp2Escape($code); ?>">
                                                        <input type="hidden" name="accion" value="actualizar_lecturas_directas">
                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                        <input type="hidden" name="codigo_servicio" value="<?php echo msp2Escape($code); ?>">
                                                        <div class="table-responsive" style="max-height: 320px; overflow: auto;">
                                                            <table class="table table-sm table-striped align-middle mb-0">
                                                                <thead class="table-light">
                                                                <tr>
                                                                    <th>Local</th>
                                                                    <th>Medidor</th>
                                                                    <th>Anterior</th>
                                                                    <th style="min-width: 140px;">Actual</th>
                                                                    <th><?php echo $code === 'AGUA' ? 'Consumo (actual - anterior)' : 'F. lectura'; ?></th>
                                                                </tr>
                                                                </thead>
                                                                <tbody>
                                                                <?php foreach ($previewLecturasRows as $rowLectura): ?>
                                                                    <?php
                                                                    $idLecturaRow = (int) ($rowLectura['id_lectura'] ?? 0);
                                                                    $actualLectura = $rowLectura['lectura_actual'] ?? null;
                                                                    $anteriorLectura = $rowLectura['lectura_anterior'] ?? null;
                                                                    $consumoLectura = null;
                                                                    if (is_numeric((string) $actualLectura) && is_numeric((string) $anteriorLectura)) {
                                                                        $consumoLectura = (float) $actualLectura - (float) $anteriorLectura;
                                                                    }
                                                                    ?>
                                                                    <tr>
                                                                        <td><?php echo msp2Escape((string) ($rowLectura['cod_local'] ?? '')); ?></td>
                                                                        <td><?php echo msp2Escape((string) ($rowLectura['codigo_medidor'] ?? '')); ?></td>
                                                                        <td><?php echo msp2Escape(omFmtNum($anteriorLectura, 0)); ?></td>
                                                                        <td>
                                                                            <input
                                                                                type="text"
                                                                                class="form-control form-control-sm text-end"
                                                                                name="lecturas_actuales[<?php echo $idLecturaRow; ?>]"
                                                                                value="<?php echo msp2Escape(omFormatReadingInput($actualLectura)); ?>"
                                                                                data-reading-actual
                                                                                data-reading-anterior="<?php echo msp2Escape((string) ($anteriorLectura ?? '')); ?>">
                                                                        </td>
                                                                        <td>
                                                                            <?php if ($code === 'AGUA'): ?>
                                                                                <span data-reading-consumo>
                                                                                    <?php echo $consumoLectura !== null ? msp2Escape(omFmtNum($consumoLectura, 0)) : '-'; ?>
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <?php echo msp2Escape(omFmtFecha((string) ($rowLectura['fecha_lectura'] ?? ''))); ?>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                        <div class="d-flex justify-content-end mt-2">
                                                            <button type="submit" class="btn btn-primary btn-sm">Guardar lecturas directas</button>
                                                        </div>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($code === 'LUZ' || $code === 'GAS'): ?>
                                            <div class="col-12">
                                                <div class="border rounded p-3 bg-white">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <div>
                                                            <h4 class="h6 mb-1">Reportes de consumo</h4>
                                                            <small class="text-muted">Se habilitan cuando las lecturas de <?php echo msp2Escape($code); ?> ya están guardadas.</small>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-2">
                                                            <a
                                                                href="<?php echo msp2Escape($consumoReporteExcelUrl ?? '#'); ?>"
                                                                class="btn btn-success <?php echo ($consumoReporteExcelUrl !== null && $consumoReportePdfUrl !== null) ? '' : 'disabled'; ?>"
                                                                <?php if ($consumoReporteExcelUrl !== null && $consumoReportePdfUrl !== null): ?>
                                                                    target="_blank" rel="noopener"
                                                                <?php else: ?>
                                                                    aria-disabled="true" tabindex="-1"
                                                                <?php endif; ?>>
                                                                <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Excel consumo
                                                            </a>
                                                            <a
                                                                href="<?php echo msp2Escape($consumoReportePdfUrl ?? '#'); ?>"
                                                                class="btn btn-outline-dark <?php echo ($consumoReporteExcelUrl !== null && $consumoReportePdfUrl !== null) ? '' : 'disabled'; ?>"
                                                                <?php if ($consumoReporteExcelUrl !== null && $consumoReportePdfUrl !== null): ?>
                                                                    target="_blank" rel="noopener"
                                                                <?php else: ?>
                                                                    aria-disabled="true" tabindex="-1"
                                                                <?php endif; ?>>
                                                                PDF consumo
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="col-12">
                                            <?php
                                            $requiredLecturasStageCodes = match ($code) {
                                                'LUZ' => ['LUZ'],
                                                'GAS' => ['LUZ', 'GAS'],
                                                'AGUA' => ['LUZ', 'AGUA'],
                                                default => [$code],
                                            };
                                            $hasPagosPeriodoActivos = ((int) ($summary['pagos_manual'] ?? ($summary['pagos'] ?? 0))) > 0;
                                            $missingLecturasStageCodes = [];
                                            foreach ($requiredLecturasStageCodes as $requiredStageCode) {
                                                $requiredStageRow = $serviciosByCode[$requiredStageCode] ?? null;
                                                $requiredHasProceso = is_array($requiredStageRow)
                                                    && ((int) ($requiredStageRow['id_proceso_cobro'] ?? 0) > 0);
                                                $requiredHasLecturas = is_array($requiredStageRow)
                                                    && ((int) ($requiredStageRow['cantidad_lecturas'] ?? 0) > 0);
                                                if (!$requiredHasProceso || !$requiredHasLecturas) {
                                                    $missingLecturasStageCodes[] = $requiredStageCode;
                                                }
                                            }
                                            $stageBlockingRow = $stageBlockingLotes[$code] ?? null;
                                            $stageLockedByLote = is_array($stageBlockingRow);
                                            $canGenerateStageNow = $missingLecturasStageCodes === [] && !$hasPagosPeriodoActivos && !$stageLockedByLote;
                                            $stageHasCandidates = (bool) ($completionStageSummary['tiene_candidatos'] ?? false);
                                            $stageReadyDocs = (int) ($completionStageSummary['documentos'] ?? 0);
                                            $stageReadyArrendatarios = (int) ($completionStageSummary['arrendatarios'] ?? 0);
                                            if ($code === 'LUZ' && (bool) ($sinServicioStats['disponible'] ?? false)) {
                                                $sinServicioDocsUi = (int) ($sinServicioStats['tiendas_documentadas'] ?? 0);
                                                if ($sinServicioDocsUi > 0) {
                                                    $stageReadyDocs += $sinServicioDocsUi;
                                                    $stageReadyArrendatarios += $sinServicioDocsUi;
                                                    $stageHasCandidates = true;
                                                }
                                            }
                                            $stageGeneratedPersisted = $stageReadyDocs > 0 && $stageReadyArrendatarios > 0;
                                            $stageReadyClass = $stageHasCandidates ? 'border-success-subtle bg-success-subtle' : 'border-secondary-subtle bg-light';
                                            $stageModalId = 'modal_generar_programar_' . strtolower($code);
                                            $stageNextFocus = omNextFocusAfterStage($code);
                                            $stageBatchAuto = max(1, min(100, (int) ($completionStageSummary['arrendatarios'] ?? 0)));
                                            $canGenerateAndScheduleNow = $canGenerateStageNow && $lotesProgramadosDisponibles;
                                            $stageBadgeClass = 'text-bg-secondary';
                                            $stageBadgeText = 'Pendiente';
                                            if ($stageLockedByLote) {
                                                $stageBadgeClass = 'text-bg-dark';
                                                $stageBadgeText = 'Bloqueada por lote';
                                            } elseif ($hasPagosPeriodoActivos) {
                                                $stageBadgeClass = 'text-bg-warning';
                                                $stageBadgeText = 'Bloqueada por pagos';
                                            } elseif ($stageHasCandidates) {
                                                $stageBadgeClass = 'text-bg-success';
                                                $stageBadgeText = 'Generada, pendiente de programar';
                                            } elseif ($canGenerateStageNow) {
                                                $stageBadgeClass = 'text-bg-primary';
                                                $stageBadgeText = 'Lista para generar';
                                            }
                                            ?>
                                            <div class="border rounded p-3 <?php echo $stageReadyClass; ?> mb-0">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                                    <strong>Flujo recomendado etapa <?php echo msp2Escape($code); ?></strong>
                                                    <span class="badge <?php echo msp2Escape($stageBadgeClass); ?>">
                                                        <?php echo msp2Escape($stageBadgeText); ?>
                                                    </span>
                                                </div>
                                                <?php if (is_array($stageGenerationInline) && (string) ($stageGenerationInline['servicio'] ?? '') === $code): ?>
                                                    <div class="alert alert-success py-2 mb-3">
                                                        <div class="small">
                                                            <strong>Etapa ejecutada correctamente.</strong>
                                                            Cobros recalculados: <?php echo (int) ($stageGenerationInline['cobros_generados'] ?? 0); ?>
                                                            | Documentos generados: <?php echo (int) ($stageGenerationInline['documentos_generados'] ?? 0); ?>
                                                            | Items recompuestos: <?php echo (int) ($stageGenerationInline['items_recompuestos'] ?? 0); ?>
                                                            | Arrendatarios listos: <?php echo (int) ($stageGenerationInline['arrendatarios'] ?? 0); ?>.
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <!-- <div class="small text-muted mb-2">
                                                    Generar etapa recalcula la base documental del período en modo acumulado; la completitud se aplica al programar lotes por etapa.
                                                </div> -->
                                                <div class="row g-2">
                                                    <div class="col-12 col-md-6">
                                                        <form method="post" class="m-0">
                                                            <input type="hidden" name="accion" value="generar_etapa_completitud">
                                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                            <input type="hidden" name="etapa_completitud" value="<?php echo msp2Escape($code); ?>">
                                                            <input type="hidden" name="dias_vencimiento" value="<?php echo msp2Escape((string) $diasVencimientoDefault); ?>">
                                                            <input type="hidden" name="abrir_prompt_lote" value="0">
                                                            <button type="submit" class="btn btn-outline-primary btn-lg w-100" <?php echo $canGenerateStageNow ? '' : 'disabled'; ?>>
                                                                <?php echo $code === 'LUZ' ? 'Solo generar (LUZ + SIN_SERVICIO)' : 'Solo generar etapa'; ?>
                                                            </button>
                                                        </form>
                                                    </div>
                                                    <div class="col-12 col-md-6">
                                                        <?php if ($lotesProgramadosDisponibles): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-primary btn-lg w-100"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#<?php echo msp2Escape($stageModalId); ?>"
                                                                <?php echo $canGenerateAndScheduleNow ? '' : 'disabled'; ?>>
                                                                <?php echo $code === 'LUZ' ? 'Generar y programar (LUZ + SIN_SERVICIO)' : 'Generar y programar lote'; ?>
                                                            </button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-outline-secondary btn-lg w-100" disabled>
                                                                Lotes no disponibles
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($stageGeneratedPersisted && !$stageLockedByLote): ?>
                                                    <div class="small text-success mt-2">
                                                        Generación persistida en período: <strong><?php echo $stageReadyDocs; ?></strong> documentos y <strong><?php echo $stageReadyArrendatarios; ?></strong> arrendatarios listos para programar lote.
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($lotesProgramadosDisponibles): ?>
                                                    <div class="modal fade" id="<?php echo msp2Escape($stageModalId); ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h4 class="modal-title fs-6 mb-0">
                                                                        <?php echo msp2Escape($code === 'LUZ' ? 'LUZ + SIN_SERVICIO: generar y programar' : ('Etapa ' . $code . ': generar y programar lote')); ?>
                                                                    </h4>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                                                </div>
                                                                <form method="post">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="accion" value="generar_y_programar_etapa_completitud">
                                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                                        <input type="hidden" name="etapa_completitud" value="<?php echo msp2Escape($code); ?>">
                                                                        <input type="hidden" name="dias_vencimiento" value="<?php echo msp2Escape((string) $diasVencimientoDefault); ?>">
                                                                        <input type="hidden" name="focus_after" value="<?php echo msp2Escape($stageNextFocus); ?>">
                                                                        <input type="hidden" name="lote_batch_size_auto" value="<?php echo (int) $stageBatchAuto; ?>">
                                                                        <input type="hidden" name="lote_client_utc_offset_min" value="">
                                                                        <div class="mb-2 small text-muted">
                                                                            Batch size automático (oculto): <strong><?php echo (int) $stageBatchAuto; ?></strong> (según arrendatarios listos de la etapa, tope 100).
                                                                        </div>
                                                                        <div class="row g-2">
                                                                            <div class="col-12">
                                                                                <label class="form-label">Programar para</label>
                                                                                <input type="datetime-local" name="lote_programado_para" class="form-control" value="<?php echo msp2Escape($defaultLoteProgramadoAt); ?>" required>
                                                                            </div>
                                                                            <div class="col-12 col-md-4">
                                                                                <label class="form-label">Destino</label>
                                                                                <select name="lote_modo_destino" class="form-select">
                                                                                    <option value="real" <?php echo $envioArrendatariosHabilitado ? '' : 'disabled'; ?>>Real</option>
                                                                                    <option value="demo" <?php echo $envioArrendatariosHabilitado ? '' : 'selected'; ?>>Demo</option>
                                                                                </select>
                                                                                <?php if (!$envioArrendatariosHabilitado): ?>
                                                                                    <div class="form-text">El envío real está bloqueado desde Configuración Correos.</div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <div class="col-12 col-md-8">
                                                                                <label class="form-label">Correo demo (si aplica)</label>
                                                                                <input type="email" name="lote_demo_destino" class="form-control" value="<?php echo msp2Escape($demoDestinoDefault); ?>" placeholder="demo@correo.cl">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                        <button type="submit" class="btn btn-primary" <?php echo $canGenerateAndScheduleNow ? '' : 'disabled'; ?>>
                                                                            Confirmar generación + programación
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <!-- <div class="small mt-2">
                                                    Documentos listos: <strong><?php echo (int) ($completionStageSummary['documentos'] ?? 0); ?></strong>
                                                    | Arrendatarios: <strong><?php echo (int) ($completionStageSummary['arrendatarios'] ?? 0); ?></strong>
                                                </div> -->
                                                <?php if (!$canGenerateStageNow): ?>
                                                    <div class="small text-warning mt-1">
                                                        <?php if ($stageLockedByLote): ?>
                                                            Etapa bloqueada por lote #<?php echo (int) ($stageBlockingRow['id_lote_envio'] ?? 0); ?>
                                                            (<?php echo msp2Escape((string) ($stageBlockingRow['estado_label'] ?? 'Desconocido')); ?>).
                                                            Cancela ese lote en Paso 6 para volver a generar.
                                                        <?php elseif ($hasPagosPeriodoActivos): ?>
                                                            Hay pagos registrados en este período. Para continuar por etapas, revierte/borrar pagos en la zona de corrección.
                                                        <?php else: ?>
                                                            Faltan lecturas cargadas para: <strong><?php echo msp2Escape(implode(', ', $missingLecturasStageCodes)); ?></strong>.
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="omw-step-pane <?php echo $activeStep === 2 ? 'is-active' : ''; ?>" data-step-pane="2" id="paso-5">
                <h2 class="h5 mb-3">Paso 2. Ajuste manual</h2>
                <?php
                $manualTabIsSaldoFavor = $manualAdjustTab === 'saldo_favor';
                $manualTabCargoHref = '?' . http_build_query([
                    'periodo' => $periodoActualYm,
                    'step' => 2,
                    'focus' => 'paso-5',
                    'manual_tab' => 'cargo_extra',
                ]) . '#paso-5';
                $manualTabSaldoHref = '?' . http_build_query([
                    'periodo' => $periodoActualYm,
                    'step' => 2,
                    'focus' => 'paso-5',
                    'manual_tab' => 'saldo_favor',
                ]) . '#paso-5';
                ?>
                <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Ajuste manual">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $manualTabIsSaldoFavor ? '' : 'active'; ?>" href="<?php echo msp2Escape($manualTabCargoHref); ?>" role="tab" aria-selected="<?php echo $manualTabIsSaldoFavor ? 'false' : 'true'; ?>">Cargo extra</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link <?php echo $manualTabIsSaldoFavor ? 'active' : ''; ?>" href="<?php echo msp2Escape($manualTabSaldoHref); ?>" role="tab" aria-selected="<?php echo $manualTabIsSaldoFavor ? 'true' : 'false'; ?>">Saldo a favor</a>
                    </li>
                </ul>

                <div class="<?php echo $manualTabIsSaldoFavor ? 'd-none' : ''; ?>" id="ajuste-pane-cargo-extra">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <?php if ($extraCharges['disponible']): ?>
                            <form method="post" class="border rounded p-3 bg-light mb-3" id="form_cargo_extra_rapido">
                                <input type="hidden" name="accion" value="crear_cargo_extra">
                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                <h3 class="h6 mb-2">Registro cargo extra</h3>
                                <div class="row g-2">
                                    <?php
                                    $targetSelectOptions = [];
                                    foreach ($extraChargeTargets as $target) {
                                        $idContratoTarget = (int) ($target['id_contrato_arriendo'] ?? 0);
                                        $idLocalTarget = (int) ($target['id_local'] ?? 0);
                                        if ($idContratoTarget <= 0 || $idLocalTarget <= 0) {
                                            continue;
                                        }
                                        $targetValue = $idContratoTarget . ':' . $idLocalTarget;
                                        $localCodeLabel = (string) ($target['cdo_local'] ?? '-');
                                        $arrendatarioLabel = trim((string) ($target['nombre_arrendatario'] ?? ''));
                                        if ($arrendatarioLabel === '') {
                                            $arrendatarioLabel = (string) ($target['nombre_comercial'] ?? ('Arrendatario #' . $idContratoTarget));
                                        }
                                        $targetLabel = '(' . $localCodeLabel . ') ' . $arrendatarioLabel;
                                        $targetSelectOptions[] = [
                                            'value' => $targetValue,
                                            'label' => $targetLabel,
                                            'search' => mb_strtolower(
                                                $targetLabel
                                                . ' '
                                                . (string) ($target['nombre_comercial'] ?? '')
                                                . ' contrato #' . $idContratoTarget,
                                                'UTF-8'
                                            ),
                                        ];
                                    }
                                    msp2RenderSearchableSelectField([
                                        'wrapper_class' => 'col-12 col-lg-4',
                                        'label' => 'Local / Arrendatario',
                                        'input_name' => 'target_contrato_local',
                                        'input_id' => 'omw_target_contrato_local',
                                        'picker_id' => 'omw_target_picker',
                                        'button_id' => 'omw_target_dropdown_btn',
                                        'filter_id' => 'omw_target_dropdown_filter',
                                        'list_id' => 'omw_target_dropdown_list',
                                        'error_id' => 'omw_target_picker_error',
                                        'error_message' => 'Debes seleccionar un local/tienda.',
                                        'button_placeholder' => 'Selecciona local / arrendatario...',
                                        'filter_placeholder' => 'Buscar por local, arrendatario, tienda o contrato',
                                        'empty_message' => 'No hay locales/contratos vigentes para este período.',
                                        'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start omw-picker-btn',
                                        'required' => true,
                                        'options' => $targetSelectOptions,
                                    ]);
                                    ?>
                                    <?php
                                    $tipoSelectOptions = [];
                                    foreach ($extraChargeTypes as $tipoCargo) {
                                        $tipoCargoId = (int) ($tipoCargo['id_tipo_cargo_salida'] ?? 0);
                                        if ($tipoCargoId <= 0) {
                                            continue;
                                        }
                                        $tipoLabel = (string) ($tipoCargo['nombre_tipo_cargo'] ?? 'Tipo #' . $tipoCargoId);
                                        $tipoSelectOptions[] = [
                                            'value' => (string) $tipoCargoId,
                                            'label' => $tipoLabel,
                                            'search' => mb_strtolower($tipoLabel, 'UTF-8'),
                                        ];
                                    }
                                    msp2RenderSearchableSelectField([
                                        'wrapper_class' => 'col-12 col-lg-3',
                                        'label' => 'Tipo cargo',
                                        'input_name' => 'id_tipo_cargo_salida',
                                        'input_id' => 'omw_id_tipo_cargo_salida',
                                        'picker_id' => 'omw_tipo_picker',
                                        'button_id' => 'omw_tipo_dropdown_btn',
                                        'filter_id' => 'omw_tipo_dropdown_filter',
                                        'list_id' => 'omw_tipo_dropdown_list',
                                        'error_id' => 'omw_tipo_picker_error',
                                        'error_message' => 'Debes seleccionar un tipo de cargo.',
                                        'button_placeholder' => 'Selecciona tipo de cargo...',
                                        'filter_placeholder' => 'Buscar tipo de cargo',
                                        'empty_message' => 'No hay tipos de cargo activos.',
                                        'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start omw-picker-btn',
                                        'required' => true,
                                        'options' => $tipoSelectOptions,
                                    ]);
                                    ?>
                                    <div class="col-12 col-lg-2">
                                        <label class="form-label">Fecha cargo</label>
                                        <input
                                            type="date"
                                            class="form-control"
                                            name="fecha_cargo"
                                            value="<?php echo msp2Escape($manualAdjustDateDefault); ?>"
                                            min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                            max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                            required>
                                        <div class="form-text omw-date-range-hint" title="Rango permitido: <?php echo msp2Escape($manualAdjustDateRangeUi); ?>"><?php echo msp2Escape($manualAdjustDateRangeUi); ?></div>
                                    </div>
                                    <?php msp2RenderMontoClpField([
                                        'wrapper_class' => 'col-12 col-lg-3',
                                        'id' => 'monto_cargo_paso5',
                                        'name' => 'monto_cargo',
                                        'label' => 'Monto',
                                        'hint' => '',
                                    ]); ?>
                                    <div class="col-12">
                                        <label class="form-label">Descripción</label>
                                        <input type="text" class="form-control" name="descripcion_cargo" maxlength="500">
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap justify-content-end align-items-center mt-2 gap-2">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Agregar cargo extra</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if (!$extraCharges['disponible']): ?>
                            <div class="alert alert-info mb-0">
                                No está disponible la tabla de cargos extra (`msp_cargos_salida`) en este ambiente.
                            </div>
                        <?php elseif ((int) $extraCharges['pendientes_count'] === 0): ?>
                            <div class="alert alert-success mb-0">
                                No hay cargos extra pendientes para incorporar. Puedes continuar al resumen.
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                                <div>
                                    <strong>Pendientes por aplicar</strong>
                                    <div class="small text-muted">Se incorporan una sola vez al documento y luego quedan marcados como aplicados.</div>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted">Cantidad</div>
                                    <strong><?php echo (int) $extraCharges['pendientes_count']; ?></strong>
                                    <div class="small text-muted mt-1">Total: <strong>$ <?php echo omFmtNum($extraCharges['pendientes_total'], 2); ?></strong></div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Tipo</th>
                                        <th>Local</th>
                                        <th>Arrendatario</th>
                                        <th>Descripción</th>
                                        <th class="text-end">Monto</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($extraCharges['pendientes'] as $extraRow): ?>
                                        <?php $rowIdCargo = (int) ($extraRow['id_cargo_salida'] ?? 0); ?>
                                        <tr>
                                            <td><?php echo msp2Escape(omFmtFecha((string) ($extraRow['fecha_cargo'] ?? ''))); ?></td>
                                            <td><?php echo msp2Escape((string) ($extraRow['nombre_tipo_cargo'] ?? '-')); ?></td>
                                            <td><?php echo msp2Escape((string) ($extraRow['cdo_local'] ?? '-')); ?></td>
                                            <td><?php echo msp2Escape((string) ($extraRow['nombre_arrendatario'] ?? ($extraRow['nombre_comercial'] ?? '-'))); ?></td>
                                            <td><?php echo msp2Escape((string) ($extraRow['descripcion_cargo'] ?? '-')); ?></td>
                                            <td class="text-end">$ <?php echo omFmtNum($extraRow['monto_cargo'] ?? 0, 2); ?></td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-1">
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-warning btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modal_editar_cargo_extra"
                                                        data-edit-id="<?php echo $rowIdCargo; ?>"
                                                        data-edit-fecha="<?php echo msp2Escape(substr((string) ($extraRow['fecha_cargo'] ?? ''), 0, 10)); ?>"
                                                        data-edit-tipo-id="<?php echo (int) ($extraRow['id_tipo_cargo_salida'] ?? 0); ?>"
                                                        data-edit-descripcion="<?php echo msp2Escape((string) ($extraRow['descripcion_cargo'] ?? '')); ?>"
                                                        data-edit-observaciones="<?php echo msp2Escape((string) ($extraRow['observaciones'] ?? '')); ?>"
                                                        data-edit-monto="<?php echo msp2Escape((string) ($extraRow['monto_cargo'] ?? '')); ?>">
                                                        <i class="bi bi-pencil-square me-1"></i>Editar
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-danger btn-sm"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modal_cancelar_cargo_extra"
                                                        data-cancel-id="<?php echo $rowIdCargo; ?>"
                                                        data-cancel-descripcion="<?php echo msp2Escape((string) ($extraRow['descripcion_cargo'] ?? '-')); ?>">
                                                        <i class="bi bi-x-circle me-1"></i>Eliminar
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="modal fade" id="modal_editar_cargo_extra" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <form method="post">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Editar cargo extra</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="accion" value="actualizar_cargo_extra">
                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                <input type="hidden" name="id_cargo_salida" id="omw_edit_id_cargo_salida" value="">

                                                <div class="row g-2">
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label">Fecha cargo</label>
                                                        <input
                                                            type="date"
                                                            class="form-control"
                                                            name="fecha_cargo"
                                                            id="omw_edit_fecha_cargo"
                                                            min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                            max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                            required>
                                                    </div>
                                                    <div class="col-12 col-md-8">
                                                        <label class="form-label">Tipo cargo</label>
                                                        <select class="form-select" name="id_tipo_cargo_salida" id="omw_edit_id_tipo_cargo_salida" required>
                                                            <option value="">Selecciona...</option>
                                                            <?php foreach ($extraChargeTypes as $tipoCargo): ?>
                                                                <?php $tipoCargoId = (int) ($tipoCargo['id_tipo_cargo_salida'] ?? 0); ?>
                                                                <?php if ($tipoCargoId <= 0) { continue; } ?>
                                                                <option value="<?php echo $tipoCargoId; ?>"><?php echo msp2Escape((string) ($tipoCargo['nombre_tipo_cargo'] ?? ('Tipo #' . $tipoCargoId))); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Descripción</label>
                                                        <input type="text" class="form-control" name="descripcion_cargo" id="omw_edit_descripcion_cargo" maxlength="500">
                                                    </div>
                                                    <div class="col-12 col-md-4">
                                                        <label class="form-label">Monto</label>
                                                        <input type="text" class="form-control" name="monto_cargo" id="omw_edit_monto_cargo" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Observaciones</label>
                                                        <textarea class="form-control" name="observaciones_cargo" id="omw_edit_observaciones_cargo" rows="3" maxlength="500"></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                                                <button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade" id="modal_cancelar_cargo_extra" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="post">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Eliminar cargo pendiente</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="accion" value="cancelar_cargo_extra">
                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                <input type="hidden" name="id_cargo_salida" id="omw_cancel_id_cargo_salida" value="">
                                                <p class="mb-2">Se cancelará el cargo:</p>
                                                <p class="small text-muted mb-3" id="omw_cancel_descripcion_cargo">-</p>
                                                <label class="form-label" for="omw_cancel_reason">Motivo (opcional)</label>
                                                <textarea class="form-control" id="omw_cancel_reason" name="confirm_reason" rows="3" maxlength="500" placeholder="Puedes indicar por qué se cancela este cargo"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
                                                <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Eliminar</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($extraCharges['disponible']): ?>
                            <hr>
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <div>
                                    <strong>Ya asignados en este período</strong>
                                    <div class="small text-muted">Estos cargos ya fueron vinculados a documentos del período actual.</div>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted">Cantidad</div>
                                    <strong><?php echo (int) $extraCharges['aplicados_count']; ?></strong>
                                    <div class="small text-muted mt-1">Total: <strong>$ <?php echo omFmtNum($extraCharges['aplicados_total'], 2); ?></strong></div>
                                </div>
                            </div>
                            <?php if ((int) $extraCharges['aplicados_count'] === 0): ?>
                                <div class="small text-muted">Todavía no hay cargos asignados en este período.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Fecha</th>
                                            <th>Tipo</th>
                                            <th>Local</th>
                                            <th>Tienda</th>
                                            <th>Documento</th>
                                            <th>Descripción</th>
                                            <th class="text-end">Monto</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($extraCharges['aplicados'] as $extraRow): ?>
                                            <tr>
                                                <td><?php echo (int) ($extraRow['id_cargo_salida'] ?? 0); ?></td>
                                                <td><?php echo msp2Escape(omFmtFecha((string) ($extraRow['fecha_cargo'] ?? ''))); ?></td>
                                                <td><?php echo msp2Escape((string) ($extraRow['nombre_tipo_cargo'] ?? '-')); ?></td>
                                                <td><?php echo msp2Escape((string) ($extraRow['cdo_local'] ?? '-')); ?></td>
                                                <td><?php echo msp2Escape((string) ($extraRow['nombre_comercial'] ?? '-')); ?></td>
                                                <td><?php echo msp2Escape((string) ($extraRow['numero_documento'] ?? '#'.((int) ($extraRow['id_documento_cobro'] ?? 0)))); ?></td>
                                                <td><?php echo msp2Escape((string) ($extraRow['descripcion_cargo'] ?? '-')); ?></td>
                                                <td class="text-end">$ <?php echo omFmtNum($extraRow['monto_cargo'] ?? 0, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                </div>

                <?php if (false): ?>
                <div class="d-none" id="paso-3">
                    <div class="row g-3">
                        <div class="col-12">
                            <?php
                            $confirmSelectedDefault = 0;
                            foreach ($serviceCodes as $codeCount) {
                                $srvCount = $serviciosByCode[$codeCount] ?? null;
                                $srvCountReady = $srvCount !== null && (int) ($srvCount['id_proceso_cobro'] ?? 0) > 0;
                                if ($srvCountReady) {
                                    $confirmSelectedDefault++;
                                }
                            }
                            ?>
                            <form method="post" class="omw-confirm-shell">
                                <input type="hidden" name="accion" value="generar_cobros">
                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                <input type="hidden" name="servicios_presentes" value="1">
                                <input type="hidden" name="auto_docs" value="1">
                                <input type="hidden" name="perfil_servicios_docs" value="ALL">
                                <div class="omw-confirm-shell-head">
                                    <h2 class="h5 mb-1">Generación de cobros y documentos</h2>
                                    <div class="small text-muted">Haz clic en la tarjeta completa para incluir o excluir servicios en esta generación.</div>
                                </div>
                                <div class="omw-confirm-layout">
                                <div class="omw-confirm-main">

                                    <div class="omw-service-grid">
                                        <?php foreach ($serviceCodes as $code): ?>
                                            <?php
                                            $srv = $serviciosByCode[$code] ?? null;
                                            $srvReady = $srv !== null && (int) ($srv['id_proceso_cobro'] ?? 0) > 0;
                                            $srvDisabled = !$srvReady;
                                            $hasLecturas = $srv !== null && (int) ($srv['cantidad_lecturas'] ?? 0) > 0;
                                            $lecturasCount = (int) ($srv['cantidad_lecturas'] ?? 0);
                                            $serviceIcon = match ($code) {
                                                'LUZ' => 'bi-lightning-charge',
                                                'GAS' => 'bi-fire',
                                                'AGUA' => 'bi-droplet',
                                                default => 'bi-grid',
                                            };
                                            $serviceLabel = (string) ($serviceNameByCode[$code] ?? $code);
                                            $stateReady = $srvReady && $hasLecturas;
                                            $stateText = $stateReady
                                                ? ($lecturasCount . ' lecturas cargadas · Parámetros OK')
                                                : ($srvReady ? 'Parámetros listos · Faltan lecturas' : 'Pendiente de parámetros');
                                            ?>
                                            <label class="omw-select-card" for="serv_all_<?php echo msp2Escape(strtolower($code)); ?>">
                                                <input
                                                    class="omw-select-card-input"
                                                    type="checkbox"
                                                    id="serv_all_<?php echo msp2Escape(strtolower($code)); ?>"
                                                    name="servicios[]"
                                                    value="<?php echo msp2Escape($code); ?>"
                                                    <?php echo $srvReady ? 'checked' : ''; ?>
                                                    <?php echo $srvDisabled ? 'disabled' : ''; ?>>
                                                <span class="omw-select-card-ui">
                                                    <span class="omw-select-card-left">
                                                        <span class="omw-select-check"><i class="bi bi-check-lg"></i></span>
                                                        <span class="omw-select-service-icon"><i class="bi <?php echo msp2Escape($serviceIcon); ?>"></i></span>
                                                        <span class="omw-select-service-info">
                                                            <span class="omw-select-service-title"><?php echo msp2Escape($serviceLabel); ?></span>
                                                            <span class="omw-select-service-sub"><?php echo msp2Escape($stateText); ?></span>
                                                        </span>
                                                    </span>
                                                    <span class="omw-select-state <?php echo $stateReady ? 'is-ready' : 'is-pending'; ?>">
                                                        <i class="bi <?php echo $stateReady ? 'bi-check-circle' : 'bi-exclamation-triangle'; ?>"></i>
                                                        <?php echo $stateReady ? 'Listo para generar' : 'Revisión pendiente'; ?>
                                                    </span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="omw-confirm-options">
                                        <div class="row g-2">
                                            <div class="col-12 col-md-6">
                                                <label class="form-label">Días hábiles vencimiento</label>
                                                <input type="number" class="form-control" name="dias_vencimiento" min="0" max="120" value="<?php echo msp2Escape((string) $diasVencimientoDefault); ?>">
                                            </div>
                                            <div class="col-12 col-md-6 d-flex align-items-end">
                                                <div class="small text-muted">
                                                    Opciones de reemplazo y cargos extra disponibles en <strong>Opciones avanzadas</strong>.
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-primary omw-confirm-cta" id="omw-confirm-submit">
                                            <i class="bi bi-lightning-charge-fill"></i>
                                            <span class="omw-confirm-cta-label">
                                                <span class="omw-confirm-cta-line-main">Generar</span>
                                                <span class="omw-confirm-cta-line-sub">y continuar</span>
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <aside class="omw-confirm-side">
                                    <h3 class="h6 omw-confirm-side-title">Resumen de generación</h3>

                                    <div class="omw-confirm-kpi">
                                        <div>
                                            <p class="label">Servicios seleccionados</p>
                                            <p class="value" id="omw-confirm-selected-count"><?php echo (int) $confirmSelectedDefault; ?></p>
                                        </div>
                                        <i class="bi bi-check2-square text-primary"></i>
                                    </div>

                                    <div class="omw-confirm-checklist">
                                        <div class="omw-confirm-check-item <?php echo $status['uf_ok'] ? 'is-ok' : 'is-pending'; ?>">
                                            <div class="omw-confirm-check-left">
                                                <span class="omw-confirm-check-icon"><i class="bi <?php echo $status['uf_ok'] ? 'bi-check-lg' : 'bi-exclamation'; ?>"></i></span>
                                                <span class="omw-confirm-check-label">Valor UF</span>
                                            </div>
                                            <span class="omw-confirm-check-value"><?php echo $status['uf_ok'] ? 'OK' : 'Pendiente'; ?></span>
                                        </div>
                                        <div class="omw-confirm-check-item <?php echo $status['procesos_ok'] ? 'is-ok' : 'is-pending'; ?>">
                                            <div class="omw-confirm-check-left">
                                                <span class="omw-confirm-check-icon"><i class="bi <?php echo $status['procesos_ok'] ? 'bi-check-lg' : 'bi-exclamation'; ?>"></i></span>
                                                <span class="omw-confirm-check-label">Parámetros</span>
                                            </div>
                                            <span class="omw-confirm-check-value"><?php echo msp2Escape($status['proc_label'] ?? '0 / 3'); ?></span>
                                        </div>
                                        <div class="omw-confirm-check-item <?php echo $status['lecturas_ok'] ? 'is-ok' : 'is-pending'; ?>">
                                            <div class="omw-confirm-check-left">
                                                <span class="omw-confirm-check-icon"><i class="bi <?php echo $status['lecturas_ok'] ? 'bi-check-lg' : 'bi-exclamation'; ?>"></i></span>
                                                <span class="omw-confirm-check-label">Lecturas</span>
                                            </div>
                                            <span class="omw-confirm-check-value"><?php echo msp2Escape($status['lect_label'] ?? '0 / 3'); ?></span>
                                        </div>
                                        <div class="omw-confirm-check-item <?php echo $status['cargos_extra_ok'] ? 'is-ok' : 'is-pending'; ?>">
                                            <div class="omw-confirm-check-left">
                                                <span class="omw-confirm-check-icon"><i class="bi <?php echo $status['cargos_extra_ok'] ? 'bi-check-lg' : 'bi-exclamation'; ?>"></i></span>
                                                <span class="omw-confirm-check-label">Cargos extra</span>
                                            </div>
                                            <span class="omw-confirm-check-value"><?php echo msp2Escape((string) ($status['cargos_extra_label'] ?? '0')); ?></span>
                                        </div>
                                    </div>

                                    <p class="omw-confirm-results-title">Resultados de generación</p>
                                    <div class="small text-muted mb-2">Se actualizan luego de ejecutar <strong>Generar y continuar</strong>.</div>
                                    <div class="omw-confirm-results-grid">
                                        <div class="omw-confirm-result-item">
                                            <p class="omw-confirm-result-label">Cobros</p>
                                            <p class="omw-confirm-result-value"><?php echo (int) ($summary['cobros'] ?? 0); ?></p>
                                        </div>
                                        <div class="omw-confirm-result-item">
                                            <p class="omw-confirm-result-label">Documentos</p>
                                            <p class="omw-confirm-result-value"><?php echo (int) ($summary['documentos'] ?? 0); ?></p>
                                        </div>
                                        <div class="omw-confirm-result-item">
                                            <p class="omw-confirm-result-label">Deuda total</p>
                                            <p class="omw-confirm-result-value">$ <?php echo omFmtNum($summary['total_saldo'] ?? 0, 2); ?></p>
                                        </div>
                                    </div>
                                </aside>
                                </div>
                            </form>
                        </div>
                        <div class="col-12">
                            <div class="accordion" id="accordion_avanzado">
                                <div class="accordion-item">
                                    <h2 class="accordion-header" id="heading_avanzado">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapse_avanzado" aria-expanded="false" aria-controls="collapse_avanzado">
                                            Opciones avanzadas
                                        </button>
                                    </h2>
                                    <div id="collapse_avanzado" class="accordion-collapse collapse" data-bs-parent="#accordion_avanzado">
                                        <div class="accordion-body">
                                            <div class="row g-3">
                                                <?php if ($selectedCierre !== null && !$isPeriodoGenerableForMutation): ?>
                                                    <div class="col-12">
                                                        <div class="alert alert-warning mb-0">
                                                            El período está en estado <strong><?php echo msp2Escape($selectedEstadoCierreLabel); ?></strong>.
                                                            Solo se puede generar en <strong>Borrador</strong> o <strong>Calculado</strong>. Para corrección debes reabrirlo a Borrador.
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                        <div class="col-12 col-lg-6">
                                            <form method="post" class="border rounded p-3 bg-light h-100" id="form_generar_cobros">
                                            <input type="hidden" name="accion" value="generar_cobros">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                            <input type="hidden" name="servicios_presentes" value="1">
                                            <h3 class="h6">Generar documento de cobro</h3>
                                            <div class="small text-muted mb-2">Puedes generar cobros parciales seleccionando solo los servicios disponibles.</div>
                                            <div class="d-flex flex-wrap gap-3 mb-2">
                                                <?php foreach ($serviceCodes as $code): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="serv_<?php echo msp2Escape(strtolower($code)); ?>" name="servicios[]" value="<?php echo msp2Escape($code); ?>" checked>
                                                        <label class="form-check-label" for="serv_<?php echo msp2Escape(strtolower($code)); ?>"><?php echo msp2Escape($code); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="rep_cobros" name="reemplazar" value="1" <?php echo ((int) ($summary['cobros'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="rep_cobros">Reemplazar cobros existentes</label>
                                            </div>
                                            <button type="submit" class="btn btn-success" <?php echo $isPeriodoGenerableForMutation ? '' : 'disabled'; ?>>Ejecutar generación de facturación</button>
                                        </form>
                                    </div>
                                    <div class="col-12 col-lg-6">
                                        <form method="post" class="border rounded p-3 bg-light h-100" id="form_generar_documentos">
                                            <input type="hidden" name="accion" value="generar_documentos">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                            <h3 class="h6">Generar documentos</h3>
                                            <div class="row g-2 mb-2">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label">Dias habiles vencimiento</label>
                                                    <input type="number" class="form-control" name="dias_vencimiento" min="0" max="120" value="<?php echo msp2Escape((string) $diasVencimientoDefault); ?>">
                                                </div>
                                                <div class="col-12 col-md-6 d-flex align-items-end">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="rep_docs" name="reemplazar" value="1" <?php echo ((int) ($summary['documentos'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="rep_docs">Reemplazar documentos</label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Perfil de medidores (contrato/local)</label>
                                                    <select class="form-select" name="perfil_servicios_docs">
                                                        <option value="ALL">Todos los perfiles</option>
                                                        <option value="LUZ_ONLY">Solo LUZ (sin GAS/AGUA)</option>
                                                        <option value="LUZ_GAS">LUZ + GAS (sin AGUA)</option>
                                                        <option value="LUZ_AGUA">LUZ + AGUA (sin GAS)</option>
                                                        <option value="LUZ_GAS_AGUA">LUZ + GAS + AGUA</option>
                                                    </select>
                                                    <div class="form-text">Aplica filtro por tienda según medidores activos asociados por contrato/local en el período.</div>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input
                                                            class="form-check-input"
                                                            type="checkbox"
                                                            id="extra_docs"
                                                            name="aplicar_cargos_extra"
                                                            value="1"
                                                            <?php echo ((int) ($extraCharges['pendientes_count'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="extra_docs">Aplicar cargos extra pendientes</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-primary" <?php echo $isPeriodoGenerableForMutation ? '' : 'disabled'; ?>>Ejecutar generacion de documentos</button>
                                        </form>
                                    </div>
                                    <div class="col-12">
                                        <form
                                            method="post"
                                            class="border border-danger-subtle rounded p-3 bg-danger-subtle"
                                            id="form_borrar_generacion"
                                            data-confirm-message="Esta acción eliminará datos del periodo seleccionado. ¿Deseas continuar?"
                                            data-confirm-title="Confirmar borrado de corrección"
                                            data-confirm-variant="danger">
                                            <input type="hidden" name="accion" value="borrar_generacion">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                            <h3 class="h6 text-danger mb-1">Zona de corrección</h3>
                                            <div class="small mb-2">
                                                Sirve para deshacer una generación equivocada en el periodo actual.
                                                Si existen cargos/movimientos asociados, el sistema puede bloquear el borrado.
                                            </div>
                                            <div class="d-flex flex-wrap gap-3 mb-2">
                                                <div class="form-check">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        id="borrar_cargos_salida_asociados"
                                                        name="borrar_cargos_salida_asociados"
                                                        value="1"
                                                        <?php echo ((int) ($summary['cargos_salida_asociados'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="borrar_cargos_salida_asociados">
                                                        Desvincular cargos de salida asociados (<?php echo (int) ($summary['cargos_salida_asociados'] ?? 0); ?>)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        id="borrar_pagos"
                                                        name="borrar_pagos"
                                                        value="1"
                                                        <?php echo ((int) ($summary['pagos'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="borrar_pagos">
                                                        Borrar pagos del periodo (<?php echo (int) ($summary['pagos'] ?? 0); ?>)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        id="borrar_documentos"
                                                        name="borrar_documentos"
                                                        value="1"
                                                        <?php echo ((int) ($summary['documentos'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="borrar_documentos">
                                                        Borrar documentos del periodo (<?php echo (int) ($summary['documentos'] ?? 0); ?>)
                                                    </label>
                                                </div>
                                                <div class="form-check">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        id="borrar_cobros"
                                                        name="borrar_cobros"
                                                        value="1"
                                                        <?php echo ((int) ($summary['cobros'] ?? 0) > 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="borrar_cobros">
                                                        Borrar cobros del cierre (<?php echo (int) ($summary['cobros'] ?? 0); ?>)
                                                    </label>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-outline-danger" <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>Ejecutar borrado de corrección</button>
                                        </form>
                                    </div>
                                </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="<?php echo $manualTabIsSaldoFavor ? '' : 'd-none'; ?>" id="ajuste-pane-saldo-favor">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <?php if (!$saldoFavorManualFlow['disponible']): ?>
                                <div class="alert alert-info mb-0">
                                    El módulo de saldo a favor manual no está disponible en este ambiente.
                                </div>
                            <?php else: ?>
                                <form method="post" class="border rounded p-3 bg-light mb-3" id="form_saldo_favor_manual_paso2">
                                    <input type="hidden" name="accion" value="crear_saldo_favor">
                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                    <input type="hidden" name="manual_tab" value="saldo_favor">
                                    <h3 class="h6 mb-2">Registro saldo a favor</h3>
                                    <div class="row g-2 align-items-start">
                                        <div class="col-12 col-lg-5">
                                            <?php
                                            $saldoFavorOptionRows = [];
                                            foreach (($saldoFavorManualFlow['tiendas'] ?? []) as $tiendaRow) {
                                                $tiendaId = (int) ($tiendaRow['id_tienda'] ?? 0);
                                                if ($tiendaId <= 0) {
                                                    continue;
                                                }
                                                $tiendaNombre = trim((string) ($tiendaRow['nombre_comercial'] ?? ''));
                                                $arrNombre = trim((string) ($tiendaRow['nombre_locatario'] ?? ''));
                                                $locales = $saldoFavorManualFlow['locales_por_tienda'][$tiendaId] ?? [];
                                                $localesLabel = $locales !== [] ? ('(' . implode(' / ', $locales) . ') ') : '(Sin locales) ';
                                                $arrLabel = $arrNombre !== '' ? $arrNombre : ('Arrendatario #' . $tiendaId);
                                                $label = $localesLabel . $arrLabel;
                                                $saldoFavorOptionRows[] = [
                                                    'value' => (string) $tiendaId,
                                                    'label' => $label,
                                                    'search' => mb_strtolower($label . ' ' . $arrNombre . ' ' . $tiendaNombre, 'UTF-8'),
                                                    'first_local' => (string) ($locales[0] ?? ''),
                                                    'nombre_tienda' => $tiendaNombre,
                                                ];
                                            }
                                            usort(
                                                $saldoFavorOptionRows,
                                                static function (array $a, array $b): int {
                                                    $firstA = trim((string) ($a['first_local'] ?? ''));
                                                    $firstB = trim((string) ($b['first_local'] ?? ''));
                                                    if ($firstA !== '' && $firstB !== '') {
                                                        $cmpLocal = msp2CompareLocalCode($firstA, $firstB);
                                                        if ($cmpLocal !== 0) {
                                                            return $cmpLocal;
                                                        }
                                                    } elseif ($firstA === '' && $firstB !== '') {
                                                        return 1;
                                                    } elseif ($firstA !== '' && $firstB === '') {
                                                        return -1;
                                                    }

                                                    $cmpTienda = strcasecmp((string) ($a['nombre_tienda'] ?? ''), (string) ($b['nombre_tienda'] ?? ''));
                                                    if ($cmpTienda !== 0) {
                                                        return $cmpTienda;
                                                    }
                                                    return strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? ''));
                                                }
                                            );
                                            $saldoFavorTiendaOptions = [];
                                            foreach ($saldoFavorOptionRows as $optionRow) {
                                                unset($optionRow['first_local'], $optionRow['nombre_tienda']);
                                                $saldoFavorTiendaOptions[] = $optionRow;
                                            }
                                            msp2RenderSearchableSelectField([
                                                'wrapper_class' => 'col-12',
                                                'label' => 'Arrendatario',
                                                'input_name' => 'id_tienda',
                                                'input_id' => 'omw_saldo_favor_id_tienda',
                                                'picker_id' => 'omw_saldo_favor_picker',
                                                'button_id' => 'omw_saldo_favor_dropdown_btn',
                                                'filter_id' => 'omw_saldo_favor_dropdown_filter',
                                                'list_id' => 'omw_saldo_favor_dropdown_list',
                                                'error_id' => 'omw_saldo_favor_picker_error',
                                                'error_message' => 'Debes seleccionar un arrendatario.',
                                                'button_placeholder' => 'Selecciona arrendatario...',
                                                'filter_placeholder' => 'Buscar arrendatario o local',
                                                'empty_message' => 'No hay arrendatarios disponibles.',
                                                'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start omw-picker-btn',
                                                'required' => true,
                                                'options' => $saldoFavorTiendaOptions,
                                            ]);
                                            ?>
                                        </div>
                                        <div class="col-12 col-lg-2">
                                            <label class="form-label">Fecha</label>
                                            <input
                                                type="date"
                                                class="form-control"
                                                name="fecha_movimiento"
                                                value="<?php echo msp2Escape($manualAdjustDateDefault); ?>"
                                                min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                required>
                                            <div class="form-text omw-date-range-hint" title="Rango permitido: <?php echo msp2Escape($manualAdjustDateRangeUi); ?>"><?php echo msp2Escape($manualAdjustDateRangeUi); ?></div>
                                        </div>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-lg-2',
                                            'id' => 'monto_saldo_favor_paso2',
                                            'name' => 'saldo_favor_monto',
                                            'label' => 'Monto',
                                            'hint' => '',
                                        ]); ?>
                                        <div class="col-12 col-lg-3">
                                            <label class="form-label">Observaciones</label>
                                            <input type="text" class="form-control" name="observaciones" maxlength="500" placeholder="Opcional">
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap justify-content-end align-items-center mt-2 gap-2">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Agregar saldo a favor</button>
                                    </div>
                                </form>

                                <hr>
                                <?php
                                $saldoPendItems = [];
                                $saldoPendItemsTotal = 0.0;
                                foreach ((array) ($saldoFavorManualFlow['manual_rows'] ?? []) as $saldoPendItemRow) {
                                    $montoPendItem = round((float) ($saldoPendItemRow['monto_pendiente'] ?? $saldoPendItemRow['monto_movimiento'] ?? 0), 2);
                                    if ($montoPendItem <= 0.005) {
                                        continue;
                                    }
                                    $saldoPendItems[] = [
                                        'id_movimiento_saldo_favor' => (int) ($saldoPendItemRow['id_movimiento_saldo_favor'] ?? 0),
                                        'id_tienda' => (int) ($saldoPendItemRow['id_tienda'] ?? 0),
                                        'fecha_movimiento' => (string) ($saldoPendItemRow['fecha_movimiento'] ?? ''),
                                        'locales' => is_array($saldoPendItemRow['locales'] ?? null) ? $saldoPendItemRow['locales'] : [],
                                        'nombre_arrendatario' => (string) ($saldoPendItemRow['nombre_arrendatario'] ?? ''),
                                        'monto_movimiento' => round((float) ($saldoPendItemRow['monto_movimiento'] ?? 0), 2),
                                        'monto_pendiente' => $montoPendItem,
                                        'observaciones' => (string) ($saldoPendItemRow['observaciones'] ?? ''),
                                    ];
                                    $saldoPendItemsTotal = round($saldoPendItemsTotal + $montoPendItem, 2);
                                }
                                $saldoPendItemsCount = count($saldoPendItems);
                                ?>
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <strong>Pendientes por aplicar</strong>
                                        <div class="small text-muted">Ingresos de saldo a favor vigentes aún no aplicados a documentos del período (no incluye ingresos anulados/revertidos).</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">Items</div>
                                        <strong><?php echo (int) $saldoPendItemsCount; ?></strong>
                                        <div class="small text-muted mt-1">Total: <strong>$ <?php echo omFmtNum((float) $saldoPendItemsTotal, 2); ?></strong></div>
                                        <div class="small text-muted mt-1">
                                            Docs sugeridos próxima generación: <strong><?php echo (int) ($saldoFavorFlow['docs_sugeridos'] ?? 0); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!(bool) ($saldoFavorManualFlow['disponible'] ?? false)): ?>
                                    <div class="small text-muted mb-3">No está disponible el módulo de saldo a favor manual en este ambiente.</div>
                                <?php elseif ($saldoPendItemsCount <= 0): ?>
                                    <div class="small text-muted mb-3">No hay saldo pendiente para aplicar en este período.</div>
                                <?php else: ?>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Locales</th>
                                                <th>Arrendatario</th>
                                                <th class="text-end">Monto pendiente</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($saldoPendItems as $saldoPendItemRow): ?>
                                                <?php
                                                $saldoPendLocales = is_array($saldoPendItemRow['locales'] ?? null) ? implode(' / ', $saldoPendItemRow['locales']) : '';
                                                $saldoPendMovId = (int) ($saldoPendItemRow['id_movimiento_saldo_favor'] ?? 0);
                                                $saldoPendTiendaId = (int) ($saldoPendItemRow['id_tienda'] ?? 0);
                                                $saldoPendMontoMov = round((float) ($saldoPendItemRow['monto_movimiento'] ?? 0), 2);
                                                $saldoPendMonto = round((float) ($saldoPendItemRow['monto_pendiente'] ?? 0), 2);
                                                $saldoPendCanManage = $saldoPendMovId > 0 && $saldoPendMontoMov > 0 && $saldoPendMonto + 0.0001 >= $saldoPendMontoMov;
                                                $saldoPendObs = (string) ($saldoPendItemRow['observaciones'] ?? '');
                                                ?>
                                                <tr>
                                                    <td><?php echo msp2Escape(omFmtFecha((string) ($saldoPendItemRow['fecha_movimiento'] ?? ''))); ?></td>
                                                    <td><?php echo $saldoPendLocales !== '' ? msp2Escape($saldoPendLocales) : '-'; ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoPendItemRow['nombre_arrendatario'] ?? '-')); ?></td>
                                                    <td class="text-end fw-semibold">$ <?php echo omFmtNum((float) ($saldoPendItemRow['monto_pendiente'] ?? 0), 2); ?></td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-1">
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-warning btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modal_editar_saldo_favor_manual"
                                                                data-edit-id="<?php echo $saldoPendMovId; ?>"
                                                                data-edit-id-tienda="<?php echo $saldoPendTiendaId; ?>"
                                                                data-edit-fecha="<?php echo msp2Escape(substr((string) ($saldoPendItemRow['fecha_movimiento'] ?? ''), 0, 10)); ?>"
                                                                data-edit-monto="<?php echo msp2Escape(number_format($saldoPendMontoMov, 2, '.', '')); ?>"
                                                                data-edit-observaciones="<?php echo msp2Escape($saldoPendObs); ?>"
                                                                <?php echo $saldoPendCanManage ? '' : 'disabled'; ?>>
                                                                <i class="bi bi-pencil-square me-1"></i>Editar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-danger btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modal_cancelar_saldo_favor_manual"
                                                                data-cancel-id="<?php echo $saldoPendMovId; ?>"
                                                                data-cancel-descripcion="<?php echo msp2Escape(($saldoPendLocales !== '' ? $saldoPendLocales . ' - ' : '') . (string) ($saldoPendItemRow['nombre_arrendatario'] ?? '-') . ' | Ingreso: $ ' . omFmtNum($saldoPendMontoMov, 2)); ?>"
                                                                <?php echo $saldoPendCanManage ? '' : 'disabled'; ?>>
                                                                <i class="bi bi-x-circle me-1"></i>Eliminar
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="modal fade" id="modal_editar_saldo_favor_manual" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Editar ingreso manual</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="accion" value="actualizar_saldo_favor_manual">
                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                        <input type="hidden" name="manual_tab" value="saldo_favor">
                                                        <input type="hidden" name="id_movimiento_saldo_favor" id="omw_edit_sf_movimiento_id" value="">

                                                        <div class="row g-2">
                                                            <div class="col-12">
                                                                <label class="form-label">Arrendatario</label>
                                                                <select class="form-select" name="id_tienda" id="omw_edit_sf_id_tienda" required>
                                                                    <option value="">Selecciona...</option>
                                                                    <?php foreach (($saldoFavorManualFlow['tiendas'] ?? []) as $tiendaEditRow): ?>
                                                                        <?php
                                                                        $tiendaEditId = (int) ($tiendaEditRow['id_tienda'] ?? 0);
                                                                        if ($tiendaEditId <= 0) {
                                                                            continue;
                                                                        }
                                                                        $tiendaEditLocales = $saldoFavorManualFlow['locales_por_tienda'][$tiendaEditId] ?? [];
                                                                        $tiendaEditLocalesLabel = $tiendaEditLocales !== [] ? ('(' . implode(' / ', $tiendaEditLocales) . ') ') : '(Sin locales) ';
                                                                        $tiendaEditArr = trim((string) ($tiendaEditRow['nombre_locatario'] ?? ''));
                                                                        if ($tiendaEditArr === '') {
                                                                            $tiendaEditArr = trim((string) ($tiendaEditRow['nombre_comercial'] ?? ''));
                                                                        }
                                                                        if ($tiendaEditArr === '') {
                                                                            $tiendaEditArr = 'Arrendatario #' . $tiendaEditId;
                                                                        }
                                                                        ?>
                                                                        <option value="<?php echo $tiendaEditId; ?>"><?php echo msp2Escape($tiendaEditLocalesLabel . $tiendaEditArr); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Fecha</label>
                                                                <input
                                                                    type="date"
                                                                    class="form-control"
                                                                    name="fecha_movimiento"
                                                                    id="omw_edit_sf_fecha_movimiento"
                                                                    min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                                    max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                                    required>
                                                            </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Monto</label>
                                                                <input type="text" class="form-control" name="saldo_favor_monto" id="omw_edit_sf_monto" required>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label">Observaciones</label>
                                                                <textarea class="form-control" name="observaciones" id="omw_edit_sf_observaciones" rows="3" maxlength="500"></textarea>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                                                        <button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal fade" id="modal_cancelar_saldo_favor_manual" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Eliminar ingreso manual</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="accion" value="cancelar_saldo_favor_manual">
                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                        <input type="hidden" name="manual_tab" value="saldo_favor">
                                                        <input type="hidden" name="id_movimiento_saldo_favor" id="omw_cancel_sf_movimiento_id" value="">
                                                        <p class="mb-2">Se eliminará el ingreso:</p>
                                                        <p class="small text-muted mb-3" id="omw_cancel_sf_descripcion">-</p>
                                                        <label class="form-label" for="omw_cancel_sf_reason">Motivo (opcional)</label>
                                                        <textarea class="form-control" id="omw_cancel_sf_reason" name="confirm_reason" rows="3" maxlength="500" placeholder="Puedes indicar por qué eliminas este ingreso"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
                                                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Eliminar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <strong>Ya asignados en este período</strong>
                                        <div class="small text-muted">Aplicaciones de saldo a favor ya asociadas a documentos del período.</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">Aplicaciones</div>
                                        <strong><?php echo (int) ($saldoFavorAppliedFlow['count'] ?? 0); ?></strong>
                                        <div class="small text-muted mt-1">Total: <strong>$ <?php echo omFmtNum((float) ($saldoFavorAppliedFlow['total'] ?? 0), 2); ?></strong></div>
                                    </div>
                                </div>
                                <?php if (!(bool) ($saldoFavorAppliedFlow['disponible'] ?? false)): ?>
                                    <div class="small text-muted">No hay trazabilidad de aplicaciones disponible en este ambiente.</div>
                                <?php elseif ((int) ($saldoFavorAppliedFlow['count'] ?? 0) <= 0): ?>
                                    <div class="small text-muted">Todavía no hay saldo aplicado en este período.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Documento</th>
                                                <th>Tienda</th>
                                                <th class="text-end">Monto aplicado</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ((array) ($saldoFavorAppliedFlow['rows'] ?? []) as $saldoAplicadoRow): ?>
                                                <tr>
                                                    <td><?php echo msp2Escape(omFmtFecha((string) ($saldoAplicadoRow['fecha_pago'] ?? ''))); ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoAplicadoRow['numero_documento'] ?? ('#' . (int) ($saldoAplicadoRow['id_documento_cobro'] ?? 0)))); ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoAplicadoRow['nombre_tienda'] ?? '-')); ?></td>
                                                    <td class="text-end fw-semibold">$ <?php echo omFmtNum((float) ($saldoAplicadoRow['monto_aplicado'] ?? 0), 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>


            <section class="omw-step-pane <?php echo $activeStep === 6 ? 'is-active' : ''; ?>" data-step-pane="6" id="paso-6">
                <h2 class="h5 mb-3">Paso 6. Vista previa y lotes de envío</h2>
                <div class="border rounded p-3 bg-light mb-3">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                        <div>
                            <div class="small text-muted">Periodo seleccionado</div>
                            <strong><?php echo msp2Escape($periodoActualYmUi); ?></strong>
                        </div>
                        <span class="small text-muted">Checklist y lotes del período</span>
                    </div>
                </div>

                <?php if ((bool) ($poolSegmentationDiagnostics['disponible'] ?? false)): ?>
                    <?php $poolCombinaciones = is_array($poolSegmentationDiagnostics['combinaciones'] ?? null) ? $poolSegmentationDiagnostics['combinaciones'] : []; ?>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h3 class="h6 mb-1">Checklist de completitud del período</h3>

                            <?php if ($poolCombinaciones === []): ?>
                                <div class="alert alert-light border mb-3">
                                    No hay filas para evaluar en el período.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>Combinación</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Documentados</th>
                                            <th class="text-end">Loteados</th>
                                            <th>Checklist servicios</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($poolCombinaciones as $poolCombo): ?>
                                            <?php
                                            $comboLabel = strtoupper(trim((string) ($poolCombo['combinacion'] ?? '')));
                                            $comboTotal = (int) ($poolCombo['total'] ?? 0);
                                            $readyMap = [
                                                'LUZ' => (int) ($poolCombo['ready_luz'] ?? 0),
                                                'GAS' => (int) ($poolCombo['ready_gas'] ?? 0),
                                                'AGUA' => (int) ($poolCombo['ready_agua'] ?? 0),
                                            ];
                                            ?>
                                            <tr>
                                                <td><?php echo msp2Escape((string) ($poolCombo['combinacion'] ?? '-')); ?></td>
                                                <td class="text-end"><?php echo $comboTotal; ?></td>
                                                <td class="text-end"><?php echo (int) ($poolCombo['documentados'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($poolCombo['loteados'] ?? 0); ?></td>
                                                <td>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php foreach (['LUZ', 'GAS', 'AGUA'] as $svcCode): ?>
                                                            <?php
                                                            $requiresSvc = str_contains($comboLabel, $svcCode);
                                                            $svcReady = (int) ($readyMap[$svcCode] ?? 0);
                                                            if (!$requiresSvc) {
                                                                $svcBadgeClass = 'text-bg-light border text-secondary';
                                                                $svcIcon = 'bi-dash-square';
                                                                $svcText = $svcCode . ' N/A';
                                                            } elseif ($comboTotal > 0 && $svcReady >= $comboTotal) {
                                                                $svcBadgeClass = 'text-bg-success';
                                                                $svcIcon = 'bi-check-square-fill';
                                                                $svcText = $svcCode . ' OK';
                                                            } elseif ($svcReady > 0) {
                                                                $svcBadgeClass = 'text-bg-warning';
                                                                $svcIcon = 'bi-dash-square-fill';
                                                                $svcText = $svcCode . ' ' . $svcReady . '/' . $comboTotal;
                                                            } else {
                                                                $svcBadgeClass = 'text-bg-secondary';
                                                                $svcIcon = 'bi-square';
                                                                $svcText = $svcCode . ' 0/' . $comboTotal;
                                                            }
                                                            ?>
                                                            <span class="badge <?php echo msp2Escape($svcBadgeClass); ?>">
                                                                <i class="bi <?php echo msp2Escape($svcIcon); ?>" aria-hidden="true"></i>
                                                                <?php echo msp2Escape($svcText); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h3 class="h6 mb-3">Lotes del período</h3>

                        <?php if (!$lotesProgramadosDisponibles): ?>
                            <div class="alert alert-warning mb-0">
                                Lotes programados no disponibles en este ambiente. Ejecuta <code>db/patch_envio_lotes_programados.sql</code>.
                            </div>
                        <?php else: ?>
                            <?php if ($lotesProgramados === []): ?>
                                <div class="small text-muted">No hay lotes registrados para este período.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>#Lote</th>
                                            <th>Servicio</th>
                                            <th>Programado</th>
                                            <th>Estado</th>
                                            <th class="text-end">Total</th>
                                            <th class="text-end">Documentos</th>
                                            <th class="text-end">Procesados</th>
                                            <th class="text-end">Enviados</th>
                                            <th class="text-end">Fallidos</th>
                                            <th class="text-end">Omitidos</th>
                                            <th class="text-end">Acciones</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($lotesProgramados as $loteRow): ?>
                                            <?php
                                            $estadoLote = (int) ($loteRow['estado_lote'] ?? 0);
                                            $idLoteRow = (int) ($loteRow['id_lote_envio'] ?? 0);
                                            $programadoParaRaw = (string) ($loteRow['programado_para'] ?? '');
                                            $canCancelLote = $estadoLote === 1 || $estadoLote === 4;
                                            $canForceLote = $estadoLote === 1 || $estadoLote === 4;
                                            $canDeleteLote = $estadoLote !== 2;
                                            ?>
                                            <tr>
                                                <td>#<?php echo $idLoteRow; ?></td>
                                                <td><?php echo msp2Escape((string) ($loteRow['codigo_servicio'] ?? '-')); ?></td>
                                                <td data-programado-utc="<?php echo msp2Escape($programadoParaRaw); ?>" data-programado-local>
                                                    <?php echo msp2Escape(omFmtFecha($programadoParaRaw) . ' ' . substr($programadoParaRaw, 11, 5)); ?>
                                                </td>
                                                <td><?php echo msp2Escape(EnvioLotesProgramadosService::buildEstadoLabel($estadoLote)); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['total_destinatarios'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['total_documentos'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['procesados'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['enviados'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['fallidos'] ?? 0); ?></td>
                                                <td class="text-end"><?php echo (int) ($loteRow['omitidos'] ?? 0); ?></td>
                                                <td class="text-end">
                                                    <?php if ($canForceLote || $canCancelLote || $canDeleteLote): ?>
                                                        <?php if ($canForceLote): ?>
                                                            <form method="post" class="d-inline me-1" data-confirm-message="Se ejecutará ahora el lote #<?php echo $idLoteRow; ?>. ¿Deseas continuar?" data-confirm-title="Forzar envío de lote" data-confirm-variant="warning">
                                                                <input type="hidden" name="accion" value="forzar_lote_programado">
                                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                                <input type="hidden" name="id_lote_envio" value="<?php echo $idLoteRow; ?>">
                                                                <button type="submit" class="btn btn-outline-success btn-sm" title="Forzar envío ahora" aria-label="Forzar envío ahora">
                                                                    <i class="bi bi-play-fill" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <?php if ($canCancelLote): ?>
                                                            <form method="post" class="d-inline">
                                                                <input type="hidden" name="accion" value="cancelar_lote_programado">
                                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                                <input type="hidden" name="id_lote_envio" value="<?php echo $idLoteRow; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm">Cancelar</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <?php if ($canDeleteLote): ?>
                                                            <form method="post" class="d-inline ms-1" data-confirm-message="Se eliminará por completo el lote #<?php echo $idLoteRow; ?> (destinatarios y vínculos). Esta acción no se puede deshacer. ¿Continuar?" data-confirm-title="Eliminar lote del sistema" data-confirm-variant="danger">
                                                                <input type="hidden" name="accion" value="eliminar_lote_programado">
                                                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                                                <input type="hidden" name="id_lote_envio" value="<?php echo $idLoteRow; ?>">
                                                                <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar lote del sistema" aria-label="Eliminar lote del sistema">
                                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted small">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="border rounded p-3 mt-3 bg-light-subtle">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                                    <div class="small text-muted">
                                        Ejecución manual de lotes vencidos (sin esperar al Job). Solo procesa lotes con hora programada <= ahora.
                                    </div>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="accion" value="ejecutar_lotes_programados">
                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Procesar lotes vencidos ahora</button>
                                    </form>
                                </div>
                            </div>

                            <section class="card border-<?php echo $selectedEstadoCierreId === 3 ? 'success' : (in_array($selectedEstadoCierreId,[2,5],true) ? 'primary' : 'warning'); ?> mt-3" aria-labelledby="resumen-final-periodo">
                                <div class="card-body">
                                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                        <div>
                                            <h3 class="h5 mb-1" id="resumen-final-periodo">Resumen final de la operación mensual</h3>
                                            <p class="text-muted mb-0">
                                                <?php if ($selectedEstadoCierreId === 3): ?>
                                                    La operación de <?php echo msp2Escape($periodoActualYmUi); ?> está cerrada y congelada.
                                                <?php elseif ($selectedEstadoCierreId === 2): ?>
                                                    El cálculo terminó. Revisa el resumen y confirma la revisión antes de cerrar.
                                                <?php elseif ($selectedEstadoCierreId === 5): ?>
                                                    La revisión fue confirmada. Puedes cerrar el período o volver a Borrador si detectaste un error.
                                                <?php else: ?>
                                                    La operación todavía no está calculada. Completa los pasos pendientes antes de cerrarla.
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <span class="badge text-bg-<?php echo $selectedEstadoCierreId === 3 ? 'success' : (in_array($selectedEstadoCierreId,[2,5],true) ? 'primary' : 'warning'); ?> fs-6">
                                            <?php echo msp2Escape($selectedEstadoCierreLabel); ?>
                                        </span>
                                    </div>

                                    <div class="row g-2 mt-2">
                                        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Cobros generados</div><div class="fs-4 fw-semibold"><?php echo (int) ($summary['cobros'] ?? 0); ?></div></div></div>
                                        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Documentos emitidos</div><div class="fs-4 fw-semibold"><?php echo (int) ($summary['documentos'] ?? 0); ?></div></div></div>
                                        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Total documentado</div><div class="fs-5 fw-semibold">$ <?php echo omFmtNum($summary['total_documentado'] ?? 0, 2); ?></div></div></div>
                                        <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="small text-muted">Saldo pendiente</div><div class="fs-5 fw-semibold">$ <?php echo omFmtNum($summary['total_saldo'] ?? 0, 2); ?></div></div></div>
                                    </div>

                                    <div class="alert alert-info py-2 mt-3 mb-3">
                                        Los lotes corresponden al envío por correo. Los documentos no enviados u omitidos conservan su cobro y no impiden cerrar el período.
                                    </div>

                                    <?php if ($canRevisarPeriodo): ?>
                                        <form method="post" class="d-inline" data-confirm-message="¿Confirmas que revisaste los cálculos y documentos de <?php echo msp2Escape($periodoActualYmUi); ?>?" data-confirm-title="Confirmar revisión" data-confirm-variant="primary">
                                            <input type="hidden" name="accion" value="revisar_periodo">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Confirmar revisión</button>
                                        </form>
                                    <?php elseif ($canCerrarPeriodo): ?>
                                        <form method="post" class="d-inline" data-confirm-message="Se cerrará el período <?php echo msp2Escape($periodoActualYmUi); ?> y el cálculo quedará congelado. ¿Deseas finalizar la operación mensual?" data-confirm-title="Finalizar operación mensual" data-confirm-variant="success">
                                            <input type="hidden" name="accion" value="cerrar_periodo">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                            <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Finalizar y cerrar período</button>
                                        </form>
                                    <?php elseif ($selectedEstadoCierreId === 3): ?>
                                        <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Operación mensual finalizada</span>
                                    <?php endif; ?>
                                </div>
                            </section>

                            <form
                                method="post"
                                class="border border-danger-subtle rounded p-3 bg-danger-subtle mt-3"
                                data-confirm-message="Esta acción puede deshacer lotes y generación del período. ¿Deseas continuar?"
                                data-confirm-title="Confirmar corrección del período"
                                data-confirm-variant="danger">
                                <input type="hidden" name="accion" value="borrar_generacion">
                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoActualYm); ?>">
                                <h3 class="h6 text-danger mb-1">Zona de corrección</h3>
                                <?php if ($selectedCierre !== null && !$isPeriodoEditableForMutation): ?>
                                    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert">
                                        El período está en estado <strong><?php echo msp2Escape($selectedEstadoCierreLabel); ?></strong>.
                                        Reábrelo a <strong>Borrador</strong> para ejecutar correcciones.
                                    </div>
                                <?php endif; ?>
                                <div class="small mb-2">
                                    Aplica al período completo <strong><?php echo msp2Escape($periodoActualYmUi); ?></strong>.
                                </div>
                                <div class="d-flex flex-wrap gap-3 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="borrar_lotes_programados_step6" name="cancelar_lotes_programados" value="1" checked <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>
                                        <label class="form-check-label" for="borrar_lotes_programados_step6">
                                            Cancelar lotes activos del período
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="borrar_cargos_salida_asociados_step6" name="borrar_cargos_salida_asociados" value="1" <?php echo ((int) ($summary['cargos_salida_asociados'] ?? 0) > 0) ? 'checked' : ''; ?> <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>
                                        <label class="form-check-label" for="borrar_cargos_salida_asociados_step6">
                                            Desvincular cargos de salida asociados (<?php echo (int) ($summary['cargos_salida_asociados'] ?? 0); ?>)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="borrar_pagos_step6" name="borrar_pagos" value="1" <?php echo ((int) ($summary['pagos'] ?? 0) > 0) ? 'checked' : ''; ?> <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>
                                        <label class="form-check-label" for="borrar_pagos_step6">
                                            Borrar pagos (<?php echo (int) ($summary['pagos'] ?? 0); ?>)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="borrar_documentos_step6" name="borrar_documentos" value="1" <?php echo ((int) ($summary['documentos'] ?? 0) > 0) ? 'checked' : ''; ?> <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>
                                        <label class="form-check-label" for="borrar_documentos_step6">
                                            Borrar documentos (<?php echo (int) ($summary['documentos'] ?? 0); ?>)
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="borrar_cobros_step6" name="borrar_cobros" value="1" <?php echo ((int) ($summary['cobros'] ?? 0) > 0) ? 'checked' : ''; ?> <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>
                                        <label class="form-check-label" for="borrar_cobros_step6">
                                            Borrar cobros (<?php echo (int) ($summary['cobros'] ?? 0); ?>)
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo $isPeriodoEditableForMutation ? '' : 'disabled'; ?>>Ejecutar corrección</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="omw-footer-nav d-flex justify-content-between flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" id="omw-prev-btn" <?php echo $wizardEnabled ? '' : 'disabled'; ?>><i class="bi bi-arrow-left me-1"></i>Anterior</button>
                <button type="button" class="btn btn-primary" id="omw-next-btn" <?php echo $wizardEnabled ? '' : 'disabled'; ?>>Siguiente<i class="bi bi-arrow-right ms-1"></i></button>
            </div>
        <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
(() => {
    const initialFocus = <?php echo json_encode($focusAnchorQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const wizardButtons = Array.from(document.querySelectorAll('.omw-step-btn'));
    const wizardPanes = Array.from(document.querySelectorAll('.omw-step-pane'));
    const prevBtn = document.getElementById('omw-prev-btn');
    const nextBtn = document.getElementById('omw-next-btn');
    const wizardEnabled = <?php echo $wizardEnabled ? 'true' : 'false'; ?>;
    const totalWizardSteps = <?php echo count($steps); ?>;
    let currentStep = <?php echo (int) $activeStep; ?>;
    const decorateWizardFieldLabels = () => {
        const resolveControlForLabel = (label) => {
            if (!(label instanceof HTMLLabelElement)) {
                return null;
            }

            if (label.htmlFor) {
                const linked = document.getElementById(label.htmlFor);
                if (linked instanceof HTMLInputElement || linked instanceof HTMLSelectElement || linked instanceof HTMLTextAreaElement) {
                    return linked;
                }
            }

            const container = label.parentElement;
            if (!(container instanceof HTMLElement)) {
                return null;
            }

            const directControls = Array.from(container.querySelectorAll('input, select, textarea'));
            const requiredControl = directControls.find((control) => {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) {
                    return false;
                }
                if (control.disabled) {
                    return false;
                }
                return control.hasAttribute('required');
            });
            if (requiredControl) {
                return requiredControl;
            }

            const visibleControl = directControls.find((control) => {
                if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) {
                    return false;
                }
                if (control.disabled) {
                    return false;
                }
                return !(control instanceof HTMLInputElement && control.type === 'hidden');
            });
            if (visibleControl) {
                return visibleControl;
            }

            const hiddenRequired = directControls.find((control) => {
                return control instanceof HTMLInputElement
                    && control.type === 'hidden'
                    && control.hasAttribute('required');
            });
            return hiddenRequired || null;
        };

        wizardPanes.forEach((pane) => {
            pane.querySelectorAll('label.form-label, label.form-check-label').forEach((label) => {
                if (!(label instanceof HTMLLabelElement)) {
                    return;
                }
                if (label.dataset.markerApplied === '1') {
                    return;
                }
                if (label.querySelector('.omw-required-mark, .omw-optional-mark')) {
                    label.dataset.markerApplied = '1';
                    return;
                }
                const rawText = (label.textContent || '').trim().toLowerCase();
                if (rawText === '') {
                    return;
                }
                if (rawText.includes('seleccionar todos')) {
                    return;
                }

                const control = resolveControlForLabel(label);
                if (!control) {
                    return;
                }

                if (control.hasAttribute('required')) {
                    const requiredMark = document.createElement('span');
                    requiredMark.className = 'omw-required-mark';
                    requiredMark.textContent = '*';
                    label.append(requiredMark);
                } else {
                    const optionalMark = document.createElement('span');
                    optionalMark.className = 'omw-optional-mark';
                    optionalMark.textContent = '(opcional)';
                    label.append(optionalMark);
                }
                label.dataset.markerApplied = '1';
            });
        });
    };
    decorateWizardFieldLabels();

    const setWizardStep = (step) => {
        const safeStep = wizardEnabled
            ? Math.max(1, Math.min(step, totalWizardSteps))
            : 1;
        currentStep = safeStep;

        wizardPanes.forEach((pane) => {
            const paneStep = Number.parseInt(pane.dataset.stepPane || '0', 10);
            pane.classList.toggle('is-active', paneStep === safeStep);
        });

        wizardButtons.forEach((btn) => {
            const btnStep = Number.parseInt(btn.dataset.stepTarget || '0', 10);
            const isActive = btnStep === safeStep;
            const isDone = btnStep > 0 && btnStep < safeStep;

            btn.classList.toggle('is-active', isActive);
            btn.classList.toggle('is-done', isDone);
            btn.setAttribute('aria-selected', btnStep === safeStep ? 'true' : 'false');
        });

        if (prevBtn) {
            prevBtn.disabled = !wizardEnabled || safeStep <= 1;
        }
        if (nextBtn) {
            const isFinalStep = safeStep >= totalWizardSteps;
            nextBtn.disabled = !wizardEnabled || isFinalStep;
            nextBtn.innerHTML = isFinalStep
                ? '<i class="bi bi-check-circle me-1"></i>Fin del flujo'
                : 'Siguiente<i class="bi bi-arrow-right ms-1"></i>';
        }
    };
    const parseDecimal = (raw) => {
        if (!raw) return null;
        const normalized = String(raw).replace(/\s+/g, '').replace(',', '.');
        if (normalized === '' || Number.isNaN(Number(normalized))) return null;
        return Number(normalized);
    };

    const formatDecimal = (value, decimals) => {
        if (!Number.isFinite(value)) {
            return '';
        }

        return Number(value).toLocaleString('es-CL', {
            useGrouping: false,
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    };

    const bindFactorInputs = () => {
        const factorInputs = Array.from(document.querySelectorAll('input[name="factor"]')).filter((el) => el instanceof HTMLInputElement);
        factorInputs.forEach((input) => {
            if (input.dataset.factorBound === '1') {
                return;
            }
            input.dataset.factorBound = '1';
            input.setAttribute('inputmode', 'decimal');

            input.addEventListener('input', () => {
                const current = input.value;
                let out = current.replace(/[^\d.,]/g, '');
                const firstSep = out.search(/[.,]/);
                if (firstSep !== -1) {
                    const intPart = out.slice(0, firstSep).replace(/[.,]/g, '');
                    const decPart = out.slice(firstSep + 1).replace(/[.,]/g, '').slice(0, 2);
                    out = `${intPart}${out[firstSep]}${decPart}`;
                } else {
                    out = out.replace(/[.,]/g, '');
                }
                if (out !== current) {
                    input.value = out;
                }
            });

            input.addEventListener('blur', () => {
                const raw = input.value.trim();
                if (raw === '') {
                    return;
                }
                const normalized = raw.replace(/\./g, '').replace(',', '.');
                const n = Number.parseFloat(normalized);
                if (!Number.isFinite(n)) {
                    return;
                }
                input.value = n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            });
        });
    };
    bindFactorInputs();

    const refreshConsumo = (form) => {
        const inputAnterior = form.querySelector('[data-agua-anterior]');
        const inputActual = form.querySelector('[data-agua-actual]');
        const inputConsumo = form.querySelector('[data-agua-consumo]');
        if (!inputAnterior || !inputActual || !inputConsumo) return;

        const anterior = parseDecimal(inputAnterior.value);
        const actual = parseDecimal(inputActual.value);

        if (anterior === null || actual === null) {
            inputConsumo.value = '';
            return;
        }

        const consumo = actual - anterior;
        inputConsumo.value = consumo > 0 ? formatDecimal(consumo, 0) : '';
    };

    document.querySelectorAll('form').forEach((form) => {
        if (!form.querySelector('[data-agua-consumo]')) return;
        form.addEventListener('input', () => refreshConsumo(form));
        refreshConsumo(form);
    });

    document.querySelectorAll('[data-open-excel]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }

            const targetUrl = button.dataset.openExcel || '';
            if (targetUrl !== '') {
                window.open(targetUrl, '_blank');
            }
        });
    });

    document.querySelectorAll('[data-direct-reading-form]').forEach((form) => {
        const refreshDirectRows = () => {
            form.querySelectorAll('tr').forEach((row) => {
                const actualInput = row.querySelector('[data-reading-actual]');
                const consumoTarget = row.querySelector('[data-reading-consumo]');
                if (!actualInput || !consumoTarget) {
                    return;
                }

                const anterior = parseDecimal(actualInput.dataset.readingAnterior || '');
                const actual = parseDecimal(actualInput.value);
                if (anterior === null || actual === null) {
                    consumoTarget.textContent = '-';
                    return;
                }

                const consumo = actual - anterior;
                consumoTarget.textContent = consumo >= 0
                    ? Number(consumo).toLocaleString('es-CL', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0,
                    })
                    : '-';
            });
        };

        form.addEventListener('input', refreshDirectRows);
        refreshDirectRows();
    });

    const periodoSelectorValue = document.getElementById('periodo_selector_value');
    const quickExtraForm = document.getElementById('form_cargo_extra_rapido');
    const extraTargetInput = document.getElementById('omw_target_contrato_local');
    const extraTargetBtn = document.getElementById('omw_target_dropdown_btn');
    const extraTargetError = document.getElementById('omw_target_picker_error');
    const extraTipoInput = document.getElementById('omw_id_tipo_cargo_salida');
    const extraTipoBtn = document.getElementById('omw_tipo_dropdown_btn');
    const extraTipoError = document.getElementById('omw_tipo_picker_error');
    const saldoFavorManualForm = document.getElementById('form_saldo_favor_manual_paso2');
    const saldoFavorManualHidden = document.getElementById('omw_saldo_favor_id_tienda');
    const saldoFavorManualBtn = document.getElementById('omw_saldo_favor_dropdown_btn');
    const saldoFavorManualError = document.getElementById('omw_saldo_favor_picker_error');
    const crearPeriodoBtn = document.getElementById('btn_crear_periodo');
    const cardPeriodoForm = document.getElementById('card_periodo_form');
    const modoCierreInput = document.getElementById('modo_cierre');
    const periodoInput = document.getElementById('periodo_input');
    const idCierreInput = document.getElementById('id_cierre_mensual');
    const fechaValorUfInput = document.getElementById('fecha_valor_uf_input');
    const periodoFormTitle = document.getElementById('periodo_form_title');
    const periodoFormModeBadge = document.getElementById('periodo_form_mode_badge');
    const periodoFormSubmitBtn = document.getElementById('periodo_form_submit_btn');
    const periodoInputHelp = document.getElementById('periodo_input_help');
    const pasoUno = document.getElementById('paso-1');
    const initialPeriodoFormMode = <?php echo json_encode($periodoFormMode, JSON_UNESCAPED_UNICODE); ?>;
    let fechaValorUfAutoMode = <?php echo $periodoFormMode === 'create' ? 'true' : 'false'; ?>;
    const periodoInputInitialValue = periodoInput instanceof HTMLInputElement ? String(periodoInput.value || '').trim() : '';
    let fechaValorUfDirty = false;

    const buildFirstDayOfMonth = (periodoYm) => {
        return /^\d{4}-\d{2}$/.test(periodoYm) ? `${periodoYm}-01` : '';
    };

    const syncFechaValorUfWithPeriodo = () => {
        if (!fechaValorUfAutoMode || !periodoInput || !fechaValorUfInput || fechaValorUfDirty) {
            return;
        }

        const firstDay = buildFirstDayOfMonth(periodoInput.value || '');
        if (firstDay !== '') {
            fechaValorUfInput.value = firstDay;
        }
    };

    const showPeriodoForm = () => {
        if (!cardPeriodoForm) {
            return;
        }
        cardPeriodoForm.classList.remove('d-none');
    };

    const applyPeriodoFormMode = (mode) => {
        const normalizedMode = mode === 'edit' ? 'edit' : 'create';
        fechaValorUfAutoMode = normalizedMode === 'create';

        if (modoCierreInput instanceof HTMLInputElement) {
            modoCierreInput.value = normalizedMode;
        }

        if (periodoFormTitle instanceof HTMLElement) {
            periodoFormTitle.textContent = normalizedMode === 'edit' ? '1) Editar periodo y UF' : '1) Crear periodo y UF';
        }

        if (periodoFormModeBadge instanceof HTMLElement) {
            periodoFormModeBadge.textContent = normalizedMode === 'edit' ? 'Modo edicion' : 'Modo creacion';
        }

        if (periodoFormSubmitBtn instanceof HTMLButtonElement) {
            periodoFormSubmitBtn.textContent = normalizedMode === 'edit' ? 'Actualizar periodo' : 'Crear periodo';
        }

        if (periodoInputHelp instanceof HTMLElement) {
            periodoInputHelp.textContent = normalizedMode === 'edit'
                ? 'Periodo bloqueado en edicion. Usa "Crear nuevo periodo" para registrar otro mes.'
                : 'Selecciona el mes que quieres crear.';
        }

        if (periodoInput instanceof HTMLInputElement) {
            if (normalizedMode === 'edit') {
                periodoInput.setAttribute('readonly', 'readonly');
            } else {
                periodoInput.removeAttribute('readonly');
            }
        }
    };

    applyPeriodoFormMode(initialPeriodoFormMode);

    if (periodoSelectorValue instanceof HTMLInputElement) {
        periodoSelectorValue.addEventListener('change', () => {
            const periodo = String(periodoSelectorValue.value || '').trim();
            if (periodo !== '') {
                window.location = `?periodo=${encodeURIComponent(periodo)}`;
            }
        });
    }

    if (quickExtraForm instanceof HTMLFormElement) {
        quickExtraForm.addEventListener('submit', (event) => {
            let valid = true;
            if (extraTargetInput instanceof HTMLInputElement && String(extraTargetInput.value || '').trim() === '') {
                valid = false;
                if (extraTargetBtn instanceof HTMLButtonElement) {
                    extraTargetBtn.classList.add('is-invalid');
                }
                if (extraTargetError instanceof HTMLDivElement) {
                    extraTargetError.classList.remove('d-none');
                }
            }
            if (extraTipoInput instanceof HTMLInputElement && String(extraTipoInput.value || '').trim() === '') {
                valid = false;
                if (extraTipoBtn instanceof HTMLButtonElement) {
                    extraTipoBtn.classList.add('is-invalid');
                }
                if (extraTipoError instanceof HTMLDivElement) {
                    extraTipoError.classList.remove('d-none');
                }
            }
            if (!valid) {
                event.preventDefault();
            }
        });
    }

    if (saldoFavorManualForm instanceof HTMLFormElement) {
        saldoFavorManualForm.addEventListener('submit', (event) => {
            if (!(saldoFavorManualHidden instanceof HTMLInputElement)) {
                return;
            }
            if (String(saldoFavorManualHidden.value || '').trim() !== '') {
                return;
            }
            event.preventDefault();
            if (saldoFavorManualBtn instanceof HTMLButtonElement) {
                saldoFavorManualBtn.classList.add('is-invalid');
                saldoFavorManualBtn.focus();
            }
            if (saldoFavorManualError instanceof HTMLDivElement) {
                saldoFavorManualError.classList.remove('d-none');
            }
        });
    }

    const modalEditarCargoExtra = document.getElementById('modal_editar_cargo_extra');
    if (modalEditarCargoExtra) {
        modalEditarCargoExtra.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalEditarCargoExtra.querySelector('#omw_edit_id_cargo_salida');
            const fechaInput = modalEditarCargoExtra.querySelector('#omw_edit_fecha_cargo');
            const tipoInput = modalEditarCargoExtra.querySelector('#omw_edit_id_tipo_cargo_salida');
            const descripcionInput = modalEditarCargoExtra.querySelector('#omw_edit_descripcion_cargo');
            const montoInput = modalEditarCargoExtra.querySelector('#omw_edit_monto_cargo');
            const observacionesInput = modalEditarCargoExtra.querySelector('#omw_edit_observaciones_cargo');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-edit-id') || '');
            }
            if (fechaInput instanceof HTMLInputElement) {
                fechaInput.value = String(trigger.getAttribute('data-edit-fecha') || '');
            }
            if (tipoInput instanceof HTMLSelectElement) {
                tipoInput.value = String(trigger.getAttribute('data-edit-tipo-id') || '');
            }
            if (descripcionInput instanceof HTMLInputElement) {
                descripcionInput.value = String(trigger.getAttribute('data-edit-descripcion') || '');
            }
            if (montoInput instanceof HTMLInputElement) {
                montoInput.value = String(trigger.getAttribute('data-edit-monto') || '');
            }
            if (observacionesInput instanceof HTMLTextAreaElement) {
                observacionesInput.value = String(trigger.getAttribute('data-edit-observaciones') || '');
            }
        });
    }

    const modalCancelarCargoExtra = document.getElementById('modal_cancelar_cargo_extra');
    if (modalCancelarCargoExtra) {
        modalCancelarCargoExtra.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalCancelarCargoExtra.querySelector('#omw_cancel_id_cargo_salida');
            const descripcionNode = modalCancelarCargoExtra.querySelector('#omw_cancel_descripcion_cargo');
            const motivoInput = modalCancelarCargoExtra.querySelector('#omw_cancel_reason');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-cancel-id') || '');
            }
            if (descripcionNode instanceof HTMLElement) {
                descripcionNode.textContent = String(trigger.getAttribute('data-cancel-descripcion') || '-');
            }
            if (motivoInput instanceof HTMLTextAreaElement) {
                motivoInput.value = '';
            }
        });
    }

    const modalEditarSaldoFavor = document.getElementById('modal_editar_saldo_favor_manual');
    if (modalEditarSaldoFavor) {
        modalEditarSaldoFavor.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalEditarSaldoFavor.querySelector('#omw_edit_sf_movimiento_id');
            const tiendaInput = modalEditarSaldoFavor.querySelector('#omw_edit_sf_id_tienda');
            const fechaInput = modalEditarSaldoFavor.querySelector('#omw_edit_sf_fecha_movimiento');
            const montoInput = modalEditarSaldoFavor.querySelector('#omw_edit_sf_monto');
            const observacionesInput = modalEditarSaldoFavor.querySelector('#omw_edit_sf_observaciones');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-edit-id') || '');
            }
            if (tiendaInput instanceof HTMLSelectElement) {
                tiendaInput.value = String(trigger.getAttribute('data-edit-id-tienda') || '');
            }
            if (fechaInput instanceof HTMLInputElement) {
                fechaInput.value = String(trigger.getAttribute('data-edit-fecha') || '');
            }
            if (montoInput instanceof HTMLInputElement) {
                montoInput.value = String(trigger.getAttribute('data-edit-monto') || '');
            }
            if (observacionesInput instanceof HTMLTextAreaElement) {
                observacionesInput.value = String(trigger.getAttribute('data-edit-observaciones') || '');
            }
        });
    }

    const modalCancelarSaldoFavor = document.getElementById('modal_cancelar_saldo_favor_manual');
    if (modalCancelarSaldoFavor) {
        modalCancelarSaldoFavor.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalCancelarSaldoFavor.querySelector('#omw_cancel_sf_movimiento_id');
            const descripcionNode = modalCancelarSaldoFavor.querySelector('#omw_cancel_sf_descripcion');
            const motivoInput = modalCancelarSaldoFavor.querySelector('#omw_cancel_sf_reason');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-cancel-id') || '');
            }
            if (descripcionNode instanceof HTMLElement) {
                descripcionNode.textContent = String(trigger.getAttribute('data-cancel-descripcion') || '-');
            }
            if (motivoInput instanceof HTMLTextAreaElement) {
                motivoInput.value = '';
            }
        });
    }

    if (fechaValorUfInput) {
        fechaValorUfInput.addEventListener('input', () => {
            fechaValorUfDirty = true;
        });
    }

    if (periodoInput) {
        periodoInput.addEventListener('input', () => {
            fechaValorUfDirty = false;
            syncFechaValorUfWithPeriodo();
        });
        periodoInput.addEventListener('change', () => {
            if (idCierreInput instanceof HTMLInputElement && String(periodoInput.value || '').trim() !== periodoInputInitialValue) {
                idCierreInput.value = '';
            }
            syncFechaValorUfWithPeriodo();
        });
    }

    if (crearPeriodoBtn && periodoInput) {
        crearPeriodoBtn.addEventListener('click', () => {
            showPeriodoForm();
            applyPeriodoFormMode('create');
            if (idCierreInput) {
                idCierreInput.value = '';
            }
            if (periodoInput.value === '') {
                const now = new Date();
                const ym = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
                periodoInput.value = ym;
            }
            fechaValorUfDirty = false;
            syncFechaValorUfWithPeriodo();
            if (pasoUno) {
                pasoUno.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            periodoInput.focus();
        });
    }

    const params = new URLSearchParams(window.location.search);
    const excelUrls = <?php echo json_encode([
        'LUZ' => msp2Url('cobros/plantilla_lecturas.php?servicio=LUZ&periodo=' . urlencode($periodoActualYm)),
        'GAS' => msp2Url('cobros/plantilla_lecturas.php?servicio=GAS&periodo=' . urlencode($periodoActualYm)),
        'AGUA' => msp2Url('cobros/plantilla_lecturas.php?servicio=AGUA&periodo=' . urlencode($periodoActualYm)),
    ], JSON_UNESCAPED_SLASHES); ?>;

    const setServiceFeedback = (form, type, message) => {
        const card = form.closest('[data-service-card]');
        const feedback = card ? card.querySelector('[data-service-feedback]') : null;
        if (!feedback) {
            return;
        }

        feedback.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
        feedback.classList.add(`alert-${type}`);
        feedback.textContent = message;
    };

    const syncServiceCardState = (form, hasProcess) => {
        const card = form.closest('[data-service-card]');
        if (!card) {
            return;
        }

        const processPill = card.querySelector('[data-service-process-pill]');
        if (processPill) {
            processPill.classList.toggle('is-ready', hasProcess);
        }

        const processPanel = card.querySelector('[data-process-panel]');
        if (processPanel) {
            processPanel.classList.toggle('is-ready', hasProcess);
            processPanel.classList.toggle('is-locked', !hasProcess);
            processPanel.setAttribute('aria-disabled', hasProcess ? 'false' : 'true');
        }

        const processPanelBadge = card.querySelector('[data-process-panel-badge]');
        if (processPanelBadge) {
            processPanelBadge.textContent = hasProcess ? 'Habilitado' : 'Bloqueado';
            processPanelBadge.classList.remove('text-bg-success', 'text-bg-secondary', 'text-bg-dark');
            processPanelBadge.classList.add(hasProcess ? 'text-bg-success' : 'text-bg-secondary');
        }

        const processState = card.querySelector('[data-service-process-state]');
        if (processState) {
            processState.textContent = hasProcess ? 'Creado' : 'Nuevo';
        }

        const processWarning = card.querySelector('[data-process-warning]');
        if (processWarning) {
            processWarning.classList.toggle('d-none', hasProcess);
        }

        card.querySelectorAll('[data-enable-on-process]').forEach((element) => {
            if (!('disabled' in element)) {
                return;
            }

            element.disabled = !hasProcess;
            if (element instanceof HTMLInputElement && element.type === 'file') {
                element.required = hasProcess;
            }
        });
    };

    document.querySelectorAll('[data-async-service-form="1"]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submitter = event.submitter instanceof HTMLButtonElement
                ? event.submitter
                : form.querySelector('button[type="submit"]');
            const originalLabel = submitter ? submitter.innerHTML : '';
            const wantsAutoExcel = form.querySelector('input[name="auto_excel"][value="1"]') !== null;
            let excelWindow = null;

            if (submitter) {
                submitter.disabled = true;
                submitter.innerHTML = 'Guardando...';
            }

            if (wantsAutoExcel) {
                excelWindow = window.open('', '_blank');
            }

            setServiceFeedback(form, 'info', 'Guardando parámetros...');

            try {
                const formData = new FormData(form);
                formData.set('ajax', '1');

                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (error) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    throw new Error(payload && payload.message ? payload.message : 'No fue posible guardar el servicio.');
                }

                syncServiceCardState(form, Boolean(payload.has_proceso));
                setServiceFeedback(form, 'success', payload.message || 'Parámetros guardados correctamente.');

                if (payload.auto_excel_url) {
                    if (excelWindow) {
                        excelWindow.location = payload.auto_excel_url;
                    } else {
                        window.open(payload.auto_excel_url, '_blank');
                    }
                } else if (excelWindow) {
                    excelWindow.close();
                }
            } catch (error) {
                if (excelWindow) {
                    excelWindow.close();
                }
                const message = error instanceof Error ? error.message : 'No fue posible guardar el servicio.';
                setServiceFeedback(form, 'danger', message);
            } finally {
                if (submitter) {
                    submitter.disabled = false;
                    submitter.innerHTML = originalLabel;
                }
            }
        });
    });

    if (params.get('auto_excel') === '1') {
        const svc = params.get('auto_servicio') || '';
        if (svc && excelUrls[svc]) {
            window.open(excelUrls[svc], '_blank');
        }
    }

    if (params.get('auto_confirm_import') === '1') {
        const svc = (params.get('auto_servicio') || '').toUpperCase();
        if (svc) {
            const confirmForm = document.querySelector('form[data-auto-confirm-form="' + svc + '"]');
            if (confirmForm) {
                confirmForm.requestSubmit();
            }
        }
    }

    if (params.get('auto_docs') === '1') {
        const docsForm = document.getElementById('form_generar_documentos');
        if (docsForm) {
            const dias = params.get('auto_dias');
            const rep = params.get('auto_rep');
            const diasInput = docsForm.querySelector('input[name="dias_vencimiento"]');
            if (diasInput && dias) {
                diasInput.value = dias;
            }
            const repInput = docsForm.querySelector('input[name="reemplazar"]');
            if (repInput) {
                repInput.checked = rep === '1';
            }
            docsForm.requestSubmit();
        }
    }

    const stageLoteAfterGenerationModalEl = document.getElementById('modal_stage_lote_after_generation');
    const completionHintModalEl = document.getElementById('modal_completion_hint_stage');
    if (stageLoteAfterGenerationModalEl || completionHintModalEl) {
        window.addEventListener('load', () => {
            if (!window.bootstrap || !window.bootstrap.Modal) {
                return;
            }
            const modalTarget = stageLoteAfterGenerationModalEl
                || completionHintModalEl;
            if (!modalTarget) {
                return;
            }
            const modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalTarget);
            modalInstance.show();
        }, { once: true });
    }

    const focusStepMap = {
        'paso-1': 1,
        'paso-5': 2,
        'servicio-luz': 3,
        'servicio-gas': 4,
        'servicio-agua': 5,
        'paso-3': 6,
        'paso-6': 6,
    };
    const focusStep = initialFocus && focusStepMap[initialFocus] ? focusStepMap[initialFocus] : currentStep;

    if (wizardButtons.length > 0) {
        wizardButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const step = Number.parseInt(btn.dataset.stepTarget || '1', 10) || 1;
                setWizardStep(step);
                const pane = document.querySelector(`.omw-step-pane[data-step-pane="${step}"]`);
                if (pane) {
                    pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            setWizardStep(currentStep - 1);
            const pane = document.querySelector(`.omw-step-pane[data-step-pane="${currentStep}"]`);
            if (pane) {
                pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            setWizardStep(currentStep + 1);
            const pane = document.querySelector(`.omw-step-pane[data-step-pane="${currentStep}"]`);
            if (pane) {
                pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    const sendAll = document.getElementById('omw-send-all');
    const sendChecks = Array.from(document.querySelectorAll('.omw-send-check'));
    const sendSelectedLabel = document.getElementById('omw-send-selected');
    const sendDocsLabel = document.getElementById('omw-send-docs');
    const demoForm = document.getElementById('omw-demo-form');
    const demoSubmit = document.getElementById('omw-demo-submit');
    const demoCancel = document.getElementById('omw-demo-cancel');
    const demoProgress = document.getElementById('omw-demo-progress');
    const demoStatus = document.getElementById('omw-demo-status');
    const demoTimer = document.getElementById('omw-demo-timer');
    let demoTimerStart = 0;
    let demoTimerInterval = null;
    let demoLastProgress = { percent: 0, processed: 0, total: 0, sent: 0, failed: 0 };
    let demoBasePayload = null;
    let demoAbortController = null;
    let demoCancelRequested = false;

    const refreshSendSummary = () => {
        let selectedCount = 0;
        let selectedDocs = 0;
        sendChecks.forEach((checkbox) => {
            if (!checkbox.checked) {
                return;
            }
            selectedCount += 1;
            const docs = Number.parseInt(checkbox.dataset.docs || '0', 10);
            if (!Number.isNaN(docs)) {
                selectedDocs += docs;
            }
        });
        if (sendSelectedLabel) {
            sendSelectedLabel.textContent = `${selectedCount} arrendatarios`;
        }
        if (sendDocsLabel) {
            sendDocsLabel.textContent = `${selectedDocs} documentos`;
        }
        if (sendAll) {
            sendAll.checked = sendChecks.length > 0 && selectedCount === sendChecks.length;
            sendAll.indeterminate = selectedCount > 0 && selectedCount < sendChecks.length;
        }
    };

    if (sendAll) {
        sendAll.addEventListener('change', () => {
            const isChecked = sendAll.checked;
            sendChecks.forEach((checkbox) => {
                checkbox.checked = isChecked;
            });
            refreshSendSummary();
        });
    }
    sendChecks.forEach((checkbox) => {
        checkbox.addEventListener('change', refreshSendSummary);
    });
    refreshSendSummary();

    const toFiniteNumber = (value) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };

    const updateDemoProgress = (percent, processed, total, sent, failed) => {
        if (!demoProgress) {
            return;
        }
        const nextPercent = toFiniteNumber(percent);
        const nextProcessed = toFiniteNumber(processed);
        const nextTotal = toFiniteNumber(total);
        const nextSent = toFiniteNumber(sent);
        const nextFailed = toFiniteNumber(failed);
        demoLastProgress = {
            percent: nextPercent !== null ? nextPercent : demoLastProgress.percent,
            processed: nextProcessed !== null ? nextProcessed : demoLastProgress.processed,
            total: nextTotal !== null ? nextTotal : demoLastProgress.total,
            sent: nextSent !== null ? nextSent : demoLastProgress.sent,
            failed: nextFailed !== null ? nextFailed : demoLastProgress.failed,
        };
        const clamped = Math.min(100, Math.max(0, demoLastProgress.percent));
        demoProgress.classList.remove('d-none');
        const bar = demoProgress.querySelector('.progress-bar');
        const wrapper = demoProgress.querySelector('.progress');
        if (bar) {
            bar.style.width = `${clamped}%`;
        }
        if (wrapper) {
            wrapper.setAttribute('aria-valuenow', `${clamped}`);
        }
        if (demoStatus) {
            demoStatus.textContent = `Enviados ${demoLastProgress.sent} / ${demoLastProgress.total} (fallidos ${demoLastProgress.failed}). Avance ${demoLastProgress.processed}/${demoLastProgress.total}.`;
        }
    };

    const updateDemoTimer = () => {
        if (demoTimer && demoTimerStart > 0) {
            const elapsedMs = Date.now() - demoTimerStart;
            const seconds = Math.floor(elapsedMs / 1000);
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            demoTimer.textContent = `Tiempo: ${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }
    };

    const setDemoEnabled = (enabled) => {
        if (!demoForm) {
            return;
        }
        demoForm.querySelectorAll('input, button, select, textarea').forEach((el) => {
            if (el === demoSubmit || el === demoCancel) {
                return;
            }
            el.disabled = !enabled;
        });
        if (demoSubmit) {
            demoSubmit.disabled = !enabled;
        }
        if (demoCancel) {
            demoCancel.disabled = enabled;
        }
    };

    const buildDemoFormData = (jobId = '') => {
        const formData = new FormData();
        if (demoBasePayload) {
            for (const [key, value] of demoBasePayload.entries()) {
                formData.append(key, value);
            }
        }
        formData.set('accion', 'enviar_demo_batch');
        formData.set('ajax', '1');
        if (jobId) {
            formData.set('job_id', jobId);
        }
        return formData;
    };

    const runDemoBatch = async (jobId = '') => {
        if (!demoForm) {
            return;
        }

        const formData = buildDemoFormData(jobId);

        const response = await fetch(demoForm.action || window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': (window.msp2CsrfToken || ''),
            },
            signal: demoAbortController ? demoAbortController.signal : undefined,
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(payload && payload.message ? payload.message : 'No fue posible enviar el demo.');
        }

        updateDemoProgress(payload.percent, payload.processed, payload.total, payload.sent, payload.failed);

        if (demoCancelRequested) {
            throw new DOMException('Aborted', 'AbortError');
        }

        if (payload.done) {
            return payload;
        }

        return runDemoBatch(payload.job_id || jobId);
    };

    if (demoForm) {
        demoForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            demoBasePayload = new FormData(demoForm);
            if (demoSubmit) {
                demoSubmit.textContent = 'Enviando...';
            }
            if (demoCancel) {
                demoCancel.classList.remove('d-none');
            }
            demoCancelRequested = false;
            demoAbortController = new AbortController();
            setDemoEnabled(false);
            updateDemoProgress(0, 0, 0, 0, 0);
            demoTimerStart = Date.now();
            if (demoTimerInterval) {
                clearInterval(demoTimerInterval);
            }
            demoTimerInterval = setInterval(updateDemoTimer, 1000);

            try {
                const result = await runDemoBatch();
                if (demoTimerInterval) {
                    clearInterval(demoTimerInterval);
                    demoTimerInterval = null;
                }
                demoAbortController = null;
                if (demoCancel) {
                    demoCancel.classList.add('d-none');
                }

                if (result && Number(result.failed || 0) > 0) {
                    if (demoStatus) {
                        demoStatus.textContent = result.message || 'El envio demo termino con errores.';
                    }
                    setDemoEnabled(true);
                    if (demoSubmit) {
                        demoSubmit.textContent = 'Enviar demo (máx. 10)';
                    }
                    return;
                }

                const periodo = demoForm.querySelector('input[name="periodo"]')?.value || '';
                const url = new URL(window.location.href);
                url.hash = 'paso-6';
                if (periodo !== '') {
                    url.searchParams.set('periodo', periodo);
                }
                window.location.href = url.toString();
            } catch (error) {
                const isAbort = error instanceof DOMException && error.name === 'AbortError';
                const message = isAbort
                    ? 'Envío cancelado.'
                    : (error instanceof Error ? error.message : 'No fue posible enviar el demo.');
                if (demoStatus) {
                    demoStatus.textContent = message;
                }
                if (demoTimerInterval) {
                    clearInterval(demoTimerInterval);
                    demoTimerInterval = null;
                }
                demoAbortController = null;
                setDemoEnabled(true);
                if (demoSubmit) {
                    demoSubmit.textContent = 'Enviar demo (máx. 10)';
                }
                if (demoCancel) {
                    demoCancel.classList.add('d-none');
                }
            }
        });
    }

    if (demoCancel) {
        demoCancel.addEventListener('click', () => {
            demoCancelRequested = true;
            if (demoAbortController) {
                demoAbortController.abort();
            }
        });
    }

    const clientUtcOffsetMinutes = new Date().getTimezoneOffset();
    document.querySelectorAll('form input[name="lote_client_utc_offset_min"]').forEach((input) => {
        if (input instanceof HTMLInputElement) {
            input.value = String(clientUtcOffsetMinutes);
        }
    });

    const toDatetimeLocalValue = (date) => {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const hh = String(date.getHours()).padStart(2, '0');
        const mm = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${hh}:${mm}`;
    };

    const localDefaultProgramado = new Date(Date.now() + (15 * 60 * 1000));
    document.querySelectorAll('input[name="lote_programado_para"]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        input.value = toDatetimeLocalValue(localDefaultProgramado);
    });

    const localDateTimeFormatter = new Intl.DateTimeFormat('es-CL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const sqlServerUtcOffsetMinutes = <?php echo json_encode((int) $sqlServerUtcOffsetMinutes); ?>;
    document.querySelectorAll('[data-programado-local][data-programado-utc]').forEach((cell) => {
        if (!(cell instanceof HTMLElement)) {
            return;
        }
        const raw = String(cell.dataset.programadoUtc || '').trim();
        if (raw === '') {
            return;
        }
        const normalized = raw.replace(' ', 'T');
        const dbLocalAsUtc = new Date(normalized.endsWith('Z') ? normalized : `${normalized}Z`);
        if (Number.isNaN(dbLocalAsUtc.getTime())) {
            return;
        }
        // `programado_para` se guarda en huso del SQL Server (sin zona). Convertimos:
        // db_local -> UTC -> local navegador.
        const utcMillis = dbLocalAsUtc.getTime() - (sqlServerUtcOffsetMinutes * 60 * 1000);
        cell.textContent = localDateTimeFormatter.format(new Date(utcMillis)).replace(',', '');
    });

    setWizardStep(focusStep);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__) . '/templates/components/confirm_action_modal.php'; ?>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
