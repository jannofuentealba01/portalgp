# Auditoría integral de diseño, compactación y tablas de MSP

**Fecha:** 10-09-2026
**Alcance:** vistas HTML de `msp`, componentes compartidos, tablas, formularios, botones, navegación, espaciado, colores y comportamiento responsive.
**Tipo de revisión:** auditoría y reporte; no se modificó la interfaz ni su funcionamiento.

## 1. Resultado ejecutivo

MSP ya tiene una base visual reconocible y adecuada para una plataforma administrativa: azul institucional, fondos claros, tipografía Segoe UI, estados semánticos y controles Bootstrap. Sin embargo, todavía no funciona como un sistema de diseño único. La apariencia final depende demasiado de estilos locales y de excepciones agregadas pantalla por pantalla.

El problema principal de las tablas está confirmado en la hoja global: todas reciben `min-width: max-content` y todas sus celdas reciben `white-space: nowrap`. Esa combinación obliga al navegador a conservar cada dato en una sola línea y a ampliar la tabla más allá del espacio disponible. Cinco familias tienen excepciones posteriores para caber en escritorio, pero el resto continúa expuesto al desplazamiento horizontal.

### Magnitud observada

- 85 archivos PHP generan HTML completo dentro de MSP, incluyendo vistas operativas, correos y documentos imprimibles.
- 58 vistas contienen uno o más bloques `<style>` propios.
- 45 vistas usan separación exterior grande (`p-4` o `py-4`).
- Existen 822 apariciones de colores hexadecimales dentro de PHP de MSP. Parte corresponde a PDF/correo, pero las vistas de pantalla también mantienen una paleta paralela a los tokens globales.
- Las tablas más extensas llegan a 16 columnas; comprimirlas sin reorganizar la información no es una solución profesional.
- Se mezclan Bootstrap Icons 1.10.5 y 1.11.3.

## 2. Criterio visual recomendado

El estándar de MSP debiera ser el siguiente:

- Escritorio: toda tabla operativa común debe caber dentro del ancho visible sin scroll lateral.
- Tablet y móvil: una tabla ancha debe transformarse en filas apiladas, tarjetas o detalle expandible; no reducir el texto hasta volverlo ilegible.
- Las matrices auténticas —varios meses o tramos de antigüedad— deben navegarse por período o por grupo de columnas, no mostrar toda la matriz simultáneamente.
- Una página debe tener una sola barra superior compacta: volver, título y acciones.
- Los filtros frecuentes deben quedar en una fila compacta; los secundarios, dentro de “Más filtros”.
- Los textos largos deben poder ocupar dos líneas dentro de su columna. La elipsis se reserva para información secundaria y debe ofrecer el contenido completo mediante `title` o detalle.
- Importes alineados a la derecha, con cifras tabulares; estados centrados; fechas y códigos con ancho breve y estable.
- Las acciones de fila deben ser botones de 28–32 px o un único menú “Acciones” cuando existan más de dos.

## 3. Hallazgos prioritarios

### P1 — Causa global del desplazamiento lateral

En `styles.css` existe esta política global para MSP:

- `main { overflow-x: auto; }`
- `main table { min-width: max-content; }`
- todas las celdas usan `white-space: nowrap`

Esto convierte el scroll lateral en el comportamiento predeterminado de toda vista. Además, permitir overflow en `main` puede mover la página completa y no solamente la tabla, lo que empeora la orientación del usuario.

**Qué debe hacerse:** invertir la regla. El valor predeterminado debe ser `width: 100%`, `min-width: 0` y contenido ajustable. Solamente una clase explícita para matrices puede habilitar desplazamiento horizontal.

### P1 — Soluciones puntuales que no forman un sistema

Actualmente se fuerza el ajuste en escritorio sólo para tiendas, contratos, respaldos PDF, cargos adicionales y libro diario. Garantías, recepciones, devoluciones, cierres y trazabilidad tienen correcciones locales adicionales. El resultado es correcto en ciertos anchos, pero cada tabla resuelve por su cuenta tipografía, columnas, elipsis y responsive.

**Qué debe hacerse:** crear tres patrones compartidos:

1. `tabla-compacta`, hasta siete columnas.
2. `tabla-densa`, ocho a diez columnas, con campos relacionados agrupados.
3. `tabla-matriz`, navegación por grupo/período y columnas de identidad fijas.

### P1 — Tablas que no deben seguir comprimiéndose

Las vistas de 11 a 16 columnas contienen más información de la que cabe de forma legible. Reducir fuente y padding solamente oculta el problema.

**Qué debe hacerse:** mostrar entre seis y ocho datos principales y mover el resto a una fila expandible, panel de detalle o segunda línea contextual.

### P1 — Fragmentación de CSS

58 vistas contienen estilos propios. Esto permite que una corrección aplicada a una tabla o botón no alcance a las demás y explica diferencias visuales entre módulos.

**Qué debe hacerse:** trasladar estructura, densidad, botones, tablas, filtros, tarjetas y estados a componentes compartidos. Los estilos locales deben quedar únicamente para una visualización realmente especial.

### P1 — Exceso de espacio por capas anidadas

Los contenedores históricos usan 24–32 px de padding, las páginas agregan con frecuencia `p-4`/`py-4`, y dentro vuelven a aparecer tarjetas con padding. Aunque una regla posterior elimina parte de ese marco, la combinación cambia según la jerarquía HTML y genera páginas con aire excesivo.

**Qué debe hacerse:** una sola separación exterior de 12–16 px en escritorio y 8–12 px en móvil; 12–16 px dentro de tarjetas; separación vertical normal de 8–12 px.

## 4. Auditoría de tablas por riesgo

### 4.1 Riesgo crítico: rediseño de información, no sólo CSS

| Vista | Situación observada | Distribución recomendada |
|---|---|---|
| `contratos/import_service_preview.php` | Vista previa de 16 columnas. | Dejar fila, arrendatario, locales, modalidad, resultado y acción; abrir comparación completa en detalle expandible. |
| `arrendatarios/importar.php` | Vista previa de 12 columnas con datos largos. | Agrupar contacto, dirección y comparación en panel de detalle por fila. |
| `cobranza/gestionar.php` | Detalle de documento con hasta 14 columnas. | Cabecera financiera de 7 campos y desglose de concepto en fila secundaria/expandible. |
| `contabilidad/aging.php` | Combina resumen y detalle de 16 columnas. | Mantener resumen por tramos; abrir documentos del arrendatario en tabla secundaria de 7–8 columnas. |
| `control_diario/index.php` | Matriz de 14 columnas y contenido mensual. | Selector de mes visible, identidad fija y grupos “arriendo”, “servicios”, “garantía” y “total”; no presentar todos los grupos a la vez. |
| `cobros/operacion_mensual.php` | Ocho tablas; la de lotes llega a 11 columnas y la pantalla contiene 21 tarjetas. | Flujo por etapas, resumen superior y detalle desplegable de cada lote. |
| `garantias/reporte.php` | Once columnas ajustadas a fuente de .76 rem y encabezado de .70 rem. | Resumen operativo de 7 columnas; pactado/recibido y aplicado/devuelto agrupados en dos celdas dobles. |

### 4.2 Riesgo alto: probable scroll o saturación en datos reales

| Vista | Columnas / causa | Corrección recomendada |
|---|---|---|
| `catalogos/medidores.php` | 9 columnas, texto sin salto. | Unir código, alias y serie; local y servicio pueden ocupar dos líneas. |
| `locales/index.php` | 7 columnas, pero exige 1080 px y reserva 310 px a medidores. | Quitar ancho mínimo, permitir dos líneas en medidores y usar acción compacta. |
| `pagos/index.php` | Historial de 10 columnas. | Unir documento/período y tienda/arrendatario; saldo como subdato del monto. |
| `cobranza/registrar_pago.php` | Documento de 10 columnas. | Unir emisión/vencimiento y monto/saldo; mover pagos previos al detalle. |
| `contratos/descuentos_arriendo.php` | Dos tablas de 9 columnas. | Unir tipo/valor, vigencia y estado; usar acción única. |
| `contabilidad/submayor_garantias.php` | 9 columnas financieras. | Mostrar recibido, egresos, saldo y cuadre; aplicado/devuelto en desglose de egresos. |
| `deuda_garantia/index.php` | 8 columnas con identidades largas. | Unir tienda/arrendatario y deuda/cargos; mantener garantía y acción. |
| `garantias/ficha.php` | Historial de 8 columnas con referencias largas. | Unir operación/concepto y documento/referencia; medio/cuenta en segunda línea. |
| `cobranza/deudores_exarrendatarios.php` | 8 columnas, `nowrap`, botones de tabla no compactos. | Unir contrato/tienda/locales y deuda documental/cargos; convertir acciones a `btn-sm`. |
| `tiendas/index.php` | La tabla principal cabe por excepción, pero usa elipsis y reserva 24% a acciones. | Reducir acciones a iconos/menú y devolver espacio a nombre y arrendatario. |
| `contratos/index.php` | Cabe por excepción; en móvil vuelve a un mínimo de 900 px. | Tarjeta responsive por contrato; permitir nombre en dos líneas en escritorio. |
| `pagos/archivos_pdf.php` | Diez columnas ajustadas por excepción. | Agrupar archivo/metadatos, estado/procesamiento y acciones. |
| `garantias/index.php` | Ajuste reciente correcto en escritorio; las identidades extensas aún requieren wrap controlado. | Dos líneas para razón social/RUT y local; mantener importes en su columna. |
| `garantias/recepciones.php` | Tabla fijada en escritorio; “Documentos” contiene botones, archivo y formulario dentro de una celda. | Dejar enlaces y cantidad; subir respaldo desde acción o panel de detalle. |
| `garantias/devoluciones.php` | Tabla fijada en escritorio, con origen, referencia y motivo extensos. | Agrupar medio/origen y referencia/motivo en dos celdas de dos líneas. |
| `garantias/aplicaciones.php` | Tres tablas con reglas de ajuste diferentes. | Usar la misma estructura para deudas documentales, cargos e historial. |
| `tesoreria/control_diario.php` | Tres tablas y 13 tarjetas. | Resumen de saldos en franja KPI; movimientos en tabla; depósitos en panel separado. |
| `documentos_cobro/index.php` | Siete tablas y ocho tarjetas en una sola vista. | Separar resumen, detalle de servicios, pagos y ajustes mediante pestañas o acordeones. |

### 4.3 Riesgo medio: caben si se normalizan

Estas vistas tienen entre cinco y ocho columnas y no requieren perder información. Necesitan quitar el mínimo global, definir anchos y permitir dos líneas en campos largos:

- `arrendatarios/index.php`, `arrendatarios/ficha.php`
- `catalogos/bancos.php`, `catalogos/feriados.php`
- `cierre/index.php`, `cierre_mensual/index.php`
- `cobranza/aviso.php`, `cobranza/cargos_extra.php`, `cobranza/configuracion_gestion.php`
- `cobranza/convenio.php`, `cobranza/deudor_historico.php`, `cobranza/registrar_pago_contrato.php`, `cobranza/saldo_favor_manual.php`
- `contratos/arriendo_periodo.php`, `contratos/arriendo_reglas.php`, `contratos/ficha.php`, `contratos/liquidacion_final.php`
- `correcciones/index.php`
- `documentos_tienda/index.php`, `documentos_tienda/simular_envio.php`
- `locales/importar.php`, `locales/importar8-1.php`
- `pagos/simulacion_masiva.php`
- `tesoreria/conciliacion.php`, `tesoreria/reaperturas.php`

### 4.4 Vistas que ya muestran un patrón aprovechable

- `reportes/trazabilidad.php`: usa tabla fija en escritorio y filas tipo tarjeta en móvil. Es el mejor punto de partida para un componente responsive compartido.
- `garantias/recepciones.php` y `garantias/devoluciones.php`: ya eliminan scroll en escritorio mediante tabla fija, aunque aún deben agrupar contenido para evitar texto demasiado comprimido.
- `tiendas/index.php`, `contratos/index.php`, `pagos/archivos_pdf.php`, `cobranza/cargos_extra.php` y `contabilidad/libro.php`: tienen anchos porcentuales globales, pero dependen de elipsis y vuelven a scroll bajo 992 px.
- `garantias/index.php`: la corrección reciente evita que el texto invada columnas; debe transformarse en el mismo componente compartido y no quedar como excepción local.

### 4.5 Documentos y salidas que no deben obedecer la tabla de pantalla

Los PDF, comprobantes, vales y plantillas de correo necesitan su propia hoja de impresión/correo. No deben recibir las reglas responsive de las vistas operativas:

- `contabilidad/aging_pdf.php`
- `documentos_cobro/pdf.php`, `documentos_cobro/vale_lib.php`
- `garantias/comprobante.php`
- `cobranza/mail_templates/*`
- reportes descargables de consumo

## 5. Espacios que pueden reducirse

### Encabezado de página

Existe una barra compacta reutilizable de tres columnas, pero las vistas todavía mezclan botón volver, breadcrumb, subtítulo y título en filas separadas. Debe quedar una sola barra de 34–40 px de alto, con subtítulo únicamente cuando entregue una instrucción necesaria.

### Márgenes y contenedores

- Reemplazar `p-4` y `py-4` como valor habitual por `px-3 py-2` o equivalente.
- Evitar `d-flex align-items-center justify-content-center` en páginas de gestión: centra un bloque grande y desperdicia ancho útil.
- Evitar tarjeta dentro de contenedor blanco dentro de otra tarjeta.
- Reducir `margin-bottom: 1.25rem` de filtros y cabeceras a 8–12 px.
- Revisar el margen superior del footer de 24 px; debe formar parte del shell y no agregar un vacío distinto en cada vista.

### Filtros y formularios

- Búsqueda, estado, período y botón deben compartir una fila en escritorio.
- La ayuda extensa debe ir en texto plegable o tooltip, no ocupar permanentemente la cabecera.
- Los formularios de acciones financieras deben mostrar primero el resumen y luego los campos; no repetir identidad y saldos en varias tarjetas.
- En tablas, no insertar un formulario completo para adjuntar archivo dentro de cada fila.

### Tarjetas

Las pantallas con mayor fragmentación visual son:

- `dashboard/index.php`: 25 apariciones de tarjeta.
- `correcciones/index.php`: 22.
- `cobros/operacion_mensual.php`: 21.
- `arrendatarios/ficha.php`: 16.
- `documentos_tienda/index.php`: 14.
- `garantias/index.php`: 14.
- `tesoreria/control_diario.php`, `contratos/ficha.php` y `contratos/liquidacion_final.php`: 13 cada una.

No toda tarjeta es incorrecta, pero los datos relacionados deben formar franjas o grillas, no una sucesión de cajas independientes. Una tarjeta debe representar una unidad funcional, no cada cifra.

### Acciones de tabla

- Hasta dos acciones: iconos de 28–32 px con `title` y `aria-label`.
- Tres o más: botón único “Acciones” o menú de tres puntos.
- No reservar 20–24% de una tabla para botones.
- Confirmar texto sólo en la acción principal de la pantalla; las acciones de fila deben ser compactas.

## 6. Botones y semántica visual

### Uso actual

Se detectaron, entre otros, 104 usos de `btn-primary`, 54 de `btn-success`, 46 de `btn-secondary`, 166 de `btn-outline-secondary`, 85 de `btn-outline-primary` y 12 de `btn-dark`.

`btn-dark` se usa para acciones distintas: buscar, seleccionar pestaña, aplicar garantía, confirmar correcciones, cerrar y liquidar. El mismo color termina representando navegación, consulta y decisiones financieras.

### Estándar recomendado

- **Azul primario:** guardar, buscar, continuar o acción principal segura.
- **Verde:** ingreso recibido, pago confirmado o finalización exitosa.
- **Ámbar:** reapertura, término o acción que exige atención pero es reversible.
- **Rojo:** eliminar, anular o revertir.
- **Blanco/gris:** volver, cancelar y acciones secundarias.
- Eliminar `btn-dark` como variante funcional o reservarlo para un único significado documentado.
- `btn-info` aparece sólo en acciones aisladas; debe mapearse a una categoría estándar.

### Accesibilidad confirmada

Faltan nombres accesibles en al menos estos controles de icono:

- eliminar en `cierre_mensual/index.php`;
- cerrar alerta en `garantias/recepciones.php`;
- cerrar modal en `cierre/index.php`.

Cada botón de icono debe tener `aria-label`, `title`, foco visible y un área clickeable mínima de 32 px.

## 7. Colores, tipografía y consistencia con la base

### Lo que está bien

La paleta base es coherente y profesional:

- primario `#0b3a6e`;
- fondo `#f5f7fb`;
- superficie `#ffffff`;
- superficie suave `#eef2f7`;
- texto `#1f2937`;
- éxito `#0f766e`, advertencia `#b45309`, peligro `#b42318`.

El header ya es compacto y emplea la paleta institucional. La tipografía Segoe UI también es consistente con una aplicación empresarial de Windows.

### Lo que rompe la consistencia

- El título de gestión usa `#003399`, distinto del primario oficial.
- Existen colores hardcodeados en múltiples vistas de dashboard, cobros, control diario, documentos de cobro, pagos y cobranza.
- `warning`, `info`, `dark` y varias variantes outline no están normalizadas completamente mediante tokens.
- Las sombras y radios cambian entre componentes globales, tarjetas Bootstrap y estilos locales.
- Se cargan dos versiones de Bootstrap Icons.

**Conclusión:** no hace falta cambiar la identidad cromática; hace falta obligar a que todos los componentes utilicen los mismos tokens.

## 8. Navegación y shell de página

La mayoría de MSP usa el header compartido, pero `cobranza/convenio.php`, `comercial/index.php` y `control_diario/index.php` generan una estructura diferente. Además, varias pantallas con header no usan footer y otras sí. Esto produce alturas y cierres visuales distintos.

La solución no es agregar elementos indiscriminadamente. Debe definirse un único shell:

1. header institucional;
2. barra compacta de contexto y acciones;
3. contenido a ancho completo;
4. footer uniforme o ausencia uniforme dentro del módulo.

El título debe envolver en móvil; la barra actual fuerza `white-space: nowrap`, por lo que títulos largos pueden competir con volver y acciones.

## 9. Responsive

El diseño actual resuelve muchas tablas con scroll a partir de 992 px o 768 px. Eso evita cortes técnicos, pero no cumple el criterio de lectura completa y obliga a perder de vista la identidad de la fila.

### Regla recomendada por ancho

- **≥1200 px:** tablas de hasta diez columnas reorganizadas, sin scroll.
- **768–1199 px:** ocultar datos secundarios detrás de “Ver detalle” o apilarlos dentro de la misma celda.
- **<768 px:** convertir cada fila a bloque con etiquetas, siguiendo el patrón ya usado en trazabilidad.
- **Matrices:** selector de período/grupo en todos los anchos; scroll sólo como respaldo excepcional.

## 10. Inventario por área y prioridad

| Área | Prioridad | Trabajo visual principal |
|---|---:|---|
| Base global `styles.css` y templates | P1 | Quitar overflow predeterminado, crear componentes de tabla, shell, botones y densidad. |
| Importaciones de arrendatarios, contratos y locales | P1 | Vista previa resumida y comparación expandible. |
| Cobranza | P1 | Rediseñar “Gestionar”, pago y exarrendatarios; unificar tablas y acciones. |
| Control diario y Aging | P1 | Tratar como matrices navegables, no como tablas comunes. |
| Operación mensual | P1 | Reducir tarjetas, separar etapas y compactar lotes. |
| Garantías | P1 | Unificar los ajustes recientes y agrupar columnas financieras. |
| Documentos de cobro | P1 | Separar información mediante secciones/pestañas sin repetir tarjetas. |
| Tesorería | P2 | Compactar KPIs, movimientos, conciliación y reaperturas. |
| Contratos, locales y tiendas | P2 | Reducir ancho de acciones, permitir dos líneas y completar responsive. |
| Pagos | P2 | Agrupar identidad/documento y normalizar historial. |
| Catálogos y maestros | P2 | Aplicar tabla compacta común y filtros en una fila. |
| Dashboard | P2 | Reducir la cantidad de tarjetas y priorizar métricas. |
| Fichas e historiales | P2 | Resumen superior compacto y detalle expandible. |
| Ayuda y menús | P3 | Unificar tamaños, iconos y separación, sin alterar jerarquía funcional. |
| PDF, correo y comprobantes | P3 separado | Mantener hoja propia de impresión y evitar heredar reglas de pantalla. |

## 11. Orden profesional de corrección propuesto

### Etapa 1 — Fundación común

- Corregir la política global de overflow/nowrap.
- Definir tokens completos, densidad, botones, estados y una sola versión de iconos.
- Crear shell compacto y los tres tipos de tabla.
- Agregar soporte responsive mediante etiquetas de columna.

### Etapa 2 — Vistas de mayor riesgo

- Importaciones, Gestionar cobranza, Aging y Control diario.
- Operación mensual, Reporte de garantías y Documentos de cobro.
- Pagos, medidores, locales y submayor de garantías.

### Etapa 3 — Normalización del resto

- Aplicar componentes a catálogos, tesorería, contratos, fichas e historiales.
- Reducir tarjetas y espacios.
- Normalizar acciones y navegación.

### Etapa 4 — Verificación visual

- Probar con nombres extensos, muchos locales, referencias largas, montos grandes y tablas vacías.
- Verificar 1920, 1440, 1366, 1024, 768 y 390 px.
- Revisar zoom de navegador al 100%, 125% y 150%.
- Confirmar navegación con teclado, foco, nombres accesibles y contraste.

## 12. Criterio de aceptación final

La corrección podrá considerarse terminada cuando:

- ninguna tabla operativa común produzca scroll horizontal en escritorio;
- ninguna palabra o dato invada otra columna;
- las matrices tengan navegación por período o grupo;
- en móvil no sea necesario desplazar lateralmente para identificar un registro y su acción;
- todas las pantallas compartan header, barra de contexto, ancho y separación;
- el color de un botón indique siempre el mismo tipo de acción;
- los textos largos puedan usar dos líneas sin romper la altura ni la jerarquía;
- los botones de icono sean accesibles y tengan tamaño uniforme;
- no queden estilos visuales duplicados en cada archivo PHP.

## 13. Alcance y limitación de esta auditoría

La revisión se realizó sobre todas las plantillas HTML, clases, estilos compartidos y estructuras de tabla presentes en el código de MSP, además de las vistas y capturas ya revisadas durante el trabajo del módulo. No se autenticó una nueva sesión para recorrer cada combinación dinámica de datos; por ello, la verificación final de cada estado real —vacío, con un registro, con nombres extensos y con muchos registros— debe hacerse después de aplicar el sistema común.

La causa estructural, las vistas de mayor riesgo y las inconsistencias de componentes sí quedan identificadas en este informe.
