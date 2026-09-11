SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_documentos_tienda_lotes', N'U') IS NULL
    THROW 51000, 'Debe aplicarse primero patch_documentos_tienda_carga_masiva.sql.', 1;
GO

IF COL_LENGTH(N'dbo.msp_documentos_tienda_lotes', N'estado_antes_cierre') IS NULL
    ALTER TABLE dbo.msp_documentos_tienda_lotes ADD estado_antes_cierre NVARCHAR(20) NULL;
IF COL_LENGTH(N'dbo.msp_documentos_tienda_lotes', N'fecha_cierre') IS NULL
    ALTER TABLE dbo.msp_documentos_tienda_lotes ADD fecha_cierre DATETIME2(0) NULL;
IF COL_LENGTH(N'dbo.msp_documentos_tienda_lotes', N'id_usuario_cierre') IS NULL
    ALTER TABLE dbo.msp_documentos_tienda_lotes ADD id_usuario_cierre INT NULL;
GO

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id=OBJECT_ID(N'dbo.msp_documentos_tienda_lotes') AND name=N'CK_msp_dtl_estado'
)
    ALTER TABLE dbo.msp_documentos_tienda_lotes DROP CONSTRAINT CK_msp_dtl_estado;

ALTER TABLE dbo.msp_documentos_tienda_lotes WITH CHECK ADD CONSTRAINT CK_msp_dtl_estado
    CHECK (estado_lote IN (N'BORRADOR',N'EN_REVISION',N'LISTO',N'PARCIAL',N'COMPLETADO',N'CERRADO'));
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE parent_object_id=OBJECT_ID(N'dbo.msp_documentos_tienda_lotes') AND name=N'FK_msp_dtl_usuario_cierre'
)
    ALTER TABLE dbo.msp_documentos_tienda_lotes WITH CHECK ADD CONSTRAINT FK_msp_dtl_usuario_cierre
        FOREIGN KEY (id_usuario_cierre) REFERENCES dbo.cr_usuarios(id);
GO

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id=OBJECT_ID(N'dbo.msp_documentos_tienda_eventos') AND name=N'CK_msp_dte_tipo'
)
    ALTER TABLE dbo.msp_documentos_tienda_eventos DROP CONSTRAINT CK_msp_dte_tipo;

ALTER TABLE dbo.msp_documentos_tienda_eventos WITH CHECK ADD CONSTRAINT CK_msp_dte_tipo
    CHECK (tipo_evento IN (
        N'LOTE_CREADO',N'ARCHIVO_CARGADO',N'ASOCIACION',N'OMISION',N'ENVIO_DEMO',N'ERROR_ENVIO',
        N'LOTE_CERRADO',N'LOTE_REABIERTO'
    ));
GO

DECLARE @permisos TABLE(nombre VARCHAR(150), descripcion VARCHAR(255));
INSERT INTO @permisos(nombre,descripcion) VALUES
    ('MSP Documentos Tienda Carga','Permite crear lotes y cargar PDFs externos por tienda.'),
    ('MSP Documentos Tienda Revision','Permite asociar, corregir y omitir documentos cargados por tienda.'),
    ('MSP Documentos Tienda Aprobacion','Permite simular, cerrar y reabrir lotes de documentos por tienda.');

INSERT dbo.cr_permisos(nombre_permiso,descripcion)
SELECT p.nombre,p.descripcion
FROM @permisos p
WHERE NOT EXISTS (SELECT 1 FROM dbo.cr_permisos actual WHERE actual.nombre_permiso=p.nombre);

;WITH roles_cobranza AS (
    SELECT rp.rol_id,
           MAX(CONVERT(INT,rp.lectura)) lectura,
           MAX(CONVERT(INT,rp.escritura)) escritura,
           MAX(CONVERT(INT,rp.eliminacion)) eliminacion
    FROM dbo.cr_rol_permisos rp
    INNER JOIN dbo.cr_permisos p ON p.id=rp.permiso_id
    WHERE p.nombre_permiso='MSP Cobranza'
    GROUP BY rp.rol_id
)
INSERT dbo.cr_rol_permisos(rol_id,permiso_id,lectura,escritura,eliminacion)
SELECT r.rol_id,p.id,r.lectura,r.escritura,r.eliminacion
FROM roles_cobranza r
CROSS JOIN dbo.cr_permisos p
WHERE p.nombre_permiso IN (
    'MSP Documentos Tienda Carga','MSP Documentos Tienda Revision','MSP Documentos Tienda Aprobacion'
)
AND NOT EXISTS (
    SELECT 1 FROM dbo.cr_rol_permisos actual
    WHERE actual.rol_id=r.rol_id AND actual.permiso_id=p.id
);
GO
