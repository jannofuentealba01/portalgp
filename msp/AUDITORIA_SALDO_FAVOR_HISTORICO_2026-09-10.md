# Auditoría de movimientos históricos de saldo a favor

Fecha: 10 de septiembre de 2026
Base revisada: `PORTALGP` local
Alcance: movimientos cuyo pago o documento de cobro original ya no existe.

## Resultado ejecutivo

Se revisaron los 60 movimientos históricos detectados. No corresponden a 60
saldos sin explicación, sino a 30 operaciones compensadas exactamente:

- 25 aplicaciones de saldo a favor con su reversa: 50 movimientos.
- 5 excedentes de pago con su reversa: 10 movimientos.
- Efecto neto total sobre el saldo a favor: `$0`.
- Tiendas involucradas: 16.
- Período de los movimientos: 8 de marzo al 25 de mayo de 2026.

Los movimientos fueron conservados; no se eliminaron ni se reasignaron a pagos
o documentos actuales. Cada uno quedó clasificado y enlazado con su
contrapartida exacta mediante una auditoría inmutable.

## Hallazgo corregido

Las 25 aplicaciones de saldo a favor tenían una reversa en el libro financiero,
pero sus asientos contables seguían activos. Esto hacía que el saldo a favor
cuadrara, mientras la contabilidad continuaba reconociendo aplicaciones que ya
habían sido deshechas.

La corrección dejó:

- 25 asientos originales en estado anulado.
- 25 asientos de reversa contable vinculados a sus originales.
- 0 aplicaciones históricas compensadas con asiento activo.
- 0 diferencias entre el saldo disponible por tienda y su libro de movimientos.

Los 5 pares de excedente/reversa ya tenían su tratamiento contable correcto y
no requirieron asientos adicionales.

## Trazabilidad incorporada

La tabla `msp_saldo_favor_auditoria_historica` conserva para cada movimiento:

- movimiento y contrapartida;
- tienda, pago histórico y documento histórico;
- tipo, monto y efecto neto del par;
- clasificación y acción contable adoptada;
- evidencia y fecha de revisión.

El registro admite consulta, pero bloquea inserciones, modificaciones y
eliminaciones desde la cuenta operativa. Un trigger impide además modificar o
borrar la evidencia histórica.

## Distribución revisada por tienda

| Tienda | Movimientos | Volumen absoluto | Efecto neto |
|---|---:|---:|---:|
| COMERCIAL YEFERSON FOX SPA (6) | 6 | $716.438,00 | $0 |
| COMERCIAL YEFERSON FOX SPA (7) | 8 | $505.774,86 | $0 |
| Carolina Gómez | 2 | $74,86 | $0 |
| Mildrett Noesi Ulloa Sáez | 2 | $1.371.414,78 | $0 |
| Alejandra Bravo | 6 | $608.592,46 | $0 |
| Susana Opazo | 2 | $93.664,06 | $0 |
| Mauricia Rebolledo | 2 | $1.744,64 | $0 |
| Barbería Estudio | 6 | $15.323,56 | $0 |
| Mecatrónica Atlantis | 6 | $9.733,92 | $0 |
| Sara Muñoz | 2 | $1.262,64 | $0 |
| Francisco Rivera | 2 | $3.136.838,22 | $0 |
| Comercializadora Torres Salazar | 2 | $12.540,64 | $0 |
| Mundo Saturno | 2 | $542.629,90 | $0 |
| Mustafy Redcorp | 2 | $496.614,18 | $0 |
| Podalmedical | 6 | $16.684,46 | $0 |
| Distribuidora Canaima | 4 | $4.000,00 | $0 |

El volumen absoluto total de los movimientos revisados es `$7.533.331,18`;
esta cifra suma entradas y salidas y no representa dinero pendiente. Su efecto
neto es cero.

## Implementación y pruebas

- Parche: `msp/db/patch_auditoria_saldo_favor_historico.sql`.
- Prueba: `tests/msp_saldo_favor_historico_audit.php`.
- Resultado específico: 17/17 comprobaciones aprobadas.
- El parche fue reaplicado con éxito para comprobar su idempotencia.
- Suite completa: 9/9 suites, 232 archivos PHP válidos y 0 fallos.
- Instalación: 71/71 parches operativos registrados, sin advertencias.
- `admin_2` permanece activo, con rol Administrador, 26 permisos y sin cambios de contraseña.
