<?php
declare(strict_types=1);

function ctCatalogoTiposTasacionRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ec.id_tipo_tasacion, ec.nombre, COUNT(t.id_tasacion) AS tasaciones_count
         FROM dbo.ct_tipo_tasacion ec
         LEFT JOIN dbo.ct_tasacion_terreno t ON t.id_tipo_tasacion = ec.id_tipo_tasacion
         GROUP BY ec.id_tipo_tasacion, ec.nombre
         ORDER BY ec.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoTiposTasacionRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tipo_tasacion WHERE id_tipo_tasacion = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoTiposTasacionRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_tipo_tasacion WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_tipo_tasacion <> :exclude_id';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoTiposTasacionRepoTasacionesCount(PDO $conn, int $id): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.ct_tasacion_terreno WHERE id_tipo_tasacion = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctCatalogoTiposTasacionRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_tipo_tasacion (nombre) VALUES (:nombre)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoTiposTasacionRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tipo_tasacion SET nombre = :nombre WHERE id_tipo_tasacion = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoTiposTasacionRepoDelete(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('DELETE FROM dbo.ct_tipo_tasacion WHERE id_tipo_tasacion = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
