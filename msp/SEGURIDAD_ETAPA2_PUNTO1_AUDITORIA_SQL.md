# Auditoría de seguridad SQL — Etapa 2, punto 1

Fecha: 2026-09-07
Base contrastada: `PORTALGP`
Modalidad: análisis estático integral del código propio y comprobaciones de solo lectura en la base operativa.

## Resultado ejecutivo

No se confirmó ninguna ruta de inyección SQL explotable desde parámetros HTTP, formularios, cookies, archivos importados ni datos normales de negocio.

La aplicación usa mayoritariamente sentencias preparadas: se revisaron 391 archivos PHP propios, 108 archivos SQL y 1.438 invocaciones a la base de datos. La distribución fue de 1.232 llamadas a `prepare()`, 200 a `query()` y 6 a `exec()`.

Quedaron cuatro oportunidades de endurecimiento para el punto 2. Ninguna permite actualmente que `admin_2` u otro usuario introduzca SQL, porque todos sus llamadores presentes suministran constantes o valores de listas permitidas. Una quinta observación afecta únicamente a un script destructivo de mantenimiento ejecutado por un administrador de base de datos.

## Alcance y método

Se excluyeron dependencias de terceros, `vendor`, `node_modules`, pruebas y archivos temporales. En el código propio se revisaron:

1. Todas las llamadas PDO `prepare`, `query` y `exec`.
2. Las 149 llamadas que entregan una variable SQL a PDO, repartidas en 55 archivos.
3. Interpolaciones, concatenaciones, filtros, búsquedas, paginación, listas `IN`, `ORDER BY`, nombres de tabla/columna y fragmentos condicionales.
4. El recorrido desde `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE` y `filter_input` hasta cada consulta candidata.
5. Las 35 llamadas PHP a procedimientos almacenados MSP.
6. Todas las apariciones de ejecución SQL dinámica en los 108 scripts SQL.
7. Los módulos realmente instalados en `PORTALGP`, consultando `sys.sql_modules` sin modificar datos ni estructura.

## Comprobación de la base operativa

La consulta de solo lectura a `PORTALGP` encontró:

| Tipo | Cantidad |
|---|---:|
| Procedimientos | 57 |
| Triggers | 32 |
| Vistas | 19 |
| Total de módulos SQL | 108 |
| Módulos con `sp_executesql`, `EXEC(@variable)` o `EXEC @variable` | 0 |

Conclusión: los procedimientos y triggers actualmente operativos no construyen ni ejecutan comandos SQL recibidos como texto. Sus parámetros se utilizan como datos dentro de sentencias estáticas.

## Resultados por clase de riesgo

### 1. Valores provenientes del usuario — conforme

Los valores de búsquedas, identificadores, fechas, montos, estados y textos se enlazan mediante marcadores PDO. No se encontró un valor proveniente de una entrada HTTP concatenado como literal SQL.

Los filtros dinámicos se forman agregando fragmentos SQL constantes y sus datos se mantienen en arreglos de parámetros. Esto incluye listados de usuarios, roles, permisos, arrendatarios, tiendas, locales, garantías, pagos, documentos, reportes y módulos CT.

### 2. Listas `IN` y operaciones masivas — conforme

Las listas variables generan únicamente nombres de marcadores como `:id_0`, `:id_1`, etc. Cada elemento se convierte al tipo esperado y se enlaza individualmente. No se interpolan los valores de la lista.

Se verificaron, entre otros, los flujos de documentos de cobro, archivos PDF, pagos, importaciones, locales, usuarios/departamentos y sincronización de estados de locales.

### 3. Paginación — conforme

Los valores de `OFFSET` y `FETCH NEXT` se normalizan a enteros, se limitan a cantidades permitidas y se enlazan con `PDO::PARAM_INT` en las vistas paginadas revisadas.

### 4. Ordenamientos dinámicos — conforme en el recorrido actual, endurecimiento pendiente

Los repositorios CT reciben un fragmento `$orderSql`, pero sus servicios actuales lo construyen exclusivamente desde mapas de columnas permitidas y direcciones `ASC`/`DESC` validadas:

- `ct/contabilidad/comercial_repository.php`
- `ct/predial/terceros/terceros_repository.php`
- `ct/predial/terrenos/terrenos_repository.php`

No existe inyección a través de las pantallas actuales. Sin embargo, el repositorio confía en que cualquier llamador futuro haya realizado esa validación. Conviene mover o repetir la lista permitida dentro del propio repositorio en el punto 2.

### 5. Expresión reutilizable de orden natural — conforme en el recorrido actual, endurecimiento pendiente

`msp2LocalCodeNaturalOrderSql()` inserta la expresión de columna recibida. Todas las llamadas actuales le entregan expresiones literales escritas en el código; ninguna proviene de una solicitud o de la base de datos.

Al ser una función global reutilizable, un uso futuro incorrecto podría convertirla en un punto de inyección. El punto 2 debe validar la sintaxis admitida o reemplazar el parámetro libre por identificadores predefinidos.

### 6. Funciones genéricas con nombres de tabla o columna — conforme en el recorrido actual, endurecimiento pendiente

Se revisaron los siguientes constructores:

- `msp2ArrImportFetchContactMap()` en `msp/arrendatarios/importar.php`.
- `msp2TiendaImportFetchLookupByDesc()` en `msp/contratos/import_service_preview.php`.
- `Ficha360Service::contactos()` en `msp/services/Ficha360Service.php`.
- El actualizador interno de `msp/cobranza/guardar_configuracion_gestion.php`.

Sus nombres de tablas y columnas son hoy constantes internas y los valores editables se enlazan. No existe entrada de usuario en los identificadores. Como defensa adicional, deben incorporar listas permitidas dentro de la función y no depender exclusivamente del llamador.

### 7. Compatibilidad con esquemas históricos — conforme

Los fragmentos variables de `msp/contratos/ficha.php`, `ct/solicitudes/solicitudes_repository.php` y `ct/predial/terrenos/terrenos_repository.php` se eligen desde listas fijas de nombres candidatos o desde metadatos del catálogo. Cuando se usan nombres obtenidos del catálogo, se protegen como identificadores con corchetes y escape de `]`.

Las alternativas de columnas, joins y condiciones de `msp/contratos/ficha.php` son fragmentos constantes seleccionados según la existencia del esquema, no texto controlado por el usuario.

### 8. Procedimientos almacenados llamados desde PHP — conforme

Se localizaron 35 sitios de llamada. El nombre del procedimiento es fijo y sus argumentos se enlazan como parámetros en todos ellos. Los procedimientos involucrados son:

- generación y eliminación de cobros, documentos y snapshots de arriendo;
- importación de lecturas;
- registro y anulación de pagos y aplicación de saldo a favor;
- convenios de pago;
- reservas, liberaciones, aplicaciones, devoluciones y reversas de garantía;
- traspaso contractual;
- cierre, reapertura, conciliación y depósitos de tesorería.

No se encontró un nombre de procedimiento, parámetro o comando `EXEC` construido desde texto de usuario.

### 9. Uso directo de `query()` — conforme

Las 200 llamadas corresponden principalmente a consultas estáticas de catálogo, existencia de objetos y lecturas sin parámetros. Las llamadas que usan una variable fueron revisadas individualmente; sus variables contienen consultas constantes o fragmentos seleccionados por condiciones internas.

Los pocos casos con identificadores variables corresponden a los puntos 4, 5, 6 y 7 anteriores y no reciben identificadores desde HTTP.

### 10. Uso directo de `exec()` en PHP — conforme y aislado

Las 6 apariciones son:

- limpieza antigua de intentos de login mediante una sentencia fija;
- dos ejecutores CLI que aplican archivos de migración con ruta fija dentro del proyecto;
- aprovisionamiento CLI de la cuenta técnica y dos sentencias fijas de prueba DDL.

No existe un `exec()` web que reciba texto SQL del usuario. Los scripts administrativos rechazan ejecución HTTP.

### 11. SQL dinámico en archivos `.sql` — conforme salvo una observación de mantenimiento

Se hallaron 20 ejecuciones dinámicas en scripts SQL. No están presentes en los módulos operativos de `PORTALGP`; pertenecen a instalación, migración o limpieza.

- Los scripts de eliminación construyen nombres desde `sys.tables`, `sys.views`, `sys.procedures`, `sys.foreign_keys` y catálogos equivalentes usando `QUOTENAME`.
- Las migraciones de claves y restricciones usan nombres tomados del catálogo y `QUOTENAME`.
- `patch_prioridad_imputacion_pagos.sql` recompila una definición obtenida mediante `OBJECT_DEFINITION`; no incorpora entrada de aplicación.
- Los `EXEC('CREATE ...')` restantes ejecutan texto fijo para compatibilidad de instalación.

Observación de severidad baja: `ct/db/00_ct_terrenos_limpiar_bd.sql` vuelve a encerrar el nombre ya protegido de una tabla dentro de un literal para `DBCC CHECKIDENT` sin duplicar una eventual comilla simple. La aplicación no permite crear tablas ni controlar esos nombres, por lo que no es explotable por un usuario de PortalGP. Debe endurecerse antes de entregar ese script a un operador de base de datos en un entorno donde pudieran existir objetos creados por terceros.

## Hallazgos pendientes para el punto 2

| ID | Severidad actual | Hallazgo | Explotable hoy |
|---|---|---|---|
| SQL-H01 | Baja | Los tres repositorios CT aceptan un `ORDER BY` libre y confían en la validación del servicio. | No |
| SQL-H02 | Baja | `msp2LocalCodeNaturalOrderSql()` acepta una expresión SQL libre; todos sus usos actuales son literales. | No |
| SQL-H03 | Baja | Cuatro funciones MSP genéricas aceptan nombres de tabla/columna sin una lista permitida interna. | No |
| SQL-H04 | Informativa | Los fragmentos de compatibilidad de esquema están distribuidos y dependen de listas fijas locales; conviene centralizarlos y citar identificadores de forma uniforme. | No |
| SQL-H05 | Baja, solo mantenimiento | El nombre usado por `DBCC CHECKIDENT` en la limpieza CT necesita escape adicional de comilla simple. | No desde PortalGP |

## Elementos descartados como falsos positivos

- `WHERE`, joins y columnas opcionales concatenados desde banderas booleanas de existencia de tablas o columnas.
- Fragmentos `CASE`, exclusiones y selección de etapas escogidos entre cadenas constantes.
- Marcadores dinámicos para listas `IN` cuyos valores se enlazan por separado.
- Consultas de importación que cambian entre `INSERT` y `UPDATE`, pero mantienen los valores parametrizados.
- Construcción de `MERGE` para archivos PDF con filas formadas exclusivamente por marcadores enlazados.
- Códigos de medio de cobranza elegidos por una expresión ternaria entre `CORREO`, `WHATSAPP` y `CARTA`.
- SQL de migración leído desde archivos con ruta fija del proyecto, ejecutado únicamente por CLI.

## Estado del punto 1

Auditoría terminada. No se modificó la base, no se cambió lógica funcional y no se tocó la cuenta `admin_2`, su contraseña, estado, rol ni permisos.

Las oportunidades `SQL-H01` a `SQL-H05` fueron tratadas en los puntos siguientes. El cierre integral de la etapa está documentado en `SEGURIDAD_ETAPA2_CIERRE_COMPLETO.md`.
