# Mini-histórico de tanques en el modal de corrección de cortes físicos

**Fecha:** 2026-08-06
**Módulo:** `/merma/` (Análisis de Merma Diaria)
**Contexto:** al corregir un corte físico corrupto en el modal `fisicoModal` (ver
`docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md` y checkpoint
"Corrección de físicos desde el aplicativo" en memoria `modulo-analisis-merma-diaria`),
el usuario solo ve el valor dañado y un único "sugerido" calculado. No hay forma de ver
cómo venía moviéndose el producto/turno en los días previos para juzgar si el nuevo
valor es razonable — especialmente útil en estaciones satélite con pocos tanques
(ej. estación 24, DIESEL con tanques 59 y 60).

## Objetivo

Mostrar, dentro del mismo modal de corrección, un mini-histórico de los últimos 5 días
(previos a la fecha corregida) de físico y contable por producto/turno, para dar
contexto de tendencia antes de guardar la corrección.

## Alcance

- Vive **dentro del modal existente** (`fisicoModal` en `views/merma/detalle.html`),
  no es una vista nueva.
- Granularidad: **producto + turno**, igual que el cálculo de "sugerido" que ya existe
  hoy. **No** separa por tanque individual — si un producto tiene 2+ tanques (caso
  estación 24), el histórico muestra la suma, igual que `recomendado` ya lo hace.
- Ventana: **5 días** hacia atrás desde la fecha del corte, sin incluir el día actual
  (que ya se ve en la fila principal).
- Fuente de datos: **`TG.dbo.merma_diaria`** (snapshot local, post `recalc_contable`),
  no `StockReal` vía OPENQUERY. Evita un segundo viaje a la estación; ya viene con
  físico/contable resueltos por la regla del libro amarillo.
- Estado inicial: **colapsado**. Cada fila del modal gana un botón/ícono pequeño para
  desplegar su historial; nada se expande automáticamente.

## Backend

**`MermaDiariaModel::get_cortes_fisicos()`** (`_assets/models/MermaDiariaModel.php:223`)

Ya ejecuta una consulta a `merma_diaria` con `BETWEEN DATEADD(DAY,-7,...) AND fecha`
para calcular `recomendado` (cadena del libro amarillo). Se reutiliza el mismo `$snap`
ya cargado en memoria — sin query adicional — para además construir, por
`codprd-turno`, un arreglo con los últimos 5 días:

```php
$c['historial'] = [
    ['fecha' => '2026-07-31', 'fisico' => 39850.10, 'contable' => 39902.55],
    ['fecha' => '2026-07-30', 'fisico' => 40010.00, 'contable' => 39998.20],
    // ... hasta 5 entradas, orden descendente por fecha
];
```

- `fisico` = `inv_fisico` del snapshot para ese `codprd`+`turno`+`fecha` (null si no
  hubo corte válido ese turno — se muestra "s/d").
- `contable` = `inv_contable` ya calculado por `recalc_contable`.
- Si el producto tiene varios tanques, `merma_diaria` ya los trae sumados a nivel
  producto/turno (mismo comportamiento que hoy).
- Sin endpoint nuevo: viaja dentro de la respuesta existente de `POST /merma/cortes_fisicos`.

## Frontend

**`views/merma/detalle.html`**, dentro del loop `res.cortes.forEach(...)` (~L378-396):

- Cada `<tr>` de tanque gana un ícono `▾` (botón) al final de la fila.
- Al hacer clic, despliega una sub-fila (`<tr>` oculta, toggle con clase `d-none`) con
  una tabla compacta de 2 filas × hasta 5 columnas: **Físico** y **Contable**, una
  columna por día (fecha corta como header).
- Si `historial` viene vacío para esa combinación producto/turno (estación nueva sin
  datos previos), no se renderiza el botón.
- Sin dependencias nuevas (no chart.js/echarts) — es una tabla HTML simple, coherente
  con el resto del modal y con el objetivo de "leer un número", no de visualizar
  tendencia gráficamente.

## Fuera de alcance (posible iteración futura)

- Vista de historial de tanques independiente del flujo de corrección (exploración
  libre de un rango como julio completo), mencionada por el usuario pero pospuesta.
- Separar por tanque individual (requeriría ir a `StockReal` día por día, más lento y
  hereda el riesgo de lecturas corruptas crudas).
- Visualización tipo sparkline/gráfico.

## Testing

- Sin framework de tests en el proyecto (ver CLAUDE.md). Verificación manual: abrir
  `/merma/detalle/24?desde=2026-08-01&hasta=2026-08-05`, clic en una fila `.fis-editable`
  con DIESEL turno 11, confirmar que el botón de historial aparece y al expandir muestra
  hasta 5 días previos coherentes con lo ya visible en la vista Diario/Desglosado.
