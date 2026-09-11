# Pendientes de implementación y desarrollo de PortalGP / MSP

**Fecha de elaboración:** 3 de septiembre de 2026
**Origen:** análisis de las tres transcripciones de la reunión y contraste general con el código actual de MSP.

## Consideraciones

- Esta lista reúne trabajos que todavía requieren desarrollo, configuración, validación o puesta en producción.
- No se presentan como pendientes las funciones que ya tienen una implementación relevante, como garantías, saldos a favor, cierre de contratos, control diario, medidores, dashboard y pagos por contrato.
- Algunas funciones existentes pueden necesitar ajustes luego de las pruebas integrales con datos reales.
- La situación de la base de datos debe comprobarse por separado: que un archivo exista en el proyecto no asegura que su parche SQL esté aplicado en la base `PORTALGP`.

---

## 1. Prueba integral de principio a fin

### Objetivo

Comprobar que el ciclo completo de un arrendatario funciona correctamente, desde su creación hasta el cierre financiero del contrato.

### Explicación adicional

MSP contiene muchos módulos que ya funcionan de manera individual, pero se necesita una prueba que los conecte en el orden real del negocio. Esta validación debe incluir arrendatario, tienda, locales, contrato, garantía, medidores, período mensual, lecturas, documento de cobro, envío, pago, conciliación y término contractual.

### Para considerarlo terminado

- Debe existir al menos un caso completo documentado con datos de prueba controlados.
- Los montos deben coincidir entre documentos, pagos, garantía, caja, bancos y reportes.
- Cada error encontrado debe quedar corregido o registrado como una incidencia pendiente.

---

## 2. Depuración y migración de datos históricos

### Objetivo

Dejar la base con información histórica confiable y claramente separada de las pruebas realizadas durante el desarrollo.

### Explicación adicional

En la reunión se mencionaron meses con información real, períodos incompletos y lecturas utilizadas solamente para probar el sistema. Se debe identificar qué registros corresponden a la operación real, cuáles deben corregirse y desde qué fecha se cargará oficialmente la historia de MSP.

### Para considerarlo terminado

- Debe existir un inventario de períodos reales, incompletos, cerrados y de prueba.
- Los datos de prueba deben eliminarse o marcarse sin destruir información válida.
- Los saldos de apertura deben quedar conciliados con los antecedentes administrativos y contables.

---

## 3. Validación de parches en la base operativa

### Objetivo

Confirmar que la estructura de la base `PORTALGP` corresponde a la versión actual del código.

### Explicación adicional

El proyecto contiene numerosos parches SQL para pagos, garantías, cierres, tesorería, correcciones y saldos a favor. Si alguno no está aplicado, una pantalla puede verse correctamente pero fallar al guardar o consultar información.

### Para considerarlo terminado

- Debe ejecutarse un verificador de tablas, columnas, índices y procedimientos requeridos.
- Los parches deben aplicarse primero sobre una copia o respaldo de la base.
- La versión instalada de la base debe quedar registrada y ser reproducible.

---

## 4. Salida estable a producción

### Objetivo

Disponer de un ambiente estable para utilizar MSP diariamente sin depender exclusivamente del computador de desarrollo.

### Explicación adicional

Se debe definir dónde funcionará la versión oficial, cómo se publicarán los cambios y qué ocurrirá si el servidor o la base dejan de responder. El ambiente local puede seguir utilizándose para desarrollar, pero no debería ser la única copia operativa de la plataforma.

### Para considerarlo terminado

- Deben existir ambientes diferenciados para desarrollo y operación.
- Se requieren respaldos automáticos de base de datos y documentos.
- Debe probarse un procedimiento de recuperación y reversión de una actualización fallida.

---

## 5. Correo automático listo para uso real

### Objetivo

Completar y certificar el envío real de documentos desde MSP a los arrendatarios.

### Explicación adicional

El proyecto ya contiene generación y envío de documentos de cobro, pero falta validar su comportamiento en condiciones reales. Deben controlarse correos incorrectos, mensajes rechazados, reintentos, duplicados y documentos enviados a un destinatario equivocado.

### Para considerarlo terminado

- Los correos vigentes de todos los arrendatarios deben estar validados.
- Cada envío debe registrar destinatario, documento, fecha, resultado y error, si corresponde.
- Debe existir una forma segura de reenviar sin duplicar envíos exitosos accidentalmente.

---

## 6. Ampliación de pruebas automatizadas

### Objetivo

Detectar regresiones automáticamente cuando se modifique el código o la base de datos.

### Explicación adicional

Actualmente existen pruebas financieras específicas, pero no cubren todo MSP. Se deben incorporar casos sobre contratos, garantías, documentos, medios de pago, períodos, cierres, permisos, correcciones y envío de correos.

### Para considerarlo terminado

- Las funciones financieras principales deben contar con escenarios exitosos y de error.
- Las pruebas no deben dejar datos residuales en la base.
- Debe existir un comando único que informe claramente qué casos aprobaron o fallaron.

---

## 7. Carga masiva de facturas PDF

### Objetivo

Permitir que el área responsable suba conjuntamente las facturas destinadas a los arrendatarios.

### Explicación adicional

La nueva pantalla debería recibir aproximadamente 150 archivos, almacenarlos de forma privada y proponer automáticamente a qué arrendatario, tienda y contrato pertenece cada uno. Antes de continuar, el usuario debe poder revisar las coincidencias.

### Para considerarlo terminado

- Debe admitir carga múltiple y mostrar el estado individual de cada archivo.
- Cada factura debe quedar asociada a una tienda, contrato y período.
- Los archivos sin coincidencia o con coincidencias múltiples deben quedar pendientes de revisión.

---

## 8. Reconocimiento de PDF y OCR

### Objetivo

Extraer automáticamente información que permita identificar correctamente cada factura cargada.

### Explicación adicional

Cuando el PDF contiene texto digital, el sistema puede leerlo directamente. Si corresponde a una imagen escaneada, debe usar OCR para intentar reconocer RUT, razón social, tienda, locales, número de factura, fecha y monto.

### Para considerarlo terminado

- La extracción debe funcionar con PDF de texto y documentos escaneados.
- Cada dato reconocido debe incluir un nivel de confianza o advertencia.
- El sistema nunca debe enviar automáticamente un documento cuya asociación sea dudosa.

---

## 9. Validación previa y envío de facturas

### Objetivo

Evitar que una factura incorrecta sea enviada automáticamente al arrendatario equivocado.

### Explicación adicional

Después del reconocimiento, MSP debe comparar RUT, tienda, locales, período y monto con la información del contrato y del documento de cobro. Un usuario responsable debe aprobar el resultado antes de autorizar el envío por correo.

### Para considerarlo terminado

- Debe existir una bandeja de documentos aprobados, observados y rechazados.
- Las diferencias deben mostrarse claramente antes de aprobar.
- El historial debe conservar archivo original, asociación, aprobación, envío y responsable.

---

## 10. Facturación electrónica integrada con el SII

### Objetivo

Evaluar y eventualmente permitir que PortalGP emita directamente documentos tributarios electrónicos.

### Explicación adicional

Esta función no consiste solamente en generar un PDF. Requiere administrar folios y CAF, producir XML/DTE, firmar electrónicamente, comunicarse con el SII y manejar respuestas, rechazos, notas de crédito, notas de débito y anulaciones.

### Para considerarlo terminado

- Deben definirse alcance legal, proveedor de firma y proceso de certificación.
- La emisión debe validarse primero en el ambiente de certificación del SII.
- El documento tributario y el cobro interno deben mantener una relación única y auditable.

---

## 11. Contratos electrónicos y firma digital

### Objetivo

Generar, enviar, firmar y conservar contratos directamente desde PortalGP.

### Explicación adicional

El sistema debería completar una plantilla usando los datos del arrendatario, tienda, locales, renta y garantía. Luego debe enviarla a las partes, registrar la firma y conservar el contrato definitivo junto con las evidencias del proceso.

### Para considerarlo terminado

- Debe existir una plantilla contractual aprobada legalmente.
- La solución de firma debe identificar al firmante y registrar fecha y evidencia.
- El contrato firmado debe quedar relacionado con su registro operativo en MSP.

---

## 12. Integración contable o ERP

### Objetivo

Evitar que documentos, pagos y movimientos deban registrarse nuevamente en Excel, QuickBooks u otro sistema.

### Explicación adicional

Antes de desarrollar la integración debe definirse cuál plataforma será la fuente oficial para cada dato. PortalGP puede administrar la operación comercial, mientras el sistema contable conserva la contabilidad formal, comunicándose mediante una API o archivos controlados.

### Para considerarlo terminado

- Debe existir un mapa de datos y responsables entre ambos sistemas.
- Cada operación debe transferirse una sola vez y admitir conciliación.
- Los errores de integración deben quedar visibles y permitir reintentos seguros.

---

## 13. Observabilidad técnica y concurrencia

### Objetivo

Detectar y diagnosticar fallos internos en operaciones financieras o realizadas simultáneamente.

### Explicación adicional

El proyecto contiene planes para registrar procedimientos ejecutados, tiempos, códigos de error, bloqueos y contexto de cada operación, pero la bitácora técnica completa todavía no está materializada. Esto es especialmente importante para pagos, anulaciones, saldos y cierres.

### Para considerarlo terminado

- Debe existir una bitácora técnica central con identificador de correlación.
- Los datos sensibles no deben almacenarse íntegramente en los registros de error.
- Deben probarse pagos y anulaciones concurrentes para evitar duplicados o saldos incorrectos.

---

## 14. Seguridad CSP y recursos externos

### Objetivo

Reducir el riesgo de ejecución de código no autorizado o alteración de dependencias externas.

### Explicación adicional

MSP todavía carga recursos de CDN sin una aplicación general de integridad SRI y no tiene una política CSP completa. El proyecto ya contiene una planificación gradual para incorporar estas medidas sin romper scripts y estilos existentes.

### Para considerarlo terminado

- Todos los recursos CDN deben utilizar versiones fijas e integridad SRI.
- La política CSP debe probarse primero en modo de reporte.
- Después de corregir las incompatibilidades, CSP debe quedar activa en producción.

---

## 15. Protección y consentimiento de datos

### Objetivo

Formalizar el tratamiento de los datos personales y comerciales almacenados en PortalGP.

### Explicación adicional

La plataforma conserva identificación, contacto, contratos, pagos, deudas y documentos de arrendatarios. Debe definirse la política de tratamiento, quién puede consultar la información, cuánto tiempo se conserva y cómo se registra la aceptación del titular.

### Para considerarlo terminado

- Debe existir una política revisada legalmente y con control de versiones.
- La aceptación debe registrar persona, versión, fecha y mecanismo utilizado.
- Se deben definir reglas de acceso, rectificación, conservación y eliminación aplicables.

---

## 16. Matriz definitiva de permisos y aprobaciones

### Objetivo

Establecer qué acciones puede realizar cada tipo de usuario y cuáles requieren autorización adicional.

### Explicación adicional

Aunque MSP ya contiene permisos funcionales, debe validarse la separación real entre registrar, revisar, autorizar, revertir y cerrar. Las operaciones sensibles no deberían depender de que una misma persona actúe siempre como solicitante y autorizador.

### Para considerarlo terminado

- Debe aprobarse una matriz de roles y acciones con responsables del negocio.
- El backend debe validar los permisos, no solamente ocultar botones.
- Las aprobaciones y reversiones deben registrar usuario, fecha y motivo.

---

## 17. Sitio web institucional de Mercado San Pedro

### Objetivo

Crear una presencia pública que informe sobre el mercado y apoye comercialmente a sus locatarios.

### Explicación adicional

El sitio podría mostrar ubicación, tiendas, servicios, horarios, eventos, noticias y locales disponibles. También debería conectarse con redes sociales y permitir que el contenido se mantenga actualizado sin depender constantemente de un desarrollador.

### Para considerarlo terminado

- Deben definirse dominio, alojamiento, diseño y responsable de contenidos.
- El sitio debe funcionar correctamente en teléfonos y buscadores.
- Debe existir un procedimiento de mantenimiento, seguridad y respaldo.

---

## 18. Integración futura con procesos del Grupo Patagual

### Objetivo

Extender lo aprendido en MSP hacia una plataforma empresarial integrada para el Grupo Patagual.

### Explicación adicional

La visión futura considera relacionar contratos y cobranza con gastos, órdenes de compra, facturas, guías de despacho y otros procesos corporativos. Esta ampliación debe realizarse después de estabilizar MSP para no mezclar procesos todavía incompletos.

### Para considerarlo terminado

- Debe elaborarse un mapa general de procesos y sistemas existentes.
- Se deben priorizar integraciones según impacto y duplicación de trabajo.
- Cada nuevo módulo debe compartir catálogos, usuarios, documentos y trazabilidad de forma controlada.

---

## Orden general recomendado

1. **Estabilización:** pendientes 1 al 6.
2. **Automatización documental:** pendientes 7 al 9.
3. **Proyectos mayores:** pendientes 10 al 12.
4. **Seguridad y gobierno:** pendientes 13 al 16.
5. **Expansión comercial y corporativa:** pendientes 17 y 18.

Este orden permite consolidar primero el funcionamiento actual de MSP antes de agregar facturación tributaria, firma electrónica o integraciones corporativas de mayor alcance.
