# Sync mensual del cron de merma + fecha de última actualización en /merma/analisis

Fecha: 2026-08-04

## Contexto

`/merma/analisis` (resumen mensual de merma diaria) tiene un botón "Actualizar
datos" que dispara `Merma::sync()` para un rango elegido a mano. Además ya
existe `cron/merma_sync_diario.php`, programado por el usuario en el
Programador de Tareas de Windows a las 5:00 am, que llama a
`Merma::sync_diario()` y sincroniza únicamente **D-2 y D-1** (ayer y
antier) de todas las estaciones.

Se pide:

1. Mostrar junto al botón "Actualizar datos" la fecha/hora de la última
   sincronización exitosa, para que el usuario sepa si los datos están al
   día sin tener que abrir el modal.
2. Ampliar el rango que cubre el cron diario: en vez de solo D-2/D-1,
   debe cubrir **todo el mes en curso hasta ayer** (día 1 del mes → ayer),
   para que un fallo puntual de un día no deje huecos permanentes en el
   mes — el día siguiente el cron los vuelve a cubrir. El usuario
   reprogramará la tarea de Windows para correr a las 6:00 am con el mismo
   comando (`php cron/merma_sync_diario.php`); no se requiere automatizar
   `schtasks` desde este cambio.

No se toca `Merma::sync()` (botón manual con rango elegido por el usuario)
ni el tope de 40 días de ese endpoint — ese límite es del endpoint HTTP,
`sync_diario()` llama a `runSync()` directamente y no pasa por ese chequeo.

## Diseño

### 1. Modelo — `MermaDiariaModel::get_ultimo_sync_ok()`

Nuevo método, mismo patrón que el resto de queries del modelo:

```php
public function get_ultimo_sync_ok(): ?array
{
    $rows = $this->sql->select(
        'SELECT TOP 1 fecha_sync, origen
         FROM [TG].[dbo].[merma_sync_log]
         WHERE estaciones_ok > 0
         ORDER BY id DESC;'
    );
    return $rows ? $rows[0] : null;
}
```

`estaciones_ok > 0` excluye los intentos que fallaron por completo (ApiER
caído, respuesta inesperada) — esos ya quedan registrados en el log para
diagnóstico, pero no deben mostrarse como "última actualización" porque no
trajeron datos nuevos.

### 2. Controlador — `Merma::analisis()`

Al final del método, antes del `compact(...)` que arma el contexto:

```php
$ultimoSync = $this->mermaModel->get_ultimo_sync_ok();
```

Se agrega `ultimoSync` al `compact(...)` existente.

### 3. Vista — `views/merma/analisis.html`

Dentro del mismo `<div class="col-auto ms-auto">` que ya contiene el botón
"Actualizar datos", debajo del botón, un texto pequeño:

```twig
<div class="col-auto ms-auto text-end">
    <button type="button" class="btn btn-merma-sync" data-bs-toggle="modal" data-bs-target="#syncModal">
        <i class="fas fa-sync"></i> Actualizar datos
    </button>
    <div class="small text-muted mt-1">
        {% if ultimoSync %}
            Última actualización: {{ ultimoSync.fecha_sync|date('d-m-Y H:i') }}
        {% else %}
            Sin sincronizar
        {% endif %}
    </div>
</div>
```

Mismo filtro `|date(...)` de Twig que ya usa `detalle.html` — sin
dependencias nuevas.

### 4. Cron — `cron/merma_sync_diario.php` + `Merma::sync_diario()`

Se reemplaza el rango fijo D-2/D-1 por "día 1 del mes en curso → ayer":

```php
$desde = date('Y-m-01');
$hasta = date('Y-m-d', strtotime('-1 day'));
```

Esto vive en `Merma::sync_diario()` (el controlador), no en el script CLI,
que solo invoca el método — así el cambio de rango aplica igual si algún
día se dispara por otra vía con el token de cron.

Casos de borde:
- Si hoy es día 1 del mes, `hasta` (ayer) cae en el mes anterior y
  `$desde > $hasta`. `runSync()`/`replace_station_range` no validan
  desde ≤ hasta como sí lo hace `sync()`, así que hay que guardar el caso:
  si `$hasta < $desde`, usar como `$desde` el día 1 del mes de `$hasta`
  (es decir, sincronizar solo ayer, que cae en el mes previo). Mismo
  criterio que ya usa `analisis()` para acotar rangos.
- Rango de hasta 31 días — sin problema de performance distinto al de hoy
  (ya se sincronizan rangos de mes completo desde la UI).

Se actualiza el comentario de cabecera del script y `docs/sql/merma_cron.md`
para reflejar el nuevo rango y el horario 6:00 am (el usuario reprograma la
tarea de Windows manualmente; no se genera ni ejecuta `schtasks` desde
aquí).

## Fuera de alcance

- No se cambia `Merma::sync()` (botón manual) ni su tope de 40 días.
- No se automatiza la creación/edición de la tarea programada de Windows.
- No se agrega un endpoint nuevo — se reutiliza `sync_diario()`.
