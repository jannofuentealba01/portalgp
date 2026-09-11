# Seguridad de acceso — Etapa 2

Aplicada y verificada el 03-09-2026 sobre la base local `PORTALGP`.

## Controles implementados

1. **Login local protegido**
   - Mensaje público único para usuario inexistente, deshabilitado o contraseña incorrecta.
   - Ventana de 15 minutos, umbral de 8 fallos y espera progresiva de 30 a 900 segundos.
   - Las claves de cuenta y origen se guardan únicamente como SHA-256; no se guarda la contraseña ni el usuario/IP en texto.
   - La protección es temporal y no deshabilita cuentas. Es compatible con una migración posterior a Microsoft Entra ID.

2. **Contraseñas nuevas validadas en el servidor**
   - Entre 12 y 128 caracteres, sin caracteres de control, valores comunes ni el usuario/correo.
   - La regla se aplica solo al crear o cambiar una contraseña; no reescribe ni obliga a cambiar contraseñas existentes.

3. **Exportaciones CSV seguras**
   - Los valores que podrían interpretarse como fórmulas (`=`, `+`, `-`, `@`, tabulador o retorno) se neutralizan antes de exportar.

4. **Diagnósticos privados**
   - Los errores técnicos se registran en el log del servidor con una referencia y la interfaz muestra un mensaje genérico.
   - `display_errors` queda desactivado, se oculta `X-Powered-By` y el verificador de instalación solo funciona por CLI.

## Protección de `admin_2`

- Usuario habilitado (`estado_id=1`), rol administrador (`rol_id=1`).
- Conserva sus 21 permisos de lectura, escritura y eliminación; su contraseña/hash no fue modificado por la migración.
- No existe un bloqueo permanente; los contadores temporales estaban vacíos al terminar las pruebas.

## Verificación ejecutada

- Etapa 2: 23/23 comprobaciones.
- Regresión de seguridad etapa 1: 42/42 comprobaciones.
- Regresión MSP: 21/21 comprobaciones.
- Sintaxis PHP: 91 archivos revisados, 0 errores.
- Esquema: 64/64 parches registrados, sin errores ni advertencias.
- Acceso HTTP al verificador: rechazado con estado 403.
