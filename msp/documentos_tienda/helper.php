<?php
declare(strict_types=1);

function msp2DocumentosTiendaPermissions(): array
{
    return [
        'MSP Documentos Tienda Carga',
        'MSP Documentos Tienda Revision',
        'MSP Documentos Tienda Aprobacion',
    ];
}

function msp2DocumentosTiendaRequireAny(string $action = 'lectura'): void
{
    msp2RequireAnyAccess(msp2DocumentosTiendaPermissions(), $action);
}

function msp2DocumentosTiendaCan(string $permission, string $action = 'lectura'): bool
{
    return in_array($permission, msp2DocumentosTiendaPermissions(), true)
        && msp2CurrentUserHasPermission($permission, $action);
}

function msp2DocumentosTiendaAssertEditable(array $batch): void
{
    if ((string) ($batch['estado_lote'] ?? '') === 'CERRADO') {
        throw new RuntimeException('El lote está cerrado. Debe reabrirse antes de modificarlo.');
    }
}

function msp2DocumentosTiendaRoot(): string
{
    static $root = null;
    if (is_string($root)) {
        return $root;
    }

    $custom = [];
    $configPath = dirname(__DIR__) . '/config/storage.php';
    if (is_file($configPath)) {
        $loaded = require $configPath;
        $custom = is_array($loaded) ? $loaded : [];
    }
    $configured = trim((string) ($custom['documentos_tienda_root'] ?? ''));
    $root = rtrim(
        $configured !== ''
            ? $configured
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'msp_storage' . DIRECTORY_SEPARATOR . 'documentos_tienda',
        "\\/"
    );
    return $root;
}

function msp2DocumentosTiendaEnsureDir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('No fue posible crear el almacenamiento seguro de documentos.');
    }
}

function msp2DocumentosTiendaAbsolutePath(string $relativePath): string
{
    $relative = str_replace('\\', '/', trim($relativePath));
    if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
        throw new RuntimeException('La ruta almacenada del documento no es válida.');
    }

    $root = msp2DocumentosTiendaRoot();
    $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $rootNormalized = strtolower(str_replace('\\', '/', rtrim($root, "\\/"))) . '/';
    $candidateNormalized = strtolower(str_replace('\\', '/', $candidate));
    if (!str_starts_with($candidateNormalized, $rootNormalized)) {
        throw new RuntimeException('El documento está fuera del almacenamiento autorizado.');
    }
    return $candidate;
}

function msp2DocumentosTiendaUserId(): ?int
{
    $id = (int) ($_SESSION['usuario']['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function msp2DocumentosTiendaEvent(
    PDO $conn,
    int $batchId,
    ?int $fileId,
    string $type,
    ?string $detail = null
): void {
    $stmt = $conn->prepare(
        'INSERT INTO dbo.msp_documentos_tienda_eventos
            (id_lote_documentos_tienda,id_documento_tienda_archivo,tipo_evento,detalle_evento,id_usuario)
         VALUES (:lote,:archivo,:tipo,:detalle,:usuario)'
    );
    $stmt->execute([
        ':lote' => $batchId,
        ':archivo' => $fileId,
        ':tipo' => $type,
        ':detalle' => $detail !== null ? mb_substr($detail, 0, 1000, 'UTF-8') : null,
        ':usuario' => msp2DocumentosTiendaUserId(),
    ]);
}

function msp2DocumentosTiendaFetchBatch(PDO $conn, int $batchId): ?array
{
    $stmt = $conn->prepare(
        'SELECT l.*,
                COUNT(a.id_documento_tienda_archivo) AS total_archivos,
                SUM(CASE WHEN a.estado_archivo=N\'PENDIENTE\' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN a.estado_archivo=N\'ASOCIADO\' THEN 1 ELSE 0 END) AS asociados,
                SUM(CASE WHEN a.estado_archivo=N\'ENVIADO\' THEN 1 ELSE 0 END) AS enviados,
                SUM(CASE WHEN a.estado_archivo=N\'ERROR\' THEN 1 ELSE 0 END) AS errores,
                SUM(CASE WHEN a.estado_archivo=N\'OMITIDO\' THEN 1 ELSE 0 END) AS omitidos
         FROM dbo.msp_documentos_tienda_lotes l
         LEFT JOIN dbo.msp_documentos_tienda_archivos a
           ON a.id_lote_documentos_tienda=l.id_lote_documentos_tienda
         WHERE l.id_lote_documentos_tienda=:id
         GROUP BY l.id_lote_documentos_tienda,l.nombre_lote,l.estado_lote,l.id_usuario_creador,l.fecha_registro,l.updated_at,
                  l.estado_antes_cierre,l.fecha_cierre,l.id_usuario_cierre'
    );
    $stmt->execute([':id' => $batchId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function msp2DocumentosTiendaFetchFiles(PDO $conn, int $batchId): array
{
    $stmt = $conn->prepare(
        "SELECT a.*, t.nombre_comercial,
                COALESCE(NULLIF(LTRIM(RTRIM(ar.nombre_locatario)),N''),NULLIF(LTRIM(RTRIM(ar.nombre_representante)),N''),ar.rut) AS nombre_arrendatario,
                ar.rut,
                locales.locales_label
         FROM dbo.msp_documentos_tienda_archivos a
         LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=a.id_tienda
         LEFT JOIN dbo.msp_arrendatarios ar ON ar.id_arrendatario=a.id_arrendatario
         OUTER APPLY (
            SELECT STUFF((SELECT N' / '+l.cdo_local
                           FROM dbo.msp_contrato_locales cl
                           INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                           WHERE cl.id_contrato_arriendo=a.id_contrato_arriendo
                           ORDER BY cl.orden_visual,cl.id_contrato_local
                           FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') AS locales_label
         ) locales
         WHERE a.id_lote_documentos_tienda=:lote
         ORDER BY a.id_documento_tienda_archivo"
    );
    $stmt->execute([':lote' => $batchId]);
    return $stmt->fetchAll() ?: [];
}

function msp2DocumentosTiendaFetchFilesPage(
    PDO $conn,
    int $batchId,
    string $query = '',
    string $status = '',
    int $page = 1,
    int $perPage = 20
): array {
    $allowedStatuses = ['PENDIENTE', 'ASOCIADO', 'ENVIADO', 'ERROR', 'OMITIDO'];
    $query = msp2NormalizeText($query);
    $status = strtoupper(trim($status));
    if (!in_array($status, $allowedStatuses, true)) {
        $status = '';
    }
    $perPage = in_array($perPage, [10, 20, 50], true) ? $perPage : 20;

    $fromSql = " FROM dbo.msp_documentos_tienda_archivos a
                 LEFT JOIN dbo.msp_tiendas t ON t.id_tienda=a.id_tienda
                 LEFT JOIN dbo.msp_arrendatarios ar ON ar.id_arrendatario=a.id_arrendatario
                 OUTER APPLY (
                    SELECT STUFF((SELECT N' / '+l.cdo_local
                                   FROM dbo.msp_contrato_locales cl
                                   INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                                   WHERE cl.id_contrato_arriendo=a.id_contrato_arriendo
                                   ORDER BY cl.orden_visual,cl.id_contrato_local
                                   FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') AS locales_label
                 ) locales";
    $conditions = ['a.id_lote_documentos_tienda=?'];
    $params = [$batchId];
    if ($status !== '') {
        $conditions[] = 'a.estado_archivo=?';
        $params[] = $status;
    }
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $pattern = '%' . $escaped . '%';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $pattern;
        }
        $conditions[] = "(a.nombre_original LIKE ? ESCAPE '\\'
                         OR ISNULL(t.nombre_comercial,N'') LIKE ? ESCAPE '\\'
                         OR ISNULL(ar.nombre_locatario,N'') LIKE ? ESCAPE '\\'
                         OR ISNULL(ar.nombre_representante,N'') LIKE ? ESCAPE '\\'
                         OR ISNULL(ar.rut,N'') LIKE ? ESCAPE '\\'
                         OR ISNULL(locales.locales_label,N'') LIKE ? ESCAPE '\\'
                         OR CAST(ISNULL(a.id_contrato_arriendo,0) AS NVARCHAR(20)) LIKE ? ESCAPE '\\')";
    }
    $whereSql = ' WHERE ' . implode(' AND ', $conditions);

    $countStmt = $conn->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT a.*,t.nombre_comercial,
                   COALESCE(NULLIF(LTRIM(RTRIM(ar.nombre_locatario)),N''),NULLIF(LTRIM(RTRIM(ar.nombre_representante)),N''),ar.rut) AS nombre_arrendatario,
                   ar.rut,locales.locales_label"
        . $fromSql . $whereSql
        . ' ORDER BY a.id_documento_tienda_archivo OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
    $stmt = $conn->prepare($sql);
    foreach (array_values($params) as $position => $value) {
        $stmt->bindValue($position + 1, $value, $position === 0 ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(count($params) + 1, $offset, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
        'query' => $query,
        'status' => $status,
    ];
}

function msp2DocumentosTiendaFetchContracts(PDO $conn): array
{
    $sql = "SELECT c.id_contrato_arriendo,c.id_tienda,c.id_arrendatario,c.estado_contrato,
                   t.nombre_comercial,
                   COALESCE(NULLIF(LTRIM(RTRIM(a.nombre_locatario)),N''),NULLIF(LTRIM(RTRIM(a.nombre_representante)),N''),a.rut) AS nombre_arrendatario,
                   a.rut, correo.correo, locales.locales_label
            FROM dbo.msp_contratos_arriendo c
            INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
            INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
            OUTER APPLY (
                SELECT TOP (1) ac.correo
                FROM dbo.msp_arrendatarios_correos ac
                WHERE ac.id_arrendatario=c.id_arrendatario
                ORDER BY ac.es_principal DESC,ac.id_arrendatario_correo
            ) correo
            OUTER APPLY (
                SELECT STUFF((SELECT N' / '+l.cdo_local
                              FROM dbo.msp_contrato_locales cl
                              INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                              WHERE cl.id_contrato_arriendo=c.id_contrato_arriendo
                              ORDER BY CASE WHEN cl.estado_relacion=1 THEN 0 ELSE 1 END,cl.orden_visual,cl.id_contrato_local
                              FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') AS locales_label
            ) locales
            WHERE c.estado_contrato IN (1,2,3,4)
            ORDER BY CASE WHEN c.estado_contrato IN (2,3) THEN 0 ELSE 1 END,t.nombre_comercial,c.id_contrato_arriendo DESC";
    return $conn->query($sql)->fetchAll() ?: [];
}

function msp2DocumentosTiendaFetchContract(PDO $conn, int $contractId): ?array
{
    foreach (msp2DocumentosTiendaFetchContracts($conn) as $row) {
        if ((int) ($row['id_contrato_arriendo'] ?? 0) === $contractId) {
            return $row;
        }
    }
    return null;
}

function msp2DocumentosTiendaRefreshBatch(PDO $conn, int $batchId): string
{
    $stateStmt = $conn->prepare('SELECT estado_lote FROM dbo.msp_documentos_tienda_lotes WHERE id_lote_documentos_tienda=:lote');
    $stateStmt->execute([':lote' => $batchId]);
    $currentState = (string) ($stateStmt->fetchColumn() ?: '');
    if ($currentState === 'CERRADO') {
        return 'CERRADO';
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN estado_archivo=N'PENDIENTE' THEN 1 ELSE 0 END) pendientes,
                SUM(CASE WHEN estado_archivo=N'ASOCIADO' THEN 1 ELSE 0 END) asociados,
                SUM(CASE WHEN estado_archivo=N'ENVIADO' THEN 1 ELSE 0 END) enviados,
                SUM(CASE WHEN estado_archivo=N'ERROR' THEN 1 ELSE 0 END) errores,
                SUM(CASE WHEN estado_archivo=N'OMITIDO' THEN 1 ELSE 0 END) omitidos
         FROM dbo.msp_documentos_tienda_archivos WHERE id_lote_documentos_tienda=:lote"
    );
    $stmt->execute([':lote' => $batchId]);
    $counts = $stmt->fetch() ?: [];
    $total = (int) ($counts['total'] ?? 0);
    $pending = (int) ($counts['pendientes'] ?? 0);
    $associated = (int) ($counts['asociados'] ?? 0);
    $sent = (int) ($counts['enviados'] ?? 0);
    $errors = (int) ($counts['errores'] ?? 0);
    $omitted = (int) ($counts['omitidos'] ?? 0);

    $state = 'BORRADOR';
    if ($total > 0 && $sent + $omitted === $total) {
        $state = 'COMPLETADO';
    } elseif ($sent > 0 || $errors > 0) {
        $state = 'PARCIAL';
    } elseif ($pending === 0 && $associated > 0) {
        $state = 'LISTO';
    } elseif ($total > 0) {
        $state = 'EN_REVISION';
    }

    $update = $conn->prepare(
        'UPDATE dbo.msp_documentos_tienda_lotes SET estado_lote=:estado,updated_at=SYSDATETIME()
         WHERE id_lote_documentos_tienda=:lote'
    );
    $update->execute([':estado' => $state, ':lote' => $batchId]);
    return $state;
}

function msp2DocumentosTiendaFetchEventsPage(PDO $conn, int $batchId, int $page = 1, int $perPage = 25): array
{
    $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    $countStmt = $conn->prepare(
        'SELECT COUNT(*) FROM dbo.msp_documentos_tienda_eventos WHERE id_lote_documentos_tienda=:lote'
    );
    $countStmt->execute([':lote' => $batchId]);
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $conn->prepare(
        'SELECT e.*,a.nombre_original,u.UserName AS usuario
         FROM dbo.msp_documentos_tienda_eventos e
         LEFT JOIN dbo.msp_documentos_tienda_archivos a ON a.id_documento_tienda_archivo=e.id_documento_tienda_archivo
         LEFT JOIN dbo.cr_usuarios u ON u.id=e.id_usuario
         WHERE e.id_lote_documentos_tienda=:lote
         ORDER BY e.fecha_evento DESC,e.id_documento_tienda_evento DESC
         OFFSET :offset ROWS FETCH NEXT :limite ROWS ONLY'
    );
    $stmt->bindValue(':lote', $batchId, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    return [
        'rows' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'per_page' => $perPage,
    ];
}

function msp2DocumentosTiendaFormatBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return number_format($bytes / 1024, 1, ',', '.') . ' KB';
}
