# Conciliación Petrotal — proveedor real ↔ Petrotal (Fase 2 del plan maestro)

**Fecha:** 2026-09-01
**Estado:** Aprobado — listo para plan de implementación.

## Contexto

Este es el arranque real de la **Fase 2** del [plan maestro de pago a proveedores](2026-07-25-pago-proveedores-plan-maestro.md). Petrotal es empresa propia (razón social de TotalGas): cuando una estación compra combustible "vía Petrotal", en ControlGas queda la factura de Petrotal hacia esa estación, pero la deuda real es contra el proveedor externo (Tesoro, Premiergas, AEMSA, etc.) que le vendió a Petrotal. El objetivo es ligar ambas facturas para poder programar tanto los pagos que TotalGas hace a los proveedores reales como los que hace a Petrotal, sin que Abastos tenga que buscar la relación a mano.

Existe un intento previo (`supply.php::fuel_reconciliation` + `buscar_facturas_proveedor`/`buscar_facturas_petrotal`/`guardar_asignacion_completa`, tabla `TG.dbo.FacturasMovimientosTanques`) que **nunca se usó en producción real** — dos buscadores de texto libre sin match sugerido, y con SQL injection en `$searchTerm` (concatenado directo al query ODBC). La tabla existe con el esquema correcto pero tiene 0 filas. Ese código queda sin tocar y sin enlazar desde el sidebar; no se reutiliza.

## Validación previa (2026-09-01, con datos reales)

Antes de diseñar se validó contra la BD real y contra los 32 PDF+XML de Petrotal descargados en esta misma sesión (ver [[plan-maestro-pago-proveedores-combustible]]):

1. **Petrotal → estación destino**: el campo `Destino` extraído del PDF de Petrotal (ej. `"ESTACION TECNOLOGICO PL/9444/EXP/ES/2015"`) contiene el `PermisoCRE`, que hace match exacto contra `TG.dbo.Estaciones.PermisoCRE`. Verificado 32/32.
2. **Estación → recepción física → proveedor real**: `MovimientosTanModel::sp_obtener_recepciones_combustible` ya trae `DC.txtref` (formato `@F:<folio>@R:<remision>@V:<vehículo>`) y `DC.codopr` (proveedor de ControlGas de esa estación). El proveedor real (nombre, RFC) se resuelve sin ambigüedad desde `codopr` vía la tabla `Proveedores` de la BD de esa estación — confirmado con dos proveedores distintos en dos estaciones distintas: Tesoro (`TMS1611162N5`) en la estación Tecnológico, Premiergas (`PRE190706416`) en la estación Villa.
3. **`@R:`/`@F:` → `FacturasRecibidas` del proveedor real**: el match funciona por `Remision` (principal) con fallback a `Folio`, pero **ambos campos requieren normalización** antes de comparar como texto — el formato varía por proveedor:
   - Tesoro: `Remision` en BD y `@R:` en `txtref` coinciden exactos, sin normalizar.
   - Premiergas: `Remision` en BD trae prefijo `RP-` que `@R:` no tiene (`RP-1239754` vs `1239754`); `Folio` en BD trae ceros de relleno que `@F:` no tiene (`FE-041741` vs `FE-41741`).
   - Verificado 6/6 en Premiergas con normalización (quitar prefijo `RP-`, quitar ceros de relleno tras el prefijo alfabético del folio).
4. **Una recepción física puede generar varias facturas de cada lado**: el mismo `@R:` puede repetirse en 2 facturas del proveedor real (una por producto, ej. Máxima + Súper Premium del mismo pipazo), y Petrotal emite igualmente una factura por producto para la misma estación/día. Confirmado con el caso real `PET31506`/`PET31507` (misma estación, mismo día, productos `T-SUPER PREMIUM`/`MAXIMA`, mismo litraje `14149.83` — el litraje NO sirve para separarlas, solo el producto).
5. **Caso fuera de alcance confirmado con el usuario**: estaciones que compran solo vía Petrotal sin capturar la recepción física en ControlGas (ej. Praxedis, sin ninguna fila en `MovimientosTan` desde 2025-10-25) quedan **fuera de este diseño**. Sus facturas Petrotal no tienen recepción que conciliar por esta vía; se resuelve en otra iteración.
6. **Timing**: la factura del proveedor real suele timbrarse después de la recepción y después de la factura de Petrotal correspondiente (días de rezago). Es normal que una recepción no tenga aún su factura proveedor al momento de revisar la bandeja.

## Decisiones de producto (confirmadas con el usuario)

- La bandeja muestra **todas** las recepciones del rango (con o sin factura proveedor ya asignada), no se separan en pestañas — no hay ruido especial que ocultar, la fila simplemente muestra su estado.
- El match automático **nunca liga solo**: siempre se sugiere y Abastos confirma, con confirmación en un clic para una fila y en lote para varias exactas a la vez (mismo patrón que el resto del plan maestro).
- Relación **N a N** por recepción: varias facturas del proveedor real y varias de Petrotal pueden convivir en la misma recepción/rango: cada par confirmado es una fila independiente en `FacturasMovimientosTanques`.
- Se **reusa** la tabla `TG.dbo.FacturasMovimientosTanques` tal cual existe (sin alterar su esquema): cada fila liga 1 factura proveedor + 1 factura Petrotal; varias filas cubren la relación N a N.

## Datos y modelo

### SQL: ajuste mínimo a `sp_obtener_recepciones_combustible`

`_assets/models/MovimientosTanModel.php:4-43` ya hace `LEFT JOIN Proveedores P ON DC.codopr = P.cod` y selecciona `P.nropcc ProveedorCRE`. Se agrega `P.rfc AS ProveedorRfc` y `P.den AS ProveedorNombre` al SELECT interno del OPENQUERY — resuelve el proveedor real sin una llamada de red adicional a la estación. No se toca el resto del método (mismos otros llamadores, mismo filtro).

### Modelo nuevo: `_assets/models/PetrotalReconciliationModel.php`

```php
class PetrotalReconciliationModel extends Model {

    // Quita el prefijo "RP-" (visto en Premiergas) que txtref no trae.
    private function normalizar_remision(string $r): string {
        return preg_replace('/^RP-/i', '', trim($r));
    }

    // Quita ceros de relleno tras el prefijo alfabético ("FE-041741" -> "FE-41741").
    private function normalizar_folio(string $f): string {
        $f = trim($f);
        if (preg_match('/^([A-Za-z]*-?)0*(\d+)$/', $f, $m)) {
            return $m[1] . $m[2];
        }
        return $f;
    }

    // Normaliza nombre de producto a una palabra clave comparable entre el
    // tanque de ControlGas ("T-Super Premium") y el concepto del CFDI de
    // Petrotal ("T-SUPER PREMIUM" / "MAXIMA"): el litraje puede repetirse
    // entre productos distintos de la misma recepción, así que el producto
    // es la única llave confiable para separar varias facturas Petrotal.
    private function normalizar_producto(string $nombre): string {
        $n = strtoupper(trim($nombre));
        $n = preg_replace('/^T[\.\-]\s*/', '', $n);
        $n = preg_replace('/\s+REGULAR$/', '', $n);
        $n = str_replace('-', ' ', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    function parse_txtref(?string $txtref): ?array {
        if (!$txtref) return null;
        if (!preg_match('/@F:([^@]*)@R:([^@]*)@V:([^@]*)/', $txtref, $m)) return null;
        return ['folio' => trim($m[1]), 'remision' => trim($m[2]), 'vehiculo' => trim($m[3])];
    }

    // Busca la factura del proveedor real por Remision (principal) con
    // fallback a Folio, ambos normalizados. No asume 1 factura por RFC:
    // trae todas las del emisor y compara en PHP porque el volumen por
    // proveedor/estación es bajo (decenas, no miles, por rango consultado).
    function buscar_factura_proveedor(string $folioRef, string $remisionRef, string $emisorRfc): ?array {
        $remNorm = $this->normalizar_remision($remisionRef);
        $folioNorm = $this->normalizar_folio($folioRef);

        $query = "SELECT Id, Folio, Remision, EmisorNombre, EmisorRfc, Fecha, Total
                   FROM TG.dbo.FacturasRecibidas WHERE EmisorRfc = :emisorRfc";
        $rows = $this->sql->select($query, ['emisorRfc' => $emisorRfc]);

        foreach ($rows as $row) {
            if ($row['Remision'] && $this->normalizar_remision($row['Remision']) === $remNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_remision'];
            }
        }
        foreach ($rows as $row) {
            if ($this->normalizar_folio($row['Folio']) === $folioNorm) {
                return ['factura' => $row, 'confianza' => 'exacta_folio'];
            }
        }
        return null;
    }

    // Facturas de Petrotal candidatas para una estación/rango, cada una ya
    // con su producto (una factura Petrotal trae un solo concepto/producto,
    // confirmado en la muestra de 32 facturas). EmisorRfc de Petrotal es fijo
    // ('PET180213L66', visto en las 32 facturas de prueba).
    function buscar_facturas_petrotal(string $permisoCRE, string $fechaDesde, string $fechaHasta): array {
        $query = "
            SELECT fr.Id, fr.Folio, fr.UUID, fr.Total, fr.Fecha, fr.Destino, fr.ReceptorNombre,
                   fc.Descripcion AS Producto, fc.Cantidad AS Litros
            FROM TG.dbo.FacturasRecibidas fr
            LEFT JOIN TG.dbo.FacturasRecibidasConceptos fc ON fc.FacturaId = fr.Id
            WHERE fr.EmisorRfc = 'PET180213L66'
              AND fr.Fecha BETWEEN :fechaDesde AND :fechaHasta
              AND fr.Destino LIKE :permiso
        ";
        return $this->sql->select($query, [
            'fechaDesde' => $fechaDesde, 'fechaHasta' => $fechaHasta,
            'permiso' => '%' . $permisoCRE . '%',
        ]);
    }

    // Si ninguna candidata coincide en producto, se devuelven todas sin
    // filtrar: mejor mostrarlas para revisión manual que ocultar una
    // factura real por un nombre de producto que no matcheó.
    function filtrar_por_producto(array $facturasPetrotal, string $productoRecepcion): array {
        $prodNorm = $this->normalizar_producto($productoRecepcion);
        $filtradas = array_values(array_filter($facturasPetrotal, function ($f) use ($prodNorm) {
            return $this->normalizar_producto($f['Producto'] ?? '') === $prodNorm;
        }));
        return $filtradas ?: $facturasPetrotal;
    }

    function ya_asignada(int $facturaId): ?array {
        $query = "SELECT Id FROM TG.dbo.FacturasMovimientosTanques
                   WHERE (FacturaProveedorId = :id OR FacturaPetrotalId = :id) AND Activo = 1";
        $r = $this->sql->select($query, ['id' => $facturaId]);
        return $r[0] ?? null;
    }

    function confirmar_asignacion(int $nrotrn, int $codgas, int $facturaProveedorId, int $facturaPetrotalId, string $usuario): void {
        $query = "
            INSERT INTO TG.dbo.FacturasMovimientosTanques
                (nrotrn, codgas, TipoOperacion, FacturaProveedorId, FacturaPetrotalId,
                 FechaAsignacion, UsuarioAsignacion, Activo, Petrotal)
            VALUES (:nrotrn, :codgas, 2, :facturaProveedorId, :facturaPetrotalId,
                    GETDATE(), :usuario, 1, 1)
        ";
        $this->sql->insert($query, compact('nrotrn', 'codgas', 'facturaProveedorId', 'facturaPetrotalId', 'usuario'));
    }

    function deshacer_asignacion(int $id, string $usuario): void {
        $query = "UPDATE TG.dbo.FacturasMovimientosTanques
                   SET Activo = 0,
                       Observaciones = CONCAT(ISNULL(Observaciones,''), ' [Deshecho por ', :usuario, ' ', CONVERT(varchar, GETDATE(), 120), ']')
                   WHERE Id = :id";
        $this->sql->update($query, compact('id', 'usuario'));
    }
}
```

## Controlador: métodos nuevos en `_assets/controllers/supply.php`

| Método | Ruta | Qué hace |
|---|---|---|
| `petrotal_reconciliation()` | `/supply/petrotal_reconciliation` | Vista con selector de estación (activas, con `RFC`/`PermisoCRE` pobladas) y rango de fechas. |
| `datatables_petrotal_reconciliation()` (POST) | `/supply/datatables_petrotal_reconciliation` | Recibe `codgas`, `fecha_desde`, `fecha_hasta`. Por cada día del rango llama `sp_obtener_recepciones_combustible`; por cada recepción con `txtref` parseable, busca factura proveedor (por `Remision`/`Folio` normalizados y el RFC ya resuelto en el SP) y facturas Petrotal candidatas (por `Destino`/`PermisoCRE`, filtradas por producto). Devuelve filas armadas para DataTables, incluyendo `ya_asignada`/`asignacion_id` cuando ya existe una fila `Activo=1` para esa factura. Recepciones sin `txtref` parseable (sin `@F:`/`@R:`) se omiten — no hay nada que conciliar. |
| `confirmar_asignacion_petrotal()` (POST) | `/supply/confirmar_asignacion_petrotal` | Recibe `{pares: [{nrotrn, codgas, factura_proveedor_id, factura_petrotal_id}, ...]}` (JSON). Inserta 1 o N filas en `FacturasMovimientosTanques`. Antes de insertar valida que ninguna de las dos facturas ya esté asignada (`ya_asignada`) — si alguna ya lo está, esa fila del lote se omite y se reporta en la respuesta, no se aborta el lote completo. |
| `deshacer_asignacion_petrotal()` (POST) | `/supply/deshacer_asignacion_petrotal` | Recibe `id` de `FacturasMovimientosTanques`. Marca `Activo=0` (soft, mismo patrón que remisiones/soft-delete del proyecto). |

Los 4 endpoints viejos (`fuel_reconciliation`, `buscar_facturas_proveedor`, `buscar_facturas_petrotal`, `guardar_asignacion_completa`) **no se tocan ni se borran** — quedan sin enlazar desde el sidebar ni desde ninguna vista nueva.

## Vista: `views/supply/petrotal_reconciliation.html`

Mismo patrón que `views/station_portal/mis_recepciones.html` (fecha desde/hasta + botón Buscar, sin autocarga; DataTable inicializado en el primer clic):

- Selector de estación (`selectpicker`, todas las estaciones activas — no se restringe de antemano a "las que compran vía Petrotal", eso lo revela la tabla vacía si no aplica).
- Rango desde/hasta (default: últimos 7 días).
- Botón "Buscar" y botón "Confirmar seleccionadas" (deshabilitado hasta que haya checkboxes marcados).
- Tabla: checkbox (solo en filas con match exacto) | Fecha | Producto | Litros | Factura proveedor (nombre + folio + total, o badge "Sin factura aún") | Factura(s) Petrotal (folio + total por cada una) | Confianza (badge) | Estado (Confirmada/Pendiente) | Acciones (botón Confirmar individual, selector manual si hay ambigüedad de producto, o botón Deshacer si ya está confirmada).

## JS: `_assets/js/petrotal_reconciliation.js`

Mismo esqueleto que `station_portal.js` (DataTable con `ajax.data` leyendo los filtros, `beforeSend`/`complete` con clase `loading`, `deferRender: true`).

Render de la columna de acciones, por caso:

- **Ya asignada:** botón "Deshacer" (`data-id` = `asignacion_id`).
- **Sin factura proveedor o sin ninguna factura Petrotal candidata:** sin acción, solo se ve el estado.
- **Exactamente 1 factura Petrotal candidata (tras filtrar por producto):** botón "Confirmar" directo con ambos IDs en `data-*`.
- **2+ facturas Petrotal candidatas del mismo producto** (ej. dos entregas el mismo día): `<select>` con las opciones + botón "Confirmar" que lee el `<select>` al hacer clic — no se adivina, el usuario elige.

Checkbox de fila (para confirmación en lote): solo se renderiza cuando hay match de **confianza exacta** (`exacta_remision`/`exacta_folio`) y exactamente 1 factura Petrotal candidata — el caso ambiguo (selector manual) nunca entra al lote, se confirma solo por fila.

`btnConfirmarLote` reúne los pares marcados y llama al mismo endpoint `confirmar_asignacion_petrotal` con el array completo — mismo endpoint para 1 o N confirmaciones.

## Errores y validaciones

- `codgas` vacío al buscar: error visible en cliente antes de llamar al backend (`alertify.myAlert`), igual que el rango de fechas invertido.
- Backend: `datatables_petrotal_reconciliation` sin `codgas` devuelve `data: []` con mensaje, sin tocar la red de la estación.
- `confirmar_asignacion_petrotal`: si una factura del lote ya fue asignada por otro usuario entre que se cargó la tabla y se confirmó, esa fila se omite (no rompe el resto del lote) y la respuesta lo reporta; el frontend recarga la tabla para reflejar el estado real.
- Recepción con `txtref` no parseable (remisión capturada a mano sin el formato `@F:/@R:/@V:`, o vacía): se omite de la bandeja — no hay match posible sin esos datos.

## Fuera de alcance (explícito)

- Estaciones sin recepción capturada en `MovimientosTan` (ej. Praxedis) — no aparecen en el selector de forma especial, simplemente su bandeja sale vacía porque no hay recepciones que conciliar. Se resuelve en otra iteración ("otro sistema", pendiente de definir cómo importar esa información).
- Alimentar `payment_requests` automáticamente desde una asignación confirmada — esta fase solo liga las facturas; la creación de la requisición de pago sigue el flujo existente de `payment.php` sobre la factura ya ligada del proveedor real.
- Notificaciones de recepciones con más de N días esperando su factura proveedor (alertas de rezago) — se deja para Fase 4 del plan maestro (cierre de ciclo).

## Testing

Sin framework de tests en el proyecto (confirmado en `CLAUDE.md`). Verificación manual:
- Contra la estación Tecnológico (`codgas=22`) y Villa (`codgas=31`), rango de fechas que cubra las recepciones ya validadas en esta sesión (27-28 agosto para Tecnológico, 22-27 agosto para Villa): confirmar que la bandeja muestra las facturas proveedor/Petrotal correctas con confianza `exacta_remision` o `exacta_folio`.
- Confirmar una fila individual y verificar que se inserta en `FacturasMovimientosTanques` con `TipoOperacion=2`, `Activo=1`, y que la fila pasa a estado "Confirmada" sin recargar la página completa.
- Confirmar en lote 2+ filas exactas a la vez.
- Deshacer una asignación y confirmar que vuelve a aparecer como "Pendiente" (el registro no se borra, `Activo=0`).
- Probar con la recepción de Tecnológico que tiene 2 facturas Petrotal del mismo día (T-Super Premium / Maxima) y confirmar que cada fila de recepción (una por producto) solo ofrece la factura Petrotal de su propio producto.
- `php -l` sobre los archivos tocados.
