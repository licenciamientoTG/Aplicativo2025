/**
 * Pagos extraordinarios — captura (index / form / detail).
 * Las URLs llegan por data-attributes generados con url() de Twig.
 */
(function ($) {
    'use strict';

    // ---------------- Cancelar (index y detalle) ----------------
    $(document).on('click', '.btn-cancelar', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        var redirect = $btn.data('redirect');
        alertify.confirm('Cancelar solicitud', '¿Seguro que desea cancelar esta solicitud?', function () {
            $btn.prop('disabled', true);
            $.ajax({ url: url, type: 'POST', dataType: 'json' })
                .done(function (resp) {
                    if (resp.success) {
                        alertify.success(resp.message);
                        if (redirect) { window.location.href = redirect; } else { window.location.reload(); }
                    } else {
                        alertify.error(resp.message || 'No se pudo cancelar');
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Error de comunicación';
                    alertify.error(msg);
                    $btn.prop('disabled', false);
                });
        }, function () {});
    });

    // ---------------- Bandejas (inbox): modales por vista parcial ----------------
    function escHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function errorDe(xhr, porDefecto) {
        var msg = (xhr.responseJSON && xhr.responseJSON.message) || (xhr.responseText || '').substring(0, 200) || porDefecto;
        return escHtml(msg);
    }

    // Abre el modal: el servidor devuelve HTML ya escapado por Twig (sin layout)
    $(document).on('click', '.btn-ep-modal', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.ajax({ url: $btn.data('url'), type: 'GET', dataType: 'html' })
            .done(function (content) {
                $('#modal_ep_content').html(content);
                $('#modal_ep').modal('show');
            })
            .fail(function (xhr) {
                alertify.error(errorDe(xhr, 'No se pudo abrir la solicitud'));
            })
            .always(function () { $btn.prop('disabled', false); });
    });

    function resolver(resp, $botones) {
        if (resp.success) {
            alertify.success(escHtml(resp.message));
            $('#modal_ep').modal('hide');
            window.location.reload();
        } else {
            alertify.error(escHtml(resp.message || 'No se pudo completar la acción'));
            $botones.prop('disabled', false);
        }
    }

    // Aprobar / Rechazar (el servidor re-valida permiso y estatus)
    $(document).on('click', '.btn-ep-decidir', function () {
        var $btn = $(this);
        var $todos = $('.btn-ep-decidir');
        var comentario = $.trim($('#ep_comentario').val());
        if ($btn.data('rechazo') && comentario === '') {
            alertify.warning('Escriba el comentario del rechazo.');
            $('#ep_comentario').focus();
            return;
        }
        $todos.prop('disabled', true); // evita el doble clic
        $.ajax({ url: $btn.data('url'), type: 'POST', dataType: 'json', data: { comentario: comentario } })
            .done(function (resp) { resolver(resp, $todos); })
            .fail(function (xhr) {
                alertify.error(errorDe(xhr, 'Error de comunicación'));
                $todos.prop('disabled', false);
            });
    });

    // Pago de Tesorería
    $(document).on('submit', '#form_ep_pago', function (e) {
        e.preventDefault();
        var $f = $(this);
        var $btn = $('#btn_ep_pagar');
        $btn.prop('disabled', true);
        $.ajax({ url: $f.data('url'), type: 'POST', dataType: 'json', data: $f.serialize() })
            .done(function (resp) {
                if (resp.success && $f.data('detail-url')) {
                    // La bandeja de Tesorería solo lista autorizadas: se va al detalle para adjuntar el comprobante.
                    alertify.success(escHtml(resp.message));
                    $('#modal_ep').modal('hide');
                    window.location.href = $f.data('detail-url');
                } else {
                    resolver(resp, $btn);
                }
            })
            .fail(function (xhr) {
                alertify.error(errorDe(xhr, 'Error de comunicación'));
                $btn.prop('disabled', false);
            });
    });

    // En el formulario de edición, adjuntar/eliminar recarga la página y se pierden los cambios sin guardar.
    function confirmarPerdidaCambios(accion) {
        if (!document.getElementById('form_extra')) { accion(); return; }
        alertify.confirm('Cambios sin guardar',
            'Al adjuntar o eliminar archivos se recarga la página y se perderán los cambios del formulario que no haya guardado. ¿Continuar?',
            accion, function () {});
    }

    // ---------------- Adjuntos (detalle y formulario de edición) ----------------
    // Subir: el servidor re-valida permiso, estatus, extensión y contenido.
    $(document).on('click', '.ep-doc-upload', function () {
        var $btn = $(this);
        var input = $btn.siblings('.ep-doc-file')[0];
        if (!input || !input.files.length) {
            alertify.warning('Seleccione al menos un archivo.');
            return;
        }
        var fd = new FormData();
        fd.append('kind', $btn.data('kind'));
        Array.prototype.forEach.call(input.files, function (f) { fd.append('documento[]', f); });
        confirmarPerdidaCambios(function () {
            $btn.prop('disabled', true);
            $.ajax({ url: $btn.data('url'), type: 'POST', dataType: 'json', data: fd, processData: false, contentType: false })
                .done(function (resp) {
                    if (resp.success) {
                        alertify.success(escHtml(resp.message));
                        window.location.reload();
                    } else {
                        alertify.error(escHtml(resp.message || 'No se pudo subir').replace(/\n/g, '<br>'));
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function (xhr) {
                    alertify.error(errorDe(xhr, 'Error de comunicación'));
                    $btn.prop('disabled', false);
                });
        });
    });

    // Eliminar (soft-delete)
    $(document).on('click', '.btn-ep-doc-del', function () {
        var $btn = $(this);
        var msg = document.getElementById('form_extra')
            ? '¿Eliminar este documento? Se recargará la página y se perderán los cambios del formulario que no haya guardado.'
            : '¿Eliminar este documento?';
        alertify.confirm('Eliminar documento', msg, function () {
            $btn.prop('disabled', true);
            $.ajax({ url: $btn.data('url'), type: 'POST', dataType: 'json' })
                .done(function (resp) {
                    if (resp.success) {
                        alertify.success(escHtml(resp.message));
                        window.location.reload();
                    } else {
                        alertify.error(escHtml(resp.message || 'No se pudo eliminar'));
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function (xhr) {
                    alertify.error(errorDe(xhr, 'Error de comunicación'));
                    $btn.prop('disabled', false);
                });
        }, function () {});
    });

    // ---------------- Filtros de la lista de solicitudes ----------------
    var $filtros = $('#ep_filtros');
    if ($filtros.length) {
        var vista = $filtros.find('[data-vista="' + $filtros.data('default') + '"]').length
            ? String($filtros.data('default'))
            : String($filtros.find('[data-vista]').first().data('vista'));
        var estatus = ''; // pestaña de estatus activa ('' = todas)

        var valor = function (id) { return $.trim($(id).val()); };

        var aplicarFiltros = function () {
            var empresa = valor('#ep_filtro_empresa');
            var motivo = valor('#ep_filtro_motivo');
            var desde = valor('#ep_filtro_desde');
            var hasta = valor('#ep_filtro_hasta');
            var texto = valor('#ep_filtro_texto').toLowerCase();
            var visibles = 0;
            var total = 0;
            $('#tabla_solicitudes .ep-fila').each(function () {
                var $f = $(this);
                var ok = (vista === 'todas')
                    || (vista === 'mias' && $f.data('propia') == 1)
                    || (vista === 'atender' && $f.data('accion') == 1);
                if (ok && estatus !== '' && String($f.data('status')) !== estatus) { ok = false; }
                if (ok && empresa !== '' && String($f.attr('data-empresas')).indexOf(',' + empresa + ',') === -1) { ok = false; }
                if (ok && motivo !== '' && String($f.data('motivo')) !== motivo) { ok = false; }
                var fecha = String($f.data('fecha'));
                if (ok && desde !== '' && fecha < desde) { ok = false; }
                if (ok && hasta !== '' && fecha > hasta) { ok = false; }
                if (ok && texto !== '' && String($f.attr('data-texto')).indexOf(texto) === -1) { ok = false; }
                $f.toggle(ok);
                if (ok) { visibles++; total += parseFloat($f.data('total')) || 0; }
            });
            $('#ep_sin_resultados').toggle(visibles === 0);
            var hayFiltros = vista !== 'todas' || estatus !== '' || empresa !== '' || motivo !== '' || desde !== '' || hasta !== '' || texto !== '';
            $('#ep_vacio_txt').text(hayFiltros
                ? 'Prueba modificando los filtros o el término de búsqueda.'
                : 'Aún no hay solicitudes registradas.');
            $('#ep_pie_n').text(visibles);
            $('#ep_pie_total').text('$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            $filtros.find('[data-vista]').each(function () {
                $(this).toggleClass('activo', $(this).data('vista') === vista);
            });
            $('#ep_tabs button').each(function () {
                $(this).toggleClass('activo', String($(this).data('status')) === estatus);
            });
            // El export lleva los mismos filtros; el servidor los re-aplica sobre lo que el usuario puede ver
            var $a = $('#ep_exportar');
            if (!$a.data('base')) { $a.data('base', $a.attr('href')); }
            var q = $.param({ vista: vista, status: estatus, empresa_cod: empresa, reason_id: motivo, desde: desde, hasta: hasta, q: valor('#ep_filtro_texto') });
            $a.attr('href', $a.data('base') + '?' + q);
        };

        $filtros.on('click', '[data-vista]', function () { vista = String($(this).data('vista')); aplicarFiltros(); });
        $('#ep_tabs').on('click', 'button', function () { estatus = String($(this).data('status')); aplicarFiltros(); });
        $('#ep_filtro_empresa, #ep_filtro_motivo, #ep_filtro_desde, #ep_filtro_hasta').on('change', aplicarFiltros);
        $('#ep_filtro_texto').on('input', aplicarFiltros);
        $('#ep_limpiar').on('click', function () {
            estatus = '';
            $('#ep_filtros').closest('.card').find('select').val('');
            $('#ep_filtros').closest('.card').find('input').val('');
            aplicarFiltros();
        });
        aplicarFiltros();

        // Clic en la fila abre el detalle (los botones y enlaces de la fila siguen funcionando solos)
        $('#tabla_solicitudes').on('click', '.ep-fila', function (e) {
            if ($(e.target).closest('a, button, input, select, label').length) { return; }
            window.location.href = $(this).data('href');
        });
    }

    // ---------------- Layout bancario (Tesorería, 109) ----------------
    // El tab se carga como vista parcial la primera vez que se abre. Generar el layout no cambia estatus.
    var $layout = $('#ep_layout');
    if ($layout.length) {
        var layoutCargado = false;
        var cuentasCargo = {};

        var dinero = function (n) {
            return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };

        var cargarLayout = function () {
            $layout.html('<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin"></i> Cargando renglones autorizados…</div>');
            $.ajax({ url: $layout.data('url'), type: 'GET', dataType: 'html' })
                .done(function (html) {
                    $layout.html(html);
                    try { cuentasCargo = JSON.parse($('#lay_cuentas_cargo').text() || '{}'); } catch (e) { cuentasCargo = {}; }
                    layoutCargado = true;
                    filtrarLayout();
                })
                .fail(function (xhr) {
                    $layout.html('<div class="alert alert-danger m-3">' + errorDe(xhr, 'No se pudo cargar el layout') + '</div>');
                });
        };

        var filtrarLayout = function () {
            var emp = $('#lay_empresa').val() || '';
            var banco = $('#lay_banco_filtro').val() || '';
            var texto = $.trim($('#lay_texto').val() || '').toLowerCase();
            var visibles = 0;
            $('#tabla_layout .lay-fila').each(function () {
                var $f = $(this);
                var s = $f.attr('data-santander') === '1', b = $f.attr('data-banorte') === '1';
                var ok = (emp === '' || $f.attr('data-empresa') === emp)
                    && (banco === '' || (banco === 'santander' && s) || (banco === 'banorte' && b) || (banco === 'ninguno' && !s && !b))
                    && (texto === '' || String($f.attr('data-busqueda')).indexOf(texto) !== -1);
                $f.toggle(ok);
                if (ok) { visibles++; }
            });
            $('#lay_vacio').toggle(visibles === 0);
            actualizarSeleccion();
        };

        // Recalcula totales, habilita cada banco solo si TODOS los seleccionados caben, y pide
        // la cuenta de cargo cuando una empresa seleccionada tiene más de una en ese banco.
        var actualizarSeleccion = function () {
            var $sel = $('#tabla_layout .lay-fila').filter(function () { return $(this).find('.lay-check').prop('checked'); });
            var total = 0, okS = $sel.length > 0, okB = $sel.length > 0, empresas = {};
            $sel.each(function () {
                var $f = $(this);
                total += parseFloat($f.attr('data-monto')) || 0;
                if ($f.attr('data-santander') !== '1') { okS = false; }
                if ($f.attr('data-banorte') !== '1') { okB = false; }
                empresas[$f.attr('data-empresa')] = true;
            });
            $('#tabla_layout .lay-fila').each(function () { $(this).toggleClass('lay-sel', $(this).find('.lay-check').prop('checked')); });
            $('#lay_n').text($sel.length);
            $('#lay_total').text(dinero(total));
            $('.lay-generar[data-banco="SANTANDER"]').prop('disabled', !okS);
            $('.lay-generar[data-banco="BANORTE"]').prop('disabled', !okB);
            var aviso = '';
            if ($sel.length && !okS && !okB) { aviso = 'Hay renglones seleccionados que no caben en ningún banco (pase el cursor sobre la ✗ para ver por qué).'; }
            else if ($sel.length && (!okS || !okB)) { aviso = 'Solo ' + (okS ? 'Santander' : 'Banorte') + ' acepta todos los seleccionados.'; }
            $('#lay_aviso').text(aviso);
            var $visibles = $('#tabla_layout .lay-fila:visible .lay-check');
            $('#lay_todos').prop('checked', $visibles.length > 0 && $visibles.filter(':checked').length === $visibles.length);

            // Selectores de cuenta de cargo (conserva lo ya elegido)
            var $cargo = $('#lay_cargo');
            var elegido = {};
            $cargo.find('select').each(function () { elegido[$(this).data('banco') + '|' + $(this).data('emp')] = $(this).val(); });
            var html = '';
            Object.keys(empresas).forEach(function (emp) {
                var c = cuentasCargo[emp] || {};
                ['SANTANDER', 'BANORTE'].forEach(function (banco) {
                    var lista = c[banco] || [];
                    if (lista.length < 2) { return; }
                    var previo = elegido[banco + '|' + emp];
                    html += '<div class="d-inline-flex align-items-center gap-1 me-3 mb-1 small"><span class="text-muted">'
                        + escHtml(c.nombre) + ' · ' + (banco === 'SANTANDER' ? 'Santander' : 'Banorte') + ':</span>'
                        + '<select class="form-select form-select-sm" style="width:auto" data-emp="' + escHtml(emp) + '" data-banco="' + banco + '">';
                    lista.forEach(function (o) {
                        html += '<option value="' + escHtml(o.id) + '"' + (String(o.id) === previo ? ' selected' : '') + '>'
                            + escHtml(o.cuenta) + (o.descripcion ? ' — ' + escHtml(o.descripcion) : '') + '</option>';
                    });
                    html += '</select></div>';
                });
            });
            $cargo.html(html ? '<div class="small fw-semibold mb-1">Cuenta de cargo (la empresa tiene varias en el banco):</div>' + html : '').toggle(html !== '');
        };

        $('#tab_ep_layout').on('shown.bs.tab', function () { if (!layoutCargado) { cargarLayout(); } });
        $layout.on('change', '#lay_empresa, #lay_banco_filtro', filtrarLayout);
        $layout.on('input', '#lay_texto', filtrarLayout);
        $layout.on('change', '.lay-check', actualizarSeleccion);
        $layout.on('change', '#lay_todos', function () {
            $('#tabla_layout .lay-fila:visible .lay-check').prop('checked', this.checked);
            actualizarSeleccion();
        });
        // Clic en cualquier parte de la fila marca/desmarca (menos en el enlace del folio)
        $layout.on('click', '.lay-fila', function (e) {
            if ($(e.target).closest('a, input, select, button').length) { return; }
            var $c = $(this).find('.lay-check');
            $c.prop('checked', !$c.prop('checked'));
            actualizarSeleccion();
        });

        // Generar: si el servidor responde JSON es un error (con la lista de renglones que no caben); si no, es el .txt
        $layout.on('click', '.lay-generar', function () {
            var $btn = $(this);
            var banco = $btn.data('banco');
            var fd = new FormData();
            fd.append('banco', banco);
            $('#tabla_layout .lay-fila').each(function () {
                if ($(this).find('.lay-check').prop('checked')) { fd.append('line_ids[]', $(this).attr('data-id')); }
            });
            $('#lay_cargo select[data-banco="' + banco + '"]').each(function () {
                fd.append('cuenta_cargo[' + $(this).data('emp') + ']', $(this).val());
            });
            $('.lay-generar').prop('disabled', true);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', $layout.data('generar'));
            xhr.responseType = 'blob';
            xhr.onload = function () {
                var tipo = xhr.getResponseHeader('Content-Type') || '';
                if (xhr.status === 200 && tipo.indexOf('text/plain') === 0) {
                    var m = /filename="([^"]+)"/.exec(xhr.getResponseHeader('Content-Disposition') || '');
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(xhr.response);
                    a.download = m ? m[1] : 'layout_' + banco.toLowerCase() + '.txt';
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                    alertify.success('Layout de ' + (banco === 'SANTANDER' ? 'Santander' : 'Banorte') + ' generado. Recuerde registrar el pago en cada solicitud.');
                    actualizarSeleccion();
                    return;
                }
                xhr.response.text().then(function (txt) {
                    var r = {};
                    try { r = JSON.parse(txt); } catch (e) { r = { message: txt.substring(0, 200) || 'Error al generar el layout' }; }
                    var msg = escHtml(r.message || 'No se pudo generar el layout');
                    if (r.fallas && r.fallas.length) {
                        msg += '<ul class="mb-0 mt-1 ps-3 small">' + r.fallas.map(function (f) { return '<li>' + escHtml(f) + '</li>'; }).join('') + '</ul>';
                    }
                    alertify.alert('Layout bancario', msg);
                    if (/ya no están autorizados/.test(r.message || '')) { cargarLayout(); } else { actualizarSeleccion(); }
                });
            };
            xhr.onerror = function () { alertify.error('Error de comunicación'); actualizarSeleccion(); };
            xhr.send(fd);
        });

        if (window.location.hash === '#layout') {
            var tabLay = document.getElementById('tab_ep_layout');
            if (tabLay && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(tabLay).show(); } else { cargarLayout(); }
        }
    }

    // ---------------- Formulario ----------------
    var $form = $('#form_extra');
    if (!$form.length) { return; }

    var $lineas = $('#lineas');
    var tpl = document.getElementById('tpl_linea').innerHTML;
    var siguiente = 0;

    function formatoMonto(n) {
        return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Total general, monto en el encabezado de cada renglón, numeración y contador
    function recalcular() {
        var total = 0;
        var $todas = $lineas.find('.ep-linea');
        $todas.each(function (idx) {
            var $l = $(this);
            var v = parseFloat($l.find('.ep-monto').val());
            if (!isNaN(v)) { total += v; }
            $l.find('.ep-num').text(idx + 1);
            $l.find('.ep-linea-monto').text(!isNaN(v) && v > 0 ? formatoMonto(v) : '');
            // Encabezado: "1 · Proveedor · $monto"
            var prov = $l.find('.ep-prov-select').val() ? $.trim($l.find('.ep-prov-select option:selected').text()) : '';
            $l.find('.ep-linea-prov').text(prov || 'Sin proveedor todavía').toggleClass('vacio', !prov);
            revisarMonto($l);
        });
        $('#total_general').text(formatoMonto(total));
        $('#contador_lineas').text($todas.length);
        $('#contador_lineas_txt').text($todas.length === 1 ? 'renglón' : 'renglones');
    }

    // Monto vs Total del CFDI leído en este renglón (el servidor lo vuelve a validar al enviar)
    function revisarMonto($l) {
        var $c = $l.find('.ep-monto-check');
        var totalCfdi = $l.data('cfdiTotal');
        if (totalCfdi === undefined) { $c.text('').removeClass('ok mal'); return; }
        var v = parseFloat($l.find('.ep-monto').val());
        if (!isNaN(v) && Math.abs(v - totalCfdi) < 0.005) {
            $c.text('✔ Coincide con el CFDI').removeClass('mal').addClass('ok');
        } else {
            $c.text('✗ El CFDI dice ' + formatoMonto(totalCfdi)).removeClass('ok').addClass('mal');
        }
    }

    // RFC de la empresa elegida, debajo del select
    function mostrarRfc($l) {
        var rfc = $l.find('.ep-empresa option:selected').attr('data-rfc') || '';
        $l.find('.ep-rfc').text(rfc ? 'RFC ' + rfc : '');
    }

    // Ya no hay tipo de solicitud: siempre se pueden agregar renglones; "Quitar" solo si queda más de uno
    function actualizarTipo() {
        $lineas.find('.btn-quitar-linea').toggle($lineas.find('.ep-linea').length > 1);
        recalcular();
    }

    // Tarjetas de opción (motivo): resalta la seleccionada
    function marcarOpciones() {
        $form.find('.ep-opcion').each(function () {
            $(this).toggleClass('sel', $(this).find('input').prop('checked'));
        });
    }

    // ---------------- Proveedor: buscador de cuentas dadas de alta (alta de cuenta aprobada) ----------------
    function iniciarProveedor($l, cuenta) {
        var $sel = $l.find('.ep-prov-select');
        $sel.append(new Option('', '', false, false));
        if (cuenta && cuenta.id) {
            $sel.append(new Option(cuenta.text, cuenta.id, true, true));
            $sel.data('pagadores', cuenta.pagadores || []);
        }
        if (!$.fn.select2) { return; }
        var es = {};
        try { es = $.fn.select2.amd.require('select2/i18n/es'); } catch (err) { es = {}; }
        $sel.select2({
            width: '100%', allowClear: true, minimumInputLength: 3,
            placeholder: 'Escriba al menos 3 letras: proveedor, cuenta o CLABE',
            ajax: {
                url: $form.data('prov-url'), dataType: 'json', delay: 300, cache: true,
                data: function (p) { return { q: p.term }; },
                processResults: function (d) { return { results: d.results || [] }; }
            },
            templateResult: function (o) {
                if (o.loading || !o.proveedor) { return o.text; }
                return $('<div class="ep-prov-op">').append(
                    $('<div>').text(o.proveedor),
                    $('<small>').text(o.text.substring(o.proveedor.length + 3) + (o.divisa ? ' · ' + o.divisa : ''))
                );
            },
            language: $.extend({}, es, {
                noResults: function () { return 'Sin cuentas: el proveedor debe tener una alta de cuenta aprobada'; }
            })
        });
        $sel.on('select2:select', function (e) { $sel.data('pagadores', e.params.data.pagadores || []); });
        $sel.on('select2:clear', function () { $sel.data('pagadores', []); });
    }

    // Aviso (no bloquea) si la empresa del renglón no está marcada como "puede pagar" en la cuenta
    function revisarPagador($l) {
        var $sel = $l.find('.ep-prov-select');
        var $aviso = $l.find('.ep-prov-aviso').text('');
        var emp = parseInt($l.find('.ep-empresa').val(), 10);
        if (!$sel.val() || !emp) { return; }
        var pag = ($sel.data('pagadores') || []).map(Number);
        if (pag.indexOf(emp) === -1) {
            $aviso.text('⚠ ' + $.trim($l.find('.ep-empresa option:selected').text()) + ' no está marcada como razón social que puede pagar a esta cuenta.');
        }
    }

    // Abre el buscador ya buscando el nombre del emisor del XML (primeras dos palabras)
    function buscarProveedor($l, nombre) {
        var $sel = $l.find('.ep-prov-select');
        if (!$.fn.select2 || $sel.val()) { return; }
        var term = $.trim(String(nombre || '').replace(/[^\wÁÉÍÓÚÑÜáéíóúñü ]+/g, ' ')).split(/\s+/).slice(0, 2).join(' ');
        if (term.length < 3) { return; }
        $sel.select2('open');
        var campo = document.querySelector('.select2-container--open .select2-search__field');
        if (campo) { $(campo).val(term).trigger('input'); }
    }

    function agregarLinea(datos, despuesDe) {
        var i = siguiente++;
        var $l = $(tpl.replace(/__I__/g, i));
        if (datos) {
            Object.keys(datos).forEach(function (k) {
                var $c = $l.find('[name="lines[' + i + '][' + k + ']"]');
                if (!$c.length || datos[k] === null || datos[k] === undefined) { return; }
                var v = datos[k];
                if (k === 'fecha_factura' || k === 'fecha_pago_requerida') {
                    // sqlsrv serializa DATE como cadena Y-m-d o como objeto {date: 'Y-m-d 00:00:00...'}
                    v = (typeof v === 'object' && v.date) ? v.date : String(v);
                    v = v.substring(0, 10);
                }
                $c.val(v);
            });
        }
        if (despuesDe && despuesDe.length) { despuesDe.after($l); } else { $lineas.append($l); }
        // Archivos ya guardados del renglón (edición): se muestran con liga para abrirlos
        ['xml', 'pdf'].forEach(function (tipo) {
            if (datos && datos[tipo + '_document_id'] && datos[tipo + '_nombre']) {
                var url = String($form.data('doc-url')).replace(/0\/?$/, datos[tipo + '_document_id'] + '/');
                var $a = $('<a target="_blank" rel="noopener">').attr('href', url).text(datos[tipo + '_nombre']);
                marcarArchivo($l, tipo, $('<span>').append('Guardado: ', $a), true);
            }
        });
        iniciarProveedor($l, datos && datos.proveedor_cuenta);
        mostrarRfc($l);
        revisarPagador($l);
        actualizarTipo();
        return $l;
    }

    // Estado visual del XML / PDF de un renglón (contenido: texto o nodo jQuery)
    function marcarArchivo($l, tipo, contenido, listo) {
        var $caja = $l.find('.ep-archivo[data-tipo="' + tipo + '"]');
        var $nombre = $caja.find('.ep-archivo-nombre').empty();
        if (typeof contenido === 'string') { $nombre.text(contenido); } else { $nombre.append(contenido); }
        $nombre.toggleClass('text-muted', !listo);
        $caja.toggleClass('listo', !!listo).removeClass('falta');
        $caja.find('.ep-elegir-txt').text(listo ? 'Cambiar' : (tipo === 'xml' ? 'Elegir XML' : 'Elegir PDF'));
    }

    function tieneArchivo($l, tipo) {
        var input = $l.find(tipo === 'xml' ? '.ep-xml-input' : '.ep-pdf-input')[0];
        return !!(input && input.files && input.files.length) || !!$l.find('.ep-archivo[data-tipo="' + tipo + '"] .ep-doc-id').val();
    }

    // Valores de un renglón en el formato de agregarLinea (clave sin "lines[i][...]")
    function valoresDe($l) {
        var out = {};
        $l.find('[name^="lines["]').each(function () {
            var m = /\]\[([a-z_]+)\]$/.exec(this.name);
            if (m) { out[m[1]] = $(this).val(); }
        });
        return out;
    }

    function actualizarMotivo() {
        var req = $form.find('input[name="reason_id"]:checked').data('requiere-texto') == 1;
        $('#wrap_motivo_otros').toggle(req);
    }

    $form.on('change', 'input[name="reason_id"]', function () { actualizarMotivo(); marcarOpciones(); });
    $form.on('input', '.ep-monto', recalcular);
    $form.on('change', '.ep-prov-select', function () { var $l = $(this).closest('.ep-linea'); revisarPagador($l); recalcular(); });
    $form.on('change', '.ep-empresa', function () { var $l = $(this).closest('.ep-linea'); mostrarRfc($l); revisarPagador($l); });
    // Si el usuario corrige a mano un dato que vino del CFDI, deja de marcarse en verde
    $form.on('input change', '.ep-cfdi', function () { $(this).removeClass('ep-cfdi'); });
    // Al corregir un renglón con error se quita la marca roja
    $form.on('input change', '.ep-linea.con-error :input', function () { $(this).closest('.ep-linea').removeClass('con-error'); });

    // Un renglón nuevo hereda empresa y centro de costos del primero
    $('#btn_agregar_linea_pie').on('click', function () {
        var $primero = $lineas.find('.ep-linea').first();
        var base = $primero.length ? valoresDe($primero) : {};
        var $n = agregarLinea({ empresa_cod: base.empresa_cod, centro_costos: base.centro_costos });
        $n[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        $n.find('.ep-xml-input').closest('label').focus();
    });
    $lineas.on('click', '.btn-quitar-linea', function () {
        $(this).closest('.ep-linea').remove();
        actualizarTipo();
    });
    // Duplicar: copia el renglón sin el folio ni los archivos (cada renglón lleva su propia factura)
    $lineas.on('click', '.btn-duplicar-linea', function () {
        var $l = $(this).closest('.ep-linea');
        var datos = valoresDe($l);
        datos.factura = '';
        datos.xml_document_id = '';
        datos.pdf_document_id = '';
        var $sel = $l.find('.ep-prov-select');
        if ($sel.val()) {
            datos.proveedor_cuenta = { id: $sel.val(), text: $sel.find('option:selected').text(), pagadores: $sel.data('pagadores') || [] };
        }
        delete datos.proveedor_cuenta_id;
        agregarLinea(datos, $l);
    });

    // ---------------- XML CFDI (se lee aquí para llenar el renglón y se sube al guardar) ----------------
    function atributo(nodo, nombre) { return nodo ? (nodo.getAttribute(nombre) || '') : ''; }

    function leerCfdi(texto) {
        var doc = new DOMParser().parseFromString(texto, 'application/xml');
        if (doc.getElementsByTagName('parsererror').length) { return null; }
        var porNombre = function (n) { return doc.getElementsByTagNameNS('*', n)[0] || null; };
        var comp = porNombre('Comprobante');
        if (!comp) { return null; }
        var emisor = porNombre('Emisor');
        var timbre = porNombre('TimbreFiscalDigital');
        var concepto = porNombre('Concepto');
        var serieFolio = (atributo(comp, 'Serie') + atributo(comp, 'Folio')).trim();
        return {
            uuid: atributo(timbre, 'UUID').toUpperCase(),
            rfc_receptor: atributo(porNombre('Receptor'), 'Rfc').toUpperCase().trim(),
            proveedor: atributo(emisor, 'Nombre'),
            monto: atributo(comp, 'Total'),
            factura: serieFolio || atributo(timbre, 'UUID'),
            fecha_factura: atributo(comp, 'Fecha').substring(0, 10),
            descripcion: atributo(concepto, 'Descripcion')
        };
    }

    // Si el archivo elegido no sirve se descarta y el renglón vuelve a como estaba (con su archivo guardado, si tenía)
    function descartar(input, $l, tipo, mensaje) {
        alertify.error(mensaje);
        input.value = '';
        var tenia = !!$l.find('.ep-archivo[data-tipo="' + tipo + '"] .ep-doc-id').val();
        if (!tenia) { marcarArchivo($l, tipo, tipo === 'xml' ? 'Sin archivo · llena proveedor, monto, factura y fecha' : 'Sin archivo', false); }
    }

    $lineas.on('change', '.ep-xml-input', function () {
        var input = this;
        var $l = $(input).closest('.ep-linea');
        var archivo = input.files && input.files[0];
        if (!archivo) { return; }
        if (!/\.xml$/i.test(archivo.name)) { descartar(input, $l, 'xml', 'Elija un archivo .xml'); return; }
        if (archivo.size > 10 * 1024 * 1024) { descartar(input, $l, 'xml', 'El XML excede el máximo de 10 MB.'); return; }
        var lector = new FileReader();
        lector.onload = function () {
            var d = leerCfdi(String(lector.result));
            var noEsCfdi = !d || !d.monto ? 'el archivo no es un comprobante válido' : (!d.uuid ? 'el XML no está timbrado (no trae UUID)' : '');
            if (noEsCfdi) {
                if ($form.data('cfdi-obligatorio') == 1) { descartar(input, $l, 'xml', 'No se pudo leer el CFDI: ' + noEsCfdi + '.'); return; }
                // Pruebas (validación apagada): se adjunta igual, sin llenar campos
                marcarArchivo($l, 'xml', archivo.name, true);
                $l.find('.ep-xml-ok').removeClass('d-none').addClass('aviso').text('⚠ Se adjuntará sin leer: ' + noEsCfdi + '. Capture los datos a mano.');
                return;
            }
            // Los campos que llena el CFDI se marcan en verde
            var set = function (campo, valor) {
                if (valor) { $l.find('[name$="[' + campo + ']"]').val(valor).addClass('ep-cfdi'); }
            };
            $l.data('cfdiTotal', Math.round(parseFloat(d.monto) * 100) / 100);
            set('monto', d.monto);
            set('factura', d.factura.substring(0, 60));
            set('fecha_factura', d.fecha_factura);
            set('descripcion', d.descripcion.substring(0, 400));
            // Empresa: se elige por el RFC receptor; si ya había otra, se avisa (al enviar se valida en el servidor)
            var $emp = $l.find('[name$="[empresa_cod]"]');
            var $opt = $emp.find('option').filter(function () { return $(this).attr('data-rfc') === d.rfc_receptor; }).first();
            var avisoEmpresa = '';
            if ($opt.length) {
                if ($emp.val() && $emp.val() !== $opt.val()) {
                    avisoEmpresa = ' La factura es para ' + $opt.text() + ': se cambió la empresa del renglón.';
                }
                $emp.val($opt.val()).addClass('ep-cfdi');
            } else {
                avisoEmpresa = ' Ninguna empresa tiene el RFC receptor ' + d.rfc_receptor
                    + ($form.data('cfdi-obligatorio') == 1 ? ': no se podrá enviar con este XML.' : '; elija la empresa a mano.');
            }
            mostrarRfc($l);
            revisarPagador($l);
            $l.removeClass('con-error');
            recalcular();
            marcarArchivo($l, 'xml', archivo.name + ' · UUID ' + d.uuid, true);
            $l.find('.ep-xml-ok').removeClass('d-none').toggleClass('aviso', !$opt.length || avisoEmpresa !== '')
                .text((avisoEmpresa ? '⚠ ' : '✔ ') + 'CFDI leído: ' + (d.proveedor || 'proveedor sin nombre') + ' · ' + formatoMonto(parseFloat(d.monto))
                    + ' · receptor ' + (d.rfc_receptor || '(sin RFC)') + '.' + avisoEmpresa
                    + ($l.find('.ep-prov-select').val() ? '' : ' Elija la cuenta del proveedor en la lista.'));
            buscarProveedor($l, d.proveedor);
        };
        lector.onerror = function () { descartar(input, $l, 'xml', 'No se pudo leer el archivo.'); };
        lector.readAsText(archivo);
    });

    $lineas.on('change', '.ep-pdf-input', function () {
        var input = this;
        var $l = $(input).closest('.ep-linea');
        var archivo = input.files && input.files[0];
        if (!archivo) { return; }
        if (!/\.pdf$/i.test(archivo.name)) { descartar(input, $l, 'pdf', 'Elija un archivo .pdf'); return; }
        if (archivo.size > 10 * 1024 * 1024) { descartar(input, $l, 'pdf', 'El PDF excede el máximo de 10 MB.'); return; }
        marcarArchivo($l, 'pdf', archivo.name, true);
    });

    // Renglones iniciales (edición) o uno vacío (alta)
    var iniciales = window.EP_LINEAS || [];
    if (iniciales.length) {
        iniciales.forEach(function (l) { agregarLinea(l); });
    } else {
        agregarLinea(null);
    }
    actualizarMotivo();
    marcarOpciones();

    // ---------------- Guardar: enviar o guardar borrador ----------------
    // Muestra el error arriba y marca en rojo los renglones que menciona ("Renglón N: ..."); lleva al primero
    function mostrarError(msg) {
        var $e = $('#ep_error');
        $e.html(escHtml(msg).replace(/\n/g, '<br>')).removeClass('d-none');
        var $todas = $lineas.find('.ep-linea').removeClass('con-error');
        var re = /Renglón (\d+)/g;
        var m;
        var $primero = null;
        while ((m = re.exec(msg)) !== null) {
            var $l = $todas.eq(parseInt(m[1], 10) - 1);
            if ($l.length) { $l.addClass('con-error'); $primero = $primero || $l; }
        }
        ($primero || $e)[0].scrollIntoView({ behavior: 'smooth', block: $primero ? 'start' : 'center' });
    }

    function guardar(esBorrador) {
        var $botones = $('#btn_guardar, #btn_borrador');
        $('#ep_error').addClass('d-none').empty();
        // Para enviar, cada renglón necesita su XML (el servidor lo vuelve a exigir). Se puede apagar en pruebas.
        if (!esBorrador && $form.data('cfdi-obligatorio') == 1) {
            var faltan = [];
            $lineas.find('.ep-linea').each(function (idx) {
                if (!tieneArchivo($(this), 'xml')) {
                    faltan.push(idx + 1);
                    $(this).find('.ep-archivo[data-tipo="xml"]').addClass('falta');
                }
            });
            if (faltan.length) {
                mostrarError('Falta el XML (CFDI) en el renglón ' + faltan.join(', ') + '. Es obligatorio para enviar; si aún no lo tiene, use "Guardar borrador".');
                return;
            }
        }
        $botones.prop('disabled', true);
        // FormData lleva los campos y los archivos XML/PDF de cada renglón
        var datos = new FormData($form[0]);
        if (esBorrador) { datos.append('borrador', '1'); }
        $.ajax({ url: $form.data('save-url'), type: 'POST', dataType: 'json', data: datos, processData: false, contentType: false })
            .done(function (resp) {
                if (resp.success) {
                    (resp.warning ? alertify.warning : alertify.success)(escHtml(resp.message));
                    // Borrador: va a la edición (con adjuntos); envío: va al detalle
                    var plantilla = String($form.data(resp.borrador ? 'edit-url' : 'detail-url'));
                    window.location.href = plantilla.replace(/\/0(\/edit)?(\/?)$/, function (m, ed, barra) { return '/' + resp.id + (ed || '') + barra; });
                } else {
                    mostrarError(resp.message || 'No se pudo guardar');
                    $botones.prop('disabled', false);
                }
            })
            .fail(function (xhr) {
                mostrarError((xhr.responseJSON && xhr.responseJSON.message) || 'Error de comunicación');
                $botones.prop('disabled', false);
            });
    }

    $form.on('submit', function (e) { e.preventDefault(); guardar(false); });
    $('#btn_borrador').on('click', function () { guardar(true); });
})(jQuery);
