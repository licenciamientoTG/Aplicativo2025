// payment.js - Funciones de Pago a Proveedores
// Migrado desde supply.js
console.log('payment.js')

$(document).ready(function () {
	$("#payment_create_table").on("draw.dt", function () {
		updateSelectedCount();
	});
});


function add_payment() {
  // Redirigir a la página de agregar pago
  window.location.href = "/payment/add_payment";
}

let selectedInvoices = new Set();

let paymentTable = null;


async function payment_create_table() {
  var fromDate = document.getElementById("from1").value;
  var untilDate = document.getElementById("until1").value;
  var codgas = document.getElementById("station_id1").value;
  var company = document.getElementById("company").value;
  // var proveedor = document.getElementById("proveedor_id").value;
    var proveedor = $('#proveedor_id').selectpicker('val') || $('#proveedor_id').val() || '0';

  console.log("Estación:", codgas);
  console.log("Proveedor ID:", proveedor);
  console.log("Company ID:", company);

  if (
    !codgas ||
    !company ||
    !proveedor ||
    codgas == "Seleccione" ||
    company == "Seleccione" ||
    proveedor == "Seleccione"
  ) {
    alertify.myAlert(
      `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar una estación para continuar.</p>
            </div>`,
    );
    return;
  }
  if (paymentTable) {
    // Actualizar parámetros AJAX
    paymentTable.settings()[0].ajax.data = {
      fromDate: fromDate,
      untilDate: untilDate,
      codgas: codgas,
      company: company,
      proveedor: proveedor,
    };

    // Recargar datos
    paymentTable.ajax.reload(function () {
      // Este callback se ejecuta DESPUÉS de cargar los datos
      $(".table-responsive").removeClass("loading");
      updateSelectedCount();
      console.log("✅ Datos recargados correctamente");
    }, false); // false = mantener página actual

    return;
  }

  console.log("🆕 Creando tabla por primera vez...");

  paymentTable = $("#payment_create_table").DataTable({
    order: [
      [0, "desc"],
      [1, "desc"],
    ],
    colReorder: false,
    dom: '<"top"f>rt<"bottom"lip>',
    paging: true,
    pageLength: 100,
    ajax: {
      method: "POST",
      data: {
        fromDate: fromDate,
        untilDate: untilDate,
        codgas: codgas,
        company: company,
        proveedor: proveedor,
      },
      url: "/payment/payment_control_table",
      timeout: 600000,
      error: function () {
        $("#payment_create_table").waitMe("hide");
        $(".table-responsive").removeClass("loading");

        alertify.myAlert(
          `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>`,
        );
      },
      beforeSend: function () {
        $(".table-responsive").addClass("loading");
      },
    },
    columns: [
      {
        data: null,
        orderable: false,
        className: "text-center text-nowrap",
        render: function (data, type, row) {
          // Facturas en orden de pago no se seleccionan, EXCEPTO las que viven
          // bajo un anticipo con aplicación parcial (se paga su remanente).
          if (row.en_orden_pago != 0 && row.anticipo_parcial != 1) {
            return ""; // o un ícono si quieres
          }
          return `
                        <input type="checkbox"
                            class="invoice-checkbox"
                            data-nro="${row.nro}"
                            data-factura="${row.Factura}" data-codigo_empresa="${row.codigo_empresa}"
                            data-codgas="${row.codgas}" data-proveedor_codigo="${row.proveedor_codigo}"
                            onchange="updateSelectedCount()">
                    `;
        },
      },
      { data: "nro" },
      { data: "Factura" },
      { data: "gasolinera", className: "text-center text-nowrap" },
      { data: "proveedor", className: "text-center text-nowrap" },
      {
        data: "fecha",
        className: "text-center text-nowrap",
        render: function (data, type, row) {
          if (type !== "display") {
            return data;
          }
          // Si la fecha NO viene de la factura (FacturasRecibidas), es de ControlGas: marcar en rojo
          if (row.fecha_de_factura == 0) {
            return (
              '<span class="text-danger fw-bold" title="Fecha de ControlGas — sin factura recibida">' +
              (data != null ? data : "") +
              "</span>"
            );
          }
          return data != null ? data : "";
        },
      },
      { data: "fechaVto", className: "text-center text-nowrap" },
      {
        data: "total_mostrar",
        className: "text-end text-nowrap",
        render: function (data, type, row) {
          var valor = data != null ? data : row.total_fac;
          if (type !== "display") {
            return parseFloat(valor) || 0;
          }
          var formateado = $.fn.dataTable.render.number(",", ".", 2).display(valor);
          // Si NO viene de FacturasRecibidas, el total es de ControlGas: pintarlo en rojo con tooltip
          if (row.tiene_factura_recibida == 0) {
            return (
              '<span class="text-danger fw-bold" title="Total de ControlGas — sin factura recibida">' +
              formateado +
              "</span>"
            );
          }
          return formateado;
        },
      },
      { data: "producto", className: "text-center text-nowrap" },
      { data: "statusLabel" },
      { data: "satuid", visible: false, searchable: false },
    ],
    columnDefs: [{ orderable: false, targets: 0 }],
    deferRender: true,
    // destroy: true,
    createdRow: function (row, data, dataIndex) {
      if (data.en_orden_pago != 0 && data.anticipo_parcial != 1) {
        $(row).addClass("bg_send");
      } else {
        if (data.anticipo_parcial == 1) {
          // Parcial de anticipo: fondo ámbar distintivo, pero seleccionable/arrastrable
          $(row).css("background", "#fffbeb");
        }
        $(row).addClass("draggable-row");
        $(row).attr("draggable", "true");
        $(row).data("rowData", data);
        $(row)
          .find("td:first")
          .prepend(
            '<i class="fas fa-grip-vertical drag-handle me-2" style="color: #6c757d; cursor: move;"></i>',
          );

        // data.fechaVto ya viene resuelto como fecha_vencimiento_credito (fecha + dias_credito del proveedor)
        if (data.fechaVto) {
          const partes = data.fechaVto.split("-");
          const fechaVto = new Date(
            parseInt(partes[0]),
            parseInt(partes[1]) - 1,
            parseInt(partes[2]),
          );

          const hoy = new Date();
          hoy.setHours(0, 0, 0, 0);

          if (fechaVto < hoy) {
            $("td", row).eq(6).addClass("bg-danger text-white text-center");
          }
        }
      }
    },
    initComplete: function () {
      const api = this.api();

      if ($("#payment_create_table thead tr.filter").length === 0) {
        const filterRow = $('<tr class="filter"></tr>');

        $("#payment_create_table thead tr:first th").each(function (index) {
          if (index === 0) {
            filterRow.append("<th></th>");
          } else {
            // ✅ RESTO DE COLUMNAS: Con filtro
            filterRow.append(
              `<th>
                                <input type="text"
                                    class="form-control form-control-sm"
                                    placeholder="${$(this).text().trim()}">
                            </th>`,
            );
          }
        });

        $("#payment_create_table thead").prepend(filterRow);

        // Eventos de búsqueda
        $("#payment_create_table thead tr.filter input").on(
          "keyup change",
          function () {
            const colIndex = $(this).parent().index();
            api.column(colIndex).search(this.value).draw();
          },
        );
      }
      $(".table-responsive").removeClass("loading");
      setupDragAndDrop();
      updateSelectedCount();
      // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
      console.log(
        "🎯 Filtros agregados:",
        $("#payment_create_table thead tr.filter").length,
      );
    },
    drawCallback: function (settings) {
      // ✅ ACTUALIZAR CONTADOR DESPUÉS DE CADA REDIBUJADO
      updateSelectedCount();
    },
    footerCallback: function (row, data, start, end, display) {},
  });
}


function filtrarEstacionesPorEmpresa() {
  const empresaSel = $("#company").val();
  const $station = $("#station_id1");

  // Si no se ha seleccionado empresa, mantener estaciones deshabilitadas
  if (empresaSel === null || empresaSel === "") {
    $station.prop("disabled", true);
    $station.selectpicker("refresh");
    return;
  }

  // Habilitar el select de estaciones
  $station.prop("disabled", false);

  // Destruir selectpicker para reconstruir opciones
  $station.selectpicker("destroy");

  // Limpiar todas las opciones
  $station.empty();

  // Agregar opción placeholder (NO seleccionada por defecto)
  $station.append(
    '<option value="" disabled selected >Seleccione una estación</option>',
  );

  // Agregar opción "Todas las estaciones"
  if (empresaSel === "0") {
    $station.append('<option value="0">Todas las estaciones</option>');
  } else {
    $station.append(
      '<option value="0">Todas las estaciones de esta empresa</option>',
    );
  }

  // Obtener y filtrar estaciones desde los datos originales
  if (window.originalStationOptions) {
    const $tempDiv = $("<div>").html(window.originalStationOptions);

    $tempDiv.find("option[data-emp]").each(function () {
      const emp = $(this).attr("data-emp");
      const stationValue = $(this).attr("value");
      const stationText = $(this).text();
      if (empresaSel === "0" || emp === empresaSel) {
        $station.append('<option value="' +stationValue +'" data-emp="' +emp +'">' +stationText +"</option>",);
      }
    });
  } else {
    console.error("No se encontraron opciones originales");
  }

  // Reinicializar selectpicker
  $station.selectpicker({
    liveSearch: true,
  });

  // Seleccionar "Todas las estaciones" por defecto
  $station.selectpicker("val", "0");

  // $station.find('option').each(function() {
  //     console.log('Opción:', $(this).text(), 'Valor:', $(this).val());
  // });
}


// Función para guardar opciones originales (llamar después de cargar la página)
function saveOriginalStationOptions() {
  console.log("Guardando opciones originales de estaciones");
  if (!window.originalStationOptions) {
    window.originalStationOptions = $("#station_id").html();
  }
}

$(document).ready(function () {
  // Solo ejecutar si estamos en la página correcta
  if ($("#formImportarUUIDs").length > 0) {
    $("#formImportarUUIDs").on("submit", function (e) {
      e.preventDefault();

      const formData = new FormData(this);
      const btnProcesar = $("#btnProcesar");

      // Validar que se haya seleccionado un archivo
      if (!$("#archivo_excel")[0].files[0]) {
        alertify.error("Debe seleccionar un archivo Excel");
        return;
      }

      // Deshabilitar botón y mostrar progreso
      btnProcesar.prop("disabled", true);
      $("#areaProgreso").show();
      $("#areaResumen").hide();
      $("#barraProgreso").css("width", "10%").text("10%");
      $("#textoProgreso").text("Procesando archivo Excel...");

      // Enviar archivo para procesar
      $.ajax({
        url: "/payment/procesar_uuids_facturas",
        type: "POST",
        data: formData,
        processData: false,
        contentType: false,
        success: function (response) {
          if (response.success) {
            $("#barraProgreso").css("width", "100%").text("100%");
            $("#textoProgreso").text("Procesamiento completado");

            // Pasar tanto exitosas como fallidas
            setTimeout(() => {
              mostrarOpcionesDescarga(
                response.facturas || [],
                btnProcesar,
                response.facturas_fallidas || [],
              );
            }, 500);
          } else {
            btnProcesar.prop("disabled", false);
            $("#areaProgreso").hide();
            alertify.error(response.message || "Error al procesar el archivo");
          }
        },
        error: function (xhr) {
          btnProcesar.prop("disabled", false);
          $("#areaProgreso").hide();

          let mensaje = "Error al procesar el archivo";
          if (xhr.responseJSON && xhr.responseJSON.message) {
            mensaje = xhr.responseJSON.message;
          }

          alertify.error(mensaje);
        },
      });
    });
  }
});


function mostrarOpcionesDescarga(facturas, btnProcesar, facturasFallidas = []) {
  $("#areaProgreso").hide();
  $("#areaResumen").show();

  const totalExitosas = facturas.length;
  const totalFallidas = facturasFallidas.length;
  const totalGeneral = totalExitosas + totalFallidas;

  if (totalGeneral === 0) {
    $("#areaResumen").html(`
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No se encontraron UUIDs válidos en el archivo Excel
            </div>
        `);
    btnProcesar.prop("disabled", false);
    return;
  }

  // Crear resumen con opciones de descarga
  let html = `
        <div class="row">
            <div class="col-12 mb-3">
                <div class="alert ${totalFallidas > 0 ? "alert-warning" : "alert-success"}">
                    <h5><i class="fas fa-info-circle"></i> Resumen de Búsqueda</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Total solicitado:</strong> <span class="badge bg-primary">${totalGeneral}</span>
                        </div>
                        <div class="col-md-4">
                            <strong class="text-success">Encontradas:</strong> <span class="badge bg-success">${totalExitosas}</span>
                        </div>
                        <div class="col-md-4">
                            <strong class="text-danger">Fallidas:</strong> <span class="badge bg-danger">${totalFallidas}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

  // Tarjeta de facturas exitosas
  if (totalExitosas > 0) {
    html += `
            <div class="card border-success mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle"></i> 
                        Facturas Disponibles para Descarga (${totalExitosas})
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <button class="btn btn-primary btn-lg" id="btnDescargarZip">
                            <i class="fas fa-file-archive"></i> Descargar Todo en ZIP (Recomendado)
                        </button>
                        <button class="btn btn-secondary" id="btnDescargarIndividual">
                            <i class="fas fa-file-pdf"></i> Descargar Individual
                        </button>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover">
                            <thead class="table-success sticky-top">
                                <tr>
                                    <th width="40"><i class="fas fa-check"></i></th>
                                    <th>Folio</th>
                                    <th>UUID</th>
                                    <th>Emisor</th>
                                    <th class="text-end">Total</th>
                                    <th>Archivo</th>
                                </tr>
                            </thead>
                            <tbody>
        `;

    facturas.forEach((f) => {
      html += `
                <tr>
                    <td><i class="fas fa-check-circle text-success"></i></td>
                    <td><strong>${f.folio || "N/A"}</strong></td>
                    <td><small class="font-monospace text-muted">${f.uuid.substring(0, 8)}...${f.uuid.substring(28)}</small></td>
                    <td>${f.emisor || "N/A"}</td>
                    <td class="text-end"><strong>$${parseFloat(f.total || 0).toLocaleString("es-MX", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong></td>
                    <td><small>${f.nombre_archivo}</small></td>
                </tr>
            `;
    });

    html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
  }

  // Tarjeta de facturas fallidas
  if (totalFallidas > 0) {
    html += `
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-times-circle"></i> 
                        UUIDs No Disponibles (${totalFallidas})
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        Los siguientes UUIDs no pudieron ser procesados. Revise el motivo de cada uno.
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-bordered">
                            <thead class="table-danger sticky-top">
                                <tr>
                                    <th width="40"><i class="fas fa-times"></i></th>
                                    <th>UUID</th>
                                    <th>Folio</th>
                                    <th>Tipo de Error</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
        `;

    facturasFallidas.forEach((f) => {
      let tipoError = "";
      let iconoError = "";
      let colorBadge = "bg-danger";

      switch (f.estado) {
        case "formato_invalido":
          tipoError = "Formato Inválido";
          iconoError =
            '<i class="fas fa-exclamation-triangle text-warning"></i>';
          colorBadge = "bg-warning";
          break;
        case "no_encontrado_bd":
          tipoError = "No en BD";
          iconoError = '<i class="fas fa-database text-danger"></i>';
          colorBadge = "bg-danger";
          break;
        case "archivo_no_existe":
          tipoError = "Archivo No Existe";
          iconoError = '<i class="fas fa-file-excel text-orange"></i>';
          colorBadge = "bg-orange";
          break;
        default:
          tipoError = "Error";
          iconoError = '<i class="fas fa-times-circle text-danger"></i>';
      }

      const folioTexto = f.folio || "N/A";
      const filaInfo = f.fila ? ` (Fila ${f.fila})` : "";

      html += `
                <tr>
                    <td class="text-center">${iconoError}</td>
                    <td><small class="font-monospace">${f.uuid}${filaInfo}</small></td>
                    <td><strong>${folioTexto}</strong></td>
                    <td><span class="badge ${colorBadge}">${tipoError}</span></td>
                    <td><small>${f.error || "Error desconocido"}</small></td>
                </tr>
            `;
    });

    html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
  }

  $("#areaResumen").html(html);
  btnProcesar.prop("disabled", false);

  // Event listeners solo si hay facturas exitosas
  if (totalExitosas > 0) {
    $("#btnDescargarZip").on("click", function () {
      descargarFacturasZip(facturas);
    });

    $("#btnDescargarIndividual").on("click", function () {
      descargarFacturasIndividual(facturas);
    });
  }
}


function descargarFacturasZip(facturas) {
  $("#btnDescargarZip")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Creando ZIP...');

  const ids = facturas.map((f) => f.id);

  $.ajax({
    url: "/payment/descargar_facturas_zip",
    type: "POST",
    contentType: "application/json",
    data: JSON.stringify({ ids: ids }),
    success: function (response) {
      if (response.success) {
        alertify.success(
          `ZIP creado con ${response.archivos_agregados} facturas`,
        );

        // Descargar el ZIP
        window.location.href = response.download_url;

        // Mostrar advertencias si hubo archivos no encontrados
        if (response.archivos_no_encontrados.length > 0) {
          setTimeout(() => {
            alertify.warning(
              `${response.archivos_no_encontrados.length} archivos no se pudieron agregar al ZIP`,
            );
          }, 1000);
        }
      } else {
        alertify.error(response.message || "Error al crear ZIP");
      }

      $("#btnDescargarZip")
        .prop("disabled", false)
        .html('<i class="fas fa-file-archive"></i> Descargar Todo en ZIP');
    },
    error: function () {
      alertify.error("Error al crear el archivo ZIP");
      $("#btnDescargarZip")
        .prop("disabled", false)
        .html('<i class="fas fa-file-archive"></i> Descargar Todo en ZIP');
    },
  });
}


function descargarFacturasIndividual(facturas) {
  alertify.confirm(
    "Descarga Individual",
    `¿Desea descargar ${facturas.length} archivos de forma individual? (Esto puede tardar más tiempo)`,
    function () {
      const exitosas = [];
      const fallidas = [];
      const total = facturas.length;
      let procesados = 0;

      $("#areaProgreso").show();
      $("#barraProgreso")
        .css("width", "0%")
        .text("0%")
        .addClass("progress-bar-animated");
      $("#textoProgreso").text("Descargando archivos...");

      async function descargarArchivo(factura) {
        return new Promise((resolve) => {
          fetch("/payment/descargar_factura/" + factura.id, {
            method: "GET",
          })
            .then((response) => {
              if (!response.ok) throw new Error("No se pudo descargar");
              return response.blob();
            })
            .then((blob) => {
              const url = window.URL.createObjectURL(blob);
              const a = document.createElement("a");
              a.style.display = "none";
              a.href = url;
              a.download = factura.nombre_archivo;
              document.body.appendChild(a);
              a.click();
              window.URL.revokeObjectURL(url);
              document.body.removeChild(a);

              exitosas.push(factura);
              resolve(true);
            })
            .catch((error) => {
              fallidas.push({ uuid: factura.uuid, error: error.message });
              resolve(false);
            })
            .finally(() => {
              procesados++;
              const progreso = (procesados / total) * 100;
              $("#barraProgreso")
                .css("width", progreso + "%")
                .text(Math.round(progreso) + "%");
              $("#textoProgreso").text(`Descargando... ${procesados}/${total}`);
            });
        });
      }

      async function procesarDescargas() {
        for (let i = 0; i < facturas.length; i++) {
          await descargarArchivo(facturas[i]);
          await new Promise((resolve) => setTimeout(resolve, 500));
        }

        $("#areaProgreso").hide();
        alertify.success(
          `Descarga completada: ${exitosas.length} exitosas, ${fallidas.length} fallidas`,
        );
      }

      procesarDescargas();
    },
    function () {
      alertify.message("Descarga cancelada");
    },
  );
}


// ==========================================
// FIN DE CÓDIGO DE FACTURAS UUID
// ==========================================
function descargarFacturas(facturas, btnProcesar) {
  const exitosas = [];
  const fallidas = [];
  const total = facturas.length;
  let procesados = 0;

  if (total === 0) {
    alertify.warning("No se encontraron facturas con los UUIDs proporcionados");
    btnProcesar.prop("disabled", false);
    $("#areaProgreso").hide();
    return;
  }

  // Función para descargar un archivo individual
  function descargarArchivo(factura, index) {
    return new Promise((resolve) => {
      fetch("/payment/descargar_factura/" + factura.id, {
        method: "GET",
      })
        .then((response) => {
          if (!response.ok) throw new Error("No se pudo descargar");
          return response.blob();
        })
        .then((blob) => {
          // Crear enlace de descarga
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.style.display = "none";
          a.href = url;
          a.download = factura.nombre_archivo;
          document.body.appendChild(a);
          a.click();
          window.URL.revokeObjectURL(url);
          document.body.removeChild(a);

          exitosas.push(factura);
          resolve(true);
        })
        .catch((error) => {
          fallidas.push({
            uuid: factura.uuid,
            error: error.message,
            folio: factura.folio,
          });
          resolve(false);
        })
        .finally(() => {
          procesados++;
          const progreso = 50 + (procesados / total) * 50;
          $("#barraProgreso")
            .css("width", progreso + "%")
            .text(Math.round(progreso) + "%");
          $("#textoProgreso").text(
            `Descargando archivos... ${procesados}/${total}`,
          );
        });
    });
  }

  // Procesar descargas con delay para no saturar el navegador
  async function procesarDescargas() {
    for (let i = 0; i < facturas.length; i++) {
      await descargarArchivo(facturas[i], i);
      // Delay de 500ms entre descargas para no saturar
      await new Promise((resolve) => setTimeout(resolve, 500));
    }

    // Mostrar resumen
    mostrarResumen(exitosas, fallidas, btnProcesar);
  }

  procesarDescargas();
}


function mostrarResumen(exitosas, fallidas, btnProcesar) {
  $("#barraProgreso")
    .css("width", "100%")
    .text("100%")
    .removeClass("progress-bar-animated");
  $("#textoProgreso").text("Proceso completado");

  setTimeout(() => {
    $("#areaProgreso").hide();
    $("#areaResumen").show();

    // Mostrar exitosas
    if (exitosas.length > 0) {
      $("#cardExitosas").show();
      $("#countExitosas").text(exitosas.length);
      const listaHtml = exitosas
        .map(
          (f) =>
            `<li class="mb-1">
                    <i class="fas fa-check text-success"></i> 
                    <strong>Folio:</strong> ${f.folio || "N/A"} | 
                    <strong>UUID:</strong> ${f.uuid}<br>
                    <small class="text-muted">${f.nombre_archivo}</small>
                </li>`,
        )
        .join("");
      $("#listaExitosas").html(listaHtml);
    }

    // Mostrar fallidas
    if (fallidas.length > 0) {
      $("#cardFallidas").show();
      $("#countFallidas").text(fallidas.length);
      const listaHtml = fallidas
        .map(
          (f) =>
            `<li class="mb-1">
                    <i class="fas fa-times text-danger"></i> 
                    <strong>UUID:</strong> ${f.uuid}<br>
                    <small>${f.error || "No encontrada"}</small>
                </li>`,
        )
        .join("");
      $("#listaFallidas").html(listaHtml);
    }

    btnProcesar.prop("disabled", false);

    // Mensaje resumen
    alertify.success(
      `Proceso completado: ${exitosas.length} descargadas, ${fallidas.length} fallidas`,
    );
  }, 500);
}

async function resumen_payment_table() {
  // Destruir tabla existente si existe
  if ($.fn.DataTable.isDataTable("#resumen_payment_table")) {
    $("#resumen_payment_table").DataTable().clear().destroy();
    $("#resumen_payment_table thead .filter").remove();
    $("#resumen_payment_table tbody").empty();
  }

  // Obtener valores de los filtros
  var fromDate = document.getElementById("from_resumen").value;
  var untilDate = document.getElementById("until_resumen").value;
  var codgas = document.getElementById("station_id_resumen").value || "0";
  var proveedor = document.getElementById("proveedor_resumen").value || "0";
  var company = document.getElementById("company_resumen").value || "0";

  // Validación de fechas
  if (!fromDate || !untilDate) {
    alertify.myAlert(
      `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar un rango de fechas para continuar.</p>
            </div>`,
    );
    return;
  }
  $("#resumen_payment_table thead").prepend(
    $("#resumen_payment_table thead tr").clone().addClass("filter"),
  );
  $("#resumen_payment_table thead tr.filter th").each(function (index) {
    col = $("#resumen_payment_table thead th").length / 2;
    if (index < col) {
      var title = $(this).text(); // Obtiene el nombre de la columna
      $(this).html(
        '<input type="text" class="form-control form-control-sm" placeholder=" ' +
          title +
          '" />',
      );
    }
  });
  $("#resumen_payment_table thead tr.filter th input").on(
    "keyup change",
    function () {
      var index = $(this).parent().index(); // Obtiene el índice de la columna
      var table = $("#resumen_payment_table").DataTable(); // Obtiene la instancia de DataTable
      table
        .column(index)
        .search(this.value) // Busca el valor del input
        .draw(); // Redibuja la tabla
    },
  );
  // let movimientoActual = {};

  // Inicializar DataTable
  let resumen_payment_table = $("#resumen_payment_table").DataTable({
    order: [[0, "asc"]],
    scrollY: "700px",
    colReorder: false, // ← Desactivar para evitar problemas de alineación
    fixedHeader: false, // ← Desactivar, usaremos CSS sticky
    dom: '<"top"Bf>rt<"bottom"lip>',
    scrollX: true,
    scrollCollapse: true,
    // responsive: false,
    paging: false,
    autoWidth: false, // ← IMPORTANTE: Desactivar auto width
    buttons: [
      {
        extend: "excel",
        className: "btn btn-success",
        text: " Excel",
      },
      {
        extend: "colvis",
        className: "btn btn-sm btn-secondary",
        text: '<i class="bi bi-eye"></i> Columnas',
      },
    ],
    ajax: {
      method: "POST",
      data: {
        fromDate: fromDate,
        untilDate: untilDate,
        codgas: codgas,
        proveedor: proveedor,
        company: company,
      },
      url: "/payment/resumen_payment_table",
      timeout: 600000,
      error: function (xhr, error, thrown) {
        $(".datatable-wrapper").removeClass("loading");
        alertify.error("Error al cargar datos: " + thrown);
      },
      beforeSend: function () {
        $(".datatable-wrapper").addClass("loading");
      },
      dataSrc: function (json) {
        if (json.data && json.data.length > 0) {
          $("#table-info").html(
            `<i class="bi bi-info-circle"></i> ${json.data.length} registro(s)`,
          );
        }
        return json.data;
      },
    },
    columns: [
      {
        data: null,
        className: "text-center",
        orderable: false,
        width: "120px",
        render: function (data, type, row) {
          let icono = "";
          let tooltipTexto = "";

          if (row.tiene_facturas_asignadas) {
            if (row.tipo_operacion == 2) {
              // Operación con Petrotal
              icono =
                '<i class="fas fa-layer-group text-warning" title="Vía Petrotal"></i>';
              tooltipTexto = `Proveedor → Petrotal → TotalGas\nUsuario: ${row.usuario_asignacion}\nFecha: ${row.fecha_asignacion}`;
            } else {
              // Operación directa
              icono =
                '<i class="fas fa-check-circle text-success" title="Compra Directa"></i>';
              tooltipTexto = `Compra Directa\nUsuario: ${row.usuario_asignacion}\nFecha: ${row.fecha_asignacion}`;
            }
          } else {
            icono =
              '<i class="fas fa-exclamation-circle text-danger" title="Sin asignar"></i>';
            tooltipTexto = "Sin factura asignada";
          }

          const btnAsignar = row.tiene_facturas_asignadas
            ? `<button class="btn btn-sm btn-info" 
                                onclick='abrirModalAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Editar asignación">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" 
                                onclick='eliminarAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>`
            : `<button class="btn btn-sm btn-primary" 
                                onclick='abrirModalAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Asignar facturas">
                            <i class="fas fa-link"></i>
                        </button>`;

          return `<div class="btn-group btn-group-sm" title="${tooltipTexto}">${icono} ${btnAsignar}</div>`;
        },
      },
      { data: "fecha", className: "text-center text-nowrap" },
      { data: "estacion", className: "text-start text-nowrap" },
      { data: "numero_estacion", className: "text-center  text-nowrap" },
      { data: "proveedor_original", className: "text-start text-nowrap" },
      { data: "combustible", className: "text-start text-nowrap" },
      {
        data: "num_fac_proveedor",
        className: "text-start text-nowrap",
        render: function (data, type, row) {
          if (row.tiene_facturas_asignadas && row.tipo_operacion == 2) {
            // Mostrar ambos folios si es operación Petrotal
            return `<small>Prov: ${row.folio_proveedor || "N/A"}<br>Petrotal: ${row.folio_petrotal || "N/A"}</small>`;
          }
          return data || "N/A";
        },
      },
      { data: "proveedor_final", className: "text-start text-nowrap" },
      {
        data: "cantidad_factura_controlgas",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2),
      },
      {
        data: "monto_factura_controlgas",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2, "$"),
      },
      {
        data: "precio_factura_controlgas",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 4),
      },
      {
        data: "uuid",
        className: "text-start text-nowrap",
        render: function (data) {
          if (!data || data === "") {
            return '<span class="badge bg-warning text-dark">Sin UUID</span>';
          }
          return "<small>" + data + "</small>";
        },
      },
      { data: "proveedor_controlgas", className: "text-start text-nowrap" },
      {
        data: "monto_factura_controlgas",
        className: "text-end",
        render: function (data) {
          return data
            ? "$" +
                parseFloat(data).toLocaleString("es-MX", {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2,
                })
            : "$0.00";
        },
      },
      {
        data: "cantidad_factura_controlgas",
        className: "text-end",
        render: function (data) {
          return data
            ? parseFloat(data).toLocaleString("es-MX", {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
              })
            : "0.00";
        },
      },

      { data: "graprd", className: "text-center" },
      { data: "nrotrn", className: "text-center text-nowrap" },
    ],
    columnDefs: [{ targets: "_all", orderable: true }],
    deferRender: true,
    createdRow: function (row, data, dataIndex) {
      if (!data.tiene_factura) {
        $(row).addClass("table-warning");
      }

      // Agregar atributos de datos para fácil acceso
      $(row).attr("data-nrotrn", data.nrotrn);
      $(row).attr("data-codgas", data.numero_estacion);
    },
    initComplete: function () {
      $(".datatable-wrapper").removeClass("loading");
      alertify.success("Tabla cargada exitosamente");
    },
    drawCallback: function (settings) {
      this.api().columns.adjust();
    },
  });
}


// Cargar filtros guardados al cargar la página de reconciliación

async function ModalinvoicePdf(id, data) {
  try {
    console.log("Abriendo modal PDF para factura ID:", id, data);
    $("#ModalinvoicePdf").modal("show"); // Abre el modal

    const response = await fetch("/payment/ModalinvoicePdf", {
      method: "POST",
      headers: {
        Accept: "application/json, text/javascript, */*",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      credentials: "include",
      body: `FacturaId=${id}&data=${encodeURIComponent(JSON.stringify(data))}`,
    });

    const content = await response.text();
    // Inserta el contenido en el modal
    $("#ModalinvoicePdf").find("#ModalinvoicePdfContent").html(content);
  } catch (error) {
    console.error(error);
  }
}


// ==========================================
// FUNCIONES PARA GESTIÓN DE PAGOS
// ==========================================
function showPaymentLoader(
  text = "Procesando pago...",
  subtext = "Por favor espere",
) {
  const loader = document.getElementById("paymentLoader");
  if (loader) {
    loader.querySelector(".payment-loader-text").textContent = text;
    loader.querySelector(".payment-loader-subtext").textContent = subtext;
    loader.classList.add("active");

    // Deshabilitar scroll del body
    document.body.style.overflow = "hidden";
  }
}


/**
 * Oculta el loader
 */
function hidePaymentLoader() {
  const loader = document.getElementById("paymentLoader");
  if (loader) {
    loader.classList.remove("active");

    // Restaurar scroll del body
    document.body.style.overflow = "";
  }
}

// Mejorar generate_payment() existente
async function generatePayment() {
    if (paymentItems.length === 0) {
    alertify.myAlert(
        `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">¡Advertencia!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No hay documentos en el pago.</p>
            </div>`,
    );
    return;
    }

    const primerItem = paymentItems[0];
    const proveedorCodigo =
    primerItem.proveedor_codigo || primerItem.id_control_gas || null;
    var codigo_empresa = primerItem.codigo_empresa || null;

    if (!proveedorCodigo) {
    alertify.error("Error: No se pudo obtener el código del proveedor");
    return;
    }

    // Solicitar comentario
    alertify.prompt(
    "Comentario del Pago",
    "Ingrese un comentario o descripción para este pago:",
    "",
    async function (evt, comment) {
        showPaymentLoader("Creando pago...", "Procesando documentos");

        const scheduledDate = $("#scheduled_payment_date").val() || new Date().toISOString().split("T")[0];
        const paymentData = {
        documentos: paymentItems,
        total_documentos: paymentItems.length,
        total_amount: paymentItems.reduce(
            (sum, item) => sum + (parseFloat(item.total_mostrar != null ? item.total_mostrar : item.total_fac) || 0),
            0,
        ),
        fecha_pago: scheduledDate,
        comment: comment || "Pago programado",
        provider_cod: proveedorCodigo, // ✅ AGREGADO
        provider_name: currentProvider, // ✅ OPCIONAL
        empresa_cod: codigo_empresa, // ✅ AGREGADO
        };

        console.log("📤 Datos enviados:", paymentData); // Debug

        try {
        const response = await fetch("/payment/generate_payment", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(paymentData),
        });

        const data = await response.json();
        hidePaymentLoader();
        if (data.success) {
            alertify.success("Pago creado exitosamente: ID #" + data.payment_id);

            // Limpiar carrito
            paymentItems = [];
            currentProvider = null; // ✅ Resetear proveedor
            renderPaymentItems();
            updatePaymentSummary();

            // Preguntar si desea ver el detalle
            alertify.confirm(
            "¿Ver detalle del pago?",
            "¿Desea ver el detalle del pago creado?",
            function () {
                window.location.href = "/payment/payment_detail/" + data.payment_id;
            },
            function () {
                window.location.href = "/payment/payment_list";
            },
            ).set("labels", { ok: "Ver Detalle", cancel: "Ir a Lista" });
        } else {
            alertify.error("Error: " + data.detail);
        }
        } catch (error) {
        hidePaymentLoader();
        alertify.error("Error de conexión");

        console.error(error);
        }
    },
    function () {
        alertify.message("Operación cancelada");
    },
    );
}


/**
 * Agrega una fila de cabeceras con filtro por columna a una DataTable,
 * igual que la del tab de Pagos: un input de texto por columna visible
 * (placeholder = titulo de la columna) que filtra esa columna. Se reconstruye
 * cuando cambia la visibilidad de columnas. Reutilizable para cualquier tabla.
 *
 * @param {string} tableId  id del <table> (sin #)
 * @param {object} api      instancia DataTables API (this.api() dentro de initComplete)
 */
function addColumnFilters(tableId, api) {
  var settings = api.settings()[0];
  var usaScroll = !!(settings.oScroll.sX || settings.oScroll.sY);

  // Con scrollX/scrollY DataTables clona el thead en un contenedor fijo
  // (.dataTables_scrollHead); la fila de filtros debe ir en ESE header clonado
  // para que sea visible. Sin scroll, va en el thead normal de la tabla.
  function getHeaderContainer() {
    if (usaScroll) {
      return $("#" + tableId + "_wrapper .dataTables_scrollHead thead");
    }
    return $("#" + tableId + " thead");
  }

  function rebuildFilterRow() {
    var $head = getHeaderContainer();
    if (!$head.length) return;

    // Guardar lo que el usuario ya hubiera escrito antes de reconstruir
    var valores = {};
    $head.find("tr.filter th input").each(function () {
      valores[$(this).closest("th").data("col-idx")] = this.value;
    });

    $head.find("tr.filter").remove();

    // Iterar sobre los <th> reales de la fila de cabecera (mas confiable que
    // api.columns().every() durante initComplete, que a veces no itera todavia).
    var $headerCells = $head.find("tr:first th");
    if (!$headerCells.length) return;

    var totalCols = $headerCells.length;
    var $filterRow = $('<tr class="filter"></tr>');
    $headerCells.each(function (colIdx) {
      var title = $(this).text().trim();
      var isLast = colIdx === totalCols - 1;
      // Columnas sin titulo (checkbox, acciones) no llevan input
      if (isLast || title === "") {
        $filterRow.append('<th data-col-idx="' + colIdx + '"></th>');
      } else {
        var $th = $('<th data-col-idx="' + colIdx + '"></th>');
        var $input = $('<input type="text" class="form-control form-control-sm" placeholder="' +
          title + '" style="font-size:.75rem;" />');
        if (valores[colIdx] !== undefined) $input.val(valores[colIdx]);
        $th.append($input);
        $filterRow.append($th);
      }
    });
    $head.append($filterRow);

    $head.find("tr.filter th input").on("keyup change", function () {
      var colIdx = $(this).closest("th").data("col-idx");
      api.column(colIdx).search(this.value).draw();
    });

    // No ordenar al hacer click/escribir sobre la fila de filtros
    $head.find("tr.filter th input").on("click", function (e) {
      e.stopPropagation();
    });
  }

  rebuildFilterRow();
  // Fallback: reconstruir tras el primer render completo, cuando el header
  // (clonado en caso de scroll) ya esta en el DOM. Aplica con y sin scroll.
  setTimeout(rebuildFilterRow, 50);

  // El header clonado se regenera al ajustar columnas (resize, recalculo de
  // anchos). Reinsertamos la fila preservando el texto que el usuario tenia.
  if (usaScroll) {
    api.on("column-sizing.dt", function () {
      rebuildFilterRow();
    });
  }

  api.on("column-visibility.dt", function () {
    rebuildFilterRow();
  });
}


function loadPaymentList() {
  // Conservar el valor de los filtros entre reconstrucciones de la tabla
  const statusFilterPrevValue = $("#payment_status_filter").val() || "";

  if ($.fn.DataTable.isDataTable("#payment_list_table")) {
    $("#payment_list_table").DataTable().destroy();
    $("#payment_list_table thead tr.filter").remove();
  }
  // Los selects de filtro y el botón de refrescar se inyectan dinámicamente en
  // initComplete; quitar los anteriores para que no se dupliquen al reconstruir.
  $("#payment_status_filter").remove();
  $("#payment_list_refresh_btn").remove();

  const status = "all";
  const search = "";

  paymentListTable = $("#payment_list_table").DataTable({
    responsive: true,
    dom: '<"d-flex justify-content-between align-items-center mb-2"Bf>rt<"d-flex justify-content-between align-items-center"<"d-flex align-items-center gap-3"li>p>',
    pageLength: 50,
    lengthMenu: [
      [25, 50, 100, 200, -1],
      [25, 50, 100, 200, "Todos"],
    ],
    language: {
      lengthMenu: "Mostrar _MENU_ registros",
    },
    buttons: [
      {
        extend: "excel",
        text: '<i class="fas fa-file-excel"></i> Excel',
        className: "btn btn-sm btn-success",
        exportOptions: { columns: ":visible:not(:last-child)" },
      },
      {
        extend: "colvis",
        text: '<i class="fas fa-columns"></i> Columnas',
        className: "btn btn-sm btn-outline-secondary",
        columns: ":not(:last-child)",
      },
    ],
    ajax: {
      url: "/payment/payment_list_table",
      type: "POST",
      data: {
        status: status,
        type: "all",
        search: search,
      },
      error: function (xhr, error, thrown) {
        alertify.error("Error al cargar datos: " + thrown);
      },
    },
    columns: [
      {
        data: "id",
        render: function(data, type) {
          if (type === 'display') return data;
          return parseInt(data) || 0;
        }
      },
      { data: "request_date" },
      {
        data: "scheduled_payment_date",
        render: function (data, type) {
          // Para ordenar: convertir dd/mm/yyyy a yyyymmdd numérico
          if (type === "sort" || type === "type") {
            if (!data) return 0;
            var p = data.split("/");
            return parseInt(p[2] + p[1] + p[0], 10) || 0;
          }
          if (!data) return '<span class="text-muted">-</span>';
          return '<span class="text-primary fw-semibold">' + data + "</span>";
        },
      },
      { data: "emp_name" },
      { data: "provider_name" },
      { data: "usuario" },
      { data: "total_invoices", className: "text-center" },
      { data: "total_amount", className: "text-end" },
      {
        data: "total_notas_credito",
        className: "text-end",
        render: function (data) {
          var val = parseFloat(data) || 0;
          if (val > 0) {
            return '<small class="text-success">-$' + val.toLocaleString("es-MX", { minimumFractionDigits: 2 }) + "</small>";
          }
          return '<small class="text-muted">$0.00</small>';
        },
      },
      {
        data: "total_notas_cargo",
        className: "text-end",
        render: function (data) {
          var val = parseFloat(data) || 0;
          if (val > 0) {
            return '<small class="text-danger">+$' + val.toLocaleString("es-MX", { minimumFractionDigits: 2 }) + "</small>";
          }
          return '<small class="text-muted">$0.00</small>';
        },
      },
      { data: "monto_neto", className: "text-end fw-bold" },
      { data: "total_paid", className: "text-end" },
      {
        data: null,
        className: "text-center",
        render: function (data, type, row) {
          // Anticipos no tienen facturas individuales autorizadas
          if (parseInt(row.tipo) === 1) {
            return '<span class="text-muted" style="font-size:.8rem;">—</span>';
          }
          var count = parseInt(row.authorized_invoices_count) || 0;
          var rawAmount = row.authorized_amount_total || "0";
          rawAmount = rawAmount.replace(/[$,]/g, "");
          var authorized_amount_total = parseFloat(rawAmount) || 0;
          if (count === 0) {
            return '<span class="badge bg-secondary">Sin autorizar</span>';
          }

          // Calcular porcentaje por conteo de facturas
          var totalInvoices = parseInt(row.total_invoices) || 0;
          var percentage =
            totalInvoices > 0 ? Math.round((count / totalInvoices) * 100) : 0;

          // Aunque todas las facturas estén marcadas como autorizadas (100% por
          // conteo), el monto autorizado puede ser menor al monto NETO (total -
          // NC + ND) si alguna factura fue autorizada por un pago parcial:
          // comparamos contra el neto, no el bruto, para no marcar como
          // incompleto algo que ya está saldado por nota de crédito (se
          // refleja en la columna "Faltante" de al lado, no aquí).
          var rawNeto = (row.monto_neto || "0").toString().replace(/[$,]/g, "");
          var montoNeto = parseFloat(rawNeto) || 0;
          var montoCompleto = montoNeto > 0 && authorized_amount_total >= montoNeto - 0.01;

          let badgeColor = "bg-warning";
          let icon = "fa-check-circle";
          if (percentage === 100 && montoCompleto) {
            badgeColor = "bg-success";
          } else if (percentage === 100 && !montoCompleto) {
            badgeColor = "bg-warning";
            icon = "fa-exclamation-triangle";
          } else if (percentage >= 50) {
            badgeColor = "bg-warning";
          }
          return `
                        <div class="text-center">
                            <span class="badge ${badgeColor}" style="font-size: 0.85rem;">
                                <i class="fas ${icon}"></i> ${count} de ${totalInvoices}
                            </span>
                            <br>
                            <small class="text-success fw-bold">
                                $${authorized_amount_total.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
                            </small>
                        </div>
                    `;
        },
      },
      {
        data: null,
        className: "text-center",
        render: function (data, type, row) {
          if (parseInt(row.tipo) === 1) {
            return '<span class="text-muted" style="font-size:.8rem;">—</span>';
          }
          var rawAuth = (row.authorized_amount_total || "0").toString().replace(/[$,]/g, "");
          var authorizedTotal = parseFloat(rawAuth) || 0;
          // Comparar contra el monto NETO (total_amount - NC + ND), no el bruto:
          // una factura ya saldada por nota de crédito no debe contar como
          // "falta autorizar" solo porque authorized_amount quedó por debajo
          // del monto bruto de la factura.
          var rawNeto = (row.monto_neto || "0").toString().replace(/[$,]/g, "");
          var montoNeto = parseFloat(rawNeto) || 0;
          var faltante = montoNeto - authorizedTotal;

          if (faltante <= 0.01) {
            return '<span class="text-muted" style="font-size:.8rem;">$0.00</span>';
          }
          if (authorizedTotal <= 0.01) {
            // Nada autorizado todavía: es el estado normal de un pago sin tocar,
            // no una alerta de autorización parcial incompleta.
            return `<span class="text-muted" style="font-size:.8rem;">$${faltante.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</span>`;
          }
          // Ya se autorizó una parte pero no cubre el total: sí es una alerta real.
          return `<span class="text-danger fw-bold" style="font-size:.85rem;" title="Ya se autorizó una parte; falta autorizar/pagar esta diferencia">
                        <i class="fas fa-exclamation-triangle"></i> $${faltante.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
                    </span>`;
        },
      },
      { data: "status", name: "status", className: "text-center" },
      { data: "authorizations", className: "text-center" },
      { data: "comment" },
      { data: "pdf_status", name: "pdf_status", visible: false },
      { data: "actions", orderable: false, className: "text-center" },
    ],
    order: [[2, "desc"]],
    columnDefs: [
      { targets: [5, 6, 7, 8, 9], visible: false },
    ],

    drawCallback: function () {
      // Inicializar tooltips de Bootstrap en los auth-boxes renderizados dinámicamente
      var tooltipEls = document.querySelectorAll('#payment_list_table [data-bs-toggle="tooltip"]');
      tooltipEls.forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
      });
    },
    initComplete: function () {
      var api = this.api();

      // Select de filtro por columna "Estado" (Pendiente/Autorizado/Pagado/Cancelado)
      // junto al filtro de PDF. Busca el texto del badge ya renderizado en la celda.
      var statusColIdx = api.column('status:name').index();
      var $statusSelect = $(
        '<select id="payment_status_filter" class="form-select form-select-sm ms-2" style="width:auto;display:inline-block;" title="Filtrar por Estado">' +
        '<option value="">Todos los estados</option>' +
        '<option value="Pendiente">Pendiente</option>' +
        '<option value="Autorizado">Autorizado</option>' +
        '<option value="Pagado">Pagado</option>' +
        '<option value="Cancelado">Cancelado</option>' +
        '</select>'
      );
      $statusSelect.val(statusFilterPrevValue);
      $(".dt-buttons").append($statusSelect);
      if (statusFilterPrevValue) {
        api.column(statusColIdx).search(statusFilterPrevValue);
      }
      $statusSelect.on("change", function () {
        api.column(statusColIdx).search(this.value).draw();
      });

      // Botón para refrescar la tabla (vuelve a pedir los datos al servidor)
      var $refreshBtn = $(
        '<button type="button" id="payment_list_refresh_btn" class="btn btn-sm btn-outline-secondary ms-2" title="Refrescar tabla">' +
        '<i class="fas fa-sync-alt"></i>' +
        '</button>'
      );
      $(".dt-buttons").append($refreshBtn);
      $refreshBtn.on("click", function () {
        api.ajax.reload(null, false);
      });

      function rebuildFilterRow() {
        $("#payment_list_table thead tr.filter").remove();
        var $filterRow = $('<tr class="filter"></tr>');
        api.columns(':visible').every(function() {
          var colIdx = this.index();
          var title = $(this.header()).text().trim();
          var isLast = colIdx === api.columns().count() - 1;
          if (isLast) {
            $filterRow.append('<th data-col-idx="' + colIdx + '"></th>');
          } else {
            $filterRow.append('<th data-col-idx="' + colIdx + '"><input type="text" placeholder="' + title + '" /></th>');
          }
        });
        $("#payment_list_table thead").append($filterRow);

        $("#payment_list_table thead tr.filter th input").on("keyup change", function () {
          var colIdx = $(this).closest("th").data("col-idx");
          api.column(colIdx).search(this.value).draw();
        });
      }

      rebuildFilterRow();

      // Reconstruir filtros cada vez que cambia visibilidad de columnas
      api.on('column-visibility.dt', function () {
        rebuildFilterRow();
      });
    },
  });
}


// ===== Panel expandible (child row) con las facturas de cada pago =====
function formatPaymentInvoicesChild(invoices) {
  if (!invoices || invoices.length === 0) {
    return '<div class="p-3 text-center text-muted"><i class="fas fa-inbox"></i> Esta requisición no tiene facturas.</div>';
  }

  function fmtMoney(v) {
    return "$" + (parseFloat(v) || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 });
  }
  function statusBadge(s) {
    if (s === 2) return '<span class="badge" style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;">Pagado</span>';
    if (s === 3) return '<span class="badge" style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;">Pago Parcial</span>';
    return '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Pendiente</span>';
  }
  function archivoBadge(inv) {
    if (parseInt(inv.is_debit_note) === 1) {
      if (inv.nota_doc_path) {
        return (
          '<button type="button" class="btn btn-sm" style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;" ' +
          'title="Ver documento nota de cargo" ' +
          "onclick=\"window.open('/payment/view_note_doc/" + inv.nota_id + "', '_blank')\">" +
          '<i class="fas fa-file-pdf"></i> Ver ND' +
          "</button>"
        );
      }
      return '<span class="badge" style="background:#fef9c3;color:#854d0e;" title="Sin documento adjunto para esta nota de cargo"><i class="fas fa-file"></i> Sin doc</span>';
    }
    var botones = [];
    if (inv.tiene_archivo && inv.fr_id) {
      botones.push(
        '<button type="button" class="btn btn-sm w-100 d-flex align-items-center justify-content-center gap-1" style="background:#059669;color:#fff;border:1px solid #059669;" ' +
        'title="' + (inv.nombre_archivo || "Ver factura") + '" ' +
        "onclick='ModalinvoicePdf(" + inv.fr_id + ", {})'>" +
        '<i class="fas fa-file-pdf"></i> Factura' +
        "</button>"
      );
    }
    (inv.comprobantes || []).forEach(function (c) {
      botones.push(
        '<button type="button" class="btn btn-sm btn-comprobante-outline w-100 d-flex align-items-center justify-content-center gap-1" ' +
        'title="' + (c.nombre || "Ver comprobante") + '" ' +
        "onclick=\"window.open('/payment/view_payment_document/" + c.doc_id + "', '_blank')\">" +
        '<i class="fas fa-receipt"></i> Comprobante' +
        "</button>"
      );
    });
    if (botones.length > 0) {
      return '<div class="d-flex flex-column gap-1 align-items-stretch" style="min-width:110px;">' + botones.join("") + '</div>';
    }
    return '<span class="badge bg-danger" title="No se ha recibido el archivo de esta factura"><i class="fas fa-exclamation-triangle"></i> Sin archivo</span>';
  }

  function ncBadge(v) {
    v = parseFloat(v) || 0;
    if (v <= 0) return '<span class="text-muted" style="font-size:.75rem;">—</span>';
    return '<span style="color:#16a34a;font-size:.78rem;">-' + fmtMoney(v) + '</span>';
  }
  function ndBadge(v) {
    v = parseFloat(v) || 0;
    if (v <= 0) return '<span class="text-muted" style="font-size:.75rem;">—</span>';
    return '<span style="color:#dc2626;font-size:.78rem;">+' + fmtMoney(v) + '</span>';
  }

  var rows = invoices.map(function (inv) {
    var esNotaCargo = parseInt(inv.is_debit_note) === 1;
    var venc = inv.expiration_date ? new Date(inv.expiration_date).toLocaleDateString("es-MX") : '<span class="text-muted">-</span>';
    var autorizada = inv.payment_authorized === 1;
    var nc = parseFloat(inv.total_notas_credito) || 0;
    var nd = parseFloat(inv.total_notas_cargo) || 0;
    var tieneNotas = nc > 0 || nd > 0;
    var saldoNeto = parseFloat(inv.saldo_neto);

    var rowStyle = esNotaCargo
      ? ' style="background:#f0fdf4;"'
      : (autorizada ? ' class="table-success"' : "");

    var folioCell;
    if (esNotaCargo) {
      folioCell =
        "<span class='badge' style='background:#dcfce7;color:#166534;font-size:.75rem;'>ND</span> " +
        "<strong>" + (inv.folio || "") + "</strong>";
    } else {
      folioCell =
        "<strong>" + (inv.folio || "") + "</strong><br>" +
        "<small class='text-muted'>" + (inv.invoice_number || "") + "</small>";
    }

    var montoCell;
    if (esNotaCargo) {
      montoCell = "<span style='color:#16a34a;font-weight:600;'>+" + fmtMoney(inv.amount) + "</span>";
    } else {
      montoCell = fmtMoney(inv.amount);
    }

    var saldoCell;
    if (esNotaCargo) {
      saldoCell = "<span style='color:#16a34a;font-weight:600;'>+" + fmtMoney(inv.amount) + "</span>";
    } else if (tieneNotas) {
      saldoCell = "<span style='color:#2563eb;'>" + fmtMoney(saldoNeto) + "</span>";
    } else {
      saldoCell = inv.saldo > 0
        ? "<strong class='text-danger'>" + fmtMoney(inv.saldo) + "</strong>"
        : "<span class='text-success'>$0.00</span>";
    }

    return (
      "<tr" + rowStyle + ">" +
        "<td class='text-center'>" + (autorizada ? '<i class="fas fa-check-circle text-success" title="Autorizada"></i>' : '<i class="fas fa-circle fa-sm text-muted"></i>') + "</td>" +
        "<td>" + folioCell + "</td>" +
        "<td class='text-truncate' style='max-width:140px;' title='" + (inv.proveedor_nombre || "") + "'>" + (inv.proveedor_nombre || "") + "</td>" +
        "<td>" + (inv.estacion_nombre || "") + "</td>" +
        "<td class='text-end'>" + montoCell + "</td>" +
        "<td class='text-end'>" + (esNotaCargo ? '<span class="text-muted" style="font-size:.75rem;">—</span>' : ncBadge(nc)) + "</td>" +
        "<td class='text-end'>" + (esNotaCargo ? '<span class="text-muted" style="font-size:.75rem;">—</span>' : ndBadge(nd)) + "</td>" +
        "<td class='text-end fw-bold'>" + saldoCell + "</td>" +
        "<td>" + statusBadge(inv.status) + "</td>" +
        "<td class='text-center'>" + venc + "</td>" +
        "<td class='text-center'>" + archivoBadge(inv) + "</td>" +
      "</tr>"
    );
  }).join("");

  return (
    "<div class='p-2' style='background:#f8fafc;'>" +
      "<table class='table table-sm table-hover mb-0' style='font-size:.8rem;'>" +
        "<thead class='table-light'><tr>" +
          "<th width='32'></th><th>Folio / Factura</th><th>Proveedor</th><th>Estación</th>" +
          "<th class='text-end'>Monto</th>" +
          "<th class='text-end' title='Notas de Crédito' style='color:#16a34a;'>NC</th>" +
          "<th class='text-end' title='Notas de Cargo' style='color:#dc2626;'>ND</th>" +
          "<th class='text-end'>Saldo Neto</th>" +
          "<th>Estado</th><th class='text-center'>Vencimiento</th><th class='text-center'>Archivo</th>" +
        "</tr></thead>" +
        "<tbody>" + rows + "</tbody>" +
      "</table>" +
    "</div>"
  );
}

// Delegación de evento: toggle del panel de facturas al presionar el botón ℹ️
$(document).on("click", "#payment_list_table .btn-toggle-invoices", function () {
  var $btn = $(this);
  var table = $("#payment_list_table").DataTable();
  var $tr = $btn.closest("tr");
  var row = table.row($tr);
  var $icon = $btn.find("i");

  if (row.child.isShown()) {
    // Ya está abierto → retraer
    row.child.hide();
    $tr.removeClass("shown");
    $icon.removeClass("fa-chevron-up").addClass("fa-info-circle");
    return;
  }

  // Abrir: mostrar loader y pedir las facturas
  row.child('<div class="p-3 text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>').show();
  $tr.addClass("shown");
  $icon.removeClass("fa-info-circle").addClass("fa-chevron-up");

  var paymentId = $btn.data("payment-id");
  var rowData   = table.row($tr).data();
  var esAnticipo = parseInt(rowData.tipo) === 1;

  if (esAnticipo) {
    // Child row para anticipos: mostrar resumen de saldo
    $.getJSON("/payment/anticipo_summary_json/" + paymentId)
      .done(function(resp) {
        if (resp && resp.success) {
          var d = resp.data;
          var fmt = function(v) { return '$' + parseFloat(v||0).toLocaleString('es-MX', {minimumFractionDigits:2}); };

          // Si el anticipo ya se pagó, mostrar el/los comprobantes en lugar de "Ver detalle"
          // (el detalle sigue accesible con el botón del ojo en la columna de acciones)
          var accion;
          if (d.comprobantes && d.comprobantes.length > 0) {
            accion = d.comprobantes.map(function(c) {
              var nombre = (c.original_filename || "Comprobante de pago").replace(/'/g, "");
              return "<button type='button' class='btn btn-sm' style='background:#ecfdf5;color:#059669;border:1px solid #a7f3d0;border-radius:4px;font-size:.75rem;' " +
                "title='" + nombre + "' " +
                "onclick=\"window.open('/payment/view_payment_document/" + c.doc_id + "', '_blank')\">" +
                "<i class='fas fa-receipt me-1'></i>Comprobante</button>";
            }).join(" ");
            accion = "<span class='ms-auto d-flex gap-1'>" + accion + "</span>";
          } else {
            accion = "<a href='/payment/anticipo_detail/" + paymentId + "' class='btn btn-sm ms-auto' style='background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:4px;font-size:.75rem;'><i class='fas fa-eye me-1'></i>Ver detalle</a>";
          }

          // Tabla de facturas ligadas al anticipo, con su archivo como en las líneas de pago
          var tablaAplicaciones = "";
          if (d.aplicaciones && d.aplicaciones.length > 0) {
            var filas = d.aplicaciones.map(function (a) {
              var archivo = a.fr_id
                ? "<button type='button' class='btn btn-sm' style='background:#059669;color:#fff;border:1px solid #059669;' " +
                  "title='" + (a.nombre_archivo || "Ver factura").replace(/'/g, "") + "' " +
                  "onclick='ModalinvoicePdf(" + a.fr_id + ", {})'>" +
                  "<i class='fas fa-file-pdf'></i> Factura</button>"
                : "<span class='badge bg-danger' title='No se ha recibido el archivo de esta factura'><i class='fas fa-exclamation-triangle'></i> Sin archivo</span>";
              var req = a.pago_id
                ? "<a href='/payment/payment_detail/" + a.pago_id + "' target='_blank' class='text-primary text-decoration-none fw-semibold'>#" + a.pago_id + "</a>"
                : "—";
              var fecha = a.fecha_aplicacion
                ? new Date(a.fecha_aplicacion).toLocaleDateString("es-MX")
                : "—";
              return (
                "<tr>" +
                  "<td><strong>" + (a.folio || "") + "</strong><br><small class='text-muted'>" + (a.invoice_number || "") + "</small></td>" +
                  "<td>" + (a.estacion_nombre || "—") + "</td>" +
                  "<td class='text-center'>" + req + "</td>" +
                  "<td class='text-end'>" + fmt(a.monto_factura) + "</td>" +
                  "<td class='text-end fw-bold text-danger'>" + fmt(a.monto_aplicado) + "</td>" +
                  "<td class='text-center'>" + fecha + "</td>" +
                  "<td><small>" + (a.aplicado_por_nombre || "—") + "</small></td>" +
                  "<td class='text-center'>" + archivo + "</td>" +
                "</tr>"
              );
            }).join("");

            tablaAplicaciones =
              "<div class='mt-3'>" +
                "<table class='table table-sm table-hover mb-0' style='font-size:.8rem;background:#fff;'>" +
                  "<thead class='table-light'><tr>" +
                    "<th>Folio / Factura</th><th>Estación</th><th class='text-center'>Requisición</th>" +
                    "<th class='text-end'>Monto Factura</th><th class='text-end'>Aplicado</th>" +
                    "<th class='text-center'>Fecha Aplicación</th><th>Aplicó</th><th class='text-center'>Archivo</th>" +
                  "</tr></thead>" +
                  "<tbody>" + filas + "</tbody>" +
                "</table>" +
              "</div>";
          }

          var html =
            "<div class='p-3' style='background:#fffbeb;border-top:2px solid #fcd34d;'>" +
            "<div class='d-flex flex-wrap gap-4 align-items-center'>" +
            "<span style='font-size:.7rem;font-weight:700;text-transform:uppercase;color:#92400e;letter-spacing:.06em;'><i class='fas fa-hand-holding-usd me-1'></i>Anticipo</span>" +
            "<span style='font-size:.8rem;color:#334155;'><strong>Monto original:</strong> " + fmt(d.monto_original) + "</span>" +
            "<span style='font-size:.8rem;color:#334155;'><strong>Aplicado:</strong> <span class='text-danger'>" + fmt(d.total_aplicado) + "</span></span>" +
            "<span style='font-size:.8rem;color:#334155;'><strong>Saldo disponible:</strong> <span class='fw-bold text-success'>" + fmt(d.saldo_disponible) + "</span></span>" +
            "<span style='font-size:.8rem;color:#64748b;'><i class='fas fa-layer-group me-1'></i>" + (d.total_aplicaciones || 0) + " aplicación(es)</span>" +
            accion +
            "</div>" +
            tablaAplicaciones +
            "</div>";
          row.child(html).show();
        } else {
          row.child('<div class="p-3 text-danger">Error al cargar datos del anticipo.</div>').show();
        }
      })
      .fail(function() {
        row.child('<div class="p-3 text-danger">Error de conexión.</div>').show();
      });
  } else {
    // Child row para pagos normales: lista de facturas
    $.getJSON("/payment/payment_invoices_json/" + paymentId)
      .done(function (resp) {
        if (resp && resp.success) {
          row.child(formatPaymentInvoicesChild(resp.data)).show();
        } else {
          row.child('<div class="p-3 text-danger">Error: ' + (resp.message || "No se pudieron cargar las facturas") + "</div>").show();
        }
      })
      .fail(function () {
        row.child('<div class="p-3 text-danger">Error de conexión al cargar las facturas.</div>').show();
      });
  }
});

// /DEV-ONLY ↑


// ===== Botón "Mandar a pagos" (Abastos): agrupa requisiciones del día + notifica =====
function sendToPayments(btn) {
  alertify.confirm(
    "Mandar requisiciones a pagos",
    "Se agruparán las requisiciones de hoy por empresa y se enviará un correo de solicitud a los destinatarios. ¿Continuar?",
    function () {
      var $btn = $(btn);
      var htmlOriginal = $btn.html();
      $btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');

      $.ajax({
        url: "/payment/send_to_payments",
        type: "POST",
        dataType: "json",
        timeout: 120000,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            alertify.success(resp.message || "Requisiciones enviadas a pagos");
            if (typeof tablaArchivosContabilidad !== "undefined" && tablaArchivosContabilidad) {
              tablaArchivosContabilidad.ajax.reload(null, false);
            }
          } else if (resp && resp.mail_failed) {
            // La agrupación SÍ ocurrió, pero el correo falló. Mostrar el motivo
            // completo y aclarar que se puede reenviar.
            Swal.fire({
              icon: "warning",
              title: "Se agrupó, pero el correo no se envió",
              html:
                "<div style='text-align:left;white-space:pre-line;font-size:.9rem;'>" +
                $("<div>").text(resp.message || "").html() +
                "</div>" +
                (resp.mail_error
                  ? "<hr><details style='text-align:left;font-size:.78rem;color:#64748b;'><summary>Detalle técnico</summary><code>" +
                    $("<div>").text(resp.mail_error).html() +
                    "</code></details>"
                  : ""),
              confirmButtonText: "Entendido",
              width: 620,
            });
          } else {
            Swal.fire({
              icon: "error",
              title: "No se pudo completar",
              html:
                "<div style='text-align:left;white-space:pre-line;font-size:.9rem;'>" +
                $("<div>").text((resp && resp.message) || "Error desconocido").html() +
                "</div>",
              confirmButtonText: "Cerrar",
              width: 600,
            });
          }
        })
        .fail(function (xhr) {
          var msg = "Error de conexión con el servidor.";
          if (xhr && xhr.statusText === "timeout") {
            msg = "La operación tardó demasiado y se agotó el tiempo de espera. Las requisiciones pueden haberse agrupado; verifica antes de reintentar.";
          } else if (xhr && xhr.status) {
            msg += " (HTTP " + xhr.status + ")";
          }
          Swal.fire({ icon: "error", title: "Error de conexión", text: msg });
        })
        .always(function () {
          $btn.prop("disabled", false).html(htmlOriginal);
        });
    },
    function () { /* cancelado */ }
  ).set("labels", { ok: "Sí, mandar", cancel: "Cancelar" });
}


// ===== Botón "Reenviar correo" (solo Id 6296): reenvía el correo de los pagos
// cerrados hoy (por agrupación de contabilidad) SIN volver a agrupar. =====
function resendTodayPayments(btn) {
  alertify.confirm(
    "Reenviar correo de pagos de hoy",
    "Se reenviará el correo de solicitud con los pagos que se <strong>cerraron hoy</strong> (agrupados en contabilidad). <strong>No</strong> se volverá a agrupar ni cerrar nada. ¿Continuar?",
    function () {
      var $btn = $(btn);
      var htmlOriginal = $btn.html();
      $btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Reenviando...');

      $.ajax({
        url: "/payment/resend_today_payments",
        type: "POST",
        dataType: "json",
        timeout: 120000,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            alertify.success(resp.message || "Correo reenviado");
          } else {
            Swal.fire({
              icon: resp && resp.mail_failed ? "warning" : "error",
              title: "No se pudo reenviar",
              html:
                "<div style='text-align:left;white-space:pre-line;font-size:.9rem;'>" +
                $("<div>").text((resp && resp.message) || "Error desconocido").html() +
                "</div>" +
                (resp && resp.mail_error
                  ? "<hr><details style='text-align:left;font-size:.78rem;color:#64748b;'><summary>Detalle técnico</summary><code>" +
                    $("<div>").text(resp.mail_error).html() +
                    "</code></details>"
                  : ""),
              confirmButtonText: "Cerrar",
              width: 620,
            });
          }
        })
        .fail(function (xhr) {
          var msg = "Error de conexión con el servidor.";
          if (xhr && xhr.status) msg += " (HTTP " + xhr.status + ")";
          Swal.fire({ icon: "error", title: "Error de conexión", text: msg });
        })
        .always(function () {
          $btn.prop("disabled", false).html(htmlOriginal);
        });
    },
    function () { /* cancelado */ }
  ).set("labels", { ok: "Sí, reenviar", cancel: "Cancelar" });
}


// ===== Botón "Mandar pagos": envía correo con los pagos listos (dot verde) =====
function sendReadyPayments(btn) {
  alertify.confirm(
    "Mandar pagos a solicitud",
    "Se enviará un correo con todos los pagos que tienen <strong>todas sus facturas en PDF</strong> y están pendientes. ¿Continuar?",
    function () {
      var $btn = $(btn);
      var htmlOriginal = $btn.html();
      $btn.prop("disabled", true).html('<i class="fas fa-spinner fa-spin"></i> Enviando...');

      $.ajax({
        url: "/payment/send_ready_payments",
        type: "POST",
        dataType: "json",
      })
        .done(function (resp) {
          if (resp && resp.success) {
            alertify.success(resp.message || "Correo enviado correctamente");
          } else {
            alertify.error((resp && resp.message) || "No se pudo enviar el correo");
          }
        })
        .fail(function () {
          alertify.error("Error de conexión al mandar los pagos");
        })
        .always(function () {
          $btn.prop("disabled", false).html(htmlOriginal);
        });
    },
    function () {
      /* cancelado */
    }
  ).set("labels", { ok: "Sí, enviar", cancel: "Cancelar" });
}


function loadAnticiposList() {
  if ($.fn.DataTable.isDataTable("#tabla_anticipos")) {
    $("#tabla_anticipos").DataTable().destroy();
  }

  const status = $("#status_filter_anticipos").val();
  paymentListTable = $("#tabla_anticipos").DataTable({
    dom: '<"top"f>rt<"bottom"lip>',
    ajax: {
      url: "/payment/loadAnticiposList",
      type: "POST",
      data: {
        status: status,
        type: "anticipos",
      },
      error: function (xhr, error, thrown) {
        alertify.error("Error al cargar datos: " + thrown);
      },
    },
    columns: [
      { data: "id" },
      { data: "request_date" },
      { data: "emp_name" },
      { data: "provider_name" },
      { data: "usuario" },
      {
        data: "total_invoices",
        className: "text-center",
        render: function (data) {
          return data > 0
            ? '<span class="badge bg-info">' + data + "</span>"
            : '<span class="badge bg-secondary">0</span>';
        },
      },
      { data: "total_amount", className: "text-end" },
      { data: "total_aplicado", className: "text-end" }, // ✅ CAMBIO
      {
        data: "saldo_disponible",
        className: "text-end",
        render: function (data) {
          const saldo = parseFloat(data.replace(/[$,]/g, ""));
          const color = saldo > 0 ? "success" : "secondary";
          return '<strong class="text-' + color + '">' + data + "</strong>";
        },
      }, // ✅ NUEVA
      { data: "status", className: "text-center" },
      { data: "authorizations", className: "text-center" },
      { data: "comment" },
      { data: "actions", orderable: false, className: "text-center" },
    ],
    order: [[0, "desc"]],
  });
}

async function abrirModalCrearAnticipo() {
  try {
    // Abrir modal inmediatamente
    $("#modalCrearAnticipo").modal("show");

    // Mostrar loader
    $("#modalCrearAnticipoContent").html(`
            <div class="modal-body text-center py-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Cargando formulario...</p>
            </div>
        `);

    // Cargar contenido del modal
    const response = await fetch("/payment/modalCrearAnticipo", {
      method: "POST",
      headers: {
        Accept: "application/json, text/javascript, */*",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      credentials: "include",
    });

    if (!response.ok) {
      throw new Error("Error al cargar el formulario");
    }

    const content = await response.text();
    $("#modalCrearAnticipoContent").html(content);

    // Reinicializar selectpicker si existe
    if ($.fn.selectpicker) {
      $(".selectpicker").selectpicker();
    }
  } catch (error) {
    console.error("Error:", error);
    $("#modalCrearAnticipoContent").html(`
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Error al cargar el formulario. Por favor intente nuevamente.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        `);
  }
}

$(document).on(
  "change input",
  "#anticipo_proveedor, #anticipo_empresa, #anticipo_monto",
  function () {
    actualizarResumenAnticipo();
  },
);

$(document).on("input", "#anticipo_comentario", function () {
  const length = $(this).val().length;
  $("#comentario_chars").text(length);
});


function actualizarResumenAnticipo() {
  const proveedor = $("#anticipo_proveedor option:selected").text();
  const empresa = $("#anticipo_empresa option:selected").text();
  const monto = parseFloat($("#anticipo_monto").val()) || 0;

  if (proveedor && proveedor !== "Seleccione un proveedor" && monto > 0) {
    $("#resumen_proveedor").text(proveedor);
    $("#resumen_empresa").text(empresa || "No seleccionada");
    $("#resumen_monto").text(
      "$" +
        monto.toLocaleString("es-MX", {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }),
    );
    $("#resumen_anticipo").slideDown();
  } else {
    $("#resumen_anticipo").slideUp();
  }
}


/**
 * Confirmar creación del anticipo
 */
function confirmarCreacionAnticipo() {
  // Validar formulario
  const proveedor_cod = $("#anticipo_proveedor").val();
  const empresa_cod = $("#anticipo_empresa").val();
  const monto = parseFloat($("#anticipo_monto").val());
  const comentario = $("#anticipo_comentario").val().trim();
  const fecha_pago = $("#anticipo_fecha_pago").val();

  // Validaciones
  if (!proveedor_cod) {
    alertify.error("Debe seleccionar un proveedor");
    return;
  }

  if (!empresa_cod) {
    alertify.error("Debe seleccionar una empresa");
    return;
  }

  if (!monto || monto <= 0) {
    alertify.error("El monto debe ser mayor a cero");
    return;
  }

  if (!fecha_pago) {
    alertify.error("Debe ingresar la fecha de pago deseada");
    return;
  }

  // Obtener nombres para confirmación
  const proveedor_nombre = $("#anticipo_proveedor option:selected").text();
  const empresa_nombre = $("#anticipo_empresa option:selected").text();

  // Confirmar con el usuario
  alertify
    .confirm(
      "Confirmar Anticipo",
      `<div class="text-center">
            <p class="mb-3">¿Crear anticipo con los siguientes datos?</p>
            <div class="alert alert-info mb-3" style="display: flex;    flex-direction: column;">
                <strong>Proveedor:</strong> ${proveedor_nombre}<br>
                <strong>Empresa:</strong> ${empresa_nombre}<br>
                <strong>Monto:</strong> <span class="h5 text-primary">$${monto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</span>
            </div>
            <small class="text-muted">Este anticipo requerirá autorización de 3 niveles antes de poder aplicarse.</small>
        </div>`,
      function () {
        ejecutarCreacionAnticipo(proveedor_cod, empresa_cod, monto, comentario, fecha_pago);
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Crear Anticipo", cancel: "Cancelar" });
}


/**
 * Ejecutar creación del anticipo (AJAX)
 */
/////comentario anticipo
async function ejecutarCreacionAnticipo(
  proveedor_cod,
  empresa_cod,
  monto,
  comentario,
  fecha_pago,
) {
  try {
    // Deshabilitar botón
    $("#btnConfirmarAnticipo")
      .prop("disabled", true)
      .html('<i class="fas fa-spinner fa-spin"></i> Creando...');

    const response = await fetch("/payment/create_anticipo", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        provider_cod: proveedor_cod,
        empresa_cod: empresa_cod,
        monto: monto,
        comentario: comentario,
        fecha_pago: fecha_pago,
      }),
    });

    const data = await response.json();
    console.log("Respuesta del servidor:", data); // Debug
    if (data.success) {
      console.log("Anticipo creado exitosamente:", data);
      // Cerrar modal
      $("#modalCrearAnticipo").modal("hide");

      // Mensaje de éxito
      alertify.success(
        "✓ Anticipo creado exitosamente: ID #" + data.anticipo_id,
      );

      // Recargar tabla unificada de pagos
      loadPaymentList();

      // Preguntar si desea ver el detalle
      setTimeout(() => {
        alertify
          .confirm(
            "¿Ver detalle?",
            "¿Desea ver el detalle del anticipo creado?",
            function () {
              window.location.href =
                "/payment/payment_detail/" + data.anticipo_id;
            },
            function () {
              // No hacer nada
            },
          )
          .set("labels", { ok: "Ver Detalle", cancel: "Cerrar" });
      }, 500);
    } else {
      alertify.error(data.message || "Error al crear anticipo");
      $("#btnConfirmarAnticipo")
        .prop("disabled", false)
        .html('<i class="fas fa-save"></i> Crear Anticipo');
    }
  } catch (error) {
    console.error("Error:", error);
    alertify.error("Error de conexión al crear anticipo");
    $("#btnConfirmarAnticipo")
      .prop("disabled", false)
      .html('<i class="fas fa-save"></i> Crear Anticipo');
  }
}




function toggleSelectAll() {
  console.log("toggleSelectAll");
  const selectAllCheckbox = $("#selectAllCheckbox");
  const isChecked = selectAllCheckbox.prop("checked");
  $(".invoice-checkbox:visible").prop("checked", isChecked);
  updateSelectedCount();
}


// Actualizar contador de facturas seleccionadas
function updateSelectedCount() {
  const count = $(".invoice-checkbox:checked").length;
  $("#selected-count").text(count + " seleccionada" + (count !== 1 ? "s" : ""));

  const totalVisible = $(".invoice-checkbox:visible").length;
  const totalChecked = $(".invoice-checkbox:checked").length;
  $("#selectAllCheckbox").prop(
    "checked",
    totalVisible > 0 && totalVisible === totalChecked,
  );
}


function addSelectedInvoices() {
  const table = $("#payment_create_table").DataTable();
  const selectedRows = [];

  // Obtener todas las filas con checkbox marcado
  table.$('input[type="checkbox"].invoice-checkbox:checked').each(function () {
    const row = table.row($(this).closest("tr"));
    selectedRows.push(row.data());
  });

  if (selectedRows.length === 0) {
    alertify.warning("No has seleccionado ninguna factura.");
    return;
  }

  // ✅ Si ya hay un proveedor establecido, filtrar solo ese proveedor
  let dataToAdd = selectedRows;
  if (currentProvider) {
    dataToAdd = selectedRows.filter((row) => row.proveedor === currentProvider);

    const otherProviders = selectedRows.filter(
      (row) => row.proveedor !== currentProvider,
    );
    if (otherProviders.length > 0) {
      alertify.warning(
        `Solo se agregarán ${dataToAdd.length} facturas del proveedor actual: ${currentProvider}<br>` +
          `<small>Se omitieron ${otherProviders.length} facturas de otros proveedores.</small>`,
      );
    }
  } else if (selectedRows.length > 0) {
    // ✅ Si no hay proveedor, validar múltiples proveedores en la selección
    const firstProvider = selectedRows[0].proveedor;
    dataToAdd = selectedRows.filter((row) => row.proveedor === firstProvider);

    if (dataToAdd.length < selectedRows.length) {
      alertify.confirm(
        "¿Múltiples Proveedores Detectados?",
        `Se detectaron facturas de diferentes proveedores en tu selección.<br><br>` +
          `<strong>¿Quieres agregar solo las ${dataToAdd.length} facturas de "${firstProvider}"?</strong><br><br>` +
          `<small class="text-muted">Las ${selectedRows.length - dataToAdd.length} facturas de otros proveedores se omitirán.</small>`,
        function () {
          // Agregar solo del primer proveedor
          addFilteredDataToPayment(dataToAdd);

          // Desmarcar checkboxes
          table
            .$('input[type="checkbox"].invoice-checkbox:checked')
            .prop("checked", false);
          $("#selectAllCheckbox").prop("checked", false);
          updateSelectedCount();
        },
        function () {
          // Cancelado - no hacer nada
          alertify.message("Operación cancelada");
        },
      );
      return; // ✅ Salir para esperar confirmación
    }
  }

  // Agregar facturas filtradas
  addFilteredDataToPayment(dataToAdd);

  // Desmarcar checkboxes
  table
    .$('input[type="checkbox"].invoice-checkbox:checked')
    .prop("checked", false);
  $("#selectAllCheckbox").prop("checked", false);
  updateSelectedCount();
}

function addFilteredDataToPayment(dataToAdd) {
  let addedCount = 0;
  let skippedCount = 0;

  dataToAdd.forEach((rowData) => {
    // Verificar UUID
    if (!rowData.satuid) {
      skippedCount++;
      return;
    }

    // Verificar si ya existe
    const exists = paymentItems.some((item) => item.nro === rowData.nro);
    if (!exists) {
      // Establecer proveedor si es el primero
      if (paymentItems.length === 0) {
        currentProvider = rowData.proveedor;
      }
      paymentItems.push(rowData);
      addedCount++;
    } else {
      skippedCount++;
    }
  });

  renderPaymentItems();
  updatePaymentSummary();

  if (addedCount > 0) {
    let message = `✓ Se agregaron ${addedCount} documento(s) al pago.`;
    if (skippedCount > 0) {
      message += ` (${skippedCount} omitidos)`;
    }
    alertify.success(message);
  } else if (skippedCount > 0) {
    alertify.warning("No se agregaron documentos nuevos.");
  }
}


function clearAllPayments() {
  if (paymentItems.length === 0) return;

  alertify.confirm(
    "¿Estás seguro?",
    "¿Quieres limpiar todos los documentos del pago?",
    function () {
      paymentItems = [];
      currentProvider = null; // ✅ Resetear proveedor
      renderPaymentItems();
      updatePaymentSummary();
      alertify.success("Pago limpiado correctamente");
    },
    function () {
      // Cancelado
    },
  );
}

function setupDragAndDrop() {
  // Eventos de drag para las filas de la tabla
  $("#payment_create_table tbody").off("dragstart dragend"); // Limpiar eventos previos

  $("#payment_create_table tbody").on("dragstart", "tr", function (e) {
    $(this).addClass("dragging");
    const rowData = $("#payment_create_table").DataTable().row(this).data();
    e.originalEvent.dataTransfer.setData("text/plain", JSON.stringify(rowData));
  });

  $("#payment_create_table tbody").on("dragend", "tr", function (e) {
    $(this).removeClass("dragging");
  });

  // Configurar área de drop si no está configurada
  const basket = document.getElementById("payment-basket");
  if (basket && !basket.hasAttribute("data-drop-configured")) {
    basket.setAttribute("data-drop-configured", "true");

    basket.addEventListener("dragover", function (e) {
      e.preventDefault();
      $(this).addClass("dragover");
    });

    basket.addEventListener("dragleave", function (e) {
      if (!basket.contains(e.relatedTarget)) {
        $(this).removeClass("dragover");
      }
    });

    basket.addEventListener("drop", function (e) {
      e.preventDefault();
      $(this).removeClass("dragover");

      try {
        const rowData = JSON.parse(e.dataTransfer.getData("text/plain"));
        addToPayment(rowData);
      } catch (error) {
        console.error("Error al procesar el elemento:", error);
      }
    });
  }
}


// Añadir elemento al pago
function addToPayment(rowData) {
  provider_name = rowData.proveedor;
  if (!rowData.satuid) {
    alertify.error(
      "⚠️ Esta factura no tiene UUID válido. No se puede agregar.",
    );
    return;
  }
  // ✅ VALIDACIÓN: Solo un proveedor por pago
  if (currentProvider && currentProvider !== rowData.proveedor) {
    alertify
      .alert(
        '<i class="fas fa-exclamation-triangle text-warning"></i> Proveedor Diferente',
        `<div class="text-center">
                <p class="mb-3">No puedes agregar facturas de diferentes proveedores en el mismo pago.</p>
                <div class="alert alert-info mb-0">
                    <strong>Proveedor actual del pago:</strong><br>
                    <span class="text-primary">${currentProvider}</span>
                </div>
                <div class="alert alert-warning mb-0 mt-2">
                    <strong>Proveedor que intentas agregar:</strong><br>
                    <span class="text-danger">${rowData.proveedor}</span>
                </div>
                <hr>
                <small class="text-muted">Debes crear un pago separado para este proveedor.</small>
            </div>`,
      )
      .set({
        maximizable: false,
        closable: true,
      });
    return;
  }
  // Verificar si ya existe (usando nro en lugar de folio)
  const exists = paymentItems.some(
    (item) =>
      item.nro === rowData.nro &&
      item.Factura === rowData.Factura &&
      item.codgas === rowData.codgas,
  );

  if (exists) {
    alertify.myAlert(
      `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">¡Advertencia!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Este documento ya está en el pago.</p>
            </div>`,
    );
    return;
  }
  if (paymentItems.length === 0) {
    currentProvider = rowData.proveedor;
    console.log("✅ Proveedor establecido:", currentProvider);
  }

  paymentItems.push(rowData);
  renderPaymentItems();
  updatePaymentSummary();
}


// Renderizar elementos del pago
function renderPaymentItems() {
  const basket = $("#payment-basket");

  if (paymentItems.length === 0) {
    basket.html(`
            <li class="list-group-item text-center text-muted" style="border: none; background: transparent;">
                <i class="fas fa-hand-point-right fa-2x mb-3"></i>
                <p>Arrastra aquí los documentos desde la tabla</p>
            </li>
        `);
    return;
  }

  let html = "";
  paymentItems.forEach((item, index) => {
    const totalFac = parseFloat(item.total_mostrar != null ? item.total_mostrar : item.total_fac) || 0;
    const esControlGas = item.tiene_factura_recibida == 0;
    const totalBadgeClass = esControlGas ? "bg-light text-danger" : "bg-light text-dark";
    const totalBadgeTitle = esControlGas ? ' title="Total de ControlGas — sin factura recibida"' : "";
    const tempKey = typeof getInvoiceTempKey === "function" ? getInvoiceTempKey(item) : `${item.nro}__${item.codgas}`;
    const notesForItem = typeof pendingNotes !== "undefined"
      ? pendingNotes.filter(n => n.invoice_temp_key === tempKey)
      : [];
    const notesHtml = notesForItem.map((n, ni) => {
      const colorClass = n.note_type === "CREDIT" ? "text-dark" : "text-dark";
      const sign = n.note_type === "CREDIT" ? "−" : "+";
      const globalIndex = typeof pendingNotes !== "undefined" ? pendingNotes.indexOf(n) : -1;
      return `<small class="d-block ${colorClass}">
        <i class="fas fa-tag"></i> ${n.note_label}: ${sign}$${parseFloat(n.applied_amount).toLocaleString("es-MX",{minimumFractionDigits:2})}
        ${globalIndex >= 0 ? `<button class="btn btn-xs btn-link text-danger p-0 ms-1" style="font-size:0.8rem;line-height:1;" onclick="removeNoteAssignment(${globalIndex})">×</button>` : ""}
      </small>`;
    }).join("");
    const noteBtn = typeof openAssignNoteModal === "function"
      ? `<button type="button" class="btn btn-xs btn-outline-light ms-1" style="font-size:0.7rem;padding:1px 5px;" onclick="openAssignNoteModal('${tempKey}')" title="Asignar nota"><i class="fas fa-tag"></i></button>`
      : "";
    // Factura con anticipo parcial aplicado: mostrar el descuento y lo que resta por pagar
    const anticipoHtml = item.anticipo_parcial == 1
      ? `<small class="d-block" style="color:#fcd34d;">
           <i class="fas fa-hand-holding-usd"></i> Anticipo: −$${(parseFloat(item.anticipo_aplicado) || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 })}
           · a pagar $${(parseFloat(item.monto_restante) || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 })}
         </small>`
      : "";
    html += `
            <li class="list-group-item payment-item d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong>Folio: ${item.nro}</strong>
                        <span class="badge ${totalBadgeClass}"${totalBadgeTitle}>$${totalFac.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</span>
                    </div>
                    <small class="d-block">Factura: ${item.Factura || "N/A"} | Remisión: ${item.Remision || "N/A"}</small>
                    <small class="d-block">Proveedor: ${item.proveedor}</small>
                    <small class="d-block text-light">Fecha: ${item.fecha}</small>
                    ${anticipoHtml}
                    ${notesHtml}
                </div>
                <div class="d-flex flex-column align-items-center ms-2 gap-1">
                    ${noteBtn}
                    <button type="button" class="btn btn-sm" style="background: rgba(255, 255, 255, 0.2); border: none; color: white; border-radius: 50%; width: 30px; height: 30px;" onclick="removeFromPayment(${index})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </li>
        `;
  });

  basket.html(html);
}


// Remover elemento del pago
function removeFromPayment(index) {
  paymentItems.splice(index, 1);
  if (paymentItems.length === 0) {
    currentProvider = null;
    console.log("✅ Proveedor reseteado");
  }
  renderPaymentItems();
  updatePaymentSummary();
}


// Actualizar resumen del pago
function updatePaymentSummary() {
  const totalDocs = paymentItems.length;
  let totalAmount = 0;
  let totalAnticipos = 0;

  paymentItems.forEach((item) => {
    const amount = parseFloat(item.total_mostrar != null ? item.total_mostrar : item.total_fac) || 0;
    totalAmount += amount;
    if (item.anticipo_parcial == 1) {
      totalAnticipos += parseFloat(item.anticipo_aplicado) || 0;
    }
  });

  // Actualizar contador en el header
  $("#item-count").text(`${totalDocs} documento${totalDocs !== 1 ? "s" : ""}`);

  // Actualizar resumen
  $("#total-docs").text(totalDocs);
  $("#total-amount").text(
    `$${totalAmount.toLocaleString("es-MX", { minimumFractionDigits: 2 })}`,
  );

  // Descuento por anticipos aplicados a las facturas del carrito
  if (totalAnticipos > 0) {
    const neto = totalAmount - totalAnticipos;
    if ($("#anticipos-resumen").length === 0) {
      $("#payment-summary .row").after('<div id="anticipos-resumen" class="mt-2 text-end" style="font-size:.78rem;"></div>');
    }
    $("#anticipos-resumen").html(
      `<span class="text-white-50">Anticipos aplicados: </span><strong style="color:#fcd34d;">−$${totalAnticipos.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong><br>` +
      `<span class="text-white-50">A pagar: </span><strong class="text-white" style="font-size:.95rem;">$${neto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong>`
    );
  } else {
    $("#anticipos-resumen").remove();
  }
  // ✅ Mostrar proveedor actual
  if (currentProvider && totalDocs > 0) {
    // Agregar badge de proveedor si no existe
    if ($("#current-provider-badge").length === 0) {
      $("#payment-summary").prepend(`
                <div id="current-provider-badge" class="alert alert-light mb-3 py-2">
                    <small class="d-block text-muted mb-1">Proveedor del pago:</small>
                    <strong class="text-primary">${currentProvider}</strong>
                </div>
            `);
    }
  } else {
    $("#current-provider-badge").remove();
  }
  // Mostrar/ocultar elementos según si hay documentos
  if (totalDocs > 0) {
    $("#payment-summary").show();
    $("#generar-pago")
      .prop("disabled", false)
      .removeClass("btn-secondary")
      .addClass("btn-success");
    $("#clear-basket").show();
  } else {
    $("#payment-summary").hide();
    $("#generar-pago")
      .prop("disabled", true)
      .removeClass("btn-success")
      .addClass("btn-secondary");
    $("#clear-basket").hide();
  }
}


function addAllToPayment() {
  const table = $("#payment_create_table").DataTable();

  if (!table || table.rows().count() === 0) {
    alertify.warning("No hay documentos en la tabla para agregar.");
    return;
  }

  const allData = table.rows({ search: "applied" }).data().toArray();

  // ✅ Si ya hay un proveedor establecido, filtrar solo ese proveedor
  let dataToAdd = allData;
  if (currentProvider) {
    dataToAdd = allData.filter((row) => row.proveedor === currentProvider);

    const otherProviders = allData.filter(
      (row) => row.proveedor !== currentProvider,
    );
    if (otherProviders.length > 0) {
      alertify.warning(
        `Solo se agregarán ${dataToAdd.length} facturas del proveedor actual: ${currentProvider}<br>` +
          `<small>Se omitieron ${otherProviders.length} facturas de otros proveedores.</small>`,
      );
    }
  } else if (allData.length > 0) {
    // ✅ Si no hay proveedor, tomar el del primer registro
    const firstProvider = allData[0].proveedor;
    dataToAdd = allData.filter((row) => row.proveedor === firstProvider);

    if (dataToAdd.length < allData.length) {
      alertify.confirm(
        "¿Múltiples Proveedores Detectados?",
        `Se detectaron facturas de diferentes proveedores.<br><br>` +
          `<strong>¿Quieres agregar solo las ${dataToAdd.length} facturas de "${firstProvider}"?</strong><br><br>` +
          `<small class="text-muted">Las ${allData.length - dataToAdd.length} facturas de otros proveedores se omitirán.</small>`,
        function () {
          // Agregar solo del primer proveedor
          addFilteredDataToPayment(dataToAdd);
        },
        function () {
          // Cancelado
        },
      );
      return;
    }
  }

  addFilteredDataToPayment(dataToAdd);
}


// Función auxiliar para agregar factura al carrito
async function addInvoiceToPayment(nro, factura, codgas) {
  try {
    console.log(nro, factura, codgas);
    const response = await fetch("/payment/get_invoice_detail", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ folio: nro, factura: factura, codgas: codgas }),
    });

    const data = await response.json();

    if (data.success && data.invoice) {
      // Verificar si ya existe
      const exists = paymentItems.some(
        (item) =>
          item.nro === nro &&
          item.Factura === factura &&
          item.codgas === codgas,
      );

      if (!exists) {
        paymentItems.push(data.invoice);
        renderPaymentItems();
        updatePaymentSummary();
        alertify.success("Factura agregada");
      } else {
        alertify.warning("Esta factura ya está en el pago");
      }
    } else {
      alertify.error(data.message || "Error al obtener factura");
    }
  } catch (error) {
    console.error("Error agregando factura:", error);
    alertify.error("Error de conexión al agregar factura");
  }
}



function ReturnListPayment() {
  window.location.href = "/payment/payment_list";
}

let currentPaymentId = null;

let availableInvoices = [];

let selectedCuentaBancaria = null;


async function generarTransferenciasBancarias(paymentId) {
  try {
    currentPaymentId = paymentId;

    // Abrir modal inmediatamente
    $("#modalConfigLayout").modal("show");

    // Mostrar loader
    $("#modalConfigLayoutContent").html(`
            <div class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Cargando configuración...</p>
            </div>
        `);

    // Cargar contenido del modal
    const response = await fetch("/payment/configLayoutModal", {
      method: "POST",
      headers: {
        Accept: "application/json, text/javascript, */*",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      credentials: "include",
      body: `payment_id=${paymentId}`,
    });

    if (!response.ok) {
      throw new Error("Error al cargar el modal");
    }

    const content = await response.text();

    // Insertar contenido en el modal
    $("#modalConfigLayoutContent").html(content);
  } catch (error) {
    console.error("Error:", error);
    alertify.error("Error al cargar la configuración del layout");
    $("#modalConfigLayout").modal("hide");
  }
}


function cargarDatosParaLayout(paymentId) {
  $.ajax({
    url: "/payment/get_payment_layout_data",
    type: "POST",
    data: { payment_id: paymentId },
    success: function (response) {
      if (response.success) {
        // Mostrar información del proveedor
        $("#layout_proveedor_nombre").text(response.proveedor.nombre);
        $("#layout_proveedor_codigo").text(response.proveedor.codigo);

        // Cargar cuentas bancarias en el select
        cargarCuentasBancarias(response.cuentas_bancarias);

        // Guardar y mostrar facturas
        availableInvoices = response.facturas;
        renderizarFacturasLayout(response.facturas);
      } else {
        alertify.error(response.message || "Error al cargar datos");
        $("#modalConfigLayout").modal("hide");
      }
    },
    error: function () {
      alertify.error("Error de conexión al cargar datos");
      $("#modalConfigLayout").modal("hide");
    },
  });
}


// ==========================================
// CARGAR CUENTAS BANCARIAS EN SELECT
// ==========================================
function cargarCuentasBancarias(cuentas) {
  const $select = $("#select_cuenta_bancaria");

  // Limpiar opciones anteriores
  $select.find("option:not(:first)").remove();

  if (!cuentas || cuentas.length === 0) {
    alertify.warning("No se encontraron cuentas bancarias para este proveedor");
    return;
  }

  // Agregar opciones
  cuentas.forEach((cuenta) => {
    const opcionTexto = `${cuenta.CuentaLocal} - ${cuenta.Banco} (${cuenta.TipoCuenta || "N/A"})`;
    $select.append(new Option(opcionTexto, cuenta.id));
  });

  // Reinicializar selectpicker
  $select.selectpicker("refresh");

  // Evento de cambio
  $select.off("change").on("change", function () {
    const cuentaId = $(this).val();
    if (cuentaId) {
      const cuenta = cuentas.find((c) => c.id == cuentaId);
      if (cuenta) {
        selectedCuentaBancaria = cuenta;
        mostrarInfoCuentaSeleccionada(cuenta);
        validarGeneracionLayout();
      }
    } else {
      selectedCuentaBancaria = null;
      $("#info_cuenta_seleccionada").hide();
      validarGeneracionLayout();
    }
  });
}


// ==========================================
// MOSTRAR INFO DE CUENTA SELECCIONADA
// ==========================================
function mostrarInfoCuentaSeleccionada(cuenta) {
  $("#cuenta_numero").text(cuenta.CuentaLocal);
  $("#cuenta_banco").text(cuenta.Banco);
  $("#cuenta_email").text(cuenta.EmailCuenta || "No especificado");
  $("#info_cuenta_seleccionada").slideDown();
}


// ==========================================
// RENDERIZAR TABLA DE FACTURAS
// ==========================================
function renderizarFacturasLayout(facturas) {
  const $tbody = $("#tabla_facturas_layout tbody");
  $tbody.empty();

  if (!facturas || facturas.length === 0) {
    $tbody.html(`
            <tr>
                <td colspan="7" class="text-center text-muted">
                    <i class="fas fa-inbox"></i> No hay facturas disponibles
                </td>
            </tr>
        `);
    return;
  }

  facturas.forEach((factura) => {
    const monto = parseFloat(factura.amount || 0);
    const uuidShort = factura.uuid
      ? factura.uuid.substring(0, 8) + "..."
      : "N/A";

    const estadoBadge =
      factura.paid_status == 1
        ? '<span class="badge bg-success">Pagada</span>'
        : '<span class="badge bg-warning text-dark">Pendiente</span>';

    const row = `
            <tr>
                <td class="text-center">
                    <input type="checkbox" 
                           class="invoice-layout-checkbox" 
                           data-factura-id="${factura.id}"
                           onchange="updateCountSelectedLayout()">
                </td>
                <td>${factura.folio}</td>
                <td>${factura.invoice_number || "N/A"}</td>
                <td>${factura.station_name || "N/A"}</td>
                <td class="text-end"><strong>$${monto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong></td>
                <td><small class="font-monospace">${uuidShort}</small></td>
                <td>${estadoBadge}</td>
            </tr>
        `;

    $tbody.append(row);
  });

  updateCountSelectedLayout();
}


// ==========================================
// TOGGLE TODAS LAS FACTURAS
// ==========================================
function toggleAllInvoicesLayout() {
  const isChecked = $("#selectAllLayoutCheckbox").prop("checked");
  $(".invoice-layout-checkbox").prop("checked", isChecked);
  updateCountSelectedLayout();
}


// ==========================================
// ACTUALIZAR CONTADOR DE SELECCIONADAS
// ==========================================
function updateCountSelectedLayout() {
  const count = $(".invoice-layout-checkbox:checked").length;
  $("#count_selected_layout").text(
    count + " seleccionada" + (count !== 1 ? "s" : ""),
  );

  // Actualizar checkbox "Seleccionar Todas"
  const total = $(".invoice-layout-checkbox").length;
  $("#selectAllLayoutCheckbox").prop("checked", count === total && total > 0);

  validarGeneracionLayout();
}


// ==========================================
// VALIDAR SI SE PUEDE GENERAR LAYOUT
// ==========================================
function validarGeneracionLayout() {
  const tieneCuenta = selectedCuentaBancaria !== null;
  const tieneFacturas = $(".invoice-layout-checkbox:checked").length > 0;

  const esValido = tieneCuenta && tieneFacturas;

  $("#btnConfirmarLayout").prop("disabled", !esValido);

  if (!tieneCuenta && tieneFacturas) {
    alertify.warning("Debes seleccionar una cuenta bancaria");
  } else if (tieneCuenta && !tieneFacturas) {
    alertify.warning("Debes seleccionar al menos una factura");
  }
}


// ==========================================
// CONFIRMAR Y GENERAR LAYOUT
// ==========================================
function confirmarGeneracionLayout() {
  if (!selectedCuentaBancaria) {
    alertify.error("Debe seleccionar una cuenta bancaria");
    return;
  }

  const facturasSeleccionadas = [];
  $(".invoice-layout-checkbox:checked").each(function () {
    facturasSeleccionadas.push($(this).data("factura-id"));
  });

  if (facturasSeleccionadas.length === 0) {
    alertify.error("Debe seleccionar al menos una factura");
    return;
  }

  // Confirmar con el usuario
  alertify
    .confirm(
      "Confirmar Generación",
      `¿Generar layout de Santander con:<br><br>` +
        `<strong>Cuenta:</strong> ${selectedCuentaBancaria.CuentaLocal}<br>` +
        `<strong>Banco:</strong> ${selectedCuentaBancaria.Banco}<br>` +
        `<strong>Facturas:</strong> ${facturasSeleccionadas.length}<br><br>` +
        `<small class="text-muted">Esta operación no se puede deshacer.</small>`,
      function () {
        // Cerrar modal y generar
        $("#modalConfigLayout").modal("hide");
        ejecutarGeneracionLayout(facturasSeleccionadas);
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Generar", cancel: "Cancelar" });
}


// ==========================================
// EJECUTAR GENERACIÓN DEL LAYOUT
// ==========================================
function ejecutarGeneracionLayout(facturasIds) {
  alertify.message(
    '<i class="fas fa-spinner fa-spin"></i> Generando archivo...',
  );

  const btnTransfer = $("#btnGenerarTransferencias");
  const btnOriginalText = btnTransfer.html();
  btnTransfer
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Generando...');

  $.ajax({
    url: "/payment/generate_payment_layout",
    type: "POST",
    data: {
      payment_id: currentPaymentId,
      cuenta_bancaria_id: selectedCuentaBancaria.id,
      facturas_ids: JSON.stringify(facturasIds),
    },
    timeout: 300000,
    success: function (response) {
      if (response.success) {
        // Descargar archivo
        fetch(response.file_url)
          .then((res) => {
            if (!res.ok) throw new Error("Error en la descarga");
            return res.blob();
          })
          .then((blob) => {
            const url = window.URL.createObjectURL(new Blob([blob]));
            const a = document.createElement("a");
            a.style.display = "none";
            a.href = url;
            a.download = response.file_name;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            // Mostrar resumen
            alertify.alert(
              "Layout Generado",
              `<div class="text-center">
                                <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                                <h5 class="mt-3">Archivo Descargado</h5>
                                <p class="mb-2"><strong>${response.file_name}</strong></p>
                                <div class="alert alert-info">
                                    <strong>Transferencias:</strong> ${response.registros_procesados}<br>
                                    <strong>Total:</strong> $${parseFloat(response.total_importe).toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
                                    <strong>Cuenta:</strong> ${selectedCuentaBancaria.CuentaLocal}
                                </div>
                            </div>`,
              function () {
                // Eliminar archivo temporal
                $.post("/payment/delete_layout", {
                  filename: response.file_name,
                });
              },
            );
          })
          .catch((error) => {
            console.error("Error:", error);
            alertify.error("Error al descargar el archivo");
          });
      } else {
        alertify.error(response.message || "Error al generar layout");
      }
    },
    error: function (xhr, status) {
      console.error("Error AJAX:", { status, xhr });
      alertify.error(status === "timeout" ? "Timeout" : "Error al generar");
    },
    complete: function () {
      btnTransfer.prop("disabled", false).html(btnOriginalText);
    },
  });
}

//             // Cambiar a tab de anticipos
//             $('a[href="#tab_anticipos"]').tab("show");
//             $("#tabla_anticipos").DataTable().ajax.reload();
//           } else {
//             alertify.error(data.message || "Error al crear anticipo");
//           }
//         } catch (error) {
//           console.error("Error:", error);
//           alertify.error("Error de conexión");
//         }
//       },
//     )
//     .set("labels", { ok: "Crear", cancel: "Cancelar" });
// }

function cargarProveedoresAnticipo() {
  $.ajax({
    url: "/payment/get_proveedores_combustible",
    type: "GET",
    success: function (response) {
      const $select = $("#anticipo_proveedor");
      $select.find("option:not(:first)").remove();

      if (response.success && response.proveedores) {
        response.proveedores.forEach((prov) => {
          $select.append(new Option(prov.nombre, prov.codigo));
        });
        $select.selectpicker("refresh");
      }
    },
  });
}


function openAuthModal(permission, departamento) {
  currentPermission = permission;
  $("#modalDepartamento").text(departamento);
  $("#authModal").modal("show");
}

function confirmAuthorization() {
  if (!currentPermission) {
    alertify.error("Error: No se seleccionó un nivel de autorización");
    return;
  }
  $("#btnConfirmAuth")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');
  $.ajax({
    url: "/payment/authorize_payment",
    type: "POST",
    data: {
      payment_id: paymentId,
      permission: currentPermission,
    },
    success: function (response) {
      if (response.success) {
        alertify.success(response.message);
        $("#authModal").modal("hide");
        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
        alertify.error(response.message);
        $("#btnConfirmAuth")
          .prop("disabled", false)
          .html('<i class="fas fa-check"></i> Confirmar Autorización');
      }
    },
    error: function () {
      alertify.error("Error de conexión al autorizar");
      $("#btnConfirmAuth")
        .prop("disabled", false)
        .html('<i class="fas fa-check"></i> Confirmar Autorización');
    },
  });
}


function autorizarPago() {
  $("#modalAutorizarPago").modal("show");
  updateAutorizacionSummary();
}


function updateAutorizacionSummary() {
  let totalAAutorizar = 0;
  let facturasCount = 0;

  // Solo contar facturas NO autorizadas que están seleccionadas
  $(".factura-checkbox:checked:not(:disabled)").each(function () {
    const row = $(this).closest("tr");
    const montoInput = row.find(".monto-autorizar");
    const monto = parseFloat(montoInput.val()) || 0;
    const saldo = parseFloat(row.data("saldo"));

    // Habilitar input cuando se selecciona
    montoInput.prop("disabled", !$(this).prop("checked"));

    // Si se acaba de seleccionar y no tiene valor, poner el saldo completo
    if ($(this).prop("checked") && montoInput.val() === "") {
      montoInput.val(saldo.toFixed(2));
    }

    // Validar que no exceda el saldo
    if (monto > saldo) {
      montoInput.val(saldo.toFixed(2));
      alertify.warning("El monto no puede exceder el saldo de la factura");
    }

    totalAAutorizar += parseFloat(montoInput.val()) || 0;
    facturasCount++;
  });

  // Actualizar resumen
  $("#totalAAutorizar").text(
    "$" +
      totalAAutorizar.toLocaleString("es-MX", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }),
  );
  $("#facturasSeleccionadas").text(facturasCount);
  $("#totalNuevasAutorizaciones").html(
    "<strong>$" +
      totalAAutorizar.toLocaleString("es-MX", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }) +
      "</strong>",
  );

  // Habilitar/deshabilitar botón de confirmar
  $("#btnConfirmarAutorizacion").prop(
    "disabled",
    facturasCount === 0 || totalAAutorizar === 0,
  );

  // Actualizar el checkbox "Seleccionar Todas" solo para las no autorizadas
  const totalCheckboxes = $(".factura-checkbox:not(:disabled)").length;
  const totalChecked = $(".factura-checkbox:checked:not(:disabled)").length;
  $("#selectAllFacturas").prop(
    "checked",
    totalCheckboxes > 0 && totalCheckboxes === totalChecked,
  );
}


function toggleSelectAllFacturas() {
  const selectAll = $("#selectAllFacturas").prop("checked");
  // Solo seleccionar checkboxes que NO están deshabilitados (facturas no autorizadas)
  $(".factura-checkbox:not(:disabled)").each(function () {
    $(this).prop("checked", selectAll);
    const row = $(this).closest("tr");
    const montoInput = row.find(".monto-autorizar");

    if (selectAll) {
      const saldo = parseFloat(row.data("saldo"));
      montoInput.prop("disabled", false).val(saldo.toFixed(2));
    } else {
      montoInput.prop("disabled", true).val("");
    }
  });

  updateAutorizacionSummary();
}

function confirmarAutorizarPago() {
  const facturasAutorizar = [];
  let totalAAutorizar = 0;

  // Recopilar facturas seleccionadas con sus montos
  $(".factura-checkbox:checked").each(function () {
    const row = $(this).closest("tr");
    const facturaId = $(this).val();
    const monto = parseFloat(row.find(".monto-autorizar").val()) || 0;
    const saldo = parseFloat(row.data("saldo"));
    const folio = row.data("folio");

    if (monto > 0 && monto <= saldo) {
      facturasAutorizar.push({
        invoice_id: facturaId,
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

  // Confirmar con el usuario
  alertify
    .confirm(
      "Confirmar Autorización de Pago",
      `<div class="text-center">
            <i class="fas fa-check-circle text-info fa-3x mb-3"></i>
            <p class="mb-3">¿Está seguro de autorizar el pago de <strong>${facturasAutorizar.length} factura(s)</strong>?</p>
            <div class="alert alert-info" style="display: flex;flex-direction: column;padding-bottom: 10px;">
                <strong>Total a Autorizar:</strong><br>
                <h4 class="text-info mb-0">$${totalAAutorizar.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</h4>
            </div>
            <small class="text-muted">La ejecución del pago será realizada posteriormente por el área correspondiente.</small>
        </div>`,
      function () {
        ejecutarAutorizacionPago(facturasAutorizar);
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Autorizar", cancel: "Cancelar" });
}


function ejecutarAutorizacionPago(facturasAutorizar) {
  $("#btnConfirmarAutorizacion")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');

  $.ajax({
    // url: '/supply/process_payment',
    url: "/payment/authorize_payment_execution",
    type: "POST",
    contentType: "application/json",
    data: JSON.stringify({
      payment_id: paymentId,
      facturas: facturasAutorizar,
    }),
    success: function (response) {
      if (response.success) {
        alertify.success("✓ " + response.message);

        if (response.auto_grouped) {
          alertify.message(
            "Esta requisición no había sido enviada por Abastos — se agrupó automáticamente en el archivo contable " +
              response.accounting_id +
              " al autorizarla."
          );
        }

        $("#modalAutorizarPago").modal("hide");

        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
        alertify.error(response.message);
        $("#btnConfirmarAutorizacion")
          .prop("disabled", false)
          .html('<i class="fas fa-check-circle"></i> Autorizar Pago');
      }
    },
    error: function (xhr) {
      const errorMsg =
        xhr.responseJSON?.message || "Error al autorizar el pago";
      alertify.error(errorMsg);
      $("#btnConfirmarAutorizacion")
        .prop("disabled", false)
        .html('<i class="fas fa-check-circle"></i> Autorizar Pago');
    },
  });
}

$("#modalAutorizarPago").on("hidden.bs.modal", function () {
  $(".factura-checkbox:not(:disabled)").prop("checked", false);
  $(".monto-autorizar:not(:disabled)").val("").prop("disabled", true);
  $("#selectAllFacturas").prop("checked", false);
  updateAutorizacionSummary();
});


// ============================================
// AUTORIZACIÓN MASIVA DE PAGO DE FACTURAS
// ============================================

function abrirModalAutorizarPagoMasivo() {
  $("#modalAutorizarPagoMasivo").modal("show");
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
    const row = `<tr data-anticipo-id="${a.id}">
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
          $("#modalAutorizarPagoMasivo").modal("hide");
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
    const headerRow = `<tr class="table-secondary group-header-row" data-group-id="${pid}" style="cursor: pointer;">
      <td>
        <input type="checkbox" class="group-select-all" data-group="${pid}"
          onchange="toggleSelectGroupMasivo(${pid})" title="Seleccionar todas de este pago"/>
      </td>
      <td colspan="${totalCols - 1}" onclick="toggleGroupMasivo(${pid})">
        <i class="fas fa-chevron-right group-toggle-icon" data-group="${pid}" style="transition: transform 0.2s; margin-right: 6px;"></i>
        <strong>
          <i class="fas fa-file-invoice-dollar text-info"></i>
          Pago <a href="/payment/payment_detail/${pid}" target="_blank" class="text-info" onclick="event.stopPropagation()">#${pid}</a>
        </strong>
        <span class="mx-2">|</span>
        <i class="fas fa-building"></i> ${grupo.empresa}
        <span class="mx-2">|</span>
        <i class="fas fa-truck"></i> ${grupo.proveedor}
        <span class="mx-2">|</span>
        <i class="fas fa-calendar"></i> ${fechaStr}
        <span class="mx-2">|</span>
        <span class="badge bg-info">${numFacturas} factura(s)</span>
        <span class="mx-2">|</span>
        <span class="text-primary fw-bold">${fmt(grupoSaldoNeto)}</span>
      </td>
    </tr>`;
    tbody.append(headerRow);

    // Filas de facturas del grupo
    grupo.facturas.forEach(function (inv) {
      const saldoNeto = Math.max(0, parseFloat(inv.saldo_neto || 0));
      totalMonto += parseFloat(inv.amount);
      totalPagado += parseFloat(inv.paid_amount);
      totalSaldo += parseFloat(inv.saldo);
      totalNC += parseFloat(inv.total_notas_credito);
      totalND += parseFloat(inv.total_notas_cargo);
      totalSaldoNeto += saldoNeto;

      let notasHtml = '<span class="text-muted">-</span>';
      if (inv.notas_count > 0) {
        let parts = [];
        if (inv.total_notas_credito > 0)
          parts.push(
            '<small class="text-success">-' + fmt(inv.total_notas_credito) + "</small>",
          );
        if (inv.total_notas_cargo > 0)
          parts.push(
            '<small class="text-danger">+' + fmt(inv.total_notas_cargo) + "</small>",
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
        let badge = "bg-secondary";
        if (diffDias < 0) badge = "bg-danger";
        else if (diffDias <= 7) badge = "bg-warning text-dark";
        else if (diffDias <= 15) badge = "bg-info";
        vencHtml =
          '<span class="badge ' +
          badge +
          '">' +
          d.toLocaleDateString("es-MX") +
          "</span>";
      }

      const row = `<tr data-invoice-id="${inv.id}" data-payment-id="${inv.payment_request_id}" data-folio="${inv.folio}" data-saldo="${saldoNeto}" data-group="${pid}" class="group-row-${pid}" style="display: none;">
        <td>
          <input type="checkbox" class="factura-masivo-checkbox" value="${inv.id}" data-group="${pid}" onchange="updateAutorizacionMasivaSummary()"/>
        </td>
        <td><strong>${inv.folio}</strong></td>
        <td>${inv.invoice_number || ""}</td>
        <td>${inv.estacion_nombre}</td>
        <td class="text-end">${fmt(inv.amount)}</td>
        <td class="text-end">${fmt(inv.paid_amount)}</td>
        <td class="text-end"><strong class="text-danger">${fmt(inv.saldo)}</strong></td>
        <td class="text-end">${notasHtml}</td>
        <td class="text-end"><strong>${fmt(saldoNeto)}</strong></td>
        <td class="text-end">${vencHtml}</td>
        <td>
          <input type="number" class="form-control form-control-sm monto-autorizar-masivo"
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
      "</small>",
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
        $("#modalAutorizarPagoMasivo").modal("hide");

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

$("#modalAutorizarPagoMasivo").on("hidden.bs.modal", function () {
  $("#tablaAutorizarPagoMasivo tbody").empty();
  $("#buscadorPagoMasivo").val("");
  $("#selectAllFacturasMasivo").prop("checked", false);
  $("#btnConfirmarAutorizacionMasiva")
    .prop("disabled", true)
    .html(
      '<i class="fas fa-check-circle"></i> Autorizar Facturas Seleccionadas',
    );
  $("#masivoPagoTotalAutorizar").text("$0.00");
  $("#masivoPagoSeleccionadas").text("0");
});


// ============================================
// FIN AUTORIZACIÓN MASIVA DE PAGO
// ============================================

function verHistorialPagos(invoiceId, folio) {
  $("#folioHistorial").text(folio);
  $("#modalHistorialPagos").modal("show");

  // Cargar historial
  $.ajax({
    url: "/payment/get_payment_history",
    type: "GET",
    data: { invoice_id: invoiceId },
    success: function (response) {
      if (response.success) {
        renderHistorial(response.data, invoiceId);
      } else {
        $("#historialContent").html(`
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> No hay pagos registrados para esta factura
                    </div>
                `);
      }
    },
    error: function () {
      $("#historialContent").html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> Error al cargar el historial
                </div>
            `);
    },
  });
}


function renderHistorial(transactions, invoiceId) {
  if (!transactions || transactions.length === 0) {
    $("#historialContent").html(`
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No hay pagos registrados para esta factura
            </div>
        `);
    return;
  }

  let totalPagado = 0;
  let html = `
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Fecha Pago</th>
                        <th class="text-end">Monto</th>
                        <th>Método</th>
                        <th>Referencia</th>
                        <th>Pagado Por</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
    `;

  transactions.forEach((tx, index) => {
    totalPagado += parseFloat(tx.payment_amount);

    let statusBadge = "";
    switch (parseInt(tx.status)) {
      case 0:
        statusBadge =
          '<span class="badge bg-warning text-dark">Pendiente</span>';
        break;
      case 1:
        statusBadge = '<span class="badge bg-info">Procesado</span>';
        break;
      case 2:
        statusBadge = '<span class="badge bg-success">Confirmado</span>';
        break;
      case 3:
        statusBadge = '<span class="badge bg-danger">Rechazado</span>';
        break;
    }

    html += `
            <tr>
                <td>${index + 1}</td>
                <td>${formatDate(tx.payment_date)}</td>
                <td class="text-end"><strong>$${parseFloat(tx.payment_amount).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong></td>
                <td>${tx.payment_method || "N/A"}</td>
                <td>${tx.payment_reference || "-"}</td>
                <td><small>${tx.created_by_name || "N/A"}</small></td>
                <td>${statusBadge}</td>
            </tr>
        `;
  });

  html += `
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="2" class="text-end">TOTAL PAGADO:</th>
                        <th class="text-end text-success">$${totalPagado.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</th>
                        <th colspan="4"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;

  $("#historialContent").html(html);
}


function formatDate(dateString) {
  if (!dateString) return "-";
  const date = new Date(dateString);
  return date.toLocaleDateString("es-MX", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  });
}


function verNotasPago(transactionId, notes) {
  $("#notasPagoContent").text(notes);
  $("#modalNotasPago").modal("show");
}

function loadAuthorizedPendingInvoices() {
  if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
    $("#tabla_facturas_autorizadas").DataTable().destroy();
  }
  tablaFacturasAutorizadas = $("#tabla_facturas_autorizadas").DataTable({
    ajax: {
      url: "/payment/authorized_pending_invoices_grouped_table",
      type: "POST",
      dataSrc: "data",
      error: function (xhr, error, thrown) {
        console.error("Error en AJAX:", xhr, error, thrown);
        alertify.error("Error al cargar facturas: " + thrown);
      },
    },
    columns: [
      {
        // Checkbox
        data: null,
        orderable: false,
        className: "text-center",
        render: function (data, type, row) {
          // ✅ Para anticipos, usar payment_request_id en lugar de invoice_ids
          const dataIds =
            row.tipo_registro === "ANTICIPO"
              ? `anticipo-${row.payment_request_id}`
              : row.invoice_ids;

          return `
                        <input type="checkbox" 
                               class="invoice-group-checkbox" 
                               data-invoice-ids="${dataIds}"
                               data-banco="${row.banco_asignado}"
                               data-monto="${row.total_autorizado}"
                               data-empresa="${row.empresa_nombre}"
                               data-proveedor="${row.proveedor_nombre}"
                               data-tipo="${row.tipo_registro}"
                               data-payment-request-id="${row.payment_request_id || ""}"
                               onchange="updateSelectedSummary()">
                    `;
        },
      },
      {
        // Banco
        data: "banco_asignado",
        render: function (data, type, row) {
          let icon = data === "Banorte" ? "university" : "landmark";
          return `
                        <span class="badge" style="background-color: ${row.banco_color}; color: white; font-size: 0.9rem;">
                            <i class="fas fa-${icon}"></i> ${data}
                        </span>
                    `;
        },
      },
      {
        // Empresa
        data: "empresa_nombre",
        render: function (data, type, row) {
          return `
                        <strong>${data}</strong><br>
                        <small class="text-muted">RFC: ${row.empresa_rfc}</small>
                    `;
        },
      },
      {
        // Proveedor
        data: "proveedor_nombre",
        render: function (data, type, row) {
          return `
                        <strong>${data}</strong><br>
                        <small class="text-muted">RFC: ${row.proveedor_rfc}</small>
                    `;
        },
      },
      {
        // Total Facturas / Tipo
        data: null,
        className: "text-center",
        render: function (data, type, row) {
          // ✅ Mostrar badge diferente para anticipos
          if (row.tipo_registro === "ANTICIPO") {
            return `
                            <span class="badge bg-warning text-dark" style="font-size: 0.9rem;">
                                <i class="fas fa-hand-holding-usd"></i> ANTICIPO
                            </span>
                        `;
          }
          return `
                        <span class="badge bg-primary" style="font-size: 0.9rem;">
                            <i class="fas fa-file-invoice"></i> ${row.total_facturas} factura(s)
                        </span>
                    `;
        },
      },
      {
        // Monto Total Autorizado (bruto)
        data: "total_autorizado",
        className: "text-end",
        render: function (data) {
          return (
            '<span style="font-size:.9rem;">$' +
            parseFloat(data).toLocaleString("es-MX", { minimumFractionDigits: 2 }) +
            "</span>"
          );
        },
      },
      {
        // Notas de Crédito
        data: "total_notas_credito",
        className: "text-end",
        render: function (data) {
          var v = parseFloat(data) || 0;
          if (v <= 0) return '<span class="text-muted" style="font-size:.78rem;">—</span>';
          return '<span style="color:#16a34a;font-size:.82rem;">-$' +
            v.toLocaleString("es-MX", { minimumFractionDigits: 2 }) + '</span>';
        },
      },
      {
        // Notas de Cargo
        data: "total_notas_cargo",
        className: "text-end",
        render: function (data) {
          var v = parseFloat(data) || 0;
          if (v <= 0) return '<span class="text-muted" style="font-size:.78rem;">—</span>';
          return '<span style="color:#dc2626;font-size:.82rem;">+$' +
            v.toLocaleString("es-MX", { minimumFractionDigits: 2 }) + '</span>';
        },
      },
      {
        // Saldo Neto (ya calculado por el modelo con NC/ND)
        data: "total_saldo",
        className: "text-end",
        render: function (data) {
          return (
            '<strong class="text-danger" style="font-size:1rem;">$' +
            parseFloat(data).toLocaleString("es-MX", { minimumFractionDigits: 2 }) +
            "</strong>"
          );
        },
      },
      {
        // Autorizado Por
        data: "authorized_by_name",
        render: function (data) {
          return `<small><i class="fas fa-user"></i> ${data}</small>`;
        },
      },
      {
        // Fecha Última Autorización
        data: "ultima_autorizacion",
        render: function (data) {
          if (!data) return "-";
          return new Date(data).toLocaleDateString("es-MX", {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
          });
        },
      },
      {
        // Vencimiento / Folio
        data: null,
        render: function (data, type, row) {
          // ✅ Para anticipos, mostrar el folio del anticipo
          if (row.tipo_registro === "ANTICIPO") {
            return `
                            <span class="badge bg-warning text-dark">
                                <i class="fas fa-hashtag"></i> ${row.folios_list}
                            </span>
                        `;
          }

          // Para facturas, mostrar vencimiento
          if (!row.vencimiento_mas_proximo) return "-";
          const vencimiento = new Date(row.vencimiento_mas_proximo);
          const hoy = new Date();
          const diasDiff = Math.ceil(
            (vencimiento - hoy) / (1000 * 60 * 60 * 24),
          );
          let badge = "secondary";
          let icon = "calendar";

          if (diasDiff < 0) {
            badge = "danger";
            icon = "exclamation-triangle";
          } else if (diasDiff <= 7) {
            badge = "warning";
            icon = "clock";
          } else {
            badge = "success";
            icon = "check";
          }

          return `
                        <span class="badge bg-${badge}">
                            <i class="fas fa-${icon}"></i> 
                            ${vencimiento.toLocaleDateString("es-MX")}
                        </span>
                        ${diasDiff >= 0 ? '<br><small class="text-muted">' + diasDiff + " días</small>" : '<br><small class="text-danger">Vencido</small>'}
                    `;
        },
      },
      {
        // Requisición
        data: null,
        render: function (data, type, row) {
          if (row.tipo_registro === "ANTICIPO") return "-";
          const fechaPago = row.scheduled_payment_date
            ? new Date(row.scheduled_payment_date).toLocaleDateString("es-MX", { day: "2-digit", month: "2-digit", year: "numeric" })
            : null;
          return `<a href="/payment/payment_detail/${row.payment_request_id}" target="_blank" class="fw-semibold text-decoration-none" style="font-size:.82rem;">#${row.payment_request_id}</a>`
            + (fechaPago ? `<br><small style="color:#16a34a;font-size:.7rem;"><i class="fas fa-calendar-check"></i> ${fechaPago}</small>` : "");
        },
      },
      {
        // Acciones
        data: null,
        orderable: false,
        className: "text-center",
        render: function (data, type, row) {
          if (row.tipo_registro === "ANTICIPO") {
            return `
                            <button onclick="verDetalleAnticipo(${row.payment_request_id})"
                                    title="Ver detalle del anticipo" data-bs-toggle="tooltip"
                                    style="color:#2563eb;background:#eff6ff;border:none;border-radius:5px;padding:.3rem .5rem;">
                                <i class="fas fa-eye" style="font-size:.8rem;"></i>
                            </button>
                        `;
          }

          const btnPagar = window.PUEDE_TESORERIA
            ? `<button onclick='pagarGrupoIndividual(${JSON.stringify(String(row.invoice_ids))}, ${JSON.stringify(row.banco_asignado)}, ${JSON.stringify(row.empresa_nombre)}, ${JSON.stringify(row.proveedor_nombre)}, ${parseFloat(row.total_autorizado) || 0})'
                       title="Marcar como pagado" data-bs-toggle="tooltip"
                       style="color:#16a34a;background:#ecfdf5;border:none;border-radius:5px;padding:.3rem .5rem;">
                   <i class="fas fa-dollar-sign" style="font-size:.8rem;"></i>
               </button>`
            : "";

          return `
                        <div class="d-inline-flex gap-1 justify-content-center">
                            <button onclick="verDetalleFacturasAgrupadas('${row.invoice_ids}', '${row.empresa_nombre}', '${row.proveedor_nombre}')"
                                    title="Ver desglose de facturas" data-bs-toggle="tooltip"
                                    style="color:#0891b2;background:#ecfeff;border:none;border-radius:5px;padding:.3rem .5rem;">
                                <i class="fas fa-eye" style="font-size:.8rem;"></i>
                            </button>
                            ${btnPagar}
                        </div>
                    `;
        },
      },
    ],
    order: [
      [1, "asc"],
      [2, "asc"],
      [3, "asc"],
    ], // Ordenar por banco, empresa, proveedor
    pageLength: 50,
    rowGroup: {
      dataSrc: "banco_asignado",
      startRender: function (rows, group) {
        const total = rows
          .data()
          .pluck("total_autorizado")
          .reduce((a, b) => {
            return parseFloat(a) + parseFloat(b);
          }, 0);

        // ✅ Contar facturas y anticipos por separado
        let totalFacturas = 0;
        let totalAnticipos = 0;

        rows.data().each(function (row) {
          if (row.tipo_registro === "ANTICIPO") {
            totalAnticipos++;
          } else {
            totalFacturas += parseInt(row.total_facturas);
          }
        });

        return $("<tr/>").addClass("group-header bg-light")
          .append(`<td colspan="12">
                        <strong><i class="fas fa-university"></i> ${group}</strong> -
                        Total: <strong>$${total.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong> -
                        ${totalFacturas} factura(s)${totalAnticipos > 0 ? " + " + totalAnticipos + " anticipo(s)" : ""} en ${rows.count()} requisición(es)
                    </td>`);
      },
    },
    rowCallback: function (row, data) {
      // ✅ Aplicar estilo diferente a las filas de anticipos
      if (data.tipo_registro === "ANTICIPO") {
        $(row).addClass("table-warning");
        $(row).css("background-color", "#fff3cd");
      }
    },
    initComplete: function () {
      addColumnFilters("tabla_facturas_autorizadas", this.api());

      // Filtro persistente de Banco/Empresa: se registra una sola vez aquí
      // (no en cada click) para que conviva con los demás draws de la tabla
      // (paginación, orden, búsqueda por columna) en vez de perderse.
      const idx = $.fn.dataTable.ext.search.indexOf(filtroBancoEmpresaSearchFn);
      if (idx !== -1) {
        $.fn.dataTable.ext.search.splice(idx, 1);
      }
      $.fn.dataTable.ext.search.push(filtroBancoEmpresaSearchFn);
    },
    drawCallback: function () {
      const table = this.api();
      updateBankSummaryFromTable(table);
      updateSelectedSummary();
      // Actualizar resumen de desglose solo si el card está abierto
      if (document.getElementById('resumenDesglose') && document.getElementById('resumenDesglose').style.display !== 'none') {
        updateResumenDesglose(table);
      }

      // Tooltips de los botones de acciones (solo icono)
      $('#tabla_facturas_autorizadas [data-bs-toggle="tooltip"]').each(function () {
        bootstrap.Tooltip.getOrCreateInstance(this);
      });

      // Poblar filtro de empresas
      const empresas = new Set();
      table
        .rows()
        .data()
        .each(function (row) {
          empresas.add(row.empresa_nombre);
        });

      $("#filtroEmpresa").html(
        '<option value="all">Todas las Empresas</option>',
      );
      empresas.forEach((empresa) => {
        $("#filtroEmpresa").append(
          `<option value="${empresa}">${empresa}</option>`,
        );
      });
    },
  });
}


// ✅ Función para ver detalle del anticipo (reutiliza la que ya tienes)
function verDetalleAnticipo(paymentRequestId) {
  // Cargar el modal de detalle de anticipo que ya tienes
  $.ajax({
    url: "/payment/anticipo_detail_modal/" + paymentRequestId,
    method: "GET",
    success: function (response) {
      $("#modalDetalleAnticipoContent").html(response);
      $("#modalDetalleAnticipo").modal("show");
    },
    error: function () {
      alertify.error("Error al cargar el detalle del anticipo");
    },
  });
}


function updateSelectedSummary() {
  let totalSeleccionado = 0;
  let facturasSeleccionadas = 0;
  let anticiposSeleccionados = 0;

  $(".invoice-group-checkbox:checked").each(function () {
    const monto = parseFloat($(this).data("monto"));
    const tipo = $(this).data("tipo");

    totalSeleccionado += monto;

    if (tipo === "ANTICIPO") {
      anticiposSeleccionados++;
    } else {
      // Contar facturas del grupo
      const invoiceIds = $(this).data("invoice-ids");
      if (invoiceIds) {
        facturasSeleccionadas += invoiceIds.toString().split(",").length;
      }
    }
  });

  $("#totalSeleccionadas").text(
    "$" +
      totalSeleccionado.toLocaleString("es-MX", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }),
  );

  let textoResumen = facturasSeleccionadas + " factura(s)";
  if (anticiposSeleccionados > 0) {
    textoResumen += " + " + anticiposSeleccionados + " anticipo(s)";
  }
  $("#facturasSeleccionadas").text(textoResumen);

  $("#footerTotalSeleccionado").text(
    "$" +
      totalSeleccionado.toLocaleString("es-MX", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }),
  );

  // Habilitar/deshabilitar botón de layout
  $("#btnGenerarLayout").prop("disabled", totalSeleccionado === 0);
}


// ── Toggle de cards colapsables ──────────────────────────────────────────────
function toggleResumenCard(contentId, chevronId) {
  var el = document.getElementById(contentId);
  var ch = document.getElementById(chevronId);
  if (!el) return;
  var open = el.style.display !== 'none';
  el.style.display = open ? 'none' : 'block';
  if (ch) ch.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
  // Si se abre el resumen de desglose y la tabla ya tiene datos, recalcular
  if (!open && contentId === 'resumenDesglose' && typeof tablaFacturasAutorizadas !== 'undefined' && tablaFacturasAutorizadas) {
    updateResumenDesglose(tablaFacturasAutorizadas);
  }
}

// ── Resumen: por razón social → qué le paga a cada proveedor ─────────────────
function updateResumenDesglose(table) {
  if (!table || !table.data().count()) return;

  // estructura: { empresa: { banco, proveedores: { prov: monto }, subtotal } }
  var grupos = {};
  var totalGeneral = 0;

  table.rows({ search: 'applied' }).data().each(function (row) {
    var monto   = parseFloat(row.total_saldo) || 0;
    var empresa = row.empresa_nombre   || 'Sin empresa';
    var prov    = row.proveedor_nombre || 'Sin proveedor';
    var banco   = row.banco_asignado   || '';

    if (!grupos[empresa]) {
      grupos[empresa] = { banco: banco, proveedores: {}, subtotal: 0 };
    }
    grupos[empresa].proveedores[prov] = (grupos[empresa].proveedores[prov] || 0) + monto;
    grupos[empresa].subtotal += monto;
    totalGeneral += monto;
  });

  var fmt = function(n) {
    return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  var bancoColor = { Banorte: '#b91c1c', Santander: '#c81e1e' };
  var bancoIcon  = { Banorte: 'fas fa-piggy-bank', Santander: 'fas fa-landmark' };

  // Ordenar empresas por subtotal desc
  var empresasOrdenadas = Object.keys(grupos).sort(function(a,b) {
    return grupos[b].subtotal - grupos[a].subtotal;
  });

  var html = '';

  // Encabezado con total general
  html += '<div class="d-flex justify-content-between align-items-center mb-2">'
        + '<span style="font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;">'
        + '<i class="fas fa-building me-1"></i>Razón social / Proveedor</span>'
        + '<span style="font-size:.78rem;font-weight:700;color:#1e293b;">Total: ' + fmt(totalGeneral) + '</span>'
        + '</div>';

  html += '<div style="display:flex;flex-direction:column;gap:.35rem;width:100%;max-width:450px;">';

  empresasOrdenadas.forEach(function(empresa) {
    var g = grupos[empresa];
    var bColor = bancoColor[g.banco] || '#475569';
    var bIcon  = bancoIcon[g.banco]  || 'fas fa-university';

    html += '<div style="border:1px solid #e2e8f0;border-radius:5px;overflow:hidden;">';

    // Cabecera empresa
    html += '<div class="d-flex align-items-center justify-content-between px-2 py-1" '
          +      'style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;">'
          + '<span style="font-weight:700;color:#1e293b;font-size:.75rem;line-height:1.2;">' + empresa + '</span>'
          + '<div class="d-flex align-items-center gap-1 ms-1 flex-shrink-0">'
          + '<span style="font-size:.65rem;color:' + bColor + ';white-space:nowrap;">'
          + '<i class="' + bIcon + '"></i> ' + (g.banco || '') + '</span>'
          + '<span style="font-weight:700;color:#1e293b;font-size:.78rem;white-space:nowrap;">' + fmt(g.subtotal) + '</span>'
          + '</div></div>';

    // Filas de proveedores
    var provsOrdenados = Object.entries(g.proveedores).sort(function(a,b){ return b[1]-a[1]; });
    html += '<table style="width:100%;border-collapse:collapse;font-size:.75rem;"><tbody>';
    provsOrdenados.forEach(function(entry, idx) {
      var bg = idx % 2 === 0 ? '#fff' : '#f8fafc';
      html += '<tr style="background:' + bg + ';">'
            + '<td style="padding:3px 8px;color:#64748b;">'
            + '<i class="fas fa-truck me-1" style="color:#cbd5e1;font-size:.65rem;"></i>' + entry[0]
            + '</td>'
            + '<td style="padding:3px 8px;text-align:right;font-weight:600;color:#334155;white-space:nowrap;">' + fmt(entry[1]) + '</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
  });

  html += '</div>';

  if (!empresasOrdenadas.length) {
    html = '<p class="text-muted text-center py-2" style="font-size:.8rem;">Sin datos para mostrar.</p>';
  }

  var container = document.getElementById('resumenTablaContainer');
  if (container) container.innerHTML = html;
}

function descargarResumenExcel() {
  if (typeof tablaFacturasAutorizadas === 'undefined' || !tablaFacturasAutorizadas || !tablaFacturasAutorizadas.data().count()) {
    alertify.error('No hay datos para exportar.');
    return;
  }
  if (typeof XLSX === 'undefined') {
    alertify.error('La librería Excel no está disponible. Recarga la página.');
    return;
  }

  // Reconstruir el mismo agrupamiento que updateResumenDesglose
  var grupos = {};
  var totalGeneral = 0;

  tablaFacturasAutorizadas.rows({ search: 'applied' }).data().each(function(row) {
    var monto   = parseFloat(row.total_saldo) || 0;
    var empresa = row.empresa_nombre   || 'Sin empresa';
    var prov    = row.proveedor_nombre || 'Sin proveedor';
    var banco   = row.banco_asignado   || '';
    if (!grupos[empresa]) grupos[empresa] = { banco: banco, proveedores: {}, subtotal: 0 };
    grupos[empresa].proveedores[prov] = (grupos[empresa].proveedores[prov] || 0) + monto;
    grupos[empresa].subtotal += monto;
    totalGeneral += monto;
  });

  var empresasOrdenadas = Object.keys(grupos).sort(function(a,b) {
    return grupos[b].subtotal - grupos[a].subtotal;
  });

  var rows = [['Razón Social', 'Banco', 'Proveedor', 'Monto']];

  empresasOrdenadas.forEach(function(empresa) {
    var g = grupos[empresa];
    var provsOrdenados = Object.entries(g.proveedores).sort(function(a,b){ return b[1]-a[1]; });
    provsOrdenados.forEach(function(entry) {
      rows.push([empresa, g.banco, entry[0], entry[1]]);
    });
    // Subtotal por empresa
    rows.push(['', '', 'SUBTOTAL ' + empresa, g.subtotal]);
    rows.push([]); // línea en blanco entre empresas
  });

  // Total general al final
  rows.push(['', '', 'TOTAL GENERAL', totalGeneral]);

  var wb = XLSX.utils.book_new();
  var ws = XLSX.utils.aoa_to_sheet(rows);

  // Ancho de columnas
  ws['!cols'] = [{ wch: 36 }, { wch: 12 }, { wch: 42 }, { wch: 18 }];

  // Formato de número para la columna Monto (col D, índice 3)
  rows.forEach(function(r, i) {
    if (i === 0 || !r[3] && r[3] !== 0) return;
    var cell = ws[XLSX.utils.encode_cell({ r: i, c: 3 })];
    if (cell) cell.z = '"$"#,##0.00';
  });

  XLSX.utils.book_append_sheet(wb, ws, 'Resumen');

  var fecha = new Date().toLocaleDateString('es-MX').replace(/\//g, '-');
  XLSX.writeFile(wb, 'Resumen_Pagos_' + fecha + '.xlsx');
}

function updateBankSummaryFromTable(table) {
  if (!table.data().count()) {
    return;
  }
  let banorte = { count: 0, total: 0 };
  let santander = { count: 0, total: 0 };

  table
    .rows({ search: "applied" })
    .data()
    .each(function (row) {
      const monto = parseFloat(row.total_saldo) || 0;

      if (row.banco_asignado === "Banorte") {
        banorte.count++;
        banorte.total += monto;
      } else if (row.banco_asignado === "Santander") {
        santander.count++;
        santander.total += monto;
      }
    });

  $("#totalBanorte").text(
    "$" + banorte.total.toLocaleString("es-MX", { minimumFractionDigits: 2 }),
  );
  $("#facturasBanorte").text(banorte.count + " grupos");

  $("#totalSantander").text(
    "$" + santander.total.toLocaleString("es-MX", { minimumFractionDigits: 2 }),
  );
  $("#facturasSantander").text(santander.count + " grupos");
}


/**
 * Función de búsqueda persistente para los filtros de Banco/Empresa de la
 * tabla de facturas autorizadas. Se registra una sola vez (ver initComplete
 * de tablaFacturasAutorizadas) y lee el valor actual de los selects en cada
 * draw, en vez de push/pop por click (que perdía el filtro en el siguiente
 * draw disparado por paginación, orden o la búsqueda por columna).
 */
function filtroBancoEmpresaSearchFn(settings, data, dataIndex) {
  if (settings.nTable.id !== "tabla_facturas_autorizadas") {
    return true;
  }

  const banco = $("#filtroBanco").val();
  const empresa = $("#filtroEmpresa").val();
  const rowData = tablaFacturasAutorizadas.row(dataIndex).data();

  let bancoMatch = banco === "all" || rowData.banco_asignado === banco;
  let empresaMatch = empresa === "all" || rowData.empresa_nombre === empresa;

  return bancoMatch && empresaMatch;
}

/**
 * Aplicar filtros
 */
function aplicarFiltros() {
  tablaFacturasAutorizadas.draw();
}

//             function() {
//                 alertify.error('Operación cancelada');
//             }
//         );
//         return;
//     }

//     // TODO: Implementar lógica de generación de layout
//     console.log('Facturas seleccionadas:', seleccionadas);
//     alertify.success('Generando layout para ' + seleccionadas.length + ' facturas del banco ' + bancos[0]);
// }

/**
 * Ver detalle del pago
 */
function verDetallePago(paymentId) {
  window.location.href = "/payment/payment_detail/" + paymentId;
}


/**
 * Ver desglose de facturas agrupadas
 */
function verDetalleFacturasAgrupadas(invoiceIds, empresa, proveedor) {
  // Mostrar información del grupo
  $("#desgloseEmpresa").text(empresa);
  $("#desgloseProveedor").text(proveedor);

  // Abrir modal
  $("#modalDesgloseFacturas").modal("show");

  // Cargar datos
  cargarDesgloseFacturas(invoiceIds);
}


/**
 * Cargar desglose de facturas
 */
function cargarDesgloseFacturas(invoiceIds) {
  // Destruir tabla anterior si existe
  if ($.fn.DataTable.isDataTable("#tablaDesgloseFacturas")) {
    $("#tablaDesgloseFacturas").DataTable().destroy();
  }

  // Mostrar loading
  $("#tablaDesgloseFacturas tbody").html(`
        <tr>
            <td colspan="11" class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">Cargando facturas...</p>
            </td>
        </tr>
    `);

  // Hacer petición AJAX
  $.ajax({
    url: "/payment/get_invoices_detail",
    type: "POST",
    data: { invoice_ids: invoiceIds },
    dataType: "json",
    success: function (response) {
      if (response.success && response.data) {
        inicializarTablaDesglose(response.data);
      } else {
        alertify.error(response.message || "Error al cargar las facturas");
        $("#tablaDesgloseFacturas tbody").html(`
                    <tr>
                        <td colspan="11" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            No se pudieron cargar las facturas
                        </td>
                    </tr>
                `);
      }
    },
    error: function (xhr, status, error) {
      console.error("Error AJAX:", error);
      alertify.error("Error al cargar las facturas: " + error);
      $("#tablaDesgloseFacturas tbody").html(`
                <tr>
                    <td colspan="11" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Error de conexión
                    </td>
                </tr>
            `);
    },
  });
}


/**
 * Inicializar tabla de desglose con datos
 */
function inicializarTablaDesglose(data) {
  // Guardar el dataset actual para el cálculo del total proyectado al desautorizar.
  desgloseDataActual = data;

  // Columna de selección para desautorizar — solo visible para Tesorería (68).
  // Una factura con pagos (paid_amount > 0) no puede desautorizarse: checkbox bloqueado.
  const colSeleccion = {
    data: null,
    orderable: false,
    className: "text-center",
    render: function (d, type, row) {
      const pagado = parseFloat(row.paid_amount) || 0;
      if (pagado > 0) {
        return '<i class="fas fa-lock text-muted" title="Tiene pagos, no se puede desautorizar"></i>';
      }
      return '<input type="checkbox" class="chk-desautorizar" value="' + row.id +
             '" data-monto="' + (parseFloat(row.authorized_amount) || 0) +
             '" onchange="onToggleDesautorizar()">';
    },
  };

  const columnasBase = [
      {
        data: "folio",
        render: function (data) {
          return "<strong>" + data + "</strong>";
        },
      },
      { data: "invoice_number" },
      { data: "estacion_nombre" },
      {
        data: "amount",
        className: "text-end",
        render: function (data) {
          return (
            "$" +
            parseFloat(data).toLocaleString("es-MX", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })
          );
        },
      },
      {
        data: "paid_amount",
        className: "text-end",
        render: function (data) {
          return (
            "$" +
            parseFloat(data).toLocaleString("es-MX", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })
          );
        },
      },
      {
        data: "authorized_amount",
        className: "text-end",
        render: function (data) {
          return (
            '<strong class="text-success">$' +
            parseFloat(data).toLocaleString("es-MX", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            }) +
            "</strong>"
          );
        },
      },
      {
        data: "saldo",
        className: "text-end",
        render: function (data) {
          return (
            "$" +
            parseFloat(data).toLocaleString("es-MX", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            })
          );
        },
      },
      {
        // NC / ND
        data: null,
        className: "text-end",
        render: function (data, type, row) {
          var nc = parseFloat(row.total_notas_credito) || 0;
          var nd = parseFloat(row.total_notas_cargo) || 0;
          if (nc === 0 && nd === 0) {
            return '<small class="text-muted">-</small>';
          }
          var html = "";
          if (nc > 0) {
            html +=
              '<small class="text-success">-$' +
              nc.toLocaleString("es-MX", { minimumFractionDigits: 2 }) +
              "</small>";
          }
          if (nd > 0) {
            html +=
              (nc > 0 ? "<br>" : "") +
              '<small class="text-danger">+$' +
              nd.toLocaleString("es-MX", { minimumFractionDigits: 2 }) +
              "</small>";
          }
          return html;
        },
      },
      {
        // Saldo Neto
        data: "saldo_neto",
        className: "text-end",
        render: function (data) {
          var val = parseFloat(data) || 0;
          var color = val > 0 ? "text-danger" : "text-success";
          return (
            '<strong class="' +
            color +
            '">$' +
            val.toLocaleString("es-MX", {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
            }) +
            "</strong>"
          );
        },
      },
      {
        data: "expiration_date",
        render: function (data) {
          if (!data) return "-";
          const vencimiento = new Date(data);
          const hoy = new Date();
          const diasDiff = Math.ceil(
            (vencimiento - hoy) / (1000 * 60 * 60 * 24),
          );
          let badge = "secondary";
          if (diasDiff < 0) badge = "danger";
          else if (diasDiff <= 7) badge = "warning";
          else badge = "success";
          return `<span class="badge bg-${badge}">${vencimiento.toLocaleDateString("es-MX")}</span>`;
        },
      },
      {
        data: "authorized_by_name",
        render: function (data) {
          return `<small><i class="fas fa-user"></i> ${data}</small>`;
        },
      },
      {
        data: "authorized_at",
        render: function (data) {
          if (!data) return "-";
          return new Date(data).toLocaleDateString("es-MX", {
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
          });
        },
      },
    ];

  // Si es Tesorería, anteponer la columna de selección. Eso desplaza en 1 los
  // índices, por lo que la columna de vencimiento (base idx 9) pasa a 10.
  const puedeTesoreria = !!window.PUEDE_TESORERIA;
  const columnasFinal = puedeTesoreria ? [colSeleccion, ...columnasBase] : columnasBase;
  const ordenCol = puedeTesoreria ? 10 : 9;

  tablaDesgloseFacturas = $("#tablaDesgloseFacturas").DataTable({
    data: data,
    columns: columnasFinal,
    order: [[ordenCol, "asc"]], // Ordenar por vencimiento
    paging: false, // Sin paginacion: todo se ve via scroll interno
    info: false,
    dom: "frt",
    scrollY: "45vh", // Scroll interno: el cuerpo scrollea y deja header/footer fijos
    scrollX: true,
    scrollCollapse: true,
    drawCallback: function () {
      actualizarTotalesDesglose(data);
    },
    initComplete: function () {
      addColumnFilters("tablaDesgloseFacturas", this.api());
    },
  });

  actualizarTotalesDesglose(data);

  // Reset del panel de desautorización al recargar la tabla.
  if (puedeTesoreria) {
    $("#desautorizarPanel").attr("style", "display:none !important;");
    $("#btnDesautorizarSeleccionadas").prop("disabled", true);
    onToggleDesautorizar();
  }
}


/**
 * Actualizar totales del desglose
 */
function actualizarTotalesDesglose(data) {
  let totalFacturas = data.length;
  let totalAutorizado = 0;
  let totalSaldo = 0;
  let totalNC = 0;
  let totalND = 0;
  let totalSaldoNeto = 0;

  data.forEach(function (factura) {
    totalAutorizado += parseFloat(factura.authorized_amount);
    totalSaldo += parseFloat(factura.saldo);
    totalNC += parseFloat(factura.total_notas_credito) || 0;
    totalND += parseFloat(factura.total_notas_cargo) || 0;
    totalSaldoNeto += parseFloat(factura.saldo_neto);
  });

  var fmt = function (v) {
    return "$" + v.toLocaleString("es-MX", { minimumFractionDigits: 2 });
  };

  // Actualizar cards superiores
  $("#desgloseTotalFacturas").text(totalFacturas);
  $("#desgloseMontoTotal").text(fmt(totalAutorizado));
  $("#desgloseTotalNC").text("-" + fmt(totalNC));
  $("#desgloseTotalND").text("+" + fmt(totalND));
  $("#desgloseSaldoTotal").text(fmt(totalSaldoNeto));

  // Actualizar footer de la tabla. Con scrollY, DataTables clona el tfoot en un
  // contenedor fijo aparte, asi que usamos selectores de clase (no #id) para
  // actualizar tanto el footer original como el clon visible.
  $(".footerDesgloseAutorizado").html("<strong>" + fmt(totalAutorizado) + "</strong>");
  $(".footerDesgloseSaldo").html(fmt(totalSaldo));
  var notasHtml = "";
  if (totalNC > 0) notasHtml += '<small class="text-success">-' + fmt(totalNC) + "</small>";
  if (totalND > 0) notasHtml += (totalNC > 0 ? "<br>" : "") + '<small class="text-danger">+' + fmt(totalND) + "</small>";
  $(".footerDesgloseNotas").html(notasHtml);
  $(".footerDesgloseSaldoNeto").html('<strong class="text-danger">' + fmt(totalSaldoNeto) + "</strong>");
}


/**
 * Tesorería: recalcula en vivo cuánto quedaría autorizado si se desautorizan
 * las facturas marcadas, para que el usuario compare contra el saldo que tiene
 * en el banco antes de confirmar.
 */
function onToggleDesautorizar() {
  if (!window.PUEDE_TESORERIA) return;

  const seleccionadas = $(".chk-desautorizar:checked");
  const count = seleccionadas.length;

  // Total autorizado actual del grupo (todas las facturas del desglose).
  let totalAutorizado = 0;
  (desgloseDataActual || []).forEach(function (f) {
    totalAutorizado += parseFloat(f.authorized_amount) || 0;
  });

  // Monto que se restaría con lo marcado.
  let bajaria = 0;
  seleccionadas.each(function () {
    bajaria += parseFloat($(this).data("monto")) || 0;
  });

  const proyectado = totalAutorizado - bajaria;
  const fmt = function (v) {
    return "$" + v.toLocaleString("es-MX", { minimumFractionDigits: 2 });
  };

  $("#desautorizarCount").text(count);
  $("#desgloseAutorizadoProyectado").text(fmt(proyectado));
  $("#desgloseBajaraEn").text(bajaria > 0 ? "(−" + fmt(bajaria) + ")" : "");
  $("#btnDesautorizarSeleccionadas").prop("disabled", count === 0);

  // Mostrar/ocultar el panel según haya o no selección.
  if (count > 0) {
    $("#desautorizarPanel").attr("style", "display:flex !important;");
  } else {
    $("#desautorizarPanel").attr("style", "display:none !important;");
  }
}


/**
 * Tesorería: desautoriza ("limpia") las facturas marcadas, regresándolas a la
 * cola de autorización. Pide confirmación y refresca el desglose y la tabla.
 */
function desautorizarSeleccionadas() {
  const ids = $(".chk-desautorizar:checked")
    .map(function () { return $(this).val(); })
    .get();

  if (ids.length === 0) {
    alertify.error("Selecciona al menos una factura");
    return;
  }

  alertify
    .confirm(
      '<i class="fas fa-eraser text-warning me-1"></i> Desautorizar facturas',
      '<div class="text-center"><p class="mb-2">¿Regresar <strong>' + ids.length +
        ' factura(s)</strong> a la cola de autorización de Tesorería?</p>' +
        '<small class="text-muted">Se limpiará su autorización. Podrás volver a autorizarlas después.</small></div>',
      function () {
        $.ajax({
          url: "/payment/unauthorize_invoices",
          type: "POST",
          dataType: "json",
          data: { invoice_ids: JSON.stringify(ids) },
        })
          .done(function (resp) {
            if (resp.success) {
              alertify.success(resp.message || "Facturas desautorizadas");
              // Recargar el desglose con las facturas restantes del grupo.
              const restantes = (desgloseDataActual || [])
                .map(function (f) { return f.id; })
                .filter(function (id) { return ids.indexOf(String(id)) === -1; });

              if (restantes.length > 0) {
                if ($.fn.DataTable.isDataTable("#tablaDesgloseFacturas")) {
                  $("#tablaDesgloseFacturas").DataTable().destroy();
                }
                cargarDesgloseFacturas(restantes.join(","));
              } else {
                // Ya no quedan facturas en el grupo: cerrar el modal.
                $("#modalDesgloseFacturas").modal("hide");
              }

              // Refrescar la tabla principal de facturas autorizadas.
              if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
                $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
              }
            } else {
              alertify.error(resp.message || "No se pudo desautorizar");
            }
          })
          .fail(function () {
            alertify.error("Error de comunicación");
          });
      },
      function () { alertify.message("Cancelado"); }
    )
    .set("labels", { ok: "Desautorizar", cancel: "Cancelar" });
}


/**
 * Exportar desglose a Excel
 */
function exportarDesgloseExcel() {
  if (tablaDesgloseFacturas) {
    tablaDesgloseFacturas.button(".buttons-excel").trigger();
  } else {
    alertify.warning("No hay datos para exportar");
  }
}

$("#modalDesgloseFacturas").on("hidden.bs.modal", function () {
  if ($.fn.DataTable.isDataTable("#tablaDesgloseFacturas")) {
    $("#tablaDesgloseFacturas").DataTable().destroy();
  }
  $("#tablaDesgloseFacturas tbody").empty();
});


function validarYGenerarLayout() {
  const facturasSeleccionadas = [];
  const anticiposSeleccionados = [];
  const empresasSeleccionadas = [];
  const bancos = new Set();

  // ✅ Separar facturas de anticipos
  $(".invoice-group-checkbox:checked").each(function () {
    const banco = $(this).data("banco");
    const tipo = $(this).data("tipo");
    const empresa = $(this).data("empresa");
    const proveedor = $(this).data("proveedor");
    const monto = $(this).data("monto");

    bancos.add(banco);

    if (tipo === "ANTICIPO") {
      // ✅ Para anticipos, guardar payment_request_id
      const paymentRequestId = $(this).data("payment-request-id");
      anticiposSeleccionados.push({
        banco: banco,
        payment_request_id: paymentRequestId,
        empresa: empresa,
        proveedor: proveedor,
        monto: monto,
        tipo: "ANTICIPO",
      });
      empresasSeleccionadas.push(empresa);
    } else {
      // ✅ Para facturas, guardar invoice_ids
      const invoiceIds = $(this).data("invoice-ids");
      facturasSeleccionadas.push({
        banco: banco,
        invoice_ids: invoiceIds,
        empresa: empresa,
        proveedor: proveedor,
        monto: monto,
        tipo: "FACTURAS",
      });
      empresasSeleccionadas.push(empresa);
    }
  });

  // ✅ VALIDACIÓN 1: Debe haber al menos una selección
  if (
    facturasSeleccionadas.length === 0 &&
    anticiposSeleccionados.length === 0
  ) {
    alertify.warning(
      "Debe seleccionar al menos un grupo de facturas o anticipos",
    );
    return;
  }

  // ✅ VALIDACIÓN 2: Solo un banco permitido
  if (bancos.size > 1) {
    const bancosArray = Array.from(bancos);
    alertify
      .alert(
        '<i class="fas fa-exclamation-triangle text-warning"></i> Bancos Mezclados',
        `<div class="text-center">
                <p class="mb-3">Has seleccionado pagos de diferentes bancos:</p>
                <div class="alert alert-warning mb-3">
                    ${bancosArray.map((b) => `<span class="badge bg-secondary me-2">${b}</span>`).join("")}
                </div>
                <p><strong>Debes generar layouts separados por banco.</strong></p>
                <small class="text-muted">Por favor, selecciona pagos de un solo banco a la vez.</small>
            </div>`,
      )
      .set({
        maximizable: false,
        closable: true,
      });
    return;
  }

  const bancoSeleccionado = Array.from(bancos)[0];

  // ✅ VALIDACIÓN 3: Redirigir según el banco
  switch (bancoSeleccionado) {
    case "Santander":
      generarLayoutSantander(
        facturasSeleccionadas,
        anticiposSeleccionados,
        empresasSeleccionadas,
      );
      break;

    case "Banorte":
      generarLayoutBanorte(
        facturasSeleccionadas,
        anticiposSeleccionados,
        empresasSeleccionadas,
      );
      break;

    case "Sin asignar":
      alertify.error("Los pagos seleccionados no tienen banco asignado");
      break;

    default:
      alertify.error("Banco no reconocido: " + bancoSeleccionado);
  }
}


function generarLayoutSantander(
  gruposFacturas,
  gruposAnticipos,
  empresasSeleccionadas,
) {
  const todosLosInvoiceIds = [];
  gruposFacturas.forEach((grupo) => {
    const ids = String(grupo.invoice_ids).split(",").map((id) => parseInt(id.trim()));
    todosLosInvoiceIds.push(...ids);
  });

  // ✅ Extraer todos los payment_request_ids de anticipos
  const todosLosAnticipoIds = gruposAnticipos.map((a) =>
    parseInt(a.payment_request_id),
  );

  // ✅ Calcular totales
  const totalGruposFacturas = gruposFacturas.length;
  const totalGruposAnticipos = gruposAnticipos.length;
  const totalFacturas = todosLosInvoiceIds.length;
  const totalAnticipos = todosLosAnticipoIds.length;

  const totalMonto = [...gruposFacturas, ...gruposAnticipos].reduce(
    (sum, g) => sum + parseFloat(g.monto),
    0,
  );

  // ✅ Obtener empresas únicas
  const empresas = [
    ...new Set([...gruposFacturas, ...gruposAnticipos].map((g) => g.empresa)),
  ];
  const empresasTexto =
    empresasSeleccionadas.length === 1
      ? empresasSeleccionadas[0]
      : empresasSeleccionadas.join(", ");

  // ✅ Construir resumen detallado
  let resumenHTML = `
        <div class="alert alert-info mb-3" style=" display:flex; flex-direction: column;" >
            <strong><i class="fas fa-building"></i> Empresa(s):</strong> ${empresasTexto}<br>
            <strong><i class="fas fa-university"></i> Banco:</strong> Santander<br>
    `;

  if (totalGruposFacturas > 0) {
    resumenHTML += `<strong><i class="fas fa-file-invoice"></i> Grupos de Facturas:</strong> ${totalGruposFacturas} (${totalFacturas} facturas)<br>`;
  }

  if (totalGruposAnticipos > 0) {
    resumenHTML += `<strong><i class="fas fa-hand-holding-usd"></i> Anticipos:</strong> ${totalAnticipos}<br>`;
  }

  resumenHTML += `
            <strong><i class="fas fa-dollar-sign"></i> Total:</strong> $${totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
        </div>
    `;

  // ✅ Mostrar confirmación
  alertify
    .confirm(
      '<i class="fas fa-file-invoice-dollar text-success"></i> Confirmar Generación de Layout Santander',
      `<div class="text-center">
            ${resumenHTML}
            <p class="mb-2">¿Deseas generar el archivo de layout para carga en Santander?</p>
            <small class="text-muted">Se generará un archivo de texto (.txt) con formato bancario.</small>
        </div>`,
      function () {
        ejecutarGeneracionLayoutSantander(
          todosLosInvoiceIds,
          todosLosAnticipoIds,
        );
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Generar Layout", cancel: "Cancelar" });
}


function ejecutarGeneracionLayoutSantander(invoiceIds, anticipoIds) {
  alertify.message(
    '<i class="fas fa-spinner fa-spin"></i> Generando layout de Santander...',
  );

  const btnLayout = $("#btnGenerarLayout");
  const btnOriginalHtml = btnLayout.html();
  btnLayout
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Generando...');

  fetch("/payment/generate_santander_layout", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      invoice_ids: invoiceIds || [], // ✅ Array de invoice IDs
      anticipo_ids: anticipoIds || [], // ✅ Array de payment_request IDs de anticipos
    }),
  })
    .then((response) => {
      const contentType = response.headers.get("content-type");
      if (contentType && contentType.includes("application/json")) {
        return response.json().then((data) => {
          throw new Error(data.message || "Error al generar layout");
        });
      }

      if (!response.ok) {
        throw new Error("Error en la respuesta del servidor");
      }

      const disposition = response.headers.get("Content-Disposition");
      let filename = "LAYOUT_SANTANDER_" + new Date().getTime() + ".txt";

      return response.blob().then((blob) => ({ blob, filename }));
    })
    .then(({ blob, filename }) => {
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.style.display = "none";
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);

      alertify.success("Layout de Santander descargado correctamente");

      const totalPagos = (invoiceIds?.length || 0) + (anticipoIds?.length || 0);
      mostrarResumenLayoutSantander(
        filename,
        totalPagos,
        invoiceIds?.length || 0,
        anticipoIds?.length || 0,
      );

      if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
        $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      let mensaje = error.message || "Error al generar el layout";

      if (mensaje.includes("<")) {
        alertify
          .alert(
            '<i class="fas fa-exclamation-triangle text-danger"></i> Error al Generar Layout',
            mensaje,
          )
          .set({
            maximizable: false,
            closable: true,
          });
      } else {
        alertify.error(mensaje);
      }
    })
    .finally(() => {
      btnLayout.prop("disabled", false).html(btnOriginalHtml);
    });
}


function mostrarResumenLayoutSantander(
  filename,
  totalPagos,
  totalFacturas,
  totalAnticipos,
) {
  let resumen = "";
  if (totalFacturas > 0) {
    resumen += `<strong><i class="fas fa-file-invoice"></i> Facturas:</strong> ${totalFacturas}<br>`;
  }
  if (totalAnticipos > 0) {
    resumen += `<strong><i class="fas fa-hand-holding-usd"></i> Anticipos:</strong> ${totalAnticipos}<br>`;
  }

  alertify
    .alert(
      '<i class="fas fa-check-circle text-success"></i> Layout Generado',
      `<div class="text-center">
            <i class="fas fa-file-download fa-3x text-success mb-3"></i>
            <h5>Archivo Descargado Exitosamente</h5>
            
            <div class="alert alert-success mt-3 mb-3" style="flex-direction: column; display: flex; font-size: 1.1em; width: 100%; padding: 15px;">
                <strong><i class="fas fa-file-alt"></i> Archivo:</strong> ${filename}<br>
                ${resumen}
                <strong><i class="fas fa-check-double"></i> Total de pagos:</strong> ${totalPagos}
            </div>
            
            <hr>
            
            <div class="alert alert-info mb-0">
                <small>
                    <i class="fas fa-info-circle"></i>
                    <strong>Siguiente paso:</strong><br>
                    Sube este archivo a Santander SuperNet para procesar los pagos.
                </small>
            </div>
        </div>`,
    )
    .set({
      maximizable: false,
      closable: true,
    });
}


function abrirModalRegistroPago() {
  const seleccionadas = [];
  const bancos = new Set();

  // Recopilar facturas seleccionadas
  $(".invoice-group-checkbox:checked").each(function () {
    const banco = $(this).data("banco");
    const invoiceIds = $(this).data("invoice-ids");
    const empresa = $(this).data("empresa");
    const proveedor = $(this).data("proveedor");
    const monto = $(this).data("monto");

    bancos.add(banco);

    seleccionadas.push({
      banco: banco,
      invoice_ids: invoiceIds,
      empresa: empresa,
      proveedor: proveedor,
      monto: monto,
    });
  });

  // ✅ VALIDACIÓN 1: Debe haber facturas seleccionadas
  if (seleccionadas.length === 0) {
    alertify.warning("Debe seleccionar al menos un grupo de facturas");
    return;
  }

  // ✅ VALIDACIÓN 2: Solo un banco permitido
  if (bancos.size > 1) {
    const bancosArray = Array.from(bancos);
    alertify.alert(
      '<i class="fas fa-exclamation-triangle text-warning"></i> Bancos Mezclados',
      `<div class="text-center">
                <p class="mb-3">Has seleccionado facturas de diferentes bancos:</p>
                <div class="alert alert-warning mb-3">
                    ${bancosArray.map((b) => `<span class="badge bg-secondary me-2">${b}</span>`).join("")}
                </div>
                <p><strong>Debes registrar pagos por banco.</strong></p>
            </div>`,
    );
    return;
  }

  const bancoSeleccionado = Array.from(bancos)[0];

  // ✅ Abrir modal con resumen
  mostrarModalRegistroPago(seleccionadas, bancoSeleccionado);
}


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
          factura: f.invoice_number || "",
          estacion: f.estacion_nombre || "",
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
  // Reservar un slot fijo por archivo, en el orden en que se soltaron,
  // para evitar que el orden de resolución de los fetch determine la
  // posición/activación de las tarjetas (condición de carrera).
  const baseIdx = regPagoComprobantes.length;
  files.forEach((file) => {
    regPagoComprobantes.push({
      file: file,
      archivo: file.name,
      banco: "",
      fecha: new Date().toISOString().split("T")[0],
      referencia: "",
      importe: 0,
      error: "Leyendo...",
    });
  });
  // Activar siempre el primer archivo recién agregado (determinista)
  regPagoComprobanteActivo = baseIdx;
  recalcularRegPago();

  // Leer cada PDF de forma independiente reutilizando preview_comprobantes_match
  files.forEach((file, k) => {
    const slot = baseIdx + k;
    const fd = new FormData();
    fd.append("comprobantes[]", file);
    fetch("/payment/preview_comprobantes_match", { method: "POST", body: fd })
      .then((r) => r.json())
      .then((res) => {
        const c = (res.success && res.comprobantes && res.comprobantes[0])
          ? res.comprobantes[0].comprobante : null;
        regPagoComprobantes[slot] = {
          file: file,
          archivo: file.name,
          banco: c ? c.banco : "",
          fecha: c ? regPagoFechaAInput(c.fecha) : new Date().toISOString().split("T")[0],
          referencia: c ? (c.referencia || "") : "",
          importe: c ? (parseFloat(c.importe) || 0) : 0,
          error: c ? (c.error || "") : "No se pudo leer el PDF",
        };
        recalcularRegPago();
      })
      .catch(() => {
        regPagoComprobantes[slot] = {
          file: file, archivo: file.name, banco: "", fecha: new Date().toISOString().split("T")[0],
          referencia: "", importe: 0, error: "Error de conexión al leer el PDF",
        };
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


/**
 * ✅ Ejecuta el registro del pago
 */
function ejecutarRegistroPago() {
  // Validar formulario
  const fechaPago = $("#fecha_pago").val();
  const referenciaBancaria = $("#referencia_bancaria").val().trim();
  const invoiceIds = JSON.parse($("#invoice_ids_pago").val());
  const observaciones = $("#observaciones_pago").val().trim();
  const comprobante = $("#comprobante_pago")[0]?.files[0];

  if (!fechaPago || !referenciaBancaria) {
    alertify.error("Complete todos los campos obligatorios");
    return;
  }

  const formData = new FormData();
  formData.append("invoice_ids", JSON.stringify(invoiceIds));
  formData.append("fecha_pago", fechaPago);
  formData.append("referencia_bancaria", referenciaBancaria);
  formData.append("observaciones", observaciones);
  if (comprobante) {
    formData.append("comprobante", comprobante);
  }

  // ✅ Enviar al servidor
  alertify.message(
    '<i class="fas fa-spinner fa-spin"></i> Registrando pago...',
  );

  $.ajax({
    url: "/payment/execute_authorized_payments",
    type: "POST",
    data: formData,
    processData: false,
    contentType: false,
    success: function (response) {
      if (response.success) {
        alertify.success("✅ Pago registrado correctamente");
        // Mostrar resumen
        mostrarResumenPagoRegistrado(response);

        // Recargar tabla
        if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
          $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
        }
      } else {
        alertify.error(response.message || "Error al registrar pago");
      }
    },
    error: function (xhr, status, error) {
      console.error("Error AJAX:", { status, error, xhr });
      alertify.error("Error al registrar el pago");
    },
  });
}


function mostrarResumenPagoRegistrado(response) {
  const mensajeCompletadas =
    response.solicitudes_completadas > 0
      ? `<div class="alert alert-success mt-2" style="display: flex;    flex-direction: column;">
             <i class="fas fa-check-double"></i> 
             ${response.solicitudes_completadas} de ${response.total_solicitudes} 
             solicitud(es) completamente pagada(s)
           </div>`
      : "";

  alertify
    .alert(
      '<i class="fas fa-check-circle text-success"></i> Pago Registrado',
      `<div class="text-center">
            <i class="fas fa-check-double fa-3x text-success mb-3"></i>
            <h5>Pago Registrado Exitosamente</h5>
            
            <div class="alert alert-info mt-3 mb-3" style="display: flex;    flex-direction: column;">
                <strong><i class="fas fa-calendar"></i> Fecha:</strong> ${response.fecha_pago}<br>
                <strong><i class="fas fa-hashtag"></i> Referencia:</strong> ${response.referencia_bancaria}<br>
                <strong><i class="fas fa-file-invoice"></i> Facturas:</strong> ${response.facturas_procesadas}<br>
                <strong><i class="fas fa-dollar-sign"></i> Total:</strong> $${parseFloat(response.total_pagado).toLocaleString("es-MX", { minimumFractionDigits: 2 })}
            </div>
            
            ${mensajeCompletadas}
            
            <div class="alert alert-info mb-0">
                <small>
                    <i class="fas fa-info-circle"></i>
                    Las facturas han sido registradas con estado <strong>PAGADO</strong>
                </small>
            </div>
        </div>`,
    )
    .set({
      maximizable: false,
      closable: true,
    });
}


/**
 * Pago individual de un solo grupo (botón "Pagar" por fila en Facturas autorizadas).
 * Reutiliza el mismo modal y flujo del registro de pago, con un único grupo.
 */
function pagarGrupoIndividual(invoiceIds, banco, empresa, proveedor, monto) {
  const grupo = {
    banco: banco,
    invoice_ids: invoiceIds,
    empresa: empresa,
    proveedor: proveedor,
    monto: monto,
  };
  mostrarModalRegistroPago([grupo], banco);
}


function generarLayoutBanorte(
  gruposFacturas,
  gruposAnticipos,
  empresasSeleccionadas,
) {
  const todosLosInvoiceIds = [];
  gruposFacturas.forEach((grupo) => {
    const ids = String(grupo.invoice_ids).split(",").map((id) => parseInt(id.trim()));
    todosLosInvoiceIds.push(...ids);
  });

  const todosLosAnticipoIds = gruposAnticipos.map((a) =>
    parseInt(a.payment_request_id),
  );

  // ✅ Calcular totales
  const totalGruposFacturas = gruposFacturas.length;
  const totalGruposAnticipos = gruposAnticipos.length;
  const totalFacturas = todosLosInvoiceIds.length;
  const totalAnticipos = todosLosAnticipoIds.length;

  const totalMonto = [...gruposFacturas, ...gruposAnticipos].reduce(
    (sum, g) => sum + parseFloat(g.monto),
    0,
  );

  const empresasTexto =
    empresasSeleccionadas.length === 1
      ? empresasSeleccionadas[0]
      : empresasSeleccionadas.join(", ");

  // ✅ Construir resumen
  let resumenHTML = `
        <div class="alert alert-info mb-3" style="display:flex; flex-direction: column;">
            <strong><i class="fas fa-building"></i> Empresa(s):</strong> ${empresasTexto}<br>
            <strong><i class="fas fa-university"></i> Banco:</strong> Banorte<br>
    `;

  if (totalGruposFacturas > 0) {
    resumenHTML += `<strong><i class="fas fa-file-invoice"></i> Grupos de Facturas:</strong> ${totalGruposFacturas} (${totalFacturas} facturas)<br>`;
  }

  if (totalGruposAnticipos > 0) {
    resumenHTML += `<strong><i class="fas fa-hand-holding-usd"></i> Anticipos:</strong> ${totalAnticipos}<br>`;
  }

  resumenHTML += `
            <strong><i class="fas fa-dollar-sign"></i> Total:</strong> $${totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}
        </div>
    `;

  // ✅ Confirmación
  alertify
    .confirm(
      '<i class="fas fa-file-invoice-dollar text-primary"></i> Confirmar Generación de Layout Banorte',
      `<div class="text-center">
            ${resumenHTML}
            <p class="mb-2">¿Deseas generar el archivo de layout para carga en Banorte?</p>
            <small class="text-muted">Se generará un archivo de texto (.txt) con formato bancario.</small>
        </div>`,
      function () {
        ejecutarGeneracionLayoutBanorte(
          todosLosInvoiceIds,
          todosLosAnticipoIds,
        );
      },
      function () {
        alertify.message("Operación cancelada");
      },
    )
    .set("labels", { ok: "Generar Layout", cancel: "Cancelar" });
}


function ejecutarGeneracionLayoutBanorte(invoiceIds, anticipoIds) {
  alertify.message(
    '<i class="fas fa-spinner fa-spin"></i> Generando layout de Banorte...',
  );

  const btnLayout = $("#btnGenerarLayout");
  const btnOriginalHtml = btnLayout.html();
  btnLayout
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Generando...');

  fetch("/payment/generate_banorte_layout", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      invoice_ids: invoiceIds || [],
      anticipo_ids: anticipoIds || [],
    }),
  })
    .then((response) => {
      const contentType = response.headers.get("content-type");
      if (contentType && contentType.includes("application/json")) {
        return response.json().then((data) => {
          throw new Error(data.message || "Error al generar layout");
        });
      }

      if (!response.ok) {
        throw new Error("Error en la respuesta del servidor");
      }

      const disposition = response.headers.get("Content-Disposition");
      let filename = "LAYOUT_BANORTE_" + new Date().getTime() + ".txt";

      return response.blob().then((blob) => ({ blob, filename }));
    })
    .then(({ blob, filename }) => {
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.style.display = "none";
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);

      alertify.success("Layout de Banorte descargado correctamente");

      const totalPagos = (invoiceIds?.length || 0) + (anticipoIds?.length || 0);
      mostrarResumenLayoutBanorte(
        filename,
        totalPagos,
        invoiceIds?.length || 0,
        anticipoIds?.length || 0,
      );

      if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
        $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
      }
    })
    .catch((error) => {
      console.error("Error:", error);
      let mensaje = error.message || "Error al generar el layout";

      if (mensaje.includes("<")) {
        alertify
          .alert(
            '<i class="fas fa-exclamation-triangle text-danger"></i> Error al Generar Layout',
            mensaje,
          )
          .set({
            maximizable: false,
            closable: true,
          });
      } else {
        alertify.error(mensaje);
      }
    })
    .finally(() => {
      btnLayout.prop("disabled", false).html(btnOriginalHtml);
    });
}


function mostrarResumenLayoutBanorte(
  filename,
  totalPagos,
  totalFacturas,
  totalAnticipos,
) {
  let resumen = "";
  if (totalFacturas > 0) {
    resumen += `<strong><i class="fas fa-file-invoice"></i> Facturas:</strong> ${totalFacturas}<br>`;
  }
  if (totalAnticipos > 0) {
    resumen += `<strong><i class="fas fa-hand-holding-usd"></i> Anticipos:</strong> ${totalAnticipos}<br>`;
  }

  alertify
    .alert(
      '<i class="fas fa-check-circle text-success"></i> Layout Generado',
      `<div class="text-center">
            <i class="fas fa-file-download fa-3x text-primary mb-3"></i>
            <h5>Archivo Descargado Exitosamente</h5>
            
            <div class="alert alert-success mt-3 mb-3" style="flex-direction: column; display: flex; font-size: 1.1em; width: 100%; padding: 15px;">
                <strong><i class="fas fa-file-alt"></i> Archivo:</strong> ${filename}<br>
                ${resumen}
                <strong><i class="fas fa-check-double"></i> Total de pagos:</strong> ${totalPagos}
            </div>
            
            <hr>
            
            <div class="alert alert-info mb-0">
                <small>
                    <i class="fas fa-info-circle"></i>
                    <strong>Siguiente paso:</strong><br>
                    Sube este archivo a Banorte Empresarial para procesar los pagos.
                </small>
            </div>
        </div>`,
    )
    .set({
      maximizable: false,
      closable: true,
    });
}


async function addNoteModal(id) {
  try {
    $("#addNoteModal").modal("show");
    const providerEl = document.getElementById("paymentProviderId");
    const providerId = providerEl ? providerEl.value : "";
    const response = await fetch("/payment/addNoteModal", {
      method: "POST",
      headers: {
        Accept: "application/json, text/javascript, */*",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      credentials: "include",
      body: `provider_id=${providerId}`,
    });

    const content = await response.text();
    $("#addNoteModal").find("#addNoteModalContent").html(content);
  } catch (error) {
    console.error(error);
  }
}


function openApplyCreditNoteModal(invoiceId, invoiceFolio) {
  const providerEl = document.getElementById("paymentProviderId");
  const providerId = providerEl ? providerEl.value : "";
  const paymentId  = document.getElementById("paymentId").value;

  const invoiceSelect = document.getElementById("applyNoteInvoiceSelect");
  invoiceSelect.value = invoiceId || "";

  if (invoiceId && !invoiceFolio) {
    const opt = invoiceSelect.options[invoiceSelect.selectedIndex];
    invoiceFolio = opt ? opt.text : "";
  }

  const title = document.getElementById("applyNoteModalTitle");
  title.innerHTML = invoiceId
    ? `<i class="fas fa-link"></i> Aplicar Nota a Factura ${invoiceFolio || ""}`
    : `<i class="fas fa-link"></i> Aplicar Nota al Pago`;

  fetch("/payment/getProviderNotes", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    credentials: "include",
    body: `provider_id=${providerId}`,
  })
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) throw new Error(data.message);
      renderApplyNoteModalNotes(data.notes);
      document.getElementById("applyNotePaymentId").value = paymentId;
      return invoiceId ? loadInvoiceNoteApplications(invoiceId) : renderApplyNoteExistingList([]);
    })
    .then(() => {
      $("#applyNoteModal").modal("show");
    })
    .catch((err) => {
      Swal.fire({ icon: "error", title: "Error", text: err.message });
    });
}


function loadInvoiceNoteApplications(invoiceId) {
  return fetch("/payment/getInvoiceNoteApplications", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    credentials: "include",
    body: `invoice_id=${invoiceId}`,
  })
    .then((r) => r.json())
    .then((data) => {
      renderApplyNoteExistingList(data.success ? data.applications : []);
    });
}


function renderApplyNoteExistingList(applications) {
  const wrap = document.getElementById("applyNoteExistingWrap");
  const list = document.getElementById("applyNoteExistingList");
  if (!applications || applications.length === 0) {
    wrap.style.display = "none";
    list.innerHTML = "";
    return;
  }
  wrap.style.display = "";
  list.innerHTML = applications
    .map((app) => {
      const sign  = app.note_type === "CREDIT" ? "-" : "+";
      const badge = app.note_type === "CREDIT" ? "bg-success" : "bg-secondary";
      const amount = parseFloat(app.applied_amount).toFixed(2);
      return `<div class="d-flex justify-content-between align-items-center border rounded px-2 py-1" style="font-size:.82rem;">
        <span><span class="badge ${badge} me-1">${app.note_type === "CREDIT" ? "Crédito" : "Cargo"}</span>${app.note_number || "S/N"}</span>
        <span>
          <strong>${sign} $${amount}</strong>
          <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeApplication(${app.id}, true)" title="Quitar"><i class="fas fa-unlink"></i></button>
        </span>
      </div>`;
    })
    .join("");
}


function renderApplyNoteModalNotes(notes) {
  const list = document.getElementById("applyNoteCheckList");
  if (!notes || notes.length === 0) {
    list.innerHTML =
      '<span class="text-muted small">Sin notas disponibles para este proveedor</span>';
    updateApplyNoteSelectedHint();
    return;
  }
  list.innerHTML = notes
    .map((n) => {
      const type    = n.note_type === "CREDIT" ? "Crédito" : "Cargo";
      const badge   = n.note_type === "CREDIT" ? "bg-success" : "bg-secondary";
      const balance = parseFloat(n.available_balance).toFixed(2);
      const date    = n.note_date ? n.note_date.substring(0, 10) : "";
      return `<div class="d-flex align-items-center gap-2 py-1 border-bottom apply-note-row" data-note-id="${n.id}" data-balance="${balance}" data-type="${n.note_type}">
        <input type="checkbox" class="form-check-input mt-0 apply-note-check" onchange="onApplyNoteCheckToggle(this)">
        <div class="flex-grow-1" style="font-size:.82rem; line-height:1.15;">
          <span class="badge ${badge} me-1">${type}</span>${n.note_number || "S/N"}
          <span class="text-muted"> · ${date} · Saldo: $${balance}</span>
        </div>
        <input type="number" class="form-control form-control-sm apply-note-amount" style="width:110px;"
          step="0.01" min="0.01" max="${balance}" placeholder="Monto" disabled
          oninput="updateApplyNoteSelectedHint()">
      </div>`;
    })
    .join("");
  updateApplyNoteSelectedHint();
}


// Al marcar/desmarcar: habilita el monto y lo pre-llena con el saldo de la nota
function onApplyNoteCheckToggle(chk) {
  const row = chk.closest(".apply-note-row");
  const amt = row.querySelector(".apply-note-amount");
  amt.disabled = !chk.checked;
  if (chk.checked) {
    if (!amt.value) amt.value = row.getAttribute("data-balance");
    amt.focus();
  }
  updateApplyNoteSelectedHint();
}


// Resumen "N notas · $Total" en el encabezado de la lista
function updateApplyNoteSelectedHint() {
  const hint = document.getElementById("applyNoteSelectedHint");
  if (!hint) return;
  let count = 0;
  let total = 0;
  document.querySelectorAll("#applyNoteCheckList .apply-note-row").forEach((row) => {
    const chk = row.querySelector(".apply-note-check");
    if (chk && chk.checked) {
      count++;
      total += parseFloat(row.querySelector(".apply-note-amount").value || 0);
    }
  });
  hint.textContent = count ? `${count} nota(s) · $${total.toFixed(2)}` : "";
}


// Itera las notas marcadas y las aplica una por una vía /payment/applyCreditNote
async function applySelectedNotes() {
  const paymentId = document.getElementById("applyNotePaymentId").value;
  const invoiceId = document.getElementById("applyNoteInvoiceSelect").value;
  const rows = Array.from(
    document.querySelectorAll("#applyNoteCheckList .apply-note-row")
  ).filter((row) => row.querySelector(".apply-note-check").checked);

  if (rows.length === 0) {
    alertify.error("Seleccione al menos una nota");
    return;
  }

  // Validación de montos en cliente (el backend revalida saldos)
  for (const row of rows) {
    const amount  = parseFloat(row.querySelector(".apply-note-amount").value || 0);
    const balance = parseFloat(row.getAttribute("data-balance") || 0);
    if (amount <= 0) {
      alertify.error("Hay notas marcadas sin monto válido");
      return;
    }
    if (amount > balance + 0.001) {
      alertify.error(`Un monto excede el saldo disponible ($${balance.toFixed(2)})`);
      return;
    }
  }

  const submitBtn = document.querySelector('#applyNoteForm button[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;

  let okCount = 0;
  const errors = [];

  for (const row of rows) {
    const noteId = row.getAttribute("data-note-id");
    const amount = row.querySelector(".apply-note-amount").value;
    const body = new URLSearchParams({
      credit_note_id: noteId,
      payment_request_id: paymentId,
      applied_amount: amount,
    });
    if (invoiceId) body.append("invoice_id", invoiceId);

    try {
      const res  = await fetch("/payment/applyCreditNote", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "include",
        body: body.toString(),
      });
      const data = await res.json();
      if (data.success) {
        okCount++;
      } else {
        errors.push(data.message || "Error al aplicar una nota");
      }
    } catch (e) {
      errors.push("Error de conexión al aplicar una nota");
    }
  }

  if (submitBtn) submitBtn.disabled = false;

  if (okCount > 0) {
    applyNotePageDirty = true;
    alertify.success(`${okCount} nota(s) aplicada(s) correctamente`);
    // Refrescar lista de notas (saldos) y notas ya aplicadas a la factura
    openApplyCreditNoteModal(invoiceId || null, null);
  }
  if (errors.length > 0) {
    alertify.error(errors.join(" | "));
  }
}


// ── Subir PDF a nota desde payment_detail ────────────────────────────────────
// function openUploadDocModalPD(noteId) {
//   document.getElementById("uploadDocNoteIdPD").value = noteId;
//   document.getElementById("uploadDocFilePD").value = "";
//   document.querySelector("#uploadDocModalPD .custom-file-label").textContent =
//     "Seleccionar archivo PDF...";
//   $("#uploadDocModalPD").modal("show");
// }

// ── Ver PDFs de una nota desde payment_detail ─────────────────────────────────
function openNoteDocsModalPD(noteId) {
  document.getElementById("noteDocsModalPDNoteId").textContent = "#" + noteId;
  document.getElementById("noteDocsListPD").innerHTML =
    '<span class="text-muted small"><i class="fas fa-spinner fa-spin"></i> Cargando...</span>';
  document.getElementById("noteDocsViewerPD").src = "";
  document.getElementById("noteDocsDownloadBtnPD").href = "#";
  $("#noteDocsModalPD").modal("show");

  fetch("/payment/getNoteDocuments", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    credentials: "include",
    body: "note_id=" + noteId,
  })
    .then((r) => r.json())
    .then((data) => {
      const list = document.getElementById("noteDocsListPD");
      if (!data.success || !data.docs || data.docs.length === 0) {
        list.innerHTML = '<span class="text-muted small">Sin documentos</span>';
        return;
      }
      list.innerHTML = data.docs
        .map(
          (doc, i) =>
            `<button class="btn btn-sm btn-outline-danger note-doc-item-pd"
                     data-doc-id="${doc.id}"
                     title="Documento ${doc.id}">
              <i class="fas fa-file-pdf"></i> Doc ${doc.id}
            </button>`
        )
        .join("");
      openNoteDocViewerPD(data.docs[0].id);
    })
    .catch(() => {
      document.getElementById("noteDocsListPD").innerHTML =
        '<span class="text-danger small">Error al cargar documentos</span>';
    });
}


function openNoteDocViewerPD(docId) {
  const pdfUrl = "/payment/viewNoteDocument/" + docId;
  document.getElementById("noteDocsViewerPD").src = pdfUrl;
  document.getElementById("noteDocsDownloadBtnPD").href = pdfUrl;
  document.querySelectorAll(".note-doc-item-pd").forEach((b) => {
    b.classList.toggle("btn-danger", b.dataset.docId == docId);
    b.classList.toggle("btn-outline-danger", b.dataset.docId != docId);
  });
}


// ── Delegación: botón "Ver PDF(s)" en la lista de notas de payment_detail ─────
document.addEventListener("click", function (e) {
  var btn = e.target.closest(".view-note-docs-btn");
  if (!btn) return;
  openNoteDocsModalPD(btn.dataset.noteId);
});

// ── Delegación: selector de doc dentro del modal noteDocsModalPD ──────────────
document.addEventListener("click", function (e) {
  var btn = e.target.closest(".note-doc-item-pd");
  if (!btn) return;
  openNoteDocViewerPD(btn.dataset.docId);
});


// ── Submit: subir PDF desde payment_detail ────────────────────────────────────
// Función para abrir el modal de subir PDF
function openUploadDocModalPD(noteId) {
    document.getElementById('uploadDocNoteIdPD').value = noteId;
    document.getElementById('uploadDocFilePD').value = ''; // Limpiar input
    $('#uploadDocModalPD').modal('show');
}


// Función para subir el PDF
function subirPDFNota() {
    const form = document.getElementById('uploadDocFormPD');
    const fileInput = document.getElementById('uploadDocFilePD');
    
    // Validar que se haya seleccionado un archivo
    if (!fileInput.files || fileInput.files.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Archivo requerido',
            text: 'Por favor selecciona un archivo PDF'
        });
        return;
    }

    // Validar que sea PDF
    const file = fileInput.files[0];
    if (file.type !== 'application/pdf') {
        Swal.fire({
            icon: 'error',
            title: 'Formato inválido',
            text: 'Solo se permiten archivos PDF'
        });
        return;
    }

    // Validar tamaño (10MB)
    if (file.size > 10 * 1024 * 1024) {
        Swal.fire({
            icon: 'error',
            title: 'Archivo muy grande',
            text: 'El archivo no debe superar los 10MB'
        });
        return;
    }

    const formData = new FormData(form);

    Swal.fire({
        title: 'Subiendo archivo...',
        html: `<div class="progress">
                 <div class="progress-bar progress-bar-striped progress-bar-animated" 
                      role="progressbar" style="width: 100%">
                 </div>
               </div>`,
        allowOutsideClick: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('/payment/uploadNoteFile', {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: '¡Éxito!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                $('#uploadDocModalPD').modal('hide');
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'No se pudo subir el archivo'
            });
        }
    })
    .catch(error => {
        Swal.close();
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor'
        });
    });
}


function removeApplication(appId, fromModal) {
  Swal.fire({
    title: "¿Quitar esta nota aplicada?",
    text: "El saldo de la nota quedará disponible nuevamente.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Sí, quitar",
    cancelButtonText: "Cancelar",
  }).then((result) => {
    if (!result.isConfirmed) return;
    fetch("/payment/removeCreditNoteApplication", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      credentials: "include",
      body: `application_id=${appId}`,
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data.success) {
          Swal.fire({ icon: "error", title: "Error", text: data.message });
          return;
        }
        if (fromModal) {
          const invoiceId = document.getElementById("applyNoteInvoiceSelect").value;
          Swal.fire({ icon: "success", title: "Quitada", timer: 1200, showConfirmButton: false });
          applyNotePageDirty = true;
          // Recarga notas disponibles (saldo liberado) y lista de aplicadas a la factura
          openApplyCreditNoteModal(invoiceId || null, null);
        } else {
          Swal.fire({ icon: "success", title: "Quitada", timer: 1500 }).then(
            () => location.reload()
          );
        }
      })
      .catch(() => {
        Swal.fire({ icon: "error", title: "Error", text: "Error al quitar la aplicación" });
      });
  });
}


function deleteNote(noteId) {
  Swal.fire({
    title: "¿Está seguro?",
    text: "Esta acción no se puede deshacer",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Sí, eliminar",
    cancelButtonText: "Cancelar",
  }).then((result) => {
    if (result.isConfirmed) {
      $.ajax({
        url: "/payment/deleteCreditDebitNote/" + noteId,
        type: "DELETE",
        success: function (response) {
          if (response.success) {
            Swal.fire({
              icon: "success",
              title: "Eliminado",
              text: response.message,
              timer: 2000,
            }).then(() => {
              location.reload();
            });
          }
        },
        error: function (xhr) {
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Error al eliminar: " + xhr.responseText,
          });
        },
      });
    }
  });
}



function removeInvoiceFromPayment(invoice_id, folio) {
  Swal.fire({
    title: "¿Quitar esta factura?",
    html: `
              <p><strong>Folio:</strong> ${folio}</p>
              <div class="alert alert-warning mt-3">
                  <i class="fas fa-exclamation-triangle"></i>
                  Las autorizaciones serán reiniciadas
              </div>
          `,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#6c757d",
    confirmButtonText: "Sí, quitar",
    cancelButtonText: "Cancelar",
  }).then((result) => {
    if (result.isConfirmed) {
      $.ajax({
        url: "/payment/remove_invoice_from_payment",
        type: "POST",
        data: {
          invoice_id: invoice_id,
          payment_id: paymentId,
        },
        success: function (response) {
          if (response.success) {
            Swal.fire({
              icon: "success",
              title: "Factura eliminada",
              text: response.message,
              confirmButtonText: "OK",
            }).then(() => {
              location.reload();
            });
          } else {
            Swal.fire({
              icon: "error",
              title: "Error",
              text: response.message,
            });
          }
        },
        error: function () {
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Error al quitar la factura",
          });
        },
      });
    }
  });
}


function renderAvailableInvoices(invoices) {
  let html = `
          <div class="table-responsive">
              <table class="table table-sm table-hover">
                  <thead class="table-light">
                      <tr>
                          <th>Folio</th>
                          <th>Factura</th>
                          <th>Estación</th>
                          <th class="text-end">Monto</th>
                          <th>Fecha Vto.</th>
                          <th>Acciones</th>
                      </tr>
                  </thead>
                  <tbody>
      `;

  invoices.forEach(function (invoice) {
    html += `
              <tr>
                  <td><strong>${invoice.nro}</strong></td>
                  <td>${invoice.Factura}</td>
                  <td>${invoice.estacion_nombre || "N/A"}</td>
                  <td class="text-end">$${parseFloat(
                    invoice.total_fac,
                  ).toLocaleString("es-MX", {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  })}</td>
                  <td>${
                    invoice.fechaVto
                      ? new Date(invoice.fechaVto).toLocaleDateString("es-MX")
                      : "N/A"
                  }</td>
                  <td>
                      <button class="btn btn-sm btn-success" onclick='addInvoiceToPayment(${JSON.stringify(
                        invoice,
                      )})'>
                          <i class="fas fa-plus"></i> Agregar
                      </button>
                  </td>
              </tr>
          `;
  });

  html += `
                  </tbody>
              </table>
          </div>
      `;

  $("#availableInvoicesResults").html(html);
}


function addInvoiceToPayment(document) {
  Swal.fire({
    title: "¿Agregar esta factura?",
    html: `
              <div class="text-start">
                  <p><strong>Folio:</strong> ${document.nro}</p>
                  <p><strong>Factura:</strong> ${document.Factura}</p>
                  <p><strong>Monto:</strong> $${parseFloat(
                    document.total_fac,
                  ).toLocaleString("es-MX", {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  })}</p>
                  <div class="alert alert-warning mt-3">
                      <i class="fas fa-exclamation-triangle"></i>
                      Las autorizaciones serán reiniciadas
                  </div>
              </div>
          `,
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#3085d6",
    cancelButtonColor: "#d33",
    confirmButtonText: "Sí, agregar",
    cancelButtonText: "Cancelar",
  }).then((result) => {
    if (result.isConfirmed) {
      $.ajax({
        url: "/payment/add_invoice_to_payment",
        type: "POST",
        data: {
          payment_id: paymentId,
          document: document,
        },
        success: function (response) {
          if (response.success) {
            Swal.fire({
              icon: "success",
              title: "Factura agregada",
              text: response.message,
              confirmButtonText: "OK",
            }).then(() => {
              location.reload();
            });
          } else {
            Swal.fire({
              icon: "error",
              title: "Error",
              text: response.message,
            });
          }
        },
        error: function () {
          Swal.fire({
            icon: "error",
            title: "Error",
            text: "Error al agregar la factura",
          });
        },
      });
    }
  });
}

var tablaArchivosContabilidad = null;


/**
 * Abre el modal para crear un archivo de contabilidad.
 * Carga el contenido dinámicamente desde el servidor.
 */
async function abrirModalCrearArchivoContabilidad(providerCod, empCod) {
  $("#modalCrearArchivoContabilidad").modal("show");
  $("#modalCrearArchivoContabilidadContent").html(
    `<div class="modal-body text-center py-5">
       <i class="fas fa-spinner fa-spin fa-3x text-secondary"></i>
       <p class="mt-3">Cargando requisiciones...</p>
     </div>`
  );

  try {
    const formData = new URLSearchParams();
    if (providerCod) formData.append("provider_cod", providerCod);
    if (empCod)      formData.append("emp_cod",      empCod);

    const response = await fetch("/payment/modalCrearArchivoContabilidad", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: formData.toString(),
    });

    if (!response.ok) throw new Error("Error al cargar el formulario");

    const content = await response.text();
    $("#modalCrearArchivoContabilidadContent").html(content);
  } catch (error) {
    console.error("Error:", error);
    $("#modalCrearArchivoContabilidadContent").html(
      `<div class="modal-body">
         <div class="alert alert-danger">
           <i class="fas fa-exclamation-circle"></i>
           Error al cargar el formulario. Intente nuevamente.
         </div>
       </div>
       <div class="modal-footer">
         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
       </div>`
    );
  }
}


/**
 * Envía el form para crear el archivo de contabilidad.
 */
async function confirmarCrearArchivoContabilidad() {
  const form = document.getElementById("formCrearArchivoContabilidad");
  if (!form) return;

  const checkedBoxes = form.querySelectorAll('.req-checkbox:checked');
  if (checkedBoxes.length === 0) {
    Swal.fire({ icon: 'warning', title: 'Sin selección', text: 'Seleccione al menos una requisición.' });
    return;
  }

  const accountingId = document.getElementById("arch_accounting_id").value.trim();
  if (!accountingId) {
    Swal.fire({ icon: 'warning', title: 'ID requerido', text: 'Ingrese el ID de contabilidad.' });
    return;
  }

  Swal.fire({
    title: "Procesando...",
    text: "Creando archivo de contabilidad",
    allowOutsideClick: false,
    didOpen: () => Swal.showLoading(),
  });

  try {
    const formData = new FormData(form);

    const response = await fetch("/payment/create_accounting_group", {
      method: "POST",
      body: formData,
    });

    const data = await response.json();
    Swal.close();

    if (data.success) {
      Swal.fire({
        icon: "success",
        title: "Archivo creado",
        text: "ID Contabilidad: " + data.accounting_id,
        timer: 2000,
        showConfirmButton: false,
      }).then(() => {
        $("#modalCrearArchivoContabilidad").modal("hide");
        // Recargar tabla si ya se había inicializado
        if (tablaArchivosContabilidad) {
          tablaArchivosContabilidad.ajax.reload();
        }
      });
    } else {
      Swal.fire({ icon: "error", title: "Error", text: data.message || "No se pudo crear el archivo." });
    }
  } catch (error) {
    Swal.close();
    console.error("Error:", error);
    Swal.fire({ icon: "error", title: "Error", text: "Error de comunicación con el servidor." });
  }
}


/**
 * Carga el DataTable del tab "Archivos Contabilidad".
 * Se llama al hacer click en el tab (onclick).
 */
function loadAccountingGroupsTable() {
  if (tablaArchivosContabilidad) {
    tablaArchivosContabilidad.ajax.reload();
    return;
  }

  tablaArchivosContabilidad = $("#tabla_archivos_contabilidad").DataTable({
    ajax: {
      url: "/payment/get_accounting_groups_table",
      type: "POST",
      dataSrc: function (json) {
        return json.data || [];
      },
    },
    columns: [
      { data: "accounting_id" },
      { data: "razon_social", defaultContent: "-" },
      { data: "emp_name", defaultContent: "-" },
      { data: "total_requisiciones", className: "text-end" },
      {
        data: "monto_total",
        className: "text-end",
        render: function (data) {
          return "$" + parseFloat(data || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 });
        },
      },
      { data: "created_by_name", defaultContent: "-" },
      {
        data: "created_at",
        render: function (data) {
          if (!data) return "-";
          var d = new Date(data);
          return d.toLocaleDateString("es-MX");
        },
      },
      {
        data: null,
        orderable: false,
        className: "text-center",
        render: function (data, type, row) {
          return `<div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" title="Ver facturas"
                    onclick='toggleFacturasInline(this, ${row.id})'>
              <i class="fas fa-chevron-down"></i>
            </button>
            <button class="btn btn-outline-primary" title="Imprimir comprobantes de compra"
                    onclick='imprimirComprobantesGrupo(${row.id})'>
              <i class="fas fa-print"></i>
            </button>
          </div>`;
        },
      },
    ],
    order: [[6, "desc"]],
    pageLength: 25,
    responsive: true,
  });
}


/**
 * Agrupa automáticamente las requisiciones autorizadas por Tesorería para hoy.
 */
async function autoGroupToday() {
  const today = new Date().toISOString().split('T')[0];
  const confirm = await Swal.fire({
    title: '¿Agrupar requisiciones de hoy?',
    html: `Se agruparán todas las requisiciones autorizadas por Tesorería con fecha de pago <strong>${today}</strong>, por empresa.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Sí, agrupar',
    cancelButtonText: 'Cancelar',
    confirmButtonColor: '#16a34a'
  });

  if (!confirm.isConfirmed) return;

  Swal.fire({ title: 'Agrupando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

  try {
    const resp = await fetch('/payment/auto_group_accounting', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'date=' + encodeURIComponent(today)
    });
    const data = await resp.json();
    Swal.close();

    if (data.success) {
      Swal.fire({ icon: 'success', title: 'Listo', text: data.message, timer: 3000 })
        .then(() => loadAccountingGroupsTable());
    } else {
      Swal.fire({ icon: 'warning', title: 'Resultado', text: data.message });
    }
  } catch (e) {
    Swal.close();
    Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexión' });
  }
}


/**
 * Abre el modal con el detalle de facturas de un grupo de contabilidad.
 */
async function abrirModalDetalleFacturasGrupo(groupId, accountingId, empName) {
  const tplRes = await fetch("/payment/modalDetalleFacturasGrupo", { method: "POST" });
  const html = await tplRes.text();
  document.getElementById("modalDetalleFacturasGrupoContent").innerHTML = html;

  document.getElementById("dfg_accounting_id").textContent = accountingId;
  document.getElementById("dfg_empresa").textContent       = empName;

  $("#modalDetalleFacturasGrupo").modal("show");

  try {
    const res = await fetch("/payment/get_accounting_group_invoices", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "group_id=" + groupId,
    });
    const data = await res.json();

    document.getElementById("dfg_loading").style.display = "none";

    if (!data.success || !data.data || data.data.length === 0) {
      document.getElementById("dfg_empty").style.display = "";
      return;
    }

    document.getElementById("dfg_tabla_container").style.display = "";
    var tbody      = document.getElementById("dfg_tbody");
    tbody.innerHTML = "";
    var montoTotal  = 0;

    data.data.forEach(function (inv) {
      montoTotal += parseFloat(inv.amount || 0);
      var vto = inv.expiration_date
        ? new Date(inv.expiration_date).toLocaleDateString("es-MX")
        : "-";

      var badgeFactura;
      if (parseInt(inv.tiene_factura_recibida)) {
        badgeFactura = `<button class="btn btn-sm btn-success"
                                title="${inv.fr_nombre_archivo || 'Ver factura'}"
                                onclick='ModalinvoicePdf(${inv.fr_id}, {})'>
                          <i class="fas fa-file-pdf"></i>
                        </button>`;
      } else {
        badgeFactura = `<span class="badge bg-danger" title="No se descargó del correo">
                          <i class="fas fa-times"></i>
                        </span>`;
      }

      tbody.insertAdjacentHTML("beforeend", `<tr>
        <td><span class="badge bg-secondary">#${inv.payment_request_id}</span></td>
        <td><small>${inv.emp_name || "-"}</small></td>
        <td><small>${inv.provider_name || "-"}</small></td>
        <td>${inv.folio || "-"}</td>
        <td>${inv.invoice_number || "-"}</td>
        <td>${inv.codgas || "-"}</td>
        <td>${vto}</td>
        <td class="text-end fw-bold">$${parseFloat(inv.amount||0).toLocaleString("es-MX",{minimumFractionDigits:2})}</td>
        <td class="text-center">${badgeFactura}</td>
      </tr>`);
    });

    document.getElementById("dfg_total_facturas").textContent = data.data.length;
    document.getElementById("dfg_monto_total").textContent =
      "$" + montoTotal.toLocaleString("es-MX", { minimumFractionDigits: 2 });

  } catch (e) {
    console.error("Error cargando facturas del grupo:", e);
    document.getElementById("dfg_loading").style.display = "none";
    document.getElementById("dfg_empty").style.display   = "";
  }
}


/**
 * Abre el PDF de comprobantes de compra del grupo en nueva pestaña.
 */
function imprimirComprobantesGrupo(groupId) {
  window.open("/payment/print_accounting_group_receipts/" + groupId, "_blank");
}


/**
 * Expande / colapsa una fila inline con las facturas del grupo y su PDF.
 */
async function toggleFacturasInline(btn, groupId) {
  var tr      = $(btn).closest("tr");
  var rowId   = "inline-facturas-" + groupId;
  var existing = $("#" + rowId);

  // Colapsar si ya está abierta
  if (existing.length) {
    existing.remove();
    $(btn).find("i").removeClass("fa-chevron-up").addClass("fa-chevron-down");
    return;
  }

  $(btn).find("i").removeClass("fa-chevron-down").addClass("fa-chevron-up");

  // Número de columnas de la tabla para el colspan
  var colspan = tr.find("td").length;

  // Insertar fila con loader
  var loaderRow = `<tr id="${rowId}">
    <td colspan="${colspan}" style="padding:0; background:#f8f9fa;">
      <div class="text-center py-3">
        <i class="fas fa-spinner fa-spin text-secondary"></i>
        <span class="text-muted ms-2">Cargando facturas...</span>
      </div>
    </td>
  </tr>`;
  tr.after(loaderRow);

  try {
    const res = await fetch("/payment/get_accounting_group_invoices", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "group_id=" + groupId,
    });
    const data = await res.json();

    if (!data.success || !data.data || data.data.length === 0) {
      $("#" + rowId).find("td").html(
        `<div class="text-center text-muted py-3">
           <i class="fas fa-inbox"></i> No hay facturas para este grupo.
         </div>`
      );
      return;
    }

    // Construir tabla de facturas
    var rows = "";
    data.data.forEach(function (inv) {
      var vto = inv.expiration_date
        ? new Date(inv.expiration_date).toLocaleDateString("es-MX")
        : "-";

      var pdfBtn;
      if (parseInt(inv.tiene_factura_recibida)) {
        pdfBtn = `<button class="btn btn-sm btn-success" title="${inv.fr_nombre_archivo || 'Ver PDF'}"
                          onclick='ModalinvoicePdf(${inv.fr_id}, {})'>
                    <i class="fas fa-file-pdf"></i>
                  </button>`;
      } else {
        pdfBtn = `<span class="badge bg-danger" title="No descargada del correo">
                    <i class="fas fa-times"></i>
                  </span>`;
      }

      rows += `<tr>
        <td><span class="badge bg-secondary">#${inv.payment_request_id}</span></td>
        <td><small>${inv.emp_name || "-"}</small></td>
        <td><small>${inv.provider_name || "-"}</small></td>
        <td>${inv.folio || "-"}</td>
        <td>${inv.invoice_number || "-"}</td>
        <td>${inv.codgas || "-"}</td>
        <td>${vto}</td>
        <td class="text-end fw-bold">$${parseFloat(inv.amount||0).toLocaleString("es-MX",{minimumFractionDigits:2})}</td>
        <td class="text-center">${pdfBtn}</td>
      </tr>`;
    });

    var html = `
      <div style="padding: 8px 16px; background:#f8f9fa; border-top: 2px solid #dee2e6;">
        <div class="table-responsive">
          <table class="table table-sm table-bordered table-hover mb-0" style="background:#fff;">
            <thead class="table-secondary">
              <tr>
                <th>Req. #</th>
                <th>Empresa</th>
                <th>Proveedor</th>
                <th>Folio</th>
                <th>Factura</th>
                <th>Estación</th>
                <th>Vencimiento</th>
                <th class="text-end">Monto</th>
                <th class="text-center">PDF</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`;

    $("#" + rowId).find("td").html(html);

  } catch (e) {
    console.error("Error cargando facturas inline:", e);
    $("#" + rowId).find("td").html(
      `<div class="text-center text-danger py-3">
         <i class="fas fa-exclamation-circle"></i> Error al cargar las facturas.
       </div>`
    );
  }
}


// =====================================================================
// CONCILIACIÓN DE COMPROBANTES DE PAGO (carga masiva → preview)
// =====================================================================
let comprobantesGrupos = []; // grupos pendientes devueltos por el preview
let comprobantesFiles = [];  // File objects originales (para reenviar al guardar)
let comprobantesPreview = []; // resultado del preview por comprobante (para fecha/ref default)

function abrirModalComprobantes() {
  // Resetear estado del modal
  $("#inputComprobantes").val("");
  $("#comprobantesArchivosSel").html("");
  $("#comprobantesResumen").hide();
  $("#comprobantesTablaWrap").hide();
  $("#comprobantesLoading").hide();
  $("#tablaComprobantes tbody").empty();
  $("#comprobanteSelectAll").prop("checked", false);
  $("#comprobantesSeleccionInfo").text("");
  $("#btnGuardarComprobantes").prop("disabled", true);
  comprobantesGrupos = [];
  comprobantesFiles = [];
  comprobantesPreview = [];
  $("#modalComprobantes").modal("show");
}

// Wiring de la zona de carga (una sola vez)
$(document).on("click", "#comprobantesDropzone", function () {
  $("#inputComprobantes").click();
});
$(document).on("change", "#inputComprobantes", function () {
  if (this.files && this.files.length) {
    subirComprobantesPreview(this.files);
  }
});
$(document).on("dragover", "#comprobantesDropzone", function (e) {
  e.preventDefault();
  $(this).css("background", "#f3e8ff");
});
$(document).on("dragleave drop", "#comprobantesDropzone", function (e) {
  e.preventDefault();
  $(this).css("background", "#faf5ff");
});
$(document).on("drop", "#comprobantesDropzone", function (e) {
  const files = e.originalEvent.dataTransfer.files;
  if (files && files.length) subirComprobantesPreview(files);
});

function subirComprobantesPreview(fileList) {
  const files = Array.from(fileList).filter(
    (f) => f.type === "application/pdf" || f.name.toLowerCase().endsWith(".pdf")
  );
  if (files.length === 0) {
    alertify.warning("Selecciona archivos PDF");
    return;
  }

  // Conservar los File en el mismo orden en que se envían al backend,
  // para poder reenviarlos al guardar (move_uploaded_file lo exige).
  comprobantesFiles = files;

  $("#comprobantesArchivosSel").html(
    `<i class="fas fa-paperclip"></i> ${files.length} archivo(s) seleccionado(s)`
  );
  $("#comprobantesLoading").show();
  $("#comprobantesTablaWrap").hide();
  $("#comprobantesResumen").hide();

  const fd = new FormData();
  files.forEach((f) => fd.append("comprobantes[]", f));

  fetch("/payment/preview_comprobantes_match", { method: "POST", body: fd })
    .then((r) => r.json())
    .then((res) => {
      $("#comprobantesLoading").hide();
      if (!res.success) {
        alertify.error(res.message || "Error al procesar comprobantes");
        return;
      }
      comprobantesGrupos = res.grupos || [];
      comprobantesPreview = res.comprobantes || [];
      renderComprobantesResumen(res.resumen);
      renderComprobantesTabla(res.comprobantes);
      actualizarSeleccionComprobantes();
    })
    .catch((err) => {
      $("#comprobantesLoading").hide();
      alertify.error("Error de conexión: " + err.message);
    });
}

// Normaliza la fecha de un comprobante (dd/mm/aaaa [hh:mm]) a yyyy-mm-dd para el input date.
function fechaComprobanteAInput(fecha) {
  const m = (fecha || "").match(/(\d{2})\/(\d{2})\/(\d{4})/);
  if (m) return `${m[3]}-${m[2]}-${m[1]}`;
  return new Date().toISOString().split("T")[0];
}

function renderComprobantesResumen(r) {
  $("#compMatched").text(r.matched || 0);
  $("#compAmbiguo").text(r.ambiguo || 0);
  $("#compUnmatched").text(r.unmatched || 0);
  $("#compMontoTotal").text(
    "$" + (r.monto_total || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 })
  );
  $("#comprobantesResumen").show();
}

function fmtMoneda(v) {
  return "$" + (parseFloat(v) || 0).toLocaleString("es-MX", { minimumFractionDigits: 2 });
}

function fmtFechaCorta(f) {
  if (!f) return "—";
  const d = new Date(f);
  if (isNaN(d.getTime())) return "—";
  return d.toLocaleDateString("es-MX", { day: "2-digit", month: "2-digit", year: "numeric" });
}

function infoRequisicion(g) {
  if (!g || !g.payment_request_id) return "—";
  if (g.tipo_registro === "ANTICIPO") {
    return `<a href="/payment/anticipo_detail/${g.payment_request_id}" target="_blank" class="fw-semibold text-decoration-none">#${g.payment_request_id}</a><br><small class="text-warning fw-semibold">ANTICIPO</small>`;
  }
  return `<a href="/payment/payment_detail/${g.payment_request_id}" target="_blank" class="fw-semibold text-decoration-none">#${g.payment_request_id}</a><br><small class="text-muted">Esp. ${fmtFechaCorta(g.scheduled_payment_date)}</small>`;
}

// Normaliza nombres de razón social para comparar sin que puntuación ("S.A.
// DE C.V." vs "SA DE CV") o palabras genéricas rompan el match por texto.
function normalizarNombreEmpresa(s) {
  return (s || "")
    .toUpperCase()
    .replace(/[.,]/g, "")
    .replace(/\b(SA|SAPI|DE|CV|RL|S|C|V)\b/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

function opcionesGruposSelect(idxSeleccionado, c) {
  let opts = '<option value="">— Sin relacionar —</option>';

  const rfcEmpresa = (c && c.rfc_ordenante ? c.rfc_ordenante : "").trim().toUpperCase();
  const rfcProveedor = (c && c.rfc_beneficiario ? c.rfc_beneficiario : "").trim().toUpperCase();
  const nombreProveedorNorm = normalizarNombreEmpresa(c && c.nombre_beneficiario);

  const gruposFiltrados = comprobantesGrupos.filter((g) => {
    // Si ya está seleccionado ese grupo (reasignación), siempre se muestra.
    if (idxSeleccionado !== null && idxSeleccionado === g.idx) return true;
    if (rfcEmpresa && (g.empresa_rfc || "").toUpperCase() !== rfcEmpresa) return false;
    if (rfcProveedor) {
      return (g.proveedor_rfc || "").toUpperCase() === rfcProveedor;
    }
    if (nombreProveedorNorm) {
      const grupoProveedorNorm = normalizarNombreEmpresa(g.proveedor_nombre);
      return grupoProveedorNorm.includes(nombreProveedorNorm)
        || nombreProveedorNorm.includes(grupoProveedorNorm);
    }
    return true;
  });

  gruposFiltrados.forEach((g) => {
    const sel = idxSeleccionado !== null && idxSeleccionado === g.idx ? "selected" : "";
    const req = g.payment_request_id
      ? (g.tipo_registro === "ANTICIPO" ? ` · ANTICIPO #${g.payment_request_id}` : ` · Req #${g.payment_request_id}`)
      : "";
    opts += `<option value="${g.idx}" ${sel}>${g.empresa_nombre} / ${g.proveedor_nombre} · ${fmtMoneda(g.total_saldo)}${req}</option>`;
  });
  return opts;
}

function renderComprobantesTabla(comprobantes) {
  const colores = {
    matched: { bg: "#ecfdf5", badge: "bg-success", txt: "Emparejado" },
    ambiguo: { bg: "#fffbeb", badge: "bg-warning text-dark", txt: "Revisar" },
    unmatched: { bg: "#fef2f2", badge: "bg-danger", txt: "Sin emparejar" },
  };

  const hoy = new Date().toISOString().split("T")[0];
  let html = "";
  comprobantes.forEach((item, i) => {
    const c = item.comprobante;
    const g = item.grupo_sugerido;
    const estilo = colores[item.estado] || colores.unmatched;
    const idxSel = g ? g.idx : null;
    const tieneGrupo = g !== null && g !== undefined;
    // Por defecto se marca lo que ya viene emparejado o ambiguo con grupo.
    const checkDefault = tieneGrupo ? "checked" : "";

    const proveedorPdf = c.rfc_beneficiario
      ? `${c.nombre_beneficiario || ""}<br><small class="text-muted">${c.rfc_beneficiario}</small>`
      : `${c.nombre_beneficiario || "—"}<br><small class="text-muted">RFC benef. no incluido · cuenta ${c.cuenta_abono || "—"}</small>`;

    const errorPdf = c.error
      ? `<br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> ${c.error}</small>`
      : "";

    // El nombre que trae el PDF (ej. "Contrato" en Santander) puede no coincidir
    // con el RFC real de la transferencia (cuenta consolidada de grupo). El
    // match usa el RFC, así que si el nombre resuelto por RFC difiere del que
    // trae el PDF, se muestran ambos para no confundir sobre a qué empresa se
    // le está aplicando el pago.
    const nombreOrdenantePdf = (c.nombre_ordenante || "").trim();
    const empresaReal = (c.empresa_ordenante_real || "").trim();
    const nombreOrdenanteHtml = (empresaReal && empresaReal.toUpperCase() !== nombreOrdenantePdf.toUpperCase())
      ? `${empresaReal} <span class="badge bg-warning text-dark" title="El PDF dice '${nombreOrdenantePdf}', pero el RFC pertenece a esta empresa">RFC≠nombre PDF</span>`
      : (nombreOrdenantePdf || empresaReal || "—");

    // Fecha/referencia pre-rellenadas desde el PDF (editables)
    const fechaDefault = fechaComprobanteAInput(c.fecha);
    const refDefault = (c.referencia || "").replace(/"/g, "");

    html += `
      <tr data-row="${i}" style="background:${estilo.bg};">
        <td class="text-center">
          <input type="checkbox" class="comprobante-aplicar-check" data-row="${i}" ${checkDefault} ${tieneGrupo ? "" : "disabled"}>
        </td>
        <td><small class="fw-semibold">${c.archivo}</small>${errorPdf}</td>
        <td><small>${c.banco}</small></td>
        <td><small>${nombreOrdenanteHtml}<br><span class="text-muted">${c.rfc_ordenante || ""}</span></small></td>
        <td><small>${proveedorPdf}</small></td>
        <td class="text-end fw-semibold">${fmtMoneda(c.importe)}</td>
        <td style="min-width:260px;">
          <select class="form-select form-select-sm comprobante-grupo-select" data-row="${i}">
            ${opcionesGruposSelect(idxSel, c)}
          </select>
        </td>
        <td class="comprobante-requisicion"><small>${infoRequisicion(g)}</small></td>
        <td><input type="date" class="form-control form-control-sm comprobante-fecha" data-row="${i}" value="${fechaDefault}" max="${hoy}" style="min-width:140px;"></td>
        <td><input type="text" class="form-control form-control-sm comprobante-ref" data-row="${i}" value="${refDefault}" placeholder="Referencia" maxlength="50" style="min-width:140px;"></td>
        <td><span class="badge ${estilo.badge}">${estilo.txt}</span></td>
      </tr>`;
  });

  $("#tablaComprobantes tbody").html(html);
  $("#comprobantesTablaWrap").show();
}

// Reasignación manual: al cambiar el grupo, recalcular estado/badge y habilitar el check.
$(document).on("change", ".comprobante-grupo-select", function () {
  const $row = $(this).closest("tr");
  const val = $(this).val();
  const $badge = $row.find("td:last-child .badge");
  const $check = $row.find(".comprobante-aplicar-check");
  const $req = $row.find(".comprobante-requisicion");
  const g = val !== "" ? comprobantesGrupos.find((x) => String(x.idx) === val) : null;
  $req.html(`<small>${infoRequisicion(g)}</small>`);
  if (val === "") {
    $row.css("background", "#fef2f2");
    $badge.attr("class", "badge bg-danger").text("Sin emparejar");
    $check.prop("checked", false).prop("disabled", true);
  } else {
    $row.css("background", "#eff6ff");
    $badge.attr("class", "badge bg-primary").text("Manual");
    $check.prop("disabled", false).prop("checked", true);
  }
  actualizarSeleccionComprobantes();
});

// "Seleccionar todos": solo afecta filas con grupo asignado (checkbox habilitado).
$(document).on("change", "#comprobanteSelectAll", function () {
  const marcar = $(this).prop("checked");
  $(".comprobante-aplicar-check:not(:disabled)").prop("checked", marcar);
  actualizarSeleccionComprobantes();
});

$(document).on("change", ".comprobante-aplicar-check", function () {
  actualizarSeleccionComprobantes();
});

// Recalcula contador, total y estado del botón Guardar.
function actualizarSeleccionComprobantes() {
  let n = 0;
  let total = 0;
  $(".comprobante-aplicar-check:checked:not(:disabled)").each(function () {
    const i = parseInt($(this).data("row"));
    const item = comprobantesPreview[i];
    if (item) {
      n++;
      total += parseFloat(item.comprobante.importe) || 0;
    }
  });
  if (n > 0) {
    $("#comprobantesSeleccionInfo").html(
      `<strong>${n}</strong> pago(s) por aplicar · <strong>${fmtMoneda(total)}</strong>`
    );
    $("#btnGuardarComprobantes").prop("disabled", false);
  } else {
    $("#comprobantesSeleccionInfo").text("");
    $("#btnGuardarComprobantes").prop("disabled", true);
  }
}

// Guardar = aplicar pagos + guardar PDFs de las filas marcadas.
function guardarConciliacionComprobantes() {
  const asignaciones = [];
  let totalSel = 0;

  $(".comprobante-aplicar-check:checked:not(:disabled)").each(function () {
    const i = parseInt($(this).data("row"));
    const $row = $(`#tablaComprobantes tbody tr[data-row="${i}"]`);
    const grupoIdx = $row.find(".comprobante-grupo-select").val();
    if (grupoIdx === "") return;

    const grupo = comprobantesGrupos.find((g) => String(g.idx) === String(grupoIdx));
    if (!grupo) return;

    const invoiceIds = String(grupo.invoice_ids || "")
      .split(",")
      .map((x) => parseInt(x.trim()))
      .filter((x) => x > 0);

    const esAnticipo = grupo.tipo_registro === "ANTICIPO";

    asignaciones.push({
      archivo_idx: i,
      archivo: comprobantesPreview[i]?.comprobante?.archivo || `comprobante ${i}`,
      invoice_ids: esAnticipo ? [] : invoiceIds,
      anticipo_id: esAnticipo ? grupo.payment_request_id : 0,
      fecha_pago: $row.find(".comprobante-fecha").val(),
      referencia: $row.find(".comprobante-ref").val().trim(),
      observaciones: esAnticipo
        ? "Pago de anticipo vía conciliación de comprobantes"
        : "Conciliación automática de comprobante",
    });
    totalSel += parseFloat(comprobantesPreview[i]?.comprobante?.importe) || 0;
  });

  if (asignaciones.length === 0) {
    alertify.warning("No hay comprobantes seleccionados con grupo asignado");
    return;
  }

  // Validar fecha/referencia en cada uno
  const incompletos = asignaciones.filter((a) => !a.fecha_pago || !a.referencia);
  if (incompletos.length > 0) {
    alertify.error("Completa fecha y referencia en todas las filas seleccionadas");
    return;
  }

  alertify.confirm(
    '<i class="fas fa-check-circle text-success"></i> Confirmar conciliación',
    `<div class="text-center">
        <p>Vas a <strong>marcar como pagado</strong> y guardar el comprobante de:</p>
        <div class="alert alert-info">
          <strong>${asignaciones.length}</strong> pago(s) · Total <strong>${fmtMoneda(totalSel)}</strong>
        </div>
        <small class="text-muted">Esta acción registra las transacciones y no se puede deshacer fácilmente.</small>
     </div>`,
    function () {
      ejecutarConciliacionComprobantes(asignaciones);
    },
    function () {
      alertify.message("Conciliación cancelada");
    }
  ).set("labels", { ok: "Sí, aplicar", cancel: "Cancelar" });
}

function ejecutarConciliacionComprobantes(asignaciones) {
  const fd = new FormData();
  // Reenviar TODOS los PDFs en el mismo orden/índice que usa archivo_idx.
  comprobantesFiles.forEach((f) => fd.append("comprobantes[]", f));
  fd.append("asignaciones", JSON.stringify(asignaciones));

  $("#btnGuardarComprobantes")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Aplicando...');

  fetch("/payment/conciliar_comprobantes", { method: "POST", body: fd })
    .then((r) => r.json())
    .then((res) => {
      $("#btnGuardarComprobantes").html('<i class="fas fa-save"></i> Aplicar y guardar');
      if (!res.success && res.aplicados === 0) {
        alertify.error(res.message || "No se aplicó ningún pago");
        $("#btnGuardarComprobantes").prop("disabled", false);
      }
      // Mostrar resultado por comprobante
      let detalle = (res.resultados || [])
        .map(
          (x) =>
            `<li>${x.success ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-danger"></i>'} <strong>${x.archivo}</strong>: ${x.message}</li>`
        )
        .join("");
      alertify.alert(
        '<i class="fas fa-clipboard-check"></i> Resultado de la conciliación',
        `<div>
            <div class="alert alert-success">${res.aplicados} de ${res.total} aplicados · Total ${fmtMoneda(res.total_aplicado)}</div>
            <ul style="font-size:.85rem;max-height:300px;overflow:auto;">${detalle}</ul>
         </div>`
      );
      // Refrescar la tabla de facturas autorizadas (ya no estarán las pagadas)
      if ($.fn.DataTable.isDataTable("#tabla_facturas_autorizadas")) {
        $("#tabla_facturas_autorizadas").DataTable().ajax.reload(null, false);
      }
      if (res.aplicados > 0) {
        $("#modalComprobantes").modal("hide");
      }
    })
    .catch((err) => {
      $("#btnGuardarComprobantes")
        .prop("disabled", false)
        .html('<i class="fas fa-save"></i> Aplicar y guardar');
      alertify.error("Error de conexión: " + err.message);
    });
}

// ============================================================
// ANTICIPO DETAIL: autorización, pago y aplicación a facturas
// ============================================================

function openAuthModalAnticipo() {
  $("#authModalAnticipo").modal("show");
}

function confirmarAutorizacionAnticipo() {
  const anticipoId = $("#anticipoId").val();

  fetch("/payment/authorize_payment", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ payment_id: anticipoId, permission: 68 }),
  })
    .then((r) => r.json())
    .then((data) => {
      $("#authModalAnticipo").modal("hide");
      if (data.success) {
        alertify.success(data.message || "Anticipo autorizado");
        setTimeout(() => location.reload(), 1000);
      } else {
        alertify.error(data.message || "Error al autorizar");
      }
    })
    .catch(() => {
      $("#authModalAnticipo").modal("hide");
      alertify.error("Error de conexión al autorizar");
    });
}

function abrirModalPagarAnticipo() {
  $("#formPagarAnticipo")[0].reset();
  $("#pago_fecha").val(new Date().toISOString().slice(0, 10));
  $("#modalPagarAnticipo").modal("show");
}

function confirmarPagoAnticipo() {
  const anticipoId = $("#anticipoId").val();
  const fecha = $("#pago_fecha").val();
  const referencia = $("#pago_referencia").val().trim();
  const observaciones = $("#pago_observaciones").val().trim();
  const fileInput = document.getElementById("pago_comprobante");

  if (!fecha || !referencia) {
    alertify.error("Fecha y referencia bancaria son obligatorias");
    return;
  }
  if (!fileInput.files.length) {
    alertify.error("Debe adjuntar el comprobante de pago");
    return;
  }

  const formData = new FormData();
  formData.append("anticipo_id", anticipoId);
  formData.append("fecha_pago", fecha);
  formData.append("referencia", referencia);
  formData.append("observaciones", observaciones);
  formData.append("comprobante", fileInput.files[0]);

  $("#btnConfirmarPagoAnticipo")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Procesando...');

  fetch("/payment/pay_anticipo", { method: "POST", body: formData })
    .then((r) => r.json())
    .then((data) => {
      $("#modalPagarAnticipo").modal("hide");
      if (data.success) {
        alertify.success(data.message || "Anticipo pagado correctamente");
        setTimeout(() => location.reload(), 1200);
      } else {
        alertify.error(data.message || "Error al registrar el pago");
        $("#btnConfirmarPagoAnticipo")
          .prop("disabled", false)
          .html('<i class="fas fa-check"></i> Confirmar Pago');
      }
    })
    .catch(() => {
      alertify.error("Error de conexión al registrar el pago");
      $("#btnConfirmarPagoAnticipo")
        .prop("disabled", false)
        .html('<i class="fas fa-check"></i> Confirmar Pago');
    });
}


