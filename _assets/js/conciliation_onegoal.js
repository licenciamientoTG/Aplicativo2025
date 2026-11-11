// Conciliación One Goal ↔ ControlGas (anticipos). Requiere conciliation_common.js (window.Conc).
$(function () {
  $.extend(Conc.ESTATUS_CLASE, {
    'En ambos': 'cv-ok', 'Falta en One Goal': 'cv-bad', 'Sin UUID': 'cv-bad',
    'Solo en One Goal': 'cv-warn', 'Cancelada': 'cv-muted', 'Cancelada, vigente en One Goal': 'cv-bad',
    'Cancelada en One Goal': 'cv-warn', 'Importe distinto': 'cv-warn', 'Timbrada en One Goal, sin UUID en ControlGas': 'cv-warn',
    'Vigente': 'cv-ok', 'Sin timbrar': 'cv-bad',
    'Sí': 'cv-ok', 'No': 'cv-warn',
  });

  Conc.bindAyuda({
    onegoal: ['One Goal (solo lo que One Goal tiene)',
      '<p>Los CFDI serie D (anticipos) que One Goal tiene registrados en el mes, tal como los guardó: folio, fecha de timbrado, cliente fiscal, RFC, código postal, uso de CFDI, método y forma de pago, régimen, correo, id de factura y UUID.</p>' +
      '<ul><li><b>Importes y estatus:</b> salen de la factura de One Goal (imp_fac_enc): Vigente, Cancelada o Sin timbrar.</li>' +
      '<li><b>Mensaje de importación:</b> "No se encontraron los despachos…" es normal en anticipos: no tienen despachos, por eso quedan en la tabla de entrada y no pasan a ventas.</li>' +
      '<li><b>En ControlGas = No:</b> el CFDI está en One Goal pero no corresponde a un anticipo del mes en ControlGas.</li></ul>'],
    anticipos: ['Anticipos One Goal ↔ ControlGas',
      '<p>Cada anticipo (factura serie D, "Facturación Débito Tarjetas") de ControlGas del mes, buscado por <b>UUID</b> en los CFDI que One Goal importó.</p>' +
      '<p><b>Qué guarda One Goal:</b> UUID, serie y folio, cliente, RFC y fecha de timbrado. <b>No guarda importe ni cancelación</b>, por eso el importe sale de ControlGas.</p>' +
      '<b>Estatus</b><ul>' +
      '<li><b>En ambos:</b> el UUID está en las dos.</li>' +
      '<li><b>Importe distinto:</b> están en las dos pero el total no coincide (Diferencia = ControlGas − One Goal).</li>' +
      '<li><b>Falta en One Goal:</b> anticipo vigente en ControlGas cuyo CFDI no se importó. Revisa el cuadro de folios consecutivos: un rango largo es una importación que no corrió.</li>' +
      '<li><b>Sin UUID:</b> anticipo con importe en ControlGas que nunca se timbró (no hay CFDI que buscar).</li>' +
      '<li><b>Timbrada en One Goal, sin UUID en ControlGas:</b> ControlGas no tiene guardado el UUID pero One Goal sí tiene el CFDI (se liga por el folio); hay que completar el UUID en ControlGas.</li>' +
      '<li><b>Solo en One Goal:</b> CFDI serie D timbrado en el mes que ControlGas no tiene.</li>' +
      '<li><b>Cancelada:</b> cancelada en ControlGas (ya sin importe) y también cancelada o ausente en One Goal. <b>Cancelada, vigente en One Goal:</b> ControlGas la canceló pero One Goal la sigue teniendo vigente: hay que cancelarla allá (su importe está en Total One Goal). <b>Cancelada en One Goal:</b> vigente en ControlGas pero cancelada en One Goal.</li></ul>' +
      '<p><b>Cuadre de importes:</b> parte de lo vigente en One Goal (imp_fac_enc) y llega al total de ControlGas sumando y restando cada estatus.</p>' +
      '<p>Se consultan los CFDI de One Goal timbrados desde 5 días antes del mes hasta 10 días después, para ligar los que se timbraron fuera del mes.</p>'],
  });

  var monto = Conc.monto, badge = Conc.badge, fecha = Conc.fecha, texto = Conc.texto;
  var esc = Conc.esc, fmtMonto = Conc.fmtMonto;

  function sumaCol(api, i) {
    return api.column(i, { search: 'applied' }).data().reduce(function (s, v) { return s + (Number(v) || 0); }, 0);
  }

  var tabla = Conc.initTabla('#tablaOg', [
    { data: 'estatus', render: badge },
    { data: 'factura', className: 'cv-mono' },
    { data: 'fecha', render: fecha },
    { data: 'codcli' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'total', className: 'cv-monto', render: monto },
    { data: 'og_total', className: 'cv-monto', render: monto },
    { data: 'diferencia', className: 'cv-monto', render: Conc.montoDif },
    { data: 'og_estatus', render: badge },
    { data: 'en_onegoal', render: badge },
    { data: 'og_folio', className: 'cv-mono', render: texto },
    { data: 'og_fecha', render: fecha },
    { data: 'og_cliente', className: 'cv-wrap', render: texto },
    { data: 'uuid', className: 'cv-mono', render: texto },
    { data: 'og_mensaje', className: 'cv-wrap', render: texto },
  ], 'Anticipos One Goal vs ControlGas', {
    fija: true,
    paging: false,
    dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () {
      var api = this.api();
      [5, 6, 7].forEach(function (i) { $(api.column(i).footer()).text(fmtMonto(sumaCol(api, i))); });
    },
  });
  var COL_ESTATUS = 0;

  var tablaSolo = Conc.initTabla('#tablaOgSolo', [
    { data: 'folio', className: 'cv-mono' },
    { data: 'fecha', render: fecha },
    { data: 'id_cli_og' },
    { data: 'cliente', className: 'cv-wrap', render: texto },
    { data: 'rfc', className: 'cv-mono', render: texto },
    { data: 'sub', className: 'cv-monto', render: monto },
    { data: 'iva', className: 'cv-monto', render: monto },
    { data: 'total', className: 'cv-monto', render: monto },
    { data: 'estatus_og', render: badge },
    { data: 'cp', render: texto },
    { data: 'uso_cfdi', render: texto },
    { data: 'metodo_pago', render: texto },
    { data: 'en_controlgas', render: badge },
    { data: 'id_fact' },
    { data: 'mensaje', className: 'cv-wrap', render: texto },
    { data: 'uuid', className: 'cv-mono', render: texto },
  ], 'Anticipos en One Goal', {
    fija: true, paging: false, dom: '<"cv-top"Bf>rt<"cv-bottom"i>',
    footerCallback: function () {
      var api = this.api();
      [5, 6, 7].forEach(function (i) { $(api.column(i).footer()).text(fmtMonto(sumaCol(api, i))); });
    },
  });
  var COL_ESTATUS_OG = 8;

  function pintarResumen(resumen) {
    var orden = ['En ambos', 'Importe distinto', 'Falta en One Goal', 'Sin UUID', 'Timbrada en One Goal, sin UUID en ControlGas', 'Solo en One Goal', 'Cancelada, vigente en One Goal', 'Cancelada en One Goal', 'Cancelada'];
    var html = '', n = 0, imp = 0;
    orden.forEach(function (k) {
      var x = resumen.por_estatus[k];
      if (!x) return;
      html += '<tr><td>' + badge(k, 'display') + '</td><td class="cv-monto">' + x.n.toLocaleString('en-US') + '</td><td class="cv-monto">' + fmtMonto(x.importe) + '</td></tr>';
      n += x.n; imp += x.importe;
    });
    html += '<tr class="cv-total"><td>Total</td><td class="cv-monto">' + n.toLocaleString('en-US') + '</td><td class="cv-monto">' + fmtMonto(imp) + '</td></tr>';
    $('#ogResEstatus tbody').html(html);
    $('#ogCuadre tbody').html((resumen.cuadre || []).map(function (c) {
      return '<tr' + (c.subtotal ? ' class="cv-total"' : '') + '><td>' + esc(c.concepto) + '</td><td class="cv-monto">' + fmtMonto(c.importe) + '</td></tr>';
    }).join(''));
    var r = resumen.rangos_faltantes || [];
    $('#ogRangos').html(r.length
      ? r.map(function (x) {
          return '<div><b>' + esc(x.desde === x.hasta ? x.desde : x.desde + ' – ' + x.hasta) + '</b> · ' + x.n + ' folio' + (x.n === 1 ? '' : 's') + '</div>';
        }).join('')
      : 'Ninguno: todos los anticipos vigentes con UUID están en One Goal.');
  }

  function buscar() {
    var $btn = $('#cvFiltros button[type=submit]').prop('disabled', true).text('Buscando…');
    $('#ogVacio').text('Consultando ControlGas y One Goal…').show();
    $.ajax({
      url: routeUrl('conciliacion.onegoal_controlgas_anticipos'), method: 'POST', dataType: 'json',
      timeout: 600000, data: { mes: $('#cvMes').val() },
    }).done(function (res) {
      pintarResumen(res.resumen);
      tabla.settings()[0].oLanguage.sEmptyTable = 'Sin registros para este mes';
      tabla.clear().rows.add(res.data).draw();
      Conc.pintarChips('#chipsOg', tabla, res.data, 'estatus', COL_ESTATUS, 'total');
      tablaSolo.clear().rows.add(res.onegoal).draw();
      Conc.pintarChips('#chipsOgSolo', tablaSolo, res.onegoal, 'estatus_og', COL_ESTATUS_OG, 'total');
      $('#cntConc').text(res.data.length.toLocaleString('en-US'));
      $('#cntOneGoal').text(res.onegoal.length.toLocaleString('en-US'));
      $('#ogVacio').hide();
      $('#ogResumen, #ogDetalle').show();
      $('button[data-bs-toggle=tab]').off('shown.bs.tab').on('shown.bs.tab', function () {
        $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
      });
      $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    }).fail(function () {
      $('#ogVacio').text('No se pudo consultar. Intenta de nuevo.').show();
    }).always(function () {
      $btn.prop('disabled', false).text('Buscar');
    });
  }

  $('#cvFiltros').on('submit', function (e) { e.preventDefault(); buscar(); });
});
