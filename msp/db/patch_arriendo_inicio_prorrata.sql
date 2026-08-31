/*
===========================================================================
 PATCH: prorrateo automático de arriendo durante el primer mes
 - Cobra desde la fecha efectiva de inicio, incluyéndola (+1 día).
 - Día 1: mes completo. Último día: 1/días_del_mes.
 - Mantiene sin cambios la regla vigente del mes de término.
 - Idempotente: no aplica dos veces sobre el mismo snapshot.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contrato_locales', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
BEGIN
    PRINT 'patch_arriendo_inicio_prorrata: faltan tablas base. Se omite.';
END
ELSE
BEGIN
    EXEC('
CREATE OR ALTER PROCEDURE dbo.msp_ajustar_snapshot_arriendo_inicio_prorrata
    @periodo_facturacion DATE,
    @target_tiendas_csv NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @periodo_facturacion IS NULL OR DAY(@periodo_facturacion) <> 1
    BEGIN
        THROW 50911, ''El periodo de facturacion debe ser el primer dia del mes.'', 1;
    END;

    DECLARE @mes_fin DATE = EOMONTH(@periodo_facturacion);
    DECLARE @dias_mes INT = DAY(@mes_fin);
    DECLARE @has_target BIT = 0;
    DECLARE @target_tiendas TABLE (id_tienda INT NOT NULL PRIMARY KEY);

    IF NULLIF(LTRIM(RTRIM(ISNULL(@target_tiendas_csv, N''''))), N'''') IS NOT NULL
    BEGIN
        INSERT INTO @target_tiendas (id_tienda)
        SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(value)) AS INT)
        FROM STRING_SPLIT(@target_tiendas_csv, N'','')
        WHERE TRY_CAST(LTRIM(RTRIM(value)) AS INT) > 0;
    END;
    IF EXISTS (SELECT 1 FROM @target_tiendas) SET @has_target = 1;

    ;WITH base AS (
        SELECT
            s.id_snapshot_arriendo,
            CASE WHEN cl.fecha_inicio > ca.fecha_inicio
                 THEN cl.fecha_inicio ELSE ca.fecha_inicio END AS fecha_inicio_efectiva,
            CASE
                WHEN cl.fecha_inicio > @periodo_facturacion OR ca.fecha_inicio > @periodo_facturacion THEN
                    CASE
                        WHEN cl.fecha_termino IS NOT NULL
                         AND cl.fecha_termino <= @mes_fin
                         AND (ca.fecha_termino_efectiva IS NULL OR cl.fecha_termino <= ca.fecha_termino_efectiva)
                            THEN cl.fecha_termino
                        WHEN ca.fecha_termino_efectiva IS NOT NULL
                         AND ca.fecha_termino_efectiva <= @mes_fin
                            THEN ca.fecha_termino_efectiva
                        ELSE @mes_fin
                    END
                ELSE @mes_fin
            END AS fecha_fin_cobro,
            CAST(ISNULL(s.monto_neto_clp, 0) AS DECIMAL(18,6)) AS neto_base_clp
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = s.id_contrato_local
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = s.id_contrato_arriendo
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
          AND cl.fecha_inicio <= @mes_fin
          AND ca.fecha_inicio <= @mes_fin
          AND (cl.fecha_inicio > @periodo_facturacion OR ca.fecha_inicio > @periodo_facturacion)
          AND (@has_target = 0 OR EXISTS (
                SELECT 1 FROM @target_tiendas tt WHERE tt.id_tienda = s.id_tienda
          ))
          AND (s.detalle_calculo IS NULL OR s.detalle_calculo NOT LIKE N''%Prorrateo inicio aplicado%'')
    ), calc AS (
        SELECT
            id_snapshot_arriendo,
            neto_base_clp,
            DATEDIFF(DAY, fecha_inicio_efectiva, fecha_fin_cobro) + 1 AS dias_cobrados
        FROM base
        WHERE fecha_inicio_efectiva BETWEEN @periodo_facturacion AND @mes_fin
          AND fecha_fin_cobro >= fecha_inicio_efectiva
    ), final_calc AS (
        SELECT
            id_snapshot_arriendo,
            dias_cobrados,
            CAST(dias_cobrados AS DECIMAL(18,6)) / NULLIF(@dias_mes, 0) AS factor_prorrata,
            CAST(ROUND(neto_base_clp * CAST(dias_cobrados AS DECIMAL(18,6)) / NULLIF(@dias_mes, 0), 2) AS DECIMAL(18,2)) AS neto_prorrateado_clp
        FROM calc
        WHERE dias_cobrados BETWEEN 1 AND @dias_mes
    )
    UPDATE s
       SET s.monto_neto_clp = fc.neto_prorrateado_clp,
           s.monto_iva_clp = CAST(ROUND(fc.neto_prorrateado_clp * 0.19, 2) AS DECIMAL(18,2)),
           s.monto_total_clp = CAST(ROUND(fc.neto_prorrateado_clp * 1.19, 2) AS DECIMAL(18,2)),
           s.detalle_calculo = LEFT(CONCAT(
                COALESCE(NULLIF(LTRIM(RTRIM(ISNULL(s.detalle_calculo, N''''))), N''''), N''Calculo base de arriendo aplicado.''),
                N'' | Prorrateo inicio aplicado (dias_cobrados='', fc.dias_cobrados,
                N'' de '', @dias_mes, N''; inicio inclusivo).''), 1000),
           s.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_arriendo_local_snapshot_periodo s
    INNER JOIN final_calc fc ON fc.id_snapshot_arriendo = s.id_snapshot_arriendo;

    SELECT @@ROWCOUNT AS snapshots_prorrateados_inicio;
END
');
END;
GO

PRINT 'patch_arriendo_inicio_prorrata aplicado.';
GO
