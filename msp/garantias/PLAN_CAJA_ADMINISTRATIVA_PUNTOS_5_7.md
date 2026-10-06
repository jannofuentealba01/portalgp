# Devoluciones y registro administrativo: puntos 5 a 7

Fecha: 2026-10-06. Continuacion de los puntos 1–4.

## Estado de implementacion

- Codigo local actualizado y probado; migraciones instaladas exclusivamente en las copias aisladas.
- La base operativa local `PORTALGP` y produccion NO recibieron las migraciones ni movimientos de prueba.
- No se realizo commit, push ni despliegue. El respaldo/acceso productivo pendiente del punto 1 sigue pendiente.
- En un ambiente sin migraciones, la nueva pantalla muestra el motivo y bloquea el envio; el endpoint tambien falla sin registrar movimientos. No hay un camino silencioso que omita la pareja administrativa.
- La regla preexistente de reversas detectada en la copia sigue pendiente del punto 9. No debe activarse el flujo productivo antes de validarla/corregirla, revisar el punto 8 y obtener el respaldo.

## Punto 5: operacion completa, atomica e idempotente

Archivos: `devolucion_service.php`, `registrar_devolucion.php`, `msp/db/patch_garantias_caja_administrativa_integracion.sql`.

- La solicitud tiene una clave aleatoria de 128 bits, independiente del token CSRF. Se almacena de manera persistente con hash de datos, usuario, devolucion y fecha.
- Un bloqueo SQL transaccional por clave serializa solicitudes simultaneas. La misma clave y datos devuelve la devolucion original; otra cuenta, monto, beneficiario o usuario se rechaza si la clave ya fue procesada.
- Se usa una sola transaccion exterior para el procedimiento financiero existente, su egreso real, la pareja administrativa (solo banco) y la clave procesada. Solo se confirma al completar todo.
- Un fallo posterior al egreso, incluso al guardar la clave final, revierte tambien los asientos contables generados por los triggers.
- Para efectivo se conserva el egreso real de caja y no se agrega una pareja administrativa.
- Los procedimientos financieros originales y sus validaciones no fueron reemplazados ni debilitados. Se conserva el permiso de escritura de MSP Cobranza, sesion, CSRF, beneficiario y motivo de autorizacion.
- Se consumen todos los resultados SQL antes de la siguiente operacion, compatible con conexiones PDO SQL Server con MARS.
- Si el resultado de un envio es incierto, la sesion conserva sus datos y clave para el reintento. No se fuerza una clave nueva que pueda duplicar una operacion ya confirmada.
- Una solicitud cuya devolucion ya fue anulada no puede emitirse otra vez con la misma clave.

## Punto 6: formulario

En `garantias/devoluciones.php`:

- Selector **Devolucion parcial / Todo el saldo disponible**.
- Para total, el importe se completa y queda de solo lectura; el servidor comprueba bajo bloqueo que siga siendo exactamente el disponible. Si cambio, pide reconfirmarlo sin alterar silenciosamente el importe.
- Selector **Banco · transferencia / Caja · efectivo**, con cuentas de origen filtradas por tipo.
- Para banco, seleccion de **Caja de registro administrativo**, separada de la cuenta de donde sale el dinero. Una unica caja compatible se selecciona automaticamente; si hay varias, el usuario elige. Se respeta la moneda.
- Banco destino, cuenta y referencia siguen obligatorios para una devolucion por transferencia; al elegir efectivo se ocultan y desactivan. No confundir con las reglas de recepcion de garantias.
- Explicacion de que la pareja no mueve efectivo ni cambia el saldo de caja.
- Confirmacion antes de enviar y bloqueo de doble clic. El servidor, no el JavaScript, garantiza la idempotencia.
- Reintentos conservan beneficiario, referencia, importe, fecha y clave; existe una accion explicita para comenzar una operacion diferente.
- No se implemento una nueva aprobacion independiente: se conserva la autorizacion vigente, que identifica al usuario solicitante como autorizador en el endpoint existente.

## Punto 7: registro diario de Tesoreria

En `tesoreria/control_diario.php`:

- El egreso real bancario y las dos lineas administrativas se muestran juntos, vinculados al mismo numero de devolucion.
- La pareja se muestra en caja como **Entrada administrativa / Salida administrativa**, con etiqueta **Sin movimiento de efectivo**, banco de origen y efecto en efectivo $0.
- Movimiento real diferenciado; importes completos en una sola linea.
- Solo se combinan los registros para mostrarlos. Los indicadores del dia se calculan con el arreglo de movimientos reales, y los saldos conservan su vista original.
- No se alteran las consultas de cierre/conciliacion ni se mezclan registros administrativos en la tabla financiera original. La auditoria integral de esos consumidores sigue correspondiendo al punto 8.
- La consulta es por la fecha seleccionada, con las lineas administrativas anuladas/inconsistentes visibles segun el estado de su origen.
- La pantalla sigue cargando movimientos reales si aun no existe la vista administrativa, sin inventar movimientos historicos.

## Migraciones y activacion

Orden:

1. `msp/db/patch_garantias_caja_administrativa_base.sql` (puntos 3–4).
2. `msp/db/patch_garantias_caja_administrativa_integracion.sql` (punto 5).
3. Desplegar conjuntamente formulario, endpoint, servicio y vista de Tesoreria.

**No activar en produccion todavia:** primero respaldo actual, comprobacion de esquema/version desplegada y puntos 8–9. El despliegue sigue siendo el punto 10.

## Verificacion

- 18 pruebas de la estructura original: correctas, con rollback, en `PORTALGP_TEST_CAJA_ADMIN_20261006_1437`.
- 9 pruebas de integracion: correctas, en la copia desechable `PORTALGP_TEST_CAJA_ADMIN_20261006_57`. Incluyen commit real dentro de esa copia, reintento, efectivo, transferencia, fallos en dos puntos posteriores a la escritura, saldo insuficiente, total obsoleto, total exacto y dos procesos concurrentes.
- Pruebas PHP de render con datos ficticios: formulario, bloqueo sin migraciones, pareja visible, numero comun de devolucion, montos sin salto e indicadores separados.
- Pruebas JavaScript del codigo real del formulario: requisitos por medio, cuentas filtradas, parcial/total, confirmacion cancelada, doble clic, regreso del navegador y conservacion de reintento.
- Lint de los archivos PHP modificados y de las pruebas: correcto. Parche SQL compilado e idempotente.
- Definiciones SQL financieras preexistentes comparadas con la base original: ninguna modificada.
- La copia de integracion contiene exclusivamente operaciones ficticias de prueba y se elimina al finalizar la verificacion; no representa datos productivos. La copia de los puntos 1–4 permanece disponible para continuar las pruebas.
- La verificacion de interfaz fue por render de plantilla y pruebas de comportamiento aisladas; no se afirma haber validado la pantalla nueva en produccion ni una sesion autenticada local.

Comandos (solo copia local desechable, nunca produccion):

```powershell
C:/xampp/php/php.exe tests/msp_garantias_caja_administrativa.php --database=PORTALGP_TEST_CAJA_ADMIN_20261006_1437
C:/xampp/php/php.exe tests/msp_garantias_devolucion_integracion.php --database=PORTALGP_TEST_CAJA_ADMIN_<fecha>
C:/xampp/php/php.exe tests/msp_garantias_devolucion_ui.php
node tests/msp_garantias_devolucion_ui.js
```

La suite de integracion hace commits y consume saldos de la copia, por lo que se debe restaurar una copia nueva para cada ejecucion integral. No reutilizar una base operativa ni quitar triggers para limpiar datos de prueba.

## Respaldo y limites

Los tres PHP existentes se respaldaron antes de editarlos en `C:/xampp/portalgp_backups/garantias_caja_admin_20261006_1437/puntos_5_7/`. Se preservaron los cambios ajenos de MSP y FTE.

No se reconstruyeron parejas para devoluciones antiguas ni se corrigieron datos productivos. El historial integral y las reversas financieras se revisaran en el punto 9. No se publico informacion financiera nominal, respaldos ni credenciales en Git.
