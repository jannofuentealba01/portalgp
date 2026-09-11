<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once __DIR__ . '/helper.php';

msp2RequireAccess('MSP Documentos Tienda Aprobacion', 'escritura');

$batchId = filter_var($_POST['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$confirmed = (string) ($_POST['confirmar_envio_demo'] ?? '') === '1';

try {
    if (!$confirmed) {
        throw new RuntimeException('Debes confirmar que el envío se realizará al correo de demostración.');
    }
    $batch = $batchId > 0 ? msp2DocumentosTiendaFetchBatch($conn, $batchId) : null;
    if (!is_array($batch)) {
        throw new RuntimeException('El lote no existe.');
    }
    msp2DocumentosTiendaAssertEditable($batch);
    $mailConfig = mspMailConfig();
    $demoTo = trim((string) ($mailConfig['demo']['to'] ?? ''));
    if (filter_var($demoTo, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('No hay un correo de demostración válido configurado.');
    }

    $stmt = $conn->prepare(
        "SELECT a.*,t.nombre_comercial,
                COALESCE(NULLIF(LTRIM(RTRIM(ar.nombre_locatario)),N''),NULLIF(LTRIM(RTRIM(ar.nombre_representante)),N''),ar.rut) AS nombre_arrendatario,
                ar.rut,locales.locales_label
         FROM dbo.msp_documentos_tienda_archivos a
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda=a.id_tienda
         INNER JOIN dbo.msp_arrendatarios ar ON ar.id_arrendatario=a.id_arrendatario
         OUTER APPLY (
            SELECT STUFF((SELECT N' / '+l.cdo_local
                          FROM dbo.msp_contrato_locales cl
                          INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                          WHERE cl.id_contrato_arriendo=a.id_contrato_arriendo
                          ORDER BY cl.orden_visual,cl.id_contrato_local
                          FOR XML PATH(''),TYPE).value('.','nvarchar(max)'),1,3,N'') AS locales_label
         ) locales
         WHERE a.id_lote_documentos_tienda=:lote AND a.estado_archivo IN (N'ASOCIADO',N'ERROR')
         ORDER BY a.id_contrato_arriendo,a.id_documento_tienda_archivo"
    );
    $stmt->execute([':lote' => $batchId]);
    $files = $stmt->fetchAll() ?: [];
    if ($files === []) {
        throw new RuntimeException('No hay documentos asociados pendientes de envío demo.');
    }

    $groups = [];
    foreach ($files as $file) {
        $groups[(int) $file['id_contrato_arriendo']][] = $file;
    }
    $groups = array_slice($groups, 0, 10, true);
    $sentGroups = 0;
    $failedGroups = 0;
    $sentFiles = 0;

    $updateSuccess = $conn->prepare(
        "UPDATE dbo.msp_documentos_tienda_archivos
         SET estado_archivo=N'ENVIADO',intentos_envio=intentos_envio+1,enviado_at=SYSDATETIME(),ultimo_error=NULL,updated_at=SYSDATETIME()
         WHERE id_documento_tienda_archivo=:archivo AND id_lote_documentos_tienda=:lote"
    );
    $updateError = $conn->prepare(
        "UPDATE dbo.msp_documentos_tienda_archivos
         SET estado_archivo=N'ERROR',intentos_envio=intentos_envio+1,ultimo_error=:error,updated_at=SYSDATETIME()
         WHERE id_documento_tienda_archivo=:archivo AND id_lote_documentos_tienda=:lote"
    );

    foreach ($groups as $contractId => $group) {
        $first = $group[0];
        try {
            $totalBytes = array_sum(array_map(static fn(array $row): int => (int) $row['bytes_archivo'], $group));
            if ($totalBytes > 18 * 1024 * 1024) {
                throw new RuntimeException('Los adjuntos del contrato superan el límite preventivo de 18 MB por correo.');
            }
            $mailer = mspMailBuildSmtp();
            $mailer->addAddress($demoTo);
            $storeName = trim((string) $first['nombre_comercial']);
            $tenantName = trim((string) $first['nombre_arrendatario']);
            $mailer->Subject = '[DEMO] Documentos de ' . $storeName . ' - lote #' . $batchId;

            $fileNames = [];
            foreach ($group as $file) {
                $absolute = msp2DocumentosTiendaAbsolutePath((string) $file['ruta_relativa']);
                if (!is_file($absolute)) {
                    throw new RuntimeException('Falta uno de los archivos asociados en el almacenamiento.');
                }
                $attachmentName = preg_replace('/[^A-Za-z0-9._ -]/u', '_', (string) $file['nombre_original']) ?: 'documento.pdf';
                $mailer->addAttachment($absolute, $attachmentName);
                $fileNames[] = (string) $file['nombre_original'];
            }

            $intendedEmail = trim((string) ($first['correo_destino_snapshot'] ?? ''));
            $lines = '';
            foreach ($fileNames as $name) {
                $lines .= '<li>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $mailer->isHTML(true);
            $mailer->Body = '<h2>Envío de demostración MSP</h2>'
                . '<p>Este correo se envió únicamente al destinatario de pruebas configurado. No fue enviado al arrendatario.</p>'
                . '<p><strong>Tienda:</strong> ' . htmlspecialchars($storeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
                . '<strong>Arrendatario:</strong> ' . htmlspecialchars($tenantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
                . '<strong>Contrato:</strong> #' . (int) $contractId . '<br>'
                . '<strong>Locales:</strong> ' . htmlspecialchars((string) ($first['locales_label'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
                . '<strong>Correo que correspondería:</strong> ' . htmlspecialchars($intendedEmail !== '' ? $intendedEmail : 'No configurado', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                . '<p><strong>Archivos adjuntos:</strong></p><ul>' . $lines . '</ul>';
            $mailer->AltBody = "ENVÍO DE DEMOSTRACIÓN MSP\nNo fue enviado al arrendatario.\nTienda: {$storeName}\nArrendatario: {$tenantName}\nContrato: #{$contractId}\nArchivos: " . implode(', ', $fileNames);
            $mailer->send();

            $conn->beginTransaction();
            foreach ($group as $file) {
                $fileId = (int) $file['id_documento_tienda_archivo'];
                $updateSuccess->execute([':archivo' => $fileId, ':lote' => $batchId]);
                msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'ENVIO_DEMO', 'Enviado al correo demo ' . $demoTo . '.');
                $sentFiles++;
            }
            $conn->commit();
            $sentGroups++;
        } catch (Throwable $groupError) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            pgpLogException($groupError, 'msp.documentos_tienda.envio_demo');
            $publicError = $groupError instanceof RuntimeException
                ? mb_substr($groupError->getMessage(), 0, 900, 'UTF-8')
                : 'No fue posible enviar este grupo al correo de demostración.';
            $conn->beginTransaction();
            foreach ($group as $file) {
                $fileId = (int) $file['id_documento_tienda_archivo'];
                $updateError->execute([':error' => $publicError, ':archivo' => $fileId, ':lote' => $batchId]);
                msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'ERROR_ENVIO', $publicError);
            }
            $conn->commit();
            $failedGroups++;
        }
    }

    msp2DocumentosTiendaRefreshBatch($conn, $batchId);
    $remainingStmt = $conn->prepare(
        "SELECT COUNT(DISTINCT id_contrato_arriendo) FROM dbo.msp_documentos_tienda_archivos
         WHERE id_lote_documentos_tienda=:lote AND estado_archivo IN (N'ASOCIADO',N'ERROR')"
    );
    $remainingStmt->execute([':lote' => $batchId]);
    $remaining = (int) $remainingStmt->fetchColumn();
    $message = 'Envío demo: ' . $sentGroups . ' correo(s), ' . $sentFiles . ' PDF(s).';
    if ($failedGroups > 0) {
        $message .= ' Grupos con error: ' . $failedGroups . '.';
    }
    if ($remaining > 0) {
        $message .= ' Quedan ' . $remaining . ' grupo(s) pendientes o reintentables.';
    }
    msp2SetFlash($failedGroups > 0 ? 'warning' : 'success', $message);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    pgpLogException($e, 'msp.documentos_tienda.envio_demo.general');
    msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible iniciar el envío de demostración.');
}

msp2Redirect('documentos_tienda/index.php?id_lote=' . max(0, $batchId));
