# Auditoría de sistemas de búsqueda de MSP

Fecha de revisión: 09-09-2026
Alcance: búsquedas y filtros visibles o funcionalmente disponibles dentro de `msp/`.
Estado: documento de referencia para modificaciones posteriores. Esta auditoría no modifica el funcionamiento de MSP.

> Actualización 10-09-2026: la etapa 1 de unificación fue implementada en
> Contratos, Garantías, Locales y Cobranza. Estos módulos ya ignoran tildes y
> mayúsculas, separan palabras, admiten orden libre y reconocen `#número` como
> identificador exacto. Los selectores compartidos de Cobranza también utilizan
> la nueva normalización. El inventario inferior conserva el estado observado
> originalmente el 09-09-2026 como evidencia de auditoría.

## Resumen ejecutivo

MSP no utiliza una única lógica de búsqueda. Actualmente conviven cinco comportamientos:

1. Coincidencia parcial en cualquier posición, normalmente mediante SQL `LIKE '%texto%'`.
2. Selectores con búsqueda interna en las opciones ya cargadas en la página.
3. Búsquedas ordenadas por relevancia: exacta, inicio del texto, inicio de palabra y contenido.
4. Filtros exactos por identificador, código, estado, servicio, tipo o período.
5. Filtros estructurados por año, mes, fecha o rango de fechas.

No existe actualmente un buscador general que separe una consulta en palabras independientes, permita escribirlas en cualquier orden y exija que todas estén presentes.

## Reglas técnicas comunes

### Coincidencia parcial en SQL

La mayor parte de los listados utiliza un patrón equivalente a `contiene`. Por ejemplo, `ivo` puede encontrar `COMERCIAL IVON` aunque los caracteres estén en medio del nombre.

La base local `PORTALGP` utiliza la intercalación `Modern_Spanish_CI_AS`:

- `CI`: no distingue mayúsculas de minúsculas.
- `AS`: sí distingue tildes. Por tanto, `perez` puede no encontrar `Pérez`.

Los filtros SQL se aplican sobre toda la consulta antes de la paginación.

### Coincidencia parcial en el navegador

Los componentes buscables convierten el texto a minúsculas y usan una comparación equivalente a `includes`. La coincidencia puede estar al inicio, en medio o al final, pero solamente se revisan las opciones o filas que ya fueron cargadas en la página.

Después de seleccionar una opción, el formulario guarda y utiliza su identificador o valor exacto.

### Búsqueda por relevancia

La prioridad observada es:

1. Coincidencia exacta.
2. El texto completo comienza con la consulta.
3. Alguna palabra comienza con la consulta.
4. La consulta aparece en cualquier posición.

La recepción de garantías elimina tildes antes de comparar. La búsqueda principal de garantías normaliza tildes para ordenar, pero la selección inicial de candidatos sigue dependiendo de la comparación SQL de la base.

### Combinación de filtros

Cuando se completan varios filtros, normalmente se combinan con `AND`: el registro debe cumplirlos todos. Dentro de un mismo campo de búsqueda general, las columnas se combinan con `OR`: basta con que una columna contenga el texto.

## Inventario por módulo

### Maestros

| Área | Campos consultados | Funcionamiento |
|---|---|---|
| Arrendatarios | Nombre y RUT | Coincidencia parcial. El RUT también puede compararse sin guion. Tipo y estado son exactos. |
| Tiendas | Tienda, arrendatario y RUT | Coincidencia parcial. Rubro y estado son exactos. |
| Formulario de tiendas | Arrendatario, rubro y locales | Buscadores internos instantáneos con coincidencia en cualquier posición. |
| Locales | Código del local | Coincidencia parcial solamente sobre el código. Estado exacto. No busca descripción, medidores ni arrendatario. |
| Medidores | Código, alias y código del local | Coincidencia parcial. Servicio y estado exactos. La ruta permanece disponible aunque ya no esté visible en el menú MSP. |
| Bancos | Nombre y código | Coincidencia parcial. La inclusión de inactivos es un filtro exacto. |
| Feriados | Título y tipo | Coincidencia parcial. Año y condición activo/inactivo son filtros exactos. |
| Comunas | Descripción o ID | Coincidencia parcial, incluso sobre el ID convertido a texto. |
| Rubros | Nombre o ID | Coincidencia parcial. |
| Estados de arrendatarios | Descripción o ID | Coincidencia parcial. |
| Estados de tiendas | Descripción o ID | Coincidencia parcial. |
| Estados de locales | Descripción o ID | Coincidencia parcial. |

### Contratos, arriendos y cierres

| Área | Campos consultados | Funcionamiento |
|---|---|---|
| Contratos | Tienda, arrendatario, RUT y número de contrato | Coincidencia parcial. Estado exacto. No busca por local. |
| Formulario de contratos | Arrendatario, tienda y locales | Selectores internos. Los locales permiten búsqueda y selección múltiple. |
| Término y cierre de contratos | Contrato, tienda, arrendatario, RUT y local | Un campo busca parcialmente en cualquiera de las columnas. |
| Arriendo dinámico mensual | Tienda, arrendatario, local, contrato o contrato-local | Coincidencia parcial. Período exacto y filtro booleano `solo pendientes`. |
| Descuentos de arriendo | Contrato, arrendatario, tienda, código/nombre del descuento y local | Coincidencia parcial. Estado exacto. Los selectores de asociación tienen búsqueda interna. |
| Reglas de arriendo | Código o nombre del local | Filtro inmediato en el historial cargado del contrato, con coincidencia en cualquier posición. |
| Correcciones | Contrato, tienda, arrendatario y RUT | Coincidencia parcial. Servicio, período, local y registro posterior son selecciones exactas dependientes. |
| Cierre mensual | Período `AAAA-MM` | Coincidencia parcial sobre el período. Estado exacto. |

### Cobros, cobranza y pagos

| Área | Campos consultados | Funcionamiento |
|---|---|---|
| Operación individual | Período y tienda | Selectores buscables por etiqueta o identificador. Servicio exacto. |
| Operación mensual | Período, local/arrendatario, tipo de cargo y arrendatario para saldo a favor | Selectores internos parciales; la operación utiliza los IDs exactos seleccionados. |
| Cargos adicionales | Local, arrendatario, contrato y tipo de cargo | Búsqueda interna en las opciones cargadas. |
| Documentos de cobro | Arrendatario y período | El selector permite buscar parcialmente, pero el filtro final usa el ID exacto. Período exacto. |
| Registrar pago | Arrendatario, período y banco | Arrendatario y banco tienen búsqueda interna parcial. El filtro usa valores exactos. |
| Pago por contrato | Arrendatario, contrato con deuda y banco | Búsqueda parcial dentro de las opciones disponibles; selección final por ID exacto. |
| Historial de pagos | Número/ID de documento, tienda, arrendatario o RUT | Coincidencia parcial. Estado de pago exacto. |
| Deudores exarrendatarios | Contrato, arrendatario, RUT, tienda y local | Un campo de coincidencia parcial. Estado exacto. |
| Respaldo de PDFs | Arrendatario y grupo de locales | La lista permite encontrar opciones parcialmente, pero el filtro en la base compara el nombre o grupo seleccionado de forma exacta. Período, tipo y estado son exactos. |
| Saldo a favor manual | Arrendatario/tienda | Selector interno con coincidencia parcial; registro por ID exacto. |

### Garantías

| Área | Campos consultados | Funcionamiento |
|---|---|---|
| Inicio de Garantías | Arrendatario, RUT, contrato, garantía, tienda, código o descripción del local | Coincidencia parcial en la base y posterior orden por relevancia. |
| Recepción de garantías | Tienda, arrendatario, RUT, contrato y local | Exige al menos dos caracteres, elimina tildes, prioriza coincidencias y muestra hasta 12 resultados. |
| Aplicación de garantías | Arrendatario, RUT, contrato y local | Coincidencia parcial. Puede quedar limitado a un contrato exacto cuando se abre desde una ficha. Solo muestra cargos o documentos compatibles. |
| Reporte de garantías | Arrendatario, RUT, tienda, contrato y local | Coincidencia parcial. Alerta y estado de recepción exactos. La exportación conserva los filtros. |
| Deuda y garantía | Tienda/ID, arrendatario/RUT y local/descripción | Tres búsquedas parciales independientes. Estados de contrato y cargo exactos. |
| Submayor de garantías | Arrendatario, contrato y local | Coincidencia parcial. |
| Devoluciones de garantías | Garantía preseleccionada | No tiene buscador textual general; recibe un ID exacto o permite escoger una opción disponible. |

### Control, reportes, contabilidad y tesorería

| Área | Campos consultados | Funcionamiento |
|---|---|---|
| Control diario | Local, arrendatario y RUT | Filtro inmediato sobre las filas cargadas del año. Estado exacto para el mes visible; año y mes estructurados. |
| Trazabilidad | Tienda/ID, local/descripción y medidor | Coincidencia parcial. Año, servicio y estado del documento exactos. |
| Reportes de agua, luz y gas | Período | Selector buscable por nombre del mes o `AAAA-MM`; el valor final es exacto. |
| Libro/contabilidad | Período, cuenta y asiento | Período y cuenta exactos. El ID del asiento abre directamente un registro. |
| Aging de deuda | Período desde/hasta y fecha de corte | Filtros de rango exactos. No tiene búsqueda textual por arrendatario. |
| Dashboard y recaudación | Fechas o período | Rango de fechas y mes exactos. |
| Tesorería y conciliación | Fecha, rango y cuenta | Filtros exactos. No existe búsqueda textual general de movimientos. |
| Bandeja de pendientes | Título, descripción, arrendatario, RUT, tienda, contrato, local y período | El motor admite coincidencia parcial sobre el texto combinado. Módulo, prioridad, período, contrato, local y arrendatario se comparan exactamente. La interfaz expone principalmente las vistas rápidas. |

## Hallazgos para modificaciones futuras

1. No existe un estándar único entre listados, selectores y filtros en memoria.
2. El tratamiento de tildes es inconsistente; recepción de garantías es la principal excepción que las ignora.
3. Las consultas con varias palabras suelen buscar una frase continua y no palabras independientes.
4. La búsqueda principal de garantías toma como máximo 100 candidatos antes de reordenarlos por relevancia.
5. Los IDs buscados con coincidencia parcial pueden producir resultados amplios: `1` también puede encontrar `10`, `21`, etc.
6. Los filtros ejecutados en JavaScript actúan solamente sobre datos ya cargados; los filtros SQL consultan toda la base antes de paginar.
7. Locales solamente busca por código, aunque la vista contiene más información.
8. Contratos no busca por local, aunque Término y cierre sí lo hace.
9. En Respaldo de PDFs, el buscador del selector es parcial, pero la consulta final es exacta.
10. La Bandeja de pendientes admite internamente más filtros de los que actualmente muestra su interfaz.

## Criterio recomendado para una futura unificación

Antes de modificar estos buscadores conviene definir un comportamiento común:

- ignorar mayúsculas, tildes, puntos y guiones cuando corresponda;
- separar la consulta en palabras y permitir que aparezcan en cualquier orden;
- buscar cada palabra en todas las columnas relevantes;
- ordenar por coincidencia exacta, comienzo, inicio de palabra y contenido;
- mantener filtros categóricos y fechas como comparaciones exactas;
- indicar visualmente cuándo se filtran datos precargados y cuándo se consulta toda la base;
- centralizar la lógica para evitar diferencias entre módulos.

## Archivos centrales de referencia

- `msp/templates/components/searchable_select.php`
- `msp/templates/components/searchable_multiselect.php`
- `msp/garantias/index.php`
- `msp/garantias/recepciones.php`
- `msp/services/PendientesService.php`
- `msp/bootstrap.php`

## Estado de implementación al 10 de septiembre de 2026

La unificación descrita como recomendación ya fue implementada en dos etapas.
Contratos, Garantías, Locales, Cobranza, Documentos de cobro, Pagos, Cierres,
Tiendas, Arrendatarios y los principales catálogos usan el motor compartido de
`msp/search_helper.php` y `msp/assets/search.js`.

Por tanto, los hallazgos 1 a 8 de esta auditoría quedan corregidos dentro de ese
alcance: existe un estándar común, las tildes son ignoradas, las palabras se
evalúan individualmente, Garantías no corta primero a 100 candidatos, `#ID`
permite exactitud, se distingue conceptualmente entre filtros SQL y datos ya
cargados, Locales busca información relacionada y Contratos incluye locales.
Los filtros exactos de Respaldo de PDF y los filtros avanzados no visibles de la
Bandeja de pendientes mantienen su diseño porque representan selecciones de
flujo, no búsquedas generales.

Detalles y evidencia: `BUSQUEDA_UNIFICADA_ETAPA1_2026-09-10.md`,
`BUSQUEDA_UNIFICADA_ETAPA2_2026-09-10.md`, `tests/msp_search_stage1.php` y
`tests/msp_search_stage2.php`.
