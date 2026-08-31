<?php
declare(strict_types=1);

function ctComercialRepoBuildWhere(array $filtros): array
{
    $conditions = [];
    $params = [];

    $filtroTexto = trim((string) ($filtros['filtroTexto'] ?? ''));
    $filtroEstadoComercial = (int) ($filtros['filtroEstadoComercial'] ?? 0);

    if ($filtroTexto !== '') {
        $like = '%' . $filtroTexto . '%';
        $conditions[] = "(
            ISNULL(t.rol_asignado, '') LIKE :filtro_texto_rol
            OR ISNULL(t.rol_matriz, '') LIKE :filtro_texto_matriz
            OR ISNULL(t.identificacion_propiedad, '') LIKE :filtro_texto_ident
            OR EXISTS (
                SELECT 1
                FROM dbo.ct_titularidad_terreno tt
                INNER JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
                WHERE tt.id_terreno = t.id_terreno
                  AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
                  AND (
                      ISNULL(tr.nombre_razon_social, '') LIKE :filtro_texto_prop
                      OR ISNULL(tr.rut, '') LIKE :filtro_texto_rut
                  )
            )
        )";
        $params[':filtro_texto_rol'] = $like;
        $params[':filtro_texto_matriz'] = $like;
        $params[':filtro_texto_ident'] = $like;
        $params[':filtro_texto_prop'] = $like;
        $params[':filtro_texto_rut'] = $like;
    }

    if ($filtroEstadoComercial > 0) {
        $conditions[] = 't.id_estado_comercial = :filtro_estado_comercial';
        $params[':filtro_estado_comercial'] = $filtroEstadoComercial;
    }

    return [
        'where' => $conditions === [] ? '1=1' : implode(' AND ', $conditions),
        'params' => $params,
    ];
}

function ctComercialRepoCountTerrenos(PDO $conn, array $filtros): int
{
    $where = ctComercialRepoBuildWhere($filtros);

    $sql = "SELECT COUNT(*)
            FROM dbo.ct_terreno t
            WHERE {$where['where']}";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible preparar el conteo de terrenos comerciales.');
    }

    foreach ($where['params'] as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function ctComercialRepoListTerrenos(PDO $conn, array $filtros, string $orderSql, int $offset, int $limit): array
{
    $where = ctComercialRepoBuildWhere($filtros);

    $sql = "SELECT
                t.id_terreno,
                t.rol_asignado,
                t.rol_matriz,
                t.identificacion_propiedad,
                t.superficie_m2,
                c.nombre AS comuna_nombre,
                ISNULL(ec.nombre, '') AS estado_comercial_nombre,
                ISNULL(tp.propietario_principal, '') AS propietario_principal,
                lt.id_tasacion AS ultima_tasacion_id,
                lt.fecha_tasacion AS ultima_tasacion_fecha,
                lt.valor_total_uf AS ultima_tasacion_valor_total_uf,
                lv.id_venta AS ultima_venta_id,
                lv.fecha_venta AS ultima_venta_fecha,
                lv.valor_total_uf AS ultima_venta_valor_total_uf
            FROM dbo.ct_terreno t
            INNER JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
            LEFT JOIN dbo.ct_estado_terreno_comercial ec ON ec.id_estado_comercial = t.id_estado_comercial
            OUTER APPLY (
                SELECT TOP (1)
                    tt.id_tasacion,
                    tt.fecha_tasacion,
                    tt.valor_total_uf
                FROM dbo.ct_tasacion_terreno tt
                WHERE tt.id_terreno = t.id_terreno
                ORDER BY tt.fecha_tasacion DESC, tt.id_tasacion DESC
            ) lt
            OUTER APPLY (
                SELECT TOP (1)
                    vt.id_venta,
                    vt.fecha_venta,
                    vt.valor_total_uf
                FROM dbo.ct_venta_terreno vt
                WHERE vt.id_terreno = t.id_terreno
                ORDER BY vt.fecha_venta DESC, vt.id_venta DESC
            ) lv
            OUTER APPLY (
                SELECT TOP (1)
                    tr.nombre_razon_social AS propietario_principal
                FROM dbo.ct_titularidad_terreno tt
                INNER JOIN dbo.ct_tercero tr ON tr.id_tercero = tt.id_tercero
                WHERE tt.id_terreno = t.id_terreno
                  AND (tt.vigente_hasta IS NULL OR tt.vigente_hasta >= CAST(GETDATE() AS DATE))
                ORDER BY tt.porcentaje_derecho DESC, tt.vigente_desde DESC, tt.id_titularidad DESC
            ) tp
            WHERE {$where['where']}
            ORDER BY {$orderSql}
            OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible preparar el listado comercial de terrenos.');
    }

    foreach ($where['params'] as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListEstadosComerciales(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_estado_comercial, nombre FROM dbo.ct_estado_terreno_comercial ORDER BY nombre');
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListTiposTasacion(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tipo_tasacion, nombre FROM dbo.ct_tipo_tasacion ORDER BY nombre');
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListEntidadesFinancieras(PDO $conn): array
{
    $existsStmt = $conn->query("SELECT OBJECT_ID('dbo.ct_entidad_financiera', 'U')");
    if (!($existsStmt instanceof PDOStatement)) {
        return [];
    }
    $tableId = (int) $existsStmt->fetchColumn();
    if ($tableId <= 0) {
        return [];
    }

    $stmt = $conn->query('SELECT id_entidad_financiera, nombre FROM dbo.ct_entidad_financiera WHERE ISNULL(activo, 1) = 1 ORDER BY nombre');
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListTerrenosSelector(PDO $conn): array
{
    $sql = "SELECT
                t.id_terreno,
                t.rol_asignado,
                t.superficie_m2,
                c.nombre AS comuna_nombre
            FROM dbo.ct_terreno t
            INNER JOIN dbo.ct_comuna c ON c.id_comuna = t.id_comuna
            ORDER BY t.rol_asignado, t.id_terreno";
    $stmt = $conn->query($sql);
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListTercerosSelector(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_tercero, nombre_razon_social, rut FROM dbo.ct_tercero ORDER BY nombre_razon_social');
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoListTasacionesSelector(PDO $conn): array
{
    $sql = "SELECT
                t.id_tasacion,
                t.id_terreno,
                tr.rol_asignado,
                t.fecha_tasacion,
                t.valor_total_uf
            FROM dbo.ct_tasacion_terreno t
            INNER JOIN dbo.ct_terreno tr ON tr.id_terreno = t.id_terreno
            ORDER BY t.fecha_tasacion DESC, t.id_tasacion DESC";
    $stmt = $conn->query($sql);
    if (!($stmt instanceof PDOStatement)) {
        return [];
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ctComercialRepoTerrenoExists(PDO $conn, int $idTerreno): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_terreno WHERE id_terreno = :id_terreno');
    if (!($stmt instanceof PDOStatement)) {
        return false;
    }
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctComercialRepoFindTerrenoSuperficie(PDO $conn, int $idTerreno): float
{
    $stmt = $conn->prepare('SELECT superficie_m2 FROM dbo.ct_terreno WHERE id_terreno = :id_terreno');
    if (!($stmt instanceof PDOStatement)) {
        return 0.0;
    }
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return is_numeric((string) $value) ? (float) $value : 0.0;
}

function ctComercialRepoTipoTasacionExists(PDO $conn, int $idTipoTasacion): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tipo_tasacion WHERE id_tipo_tasacion = :id_tipo_tasacion');
    if (!($stmt instanceof PDOStatement)) {
        return false;
    }
    $stmt->bindValue(':id_tipo_tasacion', $idTipoTasacion, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctComercialRepoEntidadFinancieraExists(PDO $conn, int $idEntidadFinanciera): bool
{
    $existsStmt = $conn->query("SELECT OBJECT_ID('dbo.ct_entidad_financiera', 'U')");
    if (!($existsStmt instanceof PDOStatement)) {
        return false;
    }
    $tableId = (int) $existsStmt->fetchColumn();
    if ($tableId <= 0) {
        return false;
    }

    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_entidad_financiera WHERE id_entidad_financiera = :id');
    if (!($stmt instanceof PDOStatement)) {
        return false;
    }
    $stmt->bindValue(':id', $idEntidadFinanciera, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctComercialRepoTerceroExists(PDO $conn, int $idTercero): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tercero WHERE id_tercero = :id_tercero');
    if (!($stmt instanceof PDOStatement)) {
        return false;
    }
    $stmt->bindValue(':id_tercero', $idTercero, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctComercialRepoTasacionBelongsToTerreno(PDO $conn, int $idTasacion, int $idTerreno): bool
{
    $stmt = $conn->prepare('SELECT TOP (1) 1 FROM dbo.ct_tasacion_terreno WHERE id_tasacion = :id_tasacion AND id_terreno = :id_terreno');
    if (!($stmt instanceof PDOStatement)) {
        return false;
    }
    $stmt->bindValue(':id_tasacion', $idTasacion, PDO::PARAM_INT);
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function ctComercialRepoInsertTasacion(PDO $conn, array $payload): int
{
    $conn->beginTransaction();
    try {
        $sql = 'INSERT INTO dbo.ct_tasacion_terreno (
                    id_terreno,
                    id_tipo_tasacion,
                    fecha_tasacion,
                    valor_total_uf,
                    valor_uf_m2,
                    id_entidad_financiera,
                    es_referencial,
                    vigente_desde,
                    vigente_hasta,
                    id_usuario
                ) VALUES (
                    :id_terreno,
                    :id_tipo_tasacion,
                    :fecha_tasacion,
                    :valor_total_uf,
                    :valor_uf_m2,
                    :id_entidad_financiera,
                    :es_referencial,
                    :vigente_desde,
                    :vigente_hasta,
                    :id_usuario
                )';

        $stmt = $conn->prepare($sql);
        if (!($stmt instanceof PDOStatement)) {
            throw new RuntimeException('No fue posible registrar la tasación.');
        }

        $stmt->bindValue(':id_terreno', (int) $payload['id_terreno'], PDO::PARAM_INT);
        $stmt->bindValue(':id_tipo_tasacion', (int) $payload['id_tipo_tasacion'], PDO::PARAM_INT);
        $stmt->bindValue(':fecha_tasacion', (string) $payload['fecha_tasacion'], PDO::PARAM_STR);
        $stmt->bindValue(':valor_total_uf', (float) $payload['valor_total_uf']);
        $stmt->bindValue(':valor_uf_m2', $payload['valor_uf_m2'] !== null ? (float) $payload['valor_uf_m2'] : null);
        $stmt->bindValue(
            ':id_entidad_financiera',
            $payload['id_entidad_financiera'],
            $payload['id_entidad_financiera'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmt->bindValue(':es_referencial', !empty($payload['es_referencial']) ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':vigente_desde', $payload['vigente_desde'], $payload['vigente_desde'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':vigente_hasta', $payload['vigente_hasta'], $payload['vigente_hasta'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id_usuario', (int) $payload['id_usuario'], PDO::PARAM_INT);
        $stmt->execute();

        $idTasacion = (int) $conn->lastInsertId();

        $idEstadoDisponible = ctComercialRepoFindEstadoComercialDisponible($conn);
        if ($idEstadoDisponible <= 0) {
            throw new RuntimeException('No fue posible resolver el estado comercial "Disponible".');
        }
        ctComercialRepoUpdateEstadoComercialConHistorial(
            $conn,
            (int) $payload['id_terreno'],
            $idEstadoDisponible,
            null,
            (int) $payload['id_usuario']
        );

        $conn->commit();
        return $idTasacion;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}

function ctComercialRepoFindEstadoComercialVendido(PDO $conn): int
{
    $sql = "SELECT TOP (1) id_estado_comercial
            FROM dbo.ct_estado_terreno_comercial
            WHERE UPPER(nombre) LIKE '%VEND%'
            ORDER BY CASE WHEN UPPER(nombre) = 'VENDIDO' THEN 0 ELSE 1 END, id_estado_comercial";
    $stmt = $conn->query($sql);
    if (!($stmt instanceof PDOStatement)) {
        return 0;
    }
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function ctComercialRepoFindEstadoComercialDisponible(PDO $conn): int
{
    $sql = "SELECT TOP (1) id_estado_comercial
            FROM dbo.ct_estado_terreno_comercial
            WHERE UPPER(nombre) LIKE '%DISPONIBLE%'
            ORDER BY CASE WHEN UPPER(nombre) = 'DISPONIBLE' THEN 0 ELSE 1 END, id_estado_comercial";
    $stmt = $conn->query($sql);
    if (!($stmt instanceof PDOStatement)) {
        return 0;
    }
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function ctComercialRepoFindTerrenoEstadoComercialId(PDO $conn, int $idTerreno): int
{
    $stmt = $conn->prepare('SELECT id_estado_comercial FROM dbo.ct_terreno WHERE id_terreno = :id_terreno');
    if (!($stmt instanceof PDOStatement)) {
        return 0;
    }
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function ctComercialRepoFindEstadoPredialNoDisponible(PDO $conn): int
{
    $sql = "SELECT TOP (1) id_estado_predial
            FROM dbo.ct_estado_terreno_predial
            WHERE UPPER(nombre) LIKE '%NO DISPONIBLE%'
            ORDER BY CASE WHEN UPPER(nombre) = 'NO DISPONIBLE' THEN 0 ELSE 1 END, id_estado_predial";
    $stmt = $conn->query($sql);
    if (!($stmt instanceof PDOStatement)) {
        return 0;
    }
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function ctComercialRepoFindTerrenoEstadoPredialId(PDO $conn, int $idTerreno): int
{
    $stmt = $conn->prepare('SELECT id_estado_predial FROM dbo.ct_terreno WHERE id_terreno = :id_terreno');
    if (!($stmt instanceof PDOStatement)) {
        return 0;
    }
    $stmt->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

function ctComercialRepoUpdateEstadoComercialConHistorial(PDO $conn, int $idTerreno, int $idEstadoNuevo, ?int $idVenta, int $idUsuario): void
{
    $idEstadoAnterior = ctComercialRepoFindTerrenoEstadoComercialId($conn, $idTerreno);
    if ($idEstadoAnterior <= 0 || $idEstadoAnterior === $idEstadoNuevo) {
        return;
    }

    $stmtUpdate = $conn->prepare('UPDATE dbo.ct_terreno SET id_estado_comercial = :id_estado_nuevo WHERE id_terreno = :id_terreno');
    if (!($stmtUpdate instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible actualizar el estado comercial del terreno.');
    }
    $stmtUpdate->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmtUpdate->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtUpdate->execute();

    $sqlHist = 'INSERT INTO dbo.ct_historial_estado_terreno (
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
                    SYSUTCDATETIME(),
                    :id_venta,
                    NULL,
                    :id_usuario,
                    :tipo_estado
                )';

    $stmtHist = $conn->prepare($sqlHist);
    if (!($stmtHist instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible registrar historial de estado comercial.');
    }

    $stmtHist->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_estado_anterior', $idEstadoAnterior, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_venta', $idVenta, $idVenta === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmtHist->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmtHist->bindValue(':tipo_estado', 'C', PDO::PARAM_STR);
    $stmtHist->execute();
}

function ctComercialRepoUpdateEstadoPredialConHistorial(PDO $conn, int $idTerreno, int $idEstadoNuevo, int $idVenta, int $idUsuario): void
{
    $idEstadoAnterior = ctComercialRepoFindTerrenoEstadoPredialId($conn, $idTerreno);
    if ($idEstadoAnterior <= 0 || $idEstadoAnterior === $idEstadoNuevo) {
        return;
    }

    $stmtUpdate = $conn->prepare('UPDATE dbo.ct_terreno SET id_estado_predial = :id_estado_nuevo WHERE id_terreno = :id_terreno');
    if (!($stmtUpdate instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible actualizar el estado predial del terreno.');
    }
    $stmtUpdate->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmtUpdate->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtUpdate->execute();

    $sqlHist = 'INSERT INTO dbo.ct_historial_estado_terreno (
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
                    SYSUTCDATETIME(),
                    :id_venta,
                    NULL,
                    :id_usuario,
                    :tipo_estado
                )';

    $stmtHist = $conn->prepare($sqlHist);
    if (!($stmtHist instanceof PDOStatement)) {
        throw new RuntimeException('No fue posible registrar historial de estado predial.');
    }

    $stmtHist->bindValue(':id_terreno', $idTerreno, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_estado_anterior', $idEstadoAnterior, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_estado_nuevo', $idEstadoNuevo, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_venta', $idVenta, PDO::PARAM_INT);
    $stmtHist->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmtHist->bindValue(':tipo_estado', 'P', PDO::PARAM_STR);
    $stmtHist->execute();
}

function ctComercialRepoInsertVenta(PDO $conn, array $payload): int
{
    $conn->beginTransaction();
    try {
        $sqlVenta = 'INSERT INTO dbo.ct_venta_terreno (
                        id_terreno,
                        fecha_venta,
                        valor_total_uf,
                        valor_venta_uf_m2,
                        id_tasacion_referencial
                     ) VALUES (
                        :id_terreno,
                        :fecha_venta,
                        :valor_total_uf,
                        :valor_venta_uf_m2,
                        :id_tasacion_referencial
                     )';

        $stmtVenta = $conn->prepare($sqlVenta);
        if (!($stmtVenta instanceof PDOStatement)) {
            throw new RuntimeException('No fue posible registrar la venta del terreno.');
        }

        $stmtVenta->bindValue(':id_terreno', (int) $payload['id_terreno'], PDO::PARAM_INT);
        $stmtVenta->bindValue(':fecha_venta', (string) $payload['fecha_venta'], PDO::PARAM_STR);
        $stmtVenta->bindValue(':valor_total_uf', (float) $payload['valor_total_uf']);
        $stmtVenta->bindValue(':valor_venta_uf_m2', $payload['valor_venta_uf_m2'] !== null ? (float) $payload['valor_venta_uf_m2'] : null);
        $stmtVenta->bindValue(
            ':id_tasacion_referencial',
            $payload['id_tasacion_referencial'],
            $payload['id_tasacion_referencial'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmtVenta->execute();

        $idVenta = (int) $conn->lastInsertId();

        $sqlVentaTercero = 'INSERT INTO dbo.ct_venta_terreno_tercero (
                                id_venta,
                                id_tercero,
                                porcentaje,
                                rol_en_venta
                            ) VALUES (
                                :id_venta,
                                :id_tercero,
                                :porcentaje,
                                :rol_en_venta
                            )';

        $stmtVentaTercero = $conn->prepare($sqlVentaTercero);
        if (!($stmtVentaTercero instanceof PDOStatement)) {
            throw new RuntimeException('No fue posible registrar los compradores de la venta.');
        }

        foreach ($payload['compradores'] as $comprador) {
            $stmtVentaTercero->bindValue(':id_venta', $idVenta, PDO::PARAM_INT);
            $stmtVentaTercero->bindValue(':id_tercero', (int) $comprador['id_tercero'], PDO::PARAM_INT);
            $stmtVentaTercero->bindValue(':porcentaje', (float) $comprador['porcentaje']);
            $stmtVentaTercero->bindValue(':rol_en_venta', $comprador['rol_en_venta'], $comprador['rol_en_venta'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmtVentaTercero->execute();
        }

        $idEstadoVendido = ctComercialRepoFindEstadoComercialVendido($conn);
        if ($idEstadoVendido > 0) {
            ctComercialRepoUpdateEstadoComercialConHistorial(
                $conn,
                (int) $payload['id_terreno'],
                $idEstadoVendido,
                $idVenta,
                (int) $payload['id_usuario']
            );
        }

        $idEstadoNoDisponible = ctComercialRepoFindEstadoPredialNoDisponible($conn);
        if ($idEstadoNoDisponible <= 0) {
            throw new RuntimeException('No fue posible resolver el estado predial "No disponible".');
        }
        ctComercialRepoUpdateEstadoPredialConHistorial(
            $conn,
            (int) $payload['id_terreno'],
            $idEstadoNoDisponible,
            $idVenta,
            (int) $payload['id_usuario']
        );

        $conn->commit();
        return $idVenta;
    } catch (Throwable $exception) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $exception;
    }
}
