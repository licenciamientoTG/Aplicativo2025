(() => {
  'use strict';
  const root = document.querySelector('.triple');
  if (!root) return;
  const station = document.getElementById('tr-station');
  const button = document.getElementById('tr-upload-cuts');
  const status = document.getElementById('tr-cuts-status');
  const imageInput = document.getElementById('tr-cuts-image');
  const preview = document.getElementById('tr-cuts-preview');
  const rowsNode = document.getElementById('tr-cuts-rows');
  const confirm = document.getElementById('tr-cuts-confirm');
  let originalImage = null;
  let recognizedText = '';
  let rows = [];
  let worker = null;

  const fields = [
    ['estacion', 'Estación'], ['fecha', 'Fecha'], ['turno', 'Turno'], ['isla', 'Isla'],
    ['ventas', 'Ventas'], ['donativo', 'Donativo'], ['vale_tarjeta_interna', 'Vale/Tarjeta Interna'],
    ['vale_tarjeta_externa', 'Vale/Tarjeta Externa'], ['efectivo', 'Efectivo'], ['dollar', 'Dollar'], ['estado', 'Estado']
  ];
  const setStatus = (message, kind = 'info') => {
    status.className = `cuts-status is-${kind}`;
    status.textContent = message;
    status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  };
  const safe = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const parseDate = value => {
    const text = String(value || '').trim();
    let match = text.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/);
    if (match) return `${match[1]}-${match[2].padStart(2, '0')}-${match[3].padStart(2, '0')}`;
    match = text.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/);
    return match ? `${match[3]}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}` : text;
  };
  const normalizeState = value => /cerrad|closed|finaliz|terminad/i.test(String(value||''))?'closed':/abiert|open/i.test(String(value||''))?'open':'';
  const parseMoney = value => {
    const text = String(value ?? '').trim().replace(/[ $]/g, '');
    if (!text) return NaN;
    if (text === '--' || text === '-') return 0;
    let normalized=text;if(text.includes(',')&&text.includes('.')){const decimal=text.lastIndexOf(',')>text.lastIndexOf('.')?',':'.';normalized=decimal===','?text.replace(/\./g,'').replace(',','.'):text.replace(/,/g,'')}else if(text.includes(',')){normalized=/,\d{3}$/.test(text)?text.replace(/,/g,''):text.replace(',','.')} 
    const result = Number(normalized);
    return Number.isFinite(result) ? result : NaN;
  };
  const parseTsv = tsv => {
    const records = tsv.split(/\r?\n/).slice(1).map(line => {
      const cells = line.split('\t');
      if (cells.length < 12 || !cells[11]?.trim()) return null;
      return {top:Number(cells[7]), left:Number(cells[6]), width:Number(cells[8]), height:Number(cells[9]), text:cells.slice(11).join('\t').trim()};
    }).filter(Boolean);
    const byLine = new Map();
    records.forEach(word => { const y=Math.round((word.top+word.height/2)/12)*12; if(!byLine.has(y))byLine.set(y,[]); byLine.get(y).push(word); });
    const lines=[...byLine.entries()].map(([y,words])=>({y,words:words.sort((a,b)=>a.left-b.left),text:words.map(word=>word.text).join(' ')})).sort((a,b)=>a.y-b.y);
    const headerIndex=lines.findIndex(line=>/fecha|date/i.test(line.text)&&/turno|shift/i.test(line.text));
    if(headerIndex<0) throw new Error('No se detectaron las columnas Fecha y Turno. Prueba con una captura más nítida.');
    const headerRules=[['estacion',/estaci[oó]n|station/i],['fecha',/fecha|date/i],['turno',/turno|shift/i],['isla',/isla|island/i],['ventas',/ventas|sales/i],['donativo',/donativo|donation/i],['vale_tarjeta_interna',/interna|internal/i],['vale_tarjeta_externa',/externa|external/i],['efectivo',/efectivo|cash/i],['dollar',/dollar|d[oó]lar|usd/i],['estado',/estado|status/i]];
    const anchors=headerRules.map(([field,pattern])=>{const words=lines[headerIndex].words,wordIndex=words.findIndex(item=>pattern.test(item.text));if(wordIndex<0)return null;let word=words[wordIndex],left=word.left,right=word.left+word.width;if(field.startsWith('vale_tarjeta_')){for(let i=wordIndex-1;i>=0;i--){const part=words[i];if(word.left-part.left>150)break;if(/vale|tarjeta/i.test(part.text)){left=Math.min(left,part.left);right=Math.max(right,part.left+part.width)}}}return {field,x:(left+right)/2}}).filter(Boolean).sort((a,b)=>a.x-b.x);
    if(!anchors.some(item=>item.field==='fecha')||!anchors.some(item=>item.field==='turno')) throw new Error('No se detectaron las columnas Fecha y Turno.');
    return lines.slice(headerIndex+1).map(line=>{
      const row=Object.fromEntries(fields.map(([field])=>[field,'']));
      line.words.forEach(word=>{let best=anchors[0],distance=Infinity;anchors.forEach(anchor=>{const delta=Math.abs(anchor.x-(word.left+word.width/2));if(delta<distance){best=anchor;distance=delta}});row[best.field]=(row[best.field]+' '+word.text).trim()});
      if(!row.fecha&&!row.turno)return null;
      row.fecha=parseDate(row.fecha); if(row.isla==='-'||row.isla==='—')row.isla='--';
      ['ventas','donativo','vale_tarjeta_interna','vale_tarjeta_externa','efectivo','dollar'].forEach(field=>{const money=parseMoney(row[field]);row[field]=Number.isFinite(money)?money.toFixed(2):row[field]});
      return row;
    }).filter(Boolean);
  };
  const validate = () => rows.map((row, index) => {
    const issues = [];
    if (!/^\d{4}-\d{2}-\d{2}$/.test(row.fecha) || Number.isNaN(Date.parse(`${row.fecha}T00:00:00Z`)) || new Date(`${row.fecha}T00:00:00Z`).toISOString().slice(0,10)!==row.fecha) issues.push('Fecha ISO válida requerida');
    if (!/^\d+$/.test(String(row.turno).trim())) issues.push('Turno requerido');
    if (String(row.estacion).normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toUpperCase() !== 'PRAXEDIS') issues.push('La estación debe ser PRAXEDIS');
    if (!String(row.isla).trim()) issues.push('Isla requerida o --');
    if (!normalizeState(row.estado)) issues.push('Estado debe ser Abierto o Cerrado');
    ['ventas','donativo','vale_tarjeta_interna','vale_tarjeta_externa','efectivo','dollar'].forEach(field => { if (!Number.isFinite(parseMoney(row[field])) || parseMoney(row[field]) < 0) issues.push(`Importe inválido: ${field}`); });
    row._issues = issues;
    row._index = index;
    return issues.length === 0;
  });
  const render = () => {
    rows.forEach(row => { const identity=`cg-40-${row.fecha}-${String(row.turno).match(/\d+/)?.[0]||row.turno}-MN`; const existing=window.tripleCashReconciliation?.state.rows.find(item=>item.id===identity&&item.group); row._warning=row._edited&&existing?'Este turno ya tiene una conciliación asociada; la asociación existente se conservará.':''; });
    validate();
    rowsNode.innerHTML = rows.map((row, index) => `<tr class="${row._issues.length ? 'is-invalid' : ''}">${fields.map(([field, label]) => `<td><label class="sr-only" for="tr-cuts-${index}-${field}">${safe(label)} fila ${index + 1}</label><input id="tr-cuts-${index}-${field}" data-row="${index}" data-field="${field}" value="${safe(row[field])}" aria-label="${safe(label)}, fila ${index + 1}" class="form-control form-control-sm"></td>`).join('')}<td class="cuts-row-error">${safe([row._warning,...row._issues].filter(Boolean).join(' '))}</td></tr>`).join('');
    confirm.disabled = !rows.length || rows.some(row => row._issues.length);
  };
  const syncButton = () => { button.hidden = String(station.value) !== '40'; };
  const openModal = () => { if (String(station.value) !== '40') return; setStatus('Pega una captura dentro de esta ventana o selecciona una imagen PNG/JPEG.'); window.jQuery('#tr-cuts-modal').modal('show'); };
  const recognize = async image => {
    if (!image || !/^image\/(png|jpeg)$/.test(image.type)) throw new Error('El portapapeles debe contener una imagen PNG o JPEG.');
    originalImage = image;
    preview.src = URL.createObjectURL(image);
    preview.hidden = false;
    setStatus('Preparando OCR local…');
    if (!worker) worker = await Tesseract.createWorker('spa+eng', 1, {workerPath:'/_assets/vendor/tesseract/worker.min.js', corePath:'/_assets/vendor/tesseract/core/tesseract-core-simd-lstm.wasm.js', langPath:'/_assets/vendor/tesseract/lang', gzip:false, logger: progress => {
      const label = progress.status === 'recognizing text' ? 'Leyendo texto' : progress.status === 'loading language traineddata' ? 'Cargando idioma' : 'Inicializando OCR';
      const percent = Number.isFinite(progress.progress) ? ` ${Math.round(progress.progress * 100)}%` : '';
      setStatus(`${label}${percent}…`);
    }});
    const result = await worker.recognize(image, {}, {text:true, tsv:true});
    recognizedText = result.data.text || '';
    rows = parseTsv(result.data.tsv || '');
    render();
    if (!rows.length) throw new Error('OCR terminó, pero no encontró filas. Corrige la imagen y vuelve a pegarla.');
    setStatus(`${rows.length} filas reconocidas. Revisa y corrige los datos antes de confirmar.`);
  };
  const imageFromClipboard = event => Array.from(event.clipboardData?.items || []).map(item => item.getAsFile?.()).find(file => file && /^image\/(png|jpeg)$/.test(file.type));
  station.addEventListener('change', syncButton);
  button.addEventListener('click', openModal);
  imageInput.addEventListener('change', () => { const file = imageInput.files?.[0]; if (file) recognize(file).catch(error => setStatus(error.message, 'error')); });
  document.getElementById('tr-cuts-paste-surface').addEventListener('paste', event => {
    const image = imageFromClipboard(event);
    if (!image) return;
    event.preventDefault();
    recognize(image).catch(error => setStatus(error.message, 'error'));
  });
  window.jQuery('#tr-cuts-modal').on('shown.bs.modal', () => document.getElementById('tr-cuts-paste-surface').focus());
  rowsNode.addEventListener('input', event => {
    const input = event.target.closest('[data-row][data-field]');
    if (!input) return;
    rows[Number(input.dataset.row)][input.dataset.field] = input.value; rows[Number(input.dataset.row)]._edited=true;
    const row=rows[Number(input.dataset.row)]; const valid=validate(); const tr=input.closest('tr'); tr.classList.toggle('is-invalid',row._issues.length>0); tr.querySelector('.cuts-row-error').textContent=row._issues.join(', '); confirm.disabled=!rows.length||valid.some(ok=>!ok);
  });
  confirm.addEventListener('click', async () => {
    const valid = validate();
    if (!originalImage || valid.some(ok => !ok)) { render(); setStatus('Corrige las filas marcadas antes de importar.', 'error'); return; }
    confirm.disabled = true;
    setStatus('Importando cortes…');
    const body = new FormData();
    body.append('image', originalImage, 'cortes.png');
    body.append('rows', JSON.stringify(rows.map(row => ({estacion:'PRAXEDIS',estacion_id:40,fecha_operativa:row.fecha,turno:String(row.turno).trim(),isla:row.isla,ventas:parseMoney(row.ventas),donativo:parseMoney(row.donativo),vale_interno:parseMoney(row.vale_tarjeta_interna),vale_externo:parseMoney(row.vale_tarjeta_externa),efectivo:parseMoney(row.efectivo),dollar:parseMoney(row.dollar),estado:normalizeState(row.estado)}))));
    body.append('ocr_text', recognizedText);
    try {
      const response = await fetch('/income/efc_conc_praxedis_importar', {method:'POST', body});
      const result = await response.json();
      if (!response.ok || result.status !== 'success') throw new Error(result.message || 'No fue posible importar los cortes.');
      setStatus('Importación completada. Actualizando conciliación…', 'success');
      await window.tripleCashReconciliation.consult();
      document.dispatchEvent(new CustomEvent('tripleCashReconciliationCompanyProgressRefresh'));
      window.jQuery('#tr-cuts-modal').modal('hide');
    } catch (error) { setStatus(error.message || 'No fue posible importar los cortes.', 'error'); }
    finally { confirm.disabled = false; }
  });
  syncButton();
  window.cashCutsOcr = {parseTsv, parseMoney, parseDate};
})();
