# Recursos frontend locales de PortalGP

Estos archivos reemplazan las cargas directas desde jsDelivr, StackPath y unpkg. Las vistas conservan las versiones que ya utilizaban, pero el navegador ahora descarga los recursos desde el mismo origen de PortalGP. Esto reduce el riesgo de cadena de suministro, evita indisponibilidad por CDN y permite una política CSP limitada a `'self'` para scripts, estilos y fuentes.

## Versiones y origen

| Recurso | Versión | Origen de la copia | Licencia del proyecto |
|---|---:|---|---|
| Bootstrap | 4.5.2, 5.3.0 y 5.3.3 | `stackpath.bootstrapcdn.com` / `cdn.jsdelivr.net` | MIT |
| Bootstrap Icons | 1.10.5 y 1.11.3 | `cdn.jsdelivr.net` | MIT |
| Chart.js | 4.4.3 | `cdn.jsdelivr.net` | MIT |
| Driver.js | 1.3.6 | `cdn.jsdelivr.net` | MIT |
| HTMX | 1.9.12 | `unpkg.com` | BSD-2-Clause |

Las versiones están fijadas de manera explícita. Una actualización debe reemplazar los archivos, actualizar las rutas utilizadas por las vistas, volver a calcular SHA-256 y ejecutar `tests/security_stage3_http.php`.

## Integridad SHA-256

```text
bootstrap-4.5.2/css/bootstrap.min.css                   5b0fbe5b7ad705f6a937c4998ad02f73d8f0d976fe231b74aef0ec996990c93a
bootstrap-5.3.0/css/bootstrap.min.css                   7f1d37f0d90b6385354c2ac10e2bb91563c46bd7a266ed351222ebcac8496c2a
bootstrap-5.3.0/js/bootstrap.bundle.min.js              aa53d582f97eb594c2a5cc5824574707f9ba9837bce3046bfa5f3556860f4e04
bootstrap-5.3.3/css/bootstrap.min.css                   3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8
bootstrap-5.3.3/js/bootstrap.bundle.min.js              0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c
bootstrap-icons-1.10.5/font/bootstrap-icons.css         d8824f7067cdfea38afec7e9ffaf072125266824206d69ef1f112d72153a505e
bootstrap-icons-1.10.5/font/fonts/bootstrap-icons.woff  999550fafbfbc0e0474b3b5c945df287b079d2a23dc07dcc6240a7d2f18e081d
bootstrap-icons-1.10.5/font/fonts/bootstrap-icons.woff2 cfe45b981d1b91b173361a34cfce5f60893dbd1ac4af2c3ac11fc17552c5401f
bootstrap-icons-1.11.3/font/bootstrap-icons.css         4ffa6bea4304d2eda418683f56261685ed47bf00995039f27e5ad62d53938d2d
bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff  bb1de989b83970f6f4e54de1cd974c5cba55b73582da5e1b225a6d0edf029483
bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff2 476adf42b40325098fcfa8b36ab3e769186bb4f6ce6a249753e2e1a9c22bf99e
chart.js-4.4.3/chart.umd.min.js                         d46d97a1fd022c5fb29fa2f45ebcbc32202d73aeebf076ce5f7248f5498fc7d7
driver.js-1.3.6/driver.css                              4b398481a7ce8375af4d9f58f39410c73a0b70726fe513686d3d51c10ad76cb5
driver.js-1.3.6/driver.js.iife.js                       31d6a387715585cc5507a3dded09eb97969f8167fabfb272648f84c6b2608325
htmx-1.9.12/htmx.min.js                                449317ade7881e949510db614991e195c3a099c4c791c24dacec55f9f4a2a452
```
