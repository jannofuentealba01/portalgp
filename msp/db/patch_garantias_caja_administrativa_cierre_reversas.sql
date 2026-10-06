/* Puntos 8–9. Ejecutar con sqlcmd -b. No modifica datos ni deshabilita triggers.
   Conserva las definiciones desplegadas; si no coinciden las anclas, aborta
   toda la migracion en lugar de reemplazar una version desconocida. */
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.msp_garantia_devolucion_solicitudes',N'U') IS NULL
    THROW 53930,N'Instale primero base e integracion de caja administrativa.',1;
IF NOT EXISTS(SELECT 1 FROM sys.triggers
              WHERE object_id=OBJECT_ID(N'dbo.TR_msp_garantia_reversas_integridad') AND is_disabled=0)
    THROW 53930,N'Falta el trigger activo de integridad de reversas.',1;
IF NOT EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_acc_tesoreria_garantias') AND is_disabled=0)
   OR NOT EXISTS(SELECT 1 FROM sys.triggers WHERE object_id=OBJECT_ID(N'dbo.TR_msp_acc_garantia_reversas') AND is_disabled=0)
    THROW 53930,N'Faltan los triggers contables activos de tesoreria o reversas.',1;
GO
CREATE OR ALTER PROCEDURE dbo.msp_garantia_caja_admin_validar_periodo
    @id_cuenta_tesoreria INT,@fecha DATE
AS
BEGIN
    SET NOCOUNT ON;
    IF @@TRANCOUNT=0 OR @fecha IS NULL
        THROW 53931,N'La validacion del periodo requiere fecha y transaccion.',1;
    DECLARE @tipo NVARCHAR(20);
    SELECT @tipo=tipo_cuenta FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK)
    WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria;
    IF @tipo IS NULL THROW 53931,N'No existe la cuenta del movimiento financiero.',1;
    -- Una reapertura autorizada no es un cierre activo. Se conserva su bitacora.
    IF @tipo=N'CAJA' AND EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre>=@fecha
          AND estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA'))
        THROW 51908,N'La caja esta cerrada para la fecha indicada; requiere reapertura autorizada.',1;
    -- Conserva el criterio bancario previo: incluso una conciliacion pendiente
    -- bloquea alterar el periodo que esta siendo revisado. No se flexibiliza.
    IF @tipo=N'BANCO' AND EXISTS(SELECT 1 FROM dbo.msp_tesoreria_conciliaciones WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_hasta>=@fecha)
        THROW 51909,N'El periodo bancario tiene una conciliacion registrada.',1;
END;
GO
DECLARE @refund NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_garantia_tienda_devolver_operativa'));
DECLARE @reverse NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_garantia_tienda_revertir_operacion'));
IF @refund IS NULL OR @reverse IS NULL THROW 53930,N'Faltan los procedimientos financieros de tienda.',1;
-- Normaliza unicamente saltos de linea para comprobar anclas exactas.
SET @refund=REPLACE(@refund,CHAR(13),N'');
SET @reverse=REPLACE(@reverse,CHAR(13),N'');
DECLARE @old NVARCHAR(MAX),@new NVARCHAR(MAX);
IF CHARINDEX(N'MSP_CAJA_ADMIN_8_9',@refund)=0
BEGIN
    SET @old=N'        IF @tipo_cuenta=N''CAJA'' AND EXISTS(
            SELECT 1 FROM dbo.msp_tesoreria_cierres_caja
            WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre>=@fecha_devolucion
        ) THROW 51908, ''La caja ya esta cerrada para la fecha indicada.'', 1;
        IF @tipo_cuenta=N''BANCO'' AND EXISTS(
            SELECT 1 FROM dbo.msp_tesoreria_conciliaciones
            WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_hasta>=@fecha_devolucion
        ) THROW 51909, ''La cuenta bancaria ya fue conciliada para la fecha indicada.'', 1;';
    IF CHARINDEX(@old,@refund)=0 OR CHARINDEX(@old,@refund,CHARINDEX(@old,@refund)+1)>0
        THROW 53930,N'Version desconocida del procedimiento de devolucion: revisar antes de migrar.',1;
    SET @refund=REPLACE(@refund,@old,N'        -- MSP_CAJA_ADMIN_8_9: exclusivamente periodo de la cuenta REAL.
        EXEC dbo.msp_garantia_caja_admin_validar_periodo @id_cuenta_tesoreria,@fecha_devolucion;');
    SET @refund=N'ALTER '+SUBSTRING(@refund,CHARINDEX(N'PROCEDURE',@refund),LEN(@refund));
    EXEC sys.sp_executesql @refund;
END;
IF CHARINDEX(N'MSP_CAJA_ADMIN_8_9',@reverse)=0
BEGIN
    -- Coincide exactamente con el trigger vigente, incluso en collation sensible
    -- a acentos. No se debilita el trigger ni se cambian observaciones historicas.
    SET @old=N'Reversa financiera compensatoria de devolucion #';
    IF CHARINDEX(@old,@reverse)=0 THROW 53930,N'Ancla de reversa de devolucion desconocida.',1;
    SET @reverse=REPLACE(@reverse,@old,N'Reversa financiera compensatoria de devolución #');
    SET @old=N'Reversa financiera compensatoria de aplicacion #';
    IF CHARINDEX(@old,@reverse)=0 THROW 53930,N'Ancla de reversa de aplicacion desconocida.',1;
    SET @reverse=REPLACE(@reverse,@old,N'Reversa financiera compensatoria de aplicación #');
    SET @old=N'    BEGIN TRY';
    IF CHARINDEX(@old,@reverse)=0 OR CHARINDEX(@old,@reverse,CHARINDEX(@old,@reverse)+1)>0
        THROW 53930,N'Ancla de autorizacion de reversa desconocida.',1;
    SET @reverse=REPLACE(@reverse,@old,N'    -- MSP_CAJA_ADMIN_8_9: actor identificado y proteccion de periodos reales.
    IF ISNULL(@id_usuario,0)<=0 OR NOT EXISTS(SELECT 1 FROM dbo.cr_usuarios WHERE id=@id_usuario AND estado_id=1)
        THROW 53932,N''La reversa requiere un usuario habilitado.'',1;
    BEGIN TRY');
    -- La nueva validacion reemplaza el chequeo antiguo de caja, que tambien
    -- bloqueaba cierres reabiertos. Conserva el rechazo de conciliado=1.
    SET @old=N'WHERE id_cuenta_tesoreria=@cuenta AND fecha_cierre>=@fecha';
    IF CHARINDEX(@old,@reverse)=0 THROW 53930,N'Ancla de cierre de reversa desconocida.',1;
    SET @reverse=REPLACE(@reverse,@old,@old+N' AND estado_cierre IN(N''CUADRADO'',N''CON_DIFERENCIA'')');
    DECLARE @table NVARCHAR(80);
    DECLARE @targets TABLE(nombre NVARCHAR(80));
    INSERT @targets VALUES(N'msp_garantia_recepciones'),(N'msp_garantia_devoluciones');
    DECLARE target_cursor CURSOR LOCAL FAST_FORWARD FOR SELECT nombre FROM @targets;
    OPEN target_cursor;
    FETCH NEXT FROM target_cursor INTO @table;
    WHILE @@FETCH_STATUS=0
    BEGIN
        SET @old=N'            UPDATE dbo.'+@table;
        IF CHARINDEX(@old,@reverse)=0 OR CHARINDEX(@old,@reverse,CHARINDEX(@old,@reverse)+1)>0
            THROW 53930,N'Ancla financiera de reversa desconocida.',1;
        SET @new=N'            IF @id_tm IS NULL OR @fecha_reversa<@fecha
                THROW 53933,N''Falta el movimiento real o la fecha de reversa precede al origen.'',1;
            EXEC dbo.msp_garantia_caja_admin_validar_periodo @cuenta,@fecha;
            EXEC dbo.msp_garantia_caja_admin_validar_periodo @cuenta,@fecha_reversa;
'+@old;
        SET @reverse=REPLACE(@reverse,@old,@new);
        FETCH NEXT FROM target_cursor INTO @table;
    END;
    CLOSE target_cursor;
    DEALLOCATE target_cursor;
    SET @reverse=N'ALTER '+SUBSTRING(@reverse,CHARINDEX(N'PROCEDURE',@reverse),LEN(@reverse));
    EXEC sys.sp_executesql @reverse;
END;
COMMIT TRANSACTION;
PRINT N'Cierre y reversas de caja administrativa validados. Sin movimientos historicos modificados.';
GO
