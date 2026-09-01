SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_cierre_mensual_transiciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cierre_mensual_transiciones (
        id_transicion BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cierre_mensual_transiciones PRIMARY KEY,
        id_cierre_mensual INT NOT NULL,
        estado_origen TINYINT NOT NULL,
        estado_destino TINYINT NOT NULL,
        motivo NVARCHAR(500) NULL,
        id_usuario INT NULL,
        fecha_transicion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cierre_transicion_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT FK_msp_cierre_transicion_cierre FOREIGN KEY(id_cierre_mensual)
            REFERENCES dbo.msp_cierre_mensual(id_cierre_mensual),
        CONSTRAINT CK_msp_cierre_transicion_origen CHECK(estado_origen IN(1,2,3,4,5)),
        CONSTRAINT CK_msp_cierre_transicion_destino CHECK(estado_destino IN(1,2,3,4,5))
    );
    CREATE INDEX IX_msp_cierre_transicion_cierre_fecha
        ON dbo.msp_cierre_mensual_transiciones(id_cierre_mensual,fecha_transicion DESC);
END;
GO

DECLARE @constraintName SYSNAME;
DECLARE @dropConstraintSql NVARCHAR(1000);
SELECT @constraintName=cc.name
FROM sys.check_constraints cc
WHERE cc.parent_object_id=OBJECT_ID(N'dbo.msp_cierre_mensual')
  AND cc.definition LIKE N'%estado_cierre%';
IF @constraintName IS NOT NULL
BEGIN
    SET @dropConstraintSql=N'ALTER TABLE dbo.msp_cierre_mensual DROP CONSTRAINT '+QUOTENAME(@constraintName);
    EXEC sys.sp_executesql @dropConstraintSql;
END;
ALTER TABLE dbo.msp_cierre_mensual WITH CHECK
ADD CONSTRAINT CK_msp_cierre_mensual_estado CHECK(estado_cierre IN(1,2,3,4,5));
GO

CREATE OR ALTER PROCEDURE dbo.msp_cierre_mensual_transicionar
    @id_cierre_mensual INT,
    @estado_esperado TINYINT,
    @estado_destino TINYINT,
    @motivo NVARCHAR(500)=NULL,
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @motivo=NULLIF(LTRIM(RTRIM(@motivo)),N'');

    BEGIN TRY
    BEGIN TRANSACTION;
    DECLARE @estado_actual TINYINT,@periodo DATE,@docs INT=0,@cobros INT=0;
    SELECT @estado_actual=estado_cierre,@periodo=periodo_facturacion
    FROM dbo.msp_cierre_mensual WITH(UPDLOCK,HOLDLOCK)
    WHERE id_cierre_mensual=@id_cierre_mensual;

    IF @estado_actual IS NULL
        THROW 53401,N'El período de cierre no existe.',1;
    IF @estado_actual<>@estado_esperado
        THROW 53402,N'El período cambió de estado mientras se procesaba la acción. Recarga la vista.',1;
    IF @estado_actual=@estado_destino
        THROW 53403,N'El período ya se encuentra en el estado solicitado.',1;
    IF NOT (
        (@estado_actual=1 AND @estado_destino=2) OR
        (@estado_actual=2 AND @estado_destino IN(1,5)) OR
        (@estado_actual=5 AND @estado_destino IN(1,3)) OR
        (@estado_actual=3 AND @estado_destino=1) OR
        (@estado_actual=4 AND @estado_destino=1)
    )
        THROW 53404,N'La transición solicitada no está permitida para el estado actual.',1;

    IF @estado_destino=1 AND @estado_actual IN(2,3,4,5) AND @motivo IS NULL
        THROW 53405,N'Debes indicar el motivo para devolver el período a Borrador.',1;

    IF @estado_destino IN(3,5)
    BEGIN
        SELECT @docs=COUNT(*) FROM dbo.msp_documentos_cobro WHERE periodo_facturacion=@periodo AND estado_documento<>5;
        IF OBJECT_ID(N'dbo.msp_cobros_servicios',N'U') IS NOT NULL
           AND OBJECT_ID(N'dbo.msp_lecturas_medidores',N'U') IS NOT NULL
           AND OBJECT_ID(N'dbo.msp_procesos_cobro_servicio',N'U') IS NOT NULL
            SELECT @cobros=COUNT(*)
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura=cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
            WHERE p.id_cierre_mensual=@id_cierre_mensual;
        IF @docs<=0 AND @cobros<=0
            THROW 53406,N'El período no tiene cobros ni documentos válidos para revisar o cerrar.',1;
    END;

    UPDATE dbo.msp_cierre_mensual SET estado_cierre=@estado_destino
    WHERE id_cierre_mensual=@id_cierre_mensual AND estado_cierre=@estado_esperado;
    IF @@ROWCOUNT<>1
        THROW 53407,N'No fue posible cambiar el estado del período.',1;

    INSERT dbo.msp_cierre_mensual_transiciones
        (id_cierre_mensual,estado_origen,estado_destino,motivo,id_usuario)
    VALUES(@id_cierre_mensual,@estado_actual,@estado_destino,@motivo,@id_usuario);

    COMMIT TRANSACTION;
    SELECT @id_cierre_mensual id_cierre_mensual,@estado_actual estado_origen,@estado_destino estado_destino;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO
