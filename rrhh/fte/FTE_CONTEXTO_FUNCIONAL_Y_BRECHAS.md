# PortalGP RR.HH. / FTE — contexto funcional y brechas

Fecha de línea base: 8 de septiembre de 2026
Estado: actualizado con reunión y análisis del Excel mensual de RR.HH.

## Actualización funcional posterior a la reunión

La referencia principal pasó a ser el informe mensual que RR.HH. construye
manualmente en Excel. El tablero diario sigue siendo útil como detalle y
trazabilidad, pero no representa la fórmula oficial del FTE mensual.

Objetivo vigente:

> Automatizar por mes y CECO las horas teóricas de la dotación, sumar horas
> extra autorizadas, descontar horas no disponibles y calcular FTE mensual,
> brecha Dotación/FTE y tasa de horas no disponibles para gerencia.

Fórmula funcional identificada:

```text
horas_teóricas_dotación = dotación × horas_teóricas_persona
horas_perdidas = vacaciones + ingresos/salidas + licencias + accidentes
                + permisos_día + permisos_hora + fallas/atrasos
horas_ajustadas = horas_teóricas_dotación + horas_extra - horas_perdidas
FTE_mensual = horas_ajustadas / horas_teóricas_persona
brecha = dotación - FTE_mensual
tasa_horas_no_disponibles = horas_perdidas / horas_teóricas_dotación
```

Las horas teóricas dependen del calendario y de reglas vigentes por período. En
el régimen reciente se informaron 8,5 horas de lunes a jueves y 8 horas el
viernes, pero el Excel demuestra cambios durante 2026; no debe fijarse una sola
jornada para todos los meses.

El ejemplo de control principal es agosto de 2026: dotación 236, 176,5 horas
teóricas por persona, 1.336 horas extra, 3.622,95 horas no disponibles y FTE
223,04. La brecha 12,96 es una diferencia de equivalencia de horas, no prueba
que sobren 13 personas.

La tasa del Excel incluye vacaciones, movimientos de ingreso/salida, licencias,
accidentes, permisos y fallas/atrasos. Por eso debe llamarse preferentemente
“tasa de horas no disponibles” y no ausentismo estricto.

## Contraste con el Portal actual

- El Portal actual calcula un FTE diario desde primera entrada, última salida,
  colación y una jornada base general. Ese cálculo no reproduce el FTE mensual.
- `EXTRA = NETO - jornada_base` es indicativo; RR.HH. requiere horas extra
  autorizadas de lunes a viernes y excluye sábado en este informe.
- La dotación Buk actual representa trabajadores activos hoy. No permite por sí
  sola reconstruir una dotación histórica mensual; deben incorporarse fechas de
  ingreso/salida y acordar la regla de corte mensual.
- La colación puede servir en el detalle diario, pero no debe descontarse otra
  vez si las horas teóricas mensuales ya la excluyen.
- El gráfico actual resume promedios por CECO; todavía no representa una serie
  diaria real aunque la API disponga de `cost_center_days`.
- Las vacaciones y licencias están programadas parcialmente. Accidentes,
  permisos, fallas/atrasos e ingresos/salidas aún no forman el cálculo mensual.
- No existe persistencia histórica ni cierre reproducible del cálculo mensual.

## Correcciones que el Portal debe aportar frente al Excel

- Calcular por identificadores y CECO, sin referencias manuales entre filas.
- Proteger divisiones por cero y mostrar `N/A` cuando no existan horas teóricas.
- Verificar que la suma de CECO coincida con el total general.
- Calcular el índice global desde los totales; no sumar índices porcentuales por
  CECO.
- Mantener trazabilidad de la fuente y composición de cada cifra.
- No confundir `DOT. PLANTA PPTO` con FTE requerido por la operación.

## Decisiones aún pendientes de RR.HH. o de las APIs

1. Campo exacto de GeoVictoria para horas extra autorizadas.
2. Fuente y reglas de accidentes, permiso día, permiso hora y falla/atraso.
3. Tratamiento de feriados, turnos, jornada parcial y artículo 22.
4. Regla de dotación mensual: cierre, promedio o personas vigentes en algún
   momento del mes.
5. Tratamiento de personas sin GeoVictoria y marcaciones incompletas.
6. Nombre definitivo de la brecha y de la tasa del informe.
7. Uso futuro de Dotación Planta PPTO.
8. Necesidad de guardar cierres mensuales en SQL Server para auditoría.

## Prioridad de desarrollo actualizada

1. Validar GeoVictoria y las fuentes reales de cada ajuste. La conexión,
   `AttendanceBook` y su estructura técnica fueron validados el 8 de septiembre;
   queda confirmar con RR.HH. cuál campo es oficial para cada concepto.
2. Implementar una función única y comprobable para la fórmula mensual.
   Completado el 8 de septiembre en `fte_monthly_engine.php`, validado contra el
   ejemplo de agosto y totales de múltiples CECO.
3. Implementar calendario parametrizado y reglas vigentes por fecha.
   Completado el 8 de septiembre: reproduce enero-agosto de 2026 y conserva el
   2 de enero como excepción pendiente de validación con RR.HH.
4. Incorporar dotación histórica Buk con ingresos, salidas y CECO.
   Completado el 8 de septiembre en `fte_headcount_history.php`. La comparación
   real favorece “vigentes en algún momento”, pero RR.HH. debe confirmar la
   regla porque solo coincide exactamente con julio y agosto.
5. Automatizar cada suma/descuento con trazabilidad.
6. Crear la vista mensual gerencial; conservar persona/día como respaldo.
   Completado de forma preliminar el 8 de septiembre en `fte_mensual.php`:
   presenta indicadores, tabla y rankings por CECO, además de la cobertura de
   fuentes y advertencias sobre componentes todavía no confirmados por RR.HH.
7. Agregar validaciones de cuadratura e historia reproducible.

## Norte funcional preliminar

Centralizar y cruzar información laboral de Buk con asistencia de GeoVictoria
para responder tres preguntas:

1. ¿Quién debería estar trabajando?
2. ¿Quién realmente trabajó y cuánto?
3. ¿Cuánta dotación efectiva hubo y en qué centro de costo?

El valor esperado no es replicar ambas plataformas, sino interpretar sus datos,
resumirlos por CECO y dirigir la atención de RR.HH. hacia las excepciones.

Este objetivo es una hipótesis de trabajo actual. No debe considerarse una
especificación definitiva hasta conversar con RR.HH. sobre jornadas, turnos,
ausencias, artículo 22, horas extra e indicadores necesarios.

## Qué hace actualmente el módulo

### Buk comprobado

- Se conecta a Buk y obtiene trabajadores desde `/api/v1/chile/employees`.
- Excluye registros cuyo estado real es `inactivo`.
- Normaliza el RUT para usarlo como identidad de cruce.
- Obtiene el CECO del trabajo vigente y enriquece su nombre con las áreas Buk.
- Agrupa la dotación activa por CECO y permite seleccionar uno o varios.
- La última comprobación real obtuvo 231 trabajadores activos y 26 CECO.

### Dashboard disponible

- Permite elegir rango, CECO, jornada base genérica, hora de inicio y minutos de
  colación.
- Contiene el cliente para consultar `AttendanceBook` de GeoVictoria por RUT.
- Si recibe marcaciones, toma la primera entrada y la última salida del día.
- Calcula de forma preliminar horas netas, exceso sobre la jornada base y FTE.
- Presenta resumen por CECO, detalle diario por persona y gráfico de asistencia.

### Ausencias disponible parcialmente

- Parte de las filas sin marcación generadas por el dashboard.
- Contiene consultas y correlación para vacaciones y licencias de Buk.
- Puede distinguir `Vacaciones`, `Licencia médica` y `No informada` cuando las
  respuestas coinciden con el trabajador y la fecha.

### Plataforma y seguridad

- Usa PortalGP para sesión y permiso `Ver Dashboard FTE`.
- `admin_2` conserva acceso completo.
- No crea tablas funcionales ni guarda historia laboral en SQL Server.
- Los datos temporales quedan en sesión por tiempo limitado.
- Valida TLS y evita insertar datos externos como HTML.

## Qué no hace actualmente

- GeoVictoria aún no ha sido validado de extremo a extremo con una respuesta
  real de `AttendanceBook` para un RUT y un día.
- No calcula quién debía trabajar según turno o calendario individual. La
  “dotación” actual significa trabajadores activos del CECO, no necesariamente
  trabajadores programados para ese día.
- Ya distingue en el motor trabajado, marcación incompleta, sin marcación,
  vacaciones, licencia, accidente, permisos, día libre, artículo 22, identidad
  no conciliada y error del proveedor. Falta conectar las fuentes oficiales de
  RR.HH. para accidente, permisos, día libre y artículo 22, y definir
  teletrabajo, turno nocturno y feriado trabajado.
- No determina una falta o ausencia injustificada.
- No utiliza la jornada contractual individual de Buk para el cálculo diario.
- No calcula horas extra oficiales; el valor mostrado es solo una diferencia
  matemática indicativa.
- No tiene bandeja de anomalías ni flujo para revisar o resolver casos.
- No guarda historia, por lo que no ofrece tendencias consolidadas semanales o
  mensuales permanentes ni comparaciones históricas.
- No integra remuneraciones, costos laborales, KPI ni evaluación de desempeño.
- No mide productividad individual ni producción obtenida.

## Riesgo funcional actual prioritario

Si GeoVictoria no responde, el dashboard conserva la nómina Buk y clasifica las
filas como `ERROR_GEOVICTORIA`; ya no se confunden con una falta de marcación.
`SIN_MARCACION` sigue siendo un caso para revisión y no equivale por sí solo a
una ausencia injustificada.

Los estados mínimos implementados son:

- Con marcaciones.
- Sin marcación comprobada.
- Vacaciones.
- Licencia.
- Permiso.
- Día libre o sin jornada esperada.
- Artículo 22 o exento de marcación.
- No conciliado entre sistemas.
- Datos de GeoVictoria no disponibles.

## Diferencia entre los indicadores

- **Asistencia:** presencia y tiempo registrado.
- **FTE:** capacidad laboral equivalente según horas y jornada esperada.
- **Desempeño:** cumplimiento de objetivos, competencias o KPI.
- **Productividad:** resultado producido respecto de recursos o tiempo.

El módulo actual aborda asistencia y un FTE preliminar. No debe presentar esos
datos como desempeño o productividad.

Buk puede ofrecer módulos de remuneraciones y desempeño, pero no está confirmado
que Grupo Patagual los tenga contratados ni que el token disponible pueda leer
esa información. Esto debe comprobarse antes de diseñar esas integraciones.

## Qué falta para cumplir el objetivo planteado

### Prioridad 1 — hacer confiable la asistencia

1. Validar login GeoVictoria y `AttendanceBook` con un trabajador y un día.
2. Ajustar el parser a la respuesta real de `Users`, `PlannedInterval` y
   `Punches`.
3. Separar técnicamente “proveedor sin datos” de “trabajador sin marcación”.
4. Validar vacaciones y licencias reales de Buk y su cruce por RUT/ID.

### Prioridad 2 — definir quién debía trabajar

1. Obtener jornada contractual y programación aplicable por trabajador.
2. Definir turnos, fines de semana, festivos, días libres y turnos nocturnos.
3. Identificar artículo 22, permisos y otras excepciones.
4. Acordar con RR.HH. qué estado corresponde a cada combinación de datos.

### Prioridad 3 — indicadores y gestión

1. Crear indicadores diarios por CECO: esperados, presentes, ausencias
   justificadas, sin marcación, horas netas y FTE.
2. Crear una bandeja de anomalías con casos que requieren revisión.
3. Definir cuándo una entrada sin salida o una jornada extensa es una alerta.
4. Etiquetar claramente FTE y horas extra como preliminares u oficiales.

### Prioridad 4 — historia y extensiones futuras

1. Diseñar persistencia histórica solo después de acordar retención y reglas.
2. Incorporar tendencias semanales y mensuales.
3. Evaluar costos laborales/remuneraciones solamente si el módulo Buk y su API
   están habilitados.
4. Evaluar desempeño o KPI como dominio separado; nunca inferirlo desde horas.

## Preguntas para la futura reunión con RR.HH.

1. ¿Cuál es la fuente oficial de turnos y jornada esperada?
2. ¿Cómo se identifican artículo 22, días libres, permisos y teletrabajo?
3. ¿Qué consideran ausencia, atraso, jornada incompleta y entrada sin salida?
4. ¿La colación se descuenta siempre o depende del contrato/turno?
5. ¿Qué indicador necesita RR.HH. diariamente y cuál requiere historia?
6. ¿Quién revisa y resuelve una anomalía, y debe quedar trazabilidad?
7. ¿Las horas extra del portal serán solo alerta o tendrán validez oficial?
8. ¿Grupo Patagual usa en Buk remuneraciones, desempeño o KPI y desea
   integrarlos posteriormente?

## Regla para decisiones futuras

Una nueva pantalla, cálculo o integración es prioritaria si ayuda a responder
quién debía trabajar, quién trabajó realmente o cuánta dotación efectiva hubo y
dónde. Toda ampliación hacia remuneraciones, desempeño o productividad debe
tratarse como un alcance distinto y validarse previamente con RR.HH.
