<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosEditarRedirect(int $idContrato): never
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

function msp2NormalizeNullableText(string $value): ?string
{
    $normalized = msp2NormalizeText($value);
    return $normalized !== '' ? $normalized : null;
}

function msp2LastInsertedIntId(PDO $conn): int
{
    $newId = (int) $conn->lastInsertId();
    if ($newId > 0) {
        return $newId;
    }
    $scopeStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
    return (int) $scopeStmt->fetchColumn();
}

function msp2EnsureSimpleCatalogId(PDO $conn, string $tableName, string $idColumn, string $descColumn, string $defaultDesc): int
{
    $stmtFindAny = $conn->query(
        'SELECT TOP (1) ' . $idColumn . '
         FROM dbo.' . $tableName . '
         ORDER BY ' . $idColumn . ' ASC'
    );
    $id = (int) $stmtFindAny->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $stmtInsert = $conn->prepare(
        'INSERT INTO dbo.' . $tableName . ' (' . $descColumn . ')
         VALUES (:desc)'
    );
    $stmtInsert->bindValue(':desc', $defaultDesc, PDO::PARAM_STR);
    $stmtInsert->execute();

    $id = msp2LastInsertedIntId($conn);
    if ($id <= 0) {
        $stmtFindAny = $conn->query(
            'SELECT TOP (1) ' . $idColumn . '
             FROM dbo.' . $tableName . '
             ORDER BY ' . $idColumn . ' ASC'
        );
        $id = (int) $stmtFindAny->fetchColumn();
    }
    if ($id <= 0) {
        throw new RuntimeException('No fue posible resolver catálogo `' . $tableName . '`.');
    }
    return $id;
}

function msp2CreateAutoTiendaContrato(PDO $conn, int $idArrendatario, string $fechaInicioIso): int
{
    $idRubro = msp2EnsureSimpleCatalogId($conn, 'msp_rubros', 'id_rubro', 'nombre_rubro', 'RUBRO AUTO CONTRATO');
    $idEstadoTienda = msp2EnsureSimpleCatalogId($conn, 'msp_estado_tiendas', 'id_estado_tienda', 'desc_estado', 'ACTIVA');

    $nombreComercial = 'AUTO-CONTRATO ARR-' . $idArrendatario . ' ' . gmdate('YmdHis');
    if (mb_strlen($nombreComercial) > 200) {
        $nombreComercial = mb_substr($nombreComercial, 0, 200);
    }

    $stmtInsertTienda = $conn->prepare(
        'INSERT INTO dbo.msp_tiendas
            (id_rubro, id_arrendatario, id_estado_tienda, nombre_comercial, fecha_inicio)
         VALUES
            (:id_rubro, :id_arrendatario, :id_estado_tienda, :nombre_comercial, :fecha_inicio)'
    );
    $stmtInsertTienda->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
    $stmtInsertTienda->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $stmtInsertTienda->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
    $stmtInsertTienda->bindValue(':nombre_comercial', $nombreComercial, PDO::PARAM_STR);
    $stmtInsertTienda->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
    $stmtInsertTienda->execute();

    $idTienda = msp2LastInsertedIntId($conn);
    if ($idTienda <= 0) {
        throw new RuntimeException('No fue posible crear tienda técnica para el contrato.');
    }

    return $idTienda;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContrato === false || $idContrato === null) {
    msp2SetFlash('warning', 'Contrato inválido.');
    msp2Redirect('contratos/index.php');
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaInicioRaw = trim((string) ($_POST['fecha_inicio'] ?? ''));
$fechaTerminoRaw = trim((string) ($_POST['fecha_termino_pactada'] ?? ''));
$montoArriendoRaw = trim((string) ($_POST['monto_arriendo_pactado'] ?? ''));
$codLocalesRaw = trim((string) ($_POST['cod_locales'] ?? ''));

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
    msp2SetFlash('warning', 'Debes seleccionar arrendatario.');
    msp2ContratosEditarRedirect($idContrato);
}

$fechaInicio = DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicioRaw);
if ($fechaInicio === false || $fechaInicio->format('Y-m-d') !== $fechaInicioRaw) {
    msp2SetFlash('warning', 'La fecha de inicio no es válida.');
    msp2ContratosEditarRedirect($idContrato);
}
$fechaInicioIso = $fechaInicio->format('Y-m-d');

$fechaTerminoIso = null;
if ($fechaTerminoRaw !== '') {
    $fechaTermino = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTerminoRaw);
    if ($fechaTermino === false || $fechaTermino->format('Y-m-d') !== $fechaTerminoRaw) {
        msp2SetFlash('warning', 'La fecha de término pactada no es válida.');
        msp2ContratosEditarRedirect($idContrato);
    }
    $fechaTerminoIso = $fechaTermino->format('Y-m-d');
    if ($fechaTerminoIso < $fechaInicioIso) {
        msp2SetFlash('warning', 'La fecha de término no puede ser menor a la fecha de inicio.');
        msp2ContratosEditarRedirect($idContrato);
    }
}

$diaCobro = 1;

$montoArriendo = null;
if ($montoArriendoRaw !== '') {
    [$okMontoArriendo, $montoArriendoNormalizado] = msp2NormalizeDecimalInput($montoArriendoRaw, 2);
    if (!$okMontoArriendo || $montoArriendoNormalizado === null) {
        msp2SetFlash('warning', 'El monto de arriendo base no es válido.');
        msp2ContratosEditarRedirect($idContrato);
    }
    $montoArriendo = $montoArriendoNormalizado;
}

$partesLocales = preg_split('/[;|,\n\r]+/', $codLocalesRaw);
$localesCodigo = [];
$seen = [];
if (is_array($partesLocales)) {
    foreach ($partesLocales as $parte) {
        $codigo = msp2NormalizeLocalCode((string) $parte);
        if ($codigo === '') {
            continue;
        }
        $key = msp2LocalCodeKey($codigo);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $localesCodigo[] = $codigo;
    }
}
if ($localesCodigo === []) {
    msp2SetFlash('warning', 'Debes mantener al menos un local en el contrato.');
    msp2ContratosEditarRedirect($idContrato);
}

$garantiaConfigByCodeKey = [];
$garantiasSolicitadasCount = 0;
foreach ($localesCodigo as $codigoLocal) {
    $codeKey = msp2LocalCodeKey($codigoLocal);
    if ($codeKey === '') {
        continue;
    }

    $habilitadaRaw = strtoupper(trim((string) ($garantiaHabilitadaByCode[$codeKey] ?? '')));
    $habilitada = in_array($habilitadaRaw, ['1', 'SI', 'TRUE', 'ON'], true);
    $observaciones = trim((string) ($garantiaObsByCode[$codeKey] ?? ''));
    if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
        msp2SetFlash('warning', 'Las observaciones de garantía para `' . $codigoLocal . '` no pueden superar 500 caracteres.');
        msp2ContratosEditarRedirect($idContrato);
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
        msp2SetFlash('warning', 'Debes indicar un monto de garantía válido para `' . $codigoLocal . '`.');
        msp2ContratosEditarRedirect($idContrato);
    }

    $fechaGarantiaRaw = trim((string) ($garantiaFechaByCode[$codeKey] ?? ''));
    $fechaGarantiaIso = $fechaInicioIso;
    if ($fechaGarantiaRaw !== '') {
        $fechaGarantia = DateTimeImmutable::createFromFormat('Y-m-d', $fechaGarantiaRaw);
        if ($fechaGarantia === false || $fechaGarantia->format('Y-m-d') !== $fechaGarantiaRaw) {
            msp2SetFlash('warning', 'La fecha de garantía para `' . $codigoLocal . '` no es válida.');
            msp2ContratosEditarRedirect($idContrato);
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
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para actualizar contrato.');
        }
    }

    $stmtContrato = $conn->prepare(
        'SELECT id_tienda, id_arrendatario, estado_contrato
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contratoDb = $stmtContrato->fetch();
    if ($contratoDb === false) {
        throw new RuntimeException('El contrato ya no existe.');
    }
    $estadoContrato = (int) ($contratoDb['estado_contrato'] ?? 0);
    if (!in_array($estadoContrato, [1, 2], true)) {
        throw new RuntimeException('Solo se pueden editar contratos en estado borrador o vigente.');
    }
    $idTiendaOriginal = (int) ($contratoDb['id_tienda'] ?? 0);
    $idArrendatarioOriginal = (int) ($contratoDb['id_arrendatario'] ?? 0);

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

    $tiendaAutogenerada = false;
    if ($idTienda !== false && $idTienda !== null && $idTienda > 0) {
        $stmtTienda = $conn->prepare(
            'SELECT id_arrendatario
             FROM dbo.msp_tiendas
             WHERE id_tienda = :id_tienda'
        );
        $stmtTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtTienda->execute();
        $tienda = $stmtTienda->fetch();
        if ($tienda === false) {
            throw new RuntimeException('La tienda seleccionada no existe.');
        }
        if ((int) ($tienda['id_arrendatario'] ?? 0) !== $idArrendatario) {
            throw new RuntimeException('El arrendatario no coincide con la tienda seleccionada.');
        }
    } elseif ($idArrendatarioOriginal === $idArrendatario && $idTiendaOriginal > 0) {
        $stmtTiendaOriginal = $conn->prepare(
            'SELECT id_arrendatario
             FROM dbo.msp_tiendas
             WHERE id_tienda = :id_tienda'
        );
        $stmtTiendaOriginal->bindValue(':id_tienda', $idTiendaOriginal, PDO::PARAM_INT);
        $stmtTiendaOriginal->execute();
        $tiendaOriginal = $stmtTiendaOriginal->fetch();
        if ($tiendaOriginal !== false && (int) ($tiendaOriginal['id_arrendatario'] ?? 0) === $idArrendatario) {
            $idTienda = $idTiendaOriginal;
        } else {
            $idTienda = msp2CreateAutoTiendaContrato($conn, $idArrendatario, $fechaInicioIso);
            $tiendaAutogenerada = true;
        }
    } else {
        $idTienda = msp2CreateAutoTiendaContrato($conn, $idArrendatario, $fechaInicioIso);
        $tiendaAutogenerada = true;
    }

    $stmtContratoActivoOtro = $conn->prepare(
        'SELECT TOP (1) id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE id_tienda = :id_tienda
           AND estado_contrato IN (1,2)
           AND id_contrato_arriendo <> :id_contrato_arriendo
         ORDER BY id_contrato_arriendo DESC'
    );
    $stmtContratoActivoOtro->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtContratoActivoOtro->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtContratoActivoOtro->execute();
    if ((int) $stmtContratoActivoOtro->fetchColumn() > 0) {
        throw new RuntimeException('La tienda objetivo ya está asociada a otro contrato activo.');
    }

    $stmtFindLocal = $conn->prepare(
        'SELECT TOP (1)
            id_local,
            cdo_local,
            valor_arriendo_uf
         FROM dbo.msp_locales
         WHERE UPPER(LTRIM(RTRIM(cdo_local))) = :cdo_local'
    );
    $idsLocalPorCodigo = [];
    $localInfoByCodeKey = [];
    foreach ($localesCodigo as $codigo) {
        $stmtFindLocal->bindValue(':cdo_local', msp2LocalCodeKey($codigo), PDO::PARAM_STR);
        $stmtFindLocal->execute();
        $localRow = $stmtFindLocal->fetch();
        $idLocal = (int) ($localRow['id_local'] ?? 0);
        if ($idLocal <= 0) {
            throw new RuntimeException('No existe el local `' . $codigo . '`.');
        }
        $idsLocalPorCodigo[$codigo] = $idLocal;
        $codeKey = msp2LocalCodeKey($codigo);
        $localInfoByCodeKey[$codeKey] = [
            'id_local' => $idLocal,
            'cdo_local_key' => strtoupper(trim((string) ($localRow['cdo_local'] ?? $codigo))),
            'valor_arriendo_uf' => isset($localRow['valor_arriendo_uf']) && is_numeric((string) $localRow['valor_arriendo_uf'])
                ? number_format((float) $localRow['valor_arriendo_uf'], 2, '.', '')
                : number_format(0, 2, '.', ''),
        ];
    }
    $idsLocalesSeleccionados = array_values(array_unique(array_values($idsLocalPorCodigo)));

    $stmtRelacionesActivas = $conn->prepare(
        'SELECT id_contrato_local, id_local
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_relacion = 1'
    );
    $stmtRelacionesActivas->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtRelacionesActivas->execute();
    $relacionesActivasPorLocal = [];
    while (($row = $stmtRelacionesActivas->fetch()) !== false) {
        $idLocal = (int) ($row['id_local'] ?? 0);
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        if ($idLocal > 0 && $idContratoLocal > 0) {
            $relacionesActivasPorLocal[$idLocal] = $idContratoLocal;
        }
    }
    $idsLocalesActivos = array_values(array_unique(array_keys($relacionesActivasPorLocal)));

    $idsLocalesNuevos = array_values(array_diff($idsLocalesSeleccionados, $idsLocalesActivos));
    $idsLocalesRemover = array_values(array_diff($idsLocalesActivos, $idsLocalesSeleccionados));

    $tieneGarantias = msp2TableExists($conn, 'msp_garantias');
    $tieneIdContratoLocal = $tieneGarantias && msp2ColumnExists($conn, 'msp_garantias', 'id_contrato_local');
    $tieneColumnaGarantiaMedioRecepcion = $tieneGarantias && msp2ColumnExists($conn, 'msp_garantias', 'medio_recepcion');
    $tieneColumnaGarantiaReferenciaRecepcion = $tieneGarantias && msp2ColumnExists($conn, 'msp_garantias', 'referencia_recepcion');

    if ($idsLocalesRemover !== []) {
        $placeholders = [];
        foreach ($idsLocalesRemover as $idx => $_id) {
            $placeholders[] = ':id_local_' . $idx;
        }

        if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            $sqlCargosNuevos = 'SELECT COUNT(*)
                               FROM dbo.msp_cargos_contrato_local ccl
                               INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
                               WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
                                 AND cl.id_local IN (' . implode(', ', $placeholders) . ')
                                 AND ccl.estado_cargo IN (1,2)';
            $stmtCargosNuevos = $conn->prepare($sqlCargosNuevos);
            $stmtCargosNuevos->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            foreach ($idsLocalesRemover as $idx => $idLocal) {
                $stmtCargosNuevos->bindValue(':id_local_' . $idx, $idLocal, PDO::PARAM_INT);
            }
            $stmtCargosNuevos->execute();
            if ((int) $stmtCargosNuevos->fetchColumn() > 0) {
                throw new RuntimeException('No se puede quitar un local con cargos pendientes o reservados.');
            }
        }

        if (msp2TableExists($conn, 'msp_cargos_salida')) {
            $sqlCargosLegacy = 'SELECT COUNT(*)
                               FROM dbo.msp_cargos_salida cs
                               WHERE cs.id_contrato_arriendo = :id_contrato_arriendo
                                 AND cs.id_local IN (' . implode(', ', $placeholders) . ')
                                 AND cs.estado_cargo IN (1,2)';
            $stmtCargosLegacy = $conn->prepare($sqlCargosLegacy);
            $stmtCargosLegacy->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            foreach ($idsLocalesRemover as $idx => $idLocal) {
                $stmtCargosLegacy->bindValue(':id_local_' . $idx, $idLocal, PDO::PARAM_INT);
            }
            $stmtCargosLegacy->execute();
            if ((int) $stmtCargosLegacy->fetchColumn() > 0) {
                throw new RuntimeException('No se puede quitar un local con cargos pendientes o reservados.');
            }
        }

        if ($tieneGarantias) {
            $sqlGarantiasActivas = 'SELECT COUNT(*)
                                   FROM dbo.msp_garantias g
                                   WHERE g.id_contrato_arriendo = :id_contrato_arriendo
                                     AND g.id_local IN (' . implode(', ', $placeholders) . ')
                                     AND g.estado_garantia NOT IN (5,6)';
            $stmtGarantiasActivas = $conn->prepare($sqlGarantiasActivas);
            $stmtGarantiasActivas->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            foreach ($idsLocalesRemover as $idx => $idLocal) {
                $stmtGarantiasActivas->bindValue(':id_local_' . $idx, $idLocal, PDO::PARAM_INT);
            }
            $stmtGarantiasActivas->execute();
            if ((int) $stmtGarantiasActivas->fetchColumn() > 0) {
                throw new RuntimeException('No se puede quitar un local con garantía activa. Cierra/anula la garantía primero.');
            }

            if (msp2TableExists($conn, 'msp_vw_garantias_resumen')) {
                $sqlSaldos = 'SELECT COUNT(*)
                             FROM dbo.msp_garantias g
                             INNER JOIN dbo.msp_vw_garantias_resumen gr ON gr.id_garantia = g.id_garantia
                             WHERE g.id_contrato_arriendo = :id_contrato_arriendo
                               AND g.id_local IN (' . implode(', ', $placeholders) . ')
                               AND (gr.saldo_disponible > 0 OR gr.saldo_reservado > 0)';
                $stmtSaldos = $conn->prepare($sqlSaldos);
                $stmtSaldos->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
                foreach ($idsLocalesRemover as $idx => $idLocal) {
                    $stmtSaldos->bindValue(':id_local_' . $idx, $idLocal, PDO::PARAM_INT);
                }
                $stmtSaldos->execute();
                if ((int) $stmtSaldos->fetchColumn() > 0) {
                    throw new RuntimeException('No se puede quitar un local con saldos de garantía disponibles o reservados.');
                }
            }
        }
    }

    $stmtUpdateContrato = $conn->prepare(
        'UPDATE dbo.msp_contratos_arriendo
         SET id_tienda = :id_tienda,
             id_arrendatario = :id_arrendatario,
             fecha_inicio = :fecha_inicio,
             fecha_termino_pactada = :fecha_termino_pactada,
             dia_cobro = :dia_cobro,
             monto_arriendo_pactado = :monto_arriendo_pactado
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );

    $stmtInsertContratoLocal = $conn->prepare(
        'INSERT INTO dbo.msp_contrato_locales
            (id_contrato_arriendo, id_local, fecha_inicio, fecha_termino, orden_visual, estado_relacion)
         VALUES
            (:id_contrato_arriendo, :id_local, :fecha_inicio, :fecha_termino, :orden_visual, 1)'
    );
    $stmtUpdateOrden = $conn->prepare(
        'UPDATE dbo.msp_contrato_locales
         SET orden_visual = :orden_visual
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND id_local = :id_local
           AND estado_relacion = 1'
    );
    $stmtCerrarRelacion = $conn->prepare(
        'UPDATE dbo.msp_contrato_locales
         SET estado_relacion = 2,
             fecha_termino = CASE WHEN fecha_inicio > :fecha_corte THEN fecha_inicio ELSE :fecha_corte END
         WHERE id_contrato_local = :id_contrato_local
           AND estado_relacion = 1'
    );

    $stmtMaxOrden = $conn->prepare(
        'SELECT ISNULL(MAX(orden_visual), 0)
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );

    $stmtInsertGarantia = null;
    $stmtUpdateGarantia = null;
    $stmtExisteGarantia = null;
    if ($garantiasSolicitadasCount > 0) {
        if (!$tieneGarantias) {
            throw new RuntimeException('No existe módulo de garantías para aplicar configuración por local.');
        }
        $stmtExisteGarantia = $conn->prepare(
            'SELECT TOP (1)
                id_garantia,
                fecha_constitucion,
                monto_inicial,
                observaciones'
                . ($tieneColumnaGarantiaMedioRecepcion ? ', medio_recepcion' : ', CAST(NULL AS NVARCHAR(50)) AS medio_recepcion')
                . ($tieneColumnaGarantiaReferenciaRecepcion ? ', referencia_recepcion' : ', CAST(NULL AS NVARCHAR(100)) AS referencia_recepcion') . '
             FROM dbo.msp_garantias
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND id_local = :id_local
             ORDER BY id_garantia DESC'
        );
        $updateSet = [
            'fecha_constitucion = :fecha_constitucion',
            'monto_inicial = :monto_inicial',
            'observaciones = :observaciones',
        ];
        if ($tieneColumnaGarantiaMedioRecepcion) {
            $updateSet[] = 'medio_recepcion = :medio_recepcion';
        }
        if ($tieneColumnaGarantiaReferenciaRecepcion) {
            $updateSet[] = 'referencia_recepcion = :referencia_recepcion';
        }
        $stmtUpdateGarantia = $conn->prepare(
            'UPDATE dbo.msp_garantias
             SET ' . implode(', ', $updateSet) . '
             WHERE id_garantia = :id_garantia'
        );

        $insertColumns = ['id_contrato_arriendo', 'id_local'];
        $insertValues = [':id_contrato_arriendo', ':id_local'];
        if ($tieneIdContratoLocal) {
            $insertColumns[] = 'id_contrato_local';
            $insertValues[] = ':id_contrato_local';
        }
        $insertColumns[] = 'fecha_constitucion';
        $insertColumns[] = 'monto_inicial';
        $insertColumns[] = 'observaciones';
        $insertValues[] = ':fecha_constitucion';
        $insertValues[] = ':monto_inicial';
        $insertValues[] = ':observaciones';
        if ($tieneColumnaGarantiaMedioRecepcion) {
            $insertColumns[] = 'medio_recepcion';
            $insertValues[] = ':medio_recepcion';
        }
        if ($tieneColumnaGarantiaReferenciaRecepcion) {
            $insertColumns[] = 'referencia_recepcion';
            $insertValues[] = ':referencia_recepcion';
        }
        $stmtInsertGarantia = $conn->prepare(
            'INSERT INTO dbo.msp_garantias
                (' . implode(', ', $insertColumns) . ')
             VALUES
                (' . implode(', ', $insertValues) . ')'
        );
    }

    $moduloArriendoReglasDisponible =
        msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
        && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
        && msp2TableExists($conn, 'msp_tipo_descuento_arriendo');
    if (!$moduloArriendoReglasDisponible) {
        throw new RuntimeException('Falta módulo de arriendo por contrato-local (`msp_contrato_local_arriendo_regla`, `msp_tipo_modalidad_arriendo`, `msp_tipo_descuento_arriendo`).');
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
    foreach ($localesCodigo as $codigoLocal) {
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

    $stmtFindReglaArriendoDefault = $conn->prepare(
        'SELECT TOP (1)
            id_regla_arriendo
         FROM dbo.msp_contrato_local_arriendo_regla
         WHERE id_contrato_local = :id_contrato_local
           AND es_default = 1
           AND estado_regla = 1
         ORDER BY prioridad DESC, id_regla_arriendo DESC'
    );
    $stmtUpdateReglaArriendoDefault = $conn->prepare(
        'UPDATE dbo.msp_contrato_local_arriendo_regla
         SET
            fecha_inicio = :fecha_inicio,
            fecha_termino = :fecha_termino,
            id_modalidad_arriendo = :id_modalidad_arriendo,
            valor_base_uf = :valor_base_uf,
            valor_base_clp = :valor_base_clp,
            id_tipo_descuento_arriendo = :id_tipo_descuento_arriendo,
            descuento_mensual_clp = :descuento_mensual_clp,
            codigo_grupo_modalidad = :codigo_grupo_modalidad,
            prioridad = 100,
            observaciones = :observaciones,
            fecha_actualizacion = SYSDATETIME()
         WHERE id_regla_arriendo = :id_regla_arriendo'
    );
    $stmtInsertReglaArriendoDefault = $conn->prepare(
        'INSERT INTO dbo.msp_contrato_local_arriendo_regla
            (id_contrato_local, fecha_inicio, fecha_termino, id_modalidad_arriendo, valor_base_uf, valor_base_clp, id_tipo_descuento_arriendo, descuento_mensual_clp, codigo_grupo_modalidad, prioridad, estado_regla, es_default, observaciones)
         VALUES
            (:id_contrato_local, :fecha_inicio, :fecha_termino, :id_modalidad_arriendo, :valor_base_uf, :valor_base_clp, :id_tipo_descuento_arriendo, :descuento_mensual_clp, :codigo_grupo_modalidad, 100, 1, 1, :observaciones)'
    );

    $idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;
    $stmtInsertHistorial = null;
    if (msp2TableExists($conn, 'msp_historial_contrato') && $idUsuarioSesion > 0) {
        $stmtInsertHistorial = $conn->prepare(
            'INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
                (:id_contrato_arriendo, :tipo_evento, :id_usuario, :detalle_evento, :motivo_evento)'
        );
    }

    $conn->beginTransaction();

    $stmtUpdateContrato->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtUpdateContrato->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtUpdateContrato->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $stmtUpdateContrato->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
    $stmtUpdateContrato->bindValue(':fecha_termino_pactada', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpdateContrato->bindValue(':dia_cobro', $diaCobro, PDO::PARAM_INT);
    $stmtUpdateContrato->bindValue(':monto_arriendo_pactado', $montoArriendo, $montoArriendo !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpdateContrato->execute();

    $stmtMaxOrden->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtMaxOrden->execute();
    $ordenVisual = (int) $stmtMaxOrden->fetchColumn();

    $fechaCorteSalida = $fechaTerminoIso ?? (new DateTimeImmutable('today'))->format('Y-m-d');
    $localesRemovidosCount = 0;
    foreach ($idsLocalesRemover as $idLocalRemover) {
        $idContratoLocal = (int) ($relacionesActivasPorLocal[$idLocalRemover] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }
        $stmtCerrarRelacion->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtCerrarRelacion->bindValue(':fecha_corte', $fechaCorteSalida, PDO::PARAM_STR);
        $stmtCerrarRelacion->execute();
        if ($stmtCerrarRelacion->rowCount() > 0) {
            $localesRemovidosCount++;
        }
    }

    foreach ($idsLocalesNuevos as $idLocalNuevo) {
        $ordenVisual++;
        $stmtInsertContratoLocal->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtInsertContratoLocal->bindValue(':id_local', $idLocalNuevo, PDO::PARAM_INT);
        $stmtInsertContratoLocal->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
        $stmtInsertContratoLocal->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertContratoLocal->bindValue(':orden_visual', $ordenVisual, PDO::PARAM_INT);
        $stmtInsertContratoLocal->execute();
    }

    $orden = 1;
    foreach ($localesCodigo as $codigoLocal) {
        $idLocalOrden = (int) ($idsLocalPorCodigo[$codigoLocal] ?? 0);
        if ($idLocalOrden <= 0) {
            continue;
        }
        $stmtUpdateOrden->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtUpdateOrden->bindValue(':id_local', $idLocalOrden, PDO::PARAM_INT);
        $stmtUpdateOrden->bindValue(':orden_visual', $orden, PDO::PARAM_INT);
        $stmtUpdateOrden->execute();
        $orden++;
    }

    if (msp2TableExists($conn, 'msp_ocupacion_locales')) {
        $stmtDeleteOcup = $conn->prepare('DELETE FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
        $tiendasLimpiar = [];
        if ($idTiendaOriginal > 0) {
            $tiendasLimpiar[] = $idTiendaOriginal;
        }
        if ($idTienda > 0 && !in_array($idTienda, $tiendasLimpiar, true)) {
            $tiendasLimpiar[] = $idTienda;
        }
        foreach ($tiendasLimpiar as $idTiendaLimpiar) {
            $stmtDeleteOcup->bindValue(':id_tienda', $idTiendaLimpiar, PDO::PARAM_INT);
            $stmtDeleteOcup->execute();
        }

        $stmtInsertOcup = $conn->prepare(
            'INSERT INTO dbo.msp_ocupacion_locales
                (id_tienda, id_local, fecha_inicio, fecha_termino)
             VALUES
                (:id_tienda, :id_local, :fecha_inicio, :fecha_termino)'
        );
        foreach ($idsLocalesSeleccionados as $idLocalSel) {
            $stmtInsertOcup->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $stmtInsertOcup->bindValue(':id_local', $idLocalSel, PDO::PARAM_INT);
            $stmtInsertOcup->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
            $stmtInsertOcup->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertOcup->execute();
        }
    }

    $stmtRelacionesActivasFinal = $conn->prepare(
        'SELECT id_contrato_local, id_local
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_relacion = 1'
    );
    $stmtRelacionesActivasFinal->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
    $stmtRelacionesActivasFinal->execute();
    $idContratoLocalPorLocal = [];
    while (($row = $stmtRelacionesActivasFinal->fetch()) !== false) {
        $idLocal = (int) ($row['id_local'] ?? 0);
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        if ($idLocal > 0 && $idContratoLocal > 0) {
            $idContratoLocalPorLocal[$idLocal] = $idContratoLocal;
        }
    }

    $reglasArriendoActualizadas = 0;
    foreach ($localesCodigo as $codigoLocal) {
        $codeKey = msp2LocalCodeKey($codigoLocal);
        if ($codeKey === '') {
            continue;
        }
        $idLocal = (int) ($idsLocalPorCodigo[$codigoLocal] ?? 0);
        $idContratoLocal = (int) ($idContratoLocalPorLocal[$idLocal] ?? 0);
        if ($idLocal <= 0 || $idContratoLocal <= 0) {
            continue;
        }

        $configArriendo = $arriendoConfigByCodeKey[$codeKey] ?? null;
        if (!is_array($configArriendo)) {
            throw new RuntimeException('No fue posible resolver configuración de arriendo para local `' . $codigoLocal . '`.');
        }

        $stmtFindReglaArriendoDefault->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
        $stmtFindReglaArriendoDefault->execute();
        $idRegla = (int) $stmtFindReglaArriendoDefault->fetchColumn();

        if ($idRegla > 0) {
            $stmtUpdateReglaArriendoDefault->bindValue(':id_regla_arriendo', $idRegla, PDO::PARAM_INT);
            $stmtUpdateReglaArriendoDefault->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
            $stmtUpdateReglaArriendoDefault->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpdateReglaArriendoDefault->bindValue(':id_modalidad_arriendo', (int) ($configArriendo['id_modalidad_arriendo'] ?? 0), PDO::PARAM_INT);
            $stmtUpdateReglaArriendoDefault->bindValue(':valor_base_uf', $configArriendo['valor_base_uf'], $configArriendo['valor_base_uf'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpdateReglaArriendoDefault->bindValue(':valor_base_clp', $configArriendo['valor_base_clp'], $configArriendo['valor_base_clp'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpdateReglaArriendoDefault->bindValue(':id_tipo_descuento_arriendo', $configArriendo['id_tipo_descuento_arriendo'], $configArriendo['id_tipo_descuento_arriendo'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtUpdateReglaArriendoDefault->bindValue(':descuento_mensual_clp', (string) ($configArriendo['descuento_mensual_clp'] ?? '0'), PDO::PARAM_STR);
            $stmtUpdateReglaArriendoDefault->bindValue(':codigo_grupo_modalidad', $configArriendo['codigo_grupo_modalidad'], $configArriendo['codigo_grupo_modalidad'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpdateReglaArriendoDefault->bindValue(':observaciones', 'Edición de contrato desde ficha única (modal Editar contrato).', PDO::PARAM_STR);
            $stmtUpdateReglaArriendoDefault->execute();
            $reglasArriendoActualizadas++;
        } else {
            $stmtInsertReglaArriendoDefault->bindValue(':id_contrato_local', $idContratoLocal, PDO::PARAM_INT);
            $stmtInsertReglaArriendoDefault->bindValue(':fecha_inicio', $fechaInicioIso, PDO::PARAM_STR);
            $stmtInsertReglaArriendoDefault->bindValue(':fecha_termino', $fechaTerminoIso, $fechaTerminoIso !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertReglaArriendoDefault->bindValue(':id_modalidad_arriendo', (int) ($configArriendo['id_modalidad_arriendo'] ?? 0), PDO::PARAM_INT);
            $stmtInsertReglaArriendoDefault->bindValue(':valor_base_uf', $configArriendo['valor_base_uf'], $configArriendo['valor_base_uf'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertReglaArriendoDefault->bindValue(':valor_base_clp', $configArriendo['valor_base_clp'], $configArriendo['valor_base_clp'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertReglaArriendoDefault->bindValue(':id_tipo_descuento_arriendo', $configArriendo['id_tipo_descuento_arriendo'], $configArriendo['id_tipo_descuento_arriendo'] !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmtInsertReglaArriendoDefault->bindValue(':descuento_mensual_clp', (string) ($configArriendo['descuento_mensual_clp'] ?? '0'), PDO::PARAM_STR);
            $stmtInsertReglaArriendoDefault->bindValue(':codigo_grupo_modalidad', $configArriendo['codigo_grupo_modalidad'], $configArriendo['codigo_grupo_modalidad'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtInsertReglaArriendoDefault->bindValue(':observaciones', 'Edición de contrato desde ficha única (modal Editar contrato).', PDO::PARAM_STR);
            $stmtInsertReglaArriendoDefault->execute();
            $reglasArriendoActualizadas++;
        }
    }

    $garantiasUpsertadas = 0;
    $garantiasAsientoRefrescado = 0;
    $garantiasParaRefrescarAsiento = [];
    $puedeRefrescarAsientoGarantia = false;
    $stmtRevertirGarantiaAsiento = null;
    $stmtGenerarGarantiaAsiento = null;
    if ($garantiasSolicitadasCount > 0) {
        $tieneProcRevertirOrigen = (int) ($conn->query("SELECT OBJECT_ID(N'dbo.msp_acc_revertir_origen', N'P')")->fetchColumn() ?: 0) > 0;
        $tieneProcGenerarGarantia = (int) ($conn->query("SELECT OBJECT_ID(N'dbo.msp_acc_generar_asiento_garantia_constitucion', N'P')")->fetchColumn() ?: 0) > 0;
        $puedeRefrescarAsientoGarantia = $tieneProcRevertirOrigen && $tieneProcGenerarGarantia;
        if ($puedeRefrescarAsientoGarantia) {
            $stmtRevertirGarantiaAsiento = $conn->prepare(
                "EXEC dbo.msp_acc_revertir_origen N'msp_garantias', :id_origen, :fecha_reversa, :motivo"
            );
            $stmtGenerarGarantiaAsiento = $conn->prepare(
                'EXEC dbo.msp_acc_generar_asiento_garantia_constitucion :id_garantia'
            );
        }
    }
    if ($stmtInsertGarantia instanceof PDOStatement && $stmtExisteGarantia instanceof PDOStatement) {
        foreach ($localesCodigo as $codigoLocal) {
            $codeKey = msp2LocalCodeKey($codigoLocal);
            if ($codeKey === '') {
                continue;
            }
            $cfgGarantia = $garantiaConfigByCodeKey[$codeKey] ?? null;
            if (!is_array($cfgGarantia) || (($cfgGarantia['habilitada'] ?? false) !== true)) {
                continue;
            }
            $idLocalGarantia = (int) ($idsLocalPorCodigo[$codigoLocal] ?? 0);
            if ($idLocalGarantia <= 0) {
                continue;
            }

            $stmtExisteGarantia->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            $stmtExisteGarantia->bindValue(':id_local', $idLocalGarantia, PDO::PARAM_INT);
            $stmtExisteGarantia->execute();
            $garantiaExistente = $stmtExisteGarantia->fetch() ?: null;
            $idGarantiaExistente = (int) ($garantiaExistente['id_garantia'] ?? 0);

            $fechaCfg = (string) ($cfgGarantia['fecha_constitucion'] ?? $fechaInicioIso);
            $montoCfg = (string) ($cfgGarantia['monto'] ?? '0');
            $obsCfg = msp2NormalizeNullableText((string) ($cfgGarantia['observaciones'] ?? ''));
            $medioCfg = $garantiaMedioRecepcion;
            $refCfg = msp2NormalizeNullableText($garantiaReferenciaRecepcion);

            if ($idGarantiaExistente > 0 && $stmtUpdateGarantia instanceof PDOStatement) {
                $fechaExistente = trim((string) ($garantiaExistente['fecha_constitucion'] ?? ''));
                $montoExistente = round((float) ($garantiaExistente['monto_inicial'] ?? 0), 2);
                $obsExistente = msp2NormalizeNullableText((string) ($garantiaExistente['observaciones'] ?? ''));
                $medioExistente = msp2NormalizeNullableText((string) ($garantiaExistente['medio_recepcion'] ?? ''));
                $refExistente = msp2NormalizeNullableText((string) ($garantiaExistente['referencia_recepcion'] ?? ''));
                $montoNuevo = round((float) $montoCfg, 2);
                $tieneCambio = (
                    $fechaExistente !== $fechaCfg
                    || abs($montoExistente - $montoNuevo) > 0.009
                    || $obsExistente !== $obsCfg
                    || ($tieneColumnaGarantiaMedioRecepcion && $medioExistente !== msp2NormalizeNullableText($medioCfg))
                    || ($tieneColumnaGarantiaReferenciaRecepcion && $refExistente !== $refCfg)
                );

                $stmtUpdateGarantia->bindValue(':id_garantia', $idGarantiaExistente, PDO::PARAM_INT);
                $stmtUpdateGarantia->bindValue(':fecha_constitucion', $fechaCfg, PDO::PARAM_STR);
                $stmtUpdateGarantia->bindValue(':monto_inicial', $montoCfg, PDO::PARAM_STR);
                $stmtUpdateGarantia->bindValue(':observaciones', $obsCfg, $obsCfg !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                if ($tieneColumnaGarantiaMedioRecepcion) {
                    $stmtUpdateGarantia->bindValue(':medio_recepcion', $medioCfg, PDO::PARAM_STR);
                }
                if ($tieneColumnaGarantiaReferenciaRecepcion) {
                    $stmtUpdateGarantia->bindValue(':referencia_recepcion', $refCfg, $refCfg !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                }
                $stmtUpdateGarantia->execute();
                $garantiasUpsertadas++;
                if ($tieneCambio) {
                    $garantiasParaRefrescarAsiento[$idGarantiaExistente] = $fechaCfg;
                }
                continue;
            }

            $stmtInsertGarantia->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
            $stmtInsertGarantia->bindValue(':id_local', $idLocalGarantia, PDO::PARAM_INT);
            if ($tieneIdContratoLocal) {
                $stmtInsertGarantia->bindValue(':id_contrato_local', (int) ($idContratoLocalPorLocal[$idLocalGarantia] ?? 0), PDO::PARAM_INT);
            }
            $stmtInsertGarantia->bindValue(':fecha_constitucion', $fechaCfg, PDO::PARAM_STR);
            $stmtInsertGarantia->bindValue(':monto_inicial', $montoCfg, PDO::PARAM_STR);
            $stmtInsertGarantia->bindValue(':observaciones', $obsCfg, $obsCfg !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            if ($tieneColumnaGarantiaMedioRecepcion) {
                $stmtInsertGarantia->bindValue(':medio_recepcion', $medioCfg, PDO::PARAM_STR);
            }
            if ($tieneColumnaGarantiaReferenciaRecepcion) {
                $stmtInsertGarantia->bindValue(':referencia_recepcion', $refCfg, $refCfg !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            }
            $stmtInsertGarantia->execute();
            $garantiasUpsertadas++;
        }
    }

    if (
        $puedeRefrescarAsientoGarantia
        && $stmtRevertirGarantiaAsiento instanceof PDOStatement
        && $stmtGenerarGarantiaAsiento instanceof PDOStatement
        && $garantiasParaRefrescarAsiento !== []
    ) {
        foreach ($garantiasParaRefrescarAsiento as $idGarantiaRefrescar => $fechaGarantiaRefrescar) {
            $stmtRevertirGarantiaAsiento->bindValue(':id_origen', (int) $idGarantiaRefrescar, PDO::PARAM_INT);
            $stmtRevertirGarantiaAsiento->bindValue(':fecha_reversa', (string) $fechaGarantiaRefrescar, PDO::PARAM_STR);
            $stmtRevertirGarantiaAsiento->bindValue(':motivo', 'Actualización de garantía desde contratos/index.php', PDO::PARAM_STR);
            $stmtRevertirGarantiaAsiento->execute();

            $stmtGenerarGarantiaAsiento->bindValue(':id_garantia', (int) $idGarantiaRefrescar, PDO::PARAM_INT);
            $stmtGenerarGarantiaAsiento->execute();
            $garantiasAsientoRefrescado++;
        }
    }

    if ($stmtInsertHistorial instanceof PDOStatement) {
        $detalle = [
            'origen' => 'contratos/actualizar.php',
            'id_tienda' => $idTienda,
            'id_tienda_original' => $idTiendaOriginal,
            'tienda_autogenerada' => $tiendaAutogenerada,
            'id_arrendatario' => $idArrendatario,
            'locales_seleccionados' => $localesCodigo,
            'locales_agregados' => count($idsLocalesNuevos),
            'locales_removidos' => $localesRemovidosCount,
            'reglas_arriendo_actualizadas' => $reglasArriendoActualizadas,
            'garantias_solicitadas' => $garantiasSolicitadasCount,
            'garantias_upsertadas' => $garantiasUpsertadas,
            'garantias_asiento_refrescado' => $garantiasAsientoRefrescado,
        ];
        $detalleJson = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmtInsertHistorial->bindValue(':id_contrato_arriendo', $idContrato, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':tipo_evento', 'ACTUALIZACION', PDO::PARAM_STR);
        $stmtInsertHistorial->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':detalle_evento', $detalleJson !== false ? $detalleJson : null, $detalleJson !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertHistorial->bindValue(':motivo_evento', 'Edición contractual desde contratos/index.php', PDO::PARAM_STR);
        $stmtInsertHistorial->execute();
    }

    $conn->commit();

    $mensaje = 'Contrato actualizado correctamente.';
    if ($idsLocalesNuevos !== []) {
        $mensaje .= ' Locales agregados: ' . count($idsLocalesNuevos) . '.';
    }
    if ($localesRemovidosCount > 0) {
        $mensaje .= ' Locales retirados: ' . $localesRemovidosCount . '.';
    }
    if ($reglasArriendoActualizadas > 0) {
        $mensaje .= ' Reglas arriendo actualizadas: ' . $reglasArriendoActualizadas . '.';
    }
    if ($garantiasUpsertadas > 0) {
        $mensaje .= ' Garantías actualizadas/creadas: ' . $garantiasUpsertadas . '.';
    }
    if ($garantiasAsientoRefrescado > 0) {
        $mensaje .= ' Asientos de garantía refrescados: ' . $garantiasAsientoRefrescado . '.';
    }
    msp2SetFlash('success', $mensaje);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosEditarRedirect($idContrato);
    }
    msp2SetFlash('danger', 'No fue posible actualizar el contrato.');
}

msp2ContratosEditarRedirect($idContrato);
