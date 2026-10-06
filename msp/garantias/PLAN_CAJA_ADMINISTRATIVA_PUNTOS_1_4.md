# Devoluciones de garantia: puntos 1 a 4

Fecha: 2026-10-06. Alcance: preparar la base, sin activar un flujo nuevo ni migrar movimientos historicos.

Este documento conserva el estado al finalizar los puntos 1–4. La continuacion esta en `PLAN_CAJA_ADMINISTRATIVA_PUNTOS_5_7.md`.

## Estado y ambientes

- Produccion se inspecciono por navegador autenticado, solo lectura. La evidencia agregada se guardo fuera del directorio publico, en `C:/xampp/portalgp_backups/garantias_caja_admin_20261006_1437/ESTADO_Y_RESPALDOS.md`.
- **Punto 1 parcialmente completado:** codigo local respaldado, base local respaldada con `COPY_ONLY, CHECKSUM` y respaldo validado con `RESTORE VERIFYONLY`. Estos archivos NO son un respaldo de produccion.
- El usuario confirmo que aun no tiene acceso al servidor/SQL productivo. Queda pendiente el respaldo nuevo del codigo desplegado y de la base de produccion, y su verificacion de restauracion. No reemplazarlo por los respaldos historicos de septiembre ni por el respaldo local.
- Puntos 2, 3 y 4 preparados en codigo y probados en una copia aislada de la base local con el esquema y las reglas financieras actuales.
- La migracion se aplico exclusivamente a `localhost / PORTALGP_TEST_CAJA_ADMIN_20261006_1437`. No se aplico a la base local operativa `PORTALGP` ni a produccion.
- No se modificaron las pantallas, los endpoints, los procedimientos financieros existentes ni FTE. No se hizo commit, push o despliegue.

## Punto 2: dos caminos claramente distintos

| Camino | Dinero real | Registro en caja | Efecto en garantia |
| --- | --- | --- | --- |
| Devolucion en efectivo | Egreso real de caja | El egreso financiero existente | Una sola devolucion |
| Transferencia directa desde banco | Egreso real del banco | Pareja administrativa E/S, misma fecha y monto, sin efectivo | Una sola devolucion |

El registro administrativo NO representa retirar dinero del banco y llevarlo fisicamente a caja. Si ese retiro realmente ocurre, requerira movimientos reales distintos; no deben confundirse con la transferencia directa al arrendatario.

La garantia no aumenta por la entrada administrativa. Los registros administrativos no son otra recepcion, deposito, pago, cargo o devolucion. No deben producir asientos contables adicionales ni formar parte de totales reales de entradas/salidas, efectivo contado, saldos disponibles o conciliaciones.

Se mantienen las validaciones actuales del egreso financiero: permisos, CSRF, autorizacion, garantia recibida/disponible, reservas, cuenta de origen, saldo y restricciones de fechas. Esta etapa no las relaja. La caja administrativa no necesita tener efectivo suficiente: no entrega efectivo real.

## Punto 3: estructura separada y trazabilidad

Migracion: `msp/db/patch_garantias_caja_administrativa_base.sql`.

- Tabla `dbo.msp_garantia_devolucion_caja_admin`: una cabecera por devolucion bancaria, enlazada por claves foraneas a la devolucion, su egreso real y la caja administrativa.
- Guarda monto y fecha originales, usuario y fecha de registro. La identidad comercial se obtiene de la devolucion de garantia de tienda; no se agrupa por local ni por nombre libre.
- Unicidad por devolucion y por egreso bancario. Conserva intacto el indice que impide dos egresos financieros vigentes para una misma devolucion.
- Trigger de integridad valida incluso un INSERT directo. No admite UPDATE/DELETE de auditoria. No se deshabilita ningun trigger de seguridad existente.
- No se crea informacion administrativa retroactiva al aplicar el parche.

## Punto 4: pareja administrativa indivisible

- Vista `dbo.msp_vw_garantia_devolucion_caja_admin`: cada cabecera genera exactamente dos lineas, entrada `E` y salida `S`, con el mismo monto/fecha y orden 1/2.
- Ambas dicen **Sin movimiento de efectivo**, `es_administrativo=1`, `afecta_saldo_caja=0`, `impacto_efectivo=0`.
- No se insertan en `msp_tesoreria_movimientos`; los saldos actuales y sus triggers contables permanecen aislados de estas lineas.
- Servicio preparado `dbo.msp_garantia_devolucion_registrar_caja_admin(@id_devolucion_garantia, @id_cuenta_caja, @id_usuario)`.
- Verifica que la devolucion bancaria este emitida y que el egreso vigente coincida en tipo, medio, cuenta, movimiento de garantia, fecha y monto. La caja debe ser activa y tener la misma moneda que el banco.
- Un reintento para la misma devolucion/caja devuelve el ID existente sin duplicar ni sobrescribir el usuario original. Una caja distinta o un origen inconsistente se rechaza.
- No confirma una transaccion externa. Si se integra en el punto 5, debe invocarse dentro de la misma transaccion exterior que registra la devolucion real.
- Si el origen se anula, ambas lineas muestran `ANULADO` sin perder la auditoria. Una discrepancia posterior muestra `INCONSISTENTE`.

## Verificacion realizada

1. Respaldo local validado, restaurado en base de prueba nueva, sin sobrescribir bases existentes.
2. Parche compilado y ejecutado en SQL Server. Segunda ejecucion idempotente verificada.
3. Suite `tests/msp_garantias_caja_administrativa.php`: 18 comprobaciones correctas. Incluye pareja completa, saldos/conteos financieros intactos, transaccion externa, reintentos, anulacion del origen, inconsistencias, efectivo excluido, usuarios, moneda, caja inactiva, origen/fecha/monto incorrectos e inmutabilidad.
4. Todas las operaciones de prueba se revirtieron con rollback. No quedan cabeceras/lineas administrativas de prueba. Los IDs identity pueden avanzar en la copia por las pruebas; no afectan a produccion.
5. Se compararon los hashes de definiciones SQL preexistentes entre la copia y la base local original: ninguna fue cambiada por el parche.
6. El test rechaza la base `PORTALGP` y cualquier nombre que no sea `PORTALGP_TEST_CAJA_ADMIN_<fecha>`; es CLI y solo se conecta a localhost con autenticacion integrada. La excepcion de certificado es exclusivamente del servidor local de pruebas, no de produccion.

Comandos de prueba (solo en una copia aislada ya restaurada):

```powershell
sqlcmd -S localhost -E -C -d PORTALGP_TEST_CAJA_ADMIN_20261006_1437 -b -i msp/db/patch_garantias_caja_administrativa_base.sql
C:/xampp/php/php.exe tests/msp_garantias_caja_administrativa.php --database=PORTALGP_TEST_CAJA_ADMIN_20261006_1437
```

### Hallazgo preexistente para el punto 9

Al probar adicionalmente el procedimiento de reversa actual en la copia, este rechazo la operacion con error 54005: "La reversa no coincide con una operacion de garantia anulada". El procedimiento genera la observacion `devolucion` sin tilde, mientras el trigger de integridad busca `devolucion` con tilde (`devolución`), o una marca alternativa que el procedimiento no agrega. En esta copia la comparacion distingue acentos.

Este rechazo tambien debe verificarse contra el esquema real de produccion cuando exista acceso; no se afirma que ocurra alli. No se corrigio en esta etapa ni se desactivo el trigger. Queda como requisito antes de completar el punto 9 y de activar el nuevo flujo. La propagacion de estado de la nueva vista si se probo de forma aislada.

Para reproducir el diagnostico adicional, con rollback y sin ignorar su fallo:

```powershell
C:/xampp/php/php.exe tests/msp_garantias_caja_administrativa.php --database=PORTALGP_TEST_CAJA_ADMIN_20261006_1437 --check-reversal
```

## Limites y siguientes pasos

- Antes de aplicar el parche productivo: obtener respaldo actual verificado, identificar version desplegada y comparar esquema real. No ejecutar las pruebas financieras en produccion, aunque tengan rollback.
- No usar `git reset --hard` ni restaurar toda la base sobre una operativa para revertir esta preparacion. El parche es aditivo; mientras no se integre, no cambia el flujo existente.
- Punto 5: envolver egreso real y registro administrativo en una transaccion exterior, con clave de idempotencia de la solicitud completa. El reintento idempotente del servicio administrativo NO evita por si solo que el endpoint financiero cree dos devoluciones distintas.
- Puntos 6 a 8: selector de origen y consulta combinada en caja, sin sumar estas lineas a dinero real ni al cuadre.
- Punto 9: corregir/verificar reversas y pruebas integrales; conservar trazabilidad, sin borrar cabeceras administrativas.
- Punto 10: despliegue autorizado y comprobacion de saldos productivos antes/despues.
