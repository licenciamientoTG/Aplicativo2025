# Conciliación de cobros: guardado y conciliación manual

Fecha: 2026-11-17 · Módulo: Ingresos → Conciliación (`/income/conciliation/`), tab **Conciliación cobros** (`#tabCruce`).

## Por qué

El cruce automático (`ConciliacionCobros::conciliar`) no puede resolver todo. En octubre 2026 DIAZ GAS hubo 6 cobros
con el MOV mal capturado en ControlGas, lo que dejó 30 cobros en "Diferencia" o "Sin banco". También quedan grupos que
no cuadran (GCC: 10 cobros contra 3 depósitos), cobros sin MOV (Fletes Sotelo, AMEX) y depósitos sin cobro
($2,000,000; $5,500). Hoy eso se corrige a mano en un Excel aparte. El usuario quiere hacerlo en la página: abrir un cobro
y buscarle movimientos, o abrir un movimiento y buscarle cobros y facturas. También quiere poder rehacer lo que el sistema
ya concilió.

Además el resultado de la conciliación tiene que quedar **guardado**. La primera vez que se abre un mes se guarda el
cruce automático; las siguientes veces se lee lo guardado y no se recalcula ni se vuelve a guardar.

## Objetivos

1. Guardar en TG la conciliación del mes la primera vez que se consulta, y leerla las siguientes veces.
2. Conciliar a mano desde un cobro (buscar movimientos) y desde un movimiento (buscar cobros y facturas).
3. Rehacer a mano cualquier conciliación, sea automática o manual, y poder deshacerla.
4. Ligar un depósito directamente a facturas cuando no hay cobro en ControlGas.
5. **Ninguna regla bloquea guardar una conciliación manual**: el sistema solo **alerta** de todo lo que no coincide.
6. Las consultas siguientes no deben tardar más que hoy.

## No objetivos

- No se escribe nada en ControlGas (SG12). Un MOV mal capturado sigue mal allá; la corrección vive solo en la conciliación.
- No se cambian las reglas del cruce automático (pasadas 1–5 de `ConciliacionCobros`).
- La conciliación débito (`/income/conciliation-debit/`) queda fuera, aunque reusa parte del cruce.
- Los cobros de serie Y (subope 24 Administrativa / 57 Arrendamiento) siguen fuera, como hoy.

## Anti-objetivos (sería un fracaso aunque funcione)

- Que la página tarde notablemente más que hoy (~1.2 s octubre) en las consultas siguientes a la primera.
- Que "Recalcular" o un cobro nuevo borre o cambie una conciliación manual.
- Que una conciliación manual se rechace por no cuadrar: siempre se guarda y avisa.
- Mostrar datos viejos del cobro o del banco (cliente, monto, cancelación) por leerlos de lo guardado.

## Medición actual (octubre 2026, CLI)

| Paso | Tiempo |
|---|---|
| `ConciliacionVentaModel::cobros()` (SG12) | 0.3–0.4 s |
| `ConciliacionVentaModel::depositos()` (TG) | 0.8 s |
| `ConciliacionCobros::conciliar()` | 0.02 s |

El cruce es barato; lo caro son las consultas. Guardar no hace la página más lenta: la primera vez agrega un insert de
unas 800 filas, y las siguientes veces cambia el cruce por un SELECT indexado.

## Enfoques considerados

- **A. Guardar solo las ligas (ELEGIDO por el usuario).** Se guarda qué cobro va con qué movimiento o factura, con su
  estatus y su origen (automático o manual). Cliente, monto, facturas del cobro y datos del banco se siguen leyendo frescos
  de SG12 y TG en cada consulta. Así se ven cancelaciones, nombres y cobros nuevos, y el tiempo queda igual que hoy.
- **B. Foto completa.** Se guarda la fila entera y las siguientes veces solo se lee TG (~0.1 s). Se descartó porque no se
  verían cobros capturados tarde, cancelaciones ni movimientos importados después, y habría dos versiones de la verdad.

## Diseño

### Modelo de datos (TG, script en `docs/sql/conciliacion_cobros_ligas_schema.sql`)

Una **conciliación** es un grupo de N cobros, M movimientos y K facturas que se consideran conciliados entre sí.

```
TG.dbo.conciliacion_cobros_mes
  mes            char(7)  PK          -- 'Y-m'
  guardado_en    datetime
  guardado_por   varchar(150)         -- correo de $_SESSION['tg_user']['Correo']
  recalculado_en datetime NULL
  recalculado_por varchar(150) NULL
  reintento_fecha date NULL           -- último día en que se reintentaron los pendientes (una vez por día)

TG.dbo.conciliacion_cobros_grupo
  id             int identity PK
  mes            char(7)              -- índice (mes, activo)
  origen         varchar(10)          -- 'auto' | 'manual'
  estatus        varchar(40)          -- texto del cruce ('Cuadra', 'Diferencia', 'Sin banco'…) o 'Conciliado manual'
  comentario     nvarchar(500) NULL   -- solo manual, opcional
  creado_en / creado_por
  activo         bit                  -- 0 = deshecho o reemplazado (no se borra físicamente)
  baja_en / baja_por NULL

TG.dbo.conciliacion_cobros_item
  id             int identity PK
  grupo_id       int FK
  tipo           char(1)              -- 'C' cobro (DocumentosC tip 4), 'M' movimiento (movimientos_bancarios.id), 'F' factura (DocumentosC tip 3)
  codgas         int NULL             -- C y F
  nro            int NULL             -- C y F
  movimiento_id  int NULL             -- M
  monto          decimal(18,2)        -- monto al momento de guardar (para alertar si cambió)
  mes            char(7)              -- mes del cobro / factura / movimiento (un grupo puede mezclar meses)
  ocupa          bit                  -- 1 = el item queda tomado por este grupo; 0 = solo referencia (ver abajo)
  índices: (tipo, codgas, nro), (movimiento_id), (mes)
```

**Movimiento de un grupo "Diferencia" (decisión del constructor/Claude).** El cruce no toma como usado el depósito de
una Diferencia (`mejorPorSecuencia(..., aunUsado: true)`), y ese depósito puede estar a la vez en un "Cuadra". Por eso se
guarda como item M con `ocupa = 0`: la fila muestra los datos del banco, pero el movimiento sigue libre para el cruce y
para los buscadores. Dar de baja grupos al guardar a mano solo considera los items con `ocupa = 1`. Un manual siempre
guarda sus items con `ocupa = 1`.

**Grupos que mezclan meses (decisión del constructor/Claude).** El grupo se lee en todos los meses que tengan un item
con ese `mes`: la lectura es "grupos activos con algún item del mes", no `grupo.mes = ?`. `grupo.mes` es el mes desde el
que se creó. Los grupos `auto` nunca mezclan meses de cobro. "Recalcular" solo toca grupos `auto` cuyo `grupo.mes` sea el
mes en pantalla.

Todos los cobros del mes quedan en algún grupo, incluso los que no cuadran ("Sin banco" = grupo con solo el cobro). Así,
"cobro nuevo" es simplemente un cobro del mes que no aparece en ningún grupo activo. Un cobro y un movimiento solo pueden
estar en un grupo activo a la vez; esto lo controla la aplicación (ver Alertas para el caso del movimiento).

### Flujo de `conciliation_payments` (POST mes, empresa)

1. Consultar los cobros del mes (`cobros()`, igual que hoy).
2. ¿Existe `conciliacion_cobros_mes` para el mes?
   - **No (primera vez):** consultar depósitos y correr `conciliar()`, igual que hoy. En una transacción, insertar la fila
     del mes y luego los grupos e items (un grupo por depósito, con sus cobros; uno por cobro sin depósito). Si el insert del
     mes choca con la PK porque otro usuario lo guardó al mismo tiempo, hacer rollback y seguir por el camino "Sí".
   - **Sí:** leer los grupos activos del mes con sus items (SELECT por `mes, activo`). Los cobros del mes que no estén en
     ningún grupo son **nuevos**: solo si los hay, consultar depósitos para esos cobros, quitar de la bolsa los movimientos
     que ya estén en grupos activos, correr `conciliar()` solo con ellos y guardar sus grupos como `auto`. Si no hay nuevos,
     no se escribe nada.
3. Traer los datos frescos de los movimientos ligados (banco, cuenta, fecha, secuencia, abono, contraparte, RFC, detalle)
   con un SELECT por `id IN (...)` a `movimientos_bancarios`, más las facturas ligadas directo (tipo F).
4. Armar las filas igual que hoy (mismas columnas que espera la vista), más: `grupo_id`, `origen`, `alertas[]`.
   Los grupos que tienen movimientos y facturas pero ningún cobro (depósito→facturas) salen como filas propias, con las
   columnas del cobro vacías y la lista de facturas.
5. Filtrar por empresa al final, como hoy (el guardado es de todas las empresas).

`cobros_mov` / `monto_mov` / `diferencia` se calculan del grupo: suma de cobros contra la suma de movimientos con `ocupa = 1`
(en una Diferencia, contra el abono de referencia, como hoy).

**Facturas en el grupo (decisión del constructor/Claude).** Si el grupo **no tiene cobros** (depósito → facturas), las
facturas se suman por su **total** contra los movimientos. Si **tiene cobros**, las facturas son solo referencia y no se
suman, para no contarlas doble con lo que el cobro ya paga (DocumentosA). En ambos casos se alerta si la factura ya está
pagada total o parcialmente por algún cobro, si está cancelada o si es de otro cliente.

### Reintento de pendientes en segundo plano (una vez por día)

Decisión del usuario: lo que ya cuadró y lo manual queda fijo. Los grupos `auto` que no cuadraron ("Sin banco",
"Sin MOV", "Diferencia") se reintentan, pero **sin frenar la carga** y **una vez por día**:

1. La carga del tab (`conciliation_payments`) responde con lo guardado, sin reintentar.
2. Al terminar de pintar, la vista llama sola a una ruta nueva (por ejemplo `conciliation/payments/retry/`, POST mes).
3. El servidor revisa `conciliacion_cobros_mes.reintento_fecha`. Si ya es hoy, responde `{cambios: 0}` sin consultar nada.
   Si no, la marca con hoy al empezar (para que dos usuarios no la corran a la vez) y:
   - toma los cobros de los grupos `auto` pendientes del mes;
   - consulta depósitos solo para ellos y quita los movimientos ocupados (`ocupa = 1` en grupos activos);
   - corre `conciliar()` con esos cobros. Si alguno cambió de estatus o de movimiento, da de baja su grupo viejo y guarda el
     nuevo, en una transacción;
   - responde `{cambios: n, detalle: [...]}`.
4. Si `cambios > 0`, la vista muestra un aviso ("Se conciliaron n cobros pendientes con movimientos nuevos del banco") y
   recarga los datos del tab. Si es 0, no hace nada visible.

La marca es por mes y vale para todos los usuarios: el primero que abre el mes ese día dispara el reintento. Un mes recién
guardado (primera vez) ya queda con `reintento_fecha = hoy`. "Recalcular automático" sigue disponible para forzarlo.
Los cobros **nuevos** (capturados después de guardar) sí se cruzan en la carga normal, como se describe arriba, porque
sin eso no tendrían fila.

### Conciliación manual (modal)

Un solo modal de Bootstrap, cargado como vista parcial Twig vía fetch (patrón del proyecto), con tres columnas o secciones:
**Cobros · Movimientos · Facturas**. Cada una tiene un buscador y una lista de seleccionados. Abajo van los totales
(cobros + facturas vs movimientos, diferencia), las alertas en vivo, el comentario opcional y **Guardar**.

Entradas:
- **Botón "Conciliar" en cada fila** del tab (cualquier estatus, automática o manual). El modal abre precargado con el
  grupo actual de esa fila: sus cobros, movimientos y facturas.
- **Botón "Conciliar desde banco"** en la cabecera del tab. Abre el modal vacío, empezando por el buscador de movimientos.

Buscadores (POST, permiso 14):
- **Movimientos:** abonos de `movimientos_bancarios` por importe (exacto o rango), fecha, cuenta, secuencia o texto
  (contraparte, RFC, detalle). Cada resultado dice si ya está en un grupo activo y de qué tipo (auto/manual, mes).
- **Cobros:** DocumentosC tip 4 subope 8 por folio, cliente, importe o MOV, del mes y de ±1 mes. Indica si ya está en un
  grupo activo.
- **Facturas:** DocumentosC tip 3 por folio (serie, por ejemplo I104614), cliente o importe. Indica si está cancelada y si
  ya la paga algún cobro (DocumentosA) o algún grupo.

Guardar (POST, transacción):
- Dar de baja (`activo = 0`, `baja_*`) los grupos activos que contengan cualquiera de los cobros, movimientos o facturas
  seleccionados, y también el grupo que se está editando.
- Crear el grupo `manual` con sus items y el comentario.
- Los cobros que estaban en un grupo dado de baja pero no se eligieron quedan sin grupo. En la siguiente consulta son
  "nuevos" y pasan por el cruce automático contra los movimientos libres. Si un movimiento quedó suelto, vuelve a la bolsa.

Deshacer: botón en el modal de un grupo manual. Lo da de baja; sus cobros vuelven al cruce automático en la siguiente
consulta.

### Alertas (nunca bloquean)

Se calculan **al guardar** (se muestran en el modal antes de confirmar) y **al mostrar** (icono con tooltip en la fila
y chip "Con alertas" para filtrar), con datos frescos:

- La suma de cobros + facturas no es igual a la suma de movimientos; se muestra la diferencia.
- El MOV capturado en un cobro no es la secuencia de ninguno de los movimientos elegidos.
- Hay más de 3 días entre la fecha del cobro y la del movimiento.
- El ordenante o RFC del banco no coincide con el cliente del cobro o de la factura.
- El movimiento, cobro o factura ya estaba en otro grupo (se dice cuál y de qué origen; al guardar, ese grupo se da de baja).
- El movimiento es de otro mes, o el cobro es de otro mes que el que se concilia.
- Hay movimientos de cuentas distintas, o de una cuenta que no suele usar la estación. "Suele usar" = cuentas de los
  grupos que cuadraron ese mes para esa estación, calculado de lo ya leído, sin consulta extra (decisión del constructor/Claude).
- Factura: cancelada, de otro cliente que el cobro u ordenante, o ya pagada por algún cobro.
- Cambió después de guardar: el cobro se canceló, se borró en ControlGas, o su monto cambió respecto al `monto` guardado.
  Lo mismo para el movimiento o la factura.
- Un grupo sin cobros y sin facturas (solo movimiento), o sin movimientos (solo cobros marcados a mano).

### Recalcular automático

Botón en la cabecera del tab. Da de baja todos los grupos `auto` activos del mes y vuelve a correr el cruce con los cobros
y movimientos que no están en grupos manuales. Actualiza `recalculado_en/por`. Pide confirmación. Nunca toca los manuales.

### Vista

- Columna de **Origen** (Auto / Manual) junto a Estatus, y nuevo estatus "Conciliado manual" en los chips.
- Icono de alerta por fila y chip "Con alertas".
- `agruparCruce` agrupa por `grupo_id` en vez de deposito_id + estatus.
- Leyenda: "Guardado el <fecha> por <usuario>" y "Recalculado…", si aplica.

### Permisos y rutas

Permiso 14 (el de la página) para todo, sin crear uno nuevo. Rutas nuevas en `routes/income.php` bajo `conciliation/`:
buscar movimientos, buscar cobros, buscar facturas, modal (GET parcial), guardar, deshacer, recalcular.

## Restricciones

- SG12 está en compatibilidad 110: sin STRING_SPLIT; las listas van en línea y saneadas, como en `depositos()`.
- Fechas de ControlGas = serial de Excel (`dateToInt`); no usar +1.
- Escapar todo texto en el modal (`escHtml`).
- `ConciliacionCobros` sigue sin BD; la persistencia va en el modelo/controlador, y la lógica de alertas en una clase sin BD
  con pruebas en `tests/` (estilo `ConciliacionCobrosTest.php`).
- No levantar el servidor PHP; las pruebas en navegador las hace el usuario.

## Lo que queda al constructor

- Nombres finales de tablas, columnas y rutas; tamaño de los índices.
- Diseño visual del modal (siguiendo los modales existentes de la página).
- Límite de resultados en los buscadores y la ventana de fechas por defecto.
- Cómo mostrar la lista de alertas en la fila (tooltip, popover).
