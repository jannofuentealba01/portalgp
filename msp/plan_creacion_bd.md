# Plan de creacion de BD MSP (v2 con import Excel)

## Objetivo
Disenar una base que soporte el flujo de cobro mensual con importacion desde Excel y multiples medidores por local y servicio, evitando ambiguedades al cargar lecturas.

## Principios
- El Excel debe identificar medidores de forma estable.
- Los usuarios trabajan sobre una plantilla pre-rellenada con la ultima lectura.
- El historico se guarda en lecturas; el medidor no se “pisotea”.

## Fase 1: Modelo base (maestros)
1. Locales, tiendas, arrendatarios y ocupacion (A1).
2. Catalogos de servicios y origen de lecturas (A21).

## Fase 2: Medidores
1. Permitir multiples medidores activos por local y servicio.
2. Cada medidor debe tener identificador estable:
   - `codigo_medidor` (unico) y `alias_medidor` (legible por usuario).
3. Relacion:
   - `msp_medidores(id_medidor, id_local, id_tipo_servicio, codigo_medidor, alias_medidor, numero_serie, valor_inicial, fecha_instalacion, fecha_retiro, estado_medidor)`
4. Indices:
   - `UNIQUE(codigo_medidor)`
   - `UNIQUE(id_local, id_tipo_servicio, alias_medidor)` (opcional, para evitar nombres duplicados por local/servicio)

## Fase 3: Cierre mensual y procesos
1. `msp_cierre_mensual` con periodo_facturacion (primer dia del mes).
2. `msp_procesos_cobro_servicio` por cierre y servicio.

## Fase 4: Importacion Excel
1. Tabla staging por lote:
   - `msp_import_lotes(id_lote, periodo_facturacion, id_tipo_servicio, fecha_import, usuario, nombre_archivo)`
2. Tabla detalle importado:
   - `msp_import_lecturas(id_lote, cod_local, codigo_medidor, lectura_actual, lectura_anterior, fecha_lectura, fecha_hasta_consumo, observaciones)`
3. Validaciones clave:
   - `codigo_medidor` existe y corresponde al `cod_local`.
   - `lectura_actual >= lectura_anterior`.

## Fase 5: Lecturas y cobros
1. `msp_lecturas_medidores` se inserta desde staging.
2. Mantener unica por medidor y proceso (o versionar si hay relecturas).
3. `msp_cobros_servicios` se genera por procedimiento.

## Fase 6: Documentos y pagos
1. Documento por tienda (grupo de locales).
2. Detalle de items y pagos parciales.
3. Procedimientos:
   - `msp_generar_documentos_cobro_periodo`
   - `msp_registrar_pago_documento`

## Fase 7: Plantilla para usuario
1. Generar Excel con:
   - `cod_local`, `codigo_medidor`, `alias_medidor`, `tipo_servicio`, `lectura_anterior`, `lectura_actual (vacio)`
2. Usuario solo completa `lectura_actual`.
3. Importacion valida y carga a staging.

## Fase 8: Riesgos conocidos y mitigacion
- Orden de filas en Excel:
  - Mitigado usando `codigo_medidor` estable.
- Medidores retirados:
  - Se excluyen de la plantilla si `fecha_retiro` no es NULL.
- Lecturas duplicadas:
  - Control por indice unico o versionado.

## Entregables
1. Scripts SQL por area:
   - A1 Agrupacion locales
   - A21 Cobro servicios (con medidores y lecturas)
   - A22 Documentos y pagos
2. Procedimiento de importacion desde staging a lecturas.
3. Query para generar plantilla de Excel.

