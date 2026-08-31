# Pruebas automatizadas MSP

Las pruebas de regresión validan el esquema y los flujos financieros sin dejar datos persistentes:

```text
php tests/msp_financial_smoke.php
php tests/msp_regression_suite.php
php tests/msp_saldo_favor_periodo_futuro.php
```

La última prueba usa una transacción que siempre se revierte: confirma que un saldo a favor manual puede asociarse a un período aún no creado, y que los períodos cerrados o anulados siguen protegidos. Un resultado distinto de cero indica que hay que revisar el detalle marcado como `FAIL` antes de desplegar.
