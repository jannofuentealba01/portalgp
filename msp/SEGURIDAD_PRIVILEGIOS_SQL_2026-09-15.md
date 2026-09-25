# PortalGP — Auditoría de privilegios de nuevos objetos SQL

Fecha: 15 de septiembre de 2026  
Base auditada: `PORTALGP` local  
Principal de ejecución: `portalgp_runtime` mediante `portalgp_runtime_role`

## Resultado

El inventario actual contiene 326 objetos SQL de aplicación:

- 248 tablas;
- 19 vistas;
- 59 procedimientos almacenados;
- 0 funciones.

La política requiere 1.058 concesiones específicas por objeto y 12 denegaciones
para tablas inmutables. Antes de repetir el parche no existían concesiones
faltantes ni inesperadas. Tampoco había permisos DML o `EXECUTE` sobre todo el
esquema `dbo`.

Desde el cierre documentado el 8 de septiembre se incorporaron nueve objetos:
siete tablas y dos procedimientos. Todos están cubiertos por permisos explícitos
del rol técnico.

El parche idempotente `msp/db/patch_seguridad_runtime_objetos.sql` fue aplicado
nuevamente con una identidad administrativa. Su resultado final informó cero
permisos amplios de esquema y 1.058 concesiones de objeto. En el entorno local
se utilizó `-C` porque SQL Server presenta un certificado de desarrollo no
confiable; esta excepción no corresponde a producción.

## Principio de mínimo privilegio comprobado

- `portalgp_runtime` no es `sysadmin` ni `db_owner`.
- No pertenece a `db_datareader` ni `db_datawriter`.
- Pertenece únicamente a `portalgp_runtime_role`.
- Su único permiso directo de base es `CONNECT`.
- El rol técnico solamente tiene `CONNECT` y `VIEW DEFINITION` a nivel de base.
- No existen concesiones amplias de `SELECT`, `INSERT`, `UPDATE`, `DELETE` o
  `EXECUTE` sobre el esquema completo.
- No existen objetos `dbo` fuera de las familias clasificadas `cr_`, `msp_`,
  `ct_` y `sp_ct_`.

## Objetos inmutables

Se comprobaron 12 denegaciones explícitas:

- `msp_schema_migrations`: sin `INSERT`, `UPDATE` ni `DELETE`;
- `msp_documentos_cobro_versiones`: sin `UPDATE` ni `DELETE`;
- `msp_cierre_mensual_eliminaciones`: sin `UPDATE` ni `DELETE`;
- `msp_cierre_mensual_transiciones`: sin `UPDATE` ni `DELETE`;
- `msp_saldo_favor_auditoria_historica`: sin `INSERT`, `UPDATE` ni `DELETE`.

## Automatización incorporada

Se agregó `tests/security_runtime_objects.php`. La prueba reconstruye el
inventario desde `sys.objects`, calcula los permisos esperados por tipo de
objeto y falla si encuentra:

- objetos nuevos sin clasificar;
- concesiones faltantes o adicionales;
- denegaciones inmutables ausentes o inesperadas;
- permisos amplios de esquema o base;
- membresía en roles fijos privilegiados;
- cambios en el estado, rol o permisos protegidos de `admin_2`.

Debe ejecutarse después de cada parche que cree una tabla, vista, procedimiento
o función:

```powershell
sqlcmd -S localhost -d PORTALGP -E -C -b -i msp\db\patch_seguridad_runtime_objetos.sql
php tests\security_runtime_objects.php
```

El primer comando requiere una identidad administrativa de SQL Server. El
segundo utiliza la conexión técnica real de PortalGP y por tanto comprueba los
permisos desde la misma perspectiva que la aplicación.

## Verificación ejecutada

- `tests/security_runtime_objects.php`: **15/15**.
- `tests/security_stage3_completion.php`: **46/46**.
- `tests/msp_regression_suite.php`: **21/21**.
- Sintaxis de `tests/security_runtime_objects.php`: sin errores.

La verificación posterior al parche volvió a confirmar 326 objetos, 1.058
concesiones, 12 denegaciones, ninguna diferencia respecto de la política y
ningún permiso amplio de esquema.

## Protección de `admin_2`

La auditoría no cambia usuarios funcionales, contraseñas, roles ni permisos de
la plataforma. `admin_2` continúa con `id=1030`, habilitado, rol Administrador,
`security_version=0` y sus permisos vigentes.
