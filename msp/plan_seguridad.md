# Plan de Seguridad MSP (General)

## Objetivo
Fortalecer MSP para reducir riesgos criticos sin frenar la operacion diaria del mercado.

## Principios
- Priorizar riesgos que permiten acceso no autorizado o cambios de datos.
- Aplicar controles simples, consistentes y auditables.
- Evitar cambios grandes de una sola vez: avanzar por fases cortas.

## Prioridades Criticas
1. Control de acceso en toda accion sensible.
2. Permisos por operacion (ver, crear, editar, eliminar, importar).
3. Proteccion CSRF en todos los formularios POST.
4. Validacion estricta de entradas y archivos importados.
5. Manejo seguro de errores (sin exponer detalles internos).
6. Proteccion de credenciales y secretos.
7. Trazabilidad y auditoria de cambios.

## Plan por Fases

### Fase 1: Contencion inmediata (alta prioridad)
- Exigir sesion activa y permiso en endpoints de negocio (incluye JSON/API).
- Bloquear ejecuciones por URL sin autenticacion/autorizacion.
- Quitar mensajes tecnicos en pantalla y mover detalle a logs internos.

### Fase 2: Integridad de operaciones
- Implementar token CSRF para toda accion POST.
- Validar server-side todos los campos de entrada.
- Para imports Excel: validar extension real, tamano, estructura y columnas esperadas.
- Usar listas permitidas (whitelist) para valores sensibles (empresa, estado, tipo, etc.).

### Fase 3: Robustez operativa
- Mover credenciales a variables de entorno y rotar claves expuestas.
- Estandarizar respuestas de error para frontend/API.
- Agregar logs de auditoria: usuario, fecha, accion, entidad, cambios.
- Definir respaldos y procedimiento de rollback ante falla.

## Criterios de Cierre Minimos
- Ninguna accion critica ejecutable sin login + permiso.
- Ningun POST critico sin CSRF valido.
- Ningun import procesa archivos fuera del formato permitido.
- Ningun error tecnico sensible visible al usuario final.
- Existe registro de auditoria para altas, ediciones, eliminaciones e importaciones.

## Diccionario Rapido
- CSRF: ataque que intenta forzar acciones en nombre de un usuario logueado.
- Endpoint: URL que ejecuta una accion del sistema.
- API: interfaz de funciones consumidas por frontend u otros sistemas.
- Whitelist: lista de valores explicitamente permitidos.
- Hardening: proceso de endurecer seguridad tecnica.
- Auditoria: registro verificable de quien hizo que y cuando.
