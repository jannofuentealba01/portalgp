# Plan Final MSP (alineado a 01_flujo_cobro y plan_creacion_bd)

## 1. Lo que YA está listo

### 1.1 Scripts BD v2
- `msp/db/msp_agrupacion_locales.sql`
- `msp/db/msp_cobro_servicios.sql`
- `msp/db/msp_documento_pago.sql`
- `msp/db/msp_instalar_full.sql`

### 1.2 CRUD básicos de catálogo
- Arrendatarios, locales, tiendas, rubros, comunas, estados.

### 1.3 Medidores + alias + plantilla lecturas
- CRUD: `msp/medidores/*`
- Plantilla lecturas: `msp/medidores/plantilla.php`

### 1.4 Catálogo de medidores
- `msp/catalogos/medidores.php`

## 2. Estado del flujo mensual (01_flujo_cobro)

### Paso 1: Ingreso periodo + UF
- Implementar módulo `msp/cierre_mensual/`.

### Paso 2–4: Procesos por servicio + importación lecturas
- Implementar módulo `msp/procesos_servicios/`.
- Importación Excel hacia `msp_import_lotes` + `msp_import_lecturas`.

### Paso 5: Generación de documentos
- Implementar módulo `msp/documentos_cobro/`.

### Paso 6: Envío de correos
- No implementado (futuro).

## 3. Lo que falta implementar (nuevo, en directorio principal)

### 3.1 Cierre mensual (Paso 1 flujo)
- Carpeta: `msp/cierre_mensual/`
  - `index.php` (listado + crear/editar)
  - `guardar.php`
  - `eliminar.php` (opcional)
- Tabla: `msp_cierre_mensual` (ya existe en SQL)

### 3.2 Procesos por servicio + importación lecturas (Pasos 2–4 flujo)
- Carpeta: `msp/procesos_servicios/`
  - `index.php` (selección periodo + servicio)
  - `guardar.php` (crear proceso de cobro)
  - `detalle_luz.php`, `detalle_gas.php`, `detalle_agua.php`
  - `importar_lecturas.php` (carga Excel)
  - `confirmar_importacion.php` (preview → staging)
- Usa tablas staging: `msp_import_lotes`, `msp_import_lecturas`

### 3.3 Generación de cobros
- Carpeta: `msp/cobros/`
  - `operacion_mensual.php` (nuevo, usa BD v2)
  - Ejecuta `msp_generar_cobros_servicios_periodo`

### 3.4 Documentos de cobro
- Carpeta: `msp/documentos_cobro/`
  - `index.php` (listado)
  - `generar.php` (llama `msp_generar_documentos_cobro_periodo`)

### 3.5 Pagos
- Carpeta: `msp/pagos/`
  - `index.php` (listado + formulario)
  - `guardar.php` (usa `msp_registrar_pago_documento`)
  - `anular.php` (usa `msp_anular_pago_documento`)

## 4. Lo que hay en `borrar/` y debe rehacerse

Estos módulos no son compatibles con la nueva BD y deben re‑implementarse:

1. `borrar/procesos_servicios/*`
   - Rehacer contra `msp_import_lotes` + `msp_import_lecturas`
   - Debe usar `codigo_medidor` + alias
2. `borrar/cobros/*`
   - Rehacer para usar procedimientos nuevos
3. `borrar/documentos_cobro/*`
   - Rehacer para estado_documento en tabla principal
4. `borrar/pagos/*`
   - Rehacer para `msp_pagos` y trigger recalculo actual
5. `borrar/reportes/*`
   - Rehacer sobre vistas/tablas nuevas (opcional, al final)

## 5. Estado del directorio hoy
- No existen las carpetas reales:
  - `msp/cierre_mensual`
  - `msp/procesos_servicios`
  - `msp/documentos_cobro`
  - `msp/pagos`
- `msp/cobros/operacion_mensual.php` fue creado con BD v2.

## 6. Orden recomendado de implementación
1. `cierre_mensual`
2. `procesos_servicios` + import Excel (staging)
3. `cobros/operacion_mensual`
4. `documentos_cobro`
5. `pagos`
6. reportes
