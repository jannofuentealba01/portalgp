SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF DB_NAME() <> N'PORTALGP'
    THROW 51000, N'Base incorrecta.', 1;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_procesos_cobro_servicio
    WHERE id_proceso_cobro = 22
      AND id_cierre_mensual = 31
      AND id_tipo_servicio = 3
)
    THROW 51001, N'Proceso AGUA esperado no encontrado.', 1;

IF (
    SELECT COUNT(*)
    FROM dbo.msp_lecturas_medidores
    WHERE id_lectura IN (1626, 1627, 1628)
      AND id_proceso_cobro = 22
) <> 3
    THROW 51002, N'Lecturas esperadas no encontradas.', 1;

IF EXISTS (SELECT 1 FROM dbo.msp_pagos WHERE id_documento_cobro IN (1934, 1935))
   OR EXISTS (SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle WHERE id_documento_cobro IN (1934, 1935))
   OR EXISTS (SELECT 1 FROM dbo.msp_envio_lote_documentos WHERE id_documento_cobro IN (1934, 1935))
   OR EXISTS (SELECT 1 FROM dbo.msp_garantia_documento_aplicaciones WHERE id_documento_cobro IN (1934, 1935))
   OR EXISTS (SELECT 1 FROM dbo.msp_saldo_favor_periodo_aplicaciones WHERE id_documento_cobro IN (1934, 1935))
    THROW 51003, N'Existen movimientos posteriores; se cancela la correccion.', 1;

IF EXISTS (
    SELECT 1
    FROM dbo.msp_documentos_cobro
    WHERE id_documento_cobro IN (1934, 1935)
      AND saldo_pendiente <> monto_total
)
    THROW 51004, N'Un documento ya no esta completamente pendiente.', 1;

DECLARE @medidores TABLE (
    id_lectura INT PRIMARY KEY,
    id_cobro INT NOT NULL,
    codigo NVARCHAR(100) NOT NULL,
    anterior_old DECIMAL(18,4) NOT NULL,
    actual_old DECIMAL(18,4) NOT NULL,
    anterior_new DECIMAL(18,4) NOT NULL,
    actual_new DECIMAL(18,4) NOT NULL,
    consumo_new DECIMAL(18,4) NOT NULL,
    variable_new DECIMAL(18,2) NOT NULL,
    fijo_new DECIMAL(18,2) NOT NULL,
    total_new DECIMAL(18,2) NOT NULL,
    id_doc INT NOT NULL,
    id_local INT NOT NULL,
    id_contrato INT NOT NULL,
    id_tienda INT NOT NULL
);

INSERT INTO @medidores
VALUES
    (1626, 1485, N'GYM-AGUA-1',     830, 843, 804, 816, 12,  16158.26, 1385,  17543.26, 1934, 146,  66,  66),
    (1627, 1486, N'OBRA-AGUA-1',    542, 600, 348, 428, 80, 107721.70, 1385, 109106.70, 1935, 147, 115, 115),
    (1628, 1487, N'MODULAR-AGUA-1',  15,  18,  11,  13,  2,   2693.04, 1385,   4078.04, 1935, 148, 115, 115);

IF EXISTS (
    SELECT 1
    FROM @medidores x
    INNER JOIN dbo.msp_lecturas_medidores l ON l.id_lectura = x.id_lectura
    WHERE l.lectura_anterior <> x.anterior_old
       OR l.lectura_actual <> x.actual_old
)
    THROW 51005, N'Las lecturas cambiaron desde la revision.', 1;

DECLARE @delta_documentos TABLE (
    id_doc INT PRIMARY KEY,
    delta DECIMAL(18,2) NOT NULL
);

INSERT INTO @delta_documentos (id_doc, delta)
SELECT x.id_doc, SUM(x.total_new - d.subtotal)
FROM @medidores x
INNER JOIN dbo.msp_documentos_cobro_detalle d
    ON d.id_cobro_servicio = x.id_cobro
GROUP BY x.id_doc;

UPDATE dbo.msp_procesos_cobro_servicio
SET numero_factura_origen = N'127881',
    observaciones = N'Corregido segun Control Diario 2026: consumo AGUA febrero 2026, facturado en abril.'
WHERE id_proceso_cobro = 22;

UPDATE dbo.msp_proceso_cobro_agua
SET servicio_agua_potable = 372276,
    servicio_alcantarillado = 308888,
    tratamiento_aguas_servidas = 615536,
    sobreconsumo = 0,
    interes_pf_plazo = 0,
    divisor = 963,
    cargo_fijo = 1385
WHERE id_proceso_cobro = 22;

UPDATE l
SET lectura_anterior = x.anterior_new,
    lectura_actual = x.actual_new,
    consumo_informado = x.consumo_new,
    observaciones = N'Correccion selectiva: lecturas reales febrero 2026, factura 127881.',
    fecha_actualizacion = SYSDATETIME()
FROM dbo.msp_lecturas_medidores l
INNER JOIN @medidores x ON x.id_lectura = l.id_lectura;

UPDATE c
SET consumo_cobrado = x.consumo_new,
    subtotal_variable = x.variable_new,
    cargo_fijo = x.fijo_new,
    monto_total = x.total_new,
    formula_version = N'V2_AGUA_2026_04',
    parametros_snapshot = N'{"servicio":"AGUA","servicio_agua_potable":372276.000000,"servicio_alcantarillado":308888.000000,"tratamiento_aguas_servidas":615536.000000,"sobreconsumo":0.000000,"interes_pf_plazo":0.000000,"divisor":963.000000,"cargo_fijo":1385.000000}',
    detalle_calculo = N'AGUA: cargo fijo por medidor + consumo * ((SAP + SAL + TAS)/divisor)',
    fecha_calculo = SYSDATETIME()
FROM dbo.msp_cobros_servicios c
INNER JOIN @medidores x ON x.id_cobro = c.id_cobro_servicio;

UPDATE d
SET cantidad = x.consumo_new,
    valor_unitario = ROUND(x.total_new / NULLIF(x.consumo_new, 0), 2),
    subtotal = x.total_new
FROM dbo.msp_documentos_cobro_detalle d
INNER JOIN @medidores x ON x.id_cobro = d.id_cobro_servicio;

UPDATE doc
SET subtotal_servicios = doc.subtotal_servicios + d.delta,
    monto_total = doc.monto_total + d.delta,
    saldo_pendiente = doc.saldo_pendiente + d.delta,
    observaciones = CONCAT(
        ISNULL(doc.observaciones, N''),
        N' Correccion de lecturas AGUA febrero 2026 (factura 127881).'
    )
FROM dbo.msp_documentos_cobro doc
INNER JOIN @delta_documentos d ON d.id_doc = doc.id_documento_cobro;

DECLARE @correcciones TABLE (
    id_correccion INT NOT NULL,
    id_lectura INT NOT NULL
);

INSERT INTO dbo.msp_correcciones (
    tipo_correccion,
    modulo_origen,
    periodo_facturacion,
    id_contrato_arriendo,
    id_tienda,
    id_local,
    entidad_afectada,
    id_registro_origen,
    estado_correccion,
    nivel_correcion,
    valor_anterior,
    valor_nuevo,
    motivo,
    resultado_analisis,
    usuario_solicitante,
    usuario_aprobador,
    usuario_ejecutor,
    fecha_aprobacion,
    fecha_ejecucion,
    estrategia_ejecucion,
    payload_ejecucion,
    resultado_ejecucion,
    fecha_analisis
)
OUTPUT inserted.id_correccion, inserted.id_registro_origen
    INTO @correcciones (id_correccion, id_lectura)
SELECT
    N'LECTURA_SERVICIO',
    N'MSP / Operacion mensual',
    CONVERT(DATE, '20260401', 112),
    x.id_contrato,
    x.id_tienda,
    x.id_local,
    N'msp_lecturas_medidores',
    x.id_lectura,
    N'EJECUTADA',
    N'FINANCIERA',
    CONCAT(N'{"lectura_anterior":', CONVERT(VARCHAR(30), x.anterior_old), N',"lectura_actual":', CONVERT(VARCHAR(30), x.actual_old), N'}'),
    CONCAT(N'{"lectura_anterior":', CONVERT(VARCHAR(30), x.anterior_new), N',"lectura_actual":', CONVERT(VARCHAR(30), x.actual_new), N'}'),
    N'Corregir lecturas de agua consumida en febrero y facturada en abril, segun Control Diario 2026.',
    N'Documentos impagos, no enviados y sin aplicaciones financieras; procede correccion selectiva.',
    1030,
    1030,
    1030,
    SYSDATETIME(),
    SYSDATETIME(),
    N'ACTUALIZACION_TRANSACCIONAL',
    CONCAT(N'{"factura":"127881","codigo_medidor":"', x.codigo, N'"}'),
    CONCAT(N'Lectura, cobro y documento #', x.id_doc, N' actualizados.'),
    SYSDATETIME()
FROM @medidores x;

INSERT INTO dbo.msp_correcciones_eventos (
    id_correccion,
    tipo_evento,
    detalle,
    estado_anterior,
    estado_nuevo,
    payload_json,
    usuario_evento
)
SELECT
    c.id_correccion,
    N'EJECUCION',
    N'Correccion aplicada y propagada a cobro y documento.',
    N'APROBADA',
    N'EJECUTADA',
    CONCAT(N'{"id_lectura":', c.id_lectura, N'}'),
    1030
FROM @correcciones c;

INSERT INTO dbo.msp_correcciones_impactos (
    id_correccion,
    tipo_entidad,
    id_registro,
    accion_prevista,
    valor_anterior,
    valor_nuevo,
    es_financiero
)
SELECT c.id_correccion, N'LECTURA_MEDIDOR', x.id_lectura, N'ACTUALIZAR',
       CONCAT(x.anterior_old, N' -> ', x.actual_old),
       CONCAT(x.anterior_new, N' -> ', x.actual_new), 0
FROM @correcciones c
INNER JOIN @medidores x ON x.id_lectura = c.id_lectura
UNION ALL
SELECT c.id_correccion, N'COBRO_SERVICIO', x.id_cobro, N'RECALCULAR',
       NULL, CONVERT(NVARCHAR(100), x.total_new), 1
FROM @correcciones c
INNER JOIN @medidores x ON x.id_lectura = c.id_lectura
UNION ALL
SELECT c.id_correccion, N'DOCUMENTO_COBRO', x.id_doc, N'RECALCULAR',
       NULL, CONVERT(NVARCHAR(100), x.total_new), 1
FROM @correcciones c
INNER JOIN @medidores x ON x.id_lectura = c.id_lectura;

INSERT INTO dbo.msp_documentos_cobro_eventos (
    id_contrato_arriendo,
    id_documento_cobro,
    id_usuario,
    tipo_evento,
    origen_evento,
    titulo_evento,
    detalle_evento,
    payload_json,
    es_evento_derivado
)
SELECT
    doc.id_contrato_arriendo,
    doc.id_documento_cobro,
    1030,
    N'CORRECCION',
    N'CORRECCION_SELECTIVA',
    N'Lecturas de agua corregidas',
    N'Se corrigieron lecturas de febrero 2026 y los montos derivados con factura 127881.',
    CONCAT(N'{"periodo":"2026-04-01","delta_documento":', CONVERT(VARCHAR(40), d.delta), N'}'),
    0
FROM dbo.msp_documentos_cobro doc
INNER JOIN @delta_documentos d ON d.id_doc = doc.id_documento_cobro;

COMMIT TRANSACTION;

SELECT N'CORREGIDO' AS resultado;
