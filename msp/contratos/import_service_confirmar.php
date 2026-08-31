<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

/** @var string $importContext */
$importContext = defined('MSP2_IMPORT_CONTEXT') && is_string(MSP2_IMPORT_CONTEXT)
    ? MSP2_IMPORT_CONTEXT
    : msp2NormalizeText((string) ($_POST['import_context'] ?? 'tiendas'));
$importContext = in_array($importContext, ['tiendas', 'contratos'], true) ? $importContext : 'tiendas';
$redirectRoute = $importContext === 'contratos' ? 'contratos/index.php' : 'tiendas/index.php';
$sessionPreviewKey = $importContext === 'contratos' ? 'msp2_contratos_import_preview' : 'msp2_tiendas_import_preview';
$modoContratoImport = $importContext === 'contratos';
$origenImport = $modoContratoImport ? 'contratos/confirmar_importacion.php' : 'tiendas/confirmar_importacion.php';
$motivoCreacionContrato = $modoContratoImport
    ? 'Creación automática de contrato desde importación de contratos.'
    : 'Creación automática de contrato desde importación de tiendas.';
$observacionCarga = $modoContratoImport
    ? 'Carga automática desde importación de contratos.'
    : 'Carga automática desde importación de tiendas.';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectRoute);
}

$token = trim((string) ($_POST['token'] ?? ''));
$preview = $_SESSION[$sessionPreviewKey] ?? null;

if ($token === '' || !is_array($preview) || !isset($preview['token']) || !is_string($preview['token']) || !hash_equals($preview['token'], $token)) {
    msp2SetFlash('warning', 'La vista previa ya no es válida. Vuelve a cargar el archivo.');
    msp2Redirect($redirectRoute);
}

$createdAt = isset($preview['created_at']) ? (int) $preview['created_at'] : 0;
if ($createdAt <= 0 || (time() - $createdAt) > 1800) {
    unset($_SESSION[$sessionPreviewKey]);
    msp2SetFlash('warning', 'La vista previa expiró (30 min). Vuelve a cargar el archivo.');
    msp2Redirect($redirectRoute);
}

$rows = $preview['rows'] ?? null;
$fileName = (string) ($preview['file_name'] ?? 'archivo');

if (!is_array($rows) || $rows === []) {
    unset($_SESSION[$sessionPreviewKey]);
    msp2SetFlash('warning', 'No hay filas válidas para importar.');
    msp2Redirect($redirectRoute);
}

$loteConGarantia = false;
$loteConArriendoConfig = false;
foreach ($rows as $rowPreview) {
    if (!is_array($rowPreview)) {
        continue;
    }
    if (array_key_exists('garantia_clp', $rowPreview) && $rowPreview['garantia_clp'] !== null && (string) $rowPreview['garantia_clp'] !== '') {
        $loteConGarantia = true;
    }
    if (!empty($rowPreview['arriendo_explicit'])) {
        $loteConArriendoConfig = true;
    }
}

$requiredTables = [
    'msp_tiendas',
    'msp_arrendatarios',
    'msp_rubros',
    'msp_estado_tiendas',
    'msp_locales',
    'msp_ocupacion_locales',
];

foreach ($requiredTables as $tableName) {
    if (!msp2TableExists($conn, $tableName)) {
        msp2SetFlash('warning', 'Falta la tabla `' . $tableName . '` para confirmar la importación.');
        msp2Redirect($redirectRoute);
    }
}

if (
    $loteConGarantia
    && $modoContratoImport
    && (
        !msp2TableExists($conn, 'msp_contratos_arriendo')
        || !msp2TableExists($conn, 'msp_contrato_locales')
        || !msp2TableExists($conn, 'msp_garantias')
    )
) {
    msp2SetFlash('warning', 'La importación incluye `garantia_clp`, pero falta el módulo nuevo de contrato/garantía (`msp_contrato_locales` / `msp_garantias`).');
    msp2Redirect($redirectRoute);
}

$moduloContratoDisponible = $modoContratoImport
    && msp2TableExists($conn, 'msp_contratos_arriendo')
    && msp2TableExists($conn, 'msp_contrato_locales');
$moduloGarantiaDisponible = $moduloContratoDisponible && msp2TableExists($conn, 'msp_garantias');
$moduloHistorialContratoDisponible = $moduloContratoDisponible && msp2TableExists($conn, 'msp_historial_contrato');
$moduloArriendoReglasDisponible = $moduloContratoDisponible
    && msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
    && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo');
$idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

$localInfoById = [];
if ($modoContratoImport && $moduloContratoDisponible) {
    $localIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ocupacionesRow = is_array($row['ocupaciones'] ?? null) ? $row['ocupaciones'] : [];
        foreach ($ocupacionesRow as $ocupacion) {
            if (!is_array($ocupacion)) {
                continue;
            }
            $idLocal = isset($ocupacion['id_local']) ? (int) $ocupacion['id_local'] : 0;
            if ($idLocal > 0) {
                $localIds[$idLocal] = true;
            }
        }
    }

    $localIds = array_keys($localIds);
    foreach (array_chunk($localIds, 500) as $chunk) {
        $placeholders = [];
        foreach ($chunk as $index => $idLocal) {
            $placeholders[] = ':id_local_' . $index;
        }

        $stmtLocales = $conn->prepare(
            'SELECT id_local, cdo_local, valor_arriendo_uf
             FROM dbo.msp_locales
             WHERE id_local IN (' . implode(', ', $placeholders) . ')'
        );
        foreach ($chunk as $index => $idLocal) {
            $stmtLocales->bindValue(':id_local_' . $index, (int) $idLocal, PDO::PARAM_INT);
        }
        $stmtLocales->execute();
        while (($row = $stmtLocales->fetch()) !== false) {
            $idLocal = (int) ($row['id_local'] ?? 0);
            $valorUf = $row['valor_arriendo_uf'] ?? null;
            $cdoLocal = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
            if ($idLocal > 0) {
                $localInfoById[$idLocal] = [
                    'valor_arriendo_uf' => $valorUf !== null ? (float) $valorUf : 0.0,
                    'cdo_local' => $cdoLocal,
                ];
            }
        }
    }
}

if ($loteConGarantia && !$modoContratoImport) {
    unset($_SESSION[$sessionPreviewKey]);
    msp2SetFlash('warning', 'La columna `garantia_clp` no se procesa en Importar Tiendas. Usa Importar Contratos.');
    msp2Redirect($redirectRoute);
}

if ($loteConArriendoConfig && !$modoContratoImport) {
    unset($_SESSION[$sessionPreviewKey]);
    msp2SetFlash('warning', 'Las columnas de arriendo contrato-local solo se procesan en Importar Contratos.');
    msp2Redirect($redirectRoute);
}

if ($loteConArriendoConfig && $modoContratoImport && !$moduloArriendoReglasDisponible) {
    msp2SetFlash('warning', 'La importación incluye configuración de arriendo, pero falta el módulo de reglas (`msp_contrato_local_arriendo_regla` / `msp_tipo_modalidad_arriendo`).');
    msp2Redirect($redirectRoute);
}

function msp2TiendaConfirmParseDate(?string $value): ?string
{
    $raw = msp2NormalizeText($value);

    if ($raw === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $raw);

    if ($date === false || $date->format('Y-m-d') !== $raw) {
        return null;
    }

    return $raw;
}

function msp2TiendaConfirmResolveCatalogId(
    PDO $conn,
    array &$cache,
    string $description,
    PDOStatement $findStmt,
    PDOStatement $insertStmt
): int {
    $desc = msp2NormalizeText($description);
    $key = msp2NormalizeLookupKey($desc);

    if ($desc === '' || $key === '') {
        throw new RuntimeException('Descripción de catálogo inválida durante confirmación.');
    }

    if (isset($cache[$key]) && (int) $cache[$key] > 0) {
        return (int) $cache[$key];
    }

    $findStmt->bindValue(':desc', $desc, PDO::PARAM_STR);
    $findStmt->execute();
    $existingId = $findStmt->fetchColumn();

    if ($existingId !== false && (int) $existingId > 0) {
        $cache[$key] = (int) $existingId;
        return (int) $existingId;
    }

    try {
        $insertStmt->bindValue(':desc', $desc, PDO::PARAM_STR);
        $insertStmt->execute();
    } catch (PDOException $exception) {
        // Puede fallar por UNIQUE en inserciones concurrentes; se reintenta con SELECT.
    }

    $newId = (int) $conn->lastInsertId();
    if ($newId <= 0) {
        try {
            $scopeIdentityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
            $newId = (int) $scopeIdentityStmt->fetchColumn();
        } catch (Throwable $exception) {
            $newId = 0;
        }
    }

    if ($newId <= 0) {
        $findStmt->bindValue(':desc', $desc, PDO::PARAM_STR);
        $findStmt->execute();
        $newId = (int) $findStmt->fetchColumn();
    }

    if ($newId <= 0) {
        throw new RuntimeException('No fue posible resolver catálogo para: ' . $desc . '.');
    }

    $cache[$key] = $newId;

    return $newId;
}

$rubrosCache = [];
$estadosCache = [];

$findRubroStmt = $conn->prepare(
    'SELECT TOP 1 id_rubro
     FROM dbo.msp_rubros
     WHERE LTRIM(RTRIM(LOWER(nombre_rubro))) = LTRIM(RTRIM(LOWER(:desc)))
     ORDER BY id_rubro ASC'
);
$insertRubroStmt = $conn->prepare('INSERT INTO dbo.msp_rubros (nombre_rubro) VALUES (:desc)');

$findEstadoStmt = $conn->prepare(
    'SELECT TOP 1 id_estado_tienda
     FROM dbo.msp_estado_tiendas
     WHERE LTRIM(RTRIM(LOWER(desc_estado))) = LTRIM(RTRIM(LOWER(:desc)))
     ORDER BY id_estado_tienda ASC'
);
$insertEstadoStmt = $conn->prepare('INSERT INTO dbo.msp_estado_tiendas (desc_estado) VALUES (:desc)');

$checkArrendatarioStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_arrendatarios WHERE id_arrendatario = :id_arrendatario');
$checkLocalStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_locales WHERE id_local = :id_local');

$checkTiendaStmt = $conn->prepare(
    'SELECT COUNT(*)
     FROM dbo.msp_tiendas
     WHERE id_tienda = :id_tienda'
);

$insertTiendaStmt = $conn->prepare(
    'INSERT INTO dbo.msp_tiendas
        (id_rubro, id_arrendatario, id_estado_tienda, nombre_comercial, fecha_inicio)
     VALUES
        (:id_rubro, :id_arrendatario, :id_estado_tienda, :nombre_comercial, :fecha_inicio)'
);

$updateTiendaStmt = $conn->prepare(
    'UPDATE dbo.msp_tiendas
     SET id_rubro = :id_rubro,
         id_arrendatario = :id_arrendatario,
         id_estado_tienda = :id_estado_tienda,
         nombre_comercial = :nombre_comercial,
         fecha_inicio = :fecha_inicio
     WHERE id_tienda = :id_tienda'
);

$deleteOcupacionesStmt = $conn->prepare('DELETE FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
$selectOldLocalesStmt = $conn->prepare('SELECT DISTINCT id_local FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
$insertOcupacionStmt = $conn->prepare(
    'INSERT INTO dbo.msp_ocupacion_locales
        (id_tienda, id_local, fecha_inicio, fecha_termino)
     VALUES
        (:id_tienda, :id_local, :fecha_inicio, :fecha_termino)'
);

$findContratoActivoStmt = null;
$insertContratoStmt = null;
$updateContratoBaseStmt = null;
$findGarantiaStmt = null;
$insertGarantiaStmt = null;
$insertHistorialContratoStmt = null;
$findContratoLocalesActivosStmt = null;
$insertContratoLocalStmt = null;
$closeContratoLocalStmt = null;
$countCargosPendientesNuevoStmt = null;
$countCargosPendientesLegacyStmt = null;
$countGarantiasActivasStmt = null;
$countGarantiasConSaldoStmt = null;
$findReglaArriendoDefaultStmt = null;
$insertReglaArriendoDefaultStmt = null;
$updateReglaArriendoDefaultStmt = null;
$tieneColumnaGarantiaContratoLocal = $moduloGarantiaDisponible && msp2ColumnExists($conn, 'msp_garantias', 'id_contrato_local');
$modalidadArriendoIdByCode = [];
$idTipoDescuentoMontoMensualClp = 0;

if ($moduloArriendoReglasDisponible) {
    $stmtModalidades = $conn->query(
        'SELECT id_modalidad_arriendo, codigo_modalidad
         FROM dbo.msp_tipo_modalidad_arriendo
         WHERE activo = 1'
    );
    while (($rowModalidad = $stmtModalidades->fetch()) !== false) {
        $codigo = strtoupper(trim((string) ($rowModalidad['codigo_modalidad'] ?? '')));
        $idModalidad = (int) ($rowModalidad['id_modalidad_arriendo'] ?? 0);
        if ($codigo !== '' && $idModalidad > 0) {
            $modalidadArriendoIdByCode[$codigo] = $idModalidad;
        }
    }

    if (msp2TableExists($conn, 'msp_tipo_descuento_arriendo')) {
        $stmtTipoDescuento = $conn->query(
            "SELECT TOP 1 id_tipo_descuento_arriendo
             FROM dbo.msp_tipo_descuento_arriendo
             WHERE codigo_descuento = N'MONTO_CLP_MENSUAL'
               AND activo = 1
             ORDER BY id_tipo_descuento_arriendo ASC"
        );
        $idTipoDescuentoMontoMensualClp = (int) $stmtTipoDescuento->fetchColumn();
    }
}

if ($moduloContratoDisponible) {
    $findContratoActivoStmt = $conn->prepare(
        'SELECT TOP 1 id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE id_tienda = :id_tienda
           AND estado_contrato IN (1,2)
         ORDER BY id_contrato_arriendo DESC'
    );

    $insertContratoStmt = $conn->prepare(
        'INSERT INTO dbo.msp_contratos_arriendo
            (id_tienda, id_arrendatario, fecha_inicio, fecha_termino_pactada, dia_cobro, monto_arriendo_pactado, rubro_contrato)
         VALUES
            (:id_tienda, :id_arrendatario, :fecha_inicio, :fecha_termino_pactada, :dia_cobro, :monto_arriendo_pactado, :rubro_contrato)'
    );

    $updateContratoBaseStmt = $conn->prepare(
        'UPDATE dbo.msp_contratos_arriendo
         SET id_arrendatario = :id_arrendatario,
             fecha_inicio = :fecha_inicio,
             monto_arriendo_pactado = :monto_arriendo_pactado
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );

    if ($moduloGarantiaDisponible) {
        $findGarantiaStmt = $conn->prepare(
            'SELECT TOP 1 id_garantia
             FROM dbo.msp_garantias
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND id_local = :id_local'
        );

        if ($tieneColumnaGarantiaContratoLocal) {
            $insertGarantiaStmt = $conn->prepare(
                'INSERT INTO dbo.msp_garantias
                    (id_contrato_arriendo, id_local, id_contrato_local, fecha_constitucion, monto_inicial, observaciones)
                 VALUES
                    (:id_contrato_arriendo, :id_local, :id_contrato_local, :fecha_constitucion, :monto_inicial, :observaciones)'
            );
        } else {
            $insertGarantiaStmt = $conn->prepare(
                'INSERT INTO dbo.msp_garantias
                    (id_contrato_arriendo, id_local, fecha_constitucion, monto_inicial, observaciones)
                 VALUES
                    (:id_contrato_arriendo, :id_local, :fecha_constitucion, :monto_inicial, :observaciones)'
            );
        }
    }

    $findContratoLocalesActivosStmt = $conn->prepare(
        'SELECT id_contrato_local, id_local, fecha_inicio, fecha_termino
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_relacion = 1'
    );

    $insertContratoLocalStmt = $conn->prepare(
        'INSERT INTO dbo.msp_contrato_locales
            (id_contrato_arriendo, id_local, fecha_inicio, fecha_termino, orden_visual, estado_relacion, observaciones)
         VALUES
            (:id_contrato_arriendo, :id_local, :fecha_inicio, :fecha_termino, :orden_visual, 1, :observaciones)'
    );

    $closeContratoLocalStmt = $conn->prepare(
        'UPDATE dbo.msp_contrato_locales
         SET estado_relacion = 2,
             fecha_termino = :fecha_termino
         WHERE id_contrato_local = :id_contrato_local
           AND estado_relacion = 1'
    );

    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $countCargosPendientesNuevoStmt = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_cargos_contrato_local
             WHERE id_contrato_local = :id_contrato_local
               AND estado_cargo IN (1,2)'
        );
    }
    if (msp2TableExists($conn, 'msp_cargos_salida')) {
        $countCargosPendientesLegacyStmt = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_cargos_salida
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND id_local = :id_local
               AND estado_cargo IN (1,2)'
        );
    }
    if ($moduloGarantiaDisponible) {
        $countGarantiasActivasStmt = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_garantias
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND id_local = :id_local
               AND estado_garantia NOT IN (5,6)'
        );
        if (msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
            $countGarantiasConSaldoStmt = $conn->prepare(
                'SELECT COUNT(*)
                 FROM dbo.msp_garantias g
                 INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
                 WHERE g.id_contrato_arriendo = :id_contrato_arriendo
                   AND g.id_local = :id_local
                   AND (gr.monto_disponible > 0 OR gr.monto_reservado > 0)'
            );
        }
    }

    if ($moduloHistorialContratoDisponible) {
        $insertHistorialContratoStmt = $conn->prepare(
            'INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
                (:id_contrato_arriendo, :tipo_evento, :id_usuario, :detalle_evento, :motivo_evento)'
        );
    }

    if ($moduloArriendoReglasDisponible) {
        $findReglaArriendoDefaultStmt = $conn->prepare(
            'SELECT TOP 1 id_regla_arriendo
             FROM dbo.msp_contrato_local_arriendo_regla
             WHERE id_contrato_local = :id_contrato_local
               AND es_default = 1
             ORDER BY
                CASE WHEN estado_regla = 1 THEN 0 ELSE 1 END,
                id_regla_arriendo DESC'
        );

        $insertReglaArriendoDefaultStmt = $conn->prepare(
            'INSERT INTO dbo.msp_contrato_local_arriendo_regla
                (id_contrato_local, fecha_inicio, fecha_termino, id_modalidad_arriendo, valor_base_uf, valor_base_clp, id_tipo_descuento_arriendo, descuento_mensual_clp, codigo_grupo_modalidad, prioridad, estado_regla, es_default, observaciones)
             VALUES
                (:id_contrato_local, :fecha_inicio, :fecha_termino, :id_modalidad_arriendo, :valor_base_uf, :valor_base_clp, :id_tipo_descuento_arriendo, :descuento_mensual_clp, :codigo_grupo_modalidad, 100, 1, 1, :observaciones)'
        );

        $updateReglaArriendoDefaultStmt = $conn->prepare(
            'UPDATE dbo.msp_contrato_local_arriendo_regla
             SET fecha_inicio = :fecha_inicio,
                 fecha_termino = :fecha_termino,
                 id_modalidad_arriendo = :id_modalidad_arriendo,
                 valor_base_uf = :valor_base_uf,
                 valor_base_clp = :valor_base_clp,
                 id_tipo_descuento_arriendo = :id_tipo_descuento_arriendo,
                 descuento_mensual_clp = :descuento_mensual_clp,
                 codigo_grupo_modalidad = :codigo_grupo_modalidad,
                 prioridad = 100,
                 estado_regla = 1,
                 es_default = 1,
                 observaciones = :observaciones,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_regla_arriendo = :id_regla_arriendo'
        );
    }
}

$insertados = 0;
$actualizados = 0;
$sinCambios = 0;
$contratosCreados = 0;
$garantiasAplicadas = 0;
$reglasArriendoAplicadas = 0;
$localesImpactados = [];

try {
    $conn->beginTransaction();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Formato de fila inválido en lote de importación.');
        }

        $action = strtoupper(msp2NormalizeText((string) ($row['action'] ?? '')));
        if ($action === 'SIN_CAMBIOS') {
            $sinCambios++;
            continue;
        }

        if ($action !== 'CREAR' && $action !== 'ACTUALIZAR') {
            throw new RuntimeException('Acción inválida durante confirmación de tiendas.');
        }

        $idArrendatario = isset($row['id_arrendatario']) ? (int) $row['id_arrendatario'] : 0;
        $idTiendaObjetivo = isset($row['id_tienda_objetivo']) ? (int) $row['id_tienda_objetivo'] : 0;
        $nombreComercial = msp2NormalizeText((string) ($row['nombre_comercial'] ?? ''));
        $rubroDesc = msp2NormalizeText((string) ($row['rubro_desc'] ?? ''));
        $estadoDesc = msp2NormalizeText((string) ($row['estado_desc'] ?? ''));
        $fechaInicioTienda = msp2TiendaConfirmParseDate(isset($row['fecha_inicio_tienda']) ? (string) $row['fecha_inicio_tienda'] : null);
        $fechaInicioOcup = msp2TiendaConfirmParseDate(isset($row['fecha_inicio_ocupacion']) ? (string) $row['fecha_inicio_ocupacion'] : null);
        $fechaTerminoOcup = msp2TiendaConfirmParseDate(isset($row['fecha_termino_ocupacion']) ? (string) $row['fecha_termino_ocupacion'] : null);
        $garantiaClp = null;
        if ($modoContratoImport && array_key_exists('garantia_clp', $row) && $row['garantia_clp'] !== null && (string) $row['garantia_clp'] !== '') {
            $garantiaRaw = trim((string) $row['garantia_clp']);
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $garantiaRaw) !== 1) {
                throw new RuntimeException('Monto de garantía inválido en lote de importación.');
            }

            $garantiaClp = number_format((float) $garantiaRaw, 2, '.', '');
        }

        $arriendoModalidad = strtoupper(msp2NormalizeText((string) ($row['arriendo_modalidad'] ?? 'UF_ESTATICO')));
        if (!in_array($arriendoModalidad, ['UF_ESTATICO', 'CLP_FIJO'], true)) {
            throw new RuntimeException('La importación solo permite arriendo mensual fijo en UF o pesos.');
        }
        $arriendoValorUf = null;
        if (array_key_exists('arriendo_valor_uf', $row) && $row['arriendo_valor_uf'] !== null && (string) $row['arriendo_valor_uf'] !== '') {
            $arriendoValorUf = number_format((float) $row['arriendo_valor_uf'], 4, '.', '');
        }
        $arriendoValorClp = null;
        if (array_key_exists('arriendo_valor_clp', $row) && $row['arriendo_valor_clp'] !== null && (string) $row['arriendo_valor_clp'] !== '') {
            $arriendoValorClp = number_format((float) $row['arriendo_valor_clp'], 2, '.', '');
        }
        $arriendoDescuentoClp = '0.00';
        if (array_key_exists('arriendo_descuento_clp', $row) && $row['arriendo_descuento_clp'] !== null && (string) $row['arriendo_descuento_clp'] !== '') {
            $arriendoDescuentoClp = number_format((float) $row['arriendo_descuento_clp'], 2, '.', '');
        }
        $arriendoExplicit = !empty($row['arriendo_explicit']);

        $ocupaciones = is_array($row['ocupaciones'] ?? null) ? $row['ocupaciones'] : [];

        if ($idArrendatario <= 0 || $nombreComercial === '' || $rubroDesc === '' || $estadoDesc === '' || $fechaInicioOcup === null || $ocupaciones === []) {
            throw new RuntimeException('Fila inválida durante confirmación de tiendas.');
        }

        if (mb_strlen($nombreComercial) > 200 || mb_strlen($rubroDesc) > 150 || mb_strlen($estadoDesc) > 100) {
            throw new RuntimeException('Fila supera largos máximos permitidos durante confirmación de tiendas.');
        }

        if ($fechaTerminoOcup !== null && $fechaTerminoOcup < $fechaInicioOcup) {
            throw new RuntimeException('Fecha término ocupación menor a fecha inicio ocupación durante confirmación.');
        }

        if ($modoContratoImport && $arriendoExplicit && $arriendoModalidad === 'CLP_FIJO' && $arriendoValorClp === null) {
            throw new RuntimeException('Configuración de arriendo inválida: CLP_FIJO requiere valor_arriendo_clp.');
        }

        $checkArrendatarioStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $checkArrendatarioStmt->execute();
        if ((int) $checkArrendatarioStmt->fetchColumn() === 0) {
            throw new RuntimeException('Arrendatario no existe durante confirmación.');
        }

        $idRubro = msp2TiendaConfirmResolveCatalogId($conn, $rubrosCache, $rubroDesc, $findRubroStmt, $insertRubroStmt);
        $idEstadoTienda = msp2TiendaConfirmResolveCatalogId($conn, $estadosCache, $estadoDesc, $findEstadoStmt, $insertEstadoStmt);

        if ($action === 'CREAR') {
            $insertTiendaStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
            $insertTiendaStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
            $insertTiendaStmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
            $insertTiendaStmt->bindValue(':nombre_comercial', $nombreComercial, PDO::PARAM_STR);
            $insertTiendaStmt->bindValue(':fecha_inicio', $fechaInicioTienda, $fechaInicioTienda !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertTiendaStmt->execute();

            $idTienda = (int) $conn->lastInsertId();
            if ($idTienda <= 0) {
                $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
                $idTienda = (int) $identityStmt->fetchColumn();
            }

            if ($idTienda <= 0) {
                throw new RuntimeException('No fue posible recuperar ID de la tienda insertada.');
            }

            $insertados++;
        } else {
            if ($idTiendaObjetivo <= 0) {
                throw new RuntimeException('No se pudo resolver la tienda a actualizar.');
            }

            $checkTiendaStmt->bindValue(':id_tienda', $idTiendaObjetivo, PDO::PARAM_INT);
            $checkTiendaStmt->execute();
            if ((int) $checkTiendaStmt->fetchColumn() === 0) {
                throw new RuntimeException('La tienda a actualizar ya no existe.');
            }

            $idTienda = $idTiendaObjetivo;

            $updateTiendaStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $updateTiendaStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
            $updateTiendaStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
            $updateTiendaStmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
            $updateTiendaStmt->bindValue(':nombre_comercial', $nombreComercial, PDO::PARAM_STR);
            $updateTiendaStmt->bindValue(':fecha_inicio', $fechaInicioTienda, $fechaInicioTienda !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updateTiendaStmt->execute();

            $actualizados++;
        }

        $deleteOcupacionesStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);

        $selectOldLocalesStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $selectOldLocalesStmt->execute();
        while (($oldLocalId = $selectOldLocalesStmt->fetchColumn()) !== false) {
            $localesImpactados[] = (int) $oldLocalId;
        }

        $deleteOcupacionesStmt->execute();

        foreach ($ocupaciones as $ocupacion) {
            if (!is_array($ocupacion)) {
                continue;
            }

            $idLocal = isset($ocupacion['id_local']) ? (int) $ocupacion['id_local'] : 0;
            if ($idLocal <= 0) {
                throw new RuntimeException('Local inválido durante confirmación.');
            }

            $checkLocalStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $checkLocalStmt->execute();
            if ((int) $checkLocalStmt->fetchColumn() === 0) {
                throw new RuntimeException('Uno de los locales ya no existe en BD.');
            }

            $insertOcupacionStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $insertOcupacionStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $insertOcupacionStmt->bindValue(':fecha_inicio', $fechaInicioOcup, PDO::PARAM_STR);
            $insertOcupacionStmt->bindValue(':fecha_termino', $fechaTerminoOcup, $fechaTerminoOcup !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertOcupacionStmt->execute();

            $localesImpactados[] = $idLocal;
        }

        if ($moduloContratoDisponible && $findContratoActivoStmt instanceof PDOStatement && $insertContratoStmt instanceof PDOStatement) {
            $findContratoActivoStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $findContratoActivoStmt->execute();
            $idContratoArriendo = (int) $findContratoActivoStmt->fetchColumn();
            $fechaInicioContratoBase = $fechaInicioTienda ?? $fechaInicioOcup ?? (new DateTimeImmutable('today'))->format('Y-m-d');

            $idsLocalesImport = [];
            foreach ($ocupaciones as $ocupacionContrato) {
                if (!is_array($ocupacionContrato)) {
                    continue;
                }
                $idLocalContrato = isset($ocupacionContrato['id_local']) ? (int) $ocupacionContrato['id_local'] : 0;
                if ($idLocalContrato > 0) {
                    $idsLocalesImport[] = $idLocalContrato;
                }
            }
            $idsLocalesImport = array_values(array_unique($idsLocalesImport));

            $montoArriendoPactado = null;
            if ($idsLocalesImport !== []) {
                $sumUf = 0.0;
                foreach ($idsLocalesImport as $idLocal) {
                    $sumUf += (float) (($localInfoById[$idLocal]['valor_arriendo_uf'] ?? 0.0));
                }
                $montoArriendoPactado = number_format($sumUf, 2, '.', '');
            }

            if ($idContratoArriendo <= 0) {
                $insertContratoStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
                $insertContratoStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
                $insertContratoStmt->bindValue(':fecha_inicio', $fechaInicioContratoBase, PDO::PARAM_STR);
                $insertContratoStmt->bindValue(':fecha_termino_pactada', null, PDO::PARAM_NULL);
                $insertContratoStmt->bindValue(':dia_cobro', 1, PDO::PARAM_INT);
                $insertContratoStmt->bindValue(
                    ':monto_arriendo_pactado',
                    $montoArriendoPactado,
                    $montoArriendoPactado !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
                );
                $insertContratoStmt->bindValue(':rubro_contrato', null, PDO::PARAM_NULL);
                $insertContratoStmt->execute();

                $idContratoArriendo = (int) $conn->lastInsertId();
                if ($idContratoArriendo <= 0) {
                    $identityStmtContrato = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
                    $idContratoArriendo = (int) $identityStmtContrato->fetchColumn();
                }

                if ($idContratoArriendo <= 0) {
                    throw new RuntimeException('No fue posible crear contrato para la tienda importada.');
                }

                $contratosCreados++;

                if ($insertHistorialContratoStmt instanceof PDOStatement && $idUsuarioSesion > 0) {
                    $detalleCreacion = [
                        'origen' => $origenImport,
                        'archivo' => $fileName,
                        'id_tienda' => $idTienda,
                        'id_arrendatario' => $idArrendatario,
                        'fecha_inicio' => $fechaInicioContratoBase,
                    ];
                    $detalleCreacionJson = json_encode($detalleCreacion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $insertHistorialContratoStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $insertHistorialContratoStmt->bindValue(':tipo_evento', 'CREACION', PDO::PARAM_STR);
                    $insertHistorialContratoStmt->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
                    $insertHistorialContratoStmt->bindValue(':detalle_evento', $detalleCreacionJson !== false ? $detalleCreacionJson : null, $detalleCreacionJson !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $insertHistorialContratoStmt->bindValue(':motivo_evento', $motivoCreacionContrato, PDO::PARAM_STR);
                    $insertHistorialContratoStmt->execute();
                }
            } elseif ($updateContratoBaseStmt instanceof PDOStatement) {
                $updateContratoBaseStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                $updateContratoBaseStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
                $updateContratoBaseStmt->bindValue(':fecha_inicio', $fechaInicioContratoBase, PDO::PARAM_STR);
                $updateContratoBaseStmt->bindValue(
                    ':monto_arriendo_pactado',
                    $montoArriendoPactado,
                    $montoArriendoPactado !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
                );
                $updateContratoBaseStmt->execute();
            }

            $contratoLocalesActivos = [];
            if ($findContratoLocalesActivosStmt instanceof PDOStatement) {
                $findContratoLocalesActivosStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                $findContratoLocalesActivosStmt->execute();
                while (($rowContratoLocal = $findContratoLocalesActivosStmt->fetch()) !== false) {
                    $idLocalActivo = (int) ($rowContratoLocal['id_local'] ?? 0);
                    $idContratoLocalActivo = (int) ($rowContratoLocal['id_contrato_local'] ?? 0);
                    if ($idLocalActivo > 0 && $idContratoLocalActivo > 0) {
                        $contratoLocalesActivos[$idLocalActivo] = [
                            'id_contrato_local' => $idContratoLocalActivo,
                            'fecha_inicio' => msp2TiendaConfirmParseDate(isset($rowContratoLocal['fecha_inicio']) ? (string) $rowContratoLocal['fecha_inicio'] : null) ?? $fechaInicioContratoBase,
                            'fecha_termino' => msp2TiendaConfirmParseDate(isset($rowContratoLocal['fecha_termino']) ? (string) $rowContratoLocal['fecha_termino'] : null),
                        ];
                    }
                }
            }

            $idsLocalesActivos = array_values(array_unique(array_keys($contratoLocalesActivos)));
            $idsLocalesRemoverContrato = array_values(array_diff($idsLocalesActivos, $idsLocalesImport));

            foreach ($idsLocalesRemoverContrato as $idLocalRemover) {
                $idContratoLocalRemover = (int) (($contratoLocalesActivos[$idLocalRemover]['id_contrato_local'] ?? 0));
                if ($idContratoLocalRemover <= 0) {
                    continue;
                }

                if ($countCargosPendientesNuevoStmt instanceof PDOStatement) {
                    $countCargosPendientesNuevoStmt->bindValue(':id_contrato_local', $idContratoLocalRemover, PDO::PARAM_INT);
                    $countCargosPendientesNuevoStmt->execute();
                    if ((int) $countCargosPendientesNuevoStmt->fetchColumn() > 0) {
                        throw new RuntimeException('No se puede cerrar relación contrato-local por importación: existen cargos pendientes/reservados.');
                    }
                }

                if ($countCargosPendientesLegacyStmt instanceof PDOStatement) {
                    $countCargosPendientesLegacyStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $countCargosPendientesLegacyStmt->bindValue(':id_local', $idLocalRemover, PDO::PARAM_INT);
                    $countCargosPendientesLegacyStmt->execute();
                    if ((int) $countCargosPendientesLegacyStmt->fetchColumn() > 0) {
                        throw new RuntimeException('No se puede cerrar relación contrato-local por importación: existen cargos legacy pendientes/reservados.');
                    }
                }

                if ($countGarantiasActivasStmt instanceof PDOStatement) {
                    $countGarantiasActivasStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $countGarantiasActivasStmt->bindValue(':id_local', $idLocalRemover, PDO::PARAM_INT);
                    $countGarantiasActivasStmt->execute();
                    if ((int) $countGarantiasActivasStmt->fetchColumn() > 0) {
                        throw new RuntimeException('No se puede cerrar relación contrato-local por importación: el local mantiene garantía activa.');
                    }
                }

                if ($countGarantiasConSaldoStmt instanceof PDOStatement) {
                    $countGarantiasConSaldoStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $countGarantiasConSaldoStmt->bindValue(':id_local', $idLocalRemover, PDO::PARAM_INT);
                    $countGarantiasConSaldoStmt->execute();
                    if ((int) $countGarantiasConSaldoStmt->fetchColumn() > 0) {
                        throw new RuntimeException('No se puede cerrar relación contrato-local por importación: el local mantiene saldo de garantía.');
                    }
                }

                if ($closeContratoLocalStmt instanceof PDOStatement) {
                    $closeContratoLocalStmt->bindValue(':id_contrato_local', $idContratoLocalRemover, PDO::PARAM_INT);
                    $closeContratoLocalStmt->bindValue(':fecha_termino', $fechaTerminoOcup ?? $fechaInicioOcup, PDO::PARAM_STR);
                    $closeContratoLocalStmt->execute();
                }
            }

            if ($insertContratoLocalStmt instanceof PDOStatement) {
                $ordenVisual = 1;
                foreach ($idsLocalesImport as $idLocalOrden) {
                    if (isset($contratoLocalesActivos[$idLocalOrden])) {
                        $ordenVisual++;
                        continue;
                    }

                    $insertContratoLocalStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $insertContratoLocalStmt->bindValue(':id_local', $idLocalOrden, PDO::PARAM_INT);
                    $insertContratoLocalStmt->bindValue(':fecha_inicio', $fechaInicioContratoBase, PDO::PARAM_STR);
                    $insertContratoLocalStmt->bindValue(':fecha_termino', null, PDO::PARAM_NULL);
                    $insertContratoLocalStmt->bindValue(':orden_visual', $ordenVisual, PDO::PARAM_INT);
                    $insertContratoLocalStmt->bindValue(':observaciones', $observacionCarga, PDO::PARAM_STR);
                    $insertContratoLocalStmt->execute();

                    $idContratoLocalNuevo = (int) $conn->lastInsertId();
                    if ($idContratoLocalNuevo <= 0) {
                        $identityStmtContratoLocal = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
                        $idContratoLocalNuevo = (int) $identityStmtContratoLocal->fetchColumn();
                    }
                    if ($idContratoLocalNuevo > 0) {
                        $contratoLocalesActivos[$idLocalOrden] = [
                            'id_contrato_local' => $idContratoLocalNuevo,
                            'fecha_inicio' => $fechaInicioContratoBase,
                            'fecha_termino' => null,
                        ];
                    }
                    $ordenVisual++;
                }
            }

            if ($garantiaClp !== null && $moduloGarantiaDisponible && $findGarantiaStmt instanceof PDOStatement && $insertGarantiaStmt instanceof PDOStatement) {
                foreach ($idsLocalesImport as $idLocalGarantia) {
                    $findGarantiaStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $findGarantiaStmt->bindValue(':id_local', $idLocalGarantia, PDO::PARAM_INT);
                    $findGarantiaStmt->execute();
                    $idGarantia = (int) $findGarantiaStmt->fetchColumn();
                    if ($idGarantia > 0) {
                        continue;
                    }

                    $insertGarantiaStmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
                    $insertGarantiaStmt->bindValue(':id_local', $idLocalGarantia, PDO::PARAM_INT);
                    if ($tieneColumnaGarantiaContratoLocal) {
                        $insertGarantiaStmt->bindValue(':id_contrato_local', (int) (($contratoLocalesActivos[$idLocalGarantia]['id_contrato_local'] ?? 0)), PDO::PARAM_INT);
                    }
                    $insertGarantiaStmt->bindValue(':fecha_constitucion', $fechaInicioContratoBase, PDO::PARAM_STR);
                    $insertGarantiaStmt->bindValue(':monto_inicial', $garantiaClp, PDO::PARAM_STR);
                    $insertGarantiaStmt->bindValue(':observaciones', $observacionCarga, PDO::PARAM_STR);
                    $insertGarantiaStmt->execute();
                    $garantiasAplicadas++;
                }
            }

            if (
                $modoContratoImport
                && $moduloArriendoReglasDisponible
                && $findReglaArriendoDefaultStmt instanceof PDOStatement
                && $insertReglaArriendoDefaultStmt instanceof PDOStatement
                && $updateReglaArriendoDefaultStmt instanceof PDOStatement
            ) {
                if (!isset($modalidadArriendoIdByCode['UF_ESTATICO']) || !isset($modalidadArriendoIdByCode['CLP_FIJO'])) {
                    throw new RuntimeException('Catálogo de modalidad de arriendo incompleto. Revisa msp_tipo_modalidad_arriendo.');
                }

                foreach ($idsLocalesImport as $idLocalArriendo) {
                    $configContratoLocal = $contratoLocalesActivos[$idLocalArriendo] ?? null;
                    if (!is_array($configContratoLocal)) {
                        continue;
                    }

                    $idContratoLocalArriendo = (int) ($configContratoLocal['id_contrato_local'] ?? 0);
                    if ($idContratoLocalArriendo <= 0) {
                        continue;
                    }

                    $findReglaArriendoDefaultStmt->bindValue(':id_contrato_local', $idContratoLocalArriendo, PDO::PARAM_INT);
                    $findReglaArriendoDefaultStmt->execute();
                    $idReglaDefault = (int) $findReglaArriendoDefaultStmt->fetchColumn();

                    if ($idReglaDefault > 0 && !$arriendoExplicit) {
                        continue;
                    }

                    $localInfo = $localInfoById[$idLocalArriendo] ?? ['valor_arriendo_uf' => 0.0, 'cdo_local' => ''];

                    $modalidadAplicada = $arriendoExplicit ? $arriendoModalidad : 'UF_ESTATICO';
                    $idModalidadAplicada = (int) ($modalidadArriendoIdByCode[$modalidadAplicada] ?? 0);
                    if ($idModalidadAplicada <= 0) {
                        throw new RuntimeException('No fue posible resolver modalidad de arriendo para contrato-local.');
                    }

                    $valorBaseUf = null;
                    $valorBaseClp = null;
                    if ($modalidadAplicada === 'UF_ESTATICO') {
                        $valorBaseUf = $arriendoExplicit && $arriendoValorUf !== null
                            ? number_format((float) $arriendoValorUf, 6, '.', '')
                            : number_format((float) ($localInfo['valor_arriendo_uf'] ?? 0.0), 6, '.', '');
                    } elseif ($modalidadAplicada === 'CLP_FIJO') {
                        if ($arriendoValorClp === null) {
                            throw new RuntimeException('No fue posible aplicar regla CLP_FIJO sin valor_arriendo_clp.');
                        }
                        $valorBaseClp = number_format((float) $arriendoValorClp, 2, '.', '');
                    }

                    $descuentoMensualClp = $arriendoExplicit
                        ? number_format((float) $arriendoDescuentoClp, 2, '.', '')
                        : '0.00';
                    $idTipoDescuentoArriendo = null;
                    if ((float) $descuentoMensualClp > 0) {
                        if ($idTipoDescuentoMontoMensualClp <= 0) {
                            throw new RuntimeException('Falta catálogo MONTO_CLP_MENSUAL para aplicar descuento de arriendo.');
                        }
                        $idTipoDescuentoArriendo = $idTipoDescuentoMontoMensualClp;
                    }

                    $codigoGrupoModalidad = $modalidadAplicada === 'CLP_FIJO' ? 'CLP_FIJO_CONTRATO' : null;

                    $fechaInicioRegla = msp2TiendaConfirmParseDate((string) ($configContratoLocal['fecha_inicio'] ?? '')) ?? $fechaInicioContratoBase;
                    $fechaTerminoRegla = msp2TiendaConfirmParseDate((string) ($configContratoLocal['fecha_termino'] ?? ''));
                    $observacionRegla = $arriendoExplicit
                        ? 'Importación contratos: regla de arriendo actualizada desde planilla.'
                        : 'Importación contratos: regla default arriendo creada con base local.';

                    if ($idReglaDefault > 0) {
                        $updateReglaArriendoDefaultStmt->bindValue(':id_regla_arriendo', $idReglaDefault, PDO::PARAM_INT);
                        $updateReglaArriendoDefaultStmt->bindValue(':fecha_inicio', $fechaInicioRegla, PDO::PARAM_STR);
                        $updateReglaArriendoDefaultStmt->bindValue(':fecha_termino', $fechaTerminoRegla, $fechaTerminoRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $updateReglaArriendoDefaultStmt->bindValue(':id_modalidad_arriendo', $idModalidadAplicada, PDO::PARAM_INT);
                        $updateReglaArriendoDefaultStmt->bindValue(':valor_base_uf', $valorBaseUf, $valorBaseUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $updateReglaArriendoDefaultStmt->bindValue(':valor_base_clp', $valorBaseClp, $valorBaseClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $updateReglaArriendoDefaultStmt->bindValue(':id_tipo_descuento_arriendo', $idTipoDescuentoArriendo, $idTipoDescuentoArriendo !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                        $updateReglaArriendoDefaultStmt->bindValue(':descuento_mensual_clp', $descuentoMensualClp, PDO::PARAM_STR);
                        $updateReglaArriendoDefaultStmt->bindValue(':codigo_grupo_modalidad', $codigoGrupoModalidad, $codigoGrupoModalidad !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $updateReglaArriendoDefaultStmt->bindValue(':observaciones', $observacionRegla, PDO::PARAM_STR);
                        $updateReglaArriendoDefaultStmt->execute();
                        $reglasArriendoAplicadas++;
                    } else {
                        $insertReglaArriendoDefaultStmt->bindValue(':id_contrato_local', $idContratoLocalArriendo, PDO::PARAM_INT);
                        $insertReglaArriendoDefaultStmt->bindValue(':fecha_inicio', $fechaInicioRegla, PDO::PARAM_STR);
                        $insertReglaArriendoDefaultStmt->bindValue(':fecha_termino', $fechaTerminoRegla, $fechaTerminoRegla !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $insertReglaArriendoDefaultStmt->bindValue(':id_modalidad_arriendo', $idModalidadAplicada, PDO::PARAM_INT);
                        $insertReglaArriendoDefaultStmt->bindValue(':valor_base_uf', $valorBaseUf, $valorBaseUf !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $insertReglaArriendoDefaultStmt->bindValue(':valor_base_clp', $valorBaseClp, $valorBaseClp !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $insertReglaArriendoDefaultStmt->bindValue(':id_tipo_descuento_arriendo', $idTipoDescuentoArriendo, $idTipoDescuentoArriendo !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
                        $insertReglaArriendoDefaultStmt->bindValue(':descuento_mensual_clp', $descuentoMensualClp, PDO::PARAM_STR);
                        $insertReglaArriendoDefaultStmt->bindValue(':codigo_grupo_modalidad', $codigoGrupoModalidad, $codigoGrupoModalidad !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                        $insertReglaArriendoDefaultStmt->bindValue(':observaciones', $observacionRegla, PDO::PARAM_STR);
                        $insertReglaArriendoDefaultStmt->execute();
                        $reglasArriendoAplicadas++;
                    }
                }
            }
        }
    }

    msp2SyncLocalStatuses($conn, $localesImpactados);

    $conn->commit();
    unset($_SESSION[$sessionPreviewKey]);
    $entidadResumen = $modoContratoImport ? 'registros comerciales' : 'tiendas';
    $verboActualizados = $modoContratoImport ? 'actualizados' : 'actualizadas';

    msp2SetFlash(
        'success',
        'Importación completada desde `' . $fileName . '`: '
        . $insertados . ' ' . $entidadResumen . ' creados, '
        . $actualizados . ' ' . $verboActualizados . ', '
        . $sinCambios . ' sin cambios'
        . ($moduloContratoDisponible ? (', contratos creados/validados: ' . $contratosCreados) : '')
        . ($garantiasAplicadas > 0 ? (', garantías aplicadas: ' . $garantiasAplicadas) : '')
        . ($reglasArriendoAplicadas > 0 ? (', reglas arriendo aplicadas: ' . $reglasArriendoAplicadas) : '')
        . '.'
    );
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $detalle = msp2NormalizeText($exception->getMessage());
    if ($detalle !== '') {
        $detalle = mb_substr($detalle, 0, 260);
        msp2SetFlash('danger', 'No fue posible confirmar la importación. Se revirtió toda la transacción. Detalle: ' . $detalle);
    } else {
        msp2SetFlash('danger', 'No fue posible confirmar la importación. Se revirtió toda la transacción.');
    }
}

msp2Redirect($redirectRoute);
