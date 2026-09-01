<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosRedirect(): never
{
    msp2Redirect(msp2ResolveContratosRedirectFromPost());
}

function msp2ResolveContratosRedirectFromPost(): string
{
    $default = 'contratos/index.php';
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if ($redirectTo === '') {
        return $default;
    }

    $parts = parse_url($redirectTo);
    if (!is_array($parts)) {
        return $default;
    }

    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($path !== $default) {
        return $default;
    }

    $queryRaw = (string) ($parts['query'] ?? '');
    if ($queryRaw === '') {
        return $path;
    }

    $query = [];
    parse_str($queryRaw, $query);
    if (!is_array($query) || $query === []) {
        return $path;
    }

    $sanitized = [];

    if (isset($query['filtroTexto']) && is_scalar($query['filtroTexto'])) {
        $filtroTexto = msp2NormalizeText((string) $query['filtroTexto']);
        if ($filtroTexto !== '') {
            $sanitized['filtroTexto'] = $filtroTexto;
        }
    }

    if (isset($query['filtroEstado']) && is_scalar($query['filtroEstado'])) {
        $filtroEstadoRaw = trim((string) $query['filtroEstado']);
        if (ctype_digit($filtroEstadoRaw)) {
            $filtroEstado = (int) $filtroEstadoRaw;
            if ($filtroEstado > 0) {
                $sanitized['filtroEstado'] = $filtroEstado;
            }
        }
    }

    if (isset($query['lineas']) && is_scalar($query['lineas'])) {
        $lineas = (int) $query['lineas'];
        if (in_array($lineas, [10, 25, 50, 100], true)) {
            $sanitized['lineas'] = $lineas;
        }
    }

    if (isset($query['pagina']) && is_scalar($query['pagina'])) {
        $paginaRaw = trim((string) $query['pagina']);
        if (ctype_digit($paginaRaw)) {
            $pagina = max(1, (int) $paginaRaw);
            $sanitized['pagina'] = $pagina;
        }
    }

    if ($sanitized === []) {
        return $path;
    }

    return $path . '?' . http_build_query($sanitized);
}

function msp2NormalizeArriendoConfigMapFromPost(mixed $rawMap): array
{
    if (!is_array($rawMap)) {
        return [];
    }

    $normalized = [];
    foreach ($rawMap as $code => $value) {
        $key = msp2LocalCodeKey((string) $code);
        if ($key === '') {
            continue;
        }
        $normalized[$key] = is_scalar($value) ? trim((string) $value) : '';
    }

    return $normalized;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2ContratosRedirect();
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

$codLocalesRaw = trim((string) ($_POST['cod_locales'] ?? ''));
$fechaInicioRaw = trim((string) ($_POST['fecha_inicio'] ?? ''));
$fechaTerminoPactadaRaw = trim((string) ($_POST['fecha_termino_pactada'] ?? ''));
$montoArriendoRaw = trim((string) ($_POST['monto_arriendo_pactado'] ?? ''));
$rubroContrato = msp2NormalizeText((string) ($_POST['rubro_contrato'] ?? ''));

$arriendoModalidadByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_arriendo_modalidad'] ?? null);
$arriendoValorUfByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_arriendo_valor_uf'] ?? null);
$arriendoValorClpByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_arriendo_valor_clp'] ?? null);
$arriendoDescuentoByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_arriendo_descuento_clp'] ?? null);
$garantiaHabilitadaByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_garantia_habilitada'] ?? null);
$garantiaMontoByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_garantia_monto'] ?? null);
$garantiaFechaByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_garantia_fecha'] ?? null);
$garantiaObsByCode = msp2NormalizeArriendoConfigMapFromPost($_POST['local_garantia_observaciones'] ?? null);
$garantiaMedioRecepcion = 'Efectivo';
$garantiaReferenciaRecepcion = '';

if ($idArrendatario === false || $idArrendatario === null) {
    msp2SetFlash('warning', 'Debes seleccionar un arrendatario.');
    msp2ContratosRedirect();
}
if ($idTienda === false || $idTienda === null) {
    msp2SetFlash('warning', 'Debes seleccionar una tienda real del arrendatario.');
    msp2ContratosRedirect();
}
if ($fechaInicioRaw === '') {
    msp2SetFlash('warning', 'Debes indicar fecha de inicio.');
    msp2ContratosRedirect();
}

$fechaInicio = DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicioRaw);
if ($fechaInicio === false || $fechaInicio->format('Y-m-d') !== $fechaInicioRaw) {
    msp2SetFlash('warning', 'La fecha de inicio no es válida.');
    msp2ContratosRedirect();
}
$fechaInicioIso = $fechaInicio->format('Y-m-d');

$fechaTerminoIso = null;
if ($fechaTerminoPactadaRaw !== '') {
    $fechaTermino = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTerminoPactadaRaw);
    if ($fechaTermino === false || $fechaTermino->format('Y-m-d') !== $fechaTerminoPactadaRaw) {
        msp2SetFlash('warning', 'La fecha de término pactada no es válida.');
        msp2ContratosRedirect();
    }
    $fechaTerminoIso = $fechaTermino->format('Y-m-d');
    if ($fechaTerminoIso < $fechaInicioIso) {
        msp2SetFlash('warning', 'La fecha de término no puede ser menor a la fecha de inicio.');
        msp2ContratosRedirect();
    }
}

$diaCobro = 1;

$montoArriendo = null;
if ($montoArriendoRaw !== '') {
    [$okMontoArriendo, $montoArriendoNormalizado] = msp2NormalizeDecimalInput($montoArriendoRaw, 2);
    if (!$okMontoArriendo || $montoArriendoNormalizado === null) {
        msp2SetFlash('warning', 'El monto de arriendo base no es válido.');
        msp2ContratosRedirect();
    }
    $montoArriendo = $montoArriendoNormalizado;
}

if ($rubroContrato !== '' && mb_strlen($rubroContrato) > 150) {
    msp2SetFlash('warning', 'El rubro de contrato no puede superar 150 caracteres.');
    msp2ContratosRedirect();
}

$partesLocales = preg_split('/[;|,\n\r]+/', $codLocalesRaw);
if (!is_array($partesLocales)) {
    msp2SetFlash('warning', 'El formato de locales no es válido.');
    msp2ContratosRedirect();
}

$codLocales = [];
$seen = [];
foreach ($partesLocales as $parte) {
    $codigo = msp2NormalizeLocalCode((string) $parte);
    if ($codigo === '') {
        continue;
    }
    if (mb_strlen($codigo) > 20) {
        msp2SetFlash('warning', 'Uno de los códigos locales supera 20 caracteres.');
        msp2ContratosRedirect();
    }
    $key = msp2LocalCodeKey($codigo);
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $codLocales[] = $codigo;
}

if ($codLocales === []) {
    msp2SetFlash('warning', 'Debes indicar al menos un local.');
    msp2ContratosRedirect();
}

$garantiaConfigByCodeKey = [];
$garantiasSolicitadasCount = 0;
foreach ($codLocales as $codigoLocal) {
    $codeKey = msp2LocalCodeKey($codigoLocal);
    if ($codeKey === '') {
        continue;
    }

    $habilitadaRaw = strtoupper(trim((string) ($garantiaHabilitadaByCode[$codeKey] ?? '')));
    $habilitada = in_array($habilitadaRaw, ['1', 'SI', 'TRUE', 'ON'], true);
    $observaciones = trim((string) ($garantiaObsByCode[$codeKey] ?? ''));
    if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
        msp2SetFlash('warning', 'La observación de garantía para `' . $codigoLocal . '` no puede superar 500 caracteres.');
        msp2ContratosRedirect();
    }

    if (!$habilitada) {
        $garantiaConfigByCodeKey[$codeKey] = [
            'habilitada' => false,
            'monto' => null,
            'fecha_constitucion' => null,
            'observaciones' => null,
        ];
        continue;
    }

    [$okMontoGarantia, $montoGarantia] = msp2NormalizeDecimalInput((string) ($garantiaMontoByCode[$codeKey] ?? ''), 0);
    if (!$okMontoGarantia || $montoGarantia === null || (float) $montoGarantia < 0) {
        msp2SetFlash('warning', 'Debes ingresar un monto de garantía válido para `' . $codigoLocal . '`.');
        msp2ContratosRedirect();
    }

    $fechaGarantiaRaw = trim((string) ($garantiaFechaByCode[$codeKey] ?? ''));
    $fechaGarantiaIso = $fechaInicioIso;
    if ($fechaGarantiaRaw !== '') {
        $fechaGarantia = DateTimeImmutable::createFromFormat('Y-m-d', $fechaGarantiaRaw);
        if ($fechaGarantia === false || $fechaGarantia->format('Y-m-d') !== $fechaGarantiaRaw) {
            msp2SetFlash('warning', 'La fecha de constitución de garantía para `' . $codigoLocal . '` no es válida.');
            msp2ContratosRedirect();
        }
        $fechaGarantiaIso = $fechaGarantia->format('Y-m-d');
    }

    $garantiaConfigByCodeKey[$codeKey] = [
        'habilitada' => true,
        'monto' => $montoGarantia,
        'fecha_constitucion' => $fechaGarantiaIso,
        'observaciones' => $observaciones !== '' ? $observaciones : null,
    ];
    $garantiasSolicitadasCount++;
}

try {
    $requiredTables = ['msp_contratos_arriendo', 'msp_contrato_locales', 'msp_tiendas', 'msp_arrendatarios', 'msp_locales'];
    if ($garantiasSolicitadasCount > 0) {
        $requiredTables[] = 'msp_garantias';
    }
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para crear contrato.');
        }
    }

    $moduloArriendoReglasDisponible =
        msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
        && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
        && msp2TableExists($conn, 'msp_tipo_descuento_arriendo');
    if (!$moduloArriendoReglasDisponible) {
        throw new RuntimeException('Falta módulo de arriendo por contrato-local (`msp_contrato_local_arriendo_regla`, `msp_tipo_modalidad_arriendo`, `msp_tipo_descuento_arriendo`).');
    }

    $stmtArr = $conn->prepare(
        'SELECT COUNT(*)
         FROM dbo.msp_arrendatarios
         WHERE id_arrendatario = :id_arrendatario'
    );
    $stmtArr->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $stmtArr->execute();
    if ((int) $stmtArr->fetchColumn() <= 0) {
        throw new RuntimeException('El arrendatario seleccionado no existe.');
    }

    $stmtTienda = $conn->prepare(
            "SELECT t.id_arrendatario, UPPER(LTRIM(RTRIM(et.desc_estado))) AS estado_tienda, t.fecha_termino
             FROM dbo.msp_tiendas t
             INNER JOIN dbo.msp_estado_tiendas et
                ON et.id_estado_tienda = t.id_estado_tienda
             WHERE t.id_tienda = :id_tienda"
    );
    $stmtTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtTienda->execute();
        $tienda = $stmtTienda->fetch();
        if ($tienda === false) {
            throw new RuntimeException('La tienda seleccionada ya no existe.');
        }
        if (in_array((string) ($tienda['estado_tienda'] ?? ''), ['INACTIVO', 'CERRADO'], true)
            || (!empty($tienda['fecha_termino']) && substr((string) $tienda['fecha_termino'], 0, 10) < date('Y-m-d'))) {
            throw new RuntimeException('No se puede crear un contrato para una tienda inactiva.');
        }

        $idArrendatarioTienda = (int) ($tienda['id_arrendatario'] ?? 0);
        if ($idArrendatarioTienda !== $idArrendatario) {
            throw new RuntimeException('El arrendatario no coincide con la tienda seleccionada.');
        }

        $stmtContratoActivo = $conn->prepare(
            'SELECT TOP (1) id_contrato_arriendo
             FROM dbo.msp_contratos_arriendo
             WHERE id_tienda = :id_tienda
               AND estado_contrato IN (1,2)
             ORDER BY id_contrato_arriendo DESC'
        );
        $stmtContratoActivo->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtContratoActivo->execute();
    if ((int) $stmtContratoActivo->fetchColumn() > 0) {
        throw new RuntimeException('La tienda ya tiene un contrato activo.');
    }

    $stmtFindLocal = $conn->prepare(
        'SELECT TOP (1)
            id_local,
            cdo_local,
            valor_arriendo_uf
         FROM dbo.msp_locales
         WHERE UPPER(LTRIM(RTRIM(cdo_local))) = :cdo_local'
    );
    $idsLocal = [];
    $localInfoByCodeKey = [];
    foreach ($codLocales as $codigo) {
        $stmtFindLocal->bindValue(':cdo_local', msp2LocalCodeKey($codigo), PDO::PARAM_STR);
        $stmtFindLocal->execute();
        $localRow = $stmtFindLocal->fetch();
        $idLocal = (int) ($localRow['id_local'] ?? 0);
        if ($idLocal <= 0) {
            throw new RuntimeException('No existe el local `' . $codigo . '`.');
        }
        $idsLocal[$codigo] = $idLocal;
        $codeKey = msp2LocalCodeKey($codigo);
        $localInfoByCodeKey[$codeKey] = [
            'id_local' => $idLocal,
            'cdo_local_key' => strtoupper(trim((string) ($localRow['cdo_local'] ?? $codigo))),
            'valor_arriendo_uf' => isset($localRow['valor_arriendo_uf']) && is_numeric((string) $localRow['valor_arriendo_uf'])
                ? number_format((float) $localRow['valor_arriendo_uf'], 2, '.', '')
                : number_format(0, 2, '.', ''),
        ];
    }

    $stmtInsertContrato = $conn->prepare(
        'INSERT INTO dbo.msp_contratos_arriendo
            (id_tienda, id_arrendatario, fecha_inicio, fecha_termino_pactada, dia_cobro, monto_arriendo_pactado, rubro_contrato, estado_contrato)
         VALUES
            (:id_tienda, :id_arrendatario, :fecha_inicio, :fecha_termino_pactada, :dia_cobro, :monto_arriendo_pactado, :rubro_contrato, 2)'
    );

    $stmtInsertContratoLocal = $conn->prepare(
        'INSERT INTO dbo.msp_contrato_locales
            (id_contrato_arriendo, id_local, fecha_inicio, fecha_termino, orden_visual, estado_relacion)
         VALUES
            (:id_contrato_arriendo, :id_local, :fecha_inicio, :fecha_termino, :orden_visual, 1)'
    );

    $tablaOcupacionDisponible = msp2TableExists($conn, 'msp_ocupacion_locales');
    $stmtSelectOldLocales = null;
    $stmtDeleteOcupaciones = null;
    $stmtInsertOcupacion = null;
    if ($tablaOcupacionDisponible) {
        $stmtSelectOldLocales = $conn->prepare('SELECT DISTINCT id_local FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
        $stmtDeleteOcupaciones = $conn->prepare('DELETE FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
        $stmtInsertOcupacion = $conn->prepare(
            'INSERT INTO dbo.msp_ocupacion_locales
                (id_tienda, id_local, fecha_inicio, fecha_termino)
             VALUES
                (:id_tienda, :id_local, :fecha_inicio, :fecha_termino)'
        );
    }

    $tieneColumnaGarantiaContratoLocal = msp2ColumnExists($conn, 'msp_garantias', 'id_contrato_local');
    $tieneColumnaGarantiaMedioRecepcion = msp2ColumnExists($conn, 'msp_garantias', 'medio_recepcion');
    $tieneColumnaGarantiaReferenciaRecepcion = msp2ColumnExists($conn, 'msp_garantias', 'referencia_recepcion');
    $stmtInsertGarantia = null;
    if ($garantiasSolicitadasCount > 0) {
        $garantiaColumns = ['id_contrato_arriendo', 'id_local'];
        $garantiaValues = [':id_contrato_arriendo', ':id_local'];
        if ($tieneColumnaGarantiaContratoLocal) {
            $garantiaColumns[] = 'id_contrato_local';
            $garantiaValues[] = ':id_contrato_local';
        }
        $garantiaColumns[] = 'fecha_constitucion';
        $garantiaColumns[] = 'monto_inicial';
        $garantiaColumns[] = 'observaciones';
        $garantiaValues[] = ':fecha_constitucion';
        $garantiaValues[] = ':monto_inicial';
        $garantiaValues[] = ':observaciones';
        if ($tieneColumnaGarantiaMedioRecepcion) {
            $garantiaColumns[] = 'medio_recepcion';
            $garantiaValues[] = ':medio_recepcion';
        }
        if ($tieneColumnaGarantiaReferenciaRecepcion) {
            $garantiaColumns[] = 'referencia_recepcion';
            $garantiaValues[] = ':referencia_recepcion';
        }

        $stmtInsertGarantia = $conn->prepare(
            'INSERT INTO dbo.msp_garantias
                (' . implode(', ', $garantiaColumns) . ')
             VALUES
                (' . implode(', ', $garantiaValues) . ')'
        );
    }

    $stmtInsertHistorial = null;
    $idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;
    if (msp2TableExists($conn, 'msp_historial_contrato') && $idUsuarioSesion > 0) {
        $stmtInsertHistorial = $conn->prepare(
            'INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
            (:id_contrato_arriendo, :tipo_evento, :id_usuario, :detalle_evento, :motivo_evento)'
        );
    }

    $modalidadIdByCode = [];
    $stmtModalidades = $conn->query(
        'SELECT id_modalidad_arriendo, codigo_modalidad
         FROM dbo.msp_tipo_modalidad_arriendo
         WHERE activo = 1'
    );
    while (($rowModalidad = $stmtModalidades->fetch()) !== false) {
        $codigo = strtoupper(trim((string) ($rowModalidad['codigo_modalidad'] ?? '')));
        $idModalidad = (int) ($rowModalidad['id_modalidad_arriendo'] ?? 0);
        if ($codigo !== '' && $idModalidad > 0) {
            $modalidadIdByCode[$codigo] = $idModalidad;
        }
    }
    foreach (['UF_ESTATICO', 'CLP_FIJO'] as $codigoReq) {
        if (!isset($modalidadIdByCode[$codigoReq])) {
            throw new RuntimeException('Catálogo de modalidad incompleto. Falta `' . $codigoReq . '`.');
        }
    }

    $stmtTipoDescuento = $conn->prepare(
        'SELECT TOP (1) id_tipo_descuento_arriendo
         FROM dbo.msp_tipo_descuento_arriendo
         WHERE codigo_descuento = N\'MONTO_CLP_MENSUAL\'
           AND activo = 1'
    );
    $stmtTipoDescuento->execute();
    $idTipoDescuentoMontoMensualClp = (int) $stmtTipoDescuento->fetchColumn();

    $arriendoConfigByCodeKey = [];
    foreach ($codLocales as $codigoLocal) {
        $codeKey = msp2LocalCodeKey($codigoLocal);
        if ($codeKey === '') {
            continue;
        }
        $localInfo = $localInfoByCodeKey[$codeKey] ?? null;
        if (!is_array($localInfo)) {
            continue;
        }

        $modalidad = strtoupper(trim((string) ($arriendoModalidadByCode[$codeKey] ?? '')));
        if (!in_array($modalidad, ['UF_ESTATICO', 'CLP_FIJO'], true)) {
            throw new RuntimeException('Solo se permite ingresar un arriendo mensual fijo en UF o pesos.');
        }

        [$okUf, $valorUf] = msp2NormalizeDecimalInput((string) ($arriendoValorUfByCode[$codeKey] ?? ''), 2);
        if (!$okUf) {
            throw new RuntimeException('Valor UF inválido para local `' . $codigoLocal . '`.');
        }
        [$okClp, $valorClp] = msp2NormalizeDecimalInput((string) ($arriendoValorClpByCode[$codeKey] ?? ''), 0);
        if (!$okClp) {
            throw new RuntimeException('Valor CLP inválido para local `' . $codigoLocal . '`.');
        }
        [$okDesc, $descuentoClp] = msp2NormalizeDecimalInput((string) ($arriendoDescuentoByCode[$codeKey] ?? ''), 0);
        if (!$okDesc) {
            throw new RuntimeException('Descuento CLP inválido para local `' . $codigoLocal . '`.');
        }

        $valorBaseUf = null;
        $valorBaseClp = null;
        if ($modalidad === 'UF_ESTATICO') {
            $valorBaseUf = $valorUf ?? (string) ($localInfo['valor_arriendo_uf'] ?? '0.00');
        } elseif ($modalidad === 'CLP_FIJO') {
            if ($valorClp === null) {
                throw new RuntimeException('CLP_FIJO requiere valor base CLP para local `' . $codigoLocal . '`.');
            }
            $valorBaseClp = $valorClp;
        }

        $descuentoFinal = $descuentoClp ?? number_format(0, 0, '.', '');
        $idTipoDescuento = null;
        if ((float) $descuentoFinal > 0) {
            if ($idTipoDescuentoMontoMensualClp <= 0) {
                throw new RuntimeException('No existe tipo de descuento MONTO_CLP_MENSUAL activo.');
            }
            $idTipoDescuento = $idTipoDescuentoMontoMensualClp;
        }

        $arriendoConfigByCodeKey[$codeKey] = [
            'id_modalidad_arriendo' => (int) $modalidadIdByCode[$modalidad],
            'valor_base_uf' => $valorBaseUf,
            'valor_base_clp' => $valorBaseClp,
            'id_tipo_descuento_arriendo' => $idTipoDescuento,
            'descuento_mensual_clp' => $descuentoFinal,
            'codigo_grupo_modalidad' => $modalidad === 'CLP_FIJO' ? 'CLP_FIJO_CONTRATO' : null,
        ];
    }

    $stmtUpsertReglaArriendo = $conn->prepare(
        'INSERT INTO dbo.msp_contrato_local_arriendo_regla
            (id_contrato_local, fecha_inicio, fecha_termino, id_modalidad_arriendo, valor_base_uf, valor_base_clp, id_tipo_descuento_arriendo, descuento_mensual_clp, codigo_grupo_modalidad, prioridad, estado_regla, es_default, observaciones)
         VALUES
            (:id_contrato_local, :fecha_inicio, :fecha_termino, :id_modalidad_arriendo, :valor_base_uf, :valor_base_clp, :id_tipo_descuento_arriendo, :descuento_mensual_clp, :codigo_grupo_modalidad, 100, 1, 1, :observaciones)'
    );

    $conn->beginTransaction();

    $stmtInsertContrato->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtInsertContrato->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $stmtInsertContrato->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
    $stmtInsertContrato->bindValue(':fecha_termino_pactada', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertContrato->bindValue(':dia_cobro', $diaCobro, PDO::PARAM_INT);
    $stmtInsertContrato->bindValue(':monto_arriendo_pactado', $montoArriendo, $montoArriendo !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertContrato->bindValue(':rubro_contrato', $rubroContrato !== '' ? $rubroContrato : null, $rubroContrato !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertContrato->execute();

    $idContrato = (int) $conn->lastInsertId();
    if ($idContrato <= 0) {
        $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
        $idContrato = (int) $identityStmt->fetchColumn();
    }
    if ($idContrato <= 0) {
        throw new RuntimeException('No fue posible determinar el contrato recién creado.');
    }

    $ordenVisual = 1;
    foreach ($codLocales as $codigo) {
        $idLocal = (int) $idsLocal[$codigo];

        $stmtInsertContratoLocal->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtInsertContratoLocal->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
        $stmtInsertContratoLocal->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
        $stmtInsertContratoLocal->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertContratoLocal->bindValue(':orden_visual', $ordenVisual, PDO::PARAM_INT);
        $stmtInsertContratoLocal->execute();

        $idContratoLocal = (int) $conn->lastInsertId();
        if ($idContratoLocal <= 0) {
            $identityStmtLocal = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
            $idContratoLocal = (int) $identityStmtLocal->fetchColumn();
        }
        if ($idContratoLocal <= 0) {
            throw new RuntimeException('No fue posible determinar el contrato-local creado.');
        }

        $codeKey = msp2LocalCodeKey($codigo);
        $arriendoConfig = $arriendoConfigByCodeKey[$codeKey] ?? null;
        if (!is_array($arriendoConfig)) {
            throw new RuntimeException('No fue posible resolver configuración de arriendo para local `' . $codigo . '`.');
        }
        $stmtUpsertReglaArriendo->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtUpsertReglaArriendo->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
        $stmtUpsertReglaArriendo->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpsertReglaArriendo->bindValue(':id_modalidad_arriendo', (int) ($arriendoConfig['id_modalidad_arriendo'] ?? 0), PDO::PARAM_INT);
        $stmtUpsertReglaArriendo->bindValue(':valor_base_uf', $arriendoConfig['valor_base_uf'], $arriendoConfig['valor_base_uf'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpsertReglaArriendo->bindValue(':valor_base_clp', $arriendoConfig['valor_base_clp'], $arriendoConfig['valor_base_clp'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpsertReglaArriendo->bindValue(':id_tipo_descuento_arriendo', $arriendoConfig['id_tipo_descuento_arriendo'], $arriendoConfig['id_tipo_descuento_arriendo'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtUpsertReglaArriendo->bindValue(':descuento_mensual_clp', (string) ($arriendoConfig['descuento_mensual_clp'] ?? '0'), PDO::PARAM_STR);
        $stmtUpsertReglaArriendo->bindValue(':codigo_grupo_modalidad', $arriendoConfig['codigo_grupo_modalidad'], $arriendoConfig['codigo_grupo_modalidad'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtUpsertReglaArriendo->bindValue(':observaciones', 'Creación de contrato desde ficha única (modal Nuevo contrato).', PDO::PARAM_STR);
        $stmtUpsertReglaArriendo->execute();

        $garantiaConfig = $garantiaConfigByCodeKey[$codeKey] ?? null;
        if (
            $stmtInsertGarantia instanceof PDOStatement
            && is_array($garantiaConfig)
            && (($garantiaConfig['habilitada'] ?? false) === true)
        ) {
            $stmtInsertGarantia->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            $stmtInsertGarantia->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            if ($tieneColumnaGarantiaContratoLocal) {
                $stmtInsertGarantia->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
            }
            $stmtInsertGarantia->bindValue(':fecha_constitucion', (string) ($garantiaConfig['fecha_constitucion'] ?? $fechaInicioIso), PDO::PARAM_STR);
            $stmtInsertGarantia->bindValue(':monto_inicial', (string) ($garantiaConfig['monto'] ?? '0'), PDO::PARAM_STR);
            $obsGar = $garantiaConfig['observaciones'] ?? null;
            $stmtInsertGarantia->bindValue(':observaciones', $obsGar, $obsGar !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($tieneColumnaGarantiaMedioRecepcion) {
                $stmtInsertGarantia->bindValue(':medio_recepcion', $garantiaMedioRecepcion, PDO::PARAM_STR);
            }
            if ($tieneColumnaGarantiaReferenciaRecepcion) {
                $refRecepcion = $garantiaReferenciaRecepcion !== '' ? $garantiaReferenciaRecepcion : null;
                $stmtInsertGarantia->bindValue(':referencia_recepcion', $refRecepcion, $refRecepcion !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            }
            $stmtInsertGarantia->execute();
        }

        $ordenVisual++;
    }

    if (
        $stmtSelectOldLocales instanceof PDOStatement
        && $stmtDeleteOcupaciones instanceof PDOStatement
        && $stmtInsertOcupacion instanceof PDOStatement
    ) {
        $localesImpactados = [];

        $stmtSelectOldLocales->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtSelectOldLocales->execute();
        while (($localId = $stmtSelectOldLocales->fetchColumn()) !== false) {
            $localesImpactados[] = (int) $localId;
        }

        $stmtDeleteOcupaciones->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtDeleteOcupaciones->execute();

        foreach ($codLocales as $codigo) {
            $idLocal = (int) $idsLocal[$codigo];
            $stmtInsertOcupacion->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $stmtInsertOcupacion->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $stmtInsertOcupacion->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
            $stmtInsertOcupacion->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertOcupacion->execute();
            $localesImpactados[] = $idLocal;
        }

        msp2SyncLocalStatuses($conn, $localesImpactados);
    }

    if ($stmtInsertHistorial instanceof PDOStatement) {
        $detalle = [
            'origen' => 'contratos/guardar.php',
            'id_tienda' => $idTienda,
            'tienda_seleccionada_explicita' => true,
            'id_arrendatario' => $idArrendatario,
            'locales' => array_values($codLocales),
            'reglas_arriendo_creadas' => count($arriendoConfigByCodeKey),
            'garantias_solicitadas' => $garantiasSolicitadasCount,
            'garantia_medio_recepcion' => $garantiaMedioRecepcion,
        ];
        $detalleJson = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmtInsertHistorial->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':tipo_evento', 'CREACION', PDO::PARAM_STR);
        $stmtInsertHistorial->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':detalle_evento', $detalleJson !== false ? $detalleJson : null, $detalleJson !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertHistorial->bindValue(':motivo_evento', 'Creación rápida desde módulo de contratos.', PDO::PARAM_STR);
        $stmtInsertHistorial->execute();
    }

    $conn->commit();
    msp2SetFlash('success', 'Contrato creado correctamente.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosRedirect();
    }

    $mensaje = $exception->getMessage();
    if (is_string($mensaje) && stripos($mensaje, 'No se puede solapar el mismo local') !== false) {
        msp2SetFlash('warning', 'No se puede crear el contrato: uno de los locales ya está ocupado por otro contrato activo.');
        msp2ContratosRedirect();
    }

    msp2SetFlash('danger', 'No fue posible crear el contrato.');
}

msp2ContratosRedirect();
