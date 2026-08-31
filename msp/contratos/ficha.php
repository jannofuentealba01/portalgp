<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$loadError = null;

$idContratoArriendo = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'Debes indicar un contrato válido para ver la ficha.');
    msp2Redirect('contratos/index.php');
}

$lineasPermitidasTimeline = [25, 50, 100, 200];
$lineasTimeline = isset($_GET['lineas_timeline']) && is_numeric($_GET['lineas_timeline'])
    ? (int) $_GET['lineas_timeline']
    : 50;
if (!in_array($lineasTimeline, $lineasPermitidasTimeline, true)) {
    $lineasTimeline = 50;
}

$lineasPermitidasDocumentos = [10, 25, 50, 100];
$lineasDocumentos = isset($_GET['lineas_documentos']) && is_numeric($_GET['lineas_documentos'])
    ? (int) $_GET['lineas_documentos']
    : 25;
if (!in_array($lineasDocumentos, $lineasPermitidasDocumentos, true)) {
    $lineasDocumentos = 25;
}

$paginaTimeline = isset($_GET['pagina_timeline']) && is_numeric($_GET['pagina_timeline'])
    ? max(1, (int) $_GET['pagina_timeline'])
    : 1;
$paginaDocumentos = isset($_GET['pagina_documentos']) && is_numeric($_GET['pagina_documentos'])
    ? max(1, (int) $_GET['pagina_documentos'])
    : 1;

$queryBase = $_GET;
unset($queryBase['pagina_timeline'], $queryBase['pagina_documentos']);

function buildMsp2FichaQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2FichaFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d-m-Y');
    } catch (Throwable) {
        return '-';
    }
}

function msp2FichaFmtFechaHora(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d-m-Y H:i');
    } catch (Throwable) {
        return '-';
    }
}

function msp2FichaFmtPeriodo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('m-Y');
    } catch (Throwable) {
        return '-';
    }
}

function msp2FichaFmtMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 0, ',', '.');
}

function msp2FichaFmtNumero(mixed $value, int $decimals = 2): string
{
    return number_format((float) ($value ?? 0), max(0, $decimals), ',', '.');
}

function msp2FichaFmtUf(mixed $value, int $decimals = 2): string
{
    return 'UF ' . msp2FichaFmtNumero($value, $decimals);
}

function msp2FichaEstadoContratoBadge(int $estado): array
{
    return match ($estado) {
        1 => ['Borrador', 'text-bg-secondary'],
        2 => ['Vigente', 'text-bg-success'],
        3 => ['En proceso de cierre', 'text-bg-warning text-dark'],
        4 => ['Terminado', 'text-bg-dark'],
        5 => ['Anulado', 'text-bg-danger'],
        default => ['Desconocido', 'text-bg-secondary'],
    };
}

function msp2FichaEstadoDocumentoBadge(int $estado): array
{
    return match ($estado) {
        1 => ['Borrador', 'text-bg-secondary'],
        2 => ['Emitido', 'text-bg-primary'],
        3 => ['Pagado parcial', 'text-bg-warning text-dark'],
        4 => ['Pagado', 'text-bg-success'],
        5 => ['Anulado', 'text-bg-danger'],
        default => ['N/D', 'text-bg-secondary'],
    };
}

function msp2FichaEstadoPagoBadge(int $estado): array
{
    return match ($estado) {
        1 => ['Aplicado', 'text-bg-success'],
        2 => ['Anulado', 'text-bg-secondary'],
        default => ['N/D', 'text-bg-secondary'],
    };
}

function msp2FichaEstadoAsientoBadge(int $estado): array
{
    return match ($estado) {
        1 => ['Contabilizado', 'text-bg-success'],
        2 => ['Reversado', 'text-bg-secondary'],
        3 => ['Reversa', 'text-bg-warning text-dark'],
        default => ['N/D', 'text-bg-secondary'],
    };
}

function msp2FichaParseJsonMaybe(string $raw): ?array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return null;
    }

    try {
        $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable) {
        return null;
    }
}

function msp2FichaAddTimelineEvent(array &$events, array $event): void
{
    $fechaRaw = trim((string) ($event['fecha_evento'] ?? ''));
    $sortTs = 0;
    if ($fechaRaw !== '') {
        try {
            $sortTs = (new DateTimeImmutable($fechaRaw))->getTimestamp();
        } catch (Throwable) {
            $sortTs = 0;
        }
    }

    $events[] = [
        'fecha_evento' => $fechaRaw,
        'tipo_evento' => (string) ($event['tipo_evento'] ?? 'EVENTO'),
        'titulo' => (string) ($event['titulo'] ?? 'Evento'),
        'detalle' => (string) ($event['detalle'] ?? ''),
        'origen' => strtoupper(trim((string) ($event['origen'] ?? 'SISTEMA'))),
        'id_referencia' => (int) ($event['id_referencia'] ?? 0),
        'metadata' => is_array($event['metadata'] ?? null) ? $event['metadata'] : [],
        'es_evento_derivado' => (bool) ($event['es_evento_derivado'] ?? false),
        'sort_ts' => $sortTs,
    ];
}

function msp2FichaResolveUsuarioLabels(PDO $conn, array $userIds): array
{
    $labels = [];
    if ($userIds === [] || !msp2TableExists($conn, 'cr_usuarios')) {
        return $labels;
    }

    $idCol = null;
    foreach (['id', 'id_usuario', 'Id'] as $candidate) {
        if (msp2ColumnExists($conn, 'cr_usuarios', $candidate)) {
            $idCol = $candidate;
            break;
        }
    }
    if ($idCol === null) {
        return $labels;
    }

    $nombreCol = null;
    foreach (['nombre_completo', 'nombre', 'usuario', 'username'] as $candidate) {
        if (msp2ColumnExists($conn, 'cr_usuarios', $candidate)) {
            $nombreCol = $candidate;
            break;
        }
    }

    $nombresCol = null;
    foreach (['nombres', 'primer_nombre'] as $candidate) {
        if (msp2ColumnExists($conn, 'cr_usuarios', $candidate)) {
            $nombresCol = $candidate;
            break;
        }
    }

    $apellidosCol = null;
    foreach (['apellidos', 'apellido', 'apellido_paterno'] as $candidate) {
        if (msp2ColumnExists($conn, 'cr_usuarios', $candidate)) {
            $apellidosCol = $candidate;
            break;
        }
    }

    $rolFkCol = null;
    foreach (['rol_id', 'id_rol'] as $candidate) {
        if (msp2ColumnExists($conn, 'cr_usuarios', $candidate)) {
            $rolFkCol = $candidate;
            break;
        }
    }

    $rolTable = null;
    if (msp2TableExists($conn, 'cr_roles')) {
        $rolTable = 'cr_roles';
    } elseif (msp2TableExists($conn, 'cr_rol')) {
        $rolTable = 'cr_rol';
    }

    $rolIdCol = null;
    $rolNombreCol = null;
    if ($rolTable !== null && $rolFkCol !== null) {
        foreach (['id', 'id_rol', 'Id'] as $candidate) {
            if (msp2ColumnExists($conn, $rolTable, $candidate)) {
                $rolIdCol = $candidate;
                break;
            }
        }
        foreach (['nombre_rol', 'rol', 'nombre', 'descripcion'] as $candidate) {
            if (msp2ColumnExists($conn, $rolTable, $candidate)) {
                $rolNombreCol = $candidate;
                break;
            }
        }
    }

    $userIds = array_values(array_unique(array_filter(array_map(static fn ($v): int => (int) $v, $userIds), static fn (int $v): bool => $v > 0)));
    if ($userIds === []) {
        return $labels;
    }

    $placeholders = [];
    foreach ($userIds as $index => $_id) {
        $placeholders[] = ':id_usuario_' . $index;
    }

    $selectNombre = $nombreCol !== null
        ? 'u.' . $nombreCol . ' AS nombre_simple'
        : 'CAST(NULL AS NVARCHAR(200)) AS nombre_simple';
    $selectNombres = $nombresCol !== null
        ? 'u.' . $nombresCol . ' AS nombres_usuario'
        : 'CAST(NULL AS NVARCHAR(200)) AS nombres_usuario';
    $selectApellidos = $apellidosCol !== null
        ? 'u.' . $apellidosCol . ' AS apellidos_usuario'
        : 'CAST(NULL AS NVARCHAR(200)) AS apellidos_usuario';
    $selectRol = ($rolTable !== null && $rolIdCol !== null && $rolNombreCol !== null && $rolFkCol !== null)
        ? 'r.' . $rolNombreCol . ' AS rol_usuario'
        : 'CAST(NULL AS NVARCHAR(120)) AS rol_usuario';
    $joinRol = ($rolTable !== null && $rolIdCol !== null && $rolNombreCol !== null && $rolFkCol !== null)
        ? 'LEFT JOIN dbo.' . $rolTable . ' r
            ON r.' . $rolIdCol . ' = u.' . $rolFkCol
        : '';

    $stmtUsuarios = $conn->prepare(
        "SELECT
            u.{$idCol} AS id_usuario,
            {$selectNombre},
            {$selectNombres},
            {$selectApellidos},
            {$selectRol}
         FROM dbo.cr_usuarios u
         {$joinRol}
         WHERE u.{$idCol} IN (" . implode(', ', $placeholders) . ')'
    );
    foreach ($userIds as $index => $idUsuario) {
        $stmtUsuarios->bindValue(':id_usuario_' . $index, $idUsuario, PDO::PARAM_INT);
    }
    $stmtUsuarios->execute();
    while (($rowUsuario = $stmtUsuarios->fetch()) !== false) {
        $idUsuario = (int) ($rowUsuario['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        $nombreSimple = trim((string) ($rowUsuario['nombre_simple'] ?? ''));
        $nombreCompuesto = trim(
            trim((string) ($rowUsuario['nombres_usuario'] ?? ''))
            . ' '
            . trim((string) ($rowUsuario['apellidos_usuario'] ?? ''))
        );
        $rol = trim((string) ($rowUsuario['rol_usuario'] ?? ''));

        $nombreBase = $nombreSimple !== '' ? $nombreSimple : $nombreCompuesto;
        if ($nombreBase === '') {
            $nombreBase = 'Usuario #' . $idUsuario;
        }

        $labels[$idUsuario] = $rol !== '' ? ($nombreBase . ' (' . $rol . ')') : $nombreBase;
    }

    return $labels;
}

$contrato = null;
$localesContrato = [];
$resumenDeuda = [
    'documentos' => 0,
    'monto_total' => 0.0,
    'saldo_pendiente' => 0.0,
    'pagado' => 0.0,
];
$resumenGarantia = [
    'registros' => 0,
    'monto_pactado' => 0.0,
    'monto_recibido' => 0.0,
    'monto_pendiente_recepcion' => 0.0,
    'monto_disponible' => 0.0,
    'monto_reservado' => 0.0,
    'monto_aplicado' => 0.0,
    'monto_devuelto' => 0.0,
];
$timeline = [];
$timelinePageRows = [];
$totalTimeline = 0;
$totalPaginasTimeline = 1;
$timelinePaginationItems = [];

$documentos = [];
$documentosInfo = [];
$totalDocumentos = 0;
$totalPaginasDocumentos = 1;
$documentosPaginationItems = [];

$pagosMovimientos = [];
$asientosContables = [];
$asientosDetalleByAsiento = [];
$asientosById = [];
$arriendoDetalleRows = [];
$arriendoModalidadResumen = [];
$arrendatariosTraspaso = [];

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_contrato_locales',
        'msp_locales',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para la ficha de contrato: `' . implode('`, `', $missingTables) . '`.');
    }

    $tieneFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $tieneRubroContrato = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'rubro_contrato');

    $selectTerminoEfectiva = $tieneFechaTerminoEfectiva
        ? 'c.fecha_termino_efectiva'
        : 'CAST(NULL AS DATE) AS fecha_termino_efectiva';
    $selectRubroContrato = $tieneRubroContrato
        ? 'c.rubro_contrato'
        : 'CAST(NULL AS NVARCHAR(150)) AS rubro_contrato';

    $stmtContrato = $conn->prepare(
        "SELECT
            c.id_contrato_arriendo,
            c.id_tienda,
            c.id_arrendatario,
            c.fecha_inicio,
            c.fecha_termino_pactada,
            {$selectTerminoEfectiva},
            c.estado_contrato,
            c.monto_arriendo_pactado,
            {$selectRubroContrato},
            t.nombre_comercial,
            a.rut,
            a.nombre_locatario
         FROM dbo.msp_contratos_arriendo c
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = c.id_tienda
         INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = c.id_arrendatario
         WHERE c.id_contrato_arriendo = :id_contrato"
    );
    $stmtContrato->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch() ?: null;
    if (!is_array($contrato)) {
        throw new RuntimeException('El contrato solicitado no existe o no está disponible.');
    }

    $idTiendaContrato = (int) ($contrato['id_tienda'] ?? 0);
    $idArrendatarioContrato = (int) ($contrato['id_arrendatario'] ?? 0);

    $stmtArrendatariosTraspaso = $conn->prepare(
        'SELECT id_arrendatario, rut, nombre_locatario
         FROM dbo.msp_arrendatarios
         WHERE id_arrendatario <> :id_arrendatario_actual
         ORDER BY nombre_locatario ASC, id_arrendatario ASC'
    );
    $stmtArrendatariosTraspaso->bindValue(':id_arrendatario_actual', $idArrendatarioContrato, PDO::PARAM_INT);
    $stmtArrendatariosTraspaso->execute();
    $arrendatariosTraspaso = $stmtArrendatariosTraspaso->fetchAll() ?: [];

    $stmtLocales = $conn->prepare(
        'SELECT
            cl.id_contrato_local,
            cl.id_local,
            cl.fecha_inicio,
            cl.fecha_termino,
            cl.estado_relacion,
            l.cdo_local,
            l.desc_local
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         WHERE cl.id_contrato_arriendo = :id_contrato
         ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ', cl.id_contrato_local ASC'
    );
    $stmtLocales->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
    $stmtLocales->execute();
    $localesContrato = $stmtLocales->fetchAll() ?: [];

    $moduloArriendoReglasDisponible =
        msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
        && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo');
    if ($moduloArriendoReglasDisponible) {
        $tieneMetrosLocal = msp2ColumnExists($conn, 'msp_locales', 'metros_cuadrados');
        $selectMetrosLocal = $tieneMetrosLocal
            ? 'l.metros_cuadrados'
            : 'CAST(NULL AS DECIMAL(18,2)) AS metros_cuadrados';
        $stmtArriendoDetalle = $conn->prepare(
            'SELECT
                cl.id_contrato_local,
                cl.estado_relacion,
                l.cdo_local,
                ' . $selectMetrosLocal . ',
                rr.id_modalidad_arriendo,
                tm.codigo_modalidad,
                tm.nombre_modalidad,
                rr.valor_base_uf,
                rr.valor_base_clp,
                rr.descuento_mensual_clp
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l
                ON l.id_local = cl.id_local
             LEFT JOIN dbo.msp_contrato_local_arriendo_regla rr
                ON rr.id_contrato_local = cl.id_contrato_local
               AND rr.es_default = 1
               AND rr.estado_regla = 1
             LEFT JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
             WHERE cl.id_contrato_arriendo = :id_contrato
             ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ', cl.id_contrato_local ASC'
        );
        $stmtArriendoDetalle->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtArriendoDetalle->execute();
        $arriendoDetalleRows = $stmtArriendoDetalle->fetchAll() ?: [];

        foreach ($arriendoDetalleRows as $arriendoRow) {
            if ((int) ($arriendoRow['estado_relacion'] ?? 0) !== 1) {
                continue;
            }
            $codigoModalidad = strtoupper(trim((string) ($arriendoRow['codigo_modalidad'] ?? '')));
            if ($codigoModalidad === '') {
                $codigoModalidad = 'SIN_REGLA';
            }
            if (!isset($arriendoModalidadResumen[$codigoModalidad])) {
                $arriendoModalidadResumen[$codigoModalidad] = 0;
            }
            $arriendoModalidadResumen[$codigoModalidad]++;
        }
    }

    $tieneDocumentos = msp2TableExists($conn, 'msp_documentos_cobro');
    $tienePagos = msp2TableExists($conn, 'msp_pagos');
    $tieneDetalleDocumentos = msp2TableExists($conn, 'msp_documentos_cobro_detalle');
    $tieneTipoItem = msp2TableExists($conn, 'msp_tipo_item_documento');
    $tieneCargosSalida = msp2TableExists($conn, 'msp_cargos_salida');
    $tieneTipoCargoSalida = msp2TableExists($conn, 'msp_tipos_cargo_salida');

    $documentosTieneContrato = $tieneDocumentos && msp2ColumnExists($conn, 'msp_documentos_cobro', 'id_contrato_arriendo');

    if ($tieneDocumentos) {
        $whereDocumentosContrato = $documentosTieneContrato
            ? 'dc.id_contrato_arriendo = :id_contrato'
            : 'dc.id_tienda = :id_tienda';

        $stmtDocsResumen = $conn->prepare(
            "WITH pagos_doc AS (
                SELECT
                    p.id_documento_cobro,
                    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
                FROM dbo.msp_pagos p
                GROUP BY p.id_documento_cobro
            )
            SELECT
                COUNT(*) AS total_documentos,
                ROUND(ISNULL(SUM(dc.monto_total), 0), 2) AS monto_total,
                ROUND(ISNULL(SUM(dc.saldo_pendiente), 0), 2) AS saldo_pendiente,
                ROUND(ISNULL(SUM(ISNULL(pg.total_pagado, 0)), 0), 2) AS total_pagado
            FROM dbo.msp_documentos_cobro dc
            LEFT JOIN pagos_doc pg
                ON pg.id_documento_cobro = dc.id_documento_cobro
            WHERE {$whereDocumentosContrato}"
        );
        if ($documentosTieneContrato) {
            $stmtDocsResumen->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtDocsResumen->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtDocsResumen->execute();
        $rowResumenDocs = $stmtDocsResumen->fetch() ?: null;
        if (is_array($rowResumenDocs)) {
            $resumenDeuda['documentos'] = (int) ($rowResumenDocs['total_documentos'] ?? 0);
            $resumenDeuda['monto_total'] = (float) ($rowResumenDocs['monto_total'] ?? 0);
            $resumenDeuda['saldo_pendiente'] = (float) ($rowResumenDocs['saldo_pendiente'] ?? 0);
            $resumenDeuda['pagado'] = (float) ($rowResumenDocs['total_pagado'] ?? 0);
        }

        $stmtCountDocumentos = $conn->prepare(
            "SELECT COUNT(*)
             FROM dbo.msp_documentos_cobro dc
             WHERE {$whereDocumentosContrato}"
        );
        if ($documentosTieneContrato) {
            $stmtCountDocumentos->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtCountDocumentos->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtCountDocumentos->execute();
        $totalDocumentos = (int) $stmtCountDocumentos->fetchColumn();
        $totalPaginasDocumentos = max(1, (int) ceil($totalDocumentos / $lineasDocumentos));
        $paginaDocumentos = min($paginaDocumentos, $totalPaginasDocumentos);
        $offsetDocumentos = ($paginaDocumentos - 1) * $lineasDocumentos;

        $stmtDocumentos = $conn->prepare(
            "SELECT
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.fecha_emision,
                dc.fecha_vencimiento,
                dc.fecha_registro,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.subtotal_arriendo,
                dc.subtotal_servicios,
                dc.estado_documento,
                dc.observaciones
             FROM dbo.msp_documentos_cobro dc
             WHERE {$whereDocumentosContrato}
             ORDER BY dc.periodo_facturacion DESC, dc.id_documento_cobro DESC
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );
        if ($documentosTieneContrato) {
            $stmtDocumentos->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtDocumentos->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtDocumentos->bindValue(':offset', $offsetDocumentos, PDO::PARAM_INT);
        $stmtDocumentos->bindValue(':lineas', $lineasDocumentos, PDO::PARAM_INT);
        $stmtDocumentos->execute();
        $documentos = $stmtDocumentos->fetchAll() ?: [];

        $idsDocumentoPagina = [];
        foreach ($documentos as $doc) {
            $idDoc = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($idDoc > 0) {
                $idsDocumentoPagina[] = $idDoc;
                $documentosInfo[$idDoc] = [
                    'pagos_aplicados' => 0,
                    'pagos_total' => 0.0,
                    'tiene_evento_recalculo' => false,
                    'tiene_evento_condonacion' => false,
                    'cargos_aplicados' => 0,
                    'cargos_condonados' => 0,
                    'envios' => 0,
                    'ultimo_lote' => null,
                ];
            }
        }

        if ($idsDocumentoPagina !== []) {
            $placeholdersDocs = [];
            foreach ($idsDocumentoPagina as $index => $_id) {
                $placeholdersDocs[] = ':doc_' . $index;
            }

            if ($tienePagos) {
                $stmtPagosResumen = $conn->prepare(
                    'SELECT
                        p.id_documento_cobro,
                        SUM(CASE WHEN p.estado_pago = 1 THEN 1 ELSE 0 END) AS pagos_aplicados,
                        ROUND(SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END), 2) AS total_pagado
                     FROM dbo.msp_pagos p
                     WHERE p.id_documento_cobro IN (' . implode(', ', $placeholdersDocs) . ')
                     GROUP BY p.id_documento_cobro'
                );
                foreach ($idsDocumentoPagina as $index => $_id) {
                    $stmtPagosResumen->bindValue(':doc_' . $index, $_id, PDO::PARAM_INT);
                }
                $stmtPagosResumen->execute();
                while (($rowPagoRes = $stmtPagosResumen->fetch()) !== false) {
                    $idDoc = (int) ($rowPagoRes['id_documento_cobro'] ?? 0);
                    if (!isset($documentosInfo[$idDoc])) {
                        continue;
                    }
                    $documentosInfo[$idDoc]['pagos_aplicados'] = (int) ($rowPagoRes['pagos_aplicados'] ?? 0);
                    $documentosInfo[$idDoc]['pagos_total'] = (float) ($rowPagoRes['total_pagado'] ?? 0);
                }
            }

            if ($tieneCargosSalida) {
                $stmtCargosResumen = $conn->prepare(
                    'SELECT
                        cs.id_documento_cobro,
                        SUM(CASE WHEN cs.estado_cargo = 3 THEN 1 ELSE 0 END) AS cargos_aplicados,
                        SUM(CASE WHEN cs.estado_cargo = 5 THEN 1 ELSE 0 END) AS cargos_condonados
                     FROM dbo.msp_cargos_salida cs
                     WHERE cs.id_documento_cobro IN (' . implode(', ', $placeholdersDocs) . ')
                     GROUP BY cs.id_documento_cobro'
                );
                foreach ($idsDocumentoPagina as $index => $_id) {
                    $stmtCargosResumen->bindValue(':doc_' . $index, $_id, PDO::PARAM_INT);
                }
                $stmtCargosResumen->execute();
                while (($rowCargoRes = $stmtCargosResumen->fetch()) !== false) {
                    $idDoc = (int) ($rowCargoRes['id_documento_cobro'] ?? 0);
                    if (!isset($documentosInfo[$idDoc])) {
                        continue;
                    }
                    $documentosInfo[$idDoc]['cargos_aplicados'] = (int) ($rowCargoRes['cargos_aplicados'] ?? 0);
                    $documentosInfo[$idDoc]['cargos_condonados'] = (int) ($rowCargoRes['cargos_condonados'] ?? 0);
                    if ((int) ($rowCargoRes['cargos_condonados'] ?? 0) > 0) {
                        $documentosInfo[$idDoc]['tiene_evento_condonacion'] = true;
                    }
                }
            }

            $tieneEventosCanonicos = msp2TableExists($conn, 'msp_documentos_cobro_eventos');
            if ($tieneEventosCanonicos) {
                $tieneColTipoEvento = msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'tipo_evento');
                if ($tieneColTipoEvento) {
                    $stmtEventosDocs = $conn->prepare(
                        'SELECT
                            e.id_documento_cobro,
                            UPPER(LTRIM(RTRIM(ISNULL(e.tipo_evento, N\'\')))) AS tipo_evento
                         FROM dbo.msp_documentos_cobro_eventos e
                         WHERE e.id_documento_cobro IN (' . implode(', ', $placeholdersDocs) . ')'
                    );
                    foreach ($idsDocumentoPagina as $index => $_id) {
                        $stmtEventosDocs->bindValue(':doc_' . $index, $_id, PDO::PARAM_INT);
                    }
                    $stmtEventosDocs->execute();
                    while (($rowEventoDoc = $stmtEventosDocs->fetch()) !== false) {
                        $idDoc = (int) ($rowEventoDoc['id_documento_cobro'] ?? 0);
                        if (!isset($documentosInfo[$idDoc])) {
                            continue;
                        }
                        $tipoEvento = strtoupper(trim((string) ($rowEventoDoc['tipo_evento'] ?? '')));
                        if (in_array($tipoEvento, ['RECALCULO', 'REGENERACION', 'AJUSTE'], true)) {
                            $documentosInfo[$idDoc]['tiene_evento_recalculo'] = true;
                        }
                        if (in_array($tipoEvento, ['CONDONACION', 'CONDONACION_CARGO'], true)) {
                            $documentosInfo[$idDoc]['tiene_evento_condonacion'] = true;
                        }
                    }
                }
            }

            $tieneEnvioLotes = msp2TableExists($conn, 'msp_envio_lotes_programados')
                && msp2TableExists($conn, 'msp_envio_lote_destinatarios')
                && msp2TableExists($conn, 'msp_envio_lote_documentos');
            if ($tieneEnvioLotes) {
                $stmtEnvioResumen = $conn->prepare(
                    'SELECT
                        eld.id_documento_cobro,
                        COUNT(*) AS total_envios,
                        MAX(l.id_lote_envio) AS ultimo_lote
                     FROM dbo.msp_envio_lote_documentos eld
                     INNER JOIN dbo.msp_envio_lote_destinatarios d
                        ON d.id_lote_destinatario = eld.id_lote_destinatario
                     INNER JOIN dbo.msp_envio_lotes_programados l
                        ON l.id_lote_envio = d.id_lote_envio
                     WHERE eld.id_documento_cobro IN (' . implode(', ', $placeholdersDocs) . ')
                     GROUP BY eld.id_documento_cobro'
                );
                foreach ($idsDocumentoPagina as $index => $_id) {
                    $stmtEnvioResumen->bindValue(':doc_' . $index, $_id, PDO::PARAM_INT);
                }
                $stmtEnvioResumen->execute();
                while (($rowEnvioRes = $stmtEnvioResumen->fetch()) !== false) {
                    $idDoc = (int) ($rowEnvioRes['id_documento_cobro'] ?? 0);
                    if (!isset($documentosInfo[$idDoc])) {
                        continue;
                    }
                    $documentosInfo[$idDoc]['envios'] = (int) ($rowEnvioRes['total_envios'] ?? 0);
                    $ultimoLote = (int) ($rowEnvioRes['ultimo_lote'] ?? 0);
                    $documentosInfo[$idDoc]['ultimo_lote'] = $ultimoLote > 0 ? $ultimoLote : null;
                }
            }
        }
    }

    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtGarantia = $conn->prepare(
            'SELECT
                COUNT(*) AS registros,
                ROUND(ISNULL(SUM(monto_pactado), 0), 2) AS monto_pactado,
                ROUND(ISNULL(SUM(monto_recibido), 0), 2) AS monto_recibido,
                ROUND(ISNULL(SUM(monto_pendiente_recepcion), 0), 2) AS monto_pendiente_recepcion,
                ROUND(ISNULL(SUM(monto_disponible), 0), 2) AS monto_disponible,
                ROUND(ISNULL(SUM(monto_reservado), 0), 2) AS monto_reservado,
                ROUND(ISNULL(SUM(monto_aplicado), 0), 2) AS monto_aplicado,
                ROUND(ISNULL(SUM(monto_devuelto), 0), 2) AS monto_devuelto
             FROM dbo.msp_vw_garantias_control_integral
             WHERE id_contrato_arriendo = :id_contrato'
        );
        $stmtGarantia->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtGarantia->execute();
        $rowGarantia = $stmtGarantia->fetch() ?: null;
        if (is_array($rowGarantia)) {
            $resumenGarantia['registros'] = (int) ($rowGarantia['registros'] ?? 0);
            foreach (['monto_pactado','monto_recibido','monto_pendiente_recepcion','monto_disponible','monto_reservado','monto_aplicado','monto_devuelto'] as $campoGarantia) {
                $resumenGarantia[$campoGarantia] = (float) ($rowGarantia[$campoGarantia] ?? 0);
            }
        }
    }

    if ($tieneDocumentos && $tienePagos) {
        $pagosTieneAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
        $pagosTieneMontoSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');

        $selectAplicaSaldoFavor = $pagosTieneAplicaSaldoFavor
            ? 'p.aplica_desde_saldo_favor'
            : 'CAST(0 AS BIT) AS aplica_desde_saldo_favor';
        $selectMontoSaldoFavor = $pagosTieneMontoSaldoFavor
            ? 'p.monto_saldo_favor_generado'
            : 'CAST(0 AS DECIMAL(18,2)) AS monto_saldo_favor_generado';

        $wherePagosContrato = $documentosTieneContrato
            ? 'dc.id_contrato_arriendo = :id_contrato'
            : 'dc.id_tienda = :id_tienda';

        $stmtPagosMov = $conn->prepare(
            "SELECT TOP (300)
                p.id_pago,
                p.id_documento_cobro,
                p.fecha_pago,
                p.fecha_registro,
                p.monto_pagado,
                p.estado_pago,
                {$selectAplicaSaldoFavor},
                {$selectMontoSaldoFavor},
                p.medio_pago,
                p.referencia_pago,
                p.observaciones,
                p.fecha_anulacion,
                p.motivo_anulacion,
                dc.numero_documento,
                dc.periodo_facturacion,
                dc.saldo_pendiente
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE {$wherePagosContrato}
             ORDER BY p.fecha_pago DESC, p.id_pago DESC"
        );
        if ($documentosTieneContrato) {
            $stmtPagosMov->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtPagosMov->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtPagosMov->execute();
        $pagosMovimientos = $stmtPagosMov->fetchAll() ?: [];

        if ($pagosMovimientos !== [] && msp2TableExists($conn, 'msp_movimientos_garantia')) {
            $idsPago = [];
            foreach ($pagosMovimientos as $rowPago) {
                $idPago = (int) ($rowPago['id_pago'] ?? 0);
                if ($idPago > 0) {
                    $idsPago[] = $idPago;
                }
            }
            $idsPago = array_values(array_unique($idsPago));
            if ($idsPago !== []) {
                $placeholdersPago = [];
                foreach ($idsPago as $index => $_id) {
                    $placeholdersPago[] = ':id_pago_' . $index;
                }
                $stmtMovGar = $conn->prepare(
                    'SELECT
                        mg.id_pago,
                        mg.id_garantia,
                        ROUND(SUM(mg.monto_movimiento), 2) AS monto_aplicado_garantia
                     FROM dbo.msp_movimientos_garantia mg
                     WHERE mg.id_pago IN (' . implode(', ', $placeholdersPago) . ')
                       AND mg.fondo_origen = N\'D\'
                     GROUP BY mg.id_pago, mg.id_garantia'
                );
                foreach ($idsPago as $index => $_id) {
                    $stmtMovGar->bindValue(':id_pago_' . $index, $_id, PDO::PARAM_INT);
                }
                $stmtMovGar->execute();
                $garantiaByPago = [];
                while (($movGar = $stmtMovGar->fetch()) !== false) {
                    $idPago = (int) ($movGar['id_pago'] ?? 0);
                    if ($idPago <= 0) {
                        continue;
                    }
                    $garantiaByPago[$idPago][] = [
                        'id_garantia' => (int) ($movGar['id_garantia'] ?? 0),
                        'monto' => (float) ($movGar['monto_aplicado_garantia'] ?? 0),
                    ];
                }

                foreach ($pagosMovimientos as $index => $rowPago) {
                    $idPago = (int) ($rowPago['id_pago'] ?? 0);
                    $pagosMovimientos[$index]['garantias_aplicadas'] = $garantiaByPago[$idPago] ?? [];
                }
            }
        }
    }

    if (
        msp2TableExists($conn, 'msp_acc_asientos')
        && msp2TableExists($conn, 'msp_acc_asientos_detalle')
        && msp2TableExists($conn, 'msp_acc_tipos_movimiento')
    ) {
        $whereDocDirect = $documentosTieneContrato
            ? 'dc_dir.id_contrato_arriendo = :id_contrato_doc_directo'
            : 'dc_dir.id_tienda = :id_tienda_doc_directo';
        $whereDocPago = $documentosTieneContrato
            ? 'dc_pago.id_contrato_arriendo = :id_contrato_doc_pago'
            : 'dc_pago.id_tienda = :id_tienda_doc_pago';

        $stmtAsientos = $conn->prepare(
            "SELECT TOP (250)
                a.id_asiento_contable,
                a.fecha_contable,
                a.fecha_registro AS fecha_asiento_registro,
                a.glosa,
                a.estado_asiento,
                a.tabla_origen,
                a.id_origen,
                tm.codigo_movimiento,
                ROUND(SUM(d.debe), 2) AS total_debe,
                ROUND(SUM(d.haber), 2) AS total_haber,
                MAX(d.id_documento_cobro) AS id_documento_cobro_ref,
                MAX(d.id_pago) AS id_pago_ref,
                MAX(d.id_garantia) AS id_garantia_ref
             FROM dbo.msp_acc_asientos a
             INNER JOIN dbo.msp_acc_tipos_movimiento tm
                ON tm.id_tipo_movimiento = a.id_tipo_movimiento
             INNER JOIN dbo.msp_acc_asientos_detalle d
                ON d.id_asiento_contable = a.id_asiento_contable
             LEFT JOIN dbo.msp_documentos_cobro dc_dir
                ON dc_dir.id_documento_cobro = d.id_documento_cobro
             LEFT JOIN dbo.msp_pagos p_det
                ON p_det.id_pago = d.id_pago
             LEFT JOIN dbo.msp_documentos_cobro dc_pago
                ON dc_pago.id_documento_cobro = p_det.id_documento_cobro
             LEFT JOIN dbo.msp_garantias g_det
                ON g_det.id_garantia = d.id_garantia
             WHERE (
                {$whereDocDirect}
                OR {$whereDocPago}
                OR g_det.id_contrato_arriendo = :id_contrato_garantia
                OR (
                    a.tabla_origen = N'msp_documentos_cobro'
                    AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_documentos_cobro d0
                        WHERE d0.id_documento_cobro = a.id_origen
                          AND " . ($documentosTieneContrato ? 'd0.id_contrato_arriendo = :id_contrato_origen_doc' : 'd0.id_tienda = :id_tienda_origen_doc') . "
                    )
                )
                OR (
                    a.tabla_origen = N'msp_pagos'
                    AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_pagos p0
                        INNER JOIN dbo.msp_documentos_cobro d0
                            ON d0.id_documento_cobro = p0.id_documento_cobro
                        WHERE p0.id_pago = a.id_origen
                          AND " . ($documentosTieneContrato ? 'd0.id_contrato_arriendo = :id_contrato_origen_pago' : 'd0.id_tienda = :id_tienda_origen_pago') . "
                    )
                )
                OR (
                    a.tabla_origen = N'msp_cargos_salida'
                    AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_cargos_salida cs0
                        WHERE cs0.id_cargo_salida = a.id_origen
                          AND cs0.id_contrato_arriendo = :id_contrato_origen_cargo
                    )
                )
                OR (
                    a.tabla_origen = N'msp_movimientos_garantia'
                    AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_movimientos_garantia mg0
                        INNER JOIN dbo.msp_garantias g0
                            ON g0.id_garantia = mg0.id_garantia
                        WHERE mg0.id_movimiento_garantia = a.id_origen
                          AND g0.id_contrato_arriendo = :id_contrato_origen_mov_gar
                    )
                )
             )
             GROUP BY
                a.id_asiento_contable,
                a.fecha_contable,
                a.fecha_registro,
                a.glosa,
                a.estado_asiento,
                a.tabla_origen,
                a.id_origen,
                tm.codigo_movimiento
             ORDER BY a.fecha_registro DESC, a.id_asiento_contable DESC"
        );

        if ($documentosTieneContrato) {
            $stmtAsientos->bindValue(':id_contrato_doc_directo', $idContratoArriendo, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_contrato_doc_pago', $idContratoArriendo, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_contrato_origen_doc', $idContratoArriendo, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_contrato_origen_pago', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtAsientos->bindValue(':id_tienda_doc_directo', $idTiendaContrato, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_tienda_doc_pago', $idTiendaContrato, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_tienda_origen_doc', $idTiendaContrato, PDO::PARAM_INT);
            $stmtAsientos->bindValue(':id_tienda_origen_pago', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtAsientos->bindValue(':id_contrato_garantia', $idContratoArriendo, PDO::PARAM_INT);
        $stmtAsientos->bindValue(':id_contrato_origen_cargo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtAsientos->bindValue(':id_contrato_origen_mov_gar', $idContratoArriendo, PDO::PARAM_INT);
        $stmtAsientos->execute();
        $asientosContables = $stmtAsientos->fetchAll() ?: [];
        if ($asientosContables !== []) {
            foreach ($asientosContables as $asientoRow) {
                $idAsientoRow = (int) ($asientoRow['id_asiento_contable'] ?? 0);
                if ($idAsientoRow > 0) {
                    $asientosById[$idAsientoRow] = $asientoRow;
                }
            }
        }

        if ($asientosContables !== []) {
            $idsAsiento = [];
            foreach ($asientosContables as $asientoRow) {
                $idAsientoRow = (int) ($asientoRow['id_asiento_contable'] ?? 0);
                if ($idAsientoRow > 0) {
                    $idsAsiento[] = $idAsientoRow;
                }
            }
            $idsAsiento = array_values(array_unique($idsAsiento));
            if ($idsAsiento !== []) {
                $tienePlanCuentas = msp2TableExists($conn, 'msp_acc_plan_cuentas');
                $selectCodigoCuenta = $tienePlanCuentas
                    ? 'pc.codigo_cuenta'
                    : 'CAST(NULL AS NVARCHAR(30)) AS codigo_cuenta';
                $selectNombreCuenta = $tienePlanCuentas
                    ? 'pc.nombre_cuenta'
                    : 'CAST(NULL AS NVARCHAR(150)) AS nombre_cuenta';
                $joinPlanCuentas = $tienePlanCuentas
                    ? 'LEFT JOIN dbo.msp_acc_plan_cuentas pc
                        ON pc.id_cuenta_contable = d.id_cuenta_contable'
                    : '';

                $placeholdersAsiento = [];
                foreach ($idsAsiento as $indexAsiento => $_idAsiento) {
                    $placeholdersAsiento[] = ':id_asiento_det_' . $indexAsiento;
                }

                $stmtAsientoDetalle = $conn->prepare(
                    "SELECT
                        d.id_asiento_contable,
                        d.linea,
                        d.id_cuenta_contable,
                        {$selectCodigoCuenta},
                        {$selectNombreCuenta},
                        d.debe,
                        d.haber,
                        d.glosa_detalle
                     FROM dbo.msp_acc_asientos_detalle d
                     {$joinPlanCuentas}
                     WHERE d.id_asiento_contable IN (" . implode(', ', $placeholdersAsiento) . ")
                     ORDER BY d.id_asiento_contable DESC, d.linea ASC"
                );
                foreach ($idsAsiento as $indexAsiento => $idAsientoBind) {
                    $stmtAsientoDetalle->bindValue(':id_asiento_det_' . $indexAsiento, $idAsientoBind, PDO::PARAM_INT);
                }
                $stmtAsientoDetalle->execute();
                while (($detalleAsiento = $stmtAsientoDetalle->fetch()) !== false) {
                    $idAsientoDet = (int) ($detalleAsiento['id_asiento_contable'] ?? 0);
                    if ($idAsientoDet <= 0) {
                        continue;
                    }
                    if (!isset($asientosDetalleByAsiento[$idAsientoDet])) {
                        $asientosDetalleByAsiento[$idAsientoDet] = [];
                    }
                    $asientosDetalleByAsiento[$idAsientoDet][] = $detalleAsiento;
                }
            }
        }
    }

    if (msp2TableExists($conn, 'msp_historial_contrato')) {
        $stmtHistorial = $conn->prepare(
            'SELECT
                h.id_historial_contrato,
                h.tipo_evento,
                h.id_usuario,
                h.detalle_evento,
                h.motivo_evento,
                h.fecha_registro
             FROM dbo.msp_historial_contrato h
             WHERE h.id_contrato_arriendo = :id_contrato
             ORDER BY h.fecha_registro DESC, h.id_historial_contrato DESC'
        );
        $stmtHistorial->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtHistorial->execute();
        while (($rowHist = $stmtHistorial->fetch()) !== false) {
            $tipo = strtoupper(trim((string) ($rowHist['tipo_evento'] ?? 'EVENTO')));
            $titulo = match ($tipo) {
                'CREACION' => 'Contrato creado',
                'ACTUALIZACION' => 'Contrato actualizado',
                'CIERRE' => 'Contrato cerrado',
                default => 'Evento contractual',
            };

            $detallePartes = [];
            $motivo = trim((string) ($rowHist['motivo_evento'] ?? ''));
            if ($motivo !== '') {
                $detallePartes[] = 'Motivo: ' . $motivo;
            }
            $detalleEventoRaw = trim((string) ($rowHist['detalle_evento'] ?? ''));
            if ($detalleEventoRaw !== '') {
                $detalleDecoded = msp2FichaParseJsonMaybe($detalleEventoRaw);
                if ($detalleDecoded !== null) {
                    $origenJson = trim((string) ($detalleDecoded['origen'] ?? ''));
                    $tipoJson = trim((string) ($detalleDecoded['tipo'] ?? ''));
                    if ($origenJson !== '') {
                        $detallePartes[] = 'Origen: ' . $origenJson;
                    }
                    if ($tipoJson !== '') {
                        $detallePartes[] = 'Tipo: ' . $tipoJson;
                    }
                } else {
                    $detallePartes[] = mb_substr($detalleEventoRaw, 0, 280, 'UTF-8');
                }
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($rowHist['fecha_registro'] ?? ''),
                'tipo_evento' => $tipo,
                'titulo' => $titulo,
                'detalle' => implode(' | ', $detallePartes),
                'origen' => 'CONTRATO',
                'id_referencia' => (int) ($rowHist['id_historial_contrato'] ?? 0),
                'metadata' => [
                    'id_usuario' => (int) ($rowHist['id_usuario'] ?? 0),
                    'usuario_label' => 'Usuario #' . (int) ($rowHist['id_usuario'] ?? 0),
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    if (msp2TableExists($conn, 'msp_bitacora_cierre_contrato')) {
        $stmtBitacora = $conn->prepare(
            'SELECT
                b.id_bitacora_cierre_contrato,
                b.id_usuario,
                b.estado_contrato_anterior,
                b.estado_contrato_nuevo,
                b.motivo_cierre,
                b.fecha_registro
             FROM dbo.msp_bitacora_cierre_contrato b
             WHERE b.id_contrato_arriendo = :id_contrato
             ORDER BY b.fecha_registro DESC, b.id_bitacora_cierre_contrato DESC'
        );
        $stmtBitacora->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtBitacora->execute();
        while (($rowBit = $stmtBitacora->fetch()) !== false) {
            $detalle = 'Estado ' . (int) ($rowBit['estado_contrato_anterior'] ?? 0) . ' → ' . (int) ($rowBit['estado_contrato_nuevo'] ?? 0);
            $motivo = trim((string) ($rowBit['motivo_cierre'] ?? ''));
            if ($motivo !== '') {
                $detalle .= ' | Motivo: ' . $motivo;
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($rowBit['fecha_registro'] ?? ''),
                'tipo_evento' => 'CIERRE_CONTRATO',
                'titulo' => 'Bitácora de cierre contractual',
                'detalle' => $detalle,
                'origen' => 'CONTRATO',
                'id_referencia' => (int) ($rowBit['id_bitacora_cierre_contrato'] ?? 0),
                'metadata' => [
                    'id_usuario' => (int) ($rowBit['id_usuario'] ?? 0),
                    'usuario_label' => 'Usuario #' . (int) ($rowBit['id_usuario'] ?? 0),
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    if ($tieneDocumentos) {
        $whereTimelineDocs = $documentosTieneContrato
            ? 'dc.id_contrato_arriendo = :id_contrato'
            : 'dc.id_tienda = :id_tienda';

        $stmtDocsTimeline = $conn->prepare(
            "SELECT TOP (500)
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.fecha_emision,
                dc.fecha_registro,
                dc.estado_documento,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.observaciones
             FROM dbo.msp_documentos_cobro dc
             WHERE {$whereTimelineDocs}
             ORDER BY dc.periodo_facturacion DESC, dc.id_documento_cobro DESC"
        );
        if ($documentosTieneContrato) {
            $stmtDocsTimeline->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtDocsTimeline->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtDocsTimeline->execute();
        while (($rowDocTl = $stmtDocsTimeline->fetch()) !== false) {
            $idDoc = (int) ($rowDocTl['id_documento_cobro'] ?? 0);
            $detalle = 'Documento #' . $idDoc;
            $numero = trim((string) ($rowDocTl['numero_documento'] ?? ''));
            if ($numero !== '') {
                $detalle .= ' (' . $numero . ')';
            }
            $detalle .= ' | Período ' . msp2FichaFmtPeriodo((string) ($rowDocTl['periodo_facturacion'] ?? ''));
            $detalle .= ' | Total ' . msp2FichaFmtMonto((float) ($rowDocTl['monto_total'] ?? 0));
            $detalle .= ' | Saldo ' . msp2FichaFmtMonto((float) ($rowDocTl['saldo_pendiente'] ?? 0));

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($rowDocTl['fecha_registro'] ?? $rowDocTl['fecha_emision'] ?? ''),
                'tipo_evento' => 'EMISION_DOCUMENTO',
                'titulo' => 'Documento de cobro emitido',
                'detalle' => $detalle,
                'origen' => 'DOCUMENTO',
                'id_referencia' => $idDoc,
                'metadata' => [
                    'id_documento' => $idDoc,
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);

            $obs = trim((string) ($rowDocTl['observaciones'] ?? ''));
            if ($obs !== '' && preg_match('/regener|recal/i', $obs) === 1) {
                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowDocTl['fecha_registro'] ?? ''),
                    'tipo_evento' => 'RECALCULO_DOCUMENTO',
                    'titulo' => 'Documento recalculado (derivado)',
                    'detalle' => 'Documento #' . $idDoc . ' | Observación: ' . mb_substr($obs, 0, 200, 'UTF-8'),
                    'origen' => 'DOCUMENTO',
                    'id_referencia' => $idDoc,
                    'metadata' => [
                        'id_documento' => $idDoc,
                        'origen_legado' => true,
                    ],
                    'es_evento_derivado' => true,
                ]);
            }
        }
    }

    if ($tieneDocumentos && $tienePagos) {
        $whereTimelinePagos = $documentosTieneContrato
            ? 'dc.id_contrato_arriendo = :id_contrato'
            : 'dc.id_tienda = :id_tienda';
        $pagosTieneAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
        $pagosTieneMontoSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');

        $selectAplicaSaldoFavor = $pagosTieneAplicaSaldoFavor
            ? 'p.aplica_desde_saldo_favor'
            : 'CAST(0 AS BIT) AS aplica_desde_saldo_favor';
        $selectMontoSaldoFavor = $pagosTieneMontoSaldoFavor
            ? 'p.monto_saldo_favor_generado'
            : 'CAST(0 AS DECIMAL(18,2)) AS monto_saldo_favor_generado';

        $stmtPagosTimeline = $conn->prepare(
            "SELECT TOP (500)
                p.id_pago,
                p.id_documento_cobro,
                p.fecha_pago,
                p.fecha_registro,
                p.monto_pagado,
                p.estado_pago,
                {$selectAplicaSaldoFavor},
                {$selectMontoSaldoFavor},
                p.medio_pago,
                p.motivo_anulacion,
                dc.numero_documento
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE {$whereTimelinePagos}
             ORDER BY p.fecha_pago DESC, p.id_pago DESC"
        );
        if ($documentosTieneContrato) {
            $stmtPagosTimeline->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtPagosTimeline->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtPagosTimeline->execute();
        while (($rowPagoTl = $stmtPagosTimeline->fetch()) !== false) {
            $idPago = (int) ($rowPagoTl['id_pago'] ?? 0);
            $estadoPago = (int) ($rowPagoTl['estado_pago'] ?? 0);
            $titulo = $estadoPago === 2 ? 'Pago anulado' : 'Pago aplicado';
            $detalle = 'Pago #' . $idPago
                . ' | Doc #' . (int) ($rowPagoTl['id_documento_cobro'] ?? 0)
                . ' | Monto ' . msp2FichaFmtMonto((float) ($rowPagoTl['monto_pagado'] ?? 0));
            if ((int) ($rowPagoTl['aplica_desde_saldo_favor'] ?? 0) === 1) {
                $detalle .= ' | Aplicado desde saldo a favor';
            }
            if ((float) ($rowPagoTl['monto_saldo_favor_generado'] ?? 0) > 0) {
                $detalle .= ' | Generó saldo favor ' . msp2FichaFmtMonto((float) ($rowPagoTl['monto_saldo_favor_generado'] ?? 0));
            }
            if ($estadoPago === 2) {
                $motivoAnul = trim((string) ($rowPagoTl['motivo_anulacion'] ?? ''));
                if ($motivoAnul !== '') {
                    $detalle .= ' | Motivo anulación: ' . $motivoAnul;
                }
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($rowPagoTl['fecha_registro'] ?? $rowPagoTl['fecha_pago'] ?? ''),
                'tipo_evento' => $estadoPago === 2 ? 'PAGO_ANULADO' : 'PAGO_APLICADO',
                'titulo' => $titulo,
                'detalle' => $detalle,
                'origen' => 'PAGO',
                'id_referencia' => $idPago,
                'metadata' => [
                    'id_pago' => $idPago,
                    'id_documento' => (int) ($rowPagoTl['id_documento_cobro'] ?? 0),
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    if ($tieneCargosSalida && $tieneTipoCargoSalida) {
        $stmtCargosTimeline = $conn->prepare(
            "SELECT TOP (500)
                cs.id_cargo_salida,
                cs.id_documento_cobro,
                cs.fecha_cargo,
                cs.fecha_registro,
                cs.estado_cargo,
                cs.monto_cargo,
                cs.descripcion_cargo,
                tc.codigo_tipo_cargo,
                tc.nombre_tipo_cargo
             FROM dbo.msp_cargos_salida cs
             INNER JOIN dbo.msp_tipos_cargo_salida tc
                ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
             WHERE cs.id_contrato_arriendo = :id_contrato
             ORDER BY cs.fecha_registro DESC, cs.id_cargo_salida DESC"
        );
        $stmtCargosTimeline->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargosTimeline->execute();
        while (($rowCargoTl = $stmtCargosTimeline->fetch()) !== false) {
            $idCargo = (int) ($rowCargoTl['id_cargo_salida'] ?? 0);
            $estadoCargo = (int) ($rowCargoTl['estado_cargo'] ?? 0);
            $codigoCargo = strtoupper(trim((string) ($rowCargoTl['codigo_tipo_cargo'] ?? '')));
            $nombreCargo = trim((string) ($rowCargoTl['nombre_tipo_cargo'] ?? ''));
            if ($nombreCargo === '') {
                $nombreCargo = $codigoCargo !== '' ? $codigoCargo : 'Cargo';
            }
            $titulo = match ($estadoCargo) {
                5 => 'Cargo condonado/anulado',
                4 => 'Cargo pagado',
                3 => 'Cargo aplicado a documento',
                2 => 'Cargo reservado',
                default => 'Cargo registrado',
            };
            $detalle = 'Cargo #' . $idCargo
                . ' | Tipo ' . $nombreCargo
                . ' | Monto ' . msp2FichaFmtMonto((float) ($rowCargoTl['monto_cargo'] ?? 0));
            $idDocCargo = (int) ($rowCargoTl['id_documento_cobro'] ?? 0);
            if ($idDocCargo > 0) {
                $detalle .= ' | Doc #' . $idDocCargo;
            }
            $descCargo = trim((string) ($rowCargoTl['descripcion_cargo'] ?? ''));
            if ($descCargo !== '') {
                $detalle .= ' | ' . mb_substr($descCargo, 0, 180, 'UTF-8');
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($rowCargoTl['fecha_registro'] ?? $rowCargoTl['fecha_cargo'] ?? ''),
                'tipo_evento' => $estadoCargo === 5 ? 'CONDONACION_CARGO' : 'CARGO',
                'titulo' => $titulo,
                'detalle' => $detalle,
                'origen' => 'CARGO',
                'id_referencia' => $idCargo,
                'metadata' => [
                    'id_cargo' => $idCargo,
                    'id_documento' => $idDocCargo,
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    /*
     * La garantía es contractual, aunque el modelo histórico conserve una fila
     * por local. El timeline agrega todas las operaciones de esas garantías al
     * contrato y mantiene la referencia del local solo como contexto.
     */
    if (msp2TableExists($conn, 'msp_garantias')) {
        if (msp2TableExists($conn, 'msp_garantia_recepciones')) {
            $stmtGarantiaRecepciones = $conn->prepare(
                "SELECT TOP (500)
                    r.id_recepcion_garantia,
                    r.id_garantia,
                    r.fecha_recepcion,
                    r.monto_recibido,
                    r.medio_recepcion,
                    r.referencia,
                    r.estado_recepcion,
                    r.observaciones,
                    r.id_usuario,
                    r.fecha_registro,
                    l.cdo_local
                 FROM dbo.msp_garantia_recepciones r
                 INNER JOIN dbo.msp_garantias g ON g.id_garantia = r.id_garantia
                 LEFT JOIN dbo.msp_locales l ON l.id_local = g.id_local
                 WHERE g.id_contrato_arriendo = :id_contrato
                 ORDER BY r.fecha_registro DESC, r.id_recepcion_garantia DESC"
            );
            $stmtGarantiaRecepciones->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmtGarantiaRecepciones->execute();
            while (($rowGarRec = $stmtGarantiaRecepciones->fetch()) !== false) {
                $estadoRecepcion = strtoupper(trim((string) ($rowGarRec['estado_recepcion'] ?? '')));
                $idRecepcion = (int) ($rowGarRec['id_recepcion_garantia'] ?? 0);
                $detalle = 'Garantía #' . (int) ($rowGarRec['id_garantia'] ?? 0)
                    . ' | Monto ' . msp2FichaFmtMonto((float) ($rowGarRec['monto_recibido'] ?? 0))
                    . ' | Medio ' . (trim((string) ($rowGarRec['medio_recepcion'] ?? '')) ?: '-');
                $localGarantia = trim((string) ($rowGarRec['cdo_local'] ?? ''));
                if ($localGarantia !== '') {
                    $detalle .= ' | Local ' . $localGarantia;
                }
                $referenciaGarantia = trim((string) ($rowGarRec['referencia'] ?? ''));
                if ($referenciaGarantia !== '') {
                    $detalle .= ' | Referencia ' . $referenciaGarantia;
                }
                if ($estadoRecepcion !== '') {
                    $detalle .= ' | Estado ' . ucfirst(strtolower($estadoRecepcion));
                }
                $observacionGarantia = trim((string) ($rowGarRec['observaciones'] ?? ''));
                if ($observacionGarantia !== '') {
                    $detalle .= ' | ' . mb_substr($observacionGarantia, 0, 180, 'UTF-8');
                }

                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowGarRec['fecha_registro'] ?? $rowGarRec['fecha_recepcion'] ?? ''),
                    'tipo_evento' => $estadoRecepcion === 'ANULADA' ? 'GARANTIA_RECEPCION_ANULADA' : 'GARANTIA_RECEPCION',
                    'titulo' => $estadoRecepcion === 'ANULADA' ? 'Recepción de garantía anulada' : 'Garantía recibida / abonada',
                    'detalle' => $detalle,
                    'origen' => 'GARANTIA',
                    'id_referencia' => $idRecepcion,
                    'metadata' => [
                        'id_garantia' => (int) ($rowGarRec['id_garantia'] ?? 0),
                        'id_recepcion_garantia' => $idRecepcion,
                        'id_usuario' => (int) ($rowGarRec['id_usuario'] ?? 0),
                        'usuario_label' => 'Usuario #' . (int) ($rowGarRec['id_usuario'] ?? 0),
                        'comprobante_tipo' => 'RECEPCION',
                        'comprobante_id' => $idRecepcion,
                        'origen_legado' => true,
                    ],
                    'es_evento_derivado' => true,
                ]);
            }
        }

        if (msp2TableExists($conn, 'msp_movimientos_garantia') && msp2TableExists($conn, 'msp_tipos_movimiento_garantia')) {
            $filtroMovimientoDevolucion = msp2TableExists($conn, 'msp_garantia_devoluciones')
                ? "AND NOT (
                       tm.codigo_movimiento = N'DEVOLUCION'
                       AND EXISTS (
                           SELECT 1 FROM dbo.msp_garantia_devoluciones d
                           WHERE d.id_movimiento_garantia = mg.id_movimiento_garantia
                       )
                   )"
                : '';
            $stmtGarantiaMovimientos = $conn->prepare(
                "SELECT TOP (500)
                    mg.id_movimiento_garantia,
                    mg.id_garantia,
                    mg.fecha_movimiento,
                    mg.monto_movimiento,
                    mg.id_cargo_salida,
                    mg.id_cargo_contrato_local,
                    mg.id_documento_cobro,
                    mg.id_pago,
                    mg.categoria_aplicacion,
                    mg.observaciones,
                    mg.motivo_autorizacion,
                    mg.id_usuario_solicita,
                    mg.fecha_registro,
                    tm.codigo_movimiento,
                    tm.nombre_movimiento,
                    l.cdo_local
                 FROM dbo.msp_movimientos_garantia mg
                 INNER JOIN dbo.msp_garantias g ON g.id_garantia = mg.id_garantia
                 INNER JOIN dbo.msp_tipos_movimiento_garantia tm
                    ON tm.id_tipo_movimiento_garantia = mg.id_tipo_movimiento_garantia
                 LEFT JOIN dbo.msp_locales l ON l.id_local = g.id_local
                 WHERE g.id_contrato_arriendo = :id_contrato
                   {$filtroMovimientoDevolucion}
                 ORDER BY mg.fecha_registro DESC, mg.id_movimiento_garantia DESC"
            );
            $stmtGarantiaMovimientos->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmtGarantiaMovimientos->execute();
            while (($rowGarMov = $stmtGarantiaMovimientos->fetch()) !== false) {
                $codigoMovimiento = strtoupper(trim((string) ($rowGarMov['codigo_movimiento'] ?? '')));
                $tituloMovimiento = match ($codigoMovimiento) {
                    'CONSTITUCION' => 'Garantía constituida',
                    'RESERVA' => 'Garantía reservada',
                    'LIBERACION_RESERVA' => 'Reserva de garantía liberada',
                    'APLICACION_CARGO' => 'Garantía aplicada a cargo',
                    'DEVOLUCION' => 'Garantía devuelta',
                    'AJUSTE_POSITIVO' => 'Ajuste positivo de garantía',
                    'AJUSTE_NEGATIVO' => 'Ajuste negativo de garantía',
                    default => trim((string) ($rowGarMov['nombre_movimiento'] ?? '')) ?: 'Movimiento de garantía',
                };
                $detalle = 'Garantía #' . (int) ($rowGarMov['id_garantia'] ?? 0)
                    . ' | Monto ' . msp2FichaFmtMonto((float) ($rowGarMov['monto_movimiento'] ?? 0));
                $localGarantia = trim((string) ($rowGarMov['cdo_local'] ?? ''));
                if ($localGarantia !== '') {
                    $detalle .= ' | Local ' . $localGarantia;
                }
                $idCargoGarantia = (int) ($rowGarMov['id_cargo_salida'] ?? 0);
                $idCargoLocalGarantia = (int) ($rowGarMov['id_cargo_contrato_local'] ?? 0);
                if ($idCargoGarantia > 0) {
                    $detalle .= ' | Cargo #' . $idCargoGarantia;
                } elseif ($idCargoLocalGarantia > 0) {
                    $detalle .= ' | Cargo local #' . $idCargoLocalGarantia;
                }
                $idDocGarantia = (int) ($rowGarMov['id_documento_cobro'] ?? 0);
                if ($idDocGarantia > 0) {
                    $detalle .= ' | Doc #' . $idDocGarantia;
                }
                $idPagoGarantia = (int) ($rowGarMov['id_pago'] ?? 0);
                if ($idPagoGarantia > 0) {
                    $detalle .= ' | Pago #' . $idPagoGarantia;
                }
                $observacionMovimiento = trim((string) ($rowGarMov['observaciones'] ?? ''));
                if ($observacionMovimiento !== '') {
                    $detalle .= ' | ' . mb_substr($observacionMovimiento, 0, 180, 'UTF-8');
                }

                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowGarMov['fecha_registro'] ?? $rowGarMov['fecha_movimiento'] ?? ''),
                    'tipo_evento' => 'GARANTIA_' . ($codigoMovimiento !== '' ? $codigoMovimiento : 'MOVIMIENTO'),
                    'titulo' => $tituloMovimiento,
                    'detalle' => $detalle,
                    'origen' => 'GARANTIA',
                    'id_referencia' => (int) ($rowGarMov['id_movimiento_garantia'] ?? 0),
                    'metadata' => [
                        'id_garantia' => (int) ($rowGarMov['id_garantia'] ?? 0),
                        'id_movimiento_garantia' => (int) ($rowGarMov['id_movimiento_garantia'] ?? 0),
                        'id_documento' => $idDocGarantia,
                        'id_pago' => $idPagoGarantia,
                        'id_usuario' => (int) ($rowGarMov['id_usuario_solicita'] ?? 0),
                        'usuario_label' => 'Usuario #' . (int) ($rowGarMov['id_usuario_solicita'] ?? 0),
                        'origen_legado' => true,
                    ],
                    'es_evento_derivado' => true,
                ]);
            }
        }

        if (msp2TableExists($conn, 'msp_garantia_devoluciones')) {
            $stmtGarantiaDevoluciones = $conn->prepare(
                "SELECT TOP (500)
                    d.id_devolucion_garantia,
                    d.id_garantia,
                    d.fecha_devolucion,
                    d.monto_devolucion,
                    d.medio_devolucion,
                    d.referencia_transferencia,
                    d.numero_cheque,
                    d.estado_devolucion,
                    d.observaciones,
                    d.id_usuario,
                    d.fecha_registro,
                    l.cdo_local
                 FROM dbo.msp_garantia_devoluciones d
                 INNER JOIN dbo.msp_garantias g ON g.id_garantia = d.id_garantia
                 LEFT JOIN dbo.msp_locales l ON l.id_local = g.id_local
                 WHERE g.id_contrato_arriendo = :id_contrato
                 ORDER BY d.fecha_registro DESC, d.id_devolucion_garantia DESC"
            );
            $stmtGarantiaDevoluciones->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmtGarantiaDevoluciones->execute();
            while (($rowGarDev = $stmtGarantiaDevoluciones->fetch()) !== false) {
                $idDevolucion = (int) ($rowGarDev['id_devolucion_garantia'] ?? 0);
                $detalle = 'Garantía #' . (int) ($rowGarDev['id_garantia'] ?? 0)
                    . ' | Monto ' . msp2FichaFmtMonto((float) ($rowGarDev['monto_devolucion'] ?? 0))
                    . ' | Medio ' . (trim((string) ($rowGarDev['medio_devolucion'] ?? '')) ?: '-');
                $localGarantia = trim((string) ($rowGarDev['cdo_local'] ?? ''));
                if ($localGarantia !== '') {
                    $detalle .= ' | Local ' . $localGarantia;
                }
                $referenciaDevolucion = trim((string) ($rowGarDev['referencia_transferencia'] ?? $rowGarDev['numero_cheque'] ?? ''));
                if ($referenciaDevolucion !== '') {
                    $detalle .= ' | Referencia ' . $referenciaDevolucion;
                }
                $estadoDevolucion = trim((string) ($rowGarDev['estado_devolucion'] ?? ''));
                if ($estadoDevolucion !== '') {
                    $detalle .= ' | Estado ' . ucfirst(strtolower($estadoDevolucion));
                }
                $observacionDevolucion = trim((string) ($rowGarDev['observaciones'] ?? ''));
                if ($observacionDevolucion !== '') {
                    $detalle .= ' | ' . mb_substr($observacionDevolucion, 0, 180, 'UTF-8');
                }

                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowGarDev['fecha_registro'] ?? $rowGarDev['fecha_devolucion'] ?? ''),
                    'tipo_evento' => 'GARANTIA_DEVOLUCION',
                    'titulo' => 'Garantía devuelta',
                    'detalle' => $detalle,
                    'origen' => 'GARANTIA',
                    'id_referencia' => $idDevolucion,
                    'metadata' => [
                        'id_garantia' => (int) ($rowGarDev['id_garantia'] ?? 0),
                        'id_devolucion_garantia' => $idDevolucion,
                        'id_usuario' => (int) ($rowGarDev['id_usuario'] ?? 0),
                        'usuario_label' => 'Usuario #' . (int) ($rowGarDev['id_usuario'] ?? 0),
                        'comprobante_tipo' => 'DEVOLUCION',
                        'comprobante_id' => $idDevolucion,
                        'origen_legado' => true,
                    ],
                    'es_evento_derivado' => true,
                ]);
            }
        }

        if (msp2TableExists($conn, 'msp_garantia_reversas')) {
            $stmtGarantiaReversas = $conn->prepare(
                "SELECT TOP (500)
                    rv.id_reversa_garantia,
                    rv.id_garantia,
                    rv.tipo_origen,
                    rv.id_origen,
                    rv.fecha_reversa,
                    rv.monto_reversa,
                    rv.motivo,
                    rv.id_usuario,
                    rv.fecha_registro,
                    l.cdo_local
                 FROM dbo.msp_garantia_reversas rv
                 INNER JOIN dbo.msp_garantias g ON g.id_garantia = rv.id_garantia
                 LEFT JOIN dbo.msp_locales l ON l.id_local = g.id_local
                 WHERE g.id_contrato_arriendo = :id_contrato
                 ORDER BY rv.fecha_registro DESC, rv.id_reversa_garantia DESC"
            );
            $stmtGarantiaReversas->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            $stmtGarantiaReversas->execute();
            while (($rowGarRev = $stmtGarantiaReversas->fetch()) !== false) {
                $detalle = 'Garantía #' . (int) ($rowGarRev['id_garantia'] ?? 0)
                    . ' | Monto ' . msp2FichaFmtMonto((float) ($rowGarRev['monto_reversa'] ?? 0))
                    . ' | Operación ' . (trim((string) ($rowGarRev['tipo_origen'] ?? '')) ?: '-')
                    . ' #' . (int) ($rowGarRev['id_origen'] ?? 0);
                $motivoReversa = trim((string) ($rowGarRev['motivo'] ?? ''));
                if ($motivoReversa !== '') {
                    $detalle .= ' | Motivo ' . mb_substr($motivoReversa, 0, 180, 'UTF-8');
                }

                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowGarRev['fecha_registro'] ?? $rowGarRev['fecha_reversa'] ?? ''),
                    'tipo_evento' => 'GARANTIA_REVERSA',
                    'titulo' => 'Operación de garantía reversada',
                    'detalle' => $detalle,
                    'origen' => 'GARANTIA',
                    'id_referencia' => (int) ($rowGarRev['id_reversa_garantia'] ?? 0),
                    'metadata' => [
                        'id_garantia' => (int) ($rowGarRev['id_garantia'] ?? 0),
                        'id_reversa_garantia' => (int) ($rowGarRev['id_reversa_garantia'] ?? 0),
                        'id_usuario' => (int) ($rowGarRev['id_usuario'] ?? 0),
                        'usuario_label' => 'Usuario #' . (int) ($rowGarRev['id_usuario'] ?? 0),
                        'origen_legado' => true,
                    ],
                    'es_evento_derivado' => true,
                ]);
            }
        }
    }

    $tieneEnvioLotes = msp2TableExists($conn, 'msp_envio_lotes_programados')
        && msp2TableExists($conn, 'msp_envio_lote_destinatarios')
        && msp2TableExists($conn, 'msp_envio_lote_documentos')
        && $tieneDocumentos;
    if ($tieneEnvioLotes) {
        $whereTimelineEnvios = $documentosTieneContrato
            ? 'dc.id_contrato_arriendo = :id_contrato'
            : 'dc.id_tienda = :id_tienda';

        $stmtEnviosTimeline = $conn->prepare(
            "SELECT TOP (500)
                l.id_lote_envio,
                l.codigo_servicio,
                l.modo_destino,
                l.programado_para,
                l.estado_lote,
                d.id_lote_destinatario,
                d.estado_destinatario,
                d.correo_destino,
                d.enviado_at,
                d.updated_at,
                eld.id_documento_cobro
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_envio_lote_destinatarios d
                ON d.id_lote_destinatario = eld.id_lote_destinatario
             INNER JOIN dbo.msp_envio_lotes_programados l
                ON l.id_lote_envio = d.id_lote_envio
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = eld.id_documento_cobro
             WHERE {$whereTimelineEnvios}
             ORDER BY l.id_lote_envio DESC, d.id_lote_destinatario DESC"
        );
        if ($documentosTieneContrato) {
            $stmtEnviosTimeline->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        } else {
            $stmtEnviosTimeline->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
        }
        $stmtEnviosTimeline->execute();
        while (($rowEnvTl = $stmtEnviosTimeline->fetch()) !== false) {
            $idLote = (int) ($rowEnvTl['id_lote_envio'] ?? 0);
            $estadoDest = (int) ($rowEnvTl['estado_destinatario'] ?? 0);
            $titulo = match ($estadoDest) {
                2 => 'Documento enviado',
                3 => 'Error de envío',
                4 => 'Documento omitido en envío',
                default => 'Envío programado',
            };
            $detalle = 'Lote #' . $idLote
                . ' | Doc #' . (int) ($rowEnvTl['id_documento_cobro'] ?? 0)
                . ' | Servicio ' . strtoupper(trim((string) ($rowEnvTl['codigo_servicio'] ?? '-')))
                . ' | Modo ' . strtolower(trim((string) ($rowEnvTl['modo_destino'] ?? 'real')))
                . ' | Destino ' . trim((string) ($rowEnvTl['correo_destino'] ?? '-'));

            $fechaEventoEnvio = (string) ($rowEnvTl['enviado_at'] ?? '');
            if (trim($fechaEventoEnvio) === '') {
                $fechaEventoEnvio = (string) ($rowEnvTl['updated_at'] ?? '');
            }
            if (trim($fechaEventoEnvio) === '') {
                $fechaEventoEnvio = (string) ($rowEnvTl['programado_para'] ?? '');
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => $fechaEventoEnvio,
                'tipo_evento' => 'ENVIO_DOCUMENTO',
                'titulo' => $titulo,
                'detalle' => $detalle,
                'origen' => 'ENVIO',
                'id_referencia' => (int) ($rowEnvTl['id_lote_destinatario'] ?? 0),
                'metadata' => [
                    'id_lote_envio' => $idLote,
                    'id_documento' => (int) ($rowEnvTl['id_documento_cobro'] ?? 0),
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    if ($asientosContables !== []) {
        foreach ($asientosContables as $asiento) {
            $idAsiento = (int) ($asiento['id_asiento_contable'] ?? 0);
            $detalle = 'Asiento #' . $idAsiento
                . ' | ' . (string) ($asiento['tabla_origen'] ?? '-') . ' #' . (int) ($asiento['id_origen'] ?? 0)
                . ' | Débito ' . msp2FichaFmtMonto((float) ($asiento['total_debe'] ?? 0))
                . ' | Crédito ' . msp2FichaFmtMonto((float) ($asiento['total_haber'] ?? 0));
            $glosa = trim((string) ($asiento['glosa'] ?? ''));
            if ($glosa !== '') {
                $detalle .= ' | ' . mb_substr($glosa, 0, 180, 'UTF-8');
            }

            msp2FichaAddTimelineEvent($timeline, [
                'fecha_evento' => (string) ($asiento['fecha_asiento_registro'] ?? $asiento['fecha_contable'] ?? ''),
                'tipo_evento' => 'ASIENTO_CONTABLE',
                'titulo' => 'Asiento contable generado',
                'detalle' => $detalle,
                'origen' => 'CONTABLE',
                'id_referencia' => $idAsiento,
                'metadata' => [
                    'id_asiento_contable' => $idAsiento,
                    'origen_legado' => true,
                ],
                'es_evento_derivado' => true,
            ]);
        }
    }

    if (msp2TableExists($conn, 'msp_documentos_cobro_eventos')) {
        $camposCanonicos = [
            'id' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'id_documento_cobro_evento'),
            'id_documento' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'id_documento_cobro'),
            'id_contrato' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'id_contrato_arriendo'),
            'id_asiento' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'id_asiento_contable'),
            'tipo' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'tipo_evento'),
            'origen' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'origen_evento'),
            'titulo' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'titulo_evento'),
            'detalle' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'detalle_evento'),
            'payload' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'payload_json'),
            'id_usuario' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'id_usuario'),
            'fecha_evento' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'fecha_evento'),
            'fecha_registro' => msp2ColumnExists($conn, 'msp_documentos_cobro_eventos', 'fecha_registro'),
        ];

        if ($camposCanonicos['id'] && $camposCanonicos['tipo'] && $camposCanonicos['fecha_evento']) {
            $idContratoSql = $camposCanonicos['id_contrato']
                ? 'e.id_contrato_arriendo'
                : 'CAST(NULL AS INT) AS id_contrato_arriendo';
            $idDocSql = $camposCanonicos['id_documento']
                ? 'e.id_documento_cobro'
                : 'CAST(NULL AS INT) AS id_documento_cobro';
            $idAsientoSql = $camposCanonicos['id_asiento']
                ? 'e.id_asiento_contable'
                : 'CAST(NULL AS INT) AS id_asiento_contable';
            $origenSql = $camposCanonicos['origen']
                ? 'e.origen_evento'
                : "N'DOCUMENTO' AS origen_evento";
            $tituloSql = $camposCanonicos['titulo']
                ? 'e.titulo_evento'
                : 'CAST(NULL AS NVARCHAR(200)) AS titulo_evento';
            $detalleSql = $camposCanonicos['detalle']
                ? 'e.detalle_evento'
                : 'CAST(NULL AS NVARCHAR(MAX)) AS detalle_evento';
            $payloadSql = $camposCanonicos['payload']
                ? 'e.payload_json'
                : 'CAST(NULL AS NVARCHAR(MAX)) AS payload_json';
            $idUsuarioSql = $camposCanonicos['id_usuario']
                ? 'e.id_usuario'
                : 'CAST(NULL AS INT) AS id_usuario';
            $fechaRegSql = $camposCanonicos['fecha_registro']
                ? 'e.fecha_registro'
                : 'e.fecha_evento AS fecha_registro';

            $whereCanonico = $camposCanonicos['id_contrato']
                ? 'e.id_contrato_arriendo = :id_contrato'
                : ($camposCanonicos['id_documento'] && $documentosTieneContrato
                    ? 'EXISTS (
                        SELECT 1
                        FROM dbo.msp_documentos_cobro dc
                        WHERE dc.id_documento_cobro = e.id_documento_cobro
                          AND dc.id_contrato_arriendo = :id_contrato
                    )'
                    : ($camposCanonicos['id_documento']
                        ? 'EXISTS (
                            SELECT 1
                            FROM dbo.msp_documentos_cobro dc
                            WHERE dc.id_documento_cobro = e.id_documento_cobro
                              AND dc.id_tienda = :id_tienda
                        )'
                        : '1=0'));

            $stmtEventosCan = $conn->prepare(
                "SELECT TOP (500)
                    e.id_documento_cobro_evento,
                    {$idContratoSql},
                    {$idDocSql},
                    {$idAsientoSql},
                    e.tipo_evento,
                    {$origenSql},
                    {$tituloSql},
                    {$detalleSql},
                    {$payloadSql},
                    {$idUsuarioSql},
                    e.fecha_evento,
                    {$fechaRegSql}
                 FROM dbo.msp_documentos_cobro_eventos e
                 WHERE {$whereCanonico}
                 ORDER BY e.fecha_evento DESC, e.id_documento_cobro_evento DESC"
            );
            if (str_contains($whereCanonico, ':id_contrato')) {
                $stmtEventosCan->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
            }
            if (str_contains($whereCanonico, ':id_tienda')) {
                $stmtEventosCan->bindValue(':id_tienda', $idTiendaContrato, PDO::PARAM_INT);
            }
            $stmtEventosCan->execute();
            while (($rowCan = $stmtEventosCan->fetch()) !== false) {
                $tipoCan = strtoupper(trim((string) ($rowCan['tipo_evento'] ?? 'EVENTO')));
                $tituloCan = trim((string) ($rowCan['titulo_evento'] ?? ''));
                if ($tituloCan === '') {
                    $tituloCan = 'Evento ' . str_replace('_', ' ', $tipoCan);
                }
                $detalleCan = trim((string) ($rowCan['detalle_evento'] ?? ''));
                $payloadCanRaw = trim((string) ($rowCan['payload_json'] ?? ''));
                if ($detalleCan === '' && $payloadCanRaw !== '') {
                    $payloadDecoded = msp2FichaParseJsonMaybe($payloadCanRaw);
                    if ($payloadDecoded !== null) {
                        $detalleCan = 'Payload: ' . json_encode($payloadDecoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $detalleCan = is_string($detalleCan) ? mb_substr($detalleCan, 0, 260, 'UTF-8') : '';
                    }
                }

                $metadataCan = [
                    'id_documento' => (int) ($rowCan['id_documento_cobro'] ?? 0),
                    'id_contrato' => (int) ($rowCan['id_contrato_arriendo'] ?? 0),
                    'id_asiento_contable' => (int) ($rowCan['id_asiento_contable'] ?? 0),
                    'id_usuario' => (int) ($rowCan['id_usuario'] ?? 0),
                    'usuario_label' => ((int) ($rowCan['id_usuario'] ?? 0)) > 0
                        ? ('Usuario #' . (int) ($rowCan['id_usuario'] ?? 0))
                        : '-',
                    'origen_legado' => false,
                ];

                msp2FichaAddTimelineEvent($timeline, [
                    'fecha_evento' => (string) ($rowCan['fecha_evento'] ?? $rowCan['fecha_registro'] ?? ''),
                    'tipo_evento' => $tipoCan,
                    'titulo' => $tituloCan,
                    'detalle' => $detalleCan,
                    'origen' => (string) ($rowCan['origen_evento'] ?? 'DOCUMENTO'),
                    'id_referencia' => (int) ($rowCan['id_documento_cobro_evento'] ?? 0),
                    'metadata' => $metadataCan,
                    'es_evento_derivado' => false,
                ]);
            }
        }
    }

    if ($timeline !== []) {
        $idsUsuarioTimeline = [];
        foreach ($timeline as $eventoTl) {
            $metadataTl = is_array($eventoTl['metadata'] ?? null) ? $eventoTl['metadata'] : [];
            $idUsuarioTl = (int) ($metadataTl['id_usuario'] ?? 0);
            if ($idUsuarioTl > 0) {
                $idsUsuarioTimeline[] = $idUsuarioTl;
            }
        }
        $usuarioLabelById = msp2FichaResolveUsuarioLabels($conn, $idsUsuarioTimeline);
        if ($usuarioLabelById !== []) {
            foreach ($timeline as $indexTl => $eventoTl) {
                $metadataTl = is_array($eventoTl['metadata'] ?? null) ? $eventoTl['metadata'] : [];
                $idUsuarioTl = (int) ($metadataTl['id_usuario'] ?? 0);
                if ($idUsuarioTl > 0) {
                    $metadataTl['usuario_label'] = $usuarioLabelById[$idUsuarioTl] ?? ('Usuario #' . $idUsuarioTl);
                    $timeline[$indexTl]['metadata'] = $metadataTl;
                }
            }
        }
    }

    usort(
        $timeline,
        static function (array $a, array $b): int {
            $ta = (int) ($a['sort_ts'] ?? 0);
            $tb = (int) ($b['sort_ts'] ?? 0);
            if ($ta === $tb) {
                $priority = static function (array $event): int {
                    $origen = strtoupper(trim((string) ($event['origen'] ?? 'SISTEMA')));
                    return match ($origen) {
                        'CONTRATO' => 10,
                        'DOCUMENTO' => 20,
                        'PAGO' => 30,
                        'GARANTIA' => 35,
                        'CARGO' => 40,
                        'ENVIO' => 50,
                        'CONTABLE' => 60,
                        default => 90,
                    };
                };
                $pa = $priority($a);
                $pb = $priority($b);
                if ($pa !== $pb) {
                    return $pb <=> $pa;
                }
                return ((int) ($b['id_referencia'] ?? 0)) <=> ((int) ($a['id_referencia'] ?? 0));
            }
            return $tb <=> $ta;
        }
    );

    $totalTimeline = count($timeline);
    $totalPaginasTimeline = max(1, (int) ceil($totalTimeline / $lineasTimeline));
    $paginaTimeline = min($paginaTimeline, $totalPaginasTimeline);
    $offsetTimeline = ($paginaTimeline - 1) * $lineasTimeline;
    $timelinePageRows = array_slice($timeline, $offsetTimeline, $lineasTimeline);
} catch (Throwable $exception) {
    $loadError = $exception->getMessage() !== ''
        ? $exception->getMessage()
        : 'No fue posible cargar la ficha del contrato.';
}

if ($totalPaginasTimeline > 1) {
    $pages = [1];
    $start = max(2, $paginaTimeline - 2);
    $end = min($totalPaginasTimeline - 1, $paginaTimeline + 2);
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($totalPaginasTimeline > 1) {
        $pages[] = $totalPaginasTimeline;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = null;
    foreach ($pages as $page) {
        if ($prev !== null && $page > $prev + 1) {
            $timelinePaginationItems[] = 'ellipsis';
        }
        $timelinePaginationItems[] = $page;
        $prev = $page;
    }
}

if ($totalPaginasDocumentos > 1) {
    $pages = [1];
    $start = max(2, $paginaDocumentos - 2);
    $end = min($totalPaginasDocumentos - 1, $paginaDocumentos + 2);
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($totalPaginasDocumentos > 1) {
        $pages[] = $totalPaginasDocumentos;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = null;
    foreach ($pages as $page) {
        if ($prev !== null && $page > $prev + 1) {
            $documentosPaginationItems[] = 'ellipsis';
        }
        $documentosPaginationItems[] = $page;
        $prev = $page;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Ficha Contrato #<?php echo (int) $idContratoArriendo; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .msp-timeline {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }

        .msp-timeline-item {
            position: relative;
            padding-left: 3rem;
        }

        .msp-timeline-item::before {
            content: "";
            position: absolute;
            left: 1.35rem;
            top: 0.35rem;
            bottom: -1rem;
            width: 2px;
            background: linear-gradient(180deg, #dde5ef 0%, #ebeff5 100%);
        }

        .msp-timeline-item:last-child::before {
            bottom: 1.6rem;
        }

        .msp-timeline-dot {
            position: absolute;
            left: 0.7rem;
            top: 0.2rem;
            width: 1.35rem;
            height: 1.35rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            color: #fff;
            box-shadow: 0 0 0 3px #f8f9fa;
        }

        .msp-tl-contrato { background-color: #0d6efd; }
        .msp-tl-documento { background-color: #0dcaf0; color: #0a2535; }
        .msp-tl-pago { background-color: #198754; }
        .msp-tl-garantia { background-color: #6f42c1; }
        .msp-tl-cargo { background-color: #f59f00; color: #3b2f00; }
        .msp-tl-envio { background-color: #6c757d; }
        .msp-tl-contable { background-color: #212529; }
        .msp-tl-default { background-color: #adb5bd; color: #17202a; }

        .msp-timeline-card {
            border: 1px solid rgba(0, 0, 0, 0.72);
            border-radius: 0.7rem;
            background: #fff;
            box-shadow: 0 2px 7px rgba(17, 24, 39, 0.08);
        }

        .msp-timeline-heading {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.45rem;
            min-height: 1.85rem;
        }

        .msp-timeline-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: center;
            gap: 0.45rem;
        }

        .msp-timeline-ref {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid #dce3ee;
            border-radius: 999px;
            padding: 0.1rem 0.5rem;
            background: #f8fbff;
            font-size: 0.77rem;
            color: #3f4f62;
        }

        .msp-timeline-meta {
            display: grid;
            grid-template-columns: repeat(3, minmax(150px, 1fr));
            gap: 0.5rem;
            color: #55687d;
            margin-top: 0.65rem;
        }

        .msp-timeline-meta > span,
        .msp-timeline-field {
            display: block;
            border: 1px solid #d8dee7;
            border-radius: 0.45rem;
            background: #f8fafc;
            padding: 0.5rem 0.65rem;
        }

        .msp-timeline-detail-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 0.5rem;
            margin: 0.65rem 0 0;
            padding: 0;
            list-style: none;
        }

        .msp-timeline-detail-list li {
            margin: 0;
        }

        .msp-summary-panel {
            height: 100%;
            border: 1px solid #d8dee7;
            border-radius: 0.6rem;
            background: #fff;
            padding: 1rem;
        }

        .msp-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem 1.25rem;
        }

        .msp-summary-grid .msp-summary-wide {
            grid-column: 1 / -1;
        }

        @media (max-width: 576px) {
            .msp-timeline-item {
                padding-left: 2.5rem;
            }

            .msp-timeline-item::before {
                left: 1.15rem;
            }

            .msp-timeline-dot {
                left: 0.52rem;
            }

            .msp-timeline-meta,
            .msp-summary-grid {
                grid-template-columns: 1fr;
            }

            .msp-summary-grid .msp-summary-wide {
                grid-column: auto;
            }

            .msp-timeline-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a contratos
                </a>
                <a href="#documentos" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Documentos
                </a>
                <a href="<?php echo msp2Escape(msp2Url('cobranza/registrar_pago_contrato.php?id_contrato_arriendo=' . (int) $idContratoArriendo . '&contexto_contrato=1')); ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-cash-stack me-1" aria-hidden="true"></i>Registrar pago
                </a>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('contabilidad/libro.php')); ?>" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Libro diario
                </a>
            </div>
        </div>

        <div class="position-relative mb-4">
            <p class="section-kicker text-center">MSP / Contratos</p>
            <h1 class="form-title text-center mb-0">Ficha Centralizada de Contrato</h1>
            <div class="position-absolute top-0 end-0 text-end">
                <div class="small text-muted text-uppercase">Contrato</div>
                <div class="display-6 fw-bold text-primary lh-1">#<?php echo (int) $idContratoArriendo; ?></div>
            </div>
        </div>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <?php
            $estadoContrato = msp2FichaEstadoContratoBadge((int) ($contrato['estado_contrato'] ?? 0));
            $estadoContratoId = (int) ($contrato['estado_contrato'] ?? 0);
            $puedeTerminarOAnular = in_array($estadoContratoId, [1, 2], true);
            $puedeTraspasar = in_array($estadoContratoId, [1, 2], true);
            $puedeCerrarFinanciero = $estadoContratoId === 3;
            $localesActivos = 0;
            foreach ($localesContrato as $localRow) {
                if ((int) ($localRow['estado_relacion'] ?? 0) === 1) {
                    $localesActivos++;
                }
            }
            $tieneModalidadUf = isset($arriendoModalidadResumen['UF_ESTATICO']) || isset($arriendoModalidadResumen['DINAMICO_MENSUAL']);
            $tieneModalidadClp = isset($arriendoModalidadResumen['CLP_FIJO']);
            $esContratoClpFijo = $tieneModalidadClp && !$tieneModalidadUf;
            $arriendoPactadoRaw = (float) ($contrato['monto_arriendo_pactado'] ?? 0);
            $arriendoPactadoLabel = $esContratoClpFijo
                ? msp2FichaFmtMonto($arriendoPactadoRaw)
                : msp2FichaFmtUf($arriendoPactadoRaw, 2);
            ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-0 pb-0">
                    <h2 class="h5 mb-0">Resumen del Contrato</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-xl-4">
                            <section class="msp-summary-panel">
                                <h3 class="h6 mb-3">Datos contractuales</h3>
                                <div class="msp-summary-grid">
                                    <div>
                                        <div class="small text-muted">Estado</div>
                                        <div><span class="badge <?php echo msp2Escape($estadoContrato[1]); ?>"><?php echo msp2Escape($estadoContrato[0]); ?></span></div>
                                    </div>
                                    <div class="msp-summary-wide">
                                        <div class="small text-muted">Arrendatario</div>
                                        <div class="fw-semibold"><?php echo msp2Escape((string) ($contrato['nombre_locatario'] ?? '-')); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($contrato['rut'] ?? '-')); ?></div>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Inicio</div>
                                        <div><?php echo msp2Escape(msp2FichaFmtFecha((string) ($contrato['fecha_inicio'] ?? ''))); ?></div>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Término pactado</div>
                                        <div><?php echo msp2Escape(msp2FichaFmtFecha((string) ($contrato['fecha_termino_pactada'] ?? ''))); ?></div>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Término efectivo</div>
                                        <div><?php echo msp2Escape(msp2FichaFmtFecha((string) ($contrato['fecha_termino_efectiva'] ?? ''))); ?></div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="col-12 col-xl-3">
                            <section class="msp-summary-panel">
                                <h3 class="h6 mb-3">Locales asociados</h3>
                                <div class="fw-semibold fs-5">
                                    <?php if ($localesContrato === []): ?>
                                        <span class="text-muted">Sin locales asociados.</span>
                                    <?php else: ?>
                                        <?php
                                        $labelsLocales = [];
                                        foreach ($localesContrato as $localRow) {
                                            $codigo = trim((string) ($localRow['cdo_local'] ?? ''));
                                            if ($codigo === '') {
                                                $codigo = '#' . (int) ($localRow['id_local'] ?? 0);
                                            }
                                            $estadoRelacion = (int) ($localRow['estado_relacion'] ?? 0);
                                            $labelsLocales[] = $codigo . ($estadoRelacion === 1 ? '' : ' (no activo)');
                                        }
                                        ?>
                                        <?php echo msp2Escape(implode(' / ', $labelsLocales)); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($localesContrato !== []): ?>
                                    <div class="small text-muted mt-1">Activos: <?php echo $localesActivos; ?> de <?php echo count($localesContrato); ?></div>
                                <?php endif; ?>
                            </section>
                        </div>

                        <div class="col-12 col-xl-5">
                            <section class="msp-summary-panel">
                                <div class="small text-muted">Arriendo pactado</div>
                                <div class="fw-semibold fs-5"><?php echo msp2Escape($arriendoPactadoLabel); ?></div>
                            <?php if ($arriendoDetalleRows !== []): ?>
                                <?php
                                $modalidadLabels = [];
                                foreach ($arriendoModalidadResumen as $codigoModalidad => $countModalidad) {
                                    $labelModalidad = match ($codigoModalidad) {
                                        'UF_ESTATICO' => 'UF fijo x m²',
                                        'DINAMICO_MENSUAL' => 'UF mensual',
                                        'CLP_FIJO' => 'CLP fijo contrato',
                                        'SIN_REGLA' => 'Sin regla',
                                        default => $codigoModalidad,
                                    };
                                    $modalidadLabels[] = $labelModalidad . ' (' . (int) $countModalidad . ')';
                                }
                                ?>
                                <?php if ($modalidadLabels !== []): ?>
                                    <div class="small text-muted mt-1">Modalidades activas: <?php echo msp2Escape(implode(' · ', $modalidadLabels)); ?></div>
                                <?php endif; ?>
                                <div class="small mt-1">
                                    <?php foreach ($arriendoDetalleRows as $arriendoRow): ?>
                                        <?php
                                        if ((int) ($arriendoRow['estado_relacion'] ?? 0) !== 1) {
                                            continue;
                                        }
                                        $codigoLocal = trim((string) ($arriendoRow['cdo_local'] ?? ''));
                                        if ($codigoLocal === '') {
                                            $codigoLocal = '#' . (int) ($arriendoRow['id_contrato_local'] ?? 0);
                                        }
                                        $codigoModalidad = strtoupper(trim((string) ($arriendoRow['codigo_modalidad'] ?? '')));
                                        $metros2 = (float) ($arriendoRow['metros_cuadrados'] ?? 0);
                                        $valorUf = (float) ($arriendoRow['valor_base_uf'] ?? 0);
                                        $valorClp = (float) ($arriendoRow['valor_base_clp'] ?? 0);
                                        $detalleModalidad = match ($codigoModalidad) {
                                            'UF_ESTATICO' => $valorUf > 0
                                                ? (msp2FichaFmtUf($valorUf, 2) . ($metros2 > 0 ? (' x ' . msp2FichaFmtNumero($metros2, 2) . ' m²') : ''))
                                                : 'UF fijo x m²',
                                            'DINAMICO_MENSUAL' => 'UF mensual (valor por período)',
                                            'CLP_FIJO' => $valorClp > 0
                                                ? ('Monto fijo contrato: ' . msp2FichaFmtMonto($valorClp))
                                                : 'CLP fijo contrato',
                                            default => trim((string) ($arriendoRow['nombre_modalidad'] ?? '')) !== ''
                                                ? (string) ($arriendoRow['nombre_modalidad'] ?? '')
                                                : 'Sin modalidad definida',
                                        };
                                        ?>
                                        <div><span class="text-muted"><?php echo msp2Escape($codigoLocal); ?>:</span> <?php echo msp2Escape($detalleModalidad); ?></div>
                                    <?php endforeach; ?>
                                 </div>
                            <?php endif; ?>
                                <hr class="my-3">
                                <div class="small text-muted">Rubro contrato</div>
                                <div><?php echo msp2Escape(trim((string) ($contrato['rubro_contrato'] ?? '')) !== '' ? (string) $contrato['rubro_contrato'] : '-'); ?></div>
                            </section>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="msp-summary-panel">
                                <div class="small text-muted">Deuda vigente agregada</div>
                                <div class="fw-semibold">Saldo: <?php echo msp2Escape(msp2FichaFmtMonto($resumenDeuda['saldo_pendiente'])); ?></div>
                                <div class="small text-muted">Monto documentos: <?php echo msp2Escape(msp2FichaFmtMonto($resumenDeuda['monto_total'])); ?></div>
                                <div class="small text-muted">Pagado aplicado: <?php echo msp2Escape(msp2FichaFmtMonto($resumenDeuda['pagado'])); ?></div>
                                <div class="small text-muted">Documentos: <?php echo (int) $resumenDeuda['documentos']; ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="msp-summary-panel">
                                <div class="small text-muted">Garantía del contrato</div>
                                <div class="fw-semibold">Disponible: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_disponible'])); ?></div>
                                <div class="small text-muted">Pactada: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_pactado'])); ?></div>
                                <div class="small text-muted">Recibida: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_recibido'])); ?></div>
                                <div class="small text-muted">Pendiente de recibir: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_pendiente_recepcion'])); ?></div>
                                <?php if ((float)$resumenGarantia['monto_reservado']>0): ?><div class="small text-muted">Reservada: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_reservado'])); ?></div><?php endif; ?>
                                <?php if ((float)$resumenGarantia['monto_aplicado']>0): ?><div class="small text-muted">Aplicada a deuda: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_aplicado'])); ?></div><?php endif; ?>
                                <?php if ((float)$resumenGarantia['monto_devuelto']>0): ?><div class="small text-muted">Devuelta: <?php echo msp2Escape(msp2FichaFmtMonto($resumenGarantia['monto_devuelto'])); ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="msp-summary-panel">
                                <div class="small text-muted">Accesos operativos</div>
                                <div class="d-grid gap-2 mt-2">
                                    <a class="btn btn-outline-primary btn-sm" href="#documentos">Ver documentos del contrato</a>
                                    <a class="btn btn-outline-dark btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/liquidacion_final.php?id_contrato_arriendo=' . (int) $idContratoArriendo)); ?>">Liquidación final</a>
                                    <?php if ($puedeTraspasar): ?>
                                        <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalTraspasarContratoFicha">Traspasar contrato</button>
                                    <?php endif; ?>
                                    <?php if ($puedeCerrarFinanciero): ?>
                                        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#modalCerrarFinancieroFicha">Cerrar contrato definitivamente</button>
                                    <?php endif; ?>
                                    <?php if ($puedeTerminarOAnular): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalTerminarContratoFicha">Terminar contrato</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAnularContratoFicha">Anular contrato</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-0 pb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="h5 mb-0">Timeline</h2>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                        <input type="hidden" name="lineas_documentos" value="<?php echo (int) $lineasDocumentos; ?>">
                        <label for="lineas_timeline" class="small text-muted mb-0">Líneas</label>
                        <select name="lineas_timeline" id="lineas_timeline" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($lineasPermitidasTimeline as $lineasTl): ?>
                                <option value="<?php echo $lineasTl; ?>" <?php echo $lineasTimeline === $lineasTl ? 'selected' : ''; ?>><?php echo $lineasTl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="card-body">
                    <div class="small text-muted mb-3">
                        Total eventos: <?php echo $totalTimeline; ?>
                    </div>

                    <?php if ($timelinePageRows === []): ?>
                        <div class="text-muted">No hay eventos para el contrato.</div>
                    <?php else: ?>
                        <div class="msp-timeline">
                            <?php foreach ($timelinePageRows as $evento): ?>
                                <?php
                                $origen = strtoupper(trim((string) ($evento['origen'] ?? 'SISTEMA')));
                                $origenBadge = match ($origen) {
                                    'CONTRATO' => 'text-bg-primary',
                                    'DOCUMENTO' => 'text-bg-info text-dark',
                                    'PAGO' => 'text-bg-success',
                                    'GARANTIA' => 'text-bg-primary',
                                    'CARGO' => 'text-bg-warning text-dark',
                                    'ENVIO' => 'text-bg-secondary',
                                    'CONTABLE' => 'text-bg-dark',
                                    default => 'text-bg-light text-dark',
                                };
                                $origenTimelineClass = match ($origen) {
                                    'CONTRATO' => 'msp-tl-contrato',
                                    'DOCUMENTO' => 'msp-tl-documento',
                                    'PAGO' => 'msp-tl-pago',
                                    'GARANTIA' => 'msp-tl-garantia',
                                    'CARGO' => 'msp-tl-cargo',
                                    'ENVIO' => 'msp-tl-envio',
                                    'CONTABLE' => 'msp-tl-contable',
                                    default => 'msp-tl-default',
                                };
                                $origenIcon = match ($origen) {
                                    'CONTRATO' => 'bi-file-earmark-text',
                                    'DOCUMENTO' => 'bi-receipt',
                                    'PAGO' => 'bi-cash-coin',
                                    'GARANTIA' => 'bi-shield-check',
                                    'CARGO' => 'bi-exclamation-triangle',
                                    'ENVIO' => 'bi-send',
                                    'CONTABLE' => 'bi-journal-check',
                                    default => 'bi-dot',
                                };
                                $metadata = is_array($evento['metadata'] ?? null) ? $evento['metadata'] : [];
                                $idAsientoMeta = (int) ($metadata['id_asiento_contable'] ?? 0);
                                $idDocumentoMeta = (int) ($metadata['id_documento'] ?? 0);
                                $idPagoMeta = (int) ($metadata['id_pago'] ?? 0);
                                $idGarantiaMeta = (int) ($metadata['id_garantia'] ?? 0);
                                $comprobanteTipo = strtoupper(trim((string) ($metadata['comprobante_tipo'] ?? '')));
                                $comprobanteId = (int) ($metadata['comprobante_id'] ?? 0);
                                $usuarioLabel = trim((string) ($metadata['usuario_label'] ?? ''));
                                $asientoResumen = $idAsientoMeta > 0 ? ($asientosById[$idAsientoMeta] ?? null) : null;
                                $asientoDetalleRows = $idAsientoMeta > 0 ? ($asientosDetalleByAsiento[$idAsientoMeta] ?? []) : [];
                                $timelineAsientoCollapseId = 'timeline_asiento_' . $idAsientoMeta . '_' . (int) ($evento['id_referencia'] ?? 0);
                                $referencias = [];
                                if ($idDocumentoMeta > 0) {
                                    $referencias[] = 'Doc #' . $idDocumentoMeta;
                                }
                                if ($idPagoMeta > 0) {
                                    $referencias[] = 'Pago #' . $idPagoMeta;
                                }
                                if ($idGarantiaMeta > 0) {
                                    $referencias[] = 'Garantía #' . $idGarantiaMeta;
                                }
                                if ($idAsientoMeta > 0) {
                                    $referencias[] = 'Asiento #' . $idAsientoMeta;
                                }
                                ?>
                                <article class="msp-timeline-item">
                                    <span class="msp-timeline-dot <?php echo msp2Escape($origenTimelineClass); ?>">
                                        <i class="bi <?php echo msp2Escape($origenIcon); ?>"></i>
                                    </span>
                                    <div class="msp-timeline-card p-3">
                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                            <div class="flex-grow-1">
                                                <div class="msp-timeline-heading">
                                                    <span class="badge <?php echo msp2Escape($origenBadge); ?>"><?php echo msp2Escape($origen); ?></span>
                                                    <span class="fw-semibold"><?php echo msp2Escape((string) ($evento['titulo'] ?? 'Evento')); ?></span>
                                                    <span class="small text-muted"><?php echo msp2Escape(msp2FichaFmtFechaHora((string) ($evento['fecha_evento'] ?? ''))); ?></span>
                                                    <?php if ($usuarioLabel !== '' && $usuarioLabel !== '-'): ?>
                                                        <span class="small text-muted"><?php echo msp2Escape($usuarioLabel); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($origen === 'CONTABLE' && is_array($asientoResumen)): ?>
                                                    <div class="small msp-timeline-meta">
                                                        <span><strong>Movimiento:</strong> <?php echo msp2Escape((string) ($asientoResumen['codigo_movimiento'] ?? '-')); ?></span>
                                                        <span><strong>Debe:</strong> <?php echo msp2Escape(msp2FichaFmtMonto((float) ($asientoResumen['total_debe'] ?? 0))); ?></span>
                                                        <span><strong>Haber:</strong> <?php echo msp2Escape(msp2FichaFmtMonto((float) ($asientoResumen['total_haber'] ?? 0))); ?></span>
                                                    </div>
                                                <?php elseif (trim((string) ($evento['detalle'] ?? '')) !== ''): ?>
                                                    <?php $detalleParts = array_values(array_filter(array_map('trim', explode('|', (string) ($evento['detalle'] ?? ''))), static fn (string $p): bool => $p !== '')); ?>
                                                    <?php if (count($detalleParts) > 1): ?>
                                                        <ul class="small msp-timeline-detail-list">
                                                            <?php foreach ($detalleParts as $detallePart): ?>
                                                                <li class="msp-timeline-field"><?php echo msp2Escape($detallePart); ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <div class="small msp-timeline-field mt-2"><?php echo msp2Escape((string) ($evento['detalle'] ?? '')); ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($referencias !== []): ?>
                                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                                        <?php foreach ($referencias as $refLabel): ?>
                                                            <span class="msp-timeline-ref"><?php echo msp2Escape($refLabel); ?></span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="msp-timeline-actions">
                                                <?php if ($idDocumentoMeta > 0): ?>
                                                    <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php?filtroDocumento=' . $idDocumentoMeta)); ?>" class="btn btn-outline-info text-dark btn-sm">Ver doc</a>
                                                <?php endif; ?>
                                                <?php if ($comprobanteId > 0 && in_array($comprobanteTipo, ['RECEPCION', 'DEVOLUCION'], true)): ?>
                                                    <a href="<?php echo msp2Escape(msp2Url('garantias/comprobante.php?tipo=' . rawurlencode($comprobanteTipo) . '&id=' . $comprobanteId)); ?>" class="btn btn-outline-primary btn-sm">Ver comprobante</a>
                                                <?php endif; ?>
                                                <?php if (is_array($asientoResumen) && $asientoDetalleRows !== []): ?>
                                                    <?php $estadoAsientoTimeline = msp2FichaEstadoAsientoBadge((int) ($asientoResumen['estado_asiento'] ?? 0)); ?>
                                                        <button
                                                            class="btn btn-outline-warning text-dark btn-sm"
                                                            type="button"
                                                            data-bs-toggle="collapse"
                                                            data-bs-target="#<?php echo msp2Escape($timelineAsientoCollapseId); ?>"
                                                            aria-expanded="false"
                                                            aria-controls="<?php echo msp2Escape($timelineAsientoCollapseId); ?>"
                                                        >
                                                            Ver detalle asiento
                                                        </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (is_array($asientoResumen) && $asientoDetalleRows !== []): ?>
                                                    <div class="collapse mt-2" id="<?php echo msp2Escape($timelineAsientoCollapseId); ?>">
                                                        <div class="border rounded p-2 bg-light-subtle">
                                                            <div class="small mb-2">
                                                                <span class="fw-semibold"><?php echo msp2Escape((string) ($asientoResumen['codigo_movimiento'] ?? '-')); ?></span>
                                                                <span class="text-muted ms-2">Debe <?php echo msp2Escape(msp2FichaFmtMonto((float) ($asientoResumen['total_debe'] ?? 0))); ?></span>
                                                                <span class="text-muted ms-2">Haber <?php echo msp2Escape(msp2FichaFmtMonto((float) ($asientoResumen['total_haber'] ?? 0))); ?></span>
                                                                <span class="badge <?php echo msp2Escape($estadoAsientoTimeline[1]); ?> ms-1"><?php echo msp2Escape($estadoAsientoTimeline[0]); ?></span>
                                                            </div>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-bordered align-middle mb-0">
                                                                    <thead class="table-light">
                                                                        <tr>
                                                                            <th style="width: 70px;">Línea</th>
                                                                            <th>Cuenta</th>
                                                                            <th>Detalle</th>
                                                                            <th class="text-end" style="width: 130px;">Debe</th>
                                                                            <th class="text-end" style="width: 130px;">Haber</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($asientoDetalleRows as $detalleRow): ?>
                                                                        <?php
                                                                        $codigoCuenta = trim((string) ($detalleRow['codigo_cuenta'] ?? ''));
                                                                        $nombreCuenta = trim((string) ($detalleRow['nombre_cuenta'] ?? ''));
                                                                        $idCuenta = (int) ($detalleRow['id_cuenta_contable'] ?? 0);
                                                                        $cuentaLabel = $codigoCuenta !== '' || $nombreCuenta !== ''
                                                                            ? trim($codigoCuenta . ' - ' . $nombreCuenta, ' -')
                                                                            : 'Cuenta #' . $idCuenta;
                                                                        ?>
                                                                        <tr>
                                                                            <td class="text-nowrap"><?php echo (int) ($detalleRow['linea'] ?? 0); ?></td>
                                                                            <td><?php echo msp2Escape($cuentaLabel); ?></td>
                                                                            <td><?php echo msp2Escape((string) ($detalleRow['glosa_detalle'] ?? '-')); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(msp2FichaFmtMonto((float) ($detalleRow['debe'] ?? 0))); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(msp2FichaFmtMonto((float) ($detalleRow['haber'] ?? 0))); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($timelinePaginationItems !== []): ?>
                        <nav class="mt-3" aria-label="Paginación timeline">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $paginaTimeline <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_timeline' => max(1, $paginaTimeline - 1)]))); ?>">Anterior</a>
                                </li>
                                <?php foreach ($timelinePaginationItems as $item): ?>
                                    <?php if ($item === 'ellipsis'): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php else: ?>
                                        <li class="page-item <?php echo $item === $paginaTimeline ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_timeline' => $item]))); ?>"><?php echo $item; ?></a>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <li class="page-item <?php echo $paginaTimeline >= $totalPaginasTimeline ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_timeline' => min($totalPaginasTimeline, $paginaTimeline + 1)]))); ?>">Siguiente</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($puedeTerminarOAnular || $puedeTraspasar || $puedeCerrarFinanciero): ?>
                <?php if ($puedeTraspasar): ?>
                    <div class="modal fade" id="modalTraspasarContratoFicha" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/traspasar.php')); ?>" data-confirm-message="¿Confirmar el traspaso? El contrato actual quedará en proceso de cierre y se creará uno nuevo para el destino." data-confirm-title="Traspasar contrato" data-confirm-variant="warning">
                                <div class="modal-header"><h2 class="modal-title fs-5">Traspasar contrato #<?php echo (int) $idContratoArriendo; ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                                <div class="modal-body">
                                    <input type="hidden" name="id_contrato_origen" value="<?php echo (int) $idContratoArriendo; ?>">
                                    <input type="hidden" name="redirect_to" value="contratos/ficha.php?id_contrato_arriendo=<?php echo (int) $idContratoArriendo; ?>">
                                    <p class="small text-muted">Crea un nuevo contrato en la misma tienda, copia los locales activos y deja este contrato en proceso de cierre.</p>
                                    <div class="mb-3"><label class="form-label" for="traspaso_arrendatario_ficha">Arrendatario destino</label><select class="form-select" id="traspaso_arrendatario_ficha" name="id_arrendatario_destino" required><option value="">Selecciona arrendatario...</option><?php foreach ($arrendatariosTraspaso as $arrendatarioTraspaso): ?><option value="<?php echo (int) ($arrendatarioTraspaso['id_arrendatario'] ?? 0); ?>"><?php echo msp2Escape(trim((string) ($arrendatarioTraspaso['nombre_locatario'] ?? '')) . ' · ' . msp2RutFormatDisplay((string) ($arrendatarioTraspaso['rut'] ?? ''))); ?></option><?php endforeach; ?></select></div>
                                    <div class="mb-3"><label class="form-label" for="traspaso_fecha_ficha">Fecha de traspaso</label><input type="date" class="form-control" id="traspaso_fecha_ficha" name="fecha_traspaso" required><div class="form-text">Debe corresponder al último día del mes.</div></div>
                                    <div><label class="form-label" for="traspaso_motivo_ficha">Motivo</label><textarea class="form-control" id="traspaso_motivo_ficha" name="motivo_traspaso" rows="3" maxlength="500" required></textarea></div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning">Confirmar traspaso</button></div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($puedeCerrarFinanciero): ?>
                    <div class="modal fade" id="modalCerrarFinancieroFicha" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/finalizar_cierre_financiero.php')); ?>" data-confirm-message="¿Confirmar el cierre financiero definitivo de este contrato?" data-confirm-title="Cerrar contrato definitivamente" data-confirm-variant="danger">
                                <div class="modal-header"><h2 class="modal-title fs-5">Cierre financiero del contrato #<?php echo (int) $idContratoArriendo; ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                                <div class="modal-body">
                                    <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                                    <input type="hidden" name="redirect_to" value="contratos/ficha.php?id_contrato_arriendo=<?php echo (int) $idContratoArriendo; ?>">
                                    <div class="mb-3"><label class="form-label" for="cierre_periodo_ficha">Período de corte</label><input type="month" class="form-control" id="cierre_periodo_ficha" name="periodo_corte_mes" required><div class="form-text">Debe ser el último período facturado y conciliado.</div></div>
                                    <div><label class="form-label" for="cierre_motivo_ficha">Motivo (opcional)</label><textarea class="form-control" id="cierre_motivo_ficha" name="motivo_cierre_financiero" rows="2" maxlength="500"></textarea></div>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-dark">Confirmar cierre definitivo</button></div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($puedeTerminarOAnular): ?>
                <div class="modal fade" id="modalTerminarContratoFicha" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/cerrar.php')); ?>" data-confirm-message="¿Registrar el término operativo de este contrato?" data-confirm-title="Terminar contrato" data-confirm-variant="warning">
                            <div class="modal-header"><h2 class="modal-title fs-5">Terminar contrato #<?php echo (int) $idContratoArriendo; ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                            <div class="modal-body">
                                <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                                <input type="hidden" name="redirect_to" value="contratos/ficha.php?id_contrato_arriendo=<?php echo (int) $idContratoArriendo; ?>">
                                <div class="mb-3"><label class="form-label">Fecha de término efectiva</label><input type="date" class="form-control" name="fecha_termino_efectiva" min="<?php echo msp2Escape(substr((string) ($contrato['fecha_inicio'] ?? ''), 0, 10)); ?>" value="<?php echo date('Y-m-d'); ?>" required></div>
                                <div><label class="form-label">Motivo</label><textarea class="form-control" name="motivo_cierre" rows="3" maxlength="500" required></textarea></div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Registrar término operativo</button></div>
                        </form>
                    </div>
                </div>
                <div class="modal fade" id="modalAnularContratoFicha" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/anular.php')); ?>" data-confirm-message="¿Anular este contrato? Esta acción liberará sus locales si no existen movimientos que lo bloqueen." data-confirm-title="Anular contrato" data-confirm-variant="danger">
                            <div class="modal-header"><h2 class="modal-title fs-5">Anular contrato #<?php echo (int) $idContratoArriendo; ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                            <div class="modal-body">
                                <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                                <input type="hidden" name="redirect_to" value="contratos/ficha.php?id_contrato_arriendo=<?php echo (int) $idContratoArriendo; ?>">
                                <label class="form-label">Motivo de anulación</label><textarea class="form-control" name="motivo_anulacion" rows="3" maxlength="500" required></textarea>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Anular contrato</button></div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4" id="documentos">
                <div class="card-header bg-white border-0 pb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="h5 mb-0">Documentos del Contrato</h2>
                    <form method="get" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                        <input type="hidden" name="lineas_timeline" value="<?php echo (int) $lineasTimeline; ?>">
                        <label for="lineas_documentos" class="small text-muted mb-0">Líneas</label>
                        <select name="lineas_documentos" id="lineas_documentos" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($lineasPermitidasDocumentos as $lineasDoc): ?>
                                <option value="<?php echo $lineasDoc; ?>" <?php echo $lineasDocumentos === $lineasDoc ? 'selected' : ''; ?>><?php echo $lineasDoc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="card-body">
                    <div class="small text-muted mb-3">Totales por período del contrato. Sin filtro de meses intermedios.</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Período</th>
                                    <th>Documento</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-end">Saldo</th>
                                    <th>Estado</th>
                                    <th>Flags</th>
                                    <th>Referencia</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($documentos === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No hay documentos asociados al contrato.</td></tr>
                            <?php else: ?>
                                <?php foreach ($documentos as $doc): ?>
                                    <?php
                                    $idDoc = (int) ($doc['id_documento_cobro'] ?? 0);
                                    $infoDoc = $documentosInfo[$idDoc] ?? [
                                        'pagos_aplicados' => 0,
                                        'pagos_total' => 0.0,
                                        'tiene_evento_recalculo' => false,
                                        'tiene_evento_condonacion' => false,
                                        'cargos_aplicados' => 0,
                                        'cargos_condonados' => 0,
                                        'envios' => 0,
                                        'ultimo_lote' => null,
                                    ];
                                    $estadoDoc = msp2FichaEstadoDocumentoBadge((int) ($doc['estado_documento'] ?? 0));
                                    ?>
                                    <tr>
                                        <td><?php echo msp2Escape(msp2FichaFmtPeriodo((string) ($doc['periodo_facturacion'] ?? ''))); ?></td>
                                        <td>
                                            <div class="fw-semibold">#<?php echo $idDoc; ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($doc['numero_documento'] ?? '-')); ?></div>
                                        </td>
                                        <td class="text-end"><?php echo msp2Escape(msp2FichaFmtMonto((float) ($doc['monto_total'] ?? 0))); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(msp2FichaFmtMonto((float) ($doc['saldo_pendiente'] ?? 0))); ?></td>
                                        <td><span class="badge <?php echo msp2Escape($estadoDoc[1]); ?>"><?php echo msp2Escape($estadoDoc[0]); ?></span></td>
                                        <td>
                                            <?php if ((bool) ($infoDoc['tiene_evento_recalculo'] ?? false)): ?>
                                                <span class="badge text-bg-info text-dark">Recalculado</span>
                                            <?php endif; ?>
                                            <?php if ((bool) ($infoDoc['tiene_evento_condonacion'] ?? false)): ?>
                                                <span class="badge text-bg-warning text-dark">Condonación</span>
                                            <?php endif; ?>
                                            <?php if ((int) ($infoDoc['envios'] ?? 0) > 0): ?>
                                                <span class="badge text-bg-secondary">Envío x<?php echo (int) ($infoDoc['envios'] ?? 0); ?></span>
                                            <?php endif; ?>
                                            <?php if ((int) ($infoDoc['pagos_aplicados'] ?? 0) > 0): ?>
                                                <span class="badge text-bg-success">Pagos x<?php echo (int) ($infoDoc['pagos_aplicados'] ?? 0); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php?filtroDocumento=' . $idDoc)); ?>">Ver</a>
                                                <?php if ((int) ($infoDoc['ultimo_lote'] ?? 0) > 0): ?>
                                                    <span class="small text-muted align-self-center">Lote #<?php echo (int) $infoDoc['ultimo_lote']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($documentosPaginationItems !== []): ?>
                        <nav class="mt-3" aria-label="Paginación documentos">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $paginaDocumentos <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_documentos' => max(1, $paginaDocumentos - 1)]))); ?>">Anterior</a>
                                </li>
                                <?php foreach ($documentosPaginationItems as $item): ?>
                                    <?php if ($item === 'ellipsis'): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php else: ?>
                                        <li class="page-item <?php echo $item === $paginaDocumentos ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_documentos' => $item]))); ?>"><?php echo $item; ?></a>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <li class="page-item <?php echo $paginaDocumentos >= $totalPaginasDocumentos ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . buildMsp2FichaQuery($queryBase, ['pagina_documentos' => min($totalPaginasDocumentos, $paginaDocumentos + 1)]))); ?>">Siguiente</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-0 pb-0">
                    <h2 class="h5 mb-0">Pagos y Movimientos</h2>
                </div>
                <div class="card-body">
                    <?php if ($pagosMovimientos === []): ?>
                        <div class="text-muted">No hay pagos vinculados al contrato.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Pago</th>
                                        <th>Documento</th>
                                        <th class="text-end">Monto</th>
                                        <th>Estado</th>
                                        <th>Aplicaciones</th>
                                        <th>Referencia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pagosMovimientos as $pago): ?>
                                    <?php
                                    $estadoPago = msp2FichaEstadoPagoBadge((int) ($pago['estado_pago'] ?? 0));
                                    $garantiasAplicadas = is_array($pago['garantias_aplicadas'] ?? null) ? $pago['garantias_aplicadas'] : [];
                                    ?>
                                    <tr>
                                        <td><?php echo msp2Escape(msp2FichaFmtFecha((string) ($pago['fecha_pago'] ?? ''))); ?></td>
                                        <td>
                                            <div class="fw-semibold">#<?php echo (int) ($pago['id_pago'] ?? 0); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($pago['medio_pago'] ?? '-')); ?></div>
                                        </td>
                                        <td>
                                            <div>#<?php echo (int) ($pago['id_documento_cobro'] ?? 0); ?></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($pago['numero_documento'] ?? '-')); ?> | <?php echo msp2Escape(msp2FichaFmtPeriodo((string) ($pago['periodo_facturacion'] ?? ''))); ?></div>
                                        </td>
                                        <td class="text-end"><?php echo msp2Escape(msp2FichaFmtMonto((float) ($pago['monto_pagado'] ?? 0))); ?></td>
                                        <td><span class="badge <?php echo msp2Escape($estadoPago[1]); ?>"><?php echo msp2Escape($estadoPago[0]); ?></span></td>
                                        <td>
                                            <?php if ((int) ($pago['aplica_desde_saldo_favor'] ?? 0) === 1): ?>
                                                <span class="badge text-bg-info text-dark">Desde saldo favor</span>
                                            <?php endif; ?>
                                            <?php if ((float) ($pago['monto_saldo_favor_generado'] ?? 0) > 0): ?>
                                                <span class="badge text-bg-secondary">Generó saldo favor <?php echo msp2Escape(msp2FichaFmtMonto((float) ($pago['monto_saldo_favor_generado'] ?? 0))); ?></span>
                                            <?php endif; ?>
                                            <?php foreach ($garantiasAplicadas as $garantia): ?>
                                                <span class="badge text-bg-warning text-dark">Garantía #<?php echo (int) ($garantia['id_garantia'] ?? 0); ?> (<?php echo msp2Escape(msp2FichaFmtMonto((float) ($garantia['monto'] ?? 0))); ?>)</span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td>
                                            <div class="small"><?php echo msp2Escape((string) ($pago['referencia_pago'] ?? '-')); ?></div>
                                            <?php if (trim((string) ($pago['motivo_anulacion'] ?? '')) !== ''): ?>
                                                <div class="small text-danger">Anulación: <?php echo msp2Escape((string) ($pago['motivo_anulacion'] ?? '')); ?></div>
                                            <?php endif; ?>
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
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
