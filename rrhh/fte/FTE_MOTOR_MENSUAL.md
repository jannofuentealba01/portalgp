# FTE — Motor mensual

Fecha: 8 de septiembre de 2026

## Implementación

El motor mensual quedó centralizado en `fte_monthly_engine.php`. Es una función
pura: no consulta Buk, GeoVictoria ni SQL Server. Recibe valores conciliados y
aplica una sola fórmula para evitar diferencias entre futuras vistas,
exportaciones y cierres.

```text
horas_teóricas_dotación = dotación × horas_teóricas_persona
horas_perdidas = vacaciones + ingresos/salidas + licencias + accidentes
                + permisos_día + permisos_hora + fallas/atrasos
horas_ajustadas = horas_teóricas_dotación + horas_extra_autorizadas
                  - horas_perdidas
FTE = horas_ajustadas / horas_teóricas_persona
brecha = dotación - FTE
tasa = horas_perdidas / horas_teóricas_dotación
índice = 1 - tasa
```

## Funciones

- `fte_monthly_calculate()`: calcula un CECO o un total ya consolidado.
- `fte_monthly_calculate_report()`: calcula múltiples CECO y genera totales
  globales desde valores absolutos.

Los porcentajes globales se recalculan desde las horas totales; nunca se suman
porcentajes de filas, corrigiendo el problema identificado en el Excel.

## Controles

- Rechaza horas negativas, valores inválidos y dotación fraccionaria.
- Con cero horas teóricas devuelve `N/A` (`null`) para FTE y tasa.
- Advierte horas ajustadas negativas, pérdidas superiores a las horas
  disponibles y FTE superior a la cantidad física de personas.
- Rechaza CECO duplicados después de normalizar su código.
- Devuelve el desglose de cada causa para permitir trazabilidad.

## Caso de control: agosto de 2026

El motor reproduce:

- Dotación: 236.
- Horas teóricas dotación: 41.654.
- Horas no disponibles: 3.622,95.
- Horas ajustadas: 39.367,05.
- FTE: 223,0428 (223,04 presentado).
- Brecha Dotación/FTE: 12,9572 (12,96 presentada).
- Tasa de horas no disponibles: 8,6977 %.
- Índice global: 91,3023 %.

## Estado de integración

El motor está terminado y disponible para el módulo, pero todavía no se conecta
a una vista mensual porque faltan el calendario parametrizado y la obtención
oficial de cada componente desde Buk/GeoVictoria. No usa el cálculo diario
preliminar como sustituto de esos datos.

Pruebas: `php tests/fte_monthly_engine.php` — 23/23 correctas.
