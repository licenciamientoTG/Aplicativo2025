# Vales en dólares — conversión correcta a MXN (Arqueo D2GO) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corregir el cálculo de vales en dólares en el módulo de Arqueo D2GO para que se conviertan a MXN usando `costo_promedio` (en vez de sumarse 1:1) y para que sí impacten el Resumen del arqueo y el Resultado Final de la caja.

**Architecture:** Cambio puramente aritmético en dos lugares que ya están documentados como duplicados intencionales: `_assets/js/arqueo.js` (cálculo en vivo en el navegador) y `Arqueo::calcular_totales_caja()` en `_assets/controllers/arqueo.php` (fuente de verdad al guardar). El concentrado (`Arqueo::concentrado()`) no se toca: lee `gran_total_vales_mxn` ya calculado desde `arqueo_cajas`, así que hereda la corrección automáticamente.

**Tech Stack:** PHP 8 (sin framework de tests), JS vanilla (sin bundler), Twig. No existe test runner en este proyecto — la verificación es manual, comparando el panel en el navegador contra cálculos de referencia hechos a mano para varios casos.

## Global Constraints

- No modificar el esquema de BD ni los modelos (`ArqueoValesModel`, `ArqueoCajasModel`) — solo agregación en el controlador y en JS.
- No recalcular/backfillear cajas ya guardadas (decisión explícita: "Solo corregir hacia adelante").
- No tocar `views/arqueo/caja.html` ni `views/arqueo/concentrado.html` — los `id` de celdas ya existen y solo cambian los valores que reciben.
- Mantener el comentario de sincronización en `arqueo.js:3` ("Las fórmulas replican Arqueo::calcular_totales_caja") — los dos archivos deben quedar matemáticamente idénticos.
- `fisico_usd`, `fisico_mxn` y las filas "Diferencias" (`dif_usd`, `dif_mxn`) se mantienen física-only (sin vales) — solo son informativas.
- `resultado_final` deja de calcularse como `dif_mxn + dif_usd * costo_promedio` y pasa a `total_arqueo - total_sistema` (evita contar el vale en USD dos veces).

---

### Task 1: Corregir `calcular_totales_caja()` en el controlador (fuente de verdad server-side)

**Files:**
- Modify: `_assets/controllers/arqueo.php:1052-1104` (método privado `calcular_totales_caja`)

**Interfaces:**
- Consumes: `$denom_rows` (array de denominaciones normalizadas, cada una con `seccion`, `moneda`, `total`), `$vales` (array de `{numero_vale, fecha, concepto, dolares, mxn}`), `$go` (`{go_exchange_dolares, go_exchange_mxn, costo_promedio}`) — firma sin cambios.
- Produces: array asociativo de totales consumido por `guardar_caja()` (línea 527) y persistido vía `$this->cajasModel->update_totales()`. Claves nuevas/cambiadas de significado: `total_en_sistema` (sin cambio de fórmula), `total_arqueo_mxn` (ahora incluye vales en USD convertidos), `gran_total_vales_mxn` (ahora convierte vales_dolares con costo_promedio), `resultado_final` (ahora derivado de `total_arqueo_mxn - total_en_sistema`, ya no de `diferencia_mxn + diferencia_dolares * costo_promedio` por separado). Las claves `diferencia_dolares` y `diferencia_mxn` se conservan sin cambio de fórmula (informativas, física-only).

- [ ] **Step 1: Leer el método actual para confirmar los límites exactos de líneas antes de editar**

Ya leído en la conversación (líneas 1052-1104). El bloque actual es:

```php
    private function calcular_totales_caja(array $denom_rows, array $vales, array $go): array
    {
        $sum = function (string $seccion, string $moneda) use ($denom_rows): float {
            $t = 0.0;
            foreach ($denom_rows as $r) {
                if ($r['seccion'] === $seccion && $r['moneda'] === $moneda) {
                    $t += (float) $r['total'];
                }
            }
            return $t;
        };

        $total_fisico_dolares =
            $sum('cajon', 'USD') + $sum('morrallero', 'USD')
            + $sum('caja_fuerte', 'USD') + $sum('morrallero_cf', 'USD');

        $total_fisico_mxn =
            $sum('cajon', 'MXN') + $sum('morrallero', 'MXN')
            + $sum('caja_fuerte', 'MXN') + $sum('morrallero_cf', 'MXN');

        $total_vales_dolares = 0.0;
        $total_vales_mxn     = 0.0;
        foreach ($vales as $v) {
            $total_vales_dolares += (float) ($v['dolares'] ?? 0);
            $total_vales_mxn     += (float) ($v['mxn'] ?? 0);
        }
        $gran_total_vales_mxn = $total_vales_dolares + $total_vales_mxn;

        $costo_promedio = (float) $go['costo_promedio'];

        $total_arqueo_mxn = $total_fisico_mxn + $total_vales_mxn;
        $total_en_sistema = ((float) $go['go_exchange_dolares'] * $costo_promedio) + (float) $go['go_exchange_mxn'];

        $diferencia_dolares = $total_fisico_dolares - (float) $go['go_exchange_dolares'];
        $diferencia_mxn     = $total_arqueo_mxn - (float) $go['go_exchange_mxn'];
        $resultado_final    = $diferencia_mxn + ($diferencia_dolares * $costo_promedio);

        return [
            'go_exchange_dolares'  => (float) $go['go_exchange_dolares'],
            'go_exchange_mxn'      => (float) $go['go_exchange_mxn'],
            'costo_promedio'       => $costo_promedio,
            'total_fisico_dolares' => $total_fisico_dolares,
            'total_fisico_mxn'     => $total_fisico_mxn,
            'total_arqueo_mxn'     => $total_arqueo_mxn,
            'total_en_sistema'     => $total_en_sistema,
            'total_vales_dolares'  => $total_vales_dolares,
            'total_vales_mxn'      => $total_vales_mxn,
            'gran_total_vales_mxn' => $gran_total_vales_mxn,
            'diferencia_dolares'   => $diferencia_dolares,
            'diferencia_mxn'       => $diferencia_mxn,
            'resultado_final'      => $resultado_final,
        ];
    }
```

- [ ] **Step 2: Reemplazar el bloque de cálculo de vales y totales**

Usa el tool Edit con este `old_string`:

```php
        $total_vales_dolares = 0.0;
        $total_vales_mxn     = 0.0;
        foreach ($vales as $v) {
            $total_vales_dolares += (float) ($v['dolares'] ?? 0);
            $total_vales_mxn     += (float) ($v['mxn'] ?? 0);
        }
        $gran_total_vales_mxn = $total_vales_dolares + $total_vales_mxn;

        $costo_promedio = (float) $go['costo_promedio'];

        $total_arqueo_mxn = $total_fisico_mxn + $total_vales_mxn;
        $total_en_sistema = ((float) $go['go_exchange_dolares'] * $costo_promedio) + (float) $go['go_exchange_mxn'];

        $diferencia_dolares = $total_fisico_dolares - (float) $go['go_exchange_dolares'];
        $diferencia_mxn     = $total_arqueo_mxn - (float) $go['go_exchange_mxn'];
        $resultado_final    = $diferencia_mxn + ($diferencia_dolares * $costo_promedio);
```

Y este `new_string`:

```php
        $total_vales_dolares = 0.0;
        $total_vales_mxn     = 0.0;
        foreach ($vales as $v) {
            $total_vales_dolares += (float) ($v['dolares'] ?? 0);
            $total_vales_mxn     += (float) ($v['mxn'] ?? 0);
        }
        $costo_promedio = (float) $go['costo_promedio'];

        // Los vales en dólares se convierten a MXN igual que el efectivo
        // físico en dólares (multiplican por costo_promedio), no se suman 1:1.
        $gran_total_vales_mxn = ($total_vales_dolares * $costo_promedio) + $total_vales_mxn;

        // "Total Dólares" del panel: físico + vales, ya convertidos.
        $usd_costo_promedio = ($total_fisico_dolares + $total_vales_dolares) * $costo_promedio;
        // "Total Moneda Nacional" del panel: físico + vales, ambos ya en MXN.
        $total_arqueo_mxn = $total_fisico_mxn + $total_vales_mxn;
        $total_en_sistema = ((float) $go['go_exchange_dolares'] * $costo_promedio) + (float) $go['go_exchange_mxn'];

        // Diferencias mostradas: informativas, solo conteo físico (sin vales).
        $diferencia_dolares = $total_fisico_dolares - (float) $go['go_exchange_dolares'];
        $diferencia_mxn     = $total_arqueo_mxn - (float) $go['go_exchange_mxn'];
        // Resultado final: se deriva de los totales ya sumados (incluyen vales
        // en USD exactamente una vez, vía usd_costo_promedio), no de
        // diferencia_mxn + diferencia_dolares por separado.
        $total_arqueo     = $usd_costo_promedio + $total_arqueo_mxn;
        $resultado_final  = $total_arqueo - $total_en_sistema;
```

- [ ] **Step 3: Agregar `usd_costo_promedio` y `total_arqueo` al array de retorno**

Usa el tool Edit con este `old_string`:

```php
        return [
            'go_exchange_dolares'  => (float) $go['go_exchange_dolares'],
            'go_exchange_mxn'      => (float) $go['go_exchange_mxn'],
            'costo_promedio'       => $costo_promedio,
            'total_fisico_dolares' => $total_fisico_dolares,
            'total_fisico_mxn'     => $total_fisico_mxn,
            'total_arqueo_mxn'     => $total_arqueo_mxn,
            'total_en_sistema'     => $total_en_sistema,
            'total_vales_dolares'  => $total_vales_dolares,
            'total_vales_mxn'      => $total_vales_mxn,
            'gran_total_vales_mxn' => $gran_total_vales_mxn,
            'diferencia_dolares'   => $diferencia_dolares,
            'diferencia_mxn'       => $diferencia_mxn,
            'resultado_final'      => $resultado_final,
        ];
    }
```

Y este `new_string`:

```php
        return [
            'go_exchange_dolares'  => (float) $go['go_exchange_dolares'],
            'go_exchange_mxn'      => (float) $go['go_exchange_mxn'],
            'costo_promedio'       => $costo_promedio,
            'total_fisico_dolares' => $total_fisico_dolares,
            'total_fisico_mxn'     => $total_fisico_mxn,
            'usd_costo_promedio'   => $usd_costo_promedio,
            'total_arqueo_mxn'     => $total_arqueo_mxn,
            'total_arqueo'         => $total_arqueo,
            'total_en_sistema'     => $total_en_sistema,
            'total_vales_dolares'  => $total_vales_dolares,
            'total_vales_mxn'      => $total_vales_mxn,
            'gran_total_vales_mxn' => $gran_total_vales_mxn,
            'diferencia_dolares'   => $diferencia_dolares,
            'diferencia_mxn'       => $diferencia_mxn,
            'resultado_final'      => $resultado_final,
        ];
    }
```

- [ ] **Step 4: Verificación aritmética manual (no hay test runner en este proyecto)**

Con lápiz/calculadora, para `costo_promedio = 18.5`, `fisico_usd = 200`, `fisico_mxn = 1000`, `vales_dolares = 100`, `vales_mxn = 50`, `go_exchange_dolares = 300`, `go_exchange_mxn = 1000`:

- `gran_total_vales_mxn` = 100×18.5 + 50 = 1900
- `usd_costo_promedio` = (200+100)×18.5 = 5550
- `total_arqueo_mxn` = 1000 + 50 = 1050
- `total_arqueo` = 5550 + 1050 = 6600
- `total_en_sistema` = 300×18.5 + 1000 = 6550
- `resultado_final` = 6600 − 6550 = 50 (sobrante)

Confirma que estos números son los esperados antes de continuar (si no, revisa el Step 2/3).

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/arqueo.php
git commit -m "fix: convierte vales en dolares a MXN con costo promedio en arqueo"
```

---

### Task 2: Corregir `recalcular()` en `arqueo.js` (cálculo en vivo, debe quedar idéntico a Task 1)

**Files:**
- Modify: `_assets/js/arqueo.js:93-175` (función `recalcular`)

**Interfaces:**
- Consumes: inputs del DOM (`.denom-input`, `.vale-dolares`, `.vale-mxn`, `#go_exchange_dolares`, `#go_exchange_mxn`, `#costo_promedio`) — sin cambio.
- Produces: escribe en los mismos elementos del DOM que ya existen en `views/arqueo/caja.html` (`total_vales_dolares`, `total_vales_mxn`, `gran_total_vales_mxn`, `r_go_usd`, `r_go_mxn`, `r_sistema`, `r_fisico_usd`, `r_fisico_mxn`, `r_usd_costo_promedio`, `r_arqueo_mxn`, `r_total_arqueo`, `r_dif_usd`, `r_dif_mxn`, `r_resultado`) — no se agregan ni quitan elementos del DOM.

- [ ] **Step 1: Reemplazar el bloque de cálculo de vales y totales**

Usa el tool Edit con este `old_string` (de `_assets/js/arqueo.js`):

```javascript
  // Vales.
  let valesUsd = 0,
    valesMxn = 0;
  document.querySelectorAll(".vale-dolares").forEach((i) => (valesUsd += parseFloat(i.value) || 0));
  document.querySelectorAll(".vale-mxn").forEach((i) => (valesMxn += parseFloat(i.value) || 0));
  const granTotalValesMxn = valesUsd + valesMxn;

  const goUsd = parseFloat(document.getElementById("go_exchange_dolares").value) || 0;
  const goMxn = parseFloat(document.getElementById("go_exchange_mxn").value) || 0;
  const costoPromedio = parseFloat(document.getElementById("costo_promedio").value) || 0;

  const usdCostoPromedio = fisicoUsd * costoPromedio;
  const arqueoMxn = fisicoMxn + valesMxn;
  const totalArqueo = usdCostoPromedio + arqueoMxn;
  const totalSistema = goUsd * costoPromedio + goMxn;
  const difUsd = fisicoUsd - goUsd;
  const difMxn = arqueoMxn - goMxn;
  const resultado = difMxn + difUsd * costoPromedio;
```

Y este `new_string`:

```javascript
  // Vales.
  let valesUsd = 0,
    valesMxn = 0;
  document.querySelectorAll(".vale-dolares").forEach((i) => (valesUsd += parseFloat(i.value) || 0));
  document.querySelectorAll(".vale-mxn").forEach((i) => (valesMxn += parseFloat(i.value) || 0));

  const goUsd = parseFloat(document.getElementById("go_exchange_dolares").value) || 0;
  const goMxn = parseFloat(document.getElementById("go_exchange_mxn").value) || 0;
  const costoPromedio = parseFloat(document.getElementById("costo_promedio").value) || 0;

  // Los vales en dólares se convierten a MXN igual que el efectivo físico
  // en dólares (multiplican por costoPromedio), no se suman 1:1.
  const granTotalValesMxn = valesUsd * costoPromedio + valesMxn;

  // "Total Dólares" del panel: físico + vales, ya convertidos.
  const usdCostoPromedio = (fisicoUsd + valesUsd) * costoPromedio;
  // "Total Moneda Nacional" del panel: físico + vales, ambos ya en MXN.
  const arqueoMxn = fisicoMxn + valesMxn;
  const totalArqueo = usdCostoPromedio + arqueoMxn;
  const totalSistema = goUsd * costoPromedio + goMxn;

  // Diferencias mostradas: informativas, solo conteo físico (sin vales).
  const difUsd = fisicoUsd - goUsd;
  const difMxn = arqueoMxn - goMxn;
  // Resultado final: se deriva de los totales ya sumados (incluyen vales en
  // USD exactamente una vez, vía usdCostoPromedio), no de difMxn + difUsd
  // por separado (evita contar el vale en USD dos veces).
  const resultado = totalArqueo - totalSistema;
```

- [ ] **Step 2: Confirmar que ningún otro punto del archivo referencia `granTotalValesMxn`, `usdCostoPromedio`, `arqueoMxn`, `totalArqueo`, `difUsd`, `difMxn` o `resultado` fuera de este bloque**

Ejecuta:

```bash
grep -n "granTotalValesMxn\|usdCostoPromedio\|arqueoMxn\|totalArqueo\|difUsd\|difMxn\b" "_assets/js/arqueo.js"
```

Todas las apariciones deben estar dentro de la función `recalcular()` (las líneas que acabas de editar más las de pintado, ej. `document.getElementById("r_usd_costo_promedio").textContent = fmtMoney(usdCostoPromedio);`). Si aparece en otro lugar, detente y revisa antes de continuar.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/arqueo.js
git commit -m "fix: convierte vales en dolares a MXN con costo promedio en calculo en vivo de arqueo"
```

---

### Task 3: Verificación manual en navegador (caja 61)

No hay test runner; esta tarea es una verificación funcional interactiva.

**Files:** ninguno (solo lectura/interacción en el navegador).

- [ ] **Step 1: Abrir `/arqueo/caja/61` en el navegador (el usuario ya tiene el servidor corriendo — no lo levantes tú)**

- [ ] **Step 2: Capturar un vale de prueba con un valor en la columna Dólares (ej. 100) y dejar Costo promedio en un valor conocido (ej. el que ya tenga la caja)**

- [ ] **Step 3: Confirmar en el panel "Resumen del arqueo" que:**
  - "Total Dólares" (bajo "Total arqueo físico (con vales)") subió en `100 × costo_promedio` respecto a antes de capturar el vale.
  - "Total" general subió en la misma cantidad.
  - "Resultado Final" se movió en esa misma cantidad (positivo o negativo según si sobra o falta).
  - La tabla de Vales, fila "Gran Total Vales (MXN)", muestra `100 × costo_promedio + mxn_del_vale` (no `100 + mxn_del_vale`).

- [ ] **Step 4: Guardar el arqueo (botón "Guardar arqueo") y confirmar que el toast de éxito aparece sin error**

- [ ] **Step 5: Recargar la página `/arqueo/caja/61` y confirmar que los mismos totales se mantienen tras recargar** (esto valida que `calcular_totales_caja()` en PHP calculó lo mismo que el JS antes de guardar).

- [ ] **Step 6: Ir a `/arqueo/concentrado/5` y confirmar que la columna "Vales Autorizados" (G) de la sucursal de la caja 61 refleja el nuevo `gran_total_vales_mxn` correctamente convertido, y que "Faltante Real de Arqueo" (H) y "Conteo Físico, Vales y Gastos" (L) se recalcularon en consecuencia**

- [ ] **Step 7: Si algo no cuadra, reportar el caso exacto (valores capturados vs. valores mostrados) antes de dar la tarea por completa — no asumir que está bien sin ver los números en pantalla**

---

## Notas para quien ejecute este plan

- El repo no tiene test framework (confirmado en `CLAUDE.md`): la validación de Task 1 y Task 2 es aritmética manual (Step 4 / Step 2 respectivamente), y Task 3 es la validación funcional real en el navegador.
- No se debe levantar ni reiniciar el servidor PHP de desarrollo — el usuario lo gestiona él mismo (preferencia registrada).
- No recalcular ni tocar datos de cajas/sesiones ya guardadas — está fuera de alcance por decisión explícita del usuario.
