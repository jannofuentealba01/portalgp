# Seguridad financiera: eliminaciones, regeneraciones y reapertura mensual

Fecha de cierre técnico: 2026-09-10
Base validada: `PORTALGP` local

## Hallazgos corregidos

1. La eliminación de cierres se validaba en PHP, pero no era atómica ni estaba
   protegida contra un `DELETE` directo en SQL.
2. La corrección mensual desvinculaba tablas auxiliares antes de ejecutar el
   procedimiento principal. Si este fallaba, podía quedar una corrección parcial.
3. La regeneración intentaba borrar documentos que ya tenían eventos y asientos;
   en la base actual esto podía fallar por claves foráneas o perder trazabilidad.
4. La reapertura mensual solo cambiaba el estado. No dejaba constancia de los
   pagos, aplicaciones, envíos y asientos existentes al momento de reabrir.

## Controles implementados

- Los documentos y cierres mensuales rechazan borrados SQL directos.
- Solo se elimina un cierre en Borrador realmente vacío; antes se guarda una
  auditoría independiente con período, UF, observaciones, motivo y usuario.
- Antes de reemplazar un documento sin movimientos se guarda su versión completa,
  incluido el detalle JSON, se revierte el asiento y se conserva su identificador
  en los eventos históricos.
- Un documento con pagos —activos o anulados—, saldo a favor, garantía, cargos,
  envíos, respaldos u otros eventos operativos no se reemplaza. Debe corregirse
  mediante una anulación o un ajuste financiero trazable.
- La corrección mensual ahora se ejecuta como una sola unidad atómica. Ya no
  elimina aplicaciones, respaldos ni detalle de operaciones antes del resultado.
- Los pagos no se eliminan físicamente durante una corrección mensual.
- Volver un período a Borrador no revierte dinero ni asientos automáticamente:
  conserva todo y registra una fotografía JSON de sus dependencias. Las acciones
  destructivas posteriores siguen bloqueadas mientras exista historia financiera.
- Las tablas de versiones, eliminaciones y transiciones son de solo lectura para
  la cuenta técnica de ejecución; la escritura válida ocurre mediante procedimientos.

## Estado de la base al revisar

- 414 documentos operativos, todos con evento de emisión.
- Enero, febrero y marzo de 2026 tienen pagos; junio tiene documentos y 2 pagos.
- Mayo de 2026 permanece en Borrador y sin documentos.
- `admin_2` permanece activo, con rol `Administrador`; no se cambió su contraseña,
  estado, rol ni permisos.

## Verificación

- 20/20 comprobaciones específicas aprobadas y revertidas sin dejar datos.
- 46/46 comprobaciones financieras de etapa 4 aprobadas.
- 9/9 comprobaciones del flujo integral de documentos aprobadas.
- 50/50 comprobaciones de integridad comercial y financiera aprobadas.
- Suite completa: 232 archivos PHP y 8 suites, sin fallos.
- Verificador de base: 70/70 parches incluidos, aplicados y registrados; cero
  errores y cero advertencias de esquema.
- Permanecen dos advertencias operativas previas: 2 movimientos bancarios sin
  conciliar y 21 movimientos de caja sin cierre. No son descuadres de integridad.
