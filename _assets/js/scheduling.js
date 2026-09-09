function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;');
}

let ultimasFilas = [];
let agrupacionActiva = 'terminal';
let colsActivas = 3;
let proveedorFiltroActivo = null;
const ESTACIONES = window.SCHEDULING_ESTACIONES || [];

// Las 16 combinaciones Proveedor→Terminal reales del programa mensual
// (confirmadas contra los Excel de julio y septiembre 2026 -- mismo
// layout de bloques en ambos meses). Se muestran siempre como tarjeta,
// aunque no tengan recepciones programadas ese día, para que la vista
// coincida con la hoja que Abastos ya conoce.
const GRUPOS_PROVEEDOR_TERMINAL = [
    { supplierId: 138, supplierNombre: 'PREMIERGAS', terminalNombre: 'Diaz Gas' },
    { supplierId: 123, supplierNombre: 'TESORO MEXICO SUPPLY & MARKETING', terminalNombre: 'Diaz Gas' },
    { supplierId: 139, supplierNombre: 'MGC MEXICO', terminalNombre: 'Diaz Gas' },
    { supplierId: 150, supplierNombre: 'ENEREY LATINOAMERICA', terminalNombre: 'Diaz Gas' },
    { supplierId: 150, supplierNombre: 'ENEREY LATINOAMERICA', terminalNombre: 'Petrotal' },
    { supplierId: 138, supplierNombre: 'PREMIERGAS', terminalNombre: 'Gaso Mex' },
    { supplierId: 163, supplierNombre: 'ALTOS ENERGETICOS MEXICANOS', terminalNombre: 'Aguascalientes' },
    { supplierId: 138, supplierNombre: 'PREMIERGAS', terminalNombre: 'Ahumada' },
    { supplierId: 139, supplierNombre: 'MGC MEXICO', terminalNombre: 'Gaso Mex' },
    { supplierId: 123, supplierNombre: 'TESORO MEXICO SUPPLY & MARKETING', terminalNombre: 'Petrotal' },
    { supplierId: 163, supplierNombre: 'ALTOS ENERGETICOS MEXICANOS', terminalNombre: 'San Miguel de Allende' },
    { supplierId: 138, supplierNombre: 'PREMIERGAS', terminalNombre: 'Servicio SYC' },
    { supplierId: 139, supplierNombre: 'MGC MEXICO', terminalNombre: 'Aguascalientes' },
    { supplierId: 122, supplierNombre: 'PETROTAL', terminalNombre: 'Petrotal' },
    { supplierId: 151, supplierNombre: 'ESSA FUEL', terminalNombre: 'Sin terminal' },
    { supplierId: 139, supplierNombre: 'MGC MEXICO', terminalNombre: 'San Miguel de Allende' },
];

// AEMSA nunca captura transportista en el Excel (0% de las filas en julio
// y septiembre 2026, confirmado) -- se oculta esa columna solo en sus
// tarjetas para no mostrar una columna que siempre va a decir "—".
const PROVEEDORES_SIN_TRANSPORTISTA = ['AEMSA', 'ALTOS ENERGETICOS'];

function ocultaTransportista(nombreProveedor) {
    const nombre = (nombreProveedor || '').toUpperCase();
    return PROVEEDORES_SIN_TRANSPORTISTA.some(function (p) { return nombre.indexOf(p) !== -1; });
}

// Premier Gas nunca captura referencia ni notas en el Excel -- se ocultan
// esas columnas solo en sus tarjetas por el mismo motivo que Transportista
// arriba: siempre van a decir "—".
const PROVEEDORES_SIN_REFERENCIA_NOTAS = ['PREMIER', 'TESORO'];

function ocultaReferenciaNotas(nombreProveedor) {
    const nombre = (nombreProveedor || '').toUpperCase();
    return PROVEEDORES_SIN_REFERENCIA_NOTAS.some(function (p) { return nombre.indexOf(p) !== -1; });
}

// Colores por proveedor: mismo valor de luminosidad y saturación para
// los 8 (HSL, L=48%, S=55%), solo cambia el matiz -- así ninguno destaca
// más que otro en el grid. El matiz de cada uno conserva, donde tiene
// sentido, la asociación de color que Abastos ya usaba en el Excel
// (Tesoro=ámbar, Petrotal=azul, Enerey=azul claro), normalizada para que
// todos convivan en la misma familia visual en vez de competir entre sí.
const COLOR_PROVEEDOR = [
    { match: 'PREMIER', color: 'hsl(28, 55%, 48%)' },   // terracota
    { match: 'TESORO', color: 'hsl(42, 55%, 48%)' },    // ámbar
    { match: 'MGC', color: 'hsl(16, 55%, 48%)' },       // naranja quemado
    { match: 'ENEREY', color: 'hsl(206, 55%, 48%)' },   // azul cielo
    { match: 'PETROTAL', color: 'hsl(222, 55%, 48%)' }, // azul marino
    { match: 'AEMSA', color: 'hsl(340, 55%, 48%)' },
    { match: 'ALTOS ENERGETICOS', color: 'hsl(340, 55%, 48%)' },
    { match: 'LOBO', color: 'hsl(266, 55%, 48%)' },     // púrpura
    { match: 'ESSA FUEL', color: 'hsl(160, 55%, 40%)' },// verde azulado
];
const COLOR_PROVEEDOR_DEFAULT = 'hsl(210, 15%, 55%)';
const COLOR_ESTACION = 'hsl(210, 10%, 45%)';

function colorProveedor(nombreProveedor) {
    const nombre = (nombreProveedor || '').toUpperCase();
    for (let i = 0; i < COLOR_PROVEEDOR.length; i++) {
        if (nombre.indexOf(COLOR_PROVEEDOR[i].match) !== -1) return COLOR_PROVEEDOR[i].color;
    }
    return COLOR_PROVEEDOR_DEFAULT;
}

// Los 7 proveedores reales del programa mensual de combustible (mismos IDs
// que FuelReceptionScheduleModel::IDS_PROVEEDORES_COMBUSTIBLE) -- botones
// fijos en vez de un selector, así el filtro siempre muestra las mismas 7
// opciones sin depender de qué haya programado ese día en particular.
const PROVEEDORES_FILTRO = [
    { id: 138, nombreCorto: 'Premier Gas' },
    { id: 151, nombreCorto: 'Essa Fuel' },
    { id: 123, nombreCorto: 'Tesoro' },
    { id: 122, nombreCorto: 'Petrotal' },
    { id: 139, nombreCorto: 'MGC' },
    { id: 150, nombreCorto: 'Enerey' },
    { id: 163, nombreCorto: 'AEMSA' },
];

// Producto: Regular/Premium/Diesel/Mixta son categorías paralelas, no
// estados de éxito/error -- un punto de color + texto evita el efecto
// semáforo (verde=bien/rojo=mal) que un badge de fondo lleno sugiere sin
// que exista ninguna jerarquía real entre los cuatro.
const COLOR_PRODUCTO = {
    'Regular': '#2E7D5B',
    'Premium': '#B23A48',
    'Diesel': '#3A3A3A',
    'Mixta': '#C77D2E',
};

function badgeProducto(producto, mezcla) {
    const color = COLOR_PRODUCTO[producto] || '#6C757D';
    const texto = esc(producto) + (mezcla ? ' (' + esc(mezcla) + ')' : '');
    return `<span class="d-inline-flex align-items-center gap-1">` +
        `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;"></span>` +
        `<span>${texto}</span></span>`;
}

function colClass() {
    if (colsActivas === 2) return 'col-12 col-lg-6';
    if (colsActivas === 3) return 'col-12 col-lg-6 col-xl-4';
    return 'col-12';
}

function botonesAccion(id, invoiceId) {
    const colorFactura = invoiceId ? 'btn-outline-success' : 'btn-outline-secondary';
    return `
        <div class="d-flex gap-1 justify-content-center">
            <button type="button" class="btn btn-outline-success btn-editar-recepcion btn-accion-icono" data-id="${id}" title="Editar"><i data-feather="edit-3"></i></button>
            <button type="button" class="btn ${colorFactura} btn-factura-recepcion btn-accion-icono" data-id="${id}" title="${invoiceId ? 'Ver factura' : 'Subir factura'}"><i data-feather="paperclip"></i></button>
            <button type="button" class="btn btn-outline-danger btn-cancelar-recepcion btn-accion-icono" data-id="${id}" title="Cancelar"><i data-feather="trash-2"></i></button>
        </div>
    `;
}

function formatearFilaTerminal(fila, mostrarTransportista, mostrarReferenciaNotas) {
    const celdaTransportista = mostrarTransportista
        ? `<td>${esc(fila.carrier_nombre) || '<span class="text-muted">—</span>'}</td>`
        : '';
    const celdasReferenciaNotas = mostrarReferenciaNotas
        ? `<td>${esc(fila.referencia) || ''}</td><td>${esc(fila.notas) || ''}</td>`
        : '';
    return `
        <tr data-id="${fila.id}">
            <td>${esc(fila.hora) || '<span class="text-muted">—</span>'}</td>
            <td>${badgeProducto(fila.product, fila.mezcla)}</td>
            <td>${Number(fila.litros).toLocaleString('es-MX')}</td>
            <td>${esc(fila.station_nombre) || '<span class="text-muted">—</span>'}</td>
            ${celdaTransportista}
            ${celdasReferenciaNotas}
            <td>${botonesAccion(fila.id, fila.invoice_id)}</td>
        </tr>
    `;
}

const ESTACIONES_INLINE = window.SCHEDULING_ESTACIONES || [];
const TRANSPORTISTAS_INLINE = window.SCHEDULING_TRANSPORTISTAS || [];
const TERMINALES_INLINE = window.SCHEDULING_TERMINALES || [];

// fuel_terminals no liga cada terminal a un proveedor (supplier_id viene
// vacío en las 8 filas reales, confirmado 2026-09-08) -- una terminal como
// "Diaz Gas" es compartida por varios proveedores, así que el match es
// solo por nombre. Necesario para resolver terminal_id en tarjetas que
// todavía no tienen ninguna recepción real ese día (ahí
// GRUPOS_PROVEEDOR_TERMINAL solo trae el nombre, nunca el id).
function resolverTerminalId(terminalNombre) {
    const match = TERMINALES_INLINE.find(function (t) { return t.nombre === terminalNombre; });
    return match ? match.id : null;
}

// Fila de captura rápida ("como Excel"): inputs directo en la tabla de la
// tarjeta, con proveedor/terminal ya fijos por el grupo. Se llena toda la
// fila y se guarda con un solo clic en ✓ -- el guardado automático por
// blur se probó y se descartó: el backend exige el registro completo en
// cada request, así que cualquier evento duplicado (change+blur casi
// simultáneos, típico de bootstrap-select) mandaba dos scheduling_add en
// paralelo y dejaba duplicados reales en BD (visto 2026-09-08, 6 copias).
function filaRapidaHtml(supplierId, terminalId, mostrarTransportista, mostrarReferenciaNotas) {
    const opcionesEstacion = ESTACIONES_INLINE.map(function (e) {
        return `<option value="${e.Codigo}">${esc(e.Nombre)}</option>`;
    }).join('');
    const opcionesTransportista = TRANSPORTISTAS_INLINE.map(function (t) {
        return `<option value="${t.id}">${esc(t.nombre)}</option>`;
    }).join('');

    const celdaTransportista = mostrarTransportista
        ? `<td><select class="form-select form-select-sm campo-rapido" data-campo="carrier_id"><option value="">—</option>${opcionesTransportista}</select></td>`
        : '';
    const celdasReferenciaNotas = mostrarReferenciaNotas
        ? `<td><input type="text" class="form-control form-control-sm campo-rapido" data-campo="referencia"></td>` +
          `<td><input type="text" class="form-control form-control-sm campo-rapido" data-campo="notas"></td>`
        : '';

    return `
        <tr class="fila-rapida" data-supplier-id="${supplierId}" data-terminal-id="${terminalId || ''}" data-registro-id="">
            <td><input type="time" class="form-control form-control-sm campo-rapido" data-campo="hora"></td>
            <td>
                <select class="form-select form-select-sm campo-rapido" data-campo="product">
                    <option value="Regular">Regular</option>
                    <option value="Premium">Premium</option>
                    <option value="Diesel">Diesel</option>
                    <option value="Mixta">Mixta</option>
                </select>
            </td>
            <td><input type="number" min="1" step="1" class="form-control form-control-sm campo-rapido" data-campo="litros" placeholder="Litros"></td>
            <td>
                <select class="selectpicker campo-rapido" data-campo="station_code" data-live-search="true" data-width="180px" data-size="8" data-container="body">
                    <option value="">Seleccione…</option>
                    ${opcionesEstacion}
                </select>
            </td>
            ${celdaTransportista}
            ${celdasReferenciaNotas}
            <td>
                <div class="d-flex gap-1 justify-content-center">
                    <button type="button" class="btn btn-outline-success btn-guardar-fila-rapida btn-accion-icono" title="Guardar"><i data-feather="check"></i></button>
                    <button type="button" class="btn btn-outline-danger btn-cancelar-fila-rapida btn-accion-icono" title="Cancelar"><i data-feather="x"></i></button>
                </div>
            </td>
        </tr>
    `;
}

function formatearFilaEstacion(fila) {
    return `
        <tr data-id="${fila.id}">
            <td>${esc(fila.hora) || '<span class="text-muted">—</span>'}</td>
            <td>${badgeProducto(fila.product, fila.mezcla)}</td>
            <td>${Number(fila.litros).toLocaleString('es-MX')}</td>
            <td>${esc(fila.supplier_nombre) || '<span class="text-muted">—</span>'}</td>
            <td>${esc(fila.terminal_nombre) || '<span class="text-muted">—</span>'}</td>
            <td>${esc(fila.carrier_nombre) || '<span class="text-muted">—</span>'}</td>
            <td>${botonesAccion(fila.id, fila.invoice_id)}</td>
        </tr>
    `;
}

function tarjetaGrupo(titulo, subtotal, filasHtml, encabezados, colorBorde, pesoRelativo, botonAgregar) {
    // El grosor del borde escala con el volumen del grupo relativo al mayor
    // del día (3px..9px) -- una tarjeta con más litros programados destaca
    // sin necesitar leer el número del badge.
    const grosor = colorBorde ? Math.round(3 + 6 * (pesoRelativo || 0)) : 0;
    const estiloBorde = colorBorde ? ` style="border-left: ${grosor}px solid ${colorBorde};"` : '';
    return `
        <div class="${colClass()} mb-4">
            <div class="card h-100"${estiloBorde}>
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 d-flex align-items-center gap-2">${titulo}${botonAgregar || ''}</h6>
                    <span class="badge bg-white text-dark border">${subtotal.toLocaleString('es-MX')} L</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>${encabezados.map(function (h) { return '<th>' + h + '</th>'; }).join('')}</tr>
                        </thead>
                        <tbody>${filasHtml}</tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function filasFiltradas() {
    if (!proveedorFiltroActivo) return ultimasFilas;
    return ultimasFilas.filter(function (f) { return String(f.supplier_id) === String(proveedorFiltroActivo); });
}

function renderBotonesProveedor() {
    const contenedor = $('#filtroProveedorBotones');
    contenedor.empty();
    PROVEEDORES_FILTRO.forEach(function (p) {
        const activo = String(proveedorFiltroActivo) === String(p.id);
        const color = colorProveedor(p.nombreCorto);
        const estilo = activo ? ` style="background-color:${color};border-color:${color};"` : ` style="border-color:${color};color:${color};"`;
        contenedor.append(
            `<button type="button" class="btn btn-sm btn-filtro-proveedor${activo ? ' active' : ''}" data-supplier-id="${p.id}"${estilo}>${esc(p.nombreCorto)}</button>`
        );
    });
}

function renderPorTerminal(filas) {
    const contenedor = $('#contenedorGrupos');
    contenedor.empty();

    const ocultarVacios = $('#btnOcultarVaciosTerminal').hasClass('active');

    // Arranca de las 16 combinaciones reales del programa (siempre visibles,
    // aunque no tengan filas ese día) y les asigna las filas que apliquen.
    const grupos = GRUPOS_PROVEEDOR_TERMINAL.map(function (g) {
        return { supplierId: g.supplierId, supplierNombre: g.supplierNombre, terminalNombre: g.terminalNombre, terminalId: null, filas: [], total: 0 };
    });

    filas.forEach(function (fila) {
        let grupo = grupos.find(function (g) {
            return String(g.supplierId) === String(fila.supplier_id) && g.terminalNombre === (fila.terminal_nombre || 'Sin terminal');
        });
        if (!grupo) {
            // Combinación no prevista en el catálogo fijo (proveedor/terminal
            // nuevo aún no confirmado) -- se agrega igual para no perder el dato.
            grupo = { supplierId: fila.supplier_id, supplierNombre: fila.supplier_nombre, terminalNombre: fila.terminal_nombre || 'Sin terminal', terminalId: null, filas: [], total: 0 };
            grupos.push(grupo);
        }
        // El catálogo fijo solo trae el nombre de la terminal, no su id --
        // se toma de la primera fila real del grupo para poder precargar
        // el botón "+" con ambos selects resueltos.
        if (!grupo.terminalId && fila.terminal_id) grupo.terminalId = fila.terminal_id;
        grupo.filas.push(fila);
        grupo.total += Number(fila.litros) || 0;
    });

    const gruposVisibles = grupos.filter(function (g) {
        if (proveedorFiltroActivo && String(g.supplierId) !== String(proveedorFiltroActivo)) return false;
        // Con un proveedor filtrado por botón, sus tarjetas siempre se ven
        // aunque no tengan nada programado ese día -- "ocultar vacíos" solo
        // aplica quitando ruido de otros proveedores, no al que se pidió ver.
        if (!proveedorFiltroActivo && ocultarVacios && g.filas.length === 0) return false;
        return true;
    });

    if (!gruposVisibles.length) {
        contenedor.html('<p class="text-muted text-center">Sin recepciones programadas para este día.</p>');
        return;
    }

    const maxTotal = Math.max.apply(null, gruposVisibles.map(function (g) { return g.total; }));
    gruposVisibles
        .sort(function (a, b) {
            return (a.supplierNombre + a.terminalNombre).localeCompare(b.supplierNombre + b.terminalNombre);
        })
        .forEach(function (grupo) {
            const mostrarTransportista = !ocultaTransportista(grupo.supplierNombre);
            const mostrarReferenciaNotas = !ocultaReferenciaNotas(grupo.supplierNombre);
            const encabezados = ['Hora', 'Producto', 'Litros', 'Estación'];
            if (mostrarTransportista) encabezados.push('Transportista');
            if (mostrarReferenciaNotas) encabezados.push('Referencia', 'Notas');
            encabezados.push('Acciones');

            const filasHtml = grupo.filas.length
                ? grupo.filas.map(function (f) { return formatearFilaTerminal(f, mostrarTransportista, mostrarReferenciaNotas); }).join('')
                : '<tr><td colspan="' + encabezados.length + '" class="text-muted text-center">Sin recepciones programadas hoy.</td></tr>';

            const titulo = esc(grupo.supplierNombre) + ' — ' + esc(grupo.terminalNombre);
            const color = colorProveedor(grupo.supplierNombre);
            const peso = maxTotal > 0 ? grupo.total / maxTotal : 0;
            // Grupos sin ninguna recepción real ese día no tienen terminalId
            // (solo se conoce el nombre vía GRUPOS_PROVEEDOR_TERMINAL) --
            // se resuelve contra el catálogo de terminales antes de armar
            // los botones que lo necesitan para guardar.
            const terminalId = grupo.terminalId || resolverTerminalId(grupo.terminalNombre);
            const botonAgregar = grupo.supplierId
                ? `<button type="button" class="btn btn-sm btn-outline-success btn-accion-icono btn-agregar-en-grupo" data-supplier-id="${grupo.supplierId}" data-terminal-id="${terminalId || ''}" title="Agregar recepción en ${esc(grupo.supplierNombre)} — ${esc(grupo.terminalNombre)}"><i data-feather="plus"></i></button>`
                : '';
            const botonFilaRapida = grupo.supplierId
                ? `<button type="button" class="btn btn-sm btn-outline-secondary btn-accion-icono btn-fila-rapida" data-supplier-id="${grupo.supplierId}" data-terminal-id="${terminalId || ''}" data-mostrar-transportista="${mostrarTransportista ? '1' : '0'}" data-mostrar-referencia-notas="${mostrarReferenciaNotas ? '1' : '0'}" title="Capturar renglón rápido (como Excel)"><i data-feather="list"></i></button>`
                : '';
            contenedor.append(tarjetaGrupo(titulo, grupo.total, filasHtml, encabezados, color, peso, botonAgregar + botonFilaRapida));
        });
}

function renderPorEstacion(filas) {
    const contenedor = $('#contenedorGrupos');
    contenedor.empty();

    const ocultarVacias = $('#btnOcultarVacias').hasClass('active');
    const grupos = {};
    ESTACIONES.forEach(function (e) {
        grupos[e.Nombre] = { filas: [], total: 0 };
    });
    filas.forEach(function (fila) {
        const nombre = fila.station_nombre || 'Sin estación';
        if (!grupos[nombre]) grupos[nombre] = { filas: [], total: 0 };
        grupos[nombre].filas.push(fila);
        grupos[nombre].total += Number(fila.litros) || 0;
    });

    const nombres = Object.keys(grupos).filter(function (nombre) {
        return !ocultarVacias || grupos[nombre].filas.length > 0;
    }).sort();

    if (!nombres.length) {
        contenedor.html('<p class="text-muted text-center">Sin recepciones programadas para este día.</p>');
        return;
    }

    const maxTotal = Math.max.apply(null, nombres.map(function (n) { return grupos[n].total; }));
    const encabezados = ['Hora', 'Producto', 'Litros', 'Proveedor', 'Terminal', 'Transportista', 'Acciones'];
    nombres.forEach(function (nombre) {
        const grupo = grupos[nombre];
        const filasHtml = grupo.filas.length
            ? grupo.filas.map(formatearFilaEstacion).join('')
            : '<tr><td colspan="' + encabezados.length + '" class="text-muted text-center">Sin recepciones programadas hoy.</td></tr>';
        const peso = maxTotal > 0 ? grupo.total / maxTotal : 0;
        contenedor.append(tarjetaGrupo(esc(nombre), grupo.total, filasHtml, encabezados, COLOR_ESTACION, peso));
    });
}

function actualizarTotalDia(filas) {
    const total = filas.reduce(function (sum, f) { return sum + (Number(f.litros) || 0); }, 0);
    $('#totalLitrosDia').text(total.toLocaleString('es-MX'));
}

function renderizarTodo() {
    const filas = filasFiltradas();
    actualizarTotalDia(filas);
    if (agrupacionActiva === 'estacion') {
        renderPorEstacion(filas);
    } else {
        renderPorTerminal(filas);
    }
    if (window.feather) feather.replace();
}

function cargarDia(fecha) {
    $('#contenedorGrupos').html('<p class="text-muted text-center">Cargando…</p>');
    $.get('/supply/scheduling_day_data', { fecha: fecha })
        .done(function (resp) {
            ultimasFilas = resp.data || [];
            renderizarTodo();
        })
        .fail(function () {
            $('#contenedorGrupos').html('<p class="text-danger text-center">No se pudo cargar la programación.</p>');
        });
}

function formatearFechaLocal(fecha) {
    const anio = fecha.getFullYear();
    const mes = String(fecha.getMonth() + 1).padStart(2, '0');
    const dia = String(fecha.getDate()).padStart(2, '0');
    return `${anio}-${mes}-${dia}`;
}

function abrirModal(id, fecha, precarga) {
    const datos = Object.assign({ id: id || '', fecha: fecha }, precarga || {});
    $.post('/supply/scheduling_modal', datos)
        .done(function (resp) {
            if (!resp.success) {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
                return;
            }
            $('#modalProgramacionContent').html(resp.html);
            $('#modalProgramacionContent .selectpicker').selectpicker();
            $('#product').on('change', function () {
                const esMixta = $(this).val() === 'Mixta';
                $('#mezcla_wrapper').toggle(esMixta);
                if (!esMixta) $('#mezcla').val('');
            });
            const modal = new bootstrap.Modal(document.getElementById('modalProgramacion'));
            modal.show();
        })
        .fail(function () {
            alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
        });
}

function abrirModalFactura(scheduleId) {
    $.post('/supply/scheduling_invoice_modal', { schedule_id: scheduleId })
        .done(function (resp) {
            if (!resp.success) {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario de factura.</p></div>');
                return;
            }
            $('#modalFacturaContent').html(resp.html);
            const modal = new bootstrap.Modal(document.getElementById('modalFactura'));
            modal.show();
        })
        .fail(function () {
            alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario de factura.</p></div>');
        });
}

$(document).ready(function () {
    const fechaInput = $('#fecha_programacion');

    // Esta vista necesita todo el ancho posible (tablas con muchas columnas
    // por tarjeta) -- se colapsa el sidebar solo aquí, sin tocar la
    // preferencia global guardada en localStorage para las demás vistas.
    const sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        sidebar.classList.add('collapsed');
        window.dispatchEvent(new Event('resize'));
    }

    $('.selectpicker').selectpicker();

    renderBotonesProveedor();
    cargarDia(fechaInput.val());

    fechaInput.on('change', function () {
        cargarDia($(this).val());
    });

    $('#tabsAgrupacion button').on('click', function () {
        agrupacionActiva = $(this).data('agrupacion');
        $('#tabsAgrupacion button').removeClass('active');
        $(this).addClass('active');
        $('#btnOcultarVacias').toggle(agrupacionActiva === 'estacion');
        $('#btnOcultarVaciosTerminal').toggle(agrupacionActiva === 'terminal');
        renderizarTodo();
    });

    $('#btnOcultarVacias').on('click', function () {
        const activo = $(this).toggleClass('active').hasClass('active');
        $(this).attr('title', activo ? 'Mostrar estaciones sin recepción programada' : 'Ocultar estaciones sin recepción programada');
        $(this).find('i').attr('data-feather', activo ? 'eye-off' : 'eye');
        if (window.feather) feather.replace();
        renderizarTodo();
    });

    $('#btnOcultarVaciosTerminal').on('click', function () {
        const activo = $(this).toggleClass('active').hasClass('active');
        $(this).attr('title', activo ? 'Mostrar grupos sin recepción programada' : 'Ocultar grupos sin recepción programada');
        $(this).find('i').attr('data-feather', activo ? 'eye-off' : 'eye');
        if (window.feather) feather.replace();
        renderizarTodo();
    });

    $(document).on('click', '.btn-filtro-proveedor', function () {
        const id = $(this).data('supplier-id');
        proveedorFiltroActivo = (String(proveedorFiltroActivo) === String(id)) ? null : id;
        renderBotonesProveedor();
        renderizarTodo();
    });

    $('.btn-cols').on('click', function () {
        colsActivas = parseInt($(this).data('cols'), 10);
        $('.btn-cols').removeClass('active');
        $(this).addClass('active');
        renderizarTodo();
    });

    $('#btnDiaAnterior').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() - 1);
        fechaInput.val(formatearFechaLocal(fecha)).trigger('change');
    });

    $('#btnDiaSiguiente').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() + 1);
        fechaInput.val(formatearFechaLocal(fecha)).trigger('change');
    });

    $('#btnAgregarRecepcion').on('click', function () {
        abrirModal(null, fechaInput.val());
    });

    $(document).on('click', '.btn-editar-recepcion', function () {
        abrirModal($(this).data('id'), fechaInput.val());
    });

    $(document).on('click', '.btn-factura-recepcion', function () {
        abrirModalFactura($(this).data('id'));
    });

    $(document).on('click', '#btnSubirFactura', function () {
        const boton = $(this);
        const scheduleId = $('#factura_schedule_id').val();
        const pdfFile = $('#factura_pdf')[0].files[0];
        const xmlFile = $('#factura_xml')[0].files[0];
        const errorBox = $('#facturaMensajeError');

        errorBox.hide().text('');

        if (!pdfFile || !xmlFile) {
            errorBox.text('Selecciona ambos archivos (PDF y XML).').show();
            return;
        }

        const datos = new FormData();
        datos.append('schedule_id', scheduleId);
        datos.append('pdf', pdfFile);
        datos.append('xml', xmlFile);

        boton.prop('disabled', true);

        $.ajax({
            url: '/supply/scheduling_upload_invoice',
            method: 'POST',
            data: datos,
            processData: false,
            contentType: false,
        })
            .done(function (resp) {
                if (!resp.success) {
                    errorBox.text(resp.message || 'No se pudo guardar la factura.').show();
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('modalFactura')).hide();
                if (resp.advertencia_rfc) {
                    alertify.myAlert('<div class="text-warning text-center"><p>' + esc(resp.advertencia_rfc) + '</p></div>');
                }
                cargarDia($('#fecha_programacion').val());
            })
            .fail(function () {
                errorBox.text('No se pudo guardar la factura.').show();
            })
            .always(function () {
                boton.prop('disabled', false);
            });
    });

    $(document).on('click', '#btnReemplazarFactura', function () {
        const scheduleId = $('#factura_schedule_id').val();
        if (!confirm('¿Quitar la factura vinculada a esta recepción? La factura seguirá existiendo en el sistema, solo se quita el vínculo.')) return;
        $.post('/supply/scheduling_invoice_unlink', { schedule_id: scheduleId })
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo quitar el vínculo.</p></div>');
                    return;
                }
                abrirModalFactura(scheduleId);
                cargarDia($('#fecha_programacion').val());
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo quitar el vínculo.</p></div>');
            });
    });

    $(document).on('click', '.btn-agregar-en-grupo', function () {
        abrirModal(null, fechaInput.val(), {
            supplier_id: $(this).data('supplier-id') || '',
            terminal_id: $(this).data('terminal-id') || '',
        });
    });

    $(document).on('click', '.btn-fila-rapida', function () {
        const boton = $(this);
        const tbody = boton.closest('.card').find('tbody');
        // La tarjeta puede seguir mostrando el placeholder "Sin recepciones
        // programadas hoy" -- se limpia antes de insertar la fila editable.
        tbody.find('td.text-muted.text-center').closest('tr').remove();
        tbody.append(filaRapidaHtml(
            boton.data('supplier-id'),
            boton.data('terminal-id'),
            boton.data('mostrar-transportista') === '1' || boton.data('mostrar-transportista') === 1,
            boton.data('mostrar-referencia-notas') === '1' || boton.data('mostrar-referencia-notas') === 1
        ));
        const filaNueva = tbody.find('tr.fila-rapida:last');
        filaNueva.data('mostrar-transportista', boton.data('mostrar-transportista') === '1' || boton.data('mostrar-transportista') === 1);
        filaNueva.data('mostrar-referencia-notas', boton.data('mostrar-referencia-notas') === '1' || boton.data('mostrar-referencia-notas') === 1);
        filaNueva.find('select.selectpicker').selectpicker();
        if (window.feather) feather.replace();
        filaNueva.find('input[data-campo="hora"]').trigger('focus');
    });

    $(document).on('click', '.btn-cancelar-fila-rapida', function () {
        const fila = $(this).closest('tr.fila-rapida');
        const tbody = fila.closest('tbody');
        fila.remove();
        if (!tbody.find('tr').length) {
            const colspan = tbody.closest('table').find('thead th').length || 1;
            tbody.html('<tr><td colspan="' + colspan + '" class="text-muted text-center">Sin recepciones programadas hoy.</td></tr>');
        }
    });

    $(document).on('click', '.btn-guardar-fila-rapida', function () {
        const boton = $(this);
        const fila = boton.closest('tr.fila-rapida');
        if (fila.data('guardando')) return;

        const litros = parseInt(fila.find('[data-campo="litros"]').val(), 10) || 0;
        const stationCode = fila.find('[data-campo="station_code"]').val();
        if (litros <= 0 || !stationCode) {
            alertify.myAlert('<div class="text-danger text-center"><p>Captura al menos Litros y Estación.</p></div>');
            return;
        }

        const datos = {
            fecha: fechaInput.val(),
            supplier_id: fila.data('supplier-id'),
            terminal_id: fila.data('terminal-id'),
            station_code: stationCode,
            product: fila.find('[data-campo="product"]').val() || 'Regular',
            litros: litros,
            hora: fila.find('[data-campo="hora"]').val() || '',
            carrier_id: fila.find('[data-campo="carrier_id"]').val() || '',
            referencia: fila.find('[data-campo="referencia"]').val() || '',
            notas: fila.find('[data-campo="notas"]').val() || '',
        };

        fila.data('guardando', true);
        boton.prop('disabled', true);

        $.post('/supply/scheduling_add', datos)
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
                    return;
                }
                // La fila editable se reemplaza por la fila normal ya
                // formateada (con sus botones Editar/Cancelar) -- sin
                // reconstruir toda la tarjeta, para no perder el lugar si
                // el usuario sigue capturando otro renglón después.
                const filaGuardada = Object.assign({}, datos, {
                    id: resp.id,
                    carrier_nombre: TRANSPORTISTAS_INLINE.find(function (t) { return String(t.id) === String(datos.carrier_id); })?.nombre || null,
                    station_nombre: ESTACIONES_INLINE.find(function (e) { return String(e.Codigo) === String(datos.station_code); })?.Nombre || null,
                });
                fila.replaceWith(formatearFilaTerminal(filaGuardada, fila.data('mostrar-transportista'), fila.data('mostrar-referencia-notas')));
                if (window.feather) feather.replace();

                $.get('/supply/scheduling_day_data', { fecha: fechaInput.val() }).done(function (dayResp) {
                    ultimasFilas = dayResp.data || [];
                    actualizarTotalDia(filasFiltradas());
                });
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
            })
            .always(function () {
                fila.data('guardando', false);
                boton.prop('disabled', false);
            });
    });

    // Filas rápidas sin guardar son estado de edición efímero -- al cambiar
    // de día se descartan igual que se descartaría un formulario a medio
    // llenar.
    fechaInput.on('change', function () {
        $('tr.fila-rapida').remove();
    });

    $(document).on('click', '.btn-cancelar-recepcion', function () {
        const id = $(this).data('id');
        if (!confirm('¿Cancelar esta recepción programada?')) return;
        $.post('/supply/scheduling_cancel', { id: id })
            .done(function () { cargarDia(fechaInput.val()); })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo cancelar.</p></div>');
            });
    });

    $(document).on('submit', '#frmProgramacion', function (e) {
        e.preventDefault();
        const datos = $(this).serialize();
        const id = $('#id').val();
        const url = id ? '/supply/scheduling_update' : '/supply/scheduling_add';
        $.post(url, datos)
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('modalProgramacion')).hide();
                cargarDia(fechaInput.val());
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
            });
    });

    // El modal de captura (Bootstrap) atrapa el foco del teclado dentro de
    // sí mismo -- si se abre un alertify.prompt encima sin desactivar ese
    // focus trap, el navegador regresa el foco al modal en cada tecla y el
    // input del prompt no recibe nada de lo que se escribe.
    function promptSobreModal(titulo, mensaje, onOk) {
        const modalEl = document.getElementById('modalProgramacion');
        const modalInstancia = bootstrap.Modal.getInstance(modalEl);
        const focusTrap = modalInstancia && modalInstancia._focustrap;
        if (focusTrap) focusTrap.deactivate();

        alertify.prompt(
            titulo,
            mensaje,
            '',
            function (evt, valor) {
                if (focusTrap) focusTrap.activate();
                onOk((valor || '').trim());
            },
            function () {
                if (focusTrap) focusTrap.activate();
            }
        );
    }

    $(document).on('click', '#btnNuevaTerminal', function () {
        promptSobreModal('Nueva terminal', 'Nombre de la terminal / base de carga:', function (nombre) {
            if (!nombre) return;
            $.post('/supply/scheduling_add_terminal', { nombre: nombre })
                .done(function (resp) {
                    if (!resp.success) return;
                    $('#terminal_id').append(`<option value="${resp.id}" selected>${esc(resp.nombre)}</option>`);
                    $('#terminal_id').selectpicker('refresh');
                })
                .fail(function () {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo crear la terminal.</p></div>');
                });
        });
    });

    $(document).on('click', '#btnNuevoTransportista', function () {
        promptSobreModal('Nuevo transportista', 'Nombre del transportista:', function (nombre) {
            if (!nombre) return;
            $.post('/supply/scheduling_add_carrier', { nombre: nombre })
                .done(function (resp) {
                    if (!resp.success) return;
                    $('#carrier_id').append(`<option value="${resp.id}" selected>${esc(resp.nombre)}</option>`);
                    $('#carrier_id').selectpicker('refresh');
                })
                .fail(function () {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo crear el transportista.</p></div>');
                });
        });
    });
});
