<?php
declare(strict_types=1);

function ctCatalogoTiposInmuebleRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ti.id_tipo_inmueble, ti.nombre, ti.activo, COUNT(t.id_terreno) AS terrenos_count
         FROM dbo.ct_tipo_inmueble ti
         LEFT JOIN dbo.ct_terreno t ON t.id_tipo_inmueble = ti.id_tipo_inmueble
         GROUP BY ti.id_tipo_inmueble, ti.nombre, ti.activo
         ORDER BY ti.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoTiposInmuebleRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tipo_inmueble WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoTiposInmuebleRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_tipo_inmueble WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
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

function ctCatalogoTiposInmuebleRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_tipo_inmueble (nombre, activo) VALUES (:nombre, 1)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoTiposInmuebleRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tipo_inmueble SET nombre = :nombre WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoTiposInmuebleRepoToggleActivo(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_tipo_inmueble SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id_tipo_inmueble = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
