<?php
declare(strict_types=1);

final class OperacionMensualService
{
    private const AGUA_FORMULA_EXCEL_FROM = '2026-04-01';

    public static function generarCobros(PDO $conn, int $idCierre, bool $reemplazar, array $selectedServices): int
    {
        if ($idCierre <= 0) {
            throw new RuntimeException('El cierre mensual indicado no existe.');
        }
        $servicios = [];
        foreach ($selectedServices as $service) {
            $code = strtoupper(trim((string) $service));
            if ($code === '' || preg_match('/^[A-Z_]+$/', $code) !== 1) {
                continue;
            }
            if (!in_array($code, ['AGUA', 'LUZ', 'GAS'], true)) {
                continue;
            }
            $servicios[$code] = true;
        }

        if ($servicios === []) {
            throw new RuntimeException('Debes seleccionar al menos un servicio para generar cobros.');
        }

        $stmt = $conn->prepare(
            'EXEC dbo.msp_generar_cobros_periodo
                @id_cierre = :id_cierre,
                @reemplazar = :reemplazar,
                @servicios_csv = :servicios_csv'
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':reemplazar', $reemplazar ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':servicios_csv', implode(',', array_keys($servicios)), PDO::PARAM_STR);
        $stmt->execute();

        $row = self::fetchFirstRowsetRow($stmt);
        if (isset($servicios['AGUA'])) {
            self::applyAguaFormulaByPeriodo($conn, $idCierre);
        }

        return (int) ($row['cobros_generados'] ?? 0);
    }

    private static function applyAguaFormulaByPeriodo(PDO $conn, int $idCierre): void
    {
        $stmt = $conn->prepare(
            "UPDATE cs
             SET
                cs.subtotal_variable = calc.subtotal_variable,
                cs.cargo_fijo = calc.cargo_fijo,
                cs.monto_total = calc.monto_total,
                cs.formula_version = N'V3_AGUA_EXCEL_2604',
                cs.detalle_calculo = N'AGUA Excel: cargo fijo completo por medidor + consumo * ((SAP + SAL + TAS)/divisor)'
             FROM dbo.msp_cobros_servicios cs
             INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
             INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
             INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = p.id_tipo_servicio
             INNER JOIN dbo.msp_cierre_mensual c
                ON c.id_cierre_mensual = p.id_cierre_mensual
             LEFT JOIN dbo.msp_proceso_cobro_agua pa
                ON pa.id_proceso_cobro = p.id_proceso_cobro
             CROSS APPLY (
                SELECT
                    CAST(ISNULL(ROUND(
                        cs.consumo_cobrado * (
                            (
                                ISNULL(pa.servicio_agua_potable, 0)
                                + ISNULL(pa.servicio_alcantarillado, 0)
                                + ISNULL(pa.tratamiento_aguas_servidas, 0)
                            ) / NULLIF(pa.divisor, 0)
                        ), 2), 0) AS DECIMAL(18,2)) AS subtotal_variable,
                    CAST(ROUND(ISNULL(pa.cargo_fijo, 0), 2) AS DECIMAL(18,2)) AS cargo_fijo,
                    CAST(ISNULL(ROUND(
                        cs.consumo_cobrado * (
                            (
                                ISNULL(pa.servicio_agua_potable, 0)
                                + ISNULL(pa.servicio_alcantarillado, 0)
                                + ISNULL(pa.tratamiento_aguas_servidas, 0)
                            ) / NULLIF(pa.divisor, 0)
                        ) + ISNULL(pa.cargo_fijo, 0), 2), 0) AS DECIMAL(18,2)) AS monto_total
             ) calc
             WHERE p.id_cierre_mensual = :id_cierre
               AND UPPER(ts.codigo_servicio) = N'AGUA'
               AND c.periodo_facturacion >= :from_periodo"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':from_periodo', self::AGUA_FORMULA_EXCEL_FROM, PDO::PARAM_STR);
        $stmt->execute();
    }

    public static function borrarGeneracion(
        PDO $conn,
        int $idCierre,
        bool $borrarDocumentos,
        bool $borrarCobros,
        bool $borrarPagos,
        bool $borrarCargosSalidaAsociados
    ): array {
        if ($idCierre <= 0) {
            throw new RuntimeException('El cierre mensual indicado no existe.');
        }
        $ownsTransaction = !$conn->inTransaction();
        try {
            if ($ownsTransaction) {
                $conn->beginTransaction();
            }
            if ($borrarPagos || $borrarDocumentos) {
                self::assertNoProtectedFinancialHistory($conn, $idCierre);
            }

            $stmt = $conn->prepare(
                'EXEC dbo.msp_borrar_generacion_periodo
                    @id_cierre = :id_cierre,
                    @del_docs = :del_docs,
                    @del_cobros = :del_cobros,
                    @del_pagos = :del_pagos,
                    @del_cargos_salida_asociados = :del_cargos_salida_asociados'
            );
            $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $stmt->bindValue(':del_docs', $borrarDocumentos ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':del_cobros', $borrarCobros ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':del_pagos', $borrarPagos ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':del_cargos_salida_asociados', $borrarCargosSalidaAsociados ? 1 : 0, PDO::PARAM_INT);
            $stmt->execute();
            $row = self::fetchFirstRowsetRow($stmt);
            $stmt->closeCursor();

            if ($ownsTransaction) {
                $conn->commit();
            }

            return [
                'docs_borrados' => (int) ($row['docs_borrados'] ?? 0),
                'items_borrados' => (int) ($row['items_borrados'] ?? 0),
                'cobros_borrados' => (int) ($row['cobros_borrados'] ?? 0),
                'pagos_borrados' => (int) ($row['pagos_borrados'] ?? 0),
                'cargos_salida_desvinculados' => (int) ($row['cargos_salida_desvinculados'] ?? 0),
                'saldo_favor_aplicaciones_desvinculadas' => 0,
                'pago_contrato_detalle_borrado' => 0,
                'archivos_pdf_borrados' => 0,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $exception;
        }
    }

    private static function assertNoProtectedFinancialHistory(PDO $conn, int $idCierre): void
    {
        $stmt = $conn->prepare(
            "DECLARE @periodo DATE;
             SELECT @periodo = periodo_facturacion
             FROM dbo.msp_cierre_mensual
             WHERE id_cierre_mensual = :id_cierre;

             SELECT
                (SELECT COUNT(*)
                 FROM dbo.msp_pagos p
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                 WHERE dc.periodo_facturacion = @periodo) AS pagos,
                (SELECT COUNT(*)
                 FROM dbo.msp_envio_lote_documentos eld
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = eld.id_documento_cobro
                 WHERE dc.periodo_facturacion = @periodo) AS envios,
                (SELECT COUNT(*)
                 FROM dbo.msp_documentos_cobro_eventos ev
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = ev.id_documento_cobro
                 WHERE dc.periodo_facturacion = @periodo
                   AND ev.tipo_evento <> N'EMISION') AS eventos_operativos,
                (SELECT COUNT(*)
                 FROM dbo.msp_garantia_documento_aplicaciones a
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=a.id_documento_cobro
                 WHERE dc.periodo_facturacion=@periodo) AS aplicaciones_garantia,
                (SELECT COUNT(*)
                 FROM dbo.msp_saldo_favor_periodo_aplicaciones a
                 WHERE a.periodo_facturacion=@periodo) AS aplicaciones_saldo,
                (SELECT COUNT(*)
                 FROM dbo.msp_pago_contrato_archivos a
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=a.id_documento_cobro
                 WHERE dc.periodo_facturacion=@periodo) AS respaldos"
        );
        $stmt->execute([':id_cierre' => $idCierre]);
        $row = $stmt->fetch() ?: [];
        $pagos = (int) ($row['pagos'] ?? 0);
        $envios = (int) ($row['envios'] ?? 0);
        $eventos = (int) ($row['eventos_operativos'] ?? 0);
        $garantias = (int) ($row['aplicaciones_garantia'] ?? 0);
        $saldos = (int) ($row['aplicaciones_saldo'] ?? 0);
        $respaldos = (int) ($row['respaldos'] ?? 0);
        if ($pagos > 0 || $envios > 0 || $eventos > 0 || $garantias > 0 || $saldos > 0 || $respaldos > 0) {
            throw new RuntimeException(
                'No se puede borrar la generación porque el período ya tiene pagos, envíos, aplicaciones, respaldos o eventos financieros. '
                . 'Usa anulación, reapertura o correcciones para conservar la trazabilidad.'
            );
        }
    }

    private static function detachSaldoFavorAplicacionesByCierre(PDO $conn, int $idCierre): int
    {
        $existsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL"
        );
        $tableExists = $existsStmt !== false && $existsStmt->fetchColumn() !== false;
        if (!$tableExists) {
            return 0;
        }

        $deleteStmt = $conn->prepare(
            "WITH cierre AS (
                SELECT c.periodo_facturacion
                FROM dbo.msp_cierre_mensual c
                WHERE c.id_cierre_mensual = :id_cierre
             )
             DELETE sfa
             FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
             INNER JOIN cierre c
                ON c.periodo_facturacion = sfa.periodo_facturacion
             WHERE sfa.estado_aplicacion = 1
                OR sfa.id_pago IS NOT NULL
                OR sfa.id_documento_cobro IS NOT NULL"
        );
        $deleteStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $deleteStmt->execute();
        return (int) $deleteStmt->rowCount();
    }

    private static function detachLoteDocumentLinksByCierre(PDO $conn, int $idCierre): void
    {
        $existsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_envio_lote_documentos', N'U') IS NOT NULL"
        );
        $tableExists = $existsStmt !== false && $existsStmt->fetchColumn() !== false;
        if (!$tableExists) {
            return;
        }

        $stmt = $conn->prepare(
            "DELETE eld
             FROM dbo.msp_envio_lote_documentos eld
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = eld.id_documento_cobro
             WHERE dc.periodo_facturacion = (
                SELECT c.periodo_facturacion
                FROM dbo.msp_cierre_mensual c
                WHERE c.id_cierre_mensual = :id_cierre
             )"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function detachPoolDocumentLinksByCierre(PDO $conn, int $idCierre): void
    {
        $existsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U') IS NOT NULL"
        );
        $tableExists = $existsStmt !== false && $existsStmt->fetchColumn() !== false;
        if (!$tableExists) {
            return;
        }

        $stmt = $conn->prepare(
            "UPDATE p
             SET
                p.id_documento_cobro = NULL,
                p.estado_pool = CASE
                    WHEN p.estado_pool = 4 THEN 4
                    WHEN p.ready_luz = 1 THEN 2
                    ELSE 1
                END,
                p.updated_at = SYSDATETIME()
             FROM dbo.msp_pool_documentos_periodo p
             WHERE p.periodo_facturacion = (
                SELECT c.periodo_facturacion
                FROM dbo.msp_cierre_mensual c
                WHERE c.id_cierre_mensual = :id_cierre
             )
               AND p.id_documento_cobro IS NOT NULL"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function detachPagoContratoOperacionDetalleByCierre(PDO $conn, int $idCierre): int
    {
        $existsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_pago_contrato_operacion_detalle', N'U') IS NOT NULL"
        );
        $tableExists = $existsStmt !== false && $existsStmt->fetchColumn() !== false;
        if (!$tableExists) {
            return 0;
        }

        $stmt = $conn->prepare(
            "DELETE pcod
             FROM dbo.msp_pago_contrato_operacion_detalle pcod
             INNER JOIN dbo.msp_pagos p
                ON p.id_pago = pcod.id_pago
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE dc.periodo_facturacion = (
                SELECT c.periodo_facturacion
                FROM dbo.msp_cierre_mensual c
                WHERE c.id_cierre_mensual = :id_cierre
             )"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->rowCount();
    }

    private static function detachPagoContratoArchivosByCierre(
        PDO $conn,
        int $idCierre,
        bool $borrarDocumentos,
        bool $borrarPagos
    ): int {
        $existsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NOT NULL"
        );
        $tableExists = $existsStmt !== false && $existsStmt->fetchColumn() !== false;
        if (!$tableExists) {
            return 0;
        }

        $totalBorrados = 0;

        if (
            $borrarPagos
            && msp2TableExists($conn, 'msp_pagos')
            && msp2TableExists($conn, 'msp_documentos_cobro')
        ) {
            $stmtPagos = $conn->prepare(
                "DELETE a
                 FROM dbo.msp_pago_contrato_archivos a
                 INNER JOIN dbo.msp_pagos p
                    ON p.id_pago = a.id_pago
                 INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_documento_cobro = p.id_documento_cobro
                 WHERE dc.periodo_facturacion = (
                    SELECT c.periodo_facturacion
                    FROM dbo.msp_cierre_mensual c
                    WHERE c.id_cierre_mensual = :id_cierre
                 )"
            );
            $stmtPagos->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $stmtPagos->execute();
            $totalBorrados += (int) $stmtPagos->rowCount();
        }

        if ($borrarDocumentos && msp2TableExists($conn, 'msp_documentos_cobro')) {
            $stmtDocs = $conn->prepare(
                "DELETE a
                 FROM dbo.msp_pago_contrato_archivos a
                 INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_documento_cobro = a.id_documento_cobro
                 WHERE dc.periodo_facturacion = (
                    SELECT c.periodo_facturacion
                    FROM dbo.msp_cierre_mensual c
                    WHERE c.id_cierre_mensual = :id_cierre
                 )"
            );
            $stmtDocs->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
            $stmtDocs->execute();
            $totalBorrados += (int) $stmtDocs->rowCount();
        }

        return $totalBorrados;
    }

    private static function fetchFirstRowsetRow(PDOStatement $stmt): array|false
    {
        try {
            while (true) {
                $columnCount = 0;
                try {
                    $columnCount = $stmt->columnCount();
                } catch (PDOException $exception) {
                    if (!str_contains($exception->getMessage(), 'contains no fields')) {
                        throw $exception;
                    }
                }

                if ($columnCount > 0) {
                    while (true) {
                        try {
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        } catch (PDOException $exception) {
                            if (str_contains($exception->getMessage(), 'contains no fields')) {
                                break;
                            }
                            throw $exception;
                        }

                        if ($row === false) {
                            break;
                        }
                        if (is_array($row)) {
                            return $row;
                        }
                    }
                }

                try {
                    if (!$stmt->nextRowset()) {
                        break;
                    }
                } catch (PDOException $exception) {
                    if (!str_contains($exception->getMessage(), 'contains no fields')) {
                        throw $exception;
                    }
                    break;
                }
            }
        } finally {
            try {
                $stmt->closeCursor();
            } catch (Throwable) {
                // El cursor puede quedar ya cerrado si el driver descarta rowsets intermedios.
            }
        }

        return false;
    }
}
