<?php
declare(strict_types=1);

function ctCatalogoEstadosComercialesRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ec.id_estado_comercial, ec.nombre, COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_estado_terreno_comercial ec
         LEFT JOIN dbo.ct_terreno t ON t.id_estado_comercial = ec.id_estado_comercial
         GROUP BY ec.id_estado_comercial, ec.nombre
         ORDER BY ec.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoEstadosComercialesRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_comercial WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoEstadosComercialesRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_estado_terreno_comercial WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
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

function ctCatalogoEstadosComercialesRepoTerrenosCount(PDO $conn, int $id): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctCatalogoEstadosComercialesRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEstadosComercialesRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_estado_terreno_comercial SET nombre = :nombre WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEstadosComercialesRepoDelete(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_estado_terreno_comercial WHERE id_estado_comercial = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
