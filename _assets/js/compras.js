(function ($) {
    'use strict';

    function escHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmt(n, dec) {
        if (n === null || n === undefined || n === '') return '';
        return Number(n).toLocaleString('es-MX', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    function fecha(s) {
        if (!s) return '';
        var p = s.split('-');
        return p[2] + '-' + p[1] + '-' + p[0];
    }

    function aviso(tipo, msg) {
        if (window.alertify) { alertify[tipo](msg); } else { window.alert(msg); }
    }

    /* ------------------------------------------------------------------ */
    /* Definición de columnas (orden del Excel COMPRAS; "ex" = columna)    */
    /* ------------------------------------------------------------------ */

    // 'display' y 'filter' devuelven lo que ve el usuario (así el filtro por columna busca lo que está escrito);
    // 'sort' y 'type' devuelven el valor crudo.
    function ver(t) { return t === 'display' || t === 'filter'; }

    var T = {
        txt: function (v, t) { return t === 'display' ? escHtml(v) : (v === null || v === undefined ? '' : v); },
        fecha: function (v, t) { return ver(t) ? fecha(v) : v; },
        n0: function (v, t) { return ver(t) ? fmt(v, 0) : v; },
        n2: function (v, t) { return ver(t) ? fmt(v, 2) : v; },
        n4: function (v, t) { return ver(t) ? fmt(v, 4) : v; },
        pct: function (v, t) { return ver(t) && v !== null && v !== undefined && v !== '' ? fmt(v * 100, 0) + '%' : v; },
        sino: function (v, t) { return ver(t) ? (v === null || v === undefined ? '' : (v ? 'Sí' : 'No')) : v; }
    };

    // edit: { tipo: texto|fecha|numero|estacion|producto, campo | campoFactura+campoManual, soloManual }
    var COLS = [
        { k: 'fecha', t: 'Fecha factura', r: T.fecha, edit: { tipo: 'fecha', campo: 'fecha', soloManual: true } },
        { k: 'fecha_descarga', t: 'Fecha de descarga', ex: 'A', r: T.fecha, edit: { tipo: 'fecha', campo: 'fecha_descarga' } },
        { k: 'mes', t: 'Mes', ex: 'B', r: T.txt },
        { k: 'anio', t: 'Año', ex: 'C', r: T.txt },
        { k: 'estacion_num', t: '# Estación', ex: 'D', r: T.txt, falta: 'sin_estacion' },
        { k: 'estacion', t: 'Estación', ex: 'AO', falta: 'sin_estacion', edit: { tipo: 'estacion', campo: 'est_codgas' },
          r: function (v, t, r) { return r.sin_estacion ? 'Sin estación' : T.txt(v, t); } },
        { k: 'proveedor', t: 'Proveedor', ex: 'E', r: T.txt, edit: { tipo: 'texto', campo: 'proveedor', soloManual: true } },
        { k: 'ciudad', t: 'Ciudad', ex: 'F', r: T.txt },
        { k: 'zona', t: 'Zona', ex: 'G', r: T.txt },
        { k: 'producto', t: 'Producto', ex: 'H', edit: { tipo: 'producto', campo: 'producto', soloManual: true },
          r: function (v, t, r) { return T.txt(v, t) + (r.varios_productos ? ' (+)' : ''); } },
        { k: 'pago_programado', t: 'Pago programado', ex: 'I', r: T.fecha, edit: { tipo: 'fecha', campo: 'pago_programado' } },
        { k: 'monto_pagado', t: 'Monto pagado', ex: 'J', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'monto_pagado', soloManual: true } },
        { k: 'folio', t: 'Folio factura proveedor', ex: 'K', r: T.txt, edit: { tipo: 'texto', campo: 'folio', soloManual: true } },
        { k: 'remision', t: 'Remisión', r: T.txt, edit: { tipo: 'texto', campo: 'remision', soloManual: true } },
        { k: 'proveedor_final', t: 'Proveedor que factura (final)', ex: 'L', r: T.txt, edit: { tipo: 'texto', campo: 'proveedor_final' } },
        { k: 'litros', t: 'Litros en documentos soporte', ex: 'M', c: 'num', r: T.n0,
          edit: { tipo: 'numero', campoFactura: 'litros_soporte', campoManual: 'litros' } },
        { k: 'monto', t: 'Monto factura', ex: 'N', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'monto', soloManual: true } },
        { k: 'vencimiento', t: 'Vencimiento', ex: 'O', edit: { tipo: 'fecha', campo: 'vencimiento', soloManual: true },
          r: function (v, t, r) { return ver(t) ? (r.dias_credito === 0 ? 'CONTADO' : fecha(v)) : v; } },
        { k: 'saldo', t: 'Saldo factura', ex: 'P', c: 'num', r: T.n2 },
        { k: 'precio_litro', t: 'Precio x litro factura', ex: 'Q', c: 'num', r: T.n4 },
        { k: 'precio_cotizado', t: 'Precio cotizado', ex: 'R', c: 'num', r: T.n4 },
        { k: 'dif_cotizado', t: 'Diferencia', ex: 'S', c: 'num', r: T.n4 },
        { k: 'precio_factura_cotizado', t: 'Precio en factura cotizado', ex: 'T', c: 'num', r: T.n2 },
        { k: 'precio_venta', t: 'Precio venta en estación', ex: 'U', c: 'num', r: T.n2 },
        { k: 'estimulo', t: 'Estímulo (Cd. Juárez)', ex: 'V', c: 'num', r: T.n2 },
        { k: 'iva', t: '% IVA facturado', ex: 'W', c: 'num', r: T.pct, edit: { tipo: 'numero', campo: 'iva', soloManual: true } },
        { k: 'costo_almacen', t: 'Costo almacenamiento', ex: 'X', c: 'num', r: T.n4 },
        { k: 'utilidad_litro', t: 'Utilidad por litro', ex: 'Y', c: 'num', r: T.n4 },
        { k: 'utilidad_factura', t: 'Utilidad litro factura', ex: 'Z', c: 'num', r: T.n2 },
        { k: 'precio_8', t: 'Precio al 8% compra proveedor', ex: 'AA', c: 'num', r: T.n4 },
        { k: 'tar_pemex', t: 'Precio Pemex al 16%', ex: 'AB', c: 'num', r: T.n2 },
        { k: 'dif_tar', t: 'Diferencia vs TAR Pemex', ex: 'AC', c: 'num', r: T.n4 },
        { k: 'subtotal_tesoro', t: 'Subtotal Tesoro', ex: 'AD', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'subtotal_tesoro' } },
        { k: 'descuento_tesoro', t: 'Descuento Tesoro', ex: 'AE', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'descuento_tesoro' } },
        { k: 'factura_petrotal', t: 'F/Factura Petrotal', ex: 'AF', r: T.txt, edit: { tipo: 'texto', campo: 'factura_petrotal' } },
        { k: 'factura_nueva', t: 'Factura nueva', ex: 'AG', r: T.txt, edit: { tipo: 'texto', campo: 'factura_nueva' } },
        { k: 'monto_petrotal', t: 'Monto factura Petrotal', ex: 'AH', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'monto_petrotal' } },
        { k: 'iva_cliente_final', t: '% IVA a cliente final', ex: 'AI', c: 'num', r: T.pct, edit: { tipo: 'numero', campo: 'iva_cliente_final' } },
        { k: 'precio_petrotal', t: 'Precio/litro s/IVA Petrotal a cliente', ex: 'AJ', c: 'num', r: T.n4, edit: { tipo: 'numero', campo: 'precio_petrotal' } },
        { k: 'litros_facturados', t: 'Litros facturados', ex: 'AK', c: 'num', r: T.n0 },
        { k: 'litros_pend_facturar', t: 'Litros pendientes de facturar', ex: 'AL', c: 'num', r: T.n0 },
        { k: 'litros_controlgas', t: 'Litros capturados en ControlGas', ex: 'AM', c: 'num', r: T.n0 },
        { k: 'litros_pend_controlgas', t: 'Litros pendientes de capturar en ControlGas', ex: 'AN', c: 'num', r: T.n0 },
        { k: 'factura_flete', t: 'F/Factura flete', ex: 'AP', r: T.txt, edit: { tipo: 'texto', campo: 'factura_flete' } },
        { k: 'monto_carta_porte', t: 'Monto carta porte', ex: 'AQ', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'monto_carta_porte' } },
        { k: 'total_a_pagar', t: 'Total a pagar', ex: 'AR', c: 'num', r: T.n2, edit: { tipo: 'numero', campo: 'total_a_pagar' } },
        { k: 'transportista', t: 'Transportista', ex: 'AS', r: T.txt, edit: { tipo: 'texto', campo: 'transportista' } },
        { k: 'razon_social_flete', t: 'Razón social flete facturado', ex: 'AT', r: T.txt, edit: { tipo: 'texto', campo: 'razon_social_flete' } },
        { k: 'pago_flete', t: 'Fecha pago programado flete', ex: 'AU', r: T.fecha, edit: { tipo: 'fecha', campo: 'pago_flete' } },
        { k: 'placas', t: 'Placas de transporte / equipo', ex: 'AV', r: T.txt, edit: { tipo: 'texto', campo: 'placas' } },
        { k: 'bol', t: 'BOL / no. embarque', ex: 'AW', r: T.txt, edit: { tipo: 'texto', campo: 'bol' } },
        { k: 'razon_social', t: 'Razón social factura', ex: 'AX', r: T.txt, edit: { tipo: 'texto', campo: 'razon_social', soloManual: true } },
        { k: 'ieps', t: 'IEPS', ex: 'AY', c: 'num', r: T.n4 },
        { k: 'ieps_total', t: 'IEPS total factura proveedor', ex: 'AZ', c: 'num', r: T.n2 },
        { k: 'menos_ieps', t: 'Menos IEPS', ex: 'BA', c: 'num', r: T.n4 },
        { k: 'precio_sin_iva16', t: 'Precio sin IVA 16%', ex: 'BB', c: 'num', r: T.n4 },
        { k: 'por_iva08', t: 'Por IVA 0.08', ex: 'BC', c: 'num', r: T.n4 },
        { k: 'precio_c_iva8_ieps', t: 'Precio c/IVA 8% y c/IEPS', ex: 'BD', c: 'num', r: T.n4 },
        { k: 'monto_sin_iva', t: 'Monto factura proveedor original sin IVA', ex: 'BE', c: 'num', r: T.n2 },
        { k: 'monto_petrotal_sin_iva', t: 'Monto factura Petrotal sin IVA', ex: 'BF', c: 'num', r: T.n2 },
        { k: 'utilidad_petrotal', t: 'Utilidad Petrotal', ex: 'BG', c: 'num', r: T.n2 },
        { k: 'observaciones', t: 'Observaciones', ex: 'BH', r: T.txt, edit: { tipo: 'texto', campo: 'observaciones' } },
        { k: 'en_pagos', t: 'En pagos', r: T.sino },
        { k: 'en_controlgas', t: 'En ControlGas', r: T.sino, falta: 'cg' },
        { k: 'origen', t: 'Origen', r: function (v, t) { return t === 'sort' || t === 'type' ? v : (v === 'manual' ? 'Manual' : 'Factura'); } }
    ];

    // Columnas siempre visibles + las de cada perfil (claves de COLS).
    var FIJAS = ['fecha_descarga', 'estacion', 'proveedor', 'folio'];
    var PERFILES = [
        { id: 'captura', nombre: 'Captura', cols: ['producto', 'proveedor_final', 'litros', 'monto', 'razon_social', 'vencimiento', 'pago_programado', 'placas', 'bol', 'transportista', 'observaciones'] },
        { id: 'pagos', nombre: 'Pagos', cols: ['fecha', 'producto', 'monto', 'razon_social', 'vencimiento', 'pago_programado', 'monto_pagado', 'saldo', 'en_pagos', 'en_controlgas'] },
        { id: 'analisis', nombre: 'Análisis', cols: ['mes', 'anio', 'ciudad', 'zona', 'producto', 'litros', 'monto', 'precio_litro', 'precio_venta', 'estimulo', 'iva', 'costo_almacen', 'utilidad_litro', 'utilidad_factura', 'precio_8'] },
        { id: 'ieps', nombre: 'IEPS', cols: ['producto', 'litros', 'monto', 'precio_litro', 'iva', 'ieps', 'ieps_total', 'menos_ieps', 'precio_sin_iva16', 'por_iva08', 'precio_c_iva8_ieps', 'monto_sin_iva'] },
        { id: 'petrotal', nombre: 'Petrotal', cols: ['producto', 'litros', 'monto', 'proveedor_final', 'factura_petrotal', 'factura_nueva', 'monto_petrotal', 'iva_cliente_final', 'precio_petrotal', 'litros_facturados', 'litros_pend_facturar', 'costo_almacen', 'monto_sin_iva', 'monto_petrotal_sin_iva', 'utilidad_petrotal'] },
        { id: 'tesoro', nombre: 'Tesoro', cols: ['producto', 'litros', 'monto', 'subtotal_tesoro', 'descuento_tesoro', 'bol', 'transportista', 'placas'] },
        { id: 'controlgas', nombre: 'ControlGas', cols: ['producto', 'litros', 'litros_facturados', 'litros_controlgas', 'litros_pend_controlgas', 'en_controlgas'] },
        { id: 'fletes', nombre: 'Fletes', cols: ['transportista', 'placas', 'bol', 'factura_flete', 'monto_carta_porte', 'total_a_pagar', 'razon_social_flete', 'pago_flete'] },
        { id: 'completa', nombre: 'Completa', cols: null }
    ];
    var PERFIL_DEFAULT = 'captura';

    var tabla = null;
    var idx = {};          // clave -> índice de columna
    var perfilActual = PERFIL_DEFAULT;

    COLS.forEach(function (c, i) { idx[c.k] = i; });

    function filtros() {
        return {
            desde: $('#desde').val(),
            hasta: $('#hasta').val(),
            proveedor: $('#proveedor').val(),
            codgas: $('#codgas').val(),
            producto: $('#producto').val(),
            faltantes: $('#faltantes').is(':checked') ? 1 : ''
        };
    }

    function totales(rows) {
        var litros = 0, monto = 0, utilidad = 0, sinEst = 0, sinCg = 0, manuales = 0;
        rows.forEach(function (r) {
            litros += r.litros; monto += r.monto;
            if (r.utilidad_factura) utilidad += r.utilidad_factura;
            if (r.sin_estacion) sinEst++;
            if (r.en_controlgas === false) sinCg++;
            if (r.origen === 'manual') manuales++;
        });
        $('#comprasTotales').html(
            '<b>' + rows.length + '</b> renglones · <b>' + fmt(litros, 0) + '</b> litros · <b>$' + fmt(monto, 2) + '</b>' +
            ' · utilidad (con precio de venta): <b>$' + fmt(utilidad, 2) + '</b>' +
            ' · sin estación: <b>' + sinEst + '</b> · sin ControlGas: <b>' + sinCg + '</b>' +
            (manuales ? ' · manuales: <b>' + manuales + '</b>' : '')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Perfiles                                                            */
    /* ------------------------------------------------------------------ */

    function guardarPerfil(id) {
        try { window.localStorage.setItem('compras_perfil', id); } catch (e) { /* sin almacenamiento */ }
    }

    function perfilGuardado() {
        try {
            var id = window.localStorage.getItem('compras_perfil');
            if (id && PERFILES.some(function (p) { return p.id === id; })) return id;
        } catch (e) { /* sin almacenamiento */ }
        return PERFIL_DEFAULT;
    }

    function aplicarPerfil(id) {
        var perfil = PERFILES.filter(function (p) { return p.id === id; })[0] || PERFILES[0];
        perfilActual = perfil.id;
        var visibles = perfil.cols === null ? null : FIJAS.concat(perfil.cols);
        tabla.columns().every(function (i) {
            this.visible(visibles === null ? true : visibles.indexOf(COLS[i].k) !== -1, false);
        });
        tabla.columns.adjust();
        reconstruirFiltros();
        $('#comprasPerfiles button').removeClass('btn-primary').addClass('btn-outline-primary');
        $('#comprasPerfiles button[data-perfil="' + perfil.id + '"]').removeClass('btn-outline-primary').addClass('btn-primary');
        guardarPerfil(perfil.id);
    }

    /* ------------------------------------------------------------------ */
    /* Fila de filtros por columna (segunda fila del encabezado)           */
    /* ------------------------------------------------------------------ */

    // DataTables solo administra la primera fila del thead; la de filtros se reconstruye con las
    // columnas visibles cada vez que cambia el perfil o "Columnas" (mismo patrón que payment_list).
    function reconstruirFiltros() {
        var $head = $('#tblCompras thead');
        var valores = {};
        $head.find('tr.filter th input').each(function () {
            valores[$(this).closest('th').data('col-idx')] = this.value;
        });
        $head.find('tr.filter').remove();

        var $fila = $('<tr class="filter"></tr>');
        tabla.columns().every(function (i) {
            if (!this.visible()) {
                // Una columna oculta no debe seguir filtrando sin que se vea el filtro
                if (this.search()) this.search('');
                return;
            }
            var $th = $('<th></th>').attr('data-col-idx', i);
            if (i < COLS.length) {
                var $in = $('<input type="text" class="form-control form-control-sm" autocomplete="off">')
                    .attr('placeholder', 'Filtrar');
                if (valores[i] !== undefined) $in.val(valores[i]);
                $th.append($in);
            }
            $fila.append($th);
        });
        $head.append($fila);
        anclarFiltros();
        ajustarScrollSuperior();
        $fila.find('input').on('keyup change', function () {
            var col = $(this).closest('th').data('col-idx');
            var v = this.value;
            if (tabla.column(col).search() !== v) tabla.column(col).search(v).draw();
        }).on('click', function (e) { e.stopPropagation(); });
        tabla.draw(false);
    }

    // El encabezado queda pegado arriba del contenedor con scroll: la fila de filtros se ancla justo
    // debajo de la de títulos (su alto varía según cuánto se envuelvan los títulos).
    function anclarFiltros() {
        var h = $('#tblCompras thead tr:first-child th').first().outerHeight() || 0;
        $('#tblCompras thead tr.filter th').css('top', h + 'px');
    }

    // Barra de scroll horizontal arriba de la tabla, sincronizada con la de abajo.
    function ajustarScrollSuperior() {
        var $t = $('#comprasScrollTop');
        var ancho = $('#tblCompras').outerWidth() || 0;
        $t.children().width(ancho);
        $t.toggle(ancho > $('#comprasScroll').innerWidth());
    }

    function enlazarScrolls() {
        var $arriba = $('#comprasScrollTop'), $cuerpo = $('#comprasScroll'), bloqueo = false;
        $arriba.on('scroll', function () {
            if (bloqueo) { bloqueo = false; return; }
            bloqueo = true; $cuerpo.scrollLeft($arriba.scrollLeft());
        });
        $cuerpo.on('scroll', function () {
            if (bloqueo) { bloqueo = false; return; }
            bloqueo = true; $arriba.scrollLeft($cuerpo.scrollLeft());
        });
    }

    function dibujarPerfiles() {
        var $g = $('#comprasPerfiles').empty();
        PERFILES.forEach(function (p) {
            $g.append($('<button type="button" class="btn btn-outline-primary"></button>').attr('data-perfil', p.id).text(p.nombre));
        });
        $g.on('click', 'button', function () { aplicarPerfil($(this).data('perfil')); });
    }

    /* ------------------------------------------------------------------ */
    /* Edición en celda                                                    */
    /* ------------------------------------------------------------------ */

    function campoDe(cfg, r) {
        if (cfg.campo) return cfg.campo;
        return r.origen === 'manual' ? cfg.campoManual : cfg.campoFactura;
    }

    function editable(cfg, r) {
        return COMPRAS_CTX.puedeEditar && cfg && !(cfg.soloManual && r.origen !== 'manual');
    }

    function valorActual(cfg, campo, r) {
        var v;
        if (campo === 'est_codgas') v = r.est_codgas;
        else if (campo === 'litros_soporte') v = r.litros;
        else if (campo === 'iva' || campo === 'iva_cliente_final') v = (r[campo] === null || r[campo] === undefined) ? '' : Math.round(r[campo] * 10000) / 100;
        else v = r[campo];
        return v === null || v === undefined ? '' : v;
    }

    function construirInput(cfg, valor) {
        var $i;
        if (cfg.tipo === 'estacion' || cfg.tipo === 'producto') {
            $i = $('<select class="form-control form-control-sm compras-input"></select>').append('<option value="">—</option>');
            var opciones = cfg.tipo === 'estacion'
                ? COMPRAS_CTX.estaciones.map(function (e) { return { v: e.cod, t: e.nombre }; })
                : ['REGULAR', 'SUPER', 'DIESEL'].map(function (p) { return { v: p, t: p }; });
            opciones.forEach(function (o) { $i.append($('<option></option>').val(o.v).text(o.t)); });
            $i.val(String(valor));
        } else {
            $i = $('<input class="form-control form-control-sm compras-input">')
                .attr('type', cfg.tipo === 'fecha' ? 'date' : 'text').val(valor);
            if (cfg.tipo === 'numero') $i.attr('inputmode', 'decimal');
        }
        return $i;
    }

    function guardar(r, campo, nuevo, td) {
        var datos = { origen: r.origen, campo: campo, valor: nuevo };
        if (r.origen === 'factura') datos.uuid = r.uuid; else datos.id = r.id;
        $(td).addClass('compras-guardando');
        return $.post(COMPRAS_CTX.urlGuardar, datos)
            .done(function () { tabla.ajax.reload(null, false); })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo guardar';
                aviso('error', escHtml(msg));
                $(td).removeClass('compras-guardando');
                tabla.cell(td).invalidate().draw(false);
            });
    }

    function abrirEditor(td) {
        var $td = $(td);
        if ($td.find('.compras-input').length || $td.hasClass('compras-guardando')) return;
        var cell = tabla.cell(td);
        var cfg = COLS[cell.index().column].edit;
        var r = tabla.row(td).data();
        var campo = campoDe(cfg, r);
        var original = valorActual(cfg, campo, r);
        var $i = construirInput(cfg, original);
        var terminado = false;

        function cerrar(confirmar) {
            if (terminado) return;
            terminado = true;
            var nuevo = $.trim($i.val());
            if (!confirmar || String(nuevo) === String(original)) {
                cell.invalidate().draw(false);
                return;
            }
            guardar(r, campo, nuevo, td);
        }

        $td.empty().append($i);
        $i.on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); cerrar(true); }
            else if (e.key === 'Escape') { e.preventDefault(); cerrar(false); }
        }).on('blur', function () { cerrar(true); }).trigger('focus');
    }

    /* ------------------------------------------------------------------ */
    /* Tabla                                                               */
    /* ------------------------------------------------------------------ */

    function columnasDT() {
        return COLS.map(function (c) {
            var def = { data: c.k, render: c.r, className: c.c || '' };
            def.createdCell = function (td, _v, r) {
                if (c.falta === 'sin_estacion' && r.sin_estacion) $(td).addClass('compras-falta');
                if (c.falta === 'cg' && r.en_controlgas === false) $(td).addClass('compras-falta');
                if (editable(c.edit, r)) $(td).addClass('compras-edit').attr('title', 'Clic para capturar');
            };
            return def;
        }).concat([{
            data: null, orderable: false, searchable: false, className: 'text-nowrap',
            render: function (_v, _t, r) {
                return COMPRAS_CTX.puedeEditar && r.origen === 'manual'
                    ? '<button type="button" class="btn btn-sm btn-outline-danger btn-eliminar-manual" title="Eliminar renglón manual"><i class="fas fa-trash"></i></button>'
                    : '';
            }
        }]);
    }

    function dibujarEncabezados() {
        var $tr = $('#tblCompras thead tr').empty();
        COLS.forEach(function (c) {
            $tr.append($('<th></th>').html(escHtml(c.t) + (c.ex ? ' <span class="compras-ex">' + c.ex + '</span>' : '')));
        });
        $tr.append('<th></th>');
    }

    function iniciar() {
        dibujarEncabezados();
        dibujarPerfiles();

        tabla = $('#tblCompras').DataTable({
            dom: '<"d-flex justify-content-between align-items-center mb-2"Bf>rt<"mt-2 small"i>',
            paging: false,
            language: { info: 'Mostrando _TOTAL_ renglones', infoFiltered: ' (filtrados de _MAX_)', infoEmpty: 'Sin renglones', emptyTable: 'Sin facturas en el rango', processing: 'Cargando…' },
            processing: true,
            order: [[idx.fecha_descarga, 'asc']],
            buttons: [
                { extend: 'excel', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-sm btn-success', title: 'Registro de compras', exportOptions: { columns: ':visible:not(:last-child)' } },
                { extend: 'colvis', text: '<i class="fas fa-columns"></i> Columnas', className: 'btn btn-sm btn-outline-secondary', columns: ':not(:last-child)' }
            ],
            ajax: function (_d, callback) {
                $('#btnBuscar').prop('disabled', true);
                $.getJSON(COMPRAS_CTX.url, filtros())
                    .done(function (resp) {
                        var rows = resp.data || [];
                        totales(rows);
                        callback({ data: rows });
                    })
                    .fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo cargar el registro';
                        $('#comprasTotales').html('<span class="text-danger">' + escHtml(msg) + '</span>');
                        callback({ data: [] });
                    })
                    .always(function () { $('#btnBuscar').prop('disabled', false); });
            },
            columns: columnasDT(),
            initComplete: function () { aplicarPerfil(perfilGuardado()); }
        });

        enlazarScrolls();
        tabla.on('draw.dt', function () { anclarFiltros(); ajustarScrollSuperior(); });
        $(window).on('resize', function () { anclarFiltros(); ajustarScrollSuperior(); });

        tabla.on('column-visibility.dt', function (_e, _s, _col, _estado, recalcular) {
            if (recalcular !== false) reconstruirFiltros();
        });

        $('#tblCompras tbody').on('click', 'td.compras-edit', function () { abrirEditor(this); });

        $('#tblCompras tbody').on('click', '.btn-eliminar-manual', function () {
            var r = tabla.row($(this).closest('tr')).data();
            if (!window.confirm('¿Eliminar el renglón manual del ' + fecha(r.fecha) + ' (' + (r.proveedor || '') + ')?')) return;
            $.post(COMPRAS_CTX.urlEliminar, { id: r.id })
                .done(function () { tabla.ajax.reload(null, false); })
                .fail(function (xhr) { aviso('error', escHtml((xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo eliminar')); });
        });
    }

    function iniciarManual() {
        var modal = new bootstrap.Modal(document.getElementById('mdlManual'));
        $('#btnNuevoManual').on('click', function () {
            $('#frmManual')[0].reset();
            modal.show();
        });
        $('#frmManual').on('submit', function (e) {
            e.preventDefault();
            var $btn = $(this).find('button[type=submit]').prop('disabled', true);
            $.post(COMPRAS_CTX.urlCrear, $(this).serialize())
                .done(function () {
                    modal.hide();
                    aviso('success', 'Renglón agregado');
                    tabla.ajax.reload(null, false);
                })
                .fail(function (xhr) {
                    aviso('error', escHtml((xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo guardar'));
                })
                .always(function () { $btn.prop('disabled', false); });
        });
    }

    $(function () {
        iniciar();
        if (COMPRAS_CTX.puedeEditar && document.getElementById('mdlManual')) iniciarManual();
        $('#frmCompras').on('submit', function (e) {
            e.preventDefault();
            tabla.ajax.reload();
        });
    });
})(jQuery);
