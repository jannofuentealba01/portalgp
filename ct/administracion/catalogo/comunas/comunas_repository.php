<?php
declare(strict_types=1);

function ctCatalogoComunasRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT c.id_comuna, c.nombre, COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_comuna c
         LEFT JOIN dbo.ct_terreno t ON t.id_comuna = c.id_comuna
         GROUP BY c.id_comuna, c.nombre
         ORDER BY c.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoComunasRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_comuna WHERE id_comuna = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoComunasRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_comuna WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
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

function ctCatalogoComunasRepoTerrenosCount(PDO $conn, int $id): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_terreno WHERE id_comuna = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctCatalogoComunasRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_comuna (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoComunasRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_comuna SET nombre = :nombre WHERE id_comuna = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoComunasRepoDelete(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_comuna WHERE id_comuna = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
