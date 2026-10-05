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
  let workerPromise = null;
  let previewUrl = null;
  let recognitionGeneration = 0;

  const fields = [['estacion', 'Estación'], ['fecha', 'Fecha'], ['turno', 'Turno'], ['efectivo', 'Efectivo'], ['dollar', 'Dollar']];
  const setStatus = (message, kind = 'info') => {
    status.className = `cuts-status is-${kind}`;
    status.textContent = message;
    status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  };
  const getOcrWorker = () => {
    if (worker) return Promise.resolve(worker);
    if (!workerPromise) {
      workerPromise = Tesseract.createWorker('spa+eng', 1, {workerPath:'/_assets/vendor/tesseract/worker.min.js', corePath:'/_assets/vendor/tesseract/core/tesseract-core-simd-lstm.wasm.js', langPath:'/_assets/vendor/tesseract/lang', gzip:false, logger: progress => {
        if (!recognitionGeneration) return;
        const label = progress.status === 'recognizing text' ? 'Leyendo texto' : progress.status === 'loading language traineddata' ? 'Cargando idioma' : 'Inicializando OCR';
        const percent = Number.isFinite(progress.progress) ? ` ${Math.round(progress.progress * 100)}%` : '';
        setStatus(`${label}${percent}…`);
      }}).then(createdWorker => { worker = createdWorker; return worker; }).catch(error => { workerPromise = null; throw error; });
    }
    return workerPromise;
  };
  const safe = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const moneyFields = new Set(['efectivo','dollar']);
  const formatMoney = value => {
    const amount = parseMoney(value);
    return Number.isFinite(amount) ? new Intl.NumberFormat('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}).format(amount) : value;
  };
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
  const prepareOcrImage = async image => {
    if (typeof createImageBitmap !== 'function') return image;
    const bitmap = await createImageBitmap(image);
    const scale = Math.min(3, 5000 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d', { willReadFrequently: true });
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
    for (let index = 0; index < pixels.data.length; index += 4) {
      const gray = (pixels.data[index] * 0.299 + pixels.data[index + 1] * 0.587 + pixels.data[index + 2] * 0.114 - 128) * 1.25 + 128;
      const value = Math.max(0, Math.min(255, gray));
      pixels.data[index] = pixels.data[index + 1] = pixels.data[index + 2] = value;
    }
    context.putImageData(pixels, 0, 0);
    return await new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error('No se pudo preparar la captura para OCR.')), 'image/png'));
  };
  const parseTsv = tsv => {
    const records = tsv.split(/\r?\n/).slice(1).map(line => {
      const cells = line.split('\t');
      if (cells.length < 12 || !cells[11]?.trim()) return null;
      return {top:Number(cells[7]), left:Number(cells[6]), width:Number(cells[8]), height:Number(cells[9]), text:cells.slice(11).join('\t').trim()};
    }).filter(Boolean);
    const heights=records.map(word=>word.height).filter(height=>height>0).sort((a,b)=>a-b);
    const lineTolerance=Math.max(7,(heights[Math.floor(heights.length/2)]||12)*0.8);
    const lines=[];
    records.sort((a,b)=>(a.top+a.height/2)-(b.top+b.height/2)).forEach(word=>{
      const center=word.top+word.height/2, line=lines[lines.length-1];
      if(!line||Math.abs(center-line.center)>lineTolerance) lines.push({center,words:[word]}); else {line.words.push(word);line.center=(line.center*(line.words.length-1)+center)/line.words.length;}
    });
    lines.forEach(line=>{line.words.sort((a,b)=>a.left-b.left);line.text=line.words.map(word=>word.text).join(' ')});
    const headerIndex=lines.findIndex(line=>/fecha|date/i.test(line.text)&&/turno|shift/i.test(line.text));
    if(headerIndex<0) throw new Error('No se detectaron las columnas Fecha y Turno. Prueba con una captura más nítida.');
    const headerRules=[['estacion',/estaci[oó]n|station/i],['fecha',/fecha|date/i],['turno',/turno|shift/i],['isla',/isla|island/i],['ventas',/ventas|sales/i],['donativo',/donativo|donation/i],['vale_tarjeta_interna',/interna|internal/i],['vale_tarjeta_externa',/externa|external/i],['efectivo',/efectivo|cash/i],['dollar',/dollar|d[oó]lar|usd/i],['estado',/estado|status/i]];
    const requiredHeaders=new Set(['estacion','fecha','turno','efectivo','dollar']);
    const headerLines=[lines[headerIndex]],headerHeight=Math.max(...lines[headerIndex].words.map(word=>word.height),lineTolerance);
    for(let i=headerIndex+1;i<lines.length&&i<=headerIndex+2;i++){
      const candidate=lines[i];
      if(candidate.center-headerLines[0].center>headerHeight*2.5||!/vale|tarjeta|interna|externa|efectivo|cash|dollar|d[oó]lar|estaci[oó]n/i.test(candidate.text))break;
      headerLines.push(candidate);
    }
    const headerWords=headerLines.flatMap(line=>line.words).sort((a,b)=>a.left-b.left);
    const anchors=headerRules.map(([field,pattern])=>{const wordIndex=headerWords.findIndex(item=>pattern.test(item.text));if(wordIndex<0)return null;let word=headerWords[wordIndex],left=word.left,right=word.left+word.width;if(field.startsWith('vale_tarjeta_')){for(let i=wordIndex-1;i>=0;i--){const part=headerWords[i];if(word.left-part.left>Math.max(word.height,part.height)*12)break;if(/vale|tarjeta/i.test(part.text)){left=Math.min(left,part.left);right=Math.max(right,part.left+part.width)}}}return {field,x:(left+right)/2}}).filter(Boolean).sort((a,b)=>a.x-b.x);
    const missingHeaders=headerRules.filter(([field])=>requiredHeaders.has(field)&&!anchors.some(anchor=>anchor.field===field)).map(([field])=>fields.find(([key])=>key===field)?.[1]||field);
    if(missingHeaders.length) throw new Error(`No pude reconocer con seguridad estas columnas: ${missingHeaders.join(', ')}. Pega una captura más nítida o recorta la tabla.`);
    const dataLines=lines.slice(headerIndex+headerLines.length);
    const assignWords=words=>{
      const row=Object.fromEntries(fields.map(([field])=>[field,'']));
      words.forEach(word=>{
        const numeric=/\d/.test(word.text),right=word.left+word.width;
        let best=anchors[0],distance=Infinity;
        anchors.forEach((anchor,index)=>{
          const boundary=index<anchors.length-1?(anchor.x+anchors[index+1].x)/2:null;
          const delta=numeric&&moneyFields.has(anchor.field)&&boundary!==null?Math.abs(boundary-right):Math.abs(anchor.x-(word.left+word.width/2));
          if(delta<distance){best=anchor;distance=delta}
        });
        row[best.field]=(row[best.field]+' '+word.text).trim();
      });
      return row;
    };
    const seeds=dataLines.map(line=>({line,row:assignWords(line.words)})).filter(seed=>/^\d{4}-\d{1,2}-\d{1,2}$/.test(parseDate(seed.row.fecha))&&/^\d+$/.test(seed.row.turno.trim()));
    if(!seeds.length) throw new Error('No pude separar las filas por fecha y turno. Pega una captura más nítida o recorta la tabla.');
    return seeds.map((seed,index)=>{
      const previousCenter=index>0?seeds[index-1].line.center:headerLines[headerLines.length-1].center;
      const nextCenter=index+1<seeds.length?seeds[index+1].line.center:null;
      const lowerBound=(previousCenter+seed.line.center)/2;
      const upperBound=nextCenter===null?seed.line.center+(seed.line.center-previousCenter)/2:(seed.line.center+nextCenter)/2;
      const words=dataLines.filter(line=>line.center>=lowerBound&&line.center<=upperBound).flatMap(line=>line.words);
      const row=assignWords(words);
      row.fecha=parseDate(row.fecha);
      ['efectivo','dollar'].forEach(field=>{const money=parseMoney(row[field]);row[field]=Number.isFinite(money)?money.toFixed(2):row[field]});
      return row;
    });
  };
  const validate = () => rows.map((row, index) => {
    const issues = [];
    if (!/^\d{4}-\d{2}-\d{2}$/.test(row.fecha) || Number.isNaN(Date.parse(`${row.fecha}T00:00:00Z`)) || new Date(`${row.fecha}T00:00:00Z`).toISOString().slice(0,10)!==row.fecha) issues.push('Fecha ISO válida requerida');
    if (!/^\d+$/.test(String(row.turno).trim())) issues.push('Turno requerido');
    if (String(row.estacion).normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().toUpperCase() !== 'PRAXEDIS') issues.push('La estación debe ser PRAXEDIS');
    ['efectivo','dollar'].forEach(field => { if (!Number.isFinite(parseMoney(row[field])) || parseMoney(row[field]) < 0) issues.push(`Importe inválido: ${field}`); });
    row._issues = issues;
    row._index = index;
    return issues.length === 0;
  });
  const render = () => {
    rows.forEach(row => { const identity=`cg-40-${row.fecha}-${String(row.turno).match(/\d+/)?.[0]||row.turno}-MN`; const existing=window.tripleCashReconciliation?.state.rows.find(item=>item.id===identity&&item.group); row._warning=row._edited&&existing?'Este turno ya tiene una conciliación asociada; la asociación existente se conservará.':''; });
    validate();
    rowsNode.innerHTML = rows.map((row, index) => `<tr class="${row._issues.length ? 'is-invalid' : ''}">${fields.map(([field, label]) => {const value=moneyFields.has(field)?formatMoney(row[field]):row[field];return `<td><label class="sr-only" for="tr-cuts-${index}-${field}">${safe(label)} fila ${index + 1}</label><input id="tr-cuts-${index}-${field}" data-row="${index}" data-field="${field}" value="${safe(value)}" aria-label="${safe(label)}, fila ${index + 1}" class="form-control form-control-sm"></td>`}).join('')}<td class="cuts-row-error">${safe([row._warning,...row._issues].filter(Boolean).join(' '))}</td></tr>`).join('');
    confirm.disabled = !rows.length || rows.some(row => row._issues.length);
  };
  const syncButton = () => { button.hidden = String(station.value) !== '40'; };
  const openModal = () => { if (String(station.value) !== '40') return; setStatus('Pega una captura dentro de esta ventana o selecciona una imagen PNG/JPEG.'); window.jQuery('#tr-cuts-modal').modal('show'); };
  const recognize = async image => {
    const generation = ++recognitionGeneration;
    originalImage = null;
    rows = [];
    recognizedText = '';
    confirm.disabled = true;
    rowsNode.innerHTML = '';
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    previewUrl = null;
    preview.removeAttribute('src');
    preview.hidden = true;
    try {
      if (!image || !/^image\/(png|jpeg)$/.test(image.type)) throw new Error('El portapapeles debe contener una imagen PNG o JPEG.');
      originalImage = image;
      previewUrl = URL.createObjectURL(image);
      preview.src = previewUrl;
      preview.hidden = false;
      setStatus('Preparando OCR local…');
      worker = await getOcrWorker();
      if (generation !== recognitionGeneration) return;
      setStatus('Mejorando la captura para leer sus columnas…');
      const ocrImage = await prepareOcrImage(image);
      if (generation !== recognitionGeneration) return;
      const result = await worker.recognize(ocrImage, {}, {text:true, tsv:true});
      if (generation !== recognitionGeneration) return;
      recognizedText = result.data.text || '';
      rows = parseTsv(result.data.tsv || '');
      render();
      if (!rows.length) throw new Error('OCR terminó, pero no encontró filas. Corrige la imagen y vuelve a pegarla.');
      setStatus(`${rows.length} filas reconocidas. Revisa y corrige los datos antes de confirmar.`);
    } catch (error) {
      if (generation === recognitionGeneration) setStatus(error.message || 'No fue posible leer la captura.', 'error');
    }
  };
  const imageFromClipboard = event => Array.from(event.clipboardData?.items || []).map(item => item.getAsFile?.()).find(file => file && /^image\/(png|jpeg)$/.test(file.type));
  station.addEventListener('change', syncButton);
  button.addEventListener('click', openModal);
  imageInput.addEventListener('change', () => { const file = imageInput.files?.[0]; if (file) recognize(file); });
  document.getElementById('tr-cuts-paste-surface').addEventListener('paste', event => {
    const image = imageFromClipboard(event);
    if (!image) return;
    event.preventDefault();
    recognize(image);
  });
  window.jQuery('#tr-cuts-modal').on('shown.bs.modal', () => document.getElementById('tr-cuts-paste-surface').focus());
  rowsNode.addEventListener('input', event => {
    const input = event.target.closest('[data-row][data-field]');
    if (!input) return;
    rows[Number(input.dataset.row)][input.dataset.field] = input.value; rows[Number(input.dataset.row)]._edited=true;
    const row=rows[Number(input.dataset.row)]; const valid=validate(); const tr=input.closest('tr'); tr.classList.toggle('is-invalid',row._issues.length>0); tr.querySelector('.cuts-row-error').textContent=row._issues.join(', '); confirm.disabled=!rows.length||valid.some(ok=>!ok);
  });
  rowsNode.addEventListener('focusin', event => {
    const input = event.target.closest('[data-row][data-field]');
    if (input && moneyFields.has(input.dataset.field)) input.value = input.value.replace(/,/g, '');
  });
  rowsNode.addEventListener('focusout', event => {
    const input = event.target.closest('[data-row][data-field]');
    if (!input || !moneyFields.has(input.dataset.field)) return;
    const amount = parseMoney(input.value);
    if (Number.isFinite(amount)) input.value = formatMoney(amount);
  });
  confirm.addEventListener('click', async () => {
    const valid = validate();
    if (!originalImage || valid.some(ok => !ok)) { render(); setStatus('Corrige las filas marcadas antes de importar.', 'error'); return; }
    confirm.disabled = true;
    setStatus('Importando cortes…');
    const body = new FormData();
    body.append('image', originalImage, 'cortes.png');
    body.append('rows', JSON.stringify(rows.map(row => ({estacion:'PRAXEDIS',estacion_id:40,fecha_operativa:row.fecha,turno:String(row.turno).trim(),efectivo:parseMoney(row.efectivo),dollar:parseMoney(row.dollar)}))));
    body.append('ocr_text', recognizedText);
    try {
      const response = await fetch('/income/efc_conc_praxedis_importar', {method:'POST', body});
      const responseText = await response.text();
      let result;
      try { result = responseText ? JSON.parse(responseText) : {}; }
      catch { throw new Error(`El servidor respondió con contenido inválido (HTTP ${response.status}).`); }
      if (!responseText) throw new Error(`El servidor rechazó la importación (HTTP ${response.status}) sin proporcionar un mensaje. Revisa el tamaño permitido de la imagen y los registros del servidor.`);
      if (!response.ok || result.status !== 'success') throw new Error(result.message || 'No fue posible importar los cortes.');
      setStatus('Importación completada. Actualizando conciliación…', 'success');
      await window.tripleCashReconciliation.consult();
      document.dispatchEvent(new CustomEvent('tripleCashReconciliationCompanyProgressRefresh'));
      window.jQuery('#tr-cuts-modal').modal('hide');
    } catch (error) { setStatus(error.message || 'No fue posible importar los cortes.', 'error'); }
    finally { confirm.disabled = false; }
  });
  syncButton();
  window.cashCutsOcr = {parseTsv, parseMoney, parseDate, prepareOcrImage};
})();
