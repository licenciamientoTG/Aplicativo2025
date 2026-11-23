---
target: lista de tickets (filtros + tabla)
total_score: 13
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
target_identity: "file:C:\\Users\\alfo.ochoa\\Documents\\Desarrollo\\Aplicativo2025\\views\\administration\\list_tickets.html"
target_fingerprint: "sha256:41e2fe74aeb8a3885a11e6869932c9d55a1374b203956c5c371dde7907a158ea"
target_path: "C:\\Users\\alfo.ochoa\\Documents\\Desarrollo\\Aplicativo2025\\views\\administration\\list_tickets.html"
timestamp: 2026-11-10T19-11-35Z
slug: views-administration-list-tickets-html
---
# Critique: lista de tickets (views/administration/list_tickets.html)

Score: 13/40 (Poor). Heuristics: 1 Status=1, 2 Real world=1, 3 Control=0, 4 Consistency=1, 5 Error prevention=0, 6 Recognition=2, 7 Flexibility=2, 8 Minimalist=1, 9 Error recovery=0, 10 Help=1.
Cognitive load: 7/8 checklist failures.

## Priority issues
- [P0] Borrar ticket (js:857) llama DELETE a la API de Mojo desde el navegador, sin confirmación, con access_key hardcodeada (js:859, js:2072). "Sin asignar" (js:2071) asigna en silencio al agente 4351669. Fix: endpoints server-side, rotar key, confirmación con ID+título. -> harden
- [P1] Estados de carga/error rotos: el overlay .loading no se quita en error; el mensaje culpa a los filtros; el botón refresh (.refresh) no tiene handler (.refresh_datatables_tickets); "Actualizar Tickets" no recarga la tabla; el toast de error de delete usa el response equivocado (js:877). -> harden
- [P1] Departamento: ~234 variantes de texto libre por estación; no se puede filtrar "mis estaciones" con confianza. Fix: normalizar a número de estación, optgroup por zona. -> clarify/harden
- [P2] Tabla = volcado de 21 columnas x-small, orden por GRUPO, input CC sin columna (desfasa los filtros), colReorder rompe filtros por índice, export incluye ACCIONES. Fix: ~8 columnas por defecto + colVis, status como badge, antigüedad, orden por fecha, filtros en el header. -> distill, layout, typeset
- [P2] Modelo de filtros confuso (3 niveles: servidor, columnas, buscador global) y copy inconsistente (TRESOLUCIÓN, GRUPO Cliente). Fix: "Aplicar filtros", "Sincronizar con Mojo" secundario, resumen de resultados. -> clarify

## Persona red flags
- Alex: orden por GRUPO, sin debounce en 21 inputs, sin recarga tras sincronizar, títulos truncados sin tooltip.
- Sam: 0 labels/aria-label en 3 selects + 21 inputs; botones de ícono sin nombre; overlay sin aria-busy; texto ~10px.
- Supervisor de estaciones: no puede elegir sus estaciones de forma fiable; sin antigüedad ni badges; no se dice que los cerrados están ocultos.

## Minor
type="input" en Buscar; btn-block (BS4); 21 inline margin-left; rowId 'Id' vs 'ID'; card dentro de card; tfoot duplicado; "Tickets actualizado"; toastr @latest.

## Questions
- ¿Debe ser una cola (agrupada por estación/estado, por antigüedad) en lugar de una hoja de 21 columnas?
- ¿Por qué se puede borrar en Mojo desde una lista con un clic?
- ¿Normalizar requesting_department no quita más complejidad que cualquier rediseño del filtro?
