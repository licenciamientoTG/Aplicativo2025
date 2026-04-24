# Diseño: Tracking de Visitas y Dashboard de Uso

**Fecha:** 2026-04-23  
**Objetivo:** Saber qué páginas del sistema son más usadas para informar el rediseño con un framework moderno.

---

## 1. Base de Datos

**Tabla:** `[TG].[dbo].[tg_page_visits]`

```sql
CREATE TABLE [tg_page_visits] (
    id           BIGINT IDENTITY PRIMARY KEY,
    user_id      INT          NOT NULL,
    username     VARCHAR(100) NOT NULL,
    controller   VARCHAR(100) NOT NULL,
    method       VARCHAR(100) NOT NULL,
    visit_date   DATE         NOT NULL DEFAULT CAST(GETDATE() AS DATE),
    visit_count  INT          NOT NULL DEFAULT 1,
    CONSTRAINT uq_visit UNIQUE (user_id, controller, method, visit_date)
);
```

**Lógica UPSERT:** SQL Server `MERGE` — si existe el registro `(user_id, controller, method, visit_date)` se incrementa `visit_count`; si no existe, se inserta con `visit_count = 1`.

---

## 2. Tracking Automático — `TgTwig`

**Archivo nuevo:** `_assets/classes/TgTwig.class.php`

Subclase de `Twig\Environment` que sobreescribe `render()`. Cuando cualquier controlador llame a `$this->twig->render(...)`, el tracking ocurre automáticamente sin modificar ningún controlador.

**Solo se registran visitas cuando:**
- Hay una sesión activa (`$_SESSION['tg_user']`)
- Se llama a `twig->render()` — garantiza que solo son páginas HTML, no AJAX

**Cambio en `twig_functions.php`:** reemplazar `new \Twig\Environment(...)` por `new TgTwig(...)`.

---

## 3. Modelo — `PageVisitsModel`

**Archivo nuevo:** `_assets/models/PageVisitsModel.php`

Métodos:
- `static upsertVisit()` — ejecuta el MERGE, llamado desde `TgTwig::render()`
- `getTopPages($from, $to)` — `SUM(visit_count)` agrupado por controller/method
- `getTopUsers($from, $to)` — `SUM(visit_count)` agrupado por usuario
- `getPagesReach($from, $to)` — `COUNT(DISTINCT user_id)` por página
- `getUnusedInPeriod($from, $to)` — páginas con historial pero 0 visitas en el período

---

## 4. Controlador — método en `It`

**Archivo modificado:** `_assets/controllers/it.php`

Método nuevo: `page_visits_dashboard()`
- Acceso restringido: solo `for_sistemas()` (IDs: 6382, 6371, 6177, 6296, 6274)
- Lee `$_GET['from']` y `$_GET['to']` con default = últimos 30 días
- Pasa los 4 datasets al template Twig

---

## 5. Vista — Dashboard

**Archivo nuevo:** `views/it/page_visits_dashboard.html`

4 paneles en el estilo Bootstrap existente del proyecto:

| Panel | Fuente | Métrica principal |
|-------|--------|-------------------|
| Top páginas más visitadas | `getTopPages()` | Total visitas (SUM) |
| Usuarios más activos | `getTopUsers()` | Total visitas (SUM) |
| Páginas con más alcance | `getPagesReach()` | Usuarios únicos (COUNT DISTINCT) |
| Páginas sin uso en el período | `getUnusedInPeriod()` | Días desde última visita |

Filtro de fechas: `<input type="date">` con rango, recarga la página via GET. Por defecto: últimos 30 días.

---

## 6. Sidebar

**Archivo modificado:** `views/layouts/sidebar.html`

Bajo `SISTEMAS → Herramientas`, dentro del bloque `{% if for_sistemas() %}` existente:

```html
<li class="sidebar-item">
    <a class="sidebar-link" href="/it/page_visits_dashboard">Dashboard de uso</a>
</li>
```

---

## Flujo completo

```
Usuario navega → index.php resuelve controller/method
  → instancia controlador → llama método
  → método llama $twig->render()
  → TgTwig::render() ejecuta MERGE en [tg_page_visits]
  → devuelve HTML normal
```

---

## Restricciones y decisiones

- **Páginas sin uso:** definidas como "páginas que alguna vez fueron visitadas pero no en el período seleccionado" — 100% dinámico, sin lista estática. Una página nueva aparecerá en los datos una vez que alguien la visite por primera vez.
- **AJAX excluido:** el tracking ocurre en `twig->render()`, por lo que métodos que retornan JSON nunca generan registros.
- **Silencioso ante errores:** si el INSERT/MERGE falla (BD caída, etc.), se captura la excepción y la página sigue cargando normalmente.
- **Sin JS adicional:** el filtro de fechas usa GET estándar, sin dependencias nuevas.
