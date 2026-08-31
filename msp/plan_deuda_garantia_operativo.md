# Plan Deuda y Garantia MSP

## Estado actual

La base ya tiene resuelto el flujo operativo de cobranza:

- `msp_documentos_cobro`
- `msp_documentos_cobro_detalle`
- `msp_pagos`
- `msp_tiendas`
- `msp_locales`
- `msp_ocupacion_locales`

Lo que faltaba era una capa especifica para:

- contrato por tienda
- garantia por local
- cargos manuales o documentales por local
- movimientos de garantia

## Decision de modelo

Se fijo este criterio:

- el contrato es por `tienda`
- la garantia es por `local`
- un contrato puede tener varias garantias
- cada garantia solo cubre deuda de su mismo local
- la deuda se asigna manualmente al local
- los servicios pendientes se registran manualmente
- multas y danos se asignan a un local

## SQL listo

Quedo preparado el script:

- [msp_deudores_garantia.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_deudores_garantia.sql)

Y el modelo visual:

- [msp_deudores_garantia.dbml](/mnt/c/wamp64/www/portalgp/msp/db/msp_deudores_garantia.dbml)

## Tablas nuevas

### 1. `msp_contratos_arriendo`

Cabecera comercial del arriendo por tienda.

### 2. `msp_garantias`

Garantia por local dentro del contrato.

Clave:

- `UNIQUE(id_contrato_arriendo, id_local)`

### 3. `msp_cargos_salida`

Cargo manual o documental por local.

Casos de uso:

- arriendo vencido
- servicio pendiente emitido
- servicio estimado manual
- multa
- danos
- otro

### 4. `msp_movimientos_garantia`

Libro de movimientos de la garantia.

Tipos base:

- constitucion
- reserva
- liberacion de reserva
- aplicacion a cargo
- devolucion
- ajustes

## Reglas de integridad

El SQL ya valida:

1. la garantia debe pertenecer a un local de la tienda del contrato
2. el cargo debe pertenecer a un local de la tienda del contrato
3. un cargo documental debe usar un documento de la misma tienda del contrato
4. una garantia solo puede cubrir cargos de su mismo local y contrato

## Lo que SI resuelve este modelo

1. registrar garantia inicial por local
2. ver saldo disponible y reservado por local
3. crear cargos por local
4. aplicar garantia a deuda de ese local
5. dejar trazabilidad completa de movimientos
6. soportar servicios estimados manuales

## Lo que NO intenta resolver aun

1. liquidacion legal final
2. salida parcial o total formalizada
3. devolucion automatica bloqueada por workflow
4. contabilidad
5. distribucion automatica de deuda por local

Eso se puede agregar despues, pero no es necesario para empezar a operar deuda y garantia.

## Confirmacion tecnica

### Lo que si puedo confirmar

El diseno y el script SQL ya estan listos para este alcance.

### Lo que no puedo confirmar

No esta verificado en la BD desplegada porque el script no se ejecuto desde aqui.

Entonces, la afirmacion correcta es:

- el modelo y el SQL estan listos
- la base real aun no esta validada en ejecucion

## Orden recomendado de implementacion

1. ejecutar `msp_deudores_garantia.sql`
2. crear un contrato de prueba
3. cargar garantias por local
4. crear cargos de prueba
5. probar movimientos de reserva y aplicacion
6. revisar las vistas resumen

## Siguiente paso recomendado

El siguiente paso correcto es construir procedimientos simples:

1. `msp_registrar_cargo_local`
2. `msp_reservar_garantia_cargo`
3. `msp_aplicar_garantia_cargo`
4. `msp_devolver_garantia_local`

