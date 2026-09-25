<?php
declare(strict_types=1);

final class PortalInicioService
{
    public function __construct(private PDO $conn)
    {
    }

    /** @return array<string,mixed> */
    public function cargar(bool $puedeOperacion, bool $puedeFinanzas): array
    {
        $ahora = new DateTimeImmutable('now');
        $periodo = $ahora->format('Y-m-01');
        $resultado = [
            'periodo' => $periodo,
            'periodo_ym' => $ahora->format('Y-m'),
            'periodo_texto' => $this->nombrePeriodo($ahora),
            'operacion' => $this->operacionVacia(),
            'finanzas' => $this->finanzasVacias(),
        ];

        if ($puedeOperacion) {
            $resultado['operacion'] = $this->cargarOperacion($periodo);
        }
        if ($puedeFinanzas) {
            $resultado['finanzas'] = $this->cargarFinanzas($periodo, $ahora->format('Y-m-d'));
        }
        return $resultado;
    }

    /** @return array<string,mixed> */
    private function operacionVacia(): array
    {
        return [
            'disponible' => false,
            'estado_codigo' => null,
            'estado' => 'No disponible',
            'documentos_generados' => null,
            'documentos_pendientes' => null,
            'documentos_esperados' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function finanzasVacias(): array
    {
        return [
            'disponible' => false,
            'facturado' => null,
            'cobrado' => null,
            'recaudacion_pct' => null,
            'documentos_vencidos' => null,
            'deuda_vencida' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function cargarOperacion(string $periodo): array
    {
        try {
            $stmt = $this->conn->prepare(
                "WITH docs AS (
                    SELECT COUNT_BIG(*) AS generados
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.periodo_facturacion = :periodo_docs
                      AND dc.estado_documento <> 5
                ),
                pool AS (
                    SELECT COUNT_BIG(*) AS total,
                        SUM(CASE WHEN p.estado_pool IN (1, 2) THEN CONVERT(bigint, 1) ELSE CONVERT(bigint, 0) END) AS pendientes
                    FROM dbo.msp_pool_documentos_periodo p
                    WHERE p.periodo_facturacion = :periodo_pool
                )
                SELECT cm.estado_cierre,
                    CASE cm.estado_cierre
                        WHEN 1 THEN N'Borrador' WHEN 2 THEN N'Calculado'
                        WHEN 3 THEN N'Cerrado' WHEN 4 THEN N'Anulado'
                        WHEN 5 THEN N'Revisado' ELSE N'No creado'
                    END AS estado_periodo,
                    CONVERT(bigint, docs.generados) AS documentos_generados,
                    CONVERT(bigint, ISNULL(pool.pendientes, 0)) AS documentos_pendientes,
                    CONVERT(bigint, ISNULL(pool.total, 0)) AS documentos_esperados
                FROM docs CROSS JOIN pool
                LEFT JOIN dbo.msp_cierre_mensual cm ON cm.periodo_facturacion = :periodo_cierre"
            );
            $stmt->bindValue(':periodo_docs', $periodo, PDO::PARAM_STR);
            $stmt->bindValue(':periodo_pool', $periodo, PDO::PARAM_STR);
            $stmt->bindValue(':periodo_cierre', $periodo, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return $this->operacionVacia();
            }
            return [
                'disponible' => true,
                'estado_codigo' => $row['estado_cierre'] !== null ? (int) $row['estado_cierre'] : null,
                'estado' => trim((string) ($row['estado_periodo'] ?? 'No creado')),
                'documentos_generados' => (int) ($row['documentos_generados'] ?? 0),
                'documentos_pendientes' => (int) ($row['documentos_pendientes'] ?? 0),
                'documentos_esperados' => (int) ($row['documentos_esperados'] ?? 0),
            ];
        } catch (Throwable) {
            return $this->operacionVacia();
        }
    }

    /** @return array<string,mixed> */
    private function cargarFinanzas(string $periodo, string $hoy): array
    {
        try {
            $stmt = $this->conn->prepare(
                "WITH docs_periodo AS (
                    SELECT dc.id_documento_cobro, dc.monto_total
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.periodo_facturacion = :periodo AND dc.estado_documento <> 5
                ),
                pagos_doc AS (
                    SELECT p.id_documento_cobro, SUM(p.monto_pagado) AS total_pagado
                    FROM dbo.msp_pagos p
                    INNER JOIN docs_periodo d ON d.id_documento_cobro = p.id_documento_cobro
                    WHERE p.estado_pago = 1 AND p.fecha_pago <= :hoy_pagos
                    GROUP BY p.id_documento_cobro
                ),
                recaudacion AS (
                    SELECT ISNULL(SUM(d.monto_total), 0) AS facturado,
                        ISNULL(SUM(ISNULL(p.total_pagado, 0)), 0) AS cobrado
                    FROM docs_periodo d
                    LEFT JOIN pagos_doc p ON p.id_documento_cobro = d.id_documento_cobro
                ),
                vencida AS (
                    SELECT COUNT_BIG(*) AS documentos, ISNULL(SUM(dc.saldo_pendiente), 0) AS monto
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.estado_documento IN (2, 3)
                      AND dc.saldo_pendiente > 0.005
                      AND dc.fecha_vencimiento < :hoy_vencida
                )
                SELECT r.facturado, r.cobrado,
                    CASE WHEN r.facturado > 0 THEN CONVERT(decimal(9, 2), r.cobrado * 100.0 / r.facturado) ELSE NULL END AS recaudacion_pct,
                    CONVERT(bigint, v.documentos) AS documentos_vencidos, v.monto AS deuda_vencida
                FROM recaudacion r CROSS JOIN vencida v"
            );
            $stmt->bindValue(':periodo', $periodo, PDO::PARAM_STR);
            $stmt->bindValue(':hoy_pagos', $hoy, PDO::PARAM_STR);
            $stmt->bindValue(':hoy_vencida', $hoy, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return $this->finanzasVacias();
            }
            return [
                'disponible' => true,
                'facturado' => (float) ($row['facturado'] ?? 0),
                'cobrado' => (float) ($row['cobrado'] ?? 0),
                'recaudacion_pct' => $row['recaudacion_pct'] !== null ? (float) $row['recaudacion_pct'] : null,
                'documentos_vencidos' => (int) ($row['documentos_vencidos'] ?? 0),
                'deuda_vencida' => (float) ($row['deuda_vencida'] ?? 0),
            ];
        } catch (Throwable) {
            return $this->finanzasVacias();
        }
    }

    private function nombrePeriodo(DateTimeImmutable $fecha): string
    {
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        return ($meses[(int) $fecha->format('n')] ?? $fecha->format('m')) . ' ' . $fecha->format('Y');
    }
}
