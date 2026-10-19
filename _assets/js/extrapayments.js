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

    // ---------------- Formulario ----------------
    var $form = $('#form_extra');
    if (!$form.length) { return; }

    var $lineas = $('#lineas');
    var tpl = document.getElementById('tpl_linea').innerHTML;
    var siguiente = 0;

    function esIndividual() {
        return $form.find('input[name="tipo"]:checked').val() === 'individual';
    }

    function recalcular() {
        var total = 0;
        $lineas.find('.ep-monto').each(function () {
            var v = parseFloat($(this).val());
            if (!isNaN(v)) { total += v; }
        });
        $('#total_general').text('$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }

    function actualizarTipo() {
        var individual = esIndividual();
        $('#btn_agregar_linea').toggle(!individual);
        if (individual) {
            // Una solicitud individual conserva solo el primer renglón
            $lineas.find('.ep-linea:gt(0)').remove();
        }
        $lineas.find('.btn-quitar-linea').toggle(!individual && $lineas.find('.ep-linea').length > 1);
        recalcular();
    }

    function agregarLinea(datos) {
        var i = siguiente++;
        var $l = $(tpl.replace(/__I__/g, i));
        if (datos) {
            Object.keys(datos).forEach(function (k) {
                var $c = $l.find('[name="lines[' + i + '][' + k + ']"]');
                if (!$c.length || datos[k] === null) { return; }
                var v = datos[k];
                if (k === 'fecha_factura' || k === 'fecha_pago_requerida') {
                    // sqlsrv serializa DATE como cadena Y-m-d o como objeto {date: 'Y-m-d 00:00:00...'}
                    v = (typeof v === 'object' && v.date) ? v.date : String(v);
                    v = v.substring(0, 10);
                }
                $c.val(v);
            });
        }
        $lineas.append($l);
        actualizarTipo();
    }

    function actualizarMotivo() {
        var req = $form.find('input[name="reason_id"]:checked').data('requiere-texto') == 1;
        $('#wrap_motivo_otros').toggle(req);
    }

    $form.on('change', 'input[name="tipo"]', function () {
        if (esIndividual() && $lineas.find('.ep-linea').length > 1) {
            alertify.warning('Una solicitud individual solo lleva un renglón; se conservó el primero.');
        }
        actualizarTipo();
    });
    $form.on('change', 'input[name="reason_id"]', actualizarMotivo);
    $form.on('input', '.ep-monto', recalcular);
    $('#btn_agregar_linea').on('click', function () { agregarLinea(null); });
    $lineas.on('click', '.btn-quitar-linea', function () {
        $(this).closest('.ep-linea').remove();
        actualizarTipo();
    });

    // Renglones iniciales (edición) o uno vacío (alta)
    var iniciales = window.EP_LINEAS || [];
    if (iniciales.length) {
        iniciales.forEach(function (l) { agregarLinea(l); });
    } else {
        agregarLinea(null);
    }
    actualizarMotivo();

    $form.on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#btn_guardar');
        $btn.prop('disabled', true);
        $.ajax({ url: $form.data('save-url'), type: 'POST', dataType: 'json', data: $form.serialize() })
            .done(function (resp) {
                if (resp.success) {
                    alertify.success(resp.message);
                    window.location.href = String($form.data('detail-url')).replace(/\/0\/?$/, '/' + resp.id);
                } else {
                    alertify.error((resp.message || 'No se pudo guardar').replace(/\n/g, '<br>'));
                    $btn.prop('disabled', false);
                }
            })
            .fail(function (xhr) {
                alertify.error((xhr.responseJSON && xhr.responseJSON.message) || 'Error de comunicación');
                $btn.prop('disabled', false);
            });
    });
})(jQuery);
