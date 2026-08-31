/*
===========================================================================
 MSP - SQL Server Agent Job para envios de lotes programados

 Uso:
 1) Ejecutar en SSMS con una cuenta sysadmin.
 2) Editar variables en la sección "CONFIG" (ruta php.exe y worker).
 3) Ejecutar este script en la instancia SQL Server que usa PORTALGP.

 Qué hace:
 - (Opcional) intenta iniciar SQL Server Agent.
 - Crea/actualiza un Job de Agent (idempotente) que corre cada N minutos.
 - El Job ejecuta worker_envio_lotes.php para procesar lotes vencidos.
===========================================================================
*/

SET NOCOUNT ON;
GO

USE msdb;
GO

/* ===============================
   CONFIG
   =============================== */
DECLARE @JobName SYSNAME = N'MSP - Envio Lotes Programados';
DECLARE @PhpExePath NVARCHAR(4000) = N'C:\wamp64\bin\php\php8.3.28\php.exe';
DECLARE @WorkerScriptPath NVARCHAR(4000) = N'C:\wamp64\www\portalgp\msp\cobros\worker_envio_lotes.php';
DECLARE @MaxLotes INT = 5;
DECLARE @BatchSize INT = 0; -- 0 = respeta batch_size definido en cada lote desde PHP
DECLARE @FrequencyMinutes INT = 1;
DECLARE @OwnerLogin SYSNAME = SUSER_SNAME();

/* ===============================
   VALIDACIONES BASE
   =============================== */
IF ISNULL(@FrequencyMinutes, 0) <= 0
BEGIN
    RAISERROR(N'@FrequencyMinutes debe ser > 0.', 16, 1);
    RETURN;
END;

IF ISNULL(@MaxLotes, 0) <= 0
BEGIN
    RAISERROR(N'@MaxLotes debe ser > 0.', 16, 1);
    RETURN;
END;

/* ===============================
   ESTADO SQL SERVER AGENT
   =============================== */
DECLARE @agentStatus NVARCHAR(60) = NULL;
BEGIN TRY
    SELECT TOP (1) @agentStatus = dss.status_desc
    FROM sys.dm_server_services dss
    WHERE dss.servicename LIKE N'SQL Server Agent%';
END TRY
BEGIN CATCH
    SET @agentStatus = NULL;
END CATCH;

PRINT N'SQL Server Agent status (previo): ' + ISNULL(@agentStatus, N'No disponible');

/* Intento opcional de START (puede fallar según permisos/políticas) */
IF (@agentStatus IS NULL OR @agentStatus <> N'Running')
BEGIN
    DECLARE @serviceControlCmd NVARCHAR(30) = N'START';
    DECLARE @agentServiceName SYSNAME;
    DECLARE @instanceName SYSNAME = CAST(SERVERPROPERTY('InstanceName') AS SYSNAME);

    IF @instanceName IS NULL
        SET @agentServiceName = N'SQLSERVERAGENT';
    ELSE
        SET @agentServiceName = N'SQLAgent$' + @instanceName;

    BEGIN TRY
        EXEC master.dbo.xp_servicecontrol @serviceControlCmd, @agentServiceName;
        PRINT N'Se intentó iniciar SQL Server Agent: ' + @agentServiceName;
    END TRY
    BEGIN CATCH
        PRINT N'No fue posible iniciar Agent desde T-SQL. Inícialo manualmente en SQL Server Configuration Manager.';
        PRINT N'Detalle: ' + ERROR_MESSAGE();
    END CATCH;
END;

/* ===============================
   COMANDO CMDEXEC
   =============================== */
DECLARE @Cmd NVARCHAR(4000);
SET @Cmd = N'"' + @PhpExePath + N'" "' + @WorkerScriptPath + N'" --max-lotes=' + CAST(@MaxLotes AS NVARCHAR(20));
IF ISNULL(@BatchSize, 0) > 0
BEGIN
    SET @Cmd = @Cmd + N' --batch-size=' + CAST(@BatchSize AS NVARCHAR(20));
END;

PRINT N'Comando JobStep:';
PRINT @Cmd;

/* ===============================
   RECREAR JOB (idempotente)
   =============================== */
DECLARE @existingJobId UNIQUEIDENTIFIER;
SELECT @existingJobId = sj.job_id
FROM msdb.dbo.sysjobs sj
WHERE sj.name = @JobName;

IF @existingJobId IS NOT NULL
BEGIN
    EXEC msdb.dbo.sp_delete_job @job_id = @existingJobId, @delete_unused_schedule = 1;
    PRINT N'Job existente eliminado para recreación limpia.';
END;

DECLARE @jobId UNIQUEIDENTIFIER;

EXEC msdb.dbo.sp_add_job
    @job_name = @JobName,
    @enabled = 1,
    @description = N'Procesa lotes programados de cobro MSP (worker PHP).',
    @start_step_id = 1,
    @owner_login_name = @OwnerLogin,
    @job_id = @jobId OUTPUT;

EXEC msdb.dbo.sp_add_jobstep
    @job_id = @jobId,
    @step_id = 1,
    @step_name = N'Ejecutar worker_envio_lotes.php',
    @subsystem = N'CmdExec',
    @command = @Cmd,
    @retry_attempts = 1,
    @retry_interval = 2,
    @on_success_action = 1,
    @on_fail_action = 2;

DECLARE @scheduleName SYSNAME = @JobName + N' - cada ' + CAST(@FrequencyMinutes AS NVARCHAR(20)) + N' min';
DECLARE @ActiveStartDate INT = CAST(CONVERT(CHAR(8), GETDATE(), 112) AS INT);

EXEC msdb.dbo.sp_add_jobschedule
    @job_id = @jobId,
    @name = @scheduleName,
    @enabled = 1,
    @freq_type = 4,               -- diario
    @freq_interval = 1,           -- cada día
    @freq_subday_type = 4,        -- minutos
    @freq_subday_interval = @FrequencyMinutes,
    @active_start_date = @ActiveStartDate,
    @active_start_time = 0;

EXEC msdb.dbo.sp_add_jobserver
    @job_id = @jobId,
    @server_name = @@SERVERNAME;

PRINT N'Job SQL Agent creado/actualizado correctamente: ' + @JobName;
PRINT N'Recuerda validar que @PhpExePath y @WorkerScriptPath existan en el servidor SQL.';
GO
