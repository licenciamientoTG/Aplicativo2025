// Ingresos > Conciliación débito (prepago). Requiere conciliation_common.js (window.Conc).
// Diseño: docs/superpowers/specs/2026-11-08-conciliacion-debito-design.md
$(function () {
  // Estatus propios de la conciliación débito (los comunes viven en Conc.ESTATUS_CLASE).
  $.extend(Conc.ESTATUS_CLASE, {
    'Facturado': 'cv-ok', 'Redondeo': 'cv-ok', 'Se factura al mes siguiente': 'cv-info', 'Factura cancelada': 'cv-bad',
    'Facturado con base en cortes': 'cv-info', 'Consumo sin corte': 'cv-bad', 'Consumo sin factura': 'cv-bad',
    'Facturado de menos': 'cv-warn', 'Facturado de más': 'cv-warn',
    'Mes anterior': 'cv-info', 'Mes siguiente': 'cv-info', 'Sin periodo': 'cv-muted',
    'Con cobro': 'cv-ok', 'Sin cobro': 'cv-bad',
    'Sí': 'cv-ok', 'No': 'cv-warn', 'Sin factura en referencia': 'cv-bad',
    // Conciliación cobros (tab 10) y clasificación de los movimientos bancarios (tabs 11-12)
    'Efectivo': 'cv-info', 'Depositado en mes anterior': 'cv-info', 'Consumo propio': 'cv-muted', 'No localizado': 'cv-bad',
    'Cobro prepago': 'cv-ok', 'Cobro crédito': 'cv-info', 'Traspaso entre cuentas': 'cv-muted', 'Retiro': 'cv-muted', 'No identificado': 'cv-bad',
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
    anticipos: ['Anticipos', '<p>Facturas de anticipo (serie D, "Facturación Débito Tarjetas") de clientes de débito emitidas en el mes: el mismo reporte de Clientes &gt; Anticipos débito. Es la hoja <b>Anticipos</b> del Excel.</p>' +
      '<b>De dónde viene cada dato</b><ul>' +
      '<li><b>Subtotal, IVA y Total:</b> las líneas de la factura en ControlGas.</li>' +
      '<li><b>Cobro aplicado:</b> lo que los cobros prepago <b>del mismo mes</b> aplicaron a esta factura. Un cobro de otro mes no cuenta aquí.</li></ul>' +
      '<b>Cómo interpretarlo</b><ul>' +
      '<li><b>Con cobro:</b> el cobro aplicado cubre el total de la factura. <b>Sin cobro:</b> falta cobro aplicado en el mes (cobro de otro mes o factura sin cobrar).</li>' +
      '<li><b>Cancelada:</b> la factura se canceló en ControlGas y ya no conserva importe, por eso se muestra vacío; solo se cuentan.</li>' +
      '<li><b>Pie de la tabla:</b> suma las filas que ves (respeta el filtro y los chips).</li></ul>'],
    anticipos_cli: ['Anticipos por cliente', '<p>Monto anticipado en el mes por cliente de débito (suma de sus facturas de anticipo vigentes). Es la hoja <b>anticipos x cliente</b> del Excel.</p>' +
      '<ul><li><b>Saldo actual:</b> el saldo de débito del cliente hoy (Clientes &gt; saldo débito), no el de fin de mes; cambia cada vez que consume o abona.</li>' +
      '<li>Las facturas canceladas no suman: no conservan importe.</li></ul>'],
    cobros_factura: ['Cobros por factura', '<p>Cada cobro prepago del mes (Cobro Clientes de Tarjetas débito), con la factura o facturas de anticipo que paga. Es la hoja <b>Cobros x factura</b> del Excel.</p>' +
      '<b>De dónde viene cada dato</b><ul>' +
      '<li><b>Factura e Importe aplicado:</b> de las aplicaciones del cobro en ControlGas; si no las hay, de la Referencia ("Fac.D242940 ($3,500.00)"). Un cobro que paga varias facturas genera una fila por factura (el monto del cobro se repite).</li></ul>' +
      '<b>¿Existe en Anticipos?</b><ul>' +
      '<li><b>Sí:</b> la factura es un anticipo del mes (tab Anticipos).</li>' +
      '<li><b>No:</b> el cobro paga un anticipo facturado en otro mes; explica parte de la diferencia cobros − anticipos.</li>' +
      '<li><b>Sin factura en referencia:</b> el cobro no indica una factura de anticipo (serie D) ni en sus aplicaciones ni en la Referencia; la fila trae el monto del cobro.</li></ul>' +
      '<p><b>Ojo:</b> la razón social del filtro se aplica a los anticipos por la factura y a los cobros por la estación del cobro; pueden diferir.</p>'],
    cruce: ['Conciliación cobros', '<p>Cada cobro prepago del mes (Cobro Clientes de Tarjetas débito) contra el depósito que le corresponde en el banco. Usa las mismas reglas que Conciliación crédito, pero solo con los depósitos de BANORTE <b>2791</b> y <b>0956</b>. Es la hoja <b>Conciliación cobros</b> del Excel.</p>' +
      '<b>Cómo se liga un cobro</b><ul>' +
      '<li><b>Cuadra / centavos / MOV compartido / MOV vecinos:</b> por el número de MOV que el capturista escribió en el cobro contra la secuencia del banco.</li>' +
      '<li><b>Cuadra por importe:</b> depósito libre del mismo importe a ±3 días. <b>Candidatos</b> mayor a 1 = se desempató por cuenta y fecha.</li>' +
      '<li><b>Diferencia:</b> hay depósito con esa secuencia pero el importe no cuadra.</li></ul>' +
      '<b>Cuando no hay depósito</b><ul>' +
      '<li><b>Efectivo:</b> el cobro se capturó con forma de pago 01; no tiene por qué estar en el banco.</li>' +
      '<li><b>Depositado en mes anterior:</b> el depósito ligado tiene fecha anterior al mes elegido.</li>' +
      '<li><b>Consumo propio:</b> el cliente del cobro está en el catálogo de consumo propio (por ejemplo Dollar to go).</li>' +
      '<li><b>No localizado:</b> no se encontró depósito; son los que hay que revisar (renglón rojo).</li>' +
      '<li><b>Cancelado:</b> cobro cancelado; no entra al cruce.</li></ul>' +
      '<p>El card de arriba suma los cobros por cuenta o por origen y los compara con el depósito ligado. La razón social del filtro se aplica después del cruce, porque un depósito puede juntar cobros de varias.</p>'],
    banco2791: ['Banco 2791', '<p>Movimientos del mes de BANORTE <b>0186932791</b> (depósitos de clientes de débito). Es la hoja <b>2791</b> del Excel. Se consulta al abrir el tab.</p>' +
      '<b>Clasificación</b><ul>' +
      '<li><b>Cobro prepago:</b> depósito ligado a un cobro prepago en el tab Conciliación cobros.</li>' +
      '<li><b>Cobro crédito:</b> depósito ligado a un cobro de crédito (mismo cruce que Conciliación crédito).</li>' +
      '<li><b>Traspaso entre cuentas:</b> el detalle menciona una cuenta propia del catálogo de cuentas bancarias.</li>' +
      '<li><b>Retiro:</b> movimiento con cargo (no es abono).</li>' +
      '<li><b>No identificado:</b> abono que no se pudo explicar; es lo que hay que revisar.</li></ul>' +
      '<p>El pie suma abonos y cargos de las filas que ves (respeta el filtro y los chips). Estos movimientos no dependen de la razón social del filtro.</p>'],
    banco0956: ['Banco 0956', '<p>Movimientos del mes de BANORTE <b>0601500956</b> (cobros con tarjeta). Es la hoja <b>0956</b> del Excel. Se consulta al abrir el tab. La clasificación funciona igual que en Banco 2791.</p>'],
  };
  Conc.bindAyuda(AYUDA);

  // Utilidades comunes: alias locales.
  var fmtMonto = Conc.fmtMonto, fmtLitros = Conc.fmtLitros, esc = Conc.esc, suma = Conc.suma;
  var monto = Conc.monto, montoDif = Conc.montoDif, montoSigno = Conc.montoSigno, litros = Conc.litros;
  var badge = Conc.badge, fecha = Conc.fecha, texto = Conc.texto, textoLineas = Conc.textoLineas;
  var initTabla = Conc.initTabla, pintarChips = Conc.pintarChips;

  function r2(n) { return Math.round(n * 100) / 100; }
  function tdm(n) { return '<td class="cv-monto">' + fmtMonto(n) + '</td>'; }
  function tdn(n) { return '<td class="cv-monto">' + Number(n).toLocaleString('en-US') + '</td>'; }
  // Tolera null (canceladas sin importe, clientes sin saldo): Number(null) || 0; monto/montoSigno pintan '' con null.
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

  var tablaAnticipos = initTabla('#tablaAnticipos', [
    { data: 'factura', className: 'cv-mono' },
    { data: 'fecha', render: fecha },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'estacion', render: texto },
    { data: 'razon_social', render: texto },
    { data: 'subtotal', className: 'cv-monto', render: monto },
    { data: 'iva', className: 'cv-monto', render: monto },
    { data: 'total', className: 'cv-monto', render: monto },
    { data: 'cobro_aplicado', className: 'cv-monto', render: monto },
    { data: 'estatus', render: badge },
  ], 'Anticipos debito', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () { pieSumas(this.api(), [[6, 2], [7, 2], [8, 2], [9, 2]]); },
  });
  var COL_ESTATUS_ANTICIPOS = 10;

  var tablaAnticiposCli = initTabla('#tablaAnticiposCli', [
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'anticipos', className: 'cv-monto' },
    { data: 'monto', className: 'cv-monto', render: monto },
    { data: 'saldo', className: 'cv-monto', render: montoSigno },
  ], 'Anticipos por cliente debito', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () { pieSumas(this.api(), [[2, 0], [3, 2], [4, 2]]); },
  });

  var tablaCobrosFactura = initTabla('#tablaCobrosFactura', [
    { data: 'cobro', className: 'cv-mono' },
    { data: 'fecha', render: fecha },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'monto_cobro', className: 'cv-monto', render: monto },
    { data: 'factura', className: 'cv-mono', render: texto },
    { data: 'importe', className: 'cv-monto', render: monto },
    { data: 'existe_en_anticipos', render: badge },
  ], 'Cobros por factura debito', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    // El monto del cobro se repite en cada fila de sus facturas: el pie suma solo el importe aplicado.
    footerCallback: function () { pieSumas(this.api(), [[6, 2]]); },
  });
  var COL_EXISTE_COBROS_FACTURA = 7;

  // Cobros sin depósito que no son un faltante: renglón gris en vez de rojo.
  var SIN_BANCO_NORMAL = ['Cancelado', 'Consumo propio', 'Efectivo'];

  var tablaCruce = initTabla('#tablaCruce', [
    { data: 'folios', className: 'cv-mono', render: texto },
    { data: 'fecha', render: fecha },
    { data: 'clientes', className: 'cv-wrap', render: textoLineas },
    { data: 'estatus', render: badge },
    // --- Cobro (Control Gas)
    { data: 'formas', render: texto },
    { data: 'ordenantes', className: 'cv-wrap', render: texto },
    { data: 'bancos_ord', render: texto },
    { data: 'facturas', className: 'cv-wrap', render: function (d, t) { return textoLineas(String(d || '').replace(/\),\s*/g, '),\n'), t); } },
    { data: 'movs', className: 'cv-mono' },
    { data: 'monto', className: 'cv-monto', render: monto },
    { data: 'uuids', className: 'cv-mono', render: textoLineas },
    // --- Movimiento bancario (sin movimiento: se avisa en la primera columna del banco)
    { data: 'banco', className: 'cv-c-banco', render: function (d, t, r) {
        if (t !== 'display') return d;
        if (d) return esc(d);
        return SIN_BANCO_NORMAL.indexOf(r.estatus) >= 0 ? '' : '<span class="cv-sinmov">Sin movimiento bancario</span>';
      } },
    { data: 'cuenta', className: 'cv-mono cv-c-banco' },
    { data: 'fecha_banco', className: 'cv-c-banco', render: fecha },
    { data: 'secuencia', className: 'cv-mono cv-c-banco' },
    { data: 'abono', className: 'cv-monto cv-c-banco', render: monto },
    { data: 'diferencia', className: 'cv-monto cv-c-banco', render: montoDif },
    { data: 'candidatos', className: 'cv-monto cv-c-banco', render: function (d, t) {
        return t === 'display' ? (d > 1 ? '<span class="cv-neg">' + d + '</span>' : (d || '')) : d;
      } },
    { data: 'contraparte', className: 'cv-wrap cv-c-banco', render: texto },
    { data: 'rfc', className: 'cv-mono cv-c-banco', render: texto },
    { data: 'detalle', className: 'cv-wrap cv-c-banco', render: texto },
    { data: 'cobros_dep', className: 'cv-monto' },
  ], 'Conciliacion cobros debito', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    // Cobro sin depósito: renglón rojo suave (gris si es cancelado, efectivo o consumo propio)
    createdRow: function (row, d) {
      if (!d.cuenta) $(row).addClass(SIN_BANCO_NORMAL.indexOf(d.estatus) >= 0 ? 'cv-fila-cancel' : 'cv-fila-sinmov');
    },
  });
  var COL_ESTATUS_CRUCE = 3;

  // Movimientos bancarios (tabs 11 y 12): mismas columnas, una tabla por cuenta.
  function tablaBanco(sel, titulo) {
    return initTabla(sel, [
      { data: 'fecha', render: fecha },
      { data: 'secuencia', className: 'cv-mono' },
      { data: 'descripcion', className: 'cv-wrap', render: texto },
      { data: 'referencia', className: 'cv-mono', render: texto },
      { data: 'abono', className: 'cv-monto', render: monto },
      { data: 'cargo', className: 'cv-monto', render: monto },
      { data: 'saldo', className: 'cv-monto', render: montoSigno },
      { data: 'contraparte', className: 'cv-wrap', render: texto },
      { data: 'rfc', className: 'cv-mono', render: texto },
      { data: 'detalle', className: 'cv-wrap', render: texto },
      { data: 'clasificacion', render: badge },
    ], titulo, {
      fija: true,
      paging: false,
      dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
      footerCallback: function () { pieSumas(this.api(), [[4, 2], [5, 2]]); },
    });
  }
  var tablaBanco2791 = tablaBanco('#tablaBanco2791', 'Banco 2791 debito');
  var tablaBanco0956 = tablaBanco('#tablaBanco0956', 'Banco 0956 debito');
  var COL_CLASIF_BANCO = 10;
  // Los bancos se consultan al abrir su tab (cada consulta recalcula el cruce de cobros): no van en CONSULTAS.
  var BANCOS = [
    { clave: 'banco2791', cuenta: '0186932791', tab: '#tabBanco2791', tabla: tablaBanco2791, cnt: '#cntBanco2791', chips: '#chipsBanco2791' },
    { clave: 'banco0956', cuenta: '0601500956', tab: '#tabBanco0956', tabla: tablaBanco0956, cnt: '#cntBanco0956', chips: '#chipsBanco0956' },
  ];

  // Cobros por cuenta de banco (o por origen sin depósito) contra el depósito ligado.
  function pintarResumenCruce(cobros) {
    var g = {}, orden = [];
    var ORIGEN = ['Depositado en mes anterior', 'Efectivo', 'Consumo propio', 'No localizado'];
    cobros.forEach(function (r) {
      var conCuenta = !!r.cuenta && r.estatus !== 'Depositado en mes anterior';
      var k = conCuenta ? 'c|' + r.cuenta : 's|' + r.estatus;
      if (!g[k]) {
        g[k] = { etiqueta: conCuenta ? (r.banco ? r.banco + ' ' : '') + r.cuenta : r.estatus, conCuenta: conCuenta, n: 0, monto: 0, depositos: {}, estatus: {} };
        orden.push(k);
      }
      var x = g[k];
      x.n++; x.monto += Number(r.monto) || 0;
      if (r.deposito_id) x.depositos[r.deposito_id] = Number(r.abono) || 0;
      x.estatus[r.estatus] = (x.estatus[r.estatus] || 0) + 1;
    });
    var t = { n: 0, monto: 0, dep: 0, montoConCuenta: 0 }, html = '';
    // Primero las cuentas (la de mayor importe arriba), luego los orígenes en orden fijo
    var rank = function (k) { var i = ORIGEN.indexOf(g[k].etiqueta); return g[k].conCuenta ? -1 : (i < 0 ? ORIGEN.length : i); };
    orden.sort(function (a, b) { return (rank(a) - rank(b)) || (g[b].monto - g[a].monto); }).forEach(function (k) {
      var x = g[k];
      var dep = Object.keys(x.depositos).reduce(function (s, id) { return s + x.depositos[id]; }, 0);
      var est = Object.keys(x.estatus).sort().map(function (e) { return badge(e, 'display') + '<b>' + x.estatus[e] + '</b>'; }).join(' ');
      html += '<tr><td>' + esc(x.etiqueta) + '</td>' + tdn(x.n) + tdm(x.monto) +
        (x.conCuenta ? tdm(dep) + '<td class="cv-monto">' + montoDif(r2(dep - x.monto), 'display') + '</td>' : '<td></td><td></td>') +
        '<td class="cv-monto">' + est + '</td></tr>';
      t.n += x.n; t.monto += x.monto;
      if (x.conCuenta) { t.dep += dep; t.montoConCuenta += x.monto; }
    });
    if (cobros.length) html += '<tr class="cv-total"><td>Total</td>' + tdn(t.n) + tdm(t.monto) + tdm(t.dep) +
      '<td class="cv-monto">' + montoDif(r2(t.dep - t.montoConCuenta), 'display') + '</td><td></td></tr>';
    $('#resCruce tbody').html(html);
  }

  // Una fila por cobro (si trae varias facturas/UUID se juntan en la misma fila); el depósito del banco se repite
  // en cada cobro que lo compone; "Cobros del depósito" dice cuántos cobros lo comparten.
  function agruparCruce(cobros) {
    var grupos = {}, orden = [], comparten = {};
    function llaveDeposito(r) {
      if (r.deposito_id) return 'd' + r.deposito_id + '|' + r.estatus;
      if (r.mov !== null && r.mov !== '' && r.estatus !== 'Cancelado') return 'm' + r.mov + '|' + r.estatus;
      return null;
    }
    cobros.forEach(function (r) {
      var kd = llaveDeposito(r);
      if (kd) (comparten[kd] = comparten[kd] || {})[r.cobro + '|' + r.estacion] = true;
    });
    cobros.forEach(function (r) {
      var kd = llaveDeposito(r);
      var k = 'c' + r.cobro + '|' + r.estacion + '|' + r.estatus;
      if (!grupos[k]) {
        grupos[k] = {
          estatus: r.estatus, movs: [], cobros: 0, cobros_dep: kd ? Object.keys(comparten[kd]).length : 1, clientes: [], monto: 0, banco: r.banco, cuenta: r.cuenta,
          fecha_banco: r.fecha_banco, secuencia: r.secuencia_banco, abono: r.abono, diferencia: r.diferencia,
          candidatos: r.estatus.indexOf('por importe') > 0 ? r.candidatos : null,
          contraparte: r.contraparte, rfc: r.rfc_contraparte, detalle: r.detalle_banco, folios: [],
          fecha: r.fecha, formas: [], ordenantes: [], bancos_ord: [], facturas: [], uuids: [],
        };
        orden.push(k);
      }
      var g = grupos[k];
      g.cobros++;
      g.monto += Number(r.monto) || 0;
      if (r.mov !== null && r.mov !== '' && g.movs.indexOf(String(r.mov)) < 0) g.movs.push(String(r.mov));
      if (r.cliente && g.clientes.indexOf(r.cliente) < 0) g.clientes.push(r.cliente);
      if (g.folios.indexOf(String(r.cobro)) < 0) g.folios.push(String(r.cobro));
      if (r.fecha && r.fecha < g.fecha) g.fecha = r.fecha;
      [['formas', r.forma_pago], ['ordenantes', r.ordenante], ['bancos_ord', r.banco_ordenante], ['facturas', r.facturas], ['uuids', r.uuid]].forEach(function (c) {
        if (c[1] && g[c[0]].indexOf(c[1]) < 0) g[c[0]].push(c[1]);
      });
    });
    return orden.map(function (k) {
      var g = grupos[k];
      g.monto = Math.round(g.monto * 100) / 100;
      g.movs = g.movs.join(', ');
      g.clientes = g.clientes.join('\n');   // uno por línea dentro de la misma celda (ver textoLineas)
      g.folios = g.folios.join(', ');
      g.uuids = g.uuids.join('\n');
      ['formas', 'ordenantes', 'bancos_ord', 'facturas'].forEach(function (c) { g[c] = g[c].join('; '); });
      return g;
    });
  }

  $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function () {
    $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
  });

  // ---------------------------------------------------------------- datos
  // Llaves fijas del estado (cobros, banco2791 y banco0956 las llena el tab 10-12).
  // partidasAnticipos = card B calculado en el servidor (ConciliacionDebito::partidasAnticipos), lo usa el Resumen.
  var estado = {
    busqueda: 0, venta: [], facturas: [], anticipos: [], cobrosFactura: [], cobros: [], banco2791: [], banco0956: [],
    partidasAnticipos: null, bancoPedido: {}, xhrs: [], fin: {}, t0: {},
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
    ['anticipos', 'Anticipos', '#tabAnticipos', '#cntAnticipos'],
    ['anticipos_cli', 'Anticipos por cliente', '#tabAnticiposCli', '#cntAnticiposCli'],
    ['cobros_factura', 'Cobros por factura', '#tabCobrosFactura', '#cntCobrosFactura'],
    ['cobros', 'Conciliación cobros', '#tabCruce', '#cntCruce'],
  ];
  var TABLAS = function () {
    return [tablaVenta, tablaFacturas, tablaPendientes, tablaApp, tablaPropio, tablaAnticipos, tablaAnticiposCli, tablaCobrosFactura, tablaCruce];
  };
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
      alOk((r && r.data) || [], r);
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
    estado.cobros = []; estado.banco2791 = []; estado.banco0956 = []; estado.partidasAnticipos = null;
    estado.bancoPedido = {};

    TABLAS().forEach(function (t) { t.settings()[0].oLanguage.sEmptyTable = 'Consultando…'; t.clear().draw(); });
    $('#cntVenta, #cntFacturas, #cntPendientes, #cntApp, #cntPropio, #cntAnticipos, #cntAnticiposCli, #cntCobrosFactura, #cntCruce').text('…');
    $('#chipsVenta, #chipsFacturas, #chipsPendientes, #chipsApp, #chipsPropio, #chipsAnticipos, #chipsAnticiposCli, #chipsCobrosFactura, #chipsCruce').empty();
    $('#resClase tbody, #resFacturas tbody, #resCruce tbody').empty();
    // Bancos: se limpian y se consultan al abrir su tab
    BANCOS.forEach(function (b) {
      b.tabla.settings()[0].oLanguage.sEmptyTable = 'Se consulta al abrir este tab';
      b.tabla.clear().draw();
      $(b.cnt).text('–'); $(b.chips).empty();
    });
    if ($('#cvTabs .nav-link.active').is('[data-bs-target="#tabBanco2791"], [data-bs-target="#tabBanco0956"]')) cargarBancoActivo();
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
    consultar('anticipos', pedir('income.conciliation_debit_anticipos'), function (filas, resp) {
      estado.anticipos = filas;
      estado.partidasAnticipos = (resp && resp.partidas) || null;
      cargarTabla(tablaAnticipos, filas, '#cntAnticipos');
      pintarChips('#chipsAnticipos', tablaAnticipos, filas, 'estatus', COL_ESTATUS_ANTICIPOS, 'total');
    });
    consultar('anticipos_cli', pedir('income.conciliation_debit_anticipos_clients'), function (filas) {
      cargarTabla(tablaAnticiposCli, filas, '#cntAnticiposCli');
      $('#chipsAnticiposCli').html(
        '<span class="cv-chip">Clientes: <b>' + filas.length.toLocaleString('en-US') + '</b></span>' +
        '<span class="cv-chip">Anticipos: <b>' + suma(filas, 'anticipos').toLocaleString('en-US') + '</b></span>' +
        '<span class="cv-chip">Monto anticipado: <b>$' + fmtMonto(suma(filas, 'monto')) + '</b></span>'
      );
    });
    consultar('cobros_factura', pedir('income.conciliation_debit_payment_invoices'), function (filas) {
      estado.cobrosFactura = filas;
      cargarTabla(tablaCobrosFactura, filas, '#cntCobrosFactura');
      pintarChips('#chipsCobrosFactura', tablaCobrosFactura, filas, 'existe_en_anticipos', COL_EXISTE_COBROS_FACTURA, 'importe');
    });
    consultar('cobros', pedir('income.conciliation_debit_payments'), function (filas) {
      estado.cobros = filas;
      var grupos = agruparCruce(filas);
      cargarTabla(tablaCruce, grupos, '#cntCruce');
      pintarChips('#chipsCruce', tablaCruce, grupos, 'estatus', COL_ESTATUS_CRUCE, 'monto');
      // Cobros que hay que revisar: sin depósito y sin explicación (sin cancelados, efectivo ni consumo propio)
      var sinMov = grupos.filter(function (g) { return !g.cuenta && SIN_BANCO_NORMAL.indexOf(g.estatus) < 0; });
      $('#chipsCruce .cv-chip').first().after('<span class="cv-chip" data-regex="^(No localizado|Sin banco|Sin MOV)$"><span class="cv-sinmov">Sin movimiento bancario</span>: <b>' +
        sinMov.reduce(function (n, g) { return n + g.cobros; }, 0).toLocaleString('en-US') + '</b> cobros · $' + fmtMonto(suma(sinMov, 'monto')) + '</span>');
      pintarResumenCruce(filas);
    });
  }

  // Banco 2791 / 0956: se consulta la primera vez que se abre el tab después de cada Buscar.
  function cargarBanco(b) {
    if (!estado.busqueda || (estado.bancoPedido && estado.bancoPedido[b.clave])) return;
    estado.bancoPedido[b.clave] = true;
    var busqueda = estado.busqueda;
    b.tabla.settings()[0].oLanguage.sEmptyTable = 'Consultando…';
    b.tabla.clear().draw();
    $(b.cnt).text('…');
    var xhr = pedir('income.conciliation_debit_bank', { cuenta: b.cuenta });
    estado.xhrs.push(xhr);
    xhr.done(function (r) {
      if (busqueda !== estado.busqueda) return;
      var filas = ((r && r.data) || []).map(function (m) {
        m.importe = Number(m.abono) > 0 ? m.abono : m.cargo;   // los chips suman el importe del movimiento (abono o retiro)
        return m;
      });
      estado[b.clave] = filas;
      cargarTabla(b.tabla, filas, b.cnt);
      pintarChips(b.chips, b.tabla, filas, 'clasificacion', COL_CLASIF_BANCO, 'importe');
    }).fail(function (x, st) {
      if (st === 'abort' || busqueda !== estado.busqueda) return;
      estado.bancoPedido[b.clave] = false;
      b.tabla.settings()[0].oLanguage.sEmptyTable = 'Error al consultar; vuelve a abrir el tab';
      b.tabla.clear().draw();
      $(b.cnt).text('!');
      alertify.error('Error al consultar: ' + b.tab.replace('#tab', ''));
    });
  }
  function cargarBancoActivo() {
    BANCOS.forEach(function (b) { if ($('#cvTabs .nav-link.active').is('[data-bs-target="' + b.tab + '"]')) cargarBanco(b); });
  }
  $('#cvTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', cargarBancoActivo);

  $('#cvFiltros').on('submit', function (e) { e.preventDefault(); buscar(); });
});
