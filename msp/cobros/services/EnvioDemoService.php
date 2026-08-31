<?php
declare(strict_types=1);

final class EnvioDemoService
{
    public static function createDemoJob(
        PDO $conn,
        string $periodoYm,
        string $periodoFacturacion,
        string $demoDestinoRaw,
        array $arrIdsInput,
        int $maxDemoEmails = 10
    ): array {
        $demoDestino = mb_strtolower(msp2NormalizeText($demoDestinoRaw), 'UTF-8');
        if (filter_var($demoDestino, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Debes indicar un correo destino demo valido.');
        }

        [$arrIds, $totalSeleccionados] = self::parseArrendatarioIds($arrIdsInput);
        if ($arrIds === []) {
            throw new RuntimeException('Selecciona al menos un arrendatario para el envio demo.');
        }

        if ($totalSeleccionados > $maxDemoEmails) {
            $arrIds = array_slice($arrIds, 0, $maxDemoEmails);
        }

        [$arrRows, $docsByArr] = self::fetchArrendatariosYDocumentos($conn, $periodoFacturacion, $arrIds);

        if ($arrRows === []) {
            throw new RuntimeException('No hay documentos del periodo para los arrendatarios seleccionados.');
        }

        return [
            'periodo_ym' => $periodoYm,
            'periodo_facturacion' => $periodoFacturacion,
            'demo_destino' => $demoDestino,
            'arr_rows' => $arrRows,
            'docs_by_arr' => $docsByArr,
            'index' => 0,
            'sent' => 0,
            'failed' => 0,
            'errors' => [],
            'total' => count($arrRows),
            'max_demo' => $maxDemoEmails,
            'selected_total' => $totalSeleccionados,
        ];
    }

    public static function processDemoJobBatch(PDO $conn, array $job, int $batchSize = 1): array
    {
        $total = (int) ($job['total'] ?? 0);
        if ($total <= 0) {
            throw new RuntimeException('No hay correos pendientes para enviar.');
        }

        $processedThisBatch = 0;
        while ($processedThisBatch < $batchSize && (int) ($job['index'] ?? 0) < $total) {
            $index = (int) $job['index'];
            $arrRow = $job['arr_rows'][$index] ?? null;
            $job['index'] = $index + 1;
            $processedThisBatch++;

            if (!is_array($arrRow)) {
                continue;
            }

            $arrId = (int) ($arrRow['id_arrendatario'] ?? 0);
            $docs = $job['docs_by_arr'][$arrId] ?? [];
            if ($docs === []) {
                continue;
            }

            try {
                self::sendOneDemoEmail(
                    $conn,
                    (string) ($job['demo_destino'] ?? ''),
                    $arrRow,
                    $docs,
                    (string) ($job['periodo_ym'] ?? ''),
                    true
                );
                $job['sent'] = (int) ($job['sent'] ?? 0) + 1;
            } catch (Throwable $sendError) {
                $job['failed'] = (int) ($job['failed'] ?? 0) + 1;
                if (count((array) ($job['errors'] ?? [])) < 3) {
                    $nombreArr = trim((string) ($arrRow['nombre_arrendatario'] ?? ''));
                    if ($nombreArr === '') {
                        $nombreArr = $arrId > 0 ? 'Arrendatario #' . $arrId : 'Arrendatario';
                    }
                    $job['errors'][] = $nombreArr . ': ' . self::formatSendError($sendError);
                }
            }
        }

        $processed = (int) ($job['index'] ?? 0);
        $done = $processed >= $total;
        $percent = $total > 0 ? (int) round(($processed / $total) * 100) : 100;

        $message = '';
        if ($done) {
            $message = self::buildFinalMessage(
                (string) ($job['demo_destino'] ?? ''),
                (int) ($job['sent'] ?? 0),
                (int) ($job['failed'] ?? 0),
                (int) ($job['selected_total'] ?? 0),
                (int) ($job['max_demo'] ?? 0),
                (array) ($job['errors'] ?? [])
            );
        }

        return [
            'job' => $job,
            'done' => $done,
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
            'sent' => (int) ($job['sent'] ?? 0),
            'failed' => (int) ($job['failed'] ?? 0),
            'message' => $message,
        ];
    }

    public static function enviarDemoSincrono(
        PDO $conn,
        string $periodoYm,
        string $periodoFacturacion,
        string $demoDestinoRaw,
        array $arrIdsInput,
        int $maxDemoEmails = 10
    ): array {
        $job = self::createDemoJob($conn, $periodoYm, $periodoFacturacion, $demoDestinoRaw, $arrIdsInput, $maxDemoEmails);

        $sent = 0;
        $failed = 0;
        $errorMessages = [];

        foreach ((array) ($job['arr_rows'] ?? []) as $arrRow) {
            if (!is_array($arrRow)) {
                continue;
            }

            $arrId = (int) ($arrRow['id_arrendatario'] ?? 0);
            if ($arrId <= 0) {
                continue;
            }

            $docs = (array) (($job['docs_by_arr'] ?? [])[$arrId] ?? []);
            if ($docs === []) {
                continue;
            }

            try {
                self::sendOneDemoEmail(
                    $conn,
                    (string) ($job['demo_destino'] ?? ''),
                    $arrRow,
                    $docs,
                    $periodoYm,
                    false
                );
                $sent++;
            } catch (Throwable $sendError) {
                $failed++;
                if (count($errorMessages) < 3) {
                    $nombreArr = trim((string) ($arrRow['nombre_arrendatario'] ?? ''));
                    if ($nombreArr === '') {
                        $nombreArr = 'Arrendatario #' . $arrId;
                    }
                    $errorMessages[] = $nombreArr . ': ' . self::formatSendError($sendError);
                }
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'message' => self::buildFinalMessage(
                (string) ($job['demo_destino'] ?? ''),
                $sent,
                $failed,
                (int) ($job['selected_total'] ?? 0),
                (int) ($job['max_demo'] ?? 0),
                $errorMessages
            ),
        ];
    }

    private static function parseArrendatarioIds(array $arrIdsInput): array
    {
        $arrIdsMap = [];
        foreach ($arrIdsInput as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($id === false || $id === null) {
                continue;
            }
            $arrIdsMap[(int) $id] = true;
        }

        $arrIds = array_keys($arrIdsMap);
        return [$arrIds, count($arrIds)];
    }

    private static function fetchArrendatariosYDocumentos(PDO $conn, string $periodoFacturacion, array $arrIds): array
    {
        $placeholders = [];
        foreach ($arrIds as $index => $arrId) {
            $placeholders[] = ':arr_' . $index;
        }
        $arrFilterSql = implode(', ', $placeholders);

        $correoTableExiste = msp2TableExists($conn, 'msp_arrendatarios_correos');
        $correoSelect = $correoTableExiste
            ? 'ISNULL(correo_principal.correo, \'\') AS correo_principal'
            : "'' AS correo_principal";
        $correoJoin = $correoTableExiste
            ? 'OUTER APPLY (
                    SELECT TOP 1 ac.correo
                    FROM dbo.msp_arrendatarios_correos ac
                    WHERE ac.id_arrendatario = a.id_arrendatario
                    ORDER BY ac.es_principal DESC, ac.id_arrendatario_correo ASC
               ) correo_principal'
            : '';

        $arrStmt = $conn->prepare(
            "SELECT DISTINCT
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT('Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                LTRIM(RTRIM(a.rut)) AS rut,
                $correoSelect
             FROM dbo.msp_arrendatarios a
             INNER JOIN dbo.msp_tiendas t
                ON t.id_arrendatario = a.id_arrendatario
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_tienda = t.id_tienda
             $correoJoin
             WHERE dc.periodo_facturacion = :periodo
               AND dc.estado_documento <> 5
               AND a.id_arrendatario IN ($arrFilterSql)
             ORDER BY nombre_arrendatario ASC"
        );
        $arrStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        foreach ($arrIds as $index => $arrId) {
            $arrStmt->bindValue(':arr_' . $index, $arrId, PDO::PARAM_INT);
        }
        $arrStmt->execute();
        $arrRows = $arrStmt->fetchAll() ?: [];

        $docsStmt = $conn->prepare(
            "SELECT
                a.id_arrendatario,
                dc.id_documento_cobro,
                dc.numero_documento,
                dc.monto_total,
                dc.saldo_pendiente
             FROM dbo.msp_arrendatarios a
             INNER JOIN dbo.msp_tiendas t
                ON t.id_arrendatario = a.id_arrendatario
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_tienda = t.id_tienda
             WHERE dc.periodo_facturacion = :periodo
               AND dc.estado_documento <> 5
               AND a.id_arrendatario IN ($arrFilterSql)
             ORDER BY a.id_arrendatario ASC, dc.id_documento_cobro ASC"
        );
        $docsStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        foreach ($arrIds as $index => $arrId) {
            $docsStmt->bindValue(':arr_' . $index, $arrId, PDO::PARAM_INT);
        }
        $docsStmt->execute();

        $docsByArr = [];
        $seenDocsByArr = [];
        while (($docRow = $docsStmt->fetch()) !== false) {
            $arrId = (int) ($docRow['id_arrendatario'] ?? 0);
            $docId = (int) ($docRow['id_documento_cobro'] ?? 0);
            if ($arrId <= 0) {
                continue;
            }
            if ($docId <= 0 || isset($seenDocsByArr[$arrId][$docId])) {
                continue;
            }
            $seenDocsByArr[$arrId][$docId] = true;
            $docsByArr[$arrId][] = $docRow;
        }

        return [$arrRows, $docsByArr];
    }

    private static function sendOneDemoEmail(
        PDO $conn,
        string $demoDestino,
        array $arrRow,
        array $docs,
        string $periodoYm,
        bool $useTemplateRenderer
    ): void {
        if ($useTemplateRenderer) {
            [$subject, $body, $altBody] = omBuildCobroEmailContent($conn, $arrRow, $docs, $periodoYm);
        } else {
            [$subject, $body, $altBody] = self::buildLegacyMailContent($arrRow, $docs, $periodoYm);
        }

        $mail = omBuildSmtpMailerFromEnv();
        $mail->addAddress($demoDestino);
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

    private static function buildLegacyMailContent(array $arrRow, array $docs, string $periodoYm): array
    {
        $arrId = (int) ($arrRow['id_arrendatario'] ?? 0);
        $nombreArr = trim((string) ($arrRow['nombre_arrendatario'] ?? ''));
        if ($nombreArr === '') {
            $nombreArr = $arrId > 0 ? 'Arrendatario #' . $arrId : 'Arrendatario';
        }
        $rutArr = trim((string) ($arrRow['rut'] ?? ''));
        $correoReal = trim((string) ($arrRow['correo_principal'] ?? ''));

        $subject = '[DEMO MSP] Cobro ' . $periodoYm . ' - ' . $nombreArr;

        $rowsHtml = '';
        $rowsText = [];
        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $docId = (int) ($doc['id_documento_cobro'] ?? 0);
            $docNumero = trim((string) ($doc['numero_documento'] ?? ''));
            $docMonto = omFmtNum($doc['monto_total'] ?? 0, 2);
            $docSaldo = omFmtNum($doc['saldo_pendiente'] ?? 0, 2);

            $rowsHtml .= '<tr>'
                . '<td style="padding:6px 8px;border:1px solid #ddd;">#' . $docId . '</td>'
                . '<td style="padding:6px 8px;border:1px solid #ddd;">' . htmlspecialchars($docNumero !== '' ? $docNumero : '-', ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 8px;border:1px solid #ddd;text-align:right;">$ ' . htmlspecialchars($docMonto, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 8px;border:1px solid #ddd;text-align:right;">$ ' . htmlspecialchars($docSaldo, ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';

            $rowsText[] = '#' . $docId
                . ' | Num: ' . ($docNumero !== '' ? $docNumero : '-')
                . ' | Monto: $ ' . $docMonto
                . ' | Saldo: $ ' . $docSaldo;
        }

        $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">'
            . '<h2 style="margin:0 0 10px;">Demo de cobro MSP</h2>'
            . '<p style="margin:0 0 12px;">Este correo es una <strong>prueba</strong>. No fue enviado al arrendatario real.</p>'
            . '<p style="margin:0 0 6px;"><strong>Periodo:</strong> ' . htmlspecialchars($periodoYm, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 6px;"><strong>Arrendatario:</strong> ' . htmlspecialchars($nombreArr, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 6px;"><strong>RUT:</strong> ' . htmlspecialchars($rutArr !== '' ? $rutArr : '-', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 12px;"><strong>Correo real (no usado):</strong> ' . htmlspecialchars($correoReal !== '' ? $correoReal : '-', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
            . '<thead><tr style="background:#f5f5f5;">'
            . '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">Documento</th>'
            . '<th style="padding:6px 8px;border:1px solid #ddd;text-align:left;">Numero</th>'
            . '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">Monto</th>'
            . '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">Saldo</th>'
            . '</tr></thead><tbody>'
            . $rowsHtml
            . '</tbody></table></div>';

        $altBody = 'Demo de cobro MSP' . PHP_EOL
            . 'Este correo es una prueba. No fue enviado al arrendatario real.' . PHP_EOL
            . 'Periodo: ' . $periodoYm . PHP_EOL
            . 'Arrendatario: ' . $nombreArr . PHP_EOL
            . 'RUT: ' . ($rutArr !== '' ? $rutArr : '-') . PHP_EOL
            . 'Correo real (no usado): ' . ($correoReal !== '' ? $correoReal : '-') . PHP_EOL
            . 'Documentos:' . PHP_EOL
            . implode(PHP_EOL, $rowsText);

        return [$subject, $body, $altBody];
    }

    private static function buildFinalMessage(
        string $demoDestino,
        int $sent,
        int $failed,
        int $totalSeleccionados,
        int $maxDemoEmails,
        array $errors
    ): string {
        $message = 'Envio demo a ' . $demoDestino . ': enviados ' . $sent . ', fallidos ' . $failed . '.';
        if ($totalSeleccionados > $maxDemoEmails) {
            $message .= ' Se seleccionaron ' . $totalSeleccionados . ' y se limito a los primeros ' . $maxDemoEmails . '.';
        }
        if ($errors !== []) {
            $message .= ' Errores: ' . implode(' | ', $errors);
        }

        return $message;
    }

    private static function formatSendError(Throwable $error): string
    {
        $msg = trim($error->getMessage());
        if ($msg !== '') {
            return $msg;
        }

        if (property_exists($error, 'errorInfo')) {
            $raw = (string) ($error->errorInfo ?? '');
            $raw = trim($raw);
            if ($raw !== '') {
                return $raw;
            }
        }

        return 'Error de envio sin detalle (' . $error::class . ').';
    }
}
