# Búsqueda unificada MSP — Etapa 2

Fecha: 10 de septiembre de 2026
Módulos: Documentos de cobro, Pagos, Cierres y maestros de MSP.

## Cambios implementados

- **Pagos:** un campo general consulta pago, documento, tienda, arrendatario,
  RUT, medio y referencia. Los filtros históricos continúan aceptándose para no
  romper enlaces ni exportaciones guardadas.
- **Documentos de cobro:** el período seleccionado dispone de búsqueda inmediata
  por documento, tienda, contrato, arrendatario, RUT y local, con contador de
  resultados y mensaje vacío.
- **Término y cierre:** aplica el estándar común a contrato, tienda,
  arrendatario, RUT y locales. Se eliminaron los cortes silenciosos que mostraban
  solo los primeros 25 contratos activos o cerrados.
- **Cierre mensual:** el período `AAAA-MM` se compara de forma exacta; una fecha
  parcial o inválida no puede confundirse con otro período.
- **Tiendas:** busca también por rubro, estado y locales asociados.
- **Arrendatarios:** reúne en un campo nombre, RUT, representante, dirección,
  comuna, contactos, tienda, contrato y local.
- **Catálogos:** medidores, bancos, feriados, rubros, comunas y estados adoptaron
  la misma comparación sin tildes y por palabras.
- **Selectores internos:** tiendas/locales, arrendatarios, rubros y bancos usan
  la misma normalización en el navegador.

## Regla visible común

- Todas las palabras escritas deben aparecer, pero pueden estar en distinto
  orden y en columnas diferentes.
- No se distinguen mayúsculas, minúsculas ni tildes.
- RUT y códigos de local aceptan sus variantes con o sin puntos y guiones.
- `#número` exige el identificador exacto del registro; un número sin `#` sigue
  siendo texto parcial cuando ese dato es buscable.
- Estado, tipo, rubro, servicio, año y período continúan como filtros exactos y
  se combinan con la búsqueda mediante `AND`.
- Los listados paginados consultan primero toda la base y paginan después. Los
  catálogos pequeños sin paginación no tienen límites ocultos.

## Verificación

- `tests/msp_search_stage2.php`: 53/53 comprobaciones aprobadas contra
  `PORTALGP`.
- `tests/run_msp_full_suite.php`: 11 suites ejecutadas, 233 archivos PHP con
  sintaxis válida y 0 fallos.
- Integridad comercial y financiera: 50/50; permanecen dos advertencias
  operativas preexistentes (2 movimientos bancarios sin conciliar y 21
  movimientos de caja sin cierre), no causadas por los buscadores.
- `admin_2` permanece activo con rol Administrador.
