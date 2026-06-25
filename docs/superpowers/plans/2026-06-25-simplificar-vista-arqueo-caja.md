# Simplificar vista de captura de arqueo (Cajón + Caja Fuerte) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reducir las 4 secciones de captura de `/arqueo/caja/{caja_id}` (Cajón del Cajero, Billetes de Caja Fuerte, Morrallero del Cajero, Morrallero de Caja Fuerte) a 2 secciones (Billetes, Monedas), cada una con dos columnas de cantidad (Cajón / Caja Fuerte) en la misma fila por denominación, sin tocar backend ni esquema de BD.

**Architecture:** Cambio puramente de presentación. `views/arqueo/caja.html` pasa de iterar 4 `seccion` independientes a iterar 2 grupos visuales, cada uno emparejando dos arrays de denominación (Cajón y Caja Fuerte) por índice. `_assets/js/arqueo.js` ajusta `recalcular()` para que una fila con dos inputs (`data-seccion` distinto cada uno) pinte un único total combinado por fila, sin cambiar la lógica de agregación por `seccion`+`moneda` que ya alimenta el panel de resumen.

**Tech Stack:** PHP 8 + Twig (vista), JavaScript vanilla (cálculo en vivo), sin build step.

## Global Constraints

- No modificar `_assets/controllers/arqueo.php`, ningún modelo `Arqueo*Model.php`, ni el esquema de `arqueo_denominaciones` (spec: "Sin cambios").
- Los nombres internos de `seccion` (`cajon`, `caja_fuerte`, `morrallero`, `morrallero_cf`) no cambian — son la clave que usa el backend y deben seguir usándose igual en los atributos `data-seccion` del HTML.
- El payload que `guardarCaja()` envía a `/arqueo/guardar_caja/{id}` debe seguir teniendo la misma forma (un objeto por cada `.denom-input` con `seccion`, `moneda`, `tipo`, `denominacion`, `valor_bolsa`, `cantidad`).
- No hay framework de pruebas en este proyecto (ver CLAUDE.md) — la verificación de cada tarea es manual: cargar la página en el navegador (`php -S localhost:8000 router.php`) y revisar consola/cálculos.

---

## Contexto de archivos existentes (no modificar fuera de lo indicado)

- `_assets/controllers/arqueo.php:46-65` — constante `DENOMINACIONES`, con las 4 listas (`cajon`, `morrallero`, `caja_fuerte`, `morrallero_cf`) por moneda. **No cambia.**
- `_assets/controllers/arqueo.php:256-274` — método `caja($caja_id)` pasa `estructura = self::DENOMINACIONES` a la vista. **No cambia.**
- `views/arqueo/caja.html:95-168` — bloque actual de 4 secciones a sustituir.
- `_assets/js/arqueo.js:93-158` — función `recalcular()` a ajustar.
- `_assets/js/arqueo.js:160-183` — `precargarCaja()`, **no requiere cambios** (selectores por `data-seccion`/`data-moneda`/`data-denominacion` siguen funcionando igual sin importar la posición en el DOM).
- `_assets/js/arqueo.js:185-230` — `guardarCaja()`, **no requiere cambios** (recolecta todos los `.denom-input` sin importar su posición visual).

---

### Task 1: Reestructurar `caja.html` — sección "Billetes" (Cajón + Caja Fuerte)

**Files:**
- Modify: `views/arqueo/caja.html:91-168` (bloque completo de las 4 secciones)

**Interfaces:**
- Consumes: `estructura` (pasado por el controlador, sin cambios) — `estructura.cajon.USD`/`estructura.cajon.MXN` (arrays de denominación billete), `estructura.caja_fuerte.USD`/`estructura.caja_fuerte.MXN` (mismas denominaciones, fajilla).
- Produces: inputs `.denom-input` con `data-seccion="cajon"` y `data-seccion="caja_fuerte"` en la misma `<tr>`, cada fila con una sola celda `<td class="total">` — consumido por Task 3 (`recalcular()`).

Esta tarea reemplaza **solo** la parte de "Billetes" del bucle de 4 secciones. La parte de "Monedas" se hace en la Task 2 — por eso en este paso el bloque de Twig queda con las dos secciones nuevas ya completas (Billetes y Monedas), para no dejar el archivo en un estado intermedio roto.

- [ ] **Step 1: Reemplazar el bloque de 4 secciones por las 2 secciones nuevas**

Reemplazar las líneas 91-168 de `views/arqueo/caja.html` (desde el comentario `{# ---------------- Grids de denominaciones... #}` hasta el `{% endfor %}` que cierra el bucle de secciones) con:

```twig
    {# ---------------- Grids de denominaciones ----------------
       Dos secciones visuales (Billetes, Monedas), cada una con una tabla
       por moneda que combina Cajón y Caja Fuerte en la misma fila por
       denominación. Las listas de Cajón y Caja Fuerte están alineadas
       posicionalmente (misma cantidad de denominaciones, mismo orden). #}

    {% set grupos = {
        'billetes': {
            'titulo':        'Billetes',
            'tipo_cajon':     'billete',
            'tipo_cf':        'fajilla',
            'label_cajon':    'Cajón',
            'label_cf':       'Caja Fuerte',
            'denoms_cajon':   {'USD': estructura.cajon.USD, 'MXN': estructura.cajon.MXN},
            'denoms_cf':      {'USD': estructura.caja_fuerte.USD, 'MXN': estructura.caja_fuerte.MXN},
            'valor_bolsa_cf': null
        },
        'monedas': {
            'titulo':        'Monedas',
            'tipo_cajon':     'moneda',
            'tipo_cf':        'bolsa',
            'label_cajon':    'Cajón',
            'label_cf':       'Caja Fuerte',
            'denoms_cajon':   {'USD': estructura.morrallero.USD, 'MXN': estructura.morrallero.MXN},
            'denoms_cf':      {'USD': estructura.morrallero_cf.USD.denominacion, 'MXN': estructura.morrallero_cf.MXN.denominacion},
            'valor_bolsa_cf': {'USD': estructura.morrallero_cf.USD.valor_bolsa, 'MXN': estructura.morrallero_cf.MXN.valor_bolsa}
        }
    } %}

    {% set seccion_cajon = {'billetes': 'cajon', 'monedas': 'morrallero'} %}
    {% set seccion_cf    = {'billetes': 'caja_fuerte', 'monedas': 'morrallero_cf'} %}

    {% for grupo_id, g in grupos %}
    <div class="card mb-3">
      <div class="seccion-titulo">{{ g.titulo }}</div>
      <div class="card-body">
        <div class="row">
          {% for moneda in ['USD','MXN'] %}
          {% set denoms = g.denoms_cajon[moneda] %}
          {% set denoms_cf = g.denoms_cf[moneda] %}
          {% set bolsas = g.valor_bolsa_cf ? g.valor_bolsa_cf[moneda] : [] %}
          <div class="col-md-6">
            <table class="table table-sm table-bordered denom-tabla {{ moneda == 'USD' ? 'moneda-usd' : 'moneda-mxn' }}">
              <thead>
                <tr>
                  <th colspan="4" class="text-center">{{ moneda }}</th>
                </tr>
                <tr>
                  <th>Denominación</th>
                  <th>{{ g.label_cajon }}</th>
                  <th>{{ g.label_cf }}{% if g.valor_bolsa_cf %} <small class="text-muted">($/bolsa)</small>{% endif %}</th>
                  <th class="text-end">Total</th>
                </tr>
              </thead>
              <tbody>
                {% set es_moneda = (grupo_id == 'monedas') %}
                {% for i, denom in denoms %}
                {# Nombre del archivo de imagen de apoyo: moneda_denominacion(.png).
                   Para monedas, $1 USD y $20 MXN llevan sufijo _coin (no chocar con el billete). #}
                {% set img = (moneda|lower) ~ '_' ~ denom %}
                {% if es_moneda and ((moneda == 'USD' and denom == 1) or (moneda == 'MXN' and denom == 20)) %}
                  {% set img = img ~ '_coin' %}
                {% endif %}
                <tr>
                  <td class="text-nowrap">
                    <img src="{{ IMAGES }}arqueo/{{ img }}.png" alt="${{ denom }}"
                         class="denom-img" loading="lazy"
                         onerror="this.style.display='none'">
                    ${{ denom }}
                  </td>
                  <td>
                    <input type="number" min="0" step="1"
                           class="form-control form-control-sm cantidad campo-amarillo denom-input"
                           data-seccion="{{ seccion_cajon[grupo_id] }}"
                           data-moneda="{{ moneda }}"
                           data-tipo="{{ g.tipo_cajon }}"
                           data-denominacion="{{ denom }}"
                           value="0">
                  </td>
                  <td>
                    {% if g.valor_bolsa_cf %}
                    <div class="small text-muted mb-1">${{ bolsas[i] }}/bolsa</div>
                    {% endif %}
                    <input type="number" min="0" step="1"
                           class="form-control form-control-sm cantidad campo-amarillo denom-input"
                           data-seccion="{{ seccion_cf[grupo_id] }}"
                           data-moneda="{{ moneda }}"
                           data-tipo="{{ g.tipo_cf }}"
                           data-denominacion="{{ denoms_cf[i] }}"
                           {% if g.valor_bolsa_cf %}data-valor-bolsa="{{ bolsas[i] }}"{% endif %}
                           value="0">
                  </td>
                  <td class="total" data-total="0">$0.00</td>
                </tr>
                {% endfor %}
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Gran Total {{ g.titulo }}</th>
                  <td class="gran-total"
                      data-gran-total-cajon="{{ seccion_cajon[grupo_id] }}-{{ moneda }}"
                      data-gran-total-cf="{{ seccion_cf[grupo_id] }}-{{ moneda }}">$0.00</td>
                </tr>
              </tfoot>
            </table>
          </div>
          {% endfor %}
        </div>
      </div>
    </div>
    {% endfor %}
```

Notas sobre este bloque:
- Para "Monedas" en Caja Fuerte, `data-denominacion` se llena con `denoms_cf[i]` (que es la denominación facial de la moneda asociada a esa bolsa, igual que hoy en el `morrallero_cf` original) — **no** con el valor de la bolsa. Esto es necesario porque `recalcular()` y `guardarCaja()` leen `data-denominacion` como la denominación facial en todos los casos; el valor de bolsa va aparte en `data-valor-bolsa`.
- Para "Billetes", `denoms_cf[i]` es siempre igual a `denoms[i]` (misma lista de denominaciones en Cajón y Caja Fuerte) — se usa `denoms_cf[i]` en el input por claridad y para que el código sea simétrico con el caso de Monedas.
- El atributo `data-gran-total` antiguo (un solo valor `seccion-moneda`) se reemplaza por dos atributos (`data-gran-total-cajon` y `data-gran-total-cf`) en la misma celda, porque ahora el "Gran Total" de la tabla debe sumar dos secciones BD (`cajon`+`caja_fuerte`, o `morrallero`+`morrallero_cf`). Esto se consume en la Task 3.

- [ ] **Step 2: Verificar visualmente en navegador**

Arrancar el servidor de desarrollo si no está corriendo:

```bash
php -S localhost:8000 router.php
```

Abrir `http://localhost:8000/arqueo/caja/1` (sustituir `1` por un `caja_id` real y accesible con el usuario de prueba — confirmar con el usuario qué `caja_id` usar si `1` no existe en el entorno local).

Verificar:
- Aparecen 2 tarjetas tituladas "Billetes" y "Monedas" (ya no 4 tarjetas).
- Cada tarjeta tiene 2 tablas (USD, MXN), cada tabla con columnas: Denominación | Cajón | Caja Fuerte | Total.
- En "Monedas", la columna "Caja Fuerte" muestra el valor de bolsa pequeño (ej. "$100/bolsa") arriba del input.
- Las imágenes de denominación siguen apareciendo igual que antes.
- No hay errores en la consola del navegador (F12 → Console) al cargar la página — en este punto SÍ se esperan errores de cálculo (`recalcular()` aún no ajustado), pero no errores de Twig/render.

- [ ] **Step 3: Commit**

```bash
git add views/arqueo/caja.html
git commit -m "Combinar Cajon y Caja Fuerte en una sola tabla por seccion (Billetes/Monedas)"
```

---

### Task 2: Ajustar `recalcular()` para celda de total combinado por fila

**Files:**
- Modify: `_assets/js/arqueo.js:93-158` (función `recalcular()`)

**Interfaces:**
- Consumes: DOM producido por Task 1 — filas `<tr>` con dos `.denom-input` (uno `data-seccion="cajon|morrallero"`, otro `data-seccion="caja_fuerte|morrallero_cf"`) y una sola `<td class="total">`; celdas `<td class="gran-total">` con `data-gran-total-cajon` y `data-gran-total-cf`.
- Produces: misma interfaz pública que antes — `recalcular()` sigue sin parámetros ni retorno, sigue pintando `#r_fisico_usd`, `#r_fisico_mxn`, etc. (sin cambios para los consumidores del panel de resumen).

El problema concreto: hoy `recalcular()` itera **cada** `.denom-input` y por cada uno hace `inp.closest("tr").querySelector("td.total")`, sobrescribiendo el total de la celda con el valor de un solo input. Con 2 inputs por fila, la segunda iteración pisaría el resultado de la primera. Hay que acumular ambos inputs de la fila antes de pintar.

- [ ] **Step 1: Reemplazar el cuerpo de `recalcular()` que calcula por fila y gran totales**

En `_assets/js/arqueo.js`, reemplazar las líneas 94-117 (desde `function recalcular() {` hasta el cierre del primer `document.querySelectorAll("[data-gran-total]")...` bloque) con:

```javascript
function recalcular() {
  const seccionMoneda = {}; // "seccion-MONEDA" -> suma

  // Acumular por fila: cada <tr> puede tener 1 o 2 .denom-input (Vales no
  // usa esta clase). Se suma el total de todos los inputs de la fila y se
  // pinta una sola vez en la celda td.total de esa fila.
  document.querySelectorAll(".denom-tabla tbody tr").forEach((tr) => {
    let totalFila = 0;
    tr.querySelectorAll(".denom-input").forEach((inp) => {
      const tipo = inp.dataset.tipo;
      const denom = parseFloat(inp.dataset.denominacion) || 0;
      const cantidad = parseInt(inp.value, 10) || 0;
      const valorBolsa = inp.dataset.valorBolsa ? parseFloat(inp.dataset.valorBolsa) : null;
      const total = totalDenominacion(tipo, denom, cantidad, valorBolsa);

      totalFila += total;
      const key = inp.dataset.seccion + "-" + inp.dataset.moneda;
      seccionMoneda[key] = (seccionMoneda[key] || 0) + total;
    });

    const celda = tr.querySelector("td.total");
    if (celda) {
      celda.dataset.total = totalFila;
      celda.textContent = fmtMoney(totalFila);
    }
  });

  // Gran totales por tabla: suman las dos secciones BD de esa tabla
  // (ej. cajon + caja_fuerte para Billetes; morrallero + morrallero_cf para Monedas).
  document.querySelectorAll("[data-gran-total-cajon]").forEach((td) => {
    const keyCajon = td.dataset.granTotalCajon;
    const keyCf = td.dataset.granTotalCf;
    const total = (seccionMoneda[keyCajon] || 0) + (seccionMoneda[keyCf] || 0);
    td.textContent = fmtMoney(total);
  });
```

El resto de la función (desde `const sum = (k) => seccionMoneda[k] || 0;` en adelante, líneas 119-157 del archivo original) **no cambia** — sigue usando las mismas claves `cajon-USD`, `morrallero-USD`, `caja_fuerte-USD`, `morrallero_cf-USD`, etc., que siguen siendo las claves reales que produce el bucle de arriba (`inp.dataset.seccion + "-" + inp.dataset.moneda`).

- [ ] **Step 2: Verificar cálculo en vivo en navegador**

Con el servidor corriendo y la página `/arqueo/caja/{id}` abierta (recargar para tomar el JS actualizado):

1. En "Billetes" sección USD, fila `$1`: Cajón=`5` → la celda Total de esa fila debe mostrar `$5.00` y el Gran Total Billetes USD debe incluir ese monto.
2. En la misma fila (`$1 USD`), Caja Fuerte=`2` (2 fajillas de $1, fórmula fajilla = cantidad×denom×100 = 2×1×100 = $200) → la celda Total de esa fila debe actualizarse a `$205.00` ($5 de Cajón + $200 de Caja Fuerte).
3. En "Monedas" sección USD, fila `$1`: Cajón=`3` (→$3.00), Caja Fuerte=`1` bolsa (etiqueta debe mostrar "$100/bolsa", fórmula bolsa = cantidad×valor_bolsa = 1×100 = $100) → Total fila = `$103.00`.
4. Verificar que el panel derecho ("Resumen del arqueo", `#r_fisico_usd` etc.) refleja la suma de todo lo anterior sin duplicar ni omitir valores.
5. Abrir la consola del navegador (F12) y confirmar que no hay errores JS al teclear en los inputs.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/arqueo.js
git commit -m "Sumar Cajon+Caja Fuerte por fila en recalcular() de arqueo"
```

---

### Task 3: Verificar guardado y precarga end-to-end

**Files:**
- No se modifican archivos en esta tarea — es verificación funcional de los cambios de Task 1 y Task 2 contra `guardar_caja()`/`precargarCaja()`, que ya soportan la nueva disposición sin cambios de código (confirmado en el análisis de la spec).

**Interfaces:**
- Consumes: endpoint existente `/arqueo/guardar_caja/{caja_id}` (`_assets/controllers/arqueo.php:284-339`), sin cambios.
- Produces: confirmación de que el flujo completo (capturar → guardar → recargar página → ver datos precargados) funciona con la vista reestructurada.

- [ ] **Step 1: Capturar y guardar una caja completa**

Con la página `/arqueo/caja/{id}` abierta (usar una caja de una sesión en estado `abierto` — confirmar con el usuario el `caja_id` de prueba si no se conoce uno):

1. Llenar `Cajero (nombre y firma)`, `Encargado de revisión`.
2. Llenar Go Exchange: Dólares=`100`, MXN=`500`, Tipo de cambio Venta=`18.50`, Compra=`18.00`.
3. En "Billetes" USD, fila `$100`: Cajón=`1`, Caja Fuerte=`0`.
4. En "Monedas" USD, fila `$1`: Cajón=`0`, Caja Fuerte=`1`.
5. Click en "Guardar arqueo".
6. Verificar que aparece el toast verde "Arqueo guardado." (no un toast rojo de error).

- [ ] **Step 2: Verificar persistencia y precarga**

1. Recargar la página (F5).
2. Verificar que el input Cajón de `$100 USD` en Billetes sigue mostrando `1`.
3. Verificar que el input Caja Fuerte de `$1 USD` en Monedas sigue mostrando `1`.
4. Verificar que los totales por fila y el panel de resumen se recalculan correctamente al cargar (sin necesidad de tocar ningún input) — esto ejercita `precargarCaja()` + `recalcular()` en `DOMContentLoaded`.

- [ ] **Step 3: Verificar caso de solo lectura (sesión cerrada)**

Si existe una sesión en estado `cerrado` con cajas capturadas:

1. Abrir `/arqueo/caja/{caja_id}` de una caja de esa sesión.
2. Verificar que aparece el aviso "La sesión está cerrada. Este arqueo es de solo lectura."
3. Verificar que todos los inputs (incluyendo los nuevos de Cajón/Caja Fuerte combinados) están deshabilitados.
4. Verificar que los valores previamente guardados se muestran correctamente distribuidos en las columnas Cajón y Caja Fuerte (no mezclados ni perdidos).

Si no existe ninguna sesión cerrada en el entorno de prueba, omitir este step y dejar constancia en el resultado de la tarea de que no se pudo verificar este caso por falta de datos, en vez de marcarlo como verificado sin haberlo probado.

- [ ] **Step 4: Reportar resultado (sin commit — esta tarea no modifica código)**

No hay cambios de código que commitear en esta tarea. Si algún paso de verificación falla, volver a Task 1 o Task 2 según corresponda, corregir, y repetir la verificación de esta tarea desde el Step 1.
