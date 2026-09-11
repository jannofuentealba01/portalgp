# Seguridad etapa 1 - revisión de hallazgos Semgrep

Fecha de revisión: 07-09-2026
Fuente analizada: `semgrep-msp.json`
Resultado original: 353 archivos, 21 hallazgos.

## Resultado

Los 21 hallazgos fueron contrastados individualmente con el código actual:

- 15 alertas de `unlink()` están controladas: eliminan archivos creados por la propia aplicación o rutas que pasan por validación de pertenencia al almacenamiento autorizado.
- 2 alertas de nombre de archivo son falsos positivos: el supuesto nombre controlado por el usuario se reduce a un año entero entre 1900 y 2100 y se combina con directorios y dominios fijos.
- 4 alertas de SQL/callable son falsos positivos duplicados: las condiciones SQL provienen de fragmentos constantes y los valores del usuario se enlazan mediante parámetros PDO.
- 0 hallazgos del archivo permanecen como vulnerabilidad confirmada abierta.

Esta clasificación se limita a los 21 resultados del análisis guardado. No sustituye la auditoría completa de SQL Injection y XSS prevista para las etapas siguientes.

## Clasificación individual

| N.º | Regla | Archivo y ubicación original | Clasificación | Evidencia en el código actual |
|---:|---|---|---|---|
| 1 | `unlink-use` | `msp/arrendatarios/plantilla.php:85` | Controlado | `$tmpFile` se obtiene con `tempnam()` y prefijo fijo; no procede de la solicitud. |
| 2 | `tainted-filename` | `msp/catalogos/feriados.php:296` | Falso positivo | El nombre local usa únicamente `$anioInt`, validado como entero entre 1900 y 2100, dentro de `config/holidays`. |
| 3 | `tainted-filename` | `msp/catalogos/feriados.php:297` | Falso positivo | `file_get_contents()` recibe la ruta fija construida con el año validado. No acepta separadores ni segmentos suministrados por el usuario. |
| 4 | `unlink-use` | `msp/cobranza/plantilla_pagos_contrato.php:203` | Controlado | El archivo eliminado fue creado en la misma ejecución mediante `tempnam()`. |
| 5 | `unlink-use` | `msp/cobros/plantilla_lecturas.php:318` | Controlado | El archivo eliminado fue creado mediante `tempnam()` con prefijo fijo. |
| 6 | `unlink-use` | `msp/cobros/reporte_consumo_electrico.php:643` | Controlado | La ruta eliminada corresponde al temporal creado por `tempnam()` para la exportación. |
| 7 | `unlink-use` | `msp/cobros/reporte_consumo_gas.php:713` | Controlado | La línea cambió en el código actual; el `unlink()` vigente elimina el temporal creado por la propia exportación. |
| 8 | `unlink-use` | `msp/cobros/support/ImportacionLecturasHelper.php:125` | Controlado | La implementación actual resuelve la ruta con `realpath()` y exige que su directorio padre sea exactamente la carpeta temporal exclusiva. |
| 9 | `unlink-use` | `msp/cobros/support/ImportacionLecturasHelper.php:144` | Controlado | La limpieza de sesión solo elimina la ruta devuelta por `omPreviewSafeExistingPath()`. |
| 10 | `unlink-use` | `msp/cobros/support/ImportacionLecturasHelper.php:149` | Controlado | Los temporales nuevos se crean dentro de `portalgp_msp_preview`; las rutas externas son rechazadas. |
| 11 | `unlink-use` | `msp/garantias/subir_archivo.php:17` | Controlado | La ruta se forma con un almacenamiento fijo, año/mes del servidor y un nombre aleatorio generado con `random_bytes()`. Solo se elimina si falla el registro en base de datos. |
| 12 | `unlink-use` | `msp/locales/plantilla.php:77` | Controlado | `$tmpFile` se crea localmente mediante `tempnam()` y no contiene datos de entrada. |
| 13 | `unlink-use` | `msp/medidores/plantilla.php:114` | Controlado | El archivo eliminado es el temporal de la exportación creado en la misma solicitud. |
| 14 | `unlink-use` | `msp/medidores/plantilla_import.php:91` | Controlado | El archivo eliminado es el temporal creado mediante `tempnam()`. |
| 15 | `unlink-use` | `msp/pagos/archivos_pdf_helper.php:1024` | Controlado | La línea cambió; la eliminación vigente convierte `ruta_relativa` mediante `msp2ArchivosPdfNormalizeRelativePath()`, que bloquea rutas absolutas, `..`, controles y ADS de NTFS. |
| 16 | `unlink-use` | `msp/pagos/exportar_respaldo.php:241` | Controlado | El archivo eliminado se crea mediante `tempnam()` para esa exportación. |
| 17 | `tainted-callable` | `msp/pagos/index.php:90-96` | Falso positivo | No existe llamada dinámica. Semgrep confunde la llamada PDO con un callable contaminado. |
| 18 | `tainted-sql-string` | `msp/pagos/index.php:90-96` | Falso positivo | `msp2PagosBuildFilters()` devuelve únicamente condiciones SQL constantes; los valores se guardan aparte y se enlazan con `bindValue()`. |
| 19 | `tainted-callable` | `msp/pagos/index.php:108-133` | Falso positivo | La consulta se prepara en un objeto PDO fijo; no se ejecuta una función elegida por el usuario. |
| 20 | `tainted-sql-string` | `msp/pagos/index.php:108-133` | Falso positivo | La cláusula dinámica contiene solo fragmentos predefinidos. Filtros, estado, desplazamiento y límite se enlazan como parámetros. |
| 21 | `unlink-use` | `msp/tiendas/plantilla.php:85` | Controlado | La eliminación afecta únicamente al temporal creado mediante `tempnam()` en esa ejecución. |

## Hallazgo adicional corregido durante la revisión

En `msp/pagos/archivos_pdf_helper.php`, `msp2ArchivosPdfRefreshMaterialized()` utilizaba `$relativePath` después de que una modificación anterior hubiera retirado su asignación. La función vuelve a extraer `ruta_relativa`, rechaza el valor vacío y lo entrega al normalizador seguro antes de acceder al disco.

## Reconciliación con informes anteriores

| Hallazgo anterior | Estado actual | Evidencia |
|---|---|---|
| Worker de envío de lotes accesible por HTTP | Corregido | `msp/cobros/worker_envio_lotes.php` rechaza toda ejecución cuando `PHP_SAPI !== 'cli'`. |
| Fallback que desactivaba la verificación TLS | Corregido en el helper señalado | `msp/cobros/support/OperacionMensualCommon.php` exige verificación del certificado tanto con cURL como con streams. |
| Errores técnicos visibles | Parcialmente corregido | Existe manejo central seguro, pero la auditoría global de todos los usos de `getMessage()` pertenece a la etapa 2. |
| Secretos operativos dentro del árbol web | Pendiente | Deben revisarse y trasladarse a variables de entorno o almacenamiento externo en la etapa 3. |
| TLS de la conexión a SQL Server | Pendiente | Es distinto del TLS de servicios HTTP y continúa configurado como opcional en desarrollo. |
| Regresión de materialización PDF | Corregido en esta etapa | Se restauró la lectura y validación de `ruta_relativa` antes de construir la ruta absoluta. |

## Estado de cierre de la etapa 1

1. Regresión PDF: corregida en código.
2. Veintiún hallazgos Semgrep: revisados individualmente.
3. Clasificación: registrada en este documento.
4. Informes anteriores: contrastados y estados vigentes documentados.
5. Línea base de seguridad y de `admin_2`: registrada en `SEGURIDAD_ETAPA1_LINEA_BASE.md`.
6. Pruebas existentes: ejecutadas; 90/90 comprobaciones funcionales correctas y seis vistas protegidas verificadas.
