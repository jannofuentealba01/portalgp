/*
===========================================================================
 PATCH: estado operativo de contrato-local y base Control Diario
 - Neutraliza el SP historico de prorrata de termino.
 - Separa vigente_arriendo, en_liquidacion y cerrado_financiero.
 - Control Diario consume arriendo desde snapshot/documento, no desde reglas.
===========================================================================
*/

SET NOCOUNT ON;
GO

CREATE OR ALTER PROCEDURE dbo.msp_ajustar_snapshot_arriendo_termino_prorrata
    @periodo_facturacion DATE,
    @target_tiendas_csv NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @periodo_facturacion IS NULL OR DAY(@periodo_facturacion) <> 1
    BEGIN
        ;THROW 50901, 'El periodo de facturacion debe ser el primer dia del mes.', 1;
    END;

    SELECT CAST(0 AS INT) AS snapshots_prorrateados;
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_contrato_local_estado_operativo
AS
WITH periodos AS (
    SELECT periodo_facturacion
    FROM dbo.msp_cierre_mensual
    UNION
    SELECT periodo_facturacion
    FROM dbo.msp_documentos_cobro
    UNION
    SELECT periodo_facturacion
    FROM dbo.msp_arriendo_local_snapshot_periodo
    UNION
    SELECT DATEFROMPARTS(YEAR(fecha_cargo), MONTH(fecha_cargo), 1)
    FROM dbo.msp_cargos_contrato_local
    UNION
    SELECT DATEFROMPARTS(YEAR(fecha_cargo), MONTH(fecha_cargo), 1)
    FROM dbo.msp_cargos_salida
),
base AS (
    SELECT
        ca.id_contrato_arriendo,
        cl.id_contrato_local,
        ca.id_tienda,
        cl.id_local,
        p.periodo_facturacion,
        ca.estado_contrato,
        ca.fecha_inicio AS fecha_inicio_contrato,
        ca.fecha_termino_efectiva,
        cl.fecha_inicio AS fecha_inicio_local,
        cl.fecha_termino AS fecha_termino_local
    FROM dbo.msp_contrato_locales cl
    INNER JOIN dbo.msp_contratos_arriendo ca
        ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
    CROSS JOIN periodos p
),
flags AS (
    SELECT
        b.*,
        CAST(CASE
            WHEN b.estado_contrato IN (1,2,3)
             AND b.fecha_inicio_contrato <= EOMONTH(b.periodo_facturacion)
             AND (b.fecha_termino_efectiva IS NULL OR b.fecha_termino_efectiva >= b.periodo_facturacion)
             AND b.fecha_inicio_local <= EOMONTH(b.periodo_facturacion)
             AND (b.fecha_termino_local IS NULL OR b.fecha_termino_local >= b.periodo_facturacion)
                THEN 1
            ELSE 0
        END AS BIT) AS vigente_arriendo,
        CAST(CASE
            WHEN EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.id_tienda = b.id_tienda
                  AND dc.estado_documento IN (1,2,3)
                  AND ISNULL(dc.saldo_pendiente, 0) > 0.005
            )
             OR EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_contrato_local ccl
                WHERE ccl.id_contrato_local = b.id_contrato_local
                  AND ccl.estado_cargo IN (1,2)
            )
             OR EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_salida cs
                WHERE cs.id_contrato_arriendo = b.id_contrato_arriendo
                  AND cs.id_local = b.id_local
                  AND cs.estado_cargo IN (1,2)
            )
             OR EXISTS (
                SELECT 1
                FROM dbo.msp_garantias g
                INNER JOIN dbo.msp_vw_garantias_resumen gr
                    ON gr.id_garantia = g.id_garantia
                WHERE g.id_contrato_local = b.id_contrato_local
                  AND g.estado_garantia <> 6
                  AND (ISNULL(gr.saldo_disponible, 0) > 0.005 OR ISNULL(gr.saldo_reservado, 0) > 0.005)
            )
             OR EXISTS (
                SELECT 1
                FROM dbo.msp_medidores m_serv
                INNER JOIN dbo.msp_lecturas_medidores lm_serv
                    ON lm_serv.id_medidor = m_serv.id_medidor
                INNER JOIN dbo.msp_cobros_servicios cs_serv
                    ON cs_serv.id_lectura = lm_serv.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p_serv
                    ON p_serv.id_proceso_cobro = lm_serv.id_proceso_cobro
                INNER JOIN dbo.msp_cierre_mensual cm_serv
                    ON cm_serv.id_cierre_mensual = p_serv.id_cierre_mensual
                WHERE m_serv.id_local = b.id_local
                  AND cm_serv.periodo_facturacion = b.periodo_facturacion
            )
                THEN 1
            ELSE 0
        END AS BIT) AS tiene_pendientes
    FROM base b
)
SELECT
    f.id_contrato_arriendo,
    f.id_contrato_local,
    f.id_tienda,
    f.id_local,
    f.periodo_facturacion,
    f.vigente_arriendo,
    CAST(CASE
        WHEN f.vigente_arriendo = 0
         AND f.estado_contrato IN (3,4)
         AND f.tiene_pendientes = 1
            THEN 1
        ELSE 0
    END AS BIT) AS en_liquidacion,
    CAST(CASE
        WHEN f.estado_contrato = 4
         AND f.tiene_pendientes = 0
            THEN 1
        ELSE 0
    END AS BIT) AS cerrado_financiero,
    f.tiene_pendientes
FROM flags f
WHERE f.vigente_arriendo = 1
   OR f.tiene_pendientes = 1
   OR f.estado_contrato IN (3,4);
GO

CREATE OR ALTER VIEW dbo.msp_vw_control_diario_base
AS
WITH estado AS (
    SELECT
        eo.id_tienda,
        eo.id_contrato_arriendo,
        eo.periodo_facturacion,
        eo.vigente_arriendo,
        eo.en_liquidacion,
        eo.cerrado_financiero,
        eo.tiene_pendientes,
        l.cdo_local
    FROM dbo.msp_vw_contrato_local_estado_operativo eo
    INNER JOIN dbo.msp_locales l
        ON l.id_local = eo.id_local
),
estado_tienda AS (
    SELECT
        e.id_tienda,
        e.id_contrato_arriendo,
        e.periodo_facturacion,
        MAX(CAST(e.vigente_arriendo AS INT)) AS vigente_arriendo,
        MAX(CAST(e.en_liquidacion AS INT)) AS en_liquidacion,
        MAX(CAST(e.cerrado_financiero AS INT)) AS cerrado_financiero,
        MAX(CAST(e.tiene_pendientes AS INT)) AS tiene_pendientes,
        STRING_AGG(CONVERT(NVARCHAR(MAX), NULLIF(LTRIM(RTRIM(e.cdo_local)), N'')), N' / ') AS locales
    FROM (
        SELECT DISTINCT
            id_tienda,
            id_contrato_arriendo,
            periodo_facturacion,
            vigente_arriendo,
            en_liquidacion,
            cerrado_financiero,
            tiene_pendientes,
            cdo_local
        FROM estado
    ) e
    GROUP BY e.id_tienda, e.id_contrato_arriendo, e.periodo_facturacion
),
arriendo_snapshot AS (
    SELECT
        s.id_tienda,
        s.periodo_facturacion,
        SUM(s.monto_neto_clp) AS arriendo_neto
    FROM dbo.msp_arriendo_local_snapshot_periodo s
    WHERE s.estado_snapshot IN (1,2,3)
    GROUP BY s.id_tienda, s.periodo_facturacion
),
arriendo_docs AS (
    SELECT
        dc.id_tienda,
        dc.periodo_facturacion,
        SUM(dc.subtotal_arriendo) AS arriendo_neto
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.estado_documento <> 5
    GROUP BY dc.id_tienda, dc.periodo_facturacion
),
detalle_docs AS (
    SELECT
        dc.id_tienda,
        dc.periodo_facturacion,
        SUM(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN dcd.subtotal ELSE 0 END) AS electricidad,
        SUM(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN dcd.subtotal ELSE 0 END) AS gas,
        SUM(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN dcd.subtotal ELSE 0 END) AS agua,
        SUM(CASE
            WHEN tid.codigo_item NOT IN (N'ARRIENDO', N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                THEN dcd.subtotal
            ELSE 0
        END) AS cargos_reservas
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = dcd.id_documento_cobro
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    WHERE dc.estado_documento <> 5
    GROUP BY dc.id_tienda, dc.periodo_facturacion
),
estado_docs AS (
    SELECT
        dc.id_tienda,
        dc.periodo_facturacion,
        CASE
            WHEN MAX(CASE WHEN dc.estado_documento <> 5 AND ISNULL(dc.saldo_pendiente, 0) > 0.005 AND dc.fecha_vencimiento < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) = 1
                THEN N'ATRASADO'
            WHEN MAX(CASE WHEN dc.estado_documento <> 5 AND ISNULL(dc.saldo_pendiente, 0) > 0.005 THEN 1 ELSE 0 END) = 1
                THEN N'PENDIENTE'
            WHEN MAX(CASE WHEN dc.estado_documento <> 5 THEN 1 ELSE 0 END) = 1
                THEN N'OK'
            ELSE N'SIN DOCUMENTO'
        END AS estado_documento
    FROM dbo.msp_documentos_cobro dc
    GROUP BY dc.id_tienda, dc.periodo_facturacion
)
SELECT
    et.id_tienda,
    et.id_contrato_arriendo,
    ca.id_arrendatario,
    et.periodo_facturacion,
    COALESCE(NULLIF(t.nombre_comercial, N''), CONCAT(N'Tienda #', et.id_tienda)) AS nombre_tienda,
    COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), N'Sin arrendatario') AS arrendatario,
    a.rut,
    et.locales,
    CAST(COALESCE(asn.arriendo_neto, ad.arriendo_neto, 0) AS DECIMAL(18,2)) AS arriendo_neto,
    CAST(ISNULL(dd.electricidad, 0) AS DECIMAL(18,2)) AS electricidad,
    CAST(ISNULL(dd.gas, 0) AS DECIMAL(18,2)) AS gas,
    CAST(ISNULL(dd.agua, 0) AS DECIMAL(18,2)) AS agua,
    CAST(ISNULL(dd.cargos_reservas, 0) AS DECIMAL(18,2)) AS cargos_reservas,
    ISNULL(ed.estado_documento, N'SIN DOCUMENTO') AS estado_documento,
    CAST(et.vigente_arriendo AS BIT) AS vigente_arriendo,
    CAST(et.en_liquidacion AS BIT) AS en_liquidacion,
    CAST(et.cerrado_financiero AS BIT) AS cerrado_financiero,
    CAST(et.tiene_pendientes AS BIT) AS tiene_pendientes,
    CAST(CASE
        WHEN ca.fecha_termino_efectiva >= et.periodo_facturacion
         AND ca.fecha_termino_efectiva <= EOMONTH(et.periodo_facturacion)
            THEN 1
        ELSE 0
    END AS BIT) AS marca_termino
FROM estado_tienda et
INNER JOIN dbo.msp_contratos_arriendo ca
    ON ca.id_contrato_arriendo = et.id_contrato_arriendo
INNER JOIN dbo.msp_tiendas t
    ON t.id_tienda = et.id_tienda
LEFT JOIN dbo.msp_arrendatarios a
    ON a.id_arrendatario = ca.id_arrendatario
LEFT JOIN arriendo_snapshot asn
    ON asn.id_tienda = et.id_tienda
   AND asn.periodo_facturacion = et.periodo_facturacion
LEFT JOIN arriendo_docs ad
    ON ad.id_tienda = et.id_tienda
   AND ad.periodo_facturacion = et.periodo_facturacion
LEFT JOIN detalle_docs dd
    ON dd.id_tienda = et.id_tienda
   AND dd.periodo_facturacion = et.periodo_facturacion
LEFT JOIN estado_docs ed
    ON ed.id_tienda = et.id_tienda
   AND ed.periodo_facturacion = et.periodo_facturacion;
GO

PRINT 'patch_estado_operativo_control_diario aplicado.';
GO
