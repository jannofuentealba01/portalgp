# Auditoria de los seis puntos FTE

Revision de codigo y regresiones locales del 9 de septiembre de 2026.

## Correcciones

1. Conexion Buk: alcanzar el limite de paginas ya no devuelve una nomina o
   ausencias truncadas como si estuvieran completas.
2. Motor mensual: un CECO sin FTE calculable impide publicar un total parcial
   como total general.
3. Fechas: el rango diario rechaza fechas inexistentes normalizadas por PHP.
4. Estados: dos marcas del mismo tipo no producen el estado Trabajado completo.
   Una consulta sin confirmacion de exito produce error de proveedor.
5. Agregacion mensual: excluye horas extra del fin de semana conforme a la
   regla documentada y evita sumar atrasos sobre ausencias de dia completo.
6. Vista: no presenta un ranking de horas extra cuando GeoVictoria no se cargo;
   informa la regla provisional de dotacion y dias sin CECO.

## Limites detectados que requieren definicion o desarrollo adicional

- El calendario reproduce enero-agosto; no es un calendario corporativo
  completo para todos los periodos. Enero 2 requiere confirmacion de RR.HH.
- La suma de personas por CECO puede repetir trabajadores trasladados. La regla
  oficial de dotacion y tratamiento de traslados debe acordarse antes del cierre.
- Accidentes, permisos, articulo 22 y turnos cuentan con soporte parcial;
  no estan todos conectados automaticamente a fuentes verificadas.
- Los componentes no cargados siguen formando un calculo parcial. La etiqueta
  preliminar es obligatoria; el resultado no es el FTE oficial conciliado.
- No existe cierre historico reproducible en SQL ni validacion visual terminada.
- No se repitieron consultas masivas a las APIs durante esta auditoria.

Las afirmaciones anteriores de que los seis puntos estaban totalmente
terminados deben entenderse como implementacion inicial, no cierre funcional.
