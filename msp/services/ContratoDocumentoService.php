<?php
declare(strict_types=1);

final class ContratoDocumentoService
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    private const TIPOS = [
        'CONTRATO' => 'Contrato',
        'ANEXO' => 'Anexo',
        'FINIQUITO' => 'Finiquito',
        'CERTIFICADO' => 'Certificado',
        'ACTA' => 'Acta',
        'OTRO' => 'Otro',
    ];

    private const COLUMNAS_REQUERIDAS = [
        'ruta_relativa',
        'mime_type',
        'hash_sha256',
        'bytes_archivo',
        'fecha_documento',
        'id_documento_reemplazado',
        'fecha_actualizacion',
        'fecha_anulacion',
        'id_usuario_anulacion',
        'motivo_anulacion',
    ];

    public static function tipos(): array
    {
        return self::TIPOS;
    }

    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public static function estructuraDisponible(PDO $conn): bool
    {
        if (!msp2TableExists($conn, 'msp_centro_documental') || !msp2TableExists($conn, 'msp_contratos_arriendo')) {
            return false;
        }
        foreach (self::COLUMNAS_REQUERIDAS as $columna) {
            if (!msp2ColumnExists($conn, 'msp_centro_documental', $columna)) {
                return false;
            }
        }
        return true;
    }

    public static function cargar(
        PDO $conn,
        int $idContrato,
        array $archivo,
        string $tipoDocumento,
        ?string $fechaDocumento,
        ?string $descripcion,
        int $idUsuario,
        ?int $idLocal = null,
        ?int $idDocumentoReemplazado = null
    ): array {
        self::requerirEstructura($conn);
        if ($idContrato <= 0 || $idUsuario <= 0) {
            throw new RuntimeException('No fue posible identificar el contrato o el usuario responsable.');
        }

        $tipo = strtoupper(trim($tipoDocumento));
        if (!isset(self::TIPOS[$tipo])) {
            throw new RuntimeException('Selecciona un tipo documental válido.');
        }
        $fecha = self::normalizarFecha($fechaDocumento);
        $detalle = self::normalizarDescripcion($descripcion);
        $meta = self::validarPdfCargado($archivo);
        $directorioRaiz = self::asegurarDirectorioRaiz();

        $transaccionPropia = !$conn->inTransaction();
        $rutaAbsoluta = null;
        if ($transaccionPropia) {
            $conn->beginTransaction();
        }
        try {
            $contrato = self::cargarContratoBloqueado($conn, $idContrato);
            self::validarContratoAdministrable($contrato);
            $idArrendatario = (int) ($contrato['id_arrendatario'] ?? 0);
            if ($idArrendatario <= 0) {
                throw new RuntimeException('El contrato no posee un arrendatario válido.');
            }
            if ($idLocal !== null) {
                self::validarLocalContrato($conn, $idContrato, $idLocal);
            }

            $documentoAnterior = null;
            if ($idDocumentoReemplazado !== null) {
                $documentoAnterior = self::cargarDocumentoBloqueado($conn, $idDocumentoReemplazado, $idContrato);
                if ($documentoAnterior === null || strtoupper((string) ($documentoAnterior['estado'] ?? '')) !== 'ACTIVO') {
                    throw new RuntimeException('El documento que intentas reemplazar ya no está disponible.');
                }
                $stmtAnularAnterior = $conn->prepare(
                    "UPDATE dbo.msp_centro_documental
                     SET estado=N'ANULADO',fecha_anulacion=SYSDATETIME(),id_usuario_anulacion=:usuario,
                         motivo_anulacion=N'Reemplazado por una nueva versión.',fecha_actualizacion=SYSDATETIME()
                     WHERE id_documento=:documento AND id_contrato_arriendo=:contrato AND estado=N'ACTIVO'"
                );
                $stmtAnularAnterior->execute([
                    ':usuario' => $idUsuario,
                    ':documento' => $idDocumentoReemplazado,
                    ':contrato' => $idContrato,
                ]);
                if ($stmtAnularAnterior->rowCount() !== 1) {
                    throw new RuntimeException('El documento cambió mientras se intentaba reemplazar.');
                }
            }

            $subdirectorio = 'contrato_' . $idContrato
                . '/' . date('Y')
                . '/' . date('m');
            $directorioDestino = $directorioRaiz . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $subdirectorio);
            self::asegurarDirectorio($directorioDestino);
            $nombreAlmacenado = bin2hex(random_bytes(20)) . '.pdf';
            $rutaAbsoluta = $directorioDestino . DIRECTORY_SEPARATOR . $nombreAlmacenado;
            if (!move_uploaded_file((string) $meta['ruta_temporal'], $rutaAbsoluta)) {
                throw new RuntimeException('No fue posible almacenar el PDF en la carpeta segura.');
            }
            @chmod($rutaAbsoluta, 0640);

            $hash = hash_file('sha256', $rutaAbsoluta);
            if (!is_string($hash) || strlen($hash) !== 64) {
                throw new RuntimeException('No fue posible calcular la integridad del PDF.');
            }
            $rutaRelativa = $subdirectorio . '/' . $nombreAlmacenado;

            $stmtInsertar = $conn->prepare(
                "INSERT dbo.msp_centro_documental (
                    id_contrato_arriendo,id_arrendatario,id_local,nombre_archivo,
                    ruta_archivo,ruta_relativa,tipo_documento,descripcion,fecha_documento,
                    mime_type,hash_sha256,bytes_archivo,id_documento_reemplazado,
                    estado,id_usuario,fecha_registro,fecha_actualizacion
                 )
                 OUTPUT INSERTED.id_documento
                 VALUES (
                    :contrato,:arrendatario,:local,:nombre,
                    :ruta_archivo,:ruta_relativa,:tipo,:descripcion,:fecha_documento,
                    N'application/pdf',:hash,:bytes,:reemplazado,
                    N'ACTIVO',:usuario,SYSDATETIME(),SYSDATETIME()
                 )"
            );
            $stmtInsertar->bindValue(':contrato', $idContrato, PDO::PARAM_INT);
            $stmtInsertar->bindValue(':arrendatario', $idArrendatario, PDO::PARAM_INT);
            self::bindNullableInt($stmtInsertar, ':local', $idLocal);
            $stmtInsertar->bindValue(':nombre', (string) $meta['nombre_original'], PDO::PARAM_STR);
            $stmtInsertar->bindValue(':ruta_archivo', $rutaRelativa, PDO::PARAM_STR);
            $stmtInsertar->bindValue(':ruta_relativa', $rutaRelativa, PDO::PARAM_STR);
            $stmtInsertar->bindValue(':tipo', $tipo, PDO::PARAM_STR);
            self::bindNullableString($stmtInsertar, ':descripcion', $detalle);
            self::bindNullableString($stmtInsertar, ':fecha_documento', $fecha);
            $stmtInsertar->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmtInsertar->bindValue(':bytes', (int) $meta['bytes'], PDO::PARAM_INT);
            self::bindNullableInt($stmtInsertar, ':reemplazado', $idDocumentoReemplazado);
            $stmtInsertar->bindValue(':usuario', $idUsuario, PDO::PARAM_INT);
            $stmtInsertar->execute();
            $idDocumento = (int) ($stmtInsertar->fetchColumn() ?: 0);
            if ($idDocumento <= 0) {
                throw new RuntimeException('No fue posible registrar la metadata del PDF.');
            }

            if ($idDocumentoReemplazado !== null) {
                $stmtMotivo = $conn->prepare(
                    "UPDATE dbo.msp_centro_documental
                     SET motivo_anulacion=:motivo,fecha_actualizacion=SYSDATETIME()
                     WHERE id_documento=:documento AND estado=N'ANULADO'"
                );
                $stmtMotivo->execute([
                    ':motivo' => 'Reemplazado por documento #' . $idDocumento . '.',
                    ':documento' => $idDocumentoReemplazado,
                ]);
            }

            self::registrarHistorial($conn, $idContrato, $idUsuario, 'DOCUMENTO_ADJUNTO_CARGADO', [
                'id_documento' => $idDocumento,
                'id_documento_reemplazado' => $idDocumentoReemplazado,
                'tipo_documento' => $tipo,
                'nombre_archivo' => (string) $meta['nombre_original'],
                'bytes_archivo' => (int) $meta['bytes'],
                'hash_sha256' => $hash,
            ]);

            if ($transaccionPropia) {
                $conn->commit();
            }
        } catch (Throwable $error) {
            if ($transaccionPropia && $conn->inTransaction()) {
                $conn->rollBack();
            }
            if (is_string($rutaAbsoluta) && $rutaAbsoluta !== '' && is_file($rutaAbsoluta)) {
                @unlink($rutaAbsoluta);
            }
            if ($error instanceof PDOException && (string) $error->getCode() === '23000') {
                throw new RuntimeException('Este PDF ya se encuentra adjunto al contrato.', 0, $error);
            }
            throw $error;
        }

        return [
            'id_documento' => $idDocumento,
            'id_contrato_arriendo' => $idContrato,
            'tipo_documento' => $tipo,
            'nombre_archivo' => (string) $meta['nombre_original'],
            'bytes_archivo' => (int) $meta['bytes'],
            'hash_sha256' => $hash,
            'reemplazo' => $idDocumentoReemplazado !== null,
        ];
    }

    public static function anular(
        PDO $conn,
        int $idContrato,
        int $idDocumento,
        string $motivo,
        int $idUsuario
    ): void {
        self::requerirEstructura($conn);
        $motivo = trim($motivo);
        if ($idContrato <= 0 || $idDocumento <= 0 || $idUsuario <= 0) {
            throw new RuntimeException('No fue posible identificar el documento que deseas anular.');
        }
        if ($motivo === '') {
            throw new RuntimeException('Debes indicar el motivo de anulación.');
        }
        if (mb_strlen($motivo) > 500) {
            throw new RuntimeException('El motivo de anulación no puede superar 500 caracteres.');
        }

        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) {
            $conn->beginTransaction();
        }
        try {
            $contrato = self::cargarContratoBloqueado($conn, $idContrato);
            self::validarContratoAdministrable($contrato);
            $documento = self::cargarDocumentoBloqueado($conn, $idDocumento, $idContrato);
            if ($documento === null || strtoupper((string) ($documento['estado'] ?? '')) !== 'ACTIVO') {
                throw new RuntimeException('El documento ya no está disponible para anulación.');
            }
            $stmt = $conn->prepare(
                "UPDATE dbo.msp_centro_documental
                 SET estado=N'ANULADO',fecha_anulacion=SYSDATETIME(),id_usuario_anulacion=:usuario,
                     motivo_anulacion=:motivo,fecha_actualizacion=SYSDATETIME()
                 WHERE id_documento=:documento AND id_contrato_arriendo=:contrato AND estado=N'ACTIVO'"
            );
            $stmt->execute([
                ':usuario' => $idUsuario,
                ':motivo' => $motivo,
                ':documento' => $idDocumento,
                ':contrato' => $idContrato,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('El documento cambió mientras se intentaba anular.');
            }
            self::registrarHistorial($conn, $idContrato, $idUsuario, 'DOCUMENTO_ADJUNTO_ANULADO', [
                'id_documento' => $idDocumento,
                'tipo_documento' => (string) ($documento['tipo_documento'] ?? ''),
                'nombre_archivo' => (string) ($documento['nombre_archivo'] ?? ''),
                'motivo' => $motivo,
            ]);
            if ($transaccionPropia) {
                $conn->commit();
            }
        } catch (Throwable $error) {
            if ($transaccionPropia && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $error;
        }
    }

    public static function obtenerParaDescarga(PDO $conn, int $idDocumento): array
    {
        self::requerirEstructura($conn);
        if ($idDocumento <= 0) {
            throw new RuntimeException('Documento adjunto inválido.');
        }
        $stmt = $conn->prepare(
            "SELECT cd.*,ca.estado_contrato
             FROM dbo.msp_centro_documental cd
             INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo=cd.id_contrato_arriendo
             WHERE cd.id_documento=:documento AND cd.estado=N'ACTIVO'"
        );
        $stmt->execute([':documento' => $idDocumento]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($documento === false) {
            throw new RuntimeException('El documento adjunto no existe o fue anulado.');
        }

        $rutaRelativa = trim((string) ($documento['ruta_relativa'] ?? $documento['ruta_archivo'] ?? ''));
        $rutaAbsoluta = self::resolverRutaSegura($rutaRelativa);
        if (!is_file($rutaAbsoluta)) {
            throw new RuntimeException('El archivo físico del documento no está disponible.');
        }
        $hashEsperado = strtolower(trim((string) ($documento['hash_sha256'] ?? '')));
        $hashActual = hash_file('sha256', $rutaAbsoluta);
        if ($hashEsperado === '' || !is_string($hashActual) || !hash_equals($hashEsperado, strtolower($hashActual))) {
            throw new RuntimeException('El PDF no superó la validación de integridad.');
        }
        $bytes = filesize($rutaAbsoluta);
        if ($bytes === false || $bytes <= 0 || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('El tamaño físico del PDF no coincide con las reglas de almacenamiento.');
        }
        if ((int) ($documento['bytes_archivo'] ?? 0) !== (int) $bytes) {
            throw new RuntimeException('El tamaño del PDF no coincide con su registro de integridad.');
        }
        if (!self::contieneFirmaPdf($rutaAbsoluta)) {
            throw new RuntimeException('El archivo almacenado ya no contiene una firma PDF válida.');
        }

        $documento['ruta_absoluta'] = $rutaAbsoluta;
        $documento['bytes_archivo'] = (int) $bytes;
        return $documento;
    }

    public static function listar(PDO $conn, int $idContrato, bool $incluirAnulados = false): array
    {
        self::requerirEstructura($conn);
        $sql = "SELECT id_documento,id_contrato_arriendo,id_arrendatario,id_local,nombre_archivo,
                       tipo_documento,descripcion,fecha_documento,mime_type,hash_sha256,bytes_archivo,
                       id_documento_reemplazado,estado,id_usuario,fecha_registro,fecha_actualizacion,
                       fecha_anulacion,id_usuario_anulacion,motivo_anulacion
                FROM dbo.msp_centro_documental
                WHERE id_contrato_arriendo=:contrato";
        if (!$incluirAnulados) {
            $sql .= " AND estado=N'ACTIVO'";
        }
        $sql .= ' ORDER BY fecha_registro DESC,id_documento DESC';
        $stmt = $conn->prepare($sql);
        $stmt->execute([':contrato' => $idContrato]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function validarPdfCargado(array $archivo): array
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $mensajes = [
                UPLOAD_ERR_INI_SIZE => 'El PDF supera el tamaño permitido por el servidor.',
                UPLOAD_ERR_FORM_SIZE => 'El PDF supera el máximo de 15 MB.',
                UPLOAD_ERR_PARTIAL => 'La carga del PDF quedó incompleta.',
                UPLOAD_ERR_NO_FILE => 'Debes seleccionar un archivo PDF.',
                UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene disponible la carpeta temporal de carga.',
                UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir temporalmente el PDF.',
                UPLOAD_ERR_EXTENSION => 'Una extensión del servidor bloqueó la carga del PDF.',
            ];
            throw new RuntimeException($mensajes[$error] ?? 'No fue posible recibir el PDF.');
        }
        $rutaTemporal = (string) ($archivo['tmp_name'] ?? '');
        if ($rutaTemporal === '' || !is_uploaded_file($rutaTemporal)) {
            throw new RuntimeException('La carga temporal del PDF no es válida.');
        }
        $bytesFisicos = filesize($rutaTemporal);
        $bytes = $bytesFisicos === false ? 0 : (int) $bytesFisicos;
        if ($bytes <= 0 || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('El PDF debe pesar entre 1 byte y 15 MB.');
        }
        $nombre = self::normalizarNombreOriginal((string) ($archivo['name'] ?? 'documento.pdf'));
        if (strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('Solo se permiten archivos con extensión .pdf.');
        }
        if (!class_exists(finfo::class)) {
            throw new RuntimeException('El servidor no dispone de la validación MIME requerida.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeDetectado = strtolower(trim((string) $finfo->file($rutaTemporal)));
        if (!in_array($mimeDetectado, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
            throw new RuntimeException('El contenido cargado no fue reconocido como PDF.');
        }
        if (!self::contieneFirmaPdf($rutaTemporal)) {
            throw new RuntimeException('El archivo no contiene una cabecera PDF válida.');
        }
        return [
            'ruta_temporal' => $rutaTemporal,
            'nombre_original' => $nombre,
            'bytes' => $bytes,
            'mime_detectado' => $mimeDetectado,
        ];
    }

    private static function contieneFirmaPdf(string $ruta): bool
    {
        $handle = @fopen($ruta, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        $cabecera = (string) fread($handle, 1024);
        fclose($handle);
        return preg_match('/%PDF-[0-9]+\.[0-9]+/', $cabecera) === 1;
    }

    private static function normalizarNombreOriginal(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', trim($nombre)));
        $nombre = preg_replace('/[\x00-\x1F\x7F]+/u', '', $nombre) ?? '';
        $nombre = trim($nombre);
        if ($nombre === '') {
            $nombre = 'documento.pdf';
        }
        return mb_substr($nombre, 0, 255, 'UTF-8');
    }

    private static function normalizarDescripcion(?string $descripcion): ?string
    {
        $descripcion = trim((string) $descripcion);
        if ($descripcion === '') {
            return null;
        }
        if (mb_strlen($descripcion) > 500) {
            throw new RuntimeException('La descripción no puede superar 500 caracteres.');
        }
        return $descripcion;
    }

    private static function normalizarFecha(?string $fecha): ?string
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return null;
        }
        $valor = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($valor === false || $valor->format('Y-m-d') !== $fecha) {
            throw new RuntimeException('La fecha del documento no es válida.');
        }
        return $fecha;
    }

    private static function cargarContratoBloqueado(PDO $conn, int $idContrato): array
    {
        $stmt = $conn->prepare(
            'SELECT id_contrato_arriendo,id_arrendatario,estado_contrato,fecha_termino_efectiva
             FROM dbo.msp_contratos_arriendo WITH(UPDLOCK,HOLDLOCK)
             WHERE id_contrato_arriendo=:contrato'
        );
        $stmt->execute([':contrato' => $idContrato]);
        $contrato = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($contrato === false) {
            throw new RuntimeException('El contrato indicado no existe.');
        }
        return $contrato;
    }

    private static function validarContratoAdministrable(array $contrato): void
    {
        if (!in_array((int) ($contrato['estado_contrato'] ?? 0), [1, 2], true)) {
            throw new RuntimeException('El contrato ya tuvo su término operativo. Sus documentos solo pueden consultarse.');
        }
    }

    private static function validarLocalContrato(PDO $conn, int $idContrato, int $idLocal): void
    {
        if ($idLocal <= 0 || !msp2TableExists($conn, 'msp_contrato_locales')) {
            throw new RuntimeException('El local relacionado no es válido.');
        }
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM dbo.msp_contrato_locales
             WHERE id_contrato_arriendo=:contrato AND id_local=:local'
        );
        $stmt->execute([':contrato' => $idContrato, ':local' => $idLocal]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('El local seleccionado no pertenece al contrato.');
        }
    }

    private static function cargarDocumentoBloqueado(PDO $conn, int $idDocumento, int $idContrato): ?array
    {
        $stmt = $conn->prepare(
            'SELECT * FROM dbo.msp_centro_documental WITH(UPDLOCK,HOLDLOCK)
             WHERE id_documento=:documento AND id_contrato_arriendo=:contrato'
        );
        $stmt->execute([':documento' => $idDocumento, ':contrato' => $idContrato]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function registrarHistorial(
        PDO $conn,
        int $idContrato,
        int $idUsuario,
        string $evento,
        array $payload
    ): void {
        if (!msp2TableExists($conn, 'msp_historial_contrato')) {
            return;
        }
        $detalle = json_encode([
            'origen' => 'documentos_adjuntos',
            'evento' => $evento,
            'datos' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare(
            "INSERT dbo.msp_historial_contrato
                (id_contrato_arriendo,tipo_evento,id_usuario,detalle_evento,motivo_evento)
             VALUES (:contrato,N'ACTUALIZACION',:usuario,:detalle,:motivo)"
        );
        $stmt->execute([
            ':contrato' => $idContrato,
            ':usuario' => $idUsuario,
            ':detalle' => $detalle,
            ':motivo' => $evento,
        ]);
    }

    private static function storageRoot(): string
    {
        $configPath = dirname(__DIR__) . '/config/storage.php';
        $config = is_file($configPath) ? require $configPath : [];
        $root = is_array($config) ? trim((string) ($config['contrato_documentos_root'] ?? '')) : '';
        if ($root === '') {
            $root = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'msp_storage' . DIRECTORY_SEPARATOR . 'contratos';
        }
        return rtrim($root, "\\/");
    }

    private static function asegurarDirectorioRaiz(): string
    {
        $root = self::storageRoot();
        self::asegurarDirectorio($root);
        $real = realpath($root);
        if ($real === false) {
            throw new RuntimeException('No fue posible resolver la carpeta segura de documentos.');
        }
        return $real;
    }

    private static function asegurarDirectorio(string $directorio): void
    {
        if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new RuntimeException('No fue posible preparar la carpeta segura de documentos.');
        }
    }

    private static function resolverRutaSegura(string $rutaRelativa): string
    {
        $rutaRelativa = str_replace('\\', '/', trim($rutaRelativa));
        if ($rutaRelativa === '' || str_contains($rutaRelativa, "\0")) {
            throw new RuntimeException('La ruta registrada para el PDF no es válida.');
        }
        $partes = explode('/', $rutaRelativa);
        foreach ($partes as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..') {
                throw new RuntimeException('La ruta registrada para el PDF no es segura.');
            }
        }
        $root = realpath(self::storageRoot());
        if ($root === false) {
            throw new RuntimeException('La carpeta segura de documentos no está disponible.');
        }
        $ruta = realpath($root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $partes));
        $prefijo = rtrim($root, "\\/") . DIRECTORY_SEPARATOR;
        if ($ruta === false || !str_starts_with(strtolower($ruta), strtolower($prefijo))) {
            throw new RuntimeException('La ruta física del PDF no está autorizada.');
        }
        return $ruta;
    }

    private static function requerirEstructura(PDO $conn): void
    {
        if (!self::estructuraDisponible($conn)) {
            throw new RuntimeException('Falta aplicar el parche de documentos adjuntos del contrato.');
        }
    }

    private static function bindNullableInt(PDOStatement $stmt, string $parametro, ?int $valor): void
    {
        if ($valor === null) {
            $stmt->bindValue($parametro, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($parametro, $valor, PDO::PARAM_INT);
    }

    private static function bindNullableString(PDOStatement $stmt, string $parametro, ?string $valor): void
    {
        if ($valor === null) {
            $stmt->bindValue($parametro, null, PDO::PARAM_NULL);
            return;
        }
        $stmt->bindValue($parametro, $valor, PDO::PARAM_STR);
    }
}
