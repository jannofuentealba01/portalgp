# Plan: Respaldo y Consulta de PDFs de Pago por Contrato

## Summary
Crear una bóveda privada de PDFs en el servidor WAMP para respaldar los documentos generados desde `cobranza/registrar_pago_contrato.php`. La primera versión cubrirá solo el flujo de pago por contrato: al registrar un pago exitoso, el sistema guardará siempre en servidor los PDFs generados y seguirá permitiendo la descarga al notebook del usuario.

## Key Changes
- Guardar PDFs en una carpeta privada fuera del acceso público directo, recomendada: `C:\wamp64\msp_storage\pagos_contrato_pdf`.
- Agregar configuración opcional en `config/storage.php`, con fallback seguro a una carpeta privada WAMP.
- Crear tabla índice `dbo.msp_pago_contrato_archivos` mediante `db/patch_pago_contrato_archivos.sql`.
- Registrar por archivo: tipo PDF, período, arrendatario, locales, documento, pago, nombre original, ruta relativa, hash SHA-256, tamaño, usuario, fecha y estado.
- Mantener nombres legibles:
  - `Vale_Pago_enero-2026_Arrendatario_(Locales)_P8.pdf`
  - `Comprobante_Gastos_enero-2026_Arrendatario_(Locales)_P8.pdf`

## Implementation Changes
- Extraer generación/guardado a un helper o service reutilizable para:
  - Generar vale de pago PDF.
  - Generar comprobante de gastos PDF cuando el documento quede saldado.
  - Escribir archivo en carpeta privada.
  - Insertar/actualizar metadata en SQL Server.
  - Evitar duplicados usando `id_pago + tipo_archivo` y/o hash.
- Ajustar `pagos/guardar_pago_contrato.php`:
  - Después del commit exitoso, crear respaldo servidor siempre.
  - La descarga al usuario seguirá usando el flujo actual, pero podrá reutilizar el archivo guardado si existe.
- Crear una vista nueva, por ejemplo `pagos/archivos_pago_contrato.php`:
  - Filtros por período, arrendatario, local, documento, tipo PDF, fecha de pago y texto libre.
  - Tabla con acciones `Ver`, `Descargar`, `Regenerar` y estado del archivo.
- Crear endpoint seguro de descarga/visualización:
  - No exponer rutas físicas.
  - Validar sesión/permisos.
  - Buscar archivo por ID en tabla.
  - Servir con `inline` o `attachment` según acción.

## Test Plan
- Lint PHP de archivos nuevos/modificados con `php -l`.
- Ejecutar patch DB en ambiente dev y validar índices/constraints.
- Registrar pago por contrato parcial: debe guardar vale en servidor.
- Registrar pago saldado: debe guardar vale y comprobante de gastos.
- Confirmar que la descarga al notebook sigue funcionando.
- Confirmar que la vista filtra por período, arrendatario, local y tipo.
- Confirmar que una URL directa al archivo físico no funciona.
- Confirmar que descarga por endpoint funciona solo con sesión/permisos.
- Simular archivo faltante en disco: la vista debe mostrar estado faltante y permitir regenerar.

## Assumptions
- Alcance v1: solo PDFs del flujo `Pago por contrato`.
- El respaldo servidor se crea siempre al registrar un pago exitoso, aunque el usuario no descargue.
- La carpeta física será privada y no navegable por URL directa.
- La base de datos guarda metadata e índice de búsqueda; los bytes del PDF viven en disco.
