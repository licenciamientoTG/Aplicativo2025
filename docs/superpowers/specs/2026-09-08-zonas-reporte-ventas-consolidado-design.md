# Zonas en el Reporte de Ventas Consolidado (`/merma/ventas`) — Design Spec

## Contexto

`/merma/ventas` (controlador `Merma::ventas()`, clase `VentasConsolidado`) ya reemplaza el libro
`VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm`, mostrando las 38 estaciones activas juntas en una
sola tabla ancha, con 5 pestañas de producto (Litros de combustible, Regular+Premium, Regular,
Premium, Diesel) más un tab Histórico.

En la práctica, el negocio trabaja con **tres libros Excel separados**, cada uno con las mismas 5
hojas de producto pero con un subconjunto fijo de estaciones:

- `VETS X EST X MARCA Y PROTS Sep2026.xlsm` — 27 estaciones (el resto de la red)
- `VETS X EST X TSA AGS Sep2026.xlsm` — 4 estaciones (San Rafael, Puertecito, Jesús María,
  Gabriela Mistral) + Colosio
- `VETS X EST X ZONA3 Sep2026.xlsm` — 7 estaciones (Ejército Nacional, Satélite, Las Fuentes,
  Clara, Solís, Santiago Troncoso, Jarudo)

Cada Excel tiene su propio resumen mensual (TOTAL, % MIX, PROY. MENSUAL, PRESUPUESTO, etc.)
calculado solo sobre sus propias estaciones — no son un recorte visual de un total global.

## Hallazgo clave

`TG.dbo.Estaciones.ZonaConso` ya clasifica esto casi por completo:

| ZonaConso | Zona real         | Estaciones                                                  |
|-----------|--------------------|--------------------------------------------------------------|
| 3         | ZONA 3             | Ejército Nacional, Satélite, Las Fuentes, Clara, Solís, Santiago Troncoso, Jarudo (calza exacto, 7/7) |
| 2         | TSA AGS            | San Rafael, Puertecito, Jesús María, Gabriela Mistral (4/4; falta Colosio) |
| 1, 0, NULL| MARCA Y PROTS      | El resto (incluye Travel Center, Picachos, Ventanas con ZonaConso=0) |

**Excepción a corregir:** Colosio (Código 199) tiene `ZonaConso` NULL/vacío, pero pertenece a TSA
AGS según el Excel real. Se corrige el dato en BD (no un hardcode en PHP):

```sql
UPDATE TG.dbo.Estaciones SET ZonaConso = 2 WHERE Codigo = 199;
```

## Diseño

### 1. Constante de zonas

Nueva sección en `VentasConsolidado` (o clase propia si crece):

```php
public const ZONAS = [
    'marca_prots' => ['label' => 'MARCA Y PROTS', 'zonaconso' => [0, 1]],  // incluye NULL
    'tsa_ags'     => ['label' => 'TSA AGS',        'zonaconso' => [2]],
    'zona3'       => ['label' => 'ZONA 3',         'zonaconso' => [3]],
];
```

`NULL` se trata como equivalente a "no 2, no 3" → cae en `marca_prots` por exclusión, no por
inclusión explícita en la lista (evita re-listar NULL como caso especial en cada filtro).

### 2. Modelo — exponer `ZonaConso`

`MermaDiariaModel::get_estaciones_ordenadas()` agrega `g_estacion... ZonaConso` (columna nativa de
`TG.dbo.Estaciones`, no de `SG12.dbo.Gasolineras`) al SELECT y al array devuelto, como
`'ZonaConso' => $e['ZonaConso']` (int|null).

### 3. Controlador — `armarReporte()` por zona

En vez de construir `VentasConsolidado::construir()` una vez sobre las 38 estaciones, se itera:

```php
foreach (VentasConsolidado::ZONAS as $zonaClave => $zonaInfo) {
    $estacionesZona = array_values(array_filter($estaciones, fn($e) =>
        in_array($zonaClave === 'marca_prots' ? ($e['ZonaConso'] ?? 0) : $e['ZonaConso'], $zonaInfo['zonaconso'], true)
    ));
    $ctxZona = $ctx; // mismo array base (ventas, presupuesto, mes_anterior, anio_anterior, anio, mes)
    $ctxZona['estaciones'] = $estacionesZona;

    $pestanas = [];
    foreach (array_keys(VentasConsolidado::PESTANAS) as $clave) {
        $pestanas[$clave] = VentasConsolidado::construir($clave, $ctxZona);
    }
    $zonas[$zonaClave] = [
        'label'           => $zonaInfo['label'],
        'estaciones'      => $estacionesZona,
        'pestanas'        => $pestanas,
        'sin_presupuesto' => $presupuesto === [],
    ];
}
```

`VentasConsolidado::construir()` NO se modifica — ya opera sobre lo que le pases en
`ctx['estaciones']`, así que el filtrado vive enteramente en el controlador.

`armarReporte()` devuelve `['anio' => ..., 'mes' => ..., 'zonas' => [...], ...]` en vez de
`estaciones`/`pestanas`/`sin_presupuesto` planos.

### 4. Histórico por zona

`ventas_historico()` recibe un nuevo parámetro `zona` (uno de los 3 claves), valida contra
`VentasConsolidado::ZONAS`, y filtra `estaciones` de `armarHistorico()` de la misma forma antes de
llamar `VentasConsolidado::construirHistorico()`.

### 5. Vista `ventas.html` — tabs anidados

```
<ul class="nav nav-tabs"> (zonas: marca_prots / tsa_ags / zona3)
  <div class="tab-pane" id="tab-zona-{zonaClave}">
     <ul class="nav nav-tabs"> (productos: total / reg_prem / regular / premium / diesel / histórico)
        ... contenido idéntico al de hoy, pero usando zonas[zonaClave].estaciones y
            zonas[zonaClave].pestanas ...
        IDs prefijados: id="tab-{zonaClave}-{claveProducto}", href="#tab-{zonaClave}-{claveProducto}"
```

Controles de mes/año arriba de TODO (fuera de los tabs de zona) — compartidos, aplican a las 3
zonas simultáneamente vía la misma URL/recarga de página.

Controles de histórico (año desde/hasta, producto) también compartidos — un solo set de selects;
el JS dispara el fetch de `ventas_historico` pasando la zona del tab actualmente visible, y
escribe el resultado en el contenedor de histórico de esa zona.

### 6. JS (`merma_ventas.js`)

- Detectar cambios de tab de zona (evento `shown.bs.tab` en los links de zona) para saber cuál
  está visible.
- Al pedir histórico: incluir `zona` en la query, apuntando al contenedor
  `#hist_contenido-{zonaClave}` correspondiente.
- Botón "Exportar a Excel": el `href` se recalcula en JS (o el form se resuelve por JS) incluyendo
  `zona={zonaActiva}` además de `mes`/`anio`/`desde`/`hasta`/`prod`.

### 7. Exportación a Excel

`ventas_excel()` recibe `zona` (query param, valida contra `ZONAS`, default `marca_prots`).
Arma el reporte igual que hoy pero filtrado a esa zona (mismo mecanismo de `armarReporte()`).
Nombre de archivo: `ventas_{zonaClave}_{anio}_{mes}.xlsx` (antes `ventas_consolidado_...`).
Estructura interna del .xlsx sin cambios (5 hojas de producto + Histórico), ahora con las
estaciones de la zona exportada únicamente.

## Fuera de alcance

- No se toca `VentasConsolidado::construir()` ni `construirHistorico()` — su firma y lógica
  interna siguen intactas; el único cambio es qué subconjunto de `estaciones` reciben.
- No se agregan controles de mes/año/histórico independientes por zona.
- No se exportan las 3 zonas en un solo archivo combinado.
