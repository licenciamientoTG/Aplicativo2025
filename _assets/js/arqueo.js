/* arqueo.js — Arqueos simultáneos Dollar2Go
 * Maneja la lista de sesiones (crear/abrir/cerrar) y la captura de una caja
 * (cálculo en vivo + guardado). Las fórmulas replican Arqueo::calcular_totales_caja.
 */

/* ===================== Utilidades ===================== */

function fmtMoney(n) {
  n = isFinite(n) ? n : 0;
  return "$" + n.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function ajaxJson(url, data) {
  return fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest" },
    body: JSON.stringify(data || {}),
  }).then((r) => r.json());
}

/* ===================== Lista de sesiones ===================== */

function abrirModalCrearSesion() {
  $("#modalCrearSesion").modal("show");
}

function crearSesion() {
  const nombre = ($("#nuevo_nombre").val() || "").trim();
  const fecha = ($("#nuevo_fecha").val() || "").trim();
  if (!nombre || !fecha) {
    toastr.warning("Nombre y fecha son obligatorios.");
    return;
  }
  ajaxJson("/arqueo/crear_sesion", { nombre: nombre, fecha: fecha }).then((res) => {
    if (res.success) {
      toastr.success("Arqueo programado.");
      setTimeout(() => location.reload(), 800);
    } else {
      toastr.error(res.message || "No se pudo crear.");
    }
  });
}

function abrirSesion(id) {
  alertify.confirm(
    "Abrir arqueo",
    "¿Abrir este arqueo para que los auditores capturen?",
    () => {
      ajaxJson("/arqueo/abrir/" + id, {}).then((res) => {
        if (res.success) {
          toastr.success("Arqueo abierto.");
          setTimeout(() => location.reload(), 600);
        } else {
          toastr.error(res.message || "No se pudo abrir.");
        }
      });
    },
    () => {}
  );
}

function cerrarSesion(id) {
  alertify.confirm(
    "Cerrar arqueo",
    "Al cerrar, el arqueo queda inmutable. ¿Continuar?",
    () => {
      ajaxJson("/arqueo/cerrar/" + id, {}).then((res) => {
        if (res.success) {
          toastr.success("Arqueo cerrado.");
          setTimeout(() => location.reload(), 600);
        } else {
          let msg = res.message || "No se pudo cerrar.";
          if (res.pendientes && res.pendientes.length) {
            msg += " Pendientes: " + res.pendientes.join(", ");
          }
          toastr.error(msg);
        }
      });
    },
    () => {}
  );
}

/* ===================== Captura de caja ===================== */

/* Total de una denominación según su tipo (igual que Arqueo::total_denominacion). */
function totalDenominacion(tipo, denom, cantidad, valorBolsa) {
  if (tipo === "fajilla") return cantidad * denom * 100;
  if (tipo === "bolsa") return cantidad * (valorBolsa || 0);
  return cantidad * denom; // billete | moneda
}

/* Recalcula totales por fila, gran totales por sección/moneda, vales y panel. */
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

  const sum = (k) => seccionMoneda[k] || 0;
  const fisicoUsd =
    sum("cajon-USD") + sum("morrallero-USD") + sum("caja_fuerte-USD") + sum("morrallero_cf-USD");
  const fisicoMxn =
    sum("cajon-MXN") + sum("morrallero-MXN") + sum("caja_fuerte-MXN") + sum("morrallero_cf-MXN");

  // Vales.
  let valesUsd = 0,
    valesMxn = 0;
  document.querySelectorAll(".vale-dolares").forEach((i) => (valesUsd += parseFloat(i.value) || 0));
  document.querySelectorAll(".vale-mxn").forEach((i) => (valesMxn += parseFloat(i.value) || 0));
  const granTotalValesMxn = valesUsd + valesMxn;

  const goUsd = parseFloat(document.getElementById("go_exchange_dolares").value) || 0;
  const goMxn = parseFloat(document.getElementById("go_exchange_mxn").value) || 0;
  const tcVenta = parseFloat(document.getElementById("tipo_cambio_venta").value) || 0;

  const arqueoMxn = fisicoMxn + valesMxn;
  const totalSistema = goUsd * tcVenta + goMxn;
  const difUsd = fisicoUsd - goUsd;
  const difMxn = arqueoMxn - goMxn;
  const resultado = difMxn + difUsd * tcVenta;

  // Pintar vales.
  document.getElementById("total_vales_dolares").textContent = fmtMoney(valesUsd);
  document.getElementById("total_vales_mxn").textContent = fmtMoney(valesMxn);
  document.getElementById("gran_total_vales_mxn").textContent = fmtMoney(granTotalValesMxn);

  // Pintar panel.
  document.getElementById("r_fisico_usd").textContent = fmtMoney(fisicoUsd);
  document.getElementById("r_fisico_mxn").textContent = fmtMoney(fisicoMxn);
  document.getElementById("r_arqueo_mxn").textContent = fmtMoney(arqueoMxn);
  document.getElementById("r_sistema").textContent = fmtMoney(totalSistema);
  document.getElementById("r_dif_usd").textContent = fmtMoney(difUsd);
  document.getElementById("r_dif_mxn").textContent = fmtMoney(difMxn);

  const elRes = document.getElementById("r_resultado");
  elRes.textContent = fmtMoney(resultado);
  elRes.className = "col-5 num " + (resultado < 0 ? "resultado-negativo" : "resultado-positivo");
}

/* Precarga inputs con lo ya capturado (window.ARQUEO_DENOMS / ARQUEO_VALES). */
function precargarCaja() {
  const denoms = window.ARQUEO_DENOMS || [];
  denoms.forEach((d) => {
    const sel =
      `.denom-input[data-seccion="${d.seccion}"][data-moneda="${d.moneda}"]` +
      `[data-denominacion="${parseFloat(d.denominacion)}"]`;
    const inp = document.querySelector(sel);
    if (inp) inp.value = parseInt(d.cantidad, 10) || 0;
  });

  const vales = window.ARQUEO_VALES || [];
  vales.forEach((v) => {
    const n = v.numero_vale;
    const f = document.querySelector(`.vale-fecha[data-num="${n}"]`);
    const c = document.querySelector(`.vale-concepto[data-num="${n}"]`);
    const dd = document.querySelector(`.vale-dolares[data-num="${n}"]`);
    const mm = document.querySelector(`.vale-mxn[data-num="${n}"]`);
    if (f && v.fecha) f.value = String(v.fecha).substring(0, 10);
    if (c) c.value = v.concepto || "";
    if (dd) dd.value = v.dolares || 0;
    if (mm) mm.value = v.mxn || 0;
  });
}

/* Construye el payload y guarda. */
function guardarCaja() {
  const cajaId = document.getElementById("caja_id").value;

  const denominaciones = [];
  document.querySelectorAll(".denom-input").forEach((inp) => {
    denominaciones.push({
      seccion: inp.dataset.seccion,
      moneda: inp.dataset.moneda,
      tipo: inp.dataset.tipo,
      denominacion: parseFloat(inp.dataset.denominacion) || 0,
      valor_bolsa: inp.dataset.valorBolsa ? parseFloat(inp.dataset.valorBolsa) : null,
      cantidad: parseInt(inp.value, 10) || 0,
    });
  });

  const vales = [];
  for (let n = 1; n <= 15; n++) {
    const fecha = (document.querySelector(`.vale-fecha[data-num="${n}"]`) || {}).value || "";
    const concepto = (document.querySelector(`.vale-concepto[data-num="${n}"]`) || {}).value || "";
    const dolares = parseFloat((document.querySelector(`.vale-dolares[data-num="${n}"]`) || {}).value) || 0;
    const mxn = parseFloat((document.querySelector(`.vale-mxn[data-num="${n}"]`) || {}).value) || 0;
    vales.push({ numero_vale: n, fecha: fecha, concepto: concepto, dolares: dolares, mxn: mxn });
  }

  const payload = {
    cajero_nombre: document.getElementById("cajero_nombre").value,
    encargado_revision: document.getElementById("encargado_revision").value,
    go_exchange_dolares: parseFloat(document.getElementById("go_exchange_dolares").value) || 0,
    go_exchange_mxn: parseFloat(document.getElementById("go_exchange_mxn").value) || 0,
    tipo_cambio_venta: parseFloat(document.getElementById("tipo_cambio_venta").value) || 0,
    tipo_cambio_compra: parseFloat(document.getElementById("tipo_cambio_compra").value) || 0,
    denominaciones: denominaciones,
    vales: vales,
  };

  ajaxJson("/arqueo/guardar_caja/" + cajaId, payload).then((res) => {
    if (res.success) {
      toastr.success("Arqueo guardado.");
    } else {
      let msg = res.message || "No se pudo guardar.";
      if (res.errores) msg += " (" + Object.values(res.errores).join(" ") + ")";
      toastr.error(msg);
    }
  });
}

/* ===================== Init ===================== */

document.addEventListener("DOMContentLoaded", function () {
  const enCaja = document.getElementById("form_arqueo");
  if (enCaja) {
    precargarCaja();
    recalcular();

    // Recalcular en cada cambio.
    document
      .querySelectorAll(".denom-input, .vale-dolares, .vale-mxn, #go_exchange_dolares, #go_exchange_mxn, #tipo_cambio_venta")
      .forEach((el) => el.addEventListener("input", recalcular));

    // Solo lectura: deshabilita toda la captura.
    if (document.getElementById("solo_lectura").value === "1") {
      enCaja.querySelectorAll("input").forEach((i) => (i.disabled = true));
    }
  }

  // DataTable de la lista de sesiones (si existe).
  if (window.jQuery && $("#tabla_sesiones").length) {
    $("#tabla_sesiones").DataTable({
      order: [[0, "desc"]],
      language: { url: "//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json" },
    });
  }
});
