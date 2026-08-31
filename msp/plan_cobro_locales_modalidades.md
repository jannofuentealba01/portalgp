# Plan MSP: Arriendo de Locales con Modalidades, Descuentos y CLP

## 1) Contexto actual (hallazgos)

- El maestro `msp_locales` define un único `valor_arriendo_uf` estático.
- La generación de documentos usa ese valor en:
  - `dbo.msp_generar_documentos_cobro_periodo` (`db/msp_documento_pago.sql`).
  - Reconciliación en `cobros/services/DocumentosCobroService.php`.
  - Flujo legacy en `cobros/operacion_individual.php`.
- Ya existe excepción hardcodeada para `OBRA`/`MODULAR` en documentos:
  - `140000` CLP por local en `DocumentosCobroService`, `documentos_cobro/pdf.php`, `documentos_cobro/vale_lib.php`.
  - `control_diario/index.php` usa modo especial `NETO_FIJO` y `280000` para combinación OBRA+MODULAR.
- `msp_contrato_locales` tiene columna `monto_arriendo_local`, pero hoy no participa en cálculo.
- `contratos/index.php` y `templates/components/searchable_multiselect.php` usan `valor_arriendo_uf` para mostrar “Suma UF locales”.
- Importadores (`locales/importar.php`, `locales/importar8-1.php`, `locales/confirmar_importacion.php`, `locales/plantilla.php`) exigen/usan `valor_arriendo_uf`.

## 2) Requerimiento funcional

Cada contrato-local debe poder cobrarse como:

- `UF_ESTATICO`: valor UF fijo.
- `DINAMICO_MENSUAL`: valor configurable por mes (periodo).
- `CLP_FIJO`: valor en CLP (caso OBRA/MODULAR u otros).
- Con descuento **mensual por monto** (sin porcentaje).
- Carga de valor `DINAMICO_MENSUAL` manual por UI.

Además:

- Debe preservarse integridad histórica de lo ya emitido.
- Al regenerar/recomponer documentos, no debe cambiar retroactivamente el cálculo del periodo si ya fue cerrado/congelado.

## 3) Estrategia recomendada: Reglas + Snapshot mensual

### 3.1 Recomendación

Implementar modelo híbrido:

- **Reglas de arriendo por contrato-local** (fuente de verdad operacional y decisión final de diseño).
- **Snapshot mensual de cálculo de arriendo** por `periodo + contrato_local`, usado por generación de documentos.

Esto evita recalcular “con datos vivos” y cubre la necesidad que planteas de snapshot mensual.

### 3.2 Por qué esta opción

- Evita drift histórico cuando cambian precios/descuentos después del cierre.
- Reduce hardcode (OBRA/MODULAR).
- Permite trazabilidad completa de fórmula aplicada por periodo.
- Permite regeneración segura del periodo usando snapshot congelado.

## 4) Diseño de datos propuesto

## 4.1 Catálogos

- `msp_tipo_modalidad_arriendo`
  - `UF_ESTATICO`, `DINAMICO_MENSUAL`, `CLP_FIJO`.
- `msp_descuento_arriendo`
  - Descuento reusable con vigencia (`periodo_desde`/`periodo_hasta`), tipo monto (`UF_FIJO` o `CLP_FIJO`) y valor.
- `msp_descuento_arriendo_contrato_local`
  - Asignación de descuento a 1..N contrato-locales con trazabilidad de alta/baja.

## 4.2 Reglas por contrato-local (vigencia)

- `msp_contrato_local_arriendo_regla`
  - `id_regla`, `id_contrato_local`, `fecha_inicio`, `fecha_termino`
  - `modalidad`
  - `valor_base_uf` (nullable), `valor_base_clp` (nullable)
  - descuento en regla queda solo como **fallback legacy** durante transición
  - `prioridad`, `estado`, `observacion`
  - checks de consistencia modalidad/moneda.

Nota: `DINAMICO_MENSUAL` usa además tabla de valores por periodo.

## 4.3 Valores dinámicos por periodo

- `msp_contrato_local_arriendo_periodo`
  - `id_contrato_local`, `periodo_facturacion`
  - `valor_periodo_uf` y/o `valor_periodo_clp` según modalidad.
  - descuento por período queda opcional solo como fallback legacy.
  - `UQ (id_contrato_local, periodo_facturacion)`.

## 4.3.1 Regla compuesta OBRA/MODULAR (default cerrado)

- Agregar una regla de negocio en el motor:
  - Si en una misma tienda/contrato del periodo existen ambos códigos `OBRA` y `MODULAR` activos, el neto total de ambos es `280000` CLP mensual.
- Persistencia sugerida:
  - guardar en snapshot un `grupo_calculo = 'OBRA_MODULAR'` y `monto_grupo_neto_clp = 280000`.
  - prorrateo técnico por local para detalle: `140000 + 140000` (solo para itemización), manteniendo control de total grupo.
- Si solo existe uno de los dos códigos en el periodo, aplicar la regla individual configurada del contrato-local (sin forzar monto de grupo).

## 4.4 Snapshot mensual de cálculo

- `msp_arriendo_local_snapshot_periodo`
  - Clave: `periodo_facturacion + id_contrato_local`.
  - Dimensiones: `id_tienda`, `id_local`, `id_contrato_arriendo`, `id_contrato_local`.
  - Insumos: modalidad, valores base, valor UF periodo, descuento aplicado, fuente (`ENTIDAD/LEGACY/NINGUNO`).
  - Trazabilidad descuento: `id_descuento_arriendo`, `tipo_descuento_snapshot`, `valor_descuento_snapshot`, `monto_descuento_clp_snapshot`.
  - Resultados: `monto_neto_clp`, `monto_iva_clp` (opcional), `monto_total_clp`.
  - `formula_json`/`detalle_calculo` para auditoría.
  - `estado_snapshot`: borrador/congelado/aplicado.

## 4.5 Compatibilidad temporal

- Mantener `msp_locales.valor_arriendo_uf` durante transición como fallback/lectura legacy.
- No eliminar en primera etapa.

## 5) Motor de cálculo (reglas)

Orden sugerido de cálculo por local/periodo:

1. Resolver regla vigente (`msp_contrato_local_arriendo_regla`) para el periodo.
2. Determinar base:
   - `UF_ESTATICO`: `valor_base_uf * valor_uf_cierre`.
   - `DINAMICO_MENSUAL`: usar `msp_contrato_local_arriendo_periodo` del mes.
   - `CLP_FIJO`: `valor_base_clp`.
3. Resolver descuento:
   - Prioridad 1: descuento entidad (`UF_FIJO`/`CLP_FIJO`) vigente en el período para el contrato-local.
   - Prioridad 2 (fallback): descuento legacy de regla/período.
4. Aplicar descuento convertido a CLP:
   - `neto = max(0, base_clp - descuento_clp_aplicado)`.
5. Regla especial de grupo:
   - si aplica `OBRA + MODULAR`, forzar neto conjunto `280000` CLP antes de persistir snapshot.
6. Piso en cero (`max(0, neto)`).
7. Persistir snapshot y usar ese neto para `ARRIENDO`.

## 6) Impacto MSP (módulos a actualizar)

## 6.1 Base de datos

- `db/patch_*` nuevo(s) para tablas/catálogos/snapshot.
- Ajustar scripts base:
  - `db/msp_agrupacion_locales.sql`
  - `db/msp_documento_pago.sql`
  - `db/initial_msp.sql`
  - registrar patch en `db/msp_instalar_core.sql`.

## 6.2 Generación/Recomposición de documentos

- `cobros/services/DocumentosCobroService.php`:
  - Reemplazar cálculo con `loc.valor_arriendo_uf * @valor_uf` por lectura de snapshot.
  - Eliminar hardcode OBRA/MODULAR y resolver por modalidad `CLP_FIJO`.
- `db/msp_documento_pago.sql` (`msp_generar_documentos_cobro_periodo`):
  - Misma migración a snapshot (o desactivar ruta SP y centralizar en servicio, según decisión).
- `cobros/operacion_individual.php`:
  - Alinear con mismo motor/snapshot (evitar divergencia).

## 6.3 Módulo Locales

- `locales/index.php`, `locales/guardar.php`:
  - UI con modalidad.
  - Si `UF_ESTATICO`, pedir UF.
  - Si `CLP_FIJO`, pedir CLP.
  - Si `DINAMICO_MENSUAL`, no exigir UF estático.
- Importación locales:
  - `locales/importar.php`, `locales/importar8-1.php`, `locales/confirmar_importacion.php`, `locales/plantilla.php`.
  - Nuevas columnas plantilla: modalidad, valor_uf, valor_clp.

## 6.4 Módulo Contratos

- `contratos/index.php`:
  - El resumen “Suma UF locales” debe pasar a “Suma referencia” (UF/CLP mixto o deshabilitar suma única).
- `templates/components/searchable_multiselect.php`:
  - hoy asume `arriendo_uf`; adaptar para `arriendo_hint` no numérico único.
- `contratos/import_service_confirmar.php`:
  - hoy lee `valor_arriendo_uf` por local; migrar a resolución por reglas/modalidad.

## 6.5 Reportes y documentos

- `documentos_cobro/pdf.php` y `documentos_cobro/vale_lib.php`:
  - fallback debe usar snapshot, no recalcular desde `msp_locales`.
- `documentos_cobro/index.php` y `contabilidad/aging*.php`:
  - mantener patrón `descripcion_item = 'Arriendo local X'` por compatibilidad de parsing.
- `control_diario/index.php`:
  - reemplazar lógica `UF` vs `NETO_FIJO` hardcodeada por datos de modalidad/snapshot.

## 6.6 Plantillas de correo

- `cobros/mail_templates/vale_cobro_email.php`:
  - remover supuestos OBRA/MODULAR hardcode y basarse en detalle emitido/snapshot.

## 7) Plan de ejecución por fases

## Fase 0: Alineación funcional

Definiciones cerradas:

- El descuento será solo por **monto mensual**.
- `DINAMICO_MENSUAL` se cargará manualmente por UI.
- El valor de arriendo se administra por **contrato-local**.
- Para el caso `OBRA + MODULAR`, ambos en conjunto valen `280000` neto mensual.

## Fase 1: Modelo DB y migración inicial

- Crear tablas catálogo/reglas/periodo/snapshot.
- Backfill:
  - locales actuales -> regla `UF_ESTATICO` con `valor_arriendo_uf`.
  - `OBRA`/`MODULAR` -> habilitar regla de grupo de `280000` neto mensual cuando estén ambos activos.
- Script de validación post-migración.

## Fase 2: Motor único de cálculo

- Implementar SQL/servicio que calcule y congele snapshot por periodo.
- Ejecutarlo en operación mensual antes de recomposición de documentos.
- Dejar trazabilidad (`formula_json`).

## Fase 3: Integración facturación/documentos

- `DocumentosCobroService` y `msp_generar_documentos_cobro_periodo` leen snapshot.
- Ajustar `operacion_individual`.
- QA de recomposición con/ sin `reemplazar`.

## Fase 4: UI e importadores

- Actualizar Locales/Contratos/importaciones para modalidad y descuentos.
- Ajustar textos y ayudas para usuarios.
- [x] Textos legacy ajustados en Locales/Contratos (UF referencial).
- [x] Importación de contratos extendida con columnas opcionales de arriendo por contrato-local.
- [x] Confirmación de importación aplica/actualiza regla default de arriendo por contrato-local.
- [x] Nueva UI manual `contratos/arriendo_periodo.php` para cargar valores mensuales `DINAMICO_MENSUAL` (UF/CLP).
- [x] Nueva UI manual `contratos/arriendo_reglas.php` para editar modalidad/valor y asignación de descuento entidad por contrato-local.
- [x] Nueva UI `contratos/descuentos_arriendo.php` para mantener catálogo de descuentos reusable (`UF_FIJO` / `CLP_FIJO`) con vigencia.
- [ ] Evaluar importador masivo de valores mensuales por período (opcional, fuera de alcance mínimo fase 4).

## Fase 5: Reportes/salidas

- PDF/vale/control_diario/aging con lógica coherente al snapshot.
- Eliminar hardcodes OBRA/MODULAR.

## Fase 6: Cierre técnico

- Telemetría funcional (conteos y diffs por periodo).
- Definir si `valor_arriendo_uf` queda legado o se depreca en fase posterior.

## 8) Riesgos y mitigaciones

- Riesgo: doble lógica (SP + PHP) desalineada.
  - Mitigación: snapshot como fuente única y pruebas comparativas.
- Riesgo: reportes que parsean texto `Arriendo local`.
  - Mitigación: mantener formato de descripción y agregar metadata en snapshot.
- Riesgo: regeneraciones alteren históricos.
  - Mitigación: bloquear recálculo si snapshot `congelado`/documento emitido, o regenerar desde snapshot inmutable.

## 9) Matriz mínima de pruebas manuales

1. Local `UF_ESTATICO` sin descuento.
2. Local `DINAMICO_MENSUAL` con valor distinto en dos meses.
3. Local `CLP_FIJO`.
4. Descuento mensual por monto CLP.
5. Tienda con `OBRA + MODULAR` y validación de neto conjunto `280000`.
6. Caso con solo `OBRA` o solo `MODULAR` usando regla individual.
7. Tienda con mezcla de modalidades en varios locales.
8. Regeneración de documentos del mismo periodo mantiene montos.
9. PDF/vale/email muestran totales y detalle correctos.
10. Control diario coincide con documento emitido.

## 10) Decisiones cerradas (input negocio confirmado)

- Descuento: por monto mensual.
- `DINAMICO_MENSUAL`: carga manual por UI.
- Ámbito de arriendo: contrato-local.
- Default OBRA/MODULAR: ambos en conjunto `280000` neto mensual.

## 11) Pendiente técnico menor a resolver en diseño detallado

- Definir representación exacta del item detalle para OBRA/MODULAR:
  - dos ítems de `140000` cada uno (recomendado por compatibilidad de parsing actual),
  - o un ítem agrupado `OBRA/MODULAR` (requiere más cambios en reportes/parsers).
