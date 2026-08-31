<?php
declare(strict_types=1);

require_once __DIR__ . '/PoolDocumentosPeriodoService.php';

final class EnvioLotesProgramadosService
{
    private const SERVICE_ITEM_CODE = [
        'AGUA' => 'SERVICIO_AGUA',
        'LUZ' => 'SERVICIO_LUZ',
        'GAS' => 'SERVICIO_GAS',
        'SIN_SERVICIO' => '__SIN_SERVICIO__',
    ];

    private const LOTE_ESTADO_PROGRAMADO = 1;
    private const LOTE_ESTADO_PROCESANDO = 2;
    private const LOTE_ESTADO_COMPLETADO = 3;
    private const LOTE_ESTADO_CON_ERROR = 4;
    private const LOTE_ESTADO_CANCELADO = 5;

    private const DEST_ESTADO_PENDIENTE = 1;
    private const DEST_ESTADO_ENVIADO = 2;
    private const DEST_ESTADO_ERROR = 3;
    private const DEST_ESTADO_OMITIDO = 4;
    private const CLAIM_MAX_AGE_MONTHS = 1;

    public static function isAvailable(PDO $conn): bool
    {
        return msp2TableExists($conn, 'msp_envio_lotes_programados')
            && msp2TableExists($conn, 'msp_envio_lote_destinatarios')
            && msp2TableExists($conn, 'msp_envio_lote_documentos');
    }

    public static function buildEstadoLabel(int $estado): string
    {
        return match ($estado) {
            self::LOTE_ESTADO_PROGRAMADO => 'Programado',
            self::LOTE_ESTADO_PROCESANDO => 'Procesando',
            self::LOTE_ESTADO_COMPLETADO => 'Completado',
            self::LOTE_ESTADO_CON_ERROR => 'Con error',
            self::LOTE_ESTADO_CANCELADO => 'Cancelado',
            default => 'Desconocido',
        };
    }

    public static function fetchBlockingCompletitudLoteForStage(PDO $conn, string $periodoFacturacion, string $etapa): ?array
    {
        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT TOP (1)
                id_lote_envio,
                estado_lote,
                programado_para
             FROM dbo.msp_envio_lotes_programados
             WHERE periodo_facturacion = :periodo
               AND codigo_servicio = :servicio
               AND estado_lote <> :estado_cancelado
             ORDER BY id_lote_envio DESC'
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':servicio', $etapa, PDO::PARAM_STR);
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $estado = (int) ($row['estado_lote'] ?? 0);
        return [
            'id_lote_envio' => (int) ($row['id_lote_envio'] ?? 0),
            'estado_lote' => $estado,
            'estado_label' => self::buildEstadoLabel($estado),
            'programado_para' => (string) ($row['programado_para'] ?? ''),
        ];
    }

    public static function countNonCancelledCompletitudLotesByPeriodo(PDO $conn, string $periodoFacturacion): int
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(DISTINCT UPPER(codigo_servicio))
             FROM dbo.msp_envio_lotes_programados
             WHERE periodo_facturacion = :periodo
               AND estado_lote <> :estado_cancelado
               AND UPPER(codigo_servicio) IN (N'LUZ', N'GAS', N'AGUA')"
        );
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->execute();
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public static function createScheduledLoteDinamico(
        PDO $conn,
        string $periodoYm,
        string $periodoFacturacion,
        string $codigoServicio,
        string $programadoParaRaw,
        int $batchSize,
        string $modoDestino,
        ?string $demoDestinoRaw,
        ?int $createdByUserId,
        ?int $clientUtcOffsetMinutes = null
    ): array {
        $codigoServicio = strtoupper(trim($codigoServicio));
        if (!isset(self::SERVICE_ITEM_CODE[$codigoServicio])) {
            throw new RuntimeException('Servicio inválido para el lote dinámico.');
        }

        $programadoPara = self::parseProgramadoPara($conn, $programadoParaRaw, $clientUtcOffsetMinutes);
        $batchSize = max(1, min(100, $batchSize));

        $modoDestinoNorm = strtolower(trim($modoDestino));
        if (!in_array($modoDestinoNorm, ['real', 'demo'], true)) {
            $modoDestinoNorm = 'real';
        }
        if ($modoDestinoNorm === 'real' && !msp2MailTenantDeliveryEnabled($conn)) {
            throw new RuntimeException('El envío real a correos de arrendatarios está deshabilitado en MSP. Puedes usar modo demo.');
        }

        $demoDestino = null;
        if ($modoDestinoNorm === 'demo') {
            $demoDestino = mb_strtolower(msp2NormalizeText((string) $demoDestinoRaw), 'UTF-8');
            if (filter_var($demoDestino, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Debes ingresar un correo demo válido para el lote.');
            }
        }

        $candidatos = self::fetchDynamicCandidatesContratoLocal($conn, $periodoFacturacion, $codigoServicio);
        if ($candidatos === []) {
            throw new RuntimeException('No hay destinatarios elegibles para el servicio seleccionado en este período.');
        }

        $conn->beginTransaction();
        try {
            self::cancelConflictingActiveLotes($conn, $periodoFacturacion, $codigoServicio);

            $insertLote = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lotes_programados (
                    periodo_facturacion,
                    codigo_servicio,
                    modo_destino,
                    demo_destino,
                    programado_para,
                    estado_lote,
                    batch_size,
                    total_destinatarios,
                    omitidos,
                    created_by_user_id
                 ) VALUES (
                    :periodo,
                    :servicio,
                    :modo,
                    :demo_destino,
                    :programado_para,
                    :estado,
                    :batch_size,
                    :total,
                    :omitidos,
                    :created_by
                 )'
            );
            $insertLote->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $insertLote->bindValue(':servicio', $codigoServicio, PDO::PARAM_STR);
            $insertLote->bindValue(':modo', $modoDestinoNorm, PDO::PARAM_STR);
            $insertLote->bindValue(':demo_destino', $demoDestino, $demoDestino !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertLote->bindValue(':programado_para', $programadoPara, PDO::PARAM_STR);
            $insertLote->bindValue(':estado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
            $insertLote->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $insertLote->bindValue(':total', count($candidatos), PDO::PARAM_INT);
            $omitidosIniciales = self::countOmitidosIniciales($candidatos, $modoDestinoNorm);
            $insertLote->bindValue(':omitidos', $omitidosIniciales, PDO::PARAM_INT);
            $insertLote->bindValue(':created_by', $createdByUserId, $createdByUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertLote->execute();

            $idLote = (int) $conn->lastInsertId();
            if ($idLote <= 0) {
                throw new RuntimeException('No fue posible crear el lote programado.');
            }

            $insertDest = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_destinatarios (
                    id_lote_envio,
                    id_arrendatario,
                    nombre_arrendatario_snapshot,
                    rut_snapshot,
                    correo_principal_snapshot,
                    correo_destino,
                    estado_destinatario,
                    intentos,
                    ultimo_error
                 ) VALUES (
                    :id_lote,
                    :id_arr,
                    :nombre,
                    :rut,
                    :correo_principal,
                    :correo_destino,
                    :estado_dest,
                    0,
                    :ultimo_error
                 )'
            );

            $insertDoc = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_documentos (id_lote_destinatario, id_documento_cobro)
                 VALUES (:id_dest, :id_doc)'
            );

            $pendientesIniciales = 0;
            foreach ($candidatos as $cand) {
                $idArr = (int) ($cand['id_arrendatario'] ?? 0);
                if ($idArr <= 0) {
                    continue;
                }

                $correoPrincipal = trim((string) ($cand['correo_principal'] ?? ''));
                $correoDestino = $modoDestinoNorm === 'demo'
                    ? (string) $demoDestino
                    : mb_strtolower($correoPrincipal, 'UTF-8');

                $estadoDest = self::DEST_ESTADO_PENDIENTE;
                $ultimoError = null;
                if (filter_var($correoDestino, FILTER_VALIDATE_EMAIL) === false) {
                    $estadoDest = self::DEST_ESTADO_OMITIDO;
                    $ultimoError = 'Correo destino inválido para envío automático.';
                } else {
                    $pendientesIniciales++;
                }

                $insertDest->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $insertDest->bindValue(':id_arr', $idArr, PDO::PARAM_INT);
                $insertDest->bindValue(':nombre', (string) ($cand['nombre_arrendatario'] ?? ''), PDO::PARAM_STR);
                $insertDest->bindValue(':rut', (string) ($cand['rut'] ?? ''), PDO::PARAM_STR);
                $insertDest->bindValue(':correo_principal', $correoPrincipal, PDO::PARAM_STR);
                $insertDest->bindValue(':correo_destino', $correoDestino, PDO::PARAM_STR);
                $insertDest->bindValue(':estado_dest', $estadoDest, PDO::PARAM_INT);
                $insertDest->bindValue(':ultimo_error', $ultimoError, $ultimoError !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertDest->execute();

                $idDestinatarioLote = (int) $conn->lastInsertId();
                if ($idDestinatarioLote <= 0) {
                    throw new RuntimeException('No fue posible registrar un destinatario del lote.');
                }

                $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
                foreach ($docs as $idDoc) {
                    $idDocInt = (int) $idDoc;
                    if ($idDocInt <= 0) {
                        continue;
                    }
                    $insertDoc->bindValue(':id_dest', $idDestinatarioLote, PDO::PARAM_INT);
                    $insertDoc->bindValue(':id_doc', $idDocInt, PDO::PARAM_INT);
                    $insertDoc->execute();
                }
            }

            self::applyFechaEmisionProgramadaToLoteDocs($conn, $idLote, $programadoPara);

            if ($pendientesIniciales <= 0) {
                $updLote = $conn->prepare(
                    'UPDATE dbo.msp_envio_lotes_programados
                     SET estado_lote = :estado,
                         procesados = total_destinatarios,
                         omitidos = total_destinatarios,
                         updated_at = SYSDATETIME(),
                         finished_at = SYSDATETIME(),
                         last_error = :err
                     WHERE id_lote_envio = :id_lote'
                );
                $updLote->bindValue(':estado', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
                $updLote->bindValue(':err', 'Lote sin correos válidos para envío automático.', PDO::PARAM_STR);
                $updLote->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $updLote->execute();
            }

            $conn->commit();

            return [
                'id_lote_envio' => $idLote,
                'total_destinatarios' => count($candidatos),
                'pendientes' => $pendientesIniciales,
                'omitidos' => $omitidosIniciales,
                'codigo_servicio' => $codigoServicio,
                'programado_para' => $programadoPara,
                'modo_destino' => $modoDestinoNorm,
                'periodo_ym' => $periodoYm,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function resendSingleDocumentoNow(
        PDO $conn,
        int $idDocumentoCobro,
        string $modoDestino,
        ?string $demoDestinoRaw,
        ?int $createdByUserId
    ): array {
        if ($idDocumentoCobro <= 0) {
            throw new RuntimeException('Documento inválido para reenviar.');
        }
        if (!self::isAvailable($conn)) {
            throw new RuntimeException('La base de datos no tiene habilitados los lotes programados.');
        }

        $docCandidate = self::fetchSingleDocumentoCandidate($conn, $idDocumentoCobro);
        if ($docCandidate === null) {
            throw new RuntimeException('No fue posible encontrar el documento indicado para reenviar.');
        }

        $periodoFacturacion = trim((string) ($docCandidate['periodo_facturacion'] ?? ''));
        if ($periodoFacturacion === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoFacturacion) !== 1) {
            throw new RuntimeException('El documento no tiene un período de facturación válido para reenviar.');
        }

        $codigoServicio = self::resolveServiceCodeForSingleDocumento(
            $conn,
            $idDocumentoCobro,
            (string) ($docCandidate['codigo_servicio_inferido'] ?? '')
        );

        $modoDestinoNorm = strtolower(trim($modoDestino));
        if (!in_array($modoDestinoNorm, ['real', 'demo'], true)) {
            $modoDestinoNorm = 'real';
        }
        if ($modoDestinoNorm === 'real' && !msp2MailTenantDeliveryEnabled($conn)) {
            throw new RuntimeException('El envío real a correos de arrendatarios está deshabilitado en MSP. Puedes usar modo demo.');
        }

        $demoDestino = null;
        if ($modoDestinoNorm === 'demo') {
            $demoDestino = mb_strtolower(msp2NormalizeText((string) $demoDestinoRaw), 'UTF-8');
            if (filter_var($demoDestino, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Debes ingresar un correo demo válido para reenviar.');
            }
        }

        $correoPrincipal = trim((string) ($docCandidate['correo_principal'] ?? ''));
        $correoDestino = $modoDestinoNorm === 'demo'
            ? (string) $demoDestino
            : mb_strtolower($correoPrincipal, 'UTF-8');
        $estadoDest = self::DEST_ESTADO_PENDIENTE;
        $ultimoError = null;
        $pendientesIniciales = 0;
        $omitidosIniciales = 0;
        $programadoPara = self::fetchSqlServerNow($conn);
        if (filter_var($correoDestino, FILTER_VALIDATE_EMAIL) === false) {
            $estadoDest = self::DEST_ESTADO_OMITIDO;
            $ultimoError = 'Correo destino inválido para envío automático.';
            $omitidosIniciales = 1;
        } else {
            $pendientesIniciales = 1;
        }

        $conn->beginTransaction();
        try {
            $insertLote = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lotes_programados (
                    periodo_facturacion,
                    codigo_servicio,
                    modo_destino,
                    demo_destino,
                    programado_para,
                    estado_lote,
                    batch_size,
                    total_destinatarios,
                    omitidos,
                    created_by_user_id
                 ) VALUES (
                    :periodo,
                    :servicio,
                    :modo,
                    :demo_destino,
                    SYSDATETIME(),
                    :estado,
                    :batch_size,
                    :total,
                    :omitidos,
                    :created_by
                 )'
            );
            $insertLote->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $insertLote->bindValue(':servicio', $codigoServicio, PDO::PARAM_STR);
            $insertLote->bindValue(':modo', $modoDestinoNorm, PDO::PARAM_STR);
            $insertLote->bindValue(':demo_destino', $demoDestino, $demoDestino !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertLote->bindValue(':estado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
            $insertLote->bindValue(':batch_size', 1, PDO::PARAM_INT);
            $insertLote->bindValue(':total', 1, PDO::PARAM_INT);
            $insertLote->bindValue(':omitidos', $omitidosIniciales, PDO::PARAM_INT);
            $insertLote->bindValue(':created_by', $createdByUserId, $createdByUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertLote->execute();

            $idLote = (int) $conn->lastInsertId();
            if ($idLote <= 0) {
                throw new RuntimeException('No fue posible crear el lote individual de reenvío.');
            }

            $insertDest = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_destinatarios (
                    id_lote_envio,
                    id_arrendatario,
                    nombre_arrendatario_snapshot,
                    rut_snapshot,
                    correo_principal_snapshot,
                    correo_destino,
                    estado_destinatario,
                    intentos,
                    ultimo_error
                 ) VALUES (
                    :id_lote,
                    :id_arr,
                    :nombre,
                    :rut,
                    :correo_principal,
                    :correo_destino,
                    :estado_dest,
                    0,
                    :ultimo_error
                 )'
            );
            $insertDest->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $insertDest->bindValue(':id_arr', (int) ($docCandidate['id_arrendatario'] ?? 0), PDO::PARAM_INT);
            $insertDest->bindValue(':nombre', (string) ($docCandidate['nombre_arrendatario'] ?? ''), PDO::PARAM_STR);
            $insertDest->bindValue(':rut', (string) ($docCandidate['rut'] ?? ''), PDO::PARAM_STR);
            $insertDest->bindValue(':correo_principal', $correoPrincipal, PDO::PARAM_STR);
            $insertDest->bindValue(':correo_destino', $correoDestino, PDO::PARAM_STR);
            $insertDest->bindValue(':estado_dest', $estadoDest, PDO::PARAM_INT);
            $insertDest->bindValue(':ultimo_error', $ultimoError, $ultimoError !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertDest->execute();

            $idDestinatarioLote = (int) $conn->lastInsertId();
            if ($idDestinatarioLote <= 0) {
                throw new RuntimeException('No fue posible registrar el destinatario del reenvío.');
            }

            $insertDoc = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_documentos (id_lote_destinatario, id_documento_cobro)
                 VALUES (:id_dest, :id_doc)'
            );
            $insertDoc->bindValue(':id_dest', $idDestinatarioLote, PDO::PARAM_INT);
            $insertDoc->bindValue(':id_doc', $idDocumentoCobro, PDO::PARAM_INT);
            $insertDoc->execute();

            self::applyFechaEmisionProgramadaToLoteDocs($conn, $idLote, $programadoPara);

            if ($pendientesIniciales <= 0) {
                $updLote = $conn->prepare(
                    'UPDATE dbo.msp_envio_lotes_programados
                     SET estado_lote = :estado,
                         procesados = total_destinatarios,
                         omitidos = total_destinatarios,
                         updated_at = SYSDATETIME(),
                         finished_at = SYSDATETIME(),
                         last_error = :err
                     WHERE id_lote_envio = :id_lote'
                );
                $updLote->bindValue(':estado', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
                $updLote->bindValue(':err', 'Lote sin correo válido para envío automático.', PDO::PARAM_STR);
                $updLote->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $updLote->execute();
            }

            $conn->commit();

            return [
                'id_lote_envio' => $idLote,
                'id_documento_cobro' => $idDocumentoCobro,
                'periodo_facturacion' => $periodoFacturacion,
                'codigo_servicio' => $codigoServicio,
                'modo_destino' => $modoDestinoNorm,
                'demo_destino' => $demoDestino,
                'total_destinatarios' => 1,
                'pendientes' => $pendientesIniciales,
                'omitidos' => $omitidosIniciales,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function fetchCompletionSummaryByStage(
        PDO $conn,
        string $periodoFacturacion,
        string $etapa,
        bool $poolAlreadySynced = false
    ): array
    {
        if (PoolDocumentosPeriodoService::isAvailable($conn)) {
            if (!$poolAlreadySynced) {
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            }
            return PoolDocumentosPeriodoService::fetchSummaryByStage($conn, $periodoFacturacion, $etapa);
        }

        $candidatos = self::fetchCompletionCandidatesByStage($conn, $periodoFacturacion, $etapa);
        $arrendatarios = count($candidatos);
        $documentos = 0;
        foreach ($candidatos as $cand) {
            $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
            $documentos += count($docs);
        }

        return [
            'etapa' => strtoupper(trim($etapa)),
            'arrendatarios' => $arrendatarios,
            'documentos' => $documentos,
            'tiene_candidatos' => $arrendatarios > 0 && $documentos > 0,
        ];
    }

    /**
     * @return int[]
     */
    public static function fetchCompletionDocumentIdsByStage(PDO $conn, string $periodoFacturacion, string $etapa): array
    {
        $candidatos = self::fetchCompletionCandidatesByStage($conn, $periodoFacturacion, $etapa);
        if ($candidatos === []) {
            return [];
        }

        $docMap = [];
        foreach ($candidatos as $cand) {
            $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
            foreach ($docs as $idDoc) {
                $idDocInt = (int) $idDoc;
                if ($idDocInt > 0) {
                    $docMap[$idDocInt] = true;
                }
            }
        }

        $ids = array_map('intval', array_keys($docMap));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @return int[]
     */
    public static function fetchDynamicDocumentIdsByService(PDO $conn, string $periodoFacturacion, string $codigoServicio): array
    {
        $codigoServicio = strtoupper(trim($codigoServicio));
        if (!isset(self::SERVICE_ITEM_CODE[$codigoServicio])) {
            return [];
        }

        $candidatos = self::fetchDynamicCandidatesContratoLocal($conn, $periodoFacturacion, $codigoServicio);
        if ($candidatos === []) {
            return [];
        }

        $docMap = [];
        foreach ($candidatos as $cand) {
            $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
            foreach ($docs as $idDoc) {
                $idDocInt = (int) $idDoc;
                if ($idDocInt > 0) {
                    $docMap[$idDocInt] = true;
                }
            }
        }

        $ids = array_map('intval', array_keys($docMap));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @return int[]
     */
    public static function fetchDocumentIdsByLote(PDO $conn, int $idLote): array
    {
        if ($idLote <= 0) {
            return [];
        }
        if (!msp2TableExists($conn, 'msp_envio_lote_destinatarios') || !msp2TableExists($conn, 'msp_envio_lote_documentos')) {
            return [];
        }

        $stmt = $conn->prepare(
            "SELECT DISTINCT
                eld.id_documento_cobro
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_envio_lote_destinatarios ed
                ON ed.id_lote_destinatario = eld.id_lote_destinatario
             WHERE ed.id_lote_envio = :id_lote"
        );
        $stmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        $ids = [];
        foreach ($rows as $value) {
            $idDoc = (int) $value;
            if ($idDoc > 0) {
                $ids[$idDoc] = true;
            }
        }
        $result = array_map('intval', array_keys($ids));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    public static function createScheduledLoteCompletitud(
        PDO $conn,
        string $periodoYm,
        string $periodoFacturacion,
        string $etapa,
        string $programadoParaRaw,
        int $batchSize,
        string $modoDestino,
        ?string $demoDestinoRaw,
        ?int $createdByUserId,
        ?int $clientUtcOffsetMinutes = null,
        bool $poolAlreadySynced = false
    ): array {
        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            throw new RuntimeException('Etapa inválida para lotes por completitud.');
        }

        $programadoPara = self::parseProgramadoPara($conn, $programadoParaRaw, $clientUtcOffsetMinutes);
        $batchSize = max(1, min(100, $batchSize));

        $modoDestinoNorm = strtolower(trim($modoDestino));
        if (!in_array($modoDestinoNorm, ['real', 'demo'], true)) {
            $modoDestinoNorm = 'real';
        }
        if ($modoDestinoNorm === 'real' && !msp2MailTenantDeliveryEnabled($conn)) {
            throw new RuntimeException('El envío real a correos de arrendatarios está deshabilitado en MSP. Puedes usar modo demo.');
        }

        $demoDestino = null;
        if ($modoDestinoNorm === 'demo') {
            $demoDestino = mb_strtolower(msp2NormalizeText((string) $demoDestinoRaw), 'UTF-8');
            if (filter_var($demoDestino, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Debes ingresar un correo demo válido para el lote.');
            }
        }

        $candidatos = self::fetchCompletionCandidatesByStage($conn, $periodoFacturacion, $etapa, $poolAlreadySynced);
        if ($candidatos === []) {
            throw new RuntimeException('No hay documentos completos pendientes para la etapa seleccionada.');
        }

        $blockingLote = self::fetchBlockingCompletitudLoteForStage($conn, $periodoFacturacion, $etapa);
        if (is_array($blockingLote)) {
            throw new RuntimeException(
                'La etapa ' . $etapa
                . ' ya tiene un lote no cancelado (#' . (int) ($blockingLote['id_lote_envio'] ?? 0)
                . ', estado: ' . (string) ($blockingLote['estado_label'] ?? 'Desconocido')
                . '). Cancélalo desde Paso 6 para volver a programar.'
            );
        }

        $lotesNoCancelados = self::countNonCancelledCompletitudLotesByPeriodo($conn, $periodoFacturacion);
        if ($lotesNoCancelados >= 3) {
            throw new RuntimeException(
                'Ya hay lotes de completitud no cancelados para las 3 etapas (LUZ, GAS y AGUA). '
                . 'Cancela uno desde Paso 6 antes de programar otro.'
            );
        }

        $conn->beginTransaction();
        try {
            $insertLote = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lotes_programados (
                    periodo_facturacion,
                    codigo_servicio,
                    modo_destino,
                    demo_destino,
                    programado_para,
                    estado_lote,
                    batch_size,
                    total_destinatarios,
                    omitidos,
                    created_by_user_id
                 ) VALUES (
                    :periodo,
                    :servicio,
                    :modo,
                    :demo_destino,
                    :programado_para,
                    :estado,
                    :batch_size,
                    :total,
                    :omitidos,
                    :created_by
                 )'
            );
            $insertLote->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $insertLote->bindValue(':servicio', $etapa, PDO::PARAM_STR);
            $insertLote->bindValue(':modo', $modoDestinoNorm, PDO::PARAM_STR);
            $insertLote->bindValue(':demo_destino', $demoDestino, $demoDestino !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertLote->bindValue(':programado_para', $programadoPara, PDO::PARAM_STR);
            $insertLote->bindValue(':estado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
            $insertLote->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $insertLote->bindValue(':total', count($candidatos), PDO::PARAM_INT);
            $omitidosIniciales = self::countOmitidosIniciales($candidatos, $modoDestinoNorm);
            $insertLote->bindValue(':omitidos', $omitidosIniciales, PDO::PARAM_INT);
            $insertLote->bindValue(':created_by', $createdByUserId, $createdByUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertLote->execute();

            $idLote = (int) $conn->lastInsertId();
            if ($idLote <= 0) {
                throw new RuntimeException('No fue posible crear el lote programado.');
            }

            $insertDest = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_destinatarios (
                    id_lote_envio,
                    id_arrendatario,
                    nombre_arrendatario_snapshot,
                    rut_snapshot,
                    correo_principal_snapshot,
                    correo_destino,
                    estado_destinatario,
                    intentos,
                    ultimo_error
                 ) VALUES (
                    :id_lote,
                    :id_arr,
                    :nombre,
                    :rut,
                    :correo_principal,
                    :correo_destino,
                    :estado_dest,
                    0,
                    :ultimo_error
                 )'
            );

            $insertDoc = $conn->prepare(
                'INSERT INTO dbo.msp_envio_lote_documentos (id_lote_destinatario, id_documento_cobro)
                 VALUES (:id_dest, :id_doc)'
            );

            $pendientesIniciales = 0;
            $documentosProgramados = 0;
            foreach ($candidatos as $cand) {
                $idArr = (int) ($cand['id_arrendatario'] ?? 0);
                if ($idArr <= 0) {
                    continue;
                }

                $correoPrincipal = trim((string) ($cand['correo_principal'] ?? ''));
                $correoDestino = $modoDestinoNorm === 'demo'
                    ? (string) $demoDestino
                    : mb_strtolower($correoPrincipal, 'UTF-8');

                $estadoDest = self::DEST_ESTADO_PENDIENTE;
                $ultimoError = null;
                if (filter_var($correoDestino, FILTER_VALIDATE_EMAIL) === false) {
                    $estadoDest = self::DEST_ESTADO_OMITIDO;
                    $ultimoError = 'Correo destino inválido para envío automático.';
                } else {
                    $pendientesIniciales++;
                }

                $insertDest->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $insertDest->bindValue(':id_arr', $idArr, PDO::PARAM_INT);
                $insertDest->bindValue(':nombre', (string) ($cand['nombre_arrendatario'] ?? ''), PDO::PARAM_STR);
                $insertDest->bindValue(':rut', (string) ($cand['rut'] ?? ''), PDO::PARAM_STR);
                $insertDest->bindValue(':correo_principal', $correoPrincipal, PDO::PARAM_STR);
                $insertDest->bindValue(':correo_destino', $correoDestino, PDO::PARAM_STR);
                $insertDest->bindValue(':estado_dest', $estadoDest, PDO::PARAM_INT);
                $insertDest->bindValue(':ultimo_error', $ultimoError, $ultimoError !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertDest->execute();

                $idDestinatarioLote = (int) $conn->lastInsertId();
                if ($idDestinatarioLote <= 0) {
                    throw new RuntimeException('No fue posible registrar un destinatario del lote.');
                }

                $docs = is_array($cand['docs'] ?? null) ? $cand['docs'] : [];
                foreach ($docs as $idDoc) {
                    $idDocInt = (int) $idDoc;
                    if ($idDocInt <= 0) {
                        continue;
                    }
                    $insertDoc->bindValue(':id_dest', $idDestinatarioLote, PDO::PARAM_INT);
                    $insertDoc->bindValue(':id_doc', $idDocInt, PDO::PARAM_INT);
                    $insertDoc->execute();
                    $documentosProgramados++;
                }
            }

            self::applyFechaEmisionProgramadaToLoteDocs($conn, $idLote, $programadoPara);

            if ($pendientesIniciales <= 0) {
                $updLote = $conn->prepare(
                    'UPDATE dbo.msp_envio_lotes_programados
                     SET estado_lote = :estado,
                         procesados = total_destinatarios,
                         omitidos = total_destinatarios,
                         updated_at = SYSDATETIME(),
                         finished_at = SYSDATETIME(),
                         last_error = :err
                     WHERE id_lote_envio = :id_lote'
                );
                $updLote->bindValue(':estado', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
                $updLote->bindValue(':err', 'Lote sin correos válidos para envío automático.', PDO::PARAM_STR);
                $updLote->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
                $updLote->execute();
            }

            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                PoolDocumentosPeriodoService::markLoteadoByLote($conn, $idLote);
            }

            $conn->commit();

            return [
                'id_lote_envio' => $idLote,
                'total_destinatarios' => count($candidatos),
                'documentos_programados' => $documentosProgramados,
                'pendientes' => $pendientesIniciales,
                'omitidos' => $omitidosIniciales,
                'codigo_servicio' => $etapa,
                'programado_para' => $programadoPara,
                'modo_destino' => $modoDestinoNorm,
                'periodo_ym' => $periodoYm,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function processDueLotes(PDO $conn, int $maxLotes = 3, ?int $forceBatchSize = null, string $workerId = 'manual'): array
    {
        $maxLotes = max(1, min(20, $maxLotes));
        $resumen = [
            'lotes_procesados' => 0,
            'destinatarios_enviados' => 0,
            'destinatarios_fallidos' => 0,
            'destinatarios_omitidos' => 0,
            'detalles' => [],
        ];

        for ($i = 0; $i < $maxLotes; $i++) {
            $lote = self::claimNextDueLote($conn, $workerId);
            if ($lote === null) {
                break;
            }

            $idLote = (int) ($lote['id_lote_envio'] ?? 0);
            if ($idLote <= 0) {
                continue;
            }

            $detalle = self::processSingleLote($conn, $idLote, $forceBatchSize);
            $resumen['lotes_procesados']++;
            $resumen['destinatarios_enviados'] += (int) ($detalle['enviados_batch'] ?? 0);
            $resumen['destinatarios_fallidos'] += (int) ($detalle['fallidos_batch'] ?? 0);
            $resumen['destinatarios_omitidos'] += (int) ($detalle['omitidos_batch'] ?? 0);
            $resumen['detalles'][] = $detalle;
        }

        return $resumen;
    }

    public static function fetchLotesByPeriodo(PDO $conn, string $periodoFacturacion, int $limit = 50, bool $soloActivos = false): array
    {
        $safeLimit = max(1, min(200, $limit));
        $sql = 'SELECT TOP (' . $safeLimit . ')
                l.id_lote_envio,
                l.periodo_facturacion,
                l.codigo_servicio,
                l.modo_destino,
                l.demo_destino,
                l.programado_para,
                l.estado_lote,
                l.batch_size,
                l.total_destinatarios,
                COALESCE(doc_stats.total_documentos, 0) AS total_documentos,
                l.procesados,
                l.enviados,
                l.fallidos,
                l.omitidos,
                l.created_at,
                l.started_at,
                l.finished_at,
                l.last_error
             FROM dbo.msp_envio_lotes_programados l
             LEFT JOIN (
                SELECT
                    d.id_lote_envio,
                    COUNT(*) AS total_documentos
                FROM dbo.msp_envio_lote_destinatarios d
                INNER JOIN dbo.msp_envio_lote_documentos ld
                    ON ld.id_lote_destinatario = d.id_lote_destinatario
                GROUP BY d.id_lote_envio
             ) doc_stats
                ON doc_stats.id_lote_envio = l.id_lote_envio
             WHERE l.periodo_facturacion = :periodo';
        if ($soloActivos) {
            $sql .= '
              AND (
                    l.estado_lote = :estado_programado
                    OR l.estado_lote = :estado_procesando
              )';
        }
        $sql .= '
             ORDER BY l.id_lote_envio DESC';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        if ($soloActivos) {
            $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
            $stmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public static function cancelarLote(PDO $conn, int $idLote, string $periodoFacturacion): array
    {
        if ($idLote <= 0) {
            throw new RuntimeException('Lote inválido para cancelar.');
        }

        $stmt = $conn->prepare(
            'UPDATE dbo.msp_envio_lotes_programados
             SET estado_lote = :estado_cancelado,
                 updated_at = SYSDATETIME(),
                 finished_at = ISNULL(finished_at, SYSDATETIME()),
                 last_error = CASE
                    WHEN last_error IS NULL OR LTRIM(RTRIM(last_error)) = \'\' THEN :mensaje
                    ELSE last_error
                 END
             WHERE id_lote_envio = :id_lote
               AND periodo_facturacion = :periodo
               AND (
                    estado_lote = :estado_programado
                    OR estado_lote = :estado_con_error
               )'
        );
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->bindValue(':mensaje', 'Lote cancelado manualmente.', PDO::PARAM_STR);
        $stmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_con_error', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() <= 0) {
            throw new RuntimeException('El lote no se puede cancelar en su estado actual.');
        }

        if (PoolDocumentosPeriodoService::isAvailable($conn)) {
            PoolDocumentosPeriodoService::releaseLoteByLote($conn, $idLote);
        }

        return [
            'id_lote_envio' => $idLote,
            'estado_lote' => self::LOTE_ESTADO_CANCELADO,
        ];
    }

    public static function deleteLotePermanently(PDO $conn, int $idLote, string $periodoFacturacion): array
    {
        if ($idLote <= 0) {
            throw new RuntimeException('Lote inválido para eliminar.');
        }

        $selectStmt = $conn->prepare(
            'SELECT TOP (1)
                id_lote_envio,
                estado_lote
             FROM dbo.msp_envio_lotes_programados
             WHERE id_lote_envio = :id_lote
               AND periodo_facturacion = :periodo'
        );
        $selectStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $selectStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $selectStmt->execute();
        $loteRow = $selectStmt->fetch();
        if ($loteRow === false) {
            throw new RuntimeException('El lote no existe en el período indicado.');
        }

        $estadoLote = (int) ($loteRow['estado_lote'] ?? 0);
        if ($estadoLote === self::LOTE_ESTADO_PROCESANDO) {
            throw new RuntimeException('No puedes eliminar un lote en estado Procesando. Espera su término o cancélalo primero.');
        }

        $conn->beginTransaction();
        try {
            $deleteDocsStmt = $conn->prepare(
                'DELETE eld
                 FROM dbo.msp_envio_lote_documentos eld
                 INNER JOIN dbo.msp_envio_lote_destinatarios d
                    ON d.id_lote_destinatario = eld.id_lote_destinatario
                 WHERE d.id_lote_envio = :id_lote'
            );
            $deleteDocsStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $deleteDocsStmt->execute();
            $docsEliminados = (int) $deleteDocsStmt->rowCount();

            $deleteDestStmt = $conn->prepare(
                'DELETE FROM dbo.msp_envio_lote_destinatarios
                 WHERE id_lote_envio = :id_lote'
            );
            $deleteDestStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $deleteDestStmt->execute();
            $destinatariosEliminados = (int) $deleteDestStmt->rowCount();

            $deleteLoteStmt = $conn->prepare(
                'DELETE FROM dbo.msp_envio_lotes_programados
                 WHERE id_lote_envio = :id_lote
                   AND periodo_facturacion = :periodo
                   AND estado_lote <> :estado_procesando'
            );
            $deleteLoteStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $deleteLoteStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $deleteLoteStmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
            $deleteLoteStmt->execute();
            if ($deleteLoteStmt->rowCount() <= 0) {
                throw new RuntimeException('No fue posible eliminar el lote en su estado actual.');
            }

            if (PoolDocumentosPeriodoService::isAvailable($conn)) {
                PoolDocumentosPeriodoService::releaseLoteByLote($conn, $idLote);
            }

            $conn->commit();

            return [
                'id_lote_envio' => $idLote,
                'estado_lote' => $estadoLote,
                'docs_eliminados' => $docsEliminados,
                'destinatarios_eliminados' => $destinatariosEliminados,
            ];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    public static function forceProcessLoteNow(
        PDO $conn,
        int $idLote,
        string $periodoFacturacion,
        ?int $forceBatchSize = null,
        string $workerId = 'manual-force'
    ): array {
        if ($idLote <= 0) {
            throw new RuntimeException('Lote inválido para ejecutar.');
        }

        $workerToken = trim($workerId);
        if ($workerToken === '') {
            $workerToken = 'manual-force';
        }

        $claimStmt = $conn->prepare(
            'UPDATE dbo.msp_envio_lotes_programados
             SET estado_lote = :estado_procesando,
                 started_at = ISNULL(started_at, SYSDATETIME()),
                 worker_token = :worker,
                 updated_at = SYSDATETIME(),
                 programado_para = CASE
                    WHEN programado_para > SYSDATETIME() THEN SYSDATETIME()
                    ELSE programado_para
                 END
             WHERE id_lote_envio = :id_lote
               AND periodo_facturacion = :periodo
               AND (
                    estado_lote = :estado_programado
                    OR estado_lote = :estado_con_error
               )'
        );
        $claimStmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
        $claimStmt->bindValue(':worker', mb_substr($workerToken, 0, 120, 'UTF-8'), PDO::PARAM_STR);
        $claimStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $claimStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $claimStmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
        $claimStmt->bindValue(':estado_con_error', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
        $claimStmt->execute();

        if ($claimStmt->rowCount() <= 0) {
            throw new RuntimeException('El lote no está disponible para forzar envío en su estado actual.');
        }

        return self::processSingleLote($conn, $idLote, $forceBatchSize);
    }

    public static function cancelActiveLotesByPeriodo(PDO $conn, string $periodoFacturacion): int
    {
        $mensajeCancelado = 'Cancelado desde zona de corrección.';
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_envio_lotes_programados
             SET estado_lote = :estado_cancelado,
                 updated_at = SYSDATETIME(),
                 finished_at = ISNULL(finished_at, SYSDATETIME()),
                 last_error = CASE
                    WHEN last_error IS NULL OR LTRIM(RTRIM(last_error)) = \'\' THEN :mensaje_nuevo
                    ELSE CONCAT(last_error, \' | \', :mensaje_append)
                 END
             WHERE periodo_facturacion = :periodo
               AND estado_lote IN (:estado_programado, :estado_procesando, :estado_con_error)'
        );
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->bindValue(':mensaje_nuevo', $mensajeCancelado, PDO::PARAM_STR);
        $stmt->bindValue(':mensaje_append', $mensajeCancelado, PDO::PARAM_STR);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_con_error', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
        $stmt->execute();

        if (PoolDocumentosPeriodoService::isAvailable($conn) && $stmt->rowCount() > 0) {
            PoolDocumentosPeriodoService::releaseLoteByPeriodo($conn, $periodoFacturacion);
        }

        return (int) $stmt->rowCount();
    }

    private static function parseProgramadoPara(PDO $conn, string $raw, ?int $clientUtcOffsetMinutes = null): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new RuntimeException('Debes indicar fecha y hora de programación para el lote.');
        }

        $dtLocal = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $raw, new DateTimeZone('UTC'));
        if ($dtLocal === false || $dtLocal->format('Y-m-d\\TH:i') !== $raw) {
            $dtLocal = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'));
        }
        if ($dtLocal === false) {
            throw new RuntimeException('Fecha/hora de programación inválida.');
        }

        if ($clientUtcOffsetMinutes !== null && $clientUtcOffsetMinutes >= -840 && $clientUtcOffsetMinutes <= 840) {
            // JS getTimezoneOffset() entrega (UTC - local), en minutos.
            // 1) Convertimos hora local navegador a UTC.
            $dtUtc = $dtLocal->modify(($clientUtcOffsetMinutes >= 0 ? '+' : '') . $clientUtcOffsetMinutes . ' minutes');
            if ($dtUtc === false) {
                throw new RuntimeException('No fue posible convertir la fecha/hora a UTC.');
            }

            // 2) Convertimos UTC a zona horaria del servidor SQL para guardar
            //    en la misma semántica que usa SYSDATETIME().
            $dbUtcOffsetMinutes = self::fetchSqlServerUtcOffsetMinutes($conn);
            $dtSqlLocal = $dtUtc->modify(($dbUtcOffsetMinutes >= 0 ? '+' : '') . $dbUtcOffsetMinutes . ' minutes');
            if ($dtSqlLocal === false) {
                throw new RuntimeException('No fue posible convertir la fecha/hora al huso del servidor.');
            }
            return $dtSqlLocal->format('Y-m-d H:i:00');
        }

        return $dtLocal->format('Y-m-d H:i:00');
    }

    private static function fetchSqlServerUtcOffsetMinutes(PDO $conn): int
    {
        $stmt = $conn->query('SELECT DATEPART(TZOFFSET, SYSDATETIMEOFFSET())');
        if ($stmt === false) {
            return 0;
        }
        $offset = $stmt->fetchColumn();
        if (!is_numeric($offset)) {
            return 0;
        }
        $minutes = (int) $offset;
        if ($minutes < -840 || $minutes > 840) {
            return 0;
        }
        return $minutes;
    }

    private static function countOmitidosIniciales(array $candidatos, string $modoDestino): int
    {
        if ($modoDestino === 'demo') {
            return 0;
        }

        $omitidos = 0;
        foreach ($candidatos as $cand) {
            $correoPrincipal = trim((string) ($cand['correo_principal'] ?? ''));
            if (filter_var($correoPrincipal, FILTER_VALIDATE_EMAIL) === false) {
                $omitidos++;
            }
        }

        return $omitidos;
    }

    private static function fetchSingleDocumentoCandidate(PDO $conn, int $idDocumentoCobro): ?array
    {
        $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
        $correoSelect = $correoTableExiste
            ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
            : "'' AS correo_principal";
        $correoJoin = $correoTableExiste
            ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
            : '';

        $stmt = $conn->prepare(
            "SELECT TOP (1)
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.estado_documento,
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut,
                $correoSelect,
                CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM dbo.msp_documentos_cobro_detalle dcd
                        INNER JOIN dbo.msp_tipo_item_documento tid
                            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                        WHERE dcd.id_documento_cobro = dc.id_documento_cobro
                          AND tid.codigo_item = N'SERVICIO_AGUA'
                    ) THEN N'AGUA'
                    WHEN EXISTS (
                        SELECT 1
                        FROM dbo.msp_documentos_cobro_detalle dcd
                        INNER JOIN dbo.msp_tipo_item_documento tid
                            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                        WHERE dcd.id_documento_cobro = dc.id_documento_cobro
                          AND tid.codigo_item = N'SERVICIO_GAS'
                    ) THEN N'GAS'
                    WHEN EXISTS (
                        SELECT 1
                        FROM dbo.msp_documentos_cobro_detalle dcd
                        INNER JOIN dbo.msp_tipo_item_documento tid
                            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                        WHERE dcd.id_documento_cobro = dc.id_documento_cobro
                          AND tid.codigo_item = N'SERVICIO_LUZ'
                    ) THEN N'LUZ'
                    ELSE NULL
                END AS codigo_servicio_inferido
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
             $correoJoin
             WHERE dc.id_documento_cobro = :id_documento
               AND dc.estado_documento <> 5
             GROUP BY
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.estado_documento,
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut"
        );
        $stmt->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false || !is_array($row)) {
            return null;
        }

        return $row;
    }

    private static function fetchActiveLoteByDocumento(PDO $conn, int $idDocumentoCobro): ?array
    {
        $stmt = $conn->prepare(
            'SELECT TOP (1)
                l.id_lote_envio,
                l.estado_lote
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_envio_lote_destinatarios d
                ON d.id_lote_destinatario = eld.id_lote_destinatario
             INNER JOIN dbo.msp_envio_lotes_programados l
                ON l.id_lote_envio = d.id_lote_envio
             WHERE eld.id_documento_cobro = :id_documento
               AND l.estado_lote IN (:estado_programado, :estado_procesando)
             ORDER BY l.id_lote_envio DESC'
        );
        $stmt->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
        $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false || !is_array($row)) {
            return null;
        }

        return $row;
    }

    private static function resolveServiceCodeForSingleDocumento(PDO $conn, int $idDocumentoCobro, string $inferredCode): string
    {
        $stmt = $conn->prepare(
            'SELECT TOP (1) UPPER(l.codigo_servicio) AS codigo_servicio
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_envio_lote_destinatarios d
                ON d.id_lote_destinatario = eld.id_lote_destinatario
             INNER JOIN dbo.msp_envio_lotes_programados l
                ON l.id_lote_envio = d.id_lote_envio
             WHERE eld.id_documento_cobro = :id_documento
               AND UPPER(l.codigo_servicio) IN (N\'AGUA\', N\'LUZ\', N\'GAS\')
             ORDER BY l.id_lote_envio DESC'
        );
        $stmt->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
        $stmt->execute();
        $lastCode = strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
        if (isset(self::SERVICE_ITEM_CODE[$lastCode])) {
            return $lastCode;
        }

        $inferredCode = strtoupper(trim($inferredCode));
        if (isset(self::SERVICE_ITEM_CODE[$inferredCode])) {
            return $inferredCode;
        }

        return 'LUZ';
    }

    private static function fetchDynamicCandidatesContratoLocal(PDO $conn, string $periodoFacturacion, string $codigoServicio): array
    {
        $codigoServicio = strtoupper(trim($codigoServicio));
        if ($codigoServicio === 'SIN_SERVICIO') {
            $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
            $correoSelect = $correoTableExiste
                ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
                : "'' AS correo_principal";
            $correoJoin = $correoTableExiste
                ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
                : '';

            $sqlSinServicio = "DECLARE @periodo DATE = :periodo;
                SELECT
                    a.id_arrendatario,
                    COALESCE(
                        NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                        NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                        NULLIF(LTRIM(RTRIM(a.rut)), ''),
                        CONCAT('Arrendatario #', a.id_arrendatario)
                    ) AS nombre_arrendatario,
                    LTRIM(RTRIM(a.rut)) AS rut,
                    $correoSelect,
                    dc.id_documento_cobro
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = dc.id_tienda
                INNER JOIN dbo.msp_arrendatarios a
                    ON a.id_arrendatario = t.id_arrendatario
                $correoJoin
                WHERE dc.periodo_facturacion = @periodo
                  AND dc.estado_documento <> 5
                  AND NOT EXISTS (
                    SELECT 1
                    FROM dbo.msp_documentos_cobro_detalle dcd
                    INNER JOIN dbo.msp_tipo_item_documento tid
                        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                    WHERE dcd.id_documento_cobro = dc.id_documento_cobro
                      AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                  )
                GROUP BY
                    a.id_arrendatario,
                    a.nombre_locatario,
                    a.nombre_representante,
                    a.rut,
                    dc.id_documento_cobro
                ORDER BY nombre_arrendatario ASC, dc.id_documento_cobro ASC";

            $stmtSinServicio = $conn->prepare($sqlSinServicio);
            $stmtSinServicio->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $stmtSinServicio->execute();
            $rows = $stmtSinServicio->fetchAll() ?: [];
            if ($rows === []) {
                return [];
            }

            $byArr = [];
            foreach ($rows as $row) {
                $arrId = (int) ($row['id_arrendatario'] ?? 0);
                $docId = (int) ($row['id_documento_cobro'] ?? 0);
                if ($arrId <= 0 || $docId <= 0) {
                    continue;
                }

                if (!isset($byArr[$arrId])) {
                    $byArr[$arrId] = [
                        'id_arrendatario' => $arrId,
                        'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
                        'rut' => (string) ($row['rut'] ?? ''),
                        'correo_principal' => trim((string) ($row['correo_principal'] ?? '')),
                        'docs' => [],
                    ];
                }

                if (!in_array($docId, $byArr[$arrId]['docs'], true)) {
                    $byArr[$arrId]['docs'][] = $docId;
                }
            }

            return array_values($byArr);
        }

        $codigoItem = self::SERVICE_ITEM_CODE[$codigoServicio] ?? null;
        if ($codigoItem === null) {
            return [];
        }

        $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
        $correoSelect = $correoTableExiste
            ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
            : "'' AS correo_principal";
        $correoJoin = $correoTableExiste
            ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
            : '';

        $sql = "DECLARE @periodo DATE = :periodo;
            SELECT
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut,
                $correoSelect,
                dc.id_documento_cobro
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
            INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                ON dcd.id_documento_cobro = dc.id_documento_cobro
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
               AND tid.codigo_item = :codigo_item
            $correoJoin
            WHERE dc.periodo_facturacion = @periodo
              AND dc.estado_documento <> 5
              AND EXISTS (
                SELECT 1
                FROM dbo.msp_medidores m
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = m.id_tipo_servicio
                INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_local = m.id_local
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                WHERE ca.id_tienda = dc.id_tienda
                  AND UPPER(ts.codigo_servicio) = :servicio
                  AND m.estado_medidor = 1
                  AND m.fecha_retiro IS NULL
                  AND cl.estado_relacion = 1
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
              )
            GROUP BY
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut,
                dc.id_documento_cobro
            ORDER BY nombre_arrendatario ASC, dc.id_documento_cobro ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':codigo_item', $codigoItem, PDO::PARAM_STR);
        $stmt->bindValue(':servicio', $codigoServicio, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $byArr = [];
        foreach ($rows as $row) {
            $arrId = (int) ($row['id_arrendatario'] ?? 0);
            $docId = (int) ($row['id_documento_cobro'] ?? 0);
            if ($arrId <= 0 || $docId <= 0) {
                continue;
            }

            if (!isset($byArr[$arrId])) {
                $byArr[$arrId] = [
                    'id_arrendatario' => $arrId,
                    'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
                    'rut' => (string) ($row['rut'] ?? ''),
                    'correo_principal' => trim((string) ($row['correo_principal'] ?? '')),
                    'docs' => [],
                ];
            }

            if (!in_array($docId, $byArr[$arrId]['docs'], true)) {
                $byArr[$arrId]['docs'][] = $docId;
            }
        }

        return array_values($byArr);
    }

    private static function claimNextDueLote(PDO $conn, string $workerId): ?array
    {
        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare(
                "SELECT TOP (1)
                    id_lote_envio,
                    periodo_facturacion,
                    codigo_servicio,
                    modo_destino,
                    demo_destino,
                    programado_para,
                    estado_lote,
                    batch_size
                 FROM dbo.msp_envio_lotes_programados WITH (UPDLOCK, READPAST, ROWLOCK)
                 WHERE (
                        estado_lote = :estado_programado
                        OR (estado_lote = :estado_procesando AND updated_at <= DATEADD(MINUTE, -15, SYSDATETIME()))
                 )
                   AND programado_para <= SYSDATETIME()
                   AND periodo_facturacion >= DATEFROMPARTS(
                        YEAR(DATEADD(MONTH, -" . self::CLAIM_MAX_AGE_MONTHS . ", SYSDATETIME())),
                        MONTH(DATEADD(MONTH, -" . self::CLAIM_MAX_AGE_MONTHS . ", SYSDATETIME())),
                        1
                   )
                 ORDER BY programado_para ASC, id_lote_envio ASC"
            );
            $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
            $stmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row === false) {
                $conn->commit();
                return null;
            }

            $idLote = (int) ($row['id_lote_envio'] ?? 0);
            if ($idLote <= 0) {
                $conn->rollBack();
                return null;
            }

            $upd = $conn->prepare(
                'UPDATE dbo.msp_envio_lotes_programados
                 SET estado_lote = :estado,
                     started_at = ISNULL(started_at, SYSDATETIME()),
                     worker_token = :worker,
                     updated_at = SYSDATETIME()
                 WHERE id_lote_envio = :id_lote'
            );
            $upd->bindValue(':estado', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
            $upd->bindValue(':worker', mb_substr($workerId, 0, 120, 'UTF-8'), PDO::PARAM_STR);
            $upd->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
            $upd->execute();

            $conn->commit();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private static function processSingleLote(PDO $conn, int $idLote, ?int $forceBatchSize = null): array
    {
        $loteStmt = $conn->prepare(
            'SELECT
                id_lote_envio,
                periodo_facturacion,
                codigo_servicio,
                modo_destino,
                demo_destino,
                batch_size,
                estado_lote
             FROM dbo.msp_envio_lotes_programados
             WHERE id_lote_envio = :id_lote'
        );
        $loteStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $loteStmt->execute();
        $lote = $loteStmt->fetch();
        if ($lote === false) {
            return [
                'id_lote_envio' => $idLote,
                'enviados_batch' => 0,
                'fallidos_batch' => 0,
                'omitidos_batch' => 0,
                'message' => 'Lote no encontrado.',
            ];
        }

        $batchSize = (int) ($lote['batch_size'] ?? 10);
        if ($forceBatchSize !== null) {
            $batchSize = max(1, min(200, $forceBatchSize));
        }
        $batchSize = max(1, min(200, $batchSize));

        $destStmt = $conn->prepare(
            'SELECT TOP (' . $batchSize . ')
                id_lote_destinatario,
                id_arrendatario,
                nombre_arrendatario_snapshot,
                rut_snapshot,
                correo_principal_snapshot,
                correo_destino,
                intentos
             FROM dbo.msp_envio_lote_destinatarios
             WHERE id_lote_envio = :id_lote
               AND estado_destinatario = :estado_pendiente
             ORDER BY id_lote_destinatario ASC'
        );
        $destStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $destStmt->bindValue(':estado_pendiente', self::DEST_ESTADO_PENDIENTE, PDO::PARAM_INT);
        $destStmt->execute();
        $destinatarios = $destStmt->fetchAll() ?: [];

        $enviadosBatch = 0;
        $fallidosBatch = 0;
        $omitidosBatch = 0;

        foreach ($destinatarios as $dest) {
            $idDest = (int) ($dest['id_lote_destinatario'] ?? 0);
            if ($idDest <= 0) {
                continue;
            }

            $correoDestino = mb_strtolower(trim((string) ($dest['correo_destino'] ?? '')), 'UTF-8');
            if (filter_var($correoDestino, FILTER_VALIDATE_EMAIL) === false) {
                self::markDestinatario(
                    $conn,
                    $idDest,
                    self::DEST_ESTADO_OMITIDO,
                    ((int) ($dest['intentos'] ?? 0)) + 1,
                    'Correo destino inválido.',
                    null
                );
                $omitidosBatch++;
                continue;
            }

            $docs = self::fetchDocsForDestinatario($conn, $idDest);
            if ($docs === []) {
                self::markDestinatario(
                    $conn,
                    $idDest,
                    self::DEST_ESTADO_OMITIDO,
                    ((int) ($dest['intentos'] ?? 0)) + 1,
                    'Sin documentos vigentes para adjuntar.',
                    null
                );
                $omitidosBatch++;
                continue;
            }
            $docs = self::filterDocsAlreadySentInOtherLotes(
                $conn,
                $docs,
                $idLote,
                (string) ($lote['periodo_facturacion'] ?? '')
            );
            if ($docs === []) {
                self::markDestinatario(
                    $conn,
                    $idDest,
                    self::DEST_ESTADO_OMITIDO,
                    ((int) ($dest['intentos'] ?? 0)) + 1,
                    'Todos los documentos de este destinatario ya fueron enviados en otro lote del mismo período/servicio.',
                    null
                );
                $omitidosBatch++;
                continue;
            }

            $arrRow = [
                'id_arrendatario' => (int) ($dest['id_arrendatario'] ?? 0),
                'nombre_arrendatario' => (string) ($dest['nombre_arrendatario_snapshot'] ?? ''),
                'rut' => (string) ($dest['rut_snapshot'] ?? ''),
                'correo_principal' => (string) ($dest['correo_principal_snapshot'] ?? ''),
            ];

            try {
                self::sendOneCobroEmail(
                    $conn,
                    $correoDestino,
                    $arrRow,
                    $docs,
                    (new DateTimeImmutable((string) ($lote['periodo_facturacion'] ?? 'now')))->format('Y-m')
                );
                self::markDestinatario(
                    $conn,
                    $idDest,
                    self::DEST_ESTADO_ENVIADO,
                    ((int) ($dest['intentos'] ?? 0)) + 1,
                    null,
                    self::fetchSqlServerNow($conn)
                );
                $enviadosBatch++;
            } catch (Throwable $e) {
                self::markDestinatario(
                    $conn,
                    $idDest,
                    self::DEST_ESTADO_ERROR,
                    ((int) ($dest['intentos'] ?? 0)) + 1,
                    self::normalizeError($e),
                    null
                );
                $fallidosBatch++;
            }
        }

        $stats = self::recalculateAndPersistLoteStats($conn, $idLote);
        $ultimoErrorDestinatario = self::fetchFirstDestinatarioErrorByLote($conn, $idLote);

        return [
            'id_lote_envio' => $idLote,
            'enviados_batch' => $enviadosBatch,
            'fallidos_batch' => $fallidosBatch,
            'omitidos_batch' => $omitidosBatch,
            'estado_lote' => (int) ($stats['estado_lote'] ?? 0),
            'procesados' => (int) ($stats['procesados'] ?? 0),
            'total_destinatarios' => (int) ($stats['total_destinatarios'] ?? 0),
            'ultimo_error_destinatario' => $ultimoErrorDestinatario,
            'message' => 'Lote #' . $idLote . ': enviados ' . $enviadosBatch . ', fallidos ' . $fallidosBatch . ', omitidos ' . $omitidosBatch . '.',
        ];
    }

    private static function fetchFirstDestinatarioErrorByLote(PDO $conn, int $idLote): ?string
    {
        if ($idLote <= 0) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT TOP (1)
                d.ultimo_error
             FROM dbo.msp_envio_lote_destinatarios d
             WHERE d.id_lote_envio = :id_lote
               AND d.estado_destinatario = :estado_error
               AND d.ultimo_error IS NOT NULL
               AND LTRIM(RTRIM(d.ultimo_error)) <> \'\'
             ORDER BY d.updated_at DESC, d.id_lote_destinatario DESC'
        );
        $stmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $stmt->bindValue(':estado_error', self::DEST_ESTADO_ERROR, PDO::PARAM_INT);
        $stmt->execute();
        $error = trim((string) ($stmt->fetchColumn() ?: ''));

        return $error !== '' ? $error : null;
    }

    private static function fetchDocsForDestinatario(PDO $conn, int $idLoteDestinatario): array
    {
        $stmt = $conn->prepare(
            'SELECT
                dc.id_documento_cobro,
                dc.numero_documento,
                dc.monto_total,
                dc.saldo_pendiente
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = eld.id_documento_cobro
             WHERE eld.id_lote_destinatario = :id_dest
               AND dc.estado_documento <> 5
             ORDER BY dc.id_documento_cobro ASC'
        );
        $stmt->bindValue(':id_dest', $idLoteDestinatario, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private static function applyFechaEmisionProgramadaToLoteDocs(PDO $conn, int $idLote, string $programadoPara): void
    {
        if ($idLote <= 0) {
            return;
        }

        $fechaProgramada = substr(trim($programadoPara), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaProgramada) !== 1) {
            return;
        }

        $stmt = $conn->prepare(
            'UPDATE dc
             SET dc.fecha_emision = :fecha_emision_set
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_envio_lote_documentos eld
                ON eld.id_documento_cobro = dc.id_documento_cobro
             INNER JOIN dbo.msp_envio_lote_destinatarios ed
                ON ed.id_lote_destinatario = eld.id_lote_destinatario
             WHERE ed.id_lote_envio = :id_lote
               AND dc.fecha_vencimiento >= :fecha_emision_check'
        );
        $stmt->bindValue(':fecha_emision_set', $fechaProgramada, PDO::PARAM_STR);
        $stmt->bindValue(':fecha_emision_check', $fechaProgramada, PDO::PARAM_STR);
        $stmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function markDestinatario(
        PDO $conn,
        int $idDestinatario,
        int $estado,
        int $intentos,
        ?string $error,
        ?string $enviadoAt
    ): void {
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_envio_lote_destinatarios
             SET estado_destinatario = :estado,
                 intentos = :intentos,
                 ultimo_error = :ultimo_error,
                 enviado_at = :enviado_at,
                 updated_at = SYSDATETIME()
             WHERE id_lote_destinatario = :id_dest'
        );
        $stmt->bindValue(':estado', $estado, PDO::PARAM_INT);
        $stmt->bindValue(':intentos', $intentos, PDO::PARAM_INT);
        $stmt->bindValue(':ultimo_error', $error, $error !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':enviado_at', $enviadoAt, $enviadoAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':id_dest', $idDestinatario, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function fetchSqlServerNow(PDO $conn): string
    {
        try {
            $stmt = $conn->query("SELECT CONVERT(VARCHAR(19), SYSDATETIME(), 120)");
            if ($stmt !== false) {
                $value = trim((string) $stmt->fetchColumn());
                if ($value !== '') {
                    return $value;
                }
            }
        } catch (Throwable) {
            // Fallback defensivo: mantener comportamiento previo si falla consulta.
        }

        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    private static function recalculateAndPersistLoteStats(PDO $conn, int $idLote): array
    {
        $statsStmt = $conn->prepare(
            'SELECT
                COUNT(*) AS total_destinatarios,
                SUM(CASE WHEN estado_destinatario = :estado_enviado THEN 1 ELSE 0 END) AS enviados,
                SUM(CASE WHEN estado_destinatario = :estado_error THEN 1 ELSE 0 END) AS fallidos,
                SUM(CASE WHEN estado_destinatario = :estado_omitido THEN 1 ELSE 0 END) AS omitidos,
                SUM(
                    CASE
                        WHEN estado_destinatario = :estado_enviado2
                          OR estado_destinatario = :estado_error2
                          OR estado_destinatario = :estado_omitido2
                        THEN 1 ELSE 0
                    END
                ) AS procesados
             FROM dbo.msp_envio_lote_destinatarios
             WHERE id_lote_envio = :id_lote'
        );
        $statsStmt->bindValue(':estado_enviado', self::DEST_ESTADO_ENVIADO, PDO::PARAM_INT);
        $statsStmt->bindValue(':estado_error', self::DEST_ESTADO_ERROR, PDO::PARAM_INT);
        $statsStmt->bindValue(':estado_omitido', self::DEST_ESTADO_OMITIDO, PDO::PARAM_INT);
        $statsStmt->bindValue(':estado_enviado2', self::DEST_ESTADO_ENVIADO, PDO::PARAM_INT);
        $statsStmt->bindValue(':estado_error2', self::DEST_ESTADO_ERROR, PDO::PARAM_INT);
        $statsStmt->bindValue(':estado_omitido2', self::DEST_ESTADO_OMITIDO, PDO::PARAM_INT);
        $statsStmt->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $statsStmt->execute();
        $stats = $statsStmt->fetch() ?: [];

        $total = (int) ($stats['total_destinatarios'] ?? 0);
        $enviados = (int) ($stats['enviados'] ?? 0);
        $fallidos = (int) ($stats['fallidos'] ?? 0);
        $omitidos = (int) ($stats['omitidos'] ?? 0);
        $procesados = (int) ($stats['procesados'] ?? 0);

        $estadoLote = self::LOTE_ESTADO_PROCESANDO;
        $finishedAt = null;
        $lastError = null;

        if ($total > 0 && $procesados >= $total) {
            if ($fallidos > 0) {
                $estadoLote = self::LOTE_ESTADO_CON_ERROR;
                $lastError = 'Lote finalizado con errores de envío.';
            } else {
                $estadoLote = self::LOTE_ESTADO_COMPLETADO;
            }
            $finishedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }

        $upd = $conn->prepare(
            'UPDATE dbo.msp_envio_lotes_programados
             SET procesados = :procesados,
                 enviados = :enviados,
                 fallidos = :fallidos,
                 omitidos = :omitidos,
                 estado_lote = :estado,
                 finished_at = :finished_at,
                 last_error = :last_error,
                 updated_at = SYSDATETIME()
             WHERE id_lote_envio = :id_lote'
        );
        $upd->bindValue(':procesados', $procesados, PDO::PARAM_INT);
        $upd->bindValue(':enviados', $enviados, PDO::PARAM_INT);
        $upd->bindValue(':fallidos', $fallidos, PDO::PARAM_INT);
        $upd->bindValue(':omitidos', $omitidos, PDO::PARAM_INT);
        $upd->bindValue(':estado', $estadoLote, PDO::PARAM_INT);
        $upd->bindValue(':finished_at', $finishedAt, $finishedAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $upd->bindValue(':last_error', $lastError, $lastError !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $upd->bindValue(':id_lote', $idLote, PDO::PARAM_INT);
        $upd->execute();

        $stats['estado_lote'] = $estadoLote;
        $stats['procesados'] = $procesados;
        $stats['total_destinatarios'] = $total;

        return $stats;
    }

    private static function sendOneCobroEmail(PDO $conn, string $correoDestino, array $arrRow, array $docs, string $periodoYm): void
    {
        if (!msp2MailTenantDeliveryEnabled($conn)) {
            throw new RuntimeException('El envío real a correos de arrendatarios está deshabilitado en MSP.');
        }

        [$subject, $body, $altBody] = omBuildCobroEmailContent($conn, $arrRow, $docs, $periodoYm);

        $mail = omBuildSmtpMailerFromEnv();
        $mail->addAddress($correoDestino);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $body;
        $mail->AltBody = $altBody;

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $docId = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($docId <= 0) {
                continue;
            }
            [$valeFilename, $valePdf] = msp2BuildDocumentoCobroValeResumenPdf($conn, $docId);
            $mail->addStringAttachment($valePdf, $valeFilename, 'base64', 'application/pdf');
        }

        $mail->send();
    }

    private static function normalizeError(Throwable $e): string
    {
        $msg = trim($e->getMessage());
        if ($msg === '') {
            $msg = 'Error sin detalle (' . $e::class . ').';
        }
        return mb_substr($msg, 0, 1000, 'UTF-8');
    }

    private static function cancelConflictingActiveLotes(PDO $conn, string $periodoFacturacion, string $codigoServicio): void
    {
        $mensajeCancelado = 'Cancelado automáticamente por creación de nuevo lote del mismo período/servicio.';
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_envio_lotes_programados
             SET estado_lote = :estado_cancelado,
                 updated_at = SYSDATETIME(),
                 finished_at = ISNULL(finished_at, SYSDATETIME()),
                 last_error = CASE
                    WHEN last_error IS NULL OR LTRIM(RTRIM(last_error)) = \'\' THEN :mensaje_nuevo
                    ELSE CONCAT(last_error, \' | \', :mensaje_append)
                 END
             WHERE periodo_facturacion = :periodo
               AND codigo_servicio = :servicio
               AND estado_lote IN (:estado_programado, :estado_procesando, :estado_con_error)'
        );
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->bindValue(':mensaje_nuevo', $mensajeCancelado, PDO::PARAM_STR);
        $stmt->bindValue(':mensaje_append', $mensajeCancelado, PDO::PARAM_STR);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':servicio', $codigoServicio, PDO::PARAM_STR);
        $stmt->bindValue(':estado_programado', self::LOTE_ESTADO_PROGRAMADO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_procesando', self::LOTE_ESTADO_PROCESANDO, PDO::PARAM_INT);
        $stmt->bindValue(':estado_con_error', self::LOTE_ESTADO_CON_ERROR, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function filterDocsAlreadySentInOtherLotes(
        PDO $conn,
        array $docs,
        int $idLoteActual,
        string $periodoFacturacion
    ): array {
        $docIds = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $idDoc = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($idDoc > 0) {
                $docIds[] = $idDoc;
            }
        }
        $docIds = array_values(array_unique($docIds));
        if ($docIds === []) {
            return [];
        }

        $placeholders = [];
        foreach ($docIds as $index => $idDoc) {
            $placeholders[] = ':doc_' . $index;
        }

        $sql = 'SELECT DISTINCT eld.id_documento_cobro
                FROM dbo.msp_envio_lote_documentos eld
                INNER JOIN dbo.msp_envio_lote_destinatarios d
                    ON d.id_lote_destinatario = eld.id_lote_destinatario
                INNER JOIN dbo.msp_envio_lotes_programados l
                    ON l.id_lote_envio = d.id_lote_envio
                WHERE l.id_lote_envio <> :id_lote_actual
                  AND l.periodo_facturacion = :periodo
                  AND d.estado_destinatario = :estado_enviado
                  AND eld.id_documento_cobro IN (' . implode(', ', $placeholders) . ')';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':id_lote_actual', $idLoteActual, PDO::PARAM_INT);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_enviado', self::DEST_ESTADO_ENVIADO, PDO::PARAM_INT);
        foreach ($docIds as $index => $idDoc) {
            $stmt->bindValue(':doc_' . $index, $idDoc, PDO::PARAM_INT);
        }
        $stmt->execute();

        $alreadySentMap = [];
        while (($row = $stmt->fetch()) !== false) {
            $alreadySentMap[(int) ($row['id_documento_cobro'] ?? 0)] = true;
        }

        $filtered = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $idDoc = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($idDoc > 0 && isset($alreadySentMap[$idDoc])) {
                continue;
            }
            $filtered[] = $doc;
        }

        return $filtered;
    }

    private static function fetchCompletionCandidatesByStage(
        PDO $conn,
        string $periodoFacturacion,
        string $etapa,
        bool $poolAlreadySynced = false
    ): array
    {
        if (PoolDocumentosPeriodoService::isAvailable($conn)) {
            if (!$poolAlreadySynced) {
                PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
            }
            return PoolDocumentosPeriodoService::fetchCandidatesByStage($conn, $periodoFacturacion, $etapa);
        }

        $etapa = strtoupper(trim($etapa));
        if (!in_array($etapa, ['LUZ', 'GAS', 'AGUA'], true)) {
            return [];
        }

        $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
        $correoSelect = $correoTableExiste
            ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
            : "'' AS correo_principal";
        $correoJoin = $correoTableExiste
            ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac ON ac.id_arrendatario = a.id_arrendatario'
            : '';

        $filtroEtapa = match ($etapa) {
            'LUZ' => "ISNULL(docf.has_luz_item, 0) = 1 AND ISNULL(docf.has_gas_item, 0) = 0 AND ISNULL(docf.has_agua_item, 0) = 0",
            'GAS' => "ISNULL(docf.has_luz_item, 0) = 1 AND ISNULL(docf.has_gas_item, 0) = 1 AND ISNULL(docf.has_agua_item, 0) = 0",
            'AGUA' => "ISNULL(docf.has_luz_item, 0) = 1 AND ISNULL(docf.has_agua_item, 0) = 1",
            default => '1 = 0',
        };

        $sql = "DECLARE @periodo DATE = :periodo;
            ;WITH docs_disponibles AS (
                SELECT
                    dc.id_documento_cobro,
                    dc.id_tienda
                FROM dbo.msp_documentos_cobro dc
                OUTER APPLY (
                    SELECT
                        MAX(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN 1 ELSE 0 END) AS has_luz_item,
                        MAX(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN 1 ELSE 0 END) AS has_gas_item,
                        MAX(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN 1 ELSE 0 END) AS has_agua_item
                    FROM dbo.msp_documentos_cobro_detalle dcd
                    INNER JOIN dbo.msp_tipo_item_documento tid
                        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                    WHERE dcd.id_documento_cobro = dc.id_documento_cobro
                ) docf
                LEFT JOIN dbo.msp_envio_lote_documentos eld
                    ON eld.id_documento_cobro = dc.id_documento_cobro
                LEFT JOIN dbo.msp_envio_lote_destinatarios ed
                    ON ed.id_lote_destinatario = eld.id_lote_destinatario
                LEFT JOIN dbo.msp_envio_lotes_programados el
                    ON el.id_lote_envio = ed.id_lote_envio
                   AND el.periodo_facturacion = @periodo
                   AND el.estado_lote <> :estado_cancelado
                WHERE dc.periodo_facturacion = @periodo
                  AND dc.estado_documento <> 5
                  AND el.id_lote_envio IS NULL
                  AND ($filtroEtapa)
            )
            SELECT
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut,
                $correoSelect,
                dd.id_documento_cobro
            FROM docs_disponibles dd
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dd.id_tienda
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
            $correoJoin
            GROUP BY
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut,
                dd.id_documento_cobro
            ORDER BY nombre_arrendatario ASC, dd.id_documento_cobro ASC";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->bindValue(':estado_cancelado', self::LOTE_ESTADO_CANCELADO, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return [];
        }

        $byArr = [];
        foreach ($rows as $row) {
            $arrId = (int) ($row['id_arrendatario'] ?? 0);
            $docId = (int) ($row['id_documento_cobro'] ?? 0);
            if ($arrId <= 0 || $docId <= 0) {
                continue;
            }

            if (!isset($byArr[$arrId])) {
                $byArr[$arrId] = [
                    'id_arrendatario' => $arrId,
                    'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
                    'rut' => (string) ($row['rut'] ?? ''),
                    'correo_principal' => trim((string) ($row['correo_principal'] ?? '')),
                    'docs' => [],
                ];
            }

            if (!in_array($docId, $byArr[$arrId]['docs'], true)) {
                $byArr[$arrId]['docs'][] = $docId;
            }
        }

        return array_values($byArr);
    }
}
