# Estados de cierre de período (MSP)

Fecha: 2026-05-19

## Regla operativa

1. `Borrador (1)`: período editable. Permite generar/reemplazar cobros, generar/reemplazar documentos y asignar saldo a favor por período.
2. `Calculado (2)`: período calculado y revisable. Permite pagos normales/anulaciones, pero no nuevas asignaciones automáticas de saldo a favor por período.
3. `Cerrado (3)`: período congelado. No permite generar, reemplazar ni borrar cálculo/documentos. Permite pagos tardíos contra documentos existentes.
4. `Anulado (4)`: período fuera de operación.

## Criterio único

Solo `Borrador (1)` permite alterar cálculo, documentos o asignaciones por período.

## Flujo recomendado

1. Generar en `Borrador`.
2. Quedar en `Calculado` para revisión.
3. Cerrar explícitamente a `Cerrado`.
4. Si se requiere rehacer: reabrir explícitamente a `Borrador` y luego recalcular.

## Saldos a favor

- Pago tardío siempre puede aplicarse a documentos vigentes.
- Si existe excedente, solo se asigna automáticamente a período siguiente cuando ese período está en `Borrador`.
- Si no existe período siguiente o no está en `Borrador`, el excedente queda como saldo a favor de tienda.
