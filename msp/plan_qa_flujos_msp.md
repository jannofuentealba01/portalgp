# Plan QA Flujos MSP

## Objetivo

Validar end-to-end los flujos principales de la app con el modelo actual:
- Contrato como módulo canónico.
- Tienda como entidad comercial.
- Cierre operativo y cierre financiero separados.

## Preparación

1. Respaldar BD.
2. Aplicar scripts pendientes:
3. `db/patch_contrato_indices_operativos.sql`
4. `db/msp_fase4_sp_negocio.sql`
5. Preparar datos base:
6. Al menos 3 arrendatarios.
7. Al menos 10 locales (A/B/C + numéricos).
8. Período de prueba sugerido: `2026-03`.

## Flujo 1: Catálogos Base

1. Crear/editar arrendatario en `arrendatarios/index.php`.
2. Crear/editar locales en `locales/index.php`.
3. Validar orden natural de locales.
4. Esperado: guarda sin errores y orden correcto.

## Flujo 2: Contrato Nuevo Manual

1. Ir a `contratos/index.php`.
2. Crear contrato nuevo.
3. Asociar tienda + 1..N locales.
4. Registrar garantía.
5. Esperado: contrato operativo, `contrato_local` y garantía creados.

## Flujo 3: Importación Canónica

1. Importar Excel desde `contratos/index.php`.
2. Confirmar vista previa.
3. Esperado: crea/actualiza tienda, contrato, contrato-local, ocupación y garantía.
4. Verificar que `tiendas/importar.php` redirige (deprecado).

## Flujo 4: Edición Contractual

1. Editar contrato vigente.
2. Cambiar locales asociados.
3. Esperado: respeta validaciones; bloquea cambios con deuda/garantía incompatible.

## Flujo 5: Facturación Mensual

1. Ir a `cobros/operacion_mensual.php`.
2. Generar cobro del período.
3. Esperado: documentos correctos por concepto/local, sin duplicados.

## Flujo 6: Cobranza y Pagos

1. Ir a `cobranza/registrar_pago.php`.
2. Registrar pago parcial por concepto.
3. Esperado: imputación correcta y saldo actualizado.

## Flujo 7: Dashboard y Aging

1. Revisar `dashboard/index.php` por período.
2. Revisar `contabilidad/aging.php` con corte.
3. Esperado: montos cuadran con documentos/pagos; orden de locales correcto.

## Flujo 8: Término Operativo

1. Ejecutar término desde `contratos/index.php`.
2. Esperado: contrato pasa a estado `3` (cierre financiero), se libera ocupación física.

## Flujo 9: Cierre Financiero

1. Intentar cierre con deuda pendiente.
2. Intentar cierre sin deuda pendiente.
3. Esperado: bloquea cuando corresponde; cierre exitoso pasa contrato a estado `4`.

## Flujo 10: Recontratación

1. Con contrato cerrado, crear uno nuevo sobre la misma tienda.
2. Esperado: no duplica tienda, nuevo contrato operativo válido.

## Flujo 11: Tiendas Como Módulo Comercial

1. Editar tienda en `tiendas/index.php`.
2. Forzar datos de contrato/garantía desde ese flujo.
3. Esperado: bloqueo con mensaje y derivación a Contratos.

## Checklist de Evidencia QA

1. Caso probado.
2. Datos usados.
3. Resultado esperado.
4. Resultado real.
5. Estado (`OK`/`NOK`).
6. Captura/URL.
7. Error exacto (si aplica).
