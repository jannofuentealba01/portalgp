# Pruebas automatizadas MSP

Las pruebas de regresión validan el esquema y los flujos financieros sin dejar datos persistentes:

```text
php tests/msp_financial_smoke.php
php tests/msp_regression_suite.php
php tests/msp_saldo_favor_periodo_futuro.php
php tests/msp_saldo_favor_historico_audit.php
php tests/msp_search_stage1.php
php tests/msp_search_stage2.php
php tests/security_financial_stage4_rollback_regeneration.php
```

La prueba de período futuro usa una transacción que siempre se revierte: confirma que un saldo a favor manual puede asociarse a un período aún no creado, y que los períodos cerrados o anulados siguen protegidos. La auditoría histórica verifica los 60 movimientos conservados, sus 30 pares compensados y sus contrapartidas contables. Un resultado distinto de cero indica que hay que revisar el detalle marcado como `FAIL` antes de desplegar.

## Suite completa de MSP

Para validar de una vez la sintaxis y los flujos comerciales y financieros:

```text
php tests/run_msp_full_suite.php
```

El ejecutor revisa todos los archivos PHP de `msp/` y después valida maestros,
contratos, documentos, cobranza, pagos, saldos a favor, garantías, tesorería,
contabilidad, cierres, prioridades de imputación y seguridad financiera.
La prueba de seguridad de etapa 4 confirma además que los cierres y documentos
no se borran directamente, que una regeneración conserva su versión anterior y
que cada reapertura mensual registra sus dependencias sin revertir movimientos.

La suite `msp_commercial_financial_integrity.php` también puede ejecutarse de
forma aislada. Los mensajes `WARN` identifican brechas operativas conocidas;
los mensajes `FAIL` representan una regresión o un descuadre que bloquea el
resultado.

## Arquitectura y regresión visual MSP

La separación entre estilos de pantalla, impresión, PDF y correo se valida con:

```text
php tests/msp_style_architecture.php
```

La prueba visual recorre nombres extensos, contratos con múltiples locales,
referencias y montos grandes, resultados vacíos, tablas extensas, navegación
por teclado, foco visible y desbordes horizontales:

```text
set MSP_QA_SESSION_ID=<sesion_local_habilitada>
node tests/msp_visual_regression_stage12.js
```

`MSP_QA_FAST=1` reduce el recorrido a escritorio y móvil. Si se define
`MSP_QA_ARTIFACT_DIR`, cualquier escenario fallido guarda una captura para su
revisión sin modificar datos de la base.
