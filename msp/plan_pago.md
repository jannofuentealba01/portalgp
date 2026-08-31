# Plan de Poblamiento de Pagos Historicos MSP

## 1) Objetivo

Poblar pagos historicos para periodos Enero, Febrero, Marzo y Abril, simulando pagos completos y parciales de documentos ya generados, sin necesidad de reconstruir el detalle exacto de conceptos pagados.

Este plan tambien cubre ajustes finos como: "el documento debe quedar con saldo final de $10".

## 2) Alcance

- Se trabaja sobre documentos en `msp_documentos_cobro`.
- Se registran pagos en `msp_pagos` usando SP de negocio ya existentes.
- El estado y saldo del documento se recalculan automaticamente por trigger.
- No se persigue fidelidad contable fina por concepto; se acepta distribucion automatica.

Fuera de alcance:
- Recalcular composicion original del documento (arriendo/servicios) para meses cerrados.
- Cambiar reglas de negocio del SP de pagos.

## 3) Base tecnica existente (ya disponible)

- SP principal: `dbo.msp_registrar_pago_documento`.
- SP para aplicar saldo a favor: `dbo.msp_aplicar_saldo_favor_documento`.
- Trigger: `TR_msp_pagos_recalcula_documento` (actualiza `saldo_pendiente` y estado 2/3/4).
- Soporte de distribucion por concepto opcional via `@detalle_conceptos_json`; para carga historica se puede enviar `NULL`.

Resultado: no se necesita crear logica financiera nueva para simular pagos.

## 4) Estrategia recomendada

- Via inmediata (recomendada para poblar ahora): carga por lote en SQL (staging + ejecucion de SP).
- Via objetivo (operacion futura): modulo `pagos/simulacion_masiva.php` con plantilla Excel, preview y confirmacion.

Se ejecuta primero la via inmediata para resolver el poblamiento actual.

## 5) Modelo de datos para la carga

Cada fila de carga debe definir el resultado deseado del documento con uno de estos modos:

- `TOTAL`: paga todo el saldo pendiente actual.
- `MONTO_PAGADO`: aplica un monto fijo.
- `SALDO_FINAL`: aplica lo necesario para dejar un saldo pendiente exacto.
- `PORCENTAJE`: aplica un porcentaje del saldo pendiente.

Para tu caso de ajuste:
- Si en Enero "debe 10", usar `modo = SALDO_FINAL` y `valor = 10`.

## 6) Estructura de plantilla (CSV/Excel)

Columnas obligatorias:

- `id_documento_cobro`
- `fecha_pago` (`YYYY-MM-DD`)
- `modo` (`TOTAL|MONTO_PAGADO|SALDO_FINAL|PORCENTAJE`)
- `valor` (decimal; en `TOTAL` puede ir `0`)

Columnas opcionales:

- `medio_pago` (ej: `Transferencia`)
- `referencia_pago` (ej: `HIST-2026-01`)
- `observaciones` (ej: `Carga historica lote 001`)

Columnas de control recomendadas (solo informativas):

- `periodo_facturacion`
- `numero_documento`
- `arrendatario`
- `tienda`
- `monto_total`
- `saldo_pendiente_actual`

## 7) Reglas de validacion de carga

- `id_documento_cobro` debe existir.
- Documento no debe estar anulado (`estado_documento != 5`).
- Documento debe tener saldo pendiente mayor a 0.
- `fecha_pago` valida.
- `modo` debe ser uno de los 4 permitidos.
- `valor`:
- `TOTAL`: ignorado o `0`.
- `MONTO_PAGADO`: `> 0`.
- `SALDO_FINAL`: `>= 0` y `<= saldo_pendiente_actual`.
- `PORCENTAJE`: `> 0` y `<= 100`.

Regla de conversion a monto aplicado:

- `TOTAL` -> `monto = saldo_pendiente_actual`
- `MONTO_PAGADO` -> `monto = valor`
- `SALDO_FINAL` -> `monto = saldo_pendiente_actual - valor`
- `PORCENTAJE` -> `monto = ROUND(saldo_pendiente_actual * (valor / 100), 2)`

Si `monto <= 0`, la fila se rechaza para evitar no-op.

## 8) Flujo operativo (poblamiento actual)

1. Generar y validar documentos de Enero-Abril.
2. Exportar universo objetivo (`id_documento_cobro`, saldo, periodo, tienda).
3. Completar archivo de carga con `modo` y `valor`.
4. Cargar a tabla staging temporal.
5. Ejecutar prevalidacion y generar reporte de errores.
6. Corregir archivo hasta quedar sin errores bloqueantes.
7. Ejecutar lote real llamando `dbo.msp_registrar_pago_documento` por fila valida.
8. Verificar totales post-carga (documentos pagados/parciales/saldo).
9. Guardar evidencia del lote (archivo, fecha, usuario, resumen).

## 9) SQL de apoyo (extraccion base)

```sql
SELECT
    dc.id_documento_cobro,
    CONVERT(char(7), dc.periodo_facturacion, 126) AS periodo_ym,
    dc.numero_documento,
    dc.id_tienda,
    dc.monto_total,
    dc.saldo_pendiente,
    dc.estado_documento
FROM dbo.msp_documentos_cobro dc
WHERE dc.periodo_facturacion IN ('2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01')
  AND dc.estado_documento IN (2, 3)
  AND dc.saldo_pendiente > 0
ORDER BY dc.periodo_facturacion, dc.id_tienda, dc.id_documento_cobro;
```

## 10) SQL de ejecucion de lote (patron)

Nota: este bloque es una guia operativa. Ajustar nombres de tabla temporal segun ambiente.

```sql
-- 1) staging de ejemplo
CREATE TABLE #msp_pago_carga (
    fila INT IDENTITY(1,1) PRIMARY KEY,
    id_documento_cobro INT NOT NULL,
    fecha_pago DATE NOT NULL,
    modo VARCHAR(20) NOT NULL,
    valor DECIMAL(18,2) NULL,
    medio_pago NVARCHAR(50) NULL,
    referencia_pago NVARCHAR(100) NULL,
    observaciones NVARCHAR(500) NULL
);

-- 2) insertar datos desde carga (ejemplo manual)
-- INSERT INTO #msp_pago_carga (...) VALUES (...);

-- 3) resolver monto segun saldo actual
WITH base AS (
    SELECT
        c.fila,
        c.id_documento_cobro,
        c.fecha_pago,
        c.modo,
        c.valor,
        c.medio_pago,
        c.referencia_pago,
        c.observaciones,
        dc.saldo_pendiente
    FROM #msp_pago_carga c
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = c.id_documento_cobro
)
SELECT
    b.*,
    monto_aplicar = CASE
        WHEN b.modo = 'TOTAL' THEN b.saldo_pendiente
        WHEN b.modo = 'MONTO_PAGADO' THEN b.valor
        WHEN b.modo = 'SALDO_FINAL' THEN ROUND(b.saldo_pendiente - ISNULL(b.valor, 0), 2)
        WHEN b.modo = 'PORCENTAJE' THEN ROUND(b.saldo_pendiente * (ISNULL(b.valor, 0) / 100.0), 2)
        ELSE NULL
    END
INTO #msp_pago_lote_resuelto
FROM base b;

-- 4) ejecutar SP por fila valida
DECLARE
    @id_documento_cobro INT,
    @fecha_pago DATE,
    @monto DECIMAL(18,2),
    @medio_pago NVARCHAR(50),
    @referencia_pago NVARCHAR(100),
    @observaciones NVARCHAR(500);

DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
SELECT
    id_documento_cobro, fecha_pago, monto_aplicar, medio_pago, referencia_pago, observaciones
FROM #msp_pago_lote_resuelto
WHERE monto_aplicar > 0;

OPEN cur;
FETCH NEXT FROM cur INTO
    @id_documento_cobro, @fecha_pago, @monto, @medio_pago, @referencia_pago, @observaciones;

WHILE @@FETCH_STATUS = 0
BEGIN
    EXEC dbo.msp_registrar_pago_documento
        @id_documento_cobro = @id_documento_cobro,
        @fecha_pago = @fecha_pago,
        @monto_pagado = @monto,
        @medio_pago = @medio_pago,
        @referencia_pago = @referencia_pago,
        @observaciones = @observaciones,
        @detalle_conceptos_json = NULL;

    FETCH NEXT FROM cur INTO
        @id_documento_cobro, @fecha_pago, @monto, @medio_pago, @referencia_pago, @observaciones;
END

CLOSE cur;
DEALLOCATE cur;
```

## 11) Control de calidad post-carga

Verificar:

- Documentos en estado 4 (`Pagado`) segun esperado.
- Documentos en estado 3 (`Pagado Parcial`) con saldo residual esperado.
- Casos de ajuste fino (ej: saldo final = 10) exactos.
- No existen pagos sobre documentos anulados.

Consulta de control:

```sql
SELECT
    dc.id_documento_cobro,
    CONVERT(char(7), dc.periodo_facturacion, 126) AS periodo_ym,
    dc.monto_total,
    dc.saldo_pendiente,
    dc.estado_documento,
    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
FROM dbo.msp_documentos_cobro dc
LEFT JOIN dbo.msp_pagos p
    ON p.id_documento_cobro = dc.id_documento_cobro
WHERE dc.periodo_facturacion IN ('2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01')
GROUP BY
    dc.id_documento_cobro,
    dc.periodo_facturacion,
    dc.monto_total,
    dc.saldo_pendiente,
    dc.estado_documento
ORDER BY dc.periodo_facturacion, dc.id_documento_cobro;
```

## 12) Trazabilidad y rollback operativo

- Etiquetar cada fila con observacion comun de lote, por ejemplo: `HIST_PAGOS_2026Q1_LOTE_001`.
- Guardar archivo original y reporte de ejecucion.
- Si un lote sale mal, anular pagos por lote usando `dbo.msp_anular_pago_documento` (no borrar directo).

## 13) Implementacion objetivo (siguiente iteracion)

Desarrollar modulo web de importacion masiva:

- `pagos/simulacion_masiva.php`
- `pagos/services/PagosMasivosService.php`
- Plantilla Excel descargable
- Preview de filas validas/error
- Confirmacion de carga
- Resumen final y trazabilidad de lote

Con esto el poblamiento inicial se puede hacer ya por SQL, y el proceso futuro queda repetible desde UI.
