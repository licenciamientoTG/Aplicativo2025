// Utilidades comunes de Ingresos > Conciliación (crédito y débito).
// Expone window.Conc (y module.exports para pruebas en node).
(function () {
  var ESTATUS_CLASE = {
    // Desglose venta / despachos
    'Facturado': 'cv-ok', 'Facturado después': 'cv-info', 'No se factura': 'cv-muted',
    'Pendiente de facturar': 'cv-warn', 'Sin facturar': 'cv-bad', 'Sin factura': 'cv-bad',
    'Factura cancelada': 'cv-bad', 'Factura no encontrada': 'cv-bad',
    'Venta': 'cv-info', 'Consumo propio': 'cv-muted', 'Bonificación': 'cv-muted',
    // Despachos vs corte
    'Vale manual sin despacho': 'cv-warn', 'Vale con producto sin despacho': 'cv-bad', 'Despacho de otro mes': 'cv-info',
    'Despacho de otro cliente': 'cv-warn', 'Vale en otro mes': 'cv-info', 'Cuadra en otro corte': 'cv-info',
    'Despacho sin vale': 'cv-bad', 'Vale sin despacho': 'cv-bad', 'Monto distinto': 'cv-warn', 'Vale duplicado': 'cv-warn',
    // Facturas
    'Del mes': 'cv-ok', 'Emitida después': 'cv-info', 'Mezcla de meses': 'cv-warn', 'Despachos de otro mes': 'cv-warn',
    'Sin despachos': 'cv-bad', 'Cancelada': 'cv-muted', 'No encontrada': 'cv-bad',
    'Timbrada': 'cv-ok', 'Sin timbrar': 'cv-warn',
    // Cobros
    'Cuadra': 'cv-ok', 'Cuadra (centavos)': 'cv-ok', 'Cuadra (MOV compartido)': 'cv-ok', 'Cuadra (MOV vecinos)': 'cv-ok',
    'Cuadra por importe': 'cv-info', 'Cuadra por importe (sin MOV)': 'cv-info',
    'Diferencia': 'cv-warn', 'Sin banco': 'cv-bad', 'Sin MOV': 'cv-bad', 'Cancelado': 'cv-muted',
    // Banco
    'Ligado a cobro': 'cv-ok', 'Sin cobro de crédito': 'cv-muted', 'Retiro': 'cv-muted',
    // Resumen
    'Conciliado': 'cv-ok', 'Revisar': 'cv-bad',
  };

  function fmtMonto(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtLitros(n) {
    return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  }
  function fmtFecha(iso) {
    var p = String(iso || '').substring(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : (iso || '');
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function suma(filas, campo, filtro) {
    return filas.reduce(function (s, r) { return (!filtro || filtro(r)) ? s + (Number(r[campo]) || 0) : s; }, 0);
  }
  // display: con formato; export/sort/filter: valor crudo
  function monto(d, type) { return type === 'display' ? (d === null || d === '' ? '' : fmtMonto(d)) : d; }
  function montoDif(d, type) {
    if (type !== 'display') return d;
    if (d === null || d === '') return '';
    return Math.abs(d) >= 0.01 ? '<span class="cv-neg">' + fmtMonto(d) + '</span>' : fmtMonto(d);
  }
  // Solo lo negativo en rojo; lo positivo y el cero van en el color normal.
  function montoSigno(d, type) {
    if (type !== 'display') return d;
    if (d === null || d === '') return '';
    return Number(d) <= -0.01 ? '<span class="cv-neg">' + fmtMonto(d) + '</span>' : fmtMonto(d);
  }
  function litros(d, type) { return type === 'display' ? fmtLitros(d) : d; }
  function badge(d, type) {
    if (type !== 'display') return d;
    return d ? '<span class="cv-badge ' + (ESTATUS_CLASE[d] || 'cv-muted') + '">' + esc(d) + '</span>' : '';
  }
  function fecha(d, type) { return type === 'display' ? fmtFecha(d) : d; }
  function texto(d, type) { return type === 'display' ? esc(d) : d; }
  // Varios valores en la misma celda, uno por línea (separados por salto de línea)
  function textoLineas(d, type) { return type === 'display' ? esc(d).replace(/\n/g, '<br>') : d; }
  // valorMes: 'Y-m' (valor del input #cvMes)
  function mesTexto(valorMes) {
    var p = (valorMes || '').split('-');
    var meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return p.length === 2 ? meses[Number(p[1]) - 1] + ' ' + p[0] : '';
  }

  function initTabla(sel, columnas, titulo, extra) {
    return $(sel).DataTable($.extend({
      paging: true,
      pageLength: 100,
      lengthMenu: [[50, 100, 500, -1], [50, 100, 500, 'Todos']],
      order: [],
      dom: '<"cv-top"Bf>rt<"cv-bottom"ilp>',
      language: {
        info: 'Mostrando _START_–_END_ de _TOTAL_',
        infoFiltered: '(filtrados de _MAX_)',
        infoEmpty: 'Sin registros',
        emptyTable: 'Elige un mes y presiona Buscar.',
        lengthMenu: '_MENU_ por página',
        search: 'Buscar:',
        paginate: { previous: '‹', next: '›' },
      },
      buttons: [
        {
          extend: 'excel',
          text: '<i class="fas fa-file-excel"></i> Excel',
          className: 'btn btn-sm btn-success',
          title: function () { return titulo + ' ' + mesTexto($('#cvMes').val()) + ' - ' + $('#cvEmpresa option:selected').text(); },
          exportOptions: { columns: ':visible', orthogonal: 'export' },
        },
        { extend: 'colvis', text: '<i class="fas fa-columns"></i> Columnas', className: 'btn btn-sm btn-outline-secondary' },
      ],
      data: [],
      deferRender: true,
      columns: columnas,
      initComplete: function () {
        var api = this.api();
        $(sel).wrap('<div class="cv-scroll' + (extra && extra.fija ? ' cv-fija' : '') + '"></div>');
        var filterRow = $('<tr class="filter"></tr>');
        $(sel + ' thead tr:last th').each(function () {
          filterRow.append('<th><input type="text" placeholder="' + esc($(this).text().trim()) + '" /></th>');
        });
        $(sel + ' thead').append(filterRow);
        $(sel + ' thead tr.filter th input').on('keyup change', function () {
          api.column($(this).parent().index() + ':visible').search(this.value).draw();
        });
      },
    }, extra || {}));
  }

  // Chips por estatus: conteo + suma; clic filtra la columna de estatus.
  // campoPend (opcional): lo que falta por facturar; en los estatus de ESTATUS_PEND el chip muestra esto en vez del monto.
  var ESTATUS_PEND = ['Pendiente de facturar', 'Sin facturar', 'Factura cancelada'];
  function pintarChips(contenedor, api, filas, campoEstatus, colEstatus, campoMonto, campoPend) {
    var grupos = {};
    filas.forEach(function (r) {
      var k = r[campoEstatus] || '(vacío)';
      grupos[k] = grupos[k] || { n: 0, monto: 0, pend: 0 };
      grupos[k].n++;
      grupos[k].monto += Number(r[campoMonto]) || 0;
      if (campoPend) grupos[k].pend += Number(r[campoPend]) || 0;
    });
    var html = '<span class="cv-chip activo" data-estatus="">Todos: <b>' + filas.length.toLocaleString('en-US') + '</b> · $' +
      fmtMonto(suma(filas, campoMonto)) + '</span>';
    Object.keys(grupos).sort().forEach(function (k) {
      html += '<span class="cv-chip" data-estatus="' + esc(k) + '">' + badge(k, 'display') +
        ' <b>' + grupos[k].n.toLocaleString('en-US') + '</b> · ' +
        // En los estatus pendientes el chip muestra solo lo que falta por facturar, no el monto total del cliente
        (campoPend && ESTATUS_PEND.indexOf(k) >= 0
          ? '<span class="cv-neg">$' + fmtMonto(grupos[k].pend) + '</span>' : '$' + fmtMonto(grupos[k].monto)) + '</span>';
    });
    api.column(colEstatus).search('').draw();
    $(contenedor).html(html).off('click').on('click', '.cv-chip', function () {
      $(contenedor + ' .cv-chip').removeClass('activo');
      $(this).addClass('activo');
      var v = $(this).data('estatus'), rx = $(this).data('regex');   // data-regex: chip que agrupa varios estatus
      api.column(colEstatus).search(rx || (v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : ''), true, false).draw();
    });
  }

  // Ayuda "¿Qué estoy viendo?": un modal compartido, el texto sale de AYUDA.
  function bindAyuda(AYUDA) {
    $(document).on('click', '.cv-ayuda', function (e) {
      e.stopPropagation();
      var a = AYUDA[$(this).data('ayuda')];
      if (!a) return;
      $('#modalAyudaTitulo').text(a[0]);
      $('#modalAyudaCuerpo').html(a[1]);
      $('#modalAyuda').modal('show');
    });
  }

  var Conc = {
    fmtMonto: fmtMonto, fmtLitros: fmtLitros, fmtFecha: fmtFecha, esc: esc, suma: suma,
    mesTexto: mesTexto, monto: monto, montoDif: montoDif, montoSigno: montoSigno, litros: litros,
    badge: badge, fecha: fecha, texto: texto, textoLineas: textoLineas,
    ESTATUS_CLASE: ESTATUS_CLASE, initTabla: initTabla, pintarChips: pintarChips, bindAyuda: bindAyuda,
  };
  if (typeof window !== 'undefined') window.Conc = Conc;
  if (typeof module !== 'undefined' && module.exports) module.exports = Conc;
})();
