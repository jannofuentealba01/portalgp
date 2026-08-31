<?php
declare(strict_types=1);

function ctCatalogoEstadosPredialesRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ep.id_estado_predial, ep.nombre, COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_estado_terreno_predial ep
         LEFT JOIN dbo.ct_terreno t ON t.id_estado_predial = ep.id_estado_predial
         GROUP BY ep.id_estado_predial, ep.nombre
         ORDER BY ep.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoEstadosPredialesRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_predial WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoEstadosPredialesRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_predial WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
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

function ctCatalogoEstadosPredialesRepoTerrenosCount(PDO $conn, int $id): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctCatalogoEstadosPredialesRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEstadosPredialesRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_estado_terreno_predial SET nombre = :nombre WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEstadosPredialesRepoDelete(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_estado_terreno_predial WHERE id_estado_predial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
