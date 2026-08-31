<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';
require_once __DIR__ . '/pago_contrato_archivos_helper.php';
require_once __DIR__ . '/saldo_favor_periodo_helper.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('pagos/index.php');
}

$previewPayload = msp2PagosPreviewSessionRead();
if (!is_array($previewPayload)) {
    msp2SetFlash('warning', 'No hay una previsualización pendiente para confirmar.');
    msp2Redirect('pagos/index.php');
}

$validRows = $previewPayload['valid_rows'] ?? [];
if (!is_array($validRows) || $validRows === []) {
    msp2SetFlash('warning', 'La previsualización no contiene filas válidas para importar.');
    msp2PagosPreviewSessionClear();
    msp2Redirect('pagos/index.php');
}

function msp2ImportFallbackPagoId(PDO $conn, int $idDocumentoCobro): int
{
    if ($idDocumentoCobro <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT TOP 1 id_pago
         FROM dbo.msp_pagos
         WHERE id_documento_cobro = :id_documento_cobro
           AND estado_pago = 1
         ORDER BY id_pago DESC"
    );
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->execute();

    return (int) ($stmt->fetchColumn() ?: 0);
}

function msp2ImportDetalleConceptosPorPago(PDO $conn, int $idPago, int $idDocumentoCobro): array
{
    if ($idPago <= 0 || $idDocumentoCobro <= 0) {
        return [];
    }

    $sql = "SELECT
                pdc.id_tipo_item_documento,
                tid.nombre_item,
                tid.codigo_item,
                ROUND(SUM(pdc.monto_aplicado), 2) AS monto
            FROM dbo.msp_pagos_detalle_concepto pdc
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = pdc.id_tipo_item_documento
            WHERE pdc.id_pago = :id_pago
              AND pdc.id_documento_cobro = :id_documento_cobro
            GROUP BY
                pdc.id_tipo_item_documento,
                tid.nombre_item,
                tid.codigo_item
            ORDER BY tid.codigo_item ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_pago', $idPago, PDO::PARAM_INT);
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->execute();

    $detalle = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $detalle[] = [
            'id_tipo_item_documento' => (int) ($row['id_tipo_item_documento'] ?? 0),
            'nombre_item' => trim((string) ($row['nombre_item'] ?? '')),
            'codigo_item' => trim((string) ($row['codigo_item'] ?? '')),
            'monto' => (float) ($row['monto'] ?? 0),
            'detalle_items' => '',
        ];
    }

    return $detalle;
}

function msp2ImportFetchPdfContext(PDO $conn, int $idDocumentoCobro): ?array
{
    if ($idDocumentoCobro <= 0) {
        return null;
    }

    $hasContratos = msp2TableExists($conn, 'msp_contratos_arriendo');
    $hasContratoLocales = msp2TableExists($conn, 'msp_contrato_locales');
    $hasLocales = msp2TableExists($conn, 'msp_locales');
    $hasArrCorreos = msp2TableExists($conn, 'msp_arrendatarios_correos');
    $hasFechaTerminoEfectiva = $hasContratos && msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $hasFechaTerminoLocal = $hasContratoLocales && msp2ColumnExists($conn, 'msp_contrato_locales', 'fecha_termino');

    $condicionTerminoContrato = $hasFechaTerminoEfectiva
        ? '(ca_loc.fecha_termino_efectiva IS NULL OR ca_loc.fecha_termino_efectiva >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionTerminoLocal = $hasFechaTerminoLocal
        ? '(cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionMesSiguienteTerminoLocal = $hasFechaTerminoLocal
        ? ' OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(cl.fecha_termino), MONTH(cl.fecha_termino), 1)) = dc.periodo_facturacion'
        : '';

    $contratoVigenteApply = $hasContratos
        ? "OUTER APPLY (
                SELECT TOP 1 ca_loc.id_contrato_arriendo, ca_loc.id_arrendatario
                FROM dbo.msp_contratos_arriendo ca_loc
                WHERE ca_loc.id_contrato_arriendo = dc.id_contrato_arriendo
                   OR (
                        dc.id_contrato_arriendo IS NULL
                    AND ca_loc.id_tienda = dc.id_tienda
                    AND ca_loc.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                    AND $condicionTerminoContrato
                    AND ca_loc.estado_contrato IN (1,2,3)
                   )
                ORDER BY ca_loc.fecha_inicio DESC, ca_loc.id_contrato_arriendo DESC
             ) contrato_vigente"
        : "OUTER APPLY (
                SELECT
                    CAST(NULL AS INT) AS id_contrato_arriendo,
                    CAST(NULL AS INT) AS id_arrendatario
             ) contrato_vigente";

    $localesApply = ($hasContratoLocales && $hasLocales && $hasContratos)
        ? "OUTER APPLY (
                SELECT
                    STUFF((
                        SELECT N' / ' + l.cdo_local
                        FROM dbo.msp_contrato_locales cl
                        INNER JOIN dbo.msp_locales l
                            ON l.id_local = cl.id_local
                        WHERE cl.id_contrato_arriendo = contrato_vigente.id_contrato_arriendo
                          AND cl.estado_relacion IN (1,2)
                          AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                          AND (
                                $condicionTerminoLocal
                                $condicionMesSiguienteTerminoLocal
                          )
                        ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . "
                        FOR XML PATH(''), TYPE
                    ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS locales_contrato
             ) loc"
        : "OUTER APPLY (
                SELECT CAST(N'' AS NVARCHAR(250)) AS locales_contrato
             ) loc";

    $correoJoin = $hasArrCorreos
        ? 'LEFT JOIN dbo.msp_arrendatarios_correos ac
                ON ac.id_arrendatario = a.id_arrendatario'
        : '';
    $correoSelect = $hasArrCorreos
        ? 'MAX(CASE WHEN ac.es_principal = 1 THEN ac.correo END) AS correo_principal'
        : "CAST(N'' AS NVARCHAR(320)) AS correo_principal";

    $sql = "SELECT TOP 1
                dc.id_documento_cobro,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                ROUND(ISNULL(dc.saldo_pendiente, 0), 2) AS saldo_pendiente_nuevo,
                ISNULL(COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo), 0) AS id_contrato_arriendo,
                a.id_arrendatario,
                COALESCE(
                    NULLIF(LTRIM(RTRIM(dc.nombre_arrendatario_snapshot)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                    NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                    NULLIF(LTRIM(RTRIM(a.rut)), ''),
                    CONCAT(N'Arrendatario #', a.id_arrendatario)
                ) AS nombre_arrendatario,
                COALESCE(NULLIF(LTRIM(RTRIM(dc.rut_arrendatario_snapshot)), ''), LTRIM(RTRIM(a.rut)), '') AS rut,
                COALESCE(NULLIF(loc.locales_contrato, ''), '') AS locales_contrato,
                $correoSelect
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            $contratoVigenteApply
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = COALESCE(contrato_vigente.id_arrendatario, t.id_arrendatario)
            $localesApply
            $correoJoin
            WHERE dc.id_documento_cobro = :id_documento_cobro
            GROUP BY
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.saldo_pendiente,
                dc.id_contrato_arriendo,
                contrato_vigente.id_contrato_arriendo,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot,
                a.id_arrendatario,
                a.nombre_locatario,
                a.nombre_representante,
                a.rut,
                loc.locales_contrato";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
        'id_contrato_arriendo' => (int) ($row['id_contrato_arriendo'] ?? 0),
        'id_arrendatario' => (int) ($row['id_arrendatario'] ?? 0),
        'arr_data' => [
            'nombre_arrendatario' => (string) ($row['nombre_arrendatario'] ?? ''),
            'rut' => (string) ($row['rut'] ?? ''),
            'correo_principal' => (string) ($row['correo_principal'] ?? ''),
        ],
        'doc_data' => [
            'id_documento_cobro' => (int) ($row['id_documento_cobro'] ?? 0),
            'numero_documento' => (string) ($row['numero_documento'] ?? ''),
            'periodo_ym' => (string) ($row['periodo_ym'] ?? ''),
            'locales_contrato' => (string) ($row['locales_contrato'] ?? ''),
            'saldo_pendiente_nuevo' => (float) ($row['saldo_pendiente_nuevo'] ?? 0),
        ],
    ];
}

try {
    $targetDocumentIds = [];
    foreach ($validRows as $row) {
        $targetDocumentId = (int) ($row['id_documento_cobro'] ?? 0);
        if ($targetDocumentId > 0) {
            $targetDocumentIds[$targetDocumentId] = true;
        }
    }

    if ($targetDocumentIds !== []) {
        $placeholders = [];
        $docIds = array_keys($targetDocumentIds);
        foreach ($docIds as $index => $idDocumento) {
            $placeholders[] = ':doc_' . $index;
        }

        $stmt = $conn->prepare(
            'SELECT id_documento_cobro, COUNT(*) AS total
             FROM dbo.msp_pagos
             WHERE estado_pago = 1
               AND id_documento_cobro IN (' . implode(', ', $placeholders) . ')
             GROUP BY id_documento_cobro'
        );
        foreach ($docIds as $index => $idDocumento) {
            $stmt->bindValue(':doc_' . $index, $idDocumento, PDO::PARAM_INT);
        }
        $stmt->execute();
        $activeRows = $stmt->fetchAll() ?: [];
        if ($activeRows !== []) {
            msp2SetFlash('danger', 'Uno o más documentos destino ya tienen pagos activos. Vuelve a generar el preview.');
            msp2PagosPreviewSessionClear();
            msp2Redirect('pagos/index.php');
        }
    }

    usort($validRows, static function (array $a, array $b): int {
        return ((int) ($a['orden_replay'] ?? 0)) <=> ((int) ($b['orden_replay'] ?? 0));
    });

    $conn->beginTransaction();

    $registrados = 0;
    $pdfArchiveItems = [];
    $saldoFavorPeriodoErrores = 0;
    foreach ($validRows as $row) {
        $pagoUid = (string) ($row['pago_uid'] ?? '');
        $idDocumentoCobro = (int) ($row['id_documento_cobro'] ?? 0);
        $fechaPago = (string) ($row['fecha_pago'] ?? '');
        $montoPagado = (string) ($row['monto_pagado'] ?? '0.00');
        $medioPago = trim((string) ($row['medio_pago'] ?? ''));
        $referenciaPago = trim((string) ($row['referencia_pago'] ?? ''));
        $observaciones = trim((string) ($row['observaciones'] ?? ''));
        $detalleConceptosJson = (string) ($row['detalle_conceptos_json'] ?? '[]');
        $aplicaDesdeSaldoFavor = (int) ($row['aplica_desde_saldo_favor'] ?? 0) === 1;

        try {
            if ($aplicaDesdeSaldoFavor) {
                $stmt = $conn->prepare(
                    'EXEC dbo.msp_aplicar_saldo_favor_documento
                        @id_documento_cobro = :id_documento_cobro,
                        @fecha_pago = :fecha_pago,
                        @monto_aplicar = :monto_aplicar,
                        @observaciones = :observaciones,
                        @detalle_conceptos_json = :detalle_conceptos_json'
                );
                $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
                $stmt->bindValue(':fecha_pago', $fechaPago, PDO::PARAM_STR);
                $stmt->bindValue(':monto_aplicar', $montoPagado, PDO::PARAM_STR);
                $stmt->bindValue(':observaciones', $observaciones === '' ? null : $observaciones, $observaciones === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':detalle_conceptos_json', $detalleConceptosJson, PDO::PARAM_STR);
                $stmt->execute();
                $resultado = $stmt->fetch() ?: [];
                $stmt->closeCursor();
            } else {
                $stmt = $conn->prepare(
                    'EXEC dbo.msp_registrar_pago_documento
                        @id_documento_cobro = :id_documento_cobro,
                        @fecha_pago = :fecha_pago,
                        @monto_pagado = :monto_pagado,
                        @medio_pago = :medio_pago,
                        @referencia_pago = :referencia_pago,
                        @observaciones = :observaciones,
                        @detalle_conceptos_json = :detalle_conceptos_json'
                );
                $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
                $stmt->bindValue(':fecha_pago', $fechaPago, PDO::PARAM_STR);
                $stmt->bindValue(':monto_pagado', $montoPagado, PDO::PARAM_STR);
                $stmt->bindValue(':medio_pago', $medioPago === '' ? null : $medioPago, $medioPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':referencia_pago', $referenciaPago === '' ? null : $referenciaPago, $referenciaPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':observaciones', $observaciones === '' ? null : $observaciones, $observaciones === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindValue(':detalle_conceptos_json', $detalleConceptosJson, PDO::PARAM_STR);
                $stmt->execute();
                $resultado = $stmt->fetch() ?: [];
                $stmt->closeCursor();
            }

            $idPagoGenerado = (int) ($resultado['id_pago_generado'] ?? 0);
            if ($idPagoGenerado <= 0) {
                $idPagoGenerado = msp2ImportFallbackPagoId($conn, $idDocumentoCobro);
            }

            $montoPagadoFloat = round((float) $montoPagado, 2);
            $montoAplicado = isset($resultado['monto_aplicado_documento'])
                ? round((float) $resultado['monto_aplicado_documento'], 2)
                : (isset($resultado['monto_aplicado']) ? round((float) $resultado['monto_aplicado'], 2) : $montoPagadoFloat);
            $montoExcedente = isset($resultado['monto_saldo_favor_generado'])
                ? round((float) $resultado['monto_saldo_favor_generado'], 2)
                : round(max(0, $montoPagadoFloat - $montoAplicado), 2);

            if ($montoExcedente > 0.005 && $idPagoGenerado > 0) {
                $syncOk = msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
                    $conn,
                    $idPagoGenerado,
                    $idDocumentoCobro,
                    $montoExcedente,
                    $fechaPago
                );
                if (!$syncOk) {
                    $saldoFavorPeriodoErrores++;
                }
            }

            if ($idPagoGenerado > 0) {
                $pdfContext = msp2ImportFetchPdfContext($conn, $idDocumentoCobro);
                if ($pdfContext !== null) {
                    $detalleConceptos = msp2ImportDetalleConceptosPorPago($conn, $idPagoGenerado, $idDocumentoCobro);
                    $pagoDataPdf = [
                        'id_pago' => $idPagoGenerado,
                        'fecha_pago' => $fechaPago,
                        'monto_pagado' => $montoPagadoFloat,
                        'monto_aplicado' => $montoAplicado,
                        'saldo_favor_aplicado' => $aplicaDesdeSaldoFavor ? $montoAplicado : 0.0,
                        'medio_pago' => $medioPago,
                        'referencia_pago' => $referenciaPago,
                        'banco' => '',
                        'observaciones' => $observaciones,
                        'detalle_conceptos' => $detalleConceptos,
                    ];

                    $baseArchive = [
                        'id_pago' => $idPagoGenerado,
                        'id_documento_cobro' => (int) ($pdfContext['id_documento_cobro'] ?? $idDocumentoCobro),
                        'id_contrato_arriendo' => (int) ($pdfContext['id_contrato_arriendo'] ?? 0),
                        'id_arrendatario' => (int) ($pdfContext['id_arrendatario'] ?? 0),
                        'module' => 'PAGO_CONTRATO',
                    ];
                    $arrData = (array) ($pdfContext['arr_data'] ?? []);
                    $docData = (array) ($pdfContext['doc_data'] ?? []);

                    $pdfArchiveItems[] = $baseArchive + [
                        'type' => 'vale_pago',
                        'pago_data' => $pagoDataPdf,
                        'arr_data' => $arrData,
                        'doc_data' => $docData,
                    ];

                    if (((float) ($docData['saldo_pendiente_nuevo'] ?? 0)) <= 0.005) {
                        $pdfArchiveItems[] = $baseArchive + [
                            'type' => 'comprobante_gastos',
                            'pago_data' => $pagoDataPdf,
                            'arr_data' => $arrData,
                            'doc_data' => $docData,
                        ];
                    }
                }
            }
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Falló la recreación del pago ' . ($pagoUid !== '' ? $pagoUid : '#' . ($registrados + 1)) . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $registrados++;
    }

    $conn->commit();

    $pdfGuardados = 0;
    $pdfErrores = 0;
    $pdfModo = 'ninguno';
    $pdfPendientes = 0;
    if ($pdfArchiveItems !== []) {
        try {
            $maxRenderSync = 20;
            if (count($pdfArchiveItems) > $maxRenderSync && function_exists('msp2ArchivosPdfRegisterMetadataMany')) {
                // Para importaciones grandes evitamos render DomPDF síncrono (timeout).
                $archiveResult = msp2ArchivosPdfRegisterMetadataMany($conn, $pdfArchiveItems);
                $pdfGuardados = count($archiveResult['saved'] ?? []);
                $pdfErrores = count($archiveResult['errors'] ?? []);
                $pdfPendientes = $pdfGuardados;
                $pdfModo = 'metadata';
            } else {
                $archiveResult = msp2PagoContratoArchivosArchiveMany($conn, $pdfArchiveItems);
                $pdfGuardados = count($archiveResult['saved'] ?? []);
                $pdfErrores = count($archiveResult['errors'] ?? []);
                $pdfModo = 'render';
            }
        } catch (Throwable) {
            $pdfErrores = count($pdfArchiveItems);
        }
    }

    msp2PagosPreviewSessionClear();
    $mensaje = 'Importación completada: ' . $registrados . ' pagos recreados correctamente.';
    if ($pdfArchiveItems !== []) {
        if ($pdfModo === 'metadata') {
            $mensaje .= ' Respaldos PDF registrados: ' . $pdfGuardados . ' (pendientes de regeneración).';
            if ($pdfPendientes > 0) {
                $mensaje .= ' Puedes regenerarlos desde "Respaldo PDFs".';
            }
        } else {
            $mensaje .= ' PDFs archivados: ' . $pdfGuardados . '.';
        }
        if ($pdfErrores > 0) {
            $mensaje .= ' No se pudieron guardar ' . $pdfErrores . ' respaldo(s) PDF.';
        }
    }
    if ($saldoFavorPeriodoErrores > 0) {
        $mensaje .= ' ' . $saldoFavorPeriodoErrores . ' excedente(s) no se reflejaron automáticamente en el período siguiente.';
    }
    msp2SetFlash($pdfErrores > 0 ? 'warning' : 'success', $mensaje);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible confirmar la importación del respaldo.');
}

msp2Redirect('pagos/index.php');
