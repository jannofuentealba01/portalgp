# Plan Dashboard KPI MSP (Sin Cambios Estructurales de BD)

## Objetivo
Implementar un dashboard operativo en una carpeta propia (`msp/dashboard/`) que permita:
- Ver KPIs del período o históricos.
- Agrupar resultados por local (en orden natural de locales).
- Revisar estado por arrendatario (al día / con deuda y monto adeudado).
- Mostrar historial mensual consolidado.

En esta fase se trabajará con la BD actual y SQL de lectura (sin nuevas tablas ni refactor contable).

## Alcance Funcional (MVP)
- Filtro `Periodo`:
  - Mes específico (`YYYY-MM`).
  - `Todos` (histórico).
- KPIs:
  - Facturado (documentos).
  - Cobrado real (pagos aplicados).
  - Saldo pendiente.
  - % recaudación.
  - Arrendatarios al día / con deuda.
- Tabla principal:
  - Agrupación por local y arrendatario.
  - Documentos, facturado, cobrado, saldo, estado.
  - Orden natural por código de local.
- Historial:
  - Serie mensual (facturado, cobrado, saldo, recaudación).

## Reglas de Negocio (Definición MVP)
- `Facturado`: suma de `msp_documentos_cobro.monto_total`.
- `Cobrado real`: suma de `msp_pagos.monto_pagado` con `estado_pago = 1`.
- `Saldo pendiente`: suma de `msp_documentos_cobro.saldo_pendiente`.
- `Estado arrendatario`:
  - Al día: saldo <= 0.
  - Con deuda: saldo > 0.
- Para agrupación por local cuando un documento involucra más de un local:
  - Se prorratea monto/saldo/pagado por cantidad de locales activos del documento en ese período.
  - Esto evita duplicar totales al consolidar por local.

## Diseño Técnico (Mínimo BD)
- Carpeta nueva: `dashboard/`
  - `dashboard/index.php` (UI + consulta de datos).
- Integración de navegación:
  - Agregar acceso en `msp_menu.php`.
- Tablas usadas:
  - `msp_documentos_cobro`, `msp_pagos`, `msp_tiendas`, `msp_arrendatarios`, `msp_ocupacion_locales`, `msp_locales`.
- Reutilización:
  - `msp2LocalCodeNaturalOrderSql()` para orden natural de locales.

## Fases
1. Implementar vista y filtros base de período.
2. Implementar consultas KPI (mes/todos).
3. Implementar agrupación por local + arrendatario con prorrateo por locales.
4. Implementar historial mensual.
5. Integrar en menú y validar cuadre con módulos existentes.

## Validación
- Comparar totales de período con `documentos_cobro/index.php`.
- Verificar casos:
  - pago parcial,
  - sin pagos,
  - múltiples locales por tienda,
  - período específico vs histórico.

## Fuera de Alcance (Fase Contable Futura)
- Asientos contables / libro mayor.
- Devengo formal vs caja con cierres contables.
- Conciliación bancaria y estados financieros.

## Riesgos y Mitigación
- Riesgo: inconsistencias históricas en ocupación local.
  - Mitigación: prorrateo por locales activos del período + fallback a `SIN LOCAL`.
- Riesgo: consultas pesadas en histórico.
  - Mitigación MVP: limitar visualmente/paginación si crece volumen.
  - Mitigación futura opcional: índices adicionales en `periodo_facturacion`, `fecha_pago`.
