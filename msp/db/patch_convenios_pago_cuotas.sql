SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.msp_convenios_pago', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_convenios_pago (
        id_convenio_pago INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_convenios_pago PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,
        fecha_convenio DATE NOT NULL CONSTRAINT DF_msp_conv_fecha DEFAULT CONVERT(date,GETDATE()),
        monto_total DECIMAL(18,2) NOT NULL,
        numero_cuotas INT NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_conv_estado DEFAULT N'VIGENTE',
        observaciones NVARCHAR(1000) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_conv_registro DEFAULT SYSDATETIME(),
        CONSTRAINT CK_msp_conv_monto CHECK (monto_total > 0),
        CONSTRAINT CK_msp_conv_cuotas CHECK (numero_cuotas > 0),
        CONSTRAINT CK_msp_conv_estado CHECK (estado IN (N'VIGENTE',N'CUMPLIDO',N'INCUMPLIDO',N'ANULADO'))
    );
END;
IF OBJECT_ID(N'dbo.msp_convenio_pago_cuotas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_convenio_pago_cuotas (
        id_cuota_pago INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_convenio_cuotas PRIMARY KEY,
        id_convenio_pago INT NOT NULL,
        numero_cuota INT NOT NULL,
        fecha_vencimiento DATE NOT NULL,
        monto_cuota DECIMAL(18,2) NOT NULL,
        monto_pagado DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_cuota_pagado DEFAULT 0,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cuota_estado DEFAULT N'PENDIENTE',
        fecha_pago DATETIME2(0) NULL,
        observaciones NVARCHAR(500) NULL,
        CONSTRAINT UQ_msp_convenio_cuota UNIQUE(id_convenio_pago,numero_cuota),
        CONSTRAINT CK_msp_cuota_num CHECK (numero_cuota > 0),
        CONSTRAINT CK_msp_cuota_monto CHECK (monto_cuota > 0 AND monto_pagado >= 0 AND monto_pagado <= monto_cuota),
        CONSTRAINT CK_msp_cuota_estado CHECK (estado IN (N'PENDIENTE',N'PAGADA',N'ATRASADA',N'ANULADA'))
    );
END;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_msp_conv_contrato' AND object_id=OBJECT_ID(N'dbo.msp_convenios_pago')) CREATE INDEX IX_msp_conv_contrato ON dbo.msp_convenios_pago(id_contrato_arriendo,estado);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name=N'IX_msp_cuota_vencimiento' AND object_id=OBJECT_ID(N'dbo.msp_convenio_pago_cuotas')) CREATE INDEX IX_msp_cuota_vencimiento ON dbo.msp_convenio_pago_cuotas(fecha_vencimiento,estado);
GO
CREATE OR ALTER PROCEDURE dbo.msp_convenio_crear
    @id_contrato_arriendo INT, @monto_total DECIMAL(18,2), @numero_cuotas INT,
    @fecha_primera_vencimiento DATE, @observaciones NVARCHAR(1000)=NULL, @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON; SET XACT_ABORT ON;
    IF @monto_total <= 0 OR @numero_cuotas < 1 OR @fecha_primera_vencimiento IS NULL THROW 51001, 'Datos de convenio inválidos.', 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.msp_contratos_arriendo WHERE id_contrato_arriendo=@id_contrato_arriendo) THROW 51002, 'Contrato no encontrado.', 1;
    DECLARE @id INT, @base DECIMAL(18,2)=ROUND(@monto_total/@numero_cuotas,2), @resto DECIMAL(18,2);
    SET @resto=@monto_total-(@base*(@numero_cuotas-1));
    BEGIN TRAN;
    INSERT dbo.msp_convenios_pago(id_contrato_arriendo,monto_total,numero_cuotas,observaciones,id_usuario) VALUES(@id_contrato_arriendo,@monto_total,@numero_cuotas,@observaciones,@id_usuario);
    SET @id=SCOPE_IDENTITY();
    ;WITH n AS (SELECT TOP (@numero_cuotas) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) n FROM sys.all_objects a CROSS JOIN sys.all_objects b)
    INSERT dbo.msp_convenio_pago_cuotas(id_convenio_pago,numero_cuota,fecha_vencimiento,monto_cuota)
    SELECT @id,n,DATEADD(MONTH,n-1,@fecha_primera_vencimiento),CASE WHEN n=@numero_cuotas THEN @resto ELSE @base END FROM n;
    COMMIT;
    SELECT @id AS id_convenio_pago;
END;
GO
CREATE OR ALTER VIEW dbo.msp_vw_convenios_pago_estado AS
SELECT c.id_convenio_pago,c.id_contrato_arriendo,c.fecha_convenio,c.monto_total,c.numero_cuotas,c.estado,
       SUM(q.monto_cuota) total_cuotas,SUM(q.monto_pagado) total_pagado,
       SUM(q.monto_cuota-q.monto_pagado) saldo_pendiente,
       SUM(CASE WHEN q.estado<>N'PAGADA' AND q.estado<>N'ANULADA' AND q.fecha_vencimiento<CONVERT(date,GETDATE()) THEN 1 ELSE 0 END) cuotas_atrasadas
FROM dbo.msp_convenios_pago c LEFT JOIN dbo.msp_convenio_pago_cuotas q ON q.id_convenio_pago=c.id_convenio_pago
GROUP BY c.id_convenio_pago,c.id_contrato_arriendo,c.fecha_convenio,c.monto_total,c.numero_cuotas,c.estado;
GO

