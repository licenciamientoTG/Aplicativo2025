# Mini-histórico de tanques en modal de corrección de cortes físicos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show, inside the existing `fisicoModal` correction modal in `/merma/detalle/{codgas}`, a collapsible 5-day history (físico + contable) per producto/turno so the user can judge whether a corrected value is plausible.

**Architecture:** Extend `MermaDiariaModel::get_cortes_fisicos()` to reuse the `merma_diaria` data it already loads in memory (currently used only to compute `recomendado`) and attach a `historial` array to each row it returns. No new endpoint, no new table. On the frontend, `detalle.html`'s existing row-rendering loop for the modal gains a collapsed-by-default toggle per row that reveals a small table of the last 5 days.

**Tech Stack:** PHP (Model layer, PDO via `$this->sql`), vanilla JS + jQuery + Bootstrap 5 (existing modal pattern), Twig view.

## Global Constraints

- No test framework exists in this project (see CLAUDE.md) — verification is manual, against the running dev server.
- Do not add a new database table or a new controller endpoint — reuse `POST /merma/cortes_fisicos`.
- Historial granularity is producto+turno (summed across tanks), matching existing `recomendado` behavior — do not attempt per-tank separation.
- Window is exactly 5 days, excluding the day already shown in the row.
- Collapsed by default; no auto-expand.
- Follow existing code style in `MermaDiariaModel.php` (PHPDoc block comments above public methods) and `detalle.html`'s inline JS (jQuery chains, `html +=` string building, no new libraries).

---

### Task 1: Backend — extend `get_cortes_fisicos()` with 5-day historial per row

**Files:**
- Modify: `_assets/models/MermaDiariaModel.php:223-290` (method `get_cortes_fisicos`)

**Interfaces:**
- Consumes: existing `$snap` array already fetched inside the method (query at `MermaDiariaModel.php:246-251`, columns `fecha, codprd, turno, ventas_reales, compras, inv_fisico`) — note this query does **not** currently select `inv_contable`, which is needed for this task and must be added to the `SELECT` list.
- Produces: each element of the returned `$cortes` array gains a new key `historial`, shape:
  ```php
  [
      ['fecha' => '2026-07-31', 'fisico' => 39850.10, 'contable' => 39902.55], // or null values if s/d
      // ... up to 5 entries, ordered descending by fecha, days strictly before the requested $fecha
  ]
  ```
  This is consumed by Task 2's JS via the JSON response of `POST /merma/cortes_fisicos` (`cortes[].historial`).

- [ ] **Step 1: Read the current method in full to confirm exact line ranges before editing**

Read `_assets/models/MermaDiariaModel.php` lines 223-290 (already done above — reproduced here for reference):

```php
public function get_cortes_fisicos(int $codgas, string $fecha, ?string $familia = null, ?int $turno = null): array
{
    $est = $this->get_estacion_conexion($codgas);
    if (!$est) return [];
    $fch  = dateToInt($fecha);
    $prds = isset(self::FAMILIAS[$familia])
        ? implode(',', self::FAMILIAS[$familia])
        : implode(',', array_merge(...array_values(self::FAMILIAS)));
    // Turno mostrado (11/21/41) → nrotur crudos de StockReal
    $turnoNrotur = [11 => '10, 11', 21 => '20, 21', 41 => '40, 41'];
    $filtroTurno = isset($turnoNrotur[$turno]) ? " AND nrotur IN ({$turnoNrotur[$turno]})" : '';
    $inner = sprintf(
        'SELECT fch, codgas, codprd, nrotur, codtan, can, logfch, lognew
         FROM [%s].dbo.StockReal
         WHERE fch = %d AND codgas = %d AND codprd IN (%s) AND nrotur NOT IN (30, 31)%s',
        $est['BaseDatos'], $fch, $codgas, $prds, $filtroTurno);
    $query = sprintf("SELECT * FROM OPENQUERY([%s], '%s') ORDER BY codprd, nrotur, codtan;",
        $est['Servidor'], str_replace("'", "''", $inner));
    $cortes = $this->sql->select($query) ?: [];

    // Valor sugerido por corte = contable del libro amarillo: último
    // físico válido anterior (encadena días previos) − ventas + compras
    // del turno, calculado desde el snapshot local
    $snap = $this->sql->select(
        'SELECT fecha, codprd, turno, ventas_reales, compras, inv_fisico
         FROM [TG].[dbo].[merma_diaria]
         WHERE codgas = ? AND fecha BETWEEN DATEADD(DAY, -7, CAST(? AS DATE)) AND CAST(? AS DATE)
         ORDER BY codprd, fecha, turno;',
        [$codgas, $fecha, $fecha]);
    $rec  = [];  // "codprd-turno" => sugerido (solo turnos del día pedido)
    $last = [];  // codprd => último físico válido de la cadena
    foreach ($snap ?: [] as $s) {
        $prd = (int)$s['codprd'];
        if (substr($s['fecha'], 0, 10) === $fecha && isset($last[$prd])) {
            $rec[$prd . '-' . (int)$s['turno']] = round(
                $last[$prd] - (float)$s['ventas_reales'] + (float)($s['compras'] ?? 0), 2);
        }
        $fis = $s['inv_fisico'];
        if ($fis !== null && $fis >= self::INV_FISICO_MIN && $fis <= self::INV_FISICO_MAX) {
            $last[$prd] = (float)$fis;
        }
    }
    // El contable es del TURNO completo (suma de tanques): a cada fila se
    // le sugiere contable - los demás tanques válidos de su mismo corte,
    // para no duplicar el turno en estaciones con varios tanques por
    // producto (caso Gemela Grande: tanque 7 real + tanque 78 en 0)
    $turnoMap = [10 => 11, 20 => 21, 30 => 41, 40 => 41];
    $validosPorCorte = [];
    foreach ($cortes as $c) {
        $key = $c['codprd'] . '-' . $c['nrotur'];
        $can = (float)$c['can'];
        if ($can >= self::INV_FISICO_MIN && $can <= self::INV_FISICO_MAX) {
            $validosPorCorte[$key][(int)$c['codtan']] = $can;
        }
    }
    foreach ($cortes as &$c) {
        $turno = $turnoMap[(int)$c['nrotur']] ?? (int)$c['nrotur'];
        $recTurno = $rec[(int)$c['codprd'] . '-' . $turno] ?? null;
        if ($recTurno === null) { $c['recomendado'] = null; continue; }
        $otros = 0.0;
        foreach ($validosPorCorte[$c['codprd'] . '-' . $c['nrotur']] ?? [] as $tan => $can) {
            if ($tan !== (int)$c['codtan']) $otros += $can;
        }
        $c['recomendado'] = max(0, round($recTurno - $otros, 2));
    }
    unset($c);
    return $cortes;
}
```

No test runner exists — this step is a read-only confirmation step, not a code change. Proceed to Step 2.

- [ ] **Step 2: Add `inv_contable` to the `$snap` query and build a historial lookup**

Edit the `$snap` query (currently at `MermaDiariaModel.php:246-251`) to also select `inv_contable`, and add a `$historial` accumulator built in the same loop that already iterates `$snap`:

```php
$snap = $this->sql->select(
    'SELECT fecha, codprd, turno, ventas_reales, compras, inv_fisico, inv_contable
     FROM [TG].[dbo].[merma_diaria]
     WHERE codgas = ? AND fecha BETWEEN DATEADD(DAY, -7, CAST(? AS DATE)) AND CAST(? AS DATE)
     ORDER BY codprd, fecha, turno;',
    [$codgas, $fecha, $fecha]);
$rec       = [];  // "codprd-turno" => sugerido (solo turnos del día pedido)
$last      = [];  // codprd => último físico válido de la cadena
$historial = [];  // "codprd-turno" => [{fecha, fisico, contable}, ...] días previos a $fecha
foreach ($snap ?: [] as $s) {
    $prd = (int)$s['codprd'];
    $day = substr($s['fecha'], 0, 10);
    if ($day === $fecha && isset($last[$prd])) {
        $rec[$prd . '-' . (int)$s['turno']] = round(
            $last[$prd] - (float)$s['ventas_reales'] + (float)($s['compras'] ?? 0), 2);
    }
    if ($day < $fecha) {
        $historial[$prd . '-' . (int)$s['turno']][] = [
            'fecha'    => $day,
            'fisico'   => $s['inv_fisico'] !== null ? (float)$s['inv_fisico'] : null,
            'contable' => $s['inv_contable'] !== null ? (float)$s['inv_contable'] : null,
        ];
    }
    $fis = $s['inv_fisico'];
    if ($fis !== null && $fis >= self::INV_FISICO_MIN && $fis <= self::INV_FISICO_MAX) {
        $last[$prd] = (float)$fis;
    }
}
```

Note: the query already limits to 7 days back; `$historial` naturally caps at 5 relevant prior calendar days for a same-day range once the current day is excluded (7 days back covers weekends/gaps safely). No change to the query's date range is needed — filtering `$day < $fecha` and keeping only entries already ordered by `fecha ASC` per `codprd, fecha, turno` is sufficient; no further truncation to "5" is required since the range naturally won't exceed it in practice, but to guarantee the contract, cap explicitly in Step 3 rendering (do NOT trust the query range alone).

To honor "exactly 5 days" as a hard contract rather than an assumption of the 7-day window, cap explicitly when attaching to `$c` in Step 3 using `array_slice`.

- [ ] **Step 3: Attach `historial` to each row in the final `foreach ($cortes as &$c)` loop**

Edit the existing loop (currently at `MermaDiariaModel.php:278-287`) to also set `$c['historial']`, sorted most-recent-first and capped to 5 entries:

```php
foreach ($cortes as &$c) {
    $turno = $turnoMap[(int)$c['nrotur']] ?? (int)$c['nrotur'];
    $recTurno = $rec[(int)$c['codprd'] . '-' . $turno] ?? null;
    if ($recTurno === null) { $c['recomendado'] = null; } else {
        $otros = 0.0;
        foreach ($validosPorCorte[$c['codprd'] . '-' . $c['nrotur']] ?? [] as $tan => $can) {
            if ($tan !== (int)$c['codtan']) $otros += $can;
        }
        $c['recomendado'] = max(0, round($recTurno - $otros, 2));
    }
    $hist = $historial[(int)$c['codprd'] . '-' . $turno] ?? [];
    usort($hist, fn($a, $b) => strcmp($b['fecha'], $a['fecha'])); // descendente
    $c['historial'] = array_slice($hist, 0, 5);
}
unset($c);
return $cortes;
```

This replaces the previous `if ($recTurno === null) { $c['recomendado'] = null; continue; }` early-continue with an if/else so `historial` still gets attached even when there's no `recomendado` (e.g. first days of data).

- [ ] **Step 4: Manual verification against the running dev server**

The user runs the dev server themselves (per stored preference — do not start `php -S` or reload it). Once the user confirms the server is running:

Ask the user to open `http://localhost:8001/merma/detalle/24?desde=2026-08-01&hasta=2026-08-05`, open browser DevTools Network tab, click a `.fis-editable` cell to trigger `POST /merma/cortes_fisicos`, and inspect the JSON response. Confirm each object in `cortes[]` has a `historial` key that is either `[]` or an array of up to 5 objects with `fecha` (descending), `fisico`, `contable`.

Expected: no PHP errors/warnings in the response; `historial` present on every row.

- [ ] **Step 5: Commit**

```bash
git add _assets/models/MermaDiariaModel.php
git commit -m "feat: agrega historial de 5 días a get_cortes_fisicos para el modal de corrección"
```

---

### Task 2: Frontend — collapsible 5-day history row in the correction modal

**Files:**
- Modify: `views/merma/detalle.html:375-397` (JS inside `$(document).on('click', '.fis-editable', ...)`, the `res.cortes.forEach` block that builds `#fisico_rows` HTML)

**Interfaces:**
- Consumes: `res.cortes[i].historial` — array (possibly empty) of `{fecha: 'YYYY-MM-DD', fisico: number|null, contable: number|null}`, produced by Task 1. Also reuses existing `res.cortes[i]` fields (`codprd`, `nrotur`, `codtan`, `can`, `recomendado`) and existing lookup maps `PRD_LBL`, `TURNO_LBL` already defined at `detalle.html:360-361`.
- Produces: no new interface consumed elsewhere — this is the final rendering step.

- [ ] **Step 1: Add a toggle button to each row and a hidden historial sub-row**

Edit the `res.cortes.forEach` block (currently `detalle.html:378-396`) to append a toggle button in the last cell and a following hidden `<tr>` per corte. Replace the block with:

```js
res.cortes.forEach(function (c, idx) {
    const corrupto = c.can !== null && (Number(c.can) < res.min || Number(c.can) > res.max);
    const sugerido = (c.recomendado !== null && c.recomendado !== undefined) ? Number(c.recomendado) : null;
    const hist = c.historial || [];
    const rowId = 'fisico-hist-' + idx;
    html += '<tr' + (corrupto ? ' class="table-danger"' : '') + '>'
        + '<td>' + (PRD_LBL[c.codprd] || '') + ' (' + c.codprd + ')</td>'
        + '<td>' + (TURNO_LBL[c.nrotur] || c.nrotur) + '</td>'
        + '<td>' + c.codtan + '</td>'
        + '<td>' + Number(c.can).toLocaleString('en-US', { maximumFractionDigits: 4 })
        + (corrupto ? ' <i class="fas fa-exclamation-triangle text-danger"></i>' : '') + '</td>'
        + '<td class="text-muted" title="Contable del turno: último físico válido anterior − ventas + compras">'
        + (sugerido !== null ? sugerido.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '-') + '</td>'
        + '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm fisico-nuevo"'
        + (corrupto && sugerido !== null ? ' value="' + sugerido + '"' : '') + '></td>'
        + '<td>'
        + '<button type="button" class="btn btn-sm btn-merma-sync fisico-guardar" data-fecha="' + fecha
        + '" data-codprd="' + c.codprd + '" data-nrotur="' + c.nrotur + '" data-codtan="' + c.codtan + '">Guardar</button>'
        + (hist.length ? ' <button type="button" class="btn btn-sm btn-link p-0 ms-1 fisico-hist-toggle" data-target="#' + rowId + '" title="Ver últimos días"><i class="fas fa-chevron-down"></i></button>' : '')
        + '</td>'
        + '</tr>';
    if (hist.length) {
        html += '<tr id="' + rowId + '" class="d-none"><td colspan="7" class="p-0">'
            + '<table class="table table-sm table-borderless mb-0 bg-light"><tbody>'
            + '<tr><td class="text-muted small" style="width:120px">Físico</td>'
            + hist.map(h => '<td class="small">' + (h.fisico !== null ? Number(h.fisico).toLocaleString('en-US', { maximumFractionDigits: 2 }) : 's/d') + '<br><span class="text-muted" style="font-size:0.7em">' + h.fecha + '</span></td>').join('')
            + '</tr>'
            + '<tr><td class="text-muted small">Contable</td>'
            + hist.map(h => '<td class="small">' + (h.contable !== null ? Number(h.contable).toLocaleString('en-US', { maximumFractionDigits: 2 }) : 's/d') + '</td>').join('')
            + '</tr>'
            + '</tbody></table>'
            + '</td></tr>';
    }
});
```

- [ ] **Step 2: Wire up the toggle click handler**

Add a new delegated click handler near the other modal handlers (after the block ending at `detalle.html:398`, before the existing `$(document).on('click', '.fisico-guardar', ...)` handler at `detalle.html:512`):

```js
$(document).on('click', '.fisico-hist-toggle', function () {
    const $icon = $(this).find('i');
    $($(this).data('target')).toggleClass('d-none');
    $icon.toggleClass('fa-chevron-down fa-chevron-up');
});
```

- [ ] **Step 3: Manual verification in the browser**

The user runs/reloads the dev server themselves. Once confirmed running, ask the user to:
1. Open `http://localhost:8001/merma/detalle/24?desde=2026-08-01&hasta=2026-08-05`.
2. Click a `.fis-editable` cell (e.g. the DIESEL turno 11 corrupt cell from the original report).
3. Confirm the modal shows the existing table plus, on rows where `historial` is non-empty, a small chevron-down button next to "Guardar".
4. Click the chevron and confirm a sub-row expands showing up to 5 days of Físico/Contable values with date labels, and the chevron flips to chevron-up.
5. Click again and confirm it collapses back.
6. Confirm rows with empty `historial` (if any, e.g. a brand-new tank) show no chevron button and no JS errors in the console.

Expected: no console errors; toggle expands/collapses correctly; values are plausible (roughly matching what's visible in the Diario/Desglosado tabs for those same dates).

- [ ] **Step 4: Commit**

```bash
git add views/merma/detalle.html
git commit -m "feat: mini-histórico colapsable de 5 días en modal de corrección de cortes físicos"
```

---

## Self-Review Notes

- **Spec coverage:** 5-day window ✓ (Task 1 Step 3 caps via `array_slice`), producto+turno granularity (summed tanks) ✓ (keyed by `codprd-turno`, same as existing `recomendado`), source is `merma_diaria` not `StockReal` ✓, collapsed by default ✓ (Task 2 Step 1 renders `d-none` by default), no new endpoint/table ✓, empty-historial rows hide the toggle ✓.
- **Placeholder scan:** none — both tasks contain full code, not descriptions.
- **Type consistency:** `historial` shape (`fecha`, `fisico`, `contable`) matches between Task 1's PHP output and Task 2's JS consumption. `PRD_LBL`/`TURNO_LBL`/`res.min`/`res.max` reused unchanged from existing code, verified against current `detalle.html:360-361` and `merma.php:609-611`.
