# Foto diaria de clientes crédito — cierre del día (diseño)

Fecha: 2026-11-12 · Aprobado en chat ("adelante") · Hermano de
`2026-11-11-debit-clients-cierre-diario-design.md` (mismo esquema de vigencia,
misma corrida de las 08:00, misma pestaña /income/clients).

## Intención

Ver día con día, por cliente de crédito, cuánto consumió (despachos y cortes),
cuánto se le facturó, cuánto pagó (cobros y notas de crédito) y cómo quedó su
saldo en ControlGas contra un saldo calculado, para detectar cuando el saldo del
sistema se mueve por algo que no se explica. Por ahora solo se observa: no se
hace nada con los clientes.

## Medido en ControlGas (2026-11-12)

- Universo: `SG12.dbo.Clientes` con `tipval = 3 AND codest <> -1` (1,054).
- Saldo sistema: `Clientes.cresdo` (positivo = lo que debe). Sube al instante con
  cada despacho (6 min en vivo: FLETES SOTELO +27,929.00 = despachos nuevos;
  MUNICIPIO DE JUÁREZ +1,745.10 = despachos nuevos). `Clientes.debsdo = -cresdo`.
- Límite: `Clientes.mtoasg` (342 con límite). `mtodis` no se usa (0).
- Bolsa global: no existe campo de crédito; solo `debglo` (8 clientes en 1). Se
  guarda la bandera igual que débito.
- Cortes: `ValesR.codval = 28`. 10-nov: despachos 494,158.98 = cortes 494,158.98.
- Cobros: `MovimientosAnl` `tipopr = 1, tipmov = 1, tipope = 4` (montos negativos
  en centavos; `tipmov = 2` son las aplicaciones y no se suman).
- Notas de crédito: igual con `tipope = 6`.
- Facturas: `DocumentosC/Documentos tip = 3` (sin `flgcon = 141`). NO mueven el
  saldo: en `MovimientosAnl` cada factura entra como +X y −X. Se guardan solo como
  información (consumido vs facturado).
- Vehículos: `ClientesVehiculos.debsdo` en crédito es un contador de vida (p. ej.
  −8.2 M en un camión), no un saldo. Se guarda la suma como control.
- El histórico NO reconstruye `cresdo`: consumo de vehículos − cobros cuadra en
  475 de 1,054; la cartera completa en 187.

## Decisiones

1. Tablas propias: `TG.dbo.credit_clients_cierre` y `credit_clients_cierre_runs`,
   mismo esquema de vigencia (fila nueva solo si cambia algo), fotos congeladas,
   bitácora y recuperación de hasta 31 días que débito.
2. **Saldo calculado encadenado, no histórico.** Sin fila anterior, el calculado
   inicial se siembra con el saldo sistema al inicio del día (cierre reconstruido
   − despachos del día + cobros + notas), así el primer día calculado = sistema.
   Después: inicial = final calculado de la fila anterior. Final calculado =
   inicial + consumos − cobros − notas, en dos versiones (por despachos y por
   cortes). Sin fila anterior y sin lectura (día de recuperación) se siembra en 0.
   No hay ajuste retroactivo: un movimiento que llega tarde aparece como
   diferencia entre sistema y calculado.
3. Cierre reconstruido: `cresdo` de la lectura − despachos posteriores al día +
   cobros y notas con fecha posterior al día. (Pendiente de confirmar con los
   datos que un cobro baja `cresdo` al registrarse; la foto lo mostrará.)
4. Vehículos al cierre: suma de `debsdo` de la lectura + despachos posteriores.
5. Disponible = límite − saldo final sistema, solo si el límite es > 0.
6. Corre en la misma tarea `sya_debit_snapshot_diario` (08:00) después de débito;
   cada uno en su propia transacción, un fallo en uno no afecta al otro. Código
   de salida 0 solo si los dos salen bien.
7. Pantalla: pestaña nueva "Estado de Cuenta Foto Crédito" en /income/clients,
   mismo patrón que la de débito (día, totales al pie, Excel, columnas).

## Ajuste retro (2026-11-15)

Revisión del 15-nov: el encadenado no veía lo que llega con fecha atrasada.
Había 6 clientes desfasados contra el sistema: 4 cobros capturados después de
la foto (IMPER, CARDBORD, CASTORES, IKRALG), un despacho quitado (GEN
INDUSTRIAL, −$19,998) y un corte tardío (SOPORTE CON SERVICIOS, +$2,424.69).

- Cada fila guarda `acum_desp/acum_cortes/acum_abonos` = lo que había hasta su
  día al momento de la foto (cada renglón a decimal(18,4) antes de sumar, para
  que sea exacto día con día).
- La corrida siguiente calcula `hist_*` (lo anterior al día, como está hoy).
  `ajuste_retro_desp = (hist_desp − acum_desp) − (hist_abonos − acum_abonos)`,
  igual con cortes, y lo suma al inicial calculado. Menos de medio centavo se
  toma como redondeo. Si la anterior no trae acumulados, el ajuste va NULL.
- Costo: el histórico de despachos agrega ~11 s a la corrida de crédito.
- Migración + carga de acumulados en las filas existentes:
  `docs/sql/credit_clients_cierre_retro.sql` (aplicada el 2026-11-15). Los dos
  cobros con fecha anterior a la siembra (CARDBORD 03-nov, IKRALG 06-nov) se
  restan a mano del acumulado para que la corrida los detecte.
- Pantalla: columnas "Ajuste retro (desp.)" (visible) y "(cortes)" (oculta).
