# Seguridad — Etapa 2, puntos 2 al 4

Fecha de cierre: 2026-09-07
Base revisada: `PORTALGP` (consultas de verificación solamente)

## Resultado

Los puntos 2, 3 y 4 quedaron implementados. Se endurecieron los SQL dinámicos permitidos, se revisaron las salidas de PHP/HTML/JavaScript y se corrigieron los casos confirmados de XSS reflejado, almacenado o DOM-XSS encontrados en el alcance.

No se modificaron usuarios, contraseñas, roles ni permisos. `admin_2` continúa habilitado con `id=1030`, `rol_id=1` y 22 asignaciones de permiso.

## Punto 2 — Parametrización y listas permitidas

- Se incorporó `msp2SqlIdentifier()`: acepta únicamente identificadores SQL simples de hasta tres partes y los delimita de forma segura.
- Las funciones genéricas de contactos, catálogos y configuración ahora validan combinaciones exactas de tabla y columnas antes de construir SQL.
- Los tres repositorios CT con orden dinámico validan columna y dirección contra listas cerradas dentro del propio repositorio. La capa de datos ya no confía solamente en el filtro de la interfaz.
- `msp2LocalCodeNaturalOrderSql()` rechaza expresiones arbitrarias y conserva exclusivamente columnas simples o la única expresión compuesta conocida del sistema.
- El SQL de mantenimiento CT escapa correctamente nombres incluidos en la cadena de `DBCC CHECKIDENT`.
- Los valores de negocio continúan parametrizados mediante PDO; no se interpolaron valores proporcionados por usuarios.

## Punto 3 — Auditoría de salidas

Se revisaron 403 archivos PHP del repositorio (incluidas las pruebas), 20 archivos JavaScript, 84 usos de manipulaciones DOM con `innerHTML`/equivalentes y 83 usos de `json_encode`. También se clasificaron las salidas directas encontradas según su contexto: texto HTML, atributo, URL, bloque JavaScript, JSON y fragmentos HTML internos.

Conclusiones principales:

- Los bloques JSON destinados a `<script>` usan `pgpJsonForHtml()` o las cuatro opciones `JSON_HEX_*`; los valores numéricos/booleanos no abren contexto ejecutable.
- Los constructores DOM que incorporan datos remotos escapan esos datos antes de usar `innerHTML`; los usos restantes son marcado fijo o limpieza de nodos.
- Los fragmentos HTML internos deliberados se revisaron por origen. El selector buscable ahora exige un objeto explícito de HTML confiable, evitando que una cadena común se convierta accidentalmente en marcado.
- Los enlaces de paginación que construyen consultas se escapan ahora en contexto de atributo, aunque `http_build_query()` ya codificaba sus valores.
- Los slots internos de layouts y tablas que imprimen HTML siguen limitados a vistas o callbacks internos; no reciben directamente texto de formularios o base de datos.

## Punto 4 — Vulnerabilidades XSS corregidas

1. **XSS almacenado en administración legacy:** nombres de roles y estados provenientes de base de datos se imprimían sin escape en selectores de usuario. Ahora se escapan y los identificadores se convierten a enteros.
2. **Inyección JavaScript en alerta legacy:** una lista de roles se interpolaba dentro de un `alert()`; ahora se serializa con codificación segura para HTML/JavaScript.
3. **Salida de errores sin escape:** dos páginas de garantías imprimían `$error` directamente. Ahora usan `msp2Escape()`.
4. **DOM-XSS en solicitudes CT:** texto recuperado del DOM se reinsertaba mediante `innerHTML`. Ahora se crean el icono y el texto con nodos DOM (`replaceChildren`/`createTextNode`).
5. **HTML libre en selector buscable:** una cadena `label_html` podía imprimirse sin escape. Ahora solo un `Msp2SearchableSelectTrustedHtml`, construido explícitamente a partir de valores ya escapados, puede conservar formato; cualquier cadena normal se trata como texto.
6. **Contexto URL:** se reforzaron los enlaces de paginación en arrendatarios, contratos, descuentos, deuda/garantía, locales, pagos, PDF, tiendas y trazabilidad.

## Pruebas realizadas

- `php -l`: **30/30** archivos intervenidos sin errores de sintaxis.
- `tests/security_stage2_sql_xss.php`: **19/19** comprobaciones correctas.
- `tests/msp_regression_suite.php`: **21/21** comprobaciones correctas, solo lectura.
- Verificación directa de `admin_2`: existe, está habilitado y conserva rol/permisos.

La prueba heredada `tests/security_stage2.php` no pudo ejecutarse porque intenta crear una base desechable y el usuario SQL configurado no posee `CREATE DATABASE`. Esto confirma la reducción de privilegios de la conexión; no indica un fallo de la aplicación ni dejó cambios parciales.

## Continuación de la etapa

Este informe conserva el detalle de los puntos 2 al 4. Los puntos 5 al 10 fueron completados posteriormente y están documentados en `SEGURIDAD_ETAPA2_CIERRE_COMPLETO.md`.
