# Garantias: puntos 7 a 10

Fecha: 2026-10-06. Complementa los documentos de puntos 1–4 y 5–7.

## Estado real

- 7: vista diaria implementada y nuevamente verificada con render aislado.
- 8: aislamiento de saldos/cierre/conciliacion verificado; reglas de periodo corregidas en una migracion incremental.
- 9: reversas e historial implementados y probados en copia SQL aislada.
- 10: preparacion de despliegue documentada; **NO ejecutado en produccion**. Falta acceso al servidor/SQL y respaldo productivo actual.
- Al terminar la implementacion el usuario indico **seguir revisando sin commit**. Posteriormente, el mismo 2026-10-06, autorizo subir a Git y desplegar. La publicacion productiva sigue condicionada a acceso y respaldo reales; ver la nota de publicacion al final.
- La base operativa local `PORTALGP` tampoco recibio estas migraciones. El formulario permanece bloqueado hasta que exista el esquema completo validado.
- El servidor HTTP productivo responde 200 redirigiendo al login en una comprobacion no autenticada. Eso no demuestra que el flujo nuevo este desplegado.

## 7. Vista diaria

`msp/tesoreria/control_diario.php` muestra un egreso real del banco junto a entrada/salida administrativas en caja, con la misma devolucion. Las dos lineas tienen efecto en efectivo cero. Los indicadores se calculan antes de unir las filas para presentacion.

No se crean recepciones ficticias ni transferencias reales banco–caja. Una transferencia al arrendatario no significa que el efectivo fisico pase por caja.

## 8. Saldos, cierres y conciliacion

La tabla administrativa esta separada de `msp_tesoreria_movimientos`. No basta con sumar cero neto: tampoco deben inflarse las entradas y salidas brutas del dia.

Consumidores revisados:

| Consumidor | Fuente financiera | Tratamiento administrativo |
| --- | --- | --- |
| Saldo por cuenta | `msp_vw_tesoreria_saldos` / movimientos reales vigentes | No se incluye |
| Entradas/salidas del dia | arreglo de movimientos reales en Control diario | Solo presentacion posterior |
| Cierre de caja | `msp_tesoreria_cerrar_caja` | No se incluye ni aumenta el efectivo contado |
| Cartola y seleccion de items | `conciliacion.php` / `msp_tesoreria_conciliar_banco` | Solo IDs de movimientos reales |
| Pendientes bancarios | `PendientesService::consultarTesoreria` | Solo movimientos reales |
| Correcciones de contrato | `CorreccionesService` | Solo movimientos reales |
| Contabilidad | triggers existentes de tesoreria y reversas | No se crea otro asiento por la pareja |

Migracion: `msp/db/patch_garantias_caja_administrativa_cierre_reversas.sql`.

- Conserva los procedimientos desplegados mediante sustituciones de anclas exactas dentro de una transaccion. Si la version no coincide, aborta; no reemplaza silenciosamente una version distinta.
- Agrega comprobacion del periodo de la cuenta **real**, con bloqueos transaccionales.
- Caja cerrada impide nueva devolucion y reversa. Un cierre `REABIERTA` por el flujo autorizado no sigue tratandose como activo.
- Conserva la restriccion bancaria anterior: cualquier conciliacion registrada del periodo, incluso pendiente, bloquea modificarlo. Un item conciliado tambien sigue bloqueado.
- La caja meramente administrativa no requiere efectivo disponible ni reapertura financiera: no participa del dinero. Su cierre no bloquea anular un egreso bancario que aun permite reversa.
- Se conservan saldo real suficiente, saldo de garantia disponible, reservas, medio/cuenta, permisos y CSRF.
- La disponibilidad del formulario exige migraciones completas y triggers de integridad y contabilidad activos. Si faltan o no se puede verificar la version, el envio falla sin registrar una operacion parcial.

## 9. Reversas e historial

Se detecto en la copia un desacuerdo entre los textos del procedimiento y el trigger de integridad: `devolucion/aplicacion` frente a `devolución/aplicación`. La migracion alinea las observaciones nuevas con el trigger existente, sin debilitarlo ni reescribir historia.

- La reversa mantiene el original, registra motivo/usuario y compensacion, restituye saldo de garantia y origen real, y genera la reversa contable existente.
- Ambas lineas administrativas heredan `ANULADO` del mismo origen; no se borran ni se generan dos nuevas devoluciones.
- No se puede usar la clave original para reemitir una devolucion ya anulada.
- La fecha de reversa no puede preceder al origen. El usuario debe estar habilitado.
- `revertir.php` conserva permiso de eliminacion, valida CSRF explicitamente y consume todos los resultados SQL antes de anunciar exito. No muestra detalles SQL sin filtrar.
- `ficha.php` pasa a la misma fuente completa de eventos usada por el historial, que incluye reversas y estados reales; la fuente anterior marcaba todos los movimientos como vigentes.
- `historial.php` y `ficha.php` muestran **Registro administrativo en caja** desplegable dentro de la devolucion. Incluye E/S, importes, estado, caja y numero comun de devolucion.
- La pareja no aumenta el numero de eventos financieros, no cambia la paginacion de 50 ni modifica saldos acumulados. El orden y filtros existentes se conservan.
- No se reconstruyen pares administrativos para devoluciones historicas anteriores.

## Verificacion realizada

Se restauro una copia LOCAL desechable desde el respaldo `COPY_ONLY` previo, sin usar configuracion ni credenciales de produccion. Base: `PORTALGP_TEST_CAJA_ADMIN_20261006_89`.

- 19 pruebas de estructura/idempotencia/inmutabilidad, incluida reversa financiera real: correctas.
- 11 pruebas de cierre/reversas: correctas. Incluyen cierre sin inflar E/S, un egreso en efectivo, conciliacion de un solo item bancario, periodos protegidos, solicitud/aprobacion de reapertura por usuarios distintos, saldos y pasivo restaurados, historial sin doble impacto, actor/fecha y bloqueo sin trigger de integridad.
- 10 pruebas de integracion con commits **solo en la copia**: correctas. Incluyen parcial, total, efectivo/banco, fallos posteriores al egreso, saldo insuficiente, total obsoleto, reintento, concurrencia y reintento de solicitud anulada.
- 4 grupos de render PHP y pruebas JavaScript: correctos. Incluyen pareja y reversa en ficha, importes sin salto, escape HTML, bloqueo sin migraciones y doble clic.
- Lint de 12 PHP y sintaxis JavaScript: correctos. Migracion SQL ejecutada y repetida sin duplicar objetos.
- Pruebas de cierre/reversas y estructura usan rollback y comprueban conteos/saldos originales. La suite de integracion hace commits; se debe restaurar una copia nueva para repetirla.
- No se afirma una prueba visual en navegador de la nueva pantalla autenticada ni pruebas de movimientos productivos. La copia no permite concluir datos financieros actuales de produccion.

La copia desechable de este turno se elimina al finalizar; se conserva el respaldo original y las pruebas. La copia de los puntos 1–4 permanece disponible.

## 10. Procedimiento de publicacion pendiente

1. Obtener acceso autorizado al servidor y SQL. No pedir ni publicar contrasenas en el chat. Confirmar ruta real del sitio, rama/commit desplegados y base real: no asumir que `localhost` representa produccion.
2. Tomar respaldo **actual de produccion** de codigo, base y definiciones SQL; verificar recuperacion y conservarlo fuera del directorio publico. Los respaldos locales y los antiguos de septiembre no lo sustituyen.
3. Registrar baseline real: saldos por cuenta, recibidos/reservados/disponibles/devueltos por garantia, pasivo contable, diferencias de submayor y conteos/IDs. Si hubo nuevos movimientos, usar los actuales, no los de la auditoria anterior.
4. Comparar los cambios contra la version desplegada y excluir los cambios ajenos de FTE y `descargar_archivo.php`. La confirmacion posterior del usuario autoriza el commit de esta solucion, no mezclar otros trabajos pendientes.
5. Restaurar ese respaldo productivo en un ambiente aislado; comprobar anclas del parche y correr las suites sin transacciones de prueba en produccion. Verificar permisos del usuario real de la aplicacion para ejecutar procedimientos y consultar metadata de version; no otorgar privilegios generales para sortear errores.
6. En ventana de mantenimiento, bloquear envios durante la publicacion. Instalar con `sqlcmd -b -f 65001` en orden: **base**, **integracion**, **cierre_reversas**. Detener al primer error. No reinstalar patches antiguos que redefinan procedimientos ya corregidos.
7. Publicar conjuntamente `devoluciones.php`, `registrar_devolucion.php`, `devolucion_service.php`, `caja_admin_historial.php`, `revertir.php`, `ficha.php`, `historial.php` y `tesoreria/control_diario.php`. Recargar cache de PHP/opcache y metadata si corresponde, sin reinicio innecesario de otras aplicaciones.
8. Antes de habilitar envios, comprobar objetos, triggers, indice unico de egreso, version, permisos y vista con sesion autorizada. Abrir formularios/historial/Tesoreria sin guardar operaciones ficticias.
9. Comparar baseline: una migracion sin movimientos nuevos debe conservar exactamente saldos/conteos financieros. La primera operacion real autorizada se verifica nominalmente: un egreso y un asiento; banco con pareja de efecto cero o efectivo sin pareja. La prueba de fallos/doble clic/reversa deliberada queda en la copia, no en datos reales.
10. Si falla, mantener bloqueados los envios y restaurar codigo/definiciones respaldadas con el administrador. **No borrar registros ni restaurar una base antigua sobre movimientos nuevos.** Si ya hubo operaciones reales, conservar auditoria, claves y pares, reconciliar antes de decidir rollback. Nunca usar `git reset --hard` sobre cambios del servidor.

No hay release productivo ni comprobacion posterior productiva todavia. Esos datos deben anotarse al ejecutar realmente el punto 10. Los hashes de codigo se consultan en Git, sin confundirlos con un despliegue.

## Limites

No cambia recepciones, depositos, clasificacion de cheques ni el modelo general de conteo fisico. No inventa un retiro banco–caja. El flujo de aplicaciones a cargos permanece con sus reglas existentes; solo se alinea el texto nuevo de su reversa con integridad. Esta entrega no certifica cartolas ni efectivo fisico de produccion.

## Nota de publicacion a Git (solicitud posterior del 2026-10-06)

- El usuario autorizo versionar y publicar esta solucion; se incluye solo garantias, su vista de Tesoreria, migraciones, pruebas y documentacion. FTE, archivos de contexto ajenos y `descargar_archivo.php` quedan fuera.
- Rama de integracion: `dev`. Rama utilizada para AWS: `codex/msp-aws-deploy`. La rama AWS tiene su propia historia; no se usa un push forzado ni se reemplazan sus cambios.
- No se encontro un workflow de despliegue automatico en el repositorio. Un push no demuestra actualizacion del servidor.
- La comprobacion de SSH al servidor productivo agoto el tiempo de conexion. La configuracion remota de SQL disponible en este equipo tampoco establecio conexion (SQLSTATE 08001). No se ejecutaron consultas financieras ni migraciones por ese intento fallido.
- Se repitio la validacion completa en una copia nueva `PORTALGP_TEST_CAJA_ADMIN_20261006_101`: 19 pruebas de estructura, 11 de cierre/reversas, 10 de integracion, 4 grupos de render y pruebas JavaScript, todas correctas. La copia se elimina despues; el respaldo local original se conserva.
- El respaldo disponible sigue siendo **local de la base local**. No se presenta como respaldo productivo ni como autorizacion para restaurar datos locales sobre produccion.
- Hasta disponer del acceso y generar/verificar un respaldo actual de produccion, el punto 10 permanece pendiente. No se aplican solo las migraciones por separado de la publicacion de codigo.
