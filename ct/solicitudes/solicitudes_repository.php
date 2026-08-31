<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/predial/terrenos/terrenos_repository.php';

function ctSolicitudesRepoQuoteIdentifier(string $name): string
{
    return '[' . str_replace(']', ']]', $name) . ']';
}

function ctSolicitudesRepoTableExists(PDO $conn, string $table): bool
{
    $schemaTable = 'dbo.' . trim($table);
    $stmt = $conn->prepare("SELECT OBJECT_ID(:table_name, 'U')");
    $stmt->bindValue(':table_name', $schemaTable, PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn() > 0;
}

function ctSolicitudesRepoFetchFirstIntFromAnyRowset(PDOStatement $stmt): int
{
    do {
        if ($stmt->columnCount() > 0) {
            $value = $stmt->fetchColumn();
            return $value === false ? 0 : (int) $value;
        }
    } while ($stmt->nextRowset());
    return 0;
}

function ctSolicitudesRepoFindUsuariosCorporativosMeta(PDO $conn): ?array
{
    $tableExistsStmt = $conn->query("SELECT OBJECT_ID('dbo.cr_usuarios', 'U')");
    if ($tableExistsStmt === false) {
        return null;
    }
    $tableId = (int) $tableExistsStmt->fetchColumn();
    if ($tableId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT name
         FROM sys.columns
         WHERE object_id = OBJECT_ID(\'dbo.cr_usuarios\')'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || $rows === []) {
        return null;
    }

    $byLower = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $byLower[strtolower($name)] = $name;
        }
    }

    $idColumn = null;
    foreach (['id_usuario', 'id'] as $candidate) {
        if (isset($byLower[$candidate])) {
            $idColumn = $byLower[$candidate];
            break;
        }
    }
    if ($idColumn === null) {
        return null;
    }

    $displayExpr = 'NULL';
    if (isset($byLower['nombre_completo'])) {
        $displayExpr = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['nombre_completo']) . '))), \'\')';
    } elseif (isset($byLower['nombre'])) {
        $displayExpr = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['nombre']) . '))), \'\')';
    } elseif (isset($byLower['username'])) {
        $displayExpr = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['username']) . '))), \'\')';
    } elseif (isset($byLower['usuario'])) {
        $displayExpr = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['usuario']) . '))), \'\')';
    } elseif (isset($byLower['nombres']) || isset($byLower['apellidos'])) {
        $nombresExpr = isset($byLower['nombres'])
            ? 'ISNULL(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['nombres']) . '), \'\')'
            : '\'\'';
        $apellidosExpr = isset($byLower['apellidos'])
            ? 'ISNULL(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier($byLower['apellidos']) . '), \'\')'
            : '\'\'';
        $displayExpr = 'NULLIF(LTRIM(RTRIM('
            . $nombresExpr
            . ' + CASE WHEN ' . $apellidosExpr . ' <> \'\' THEN \' \' + ' . $apellidosExpr . ' ELSE \'\' END'
            . ')), \'\')';
    }

    $emailColumn = null;
    foreach (['correo_electronico', 'email', 'correo', 'mail'] as $candidate) {
        if (isset($byLower[$candidate])) {
            $emailColumn = $byLower[$candidate];
            break;
        }
    }

    return [
        'id_column' => $idColumn,
        'display_expr' => $displayExpr,
        'email_column' => $emailColumn,
    ];
}

function ctSolicitudesRepoListUsuariosCorporativos(PDO $conn): array
{
    $meta = ctSolicitudesRepoFindUsuariosCorporativosMeta($conn);
    if (!is_array($meta)) {
        return [];
    }

    $selectEmail = 'CAST(NULL AS NVARCHAR(255)) AS email';
    if (is_string($meta['email_column'] ?? null) && trim((string) $meta['email_column']) !== '') {
        $selectEmail = 'NULLIF(LTRIM(RTRIM(CONVERT(NVARCHAR(255), ' . ctSolicitudesRepoQuoteIdentifier((string) $meta['email_column']) . '))), \'\') AS email';
    }

    $sql = 'SELECT
                CAST(' . ctSolicitudesRepoQuoteIdentifier((string) $meta['id_column']) . ' AS INT) AS id_usuario,
                ' . (string) $meta['display_expr'] . ' AS nombre,
                ' . $selectEmail . '
            FROM dbo.cr_usuarios
            WHERE ' . ctSolicitudesRepoQuoteIdentifier((string) $meta['id_column']) . ' IS NOT NULL
            ORDER BY nombre, id_usuario';
    $stmt = $conn->query($sql);
    if ($stmt === false) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function ctSolicitudesRepoSyncParticipantesCatalog(PDO $conn): void
{
    // Modelo nuevo: ct_participante_solicitud ya no es catalogo global,
    // es una relacion por solicitud. El catalogo sale directo de cr_usuarios.
}

function ctSolicitudesRepoListTipos(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tipo_solicitud, codigo, nombre FROM dbo.ct_tipo_solicitud WHERE activo = 1 ORDER BY nombre');
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoListEstados(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_estado_solicitud, codigo, nombre, orden_visual FROM dbo.ct_estado_solicitud WHERE activo = 1 ORDER BY orden_visual, nombre');
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoListAreas(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            d.id_departamento AS id_area_solicitud,
            d.id_departamento,
            d.codigo,
            d.nombre,
            d.orden_visual
         FROM dbo.cr_departamentos d
         WHERE d.activo = 1
         ORDER BY d.orden_visual, d.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoListAreasForCreate(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT DISTINCT
            d.id_departamento AS id_area_solicitud,
            d.id_departamento,
            d.codigo,
            d.nombre,
            d.orden_visual
         FROM dbo.ct_solicitud_tipo_area sta
         INNER JOIN dbo.ct_tipo_solicitud ts ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
         INNER JOIN dbo.cr_departamentos d ON d.id_departamento = sta.id_area_solicitud
         WHERE sta.activo = 1
           AND ts.activo = 1
           AND d.activo = 1
         ORDER BY d.orden_visual, d.nombre'
    );
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function ctSolicitudesRepoListTipoAreaConfig(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT
            sta.id_tipo_solicitud,
            sta.id_area_solicitud,
            sta.orden_flujo,
            sta.es_requerida,
            sta.habilita_automaticamente,
            sta.requiere_formulario_tipado,
            ts.codigo AS tipo_codigo,
            ts.nombre AS tipo_nombre,
            d.codigo AS area_codigo,
            d.nombre AS area_nombre
         FROM dbo.ct_solicitud_tipo_area sta
         INNER JOIN dbo.ct_tipo_solicitud ts ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
         INNER JOIN dbo.cr_departamentos d ON d.id_departamento = sta.id_area_solicitud
         WHERE sta.activo = 1
           AND ts.activo = 1
           AND d.activo = 1
         ORDER BY sta.id_tipo_solicitud, sta.orden_flujo, d.orden_visual, d.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoListTipoAreaParticipanteDefaults(PDO $conn): array
{
    if (!ctSolicitudesRepoTableExists($conn, 'ct_solicitud_tipo_area_participante_default')) {
        return [];
    }

    $sql = 'SELECT
                sta.id_tipo_solicitud,
                sta.id_area_solicitud,
                pd.id_solicitud_tipo_area,
                pd.id_rol_solicitud,
                pd.id_usuario_default,
                pd.es_responsable,
                pd.orden_asignacion,
                COALESCE(pd.id_usuario_default, ur.id_usuario) AS id_usuario_resuelto
            FROM dbo.ct_solicitud_tipo_area_participante_default pd
            INNER JOIN dbo.ct_solicitud_tipo_area sta
                ON sta.id_solicitud_tipo_area = pd.id_solicitud_tipo_area
            LEFT JOIN dbo.ct_usuario_rol_solicitud ur
                ON ur.id_rol_solicitud = pd.id_rol_solicitud
               AND ur.activo = 1
               AND (ur.fecha_hasta IS NULL OR ur.fecha_hasta >= SYSUTCDATETIME())
            INNER JOIN dbo.ct_tipo_solicitud ts
                ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
               AND ts.activo = 1
            INNER JOIN dbo.cr_departamentos d
                ON d.id_departamento = sta.id_area_solicitud
               AND d.activo = 1
            WHERE pd.activo = 1
              AND sta.activo = 1
            ORDER BY sta.id_tipo_solicitud, sta.id_area_solicitud, pd.orden_asignacion, pd.id_solicitud_tipo_area_participante_default';

    $stmt = $conn->query($sql);
    if ($stmt === false) {
        return [];
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }
    return $rows;
}

function ctSolicitudesRepoListParticipantesCatalog(PDO $conn): array
{
    $usuarios = ctSolicitudesRepoListUsuariosCorporativos($conn);
    $rows = [];
    foreach ($usuarios as $usuario) {
        $idUsuario = (int) ($usuario['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        $rows[] = [
            'id_participante_solicitud' => $idUsuario,
            'nombre' => trim((string) ($usuario['nombre'] ?? '')) !== '' ? (string) $usuario['nombre'] : ('Usuario #' . $idUsuario),
            'email' => $usuario['email'] ?? null,
            'id_usuario_corporativo' => $idUsuario,
        ];
    }
    return $rows;
}

function ctSolicitudesRepoListParticipantesCatalogByAreaForCreate(PDO $conn, array $areasForCreate): array
{
    $participantesGlobal = ctSolicitudesRepoListParticipantesCatalog($conn);
    if ($participantesGlobal === []) {
        return [];
    }

    $result = [];
    foreach ($areasForCreate as $area) {
        $idArea = (int) ($area['id_area_solicitud'] ?? 0);
        if ($idArea > 0) {
            $result[$idArea] = [];
        }
    }

    if (!ctSolicitudesRepoTableExists($conn, 'cr_usuario_departamento')) {
        foreach ($result as $idArea => $unused) {
            $result[$idArea] = $participantesGlobal;
        }
        return $result;
    }

    $departamentoToAreas = [];
    foreach ($areasForCreate as $area) {
        $idArea = (int) ($area['id_area_solicitud'] ?? 0);
        $idDepartamento = (int) ($area['id_departamento'] ?? ($area['id_area_solicitud'] ?? 0));
        if ($idArea <= 0 || $idDepartamento <= 0) {
            continue;
        }
        if (!isset($departamentoToAreas[$idDepartamento])) {
            $departamentoToAreas[$idDepartamento] = [];
        }
        $departamentoToAreas[$idDepartamento][] = $idArea;
    }

    if ($departamentoToAreas === []) {
        foreach ($result as $idArea => $unused) {
            $result[$idArea] = $participantesGlobal;
        }
        return $result;
    }

    $stmt = $conn->query(
        'SELECT usuario_id, departamento_id
         FROM dbo.cr_usuario_departamento'
    );
    if ($stmt === false) {
        foreach ($result as $idArea => $unused) {
            $result[$idArea] = $participantesGlobal;
        }
        return $result;
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || $rows === []) {
        return $result;
    }

    $globalById = [];
    foreach ($participantesGlobal as $participante) {
        $idParticipante = (int) ($participante['id_participante_solicitud'] ?? 0);
        if ($idParticipante > 0) {
            $globalById[$idParticipante] = $participante;
        }
    }

    foreach ($rows as $row) {
        $idUsuario = (int) ($row['usuario_id'] ?? 0);
        $idDepartamento = (int) ($row['departamento_id'] ?? 0);
        if ($idUsuario <= 0 || $idDepartamento <= 0) {
            continue;
        }
        if (!isset($globalById[$idUsuario]) || !isset($departamentoToAreas[$idDepartamento])) {
            continue;
        }
        foreach ($departamentoToAreas[$idDepartamento] as $idArea) {
            if (!isset($result[$idArea])) {
                $result[$idArea] = [];
            }
            $result[$idArea][$idUsuario] = $globalById[$idUsuario];
        }
    }

    $defaultsRows = ctSolicitudesRepoListTipoAreaParticipanteDefaults($conn);
    foreach ($defaultsRows as $defaultRow) {
        $idArea = (int) ($defaultRow['id_area_solicitud'] ?? 0);
        $idUsuario = (int) ($defaultRow['id_usuario_resuelto'] ?? 0);
        if ($idArea <= 0 || $idUsuario <= 0) {
            continue;
        }
        if (!isset($result[$idArea]) || !isset($globalById[$idUsuario])) {
            continue;
        }
        $result[$idArea][$idUsuario] = $globalById[$idUsuario];
    }

    foreach ($result as $idArea => $participantesArea) {
        if (!is_array($participantesArea) || $participantesArea === []) {
            $result[$idArea] = $participantesGlobal;
            $participantesArea = $result[$idArea];
        }
        usort(
            $participantesArea,
            static function (array $a, array $b): int {
                return strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
            }
        );
        $result[$idArea] = array_values($participantesArea);
    }

    return $result;
}

function ctSolicitudesRepoListComunas(PDO $conn): array
{
    return ctTerrenosRepoListComunas($conn);
}

function ctSolicitudesRepoListTiposInmueble(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tipo_inmueble, nombre FROM dbo.ct_tipo_inmueble ORDER BY nombre');
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoListTerceros(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tercero, rut, nombre_razon_social FROM dbo.ct_tercero ORDER BY nombre_razon_social');
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctSolicitudesRepoExistsRazonSocialJuridica(PDO $conn, string $razonSocial, ?int $excludeId = null): bool
{
    $sql = "SELECT TOP (1) 1
            FROM dbo.ct_tercero
            WHERE tipo_persona = 'J'
              AND UPPER(LTRIM(RTRIM(nombre_razon_social))) = UPPER(LTRIM(RTRIM(:nombre)))";
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_tercero <> :exclude_id';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $razonSocial, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return (bool) $stmt->fetchColumn();
}

function ctSolicitudesRepoInsertTercero(PDO $conn, string $tipoPersona, ?string $rut, string $nombreRazonSocial): int
{
    $stmt = $conn->prepare(
        'INSERT INTO dbo.ct_tercero (tipo_persona, rut, nombre_razon_social)
         VALUES (:tipo_persona, :rut, :nombre_razon_social);
         SELECT CAST(SCOPE_IDENTITY() AS INT) AS id_tercero;'
    );
    $stmt->bindValue(':tipo_persona', $tipoPersona, PDO::PARAM_STR);
    if ($rut === null || trim($rut) === '') {
        $stmt->bindValue(':rut', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rut', trim($rut), PDO::PARAM_STR);
    }
    $stmt->bindValue(':nombre_razon_social', $nombreRazonSocial, PDO::PARAM_STR);
    $stmt->execute();
    $id = ctSolicitudesRepoFetchFirstIntFromAnyRowset($stmt);
    if ($id <= 0) {
        throw new RuntimeException('No fue posible crear el tercero.');
    }
    return $id;
}

function ctSolicitudesRepoFindEstadoIdByCode(PDO $conn, string $code): int
{
    $stmt = $conn->prepare('SELECT TOP (1) id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = :codigo');
    $stmt->bindValue(':codigo', strtoupper(trim($code)), PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctSolicitudesRepoFindTipoIdByCode(PDO $conn, string $code): int
{
    $stmt = $conn->prepare('SELECT TOP (1) id_tipo_solicitud FROM dbo.ct_tipo_solicitud WHERE codigo = :codigo');
    $stmt->bindValue(':codigo', strtoupper(trim($code)), PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctSolicitudesRepoFindTipoById(PDO $conn, int $idTipoSolicitud): ?array
{
    if ($idTipoSolicitud <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT TOP (1) id_tipo_solicitud, codigo, nombre, activo
         FROM dbo.ct_tipo_solicitud
         WHERE id_tipo_solicitud = :id_tipo_solicitud'
    );
    $stmt->bindValue(':id_tipo_solicitud', $idTipoSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoListSolicitantesFilter(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT DISTINCT id_gerente_usuario AS id_solicitante
         FROM dbo.ct_solicitud
         ORDER BY id_gerente_usuario'
    );
    if ($stmt === false) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id_solicitante'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $displayMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, $ids);

    $result = [];
    foreach ($ids as $id) {
        $result[] = [
            'id_usuario' => $id,
            'nombre' => $displayMap[$id] ?? ('Usuario #' . $id),
        ];
    }
    return $result;
}

function ctSolicitudesRepoBuildWhere(array $filters): array
{
    $conditions = ['1=1'];
    $params = [];

    $texto = trim((string) ($filters['filtro_texto'] ?? ''));
    $idEstado = (int) ($filters['id_estado_solicitud'] ?? 0);
    $idTipo = (int) ($filters['id_tipo_solicitud'] ?? 0);
    $idSolicitante = (int) ($filters['id_solicitante'] ?? 0);

    if ($idEstado > 0) {
        $conditions[] = 's.id_estado_solicitud = :id_estado_solicitud';
        $params[':id_estado_solicitud'] = $idEstado;
    }
    if ($idTipo > 0) {
        $conditions[] = 's.id_tipo_solicitud = :id_tipo_solicitud';
        $params[':id_tipo_solicitud'] = $idTipo;
    }
    if ($idSolicitante > 0) {
        $conditions[] = 's.id_gerente_usuario = :id_solicitante';
        $params[':id_solicitante'] = $idSolicitante;
    }
    if ($texto !== '') {
        $conditions[] = '(
            CAST(s.id_solicitud AS NVARCHAR(20)) LIKE :texto_id
            OR ISNULL(s.resumen, \'\') LIKE :texto_resumen
            OR ISNULL(ts.nombre, \'\') LIKE :texto_tipo
            OR ISNULL(es.nombre, \'\') LIKE :texto_estado
            OR EXISTS (
                SELECT 1
                FROM dbo.ct_solicitud_adquisicion sad
                WHERE sad.id_solicitud = s.id_solicitud
                  AND (
                    ISNULL(sad.rol_propuesto, \'\') LIKE :texto_draft_rol
                    OR ISNULL(sad.identificacion_propiedad, \'\') LIKE :texto_draft_ident
                  )
            )
        )';
        $params[':texto_id'] = '%' . $texto . '%';
        $params[':texto_resumen'] = '%' . $texto . '%';
        $params[':texto_tipo'] = '%' . $texto . '%';
        $params[':texto_estado'] = '%' . $texto . '%';
        $params[':texto_draft_rol'] = '%' . $texto . '%';
        $params[':texto_draft_ident'] = '%' . $texto . '%';
    }

    return [
        'where' => implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function ctSolicitudesRepoCount(PDO $conn, array $filters): int
{
    $where = ctSolicitudesRepoBuildWhere($filters);
    $sql = 'SELECT COUNT(*)
            FROM dbo.ct_solicitud s
            INNER JOIN dbo.ct_tipo_solicitud ts ON ts.id_tipo_solicitud = s.id_tipo_solicitud
            INNER JOIN dbo.ct_estado_solicitud es ON es.id_estado_solicitud = s.id_estado_solicitud
            WHERE ' . $where['where'];
    $stmt = $conn->prepare($sql);
    foreach ($where['params'] as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctSolicitudesRepoList(PDO $conn, array $filters, int $offset, int $limit): array
{
    $where = ctSolicitudesRepoBuildWhere($filters);
    $sql = 'SELECT
                s.id_solicitud,
                s.id_gerente_usuario AS id_solicitante,
                s.resumen,
                s.id_terreno_generado,
                s.id_operacion_generada,
                s.fecha_creacion,
                s.fecha_actualizacion,
                ts.codigo AS tipo_codigo,
                ts.nombre AS tipo_nombre,
                es.codigo AS estado_codigo,
                es.nombre AS estado_nombre,
                draft.rol_propuesto AS rol_asignado,
                draft.identificacion_propiedad,
                draft.fecha_adquisicion,
                areas.areas_count,
                areas.areas_completas_count,
                titulares.titulares_count
            FROM dbo.ct_solicitud s
            INNER JOIN dbo.ct_tipo_solicitud ts ON ts.id_tipo_solicitud = s.id_tipo_solicitud
            INNER JOIN dbo.ct_estado_solicitud es ON es.id_estado_solicitud = s.id_estado_solicitud
            LEFT JOIN dbo.ct_solicitud_adquisicion draft ON draft.id_solicitud = s.id_solicitud
            OUTER APPLY (
                SELECT
                    COUNT(*) AS areas_count,
                    COUNT(CASE WHEN eas.codigo = \'COMPLETA\' THEN 1 END) AS areas_completas_count
                FROM dbo.ct_solicitud_area_instancia sai
                INNER JOIN dbo.ct_estado_area_solicitud eas
                    ON eas.id_estado_area_solicitud = sai.id_estado_area_solicitud
                WHERE sai.id_solicitud = s.id_solicitud
            ) areas
            OUTER APPLY (
                SELECT COUNT(*) AS titulares_count
                FROM dbo.ct_solicitud_adquisicion_titular st
                WHERE st.id_solicitud = s.id_solicitud
            ) titulares
            WHERE ' . $where['where'] . '
            ORDER BY s.fecha_actualizacion DESC, s.id_solicitud DESC
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';
    $stmt = $conn->prepare($sql);
    foreach ($where['params'] as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoCreate(PDO $conn, int $idTipoSolicitud, int $idEstadoSolicitud, int $idSolicitante, ?string $resumen): int
{
    $sql = 'INSERT INTO dbo.ct_solicitud (
                id_tipo_solicitud,
                id_estado_solicitud,
                id_gerente_usuario,
                resumen,
                fecha_creacion,
                fecha_actualizacion
            ) VALUES (
                :id_tipo_solicitud,
                :id_estado_solicitud,
                :id_solicitante,
                :resumen,
                SYSUTCDATETIME(),
                SYSUTCDATETIME()
            );
            SELECT CAST(SCOPE_IDENTITY() AS INT) AS id_solicitud;';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_tipo_solicitud', $idTipoSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_solicitud', $idEstadoSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitante', $idSolicitante, PDO::PARAM_INT);
    if ($resumen === null || trim($resumen) === '') {
        $stmt->bindValue(':resumen', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':resumen', trim($resumen), PDO::PARAM_STR);
    }
    $stmt->execute();
    $idSolicitud = ctSolicitudesRepoFetchFirstIntFromAnyRowset($stmt);
    if ($idSolicitud <= 0) {
        throw new RuntimeException('No fue posible obtener el id de la solicitud creada.');
    }
    return $idSolicitud;
}

function ctSolicitudesRepoFindEstadoAreaIdByCode(PDO $conn, string $code): int
{
    $stmt = $conn->prepare('SELECT TOP (1) id_estado_area_solicitud FROM dbo.ct_estado_area_solicitud WHERE codigo = :codigo');
    $stmt->bindValue(':codigo', strtoupper(trim($code)), PDO::PARAM_STR);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctSolicitudesRepoEnsureAreaInstancesForSolicitud(PDO $conn, int $idSolicitud): void
{
    $solicitud = ctSolicitudesRepoFindById($conn, $idSolicitud);
    if (!is_array($solicitud)) {
        throw new RuntimeException('No fue posible crear las areas de la solicitud.');
    }

    $idEstadoHabilitada = ctSolicitudesRepoFindEstadoAreaIdByCode($conn, 'HABILITADA');
    if ($idEstadoHabilitada <= 0) {
        $idEstadoHabilitada = ctSolicitudesRepoFindEstadoAreaIdByCode($conn, 'PENDIENTE');
    }
    if ($idEstadoHabilitada <= 0) {
        throw new RuntimeException('No fue posible resolver estados de areas de solicitud.');
    }

    $sql = 'INSERT INTO dbo.ct_solicitud_area_instancia (
                id_solicitud,
                id_area_solicitud,
                id_solicitud_tipo_area,
                id_estado_area_solicitud,
                id_formulario_plantilla_version,
                es_requerida,
                orden_flujo,
                habilitada_en,
                fecha_creacion,
                fecha_actualizacion
            )
            SELECT
                :id_solicitud,
                sta.id_area_solicitud,
                sta.id_solicitud_tipo_area,
                :id_estado_area_solicitud,
                fpv.id_formulario_plantilla_version,
                sta.es_requerida,
                sta.orden_flujo,
                SYSUTCDATETIME(),
                SYSUTCDATETIME(),
                SYSUTCDATETIME()
            FROM dbo.ct_solicitud_tipo_area sta
            CROSS APPLY (
                SELECT TOP (1) v.id_formulario_plantilla_version
                FROM dbo.ct_formulario_plantilla_version v
                WHERE v.id_formulario_plantilla = sta.id_formulario_plantilla
                  AND v.publicado = 1
                ORDER BY v.version_numero DESC, v.id_formulario_plantilla_version DESC
            ) fpv
            WHERE sta.id_tipo_solicitud = :id_tipo_solicitud
              AND sta.activo = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM dbo.ct_solicitud_area_instancia existing
                  WHERE existing.id_solicitud = :id_solicitud_exists
                    AND existing.id_area_solicitud = sta.id_area_solicitud
              )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitud_exists', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_area_solicitud', $idEstadoHabilitada, PDO::PARAM_INT);
    $stmt->bindValue(':id_tipo_solicitud', (int) $solicitud['id_tipo_solicitud'], PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoFindById(PDO $conn, int $idSolicitud): ?array
{
    $sql = 'SELECT
                s.*,
                s.id_gerente_usuario AS id_solicitante,
                ts.codigo AS tipo_codigo,
                ts.nombre AS tipo_nombre,
                es.codigo AS estado_codigo,
                es.nombre AS estado_nombre
            FROM dbo.ct_solicitud s
            INNER JOIN dbo.ct_tipo_solicitud ts ON ts.id_tipo_solicitud = s.id_tipo_solicitud
            INNER JOIN dbo.ct_estado_solicitud es ON es.id_estado_solicitud = s.id_estado_solicitud
            WHERE s.id_solicitud = :id_solicitud';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoTouchSolicitud(PDO $conn, int $idSolicitud): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_solicitud SET fecha_actualizacion = SYSUTCDATETIME() WHERE id_solicitud = :id_solicitud');
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoUpdateEstado(PDO $conn, int $idSolicitud, int $idEstadoSolicitud): void
{
    $stmt = $conn->prepare(
        'UPDATE dbo.ct_solicitud
         SET id_estado_solicitud = :id_estado_solicitud,
             fecha_actualizacion = SYSUTCDATETIME()
         WHERE id_solicitud = :id_solicitud'
    );
    $stmt->bindValue(':id_estado_solicitud', $idEstadoSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoUpdateResumen(PDO $conn, int $idSolicitud, ?string $resumen): void
{
    $stmt = $conn->prepare(
        'UPDATE dbo.ct_solicitud
         SET resumen = :resumen,
             fecha_actualizacion = SYSUTCDATETIME()
         WHERE id_solicitud = :id_solicitud'
    );
    if ($resumen === null || trim($resumen) === '') {
        $stmt->bindValue(':resumen', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':resumen', trim($resumen), PDO::PARAM_STR);
    }
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoUpdateGeneratedLinks(PDO $conn, int $idSolicitud, int $idTerreno, int $idOperacion): void
{
    $stmt = $conn->prepare(
        'UPDATE dbo.ct_solicitud
         SET id_terreno_generado = :id_terreno,
             id_operacion_generada = :id_operacion,
             fecha_actualizacion = SYSUTCDATETIME()
         WHERE id_solicitud = :id_solicitud'
    );
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->bindValue(':id_operacion', $idOperacion, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoFindDraftBySolicitudId(PDO $conn, int $idSolicitud): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            *,
            rol_propuesto AS rol_asignado
         FROM dbo.ct_solicitud_adquisicion
         WHERE id_solicitud = :id_solicitud'
    );
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoUpsertDraft(PDO $conn, int $idSolicitud, array $payload): void
{
    $sql = 'MERGE dbo.ct_solicitud_adquisicion AS target
            USING (
                SELECT
                    :id_solicitud AS id_solicitud,
                    :rol_asignado AS rol_propuesto,
                    :rol_matriz AS rol_matriz,
                    :identificacion_propiedad AS identificacion_propiedad,
                    :superficie_m2 AS superficie_m2,
                    :id_comuna AS id_comuna,
                    :id_tipo_inmueble AS id_tipo_inmueble,
                    :fecha_adquisicion AS fecha_adquisicion,
                    :documento_fuente AS documento_fuente
            ) AS source
            ON target.id_solicitud = source.id_solicitud
            WHEN MATCHED THEN
                UPDATE SET
                    rol_propuesto = source.rol_propuesto,
                    rol_matriz = source.rol_matriz,
                    identificacion_propiedad = source.identificacion_propiedad,
                    superficie_m2 = source.superficie_m2,
                    id_comuna = source.id_comuna,
                    id_tipo_inmueble = source.id_tipo_inmueble,
                    fecha_adquisicion = source.fecha_adquisicion,
                    documento_fuente = source.documento_fuente,
                    fecha_actualizacion = SYSUTCDATETIME()
            WHEN NOT MATCHED THEN
                INSERT (
                    id_solicitud,
                    rol_propuesto,
                    rol_matriz,
                    identificacion_propiedad,
                    superficie_m2,
                    id_comuna,
                    id_tipo_inmueble,
                    fecha_adquisicion,
                    documento_fuente,
                    fecha_creacion,
                    fecha_actualizacion
                )
                VALUES (
                    source.id_solicitud,
                    source.rol_propuesto,
                    source.rol_matriz,
                    source.identificacion_propiedad,
                    source.superficie_m2,
                    source.id_comuna,
                    source.id_tipo_inmueble,
                    source.fecha_adquisicion,
                    source.documento_fuente,
                    SYSUTCDATETIME(),
                    SYSUTCDATETIME()
                );';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    foreach ([
        'rol_asignado',
        'rol_matriz',
        'identificacion_propiedad',
        'documento_fuente',
    ] as $field) {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $stmt->bindValue(':' . $field, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':' . $field, trim($value), PDO::PARAM_STR);
        }
    }
    $superficie = $payload['superficie_m2'] ?? null;
    if ($superficie === null) {
        $stmt->bindValue(':superficie_m2', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':superficie_m2', (float) $superficie);
    }
    foreach (['id_comuna', 'id_tipo_inmueble'] as $field) {
        $value = (int) ($payload[$field] ?? 0);
        if ($value <= 0) {
            $stmt->bindValue(':' . $field, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':' . $field, $value, PDO::PARAM_INT);
        }
    }
    $fecha = $payload['fecha_adquisicion'] ?? null;
    if (!is_string($fecha) || trim($fecha) === '') {
        $stmt->bindValue(':fecha_adquisicion', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':fecha_adquisicion', $fecha, PDO::PARAM_STR);
    }
    $stmt->execute();
}

function ctSolicitudesRepoListTitularesBySolicitudId(PDO $conn, int $idSolicitud): array
{
    $stmt = $conn->prepare(
        'SELECT
            st.*,
            t.nombre_razon_social,
            t.rut
         FROM dbo.ct_solicitud_adquisicion_titular st
         INNER JOIN dbo.ct_tercero t ON t.id_tercero = st.id_tercero
         WHERE st.id_solicitud = :id_solicitud
         ORDER BY st.id_solicitud_titular'
    );
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoReplaceTitulares(PDO $conn, int $idSolicitud, array $titulares): void
{
    $stmtDelete = $conn->prepare('DELETE FROM dbo.ct_solicitud_adquisicion_titular WHERE id_solicitud = :id_solicitud');
    $stmtDelete->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmtDelete->execute();

    if ($titulares === []) {
        return;
    }

    $sql = 'INSERT INTO dbo.ct_solicitud_adquisicion_titular (
                id_solicitud,
                id_tercero,
                porcentaje_derecho,
                vigente_desde,
                vigente_hasta,
                fecha_creacion,
                fecha_actualizacion
            ) VALUES (
                :id_solicitud,
                :id_tercero,
                :porcentaje_derecho,
                :vigente_desde,
                :vigente_hasta,
                SYSUTCDATETIME(),
                SYSUTCDATETIME()
            )';
    $stmt = $conn->prepare($sql);
    foreach ($titulares as $titular) {
        $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
        $stmt->bindValue(':id_tercero', (int) $titular['id_tercero'], PDO::PARAM_INT);
        $stmt->bindValue(':porcentaje_derecho', (float) $titular['porcentaje_derecho']);
        $stmt->bindValue(':vigente_desde', (string) $titular['vigente_desde'], PDO::PARAM_STR);
        $vigenteHasta = $titular['vigente_hasta'] ?? null;
        if (!is_string($vigenteHasta) || trim($vigenteHasta) === '') {
            $stmt->bindValue(':vigente_hasta', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':vigente_hasta', $vigenteHasta, PDO::PARAM_STR);
        }
        $stmt->execute();
    }
}

function ctSolicitudesRepoListParticipantesBySolicitudId(PDO $conn, int $idSolicitud): array
{
    $sql = 'SELECT
                aa.id_area_asignacion,
                aa.id_usuario_asignado AS id_participante_solicitud,
                aa.id_usuario_asignado AS id_usuario_corporativo,
                aa.es_responsable AS es_responsable_area,
                aa.activo,
                aa.fecha_asignacion AS fecha_creacion,
                sai.id_area_instancia,
                sai.id_solicitud,
                sai.id_area_solicitud,
                a.codigo AS area_codigo,
                a.nombre AS area_nombre,
                CONCAT(N\'Usuario #\', CONVERT(NVARCHAR(20), aa.id_usuario_asignado)) AS participante_nombre,
                CAST(NULL AS NVARCHAR(255)) AS participante_email
            FROM dbo.ct_solicitud_area_asignacion aa
            INNER JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = aa.id_area_instancia
            INNER JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
            WHERE sai.id_solicitud = :id_solicitud
              AND aa.activo = 1
            ORDER BY a.orden_visual, a.nombre, aa.es_responsable DESC, aa.id_usuario_asignado';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoReplaceParticipantes(PDO $conn, int $idSolicitud, array $rows): void
{
    ctSolicitudesRepoEnsureAreaInstancesForSolicitud($conn, $idSolicitud);

    $stmtDeleteAsignaciones = $conn->prepare(
        'DELETE aa
         FROM dbo.ct_solicitud_area_asignacion aa
         INNER JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = aa.id_area_instancia
         WHERE sai.id_solicitud = :id_solicitud'
    );
    $stmtDeleteAsignaciones->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmtDeleteAsignaciones->execute();

    $stmtDelete = $conn->prepare('DELETE FROM dbo.ct_participante_solicitud WHERE id_solicitud = :id_solicitud');
    $stmtDelete->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmtDelete->execute();

    if ($rows === []) {
        return;
    }

    $participantSql = 'INSERT INTO dbo.ct_participante_solicitud (
                id_solicitud,
                id_usuario,
                tipo_participacion,
                activo,
                fecha_creacion,
                fecha_actualizacion
            ) VALUES (
                :id_solicitud,
                :id_usuario,
                :tipo_participacion,
                1,
                SYSUTCDATETIME(),
                SYSUTCDATETIME()
            );
            SELECT CAST(SCOPE_IDENTITY() AS INT) AS id_participante_solicitud;';
    $participantStmt = $conn->prepare($participantSql);

    $assignmentSql = 'INSERT INTO dbo.ct_solicitud_area_asignacion (
                id_area_instancia,
                id_usuario_asignado,
                id_participante_solicitud,
                es_responsable,
                activo,
                fecha_asignacion
            ) VALUES (
                :id_area_instancia,
                :id_usuario_asignado,
                :id_participante_solicitud,
                :es_responsable,
                1,
                SYSUTCDATETIME()
            )';
    $assignmentStmt = $conn->prepare($assignmentSql);
    $participantsByUser = [];

    foreach ($rows as $row) {
        $idAreaSolicitud = (int) ($row['id_area_solicitud'] ?? 0);
        $idUsuarioAsignado = (int) ($row['id_participante_solicitud'] ?? 0);
        if ($idAreaSolicitud <= 0 || $idUsuarioAsignado <= 0) {
            continue;
        }

        $idAreaInstancia = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
        if ($idAreaInstancia <= 0) {
            continue;
        }

        if (!isset($participantsByUser[$idUsuarioAsignado])) {
            $participantStmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
            $participantStmt->bindValue(':id_usuario', $idUsuarioAsignado, PDO::PARAM_INT);
            $participantStmt->bindValue(':tipo_participacion', !empty($row['es_responsable_area']) ? 'APROBADOR' : 'COLABORADOR', PDO::PARAM_STR);
            $participantStmt->execute();
            $idParticipante = ctSolicitudesRepoFetchFirstIntFromAnyRowset($participantStmt);
            if ($idParticipante <= 0) {
                throw new RuntimeException('No fue posible obtener el id del participante de la solicitud.');
            }
            $participantsByUser[$idUsuarioAsignado] = $idParticipante;
        }

        $assignmentStmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
        $assignmentStmt->bindValue(':id_usuario_asignado', $idUsuarioAsignado, PDO::PARAM_INT);
        $assignmentStmt->bindValue(':id_participante_solicitud', $participantsByUser[$idUsuarioAsignado], PDO::PARAM_INT);
        $assignmentStmt->bindValue(':es_responsable', !empty($row['es_responsable_area']) ? 1 : 0, PDO::PARAM_INT);
        $assignmentStmt->execute();
    }
}

function ctSolicitudesRepoFindAreaInstanciaId(PDO $conn, int $idSolicitud, int $idAreaSolicitud): int
{
    $sql = 'SELECT TOP (1) id_area_instancia
            FROM dbo.ct_solicitud_area_instancia
            WHERE id_solicitud = :id_solicitud
              AND id_area_solicitud = :id_area_solicitud';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_area_solicitud', $idAreaSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctSolicitudesRepoFindAreaRespuesta(PDO $conn, int $idSolicitud, int $idAreaSolicitud): ?array
{
    $sql = 'SELECT TOP (1)
                sai.id_area_instancia,
                sai.id_solicitud,
                sai.id_area_solicitud,
                eas.codigo AS estado,
                eas.nombre AS estado_nombre,
                a.codigo AS area_codigo,
                a.nombre AS area_nombre,
                legal.estudio_titulos_ok,
                legal.prohibiciones_hipotecas,
                legal.litigios_vigentes,
                legal.observaciones_legal,
                arq.informe_tecnico_ok,
                arq.superficie_validada_m2,
                arq.requiere_regularizacion,
                arq.observaciones_arquitectura,
                COALESCE(legal.payload_extra_json, arq.payload_extra_json) AS payload_json
            FROM dbo.ct_solicitud_area_instancia sai
            INNER JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
            INNER JOIN dbo.ct_estado_area_solicitud eas ON eas.id_estado_area_solicitud = sai.id_estado_area_solicitud
            LEFT JOIN dbo.ct_solicitud_adquisicion_legal legal ON legal.id_area_instancia = sai.id_area_instancia
            LEFT JOIN dbo.ct_solicitud_adquisicion_arquitectura arq ON arq.id_area_instancia = sai.id_area_instancia
            WHERE sai.id_solicitud = :id_solicitud
              AND sai.id_area_solicitud = :id_area_solicitud';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_area_solicitud', $idAreaSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoListAreaRespuestasBySolicitudId(PDO $conn, int $idSolicitud): array
{
    $sql = 'SELECT
                sai.id_area_instancia,
                sai.id_solicitud,
                sai.id_area_solicitud,
                eas.codigo AS estado,
                eas.nombre AS estado_nombre,
                a.codigo AS area_codigo,
                a.nombre AS area_nombre,
                legal.estudio_titulos_ok,
                legal.prohibiciones_hipotecas,
                legal.litigios_vigentes,
                legal.observaciones_legal,
                arq.informe_tecnico_ok,
                arq.superficie_validada_m2,
                arq.requiere_regularizacion,
                arq.observaciones_arquitectura,
                COALESCE(legal.payload_extra_json, arq.payload_extra_json) AS payload_json
            FROM dbo.ct_solicitud_area_instancia sai
            INNER JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
            INNER JOIN dbo.ct_estado_area_solicitud eas ON eas.id_estado_area_solicitud = sai.id_estado_area_solicitud
            LEFT JOIN dbo.ct_solicitud_adquisicion_legal legal ON legal.id_area_instancia = sai.id_area_instancia
            LEFT JOIN dbo.ct_solicitud_adquisicion_arquitectura arq ON arq.id_area_instancia = sai.id_area_instancia
            WHERE sai.id_solicitud = :id_solicitud
            ORDER BY a.orden_visual, a.nombre';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoUpsertAreaRespuesta(PDO $conn, int $idSolicitud, int $idAreaSolicitud, ?int $idParticipanteSolicitud, array $payload): void
{
    $respuesta = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idAreaSolicitud);
    if (!is_array($respuesta)) {
        throw new RuntimeException('No existe instancia de area para la solicitud.');
    }

    $idAreaInstancia = (int) $respuesta['id_area_instancia'];
    $areaCodigo = strtoupper(trim((string) ($respuesta['area_codigo'] ?? '')));
    if ($areaCodigo === 'LEGAL') {
        $sql = 'MERGE dbo.ct_solicitud_adquisicion_legal AS target
                USING (
                    SELECT
                        :id_area_instancia AS id_area_instancia,
                        :id_solicitud AS id_solicitud,
                        :estudio_titulos_ok AS estudio_titulos_ok,
                        :prohibiciones_hipotecas AS prohibiciones_hipotecas,
                        :litigios_vigentes AS litigios_vigentes,
                        :observaciones_legal AS observaciones_legal,
                        :payload_json AS payload_extra_json
                ) AS source
                ON target.id_area_instancia = source.id_area_instancia
                WHEN MATCHED THEN
                    UPDATE SET
                        estudio_titulos_ok = source.estudio_titulos_ok,
                        prohibiciones_hipotecas = source.prohibiciones_hipotecas,
                        litigios_vigentes = source.litigios_vigentes,
                        observaciones_legal = source.observaciones_legal,
                        payload_extra_json = source.payload_extra_json,
                        fecha_actualizacion = SYSUTCDATETIME()
                WHEN NOT MATCHED THEN
                    INSERT (
                        id_area_instancia,
                        id_solicitud,
                        estudio_titulos_ok,
                        prohibiciones_hipotecas,
                        litigios_vigentes,
                        observaciones_legal,
                        payload_extra_json,
                        fecha_creacion,
                        fecha_actualizacion
                    )
                    VALUES (
                        source.id_area_instancia,
                        source.id_solicitud,
                        source.estudio_titulos_ok,
                        source.prohibiciones_hipotecas,
                        source.litigios_vigentes,
                        source.observaciones_legal,
                        source.payload_extra_json,
                        SYSUTCDATETIME(),
                        SYSUTCDATETIME()
                    );';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
        $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
        if (!array_key_exists('estudio_titulos_ok', $payload) || $payload['estudio_titulos_ok'] === null) {
            $stmt->bindValue(':estudio_titulos_ok', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':estudio_titulos_ok', (int) $payload['estudio_titulos_ok'], PDO::PARAM_INT);
        }
        if (!array_key_exists('prohibiciones_hipotecas', $payload) || $payload['prohibiciones_hipotecas'] === null) {
            $stmt->bindValue(':prohibiciones_hipotecas', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':prohibiciones_hipotecas', (int) $payload['prohibiciones_hipotecas'], PDO::PARAM_INT);
        }
        if (!array_key_exists('litigios_vigentes', $payload) || $payload['litigios_vigentes'] === null) {
            $stmt->bindValue(':litigios_vigentes', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':litigios_vigentes', (int) $payload['litigios_vigentes'], PDO::PARAM_INT);
        }
        $observaciones = trim((string) ($payload['observaciones_legal'] ?? ''));
        if ($observaciones === '') {
            $stmt->bindValue(':observaciones_legal', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':observaciones_legal', $observaciones, PDO::PARAM_STR);
        }
        $stmt->bindValue(':payload_json', null, PDO::PARAM_NULL);
        $stmt->execute();
    } elseif ($areaCodigo === 'ARQUITECTURA') {
        $sql = 'MERGE dbo.ct_solicitud_adquisicion_arquitectura AS target
                USING (
                    SELECT
                        :id_area_instancia AS id_area_instancia,
                        :id_solicitud AS id_solicitud,
                        :informe_tecnico_ok AS informe_tecnico_ok,
                        :superficie_validada_m2 AS superficie_validada_m2,
                        :requiere_regularizacion AS requiere_regularizacion,
                        :observaciones_arquitectura AS observaciones_arquitectura,
                        :payload_json AS payload_extra_json
                ) AS source
                ON target.id_area_instancia = source.id_area_instancia
                WHEN MATCHED THEN
                    UPDATE SET
                        informe_tecnico_ok = source.informe_tecnico_ok,
                        superficie_validada_m2 = source.superficie_validada_m2,
                        requiere_regularizacion = source.requiere_regularizacion,
                        observaciones_arquitectura = source.observaciones_arquitectura,
                        payload_extra_json = source.payload_extra_json,
                        fecha_actualizacion = SYSUTCDATETIME()
                WHEN NOT MATCHED THEN
                    INSERT (
                        id_area_instancia,
                        id_solicitud,
                        informe_tecnico_ok,
                        superficie_validada_m2,
                        requiere_regularizacion,
                        observaciones_arquitectura,
                        payload_extra_json,
                        fecha_creacion,
                        fecha_actualizacion
                    )
                    VALUES (
                        source.id_area_instancia,
                        source.id_solicitud,
                        source.informe_tecnico_ok,
                        source.superficie_validada_m2,
                        source.requiere_regularizacion,
                        source.observaciones_arquitectura,
                        source.payload_extra_json,
                        SYSUTCDATETIME(),
                        SYSUTCDATETIME()
                    );';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
        $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
        if (!array_key_exists('informe_tecnico_ok', $payload) || $payload['informe_tecnico_ok'] === null) {
            $stmt->bindValue(':informe_tecnico_ok', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':informe_tecnico_ok', (int) $payload['informe_tecnico_ok'], PDO::PARAM_INT);
        }
        if (!array_key_exists('superficie_validada_m2', $payload) || $payload['superficie_validada_m2'] === null) {
            $stmt->bindValue(':superficie_validada_m2', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':superficie_validada_m2', (float) $payload['superficie_validada_m2']);
        }
        if (!array_key_exists('requiere_regularizacion', $payload) || $payload['requiere_regularizacion'] === null) {
            $stmt->bindValue(':requiere_regularizacion', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':requiere_regularizacion', (int) $payload['requiere_regularizacion'], PDO::PARAM_INT);
        }
        $observaciones = trim((string) ($payload['observaciones_arquitectura'] ?? ''));
        if ($observaciones === '') {
            $stmt->bindValue(':observaciones_arquitectura', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':observaciones_arquitectura', $observaciones, PDO::PARAM_STR);
        }
        $stmt->bindValue(':payload_json', null, PDO::PARAM_NULL);
        $stmt->execute();
    } else {
        throw new RuntimeException('El area seleccionada no tiene formulario tipado implementado en esta fase.');
    }

    ctSolicitudesRepoUpdateAreaEstado($conn, $idAreaInstancia, 'EN_PROCESO');
}

function ctSolicitudesRepoMarkAreaCompleta(PDO $conn, int $idSolicitud, int $idAreaSolicitud, ?int $idParticipanteSolicitud): void
{
    $idAreaInstancia = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
    if ($idAreaInstancia <= 0) {
        throw new RuntimeException('No existe instancia de area para marcar como completa.');
    }
    ctSolicitudesRepoUpdateAreaEstado($conn, $idAreaInstancia, 'COMPLETA');
}

function ctSolicitudesRepoUpdateAreaEstado(PDO $conn, int $idAreaInstancia, string $estadoCodigo): void
{
    $idEstado = ctSolicitudesRepoFindEstadoAreaIdByCode($conn, $estadoCodigo);
    if ($idEstado <= 0) {
        throw new RuntimeException('No fue posible resolver el estado de area ' . $estadoCodigo . '.');
    }
    $sql = 'UPDATE dbo.ct_solicitud_area_instancia
            SET id_estado_area_solicitud = :id_estado,
                completada_en = CASE WHEN :codigo = \'COMPLETA\' THEN SYSUTCDATETIME() ELSE completada_en END,
                fecha_actualizacion = SYSUTCDATETIME()
            WHERE id_area_instancia = :id_area_instancia';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_estado', $idEstado, PDO::PARAM_INT);
    $stmt->bindValue(':codigo', strtoupper(trim($estadoCodigo)), PDO::PARAM_STR);
    $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoUpdateAreaParticipantesEstado(PDO $conn, int $idSolicitud, int $idAreaSolicitud, string $estadoTrabajo): void
{
    $idAreaInstancia = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
    if ($idAreaInstancia <= 0) {
        return;
    }
    if (in_array(strtoupper(trim($estadoTrabajo)), ['EN_CURSO', 'EN_PROCESO'], true)) {
        ctSolicitudesRepoUpdateAreaEstado($conn, $idAreaInstancia, 'EN_PROCESO');
    }
}

function ctSolicitudesRepoMarcarAreaConObservaciones(PDO $conn, int $idSolicitud, int $idAreaSolicitud, string $observacion): void
{
    $idAreaInstancia = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
    if ($idAreaInstancia <= 0) {
        throw new RuntimeException('No existe instancia de área para registrar observación.');
    }

    $observacion = trim($observacion);
    if ($observacion === '') {
        $observacion = 'Observación registrada por Gerencia General.';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($observacion) : strlen($observacion)) > 1000) {
        $observacion = function_exists('mb_substr') ? (string) mb_substr($observacion, 0, 1000) : substr($observacion, 0, 1000);
    }

    $idEstado = ctSolicitudesRepoFindEstadoAreaIdByCode($conn, 'CON_OBSERVACIONES');
    if ($idEstado <= 0) {
        throw new RuntimeException('No fue posible resolver el estado de área CON_OBSERVACIONES.');
    }

    $sql = 'UPDATE dbo.ct_solicitud_area_instancia
            SET id_estado_area_solicitud = :id_estado,
                observacion_abierta = :observacion,
                completada_en = NULL,
                cerrada_en = NULL,
                fecha_actualizacion = SYSUTCDATETIME()
            WHERE id_area_instancia = :id_area_instancia';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_estado', $idEstado, PDO::PARAM_INT);
    $stmt->bindValue(':observacion', $observacion, PDO::PARAM_STR);
    $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoComentarioResolutionColumnsReady(PDO $conn): bool
{
    $stmt = $conn->query("SELECT
        CASE WHEN COL_LENGTH('dbo.ct_solicitud_comentario', 'estado_revision') IS NOT NULL THEN 1 ELSE 0 END AS estado_ok,
        CASE WHEN COL_LENGTH('dbo.ct_solicitud_comentario', 'resuelto_en') IS NOT NULL THEN 1 ELSE 0 END AS resuelto_ok,
        CASE WHEN COL_LENGTH('dbo.ct_solicitud_comentario', 'id_usuario_resolucion') IS NOT NULL THEN 1 ELSE 0 END AS usuario_ok");
    if ($stmt === false) {
        return false;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    return (int) ($row['estado_ok'] ?? 0) === 1
        && (int) ($row['resuelto_ok'] ?? 0) === 1
        && (int) ($row['usuario_ok'] ?? 0) === 1;
}

function ctSolicitudesRepoAddComentario(PDO $conn, int $idSolicitud, int $idUsuario, ?int $idAreaSolicitud, string $comentario): void
{
    $idAreaInstancia = null;
    if ($idAreaSolicitud !== null && $idAreaSolicitud > 0) {
        $candidate = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
        $idAreaInstancia = $candidate > 0 ? $candidate : null;
    }

    if (ctSolicitudesRepoComentarioResolutionColumnsReady($conn)) {
        $sql = 'INSERT INTO dbo.ct_solicitud_comentario (
                    id_solicitud,
                    id_usuario,
                    id_area_instancia,
                    estado_revision,
                    comentario,
                    fecha_creacion
                ) VALUES (
                    :id_solicitud,
                    :id_usuario,
                    :id_area_instancia,
                    \'PENDIENTE\',
                    :comentario,
                    SYSUTCDATETIME()
                )';
    } else {
        $sql = 'INSERT INTO dbo.ct_solicitud_comentario (
                    id_solicitud,
                    id_usuario,
                    id_area_instancia,
                    comentario,
                    fecha_creacion
                ) VALUES (
                    :id_solicitud,
                    :id_usuario,
                    :id_area_instancia,
                    :comentario,
                    SYSUTCDATETIME()
                )';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    if ($idAreaInstancia === null || $idAreaInstancia <= 0) {
        $stmt->bindValue(':id_area_instancia', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
    }
    $stmt->bindValue(':comentario', $comentario, PDO::PARAM_STR);
    $stmt->execute();
}

function ctSolicitudesRepoFindComentarioById(PDO $conn, int $idSolicitud, int $idComentario): ?array
{
    if ($idSolicitud <= 0 || $idComentario <= 0) {
        return null;
    }

    $sql = 'SELECT TOP (1)
                sc.*,
                sai.id_area_solicitud
            FROM dbo.ct_solicitud_comentario sc
            LEFT JOIN dbo.ct_solicitud_area_instancia sai
                ON sai.id_area_instancia = sc.id_area_instancia
            WHERE sc.id_solicitud = :id_solicitud
              AND sc.id_solicitud_comentario = :id_solicitud_comentario';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitud_comentario', $idComentario, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoMarcarComentarioResuelto(PDO $conn, int $idComentario, int $idUsuario): void
{
    if (!ctSolicitudesRepoComentarioResolutionColumnsReady($conn)) {
        throw new RuntimeException('Debes ejecutar migración CT para habilitar resolución de comentarios.');
    }
    if ($idComentario <= 0) {
        throw new RuntimeException('Comentario inválido para resolver.');
    }
    if ($idUsuario <= 0) {
        throw new RuntimeException('Usuario inválido para resolver comentario.');
    }

    $sql = 'UPDATE dbo.ct_solicitud_comentario
            SET estado_revision = \'RESUELTO\',
                resuelto_en = SYSUTCDATETIME(),
                id_usuario_resolucion = :id_usuario_resolucion
            WHERE id_solicitud_comentario = :id_solicitud_comentario';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_usuario_resolucion', $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(':id_solicitud_comentario', $idComentario, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoQueueNotificacion(
    PDO $conn,
    int $idSolicitud,
    ?int $idAreaSolicitud,
    string $tipoEvento,
    ?int $idUsuarioDestinatario,
    string $destinatario,
    string $asunto,
    ?string $payload = null
): int {
    $idAreaInstancia = null;
    if ($idAreaSolicitud !== null && $idAreaSolicitud > 0) {
        $candidate = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
        $idAreaInstancia = $candidate > 0 ? $candidate : null;
    }

    $tipoEvento = trim($tipoEvento);
    if ($tipoEvento === '') {
        $tipoEvento = 'SOLICITUD_EVENTO';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($tipoEvento) : strlen($tipoEvento)) > 50) {
        $tipoEvento = function_exists('mb_substr') ? (string) mb_substr($tipoEvento, 0, 50) : substr($tipoEvento, 0, 50);
    }

    $destinatario = trim($destinatario);
    if ($destinatario === '') {
        $destinatario = $idUsuarioDestinatario !== null && $idUsuarioDestinatario > 0
            ? ('Usuario #' . $idUsuarioDestinatario)
            : 'Destinatario';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($destinatario) : strlen($destinatario)) > 180) {
        $destinatario = function_exists('mb_substr') ? (string) mb_substr($destinatario, 0, 180) : substr($destinatario, 0, 180);
    }

    $asunto = trim($asunto);
    if ($asunto === '') {
        $asunto = 'Notificación de solicitud';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($asunto) : strlen($asunto)) > 255) {
        $asunto = function_exists('mb_substr') ? (string) mb_substr($asunto, 0, 255) : substr($asunto, 0, 255);
    }

    $sql = 'INSERT INTO dbo.ct_solicitud_notificacion (
                id_solicitud,
                id_area_instancia,
                tipo_evento,
                id_usuario_destinatario,
                destinatario,
                asunto,
                payload,
                estado,
                intentos,
                fecha_creacion
            ) VALUES (
                :id_solicitud,
                :id_area_instancia,
                :tipo_evento,
                :id_usuario_destinatario,
                :destinatario,
                :asunto,
                :payload,
                \'PENDIENTE\',
                0,
                SYSUTCDATETIME()
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    if ($idAreaInstancia === null) {
        $stmt->bindValue(':id_area_instancia', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
    }
    $stmt->bindValue(':tipo_evento', $tipoEvento, PDO::PARAM_STR);
    if ($idUsuarioDestinatario === null || $idUsuarioDestinatario <= 0) {
        $stmt->bindValue(':id_usuario_destinatario', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_usuario_destinatario', $idUsuarioDestinatario, PDO::PARAM_INT);
    }
    $stmt->bindValue(':destinatario', $destinatario, PDO::PARAM_STR);
    $stmt->bindValue(':asunto', $asunto, PDO::PARAM_STR);
    if ($payload === null || trim($payload) === '') {
        $stmt->bindValue(':payload', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':payload', $payload, PDO::PARAM_STR);
    }
    $stmt->execute();
    return (int) $conn->lastInsertId();
}

function ctSolicitudesRepoMarkNotificacionEnviada(PDO $conn, int $idNotificacion): void
{
    if ($idNotificacion <= 0) {
        return;
    }
    $stmt = $conn->prepare(
        'UPDATE dbo.ct_solicitud_notificacion
         SET estado = \'ENVIADA\',
             intentos = intentos + 1,
             fecha_ultimo_intento = SYSUTCDATETIME(),
             fecha_envio = SYSUTCDATETIME(),
             error_ultimo = NULL
         WHERE id_solicitud_notificacion = :id'
    );
    $stmt->bindValue(':id', $idNotificacion, PDO::PARAM_INT);
    $stmt->execute();
}

function ctSolicitudesRepoMarkNotificacionError(PDO $conn, int $idNotificacion, string $error): void
{
    if ($idNotificacion <= 0) {
        return;
    }
    $error = trim($error);
    if ($error === '') {
        $error = 'No fue posible enviar el correo.';
    }
    if ((function_exists('mb_strlen') ? mb_strlen($error) : strlen($error)) > 2000) {
        $error = function_exists('mb_substr') ? (string) mb_substr($error, 0, 2000) : substr($error, 0, 2000);
    }
    $stmt = $conn->prepare(
        'UPDATE dbo.ct_solicitud_notificacion
         SET estado = \'ERROR\',
             intentos = intentos + 1,
             fecha_ultimo_intento = SYSUTCDATETIME(),
             error_ultimo = :error
         WHERE id_solicitud_notificacion = :id'
    );
    $stmt->bindValue(':id', $idNotificacion, PDO::PARAM_INT);
    $stmt->bindValue(':error', $error, PDO::PARAM_STR);
    $stmt->execute();
}

function ctSolicitudesRepoListComentariosBySolicitudId(PDO $conn, int $idSolicitud): array
{
    if (ctSolicitudesRepoComentarioResolutionColumnsReady($conn)) {
        $sql = 'SELECT
                    sc.*,
                    a.codigo AS area_codigo,
                    a.nombre AS area_nombre
                FROM dbo.ct_solicitud_comentario sc
                LEFT JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = sc.id_area_instancia
                LEFT JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
                WHERE sc.id_solicitud = :id_solicitud
                ORDER BY CASE WHEN UPPER(ISNULL(sc.estado_revision, \'PENDIENTE\')) = \'RESUELTO\' THEN 1 ELSE 0 END,
                         sc.fecha_creacion DESC,
                         sc.id_solicitud_comentario DESC';
    } else {
        $sql = 'SELECT
                    sc.*,
                    a.codigo AS area_codigo,
                    a.nombre AS area_nombre
                FROM dbo.ct_solicitud_comentario sc
                LEFT JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = sc.id_area_instancia
                LEFT JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
                WHERE sc.id_solicitud = :id_solicitud
                ORDER BY sc.fecha_creacion DESC, sc.id_solicitud_comentario DESC';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoAddAdjunto(PDO $conn, int $idSolicitud, ?int $idAreaSolicitud, string $nombre, ?string $tipo, string $referencia, ?string $nota): void
{
    $idAreaInstancia = null;
    if ($idAreaSolicitud !== null && $idAreaSolicitud > 0) {
        $candidate = ctSolicitudesRepoFindAreaInstanciaId($conn, $idSolicitud, $idAreaSolicitud);
        $idAreaInstancia = $candidate > 0 ? $candidate : null;
    }

    $sql = 'INSERT INTO dbo.ct_solicitud_adjunto (
                id_solicitud,
                id_area_instancia,
                nombre,
                tipo,
                referencia,
                nota,
                fecha_creacion
            ) VALUES (
                :id_solicitud,
                :id_area_instancia,
                :nombre,
                :tipo,
                :referencia,
                :nota,
                SYSUTCDATETIME()
            )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    if ($idAreaInstancia === null || $idAreaInstancia <= 0) {
        $stmt->bindValue(':id_area_instancia', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':id_area_instancia', $idAreaInstancia, PDO::PARAM_INT);
    }
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($tipo === null || trim($tipo) === '') {
        $stmt->bindValue(':tipo', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':tipo', trim($tipo), PDO::PARAM_STR);
    }
    $stmt->bindValue(':referencia', $referencia, PDO::PARAM_STR);
    if ($nota === null || trim($nota) === '') {
        $stmt->bindValue(':nota', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':nota', trim($nota), PDO::PARAM_STR);
    }
    $stmt->execute();
}

function ctSolicitudesRepoListAdjuntosBySolicitudId(PDO $conn, int $idSolicitud): array
{
    $sql = 'SELECT
                sa.*,
                a.codigo AS area_codigo,
                a.nombre AS area_nombre
            FROM dbo.ct_solicitud_adjunto sa
            LEFT JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = sa.id_area_instancia
            LEFT JOIN dbo.cr_departamentos a ON a.id_departamento = sai.id_area_solicitud
            WHERE sa.id_solicitud = :id_solicitud
            ORDER BY sa.fecha_creacion DESC, sa.id_solicitud_adjunto DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctSolicitudesRepoFindParticipanteCatalogById(PDO $conn, int $idParticipanteSolicitud): ?array
{
    foreach (ctSolicitudesRepoListParticipantesCatalog($conn) as $row) {
        if ((int) ($row['id_participante_solicitud'] ?? 0) === $idParticipanteSolicitud) {
            return $row;
        }
    }
    return null;
}

function ctSolicitudesRepoFindAreaById(PDO $conn, int $idAreaSolicitud): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            d.id_departamento AS id_area_solicitud,
            d.id_departamento,
            d.codigo,
            d.nombre,
            d.descripcion,
            d.orden_visual,
            d.activo
         FROM dbo.cr_departamentos d
         WHERE d.id_departamento = :id'
    );
    $stmt->bindValue(':id', $idAreaSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoFindAreaAssignmentForUser(PDO $conn, int $idSolicitud, int $idAreaSolicitud, int $idUsuario): ?array
{
    $sql = 'SELECT TOP (1)
                aa.*,
                sai.id_solicitud,
                sai.id_area_solicitud,
                aa.id_usuario_asignado AS id_usuario_corporativo
            FROM dbo.ct_solicitud_area_asignacion aa
            INNER JOIN dbo.ct_solicitud_area_instancia sai ON sai.id_area_instancia = aa.id_area_instancia
            WHERE sai.id_solicitud = :id_solicitud
              AND sai.id_area_solicitud = :id_area_solicitud
              AND aa.id_usuario_asignado = :id_usuario
              AND aa.activo = 1';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_area_solicitud', $idAreaSolicitud, PDO::PARAM_INT);
    $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ctSolicitudesRepoHasAreaResponseActivity(PDO $conn, int $idSolicitud): bool
{
    $stmt = $conn->prepare(
        'SELECT TOP (1) 1
         FROM dbo.ct_solicitud_area_instancia sai
         INNER JOIN dbo.ct_estado_area_solicitud eas
            ON eas.id_estado_area_solicitud = sai.id_estado_area_solicitud
         LEFT JOIN dbo.ct_solicitud_adquisicion_legal legal
            ON legal.id_area_instancia = sai.id_area_instancia
         LEFT JOIN dbo.ct_solicitud_adquisicion_arquitectura arq
            ON arq.id_area_instancia = sai.id_area_instancia
         WHERE sai.id_solicitud = :id_solicitud
           AND (
                legal.estudio_titulos_ok IS NOT NULL
                OR arq.informe_tecnico_ok IS NOT NULL
                OR eas.codigo IN (\'EN_PROCESO\', \'CON_OBSERVACIONES\', \'COMPLETA\', \'CERRADA\')
           )'
    );
    $stmt->bindValue(':id_solicitud', $idSolicitud, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctSolicitudesRepoUserBelongsToGerenciaGeneral(PDO $conn, int $idUsuario): bool
{
    if ($idUsuario <= 0) {
        return false;
    }
    if (!ctSolicitudesRepoTableExists($conn, 'cr_usuario_departamento') || !ctSolicitudesRepoTableExists($conn, 'cr_departamentos')) {
        return false;
    }

    $sql = 'SELECT TOP (1) 1
            FROM dbo.cr_usuario_departamento ud
            INNER JOIN dbo.cr_departamentos d ON d.id_departamento = ud.departamento_id
            WHERE ud.usuario_id = :id_usuario
              AND d.activo = 1
              AND (
                    UPPER(LTRIM(RTRIM(ISNULL(d.codigo, \'\')))) IN (\'GERENCIA_GENERAL\', \'GERENCIA GENERAL\')
                    OR UPPER(ISNULL(d.nombre, \'\')) LIKE \'%GERENCIA%GENERAL%\'
                  )';
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}
