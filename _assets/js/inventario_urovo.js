(function ($) {
  'use strict';
  $(function () {
    var $root = $('#urovoInventory');
    if (!$root.length) return;
    var stations = [], rows = [], currentTerminal = null, searchTimer, currentPage = 1, pageSize = 10, returnToSummary = false, dashboardLoading=false, modalReturnFocus=null, supportsPopover='popover' in HTMLElement.prototype;
    var labels = { estacion: 'En estación', almacen: 'Stock', reparacion: 'Reparación', perdida: 'Extraviado', baja_definitiva: 'Baja definitiva' };
    var conditionLabels = { operativa: 'Operativa', dañada: 'Dañada', desconocida: 'Desconocida', extraviada: 'Extraviada' };
    var damageTypes = [['pantalla_quebrada','Pantalla quebrada'],['pantalla_negra','Pantalla Negra'],['carcasa_exterior','Carcasa Exterior'],['puerto_carga_punta','Puerto de Carga (Punta)'],['usb_danado','USB dañado'],['falta_bateria','Falta batería'],['touch','Touch'],['modo_seguro','Modo Seguro'],['impresora','Impresora'],['tapa_impresora','Tapa de impresora'],['boton_encendido','Botón de encendido'],['tapa_bateria','Tapa de batería'],['lector_tarjetas','Lector de Tarjetas'],['otro','Otro']];
    function showModal(id) { var el=document.getElementById(id); if(!hasOpenModal())modalReturnFocus=document.activeElement; if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(el).show(); else $('#' + id).modal('show'); }
    function hideModal(id) { var el=document.getElementById(id); if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(el).hide(); else $('#' + id).modal('hide'); }
    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function escAttr(value) { return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function val(obj, keys) { for (var i = 0; i < keys.length; i++) if (obj && obj[keys[i]] != null) return obj[keys[i]]; return ''; }
    function conditionText(value) { return conditionLabels[value] || value || '—'; }
    function conditionClass(value) { return ({ operativa:'is-operativa', dañada:'is-dañada', desconocida:'is-desconocida', extraviada:'is-extraviada' })[value] || 'is-desconocida'; }
    function terminalType(value) { return String(value || 'urovo').toLowerCase() === 'verifone' ? 'verifone' : 'urovo'; }
    function terminalBadge(value) { var type=terminalType(value); return '<span class="terminal-type-badge is-'+type+'">'+(type==='verifone'?'VERIFONE':'UROVO')+'</span>'; }
    function damageListHtml(name, selected) { selected = Array.isArray(selected) ? selected : []; var other=selected.filter(function(v){return typeof v==='string'&&v.indexOf('otro:')===0;})[0]||'', checked=selected.indexOf('otro')>=0||!!other, description=other?other.slice(5).trim():''; return '<fieldset class="urovo-damage-list" hidden><legend>Daños detectados</legend><div>' + damageTypes.map(function (d) { return '<label><input type="checkbox" data-damage-type="' + escAttr(d[0]) + '" name="' + escAttr(name) + '"' + ((d[0]==='otro'?checked:selected.indexOf(d[0])>=0) ? ' checked' : '') + '> ' + esc(d[1]) + '</label>'; }).join('') + '</div><label class="urovo-damage-other"'+(checked?'':' hidden')+'><span>Describe el otro daño</span><input type="text" class="form-control form-control-sm" data-damage-other maxlength="500" value="'+escAttr(description)+'" placeholder="Escribe el daño" aria-label="Describe el otro daño"></label></fieldset>'; }
    function selectedDamages($scope) { var types=$scope.find('[data-damage-type]:checked').map(function () { return $(this).attr('data-damage-type'); }).get(), $other=$scope.find('[data-damage-type="otro"]:checked'); if($other.length){var text=String($scope.find('[data-damage-other]').val()||'').trim();if(text)types[types.indexOf('otro')]='otro: '+text;} return types; }
    function hasIncompleteOther($scope) { return $scope.find('[data-damage-type="otro"]:checked').length>0&&!String($scope.find('[data-damage-other]').val()||'').trim(); }
    function toggleDamage($select) { $select.closest('td, .urovo-condition-edit, .urovo-move-form').find('.urovo-damage-list').prop('hidden', $select.val() !== 'dañada'); }
    function toggleNoSerial($checkbox) { var $row=$checkbox.closest('tr'), enabled=$checkbox.is(':checked'), $serial=$row.find('[data-field="serial"]'); $row.toggleClass('is-no-serial',enabled); $serial.prop('disabled',enabled).prop('required',!enabled).val(enabled?'':$serial.val()).prop('hidden',enabled); $row.find('.urovo-no-serial-label').prop('hidden',!enabled); }
    function mojoLink(value) { var number = String(value || '').replace(/\D/g, ''); return number ? '<a href="https://totalgas.mojohelpdesk.com/mc/tickets/' + escAttr(number) + '" target="_blank" rel="noopener noreferrer">Abrir ticket Mojo #' + esc(number) + '</a>' : '—'; }
    function damageValues(row) { var v = val(row,['damage_types','damage_types_json']); if (typeof v === 'string') { try { v = JSON.parse(v); } catch (_) { v = []; } } return Array.isArray(v) ? v : []; }
    function damageNames(values) { return (values || []).map(function (key) { if(typeof key==='string'&&key.indexOf('otro:')===0)return 'Otro: '+key.slice(5).trim(); for (var i=0;i<damageTypes.length;i++) if (damageTypes[i][0]===key) return damageTypes[i][1]; return key; }).join(', '); }
    function damageDetails(values) { var names=damageNames(values); return names ? '<small class="urovo-damage-summary">Daños: '+esc(names)+'</small>' : ''; }
    function hasOpenModal() { return $('.modal.show').length > 0; }
    function stopToastTimer($toast) { var timer=$toast.data('toastTimer'); if(timer)window.clearTimeout(timer); $toast.removeData('toastTimer'); }
    function scheduleToastDismiss($toast) {
      var remaining=3000, started=Date.now();
      function startTimer() { if(!$toast[0].isConnected)return; started=Date.now(); $toast.data('toastTimer',window.setTimeout(function(){ if($toast[0].contains(document.activeElement)){remaining=0;return;} removeToast($toast,false); },remaining)); }
      function pauseTimer() { var timer=$toast.data('toastTimer'); if(timer){window.clearTimeout(timer);$toast.removeData('toastTimer');remaining=Math.max(0,remaining-(Date.now()-started));} }
      $toast.on('focusin',pauseTimer).on('focusout',function(){window.setTimeout(function(){if(!$toast[0].contains(document.activeElement)){if(remaining<=0)removeToast($toast,false);else startTimer();}},0);});
      startTimer();
    }
    function removeToast($toast,manual) {
      if(!$toast.length)return;
      var wasFocused=$toast[0].contains(document.activeElement), returnFocus=$toast.data('returnFocus'), $region=$toast.parent();
      stopToastTimer($toast); $toast.remove();
      if(!$region.find('.urovo-toast').length){var region=$region[0];if(supportsPopover&&typeof region.hidePopover==='function'&&region.matches(':popover-open'))region.hidePopover();$region.prop('hidden',true);}
      if(manual&&wasFocused&&returnFocus&&document.contains(returnFocus))$(returnFocus).trigger('focus');
    }
    function toast(text,type) {
      var item={text:String(text||''),type:type||'success'}, $modal=$('.modal.show').last();
      if(!item.text)return;
      var $region=$modal.length?$modal.find('.urovo-modal-toast-region').first():$('#urovoToastRegion'), region=$region[0];
      if(!$region.length){$region=$('#urovoToastRegion');region=$region[0];}
      var hadFocus=$region.find('.urovo-toast').toArray().some(function(el){return el.contains(document.activeElement);});
      $region.find('.urovo-toast').each(function(){stopToastTimer($(this));}); $region.empty().prop('hidden',false);
      if(!$modal.length&&supportsPopover&&typeof region.showPopover==='function'&&!region.matches(':popover-open'))region.showPopover();
      var $toast=$('<article class="urovo-toast" role="status"></article>').addClass(item.type==='error'?'is-error':(item.type==='warning'?'is-warning':'is-success'));
      var $text=$('<p class="urovo-toast-text"></p>').text(item.text), $close=$('<button type="button" class="urovo-toast-close" aria-label="Cerrar notificación">&times;</button>');
      $toast.append($text,$close).data('returnFocus',$modal.length?(modalReturnFocus||document.activeElement):(modalReturnFocus||(document.activeElement!==document.body?document.activeElement:null)));
      $region.append($toast); scheduleToastDismiss($toast);
      if(hadFocus)$close.trigger('focus');
      if(typeof $text[0].ariaNotify==='function'){try{$text[0].ariaNotify(item.text);}catch(_) {}}
    }
    $(document).on('click','.urovo-toast-close',function(){removeToast($(this).closest('.urovo-toast'),true);});
    $(document).on('hidden.bs.modal','#urovoBatchModal,#urovoDetailModal,#urovoSummaryModal',function(){
      $(this).find('.urovo-modal-toast-region .urovo-toast').each(function(){stopToastTimer($(this));});
      $(this).find('.urovo-modal-toast-region').empty().prop('hidden',true); modalReturnFocus=null;
    });
    function message(text,type) { toast(text,type); }
    function request(url, method, data) { if (data) data.csrf_token = $root.attr('data-csrf') || ''; return $.ajax({ url: url, method: method, contentType: 'application/json', dataType: 'json', data: data ? JSON.stringify(data) : undefined, headers: { 'X-CSRF-Token': $root.attr('data-csrf') || '' } }); }
    function selectedStation() { return $('#urovoStation').val() || ''; }
    function fillStations() { var options = '<option value="">Selecciona estación…</option>'; stations.forEach(function (s) { options += '<option value="' + escAttr(s.Codigo) + '">' + esc(s.Nombre) + '</option>'; }); $('#urovoBatchStation,#urovoMoveStation').html(options); var top = '<option value="">Todas las ubicaciones</option>'; stations.forEach(function (s) { top += '<option value="' + escAttr(s.Codigo) + '">' + esc(s.Nombre) + '</option>'; }); $('#urovoStation').html(top); }
    function locationText(row) { var type = val(row, ['ubicacion_tipo','location_type','LocationType', 'status', 'Status']); var name = val(row, ['estacion_nombre','station_name', 'NombreEstacion', 'station', 'proveedor','provider', 'location_name']); return (labels[type] || type || 'Sin ubicación') + (name ? ' · ' + name : ''); }
    function historyLocation(e, side) { var type=val(e,['ubicacion_'+side+'_tipo']); var station=val(e,[side+'_estacion_nombre','estacion_'+side+'_nombre',side+'_estacion_codigo','estacion_'+side+'_codigo']); var provider=val(e,['proveedor_'+side+'_nombre','proveedor_'+side]); var place=type==='estacion'?station:(type==='reparacion'?provider:''); return (labels[type]||type||'Sin ubicación')+(place?' · '+place:''); }
    function historyReason(raw) {
      if (!raw) return '';
      var text = String(raw), prefix = '', obj = null, start = text.indexOf('{');
      if (start >= 0) {
        prefix = text.slice(0, start).replace(/[;:\s]+$/, '').trim();
        try { obj = JSON.parse(text.slice(start)); } catch (_) { obj = null; }
      } else {
        try { obj = JSON.parse(text); } catch (_) { obj = null; }
      }
      if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return text;
      var names = { modelo:'Modelo', ip:'IP', mac:'MAC', imei:'IMEI', condition:'Condición', condicion:'Condición', fecha_compra:'Fecha de compra', fin_garantia:'Fin de garantía', accesorios:'Accesorios', notas:'Notas', damage_types:'Daños', damage_types_json:'Daños', mojo_ticket_number:'Ticket Mojo', 'Tipos de daño':'Daños', 'Ticket Mojo':'Ticket Mojo' };
      function damageText(value) {
        if (Array.isArray(value)) return damageNames(value);
        var str = String(value || '');
        try { var parsed = JSON.parse(str); if (Array.isArray(parsed)) return damageNames(parsed); } catch (_) {}
        return str || '—';
      }
      var details = Object.keys(obj).map(function (key) {
        var value = obj[key], isDamage = key.toLowerCase().indexOf('daño') >= 0 || key === 'damage_types' || key === 'damage_types_json';
        if (value && typeof value === 'object' && ('anterior' in value || 'nuevo' in value || 'old' in value || 'new' in value || 'from' in value || 'to' in value)) {
          var oldValue = value.anterior != null ? value.anterior : (value.old != null ? value.old : value.from);
          var newValue = value.nuevo != null ? value.nuevo : (value.new != null ? value.new : value.to);
          return (names[key] || key) + ': ' + (isDamage ? damageText(oldValue) : String(oldValue || '—')) + ' → ' + (isDamage ? damageText(newValue) : String(newValue || '—'));
        }
        return (names[key] || key) + ': ' + (isDamage ? damageText(value) : String(value));
      }).join('; ');
      return (prefix ? prefix + ': ' : '') + details;
    }
    function historyItem(e) { var rawType=val(e,['tipo_evento','event','action','movement','tipo'])||'movimiento'; var names={alta:'Alta',baja:'Baja',traslado:'Traslado',ajuste:'Ajuste',movimiento:'Movimiento'}; var from=historyLocation(e,'origen'), to=historyLocation(e,'destino'); var route=(from||to)?'<p>'+esc(from)+' → '+esc(to)+'</p>':''; return '<article class="urovo-event"><i></i><div><strong>'+esc(names[rawType]||rawType)+'</strong>'+route+'<p>'+esc(historyReason(val(e,['motivo','reason','cambios','descripcion','description'])||''))+'</p><small>'+esc(val(e,['fecha_evento','effective_date','fecha','created_at'])||'')+' · '+esc(val(e,['actor_nombre','actor','user','usuario'])||'Usuario no informado')+'</small></div></article>'; }
    function renderRows() {
      var total=rows.length, pages=Math.max(1,Math.ceil(total/pageSize));
      currentPage=Math.min(Math.max(1,currentPage),pages);
      var start=(currentPage-1)*pageSize, shown=rows.slice(start,start+pageSize);
      $('#urovoPageLabel').text(total ? 'Página '+currentPage+' de '+pages+' · '+total+' terminales' : 'Sin terminales');
      $('#urovoPagePrev').prop('disabled',currentPage<=1);
      $('#urovoPageNext').prop('disabled',currentPage>=pages);
      if (!shown.length) {
        $('#urovoRows').html('<tr><td colspan="6" class="urovo-empty">No hay terminales que coincidan con estos filtros.</td></tr>');
        return;
      }
      $('#urovoRows').html(shown.map(function(r){
        var id=val(r,['id','Id','terminal_id']), serial=val(r,['numero_serie','serial','Serie','serial_number']), model=val(r,['modelo','model','Modelo']);
        var status=val(r,['ubicacion_tipo','location_type','LocationType','status']), damages=damageValues(r), ticket=val(r,['mojo_ticket_number']);
        var condition=val(r,['condicion','condition','Condicion']);
        var movement=val(r,['last_movement','actualizado_en','moved_at','updated_at','FechaMovimiento'])||'—';
        return '<tr>'+
          '<td data-label="Serie / modelo"><strong>'+esc(serial||'Sin serie')+'</strong><small>'+esc(model||'Modelo no indicado')+'</small></td>'+
          '<td data-label="Tipo">'+terminalBadge(r.tipo_terminal)+'</td>'+
          '<td data-label="Ubicación"><span class="urovo-status '+(status==='reparacion'?'is-repair':'')+'">'+esc(locationText(r))+'</span></td>'+
          '<td data-label="Condición"><span class="urovo-condition '+conditionClass(condition)+'">'+esc(conditionText(condition))+'</span>'+damageDetails(damages)+(ticket?'<small class="urovo-ticket-summary">Ticket Mojo: '+mojoLink(ticket)+'</small>':'')+'</td>'+
          '<td data-label="Último movimiento">'+esc(movement)+'</td>'+
          '<td data-label="Acción"><button type="button" class="btn btn-sm btn-outline-primary js-urovo-detail" data-id="'+escAttr(id)+'" aria-label="Ver detalle de '+escAttr(serial||'terminal sin número de serie')+'">Ver detalle</button></td>'+
        '</tr>';
      }).join(''));
    }

    function renderSummaryDetail(detail) {
      var terminals = detail.terminals || [];
      var serialCount=terminals.filter(function(t){return !!t.numero_serie;}).length, noSerialCount=terminals.length-serialCount;
      var html = '<p class="urovo-summary-total">Con serie: <strong>' + esc(serialCount) + '</strong> · Sin serie: <strong>' + esc(noSerialCount) + '</strong> · Total: <strong>' + esc(terminals.length) + '</strong></p>';
      html += terminals.length ? '<div class="urovo-summary-scroll" tabindex="0" role="region" aria-label="Terminales registradas en esta estación"><table class="table urovo-summary-table"><caption class="sr-only">Terminales, condición y último movimiento de esta estación</caption><thead><tr><th scope="col">Serie / modelo</th><th scope="col">Tipo</th><th scope="col">Condición</th><th scope="col">Último movimiento</th><th scope="col">Acción</th></tr></thead><tbody>' + terminals.map(function (t) {
        var row = $.extend({}, t, { id:t.id, numero_serie:t.numero_serie, modelo:t.modelo, condicion:t.condicion, ubicacion_tipo:'estacion', estacion_nombre:detail.station && detail.station.station_name, last_movement:t.last_movement });
        return '<tr><td data-label="Serie / modelo"><strong>' + esc(t.numero_serie || 'Sin serie') + '</strong><small>' + esc(t.modelo || 'Modelo no indicado') + '</small></td><td data-label="Tipo">' + terminalBadge(t.tipo_terminal) + '</td><td data-label="Condición">' + esc(conditionText(t.condicion)) + '</td><td data-label="Último movimiento">' + esc(t.last_movement || '—') + '</td><td data-label="Acción"><button type="button" class="btn btn-sm btn-outline-primary js-summary-history" data-id="' + escAttr(t.id) + '" data-row="' + escAttr(JSON.stringify(row)) + '">Ver historial</button></td></tr>';
      }).join('') + '</tbody></table></div>' : '<p class="urovo-empty">No hay terminales registradas en esta estación.</p>';
      return html;
    }
    function loadSummary() {
      var $list = $('#urovoSummaryRows');
      $list.html('<p class="urovo-empty">Cargando resumen…</p>');
      request('/inventario-urovo/summary/', 'GET').done(function (data) {
        if (!data || data.success !== true || !Array.isArray(data.rows)) { $list.empty(); toast('No fue posible cargar el resumen.','error'); return; }
        if (!data.rows.length) { $list.html('<p class="urovo-empty">No hay estaciones para mostrar.</p>'); return; }
        var grandTotal = data.rows.reduce(function (sum, r) { return sum + (Number(r.total_count) || 0); }, 0);
        var conditions = ['operativa', 'dañada', 'desconocida', 'extraviada'];
        var conditionNames = { operativa:'Operativa', dañada:'Dañada', desconocida:'Desconocida', extraviada:'Extraviada' };
        var grandConditions = conditions.map(function (condition) {
          var total = data.rows.reduce(function (sum, r) { return sum + (Number((r.conditions || {})[condition]) || 0); }, 0);
          return '<article class="urovo-summary-condition-card is-' + escAttr(condition) + '"><span>' + esc(conditionNames[condition]) + '</span><strong>' + esc(total) + '</strong></article>';
        }).join('');
        $list.html('<section class="urovo-summary-overview" aria-label="Total de terminales en estaciones"><div class="urovo-summary-grand-total"><span>Total de terminales en estaciones</span><strong>' + esc(grandTotal) + '</strong></div><div class="urovo-summary-condition-grid" aria-label="Total por condición">' + grandConditions + '</div></section>' + data.rows.map(function (r, index) {
          var id = 'urovoSummaryDetail' + index;
          var breakdown = conditions.map(function (condition) { var counts = r.conditions || {}; return '<span class="urovo-condition-count is-' + escAttr(condition) + '"><span>' + esc(conditionNames[condition]) + '</span><strong>' + esc(counts[condition] == null ? 0 : counts[condition]) + '</strong></span>'; }).join('');
          return '<section class="urovo-summary-station"><div class="urovo-summary-station-head"><button type="button" class="urovo-summary-toggle" data-station="' + escAttr(r.station_id) + '" aria-controls="' + id + '" aria-expanded="false"><i class="fas fa-chevron-right" aria-hidden="true"></i><span>' + esc(r.station_name || 'Estación sin nombre') + '</span></button><div class="urovo-summary-counts"><span class="urovo-summary-total-count">Total <strong>' + esc(r.total_count) + '</strong></span><div class="urovo-summary-condition-counts" aria-label="Conteo por condición">' + breakdown + '</div></div></div><div class="urovo-summary-detail" id="' + id + '" hidden></div></section>';
        }).join(''));
      }).fail(function () { $list.empty(); toast('No se pudo cargar el resumen. Cierra y vuelve a abrir para intentar de nuevo.','error'); });
    }
    $('#urovoSummaryOpen').on('click', function () { showModal('urovoSummaryModal'); loadSummary(); });
    $('#dashboardTab').on('shown.bs.tab',function(){ $('#inventoryPanel').prop('hidden',true).attr('tabindex','-1'); $('#dashboardPanel').prop('hidden',false).attr('tabindex','0'); loadDashboard(); });
    $('#inventoryTab').on('shown.bs.tab',function(){ $('#dashboardPanel').prop('hidden',true).attr('tabindex','-1'); $('#inventoryPanel').prop('hidden',false).attr('tabindex','0'); });
    $('#urovoDashboardRefresh').on('click',loadDashboard); $('#urovoDashboardType').on('change',applyDashboardTypeFilter); $('#urovoDashboardExport').on('click',function(){window.location.href='/inventario-urovo/dashboard/export/?'+$.param({terminal_type:$('#urovoDashboardType').val()||'all'});}); applyDashboardTypeFilter();
    $('#urovoSummaryRows').on('click', '.urovo-summary-toggle', function () {
      var $button = $(this), $detail = $('#' + $button.attr('aria-controls'));
      if ($button.attr('aria-expanded') === 'true') { $button.attr('aria-expanded', 'false'); $detail.prop('hidden', true); return; }
      $button.attr('aria-expanded', 'true'); $detail.prop('hidden', false);
      if ($detail.data('loaded') || $detail.data('loading')) return;
      $detail.data('loading', true).html('<p class="urovo-summary-note">Cargando detalle…</p>');
      request('/inventario-urovo/summary/' + encodeURIComponent($button.data('station')) + '/', 'GET').done(function (detail) {
        if (!detail || detail.success !== true) { $detail.empty(); toast('No fue posible cargar el detalle de esta estación.','error'); return; }
        $detail.html(renderSummaryDetail(detail)).data('loaded', true);
      }).fail(function () { $detail.empty(); toast('Error al consultar el detalle. Vuelve a cerrar y desplegar esta estación para reintentar.','error'); }).always(function () { $detail.data('loading', false); });
    });
    $('#urovoDetailModal').on('hidden.bs.modal',function(){if(returnToSummary){returnToSummary=false;showModal('urovoSummaryModal');}});
    $('#urovoSummaryRows').on('click', '.js-summary-history', function () { var row = {}, id=$(this).attr('data-id'); try { row = JSON.parse($(this).attr('data-row') || '{}'); } catch (_) {} returnToSummary=true; $('#urovoSummaryModal').one('hidden.bs.modal',function(){openDetail(id,row);}); hideModal('urovoSummaryModal'); });
    function load() {
      var stationId=selectedStation(); var params = $.param({ station_id: stationId, status: $('#urovoStatus').val(), condition: $('#urovoCondition').val(), terminal_type: $('#urovoType').val(), q: $('#urovoSearch').val() });
      request('/inventario-urovo/data/?' + params, 'GET').done(function (data) { if (!data || data.success !== true) { message('No fue posible cargar el inventario.', 'error'); return; } stations = data.stations || []; rows = data.rows || []; fillStations(); $('#urovoStation').val(stationId); renderRows(); $('#urovoResultCount').text(rows.length + (rows.length === 1 ? ' terminal' : ' terminales')); }).fail(function () { $('#urovoRows').html('<tr><td colspan="6" class="urovo-empty">No se pudo cargar el inventario. Actualiza para intentar de nuevo.</td></tr>'); message('Error al consultar el inventario.', 'error'); });
    }
    function renderDashboard(data) {
      var entries=data.rows||[];
      var totals=data.totals||{}, urovo=totals.urovo||{}, verifone=totals.verifone||{};
      function cells(type,row){var item=row[type]||{},objective=Number(item.objetivo)||0,functioning=Number(item.funcionando)||0,difference=Number(item.diferencia)||0,low=objective>0&&functioning*2<=objective;return '<td class="text-right '+type+'-column">'+esc(objective)+'</td><td class="text-right '+type+'-column'+(low?' is-low':'')+'"><span>'+esc(functioning)+'</span>'+(low?'<small class="urovo-dashboard-risk"><i class="fas fa-exclamation-circle" aria-hidden="true"></i> Bajo el mínimo</small>':'')+'</td><td class="text-right '+type+'-column '+(difference<0?'is-short':(difference>0?'is-surplus':''))+'">'+(difference>0?'+':'')+esc(difference)+'</td>';}
      $('#urovoDashboardRows').html(entries.length?entries.map(function(row){return '<tr><th scope="row">'+esc(row.station_name)+'</th>'+cells('urovo',row)+cells('verifone',row)+'</tr>';}).join(''):'<tr><td colspan="7" class="urovo-empty">No hay objetivos para mostrar.</td></tr>');
      function updateCards(label,data){var objective=Number(data.total_objetivo)||0,functioning=Number(data.total_funcionando)||0,stock=Number(data.total_stock)||0,repair=Number(data.total_reparacion)||0;
        $('#urovoDashboard'+label+'StationMissing').text(Number(data.faltante_en_estacion)||0);
        $('#urovoDashboard'+label+'StationBreakdown').text('Objetivo de '+label+' (suma de estaciones): '+objective+' · Funcionando ('+label+' Operativas en estaciones): '+functioning);
        $('#urovoDashboard'+label+'TotalMissing').text(Number(data.faltante_total)||0);
        $('#urovoDashboard'+label+'TotalBreakdown').text('Funcionando '+label+': '+functioning+' + Stock '+label+': '+stock+' + Reparación '+label+': '+repair+' − Objetivo '+label+': '+objective);
      }
      updateCards('Urovo',urovo); updateCards('Verifone',verifone);
      function totalCells(type,data){var objective=Number(data.total_objetivo)||0,functioning=Number(data.total_funcionando)||0,difference=functioning-objective;return '<td class="text-right '+type+'-column">'+esc(objective)+'</td><td class="text-right '+type+'-column">'+esc(functioning)+'</td><td class="text-right '+type+'-column '+(difference<0?'is-short':(difference>0?'is-surplus':''))+'">'+(difference>0?'+':'')+esc(difference)+'</td>';}
      $('#urovoDashboardTotalsRow').html('<tr><th scope="row">Total</th>'+totalCells('urovo',urovo)+totalCells('verifone',verifone)+'</tr>');
      applyDashboardTypeFilter();
    }
    function applyDashboardTypeFilter(){var selected=$('#urovoDashboardType').val()||'all';$('.urovo-dashboard-card').each(function(){$(this).toggle(selected==='all'||$(this).data('terminal-type')===selected);});$('.urovo-column').toggleClass('is-type-hidden',selected==='verifone');$('.verifone-column').toggleClass('is-type-hidden',selected==='urovo');}
    function loadDashboard() {
      if(dashboardLoading)return; dashboardLoading=true;
      $('#urovoDashboardRows').html('<tr><td colspan="7" class="urovo-empty">Cargando dashboard…</td></tr>');
      request('/inventario-urovo/dashboard/','GET').done(function(data){if(!data||data.success!==true){$('#urovoDashboardRows').html('<tr><td colspan="7" class="urovo-empty">No hay datos para mostrar.</td></tr>');toast('No fue posible cargar el dashboard.','error');return;}renderDashboard(data);}).fail(function(){$('#urovoDashboardRows').html('<tr><td colspan="7" class="urovo-empty">No hay datos para mostrar.</td></tr>');toast('Error al consultar el dashboard. Actualiza para intentar de nuevo.','error');}).always(function(){dashboardLoading=false;});
    }
    function captureRow() { var fields = [['serial','Serie'],['terminal_type','Tipo de terminal'],['model','Modelo'],['condition','Condición'],['ip','IP'],['mac','MAC'],['imei','IMEI'],['purchase_date','Compra'],['warranty_until','Garantía'],['accessories','Accesorios'],['notes','Notas'],['mojo_ticket_number','Ticket Mojo']]; return '<tr><td class="urovo-capture-no-serial" data-label="Sin serie"><label class="urovo-check"><input type="checkbox" data-no-serial aria-label="Registrar terminal sin número de serie"> Sin número de serie</label></td>' + fields.map(function (field, ix) { var name=field[0], label=field[1], advanced=ix>3?' urovo-advanced-cell':''; if (name === 'terminal_type') return '<td class="urovo-capture-field" data-label="'+label+'"><select class="form-control form-control-sm" data-field="terminal_type" aria-label="Tipo de terminal"><option value="urovo" selected>Urovo</option><option value="verifone">Verifone</option></select></td>'; if (name === 'condition') return '<td class="urovo-capture-field'+advanced+'" data-label="'+label+'"><select class="form-control form-control-sm" data-field="condition" aria-label="Condición"><option value="desconocida" selected>Desconocida</option><option value="operativa">Operativa</option><option value="dañada">Dañada</option><option value="extraviada">Extraviada</option></select>'+damageListHtml('batch_damage_types', [])+'</td>'; var type = name === 'purchase_date' || name === 'warranty_until' ? 'date' : 'text'; var value = name === 'model' ? ' value="I9000S"' : ''; var numeric = name === 'mojo_ticket_number' ? ' inputmode="numeric" pattern="[0-9]*" maxlength="30"' : ''; var serial=name==='serial'; return '<td class="urovo-capture-field' + advanced + (serial?' urovo-serial-cell':'') + '" data-label="' + escAttr(label) + '"><input class="form-control form-control-sm" data-field="' + escAttr(name) + '" type="' + escAttr(type) + '"' + numeric + value + (serial?' required':'') + ' aria-label="' + escAttr(label) + '" placeholder="' + escAttr(label) + '">'+(serial?'<span class="urovo-no-serial-label" hidden>Terminal sin número de serie</span>':'')+'</td>'; }).join('') + '<td class="urovo-capture-options"><button type="button" class="btn btn-link btn-sm js-row-options" aria-expanded="false">Campos opcionales</button></td><td class="urovo-capture-remove"><button type="button" class="urovo-remove-row" aria-label="Quitar fila">×</button></td></tr>'; }
    function batchLocation() { var provider = $('#urovoBatchLocation').val() === 'reparacion'; $('.urovo-provider-field').prop('hidden', !provider); $('.urovo-batch-station').prop('hidden', $('#urovoBatchLocation').val() !== 'estacion'); }
    function moveLocation() { var type = $('#urovoMoveLocation').val(); $('.urovo-move-station').prop('hidden', type !== 'estacion'); $('.urovo-move-provider').prop('hidden', type !== 'reparacion'); }
    function locationPayload(prefix, type) { var d = { location_type: type }; var st = $('#' + prefix + 'Station').val(); var provider = $('#' + prefix + 'Provider').val(); if (type === 'estacion') d.station_id = st; if (type === 'reparacion') d.provider = provider; return d; }
    function openDetail(id, rowOverride, successNotice) { currentTerminal=id; request('/inventario-urovo/terminal/'+encodeURIComponent(id)+'/history/','GET').done(function(data){var row=$.extend({},rows.filter(function(r){return String(val(r,['id','Id','terminal_id']))===String(id);})[0]||{},rowOverride||{}), damages=damageValues(row), ticket=val(row,['mojo_ticket_number']),serial=val(row,['numero_serie','serial','Serie','serial_number']); $('#urovoDetailTitle').text(serial||'Terminal sin número de serie'); $('#urovoDetailMeta').text(locationText(row)); var fields=[['terminal_type','Tipo de terminal',terminalType(row.tipo_terminal)],['model','Modelo',val(row,['modelo','model','Modelo'])],['ip','IP',val(row,['ip','IP'])],['mac','MAC',val(row,['mac','MAC'])],['imei','IMEI',val(row,['imei','IMEI'])],['purchase_date','Compra',val(row,['fecha_compra','purchase_date','FechaCompra'])],['warranty_until','Garantía',val(row,['fin_garantia','warranty_until','FinGarantia'])],['condition','Condición',val(row,['condicion','condition','Condicion'])],['accessories','Accesorios',val(row,['accesorios','accessories','Accesorios'])],['notes','Notas',val(row,['notas','notes','Notas'])]]; $('#urovoDetailFields').html(fields.map(function(f){if(f[0]==='terminal_type')return '<label><small>'+esc(f[1])+'</small><select class="form-control form-control-sm js-urovo-edit" data-field="terminal_type"><option value="urovo">Urovo</option><option value="verifone">Verifone</option></select></label>';if(f[0]==='condition')return '<div class="urovo-condition-edit"><label><small>'+esc(f[1])+'</small><select class="form-control form-control-sm js-urovo-edit" data-field="condition"><option value="operativa">Operativa</option><option value="dañada">Dañada</option><option value="desconocida">Desconocida</option><option value="extraviada">Extraviada</option></select></label>'+damageListHtml('detail_damage_types',damages)+'</div>';return '<label><small>'+esc(f[1])+'</small><input class="form-control form-control-sm js-urovo-edit" data-field="'+escAttr(f[0])+'" value="'+escAttr(f[2])+'"></label>';}).join('')+'<label><small>Ticket Mojo</small><input class="form-control form-control-sm js-urovo-edit" data-field="mojo_ticket_number" inputmode="numeric" pattern="[0-9]*" maxlength="30" value="'+escAttr(ticket)+'"><span class="urovo-ticket-link">'+mojoLink(ticket)+'</span></label><div class="urovo-edit-action"><button type="button" class="btn btn-sm btn-outline-primary" id="urovoSaveEdit">Guardar datos</button></div>'); $('#urovoDetailFields [data-field="terminal_type"]').val(terminalType(row.tipo_terminal)); $('#urovoDetailFields [data-field="condition"]').val(val(row,['condicion','condition','Condicion'])||'desconocida'); toggleDamage($('#urovoDetailFields [data-field="condition"]')); var h=data.history||[]; $('#urovoHistory').html(h.length?h.map(historyItem).join(''):'<p class="urovo-empty">Sin movimientos registrados.</p>'); $('#urovoMoveStation').val(selectedStation()); $('#urovoMoveReason').val(''); $('#urovoMoveStatus').val(val(row,['condicion','condition','Condicion'])||'desconocida'); $('#urovoMoveStatus').trigger('change'); $('#urovoMoveDamage').remove(); $('.urovo-move-form .urovo-damage-list').remove(); $('.urovo-move-form .row #urovoMoveReason').closest('.col-12').before('<div class="col-12" id="urovoMoveDamage"></div>'); $('#urovoMoveDamage').html(damageListHtml('move_damage_types',damages));  toggleDamage($('#urovoMoveStatus')); $('#urovoMoveTicket').attr('maxlength','30').val(ticket); $('#urovoMoveTicketLink').html(mojoLink(ticket)); showModal('urovoDetailModal'); if(successNotice)toast(successNotice,'success');}).fail(function(){if(returnToSummary){returnToSummary=false;showModal('urovoSummaryModal');}message('No se pudo cargar el historial.','error');}); }

    $('#urovoRefresh').on('click', load); $('#urovoStation,#urovoStatus,#urovoCondition,#urovoType').on('change', function(){currentPage=1;load();}); $('#urovoSearch').on('input', function () { currentPage=1;clearTimeout(searchTimer); searchTimer = setTimeout(load, 250); }); $('#urovoPagePrev').on('click',function(){if(currentPage>1){currentPage--;renderRows();}}); $('#urovoPageNext').on('click',function(){if(currentPage<Math.ceil(rows.length/pageSize)){currentPage++;renderRows();}});
    $('#urovoAddBatch').on('click', function () { $('#urovoCaptureRows').html(captureRow()).removeClass('show-advanced'); $('.urovo-capture').removeClass('show-advanced'); $('#urovoToggleAdvanced').attr('aria-expanded','false').text('Mostrar campos opcionales'); $('#urovoBatchStation').val(selectedStation()); batchLocation(); showModal('urovoBatchModal'); });
    function batchMessage(text,type) { toast(text,type); }
    function modalMessage(id,text,type) { toast(text,type); }
    $('#urovoAddRow').on('click', function () { var advancedOpen=$('#urovoToggleAdvanced').attr('aria-expanded')==='true';var $row=$(captureRow()).toggleClass('show-advanced',advancedOpen);$row.find('.js-row-options').attr('aria-expanded',String(advancedOpen)).text(advancedOpen?'Ocultar campos opcionales':'Campos opcionales');$('#urovoCaptureRows').append($row);$('#urovoCaptureRows tr:last [data-field="serial"]').trigger('focus'); });
    $('#urovoCaptureRows').on('click','.js-row-options',function(){var $button=$(this),$row=$button.closest('tr'),open=!$row.hasClass('show-advanced');$row.toggleClass('show-advanced',open);$button.attr('aria-expanded',String(open)).text(open?'Ocultar campos opcionales':'Campos opcionales');var allOpen=$('#urovoCaptureRows tr').length>0&&$('#urovoCaptureRows tr').filter(function(){return !$(this).hasClass('show-advanced');}).length===0;$('#urovoToggleAdvanced').attr('aria-expanded',String(allOpen)).text(allOpen?'Ocultar campos opcionales':'Mostrar campos opcionales');$('#urovoCaptureRows').toggleClass('show-advanced',allOpen);$('.urovo-capture').toggleClass('show-advanced',allOpen);});
    $('#urovoCaptureRows').on('change','[data-no-serial]',function(){toggleNoSerial($(this));});
    $('#urovoCaptureRows,#urovoDetailFields,.urovo-move-form').on('change','[data-damage-type="otro"]',function(){var $box=$(this),$field=$box.closest('.urovo-damage-list').find('[data-damage-other]'),active=$box.is(':checked');$field.closest('.urovo-damage-other').prop('hidden',!active);$field.prop('required',active);if(!active)$field.val('');});
    $('#urovoToggleAdvanced').on('click', function () { var show = $(this).attr('aria-expanded') !== 'true'; $(this).attr('aria-expanded', String(show)).text(show ? 'Ocultar campos opcionales' : 'Mostrar campos opcionales'); $('#urovoCaptureRows tr').toggleClass('show-advanced', show); $('#urovoCaptureRows .js-row-options').attr('aria-expanded', String(show)).text(show ? 'Ocultar campos opcionales' : 'Campos opcionales'); $('#urovoCaptureRows,.urovo-capture').toggleClass('show-advanced', show); });
    $('#urovoCaptureRows').on('click', '.urovo-remove-row', function () { if ($('#urovoCaptureRows tr').length > 1) $(this).closest('tr').remove(); else $(this).closest('tr').find('input').val(''); });
    $('#urovoBatchLocation').on('change', batchLocation); $('#urovoCaptureRows').on('change','[data-field=condition]',function(){toggleDamage($(this));}); $('#urovoMoveStatus').on('change',function(){toggleDamage($(this));}); $('#urovoDetailFields').on('change','[data-field=condition]',function(){toggleDamage($(this));}); $('#urovoDetailFields').on('input','[data-field=mojo_ticket_number]',function(){var n=String($(this).val()||'').replace(/\D/g,'');$('#urovoDetailFields .urovo-ticket-link').html(mojoLink(n));}); $('#urovoMoveTicket').on('input',function(){var n=String($(this).val()||'').replace(/\D/g,'');$('#urovoMoveTicketLink').html(mojoLink(n));});
    $('#urovoSaveBatch').on('click', function () { var invalidOther=false; $('#urovoCaptureRows tr').each(function(){if(hasIncompleteOther($(this)))invalidOther=true;}); if(invalidOther){batchMessage('Describe el problema marcado como “Otro”.','error');return;} var items = $('#urovoCaptureRows tr').map(function () { var $row=$(this), item = {}; $row.find('[data-field]').each(function () { item[$(this).data('field')] = $(this).val(); }); item.no_serial=$row.find('[data-no-serial]').is(':checked'); item.serial=item.no_serial?'':String(item.serial||'').trim(); item.damage_types=selectedDamages($row); item.mojo_ticket_number=String(item.mojo_ticket_number||'').replace(/\D/g,''); return item; }).get().filter(function (i) { return i.no_serial || i.serial !== ''; }); if (!items.length) { batchMessage('Agrega al menos una terminal.', 'error'); return; } var type = $('#urovoBatchLocation').val(); var payload = locationPayload('urovoBatch', type); payload.items = items; $('#urovoSaveBatch').prop('disabled', true); request('/inventario-urovo/batch/', 'POST', payload).done(function (d) { $('#urovoSaveBatch').prop('disabled', false); if (!d || d.success !== true) { batchMessage(d && d.message || 'No fue posible guardar los equipos.', 'error'); return; } var inserted=d.inserted||[], skipped=d.skipped||[], summary=inserted.length+' equipos guardados.'; if(skipped.length)summary+=' Omitidos: '+skipped.map(function(x){return (x.serial||'Sin serie')+' ('+(x.reason||'duplicado')+')';}).join(', '); $('#urovoBatchModal').one('hidden.bs.modal',function(){toast(summary,skipped.length?'warning':'success');}); load(); hideModal('urovoBatchModal'); }).fail(function (xhr) { $('#urovoSaveBatch').prop('disabled', false); batchMessage(xhr.responseJSON && xhr.responseJSON.message || 'Error al guardar los equipos.', 'error'); }); });
    $('#urovoRows').on('click', '.js-urovo-detail', function () { openDetail($(this).data('id')); });
    $('#urovoDetailFields').on('click', '#urovoSaveEdit', function () { if(hasIncompleteOther($('#urovoDetailFields'))){modalMessage('#urovoDetailNotice','Describe el problema marcado como “Otro”.','error');return;} var payload = {}; $('#urovoDetailFields .js-urovo-edit').each(function () { payload[$(this).data('field')] = $(this).val(); }); payload.damage_types=selectedDamages($('#urovoDetailFields')); payload.mojo_ticket_number=String(payload.mojo_ticket_number||'').replace(/\D/g,''); request('/inventario-urovo/terminal/' + encodeURIComponent(currentTerminal) + '/edit/', 'POST', payload).done(function (d) { if (!d || d.success !== true) { modalMessage('#urovoDetailNotice',d && d.message || 'No fue posible guardar los datos.','error'); return; } var current = rows.filter(function (r) { return String(val(r,['id','Id','terminal_id'])) === String(currentTerminal); })[0] || {}; var updated = $.extend({},current,payload,d.row || {}); openDetail(currentTerminal,updated,'Datos actualizados.'); load(); }).fail(function () { modalMessage('#urovoDetailNotice','Error al actualizar los datos.','error'); }); });
    $('#urovoMoveLocation').on('change', moveLocation); $('#urovoSaveMove').on('click', function () { if(hasIncompleteOther($('.urovo-move-form'))){modalMessage('#urovoDetailNotice','Describe el problema marcado como “Otro”.','error');return;} var type = $('#urovoMoveLocation').val(), payload = locationPayload('urovoMove', type); payload.status = $('#urovoMoveStatus').val(); payload.damage_types=selectedDamages($('.urovo-move-form')); payload.mojo_ticket_number=String($('#urovoMoveTicket').val()||'').replace(/\D/g,''); payload.reason = $('#urovoMoveReason').val(); if (!payload.reason.trim() || (type === 'estacion' && !payload.station_id) || (type === 'reparacion' && !payload.provider.trim())) { modalMessage('#urovoDetailNotice','Completa destino y motivo del movimiento.','error'); return; } request('/inventario-urovo/terminal/' + encodeURIComponent(currentTerminal) + '/move/', 'POST', payload).done(function (d) { if (!d || d.success !== true) { modalMessage('#urovoDetailNotice',d && d.message || 'No fue posible registrar el movimiento.','error'); return; } $('#urovoDetailModal').one('hidden.bs.modal',function(){message('Movimiento registrado.', 'success');}); hideModal('urovoDetailModal'); load(); }).fail(function () { modalMessage('#urovoDetailNotice','Error al registrar el movimiento.','error'); }); });
    load();
  });
})(jQuery);
