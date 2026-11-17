// Conciliación manual de cobros (Ingresos > Conciliación > tab Conciliación cobros).
// Expone window.ConciliacionManual: agruparCruce / claveItem (puros, probados en node) y abrir({grupo_id | movimiento_id}),
// que carga el modal (vista parcial), maneja buscadores, selección, preview, guardar y deshacer.
// Al guardar o deshacer dispara 'conciliacion:guardada' en document.
(function () {
  var Conc = (typeof window !== 'undefined' && window.Conc) || (typeof require !== 'undefined' ? require('./conciliation_common.js') : null);
  var esc = Conc.esc, fmtMonto = Conc.fmtMonto, fmtFecha = Conc.fmtFecha;

  // Clave de un item del modal: 'C|codgas|nro', 'F|codgas|nro', 'M|id'
  function claveItem(it) {
    return it.tipo === 'M' ? 'M|' + it.movimiento_id : it.tipo + '|' + it.codgas + '|' + it.nro;
  }

  // Una fila por cobro (si trae varias facturas/UUID se juntan en la misma fila); el depósito del banco se repite
  // en cada cobro que lo compone. Llave = grupo + cobro + estación; las filas sin cobro (depósito de un grupo sin
  // cobros) se agrupan por grupo. "Cobros del depósito" = cobros distintos del mismo grupo.
  function agruparCruce(cobros) {
    var grupos = {}, orden = [], comparten = {};
    cobros.forEach(function (r) {
      if (r.cobro !== null && r.cobro !== undefined && r.grupo_id) (comparten[r.grupo_id] = comparten[r.grupo_id] || {})[r.cobro + '|' + r.estacion] = true;
    });
    cobros.forEach(function (r) {
      var sinCobro = r.cobro === null || r.cobro === undefined;
      var k = 'g' + r.grupo_id + '|' + r.cobro + '|' + r.estacion;
      if (!grupos[k]) {
        grupos[k] = {
          grupo_id: r.grupo_id || null, origen: r.origen || 'auto', comentario: r.comentario || '', alertas: [],
          estatus: r.estatus, movs: [], cobros: 0, cobros_dep: r.grupo_id && comparten[r.grupo_id] ? Object.keys(comparten[r.grupo_id]).length : (sinCobro ? 0 : 1),
          clientes: [], monto: 0, banco: r.banco, cuenta: r.cuenta,
          fecha_banco: r.fecha_banco, secuencia: r.secuencia_banco, abono: r.abono, diferencia: r.diferencia,
          candidatos: null,
          contraparte: r.contraparte, rfc: r.rfc_contraparte, detalle: r.detalle_banco, folios: [],
          fecha: r.fecha || '', formas: [], ordenantes: [], bancos_ord: [], facturas: [], uuids: [],
          deposito_id: r.deposito_id || null,
        };
        orden.push(k);
      }
      var g = grupos[k];
      (r.alertas || []).forEach(function (a) { if (g.alertas.indexOf(a) < 0) g.alertas.push(a); });
      // Facturas y UUID también se juntan en filas sin cobro (depósito -> facturas)
      [["facturas", r.facturas], ["uuids", r.uuid]].forEach(function (c) {
        if (c[1] && g[c[0]].indexOf(c[1]) < 0) g[c[0]].push(c[1]);
      });
      if (sinCobro) return;
      g.cobros++;
      g.monto += Number(r.monto) || 0;
      if (r.mov !== null && r.mov !== undefined && r.mov !== '' && g.movs.indexOf(String(r.mov)) < 0) g.movs.push(String(r.mov));
      if (r.cliente && g.clientes.indexOf(r.cliente) < 0) g.clientes.push(r.cliente);
      if (g.folios.indexOf(String(r.cobro)) < 0) g.folios.push(String(r.cobro));
      if (r.fecha && (!g.fecha || r.fecha < g.fecha)) g.fecha = r.fecha;
      [['formas', r.forma_pago], ['ordenantes', r.ordenante], ['bancos_ord', r.banco_ordenante]].forEach(function (c) {
        if (c[1] && g[c[0]].indexOf(c[1]) < 0) g[c[0]].push(c[1]);
      });
    });
    return orden.map(function (k) {
      var g = grupos[k];
      g.monto = Math.round(g.monto * 100) / 100;
      g.movs = g.movs.join(', ');
      g.clientes = g.clientes.join('\n');   // uno por línea dentro de la misma celda
      g.folios = g.folios.join(', ');
      g.uuids = g.uuids.join('\n');
      ['formas', 'ordenantes', 'bancos_ord', 'facturas'].forEach(function (c) { g[c] = g[c].join('; '); });
      return g;
    });
  }

  // Mes y razón social de la búsqueda que se está viendo (no lo que el usuario cambie después en los filtros):
  // la vista registra de dónde leerlos con usarFiltros. Sin búsqueda, null.
  var fuenteFiltros = null;
  function usarFiltros(fn) { fuenteFiltros = fn; }
  function filtrosBusqueda() { return (fuenteFiltros && fuenteFiltros()) || null; }

  var API = { agruparCruce: agruparCruce, claveItem: claveItem, usarFiltros: usarFiltros, filtrosBusqueda: filtrosBusqueda };
  if (typeof window !== 'undefined') window.ConciliacionManual = API;
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
  if (typeof window === 'undefined' || typeof $ === 'undefined') return;

  // ------------------------------------------------------------------ modal
  var sel, sugeridos, res, timers = {}, previewSeq = 0, buscarSeq = { C: 0, M: 0, F: 0 };

  function filtros(extra) {
    return $.extend({}, filtrosBusqueda(), extra || {});
  }
  function jsonDe(texto) {
    try { return JSON.parse(texto); } catch (e) { return null; }
  }
  function root() { return $('#cmRoot'); }
  function grupoId() { return root().data('grupo-id') || ''; }

  function abrir(opts) {
    if (!filtrosBusqueda()) return;   // sin búsqueda no hay mes que conciliar
    opts = opts || {};
    var extra = {};
    if (opts.grupo_id) extra.grupo_id = opts.grupo_id;
    else if (opts.movimiento_id) extra.movimiento_id = opts.movimiento_id;
    $('#modalConciliacionManualCuerpo').html('<div class="modal-body text-muted">Cargando…</div>');
    $('#modalConciliacionManual').modal('show');
    $.ajax({ url: routeUrl('income.conciliation_manual_modal'), method: 'POST', dataType: 'html', data: filtros(extra) })
      .done(function (html) {
        $('#modalConciliacionManualCuerpo').html(html);
        iniciar();
      })
      .fail(function () {
        $('#modalConciliacionManual').modal('hide');
        alertify.error('No se pudo abrir la conciliación manual');
      });
  }

  function limpiarTimers() {
    Object.keys(timers).forEach(function (k) { clearTimeout(timers[k]); });
    timers = {};
  }

  function iniciar() {
    var items = jsonDe($('#cmItems').text()) || [];
    sugeridos = jsonDe($('#cmSugeridos').text()) || [];
    sel = { C: [], M: [], F: [] };
    res = { C: [], M: [], F: [] };
    limpiarTimers();
    items.forEach(function (it) { if (sel[it.tipo]) sel[it.tipo].push(it); });
    if (sugeridos.length) {
      $('#cmTotales').before('<div id="cmSugeridosBox" class="mb-2 small"></div>');
    }
    pintarSeleccion();
    pintarSugeridos();
    preview();
  }

  function seleccionado(it) {
    var k = claveItem(it);
    return sel[it.tipo].some(function (x) { return claveItem(x) === k; });
  }
  function agregar(it) {
    if (seleccionado(it)) return;
    sel[it.tipo].push(it);
    cambio();
  }
  function quitar(tipo, i) {
    sel[tipo].splice(i, 1);
    cambio();
  }
  function cambio() {
    pintarSeleccion();
    pintarSugeridos();
    ['C', 'M', 'F'].forEach(pintarResultados);
    preview();
  }

  function grupoTxt(r) {
    return r.grupo_id ? '#' + r.grupo_id + ' ' + (r.origen === 'manual' ? 'Manual' : 'Auto') : '';
  }
  function td(v, cls) { return '<td' + (cls ? ' class="' + cls + '"' : '') + '>' + esc(v) + '</td>'; }

  var COLS = {
    C: {
      res: function (r) { return td(r.estacion) + td(r.nro + (r.cancelado ? ' (cancelado)' : '')) + td(fmtFecha(r.fecha)) + td(r.cliente) + td(fmtMonto(r.monto), 'text-end') + td(r.mov) + td(r.facturas) + td(grupoTxt(r)); },
      sel: function (r) { return td(r.estacion) + td(r.nro + (r.existe === false ? ' (ya no existe)' : '')) + td(fmtFecha(r.fecha)) + td(r.cliente) + td(fmtMonto(r.monto), 'text-end') + td(r.mov); },
    },
    M: {
      res: function (r) { return td(r.banco) + td(r.cuenta) + td(fmtFecha(r.fecha)) + td(r.secuencia) + td(fmtMonto(r.monto), 'text-end') + td(r.contraparte) + td(r.rfc) + td(r.detalle) + td(grupoTxt(r)); },
      sel: function (r) { return td(r.banco) + td(r.cuenta) + td(fmtFecha(r.fecha)) + td(r.secuencia + (r.existe === false ? ' (ya no existe)' : '')) + td(fmtMonto(r.monto), 'text-end') + td(r.contraparte); },
    },
    F: {
      res: function (r) { return td(r.folio + (r.cancelada ? ' (cancelada)' : '')) + td(fmtFecha(r.fecha)) + td(r.cliente) + td(fmtMonto(r.monto), 'text-end') + td(fmtMonto(r.pagada), 'text-end') + td(r.cancelada ? 'Cancelada' : '') + td(grupoTxt(r)); },
      sel: function (r) { return td(r.folio + (r.existe === false ? ' (ya no existe)' : '')) + td(fmtFecha(r.fecha)) + td(r.cliente) + td(fmtMonto(r.monto), 'text-end') + td(r.cancelada ? 'Cancelada' : ''); },
    },
  };
  var IDS = { C: ['#cmResCobros', '#cmSelCobros'], M: ['#cmResMovs', '#cmSelMovs'], F: ['#cmResFacturas', '#cmSelFacturas'] };

  function pintarResultados(tipo) {
    var html = res[tipo].map(function (r, i) {
      var ya = seleccionado(r);
      return '<tr>' + '<td><button type="button" class="btn btn-sm btn-outline-primary py-0 cm-add" data-tipo="' + tipo + '" data-i="' + i + '"' +
        (ya ? ' disabled' : '') + '>' + (ya ? 'Agregado' : 'Agregar') + '</button></td>' + COLS[tipo].res(r) + '</tr>';
    }).join('');
    $(IDS[tipo][0] + ' tbody').html(html || '<tr><td colspan="12" class="text-muted">Sin resultados</td></tr>');
  }
  function pintarSeleccion() {
    ['C', 'M', 'F'].forEach(function (tipo) {
      var html = sel[tipo].map(function (r, i) {
        return '<tr>' + COLS[tipo].sel(r) + '<td><button type="button" class="btn btn-sm btn-outline-danger py-0 cm-del" data-tipo="' + tipo + '" data-i="' + i +
          '" title="Quitar"><i class="fas fa-times"></i></button></td></tr>';
      }).join('');
      $(IDS[tipo][1] + ' tbody').html(html || '<tr><td colspan="8" class="text-muted">Nada seleccionado</td></tr>');
    });
  }
  function pintarSugeridos() {
    var box = $('#cmSugeridosBox');
    if (!box.length) return;
    var pend = sugeridos.filter(function (it) { return !seleccionado(it); });
    box.html(pend.length ? '<b>Sugeridos por el cruce:</b> ' + pend.map(function (r, i) {
      var txt = r.tipo === 'M' ? 'Depósito ' + (r.cuenta || '') + ' sec. ' + (r.secuencia || '') + ' $' + fmtMonto(r.monto)
        : (r.tipo === 'C' ? 'Cobro ' + r.nro : 'Factura ' + (r.folio || r.nro)) + ' $' + fmtMonto(r.monto);
      return '<button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1 cm-sug" data-k="' + esc(claveItem(r)) + '">' + esc(txt) + ' <i class="fas fa-plus"></i></button>';
    }).join('') : '');
  }

  // ------------------------------------------------------------------ preview / totales
  function listaPost() {
    var out = [];
    ['C', 'M', 'F'].forEach(function (t) {
      sel[t].forEach(function (it) {
        out.push({ tipo: it.tipo, codgas: it.codgas === undefined ? null : it.codgas, nro: it.nro === undefined ? null : it.nro, movimiento_id: it.movimiento_id === undefined ? null : it.movimiento_id });
      });
    });
    return out;
  }
  function preview() {
    var seq = ++previewSeq;
    if (!listaPost().length) {
      $('#cmTotales').empty(); $('#cmAlertas').empty();
      return;
    }
    $.ajax({ url: routeUrl('income.conciliation_manual_preview'), method: 'POST', dataType: 'text',
      data: filtros({ items: JSON.stringify(listaPost()), grupo_id: grupoId() }) })
      .done(function (txt) {
        if (seq !== previewSeq) return;
        var r = jsonDe(txt);
        if (!r || !r.totales) return;
        var t = r.totales;
        $('#cmTotales').html(
          '<span>Cobros: <b>$' + esc(fmtMonto(t.cobros)) + '</b></span>' +
          '<span>Facturas: <b>$' + esc(fmtMonto(t.facturas)) + '</b></span>' +
          '<span>Movimientos: <b>$' + esc(fmtMonto(t.movimientos)) + '</b></span>' +
          '<span>Diferencia: <b class="' + (Math.abs(Number(t.diferencia)) >= 0.01 ? 'text-danger' : '') + '">$' + esc(fmtMonto(t.diferencia)) + '</b></span>');
        $('#cmAlertas').html((r.alertas || []).map(function (a) {
          return '<div class="alert alert-warning py-1 px-2 mb-1 small">' + esc(a) + '</div>';
        }).join(''));
      })
      .fail(function (x) {
        if (seq !== previewSeq) return;
        var r = jsonDe(x.responseText);
        $('#cmAlertas').html('<div class="alert alert-danger py-1 px-2 mb-1 small">' + esc(r && r.error ? r.error : 'No se pudieron calcular los totales') + '</div>');
      });
  }

  // ------------------------------------------------------------------ buscadores
  var RUTAS = { C: 'income.conciliation_manual_search_payments', M: 'income.conciliation_manual_search_movements', F: 'income.conciliation_manual_search_invoices' };
  var FORMS = { C: '#cmBuscarCobros', M: '#cmBuscarMovs', F: '#cmBuscarFacturas' };
  function buscar(tipo) {
    var datos = filtros({}), seq = ++buscarSeq[tipo];
    $(FORMS[tipo]).serializeArray().forEach(function (f) { if (f.value !== '') datos[f.name] = f.value; });
    $(IDS[tipo][0] + ' tbody').html('<tr><td colspan="12" class="text-muted">Buscando…</td></tr>');
    $.ajax({ url: routeUrl(RUTAS[tipo]), method: 'POST', dataType: 'json', data: datos })
      .done(function (r) { if (seq !== buscarSeq[tipo]) return; res[tipo] = (r && r.data) || []; pintarResultados(tipo); })
      .fail(function (x, st) { if (seq !== buscarSeq[tipo] || st === 'abort') return; res[tipo] = []; pintarResultados(tipo); alertify.error('Error al buscar'); });
  }

  $(document).on('submit', '#cmBuscarCobros, #cmBuscarMovs, #cmBuscarFacturas', function (e) {
    e.preventDefault();
    var tipo = { cmBuscarCobros: 'C', cmBuscarMovs: 'M', cmBuscarFacturas: 'F' }[this.id];
    clearTimeout(timers[tipo]);
    buscar(tipo);
  });
  // Debounce de 300 ms al escribir
  $(document).on('input change', '#cmBuscarCobros, #cmBuscarMovs, #cmBuscarFacturas', function () {
    var tipo = { cmBuscarCobros: 'C', cmBuscarMovs: 'M', cmBuscarFacturas: 'F' }[this.id];
    clearTimeout(timers[tipo]);
    timers[tipo] = setTimeout(function () { buscar(tipo); }, 300);
  });
  $(document).on('click', '#cmRoot .cm-add', function () {
    var it = res[$(this).data('tipo')][$(this).data('i')];
    if (it) agregar(it);
  });
  $(document).on('click', '#cmRoot .cm-del', function () { quitar($(this).data('tipo'), $(this).data('i')); });
  $(document).on('click', '#cmRoot .cm-sug', function () {
    var k = String($(this).data('k'));
    sugeridos.forEach(function (it) { if (claveItem(it) === k) agregar(it); });
  });

  // ------------------------------------------------------------------ guardar / deshacer
  function enviar(ruta, datos, boton, okMsg) {
    $(boton).prop('disabled', true);
    $.ajax({ url: routeUrl(ruta), method: 'POST', dataType: 'text', timeout: 120000, data: filtros(datos) })
      .done(function (txt) {
        var r = jsonDe(txt);
        if (!r || !r.ok) {
          alertify.error(r && r.error ? esc(r.error) : 'Respuesta inesperada del servidor');
          return;
        }
        alertify.success(okMsg);
        $('#modalConciliacionManual').modal('hide');
        $(document).trigger('conciliacion:guardada');
      })
      .fail(function (x, st) {
        var r = jsonDe(x.responseText);
        if (st === 'timeout') alertify.error('No se sabe si se guardó (tiempo de espera agotado). Recarga la conciliación para verificar.');
        else alertify.error(r && r.error ? esc(r.error) : 'Error del servidor (' + (x.status || 'sin respuesta') + '); la conciliación no se guardó');
      })
      .always(function () { $(boton).prop('disabled', false); });
  }
  $(document).on('click', '#cmGuardar', function () {
    var items = listaPost();
    if (!items.length) { alertify.error('Elige al menos un cobro, movimiento o factura'); return; }
    enviar('income.conciliation_manual_save', { items: JSON.stringify(items), comentario: $('#cmComentario').val(), grupo_id: grupoId() }, this, 'Conciliación guardada');
  });
  $(document).on('click', '#cmDeshacer', function () {
    if (!confirm('¿Deshacer esta conciliación manual? Sus cobros vuelven al cruce automático.')) return;
    enviar('income.conciliation_manual_undo', { grupo_id: grupoId() }, this, 'Conciliación deshecha');
  });
  $(document).on('hidden.bs.modal', '#modalConciliacionManual', function () {
    limpiarTimers();
    ++buscarSeq.C; ++buscarSeq.M; ++buscarSeq.F;
    $('#modalConciliacionManualCuerpo').empty();
  });

  API.abrir = abrir;
})();
