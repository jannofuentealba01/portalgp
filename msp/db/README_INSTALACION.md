# Instalación reproducible de MSP

1. Crear la base `PORTALGP` y ejecutar el esquema base.
2. Definir `MSP_DB_DIR` apuntando a esta carpeta al usar `sqlcmd`.
3. Ejecutar `msp_instalar_core.sql` en una instalación nueva o `core_msp_migrate.sql` en una base existente.
4. Los parches son idempotentes y pueden ejecutarse nuevamente.
5. Desde la raíz ejecutar `php msp/db/verificar_instalacion.php`.

El verificador devuelve JSON y código 0 únicamente cuando están presentes los archivos y objetos críticos. No modifica datos operacionales.

## Ejecución

Desde la raíz del proyecto (C:\xampp\htdocs\portalgp):

`	ext
sqlcmd -S <servidor> -d PORTALGP -E -b -i msp/db/msp_instalar_core.sql
sqlcmd -S <servidor> -d PORTALGP -E -b -i msp/db/core_msp_migrate.sql
` 

Los instaladores ya no dependen de una ruta absoluta de XAMPP.
