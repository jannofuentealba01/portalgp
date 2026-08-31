<?php
declare(strict_types=1);

function ctCatalogoEntidadesFinancierasRepoList(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT ef.id_entidad_financiera,
                ef.nombre,
                ef.activo,
                ISNULL(t.tasaciones_count, 0) + ISNULL(h.hipotecas_count, 0) AS usos_count
         FROM dbo.ct_entidad_financiera ef
         LEFT JOIN (
            SELECT id_entidad_financiera, COUNT(*) AS tasaciones_count
            FROM dbo.ct_tasacion_terreno
            WHERE id_entidad_financiera IS NOT NULL
            GROUP BY id_entidad_financiera
         ) t ON t.id_entidad_financiera = ef.id_entidad_financiera
         LEFT JOIN (
            SELECT id_entidad_financiera, COUNT(*) AS hipotecas_count
            FROM dbo.ct_hipoteca_terreno
            GROUP BY id_entidad_financiera
         ) h ON h.id_entidad_financiera = ef.id_entidad_financiera
         ORDER BY ef.nombre'
    );
    return $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function ctCatalogoEntidadesFinancierasRepoExistsById(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_entidad_financiera WHERE id_entidad_financiera = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoEntidadesFinancierasRepoExistsByNombre(PDO $conn, string $nombre, ?int $excludeId = null): bool
{
    $sql = 'SELECT TOP (1) 1 FROM dbo.ct_entidad_financiera WHERE UPPER(LTRIM(RTRIM(nombre))) = UPPER(LTRIM(RTRIM(:nombre)))';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id_entidad_financiera <> :exclude_id';
    }
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    if ($excludeId !== null && $excludeId > 0) {
        $stmt->bindValue(':exclude_id', $excludeId, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctCatalogoEntidadesFinancierasRepoInsert(PDO $conn, string $nombre): void
{
    $stmt = $conn->prepare('INSERT INTO dbo.ct_entidad_financiera (nombre, activo) VALUES (:nombre, 1)');
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEntidadesFinancierasRepoUpdate(PDO $conn, int $id, string $nombre): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_entidad_financiera SET nombre = :nombre WHERE id_entidad_financiera = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':nombre', $nombre, PDO::PARAM_STR);
    $stmt->execute();
}

function ctCatalogoEntidadesFinancierasRepoToggleActivo(PDO $conn, int $id): void
{
    $stmt = $conn->prepare('UPDATE dbo.ct_entidad_financiera SET activo = CASE WHEN activo = 1 THEN 0 ELSE 1 END WHERE id_entidad_financiera = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}
