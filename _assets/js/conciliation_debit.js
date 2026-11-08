// Ingresos > Conciliación débito (prepago). Requiere conciliation_common.js (window.Conc).
// Diseño: docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md
$(function () {
  // Estatus propios de la conciliación débito (los comunes viven en Conc.ESTATUS_CLASE).
  $.extend(Conc.ESTATUS_CLASE, {
    'Facturado': 'cv-ok', 'Redondeo': 'cv-ok', 'Se factura al mes siguiente': 'cv-info', 'Factura cancelada': 'cv-bad',
    'Facturado con base en cortes': 'cv-info', 'Consumo sin corte': 'cv-bad', 'Consumo sin factura': 'cv-bad',
    'Facturado de menos': 'cv-warn', 'Facturado de más': 'cv-warn',
    'Mes anterior': 'cv-info', 'Mes siguiente': 'cv-info', 'Sin periodo': 'cv-muted',
  });

  // Ayuda "¿Qué estoy viendo?": un modal compartido, el texto sale de AYUDA. Las llaves de los tabs 7-12 y del Resumen las agregan las tareas siguientes.
  var AYUDA = {
    filtros: ['Filtros de la consulta', '<p>Todo el módulo trabaja con <b>clientes de débito (prepago)</b> del mes y la razón social elegidos.</p><ul>' +
      '<li><b>Mes:</b> los despachos y cortes se toman por su fecha; las facturas Serie E por la fecha de la factura (más las emitidas el mes siguiente que son de consumos de este mes).</li>' +
      '<li><b>Razón social:</b> la de la estación en los despachos y cortes, y la de la propia factura en la Serie E.</li>' +
      '<li>Mientras corren las consultas aparece una insignia "n/N"; haz clic para ver cuáles ya terminaron y abrir su tab.</li></ul>'],
    venta: ['Desglose venta', '<p>Una fila por cliente de débito. Arriba, el resumen por clasificación; abajo, el detalle por cliente. Es la hoja <b>Desglose venta</b> del Excel.</p>' +
      '<b>De dónde viene cada dato</b><ul>' +
      '<li><b>Clasificación:</b> Consumo propio y Bonificación vienen del catálogo de consumo propio (Clientes &gt; Consumos Débito); todo lo demás es Venta.</li>' +
      '<li><b>Despachos, Litros, Monto despachos y Monto cortes:</b> el mismo reporte de Consumos por cliente (tab 3).</li>' +
      '<li><b>Facturado del mes:</b> facturas Serie E (facturación de consumos) vigentes del cliente cuyo periodo es el mes elegido o no trae periodo en su nota.</li>' +
      '<li><b>Facturado mes anterior:</b> facturas emitidas en este mes pero de consumos del mes pasado ("Consumos Agosto…"). <b>Mes siguiente:</b> facturas emitidas el mes siguiente por consumos de este mes (típico del diésel).</li>' +
      '<li><b>Factura cancelada:</b> Serie E cancelada del cliente; no suma a lo facturado.</li></ul>' +
      '<b>Cómo interpretarlo</b><ul>' +
      '<li><b>Diferencia</b> = Monto despachos − Facturado del mes. La factura de consumos se emite por cliente con base en los cortes, por eso no se compara despacho por despacho.</li>' +
      '<li><b>Estatus</b> (se evalúa en este orden): No se factura (consumo propio / bonificación), Facturado, Redondeo (menos de $1), Se factura al mes siguiente, Factura cancelada, Facturado con base en cortes, Consumo sin corte, Consumo sin factura, Facturado de menos / de más.</li>' +
      '<li><b>Pie de la tabla:</b> suma solo las filas que ves (respeta el filtro y los chips por estatus).</li></ul>'],
    app: ['Consumos por cliente', '<p>El mismo reporte de Clientes &gt; Consumos Débito &gt; Resumen por cliente (hoja <b>Consumos</b> del Excel): despachos, litros, monto y cortes del mes por cliente, incluidos los consumos propios (columna Consumo propio).</p>' +
      '<ul><li><b>Diferencia</b> = Monto − Cortes: lo despachado contra lo que entró en los cortes de caja con el valor de débito.</li></ul>'],
    propio: ['Consumos propios', '<p>Despachos de débito de los clientes del catálogo de consumo propio (marketing, cortesías y donativos, bonificaciones, arqueos…). No se facturan, por eso el Resumen los resta de la venta. Aquí cae, por ejemplo, el consumo de Dollar to go.</p>'],
    facturas: ['Facturas de consumos (Serie E)', '<p>Equivale a las hojas <b>facturacion consumos</b> y <b>facturacion consumos oct</b> del Excel: una fila por factura Serie E (folios 1300000000 en adelante) con fecha del mes, más las emitidas el mes siguiente que son de consumos de este mes.</p>' +
      '<b>Periodo</b> (sale de la nota de la factura, "Consumos Agosto 2026"):<ul>' +
      '<li><b>Del mes:</b> consumos del mes elegido facturados en el mismo mes.</li>' +
      '<li><b>Mes anterior:</b> consumos del mes pasado facturados en este mes.</li>' +
      '<li><b>Mes siguiente:</b> consumos de este mes facturados el mes siguiente.</li>' +
      '<li><b>Sin periodo:</b> la nota no dice el mes; se cuenta como del mes.</li>' +
      '<li><b>Cancelada:</b> factura cancelada; no suma a lo facturado.</li></ul>' +
      '<p>El card de arriba (colapsable) suma las facturas por periodo.</p>'],
    pendientes: ['Despachos pendientes de facturar', '<p>Equivale a la hoja <b>despachos pendientes de fact</b>: despachos del mes de clientes de débito que todavía no tienen factura (sin folio de factura) y con monto distinto de cero, con su cliente, estación y producto.</p>' +
      '<ul><li>Es el único lugar donde se mira el enlace despacho-factura; en Desglose venta la comparación es por cliente.</li>' +
      '<li>La Clasificación indica si el cliente es de Venta (debería facturarse) o de consumo propio / bonificación (no se factura).</li></ul>'],
  };
  Conc.bindAyuda(AYUDA);

  // Utilidades comunes: alias locales.
  var fmtMonto = Conc.fmtMonto, fmtLitros = Conc.fmtLitros, esc = Conc.esc, suma = Conc.suma;
  var monto = Conc.monto, montoDif = Conc.montoDif, montoSigno = Conc.montoSigno, litros = Conc.litros;
  var badge = Conc.badge, fecha = Conc.fecha, texto = Conc.texto;
  var initTabla = Conc.initTabla, pintarChips = Conc.pintarChips;

  function r2(n) { return Math.round(n * 100) / 100; }
  function tdm(n) { return '<td class="cv-monto">' + fmtMonto(n) + '</td>'; }
  function tdn(n) { return '<td class="cv-monto">' + Number(n).toLocaleString('en-US') + '</td>'; }
  function sumaCol(api, i) {
    return api.column(i, { search: 'applied' }).data().reduce(function (s, v) { return s + (Number(v) || 0); }, 0);
  }
  // Pie de tabla: [[columna, tipo]], tipo 0 = conteo, 1 = litros, 2 = importe
  function pieSumas(api, cols) {
    cols.forEach(function (c) {
      var t = sumaCol(api, c[0]);
      $(api.column(c[0]).footer()).text(c[1] === 1 ? fmtLitros(t) : (c[1] === 2 ? fmtMonto(t) : t.toLocaleString('en-US')));
    });
  }

  // Resumen por clasificación (Desglose venta): Venta / Consumo propio / Bonificación + total.
  function pintarResumenClase(filas) {
    var orden = ['Venta', 'Consumo propio', 'Bonificación'];
    var g = {};
    orden.forEach(function (k) { g[k] = { n: 0, litros: 0, monto: 0, cortes: 0, facturado: 0 }; });
    filas.forEach(function (r) {
      var x = g[r.clase] || (g[r.clase] = { n: 0, litros: 0, monto: 0, cortes: 0, facturado: 0 });
      x.n++; x.litros += Number(r.litros) || 0; x.monto += Number(r.monto) || 0;
      x.cortes += Number(r.cortes) || 0; x.facturado += Number(r.facturado_mes) || 0;
    });
    var tot = { n: 0, litros: 0, monto: 0, cortes: 0, facturado: 0, dif: 0 };
    var html = '';
    Object.keys(g).forEach(function (k) {
      var x = g[k];
      if (!x.n) return;
      // Solo la Venta se factura: en consumo propio y bonificación no hay diferencia que cuadrar.
      var dif = k === 'Venta' ? r2(x.monto - x.facturado) : null;
      html += '<tr><td>' + badge(k, 'display') + '</td>' + tdn(x.n) + '<td class="cv-monto">' + fmtLitros(x.litros) + '</td>' +
        tdm(x.monto) + tdm(x.cortes) + tdm(x.facturado) + '<td class="cv-monto">' + (dif === null ? '' : montoDif(dif, 'display')) + '</td></tr>';
      tot.n += x.n; tot.litros += x.litros; tot.monto += x.monto; tot.cortes += x.cortes; tot.facturado += x.facturado; tot.dif += dif || 0;
    });
    html += '<tr class="cv-total"><td>Total</td>' + tdn(tot.n) + '<td class="cv-monto">' + fmtLitros(tot.litros) + '</td>' +
      tdm(tot.monto) + tdm(tot.cortes) + tdm(tot.facturado) + '<td class="cv-monto">' + montoDif(r2(tot.dif), 'display') + '</td></tr>';
    $('#resClase tbody').html(html);
  }

  // Facturas Serie E agrupadas por periodo.
  function pintarResumenFacturas(filas) {
    var g = {}, orden = [], t = { n: 0, total: 0 };
    filas.forEach(function (f) {
      var k = f.periodo || '(vacío)';
      if (!g[k]) { g[k] = { n: 0, total: 0 }; orden.push(k); }
      g[k].n++; g[k].total += Number(f.total) || 0;
      t.n++; t.total += Number(f.total) || 0;
    });
    var html = orden.sort().map(function (k) {
      return '<tr><td>' + badge(k, 'display') + '</td>' + tdn(g[k].n) + tdm(g[k].total) + '</tr>';
    }).join('');
    if (filas.length) html += '<tr class="cv-total"><td>Total</td>' + tdn(t.n) + tdm(t.total) + '</tr>';
    $('#resFacturas tbody').html(html);
  }

  function cargarTabla(api, filas, cnt) {
    api.settings()[0].oLanguage.sEmptyTable = 'Sin registros para este mes';
    api.clear().rows.add(filas).draw();
    $(cnt).text(filas.length.toLocaleString('en-US'));
  }

  // ---------------------------------------------------------------- tablas
  var tablaVenta = initTabla('#tablaVenta', [
    { data: 'clase', render: badge },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'despachos', className: 'cv-monto' },
    { data: 'litros', className: 'cv-monto', render: litros },
    { data: 'monto', className: 'cv-monto', render: monto },
    { data: 'cortes', className: 'cv-monto', render: monto },
    { data: 'dif_cortes', className: 'cv-monto', render: montoSigno },
    { data: 'facturado_mes', className: 'cv-monto', render: monto },
    { data: 'facturado_mes_anterior', className: 'cv-monto', render: monto },
    { data: 'facturado_siguiente', className: 'cv-monto', render: monto },
    { data: 'cancelada', className: 'cv-monto', render: montoDif },
    { data: 'facturas', className: 'cv-monto' },
    { data: 'diferencia', className: 'cv-monto', render: montoDif },
    { data: 'estatus', render: badge },
  ], 'Desglose venta debito', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    // Sumas de las filas que pasan el filtro/chip activo.
    footerCallback: function () {
      pieSumas(this.api(), [[3, 0], [4, 1], [5, 2], [6, 2], [7, 2], [8, 2], [9, 2], [10, 2], [11, 2], [12, 0], [13, 2]]);
    },
  });
  var COL_ESTATUS_VENTA = 14;

  var tablaApp = initTabla('#tablaApp', [
    { data: 'codcli' },
    { data: 'Cliente', className: 'cv-wrap', render: texto },
    { data: 'Categoria', render: function (d, t) { return t === 'display' ? (d ? '<span class="cv-badge cv-muted">' + esc(d) + '</span>' : '') : d; } },
    { data: 'RazonesSociales', className: 'cv-monto' },
    { data: 'Estaciones', className: 'cv-monto' },
    { data: 'PrimerConsumo', render: fecha },
    { data: 'UltimoConsumo', render: fecha },
    { data: 'Despachos', className: 'cv-monto' },
    { data: 'Litros', className: 'cv-monto', render: litros },
    { data: 'Monto', className: 'cv-monto', render: monto },
    { data: 'MontoCortes', className: 'cv-monto', render: monto },
    { data: 'Diferencia', className: 'cv-monto', render: montoDif },
  ], 'Consumos por cliente debito');

  var tablaPropio = initTabla('#tablaPropio', [
    { data: 'Cliente', className: 'cv-wrap', render: texto },
    { data: 'codcli' },
    { data: 'Categoria', render: function (d, t) { return t === 'display' ? '<span class="cv-badge cv-muted">' + esc(d) + '</span>' : d; } },
    { data: 'Fecha', render: fecha },
    { data: 'hratrn', render: function (d, t) {
        if (t !== 'display') return d;
        var h = String(d == null ? '' : d).padStart(4, '0');
        return h.substring(0, 2) + ':' + h.substring(2);
      } },
    { data: 'nrotrn', className: 'cv-mono' },
    { data: 'Estacion', render: texto },
    { data: 'RazonSocial', render: texto },
    { data: 'Litros', className: 'cv-monto', render: litros },
    { data: 'Monto', className: 'cv-monto', render: monto },
    { data: 'producto', render: texto },
  ], 'Consumos propios debito');
  var COL_CATEGORIA_PROPIO = 2;

  var tablaFacturas = initTabla('#tablaFacturas', [
    { data: 'factura', className: 'cv-mono' },
    { data: 'fecha', render: fecha },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'tipo', render: texto },
    { data: 'total', className: 'cv-monto', render: monto },
    { data: 'cfdi', render: badge },
    { data: 'periodo', render: badge },
    { data: 'referencia', className: 'cv-wrap', render: texto },
    { data: 'uuid', className: 'cv-mono', render: texto },
  ], 'Facturas de consumos Serie E', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () { pieSumas(this.api(), [[5, 2]]); },
  });
  var COL_PERIODO_FACTURAS = 7;

  var tablaPendientes = initTabla('#tablaPendientes', [
    { data: 'fecha', render: fecha },
    { data: 'hora' },
    { data: 'nrotrn', className: 'cv-mono' },
    { data: 'estacion', render: texto },
    { data: 'producto', render: texto },
    { data: 'litros', className: 'cv-monto', render: litros },
    { data: 'monto', className: 'cv-monto', render: monto },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'clase', render: badge },
    { data: 'categoria', render: function (d, t) { return t === 'display' ? (d ? '<span class="cv-badge cv-muted">' + esc(d) + '</span>' : '') : d; } },
  ], 'Despachos pendientes de facturar', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () { pieSumas(this.api(), [[5, 1], [6, 2]]); },
  });
  var COL_CLASE_PENDIENTES = 9;

  $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
    $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
  });

  // ---------------------------------------------------------------- datos
  // Llaves fijas del estado: las tareas siguientes llenan anticipos, cobrosFactura, cobros, banco2791 y banco0956.
  var estado = {
    busqueda: 0, venta: [], facturas: [], anticipos: [], cobrosFactura: [], cobros: [], banco2791: [], banco0956: [],
    xhrs: [], fin: {}, t0: {},
  };

  function filtros(extra) {
    return $.extend({ mes: $('#cvMes').val(), empresa: $('#cvEmpresa').val() }, extra || {});
  }
  function pedir(ruta, extra) {
    return $.ajax({ url: routeUrl(ruta), method: 'POST', dataType: 'json', timeout: 600000, data: filtros(extra) });
  }

  // Consultas del módulo: cada una llena su(s) tab(s) en cuanto responde.
  var CONSULTAS = [
    ['venta', 'Desglose venta', '#tabVenta', '#cntVenta'],
    ['facturas', 'Facturas de consumos (Serie E)', '#tabFacturas', '#cntFacturas'],
    ['pendientes', 'Despachos pendientes de facturar', '#tabPendientes', '#cntPendientes'],
    ['app', 'Consumos por cliente', '#tabApp', '#cntApp'],
    ['propio', 'Consumos propios', '#tabPropio', '#cntPropio'],
  ];
  var TABLAS = function () { return [tablaVenta, tablaFacturas, tablaPendientes, tablaApp, tablaPropio]; };
  var progTimer = null;

  function progresoInicio() {
    clearInterval(progTimer);
    $('#cvProgItems').html(CONSULTAS.map(function (c) {
      return '<span class="cv-prog-item" data-k="' + esc(c[0]) + '" data-tab="' + esc(c[2]) + '" title="Consultando..."><i class="fas fa-circle-notch fa-spin"></i> ' +
        esc(c[1]) + ' <small></small></span>';
    }).join(''));
    estado.t0 = {};
    CONSULTAS.forEach(function (c) { estado.t0[c[0]] = Date.now(); });
    progresoActualiza();
    $('#cvProgreso').show();
    progTimer = setInterval(progresoActualiza, 1000);
  }
  // Pinta texto y segundos transcurridos de las consultas que siguen en curso.
  function progresoActualiza() {
    var total = CONSULTAS.length, hechas = 0, fallas = 0;
    CONSULTAS.forEach(function (c) {
      var est = estado.fin[c[0]];
      if (est) { hechas++; if (est === 'err') fallas++; }
      else $('.cv-prog-item[data-k="' + c[0] + '"] small').text(Math.round((Date.now() - estado.t0[c[0]]) / 1000) + ' s');
    });
    var fin = hechas === total;
    $('#cvProgTexto').text(hechas + '/' + total);
    $('#cvProgBadge').removeClass('ok err').addClass(fin ? (fallas ? 'err' : 'ok') : '')
      .attr('title', fin
        ? (fallas ? 'Terminó con ' + fallas + ' consulta(s) con error' : 'Listo: las ' + total + ' consultas terminaron')
        : 'Consultando: ' + hechas + ' de ' + total + ' listas. Clic para ver el detalle.')
      .find('i').attr('class', 'fas ' + (!fin ? 'fa-circle-notch fa-spin' : (fallas ? 'fa-exclamation-triangle' : 'fa-check')));
    if (fin) clearInterval(progTimer);
  }
  function progresoMarca(k, ok) {
    estado.fin[k] = ok ? 'ok' : 'err';
    var seg = ((Date.now() - estado.t0[k]) / 1000).toFixed(1) + ' s';
    $('.cv-prog-item[data-k="' + k + '"]').removeClass('ok err').addClass(ok ? 'ok' : 'err')
      .attr('title', ok ? 'Terminó: clic para abrir el tab' : 'Falló la consulta; vuelve a presionar Buscar')
      .find('i').attr('class', 'fas ' + (ok ? 'fa-check' : 'fa-times')).end()
      .find('small').text(seg);
    progresoActualiza();
    $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
  }
  // Clic nativo: lo atiende el data-api de Bootstrap (no depende del plugin de jQuery)
  function abrirTab(tab) {
    var b = document.querySelector('#cvTabs button[data-bs-target="' + tab + '"]');
    if (b) b.click();
  }
  $('#cvProgItems').on('click', '.cv-prog-item.ok', function () { abrirTab($(this).data('tab')); $('#cvProgPanel').hide(); });
  $('#cvProgBadge').on('click', function (e) { e.stopPropagation(); $('#cvProgPanel').toggle(); });
  $(document).on('click', function (e) { if (!$(e.target).closest('#cvProgreso').length) $('#cvProgPanel').hide(); });

  // Lanza una consulta; si ya hay otra búsqueda en marcha, su respuesta se descarta.
  function consultar(k, xhr, alOk) {
    var busqueda = estado.busqueda;
    estado.xhrs.push(xhr);
    xhr.done(function (r) {
      if (busqueda !== estado.busqueda) return;
      alOk((r && r.data) || []);
      progresoMarca(k, true);
    }).fail(function (x, st) {
      if (st === 'abort' || busqueda !== estado.busqueda) return;
      progresoMarca(k, false);
      alertify.error('Error al consultar: ' + CONSULTAS.filter(function (c) { return c[0] === k; })[0][1]);
    });
    return xhr;
  }

  function buscar() {
    (estado.xhrs || []).forEach(function (x) { x.abort(); });
    estado.busqueda++;
    estado.xhrs = []; estado.fin = {};
    estado.venta = []; estado.facturas = []; estado.anticipos = []; estado.cobrosFactura = [];
    estado.cobros = []; estado.banco2791 = []; estado.banco0956 = [];

    TABLAS().forEach(function (t) { t.settings()[0].oLanguage.sEmptyTable = 'Consultando…'; t.clear().draw(); });
    $('#cntVenta, #cntFacturas, #cntPendientes, #cntApp, #cntPropio').text('…');
    $('#chipsVenta, #chipsFacturas, #chipsPendientes, #chipsApp, #chipsPropio').empty();
    $('#resClase tbody, #resFacturas tbody').empty();
    progresoInicio();

    consultar('venta', pedir('income.conciliation_debit_sales'), function (filas) {
      estado.venta = filas;
      cargarTabla(tablaVenta, filas, '#cntVenta');
      pintarResumenClase(filas);
      pintarChips('#chipsVenta', tablaVenta, filas, 'estatus', COL_ESTATUS_VENTA, 'monto');
    });
    consultar('facturas', pedir('income.conciliation_debit_invoices'), function (filas) {
      estado.facturas = filas;
      cargarTabla(tablaFacturas, filas, '#cntFacturas');
      pintarChips('#chipsFacturas', tablaFacturas, filas, 'periodo', COL_PERIODO_FACTURAS, 'total');
      pintarResumenFacturas(filas);
    });
    consultar('pendientes', pedir('income.conciliation_debit_pending'), function (filas) {
      cargarTabla(tablaPendientes, filas, '#cntPendientes');
      pintarChips('#chipsPendientes', tablaPendientes, filas, 'clase', COL_CLASE_PENDIENTES, 'monto');
    });
    consultar('app', pedir('income.conciliation_debit_consumption'), function (filas) {
      cargarTabla(tablaApp, filas, '#cntApp');
      $('#chipsApp').html(
        '<span class="cv-chip">Clientes: <b>' + filas.length + '</b></span>' +
        '<span class="cv-chip">Despachos: <b>' + suma(filas, 'Despachos').toLocaleString('en-US') + '</b></span>' +
        '<span class="cv-chip">Litros: <b>' + fmtLitros(suma(filas, 'Litros')) + '</b></span>' +
        '<span class="cv-chip">Monto: <b>$' + fmtMonto(suma(filas, 'Monto')) + '</b></span>' +
        '<span class="cv-chip">Cortes: <b>$' + fmtMonto(suma(filas, 'MontoCortes')) + '</b></span>' +
        '<span class="cv-chip">Diferencia: <b>$' + fmtMonto(suma(filas, 'Diferencia')) + '</b></span>'
      );
    });
    consultar('propio', pedir('income.conciliation_debit_own'), function (filas) {
      cargarTabla(tablaPropio, filas, '#cntPropio');
      pintarChips('#chipsPropio', tablaPropio, filas, 'Categoria', COL_CATEGORIA_PROPIO, 'Monto');
    });
  }

  $('#cvFiltros').on('submit', function (e) { e.preventDefault(); buscar(); });
});
