/*
===========================================================================
 PATCH: prorrateo de arriendo en mes de termino efectivo
 - Agrega SP auxiliar para ajustar snapshot mensual de arriendo.
 - Regla: si el contrato termina dentro del periodo, prorratea neto por dias ocupados.
 - Idempotente: no vuelve a aplicar si ya se marco en detalle_calculo.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contrato_locales', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
BEGIN
    PRINT 'patch_arriendo_termino_prorrata: faltan tablas base. Se omite.';
END
ELSE
BEGIN
    EXEC(' 
CREATE OR ALTER PROCEDURE dbo.msp_ajustar_snapshot_arriendo_termino_prorrata
    @periodo_facturacion DATE,
    @target_tiendas_csv NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @periodo_facturacion IS NULL OR DAY(@periodo_facturacion) <> 1
    BEGIN
        ;THROW 50901, ''El periodo de facturacion debe ser el primer dia del mes.'', 1;
    END;

    DECLARE @mes_fin DATE = EOMONTH(@periodo_facturacion);
    DECLARE @dias_mes DECIMAL(18,6) = CAST(DAY(@mes_fin) AS DECIMAL(18,6));
    DECLARE @has_target BIT = 0;

    DECLARE @target_tiendas TABLE (
        id_tienda INT NOT NULL PRIMARY KEY
    );

    IF NULLIF(LTRIM(RTRIM(ISNULL(@target_tiendas_csv, N''''))), N'''') IS NOT NULL
    BEGIN
        INSERT INTO @target_tiendas (id_tienda)
        SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT)
        FROM STRING_SPLIT(@target_tiendas_csv, N'','') ss
        WHERE TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) IS NOT NULL
          AND TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) > 0;
    END;

    IF EXISTS (SELECT 1 FROM @target_tiendas)
    BEGIN
        SET @has_target = 1;
    END;

    ;WITH base AS (
        SELECT
            s.id_snapshot_arriendo,
            s.id_tienda,
            s.codigo_grupo_modalidad,
            ca.fecha_termino_efectiva,
            CASE
                WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio
                ELSE @periodo_facturacion
            END AS fecha_inicio_ocupacion,
            CASE
                WHEN ca.fecha_termino_efectiva < @mes_fin THEN ca.fecha_termino_efectiva
                ELSE @mes_fin
            END AS fecha_fin_ocupacion,
            CAST(ISNULL(s.monto_neto_clp, 0) AS DECIMAL(18,6)) AS neto_base_clp
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = s.id_contrato_local
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = s.id_contrato_arriendo
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
          AND ca.fecha_termino_efectiva IS NOT NULL
          AND ca.fecha_termino_efectiva >= @periodo_facturacion
          AND ca.fecha_termino_efectiva <= @mes_fin
          AND (@has_target = 0 OR EXISTS (
                SELECT 1
                FROM @target_tiendas tt
                WHERE tt.id_tienda = s.id_tienda
          ))
          AND (s.detalle_calculo IS NULL OR s.detalle_calculo NOT LIKE N''%Prorrateo termino aplicado%'')
    ),
    calc AS (
        SELECT
            b.id_snapshot_arriendo,
            b.id_tienda,
            b.neto_base_clp,
            CASE
                WHEN b.fecha_fin_ocupacion < b.fecha_inicio_ocupacion THEN 0
                ELSE DATEDIFF(DAY, b.fecha_inicio_ocupacion, b.fecha_fin_ocupacion) + 1
            END AS dias_ocupados
        FROM base b
    ),
    final_calc AS (
        SELECT
            c.id_snapshot_arriendo,
            c.id_tienda,
            c.dias_ocupados,
            CAST(
                CASE
                    WHEN @dias_mes <= 0 THEN 0
                    WHEN c.dias_ocupados <= 0 THEN 0
                    WHEN CAST(c.dias_ocupados AS DECIMAL(18,6)) >= @dias_mes THEN 1
                    ELSE CAST(c.dias_ocupados AS DECIMAL(18,6)) / @dias_mes
                END
                AS DECIMAL(18,6)
            ) AS factor_prorrata,
            CAST(ROUND(
                CASE
                    WHEN c.neto_base_clp <= 0 THEN 0
                    WHEN c.dias_ocupados <= 0 THEN 0
                    WHEN CAST(c.dias_ocupados AS DECIMAL(18,6)) >= @dias_mes THEN c.neto_base_clp
                    ELSE c.neto_base_clp * (CAST(c.dias_ocupados AS DECIMAL(18,6)) / @dias_mes)
                END,
                2
            ) AS DECIMAL(18,2)) AS neto_prorrateado_clp
        FROM calc c
    )
    UPDATE s
    SET
        s.monto_neto_clp = fc.neto_prorrateado_clp,
        s.monto_iva_clp = CAST(ROUND(fc.neto_prorrateado_clp * 0.19, 2) AS DECIMAL(18,2)),
        s.monto_total_clp = CAST(ROUND(fc.neto_prorrateado_clp * 1.19, 2) AS DECIMAL(18,2)),
        s.formula_json = CASE
            WHEN NULLIF(LTRIM(RTRIM(ISNULL(s.formula_json, N''''))), N'''') IS NULL THEN
                CONCAT(
                    N''{"prorrata_termino":{"dias_ocupados":'', CONVERT(NVARCHAR(10), fc.dias_ocupados),
                    N'',"dias_mes":'', CONVERT(NVARCHAR(10), DAY(@mes_fin)),
                    N'',"factor":'', CONVERT(NVARCHAR(40), fc.factor_prorrata),
                    N'',"neto_prorrateado_clp":'', CONVERT(NVARCHAR(40), fc.neto_prorrateado_clp),
                    N''}}''
                )
            ELSE s.formula_json
        END,
        s.detalle_calculo = LEFT(
            CONCAT(
                COALESCE(NULLIF(LTRIM(RTRIM(ISNULL(s.detalle_calculo, N''''))), N''''), N''Calculo base de arriendo aplicado.''),
                N'' | Prorrateo termino aplicado (dias_ocupados='', CONVERT(NVARCHAR(10), fc.dias_ocupados),
                N'' de '', CONVERT(NVARCHAR(10), DAY(@mes_fin)), N'').''
            ),
            1000
        ),
        s.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_arriendo_local_snapshot_periodo s
    INNER JOIN final_calc fc
        ON fc.id_snapshot_arriendo = s.id_snapshot_arriendo;

    SELECT @@ROWCOUNT AS snapshots_prorrateados;
END
');
END;
GO

PRINT 'patch_arriendo_termino_prorrata aplicado.';
GO
