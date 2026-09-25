# FTE — Etapa 1: definición y conciliación de dotación

Estado: implementada el 23-09-2026. Esta etapa no modifica la fórmula del FTE ni el calendario mensual.

## Regla definida

- **Dotación mensual usada en FTE:** personas únicas que estuvieron vigentes al menos un día del mes, contadas dentro de cada CECO en el que estuvieron vigentes.
- **Personas únicas del mes:** personas distintas vigentes al menos un día en el alcance consultado. Sirve para detectar duplicidad por traslados entre CECO.
- **Dotación al cierre:** personas vigentes el último día del mes.
- **Dotación promedio por día hábil:** suma de personas-día vigentes en días hábiles dividida por la cantidad de días hábiles del calendario.
- Un ingreso o salida parcial se incluye en la dotación mensual y sus días no vigentes se reflejan en `Ingresos / salidas`.
- El cálculo conserva precisión completa. El redondeo se aplica solamente al mostrar los valores.

La vista mensual muestra las cuatro cifras y alerta si la suma utilizada por CECO supera la cantidad de personas únicas del mes por efecto de un traslado.

## Conciliación de junio de 2026

El Excel de control informa una dotación total de **219**. La reconstrucción actual del Portal/Buk informa **218**.

| CECO | Excel | Portal/Buk | Diferencia | Diagnóstico |
| --- | ---: | ---: | ---: | --- |
| 11-30-30 | 2 | 1 | -1 | Falta una persona histórica en la respuesta actual de Buk. El Excel agregado no permite identificar su nombre. |
| 12-16-16 | 5 | 6 | +1 | Un trabajador aparece en el Portal en este CECO durante junio. |
| 12-27-27 | 14 | 13 | -1 | El mismo trabajador aparece trasladado a este CECO desde el 01-07-2026; el Excel de junio parece haberlo asignado aquí. |

La reasignación probable entre 12-16-16 y 12-27-27 explica ambos desfases sin cambiar el total. La diferencia total restante es la persona histórica faltante en 11-30-30.

## Resultado y límite de esta etapa

- La regla ya está formalizada, expuesta por API y visible en la pantalla mensual.
- Junio queda diagnosticado por CECO y con total de control conocido.
- No se aplicó ningún ajuste manual ni se inventó una persona para forzar 219.
- La conciliación nominal de junio seguirá pendiente hasta obtener la nómina histórica o respaldo individual de la segunda persona de 11-30-30.
- La persistencia de una nómina mensual aprobada corresponde a la etapa 2.

