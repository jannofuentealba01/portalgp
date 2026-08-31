<?php
declare(strict_types=1);

function ctTercerosRepoBuildWhere(array $filtros): array
{
    $conditions = [];
    $params = [];

    $filtroNombre = (string) ($filtros['filtroNombre'] ?? '');
    $filtroRut = (string) ($filtros['filtroRut'] ?? '');
    $filtroTipo = (string) ($filtros['filtroTipo'] ?? '');
    $filtroRelacion = (string) ($filtros['filtroRelacion'] ?? '');

    if ($filtroNombre !== '') {
        $conditions[] = "ISNULL(nombre_razon_social, '') LIKE :filtro_nombre";
        $params[':filtro_nombre'] = '%' . $filtroNombre . '%';
    }

    if ($filtroRut !== '') {
        $conditions[] = "ISNULL(rut, '') LIKE :filtro_rut";
        $params[':filtro_rut'] = '%' . $filtroRut . '%';
    }

    if ($filtroTipo === 'N' || $filtroTipo === 'J') {
        $conditions[] = 'tipo_persona = :filtro_tipo';
        $params[':filtro_tipo'] = $filtroTipo;
    }

    if ($filtroRelacion === 'P') {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM dbo.ct_titularidad_terreno tt
            WHERE tt.id_tercero = dbo.ct_tercero.id_tercero
        )';
    } elseif ($filtroRelacion === 'C') {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM dbo.ct_venta_terreno_tercero vtt
            WHERE vtt.id_tercero = dbo.ct_tercero.id_tercero
        )';
    }

    return [
        'where' => $conditions === [] ? '1=1' : implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function ctTercerosRepoCount(PDO $conn, array $filtros): int
{
    $query = ctTercerosRepoBuildWhere($filtros);
    $stmt = $conn->prepare("SELECT COUNT(*) FROM dbo.ct_tercero WHERE {$query['where']}");
    foreach ($query['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

function ctTercerosRepoList(PDO $conn, array $filtros, string $orderSql, int $offset, int $limit): array
{
    $query = ctTercerosRepoBuildWhere($filtros);
    $stmt = $conn->prepare(
        "SELECT id_tercero, tipo_persona, rut, nombre_razon_social
         FROM dbo.ct_tercero
         WHERE {$query['where']}
         ORDER BY {$orderSql}
         OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY"
    );
    foreach ($query['params'] as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctTercerosRepoInsert(PDO $conn, string $tipo, ?string $rut, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_tercero (tipo_persona, rut, nombre_razon_social) VALUES (:tipo, :rut, :nombre)');
    $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
    if ($rut === null) {
        $stmt->bindValue(':rut', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rut', $rut, PDO::PARAM_STR);
    }
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTercerosRepoUpdate(PDO $conn, int $idTercero, string $tipo, ?string $rut, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tercero SET tipo_persona = :tipo, rut = :rut, nombre_razon_social = :nombre WHERE id_tercero = :id');
    $stmt->bindValue(':id', $idTercero, PDO::PARAM_INT);
    $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
    if ($rut === null) {
        $stmt->bindValue(':rut', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':rut', $rut, PDO::PARAM_STR);
    }
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctTercerosRepoDelete(PDO $conn, int $idTercero): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_tercero WHERE id_tercero = :id');
    $stmt->bindValue(':id', $idTercero, PDO::PARAM_INT);
    $stmt->execute();
}

function ctTercerosRepoExistsRazonSocialJuridica(PDO $conn, string $razonSocial, ?int $excludeId = null): bool
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

    return $stmt->fetchColumn() !== false;
}

function ctTercerosRepoFindByRuts(PDO $conn, array $ruts): array
{
    $normalized = [];
    foreach ($ruts as $rut) {
        $key = trim((string) $rut);
        if ($key === '') {
            continue;
        }
        $normalized[$key] = true;
    }

    $uniqueRuts = array_keys($normalized);
    if ($uniqueRuts === []) {
        return [];
    }

    $result = [];
    foreach (array_chunk($uniqueRuts, 400) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $index => $rut) {
            $placeholder = ':rut_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $rut;
        }

        $sql = 'SELECT id_tercero, tipo_persona, rut, nombre_razon_social
                FROM dbo.ct_tercero
                WHERE rut IN (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rutKey = trim((string) ($row['rut'] ?? ''));
            if ($rutKey === '') {
                continue;
            }
            $result[$rutKey] = $row;
        }
    }

    return $result;
}

function ctTercerosRepoFindByNombreRazonSocialKeys(PDO $conn, array $normalizedNames): array
{
    $normalized = [];
    foreach ($normalizedNames as $nameKey) {
        $key = trim((string) $nameKey);
        if ($key === '') {
            continue;
        }
        $normalized[$key] = true;
    }

    $uniqueKeys = array_keys($normalized);
    if ($uniqueKeys === []) {
        return [];
    }

    $result = [];
    foreach (array_chunk($uniqueKeys, 250) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $index => $nameKey) {
            $placeholder = ':name_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $nameKey;
        }

        $sql = "SELECT id_tercero, tipo_persona, rut, nombre_razon_social,
                       UPPER(LTRIM(RTRIM(nombre_razon_social))) AS nombre_norm
                FROM dbo.ct_tercero
                WHERE UPPER(LTRIM(RTRIM(nombre_razon_social))) IN (" . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $nameKey = trim((string) ($row['nombre_norm'] ?? ''));
            if ($nameKey === '') {
                continue;
            }

            if (!isset($result[$nameKey])) {
                $result[$nameKey] = [
                    'count' => 0,
                    'row' => null,
                ];
            }

            $result[$nameKey]['count']++;
            if ($result[$nameKey]['row'] === null) {
                unset($row['nombre_norm']);
                $result[$nameKey]['row'] = $row;
            }
        }
    }

    return $result;
}

function ctTercerosRepoFindByIds(PDO $conn, array $ids): array
{
    $normalized = [];
    foreach ($ids as $id) {
        $value = (int) $id;
        if ($value <= 0) {
            continue;
        }
        $normalized[$value] = true;
    }

    $uniqueIds = array_keys($normalized);
    if ($uniqueIds === []) {
        return [];
    }

    $result = [];
    foreach (array_chunk($uniqueIds, 400) as $chunk) {
        $placeholders = [];
        $params = [];
        foreach ($chunk as $index => $id) {
            $placeholder = ':id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $sql = 'SELECT id_tercero, tipo_persona, rut, nombre_razon_social
                FROM dbo.ct_tercero
                WHERE id_tercero IN (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = max(0, (int) ($row['id_tercero'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            $result[$id] = $row;
        }
    }

    return $result;
}
