# Plan de Implementacion: SRI + CSP en MSP

## Objetivo
Reducir riesgo de supply-chain y XSS en frontend aplicando:
- `SRI` en recursos CDN (`<script>` y `<link>`).
- `CSP` por cabecera HTTP con despliegue gradual.

## Alcance Inicial
- Todas las vistas MSP que cargan Bootstrap/Bootstrap Icons desde `cdn.jsdelivr.net`.
- Archivos PHP de modulo MSP (no portal completo en primera etapa).

## Riesgo Actual (resumen)
- Recursos externos sin `integrity` ni `crossorigin`.
- Sin politica `Content-Security-Policy` centralizada.
- Uso de scripts inline en varias vistas, lo que impide una CSP estricta inmediata.

## Estrategia por Fases

### Fase 1: Inventario y congelamiento de versiones (1 dia)
1. Listar todos los `<script src="https://...">` y `<link href="https://...">`.
2. Confirmar versiones exactas de Bootstrap y Bootstrap Icons usadas hoy.
3. Congelar versiones (sin `latest`, sin rangos).

**Entrega:** listado unico de recursos externos con owner y archivo.

### Fase 2: Aplicar SRI en CDN criticos (1-2 dias)
1. Agregar `integrity="sha384-..."` (o sha512) y `crossorigin="anonymous"` a cada recurso CDN.
2. Priorizar:
   - `bootstrap.min.css`
   - `bootstrap.bundle.min.js`
   - `bootstrap-icons.css`
3. Verificar carga en navegadores principales.

**Ejemplo:**
```html
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
      integrity="..."
      crossorigin="anonymous">
```

**Entrega:** 100% de recursos CDN del alcance inicial con SRI.

### Fase 3: CSP en modo Report-Only (2-3 dias)
1. Definir cabecera inicial en `bootstrap.php` o punto central de salida:
   - `Content-Security-Policy-Report-Only`
2. Politica base sugerida:
   - `default-src 'self'`
   - `script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'`
   - `style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'`
   - `img-src 'self' data:`
   - `font-src 'self' https://cdn.jsdelivr.net data:`
   - `connect-src 'self'`
   - `frame-ancestors 'self'`
3. Registrar violaciones (logs server) y analizar 5-7 dias.

**Nota:** se mantiene `'unsafe-inline'` temporalmente para no romper vistas actuales.

**Entrega:** CSP Report-Only habilitada + reporte de violaciones.

### Fase 4: Remocion progresiva de inline scripts/styles (3-7 dias)
1. Migrar JS inline a archivos `.js` locales en `/assets`.
2. Reemplazar bloques `<script>...</script>` inline por referencias externas propias.
3. Evaluar nonce/hash CSP para casos puntuales inevitables.

**Entrega:** reduccion significativa de dependencia en `'unsafe-inline'`.

### Fase 5: CSP Enforcing (1-2 dias)
1. Cambiar de `Report-Only` a `Content-Security-Policy`.
2. Politica objetivo:
   - eliminar `'unsafe-inline'` de `script-src`.
   - minimizar o eliminar `'unsafe-inline'` en `style-src` si es viable.
3. Monitorear errores post-despliegue y rollback controlado.

**Entrega:** CSP activa en produccion.

## Criterios de Exito
- 0 recursos CDN sin SRI en alcance MSP.
- CSP activa en produccion.
- Sin regresiones funcionales en:
  - navegacion
  - modales
  - formularios POST con CSRF
  - reportes y dashboards

## Checklist Tecnico
- [ ] Inventario completo de recursos externos.
- [ ] SRI + `crossorigin` en todos los CDN.
- [ ] CSP Report-Only configurada.
- [ ] Pipeline/logs para violaciones CSP.
- [ ] Migracion de scripts inline prioritarios.
- [ ] CSP en modo enforce.
- [ ] Pruebas manuales de rutas criticas.

## Priorizacion Recomendada
1. SRI primero (bajo costo, alto impacto inmediato).
2. CSP Report-Only despues (visibilidad sin riesgo de caida).
3. Endurecimiento CSP final (quitar inline progresivamente).

## Riesgos de Implementacion
- Romper funcionalidades por bloqueo de scripts inline.
- Recursos CDN que cambien hash por error de version.
- Falta de monitoreo de reportes CSP en primeras semanas.

## Mitigaciones
- Despliegue gradual por modulo.
- Versiones fijas en CDN.
- Activar Report-Only antes de enforcing.
- Ventana de observabilidad con rollback documentado.

