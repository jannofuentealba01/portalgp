<?php
declare(strict_types=1);

function ctCatalogoUsufructuarioTiposRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ut.id_usufructuario_tipo,
                ut.nombre,
                ut.activo,
                COUNT(u.id_usufructo) AS usos_count
         FROM dbo.ct_usufructuario_tipo ut
         LEFT JOIN dbo.ct_usufructo_terreno u ON u.id_usufructuario_tipo = ut.id_usufructuario_tipo
         GROUP BY ut.id_usufructuario_tipo, ut.nombre, ut.activo
         ORDER BY ut.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoUsufructuarioTiposRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_usufructuario_tipo WHERE id_usufructuario_tipo = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoUsufructuarioTiposRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_usufructuario_tipo WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_usufructuario_tipo <> :exclude_id';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoUsufructuarioTiposRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_usufructuario_tipo (nombre, activo) VALUES (:nombre, 1)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoUsufructuarioTiposRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_usufructuario_tipo SET nombre = :nombre WHERE id_usufructuario_tipo = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoUsufructuarioTiposRepoToggleActivo(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_usufructuario_tipo SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id_usufructuario_tipo = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
