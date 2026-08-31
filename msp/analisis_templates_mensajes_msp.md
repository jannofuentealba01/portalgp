# Analisis MSP: uso de `templates/` para centralizar mensajes

Fecha: 2026-03-25

## Resumen ejecutivo

En MSP, la carpeta `templates/` ya existe, pero hoy se usa casi solo para layout y componentes visuales compartidos.

Hallazgo principal: los mensajes del sistema no estan centralizados en `templates/`; estan distribuidos directamente en controladores PHP, respuestas JSON/AJAX, `window.alert(...)`, modales inline y templates de correo construidos a mano.

Esto hace dificil:

- mantener consistencia de tono y vocabulario;
- reutilizar textos entre flujos similares;
- traducir o ajustar mensajes globalmente;
- separar contenido de presentacion y logica.

## Estado actual de `templates/`

Archivos actuales detectados:

- `templates/header.php`
- `templates/footer.php`
- `templates/flash.php`
- `templates/components/flash_toast.php`
- `templates/components/confirm_action_modal.php`
- `templates/components/undo_toast.php`

Conclusion: `templates/` hoy sirve como contenedor de parciales UI, no como repositorio de mensajes reutilizables.

## Inventario de mensajes detectados

### 1. Flash messages de servidor

Patron principal:

- `msp2SetFlash($type, $message, ...)` en [`bootstrap.php`](/mnt/c/wamp64/www/portalgp/msp/bootstrap.php:194)
- render en [`templates/flash.php`](/mnt/c/wamp64/www/portalgp/msp/templates/flash.php:1)

Magnitud:

- 480 invocaciones de `msp2SetFlash(...)`
- 53 archivos con flashes

Archivos con mayor concentracion:

- [`cobros/operacion_mensual.php`](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:1): 64
- [`pagos/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/pagos/guardar.php:1): 40
- [`cobranza/cargos_extra.php`](/mnt/c/wamp64/www/portalgp/msp/cobranza/cargos_extra.php:1): 22
- [`medidores/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/medidores/guardar.php:1): 19
- [`contratos/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/guardar.php:1): 18
- [`tiendas/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/tiendas/guardar.php:1): 18
- [`pagos/aplicar_saldo_favor.php`](/mnt/c/wamp64/www/portalgp/msp/pagos/aplicar_saldo_favor.php:1): 15
- [`tiendas/guardar_cargo.php`](/mnt/c/wamp64/www/portalgp/msp/tiendas/guardar_cargo.php:1): 14
- [`arrendatarios/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/arrendatarios/guardar.php:1): 13
- [`contratos/actualizar.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/actualizar.php:1): 13
- [`medidores/importar.php`](/mnt/c/wamp64/www/portalgp/msp/medidores/importar.php:1): 13
- [`tiendas/editar_cargo.php`](/mnt/c/wamp64/www/portalgp/msp/tiendas/editar_cargo.php:1): 13

Donde conviene ocupar `templates/`:

- No en la vista HTML del flash, que ya existe.
- Si en el contenido del mensaje: catalogar claves como `contratos.creado_ok`, `pagos.documento_invalido`, `medidores.importacion_parcial` y resolver el texto desde un repositorio central.

### 2. Respuestas JSON/AJAX con `message`

Puntos detectados:

- [`bootstrap.php`](/mnt/c/wamp64/www/portalgp/msp/bootstrap.php:81)
- [`contratos/precheck_termino.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/precheck_termino.php:18)
- [`cobros/operacion_mensual.php`](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:311)
- [`cobranza/cargos_extra.php`](/mnt/c/wamp64/www/portalgp/msp/cobranza/cargos_extra.php:349)

Magnitud:

- 15 entradas `message` en respuestas JSON
- 4 archivos con este patron

Ejemplos concretos:

- `'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.'`
- `'Contrato inválido.'`
- `'No fue posible validar.'`
- `'No hay correos pendientes para enviar.'`

Donde conviene ocupar `templates/`:

- Centralizar los textos de API/AJAX en archivos de mensajes por dominio.
- Mantener el `payload` JSON donde esta, pero reemplazar strings hardcodeados por claves.

### 3. Mensajes en JavaScript y `window.alert(...)`

Puntos detectados:

- [`bootstrap.php`](/mnt/c/wamp64/www/portalgp/msp/bootstrap.php:14)
- [`bootstrap.php`](/mnt/c/wamp64/www/portalgp/msp/bootstrap.php:30)
- [`deuda_garantia/index.php`](/mnt/c/wamp64/www/portalgp/msp/deuda_garantia/index.php:495)
- `contratos/index.php` (modal `#modalNuevoContrato`)
- [`contratos/index.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/index.php:1347)

Hallazgo:

- Hay validaciones de cliente con textos embedidos.
- Hay `alert(...)` para permisos/sesion y para validaciones de formularios.

Donde conviene ocupar `templates/`:

- Crear un partial que exporte un diccionario JS de mensajes del modulo activo.
- O exponer mensajes mediante `data-*` en el DOM cuando el JS sea acotado.

### 4. Mensajes de modal / componentes compartidos

Puntos detectados:

- [`templates/components/confirm_action_modal.php`](/mnt/c/wamp64/www/portalgp/msp/templates/components/confirm_action_modal.php:1)
- [`templates/components/flash_toast.php`](/mnt/c/wamp64/www/portalgp/msp/templates/components/flash_toast.php:1)

Hallazgo:

- Aqui ya existe un buen punto de centralizacion visual, pero el texto default sigue hardcodeado.
- El modal incluye strings base como `Confirmar acción`, `¿Deseas continuar?`, `Motivo`, `Debes ingresar un motivo.`, `Volver`, `Confirmar`.

Donde conviene ocupar `templates/`:

- Este es el lugar correcto para dejar defaults reutilizables.
- Si el sistema crece, estos textos deberian salir desde un catalogo de mensajes UI comun.

### 5. Correos y plantillas de correo

Puntos detectados:

- [`mail_helper.php`](/mnt/c/wamp64/www/portalgp/msp/mail_helper.php:1)
- [`cobranza/mail_templates/vale_pago_email.php`](/mnt/c/wamp64/www/portalgp/msp/cobranza/mail_templates/vale_pago_email.php:1)
- [`cobros/mail_templates/vale_demo_email.php`](/mnt/c/wamp64/www/portalgp/msp/cobros/mail_templates/vale_demo_email.php:1)
- [`pagos/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/pagos/guardar.php:315)
- [`cobros/operacion_mensual.php`](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:1)

Hallazgo:

- Los correos ya tienen una forma de "template", pero viven fuera de `templates/`.
- Ambos archivos mezclan:
  - formateo,
  - contenido textual,
  - estructura HTML,
  - variante texto plano,
  - reglas de negocio menores.

Donde conviene ocupar `templates/`:

- Alta prioridad.
- Conviene mover o estandarizar estos templates bajo una convención comun, por ejemplo:
  - `templates/mail/vale_pago.php`
  - `templates/mail/vale_demo.php`
  - `templates/mail/partials/header.php`
  - `templates/mail/partials/footer.php`
- Si no se quiere mover archivos aun, al menos estandarizar un contrato comun para builders de correo.

### 6. Mensajes de excepcion y validacion de negocio

Patron detectado:

- `throw new RuntimeException('...')`
- luego `catch` que hace `msp2SetFlash('warning', $exception->getMessage())`

Ejemplos:

- [`contratos/guardar.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/guardar.php:329)
- [`contratos/cerrar.php`](/mnt/c/wamp64/www/portalgp/msp/contratos/cerrar.php:292)
- [`tiendas/guardar_cargo.php`](/mnt/c/wamp64/www/portalgp/msp/tiendas/guardar_cargo.php:258)
- [`tiendas/editar_cargo.php`](/mnt/c/wamp64/www/portalgp/msp/tiendas/editar_cargo.php:245)

Hallazgo:

- El mensaje de excepcion se esta usando como mensaje final para usuario.
- Eso acopla demasiado la logica con el texto visible.

Donde conviene ocupar `templates/`:

- No directamente en un partial visual.
- Si conviene centralizar catalogos de errores de dominio y mapearlos a mensajes de usuario.
- Recomendacion: usar claves o codigos de error, no strings libres, para luego resolverlos desde un catalogo central.

## Dónde conviene usar `@templates/` primero

Si por `@templates/` te refieres a la carpeta local `templates/`, estas son las mejores oportunidades, ordenadas por impacto:

1. Correos
- Porque ya son templates de facto y hoy estan duplicando estilo, copy y estructura.

2. Flash messages reutilizables
- Porque hay 480 usos dispersos y muchos repiten patrones como:
  - "Debes ingresar..."
  - "No fue posible..."
  - "... fue creado correctamente."
  - "... fue actualizado correctamente."
  - "... fue eliminado correctamente."

3. Mensajes compartidos de modales y toast
- Porque ya viven dentro de `templates/components/`.

4. Mensajes AJAX/JSON
- Porque son pocos archivos y faciles de estandarizar.

5. Alerts y validaciones JS
- Porque hoy estan mas dispersos y requieren una interfaz PHP -> JS.

## Propuesta de estructura

Propuesta minima y pragmatica:

- `templates/messages/ui.php`
- `templates/messages/flash.php`
- `templates/messages/ajax.php`
- `templates/messages/mail.php`
- `templates/mail/vale_pago.php`
- `templates/mail/vale_demo.php`
- `templates/mail/partials/header.php`
- `templates/mail/partials/footer.php`

Alternativa mejor escalable por dominio:

- `templates/messages/arrendatarios.php`
- `templates/messages/contratos.php`
- `templates/messages/pagos.php`
- `templates/messages/medidores.php`
- `templates/messages/cobros.php`
- `templates/messages/shared.php`

Con helper comun, por ejemplo:

```php
function mspMessage(string $key, array $vars = []): string
{
    static $catalog = null;
    if ($catalog === null) {
        $catalog = require __DIR__ . '/templates/messages/shared.php';
    }

    $text = $catalog[$key] ?? $key;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }

    return $text;
}
```

Uso esperado:

```php
msp2SetFlash('success', mspMessage('contratos.creado_ok'));
omJsonResponse(['ok' => false, 'message' => mspMessage('cobros.envio.sin_pendientes')], 422);
throw new RuntimeException(mspMessage('pagos.documento_no_existe'));
```

## Reglas recomendadas para centralizar mensajes

- Separar mensaje de usuario vs detalle tecnico.
- No usar `RuntimeException` como canal principal de copy UX.
- Reutilizar plantillas parametrizadas para CRUD repetitivo.
- Mantener un tono consistente: directo, corto, sin detalles internos de DB salvo cuando sea estrictamente necesario.
- Reservar `templates/` para contenido reutilizable; no para logs ni mensajes transitorios ad hoc.

## Registro por prioridad

### Prioridad alta

- Correos de pago/cobro.
- Flash messages de `pagos`, `contratos`, `tiendas`, `medidores`, `cobros`.

### Prioridad media

- Respuestas JSON de `cobros/operacion_mensual.php` y `contratos/precheck_termino.php`.
- Defaults de componentes compartidos (`confirm_action_modal`, `flash_toast`).

### Prioridad baja

- `window.alert(...)` de validacion de cliente.
- Mensajes muy especificos de una sola pantalla que no se reutilizan.

## Conclusión

Si, en MSP hay espacio claro para ocupar mejor `templates/`, especialmente en mensajes.

Hoy `templates/` resuelve la capa visual compartida, pero no el contenido textual del sistema. El mayor retorno esta en centralizar:

- mensajes flash;
- mensajes JSON/AJAX;
- texto de componentes comunes;
- y, sobre todo, templates de correo.

La mejor primera iteracion no es mover todo, sino atacar tres bloques:

1. `templates/messages/*.php` para catalogo de mensajes.
2. `templates/mail/*` para correos.
3. un helper comun `mspMessage()` para reemplazar strings hardcodeados progresivamente.
