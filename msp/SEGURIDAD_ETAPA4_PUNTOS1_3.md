# PortalGP — Etapa 4 de seguridad, puntos 1 a 3

Fecha: 8 de septiembre de 2026
Alcance: pagos, garantías y saldos a favor en la base operativa `PORTALGP`.

## Resultado ejecutivo

Los tres primeros puntos de integridad financiera quedaron auditados,
fortalecidos y comprobados sobre la base local. No se eliminaron movimientos
históricos ni se alteró la cuenta `admin_2`.

## 1. Pagos y aplicaciones

- Se verificó que los saldos de documentos coincidan con sus pagos activos y
  que el detalle por concepto coincida con el total aplicado.
- Las operaciones de pago por contrato mantienen igualdad entre total recibido,
  total aplicado y saldo a favor generado.
- Registrar, anular o aplicar saldo a favor ahora se ejecuta dentro de una única
  transacción, incluidos los pasos auxiliares de sincronización.
- La restricción `CK_msp_pco_montos` exige el balance exacto de una operación,
  con tolerancia de un centavo.

## 2. Garantías

- Se comprobó la relación entre garantía pactada, recepción confirmada,
  aplicaciones, devoluciones, reversas y tesorería.
- Se impide confirmar una recepción sobre una garantía anulada o superar el
  monto pactado; también se impiden saldos disponibles o reservados negativos.
- Los movimientos y las reversas financieras son inmutables por eliminación y
  cada reversa debe corresponder a su operación de origen.
- Se corrigió un defecto real: una reversa convertía el débito original en un
  ajuste positivo y duplicaba el monto restituido. Ahora conserva el débito y
  agrega una sola contrapartida positiva identificable.
- Las dos reversas históricas existentes se normalizaron de forma idempotente.
  La garantía 100 volvió a un saldo disponible correcto de $100.000.

## 3. Saldos a favor

- Se verificó que el resumen por tienda coincida con la suma del libro de
  movimientos y que ningún saldo actual sea negativo.
- Se validan signo, tienda, pago y documento en cada movimiento nuevo. Los
  movimientos ya registrados no pueden borrarse físicamente.
- Un pago o documento con movimientos de saldo a favor asociados tampoco puede
  eliminarse; se evita crear nueva historia huérfana.
- Las aplicaciones por período validan tienda, período, documento y pago, y no
  pueden superar el saldo asignado al ítem.
- El índice filtrado `UX_msp_msf_pago_tipo_operativo` evita duplicar el mismo
  movimiento operativo para un pago.

## Hallazgo histórico resuelto

Los 60 movimientos antiguos de saldo a favor fueron revisados individualmente.
Corresponden a 30 pares exactos con efecto neto cero: 25 aplicaciones y sus
reversas, más 5 excedentes y sus reversas. Se conservaron los identificadores
históricos y cada movimiento quedó vinculado a su contrapartida en un registro
de auditoría inmutable.

Se detectó y corrigió un defecto contable real: las 25 aplicaciones mantenían
su asiento activo aunque su movimiento financiero ya estaba revertido. Ahora
los 25 asientos originales están anulados y existen sus 25 contrapartidas
contables. Los saldos disponibles no fueron modificados y continúan cuadrando
con el libro de movimientos.

## Componentes implementados

- `msp/db/patch_seguridad_financiera_etapa4_puntos1_3.sql`
- `msp/db/patch_auditoria_saldo_favor_historico.sql`
- `msp/db/core_msp_migrate.sql`
- `msp/db/msp_instalar_core.sql`
- `msp/db/patch_garantias_etapa1_operativa.sql`
- `msp/pagos/guardar.php`
- `msp/pagos/aplicar_saldo_favor.php`
- `msp/pagos/anular.php`
- `msp/garantias/aplicar_documento.php`
- `msp/contratos/movimiento_garantia_cargo.php`
- `tests/security_financial_stage4_points1_3.php`
- `tests/msp_saldo_favor_historico_audit.php`

## Verificación

- Pruebas específicas de etapa 4: 46/46.
- Seguridad financiera de etapa 2: 20/20.
- Regresión funcional MSP: 21/21.
- Cierre de seguridad etapa 3: 46/46.
- Los flujos reales de pago, aplicación de saldo y reversa se probaron dentro de
  transacciones revertidas, sin dejar datos de prueba.
- El parche se reaplicó correctamente, confirmando que es idempotente.

## Cuenta de desarrollo

`admin_2` se mantiene habilitado, con rol Administrador,
`security_version=0`, sus permisos vigentes y su contraseña sin cambios.
