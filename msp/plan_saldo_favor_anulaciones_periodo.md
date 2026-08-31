# Plan: Saldo a Favor Sin Huérfanos

## Objetivo

Corregir el flujo hacia adelante para que los saldos a favor no queden desincronizados entre:

- Saldo global por tienda: `msp_saldos_favor_tienda` y `msp_movimientos_saldo_favor_tienda`.
- Trazabilidad por período: `msp_saldo_favor_periodo_items`.
- Aplicaciones a documentos: `msp_saldo_favor_periodo_aplicaciones`.

Políticas definidas:

- Si un pago tardío genera saldo a favor para un período que ya tiene documentos generados, el sistema debe intentar aplicarlo inmediatamente a documentos existentes con saldo pendiente del mismo período y tienda.
- Si se anula el pago origen de un saldo a favor ya aplicado, la anulación debe bloquearse hasta anular primero la aplicación del saldo.
- No se hará reparación histórica automática en esta intervención.
- No se incluyen tests por ahora.

## Estado General

- [x] Slice 1: Preparar patch SQL de anulación consistente con período.
- [x] Slice 2: Sincronizar anulación de pago origen con `msp_saldo_favor_periodo_items`.
- [x] Slice 3: Sincronizar anulación de aplicaciones de saldo a favor.
- [x] Slice 4: Aplicar saldo tardío a documentos ya generados.
- [x] Slice 5: Ajustar mensajes de usuario.
- [x] Slice 6: Actualizar instalación base.
- [x] Slice 7: Revisión manual de consistencia.

## Slice 1: Patch SQL Base

Objetivo: crear un patch incremental para encapsular la corrección sin editar directamente la base en caliente.

To-do:

- [x] Crear `msp/db/patch_saldo_favor_anulaciones_periodo.sql`.
- [x] Incluir `SET NOCOUNT ON` y separadores `GO`, siguiendo estilo de parches MSP.
- [x] Reemplazar `dbo.msp_anular_pago_documento` con `CREATE OR ALTER PROCEDURE`.
- [x] Mantener los errores actuales de anulación (`50071` a `50075`) para compatibilidad con PHP.
- [x] Agregar solo lógica necesaria para sincronizar items y aplicaciones de período.

Criterios de término:

- [x] El patch puede ejecutarse de forma idempotente.
- [x] No elimina pagos, movimientos, items ni aplicaciones.
- [x] Solo inserta reversas y cambia estados auditables.

## Slice 2: Anulación Del Pago Origen

Objetivo: si se anula un pago real que creó excedente, el item de período asociado no debe seguir pendiente.

To-do:

- [x] En `msp_anular_pago_documento`, detectar pagos con `monto_saldo_favor_generado > 0` y `aplica_desde_saldo_favor = 0`.
- [x] Buscar el movimiento positivo de saldo a favor asociado por `id_pago`, `id_documento_cobro`, `tipo_movimiento = 1`.
- [x] Buscar el item asociado en `msp_saldo_favor_periodo_items.id_movimiento_saldo_favor`.
- [x] Si el item tiene aplicaciones activas (`estado_aplicacion = 1`), bloquear la anulación con `50075`.
- [x] Si no tiene aplicaciones activas, insertar movimiento reversa tipo `3` por el monto generado.
- [x] Marcar `msp_saldo_favor_periodo_items.estado_item = 5`.
- [x] Guardar `id_movimiento_reversa` en el item.
- [x] Actualizar `fecha_actualizacion` del item.

Criterios de término:

- [x] Al anular el pago origen, el saldo global se revierte.
- [x] El item por período desaparece de “Pendientes por aplicar”.
- [x] El historial queda trazable por movimiento origen y movimiento reversa.

## Slice 3: Anulación De Aplicación De Saldo

Objetivo: si se anula un pago que aplicó saldo a favor, la aplicación por período también debe quedar anulada.

To-do:

- [x] En `msp_anular_pago_documento`, detectar pagos con `aplica_desde_saldo_favor = 1`.
- [x] Mantener la reversa positiva existente en `msp_movimientos_saldo_favor_tienda` tipo `4`.
- [x] Buscar aplicación activa en `msp_saldo_favor_periodo_aplicaciones.id_pago = @id_pago`.
- [x] Marcar `estado_aplicacion = 5`.
- [x] Actualizar `fecha_actualizacion`.
- [x] No anular el item origen, porque el saldo vuelve a estar disponible si el origen sigue vigente.

Criterios de término:

- [x] El documento recupera saldo pendiente por el trigger de pagos.
- [x] El saldo global vuelve a estar disponible.
- [x] La aplicación ya no descuenta disponibilidad del item.

## Slice 4: Saldo Tardío Con Documento Ya Generado

Objetivo: cuando un pago de un mes anterior crea saldo para el mes siguiente ya generado, aplicarlo automáticamente si existe documento pendiente.

To-do:

- [x] Revisar `msp/pagos/saldo_favor_periodo_helper.php`.
- [x] Mantener creación del item en `msp_saldo_favor_periodo_items` para el período siguiente.
- [x] Después de crear o resolver el item, buscar documento del período siguiente para la misma tienda.
- [x] Filtrar documentos no anulados con `saldo_pendiente > 0`.
- [x] Aplicar hasta el mínimo entre monto disponible del item, saldo global disponible y saldo pendiente del documento.
- [x] Reutilizar `dbo.msp_aplicar_saldo_favor_documento` para crear el pago de aplicación y mover saldo global.
- [x] Registrar fila en `msp_saldo_favor_periodo_aplicaciones` con `id_pago` generado.
- [x] Si no hay documento pendiente, dejar el item como pendiente.

Criterios de término:

- [x] Un excedente de Enero puede aplicarse a Febrero aunque Febrero ya esté generado.
- [x] Si se aplica completo, no aparece en “Pendientes por aplicar”.
- [x] Si se aplica parcial, queda pendiente solo el remanente real.

## Slice 5: Mensajes De Usuario

Objetivo: que la UI explique correctamente los bloqueos y el estado del saldo.

To-do:

- [x] Revisar `msp/pagos/anular.php`.
- [x] Mantener mensaje para `50075`, pero ajustarlo si hace falta para indicar que primero debe anularse la aplicación del saldo a favor.
- [x] Revisar textos en `msp/cobros/operacion_mensual.php` en “Pendientes por aplicar”.
- [x] Aclarar que los pendientes son saldos vigentes no aplicados, no saldos anulados.
- [x] No cambiar layout salvo texto estrictamente necesario.

Criterios de término:

- [x] El usuario entiende por qué no puede anular un pago origen si el saldo ya fue usado.
- [x] La pantalla no presenta como pendiente un saldo anulado por flujo nuevo.

## Slice 6: Instalación Base

Objetivo: que instalaciones nuevas queden con el mismo comportamiento que una instalación actualizada por patch.

To-do:

- [x] Replicar la versión final de `msp_anular_pago_documento` en `msp/db/initial_msp.sql`.
- [x] Verificar que el orden del bloque no rompa dependencias de tablas existentes.
- [x] Agregar referencia del nuevo patch si existe una sección de orden/documentación de patches.

Criterios de término:

- [x] Base nueva y base parcheada tienen el mismo SP de anulación.
- [x] No se duplican bloques contradictorios del procedimiento.

## Slice 7: Revisión Manual De Consistencia

Objetivo: revisar la coherencia de los flujos sin incluir tests automatizados por ahora.

To-do:

- [x] Revisar que ninguna consulta de “Pendientes por aplicar” incluya `estado_item = 5`.
- [x] Revisar que ninguna consulta de disponibilidad descuente aplicaciones con `estado_aplicacion = 5`.
- [x] Revisar que los flujos manuales existentes de saldo a favor sigan usando los mismos estados.
- [x] Revisar que no se introduzca borrado físico de historial.
- [x] Revisar que los errores SQL sigan siendo capturados por los PHP actuales.

Criterios de término:

- [x] Flujo de pago real, aplicación de saldo y anulación quedan consistentes.
- [x] No hay reparación histórica automática incluida.
- [x] El documento queda listo para marcar avances por slice.

## Fuera De Alcance Por Ahora

- [ ] Reparación automática de saldos huérfanos existentes.
- [ ] Diagnóstico SQL histórico para identificar casos anteriores.
- [ ] Tests automatizados.
- [ ] Rediseño completo del módulo de operación mensual.
- [ ] Cambios visuales grandes en UI.

## Notas De Implementación

- No usar borrado físico para corregir saldos: usar reversas y estados.
- Priorizar cambios en SQL porque ahí vive la atomicidad del pago/anulación.
- Mantener PHP como capa de mensajes y orquestación mínima.
- Si aparece un saldo histórico huerfano durante revisión, documentarlo pero no corregirlo en este alcance.
