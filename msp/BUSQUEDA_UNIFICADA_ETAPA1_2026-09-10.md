# Búsqueda unificada MSP — Etapa 1

Fecha: 10 de septiembre de 2026
Módulos: Contratos, Garantías, Locales y Cobranza.

## Comportamiento implementado

- No distingue mayúsculas, minúsculas ni tildes.
- Separa la consulta en palabras y exige que todas aparezcan, aunque estén en
  distinto orden o distribuidas entre campos diferentes.
- Permite buscar RUT y códigos de local con o sin puntos y guiones.
- Interpreta `#número` como el identificador exacto del registro del módulo.
- Conserva los filtros de estado combinados con la búsqueda mediante `AND`.
- Consulta antes de paginar; Garantías dejó de limitar previamente a 100
  candidatos.
- Incorpora una acción consistente para limpiar filtros.

## Alcance por módulo

- **Contratos:** contrato, tienda, arrendatario, RUT, código y descripción de
  locales asociados.
- **Garantías:** garantía exacta, contrato, tienda, arrendatario, RUT, códigos y
  descripciones de locales.
- **Locales:** identificador, código, descripción, estado, tienda, arrendatario,
  contrato, medidor y tipo de servicio.
- **Cobranza:** búsqueda general de deudores por contrato, tienda,
  arrendatario, RUT y local; los selectores compartidos de pagos y cargos usan
  además la misma lógica de palabras, tildes y puntuación.

## Componentes compartidos

- `msp/search_helper.php`: construcción parametrizada de búsquedas SQL.
- `msp/assets/search.js`: normalización equivalente en el navegador.
- `msp/templates/components/searchable_select.php`.
- `msp/templates/components/searchable_multiselect.php`.

Las consultas continúan utilizando parámetros preparados. Los comodines
escritos por el usuario se escapan, por lo que `%`, `_` y `[` se interpretan
como caracteres y no amplían accidentalmente los resultados.

## Verificación

- Prueba específica: `tests/msp_search_stage1.php` — 24/24 controles aprobados.
- Suite MSP: 10/10 suites, 233 archivos PHP válidos y 0 fallos.
- Casos reales comprobados: palabras desordenadas, `optica`/`ÓPTICA`, RUT sin
  separadores, búsqueda por local/medidor y búsqueda exacta con `#`.
- `admin_2` permanece activo con rol Administrador.
