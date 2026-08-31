# Auditoría de garantías y tesorería MSP

Fecha: 18-08-2026  
Base auditada: `PORTALGP` local (`KVILLEGAS`)  
Alcance: contrato-local, garantías, movimientos, devolución, contabilidad, caja/banco, archivos y conciliación.

## Resultado ejecutivo

El núcleo existente permite definir una garantía por contrato-local, reservar saldo, aplicarlo a cargos/documentos y registrar una devolución. No constituye todavía un circuito de tesorería completo.

La brecha principal era de modelo: `msp_garantias.monto_inicial` se utilizaba simultáneamente como monto pactado y monto recibido. Eso impedía distinguir deuda de garantía, efectivo realmente disponible y respaldos de la recepción.

## Evidencia de la base local antes del prerrequisito

- 149 garantías activas por contrato-local.
- 1 garantía con monto positivo, por $530.000; 148 registros con monto $0.
- 1 garantía con medio de recepción (`Efectivo`); 148 sin medio.
- 149 sin referencia de recepción.
- 0 movimientos en `msp_movimientos_garantia`.
- 1 asiento contable de constitución de garantía.
- 0 duplicados activos por contrato/local.
- 0 garantías sin vínculo `id_contrato_local`.
- 0 asientos contables descuadrados.
- Los triggers contables y de integridad de garantía existen y están habilitados.

## Capacidades existentes

- Monto y fecha de garantía por contrato-local.
- Medio y referencia básicos en la cabecera de garantía.
- Reserva, liberación de reserva, aplicación a cargo y devolución mediante procedimientos almacenados.
- Aplicación de garantía a documento de cobro.
- Asiento de constitución y asiento de aplicación.
- Prevalidaciones para término y traspaso de contrato.
- Visualización del historial en la ficha del contrato.

## Brechas comprobadas

1. No existía una entidad separada para la recepción efectiva de la garantía.
2. El formulario de contrato fuerza `Efectivo (caja)` y no permite transferencia o cheque.
3. La devolución solo inserta `DEVOLUCION` en `msp_movimientos_garantia`; no captura cuenta de salida, beneficiario, banco, cheque ni comprobante.
4. No existe asiento específico para devolución de garantía.
5. No existe libro operativo de caja/banco ni flujo de depósito de efectivo.
6. No existe conciliación bancaria.
7. No existe repositorio de respaldos específico para garantías.
8. Los movimientos de garantía históricos están vacíos; la constitución vive en la cabecera y en contabilidad.

## Prerrequisito implementado

Se aplicó `msp/db/patch_garantias_tesoreria_base.sql`, que crea de forma idempotente:

- `msp_tesoreria_cuentas`: cajas y cuentas bancarias.
- `msp_garantia_recepciones`: recepciones reales, parciales o múltiples.
- `msp_tesoreria_movimientos`: entradas/salidas y estado de conciliación.
- `msp_garantia_archivos`: respaldos de recepción, devolución y cheque.
- `msp_vw_garantias_control_recepcion`: comparación entre monto pactado y recibido.

La única garantía histórica con monto positivo, medio explícito y asiento vigente fue migrada conservadoramente como recepción efectiva y entrada de caja. No se imputaron recepciones a los otros 148 registros porque su monto es cero y no hay evidencia de dinero recibido.

## Validación posterior

- Estado `COMPLETA`: 1 garantía; pactado y recibido $530.000.
- Estado `NO_RECIBIDA`: 148 garantías de monto $0.
- 1 cuenta `CAJA_GENERAL`.
- 1 recepción migrada.
- 1 movimiento de tesorería migrado.
- Segunda ejecución del patch no duplicó registros.
- `DBCC CHECKCONSTRAINTS` no informó infracciones.

## Orden recomendado para la implementación funcional

1. Recepción de garantía: efectivo, transferencia o cheque, con validaciones y comprobante.
2. Caja diaria y depósito de efectivo a banco.
3. Devolución operativa por transferencia o cheque, vinculada al movimiento de garantía.
4. Asiento contable automático de devolución y reversas.
5. Carga/descarga segura de respaldos.
6. Conciliación bancaria y cierre de caja.
7. Reportes y alertas: pactado, recibido, disponible, reservado, aplicado y devuelto.

## Decisiones de seguridad de datos

- No se modificó el significado histórico de `monto_inicial`; desde ahora se interpreta como monto pactado.
- No se inventaron referencias, bancos ni recepciones faltantes.
- La migración histórica exige evidencia conjunta: monto positivo, medio explícito y asiento contable vigente.
- No se añadió integración con QuickBooks.
