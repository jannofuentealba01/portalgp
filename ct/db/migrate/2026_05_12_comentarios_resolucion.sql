/*
  CT MIGRATE: habilita estado de resolucion en comentarios de solicitud.
*/

IF OBJECT_ID('dbo.ct_solicitud_comentario', 'U') IS NULL
BEGIN
    PRINT 'ct_solicitud_comentario no existe; se omite migracion de comentarios.';
    RETURN;
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'estado_revision') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD estado_revision NVARCHAR(20) NOT NULL
        CONSTRAINT DF_ct_solicitud_comentario_estado_revision DEFAULT ('PENDIENTE');
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'resuelto_en') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD resuelto_en DATETIME2(0) NULL;
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'id_usuario_resolucion') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD id_usuario_resolucion INT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_ct_solicitud_comentario_estado_revision')
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario WITH CHECK
    ADD CONSTRAINT CK_ct_solicitud_comentario_estado_revision
        CHECK (estado_revision IN ('PENDIENTE', 'RESUELTO'));
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_ct_solicitud_comentario_estado'
      AND object_id = OBJECT_ID('dbo.ct_solicitud_comentario')
)
BEGIN
    CREATE INDEX IX_ct_solicitud_comentario_estado
        ON dbo.ct_solicitud_comentario (id_solicitud, estado_revision, fecha_creacion DESC);
END;
GO
