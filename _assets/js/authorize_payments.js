// ============================================
// AUTORIZAR PAGO DE FACTURAS - vista completa
// Copia independiente de la lógica del modal #modalAutorizarPagoMasivo
// (payment.js / payment_list.html). No compartir código con el modal
// hasta que se decida reemplazarlo.
// ============================================

function cargarFacturasPagoMasivo() {
  $("#loadingPagoMasivo").show();
  $("#tablaPagoMasivoContainer").hide();
  $("#sinFacturasPagoMasivo").hide();
  $("#anticiposPagoMasivoContainer").hide();

  $.ajax({
    url: "/payment/get_pending_payment_invoices",
    type: "GET",
    success: function (response) {
      $("#loadingPagoMasivo").hide();
      if (response.success && response.data && response.data.length > 0) {
        renderTablaPagoMasivo(response.data);
        $("#tablaPagoMasivoContainer").show();
      } else {
        $("#sinFacturasPagoMasivo").show();
      }

      if (response.success && response.anticipos && response.anticipos.length > 0) {
        renderTablaAnticiposPagoMasivo(response.anticipos);
        $("#anticiposPagoMasivoContainer").show();
      }
    },
    error: function () {
      $("#loadingPagoMasivo").hide();
      alertify.error("Error al cargar facturas pendientes");
    },
  });
}


function renderTablaAnticiposPagoMasivo(anticipos) {
  const tbody = $("#tablaAnticiposPagoMasivo tbody");
  tbody.empty();

  const fmt = (val) =>
    "$" +
    parseFloat(val || 0).toLocaleString("es-MX", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  anticipos.forEach(function (a) {
    const row = `<tr data-anticipo-id="${a.id}" class="apf-row-selectable">
      <td>
        <input type="checkbox" class="anticipo-masivo-checkbox" value="${a.id}" onchange="updateBtnAutorizarAnticiposMasivo()"/>
      </td>
      <td><a href="/payment/anticipo_detail/${a.id}" target="_blank">#${a.id}</a></td>
      <td>${a.empresa_nombre}</td>
      <td>${a.proveedor_nombre}</td>
      <td>${a.comment || ""}</td>
      <td class="text-end">${fmt(a.monto_total)}</td>
    </tr>`;
    tbody.append(row);
  });

  updateBtnAutorizarAnticiposMasivo();
}

function toggleSelectAllAnticiposMasivo() {
  const checked = $("#selectAllAnticiposMasivo").is(":checked");
  $(".anticipo-masivo-checkbox").prop("checked", checked);
  updateBtnAutorizarAnticiposMasivo();
}

function updateBtnAutorizarAnticiposMasivo() {
  const totalChecked = $(".anticipo-masivo-checkbox:checked").length;
  $("#btnConfirmarAutorizacionAnticiposMasiva").prop("disabled", totalChecked === 0);
}

function confirmarAutorizarAnticiposMasivo() {
  const anticipoIds = $(".anticipo-masivo-checkbox:checked")
    .map(function () {
      return $(this).val();
    })
    .get();

  if (anticipoIds.length === 0) {
    alertify.error("Debe seleccionar al menos un anticipo");
    return;
  }

  alertify
    .confirm(
      "Confirmar Autorización de Anticipos",
      `<div class="text-center">
        <i class="fas fa-check-circle text-warning fa-3x mb-3"></i>
        <p class="mb-0">¿Está seguro de autorizar <strong>${anticipoIds.length} anticipo(s)</strong>?</p>
      </div>`,
      function () {
        ejecutarAutorizacionAnticiposMasivo(anticipoIds);
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Autorizar", cancel: "Cancelar" });
}

function ejecutarAutorizacionAnticiposMasivo(anticipoIds) {
  $("#btnConfirmarAutorizacionAnticiposMasiva")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');

  let pendientes = anticipoIds.length;
  let errores = [];

  anticipoIds.forEach(function (anticipoId) {
    $.ajax({
      url: "/payment/authorize_payment",
      type: "POST",
      data: {
        payment_id: anticipoId,
        permission: 68,
      },
      success: function (response) {
        if (!response.success) {
          errores.push(`Anticipo #${anticipoId}: ${response.message}`);
        }
      },
      error: function () {
        errores.push(`Anticipo #${anticipoId}: error de conexión`);
      },
      complete: function () {
        pendientes--;
        if (pendientes === 0) {
          if (errores.length === 0) {
            alertify.success("Anticipos autorizados correctamente");
          } else {
            alertify.error(errores.join("<br>"));
          }
          setTimeout(() => {
            location.reload();
          }, 1500);
        }
      },
    });
  });
}


function renderTablaPagoMasivo(facturas) {
  const tbody = $("#tablaAutorizarPagoMasivo tbody");
  tbody.empty();

  const fmt = (val) =>
    "$" +
    parseFloat(val || 0).toLocaleString("es-MX", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  // Agrupar facturas por payment_request_id
  const grupos = {};
  const ordenGrupos = [];
  facturas.forEach(function (inv) {
    const pid = inv.payment_request_id;
    if (!grupos[pid]) {
      grupos[pid] = {
        payment_id: pid,
        empresa: inv.empresa_nombre,
        proveedor: inv.proveedor_nombre,
        fecha: inv.pago_fecha,
        es_anticipo: !!inv.es_anticipo,
        facturas: [],
      };
      ordenGrupos.push(pid);
    }
    grupos[pid].facturas.push(inv);
  });

  let totalMonto = 0,
    totalPagado = 0,
    totalSaldo = 0,
    totalNC = 0,
    totalND = 0,
    totalAnticipo = 0,
    totalSaldoNeto = 0;
  const totalCols = 11;

  // Ordenar facturas de cada grupo por número de factura
  ordenGrupos.forEach(function (pid) {
    grupos[pid].facturas.sort(function(a, b) {
      return (a.invoice_number || '').localeCompare(b.invoice_number || '', 'es', { numeric: true });
    });
  });

  ordenGrupos.forEach(function (pid) {
    const grupo = grupos[pid];
    const numFacturas = grupo.facturas.length;

    // Formatear fecha del pago
    let fechaStr = "";
    if (grupo.fecha) {
      const d = new Date(grupo.fecha);
      fechaStr = d.toLocaleDateString("es-MX");
    }

    // Calcular saldo neto del grupo para mostrar en encabezado
    let grupoSaldoNeto = 0;
    grupo.facturas.forEach(function (inv) {
      grupoSaldoNeto += Math.max(0, parseFloat(inv.saldo_neto || 0));
    });

    // Fila encabezado del grupo
    const detailUrl = grupo.es_anticipo
      ? `/payment/anticipo_detail/${pid}`
      : `/payment/payment_detail/${pid}`;
    const anticipoBadge = grupo.es_anticipo
      ? '<span class="badge bg-warning text-dark ms-1">Anticipo</span>'
      : '';
    const headerRow = `<tr class="group-header-row" data-group-id="${pid}">
      <td>
        <input type="checkbox" class="group-select-all" data-group="${pid}"
          onchange="toggleSelectGroupMasivo(${pid})" title="Seleccionar todas de este pago"/>
      </td>
      <td colspan="${totalCols - 1}" onclick="toggleGroupMasivo(${pid})">
        <i class="fas fa-chevron-right group-toggle-icon" data-group="${pid}" style="transition: transform 0.2s; margin-right: 6px; color:#94a3b8;"></i>
        <span class="apf-badge-pago">Pago #${pid}</span>${anticipoBadge}
        <span class="mx-2 text-muted">|</span>
        <i class="fas fa-building text-muted"></i> ${grupo.empresa}
        <span class="mx-2 text-muted">|</span>
        <i class="fas fa-truck text-muted"></i> ${grupo.proveedor}
        <span class="mx-2 text-muted">|</span>
        <i class="fas fa-calendar text-muted"></i> ${fechaStr}
        <span class="mx-2 text-muted">|</span>
        <span class="apf-badge-count">${numFacturas} factura(s)</span>
        <span class="mx-2 text-muted">|</span>
        <span class="apf-saldo-neto">${fmt(grupoSaldoNeto)}</span>
        <a href="${detailUrl}" target="_blank" class="float-end text-muted" onclick="event.stopPropagation()" title="Ver pago #${pid}"><i class="fas fa-external-link-alt"></i></a>
      </td>
    </tr>`;
    tbody.append(headerRow);

    // Filas de facturas del grupo
    grupo.facturas.forEach(function (inv) {
      const anticipo = parseFloat(inv.anticipo_aplicado || 0);
      const saldoNeto = Math.max(0, parseFloat(inv.saldo_neto || 0));
      totalMonto += parseFloat(inv.amount);
      totalPagado += parseFloat(inv.paid_amount);
      totalSaldo += parseFloat(inv.saldo);
      totalNC += parseFloat(inv.total_notas_credito);
      totalND += parseFloat(inv.total_notas_cargo);
      totalAnticipo += anticipo;
      totalSaldoNeto += saldoNeto;

      let notasHtml = '<span class="text-muted">-</span>';
      if (inv.notas_count > 0 || anticipo > 0) {
        let parts = [];
        if (inv.total_notas_credito > 0)
          parts.push(
            '<small class="text-success">-' + fmt(inv.total_notas_credito) + "</small>",
          );
        if (inv.total_notas_cargo > 0)
          parts.push(
            '<small class="text-danger">+' + fmt(inv.total_notas_cargo) + "</small>",
          );
        if (anticipo > 0)
          parts.push(
            '<small style="color:#92400e;" title="Anticipo aplicado a esta factura">ant. -' + fmt(anticipo) + "</small>",
          );
        notasHtml = parts.join("<br>");
      }

      // Formatear vencimiento
      let vencHtml = "-";
      if (inv.expiration_date) {
        const d = new Date(inv.expiration_date);
        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        const diffDias = Math.floor((d - hoy) / (1000 * 60 * 60 * 24));
        let badgeClass = "";
        let icon = "";
        if (diffDias < 0) { badgeClass = "overdue"; icon = '<i class="fas fa-exclamation-circle"></i> '; }
        else if (diffDias <= 7) { badgeClass = "soon"; }
        else if (diffDias <= 15) { badgeClass = "upcoming"; }
        vencHtml =
          '<span class="apf-badge-venc ' +
          badgeClass +
          '">' +
          icon +
          d.toLocaleDateString("es-MX") +
          "</span>";
      }

      const row = `<tr data-invoice-id="${inv.id}" data-payment-id="${inv.payment_request_id}" data-folio="${inv.folio}" data-saldo="${saldoNeto}" data-group="${pid}" class="group-row-${pid} apf-row-selectable" style="display: none;">
        <td>
          <input type="checkbox" class="factura-masivo-checkbox" value="${inv.id}" data-group="${pid}" onchange="updateAutorizacionMasivaSummary()"/>
        </td>
        <td><strong>${inv.folio}</strong></td>
        <td>${inv.invoice_number || ""}</td>
        <td>${inv.estacion_nombre}</td>
        <td class="text-end">${fmt(inv.amount)}</td>
        <td class="text-end">${fmt(inv.paid_amount)}</td>
        <td class="text-end"><strong class="apf-saldo-danger">${fmt(inv.saldo)}</strong></td>
        <td class="text-end">${notasHtml}</td>
        <td class="text-end"><strong class="apf-saldo-neto">${fmt(saldoNeto)}</strong></td>
        <td class="text-end">${vencHtml}</td>
        <td>
          <input type="number" class="form-control form-control-sm apf-monto-input monto-autorizar-masivo"
            step="0.01" min="0" max="${saldoNeto}" disabled
            onchange="updateAutorizacionMasivaSummary()"
            oninput="updateAutorizacionMasivaSummary()"/>
        </td>
      </tr>`;
      tbody.append(row);
    });
  });

  // Actualizar totales en footer
  $("#footerMasivoPagoMonto").text(fmt(totalMonto));
  $("#footerMasivoPagoPagado").text(fmt(totalPagado));
  $("#footerMasivoPagoSaldo").text(fmt(totalSaldo));
  $("#footerMasivoPagoNotas").html(
    '<small class="text-success">-' +
      fmt(totalNC) +
      '</small><br><small class="text-danger">+' +
      fmt(totalND) +
      "</small>" +
      (totalAnticipo > 0
        ? '<br><small style="color:#92400e;" title="Anticipos aplicados">ant. -' + fmt(totalAnticipo) + "</small>"
        : ""),
  );
  $("#footerMasivoPagoSaldoNeto").text(fmt(totalSaldoNeto));

  // Actualizar cards de resumen
  $("#masivoPagoMontoFacturas").text(fmt(totalMonto));
  $("#masivoPagoNC").text("-" + fmt(totalNC));
  $("#masivoPagoND").text("+" + fmt(totalND));
  $("#masivoPagoSaldoNeto").text(fmt(totalSaldoNeto));

  updateAutorizacionMasivaSummary();
}


function toggleGroupMasivo(groupId) {
  const rows = $(`.group-row-${groupId}`);
  const icon = $(`.group-toggle-icon[data-group="${groupId}"]`);
  const isVisible = rows.first().is(":visible");

  if (isVisible) {
    rows.hide();
    icon.css("transform", "rotate(0deg)");
  } else {
    rows.show();
    icon.css("transform", "rotate(90deg)");
  }
}


function toggleAllGroupsMasivo() {
  const allRows = $("[class*='group-row-']");
  const anyHidden = allRows.filter(":hidden").length > 0;

  if (anyHidden) {
    allRows.show();
    $(".group-toggle-icon").css("transform", "rotate(90deg)");
    $("#btnToggleAllGroups")
      .html('<i class="fas fa-compress-alt"></i> Colapsar Todos');
  } else {
    allRows.hide();
    $(".group-toggle-icon").css("transform", "rotate(0deg)");
    $("#btnToggleAllGroups")
      .html('<i class="fas fa-expand-alt"></i> Expandir Todos');
  }
}


function filtrarTablaPagoMasivo(term) {
  const q = term.trim().toLowerCase();

  // Sin texto → mostrar todo, colapsar facturas
  if (!q) {
    $(".group-header-row").show();
    $("[class*='group-row-']").hide();
    $(".group-toggle-icon").css("transform", "rotate(0deg)");
    return;
  }

  // Recorrer cada grupo
  $(".group-header-row").each(function() {
    const pid      = $(this).data("group-id");
    const headerTxt = $(this).text().toLowerCase();
    const factRows  = $(".group-row-" + pid);

    // Buscar en el header (empresa/proveedor/fecha)
    let headerMatch = headerTxt.includes(q);

    // Buscar en filas de facturas (folio, factura, estación)
    let matchingRows = factRows.filter(function() {
      return $(this).text().toLowerCase().includes(q);
    });

    if (headerMatch) {
      // Mostrar grupo completo con todas sus facturas
      $(this).show();
      factRows.show();
      $(".group-toggle-icon[data-group='" + pid + "']").css("transform", "rotate(90deg)");
    } else if (matchingRows.length > 0) {
      // Mostrar solo el grupo y las filas que coinciden
      $(this).show();
      factRows.hide();
      matchingRows.show();
      $(".group-toggle-icon[data-group='" + pid + "']").css("transform", "rotate(90deg)");
    } else {
      // Ocultar grupo completo
      $(this).hide();
      factRows.hide();
    }
  });
}


function toggleSelectGroupMasivo(groupId) {
  const groupCheckbox = $(`.group-select-all[data-group="${groupId}"]`);
  const isChecked = groupCheckbox.prop("checked");

  $(`.factura-masivo-checkbox[data-group="${groupId}"]`).each(function () {
    $(this).prop("checked", isChecked);
    const row = $(this).closest("tr");
    const montoInput = row.find(".monto-autorizar-masivo");

    if (isChecked) {
      const saldo = parseFloat(row.data("saldo"));
      montoInput.prop("disabled", false).val(saldo.toFixed(2));
    } else {
      montoInput.prop("disabled", true).val("");
    }
  });

  updateAutorizacionMasivaSummary();
}


function updateAutorizacionMasivaSummary() {
  let totalAAutorizar = 0;
  let facturasCount = 0;

  $(".factura-masivo-checkbox:checked").each(function () {
    const row = $(this).closest("tr");
    const montoInput = row.find(".monto-autorizar-masivo");
    const monto = parseFloat(montoInput.val()) || 0;
    const saldo = parseFloat(row.data("saldo"));

    montoInput.prop("disabled", !$(this).prop("checked"));

    if ($(this).prop("checked") && montoInput.val() === "") {
      montoInput.val(saldo.toFixed(2));
    }

    if (monto > saldo) {
      montoInput.val(saldo.toFixed(2));
      alertify.warning("El monto no puede exceder el saldo neto de la factura");
    }

    totalAAutorizar += parseFloat(montoInput.val()) || 0;
    facturasCount++;
  });

  // Deshabilitar inputs de no seleccionadas
  $(".factura-masivo-checkbox:not(:checked)").each(function () {
    $(this).closest("tr").find(".monto-autorizar-masivo").prop("disabled", true);
  });

  const fmt = (val) =>
    "$" +
    parseFloat(val).toLocaleString("es-MX", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  $("#masivoPagoTotalAutorizar").text(fmt(totalAAutorizar));
  $("#masivoPagoSeleccionadas").text(facturasCount);

  $("#btnConfirmarAutorizacionMasiva").prop(
    "disabled",
    facturasCount === 0 || totalAAutorizar === 0,
  );

  // Actualizar checkbox "Seleccionar Todas" global
  const totalCheckboxes = $(".factura-masivo-checkbox").length;
  const totalChecked = $(".factura-masivo-checkbox:checked").length;
  $("#selectAllFacturasMasivo").prop(
    "checked",
    totalCheckboxes > 0 && totalCheckboxes === totalChecked,
  );

  // Actualizar checkboxes de grupo
  $(".group-select-all").each(function () {
    const gid = $(this).data("group");
    const groupTotal = $(`.factura-masivo-checkbox[data-group="${gid}"]`).length;
    const groupChecked = $(`.factura-masivo-checkbox[data-group="${gid}"]:checked`).length;
    $(this).prop("checked", groupTotal > 0 && groupTotal === groupChecked);
  });
}


function toggleSelectAllFacturasMasivo() {
  const selectAll = $("#selectAllFacturasMasivo").prop("checked");
  $(".factura-masivo-checkbox").each(function () {
    $(this).prop("checked", selectAll);
    const row = $(this).closest("tr");
    const montoInput = row.find(".monto-autorizar-masivo");

    if (selectAll) {
      const saldo = parseFloat(row.data("saldo"));
      montoInput.prop("disabled", false).val(saldo.toFixed(2));
    } else {
      montoInput.prop("disabled", true).val("");
    }
  });

  // Sincronizar checkboxes de grupo
  $(".group-select-all").prop("checked", selectAll);

  updateAutorizacionMasivaSummary();
}


function confirmarAutorizarPagoMasivo() {
  const facturasAutorizar = [];
  let totalAAutorizar = 0;

  $(".factura-masivo-checkbox:checked").each(function () {
    const row = $(this).closest("tr");
    const facturaId = $(this).val();
    const monto = parseFloat(row.find(".monto-autorizar-masivo").val()) || 0;
    const saldo = parseFloat(row.data("saldo"));
    const folio = row.data("folio");
    const paymentId = row.data("payment-id");

    if (monto > 0 && monto <= saldo) {
      facturasAutorizar.push({
        invoice_id: facturaId,
        payment_id: paymentId,
        folio: folio,
        monto_autorizado: monto,
        saldo_anterior: saldo,
      });
      totalAAutorizar += monto;
    }
  });

  if (facturasAutorizar.length === 0) {
    alertify.error("Debe seleccionar al menos una factura con monto válido");
    return;
  }

  // Contar pagos únicos
  const pagosUnicos = [...new Set(facturasAutorizar.map((f) => f.payment_id))];

  alertify
    .confirm(
      "Confirmar Autorización Masiva de Pago",
      `<div class="text-center">
        <i class="fas fa-check-circle text-info fa-3x mb-3"></i>
        <p class="mb-3">¿Está seguro de autorizar el pago de <strong>${facturasAutorizar.length} factura(s)</strong> de <strong>${pagosUnicos.length} pago(s)</strong>?</p>
        <div class="alert alert-info" style="display: flex;flex-direction: column;padding-bottom: 10px;">
          <strong>Total a Autorizar:</strong><br>
          <h4 class="text-info mb-0">$${totalAAutorizar.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</h4>
        </div>
        <small class="text-muted">La ejecución del pago será realizada posteriormente por el área correspondiente.</small>
      </div>`,
      function () {
        ejecutarAutorizacionPagoMasivo(facturasAutorizar);
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Autorizar", cancel: "Cancelar" });
}


function ejecutarAutorizacionPagoMasivo(facturasAutorizar) {
  $("#btnConfirmarAutorizacionMasiva")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');

  $.ajax({
    url: "/payment/bulk_authorize_payment_execution",
    type: "POST",
    contentType: "application/json",
    data: JSON.stringify({
      facturas: facturasAutorizar,
    }),
    success: function (response) {
      if (response.success) {
        alertify.success(response.message);

        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
        alertify.error(response.message);
        $("#btnConfirmarAutorizacionMasiva")
          .prop("disabled", false)
          .html(
            '<i class="fas fa-check-circle"></i> Autorizar Facturas Seleccionadas',
          );
      }
    },
    error: function (xhr) {
      const errorMsg =
        xhr.responseJSON?.message || "Error al autorizar el pago masivo";
      alertify.error(errorMsg);
      $("#btnConfirmarAutorizacionMasiva")
        .prop("disabled", false)
        .html(
          '<i class="fas fa-check-circle"></i> Autorizar Facturas Seleccionadas',
        );
    },
  });
}

// Permite marcar/desmarcar una factura haciendo clic en cualquier parte de la
// fila, no solo en el checkbox. Ignora clics sobre el propio checkbox (ya
// dispara su "onchange"), el input de monto y enlaces dentro de la fila.
// Registrado dentro de DOMContentLoaded porque este script se carga antes
// que jquery.min.js en el layout base (jQuery aún no existe a nivel superior).
document.addEventListener("DOMContentLoaded", function () {
  $(document).on("click", "#tablaAutorizarPagoMasivo tbody tr.apf-row-selectable", function (e) {
    if ($(e.target).is('input, a, a *')) return;
    const checkbox = $(this).find(".factura-masivo-checkbox");
    checkbox.prop("checked", !checkbox.prop("checked")).trigger("change");
  });

  // Mismo comportamiento para la tabla de anticipos pendientes de autorización.
  $(document).on("click", "#tablaAnticiposPagoMasivo tbody tr.apf-row-selectable", function (e) {
    if ($(e.target).is('input, a, a *')) return;
    const checkbox = $(this).find(".anticipo-masivo-checkbox");
    checkbox.prop("checked", !checkbox.prop("checked")).trigger("change");
  });
});
