# Registrar Pago con lectura de comprobantes y asignación por factura - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar el modal "Registrar Pago" (tab Facturas Autorizadas) para subir 1+ comprobantes PDF que el sistema lee automáticamente (fecha/referencia/importe), asignar facturas completas a cada comprobante descontando de su importe hasta cero, y guardar reutilizando el endpoint `conciliar_comprobantes`.

**Architecture:** Solo frontend. Se añade un modal Bootstrap nuevo (`#modalRegistroPagoNuevo`) en la vista y se reescribe `mostrarModalRegistroPago()` en `payment.js` para abrirlo y orquestar: cargar facturas vía `/payment/get_invoices_detail`, leer cada PDF vía `/payment/preview_comprobantes_match` (un archivo por vez, ignorando el match), gestionar la asignación factura→comprobante en memoria, y guardar vía `/payment/conciliar_comprobantes`. No se toca PHP.

**Tech Stack:** PHP MVC (sin tocar), Twig, jQuery + Bootstrap 5 modal + alertify.js. Sin framework de tests — verificación con `node --check` y prueba manual en navegador.

## Global Constraints

- No modificar ningún endpoint/método PHP: `execute_authorized_payments`, `conciliar_comprobantes`, `preview_comprobantes_match`, `get_invoices_detail` y el parser quedan intactos.
- Reutilizar `/payment/preview_comprobantes_match` para leer cada PDF (enviar un archivo en `comprobantes[]`, usar solo `comprobantes[0].comprobante`).
- Reutilizar `/payment/conciliar_comprobantes` para guardar: `FormData` con todos los PDFs en `comprobantes[]` (orden = `archivo_idx`) + `asignaciones` JSON, cada entrada `{ archivo_idx, archivo, invoice_ids, fecha_pago, referencia, observaciones }`.
- Asignación a nivel de factura COMPLETA (el backend paga cada factura por su `authorized_amount`; no hay pago parcial). El descuento resta `authorized_amount` de cada factura asignada al importe leído del comprobante.
- Diferencia importe leído vs suma asignada: avisar en rojo, NO bloquear.
- Permiso 68 (Tesorería) ya se valida en backend; el tab solo es visible para 68.
- `mostrarModalRegistroPago(grupos, banco)` es el único punto de entrada y lo usan DOS llamadores que NO deben romperse: `abrirModalRegistroPago()` (botón "Registrar Pago" masivo) y `pagarGrupoIndividual()` (botón "Pagar" por fila).

---

### Task 1: Modal HTML `#modalRegistroPagoNuevo` en la vista

**Files:**
- Modify: `views/payment/payment_list.html` (insertar el nuevo modal junto a los demás modales, justo después del cierre de `#modalComprobantes`, alrededor de la línea 1014, antes de `{% endblock %}`)

**Interfaces:**
- Produces: estructura DOM con estos IDs que Task 2 y 3 consumen: `#modalRegistroPagoNuevo`, `#regPagoHeaderResumen`, `#regPagoDropzone`, `#regPagoInput`, `#regPagoComprobantesCards`, `#regPagoFacturasBody` (tbody de la tabla de facturas), `#regPagoSinComprobante` (aviso cuando no hay comprobante activo), `#btnGuardarRegistroPago`, `#regPagoResumenSel`.

- [ ] **Step 1: Insertar el modal en la vista**

Abrir `views/payment/payment_list.html`. Localizar el cierre del modal de comprobantes (la línea `</div>` que cierra `#modalComprobantes`, justo antes de `{% endblock %}` en la línea ~1014). Insertar INMEDIATAMENTE DESPUÉS de ese `</div>` de cierre del modal y ANTES de `{% endblock %}`:

```html
    <!-- Modal Registrar Pago (multi-comprobante con lectura de PDF) -->
    <div class="modal fade" id="modalRegistroPagoNuevo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:#16a34a;color:#fff;">
                    <h5 class="modal-title"><i class="fas fa-check-circle"></i> Registrar Pago Ejecutado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Header resumen (banco / empresa / total) -->
                    <div id="regPagoHeaderResumen" class="mb-3"></div>

                    <!-- Zona de carga de comprobantes -->
                    <div id="regPagoDropzone" style="border:2px dashed #6ee7b7;border-radius:.75rem;padding:1.25rem;text-align:center;background:#f0fdf4;cursor:pointer;">
                        <i class="fas fa-cloud-upload-alt" style="font-size:1.6rem;color:#16a34a;"></i>
                        <p class="mb-1 mt-2 fw-semibold" style="color:#15803d;">Arrastra aquí los PDFs de comprobantes o haz clic para seleccionar</p>
                        <small class="text-muted">Se lee fecha, referencia e importe de cada comprobante. No se guarda nada hasta confirmar.</small>
                    </div>
                    <input type="file" id="regPagoInput" accept="application/pdf" multiple style="display:none;">

                    <!-- Tarjetas de comprobantes leídos -->
                    <div id="regPagoComprobantesCards" class="mt-3"></div>

                    <!-- Tabla de facturas de la(s) línea(s) seleccionada(s) -->
                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-hover table-bordered align-middle">
                            <thead class="table-light" style="font-size:.72rem;text-transform:uppercase;color:#475569;">
                                <tr>
                                    <th class="text-center" style="width:34px;"><i class="fas fa-link text-muted" title="Asignar al comprobante activo"></i></th>
                                    <th>Folio</th>
                                    <th>Factura</th>
                                    <th>Estación</th>
                                    <th class="text-end">Monto Autorizado</th>
                                    <th>Comprobante</th>
                                </tr>
                            </thead>
                            <tbody id="regPagoFacturasBody"></tbody>
                        </table>
                    </div>
                    <div id="regPagoSinComprobante" class="alert alert-warning py-2" style="font-size:.82rem;">
                        <i class="fas fa-info-circle"></i> Sube un comprobante para empezar a asignar facturas.
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="me-auto small text-muted" id="regPagoResumenSel"></span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-sm" style="background:#16a34a;color:#fff;" id="btnGuardarRegistroPago" onclick="guardarRegistroPagoMulti()" disabled>
                        <i class="fas fa-save"></i> Registrar pago(s)
                    </button>
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 2: Verificar que el bloque Twig sigue balanceado**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && grep -c "{% endblock %}" views/payment/payment_list.html`
Expected: el mismo número de `{% endblock %}` que antes del cambio (no se agregó ni quitó ninguno). Confirmar visualmente que el nuevo `<div class="modal...">` quedó ANTES del `{% endblock %}` que cierra el bloque de contenido y DESPUÉS del cierre de `#modalComprobantes`.

- [ ] **Step 3: Commit**

```bash
git add views/payment/payment_list.html
git commit -m "Agregar modal #modalRegistroPagoNuevo para registrar pago multi-comprobante"
```

---

### Task 2: Reescribir `mostrarModalRegistroPago` para abrir y poblar el nuevo modal

**Files:**
- Modify: `_assets/js/payment.js` — reemplazar el cuerpo de `mostrarModalRegistroPago()` (línea ~5506-5627, el bloque que arma el `alertify.confirm`)

**Interfaces:**
- Consumes: IDs DOM de Task 1; endpoint `/payment/get_invoices_detail` (POST `invoice_ids` como string CSV o array; responde `{ success, data:[{ id, folio, factura, estacion, authorized_amount, ... }] }`). Llamado igual que en `cargarDesgloseFacturas` (payment.js:4727).
- Produces: variables de estado globales que Task 3 consume: `regPagoFacturas` (array de `{ id, folio, factura, estacion, authorized_amount, asignadoA }`), `regPagoComprobantes` (array, vacío al abrir), `regPagoComprobanteActivo` (índice o null), `regPagoBanco` (string). Y deja el modal `#modalRegistroPagoNuevo` abierto con header + tabla de facturas pobladas.

- [ ] **Step 1: Declarar el estado global y reescribir la función**

En `_assets/js/payment.js`, localizar `function mostrarModalRegistroPago(gruposSeleccionados, banco) {` (línea ~5506). Reemplazar TODA la función (desde esa línea hasta su `}` de cierre en la línea ~5627, justo antes de `function ejecutarRegistroPago()`) por:

```javascript
// Estado del nuevo modal Registrar Pago (multi-comprobante)
let regPagoFacturas = [];          // facturas de la línea: {id, folio, factura, estacion, authorized_amount, asignadoA}
let regPagoComprobantes = [];      // comprobantes leídos: {file, archivo, banco, fecha, referencia, importe, error}
let regPagoComprobanteActivo = null; // índice en regPagoComprobantes
let regPagoBanco = "";

function mostrarModalRegistroPago(gruposSeleccionados, banco) {
  // Reset de estado
  regPagoFacturas = [];
  regPagoComprobantes = [];
  regPagoComprobanteActivo = null;
  regPagoBanco = banco;

  // Reunir todos los invoice_ids de los grupos seleccionados
  const todosLosIds = [];
  gruposSeleccionados.forEach((grupo) => {
    String(grupo.invoice_ids).split(",").forEach((id) => {
      const n = parseInt(String(id).trim());
      if (n > 0) todosLosIds.push(n);
    });
  });

  // Header resumen
  const empresas = [...new Set(gruposSeleccionados.map((g) => g.empresa))];
  const empresasTexto = empresas.length === 1 ? empresas[0] : `${empresas.length} empresas`;
  const totalMonto = gruposSeleccionados.reduce((s, g) => s + parseFloat(g.monto), 0);
  const bancoColor = banco === "Santander" ? "#ec1c24" : banco === "Banorte" ? "#c9302c" : "#6c757d";
  const bancoIcon = banco === "Santander" ? "fas fa-university" : "fas fa-piggy-bank";

  $("#regPagoHeaderResumen").html(`
    <div style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); border-radius: 8px; padding: 14px;">
      <div class="d-flex align-items-center gap-2 mb-2">
        <div style="background:${bancoColor};border-radius:6px;width:34px;height:34px;display:flex;align-items:center;justify-content:center;">
          <i class="${bancoIcon} text-white"></i>
        </div>
        <div>
          <div style="color:#fff;font-weight:600;font-size:.95rem;">${banco}</div>
          <div style="color:#adb5bd;font-size:.72rem;">${empresasTexto} · ${todosLosIds.length} factura(s) · $${totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</div>
        </div>
      </div>
    </div>`);

  // Resetear UI
  $("#regPagoInput").val("");
  $("#regPagoComprobantesCards").html("");
  $("#regPagoFacturasBody").html('<tr><td colspan="6" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Cargando facturas...</td></tr>');
  $("#regPagoSinComprobante").show();
  $("#btnGuardarRegistroPago").prop("disabled", true);
  $("#regPagoResumenSel").text("");

  $("#modalRegistroPagoNuevo").modal("show");

  // Cargar detalle de facturas (mismo endpoint que el desglose)
  $.ajax({
    url: "/payment/get_invoices_detail",
    type: "POST",
    data: { invoice_ids: todosLosIds.join(",") },
    dataType: "json",
    success: function (response) {
      if (response.success && response.data) {
        regPagoFacturas = response.data.map((f) => ({
          id: f.id,
          folio: f.folio,
          factura: f.factura || "",
          estacion: f.estacion || "",
          authorized_amount: parseFloat(f.authorized_amount) || 0,
          asignadoA: null,
        }));
        renderRegPagoFacturas();
      } else {
        $("#regPagoFacturasBody").html('<tr><td colspan="6" class="text-center text-danger">No se pudieron cargar las facturas</td></tr>');
      }
    },
    error: function () {
      $("#regPagoFacturasBody").html('<tr><td colspan="6" class="text-center text-danger">Error de conexión</td></tr>');
    },
  });
}
```

- [ ] **Step 2: Verificar sintaxis JS**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && node --check _assets/js/payment.js`
Expected: sin salida de error (exit 0). Nota: `renderRegPagoFacturas` y `guardarRegistroPagoMulti` aún no existen — `node --check` solo valida sintaxis, no referencias, así que pasará. Se definen en Task 3.

- [ ] **Step 3: Commit**

```bash
git add _assets/js/payment.js
git commit -m "Reescribir mostrarModalRegistroPago para abrir el nuevo modal multi-comprobante"
```

---

### Task 3: Lectura de comprobantes, asignación en vivo y guardado

**Files:**
- Modify: `_assets/js/payment.js` — añadir funciones nuevas justo después de `mostrarModalRegistroPago` (antes de `function ejecutarRegistroPago()`)

**Interfaces:**
- Consumes: estado global de Task 2 (`regPagoFacturas`, `regPagoComprobantes`, `regPagoComprobanteActivo`, `regPagoBanco`); IDs DOM de Task 1; endpoints `/payment/preview_comprobantes_match` y `/payment/conciliar_comprobantes`.
- Produces: funciones `renderRegPagoFacturas()`, `renderRegPagoComprobantes()`, `subirRegPagoComprobantes(fileList)`, `setRegPagoComprobanteActivo(idx)`, `onToggleRegPagoFactura(invoiceId)`, `recalcularRegPago()`, `guardarRegistroPagoMulti()`. Y handlers de dropzone.

- [ ] **Step 1: Añadir las funciones de render, lectura y asignación**

En `_assets/js/payment.js`, inmediatamente después del cierre de `mostrarModalRegistroPago` (el `}` final) y antes de `function ejecutarRegistroPago()`, insertar:

```javascript
function regPagoFmt(v) {
  return "$" + (parseFloat(v) || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 });
}

// Normaliza fecha dd/mm/aaaa [hh:mm] -> yyyy-mm-dd (reutiliza la lógica del otro flujo)
function regPagoFechaAInput(fecha) {
  const m = (fecha || "").match(/(\d{2})\/(\d{2})\/(\d{4})/);
  if (m) return `${m[3]}-${m[2]}-${m[1]}`;
  return new Date().toISOString().split("T")[0];
}

function renderRegPagoFacturas() {
  let html = "";
  regPagoFacturas.forEach((f) => {
    const asignado = f.asignadoA !== null;
    const compTxt = asignado && regPagoComprobantes[f.asignadoA]
      ? `<span class="badge bg-primary">${regPagoComprobantes[f.asignadoA].archivo}</span>`
      : '<span class="text-muted">—</span>';
    // Marcable solo si está libre, o si pertenece al comprobante activo
    const perteneceActivo = f.asignadoA === regPagoComprobanteActivo;
    const deshabilitado = asignado && !perteneceActivo;
    const checked = perteneceActivo ? "checked" : "";
    html += `
      <tr>
        <td class="text-center">
          <input type="checkbox" class="regpago-chk" data-id="${f.id}" ${checked} ${deshabilitado ? "disabled" : ""} onchange="onToggleRegPagoFactura(${f.id})">
        </td>
        <td><strong>${f.folio}</strong></td>
        <td>${f.factura}</td>
        <td>${f.estacion}</td>
        <td class="text-end">${regPagoFmt(f.authorized_amount)}</td>
        <td>${compTxt}</td>
      </tr>`;
  });
  $("#regPagoFacturasBody").html(html || '<tr><td colspan="6" class="text-center text-muted">Sin facturas</td></tr>');
}

function renderRegPagoComprobantes() {
  if (regPagoComprobantes.length === 0) {
    $("#regPagoComprobantesCards").html("");
    $("#regPagoSinComprobante").show();
    return;
  }
  $("#regPagoSinComprobante").hide();
  let html = "";
  regPagoComprobantes.forEach((c, idx) => {
    const asignadas = regPagoFacturas.filter((f) => f.asignadoA === idx);
    const sumaAsignada = asignadas.reduce((s, f) => s + f.authorized_amount, 0);
    const saldo = (parseFloat(c.importe) || 0) - sumaAsignada;
    const activo = idx === regPagoComprobanteActivo;
    const dif = saldo;
    const difTxt = Math.abs(dif) < 0.01
      ? '<span class="text-success">cuadra</span>'
      : `<span class="text-danger">dif. ${regPagoFmt(dif)}</span>`;
    const errTxt = c.error ? `<div class="text-danger small"><i class="fas fa-exclamation-triangle"></i> ${c.error}</div>` : "";
    html += `
      <div class="card mb-2 ${activo ? "border-success" : ""}" style="cursor:pointer;" onclick="setRegPagoComprobanteActivo(${idx})">
        <div class="card-body py-2 px-3">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <strong>${activo ? '<i class="fas fa-dot-circle text-success"></i> ' : ""}${c.archivo}</strong>
              <span class="badge bg-secondary ms-1">${c.banco || "—"}</span>
              ${errTxt}
            </div>
            <div class="text-end small">
              Importe leído: <strong>${regPagoFmt(c.importe)}</strong><br>
              Asignado: ${regPagoFmt(sumaAsignada)} · ${difTxt}
            </div>
          </div>
          <div class="row g-2 mt-1" onclick="event.stopPropagation()">
            <div class="col">
              <input type="date" class="form-control form-control-sm regpago-comp-fecha" data-idx="${idx}" value="${c.fecha}" max="${new Date().toISOString().split("T")[0]}">
            </div>
            <div class="col">
              <input type="text" class="form-control form-control-sm regpago-comp-ref" data-idx="${idx}" value="${(c.referencia || "").replace(/"/g, "")}" placeholder="Referencia" maxlength="50">
            </div>
          </div>
        </div>
      </div>`;
  });
  $("#regPagoComprobantesCards").html(html);
}

function setRegPagoComprobanteActivo(idx) {
  regPagoComprobanteActivo = idx;
  renderRegPagoComprobantes();
  renderRegPagoFacturas();
}

function onToggleRegPagoFactura(invoiceId) {
  if (regPagoComprobanteActivo === null) {
    alertify.warning("Selecciona un comprobante primero");
    renderRegPagoFacturas();
    return;
  }
  const f = regPagoFacturas.find((x) => x.id === invoiceId);
  if (!f) return;
  if (f.asignadoA === regPagoComprobanteActivo) {
    f.asignadoA = null; // desmarcar
  } else if (f.asignadoA === null) {
    f.asignadoA = regPagoComprobanteActivo; // asignar al activo
  }
  recalcularRegPago();
}

function recalcularRegPago() {
  renderRegPagoComprobantes();
  renderRegPagoFacturas();
  // Resumen + habilitar guardar si hay al menos una factura asignada
  const asignadasTotal = regPagoFacturas.filter((f) => f.asignadoA !== null);
  const total = asignadasTotal.reduce((s, f) => s + f.authorized_amount, 0);
  if (asignadasTotal.length > 0) {
    $("#regPagoResumenSel").html(`<strong>${asignadasTotal.length}</strong> factura(s) asignada(s) · <strong>${regPagoFmt(total)}</strong>`);
    $("#btnGuardarRegistroPago").prop("disabled", false);
  } else {
    $("#regPagoResumenSel").text("");
    $("#btnGuardarRegistroPago").prop("disabled", true);
  }
}

function subirRegPagoComprobantes(fileList) {
  const files = Array.from(fileList).filter(
    (f) => f.type === "application/pdf" || f.name.toLowerCase().endsWith(".pdf")
  );
  if (files.length === 0) {
    alertify.warning("Selecciona archivos PDF");
    return;
  }
  // Leer cada PDF de forma independiente reutilizando preview_comprobantes_match
  files.forEach((file) => {
    const fd = new FormData();
    fd.append("comprobantes[]", file);
    fetch("/payment/preview_comprobantes_match", { method: "POST", body: fd })
      .then((r) => r.json())
      .then((res) => {
        const c = (res.success && res.comprobantes && res.comprobantes[0])
          ? res.comprobantes[0].comprobante : null;
        regPagoComprobantes.push({
          file: file,
          archivo: file.name,
          banco: c ? c.banco : "",
          fecha: c ? regPagoFechaAInput(c.fecha) : new Date().toISOString().split("T")[0],
          referencia: c ? (c.referencia || "") : "",
          importe: c ? (parseFloat(c.importe) || 0) : 0,
          error: c ? (c.error || "") : "No se pudo leer el PDF",
        });
        // Activar el recién agregado
        regPagoComprobanteActivo = regPagoComprobantes.length - 1;
        recalcularRegPago();
      })
      .catch(() => {
        regPagoComprobantes.push({
          file: file, archivo: file.name, banco: "", fecha: new Date().toISOString().split("T")[0],
          referencia: "", importe: 0, error: "Error de conexión al leer el PDF",
        });
        regPagoComprobanteActivo = regPagoComprobantes.length - 1;
        recalcularRegPago();
      });
  });
}

function guardarRegistroPagoMulti() {
  // Sincronizar fecha/referencia editadas en las tarjetas hacia el estado
  $(".regpago-comp-fecha").each(function () {
    const i = parseInt($(this).data("idx"));
    if (regPagoComprobantes[i]) regPagoComprobantes[i].fecha = this.value;
  });
  $(".regpago-comp-ref").each(function () {
    const i = parseInt($(this).data("idx"));
    if (regPagoComprobantes[i]) regPagoComprobantes[i].referencia = this.value.trim();
  });

  // Construir asignaciones (un lote por comprobante con facturas asignadas)
  const asignaciones = [];
  regPagoComprobantes.forEach((c, idx) => {
    const ids = regPagoFacturas.filter((f) => f.asignadoA === idx).map((f) => f.id);
    if (ids.length === 0) return; // comprobante sin facturas: se omite
    asignaciones.push({
      archivo_idx: idx,
      archivo: c.archivo,
      invoice_ids: ids,
      fecha_pago: c.fecha,
      referencia: c.referencia,
      observaciones: "Registro de pago con comprobante",
    });
  });

  if (asignaciones.length === 0) {
    alertify.warning("Asigna al menos una factura a un comprobante");
    return;
  }
  const incompletos = asignaciones.filter((a) => !a.fecha_pago || !a.referencia);
  if (incompletos.length > 0) {
    alertify.error("Completa fecha y referencia en cada comprobante con facturas asignadas");
    return;
  }

  const fd = new FormData();
  // Reenviar TODOS los PDFs en el mismo orden/índice que archivo_idx
  regPagoComprobantes.forEach((c) => fd.append("comprobantes[]", c.file));
  fd.append("asignaciones", JSON.stringify(asignaciones));

  $("#btnGuardarRegistroPago").prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Registrando...');

  fetch("/payment/conciliar_comprobantes", { method: "POST", body: fd })
    .then((r) => r.json())
    .then((res) => {
      $("#btnGuardarRegistroPago").html('<i class="fas fa-save"></i> Registrar pago(s)').prop("disabled", false);
      let detalle = (res.resultados || [])
        .map((x) => `<li>${x.success ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>'} <strong>${x.archivo}</strong>: ${x.message}</li>`)
        .join("");
      alertify.alert(
        '<i class="fas fa-clipboard-check"></i> Resultado del registro',
        `<div>
            <div class="alert alert-success">${res.aplicados || 0} de ${res.total || 0} aplicados · Total ${regPagoFmt(res.total_aplicado || 0)}</div>
            <ul style="font-size:.85rem;max-height:300px;overflow:auto;">${detalle}</ul>
         </div>`
      );
      if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
        $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
      }
      if ((res.aplicados || 0) > 0) {
        $("#modalRegistroPagoNuevo").modal("hide");
      }
    })
    .catch((err) => {
      $("#btnGuardarRegistroPago").html('<i class="fas fa-save"></i> Registrar pago(s)').prop("disabled", false);
      alertify.error("Error de conexión: " + err.message);
    });
}

// Wiring del dropzone (una sola vez)
$(document).on("click", "#regPagoDropzone", function () {
  $("#regPagoInput").click();
});
$(document).on("change", "#regPagoInput", function () {
  if (this.files && this.files.length) subirRegPagoComprobantes(this.files);
});
$(document).on("dragover", "#regPagoDropzone", function (e) {
  e.preventDefault();
  $(this).css("background", "#dcfce7");
});
$(document).on("dragleave drop", "#regPagoDropzone", function (e) {
  e.preventDefault();
  $(this).css("background", "#f0fdf4");
});
$(document).on("drop", "#regPagoDropzone", function (e) {
  const files = e.originalEvent.dataTransfer.files;
  if (files && files.length) subirRegPagoComprobantes(files);
});
```

- [ ] **Step 2: Verificar sintaxis JS**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && node --check _assets/js/payment.js`
Expected: sin salida de error (exit 0).

- [ ] **Step 3: Verificar que las funciones viejas quedaron sin uso pero sin romper**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && grep -n "ejecutarRegistroPago\|mostrarResumenPagoRegistrado" _assets/js/payment.js`
Expected: `ejecutarRegistroPago` y `mostrarResumenPagoRegistrado` siguen DEFINIDAS (no se borraron) pero ya no se llaman desde el nuevo flujo. No deben eliminarse en esta tarea (evitar romper referencias inadvertidas); quedan como código muerto tolerado. Confirmar que NO hay ninguna llamada activa nueva a ellas.

- [ ] **Step 4: Commit**

```bash
git add _assets/js/payment.js
git commit -m "Lectura de comprobantes, asignacion factura-comprobante y guardado en Registrar Pago"
```

- [ ] **Step 5: Verificación manual en navegador**

1. Iniciar servidor: `php -S localhost:8000 router.php` (o usar el IIS local existente).
2. Login como usuario Tesorería (permiso 68). Ir al tab **Facturas Autorizadas**.
3. Marcar un grupo y pulsar **Registrar Pago**. Confirmar que abre el modal nuevo con header (banco/empresa/total) y la tabla de facturas de esa línea.
4. Arrastrar/seleccionar un PDF de comprobante. Confirmar: aparece una tarjeta con banco/fecha/referencia/importe leídos, marcada como activa, y el aviso "Sube un comprobante…" desaparece.
5. Marcar facturas: su monto autorizado se descuenta del saldo de la tarjeta (Asignado sube, dif. baja). Cuando cuadra muestra "cuadra" en verde; si no, la diferencia en rojo.
6. Subir un segundo comprobante: se vuelve el activo; las facturas ya asignadas al primero aparecen deshabilitadas. Asignarle otras facturas.
7. Pulsar **Registrar pago(s)**. Confirmar el resumen por comprobante, que la tabla `#tabla_facturas_autorizadas` se recarga y que las facturas pagadas ya no aparecen.
8. Probar también el botón **Pagar** por fila (un solo grupo) → debe abrir el mismo modal con una sola línea.
9. Caso de error: subir un PDF no legible → la tarjeta aparece con aviso e importe 0, fecha/referencia editables a mano; se puede completar y registrar igual.
