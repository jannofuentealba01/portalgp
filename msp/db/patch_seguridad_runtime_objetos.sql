SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NULL
        THROW 51000, 'No existe el rol portalgp_runtime_role.', 1;

    REVOKE SELECT, INSERT, UPDATE, DELETE, EXECUTE ON SCHEMA::dbo FROM portalgp_runtime_role;

    DECLARE @schema SYSNAME;
    DECLARE @object SYSNAME;
    DECLARE @type CHAR(2);
    DECLARE @qualified NVARCHAR(520);
    DECLARE @sql NVARCHAR(MAX);

    DECLARE runtime_objects CURSOR LOCAL FAST_FORWARD FOR
        SELECT s.name, o.name, o.type
        FROM sys.objects o
        INNER JOIN sys.schemas s ON s.schema_id=o.schema_id
        WHERE s.name=N'dbo'
          AND o.is_ms_shipped=0
          AND o.type IN ('U','V','P','FN','IF','TF')
          AND (
              o.name LIKE N'cr[_]%'
              OR o.name LIKE N'msp[_]%'
              OR o.name LIKE N'ct[_]%'
              OR o.name LIKE N'sp[_]ct[_]%'
          );

    OPEN runtime_objects;
    FETCH NEXT FROM runtime_objects INTO @schema,@object,@type;
    WHILE @@FETCH_STATUS=0
    BEGIN
        SET @qualified=QUOTENAME(@schema)+N'.'+QUOTENAME(@object);
        IF @type IN ('U','V')
            EXEC (N'GRANT SELECT ON OBJECT::'+@qualified+N' TO portalgp_runtime_role;');
        IF @type='U' AND @object<>N'msp_schema_migrations'
            EXEC (N'GRANT INSERT, UPDATE, DELETE ON OBJECT::'+@qualified+N' TO portalgp_runtime_role;');
        IF @type='P'
            EXEC (N'GRANT EXECUTE ON OBJECT::'+@qualified+N' TO portalgp_runtime_role;');
        IF @type IN ('FN','IF','TF')
            EXEC (N'GRANT SELECT, REFERENCES ON OBJECT::'+@qualified+N' TO portalgp_runtime_role;');

        FETCH NEXT FROM runtime_objects INTO @schema,@object,@type;
    END
    CLOSE runtime_objects;
    DEALLOCATE runtime_objects;

    DENY INSERT, UPDATE, DELETE ON OBJECT::dbo.msp_schema_migrations TO portalgp_runtime_role;
    IF OBJECT_ID(N'dbo.msp_documentos_cobro_versiones',N'U') IS NOT NULL
        DENY UPDATE, DELETE ON OBJECT::dbo.msp_documentos_cobro_versiones TO portalgp_runtime_role;
    IF OBJECT_ID(N'dbo.msp_cierre_mensual_eliminaciones',N'U') IS NOT NULL
        DENY UPDATE, DELETE ON OBJECT::dbo.msp_cierre_mensual_eliminaciones TO portalgp_runtime_role;
    IF OBJECT_ID(N'dbo.msp_cierre_mensual_transiciones',N'U') IS NOT NULL
        DENY UPDATE, DELETE ON OBJECT::dbo.msp_cierre_mensual_transiciones TO portalgp_runtime_role;
    IF OBJECT_ID(N'dbo.msp_saldo_favor_auditoria_historica',N'U') IS NOT NULL
        DENY INSERT, UPDATE, DELETE ON OBJECT::dbo.msp_saldo_favor_auditoria_historica TO portalgp_runtime_role;
    GRANT CONNECT TO portalgp_runtime_role;
    GRANT VIEW DEFINITION TO portalgp_runtime_role;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF CURSOR_STATUS('local','runtime_objects')>-1
        CLOSE runtime_objects;
    IF CURSOR_STATUS('local','runtime_objects')>=-1
        DEALLOCATE runtime_objects;
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

SELECT
    dp.name AS rol,
    SUM(CASE WHEN p.class=3 AND p.permission_name IN ('SELECT','INSERT','UPDATE','DELETE','EXECUTE') THEN 1 ELSE 0 END) AS permisos_amplios_schema,
    SUM(CASE WHEN p.class=1 AND p.state IN ('G','W') THEN 1 ELSE 0 END) AS permisos_objeto
FROM sys.database_principals dp
LEFT JOIN sys.database_permissions p ON p.grantee_principal_id=dp.principal_id
WHERE dp.name=N'portalgp_runtime_role'
GROUP BY dp.name;
