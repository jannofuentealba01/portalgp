<?php
declare(strict_types=1);

function ctTerrenosRepoPrepareStrict(PDO $conn, string $sql): PDOStatement
{
    preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $matches);
    $placeholders = isset($matches[0]) && is_array($matches[0]) ? $matches[0] : [];
    if ($placeholders !== []) {
        $counts = array_count_values($placeholders);
        $duplicados = [];
        foreach ($counts as $name => $count) {
            if ((int) $count > 1) {
                $duplicados[] = $name;
            }
        }
        if ($duplicados !== []) {
            throw new RuntimeException(
                'SQL inválido para ODBC: placeholders nombrados repetidos (' . implode(', ', $duplicados) . '). Usa nombres únicos.'
            );
        }
    }

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible preparar la sentencia SQL.');
    }
    return $stmt;
}

function ctTerrenosRepoBuildWhere(array $filtros): array
{
    $conditions = [];
    $params = [];

    $filtroTexto = (string) ($filtros['filtroTexto'] ?? '');
    $filtroCampo = strtolower(trim((string) ($filtros['filtroCampo'] ?? 'todos')));
    $filtroComuna = (int) ($filtros['filtroComuna'] ?? 0);
    $filtroEstadoPredial = (int) ($filtros['filtroEstadoPredial'] ?? 0);
    $filtroEstadoComercial = (int) ($filtros['filtroEstadoComercial'] ?? 0);
    $filtroTipoInmueble = (int) ($filtros['filtroTipoInmueble'] ?? 0);

    if ($filtroTexto !== '') {
        $filtroLen = function_exists('mb_strlen') ? mb_strlen($filtroTexto) : strlen($filtroTexto);
        $filtroLike = '%' . $filtroTexto . '%';
        if (!in_array($filtroCampo, ['todos', 'rol', 'identificacion', 'propietario'], true)) {
            $filtroCampo = 'todos';
        }

        $searchParts = [];
        if ($filtroCampo === 'todos' || $filtroCampo === 'rol') {
            $searchParts[] = "ISNULL(t.rol_asignado, '') LIKE :filtro_texto_rol";
            $searchParts[] = "ISNULL(t.rol_matriz, '') LIKE :filtro_texto_matriz";
            $params[':filtro_texto_rol'] = $filtroLike;
            $params[':filtro_texto_matriz'] = $filtroLike;
        }
        if ($filtroCampo === 'todos' || $filtroCampo === 'identificacion') {
            $searchParts[] = "ISNULL(t.identificacion_propiedad, '') LIKE :filtro_texto_ident";
            $params[':filtro_texto_ident'] = $filtroLike;
        }

        // En "Todos", propietario se activa desde 3+ caracteres para evitar ruido.
        $buscarPropietario = $filtroCampo === 'propietario' || ($filtroCampo === 'todos' && $filtroLen >= 3);
        if ($buscarPropietario) {
            $searchParts[] = "EXISTS (
                SELECT 1
                FROM dbo.ct_titularidad_terreno tt
                INNER JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
                WHERE tt.id_terreno = t.id_terreno
                  AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
                  AND (
                    ISNULL(tr.nombre_razon_social, '') LIKE :filtro_texto_propietario
                    OR ISNULL(tr.rut, '') LIKE :filtro_texto_propietario_rut
                  )
            )";
            $params[':filtro_texto_propietario'] = $filtroLike;
            $params[':filtro_texto_propietario_rut'] = $filtroLike;
        }

        if ($searchParts !== []) {
            $conditions[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    if ($filtroComuna > 0) {
        $conditions[] = 't.id_comuna = :filtro_comuna';
        $params[':filtro_comuna'] = $filtroComuna;
    }

    if ($filtroEstadoPredial > 0) {
        $conditions[] = 't.id_estado_predial = :filtro_estado_predial';
        $params[':filtro_estado_predial'] = $filtroEstadoPredial;
    }

    if ($filtroEstadoComercial > 0) {
        $conditions[] = 't.id_estado_comercial = :filtro_estado_comercial';
        $params[':filtro_estado_comercial'] = $filtroEstadoComercial;
    }

    if ($filtroTipoInmueble > 0) {
        $conditions[] = 't.id_tipo_inmueble = :filtro_tipo_inmueble';
        $params[':filtro_tipo_inmueble'] = $filtroTipoInmueble;
    }

    return [
        'where' => $conditions === [] ? '1=1' : implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function ctTerrenosRepoCount(PDO $conn, array $filtros): int
{
    $query = ctTerrenosRepoBuildWhere($filtros);

    $sql = "SELECT COUNT(*)
            FROM dbo.ct_terreno t
            WHERE {$query['where']}";
    $stmt = $conn->prepare($sql);
    foreach ($query['params'] as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoList(PDO $conn, array $filtros, string $orderSql, int $offset, int $limit): array
{
    $query = ctTerrenosRepoBuildWhere($filtros);

    $sql = "SELECT
                t.id_terreno,
                t.rol_asignado,
                t.rol_matriz,
                t.identificacion_propiedad,
                t.superficie_m2,
                t.id_comuna,
                c.nombre AS comuna_nombre,
                t.id_estado_predial,
                ep.nombre AS estado_predial_nombre,
                t.id_estado_comercial,
                ec.nombre AS estado_comercial_nombre,
                t.id_tipo_inmueble,
                ti.nombre AS tipo_inmueble_nombre,
                ISNULL(tp.propietario_principal, '') AS propietario_principal,
                ISNULL(tc.propietarios_vigentes_count, 0) AS propietarios_vigentes_count,
                uop.id_operacion AS ultima_operacion_id,
                uop.tipo_operacion AS ultima_operacion_tipo,
                uop.fecha_operacion AS ultima_operacion_fecha,
                uop.rol_en_operacion AS ultima_operacion_rol
            FROM dbo.ct_terreno t
            INNER JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
            INNER JOIN dbo.ct_estado_terreno_predial ep ON ep.id_estado_predial = t.id_estado_predial
            LEFT JOIN dbo.ct_estado_terreno_comercial ec ON ec.id_estado_comercial = t.id_estado_comercial
            INNER JOIN dbo.ct_tipo_inmueble ti ON ti.id_tipo_inmueble = t.id_tipo_inmueble
            OUTER APPLY (
                SELECT TOP (1)
                    tr.nombre_razon_social AS propietario_principal
                FROM dbo.ct_titularidad_terreno tt
                INNER JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
                WHERE tt.id_terreno = t.id_terreno
                  AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
                ORDER BY tt.porcentaje_derecho DESC, tt.vigente_desde DESC, tt.id_titularidad DESC
            ) tp
            OUTER APPLY (
                SELECT COUNT(1) AS propietarios_vigentes_count
                FROM dbo.ct_titularidad_terreno tt
                WHERE tt.id_terreno = t.id_terreno
                  AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
            ) tc
            OUTER APPLY (
                SELECT TOP (1)
                    op.id_operacion,
                    op.tipo_operacion,
                    op.fecha_operacion,
                    ot.rol_en_operacion
                FROM dbo.ct_operacion_terreno ot
                INNER JOIN dbo.ct_operacion_predial op ON op.id_operacion = ot.id_operacion
                WHERE ot.id_terreno = t.id_terreno
                ORDER BY op.fecha_operacion DESC, op.id_operacion DESC, ot.id_operacion_terreno DESC
            ) uop
            WHERE {$query['where']}
            ORDER BY {$orderSql}
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

    $stmt = $conn->prepare($sql);
    foreach ($query['params'] as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoBuildHistorialBaseTerrenosWhere(array $filtros): array
{
    $conditions = ['1=1'];
    $params = [];

    $filtroRol = trim((string) ($filtros['rol'] ?? ''));
    $filtroIdTerreno = (int) ($filtros['id_terreno'] ?? 0);
    $filtroComuna = (int) ($filtros['id_comuna'] ?? 0);

    if ($filtroRol !== '') {
        $conditions[] = 't.rol_asignado = :filtro_rol';
        $params[':filtro_rol'] = $filtroRol;
    }
    if ($filtroIdTerreno > 0) {
        $conditions[] = 't.id_terreno = :filtro_id_terreno';
        $params[':filtro_id_terreno'] = $filtroIdTerreno;
    }
    if ($filtroComuna > 0) {
        $conditions[] = 't.id_comuna = :filtro_id_comuna';
        $params[':filtro_id_comuna'] = $filtroComuna;
    }

    return [
        'where' => implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function ctTerrenosRepoListRolesSelector(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT DISTINCT
            t.rol_asignado
         FROM dbo.ct_terreno t
         WHERE LTRIM(RTRIM(ISNULL(t.rol_asignado, ''))) <> ''
         ORDER BY t.rol_asignado"
    );
    if ($stmt === false) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $rol = trim((string) ($row['rol_asignado'] ?? ''));
        if ($rol !== '') {
            $result[] = $rol;
        }
    }
    return $result;
}

function ctTerrenosRepoBuildHistorialDateFilter(
    string $column,
    ?string $fechaDesde,
    ?string $fechaHasta,
    string $prefix,
    array &$params
): string {
    $conditions = [];

    if ($fechaDesde !== null && trim($fechaDesde) !== '') {
        $param = ':' . $prefix . '_desde';
        $conditions[] = $column . ' >= CAST(' . $param . ' AS DATE)';
        $params[$param] = trim($fechaDesde);
    }

    if ($fechaHasta !== null && trim($fechaHasta) !== '') {
        $param = ':' . $prefix . '_hasta';
        $conditions[] = $column . ' < DATEADD(DAY, 1, CAST(' . $param . ' AS DATE))';
        $params[$param] = trim($fechaHasta);
    }

    return $conditions === [] ? '1=1' : implode(' AND ', $conditions);
}

function ctTerrenosRepoBuildHistorialEventsCte(array $filtros): array
{
    $base = ctTerrenosRepoBuildHistorialBaseTerrenosWhere($filtros);
    $params = $base['params'];

    $fechaDesde = trim((string) ($filtros['fecha_desde'] ?? ''));
    $fechaHasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
    $fechaDesde = $fechaDesde !== '' ? $fechaDesde : null;
    $fechaHasta = $fechaHasta !== '' ? $fechaHasta : null;

    $dateOps = ctTerrenosRepoBuildHistorialDateFilter('op.fecha_operacion', $fechaDesde, $fechaHasta, 'op', $params);
    $dateHist = ctTerrenosRepoBuildHistorialDateFilter('h.fecha_cambio', $fechaDesde, $fechaHasta, 'hist', $params);
    $dateTit = ctTerrenosRepoBuildHistorialDateFilter('tt.vigente_desde', $fechaDesde, $fechaHasta, 'tit', $params);

    $tipoOperacion = strtoupper(trim((string) ($filtros['tipo_operacion'] ?? '')));
    $filterTipoOps = '1=1';
    $filterTipoHist = '1=1';
    $filterTipoTit = '1=1';
    if ($tipoOperacion !== '') {
        $params[':tipo_op_ops'] = $tipoOperacion;
        $params[':tipo_op_hist'] = $tipoOperacion;
        $filterTipoOps = "UPPER(LTRIM(RTRIM(op.tipo_operacion))) = :tipo_op_ops";
        $filterTipoHist = "UPPER(LTRIM(RTRIM(ISNULL(op.tipo_operacion, '')))) = :tipo_op_hist";
        $filterTipoTit = '1=0';
    }

    $cte = "WITH base_terrenos AS (
                SELECT
                    t.id_terreno,
                    t.rol_asignado,
                    c.nombre AS comuna_nombre
                FROM dbo.ct_terreno t
                LEFT JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
                WHERE {$base['where']}
            ),
            eventos AS (
                SELECT
                    bt.id_terreno,
                    bt.rol_asignado,
                    bt.comuna_nombre,
                    CAST(op.fecha_operacion AS DATETIME2(0)) AS fecha_evento,
                    CAST('OPERACION' AS NVARCHAR(20)) AS evento_tipo,
                    CAST(NULL AS NVARCHAR(1)) AS tipo_estado,
                    op.id_operacion AS referencia_id,
                    op.id_operacion,
                    op.tipo_operacion,
                    ot.rol_en_operacion,
                    op.documento_fuente,
                    CAST(NULL AS NVARCHAR(120)) AS estado_anterior_nombre,
                    CAST(NULL AS NVARCHAR(120)) AS estado_nuevo_nombre,
                    CAST(NULL AS INT) AS id_tercero,
                    CAST(NULL AS NVARCHAR(255)) AS tercero_nombre,
                    CAST(NULL AS NVARCHAR(30)) AS tercero_rut,
                    CAST(NULL AS DECIMAL(5,2)) AS porcentaje_derecho,
                    CAST(NULL AS DATE) AS vigente_hasta,
                    CAST(NULL AS INT) AS id_usuario
                FROM base_terrenos bt
                INNER JOIN dbo.ct_operacion_terreno ot ON ot.id_terreno = bt.id_terreno
                INNER JOIN dbo.ct_operacion_predial op ON op.id_operacion = ot.id_operacion
                WHERE {$dateOps} AND {$filterTipoOps}

                UNION ALL

                SELECT
                    bt.id_terreno,
                    bt.rol_asignado,
                    bt.comuna_nombre,
                    CAST(h.fecha_cambio AS DATETIME2(0)) AS fecha_evento,
                    CAST('ESTADO' AS NVARCHAR(20)) AS evento_tipo,
                    h.tipo_estado,
                    h.id_historial_estado AS referencia_id,
                    h.id_operacion,
                    op.tipo_operacion,
                    CAST(NULL AS NVARCHAR(40)) AS rol_en_operacion,
                    CAST(NULL AS NVARCHAR(255)) AS documento_fuente,
                    CASE
                        WHEN h.tipo_estado = 'P' THEN ep_old.nombre
                        WHEN h.tipo_estado = 'C' THEN ec_old.nombre
                        ELSE NULL
                    END AS estado_anterior_nombre,
                    CASE
                        WHEN h.tipo_estado = 'P' THEN ep_new.nombre
                        WHEN h.tipo_estado = 'C' THEN ec_new.nombre
                        ELSE NULL
                    END AS estado_nuevo_nombre,
                    CAST(NULL AS INT) AS id_tercero,
                    CAST(NULL AS NVARCHAR(255)) AS tercero_nombre,
                    CAST(NULL AS NVARCHAR(30)) AS tercero_rut,
                    CAST(NULL AS DECIMAL(5,2)) AS porcentaje_derecho,
                    CAST(NULL AS DATE) AS vigente_hasta,
                    h.id_usuario
                FROM base_terrenos bt
                INNER JOIN dbo.ct_historial_estado_terreno h ON h.id_terreno = bt.id_terreno
                LEFT JOIN dbo.ct_operacion_predial op ON op.id_operacion = h.id_operacion
                LEFT JOIN dbo.ct_estado_terreno_predial ep_old
                    ON h.tipo_estado = 'P' AND ep_old.id_estado_predial = h.id_estado_anterior
                LEFT JOIN dbo.ct_estado_terreno_predial ep_new
                    ON h.tipo_estado = 'P' AND ep_new.id_estado_predial = h.id_estado_nuevo
                LEFT JOIN dbo.ct_estado_terreno_comercial ec_old
                    ON h.tipo_estado = 'C' AND ec_old.id_estado_comercial = h.id_estado_anterior
                LEFT JOIN dbo.ct_estado_terreno_comercial ec_new
                    ON h.tipo_estado = 'C' AND ec_new.id_estado_comercial = h.id_estado_nuevo
                WHERE {$dateHist} AND {$filterTipoHist}

                UNION ALL

                SELECT
                    bt.id_terreno,
                    bt.rol_asignado,
                    bt.comuna_nombre,
                    CAST(tt.vigente_desde AS DATETIME2(0)) AS fecha_evento,
                    CAST('TITULARIDAD' AS NVARCHAR(20)) AS evento_tipo,
                    CAST(NULL AS NVARCHAR(1)) AS tipo_estado,
                    tt.id_titularidad AS referencia_id,
                    CAST(NULL AS INT) AS id_operacion,
                    CAST(NULL AS NVARCHAR(20)) AS tipo_operacion,
                    CAST(NULL AS NVARCHAR(40)) AS rol_en_operacion,
                    CAST(NULL AS NVARCHAR(255)) AS documento_fuente,
                    CAST(NULL AS NVARCHAR(120)) AS estado_anterior_nombre,
                    CAST(NULL AS NVARCHAR(120)) AS estado_nuevo_nombre,
                    tt.id_tercero,
                    tr.nombre_razon_social AS tercero_nombre,
                    tr.rut AS tercero_rut,
                    tt.porcentaje_derecho,
                    tt.vigente_hasta,
                    CAST(NULL AS INT) AS id_usuario
                FROM base_terrenos bt
                INNER JOIN dbo.ct_titularidad_terreno tt ON tt.id_terreno = bt.id_terreno
                LEFT JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
                WHERE {$dateTit} AND {$filterTipoTit}
            )";

    return [
        'cte' => $cte,
        'params' => $params,
    ];
}

function ctTerrenosRepoHistorialCount(PDO $conn, array $filtros): int
{
    $built = ctTerrenosRepoBuildHistorialEventsCte($filtros);
    $sql = $built['cte'] . '
            SELECT COUNT(*) AS total
            FROM eventos';

    $stmt = $conn->prepare($sql);
    foreach ($built['params'] as $param => $value) {
        $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoHistorialList(PDO $conn, array $filtros, int $offset, int $limit): array
{
    $built = ctTerrenosRepoBuildHistorialEventsCte($filtros);
    $sql = $built['cte'] . '
            SELECT
                id_terreno,
                rol_asignado,
                comuna_nombre,
                fecha_evento,
                evento_tipo,
                tipo_estado,
                referencia_id,
                id_operacion,
                tipo_operacion,
                rol_en_operacion,
                documento_fuente,
                estado_anterior_nombre,
                estado_nuevo_nombre,
                id_tercero,
                tercero_nombre,
                tercero_rut,
                porcentaje_derecho,
                vigente_hasta,
                id_usuario
            FROM eventos
            ORDER BY fecha_evento DESC, id_terreno DESC, referencia_id DESC
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

    $stmt = $conn->prepare($sql);
    foreach ($built['params'] as $param => $value) {
        $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoBuildHistorialSimpleEventsCte(array $filtros): array
{
    $params = [];
    $buildBaseWhere = static function (string $suffix) use ($filtros, &$params): array {
        $base = ctTerrenosRepoBuildHistorialBaseTerrenosWhere($filtros);
        $where = $base['where'];
        foreach ($base['params'] as $param => $value) {
            $newParam = $param . '_' . $suffix;
            $where = str_replace($param, $newParam, $where);
            $params[$newParam] = $value;
        }

        return [
            'where' => $where,
        ];
    };
    $baseOps = $buildBaseWhere('ops');
    $baseTas = $buildBaseWhere('tas');
    $baseVen = $buildBaseWhere('ven');

    $fechaDesde = trim((string) ($filtros['fecha_desde'] ?? ''));
    $fechaHasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
    $fechaDesde = $fechaDesde !== '' ? $fechaDesde : null;
    $fechaHasta = $fechaHasta !== '' ? $fechaHasta : null;

    $dateOps = ctTerrenosRepoBuildHistorialDateFilter('op.fecha_operacion', $fechaDesde, $fechaHasta, 'op_simple', $params);
    $dateTas = ctTerrenosRepoBuildHistorialDateFilter('tas.fecha_tasacion', $fechaDesde, $fechaHasta, 'tas_simple', $params);
    $dateVen = ctTerrenosRepoBuildHistorialDateFilter('ven.fecha_venta', $fechaDesde, $fechaHasta, 'ven_simple', $params);

    $tipoOperacion = strtoupper(trim((string) ($filtros['tipo_operacion'] ?? '')));
    $filterOps = '1=1';
    $filterTas = '1=1';
    $filterVen = '1=1';
    if ($tipoOperacion !== '') {
        if ($tipoOperacion === 'TASACION') {
            $filterOps = '1=0';
            $filterVen = '1=0';
        } elseif ($tipoOperacion === 'VENTA') {
            $filterOps = '1=0';
            $filterTas = '1=0';
        } else {
            $params[':tipo_op_simple'] = $tipoOperacion;
            $filterOps = 'UPPER(LTRIM(RTRIM(op.tipo_operacion))) = :tipo_op_simple';
            $filterTas = '1=0';
            $filterVen = '1=0';
        }
    }

    $cte = "WITH eventos_simple AS (
                SELECT
                    op.id_operacion AS evento_id,
                    CAST('OPERACION' AS NVARCHAR(20)) AS fuente,
                    op.id_operacion,
                    CAST(NULL AS INT) AS id_tasacion,
                    CAST(NULL AS INT) AS id_venta,
                    op.tipo_operacion,
                    CAST(op.fecha_operacion AS DATETIME2(0)) AS fecha_evento,
                    op.documento_fuente,
                    CAST(NULL AS DECIMAL(18,4)) AS valor_total_uf,
                    CAST(NULL AS DECIMAL(18,4)) AS valor_uf_m2,
                    CAST(NULL AS INT) AS id_terreno_directo,
                    CAST(NULL AS NVARCHAR(255)) AS rol_directo,
                    CAST(NULL AS DECIMAL(18,2)) AS superficie_directa
                FROM dbo.ct_operacion_predial op
                WHERE {$dateOps}
                  AND {$filterOps}
                  AND EXISTS (
                        SELECT 1
                        FROM dbo.ct_operacion_terreno ot
                        INNER JOIN dbo.ct_terreno t ON t.id_terreno = ot.id_terreno
                        WHERE ot.id_operacion = op.id_operacion
                          AND {$baseOps['where']}
                  )

                UNION ALL

                SELECT
                    tas.id_tasacion AS evento_id,
                    CAST('TASACION' AS NVARCHAR(20)) AS fuente,
                    CAST(NULL AS INT) AS id_operacion,
                    tas.id_tasacion,
                    CAST(NULL AS INT) AS id_venta,
                    CAST('TASACION' AS NVARCHAR(20)) AS tipo_operacion,
                    CAST(tas.fecha_tasacion AS DATETIME2(0)) AS fecha_evento,
                    CAST(NULL AS NVARCHAR(255)) AS documento_fuente,
                    tas.valor_total_uf,
                    tas.valor_uf_m2,
                    t.id_terreno AS id_terreno_directo,
                    t.rol_asignado AS rol_directo,
                    t.superficie_m2 AS superficie_directa
                FROM dbo.ct_tasacion_terreno tas
                INNER JOIN dbo.ct_terreno t ON t.id_terreno = tas.id_terreno
                WHERE {$dateTas}
                  AND {$filterTas}
                  AND {$baseTas['where']}

                UNION ALL

                SELECT
                    ven.id_venta AS evento_id,
                    CAST('VENTA' AS NVARCHAR(20)) AS fuente,
                    CAST(NULL AS INT) AS id_operacion,
                    CAST(NULL AS INT) AS id_tasacion,
                    ven.id_venta,
                    CAST('VENTA' AS NVARCHAR(20)) AS tipo_operacion,
                    CAST(ven.fecha_venta AS DATETIME2(0)) AS fecha_evento,
                    CAST(NULL AS NVARCHAR(255)) AS documento_fuente,
                    ven.valor_total_uf,
                    ven.valor_venta_uf_m2 AS valor_uf_m2,
                    t.id_terreno AS id_terreno_directo,
                    t.rol_asignado AS rol_directo,
                    t.superficie_m2 AS superficie_directa
                FROM dbo.ct_venta_terreno ven
                INNER JOIN dbo.ct_terreno t ON t.id_terreno = ven.id_terreno
                WHERE {$dateVen}
                  AND {$filterVen}
                  AND {$baseVen['where']}
            )";

    return [
        'cte' => $cte,
        'params' => $params,
    ];
}

function ctTerrenosRepoHistorialSimpleCount(PDO $conn, array $filtros): int
{
    $built = ctTerrenosRepoBuildHistorialSimpleEventsCte($filtros);
    $sql = $built['cte'] . '
            SELECT COUNT(*) AS total
            FROM eventos_simple';

    $stmt = $conn->prepare($sql);
    foreach ($built['params'] as $param => $value) {
        $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoHistorialSimpleList(PDO $conn, array $filtros, int $offset, int $limit): array
{
    $built = ctTerrenosRepoBuildHistorialSimpleEventsCte($filtros);
    $sql = $built['cte'] . '
            SELECT
                evento_id,
                fuente,
                id_operacion,
                id_tasacion,
                id_venta,
                tipo_operacion,
                fecha_evento,
                documento_fuente,
                valor_total_uf,
                valor_uf_m2,
                id_terreno_directo,
                rol_directo,
                superficie_directa
            FROM eventos_simple
            ORDER BY fecha_evento DESC, evento_id DESC, fuente ASC
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';

    $stmt = $conn->prepare($sql);
    foreach ($built['params'] as $param => $value) {
        $stmt->bindValue($param, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $idsOperacion = [];
    foreach ($rows as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idOperacion > 0) {
            $idsOperacion[$idOperacion] = true;
        }
    }

    $participantesPorOperacion = [];
    if ($idsOperacion !== []) {
        foreach (ctTerrenosRepoListOperacionTerrenosByOperacionIds($conn, array_keys($idsOperacion)) as $participante) {
            $idOperacion = (int) ($participante['id_operacion'] ?? 0);
            if ($idOperacion <= 0) {
                continue;
            }
            if (!isset($participantesPorOperacion[$idOperacion])) {
                $participantesPorOperacion[$idOperacion] = [];
            }
            $participantesPorOperacion[$idOperacion][] = $participante;
        }
    }

    foreach ($rows as &$row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        $row['participantes'] = $idOperacion > 0 && is_array($participantesPorOperacion[$idOperacion] ?? null)
            ? $participantesPorOperacion[$idOperacion]
            : [];
    }
    unset($row);

    return $rows;
}

function ctTerrenosRepoListTiposOperacionHistorial(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT DISTINCT
            UPPER(LTRIM(RTRIM(tipo_operacion))) AS tipo_operacion
         FROM dbo.ct_operacion_predial
         WHERE LTRIM(RTRIM(ISNULL(tipo_operacion, ''))) <> ''
         ORDER BY tipo_operacion"
    );
    if ($stmt === false) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $tipo = trim((string) ($row['tipo_operacion'] ?? ''));
        if ($tipo !== '') {
            $result[] = $tipo;
        }
    }
    return $result;
}

function ctTerrenosRepoInsert(
    PDO $conn,
    string $rolAsignado,
    ?string $rolMatriz,
    ?string $identificacionPropiedad,
    float $superficieM2,
    int $idComuna,
    int $idEstadoPredial,
    int $idEstadoComercial,
    int $idTipoInmueble
): void {
    $sql = 'INSERT INTO dbo.ct_terreno (
                rol_asignado,
                rol_matriz,
                identificacion_propiedad,
                superficie_m2,
                id_comuna,
                id_estado_predial,
                id_estado_comercial,
                id_tipo_inmueble
            ) VALUES (
                :rol_asignado,
                :rol_matriz,
                :identificacion_propiedad,
                :superficie_m2,
                :id_comuna,
                :id_estado_predial,
                :id_estado_comercial,
                :id_tipo_inmueble
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':rol_asignado', $rolAsignado, PDO::PARAM_STR);
    if ($rolMatriz === null) {
        $stmt->bindValue(':rol_matriz', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rol_matriz', $rolMatriz, PDO::PARAM_STR);
    }
    if ($identificacionPropiedad === null) {
        $stmt->bindValue(':identificacion_propiedad', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':identificacion_propiedad', $identificacionPropiedad, PDO::PARAM_STR);
    }
    $stmt->bindValue(':superficie_m2', $superficieM2);
    $stmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_predial', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_comercial', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->bindValue(':id_tipo_inmueble', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoUpdate(
    PDO $conn,
    int $idTerreno,
    string $rolAsignado,
    ?string $rolMatriz,
    ?string $identificacionPropiedad,
    float $superficieM2,
    int $idComuna,
    int $idEstadoPredial,
    int $idEstadoComercial,
    int $idTipoInmueble
): void {
    $sql = 'UPDATE dbo.ct_terreno
            SET rol_asignado = :rol_asignado,
                rol_matriz = :rol_matriz,
                identificacion_propiedad = :identificacion_propiedad,
                superficie_m2 = :superficie_m2,
                id_comuna = :id_comuna,
                id_estado_predial = :id_estado_predial,
                id_estado_comercial = :id_estado_comercial,
                id_tipo_inmueble = :id_tipo_inmueble
            WHERE id_terreno = :id_terreno';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->bindValue(':rol_asignado', $rolAsignado, PDO::PARAM_STR);
    if ($rolMatriz === null) {
        $stmt->bindValue(':rol_matriz', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rol_matriz', $rolMatriz, PDO::PARAM_STR);
    }
    if ($identificacionPropiedad === null) {
        $stmt->bindValue(':identificacion_propiedad', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':identificacion_propiedad', $identificacionPropiedad, PDO::PARAM_STR);
    }
    $stmt->bindValue(':superficie_m2', $superficieM2);
    $stmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_predial', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_comercial', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->bindValue(':id_tipo_inmueble', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoDelete(PDO $conn, int $idTerreno): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_terreno WHERE id_terreno = :id');
    $stmt->bindValue(':id', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoFindById(PDO $conn, int $idTerreno): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            id_terreno,
            id_estado_predial,
            id_estado_comercial
         FROM dbo.ct_terreno
         WHERE id_terreno = :id'
    );
    $stmt->bindValue(':id', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctTerrenosRepoFindResumenById(PDO $conn, int $idTerreno): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            t.id_terreno,
            t.rol_asignado,
            c.nombre AS comuna_nombre
         FROM dbo.ct_terreno t
         LEFT JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
         WHERE t.id_terreno = :id'
    );
    $stmt->bindValue(':id', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctTerrenosRepoFindFichaById(PDO $conn, int $idTerreno): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            t.id_terreno,
            t.rol_asignado,
            t.rol_matriz,
            t.identificacion_propiedad,
            t.superficie_m2,
            c.nombre AS comuna_nombre,
            ep.nombre AS estado_predial_nombre,
            ec.nombre AS estado_comercial_nombre,
            ti.nombre AS tipo_inmueble_nombre
         FROM dbo.ct_terreno t
         LEFT JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
         LEFT JOIN dbo.ct_estado_terreno_predial ep ON ep.id_estado_predial = t.id_estado_predial
         LEFT JOIN dbo.ct_estado_terreno_comercial ec ON ec.id_estado_comercial = t.id_estado_comercial
         LEFT JOIN dbo.ct_tipo_inmueble ti ON ti.id_tipo_inmueble = t.id_tipo_inmueble
         WHERE t.id_terreno = :id'
    );
    $stmt->bindValue(':id', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctTerrenosRepoListHistorialOperaciones(PDO $conn, int $idTerreno): array
{
    $stmt = $conn->prepare(
        'SELECT
            ot.id_operacion_terreno,
            ot.rol_en_operacion,
            op.id_operacion,
            op.tipo_operacion,
            CAST(op.fecha_operacion AS DATETIME2(0)) AS fecha_operacion,
            CAST(op.fecha_registro AS DATETIME2(0)) AS fecha_registro,
            op.documento_fuente
         FROM dbo.ct_operacion_terreno ot
         INNER JOIN dbo.ct_operacion_predial op ON op.id_operacion = ot.id_operacion
         WHERE ot.id_terreno = :id_terreno
         ORDER BY op.fecha_operacion DESC, op.id_operacion DESC, ot.id_operacion_terreno DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListOperacionTerrenosByOperacionIds(PDO $conn, array $idsOperacion): array
{
    $ids = [];
    foreach ($idsOperacion as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    $idList = array_values(array_map('intval', array_keys($ids)));
    if ($idList === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($idList as $idx => $idOperacion) {
        $ph = ':id_op_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idOperacion;
    }

    $sql = 'SELECT
                ot.id_operacion,
                ot.id_terreno,
                ot.rol_en_operacion,
                t.rol_asignado,
                t.superficie_m2
            FROM dbo.ct_operacion_terreno ot
            INNER JOIN dbo.ct_terreno t ON t.id_terreno = ot.id_terreno
            WHERE ot.id_operacion IN (' . implode(', ', $placeholders) . ')
            ORDER BY ot.id_operacion ASC, ot.id_operacion_terreno ASC';

    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListOperacionIdsByTerrenoIds(PDO $conn, array $idsTerreno): array
{
    $ids = [];
    foreach ($idsTerreno as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    $idList = array_values(array_map('intval', array_keys($ids)));
    if ($idList === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($idList as $idx => $idTerreno) {
        $ph = ':id_terreno_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idTerreno;
    }

    $sql = 'SELECT DISTINCT
                ot.id_operacion
            FROM dbo.ct_operacion_terreno ot
            WHERE ot.id_terreno IN (' . implode(', ', $placeholders) . ')';

    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idOperacion > 0) {
            $result[$idOperacion] = true;
        }
    }

    return array_values(array_map('intval', array_keys($result)));
}

function ctTerrenosRepoListOperacionesByIds(PDO $conn, array $idsOperacion): array
{
    $ids = [];
    foreach ($idsOperacion as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    $idList = array_values(array_map('intval', array_keys($ids)));
    if ($idList === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($idList as $idx => $idOperacion) {
        $ph = ':id_op_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idOperacion;
    }

    $sql = 'SELECT
                op.id_operacion,
                op.tipo_operacion,
                CAST(op.fecha_operacion AS DATETIME2(0)) AS fecha_operacion,
                CAST(op.fecha_registro AS DATETIME2(0)) AS fecha_registro,
                op.documento_fuente
            FROM dbo.ct_operacion_predial op
            WHERE op.id_operacion IN (' . implode(', ', $placeholders) . ')';

    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListHistorialEstados(PDO $conn, int $idTerreno): array
{
    $stmt = $conn->prepare(
        'SELECT
            h.id_historial_estado,
            CAST(h.fecha_cambio AS DATETIME2(0)) AS fecha_cambio,
            h.tipo_estado,
            h.id_estado_anterior,
            h.id_estado_nuevo,
            h.id_venta,
            h.id_operacion,
            h.id_usuario,
            ep_old.nombre AS estado_predial_anterior_nombre,
            ep_new.nombre AS estado_predial_nuevo_nombre,
            ec_old.nombre AS estado_comercial_anterior_nombre,
            ec_new.nombre AS estado_comercial_nuevo_nombre
         FROM dbo.ct_historial_estado_terreno h
         LEFT JOIN dbo.ct_estado_terreno_predial ep_old
           ON h.tipo_estado = \'P\' AND ep_old.id_estado_predial = h.id_estado_anterior
         LEFT JOIN dbo.ct_estado_terreno_predial ep_new
           ON h.tipo_estado = \'P\' AND ep_new.id_estado_predial = h.id_estado_nuevo
         LEFT JOIN dbo.ct_estado_terreno_comercial ec_old
           ON h.tipo_estado = \'C\' AND ec_old.id_estado_comercial = h.id_estado_anterior
         LEFT JOIN dbo.ct_estado_terreno_comercial ec_new
           ON h.tipo_estado = \'C\' AND ec_new.id_estado_comercial = h.id_estado_nuevo
         WHERE h.id_terreno = :id_terreno
         ORDER BY h.fecha_cambio DESC, h.id_historial_estado DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListHistorialTitularidades(PDO $conn, int $idTerreno): array
{
    $stmt = $conn->prepare(
        'SELECT
            tt.id_titularidad,
            tt.id_tercero,
            tr.nombre_razon_social AS tercero_nombre,
            tr.rut AS tercero_rut,
            CAST(tt.vigente_desde AS DATE) AS vigente_desde,
            CAST(tt.vigente_hasta AS DATE) AS vigente_hasta,
            tt.porcentaje_derecho
         FROM dbo.ct_titularidad_terreno tt
         LEFT JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
         WHERE tt.id_terreno = :id_terreno
         ORDER BY tt.vigente_desde DESC, tt.id_titularidad DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListHistorialTasaciones(PDO $conn, int $idTerreno): array
{
    $hasFechaRegistro = false;
    $colStmt = $conn->prepare(
        'SELECT TOP (1) 1
         FROM sys.columns
         WHERE object_id = OBJECT_ID(\'dbo.ct_tasacion_terreno\')
           AND name = \'fecha_registro\''
    );
    if ($colStmt instanceof PDOStatement) {
        $colStmt->execute();
        $hasFechaRegistro = $colStmt->fetchColumn() !== false;
    }

    $fechaRegistroSql = $hasFechaRegistro
        ? 'CAST(t.fecha_registro AS DATETIME2(0))'
        : 'CAST(hs.fecha_cambio AS DATETIME2(0))';
    $hasEntidadFinanciera = false;
    $existsEntidadStmt = $conn->query("SELECT OBJECT_ID('dbo.ct_entidad_financiera', 'U')");
    if ($existsEntidadStmt instanceof PDOStatement) {
        $hasEntidadFinanciera = (int) $existsEntidadStmt->fetchColumn() > 0;
    }
    $entidadNombreSql = $hasEntidadFinanciera ? 'ISNULL(ef.nombre, \'\')' : '\'\'';
    $entidadJoinSql = $hasEntidadFinanciera
        ? 'LEFT JOIN dbo.ct_entidad_financiera ef ON ef.id_entidad_financiera = t.id_entidad_financiera'
        : '';

    $stmt = $conn->prepare(
        'SELECT
            t.id_tasacion,
            CAST(t.fecha_tasacion AS DATE) AS fecha_tasacion,
            ' . $fechaRegistroSql . ' AS fecha_registro,
            t.valor_total_uf,
            t.valor_uf_m2,
            t.es_referencial,
            CAST(t.vigente_desde AS DATE) AS vigente_desde,
            CAST(t.vigente_hasta AS DATE) AS vigente_hasta,
            t.id_usuario,
            ' . $entidadNombreSql . ' AS entidad_financiera_nombre,
            ISNULL(tt.nombre, \'\') AS tipo_tasacion_nombre
         FROM dbo.ct_tasacion_terreno t
         LEFT JOIN dbo.ct_tipo_tasacion tt ON tt.id_tipo_tasacion = t.id_tipo_tasacion
         ' . $entidadJoinSql . '
         OUTER APPLY (
            SELECT TOP (1)
                h.fecha_cambio
            FROM dbo.ct_historial_estado_terreno h
            WHERE h.id_terreno = t.id_terreno
              AND h.tipo_estado = \'C\'
              AND h.id_venta IS NULL
              AND h.id_usuario = t.id_usuario
              AND CAST(h.fecha_cambio AS DATE) = t.fecha_tasacion
            ORDER BY h.fecha_cambio DESC, h.id_historial_estado DESC
         ) hs
         WHERE t.id_terreno = :id_terreno
         ORDER BY t.fecha_tasacion DESC, t.id_tasacion DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListHistorialVentas(PDO $conn, int $idTerreno): array
{
    $stmt = $conn->prepare(
        'SELECT
            v.id_venta,
            CAST(v.fecha_venta AS DATE) AS fecha_venta,
            v.valor_total_uf,
            v.valor_venta_uf_m2,
            v.id_tasacion_referencial,
            hs.id_usuario,
            CAST(hs.fecha_cambio AS DATETIME2(0)) AS fecha_registro
         FROM dbo.ct_venta_terreno v
         OUTER APPLY (
            SELECT TOP (1)
                h.id_usuario,
                h.fecha_cambio
            FROM dbo.ct_historial_estado_terreno h
            WHERE h.id_venta = v.id_venta
              AND h.id_terreno = v.id_terreno
            ORDER BY h.fecha_cambio DESC, h.id_historial_estado DESC
         ) hs
         WHERE v.id_terreno = :id_terreno
         ORDER BY v.fecha_venta DESC, v.id_venta DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListVentaCompradoresByVentas(PDO $conn, array $idsVenta): array
{
    $ids = [];
    foreach ($idsVenta as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    $idList = array_keys($ids);
    if ($idList === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($idList as $idx => $idVenta) {
        $ph = ':id_venta_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idVenta;
    }

    $sql = 'SELECT
                vt.id_venta,
                tr.nombre_razon_social AS tercero_nombre,
                tr.rut AS tercero_rut,
                vt.porcentaje,
                vt.rol_en_venta
            FROM dbo.ct_venta_terreno_tercero vt
            LEFT JOIN dbo.ct_tercero tr ON tr.id_tercero = vt.id_tercero
            WHERE vt.id_venta IN (' . implode(', ', $placeholders) . ')
            ORDER BY vt.id_venta ASC, vt.id_venta_tercero ASC';

    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoListTitularesVigentes(PDO $conn, int $idTerreno): array
{
    $stmt = $conn->prepare(
        'SELECT
            tt.id_titularidad,
            tt.id_tercero,
            tr.nombre_razon_social AS tercero_nombre,
            tr.rut AS tercero_rut,
            CAST(tt.vigente_desde AS DATE) AS vigente_desde,
            CAST(tt.vigente_hasta AS DATE) AS vigente_hasta,
            tt.porcentaje_derecho
         FROM dbo.ct_titularidad_terreno tt
         LEFT JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
         WHERE tt.id_terreno = :id_terreno
           AND (
                tt.vigente_hasta IS NULL
                OR tt.vigente_hasta >= CAST(GETDATE() AS DATE)
           )
         ORDER BY tt.porcentaje_derecho DESC, tt.vigente_desde DESC, tt.id_titularidad DESC'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTerrenosRepoQuoteIdentifier(string $name): string
{
    return '[' . str_replace(']', ']]', $name) . ']';
}

function ctTerrenosRepoResolveUsuariosDisplayMap(PDO $conn, array $idsUsuario): array
{
    $ids = [];
    foreach ($idsUsuario as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $idList = array_keys($ids);
    if ($idList === []) {
        return [];
    }

    $tableExistsStmt = $conn->query("SELECT OBJECT_ID('dbo.cr_usuarios', 'U')");
    if ($tableExistsStmt === false) {
        return [];
    }
    $tableId = (int) $tableExistsStmt->fetchColumn();
    if ($tableId <= 0) {
        return [];
    }

    $colStmt = $conn->prepare(
        'SELECT name
         FROM sys.columns
         WHERE object_id = OBJECT_ID(\'dbo.cr_usuarios\')'
    );
    $colStmt->execute();
    $columnRows = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($columnRows) || $columnRows === []) {
        return [];
    }

    $columnsByLower = [];
    foreach ($columnRows as $colRow) {
        $name = trim((string) ($colRow['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $columnsByLower[strtolower($name)] = $name;
    }

    $idColumn = null;
    foreach (['id_usuario', 'id'] as $candidate) {
        if (isset($columnsByLower[$candidate])) {
            $idColumn = $columnsByLower[$candidate];
            break;
        }
    }
    if ($idColumn === null) {
        return [];
    }

    $displayColumn = null;
    foreach (['nombre_completo', 'nombre', 'username', 'usuario', 'user_name', 'login', 'email', 'correo'] as $candidate) {
        if (isset($columnsByLower[$candidate])) {
            $displayColumn = $columnsByLower[$candidate];
            break;
        }
    }

    if ($displayColumn !== null) {
        $displayExpr = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctTerrenosRepoQuoteIdentifier($displayColumn) . '))), \'\')';
    } elseif (isset($columnsByLower['nombres']) || isset($columnsByLower['apellidos'])) {
        $nombresExpr = isset($columnsByLower['nombres'])
            ? 'ISNULL(CONVERT(NVARCHAR(255), ' . ctTerrenosRepoQuoteIdentifier($columnsByLower['nombres']) . '), \'\')'
            : '\'\'';
        $apellidosExpr = isset($columnsByLower['apellidos'])
            ? 'ISNULL(CONVERT(NVARCHAR(255), ' . ctTerrenosRepoQuoteIdentifier($columnsByLower['apellidos']) . '), \'\')'
            : '\'\'';
        $displayExpr = 'NULLIF(LTRIM(RTRIM('
            . $nombresExpr
            . ' + CASE WHEN ' . $apellidosExpr . ' <> \'\' THEN \' \' + ' . $apellidosExpr . ' ELSE \'\' END'
            . ')), \'\')';
    } else {
        $displayExpr = 'NULL';
    }

    $params = [];
    $placeholders = [];
    foreach (array_values($idList) as $idx => $idUsuario) {
        $ph = ':id_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idUsuario;
    }

    $sql = 'SELECT
                CAST(' . ctTerrenosRepoQuoteIdentifier($idColumn) . ' AS INT) AS id_usuario,
                ' . $displayExpr . ' AS usuario_nombre
            FROM dbo.cr_usuarios
            WHERE ' . ctTerrenosRepoQuoteIdentifier($idColumn) . ' IN (' . implode(', ', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        $nombre = trim((string) ($row['usuario_nombre'] ?? ''));
        if ($nombre !== '') {
            $map[$idUsuario] = $nombre;
        }
    }

    return $map;
}

function ctTerrenosRepoResolveUsuariosLogoMap(PDO $conn, array $idsUsuario): array
{
    $ids = [];
    foreach ($idsUsuario as $rawId) {
        if (!is_numeric((string) $rawId)) {
            continue;
        }
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $idList = array_keys($ids);
    if ($idList === []) {
        return [];
    }

    $tableExistsStmt = $conn->query("SELECT OBJECT_ID('dbo.cr_usuarios', 'U')");
    if ($tableExistsStmt === false) {
        return [];
    }
    $tableId = (int) $tableExistsStmt->fetchColumn();
    if ($tableId <= 0) {
        return [];
    }

    $colStmt = $conn->prepare(
        'SELECT name
         FROM sys.columns
         WHERE object_id = OBJECT_ID(\'dbo.cr_usuarios\')'
    );
    $colStmt->execute();
    $columnRows = $colStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($columnRows) || $columnRows === []) {
        return [];
    }

    $columnsByLower = [];
    foreach ($columnRows as $colRow) {
        $name = trim((string) ($colRow['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $columnsByLower[strtolower($name)] = $name;
    }

    $idColumn = null;
    foreach (['id_usuario', 'id'] as $candidate) {
        if (isset($columnsByLower[$candidate])) {
            $idColumn = $columnsByLower[$candidate];
            break;
        }
    }
    if ($idColumn === null || !isset($columnsByLower['url_logo'])) {
        return [];
    }
    $urlLogoColumn = $columnsByLower['url_logo'];

    $params = [];
    $placeholders = [];
    foreach (array_values($idList) as $idx => $idUsuario) {
        $ph = ':id_' . $idx;
        $placeholders[] = $ph;
        $params[$ph] = $idUsuario;
    }

    $sql = 'SELECT
                CAST(' . ctTerrenosRepoQuoteIdentifier($idColumn) . ' AS INT) AS id_usuario,
                NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(500), ' . ctTerrenosRepoQuoteIdentifier($urlLogoColumn) . '))), \'\') AS url_logo
            FROM dbo.cr_usuarios
            WHERE ' . ctTerrenosRepoQuoteIdentifier($idColumn) . ' IN (' . implode(', ', $placeholders) . ')';
    $stmt = $conn->prepare($sql);
    foreach ($params as $ph => $value) {
        $stmt->bindValue($ph, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        $urlLogo = trim((string) ($row['url_logo'] ?? ''));
        if ($urlLogo !== '') {
            $map[$idUsuario] = $urlLogo;
        }
    }

    return $map;
}

function ctTerrenosRepoListComunas(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            c.id_comuna,
            c.nombre,
            COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_comuna c
         LEFT JOIN dbo.ct_terreno t ON t.id_comuna = c.id_comuna
         GROUP BY c.id_comuna, c.nombre
         ORDER BY c.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoListEstadosPrediales(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            ep.id_estado_predial,
            ep.nombre,
            COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_estado_terreno_predial ep
         LEFT JOIN dbo.ct_terreno t ON t.id_estado_predial = ep.id_estado_predial
         GROUP BY ep.id_estado_predial, ep.nombre
         ORDER BY ep.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoListEstadosComerciales(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            ec.id_estado_comercial,
            ec.nombre,
            COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_estado_terreno_comercial ec
         LEFT JOIN dbo.ct_terreno t ON t.id_estado_comercial = ec.id_estado_comercial
         GROUP BY ec.id_estado_comercial, ec.nombre
         ORDER BY ec.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoFirstEstadoComercialId(PDO $conn): int
{
    $stmt = $conn->query('SELECT TOP (1) id_estado_comercial FROM dbo.ct_estado_terreno_comercial ORDER BY id_estado_comercial');
    if ($stmt === false) {
        return 0;
    }
    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoEnsureEstadoComercialDefault(PDO $conn): int
{
    $find = $conn->query(
        "SELECT TOP (1) id_estado_comercial
         FROM dbo.ct_estado_terreno_comercial
         WHERE UPPER(LTRIM(RTRIM(nombre))) = 'SIN DEFINIR'"
    );
    if ($find !== false) {
        $existingId = (int) $find->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
    }

    $insert = $conn->prepare('INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES (:nombre)');
    $insert->bindValue(':nombre', 'SIN DEFINIR', PDO::PARAM_STR);
    $insert->execute();

    $findAfter = $conn->query(
        "SELECT TOP (1) id_estado_comercial
         FROM dbo.ct_estado_terreno_comercial
         WHERE UPPER(LTRIM(RTRIM(nombre))) = 'SIN DEFINIR'"
    );
    if ($findAfter === false) {
        return 0;
    }
    return (int) $findAfter->fetchColumn();
}

function ctTerrenosRepoListTiposInmueble(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tipo_inmueble, nombre FROM dbo.ct_tipo_inmueble WHERE activo = 1 ORDER BY nombre');
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoListTiposInmuebleCatalogo(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            ti.id_tipo_inmueble,
            ti.nombre,
            ti.activo,
            COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_tipo_inmueble ti
         LEFT JOIN dbo.ct_terreno t ON t.id_tipo_inmueble = ti.id_tipo_inmueble
         GROUP BY ti.id_tipo_inmueble, ti.nombre, ti.activo
         ORDER BY ti.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoComunaExistsById(PDO $conn, int $idComuna): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_comuna WHERE id_comuna = :id');
    $stmt->bindValue(':id', $idComuna, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoEstadoPredialExistsById(PDO $conn, int $idEstadoPredial): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_predial WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoEstadoComercialExistsById(PDO $conn, int $idEstadoComercial): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_comercial WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoTipoInmuebleExistsById(PDO $conn, int $idTipoInmueble, bool $onlyActive = true): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_tipo_inmueble WHERE id_tipo_inmueble = :id';
    if ($onlyActive) {
        $sql .= ' AND activo = 1';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoComunaExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1
            FROM dbo.ct_comuna
            WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_comuna <> :exclude_id';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoRolAsignadoExists(PDO $conn, string $rolAsignado, ?int $excludeIdTerreno = null): bool
{
    $rol = strtoupper(trim($rolAsignado));
    if ($rol === '') {
        return false;
    }

    $sql = 'SELECT TOP (1) 1
            FROM dbo.ct_terreno
            WHERE UPPER(LTRIM(RTRIM(rol_asignado))) = :rol_asignado';
    if ($excludeIdTerreno !== null && $excludeIdTerreno > 0) {
        $sql .= ' AND id_terreno <> :exclude_id_terreno';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':rol_asignado', $rol, PDO::PARAM_STR);
    if ($excludeIdTerreno !== null && $excludeIdTerreno > 0) {
        $stmt->bindValue(':exclude_id_terreno', $excludeIdTerreno, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoComunaTerrenosCount(PDO $conn, int $idComuna): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_comuna = :id');
    $stmt->bindValue(':id', $idComuna, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoInsertComuna(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_comuna (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoUpdateComuna(PDO $conn, int $idComuna, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_comuna SET nombre = :nombre WHERE id_comuna = :id');
    $stmt->bindValue(':id', $idComuna, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoDeleteComuna(PDO $conn, int $idComuna): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_comuna WHERE id_comuna = :id');
    $stmt->bindValue(':id', $idComuna, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoEstadoPredialExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1
            FROM dbo.ct_estado_terreno_predial
            WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_estado_predial <> :exclude_id';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoFindEstadoPredialIdByNombre(PDO $conn, string $nombre): int
{
    $sql = 'SELECT TOP (1) id_estado_predial
            FROM dbo.ct_estado_terreno_predial
            WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoEnsureEstadoPredialDisponible(PDO $conn): int
{
    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'DISPONIBLE');
    if ($id > 0) {
        return $id;
    }

    ctTerrenosRepoInsertEstadoPredial($conn, 'DISPONIBLE');

    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'DISPONIBLE');
    if ($id <= 0) {
        throw new RuntimeException('No fue posible resolver el estado predial DISPONIBLE.');
    }

    return $id;
}

function ctTerrenosRepoEnsureEstadoPredialAdquirido(PDO $conn): int
{
    return ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
}

function ctTerrenosRepoEnsureEstadoPredialSubdividido(PDO $conn): int
{
    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'SUBDIVIDIDO');
    if ($id > 0) {
        return $id;
    }

    ctTerrenosRepoInsertEstadoPredial($conn, 'SUBDIVIDIDO');

    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'SUBDIVIDIDO');
    if ($id <= 0) {
        throw new RuntimeException('No fue posible resolver el estado predial SUBDIVIDIDO.');
    }

    return $id;
}

function ctTerrenosRepoEnsureEstadoPredialFusionado(PDO $conn): int
{
    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'FUSIONADO');
    if ($id > 0) {
        $stmtNormalize = ctTerrenosRepoPrepareStrict(
            $conn,
            'UPDATE dbo.ct_estado_terreno_predial
             SET nombre = :nombre_set
             WHERE id_estado_predial = :id
               AND nombre <> :nombre_where'
        );
        $stmtNormalize->bindValue(':id', $id, PDO::PARAM_INT);
        $stmtNormalize->bindValue(':nombre_set', 'Fusionado', PDO::PARAM_STR);
        $stmtNormalize->bindValue(':nombre_where', 'Fusionado', PDO::PARAM_STR);
        $stmtNormalize->execute();
        return $id;
    }

    ctTerrenosRepoInsertEstadoPredial($conn, 'Fusionado');

    $id = ctTerrenosRepoFindEstadoPredialIdByNombre($conn, 'FUSIONADO');
    if ($id <= 0) {
        throw new RuntimeException('No fue posible resolver el estado predial FUSIONADO.');
    }

    return $id;
}

function ctTerrenosRepoEstadoPredialTerrenosCount(PDO $conn, int $idEstadoPredial): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoInsertEstadoPredial(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoUpdateEstadoPredial(PDO $conn, int $idEstadoPredial, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_estado_terreno_predial SET nombre = :nombre WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoDeleteEstadoPredial(PDO $conn, int $idEstadoPredial): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_estado_terreno_predial WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $idEstadoPredial, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoEstadoComercialExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1
            FROM dbo.ct_estado_terreno_comercial
            WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_estado_comercial <> :exclude_id';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoEstadoComercialTerrenosCount(PDO $conn, int $idEstadoComercial): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctTerrenosRepoInsertEstadoComercial(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoUpdateEstadoComercial(PDO $conn, int $idEstadoComercial, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_estado_terreno_comercial SET nombre = :nombre WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoDeleteEstadoComercial(PDO $conn, int $idEstadoComercial): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_estado_terreno_comercial WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $idEstadoComercial, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoTipoInmuebleExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1
            FROM dbo.ct_tipo_inmueble
            WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';

    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_tipo_inmueble <> :exclude_id';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoTipoInmuebleIsActive(PDO $conn, int $idTipoInmueble): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) activo FROM dbo.ct_tipo_inmueble WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return ((int) $value) === 1;
}

function ctTerrenosRepoInsertTipoInmueble(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_tipo_inmueble (nombre, activo) VALUES (:nombre, 1)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoUpdateTipoInmueble(PDO $conn, int $idTipoInmueble, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tipo_inmueble SET nombre = :nombre WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoSetTipoInmuebleActivo(PDO $conn, int $idTipoInmueble, bool $activo): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tipo_inmueble SET activo = :activo WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $idTipoInmueble, PDO::PARAM_INT);
    $stmt->bindValue(':activo', $activo ? 1 : 0, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTerrenosRepoTerceroExistsById(PDO $conn, int $idTercero): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tercero WHERE id_tercero = :id');
    $stmt->bindValue(':id', $idTercero, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctTerrenosRepoListTercerosSelector(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            id_tercero,
            tipo_persona,
            rut,
            nombre_razon_social
         FROM dbo.ct_tercero
         ORDER BY nombre_razon_social'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoListTerrenosSelector(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            t.id_terreno,
            t.rol_asignado,
            t.rol_matriz,
            t.superficie_m2,
            t.id_estado_predial,
            ISNULL(ep.nombre, \'\') AS estado_predial_nombre,
            c.nombre AS comuna_nombre,
            ISNULL(tp.propietario_principal, \'\') AS propietario_principal,
            ISNULL(tc.propietarios_vigentes_count, 0) AS propietarios_vigentes_count
         FROM dbo.ct_terreno t
         LEFT JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
         LEFT JOIN dbo.ct_estado_terreno_predial ep ON ep.id_estado_predial = t.id_estado_predial
         OUTER APPLY (
            SELECT TOP (1)
                tr.nombre_razon_social AS propietario_principal
            FROM dbo.ct_titularidad_terreno tt
            INNER JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
            WHERE tt.id_terreno = t.id_terreno
              AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
            ORDER BY tt.porcentaje_derecho DESC, tt.vigente_desde DESC, tt.id_titularidad DESC
         ) tp
         OUTER APPLY (
            SELECT COUNT(1) AS propietarios_vigentes_count
            FROM dbo.ct_titularidad_terreno tt
            WHERE tt.id_terreno = t.id_terreno
              AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
         ) tc
         ORDER BY t.rol_asignado, t.id_terreno'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctTerrenosRepoFindSubdivisionOrigenById(PDO $conn, int $idTerreno): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            t.id_terreno,
            t.rol_asignado,
            t.superficie_m2,
            t.id_comuna,
            t.id_estado_predial,
            t.id_estado_comercial,
            t.id_tipo_inmueble
         FROM dbo.ct_terreno t
         WHERE t.id_terreno = :id_terreno'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctTerrenosRepoInsertTitularidad(
    PDO $conn,
    int $idTerreno,
    int $idTercero,
    string $vigenteDesde,
    ?string $vigenteHasta,
    float $porcentajeDerecho
): int {
    $sql = 'INSERT INTO dbo.ct_titularidad_terreno (
                id_terreno,
                id_tercero,
                vigente_desde,
                vigente_hasta,
                porcentaje_derecho
            ) VALUES (
                :id_terreno,
                :id_tercero,
                :vigente_desde,
                :vigente_hasta,
                :porcentaje_derecho
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->bindValue(':id_tercero', $idTercero, PDO::PARAM_INT);
    $stmt->bindValue(':vigente_desde', $vigenteDesde, PDO::PARAM_STR);
    if ($vigenteHasta === null) {
        $stmt->bindValue(':vigente_hasta', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':vigente_hasta', $vigenteHasta, PDO::PARAM_STR);
    }
    $stmt->bindValue(':porcentaje_derecho', round($porcentajeDerecho, 2));
    $stmt->execute();

    return (int) $conn->lastInsertId();
}

function ctTerrenosRepoInsertOperacionPredial(
    PDO $conn,
    string $tipoOperacion,
    string $fechaOperacion,
    ?string $documentoFuente
): int {
    $sql = 'INSERT INTO dbo.ct_operacion_predial (
                tipo_operacion,
                fecha_operacion,
                documento_fuente
            ) VALUES (
                :tipo_operacion,
                :fecha_operacion,
                :documento_fuente
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':tipo_operacion', $tipoOperacion, PDO::PARAM_STR);
    $stmt->bindValue(':fecha_operacion', $fechaOperacion, PDO::PARAM_STR);
    if ($documentoFuente === null) {
        $stmt->bindValue(':documento_fuente', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':documento_fuente', $documentoFuente, PDO::PARAM_STR);
    }
    $stmt->execute();

    return (int) $conn->lastInsertId();
}

function ctTerrenosRepoInsertOperacionTerreno(PDO $conn, int $idOperacion, int $idTerreno, ?string $rolEnOperacion): void
{
    $sql = 'INSERT INTO dbo.ct_operacion_terreno (
                id_operacion,
                id_terreno,
                rol_en_operacion
            ) VALUES (
                :id_operacion,
                :id_terreno,
                :rol_en_operacion
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_operacion', $idOperacion, PDO::PARAM_INT);
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    if ($rolEnOperacion === null) {
        $stmt->bindValue(':rol_en_operacion', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rol_en_operacion', $rolEnOperacion, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function ctTerrenosRepoInsertHistorialEstado(
    PDO $conn,
    int $idTerreno,
    ?int $idEstadoAnterior,
    int $idEstadoNuevo,
    string $fechaCambio,
    ?int $idVenta,
    ?int $idOperacion,
    int $idUsuario,
    string $tipoEstado
): void {
    $sql = 'INSERT INTO dbo.ct_historial_estado_terreno (
                id_terreno,
                id_estado_anterior,
                id_estado_nuevo,
                fecha_cambio,
                id_venta,
                id_operacion,
                id_usuario,
                tipo_estado
            ) VALUES (
                :id_terreno,
                :id_estado_anterior,
                :id_estado_nuevo,
                CAST(:fecha_cambio AS DATETIME2(0)),
                :id_venta,
                :id_operacion,
                :id_usuario,
                :tipo_estado
            )';

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    if ($idEstadoAnterior === null) {
        $stmt->bindValue(':id_estado_anterior', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_estado_anterior', $idEstadoAnterior, PDO::PARAM_INT);
    }
    $stmt->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_cambio', $fechaCambio, PDO::PARAM_STR);
    if ($idVenta === null) {
        $stmt->bindValue(':id_venta', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_venta', $idVenta, PDO::PARAM_INT);
    }
    if ($idOperacion === null) {
        $stmt->bindValue(':id_operacion', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_operacion', $idOperacion, PDO::PARAM_INT);
    }
    $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(':tipo_estado', $tipoEstado, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTerrenosRepoMaterializarAdquisicion(PDO $conn, array $payload, array $titulares, int $idUsuario): array
{
    if ($titulares === []) {
        throw new RuntimeException('Debes indicar al menos una titularidad para registrar la adquisicion.');
    }

    $fechaAdquisicion = (string) $payload['fecha_adquisicion'];
    $documentoFuente = isset($payload['documento_fuente']) && is_string($payload['documento_fuente'])
        ? trim($payload['documento_fuente'])
        : '';
    $documentoFuente = $documentoFuente !== '' ? $documentoFuente : null;

    ctTerrenosRepoInsert(
        $conn,
        (string) $payload['rol_asignado'],
        isset($payload['rol_matriz']) && is_string($payload['rol_matriz']) ? $payload['rol_matriz'] : null,
        isset($payload['identificacion_propiedad']) && is_string($payload['identificacion_propiedad']) ? $payload['identificacion_propiedad'] : null,
        (float) $payload['superficie_m2'],
        (int) $payload['id_comuna'],
        (int) $payload['id_estado_predial'],
        (int) $payload['id_estado_comercial'],
        (int) $payload['id_tipo_inmueble']
    );

    $idTerreno = (int) $conn->lastInsertId();
    if ($idTerreno <= 0) {
        throw new RuntimeException('No fue posible obtener el ID del terreno adquirido.');
    }

    foreach ($titulares as $titular) {
        $vigenteDesde = (string) ($titular['vigente_desde'] ?? $fechaAdquisicion);
        $vigenteHasta = isset($titular['vigente_hasta']) && is_string($titular['vigente_hasta'])
            ? trim($titular['vigente_hasta'])
            : '';

        ctTerrenosRepoInsertTitularidad(
            $conn,
            $idTerreno,
            (int) $titular['id_tercero'],
            $vigenteDesde,
            $vigenteHasta !== '' ? $vigenteHasta : null,
            (float) $titular['porcentaje_derecho']
        );
    }

    $idOperacion = ctTerrenosRepoInsertOperacionPredial(
        $conn,
        'ADQUISICION',
        $fechaAdquisicion,
        $documentoFuente
    );
    ctTerrenosRepoInsertOperacionTerreno($conn, $idOperacion, $idTerreno, 'ADQUIRIDO');

    $fechaCambio = $fechaAdquisicion . ' 00:00:00';
    ctTerrenosRepoInsertHistorialEstado(
        $conn,
        $idTerreno,
        null,
        (int) $payload['id_estado_predial'],
        $fechaCambio,
        null,
        $idOperacion,
        $idUsuario,
        'P'
    );
    ctTerrenosRepoInsertHistorialEstado(
        $conn,
        $idTerreno,
        null,
        (int) $payload['id_estado_comercial'],
        $fechaCambio,
        null,
        $idOperacion,
        $idUsuario,
        'C'
    );

    return [
        'id_terreno' => $idTerreno,
        'id_operacion' => $idOperacion,
    ];
}

function ctTerrenosRepoCrearAdquisicion(PDO $conn, array $payload, array $titulares, int $idUsuario): array
{
    $conn->beginTransaction();
    try {
        $result = ctTerrenosRepoMaterializarAdquisicion($conn, $payload, $titulares, $idUsuario);
        $conn->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

function ctTerrenosRepoRegistrarTitularidad(PDO $conn, array $payload): int
{
    $cerrarVigenteActual = !empty($payload['cerrar_vigente_actual']);

    $conn->beginTransaction();
    try {
        if ($cerrarVigenteActual) {
            $sqlCerrar = 'UPDATE dbo.ct_titularidad_terreno
                         SET vigente_hasta = DATEADD(DAY, -1, :vigente_desde)
                         WHERE id_terreno = :id_terreno
                           AND id_tercero = :id_tercero
                           AND vigente_hasta IS NULL
                           AND vigente_desde <= :vigente_desde';
            $stmtCerrar = $conn->prepare($sqlCerrar);
            $stmtCerrar->bindValue(':vigente_desde', (string) $payload['vigente_desde'], PDO::PARAM_STR);
            $stmtCerrar->bindValue(':id_terreno', (int) $payload['id_terreno'], PDO::PARAM_INT);
            $stmtCerrar->bindValue(':id_tercero', (int) $payload['id_tercero'], PDO::PARAM_INT);
            $stmtCerrar->execute();
        }

        $idTitularidad = ctTerrenosRepoInsertTitularidad(
            $conn,
            (int) $payload['id_terreno'],
            (int) $payload['id_tercero'],
            (string) $payload['vigente_desde'],
            isset($payload['vigente_hasta']) && is_string($payload['vigente_hasta'])
                ? (trim($payload['vigente_hasta']) !== '' ? trim($payload['vigente_hasta']) : null)
                : null,
            (float) $payload['porcentaje_derecho']
        );

        $conn->commit();
        return $idTitularidad;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

function ctTerrenosRepoCambiarEstadoPredialConHistorial(
    PDO $conn,
    int $idTerreno,
    int $idEstadoNuevo,
    int $idUsuario,
    ?int $idOperacion,
    string $fechaCambio
): void {
    $sqlEstado = 'SELECT id_estado_predial
                  FROM dbo.ct_terreno WITH (UPDLOCK, ROWLOCK)
                  WHERE id_terreno = :id_terreno';
    $stmtEstado = $conn->prepare($sqlEstado);
    $stmtEstado->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtEstado->execute();

    $estadoAnterior = $stmtEstado->fetchColumn();
    if ($estadoAnterior === false) {
        throw new RuntimeException('El terreno indicado no existe para cambio de estado predial.');
    }

    $idEstadoAnterior = (int) $estadoAnterior;
    if ($idEstadoAnterior === $idEstadoNuevo) {
        return;
    }

    $stmtUpdate = $conn->prepare('UPDATE dbo.ct_terreno SET id_estado_predial = :id_estado_nuevo WHERE id_terreno = :id_terreno');
    $stmtUpdate->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmtUpdate->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtUpdate->execute();

    ctTerrenosRepoInsertHistorialEstado(
        $conn,
        $idTerreno,
        $idEstadoAnterior,
        $idEstadoNuevo,
        $fechaCambio,
        null,
        $idOperacion,
        $idUsuario,
        'P'
    );
}

function ctTerrenosRepoRegistrarSubdivision(PDO $conn, array $payload, int $idUsuario): int
{
    $idTerrenoOrigen = (int) $payload['id_terreno_origen'];
    $resultados = is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
    if (count($resultados) < 2) {
        throw new RuntimeException('Debes indicar al menos dos terrenos resultado para la subdivisión.');
    }

    $fechaOperacion = (string) $payload['fecha_operacion'];
    $fechaCambio = $fechaOperacion . ' 00:00:00';
    $documentoFuente = isset($payload['documento_fuente']) && is_string($payload['documento_fuente'])
        ? trim($payload['documento_fuente'])
        : '';
    $documentoFuente = $documentoFuente !== '' ? $documentoFuente : null;

    $idEstadoOrigenDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
    $idEstadoOrigenSubdividido = ctTerrenosRepoEnsureEstadoPredialSubdividido($conn);
    $idEstadoResultadoDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);

    $conn->beginTransaction();
    try {
        $sqlOrigen = 'SELECT
                        t.id_terreno,
                        t.rol_asignado,
                        t.superficie_m2,
                        t.id_comuna,
                        t.id_estado_predial,
                        t.id_estado_comercial,
                        t.id_tipo_inmueble
                      FROM dbo.ct_terreno t WITH (UPDLOCK, ROWLOCK)
                      WHERE t.id_terreno = :id_terreno';
        $stmtOrigen = $conn->prepare($sqlOrigen);
        $stmtOrigen->bindValue(':id_terreno', $idTerrenoOrigen, PDO::PARAM_INT);
        $stmtOrigen->execute();
        $origen = $stmtOrigen->fetch(PDO::FETCH_ASSOC);
        if (!is_array($origen)) {
            throw new RuntimeException('No existe el terreno origen para la subdivisión.');
        }
        if ((int) ($origen['id_estado_predial'] ?? 0) !== $idEstadoOrigenDisponible) {
            throw new RuntimeException('El terreno origen debe estar en estado Disponible para registrar subdivisión.');
        }

        $rolMatrizResultado = trim((string) ($origen['rol_asignado'] ?? ''));
        if ($rolMatrizResultado === '') {
            throw new RuntimeException('El terreno origen no tiene rol asignado para derivar rol matriz.');
        }

        $superficieOrigen = round((float) ($origen['superficie_m2'] ?? 0), 2);
        if ($superficieOrigen <= 0) {
            throw new RuntimeException('La superficie del terreno origen es inválida.');
        }

        $stmtTitularesVigentes = $conn->prepare(
            'SELECT
                tt.id_tercero,
                tt.porcentaje_derecho,
                tt.vigente_hasta
             FROM dbo.ct_titularidad_terreno tt
             WHERE tt.id_terreno = :id_terreno
               AND tt.vigente_desde <= :fecha_operacion_desde
               AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= :fecha_operacion_hasta)
             ORDER BY tt.porcentaje_derecho DESC, tt.vigente_desde DESC, tt.id_titularidad DESC'
        );
        $stmtTitularesVigentes->bindValue(':id_terreno', $idTerrenoOrigen, PDO::PARAM_INT);
        $stmtTitularesVigentes->bindValue(':fecha_operacion_desde', $fechaOperacion, PDO::PARAM_STR);
        $stmtTitularesVigentes->bindValue(':fecha_operacion_hasta', $fechaOperacion, PDO::PARAM_STR);
        $stmtTitularesVigentes->execute();
        $titularesVigentesOrigen = $stmtTitularesVigentes->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($titularesVigentesOrigen) || $titularesVigentesOrigen === []) {
            throw new RuntimeException(
                'El terreno origen no tiene titulares vigentes en la fecha de operación. No se puede heredar titularidad.'
            );
        }

        $sumaResultados = 0.0;
        foreach ($resultados as $resultado) {
            $sumaResultados += round((float) ($resultado['superficie_m2'] ?? 0), 2);
        }
        if ((int) round($sumaResultados * 100) !== (int) round($superficieOrigen * 100)) {
            throw new RuntimeException(
                'La suma de superficies de terrenos resultado (' . number_format($sumaResultados, 2, '.', '')
                . ') debe ser igual a la superficie del origen (' . number_format($superficieOrigen, 2, '.', '') . ').'
            );
        }

        $idOperacion = ctTerrenosRepoInsertOperacionPredial(
            $conn,
            'SUBDIVISION',
            $fechaOperacion,
            $documentoFuente
        );

        ctTerrenosRepoInsertOperacionTerreno($conn, $idOperacion, $idTerrenoOrigen, 'ORIGEN');
        foreach ($resultados as $resultado) {
            $rolAsignadoResultado = trim((string) ($resultado['rol_asignado'] ?? ''));
            $superficieResultado = round((float) ($resultado['superficie_m2'] ?? 0), 2);
            if ($rolAsignadoResultado === '' || $superficieResultado <= 0) {
                throw new RuntimeException('Cada resultado de subdivisión debe incluir rol y superficie válidos.');
            }

            ctTerrenosRepoInsert(
                $conn,
                $rolAsignadoResultado,
                $rolMatrizResultado,
                null,
                $superficieResultado,
                (int) ($origen['id_comuna'] ?? 0),
                $idEstadoResultadoDisponible,
                (int) ($origen['id_estado_comercial'] ?? 0),
                (int) ($origen['id_tipo_inmueble'] ?? 0)
            );

            $idResultado = (int) $conn->lastInsertId();
            if ($idResultado <= 0) {
                throw new RuntimeException('No fue posible crear un terreno resultado de la subdivisión.');
            }

            foreach ($titularesVigentesOrigen as $titularOrigen) {
                $vigenteHastaOrigen = trim((string) ($titularOrigen['vigente_hasta'] ?? ''));
                ctTerrenosRepoInsertTitularidad(
                    $conn,
                    $idResultado,
                    (int) ($titularOrigen['id_tercero'] ?? 0),
                    $fechaOperacion,
                    $vigenteHastaOrigen !== '' ? $vigenteHastaOrigen : null,
                    (float) ($titularOrigen['porcentaje_derecho'] ?? 0)
                );
            }

            ctTerrenosRepoInsertOperacionTerreno($conn, $idOperacion, $idResultado, 'RESULTADO');
            ctTerrenosRepoInsertHistorialEstado(
                $conn,
                $idResultado,
                null,
                $idEstadoResultadoDisponible,
                $fechaCambio,
                null,
                $idOperacion,
                $idUsuario,
                'P'
            );
            ctTerrenosRepoInsertHistorialEstado(
                $conn,
                $idResultado,
                null,
                (int) ($origen['id_estado_comercial'] ?? 0),
                $fechaCambio,
                null,
                $idOperacion,
                $idUsuario,
                'C'
            );
        }

        ctTerrenosRepoCambiarEstadoPredialConHistorial(
            $conn,
            $idTerrenoOrigen,
            $idEstadoOrigenSubdividido,
            $idUsuario,
            $idOperacion,
            $fechaCambio
        );

        $conn->commit();
        return $idOperacion;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

function ctTerrenosRepoRegistrarFusion(PDO $conn, array $payload, int $idUsuario): int
{
    $idsOrigen = array_values(array_unique(array_map('intval', (array) ($payload['ids_terrenos_origen'] ?? []))));
    if (count($idsOrigen) < 2) {
        throw new RuntimeException('Debes indicar al menos dos terrenos origen para la fusión.');
    }

    $resultadoModo = strtolower(trim((string) ($payload['resultado_modo'] ?? 'existente')));
    if ($resultadoModo !== 'existente' && $resultadoModo !== 'nuevo') {
        throw new RuntimeException('Modo de resultado de fusión inválido.');
    }

    $fechaOperacion = (string) $payload['fecha_operacion'];
    $fechaCambio = $fechaOperacion . ' 00:00:00';
    $documentoFuente = isset($payload['documento_fuente']) && is_string($payload['documento_fuente'])
        ? trim($payload['documento_fuente'])
        : '';
    $documentoFuente = $documentoFuente !== '' ? $documentoFuente : null;
    $idEstadoOrigenDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
    $idEstadoOrigenFusionado = ctTerrenosRepoEnsureEstadoPredialFusionado($conn);
    $idEstadoResultadoExistenteSubdividido = ctTerrenosRepoEnsureEstadoPredialSubdividido($conn);
    $idEstadoResultadoDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);

    $conn->beginTransaction();
    try {
        $nuevoResultadoCreado = false;
        $nuevoResultadoEstadoComercial = 0;

        $origenPlaceholders = implode(', ', array_fill(0, count($idsOrigen), '?'));
        $stmtOrigen = $conn->prepare(
            'SELECT
                t.id_terreno,
                t.rol_asignado,
                t.rol_matriz,
                t.superficie_m2,
                t.id_comuna,
                t.id_estado_predial,
                t.id_estado_comercial,
                t.id_tipo_inmueble
             FROM dbo.ct_terreno t WITH (UPDLOCK, ROWLOCK)
             WHERE t.id_terreno IN (' . $origenPlaceholders . ')'
        );
        foreach (array_values($idsOrigen) as $idx => $idOrigen) {
            $stmtOrigen->bindValue($idx + 1, $idOrigen, PDO::PARAM_INT);
        }
        $stmtOrigen->execute();
        $origenRowsRaw = $stmtOrigen->fetchAll(PDO::FETCH_ASSOC);
        $origenRows = [];
        foreach ($origenRowsRaw as $row) {
            $idOrigen = (int) ($row['id_terreno'] ?? 0);
            if ($idOrigen > 0) {
                $origenRows[$idOrigen] = $row;
            }
        }
        foreach ($idsOrigen as $idOrigen) {
            if (!isset($origenRows[$idOrigen])) {
                throw new RuntimeException('No existe el terreno origen #' . $idOrigen . ' para la fusión.');
            }
        }
        foreach ($idsOrigen as $idOrigen) {
            $estadoPredialOrigen = (int) ($origenRows[$idOrigen]['id_estado_predial'] ?? 0);
            if ($estadoPredialOrigen !== $idEstadoOrigenDisponible) {
                throw new RuntimeException(
                    'El terreno origen #' . $idOrigen . ' debe estar en estado Disponible para registrar fusión.'
                );
            }
        }

        $rowsOrdenadas = [];
        foreach ($idsOrigen as $idOrigen) {
            $rowsOrdenadas[] = $origenRows[$idOrigen];
        }
        if (count($rowsOrdenadas) < 2) {
            throw new RuntimeException('No fue posible resolver los terrenos origen para la fusión.');
        }

        $superficieTotal = 0.0;
        $rolTroncoComun = null;
        foreach ($rowsOrdenadas as $row) {
            $superficieTotal += round((float) ($row['superficie_m2'] ?? 0), 2);

            $rolMatrizRow = strtoupper(trim((string) ($row['rol_matriz'] ?? '')));
            $rolAsignadoRow = strtoupper(trim((string) ($row['rol_asignado'] ?? '')));
            $rolReferencia = $rolMatrizRow !== '' ? $rolMatrizRow : $rolAsignadoRow;
            if ($rolTroncoComun === null) {
                $rolTroncoComun = $rolReferencia;
            } elseif ($rolTroncoComun !== $rolReferencia) {
                $rolTroncoComun = '';
            }
        }

        $idTerrenoResultado = 0;
        if ($resultadoModo === 'existente') {
            $idTerrenoResultado = (int) ($payload['id_terreno_resultado'] ?? 0);
            if ($idTerrenoResultado <= 0) {
                throw new RuntimeException('Debes seleccionar un terreno resultado para la fusión.');
            }
            if (in_array($idTerrenoResultado, $idsOrigen, true)) {
                throw new RuntimeException('El terreno resultado no puede ser parte de los orígenes de la fusión.');
            }
            $stmtResultado = $conn->prepare(
                'SELECT TOP (1)
                    id_terreno,
                    rol_asignado,
                    rol_matriz,
                    id_estado_predial,
                    superficie_m2
                 FROM dbo.ct_terreno WITH (UPDLOCK, ROWLOCK)
                 WHERE id_terreno = :id_terreno'
            );
            $stmtResultado->bindValue(':id_terreno', $idTerrenoResultado, PDO::PARAM_INT);
            $stmtResultado->execute();
            $resultadoRow = $stmtResultado->fetch(PDO::FETCH_ASSOC);
            if (!is_array($resultadoRow)) {
                throw new RuntimeException('No existe el terreno resultado indicado para la fusión.');
            }
            if ((int) ($resultadoRow['id_estado_predial'] ?? 0) !== $idEstadoResultadoExistenteSubdividido) {
                throw new RuntimeException('El terreno resultado existente debe estar en estado Subdividido.');
            }

            $rolResultadoMatriz = strtoupper(trim((string) ($resultadoRow['rol_matriz'] ?? '')));
            $rolResultadoAsignado = strtoupper(trim((string) ($resultadoRow['rol_asignado'] ?? '')));
            $rolResultadoReferencia = $rolResultadoMatriz !== '' ? $rolResultadoMatriz : $rolResultadoAsignado;
            if ($rolTroncoComun === '' || $rolResultadoReferencia === '' || $rolTroncoComun !== $rolResultadoReferencia) {
                throw new RuntimeException(
                    'Los orígenes y el resultado existente no comparten el mismo tronco predial (rol matriz/referencia).'
                );
            }

            $superficieResultado = round((float) ($resultadoRow['superficie_m2'] ?? 0), 2);
            if ((int) round($superficieTotal * 100) !== (int) round($superficieResultado * 100)) {
                throw new RuntimeException(
                    'La suma de superficie de origen (' . number_format($superficieTotal, 2, '.', '')
                    . ') debe coincidir con la superficie del resultado existente ('
                    . number_format($superficieResultado, 2, '.', '') . ').'
                );
            }
        } else {
            $rolResultadoNuevo = strtoupper(trim((string) ($payload['resultado_nuevo_rol_asignado'] ?? '')));
            if ($rolResultadoNuevo === '') {
                throw new RuntimeException('Debes indicar el rol asignado del nuevo terreno resultado.');
            }

            $baseRow = $rowsOrdenadas[0] ?? null;
            if (!is_array($baseRow)) {
                throw new RuntimeException('No fue posible resolver información base de origen para fusión.');
            }

            $idComuna = (int) ($baseRow['id_comuna'] ?? 0);
            $idEstadoComercial = (int) ($baseRow['id_estado_comercial'] ?? 0);
            $idTipoInmueble = (int) ($baseRow['id_tipo_inmueble'] ?? 0);
            if ($idComuna <= 0 || $idEstadoComercial <= 0 || $idTipoInmueble <= 0) {
                throw new RuntimeException('Los terrenos origen no tienen catálogos base válidos para crear el resultado.');
            }

            foreach ($rowsOrdenadas as $row) {
                if (
                    (int) ($row['id_comuna'] ?? 0) !== $idComuna
                    || (int) ($row['id_estado_comercial'] ?? 0) !== $idEstadoComercial
                    || (int) ($row['id_tipo_inmueble'] ?? 0) !== $idTipoInmueble
                ) {
                    throw new RuntimeException(
                        'Para crear un resultado nuevo, los orígenes deben compartir comuna, estado comercial y tipo.'
                    );
                }
            }

            ctTerrenosRepoInsert(
                $conn,
                $rolResultadoNuevo,
                $rolTroncoComun !== '' ? $rolTroncoComun : null,
                null,
                round($superficieTotal, 2),
                $idComuna,
                $idEstadoResultadoDisponible,
                $idEstadoComercial,
                $idTipoInmueble
            );

            $idTerrenoResultado = (int) $conn->lastInsertId();
            if ($idTerrenoResultado <= 0) {
                throw new RuntimeException('No fue posible crear el nuevo terreno resultado de la fusión.');
            }
            $nuevoResultadoCreado = true;
            $nuevoResultadoEstadoComercial = $idEstadoComercial;
        }

        $idOperacion = ctTerrenosRepoInsertOperacionPredial(
            $conn,
            'FUSION',
            $fechaOperacion,
            $documentoFuente
        );

        foreach ($idsOrigen as $idOrigen) {
            ctTerrenosRepoInsertOperacionTerreno($conn, $idOperacion, $idOrigen, 'ORIGEN');
        }
        ctTerrenosRepoInsertOperacionTerreno($conn, $idOperacion, $idTerrenoResultado, 'RESULTADO');

        if ($nuevoResultadoCreado) {
            ctTerrenosRepoInsertHistorialEstado(
                $conn,
                $idTerrenoResultado,
                null,
                $idEstadoResultadoDisponible,
                $fechaCambio,
                null,
                $idOperacion,
                $idUsuario,
                'P'
            );
            ctTerrenosRepoInsertHistorialEstado(
                $conn,
                $idTerrenoResultado,
                null,
                $nuevoResultadoEstadoComercial,
                $fechaCambio,
                null,
                $idOperacion,
                $idUsuario,
                'C'
            );
        }

        foreach ($idsOrigen as $idOrigen) {
            ctTerrenosRepoCambiarEstadoPredialConHistorial(
                $conn,
                $idOrigen,
                $idEstadoOrigenFusionado,
                $idUsuario,
                $idOperacion,
                $fechaCambio
            );
        }

        ctTerrenosRepoCambiarEstadoPredialConHistorial(
            $conn,
            $idTerrenoResultado,
            $idEstadoResultadoDisponible,
            $idUsuario,
            $idOperacion,
            $fechaCambio
        );

        $conn->commit();
        return $idOperacion;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}
