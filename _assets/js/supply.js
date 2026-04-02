// Si el documento esta listo
$(document).ready(function () {

	// var proveedor_id = document.getElementById("proveedor_id");
	//  proveedor_id.addEventListener("change", function () {
	//  	console.log("Proveedor seleccionado:", this.value);
	//  });
	

	let inventory_mov_table = $("#inventory_mov_table").DataTable({
	colReorder: true,
	dom: '<"top"Bf>rt<"bottom"lip>',
	pageLength: 100,
	buttons: [
		{
		extend: "excel",
		className: "d-none",
		},
		{
		extend: "print", // Agrega el botón de impresión
		className: "d-none",
		},
	],
	ajax: {
		url:
		"/supply/inventory_mov_table/" +
		$("input#from").val() +
		"/" +
		$("select#station_id").val(),
		error: function () {
		$("#inventory_mov_table").waitMe("hide");
		alertify.myAlert(
			`<div class="container text-center text-danger">
					<h4 class="mt-2 text-danger">¡Error!</h4>
				</div>
				<div class="text-dark">
					<p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
				</div>`,
		);
		},
	},
	deferRender: true,
	columns: [
		{ data: "ESTACION" },
		{ data: "TURNO" },
		{ data: "PRODUCTO" },
		{ data: "CAP", render: $.fn.dataTable.render.number(",", ".", 3) },
		{ data: "VOLUMEN", render: $.fn.dataTable.render.number(",", ".", 3) },
		{
		data: "PORCENTAJE",
		render: $.fn.dataTable.render.number(",", ".", 0, "%"),
		},
	],
	createdRow: function (row, data, dataIndex) {},
	initComplete: function () {
		$(".dt-buttons").addClass("d-none");
		$('[data-toggle="tooltip"]').tooltip();
	},
	});

	// Agregar un evento clic de refresh
	$(".refresh_inventory_mov_table").on("click", function () {
	inventory_mov_table.clear().draw();
	inventory_mov_table.ajax.reload();
	$("#inventory_mov_table").waitMe("hide");
	});

	$("#ieps_value").text();
	$("#product").on(
	"changed.bs.select",
	function (e, clickedIndex, isSelected, previousValue) {
		var selectedValue = $(this).val();
		$.getJSON("/supply/get_ieps/" + selectedValue, function (json) {
		// Vamos a actualizar el contenido de la eqtiqueta <small> con el valor del IEPS
		$("#ieps_value").text("IEPS: " + json.abr);
		});
	},
	);
	let movimientoActual = {};
	let facturaProveedorSeleccionada = null;
	let facturaPetrotalSeleccionada = null;

	let datatable_product_prices = $("#datatable_product_prices").DataTable({
	colReorder: true,
	order: [0, "asc"],
	dom: '<"top"Bf>rt<"bottom"lip>',
	pageLength: 100,
	buttons: [
		{
		extend: "excel",
		className: "d-none",
		// Título del archivo de exportación
		title: "Precios de Combustibles",
		},
	],
	ajax: {
		url: "/supply/datatable_product_prices",
		type: "POST",
		error: function () {
		$("#datatable_product_prices").waitMe("hide");
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
	deferRender: true,
	columns: [
		{ data: "CODEST" },
		{ data: "ESTACION" },
		{ data: "PRECIOANTERIORMAXIMA" },
		{ data: "PRECIONUEVOMAXIMA" },
		{ data: "DIFERENCIAMAXIMA" },
		{ data: "PRECIOANTERIORSUPER" },
		{ data: "PRECIONUEVOSUPER" },
		{ data: "DIFERENCIASUPER" },
		{ data: "PRECIOANTERIORDIESEL" },
		{ data: "PRECIONUEVODIESEL" },
		{ data: "DIFERENCIADIESEL" },
	],
	rowId: "CODEST",
	createdRow: function (row, data, dataIndex) {
		// Vamos a agregar la clase .bg-success a las celdas de la columna 2,3 y 4
		// que tengan un valor mayor a 100
		$("td", row).eq(2).addClass("bg-success text-white text-center");
		$("td", row).eq(3).addClass("bg-success text-white text-center");
		$("td", row).eq(4).addClass("bg-success text-white text-center");

		$("td", row).eq(5).addClass("bg-primary text-white text-center");
		$("td", row).eq(6).addClass("bg-primary text-white text-center");
		$("td", row).eq(7).addClass("bg-primary text-white text-center");

		// Vamos a agregar la clase .bg-warning a las celdas de la columna 5,6 y 7 si el contenido de la celda es 'N/A'
		if ($("td", row).eq(6).text() === "N/A") {
		$("td", row).eq(5).addClass("bg-black");
		$("td", row).eq(6).addClass("bg-black");
		$("td", row).eq(7).addClass("bg-black");
		}

		$("td", row).eq(8).addClass("table-warning text-center");
		$("td", row).eq(9).addClass("table-warning text-center");
		$("td", row).eq(10).addClass("table-warning text-center");

		// Vamos a agregar la clase .bg-warning a las celdas de la columna 5,6 y 7 si el contenido de la celda es 'N/A'
		if ($("td", row).eq(9).text() === "N/A") {
		$("td", row).eq(8).addClass("bg-black text-white");
		$("td", row).eq(9).addClass("bg-black text-white");
		$("td", row).eq(10).addClass("bg-black text-white");
		}
	},
	initComplete: function () {
		$(".dt-buttons").addClass("d-none");
		$(".table-responsive").removeClass("loading");
	},
	});

	datatable_product_prices.on("draw", function () {
	$('[data-toggle="tooltip"]').tooltip();
	});

	// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
	$("#filtro-datatable_product_prices input").on(
	"keyup change clear",
	function () {
		datatable_product_prices
		.column(0)
		.search($("#CODEST").val().trim())
		.column(1)
		.search($("#ESTACION").val().trim())
		.column(2)
		.search($("#PRECIOANTERIORMAXIMA").val().trim())
		.column(3)
		.search($("#PRECIONUEVOMAXIMA").val().trim())
		.column(4)
		.search($("#DIFERENCIAMAXIMA").val().trim())
		.column(5)
		.search($("#PRECIOANTERIORSUPER").val().trim())
		.column(6)
		.search($("#PRECIONUEVOSUPER").val().trim())
		.column(7)
		.search($("#DIFERENCIASUPER").val().trim())
		.column(8)
		.search($("#PRECIOANTERIORDIESEL").val().trim())
		.column(9)
		.search($("#PRECIONUEVODIESEL").val().trim())
		.column(10)
		.search($("#DIFERENCIADIESEL").val().trim())
		.draw();
	},
	);

	// Agregar un evento clic de refresh
	$(".refresh_datatable_product_prices").on("click", function () {
		datatable_product_prices.clear().draw();
		datatable_product_prices.ajax.reload();
		$("#datatable_product_prices").waitMe("hide");
	});
});

function update_price(codprd, codgas, fch, hra, pre) {
  alertify.prompt(
    "Actualizar precio",
    "Por favor, ingrese el precio del producto: ",
    pre,
    function (evt, value) {
      // Convertir el valor ingresado a número decimal
      var price = parseFloat(value);

      // Validar si el valor es un número decimal válido
      if (!isNaN(price) && price >= 0) {
        // Comparar el precio ingresado con el precio actual
        if (price === parseFloat(pre)) {
          toastr.warning(
            "El precio ingresado es igual al precio actual.",
            "¡Atención!",
            { timeOut: 3000 },
          );
        } else {
          // Aqui vamos a ingresar un nuevo registro en la tabla de precios
          $.ajax({
            url: "/supply/update_price",
            type: "POST",
            data: {
              codprd: codprd,
              codgas: codgas,
              fch: fch,
              hra: hra,
              pre: price,
            },
            success: function (data) {
              if (data.status == "Success") {
                toastr.success(data.message, "¡Éxito!", { timeOut: 3000 });

                // Vamos a actualizar la tabla
                datatable_product_prices.clear().draw();
                datatable_product_prices.ajax.reload();

                // Vamos a remover la clase .loading de la tabla
                toastr.success(
                  "Por favor, espere mientras la tabla recarga la información",
                  "¡Éxito!",
                  { timeOut: 3000 },
                );
                // Vamos a esperar 4 segundos y removemos la clase .loading
                setTimeout(function () {
                  $(".table-responsive").removeClass("loading");
                }, 6000);
              } else {
                toastr.error(data.msg, "¡Error!", { timeOut: 3000 });
              }
            },
            error: function () {
              toastr.error(
                "Ocurrió un error al intentar actualizar el precio.",
                "¡Error!",
                { timeOut: 3000 },
              );
            },
          });
        }
      } else {
        toastr.error(
          "El valor ingresado no es un número decimal válido.",
          "¡Atención!",
          { timeOut: 3000 },
        );
      }
    },
    function () {
      toastr.info("Operación cancelada", "¡Atención!", { timeOut: 3000 });
    },
  );
}

function delete_price(codprd, codgas, fch, hra) {
  alertify.confirm(
    "Eliminar precio actual",
    "¿Está segur@ de eliminar el precio actual? El cambio no podrá deshacerse pero se guardará en la bitácora electrónica.",
    function () {
      // Aqui vamos a redirigir a la ruta de eliminación
      window.location.href =
        "/supply/delete_price/" + codprd + "/" + codgas + "/" + fch + "/" + hra;
      toastr.success("El precio fue eliminado correctamente.", "¡Éxito!", {
        timeOut: 3000,
      });
    },
    function () {
      toastr.info("Operación cancelada", "¡Atención!", { timeOut: 3000 });
    },
  );
}

let datatable_creProducts = $("#datatable_creProducts").DataTable({
  colReorder: true,
  order: [0, "asc"],
  dom: '<"top"Bf>rt<"bottom"lip>',
  pageLength: 100,
  buttons: [
    {
      extend: "excel",
      className: "d-none",
      // Título del archivo de exportación
      title: "Precios de Combustibles",
    },
  ],
  ajax: {
    url: "/supply/datatable_creProducts",
    type: "POST",
    error: function () {
      alertify.myAlert(
        `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`,
      );
    },
  },
  deferRender: true,
  columns: [
    { data: "ID" },
    { data: "ESTACIÓN" },
    { data: "CREPRODUCTO" },
    { data: "CRESUBPRODUCTO" },
    { data: "CREMARCA" },
    { data: "ALTA" },
    { data: "ACTIONS" },
  ],
  rowId: "ID",
  createdRow: function (row, data, dataIndex) {},
  initComplete: function () {
    $(".dt-buttons").addClass("d-none");
  },
});

datatable_creProducts.on("draw", function () {
  $('[data-toggle="tooltip"]').tooltip();
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$("#filtro-datatable_creProducts input").on("keyup change clear", function () {
  datatable_creProducts
    .column(0)
    .search($("#ID").val().trim())
    .column(1)
    .search($("#ESTACIÓN").val().trim())
    .column(2)
    .search($("#CREPRODUCTO").val().trim())
    .column(3)
    .search($("#CRESUBPRODUCTO").val().trim())
    .column(4)
    .search($("#CREMARCA").val().trim())
    .column(5)
    .search($("#ALTA").val().trim())
    .draw();
});

// Agregar un evento clic de refresh
$(".refresh_datatable_creProducts").on("click", function () {
  datatable_creProducts.clear().draw();
  datatable_creProducts.ajax.reload();
  $("#datatable_creProducts").waitMe("hide");
});


async function providers_table() {
  if ($.fn.DataTable.isDataTable("#providers_table")) {
    $("#providers_table").DataTable().destroy();
    $("#providers_table thead .filter").remove();
  }

  $("#providers_table thead").prepend(
    $("#providers_table thead tr").clone().addClass("filter"),
  );
  $("#providers_table thead tr.filter th").each(function (index) {
    col = $("#providers_table thead th").length / 2;
    if (index < col) {
      var title = $(this).text(); // Obtiene el nombre de la columna
      $(this).html(
        '<input type="text" class="form-control form-control-sm" placeholder=" ' +
          title +
          '" />',
      );
    }
  });
  $("#providers_table thead tr.filter th input").on(
    "keyup change",
    function () {
      var index = $(this).parent().index(); // Obtiene el índice de la columna
      var table = $("#providers_table").DataTable(); // Obtiene la instancia de DataTable
      table
        .column(index)
        .search(this.value) // Busca el valor del input
        .draw(); // Redibuja la tabla
    },
  );
  let providers_table = $("#providers_table").DataTable({
    order: [[3, "desc"]],
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    // scrollY: '700px',
    // scrollX: true,
    // scrollCollapse: true,
    paging: true,
    pageLength: 100,
    // processing: true,  // Agregar esta línea
    // serverSide: true,  // Agregar esta línea
    buttons: [
      {
        extend: "excel",
        className: "btn btn-success",
        text: " Excel",
      },
    ],
    ajax: {
      method: "POST",
      url: "/supply/providers_table",
      timeout: 600000,
      error: function () {
        $("#providers_table").waitMe("hide");
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
      { data: "id_control_gas", className: "text-nowrap" }, // Folio del documento
      { data: "proveedor" }, // Proveedor (t4.den)
      { data: "dias_credito", className: "text-nowrap" }, // Días Crédito
      {
        data: "total_facturado",
        render: $.fn.dataTable.render.number(",", ".", 2),
        className: "text-nowrap text-end",
      }, // Total total_facturado
      {
        data: "limite_credito",
        render: $.fn.dataTable.render.number(",", ".", 2),
        className: "text-nowrap text-end",
      }, // Límite Crédito
      { data: "condiciones_pago", className: "text-nowrap" }, // Condiciones Pago
      { data: "observaciones", className: "text-nowrap" }, // Observaciones
      { data: "activo", className: "text-nowrap" },
      {
        data: null,
        orderable: false,
        searchable: false,
        className: "text-center text-nowrap",
        render: function (data, type, row) {
          return `<button class="btn btn-sm btn-outline-primary"
                    onclick="abrirEditarProveedor(${row.id_control_gas}, '${(row.proveedor || '').replace(/'/g, "\\'")}', ${row.dias_credito || 0}, ${row.limite_credito || 0})"
                    title="Editar crédito">
                    <i class="fas fa-edit"></i>
                  </button>`;
        },
      },
    ],
    deferRender: true,
    createdRow: function (row, data, dataIndex) {
      if (
        parseFloat(data["total_facturado"]) >=
        parseFloat(data["limite_credito"])
      ) {
        // $(row).addClass('bg-warning');
      }
    },
    initComplete: function () {
      $(".table-responsive").removeClass("loading");
      // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
    },
    footerCallback: function (row, data, start, end, display) {},
  });
}

async function shop_fuel_table() {
  if ($.fn.DataTable.isDataTable("#shop_fuel_table")) {
    $("#shop_fuel_table").DataTable().destroy();
    $("#shop_fuel_table thead .filter").remove();
  }
  var fromDate = document.getElementById("from").value;
  var untilDate = document.getElementById("until").value;
  var codgas = document.getElementById("station_id").value;
  if (!codgas) {
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

  $("#shop_fuel_table thead").prepend(
    $("#shop_fuel_table thead tr").clone().addClass("filter"),
  );
  $("#shop_fuel_table thead tr.filter th").each(function (index) {
    col = $("#shop_fuel_table thead th").length / 2;
    if (index < col) {
      var title = $(this).text(); // Obtiene el nombre de la columna
      $(this).html(
        '<input type="text" class="form-control form-control-sm" placeholder=" ' +
          title +
          '" />',
      );
    }
  });
  $("#shop_fuel_table thead tr.filter th input").on(
    "keyup change",
    function () {
      var index = $(this).parent().index(); // Obtiene el índice de la columna
      var table = $("#shop_fuel_table").DataTable(); // Obtiene la instancia de DataTable
      table
        .column(index)
        .search(this.value) // Busca el valor del input
        .draw(); // Redibuja la tabla
    },
  );
  let shop_fuel_table = $("#shop_fuel_table").DataTable({
    order: [
      [1, "asc"],
      [2, "desc"],
    ],
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    // scrollY: '700px',
    // scrollX: true,
    // scrollCollapse: true,
    paging: true,
    pageLength: 100,
    // processing: true,  // Agregar esta línea
    // serverSide: true,  // Agregar esta línea
    buttons: [
      {
        extend: "excel",
        className: "btn btn-success",
        text: " Excel",
      },
    ],
    ajax: {
      method: "POST",
      data: {
        fromDate: fromDate,
        untilDate: untilDate,
        codgas: codgas,
      },
      url: "/supply/shop_fuel_table",
      timeout: 600000,
      error: function () {
        $("#shop_fuel_table").waitMe("hide");
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
      { data: "check_box" }, // Folio del documento
      { data: "gasolinera" }, // Folio del documento
      { data: "nro" }, // Folio del documento
      { data: "Factura" }, // Texto extraído de @F:
      { data: "Remision" }, // Texto extraído de @R:
      { data: "fecha" }, // Fecha (fch - 1)
      { data: "fechaVto" }, // Vencimiento (vto - 1)
      { data: "producto" }, // Producto (t3.den)
      { data: "proveedor" }, // Proveedor (t4.den)
      { data: "volrec", render: $.fn.dataTable.render.number(",", ".", 2) }, // Volumen recibido
      { data: "can", render: $.fn.dataTable.render.number(",", ".", 2) }, // Cantidad
      { data: "pre", render: $.fn.dataTable.render.number(",", ".", 4) }, // Precio unitario
      { data: "mto", render: $.fn.dataTable.render.number(",", ".", 2) }, // Monto
      { data: "mtoiie", render: $.fn.dataTable.render.number(",", ".", 2) }, // Monto IIE
      { data: "iva8", render: $.fn.dataTable.render.number(",", ".", 2) }, // IVA 8%
      { data: "iva", render: $.fn.dataTable.render.number(",", ".", 2) }, // IVA Extra
      { data: "iva_total", render: $.fn.dataTable.render.number(",", ".", 2) }, // Total IVA
      { data: "servicio", render: $.fn.dataTable.render.number(",", ".", 2) }, // Servicio
      {
        data: "iva_servicio",
        render: $.fn.dataTable.render.number(",", ".", 2),
      }, // IVA Servicio
      { data: "total_fac", render: $.fn.dataTable.render.number(",", ".", 2) }, // Total Factura
      { data: "satuid", className: "text-nowrap" }, // UID SAT
    ],
    deferRender: true,
    // destroy: true,
    createdRow: function (row, data, dataIndex) {
      var cls = data.control_estado === "SI" ? "bg-success" : "bg-danger";
      // $('td:eq(19)', row)
      //   .addClass(cls)
      //   .text(data.control); // muestra “12345 SI” o “12345 NO”
    },
    initComplete: function () {
      $(".table-responsive").removeClass("loading");
      // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
    },
    footerCallback: function (row, data, start, end, display) {},
  });
}


// ==========================================
// SISTEMA DE ASIGNACIÓN DE FACTURAS (DIRECTO Y PETROTAL)
// ==========================================

// Evento para cambio de tipo de operación
$(document).ready(function () {
  $('input[name="tipoOperacion"]').on("change", function () {
    const tipoPetrotal = $(this).val() === "2";

    // Mostrar/ocultar elementos según el tipo
    if (tipoPetrotal) {
      $("#paso2").fadeIn();
      $("#flecha2").fadeIn();
      $("#petrotal-tab-item").fadeIn();
      $("#paso3_directo").removeClass("col-md-3").addClass("col-md-3");
    } else {
      $("#paso2").fadeOut();
      $("#flecha2").fadeOut();
      $("#petrotal-tab-item").fadeOut();
      $("#paso3_directo").removeClass("col-md-3").addClass("col-md-3");

      // Limpiar selección de Petrotal
      facturaPetrotalSeleccionada = null;
      $("#info_petrotal").html('<span class="text-muted">Sin asignar</span>');
      $("#badge-petrotal")
        .removeClass("bg-success")
        .addClass("bg-danger")
        .text("Requerida");
    }

    validarAsignacionCompleta();
  });
});

function abrirModalAsignacion(movimiento) {
  movimientoActual = movimiento;
  facturaProveedorSeleccionada = null;
  facturaPetrotalSeleccionada = null;

  // Llenar información del movimiento
  $("#modal_nrotrn").text(movimiento.nrotrn);
  $("#modal_estacion").text(movimiento.estacion);
  $("#modal_fecha").text(movimiento.fecha);
  $("#modal_combustible").text(movimiento.combustible);
  $("#modal_litros").text(
    parseFloat(movimiento.recaudado || 0).toLocaleString("es-MX", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }),
  );

  // Determinar tipo de operación si ya está asignada
  if (movimiento.tiene_facturas_asignadas) {
    const tipoOp = movimiento.tipo_operacion || 1;
    $(`input[name="tipoOperacion"][value="${tipoOp}"]`)
      .prop("checked", true)
      .trigger("change");

    // Pre-cargar facturas asignadas
    if (movimiento.factura_proveedor_id) {
      facturaProveedorSeleccionada = {
        id: movimiento.factura_proveedor_id,
        uuid: movimiento.uuid_proveedor,
        folio: movimiento.folio_proveedor,
        total: movimiento.total_factura_proveedor,
        emisor: movimiento.emisor_factura_proveedor,
        litros: movimiento.litros_proveedor,
        precio: movimiento.precio_proveedor,
      };
      actualizarInfoProveedor();
    }

    if (movimiento.factura_petrotal_id) {
      facturaPetrotalSeleccionada = {
        id: movimiento.factura_petrotal_id,
        uuid: movimiento.uuid_petrotal,
        folio: movimiento.folio_petrotal,
        total: movimiento.total_factura_petrotal,
        litros: movimiento.litros_petrotal,
        precio: movimiento.precio_petrotal,
      };
      actualizarInfoPetrotal();
    }
  } else {
    // Nueva asignación - por defecto directo
    $("#tipoDirecto").prop("checked", true).trigger("change");
  }

  // Limpiar formularios
  $(
    "#search_factura_proveedor, #fecha_inicio_proveedor, #fecha_fin_proveedor",
  ).val("");
  $(
    "#search_factura_petrotal, #fecha_inicio_petrotal, #fecha_fin_petrotal",
  ).val("");
  $("#observaciones_asignacion").val(movimiento.observaciones || "");

  // Limpiar tablas
  $("#tbody_facturas_proveedor").html(`
        <tr><td colspan="8" class="text-center text-muted">
            <i class="fas fa-search"></i> Utilice los filtros para buscar facturas
        </td></tr>
    `);
  $("#tbody_facturas_petrotal").html(`
        <tr><td colspan="7" class="text-center text-muted">
            <i class="fas fa-search"></i> Utilice los filtros para buscar facturas
        </td></tr>
    `);

  $("#resumenAsignacion").hide();
  $("#btnGuardarAsignacion").prop("disabled", true);

  // Abrir modal
  $("#modalAsignarFactura").modal("show");
}

// ==========================================
// BÚSQUEDA DE FACTURAS PROVEEDOR
// ==========================================
function buscarFacturasProveedor() {
  const searchTerm = $("#search_factura_proveedor").val();
  const fechaInicio = $("#fecha_inicio_proveedor").val();
  const fechaFin = $("#fecha_fin_proveedor").val();

  if (!searchTerm && (!fechaInicio || !fechaFin)) {
    alertify.warning("Debe ingresar al menos un criterio de búsqueda");
    return;
  }

  $("#tbody_facturas_proveedor").html(`
        <tr><td colspan="8" class="text-center">
            <i class="fas fa-spinner fa-spin"></i> Buscando facturas...
        </td></tr>
    `);

  $.ajax({
    url: "/supply/buscar_facturas_proveedor",
    type: "POST",
    data: {
      search: searchTerm,
      fecha_inicio: fechaInicio,
      fecha_fin: fechaFin,
      tipo: "proveedor", // Excluir facturas de Petrotal
    },
    success: function (response) {
      if (response.success && response.data.length > 0) {
        let html = "";

        response.data.forEach((factura) => {
          // Calcular litros y precio por litro desde conceptos si existen
          const litros = factura.litros || 0;
          const precioLitro = litros > 0 ? factura.total / litros : 0;

          const btnSeleccionar = `
                        <button class="btn btn-sm btn-success" 
                                onclick='seleccionarFacturaProveedor(${JSON.stringify(factura)})' 
                                title="Seleccionar esta factura">
                            <i class="fas fa-check"></i>
                        </button>
                    `;

          html += `
                        <tr>
                            <td>${btnSeleccionar}</td>
                            <td><strong>${factura.folio || "N/A"}</strong></td>
                            <td>${factura.fecha}</td>
                            <td><small>${factura.emisor_nombre}</small></td>
                            <td class="text-end"><strong>$${parseFloat(factura.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong></td>
                            <td class="text-end">${litros > 0 ? litros.toLocaleString("es-MX", { minimumFractionDigits: 2 }) : "N/A"}</td>
                            <td class="text-end">${precioLitro > 0 ? "$" + precioLitro.toFixed(4) : "N/A"}</td>
                            <td><small class="font-monospace">${factura.uuid.substring(0, 8)}...</small></td>
                        </tr>
                    `;
        });

        $("#tbody_facturas_proveedor").html(html);
      } else {
        $("#tbody_facturas_proveedor").html(`
                    <tr><td colspan="8" class="text-center text-muted">
                        <i class="fas fa-inbox"></i> No se encontraron facturas
                    </td></tr>
                `);
      }
    },
    error: function () {
      alertify.error("Error al buscar facturas");
    },
  });
}

function seleccionarFacturaProveedor(factura) {
  facturaProveedorSeleccionada = factura;
  actualizarInfoProveedor();
  validarAsignacionCompleta();

  // Cambiar al tab de Petrotal si es operación con intermediario
  if ($('input[name="tipoOperacion"]:checked').val() === "2") {
    $("#petrotal-tab").click();
  }
}

function actualizarInfoProveedor() {
  if (facturaProveedorSeleccionada) {
    const litros = facturaProveedorSeleccionada.litros || 0;
    const precio =
      facturaProveedorSeleccionada.precio ||
      (litros > 0 ? facturaProveedorSeleccionada.total / litros : 0);

    $("#info_proveedor").html(`
            <strong class="text-success">${facturaProveedorSeleccionada.emisor_nombre || facturaProveedorSeleccionada.emisor}</strong><br>
            <small>Folio: ${facturaProveedorSeleccionada.folio}</small><br>
            <small>Total: $${parseFloat(facturaProveedorSeleccionada.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</small><br>
            <small>Precio/L: $${precio.toFixed(4)}</small>
        `);

    $("#badge-proveedor")
      .removeClass("bg-danger")
      .addClass("bg-success")
      .text("Asignada");

    $("#resumen_proveedor").html(`
            <strong>Folio:</strong> ${facturaProveedorSeleccionada.folio}<br>
            <strong>UUID:</strong> ${facturaProveedorSeleccionada.uuid}<br>
            <strong>Total:</strong> $${parseFloat(facturaProveedorSeleccionada.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
            <strong>Litros:</strong> ${litros.toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
            <strong>Precio/L:</strong> $${precio.toFixed(4)}
        `);

    $("#resumenAsignacion").show();
  }
}

// ==========================================
// BÚSQUEDA DE FACTURAS PETROTAL
// ==========================================
function buscarFacturasPetrotal() {
  const searchTerm = $("#search_factura_petrotal").val();
  const fechaInicio = $("#fecha_inicio_petrotal").val();
  const fechaFin = $("#fecha_fin_petrotal").val();

  if (!searchTerm && (!fechaInicio || !fechaFin)) {
    alertify.warning("Debe ingresar al menos un criterio de búsqueda");
    return;
  }

  $("#tbody_facturas_petrotal").html(`
        <tr><td colspan="7" class="text-center">
            <i class="fas fa-spinner fa-spin"></i> Buscando facturas...
        </td></tr>
    `);

  $.ajax({
    url: "/supply/buscar_facturas_petrotal",
    type: "POST",
    data: {
      search: searchTerm,
      fecha_inicio: fechaInicio,
      fecha_fin: fechaFin,
    },
    success: function (response) {
      if (response.success && response.data.length > 0) {
        let html = "";

        response.data.forEach((factura) => {
          const litros = factura.litros || 0;
          const precioLitro = litros > 0 ? factura.total / litros : 0;

          const btnSeleccionar = `
                        <button class="btn btn-sm btn-warning" 
                                onclick='seleccionarFacturaPetrotal(${JSON.stringify(factura)})' 
                                title="Seleccionar esta factura">
                            <i class="fas fa-check"></i>
                        </button>
                    `;

          html += `
                        <tr>
                            <td>${btnSeleccionar}</td>
                            <td><strong>${factura.folio || "N/A"}</strong></td>
                            <td>${factura.fecha}</td>
                            <td class="text-end"><strong>$${parseFloat(factura.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong></td>
                            <td class="text-end">${litros > 0 ? litros.toLocaleString("es-MX", { minimumFractionDigits: 2 }) : "N/A"}</td>
                            <td class="text-end">${precioLitro > 0 ? "$" + precioLitro.toFixed(4) : "N/A"}</td>
                            <td><small class="font-monospace">${factura.uuid.substring(0, 8)}...</small></td>
                        </tr>
                    `;
        });

        $("#tbody_facturas_petrotal").html(html);
      } else {
        $("#tbody_facturas_petrotal").html(`
                    <tr><td colspan="7" class="text-center text-muted">
                        <i class="fas fa-inbox"></i> No se encontraron facturas de Petrotal
                    </td></tr>
                `);
      }
    },
    error: function () {
      alertify.error("Error al buscar facturas");
    },
  });
}

function seleccionarFacturaPetrotal(factura) {
  facturaPetrotalSeleccionada = factura;
  actualizarInfoPetrotal();
  validarAsignacionCompleta();
  calcularMargen();
}

function actualizarInfoPetrotal() {
  if (facturaPetrotalSeleccionada) {
    const litros = facturaPetrotalSeleccionada.litros || 0;
    const precio =
      facturaPetrotalSeleccionada.precio ||
      (litros > 0 ? facturaPetrotalSeleccionada.total / litros : 0);

    $("#info_petrotal").html(`
            <strong class="text-warning">PETROTAL</strong><br>
            <small>Folio: ${facturaPetrotalSeleccionada.folio}</small><br>
            <small>Total: $${parseFloat(facturaPetrotalSeleccionada.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</small><br>
            <small>Precio/L: $${precio.toFixed(4)}</small>
        `);

    $("#badge-petrotal")
      .removeClass("bg-danger")
      .addClass("bg-success")
      .text("Asignada");

    $("#resumen_petrotal_container").show();
    $("#resumen_petrotal").html(`
            <strong>Folio:</strong> ${facturaPetrotalSeleccionada.folio}<br>
            <strong>UUID:</strong> ${facturaPetrotalSeleccionada.uuid}<br>
            <strong>Total:</strong> $${parseFloat(facturaPetrotalSeleccionada.total).toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
            <strong>Litros:</strong> ${litros.toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
            <strong>Precio/L:</strong> $${precio.toFixed(4)}
        `);
  }
}

// ==========================================
// VALIDACIÓN Y CÁLCULOS
// ==========================================
function validarAsignacionCompleta() {
  const tipoOperacion = $('input[name="tipoOperacion"]:checked').val();
  let esValido = false;

  if (tipoOperacion === "1") {
    // Operación directa: solo necesita factura proveedor
    esValido = facturaProveedorSeleccionada !== null;
  } else {
    // Operación con Petrotal: necesita ambas facturas
    esValido =
      facturaProveedorSeleccionada !== null &&
      facturaPetrotalSeleccionada !== null;
  }

  $("#btnGuardarAsignacion").prop("disabled", !esValido);

  return esValido;
}

function calcularMargen() {
  if (facturaProveedorSeleccionada && facturaPetrotalSeleccionada) {
    const litrosProv = facturaProveedorSeleccionada.litros || 0;
    const litrosPetro = facturaPetrotalSeleccionada.litros || 0;

    if (litrosProv > 0 && litrosPetro > 0) {
      const precioProveedor = facturaProveedorSeleccionada.total / litrosProv;
      const precioPetrotal = facturaPetrotalSeleccionada.total / litrosPetro;

      const diferencia = precioPetrotal - precioProveedor;
      const margen = (diferencia / precioProveedor) * 100;

      $("#diferencia_precio").text(diferencia.toFixed(4));
      $("#margen_porcentual").text(margen.toFixed(2));

      $("#analisis_margen").show();
    }
  }
}

// ==========================================
// GUARDAR ASIGNACIÓN
// ==========================================
function guardarAsignacionCompleta() {
  if (!validarAsignacionCompleta()) {
    alertify.error("Debe seleccionar todas las facturas requeridas");
    return;
  }

  const tipoOperacion = $('input[name="tipoOperacion"]:checked').val();
  const observaciones = $("#observaciones_asignacion").val();

  // Preparar datos para enviar
  const datosAsignacion = {
    nrotrn: movimientoActual.nrotrn,
    codgas: movimientoActual.numero_estacion,
    tipo_operacion: tipoOperacion,
    observaciones: observaciones,

    // Factura Proveedor (siempre presente)
    factura_proveedor_id: facturaProveedorSeleccionada.id,
    uuid_proveedor: facturaProveedorSeleccionada.uuid,
    folio_proveedor: facturaProveedorSeleccionada.folio,
    monto_proveedor: facturaProveedorSeleccionada.total,
    litros_proveedor: facturaProveedorSeleccionada.litros || 0,
    precio_proveedor:
      facturaProveedorSeleccionada.litros > 0
        ? facturaProveedorSeleccionada.total /
          facturaProveedorSeleccionada.litros
        : 0,
  };

  // Agregar factura Petrotal si aplica
  if (tipoOperacion === "2" && facturaPetrotalSeleccionada) {
    datosAsignacion.factura_petrotal_id = facturaPetrotalSeleccionada.id;
    datosAsignacion.uuid_petrotal = facturaPetrotalSeleccionada.uuid;
    datosAsignacion.folio_petrotal = facturaPetrotalSeleccionada.folio;
    datosAsignacion.monto_petrotal = facturaPetrotalSeleccionada.total;
    datosAsignacion.litros_petrotal = facturaPetrotalSeleccionada.litros || 0;
    datosAsignacion.precio_petrotal =
      facturaPetrotalSeleccionada.litros > 0
        ? facturaPetrotalSeleccionada.total / facturaPetrotalSeleccionada.litros
        : 0;
  }

  // Deshabilitar botón mientras guarda
  $("#btnGuardarAsignacion")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

  $.ajax({
    url: "/supply/guardar_asignacion_completa",
    type: "POST",
    data: datosAsignacion,
    success: function (response) {
      if (response.success) {
        alertify.success(response.message);
        $("#modalAsignarFactura").modal("hide");

        // Recargar tabla
        $("#resumen_payment_table").DataTable().ajax.reload(null, false);
      } else {
        alertify.error(response.message);
        $("#btnGuardarAsignacion")
          .prop("disabled", false)
          .html('<i class="fas fa-save"></i> Guardar Asignación');
      }
    },
    error: function () {
      alertify.error("Error al guardar la asignación");
      $("#btnGuardarAsignacion")
        .prop("disabled", false)
        .html('<i class="fas fa-save"></i> Guardar Asignación');
    },
  });
}

// ==========================================
// ELIMINAR ASIGNACIÓN
// ==========================================
function eliminarAsignacion(movimiento) {
  alertify.confirm(
    "Eliminar Asignación",
    "¿Está seguro de eliminar la asignación de facturas de este movimiento? Esta acción no se puede deshacer.",
    function () {
      $.ajax({
        url: "/supply/eliminar_asignacion_factura",
        type: "POST",
        data: {
          nrotrn: movimiento.nrotrn,
          codgas: movimiento.numero_estacion,
        },
        success: function (response) {
          if (response.success) {
            alertify.success(response.message);
            $("#resumen_payment_table").DataTable().ajax.reload(null, false);
          } else {
            alertify.error(response.message);
          }
        },
        error: function () {
          alertify.error("Error al eliminar asignación");
        },
      });
    },
    function () {
      alertify.message("Operación cancelada");
    },
  );
}

// Filtro rápido activo (columna Estado col 22)
var _filtroRapidoActivo = 'todas';

function aplicarFiltroRapido(filtro) {
  _filtroRapidoActivo = filtro;
  // Marcar botón activo
  document.querySelectorAll('#filtros_rapidos .btn').forEach(function(b){ b.classList.remove('activo'); });
  document.getElementById('filtro_' + (filtro === 'sin_fac' ? 'sin_fac' : filtro)).classList.add('activo');

  var table = $("#compras_combustible_table").DataTable();
  if (filtro === 'todas') {
    table.column(22).search('').draw();
  } else if (filtro === 'sin_fac') {
    table.column(22).search('Sin Factura SAT').draw();
  } else if (filtro === 'asignadas') {
    table.column(22).search('Asignada').draw();
  } else if (filtro === 'petrotal') {
    table.column(22).search('Con Petrotal').draw();
  }
}

async function compras_facturas_table() {
    if ($.fn.DataTable.isDataTable("#compras_combustible_table")) {
      $("#compras_combustible_table").DataTable().destroy();
      $("#compras_combustible_table thead .filter").remove();
    }
    // OBTENER TODOS LOS FILTROS
    var fromDate = document.getElementById("from_compras").value;
    var untilDate = document.getElementById("until_compras").value;
    var codgas = document.getElementById("codgas_compras").value;
    var proveedor = document.getElementById("proveedor_compras").value;
    var company = 0;

    if (!fromDate || !untilDate) {
      alertify.myAlert(
        `<div class="container text-center text-danger">
                  <h4 class="mt-2 text-danger">¡Error!</h4>
              </div>
              <div class="text-dark">
                  <p class="text-center">Debe seleccionar las fechas para continuar.</p>
              </div>`,
      );
      return;
    }

    // Guardar filtros en localStorage
    localStorage.setItem('compras_filtros', JSON.stringify({
      from: fromDate, until: untilDate, codgas: codgas, proveedor: proveedor
    }));

    // Resetear filtro rápido
    _filtroRapidoActivo = 'todas';

    // Agregar fila de filtros en el thead
    $("#compras_combustible_table thead").prepend(
      $("#compras_combustible_table thead tr").clone().addClass("filter"),
    );
    $("#compras_combustible_table thead tr.filter th").each(function (index) {
      var col = $("#compras_combustible_table thead th").length / 2;
      if (index < col) {
        var title = $(this).text();
        $(this).html(
          '<input type="text" class="form-control form-control-sm" placeholder="' +
            title +
            '" />',
        );
      }
    });

    $("#compras_combustible_table thead tr.filter th input").on(
      "keyup change",
      function () {
        var index = $(this).parent().index();
        var table = $("#compras_combustible_table").DataTable();
        table.column(index).search(this.value).draw();
      },
    );

    let compras_combustible_table = $("#compras_combustible_table").DataTable({
      order: [[0, "desc"]],
      colReorder: false,
      dom: '<"top"Bf>rt<"bottom"lip>',
      scrollX: true,
      scrollY: "calc(100vh - 350px)",
      scrollCollapse: true,
      paging: false,
      fixedColumns: { left: 4 },
      columnDefs: [
        // Ocultar por defecto: Hora(1), Nro.Trn(2), Num.Est(4), Litros Fact CG(8),
        // Nro Doc CG(9), Remisión(11), Precio Cotizado(14), Diferencia(15),
        // %IVA(16), IEPS(17), Num.Fac Petrotal(19), Monto Petrotal(20), UUID(21)
        // { targets: [
        //     1,//hora
        //      2,//numero de transaccion 
        //     //  4, 
        //     //  8, 9, 11, 14, 15, 16, 17, 19, 20, 21
        //  ], visible: false }
      ],
      buttons: [
        {
          className: "btn btn-warning",
          text: '<i class="fas fa-exchange-alt"></i> Reconciliar',
          action: function () {
            abrirVistaReconciliacion();
          },
        },
        {
          extend: "excel",
          className: "btn btn-success",
          text: '<i class="fas fa-file-excel"></i> Excel',
          title: "Compras_Facturas_" + fromDate + "_" + untilDate,
          exportOptions: {
            columns: ":visible:not(:last-child)",
          },
        },
        {
          extend: "print",
          className: "btn btn-secondary",
          text: '<i class="fas fa-print"></i> Imprimir',
          exportOptions: {
            columns: ":visible:not(:last-child)",
          },
        },
        {
          extend: "colvis",
          className: "btn btn-info",
          text: '<i class="fas fa-columns"></i> Columnas',
        }
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
        url: "/supply/compras_combustible_table",
        timeout: 600000,
        error: function (xhr, error, thrown) {
          $(".table-responsive").removeClass("loading");
          alertify.myAlert(
            `<div class="container text-center text-danger">
                          <h4 class="mt-2 text-danger">¡Error!</h4>
                      </div>
                      <div class="text-dark">
                          <p class="text-center">No se pudieron cargar las facturas.</p>
                          <small>${thrown}</small>
                      </div>`,
          );
        },
        beforeSend: function () {
          $(".table-responsive").addClass("loading");
        },
        dataSrc: function (json) {
          if (json.error) {
            alertify.error(json.message);
            return [];
          }
          var total = json.data.length;
          var asignadas = json.data.filter(function(r){ return r.tiene_factura; }).length;
          var pendientes = total - asignadas;
          var pct = total > 0 ? Math.round(asignadas / total * 100) : 0;

          // Calcular total monto controlgas
          var totalMonto = json.data.reduce(
            (sum, item) => sum + parseFloat(item.monto_factura_controlgas || 0), 0
          );

          // Actualizar badge header
          $("#contador_facturas").text(total + " recepciones");
          $("#total_monto_facturas").text(
            "$" + totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 })
          );

          // Actualizar KPI strip
          $("#kpi_total").text(total);
          $("#kpi_asignadas").text(asignadas);
          $("#kpi_pendientes").text(pendientes);
          $("#kpi_monto").text("$" + (totalMonto / 1000000).toLocaleString("es-MX", { minimumFractionDigits: 2 }) + "M");
          $("#kpi_strip").show();

          // Actualizar barra de progreso
          $("#barra_asignacion").css("width", pct + "%").attr("aria-valuenow", pct).text(pct + "%");
          $("#barra_pct_text").text(pct + "% asignadas");
          $("#barra_asignacion_wrap").show();

          // Mostrar filtros rápidos y resetear botón activo
          $("#filtros_rapidos").css('display', 'flex');
          document.querySelectorAll('#filtros_rapidos .btn').forEach(function(b){ b.classList.remove('activo'); });
          document.getElementById('filtro_todas').classList.add('activo');

          return json.data;
        },
      },
      columns: [
        { data: "fecha", className: "text-start text-nowrap" },// col 0 — FECHA DESCARGA
        { data: "hora", className: "text-start text-nowrap" }, // col 1 — HORA
        { data: "nrotrn", className: "text-start text-nowrap" },// col 2 — NRO TRANSACCIÓN
        { data: "estacion", className: "text-start text-nowrap" },// col 3 — ESTACIÓN (nombre)
        { data: "numero_estacion", className: "text-center" },// col 4 — NUMERO DE ESTACION
        { data: "proveedor_original", className: "text-start text-nowrap" },// col 6 — PROVEEDOR (vacío si es PETROTAL)
        { data: "combustible", className: "text-start" }, // col 5 — PRODUCTO (combustible normalizado)
        {
          data: "factura_proveedor",
          className: "text-start text-nowrap",
          render: function (data, type, row) {
            if (!data) return '<span class="text-muted">-</span>';
            if (row.RutaArchivo) {
              return `<a href="javascript:void(0);"
                          onclick='ModalinvoicePdf(${row.factura_recibida_id}, ${JSON.stringify(row).replace(/'/g, "&apos;")})'
                          class="text-primary fw-bold"
                          title="Ver factura PDF">
                          <i class="fas fa-file-pdf text-danger"></i> ${data}
                      </a>`;
            }
            return data;
          },
        },
        { data: "proveedor_controlgas", className: "text-start text-nowrap" },// col 6 — PROVEEDOR (vacío si es PETROTAL)


        // col 7 — LITROS EN DOCUMENTOS SOPORTE (recaudado = lo que entró al tanque)
        {
          data: "recaudado",
          className: "text-end",
          render: $.fn.dataTable.render.number(",", ".", 2),
        },
        // col 8 — LITROS FACTURADOS EN CONTROL GAS (fac_rec = litros del documento de compra)
        {
          data: "fac_rec",
          className: "text-end",
          render: $.fn.dataTable.render.number(",", ".", 2),
        },
        // col 9 — NRO DOC CONTROL GAS
        {
          data: "nro_fac",
          className: "text-start text-nowrap",
          render: function (data) {
            return data || '<span class="text-muted">-</span>';
          },
        },
       
        // col 11 — REMISIÓN
        {
          data: "remision_factura",
          className: "text-start text-nowrap",
          render: function (data) {
            return data || '<span class="text-muted">-</span>';
          },
        },
        // col 12 — MONTO FACTURA (de ControlGas)
        {
          data: "monto_factura_controlgas",
          className: "text-end",
          render: $.fn.dataTable.render.number(",", ".", 2, "$"),
        },
        // col 13 — PRECIO X LITRO FACTURA (calculado: monto / fac_rec)
        {
          data: null,
          className: "text-end",
          render: function (data, type, row) {
            var litros = parseFloat(row.fac_rec || 0);
            var monto = parseFloat(row.monto_factura_controlgas || 0);
            if (litros <= 0 || monto <= 0) return '<span class="text-muted">-</span>';
            return "$" + (monto / litros).toFixed(4);
          },
        },
        // col 14 — PRECIO COTIZADO (viene del SAT si hay factura asignada)
        {
          data: null,
          className: "text-end",
          render: function (data, type, row) {
            if (!row.tiene_factura || !row.total_factura_asignada) return '<span class="badge bg-secondary">Pendiente</span>';
            var litros = parseFloat(row.fac_rec || 0);
            var total = parseFloat(row.total_factura_asignada || 0);
            if (litros <= 0) return '<span class="text-muted">-</span>';
            return "$" + (total / litros).toFixed(4);
          },
        },
        // col 15 — DIFERENCIA (Precio Cotizado SAT - Precio x Litro CG)
        {
          data: null,
          className: "text-end",
          render: function (data, type, row) {
            var litros = parseFloat(row.fac_rec || 0);
            var montoCG = parseFloat(row.monto_factura_controlgas || 0);
            var totalSAT = parseFloat(row.total_factura_asignada || 0);
            if (!row.tiene_factura || litros <= 0 || montoCG <= 0 || totalSAT <= 0) return '<span class="text-muted">-</span>';
            var precioCG = montoCG / litros;
            var precioSAT = totalSAT / litros;
            var diff = precioSAT - precioCG;
            var color = diff > 0 ? "text-danger" : diff < 0 ? "text-success" : "";
            return '<span class="' + color + '">' + (diff >= 0 ? "+" : "") + diff.toFixed(4) + "</span>";
          },
        },
        // col 16 — % IVA FACTURADO (de conceptos SAT)
        {
          data: null,
          className: "text-center",
          render: function (data, type, row) {
            if (!row.tiene_factura) return '<span class="text-muted">-</span>';
            // La tasa viene en ValorUnitario de conceptos o se puede inferir del total
            // Por ahora mostramos indicador de si tiene factura
            return '<span class="text-muted">-</span>';
          },
        },
        // col 17 — IEPS x LITRO (de conceptos SAT: ImporteImpuesto / Cantidad)
        {
          data: null,
          className: "text-end",
          render: function (data, type, row) {
            if (!row.tiene_factura) return '<span class="text-muted">-</span>';
            return '<span class="text-muted">-</span>';
          },
        },
        // col 18 — PROVEEDOR QUE REALIZA LA FACTURA FINAL
        {
          data: "emisor_factura_asignada",
          className: "text-start text-nowrap",
          render: function (data, type, row) {
            if (!data) return '<span class="text-muted">-</span>';
            // Resaltar si es Petrotal
            if (data.toUpperCase().includes("PETROTAL")) {
              return '<span class="badge bg-warning text-dark"><i class="fas fa-building"></i> ' + data + '</span>';
            }
            return data;
          },
        },
        // col 19 — NUMERO DE FACTURA PETROTAL
        {
          data: "folio_petrotal",
          className: "text-start text-nowrap",
          render: function (data) {
            return data || '<span class="text-muted">-</span>';
          },
        },
        // col 20 — MONTO FACTURA PETROTAL
        {
          data: "monto_petrotal",
          className: "text-end",
          render: function (data) {
            if (!data || parseFloat(data) === 0) return '<span class="text-muted">-</span>';
            return "$" + parseFloat(data).toLocaleString("es-MX", { minimumFractionDigits: 2 });
          },
        },
        // col 21 — UUID (truncado, click para copiar)
        {
          data: "uuid",
          className: "text-start",
          render: function (data) {
            if (!data) return '<span class="text-muted">-</span>';
            return '<span title="' + data + '" style="cursor:pointer;font-family:monospace" ' +
              'onclick="navigator.clipboard.writeText(\'' + data + '\');alertify.success(\'UUID copiado\')">' +
              data.substring(0, 8) + '…</span>';
          },
        },
        // col 22 — ESTADO
        {
          data: "tiene_factura",
          className: "text-center",
          render: function (data, type, row) {
            if (data) {
              if (row.folio_petrotal) {
                return '<span class="badge bg-info"><i class="fas fa-layer-group"></i> Con Petrotal</span>';
              }
              return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Asignada</span>';
            }
            if (row.nro_fac) {
              return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> Sin Factura SAT</span>';
            }
            return '<span class="badge bg-secondary"><i class="fas fa-minus-circle"></i> Sin Documento</span>';
          },
        },
        // col 23 — ACCIONES
        {
          data: null,
          orderable: false,
          className: "text-center",
          render: function (data, type, row) {
            if (!row.tiene_factura) {
              return `<button class="btn btn-sm btn-primary"
                          onclick='abrirModalAsignarFactura(${JSON.stringify(row).replace(/'/g, "&apos;")})'
                          title="Asignar factura a recepción">
                          <i class="fas fa-link"></i>
                      </button>`;
            }
            return `<button class="btn btn-sm btn-outline-secondary"
                        onclick='abrirModalAsignarFactura(${JSON.stringify(row).replace(/'/g, "&apos;")})'
                        title="Ver / editar asignación">
                        <i class="fas fa-eye"></i>
                    </button>`;
          },
        },
      ],
      createdRow: function (row, data, dataIndex) {
        // // Highlight de filas según estado
        // if (data.tiene_factura && data.folio_petrotal) {
        //   $(row).addClass("fila-con-petrotal");
        // } else if (!data.tiene_factura && data.nro_fac) {
        //   $(row).addClass("fila-sin-factura");
        // }
        // // Tooltip al hacer hover — muestra info SAT resumida
        // var tooltip = [];
        // if (data.emisor_factura_asignada) tooltip.push('Emisor SAT: ' + data.emisor_factura_asignada);
        // if (data.total_factura_asignada)  tooltip.push('Total SAT: $' + parseFloat(data.total_factura_asignada).toLocaleString('es-MX', {minimumFractionDigits:2}));
        // if (data.destino_factura)         tooltip.push('Destino: ' + data.destino_factura);
        // if (data.uuid)                    tooltip.push('UUID: ' + data.uuid.substring(0,8) + '…');
        // if (tooltip.length) {
        //   $(row).attr('title', tooltip.join(' | ')).attr('data-bs-toggle', 'tooltip');
        //   new bootstrap.Tooltip(row, { placement: 'top', trigger: 'hover', html: false });
        // }
      },
      initComplete: function () {
        $(".table-responsive").removeClass("loading");
        alertify.success("Tabla cargada exitosamente");
      },
      footerCallback: function (row, data, start, end, display) {
      },
    });
}


function abrirRelacionarFactura(facturaId) {
  window.open("/supply/relacionar_factura/" + facturaId, "_blank");
}


// Funciones auxiliares
function verDetalleFactura(factura) {
  alertify.alert(
    "Detalle de Factura",
    `<div class="row">
            <div class="col-6"><strong>Folio:</strong> ${factura.NumeroFacturaProveedorOriginal}</div>
            <div class="col-6"><strong>Fecha:</strong> ${factura.FechaRecepcion}</div>
            <div class="col-6"><strong>Proveedor:</strong> ${factura.ProveedorOriginalizado}</div>
            <div class="col-6"><strong>RFC:</strong> ${factura.RfcProveedorOriginal}</div>
            <div class="col-6"><strong>Producto:</strong> ${factura.ProductoNormalizado}</div>
            <div class="col-6"><strong>Litros:</strong> ${factura.LitrosDocumentoSoporte}</div>
            <div class="col-6"><strong>Total:</strong> $${parseFloat(factura.MontoFactura).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</div>
            <div class="col-6"><strong>Precio/L:</strong> $${parseFloat(factura.PrecioPorLitro).toFixed(4)}</div>
            <div class="col-12 mt-2"><strong>UUID:</strong><br><small class="font-monospace">${factura.UUID}</small></div>
        </div>`,
  );
}

function asignarFacturaAMovimiento(factura) {
  // Esta función abrirá un modal para buscar el movimiento correspondiente
  alertify.prompt(
    "Asignar a Movimiento",
    "Ingrese el número de transacción (nrotrn) al que desea asignar esta factura:",
    "",
    function (evt, nrotrn) {
      if (nrotrn) {
        // Aquí implementarás la lógica de asignación
        alertify.success(
          "Función en desarrollo: asignar factura " +
            factura.NumeroFacturaProveedorOriginal +
            " a transacción " +
            nrotrn,
        );
      }
    },
    function () {
      alertify.message("Operación cancelada");
    },
  );
}

function editarAsignacionFactura(factura) {
  alertify.message(
    "Función en desarrollo: editar asignación de factura " +
      factura.NumeroFacturaProveedorOriginal,
  );
}
let facturaActualPDF = null;
let pdfBlobUrl = null; // URL temporal del blob


/**
 * Descarga el PDF directamente
 */
function descargarPDFDirecto() {
  if (!facturaActualPDF) {
    alertify.error("No hay factura seleccionada");
    return;
  }

  // Crear un formulario temporal para hacer POST y descargar
  const form = document.createElement("form");
  form.method = "POST";
  form.action = "/payment/descargar_factura_pdf";
  form.target = "_blank"; // Abrir en nueva pestaña

  const input = document.createElement("input");
  input.type = "hidden";
  input.name = "id";
  input.value = facturaActualPDF.id;

  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);

  alertify.success("Descargando PDF...");
}

/**
 * Abre el PDF en una nueva ventana/pestaña
 */
function abrirPDFNuevaVentana() {
  if (!pdfBlobUrl) {
    alertify.error("No hay PDF cargado");
    return;
  }

  // Abrir el blob URL en nueva ventana
  window.open(pdfBlobUrl, "_blank");
}
/**
 * Imprime el PDF
 */
function imprimirPDF() {
  const iframe = document.getElementById("iframe_pdf");

  if (iframe && iframe.contentWindow) {
    try {
      iframe.contentWindow.print();
    } catch (e) {
      alertify.warning(
        "No se pudo imprimir directamente. Intente desde el botón de nueva pestaña.",
      );
      abrirPDFNuevaVentana();
    }
  } else {
    alertify.error("No hay PDF cargado para imprimir");
  }
}


function abrirModalAsignarFactura(factura) {
  // Si ya está asignada, mostrar la info del movimiento
  if (factura.EstadoAsignacion === "ASIGNADA" && factura.NumeroTransaccion) {
    // Cargar datos del movimiento desde la asignación existente
    $("#modal_nrotrn").text(factura.NumeroTransaccion);
    $("#modal_estacion").text(factura.NombreEstacion || "N/A");
    $("#modal_fecha").text(factura.FechaRecepcion);
    $("#modal_combustible").text(factura.ProductoNormalizado);
    $("#modal_litros").text(
      parseFloat(factura.LitrosDocumentoSoporte).toLocaleString("es-MX", {
        minimumFractionDigits: 2,
      }),
    );

    // Determinar tipo de operación
    if (factura.TipoOperacion === 2) {
      $("#tipoPetrotal").prop("checked", true);
      $("#tipoDirecto").prop("checked", false);
      mostrarSeccionPetrotal();
    } else {
      $("#tipoDirecto").prop("checked", true);
      $("#tipoPetrotal").prop("checked", false);
      ocultarSeccionPetrotal();
    }

    // Pre-cargar las facturas seleccionadas
    // ... (código para mostrar facturas ya asignadas)
  } else {
    // Nueva asignación - mostrar modal para buscar movimiento
    alertify
      .prompt(
        "Asignar a Movimiento",
        "Ingrese el número de transacción (nrotrn) y código de estación (codgas) separados por coma:",
        "",
        function (evt, value) {
          if (value) {
            const [nrotrn, codgas] = value.split(",").map((v) => v.trim());
            if (nrotrn && codgas) {
              // Buscar datos del movimiento
              buscarMovimientoParaAsignar(nrotrn, codgas, factura);
            } else {
              alertify.error("Formato incorrecto. Use: nrotrn,codgas");
            }
          }
        },
        function () {
          alertify.message("Operación cancelada");
        },
      )
      .set("labels", { ok: "Buscar", cancel: "Cancelar" });
  }
}

// FUNCIÓN AUXILIAR PARA BUSCAR MOVIMIENTO
function buscarMovimientoParaAsignar(nrotrn, codgas, factura) {
  $.ajax({
    url: "/supply/buscar_movimiento_por_nrotrn",
    method: "POST",
    data: { nrotrn: nrotrn, codgas: codgas },
    success: function (response) {
      if (response.success && response.data) {
        const movimiento = response.data;

        // Llenar modal con datos del movimiento
        $("#modal_nrotrn").text(movimiento.nrotrn);
        $("#modal_estacion").text(movimiento.nombre_estacion);
        $("#modal_fecha").text(movimiento.fecha);
        $("#modal_combustible").text(movimiento.producto);
        $("#modal_litros").text(
          parseFloat(movimiento.litros).toLocaleString("es-MX", {
            minimumFractionDigits: 2,
          }),
        );

        // Guardar movimiento actual y factura pre-seleccionada
        movimientoActual = movimiento;
        facturaProveedorSeleccionada = factura;

        // Actualizar diagrama
        actualizarDiagramaProveedor(factura);

        // Abrir modal
        $("#modalAsignarFactura").modal("show");
      } else {
        alertify.error("Movimiento no encontrado");
      }
    },
    error: function () {
      alertify.error("Error al buscar movimiento");
    },
  });
}

// Función para verificar si el navegador soporta PDFs en iframe
function navegadorSoportaPDF() {
  const userAgent = navigator.userAgent.toLowerCase();

  // Navegadores que típicamente soportan PDF en iframe
  if (
    userAgent.includes("chrome") ||
    userAgent.includes("firefox") ||
    (userAgent.includes("safari") && !userAgent.includes("android"))
  ) {
    return true;
  }

  return false;
}


// ==================== FUNCIONES PARA VISTA DE RECONCILIACIÓN ====================

function regresarACompras() {
  // Redirigir a la vista de compras/facturas recibidas
  window.location.href = "/payment/fuel_payments";
}

function abrirVistaReconciliacion() {
  // Guardar los filtros actuales en localStorage para pasarlos a la nueva vista
  var filtros = {
    fromDate: document.getElementById("from_compras").value,
    untilDate: document.getElementById("until_compras").value,
    codgas: document.getElementById("codgas_compras").value,
    proveedor: document.getElementById("proveedor_compras").value,
    company: document.getElementById("company_compras").value,
  };

  localStorage.setItem("reconciliation_filters", JSON.stringify(filtros));

  // Redirigir a la nueva vista
  window.location.href = "/supply/fuel_reconciliation";
}

async function loadReconciliationData() {
  var fromDate = document.getElementById("from_reconciliation").value;
  var untilDate = document.getElementById("until_reconciliation").value;
  var codgas = document.getElementById("codgas_reconciliation").value;
  var proveedor = document.getElementById("proveedor_reconciliation").value;
  var company = document.getElementById("company_reconciliation").value;

  if (!fromDate || !untilDate) {
    alertify.myAlert(
      `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar las fechas para continuar.</p>
            </div>`,
    );
    return;
  }

  // Cargar ambas tablas en paralelo
  await Promise.all([
    loadFacturasReconciliationTable(
      fromDate,
      untilDate,
      codgas,
      proveedor,
      company,
    ),
    loadRecepcionesReconciliationTable(
      fromDate,
      untilDate,
      codgas,
      proveedor,
      company,
    ),
  ]);
}

async function loadFacturasReconciliationTable(
  fromDate,
  untilDate,
  codgas,
  proveedor,
  company,
) {
  if ($.fn.DataTable.isDataTable("#facturas_reconciliation_table")) {
    $("#facturas_reconciliation_table").DataTable().destroy();
  }

  let facturas_reconciliation_table = $(
    "#facturas_reconciliation_table",
  ).DataTable({
    order: [[0, "desc"]],
    dom: '<"top"f>rt<"bottom"ip>',
    scrollY: "calc(100vh - 350px)",
    scrollCollapse: true,
    paging: false,
    pageLength: 100,
    ajax: {
      method: "POST",
      data: {
        fromDate: fromDate,
        untilDate: untilDate,
        codgas: codgas,
        proveedor: proveedor,
        company: company,
      },
      url: "/supply/compras_facturas_table",
      timeout: 600000,
      error: function (xhr, error, thrown) {
        $(".table-responsive").removeClass("loading");
        alertify.myAlert(
          `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudieron cargar las facturas.</p>
                        <small>${thrown}</small>
                    </div>`,
        );
      },
      beforeSend: function () {
        $(".table-responsive").addClass("loading");
      },
      dataSrc: function (json) {
        if (json.error) {
          alertify.error(json.message);
          return [];
        }
        // Actualizar contadores
        $("#contador_facturas_reconciliation").text(
          json.data.length + " facturas",
        );
        // Calcular total
        var totalMonto = json.data.reduce(
          (sum, item) => sum + parseFloat(item.MontoFactura || 0),
          0,
        );
        $("#total_monto_facturas_reconciliation").text(
          "$" +
            totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 }),
        );
        $("#footer_monto_facturas").text(
          "$" +
            totalMonto.toLocaleString("es-MX", { minimumFractionDigits: 2 }),
        );
        return json.data;
      },
    },
    columns: [
      {
        data: "FechaRecepcion",
        className: "text-center text-nowrap",
      },
      {
        data: "ProveedorNormalizado",
        className: "text-start text-nowrap",
      },

      {
        data: "NumeroFacturaProveedorOriginal",
        className: "text-start text-nowrap",
        render: function (data, type, row) {
          // Hacer el folio clickeable para abrir el PDF en modal
            if (row.RutaArchivo) {
            return `<a href="javascript:void(0);" 
                                onclick='ModalinvoicePdf(${row.factura_recibida_id}, ${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                class="text-primary fw-bold" 
                                title="Click para ver la factura PDF">
                                    <i class="fas fa-file-pdf text-danger"></i> ${data}
                                </a>`;
          }
          return data;
        },
      },
      {
        data: "MontoFactura",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2, "$"),
      },
    ],
    deferRender: true,
    initComplete: function () {
      $(".table-responsive").removeClass("loading");
    },
    createdRow: function (row, data, dataIndex) {
      // Agregar atributos de datos para facilitar la selección
      $(row).attr("data-factura-id", data.FacturaId);
      $(row).attr("data-uuid", data.UUID);
    },
  });

  // Agregar evento de clic para seleccionar factura
  $("#facturas_reconciliation_table tbody").on("click", "tr", function () {
    if ($(this).hasClass("selected-row")) {
      $(this).removeClass("selected-row");
      facturaSeleccionada = null;
    } else {
      $("#facturas_reconciliation_table tbody tr").removeClass("selected-row");
      $(this).addClass("selected-row");
      facturaSeleccionada = facturas_reconciliation_table.row(this).data();
    }
    actualizarBotonRelacionar();
  });
}

async function loadRecepcionesReconciliationTable(
  fromDate,
  untilDate,
  codgas,
  proveedor,
  company,
) {
  if ($.fn.DataTable.isDataTable("#recepciones_reconciliation_table")) {
    $("#recepciones_reconciliation_table").DataTable().clear().destroy();
    $("#recepciones_reconciliation_table thead .filter").remove();
    $("#recepciones_reconciliation_table tbody").empty();
  }

  // let movimientoActual = {};

  let recepciones_reconciliation_table = $(
    "#recepciones_reconciliation_table",
  ).DataTable({
    order: [[1, "asc"]],
    scrollY: "calc(100vh - 350px)",
    colReorder: false,
    fixedHeader: false,
    dom: '<"top"f>rt<"bottom"ip>',
    scrollX: true,
    scrollCollapse: true,
    paging: false,
    autoWidth: false,
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
          $("#table-info-reconciliation").html(
            `<i class="bi bi-info-circle"></i> ${json.data.length} registro(s)`,
          );
        }
        return json.data;
      },
    },
    columns: [
      { data: "fecha", className: "text-center text-nowrap" },
      { data: "estacion", className: "text-start text-nowrap" },
      { data: "numero_estacion", className: "text-center text-nowrap" },
      { data: "proveedor_original", className: "text-start text-nowrap" },
      { data: "combustible", className: "text-start text-nowrap" },
      {
        data: "num_fac_proveedor",
        className: "text-start text-nowrap",
        render: function (data) {
          return data || '<span class="text-muted">Sin asignar</span>';
        },
      },
      { data: "proveedor_final", className: "text-start text-nowrap" },
      {
        data: "fac_rec",
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
        render: $.fn.dataTable.render.number(",", ".", 4, "$"),
      },
      { data: "uuid", className: "text-start text-nowrap" },
      { data: "proveedor_controlgas", className: "text-start text-nowrap" },
      {
        data: "monto_factura_controlgas",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2, "$"),
      },
      {
        data: "cantidad_factura_controlgas",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2),
      },
      { data: "graprd", className: "text-start text-nowrap" },
      { data: "nrotrn", className: "text-center" },
    ],
    deferRender: true,
    initComplete: function () {
      $(".datatable-wrapper").removeClass("loading");
    },
    createdRow: function (row, data, dataIndex) {
      // Agregar atributos de datos para facilitar la selección
      $(row).attr("data-nrotrn", data.nrotrn);
      $(row).attr("data-codgas", data.codgas);
    },
  });

  // Agregar evento de clic para seleccionar recepción
  $("#recepciones_reconciliation_table tbody").on("click", "tr", function () {
    if ($(this).hasClass("selected-row")) {
      $(this).removeClass("selected-row");
      recepcionSeleccionada = null;
    } else {
      $("#recepciones_reconciliation_table tbody tr").removeClass(
        "selected-row",
      );
      $(this).addClass("selected-row");
      recepcionSeleccionada = recepciones_reconciliation_table.row(this).data();
    }
    actualizarBotonRelacionar();
  });
}

// Variables globales para almacenar las selecciones
let facturaSeleccionada = null;
let recepcionSeleccionada = null;

// Función para actualizar la visibilidad del botón de relacionar
function actualizarBotonRelacionar() {
  if (facturaSeleccionada && recepcionSeleccionada) {
    $("#btnRelacionar").fadeIn();
  } else {
    $("#btnRelacionar").fadeOut();
  }
}

// Función para abrir el modal de confirmación
function relacionarFacturaRecepcion() {
  if (!facturaSeleccionada || !recepcionSeleccionada) {
    alertify.error("Debe seleccionar una factura y una recepción");
    return;
  }

  // Mostrar información de la factura seleccionada
  $("#infoFacturaSeleccionada").html(`
        <strong>Fecha:</strong> ${facturaSeleccionada.FechaRecepcion}<br>
        <strong>Proveedor:</strong> ${facturaSeleccionada.ProveedorNormalizado}<br>
        <strong>Número de Factura:</strong> ${facturaSeleccionada.NumeroFacturaProveedorOriginal}<br>
        <strong>Monto:</strong> $${parseFloat(facturaSeleccionada.MontoFactura).toLocaleString("es-MX", { minimumFractionDigits: 2 })}<br>
        <strong>UUID:</strong> ${facturaSeleccionada.UUID}
    `);

  // Mostrar información de la recepción seleccionada
  $("#infoRecepcionSeleccionada").html(`
        <strong>Fecha:</strong> ${recepcionSeleccionada.fecha}<br>
        <strong>Estación:</strong> ${recepcionSeleccionada.estacion} (${recepcionSeleccionada.numero_estacion})<br>
        <strong>Nro. Transacción:</strong> ${recepcionSeleccionada.nrotrn}<br>
        <strong>Combustible:</strong> ${recepcionSeleccionada.combustible}<br>
        <strong>Litros:</strong> ${parseFloat(recepcionSeleccionada.fac_rec).toLocaleString("es-MX", { minimumFractionDigits: 2 })}
    `);

  // Limpiar observaciones y checkbox
  $("#observacionesRelacion").val("");
  $("#checkPetrotal").prop("checked", false);
  actualizarTipoOperacion();

  // Mostrar modal
  $("#modalConfirmRelacion").modal("show");
}

// Función para actualizar el diagrama según el tipo de operación
function actualizarTipoOperacion() {
  var conPetrotal = $("#checkPetrotal").is(":checked");

  if (conPetrotal) {
    $("#flujo-texto").html(
      'Proveedor → <span class="text-warning fw-bold">PETROTAL</span> → TotalGas (Con Intermediario)',
    );
    $("#diagrama-operacion")
      .removeClass("bg-light")
      .addClass("bg-warning bg-opacity-10");
  } else {
    $("#flujo-texto").html("Proveedor → TotalGas (Compra Directa)");
    $("#diagrama-operacion")
      .removeClass("bg-warning bg-opacity-10")
      .addClass("bg-light");
  }
}

// Función para confirmar la relación
function confirmarRelacion() {
  if (!facturaSeleccionada || !recepcionSeleccionada) {
    alertify.error("Debe seleccionar una factura y una recepción");
    return;
  }

  var observaciones = $("#observacionesRelacion").val();
  var conPetrotal = $("#checkPetrotal").is(":checked");

  // Preparar datos para enviar
  var datos = {
    nrotrn: recepcionSeleccionada.nrotrn,
    codgas: recepcionSeleccionada.codgas,
    factura_proveedor_id: facturaSeleccionada.FacturaId,
    uuid_proveedor: facturaSeleccionada.UUID,
    folio_proveedor: facturaSeleccionada.NumeroFacturaProveedorOriginal,
    monto_proveedor: facturaSeleccionada.MontoFactura,
    litros_proveedor: facturaSeleccionada.LitrosDocumentoSoporte,
    precio_proveedor: facturaSeleccionada.PrecioPorLitro,
    observaciones: observaciones,
    tipo_operacion: conPetrotal ? 2 : 1, // 1 = Compra directa, 2 = Con Petrotal
    petrotal: conPetrotal ? 1 : 0, // Campo BIT: 1 = lleva Petrotal, 0 = no lleva
  };

  // Enviar al servidor
  $.ajax({
    url: "/supply/relacionar_factura_movimiento",
    type: "POST",
    data: datos,
    beforeSend: function () {
      $(".modal-footer button").prop("disabled", true);
    },
    success: function (response) {
      if (response.success) {
        toastr.success("Factura relacionada exitosamente", "¡Éxito!");
        $("#modalConfirmRelacion").modal("hide");

        // Limpiar selecciones
        $("#facturas_reconciliation_table tbody tr").removeClass(
          "selected-row",
        );
        $("#recepciones_reconciliation_table tbody tr").removeClass(
          "selected-row",
        );
        facturaSeleccionada = null;
        recepcionSeleccionada = null;
        actualizarBotonRelacionar();

        // Recargar las tablas
        loadReconciliationData();
      } else {
        toastr.error(
          response.message || "Error al relacionar factura",
          "¡Error!",
        );
      }
    },
    error: function (xhr, status, error) {
      toastr.error("Error al comunicarse con el servidor", "¡Error!");
    },
    complete: function () {
      $(".modal-footer button").prop("disabled", false);
    },
  });
}
/**
 * Actualizar resumen del anticipo en tiempo real
 */

/**
 * Contador de caracteres del comentario
 */


/////comentario anticipo
// async function crearAnticipo() {
//   const proveedor_cod = $("#anticipo_proveedor").val();
//   const monto = parseFloat($("#anticipo_monto").val());
//   const comentario = $("#anticipo_comentario").val().trim();

//   // Validaciones
//   if (!proveedor_cod) {
//     alertify.error("Debe seleccionar un proveedor");
//     return;
//   }

//   if (!monto || monto <= 0) {
//     alertify.error("El monto debe ser mayor a cero");
//     return;
//   }

//   if (!comentario) {
//     alertify.error("Debe proporcionar una justificación");
//     return;
//   }

//   alertify
//     .confirm(
//       "Confirmar Anticipo",
//       `¿Crear anticipo de <strong>$${monto.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</strong>?<br><br>` +
//         `<small>${comentario}</small>`,
//       async function () {
//         try {
//           const response = await fetch("/payment/create_anticipo", {
//             method: "POST",
//             headers: { "Content-Type": "application/json" },
//             body: JSON.stringify({
//               provider_cod: proveedor_cod,
//               monto: monto,
//               comentario: comentario,
//             }),
//           });

//           const data = await response.json();
//           console.log('data', data);

//           if (data.success) {
//             alertify.success("Anticipo creado: ID #" + data.anticipo_id);

//             // Limpiar formulario
//             $("#anticipo_proveedor").val("").selectpicker("refresh");
//             $("#anticipo_monto").val("");
//             $("#anticipo_comentario").val("");

// Limpiar cuando se cierra el modal

// Limpiar cuando se cierra el modal masivo

let tablaFacturasAutorizadas;

/**
 * Generar layout de pago
 */
// function generarLayoutPago() {
//     const seleccionadas = [];

//     $('.invoice-checkbox:checked').each(function() {
//         const row = tablaFacturasAutorizadas.row($(this).closest('tr')).data();
//         seleccionadas.push({
//             id: row.id,
//             payment_request_id: row.payment_request_id,
//             folio: row.folio,
//             monto: row.authorized_amount,
//             banco: row.banco_asignado,
//             empresa: row.empresa_nombre,
//             proveedor: row.proveedor_nombre
//         });
//     });

//     if (seleccionadas.length === 0) {
//         alertify.warning('Seleccione al menos una factura');
//         return;
//     }

//     // Verificar que todas sean del mismo banco
//     const bancos = [...new Set(seleccionadas.map(f => f.banco))];
//     if (bancos.length > 1) {
//         alertify.confirm(
//             'Múltiples Bancos',
//             'Has seleccionado facturas de diferentes bancos: ' + bancos.join(', ') + '. ¿Deseas continuar generando layouts separados?',
//             function() {
//                 generarLayoutsPorBanco(seleccionadas);
//             },

let tablaDesgloseFacturas;

/**
 * Limpiar modal al cerrar
 */

// Update balance hint when a note is selected in the apply modal
document.addEventListener("DOMContentLoaded", function () {
  var applyNoteSelect = document.getElementById("applyNoteSelect");
  if (applyNoteSelect) {
    applyNoteSelect.addEventListener("change", function () {
      var selected = this.options[this.selectedIndex];
      var balance  = selected ? selected.getAttribute("data-balance") : null;
      var hint     = document.getElementById("applyNoteBalanceHint");
      var amtInput = document.getElementById("applyNoteAmount");
      if (balance && parseFloat(balance) > 0) {
        hint.textContent = "Saldo disponible: $" + parseFloat(balance).toFixed(2);
        amtInput.max = balance;
      } else {
        hint.textContent = "";
        amtInput.removeAttribute("max");
      }
    });
  }

  var applyNoteForm = document.getElementById("applyNoteForm");
  if (applyNoteForm) {
    applyNoteForm.addEventListener("submit", function (e) {
      e.preventDefault();

      var sel     = document.getElementById("applyNoteSelect");
      var balance = sel.options[sel.selectedIndex]
        ? parseFloat(sel.options[sel.selectedIndex].getAttribute("data-balance") || 0)
        : 0;
      var amount  = parseFloat(document.getElementById("applyNoteAmount").value || 0);

      if (amount <= 0) {
        Swal.fire({ icon: "warning", title: "Monto inválido", text: "Ingrese un monto mayor a 0." });
        return;
      }
      if (amount > balance) {
        Swal.fire({ icon: "warning", title: "Monto excede saldo", text: "El monto no puede superar el saldo disponible de la nota ($" + balance.toFixed(2) + ")." });
        return;
      }

      var formData = new FormData(applyNoteForm);

      Swal.fire({
        title: "Procesando...",
        text: "Aplicando nota al pago",
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
      });

      fetch("/payment/applyCreditNote", {
        method: "POST",
        body: formData,
        credentials: "include"
      })
        .then((r) => r.json())
        .then((data) => {
          Swal.close();
          if (data.success) {
            Swal.fire({ icon: "success", title: "Nota aplicada", text: data.message, timer: 2000 })
              .then(() => { location.reload(); });
          } else {
            Swal.fire({ icon: "error", title: "Error", text: data.message });
          }
        })
        .catch(() => {
          Swal.close();
          Swal.fire({ icon: "error", title: "Error", text: "Error al aplicar la nota" });
        });
    });
  }
});

// ── Delegación: botón ver docs en payment_detail ──────────────────────────────
document.addEventListener("click", function (e) {
  var btn = e.target.closest(".view-note-docs-btn");
  if (!btn) return;
  openNoteDocsModalPD(btn.dataset.noteId);
});

// ── Delegación: selector de doc en noteDocsModalPD ───────────────────────────
document.addEventListener("click", function (e) {
  var btn = e.target.closest(".note-doc-item-pd");
  if (!btn) return;
  openNoteDocViewerPD(btn.dataset.docId);
});

// ═══════════════════════════════════════════════════════════
//  ANÁLISIS DE COMPRAS
// ═══════════════════════════════════════════════════════════

// Patrones esperados por proveedor_codigo
// Derivados del análisis de 4,113 facturas históricas
var _patronesProveedor = {
  96: { nombre: 'AEMSA',      regex: /^F-\d+/i },
  83: { nombre: 'ENEREY',     regex: /^E-\d+/i },
  41: { nombre: 'GAZPRO',     regex: /^FE-\d+/i },
  72: { nombre: 'MGC',        regex: /^(CO-\d+|\d+-CO-\d+)/i },
  76: { nombre: 'LOBO',       regex: /^-\d+/ },
  55: { nombre: 'PETROTAL',   regex: /^(PET-|IPET-)/i },
  71: { nombre: 'PREMIERGAS', regex: /^(FE-|FF-)\d+/i },
  56: { nombre: 'TESORO',     regex: /^02-88002\d+/i },
};

function _validarFacturaProveedor(factura, proveedorCodigo) {
  if (!factura || !proveedorCodigo) return true; // sin datos, no alertar
  var patron = _patronesProveedor[parseInt(proveedorCodigo)];
  if (!patron) return true; // proveedor desconocido, no alertar
  return patron.regex.test(factura.trim());
}

var _analisisFiltrandoSinUuid = false;
var _analisisFiltrandoMismatch = false;
var _analisisFiltrandoSinCorpo = false;
var _analisisFiltrandoDifiereCorpo = false;

// Filtro personalizado por datos crudos (no por HTML renderizado)
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData) {
  if (settings.nTable.id !== 'analisis_compras_table') return true;
  if (_analisisFiltrandoSinUuid && !rowData.satuid) return false;
  if (_analisisFiltrandoMismatch && _validarFacturaProveedor(rowData.Factura, rowData.proveedor_codigo)) return false;
  if (_analisisFiltrandoSinCorpo && rowData.nro_corp) return false;
  if (_analisisFiltrandoDifiereCorpo && !_difiereCorpo(rowData)) return false;
  return true;
});

function toggleFiltroSinUuid() {
  _analisisFiltrandoSinUuid = !_analisisFiltrandoSinUuid;
  if (_analisisFiltrandoSinUuid) {
    $("#btn_filtro_uuid").html('<i class="fas fa-filter"></i> Mostrar sin UUID').removeClass("btn-outline-warning").addClass("btn-warning");
  } else {
    $("#btn_filtro_uuid").html('<i class="fas fa-filter"></i> Ocultar sin UUID').removeClass("btn-warning").addClass("btn-outline-warning");
  }
  $("#analisis_compras_table").DataTable().draw();
}

function toggleFiltroMismatch() {
  _analisisFiltrandoMismatch = !_analisisFiltrandoMismatch;
  var kpiCard = $("#kpi_analisis_mismatch").closest(".kpi-card");
  if (_analisisFiltrandoMismatch) {
    kpiCard.css("background-color", "#f8d7da");
    $("#btn_filtro_mismatch").html('<i class="fas fa-exclamation-triangle"></i> Mostrar todos').removeClass("btn-outline-danger").addClass("btn-danger");
  } else {
    kpiCard.css("background-color", "");
    $("#btn_filtro_mismatch").html('<i class="fas fa-exclamation-triangle"></i> Solo errores factura').removeClass("btn-danger").addClass("btn-outline-danger");
  }
  $("#analisis_compras_table").DataTable().draw();
}

// Devuelve true si el documento existe en corpo pero difiere en proveedor, factura o UUID
function _difiereCorpo(row) {
  if (!row.nro_corp) return false;
  var provDif  = (row.proveedor_corpo || '').trim().toLowerCase() !== (row.proveedor    || '').trim().toLowerCase();
  var facDif   = (row.Factura_corpo   || '').trim().toLowerCase() !== (row.Factura      || '').trim().toLowerCase();
  var uuidDif  = (row.uuid_corp       || '').trim().toLowerCase() !== (row.satuid       || '').trim().toLowerCase();
  return provDif || facDif || uuidDif;
}

function toggleFiltroSinCorpo() {
  _analisisFiltrandoSinCorpo = !_analisisFiltrandoSinCorpo;
  var kpiCard = $("#kpi_analisis_sin_corpo").closest(".kpi-card");
  if (_analisisFiltrandoSinCorpo) {
    kpiCard.css("background-color", "#fff3cd");
    $("#btn_filtro_sin_corpo").html('<i class="fas fa-building"></i> Mostrar todos').removeClass("btn-outline-warning").addClass("btn-warning");
  } else {
    kpiCard.css("background-color", "");
    $("#btn_filtro_sin_corpo").html('<i class="fas fa-building"></i> Solo sin corpo').removeClass("btn-warning").addClass("btn-outline-warning");
  }
  $("#analisis_compras_table").DataTable().draw();
}

function toggleFiltroDifiereCorpo() {
  _analisisFiltrandoDifiereCorpo = !_analisisFiltrandoDifiereCorpo;
  var kpiCard = $("#kpi_analisis_difiere_corpo").closest(".kpi-card");
  if (_analisisFiltrandoDifiereCorpo) {
    kpiCard.css("background-color", "#e9d8fd");
    $("#btn_filtro_difiere_corpo").html('<i class="fas fa-not-equal"></i> Mostrar todos').removeClass("btn-outline-secondary").addClass("btn-secondary");
  } else {
    kpiCard.css("background-color", "");
    $("#btn_filtro_difiere_corpo").html('<i class="fas fa-not-equal"></i> Solo difiere corpo').removeClass("btn-secondary").addClass("btn-outline-secondary");
  }
  $("#analisis_compras_table").DataTable().draw();
}

async function analisis_compras_table() {
  if ($.fn.DataTable.isDataTable("#analisis_compras_table")) {
    $("#analisis_compras_table").DataTable().destroy();
    $("#analisis_compras_table thead .filter").remove();
  }
  _analisisFiltrandoSinUuid = false;
  _analisisFiltrandoMismatch = false;
  _analisisFiltrandoSinCorpo = false;
  _analisisFiltrandoDifiereCorpo = false;
  $("#btn_filtro_uuid").html('<i class="fas fa-filter"></i> Ocultar sin UUID').removeClass("btn-warning").addClass("btn-outline-warning").hide();
  $("#btn_filtro_mismatch").html('<i class="fas fa-exclamation-triangle"></i> Solo errores factura').removeClass("btn-danger").addClass("btn-outline-danger").hide();
  $("#btn_filtro_sin_corpo").html('<i class="fas fa-building"></i> Solo sin corpo').removeClass("btn-warning").addClass("btn-outline-warning").hide();
  $("#btn_filtro_difiere_corpo").html('<i class="fas fa-not-equal"></i> Solo difiere corpo').removeClass("btn-secondary").addClass("btn-outline-secondary").hide();
  $("#kpi_analisis_mismatch").closest(".kpi-card").css("background-color", "");
  $("#kpi_analisis_sin_corpo").closest(".kpi-card").css("background-color", "");
  $("#kpi_analisis_difiere_corpo").closest(".kpi-card").css("background-color", "");

  var fromDate  = document.getElementById("from_analisis").value;
  var untilDate = document.getElementById("until_analisis").value;
  var codgas    = document.getElementById("codgas_analisis").value;
  var proveedor = document.getElementById("proveedor_analisis").value;
  var company   = 0;

  if (!fromDate || !untilDate) {
    alertify.myAlert(
      `<div class="container text-center text-danger">
          <h4 class="mt-2 text-danger">¡Error!</h4>
       </div>
       <div class="text-dark">
          <p class="text-center">Debe seleccionar las fechas para continuar.</p>
       </div>`
    );
    return;
  }

  // Fila de filtros por columna
  $("#analisis_compras_table thead").prepend(
    $("#analisis_compras_table thead tr").clone().addClass("filter")
  );
  $("#analisis_compras_table thead tr.filter th").each(function (index) {
    var col = $("#analisis_compras_table thead th").length / 2;
    if (index < col) {
      var title = $(this).text();
      $(this).html(
        '<input type="text" class="form-control form-control-sm" placeholder="' + title + '" />'
      );
    }
  });
  $("#analisis_compras_table thead tr.filter th input").on("keyup change", function () {
    var index = $(this).parent().index();
    $("#analisis_compras_table").DataTable().column(index).search(this.value).draw();
  });

  $("#analisis_compras_table").DataTable({
    order: [[2, "asc"]],
    dom: '<"top"Bf>rt<"bottom"lip>',
     pageLength: 100,
    // scrollY: "calc(100vh - 380px)",
    // scrollCollapse: true,
    paging: true,

    buttons: [
      {
        extend: "excel",
        className: "btn btn-success",
        text: '<i class="fas fa-file-excel"></i> Excel',
        title: "Analisis_Compras_" + fromDate + "_" + untilDate,
        exportOptions: { columns: ":visible" }
      },
      {
        extend: "print",
        className: "btn btn-secondary",
        text: '<i class="fas fa-print"></i> Imprimir',
        exportOptions: { columns: ":visible" }
      },
      {
        extend: "colvis",
        className: "btn btn-info",
        text: '<i class="fas fa-columns"></i> Columnas'
      }
    ],
    ajax: {
      method: "POST",
      url: "/supply/purchase_analysis_table",
      timeout: 600000,
      data: {
        fromDate: fromDate,
        untilDate: untilDate,
        codgas: codgas,
        proveedor: proveedor,
        company: 0
      },
      beforeSend: function () {
        $(".table-responsive").addClass("loading");
      },
      error: function (xhr, error, thrown) {
        $(".table-responsive").removeClass("loading");
        alertify.myAlert(
          `<div class="container text-center text-danger">
              <h4 class="mt-2 text-danger">¡Error!</h4>
           </div>
           <div class="text-dark">
              <p class="text-center">No se pudo cargar el análisis de compras.</p>
              <small>${thrown}</small>
           </div>`
        );
      },
      dataSrc: function (json) {
        if (json.error) {
          alertify.error(json.message);
          return [];
        }
        $("#kpi_analisis_strip").show();
        $("#btn_filtro_uuid").show();
        $("#btn_filtro_mismatch").show();
        $("#btn_filtro_sin_corpo").show();
        $("#btn_filtro_difiere_corpo").show();
        return json.data;
      }
    },
    columns: [
      // 0 — Estación
      { data: "gasolinera", className: "text-start text-nowrap" },
      // 1 — Número
      { data: "nro", className: "text-start text-nowrap" },
      // 2 — Fecha
      { data: "fecha", className: "text-start text-nowrap" },
      // 3 — Vto.
      {
        data: "fechaVto",
        className: "text-start text-nowrap",
        render: function (data) {
          if (!data) return '<span class="text-muted">-</span>';
          var hoy = new Date().toISOString().slice(0,10);
          if (data < hoy) return '<span class="text-danger fw-bold">' + data + '</span>';
          return data;
        }
      },
      // 4 — Proveedor
      { data: "proveedor", className: "text-start text-nowrap" },
      // 5 — Factura
      {
        data: "Factura",
        className: "text-start text-nowrap",
        render: function (data, type, row) {
          if (!data) return '<span class="text-muted">-</span>';
          if (!_validarFacturaProveedor(data, row.proveedor_codigo)) {
            var esperado = _patronesProveedor[parseInt(row.proveedor_codigo)];
            var hint = esperado ? 'Patrón esperado para ' + esperado.nombre + ': ' + esperado.regex.toString() : '';
            return '<span class="text-danger fw-bold" title="⚠ Factura no coincide con el patrón del proveedor. ' + hint + '">' +
                   '<i class="fas fa-exclamation-triangle"></i> ' + data + '</span>';
          }
          return data;
        }
      },
      // 6 — Remisión
      {
        data: "Remision",
        className: "text-start text-nowrap",
        render: function (data) {
          return data || '<span class="text-muted">-</span>';
        }
      },
      // 7 — Producto
      { data: "producto", className: "text-start" },
      // 8 — Vol. Recibido
      {
        data: "volrec",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2)
      },
      // 9 — Cantidad
      {
        data: "can",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2)
      },
      // 10 — Monto
      {
        data: "mto",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2, "$")
      },
      // 11 — I.V.A.
      {
        data: "iva_total",
        className: "text-end",
        render: $.fn.dataTable.render.number(",", ".", 2, "$")
      },
      // 12 — Total
      {
        data: "total_fac",
        className: "text-end fw-bold",
        render: $.fn.dataTable.render.number(",", ".", 2, "$")
      },
      // 13 — UUID
      {
        data: "satuid",
        className: "text-start",
        render: function (data) {
          if (!data) return '<span class="text-muted">-</span>';
          return '<span title="' + data + '" style="cursor:pointer;font-family:monospace" ' +
            'onclick="navigator.clipboard.writeText(\'' + data + '\');alertify.success(\'UUID copiado\')">' +
            data.substring(0, 8) + '…</span>';
        }
      },
      // 14 — R.F.C.
      {
        data: "rfc",
        className: "text-start text-nowrap",
        render: function (data) {
          return data || '<span class="text-muted badge bg-secondary">debug</span>';
        }
      },
      // 15 — Factura SAT (PDF)
      {
        data: "factura_recibida_id",
        className: "text-center",
        orderable: false,
        render: function (data, type, row) {
          if (!data) return '<span class="text-muted">-</span>';
          if (row.RutaArchivo) {
            return `<a href="javascript:void(0);"
                        onclick='ModalinvoicePdf(${data}, ${JSON.stringify(row).replace(/'/g, "&apos;")})'
                        class="text-primary fw-bold"
                        title="${row.EmisorNombre || ''}">
                        <i class="fas fa-file-pdf text-danger"></i> Ver
                    </a>`;
          }
          return '<span class="badge bg-secondary" title="Sin archivo PDF">Sin PDF</span>';
        }
      },
      // 16 — Nro. Corpo
      {
        data: "nro_corp",
        className: "text-center text-nowrap",
        render: function (data) {
          if (!data) return '<span class="badge bg-danger" title="No encontrado en SG12 corpo">Sin corpo</span>';
          return '<span class="badge bg-success">' + data + '</span>';
        }
      },
      // 17 — Factura Corpo
      {
        data: "Factura_corpo",
        className: "text-start text-nowrap",
        render: function (data, type, row) {
          if (!row.nro_corp) return '<span class="text-muted">-</span>';
          if (!data) return '<span class="text-muted">-</span>';
          var coincide = (data || '').trim().toLowerCase() === (row.Factura || '').trim().toLowerCase();
          if (!coincide) {
            return '<span class="text-danger fw-bold" title="Difiere de estación: ' + (row.Factura || '') + '">'
                   + '<i class="fas fa-exclamation-triangle"></i> ' + data + '</span>';
          }
          return '<span class="text-success"><i class="fas fa-check"></i> ' + data + '</span>';
        }
      },
      // 18 — Proveedor Corpo
      {
        data: "proveedor_corpo",
        className: "text-start text-nowrap",
        render: function (data, type, row) {
          if (!row.nro_corp) return '<span class="text-muted">-</span>';
          if (!data) return '<span class="text-muted badge bg-secondary">Sin prov.</span>';
          var coincide = (data || '').trim().toLowerCase() === (row.proveedor || '').trim().toLowerCase();
          if (!coincide) {
            return '<span class="text-danger fw-bold" title="Estación: ' + (row.proveedor || '') + '">'
                   + '<i class="fas fa-exclamation-triangle"></i> ' + data + '</span>';
          }
          return '<span class="text-success"><i class="fas fa-check"></i> ' + data + '</span>';
        }
      },
      // 19 — UUID Corpo
      {
        data: "uuid_corp",
        className: "text-start",
        render: function (data, type, row) {
          if (!row.nro_corp) return '<span class="text-muted">-</span>';
          if (!data) return '<span class="badge bg-secondary">Sin UUID</span>';
          var uEst   = (row.satuid || '').trim().toLowerCase();
          var uCorpo = data.trim().toLowerCase();
          if (uEst && uCorpo !== uEst) {
            return '<span class="text-danger fw-bold" title="UUID estación: ' + (row.satuid || '') + ' | UUID corpo: ' + data + '">'
                   + '<i class="fas fa-exclamation-triangle"></i> Difiere</span>';
          }
          return '<span class="text-success" title="' + data + '"><i class="fas fa-check"></i> '
                 + data.substring(0, 8) + '…</span>';
        }
      }
    ],
    createdRow: function (row, data) {
      if (data.Factura && !_validarFacturaProveedor(data.Factura, data.proveedor_codigo)) {
        $(row).addClass("table-danger");
      } else if (!data.nro_corp) {
        $(row).addClass("table-warning");
      } else if (_difiereCorpo(data)) {
        $(row).css("background-color", "#ede9fe");
      }
    },
    initComplete: function () {
      $(".table-responsive").removeClass("loading");
      alertify.success("Análisis de compras cargado");
    },
    drawCallback: function () {
      var dt = $("#analisis_compras_table").DataTable();
      var filas = dt.rows({ search: 'applied' }).data();
      var total    = filas.length;
      var volrec = 0, cantidad = 0, monto = 0, iva = 0, totalFac = 0, mismatch = 0, sinCorpo = 0, difiereCorpo = 0;
      for (var i = 0; i < total; i++) {
        var r = filas[i];
        volrec   += parseFloat(r.volrec    || 0);
        cantidad += parseFloat(r.can       || 0);
        monto    += parseFloat(r.mto       || 0);
        iva      += parseFloat(r.iva_total || 0);
        totalFac += parseFloat(r.total_fac || 0);
        if (r.Factura && !_validarFacturaProveedor(r.Factura, r.proveedor_codigo)) mismatch++;
        if (!r.nro_corp) sinCorpo++;
        if (_difiereCorpo(r)) difiereCorpo++;
      }
      var fmt = function(n) { return n.toLocaleString("es-MX", { minimumFractionDigits: 2 }); };
      $("#kpi_analisis_total").text(total);
      $("#kpi_analisis_cantidad").text(fmt(cantidad));
      $("#kpi_analisis_monto").text("$" + fmt(monto));
      $("#kpi_analisis_iva").text("$" + fmt(iva));
      $("#kpi_analisis_total_fac").text("$" + fmt(totalFac));
      $("#kpi_analisis_mismatch").text(mismatch);
      $("#kpi_analisis_sin_corpo").text(sinCorpo);
      $("#kpi_analisis_difiere_corpo").text(difiereCorpo);
      $("#contador_analisis").text(total + " facturas");
      $("#total_monto_analisis").text("$" + fmt(totalFac));
      $("#tfoot_volrec").text(fmt(volrec));
      $("#tfoot_cantidad").text(fmt(cantidad));
      $("#tfoot_monto").text("$" + fmt(monto));
      $("#tfoot_iva").text("$" + fmt(iva));
      $("#tfoot_total").text("$" + fmt(totalFac));
    }
  });
}

// ============================================================
// ARCHIVOS DE CONTABILIDAD (PDP-38 / PDP-39)
// ============================================================


