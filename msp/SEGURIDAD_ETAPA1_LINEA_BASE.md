# Seguridad etapa 1 - línea base y verificación

Fecha: 07-09-2026
Base verificada: `PORTALGP`
Alcance: puntos 5 y 6 de la etapa 1.

## Línea base de la cuenta protegida

La cuenta `admin_2` fue consultada antes y después de las pruebas.

| Propiedad | Antes | Después | Resultado |
|---|---:|---:|---|
| ID | 1030 | 1030 | Sin cambio |
| Estado | 1, activo | 1, activo | Sin cambio |
| Rol | 1, Administrador | 1, Administrador | Sin cambio |
| `security_version` | 0 | 0 | Sin cambio |
| Permisos asignados | 22 | 22 | Sin cambio |
| Permisos de lectura | 22 | 22 | Sin cambio |
| Permisos de escritura | 22 | 22 | Sin cambio |
| Permisos de eliminación | 22 | 22 | Sin cambio |
| Huella SHA-256 del hash de contraseña | Registrada en memoria | Coincidente | Contraseña sin cambio |

La huella no se guarda en este documento. Se utilizó únicamente para comparar el valor anterior y posterior sin exponer el hash de contraseña.

## Línea base de la conexión de la aplicación

| Control | Resultado |
|---|---|
| Login SQL usado por la aplicación | `portalgp_runtime` |
| Miembro de `sysadmin` | No |
| Miembro de `db_owner` | No |
| Permiso para crear tablas | No |
| Bases temporales de seguridad pendientes antes de las pruebas | Ninguna |
| Bases temporales de seguridad pendientes después de las pruebas | Ninguna |

## Pruebas ejecutadas

| Prueba | Resultado | Alcance principal |
|---|---:|---|
| `tests/security_stage1.php` | 47/47 | Permisos, sesiones, CSRF, JSON seguro, perfil y protección de operaciones administrativas. |
| `tests/security_stage2.php` | 23/23 | Fuerza bruta, contraseñas nuevas, exportaciones CSV y mensajes de error públicos. |
| `tests/security_financial_stage2.php` | 20/20 | Cuenta SQL, Tesorería, doble autorización y transacción de reapertura. |
| Render de Roles | Correcto | 11/11 formularios POST con CSRF y 22 permisos completos de administrador. |
| Render de Usuarios | Correcto | 23/23 formularios POST con CSRF. |
| Render de Permisos | Correcto | 12/12 formularios POST con CSRF. |
| Render de Departamentos | Correcto | 16/16 formularios POST con CSRF. |
| Render de Perfil | Correcto | Perfil correspondiente a `admin_2` y token CSRF presente. |
| Render de menú MSP | Correcto | Vista generada sin errores PHP. |
| Sintaxis de `msp/pagos/archivos_pdf_helper.php` | Correcta | Verificación de la corrección de materialización PDF. |
| Sintaxis de `tests/security_stage1_render.php` | Correcta | Verificación de la actualización de la prueba de permisos. |

En total, las pruebas funcionales de seguridad ejecutaron 90 comprobaciones correctas y ninguna fallida en su ejecución final. Los 62 formularios POST inspeccionados en las vistas administrativas incluyeron token CSRF.

## Aislamiento y limpieza

- `security_stage1.php` y `security_stage2.php` crearon bases con nombres aleatorios y las eliminaron al finalizar.
- La prueba financiera creó sus datos dentro de una transacción sobre `PORTALGP` y ejecutó `ROLLBACK` antes de terminar.
- No quedó ninguna base `PORTALGP_SEC_STAGE*` en SQL Server.
- Ninguna prueba cambió el estado, rol, contraseña, versión de seguridad o permisos de `admin_2`.

## Ajuste requerido en las pruebas

La prueba de render tenía codificado el valor histórico de 21 permisos para el rol Administrador. Fue actualizada para comparar la interfaz con la cantidad real de permisos completos almacenados en la base, actualmente 22.

La primera ejecución aislada de `security_stage1.php` utilizó una contraseña temporal que contenía el nombre del usuario de prueba. La política la rechazó correctamente. Se cambió exclusivamente el dato generado por la prueba y la ejecución final completó 47/47 controles. La base temporal de ese intento también fue eliminada.

## Alcance de esta verificación

Esta etapa confirma los controles automatizados existentes y la estabilidad de `admin_2`. La auditoría completa de SQL Injection, XSS, errores globales, TLS SQL e invariantes financieras continúa en las etapas posteriores.
