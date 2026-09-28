$(function () {
  const app=$('#terminalInventoryApp'); if(!app.length)return;
  const types=JSON.parse(app.attr('data-types')),expected=JSON.parse(app.attr('data-expected-counts')||'{}');
  let active=JSON.parse(app.attr('data-active')||'[]'),requestKey=null,resolvedType=null,selectedCloseId=null,detailIncident=null;
  const modal=new bootstrap.Modal(document.getElementById('terminalIncidentModal'));
  const resolvedModal=new bootstrap.Modal(document.getElementById('terminalResolvedModal'));
  const detailModal=new bootstrap.Modal(document.getElementById('terminalTicketDetailModal'));
  const esc=value=>$('<div>').text(value??'').html();
  function newRequestKey(){if(window.crypto?.randomUUID)return window.crypto.randomUUID();const bytes=new Uint8Array(16);if(window.crypto?.getRandomValues)window.crypto.getRandomValues(bytes);else for(let i=0;i<bytes.length;i++)bytes[i]=Math.floor(Math.random()*256);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');}
  $('#printTerminalRegister').on('click',()=>window.print());
  function statusOf(i){return i.estado_local||i.estado_mojo||'Abierta';}
  function solvedFor(type){return active.filter(i=>i.tipo_terminal===type&&statusOf(i)==='Solved');}
  function openDetail(id){
    detailIncident=active.find(i=>Number(i.id)===Number(id)); if(!detailIncident)return;
    $('#ticketReplyForm').addClass('d-none')[0].reset();
    $('#ticketDetailBody').html('<div class="terminal-detail-loading"><span class="spinner-border spinner-border-sm" role="status"></span> Cargando información y respuestas…</div>');
    $('#ticketDetailActions').empty(); detailModal.show();
    $.getJSON('/operations/terminal_incident_detail',{incident_id:id}).done(r=>{
      if(!r.success)throw new Error(r.message||'No fue posible cargar el ticket.');
      const i=r.incident,t=r.ticket,comments=r.comments||[],hist=r.state_history||[];
      const fields=[['Estación',i.station],['Estado en portal',i.state],['Estado en Mojo',t.status],['Solicitante',t.requester],['Responsable',t.assignee],['Prioridad',t.priority],['Apertura',t.created||i.opened],['Última actualización',t.updated],['Vencimiento',t.due],['Serie',i.serial],['Folio proveedor',i.provider_folio],['Fecha reporte',i.provider_date]];
      let html='<div class="terminal-ticket-hero"><div><span class="terminal-detail-kicker">'+esc(types[i.type]?.label||i.type)+' · Ticket #'+Number(i.ticket_id)+'</span><h4>'+esc(t.title||i.description||'Incidencia de terminal')+'</h4><span class="terminal-ticket-state">'+esc(i.state)+'</span></div></div><div class="terminal-detail-grid">';
      fields.forEach(([label,value])=>{if(value)html+='<div><span>'+esc(label)+'</span><strong>'+esc(value)+'</strong></div>';});
      html+='</div><section class="terminal-detail-section"><h6>Descripción del reporte</h6><p>'+esc(t.description||i.description||'Sin descripción.')+'</p></section>';
      if(t.resolution)html+='<section class="terminal-detail-section"><h6>Resolución registrada</h6><p>'+esc(t.resolution)+'</p></section>';
      if(t.custom_fields?.length)html+='<section class="terminal-detail-section"><h6>Información adicional</h6><dl class="terminal-detail-list">'+t.custom_fields.map(f=>'<div><dt>'+esc(f.label)+'</dt><dd>'+esc(f.value)+'</dd></div>').join('')+'</dl></section>';
      if(t.attachments?.length)html+='<section class="terminal-detail-section"><h6>Archivos</h6><ul class="terminal-ticket-attachments">'+t.attachments.map(f=>'<li>'+(f.url?'<a href="'+esc(f.url)+'" target="_blank" rel="noopener">'+esc(f.name)+'</a>':esc(f.name))+'</li>').join('')+'</ul></section>';
      html+='<section class="terminal-detail-section"><h6>Conversación pública <span class="terminal-comment-count">'+comments.length+'</span></h6>';
      if(comments.length)html+='<div class="terminal-comment-timeline">'+comments.map(c=>'<article class="terminal-comment"><div class="terminal-comment-heading"><strong>'+esc(c.related_data?.user?.full_name||c.user?.full_name||c.user_name||c.author||'Usuario')+'</strong><time>'+esc(c.created_on||c.created_at||'')+'</time></div><p>'+esc(c.body||c.comment||'')+'</p></article>').join('')+'</div>';
      else html+='<p class="text-muted">Aún no hay respuestas públicas.</p>';
      html+='</section>';
      if(hist.length)html+='<section class="terminal-detail-section"><h6>Actividad del portal</h6><div class="terminal-state-history">'+hist.map(h=>'<div class="terminal-state-history-item"><strong>'+esc(h.estado_anterior||'Creado')+' → '+esc(h.estado_nuevo)+'</strong><small>'+esc(h.fecha_registro||h.fecha_estado_mojo||'')+' · '+esc(h.usuario_correo||h.origen||'')+'</small>'+(h.comentario?'<p>'+esc(h.comentario)+'</p>':'')+'</div>').join('')+'</div></section>';
      $('#ticketDetailBody').html(html); renderDetailActions(i);
    }).fail(x=>$('#ticketDetailBody').html('<div class="alert alert-danger mb-0">'+esc(x.responseJSON?.message||'No se pudo cargar el detalle del ticket.')+'</div>'));
  }
  function renderDetailActions(i){
    const state=i.state;let buttons='';
    if(state==='Solved')buttons+='<button type="button" class="btn btn-outline-primary" id="reopenTicket">Reabrir ticket</button><button type="button" class="btn btn-outline-success" id="confirmCloseFromDetail">Cerrar ticket</button>';
    if(['Abierta','Reabierta','Solved'].includes(state))buttons+='<button type="button" class="btn btn-primary" id="showTicketReply">Responder'+(state==='Solved'?' y reabrir':'')+'</button>';
    $('#ticketDetailActions').html(buttons);
  }
  function renderResolvedModal(){
    if(!resolvedType)return;
    const list=$('#terminalResolvedList').empty(),solved=solvedFor(resolvedType);selectedCloseId=null;$('#terminalCloseConfirmation').addClass('d-none');$('#resolvedTypeLabel').text(types[resolvedType]?.label||resolvedType);
    if(!solved.length){list.append('<p class="text-muted mb-0">No hay tickets resueltos pendientes de cierre para este tipo.</p>');return;}
    solved.forEach(i=>list.append('<div class="terminal-resolved-ticket"><div><strong class="terminal-ticket-link">Ticket #'+Number(i.ticket_mojo_id)+'</strong><p class="mb-0">'+esc(i.descripcion)+'</p>'+(i.serial_urovo?'<small>Serie: '+esc(i.serial_urovo)+'</small>':'')+'</div><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-primary view-ticket" data-id="'+Number(i.id)+'">Ver detalles</button><button type="button" class="btn btn-sm btn-outline-success select-resolved" data-id="'+Number(i.id)+'">Cerrar</button></div></div>'));
  }
  function selectResolved(id){const incident=solvedFor(resolvedType).find(i=>Number(i.id)===id);if(!incident)return;selectedCloseId=id;$('#terminalCloseTicket').text('#'+Number(incident.ticket_mojo_id));$('#terminalCloseConfirmation').removeClass('d-none');$('#confirmResolvedClose').trigger('focus');}
  function refresh(){
    let totalStock=0,totalDamaged=0,totalWorking=0,totalSolved=0;
    Object.keys(types).forEach(type=>{const count=active.filter(i=>i.tipo_terminal===type&&statusOf(i)!=='Closed').length,solved=solvedFor(type).length,stock=Math.max(0,Number(expected[type])||0),working=Math.max(0,stock-count);totalStock+=stock;totalDamaged+=count;totalWorking+=working;totalSolved+=solved;$('[data-damaged="'+type+'"]').text(count);$('[data-working="'+type+'"]').text(working);$('[data-solved="'+type+'"]').text(solved).toggleClass('has-solved',solved>0);$('.show-resolved[data-type="'+type+'"]').prop('disabled',solved===0);$('.add-incident[data-type="'+type+'"]').prop('disabled',count>=stock);});
    $('#terminalStockTotal').text(totalStock);$('#terminalDamagedTotal').text(totalDamaged);$('#terminalWorkingTotal').text(totalWorking);$('#terminalSolvedTotal').text(totalSolved);
    const list=$('#terminalIncidentList').empty(),open=active.filter(i=>['Abierta','Reabierta'].includes(statusOf(i)));
    list.append('<h6>Abiertas</h6>');
    if(!open.length)list.append('<p class="text-muted">No hay incidencias abiertas.</p>');
    open.forEach(i=>list.append('<div class="terminal-incident active mb-2"><div><strong>'+esc(types[i.tipo_terminal]?.label||i.tipo_terminal)+'</strong> · <span class="terminal-ticket-link">#'+Number(i.ticket_mojo_id)+'</span> <span class="badge bg-warning text-dark">'+esc(statusOf(i))+'</span><div>'+esc(i.descripcion)+'</div></div><button type="button" class="btn btn-sm btn-outline-primary view-ticket" data-id="'+Number(i.id)+'">Ver ticket</button></div>'));
    renderResolvedModal();if(window.feather)feather.replace();
  }
  $('.add-incident').on('click',function(){const type=$(this).data('type');$('#terminalIncidentForm')[0].reset();$('#incidentType').val(type);$('#incidentTypeLabel').text(types[type].label);$('#urovoSerialField').toggleClass('d-none',type!=='urovo');$('#verifoneProblemField').toggleClass('d-none',type!=='verifone');$('.valera-fields').toggleClass('d-none',['urovo','verifone'].includes(type));requestKey=newRequestKey();modal.show();});
  $('#terminalIncidentForm').on('submit',function(e){e.preventDefault();const type=$('#incidentType').val(),description=$('#incidentDescription').val().trim(),serial=$('#urovoSerial').val().trim(),problem=type==='urovo'?'Terminal Urovo':$('#verifoneProblem').val(),button=$('#confirmIncident');if(!description){toastr.error('La descripción es obligatoria.');return;}if(type==='urovo'&&!serial){toastr.error('Capture el número de serie UROVO.');return;}if(type==='verifone'&&!problem){toastr.error('Seleccione un problema para la Verifone.');return;}if(!['urovo','verifone'].includes(type)&&(!$('#providerFolio').val().trim()||!$('#providerDate').val())){toastr.error('Capture folio y fecha del reporte al proveedor.');return;}button.prop('disabled',true).text('Creando y registrando...');$.post('/operations/terminal_ticket_create',{type,description,problem,provider_folio:$('#providerFolio').val().trim(),provider_date:$('#providerDate').val(),urovo_serial:serial,request_key:requestKey}).done(r=>{if(!r.success)return toastr.error(r.message||'No fue posible registrar la incidencia.');toastr.success('Incidencia registrada · Ticket MOJO #'+r.ticket_id);active.unshift(r.incident);modal.hide();refresh();requestKey=null;}).fail(x=>toastr.error(x.responseJSON?.message||'No se confirmó el resultado. Reintenta sin cerrar esta ventana para conciliar el mismo ticket.')).always(()=>button.prop('disabled',false).text('Crear ticket y registrar'));});
  $('.show-resolved').on('click',function(){resolvedType=$(this).data('type');renderResolvedModal();resolvedModal.show();});
  $('#terminalResolvedList').on('click','.select-resolved',function(){selectResolved(Number($(this).data('id')));});
  $('#terminalResolvedList').on('click','.view-ticket',function(){const id=Number($(this).data('id'));$('#terminalResolvedModal').one('hidden.bs.modal',()=>openDetail(id));resolvedModal.hide();});
  $('#terminalIncidentList').on('click','.view-ticket',function(){openDetail(Number($(this).data('id')));});
  $('#cancelResolvedClose').on('click',function(){selectedCloseId=null;$('#terminalCloseConfirmation').addClass('d-none');});
  function runAction(id,action,message,button){button.prop('disabled',true);const original=button.text();button.text(action==='close'?'Cerrando…':action==='reopen'?'Reabriendo…':'Enviando…');$.post('/operations/terminal_incident_action',{incident_id:id,action,message}).done(r=>{if(!r.success){toastr.error(r.message||'No se pudo completar la acción.');return;}if(action==='close'){active=active.filter(i=>Number(i.id)!==id);toastr.success('Ticket cerrado. La terminal ya no cuenta como dañada.');resolvedModal.hide();detailModal.hide();}else{const item=active.find(i=>Number(i.id)===id);if(item)item.estado_local=r.state;toastr.success(action==='reply'?(r.state==='Reabierta'?'Respuesta enviada y ticket reabierto.':'Respuesta enviada.'):'Ticket reabierto.');if(action==='reply')$('#ticketReplyForm')[0].reset();openDetail(id);}refresh();}).fail(x=>toastr.error(x.responseJSON?.message||'No fue posible completar la operación.')).always(()=>button.prop('disabled',false).text(original));}
  $('#confirmResolvedClose').on('click',function(){const id=selectedCloseId;if(!id)return;runAction(id,'close','',$(this));});
  $('#ticketDetailActions').on('click','#reopenTicket',function(){runAction(Number(detailIncident?.id),'reopen','',$(this));});
  $('#ticketDetailActions').on('click','#confirmCloseFromDetail',function(){const i=detailIncident;if(!i)return;resolvedType=i.type;detailModal.hide();$('#terminalTicketDetailModal').one('hidden.bs.modal',()=>{renderResolvedModal();resolvedModal.show();setTimeout(()=>selectResolved(Number(i.id)),100);});});
  $('#ticketDetailActions').on('click','#showTicketReply',function(){$('#ticketReplyForm').removeClass('d-none');$('#ticketReplyMessage').val('').trigger('focus');});
  $('#ticketReplyForm').on('submit',function(e){e.preventDefault();const message=$('#ticketReplyMessage').val().trim();if(!message||!detailIncident)return;runAction(Number(detailIncident.id),'reply',message,$(this).find('[type="submit"]'));});
  $('#terminalResolvedModal').on('hidden.bs.modal',function(){resolvedType=null;selectedCloseId=null;});
  refresh();
});
