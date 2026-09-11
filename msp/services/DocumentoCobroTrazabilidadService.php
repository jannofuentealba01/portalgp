<?php

declare(strict_types=1);

final class DocumentoCobroTrazabilidadService
{
    public static function registrar(PDO $conn, int $idDocumento, string $tipo, string $origen, ?int $idUsuario, array $payload): void
    {
        if ($idDocumento <= 0 || !msp2TableExists($conn, 'msp_documentos_cobro_eventos')) {
            return;
        }
        $payloadJson = $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_documentos_cobro_eventos
                (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento, id_usuario, payload_json, fecha_evento)
             SELECT dc.id_contrato_arriendo, dc.id_documento_cobro, :tipo, :origen, :id_usuario, :payload, SYSDATETIME()
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.id_documento_cobro = :id_documento'
        );
        $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        $stmt->bindValue(':origen', $origen, PDO::PARAM_STR);
        $stmt->bindValue(':id_usuario', $idUsuario, $idUsuario === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':payload', $payloadJson, $payloadJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id_documento', $idDocumento, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('No fue posible asociar el evento al documento de cobro.');
        }
    }

    public static function registrarLote(PDO $conn, int $idLote, string $programadoPara, string $modoDestino): void
    {
        if ($idLote <= 0 || !msp2TableExists($conn, 'msp_documentos_cobro_eventos')) {
            return;
        }
        $payload = json_encode(['id_lote_envio' => $idLote, 'programado_para' => $programadoPara, 'modo_destino' => $modoDestino], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_documentos_cobro_eventos
                (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento, id_usuario, payload_json, fecha_evento)
             SELECT DISTINCT dc.id_contrato_arriendo, dc.id_documento_cobro, N\'ENVIO_PROGRAMADO\', N\'ENVIO\', NULL, :payload, SYSDATETIME()
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_envio_lote_destinatarios ed ON ed.id_lote_destinatario = eld.id_lote_destinatario
             INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = eld.id_documento_cobro
             WHERE ed.id_lote_envio = :id_lote'
        );
        $stmt->execute([':payload' => $payload, ':id_lote' => $idLote]);
    }

    public static function registrarResultado(PDO $conn, array $docs, int $idLote, bool $enviado, ?string $error): void
    {
        foreach ($docs as $doc) {
            $idDocumento = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($idDocumento <= 0) {
                continue;
            }
            try {
                self::registrar($conn, $idDocumento, 'ENVIO_RESULTADO', 'ENVIO', null, ['id_lote_envio' => $idLote, 'enviado' => $enviado, 'error' => $error]);
            } catch (Throwable $eventError) {
                error_log('No fue posible registrar resultado de envio #' . $idDocumento . ': ' . $eventError->getMessage());
            }
        }
    }

    public static function registrarGeneracion(PDO $conn, string $periodo, int $reemplazar, string $perfil, string $tiendasCsv): void
    {
        if (!msp2TableExists($conn, 'msp_documentos_cobro_eventos')) {
            return;
        }
        $payload = json_encode(['perfil_servicios' => $perfil, 'reemplazar' => $reemplazar === 1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             DECLARE @tiendas NVARCHAR(MAX) = :tiendas;
             DECLARE @target TABLE (id_tienda INT PRIMARY KEY);
             INSERT INTO @target SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(ISNULL(@tiendas, N''), N',') WHERE TRY_CONVERT(INT, value) IS NOT NULL;
             INSERT INTO dbo.msp_documentos_cobro_eventos
                (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento, id_usuario, payload_json, fecha_evento, es_evento_derivado)
             SELECT dc.id_contrato_arriendo, dc.id_documento_cobro, N'EMISION', N'DOCUMENTO', NULL, :payload_emision,
                    COALESCE(dc.fecha_registro, CAST(dc.fecha_emision AS DATETIME2(0)), SYSDATETIME()), 0
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.periodo_facturacion = @periodo
               AND (NULLIF(@tiendas, N'') IS NULL OR EXISTS (SELECT 1 FROM @target t WHERE t.id_tienda = dc.id_tienda))
               AND NOT EXISTS (SELECT 1 FROM dbo.msp_documentos_cobro_eventos ev WHERE ev.id_documento_cobro = dc.id_documento_cobro AND ev.tipo_evento = N'EMISION');
             IF :reemplazar = 1
                INSERT INTO dbo.msp_documentos_cobro_eventos
                    (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento, id_usuario, payload_json, fecha_evento)
                SELECT dc.id_contrato_arriendo, dc.id_documento_cobro, N'REGENERACION', N'SISTEMA', NULL, :payload_regeneracion, SYSDATETIME()
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.periodo_facturacion = @periodo
                  AND (NULLIF(@tiendas, N'') IS NULL OR EXISTS (SELECT 1 FROM @target t WHERE t.id_tienda = dc.id_tienda));"
        );
        $stmt->execute([':periodo' => $periodo, ':tiendas' => $tiendasCsv, ':payload_emision' => $payload, ':reemplazar' => $reemplazar, ':payload_regeneracion' => $payload]);
    }
}
