/**
 * Alta de cuenta bancaria — index / form / detail.
 * Las URLs llegan por data-attributes generados con url() de Twig.
 */
(function ($) {
    'use strict';

    function escHtml(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function errorDe(xhr, porDefecto) {
        return (xhr.responseJSON && xhr.responseJSON.message) || (xhr.responseText || '').substring(0, 200) || porDefecto;
    }

    // ---------------- Zona de carga de la carátula (form y detalle) ----------------
    var MAX_BYTES = 10 * 1024 * 1024;
    function tamano(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
    $('.ba-drop').each(function () {
        var $drop = $(this);
        var $input = $drop.find('.ba-drop-input');
        var limpiar = function () {
            $input.val('');
            $drop.removeClass('con-archivo').find('.ba-drop-vacio').removeClass('d-none');
            $drop.find('.ba-drop-archivo').addClass('d-none');
            $input.trigger('change');
        };
        $input.on('change', function () {
            var f = this.files && this.files[0];
            if (!f) { $drop.removeClass('con-archivo').find('.ba-drop-vacio').removeClass('d-none'); $drop.find('.ba-drop-archivo').addClass('d-none'); return; }
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            if (['pdf', 'jpg', 'jpeg', 'png'].indexOf(ext) === -1) { alertify.error('Tipo de archivo no permitido. Use PDF, JPG o PNG'); limpiar(); return; }
            if (f.size > MAX_BYTES) { alertify.error('El archivo excede el máximo de 10 MB'); limpiar(); return; }
            $drop.addClass('con-archivo').find('.ba-drop-vacio').addClass('d-none');
            $drop.find('.ba-drop-archivo').removeClass('d-none');
            $drop.find('.ba-drop-nombre').text(f.name);
            $drop.find('.ba-drop-tam').text(tamano(f.size));
            $drop.find('.ba-drop-tipo').attr('class', 'fas ba-drop-tipo ' + (ext === 'pdf' ? 'fa-file-pdf' : 'fa-file-image'));
        });
        $drop.on('dragenter dragover', function () { $drop.addClass('arrastrando'); });
        $drop.on('dragleave drop', function () { $drop.removeClass('arrastrando'); });
        $drop.find('.ba-drop-quitar').on('click', limpiar);
    });
    // En el detalle el botón de subir solo se habilita con un archivo elegido
    $('#ba_archivo').on('change', function () { $('#ba_subir').prop('disabled', !(this.files && this.files.length)); });

    // ---------------- Index: filtros (al detalle se entra con el botón Ver, no con clic en la fila) ----------------
    var $tabla = $('#tabla_altas');
    if ($tabla.length) {
        var status = '';
        var aplicar = function () {
            var q = ($('#ba_filtro_texto').val() || '').toLowerCase().trim();
            $tabla.find('tbody tr.ba-fila').each(function () {
                var $tr = $(this);
                var ok = (status === '' || String($tr.data('status')) === status)
                    && (q === '' || String($tr.data('busqueda')).indexOf(q) !== -1);
                $tr.toggle(ok);
            });
        };
        $('#ba_tabs').on('click', 'button', function () {
            status = String($(this).data('status'));
            $('#ba_tabs button').removeClass('activo');
            $(this).addClass('activo');
            aplicar();
        });
        $('#ba_filtro_texto').on('input', aplicar);

        // Tesorería: modal Aprobar / Rechazar desde la lista (vista parcial del servidor)
        $tabla.on('click', '.btn-ba-modal', function () {
            var $btn = $(this).prop('disabled', true);
            $.ajax({ url: $btn.data('url'), type: 'GET', dataType: 'html' })
                .done(function (html) {
                    $('#modal_ba_content').html(html);
                    $('#modal_ba').modal('show');
                })
                .fail(function (xhr) { alertify.error(escHtml(errorDe(xhr, 'No se pudo abrir la solicitud'))); })
                .always(function () { $btn.prop('disabled', false); });
        });
        $(document).on('click', '.btn-ba-decidir', function () {
            var $btn = $(this);
            var comentario = ($('#ba_modal_comentario').val() || '').trim();
            if ($btn.data('rechazo') && comentario === '') { alertify.error('Escriba el motivo del rechazo en el comentario'); return; }
            decidir($btn.data('url'), { comentario: comentario }, $('.btn-ba-decidir'));
        });
    }

    // ---------------- Form: aviso de duplicado y envío ----------------
    var $form = $('#form_alta');
    if ($form.length) {
        // Buscador en todos los select (select2 local); con búsqueda aun en listas cortas
        if ($.fn.select2) {
            $form.find('select').select2({ language: 'es', minimumResultsForSearch: 0, width: '100%' });
            // select2 abre el cuadro de búsqueda con foco al desplegar
            $(document).on('select2:open', function () {
                var $campo = document.querySelector('.select2-container--open .select2-search__field');
                if ($campo) { $campo.focus(); }
            });
        }

        var $aviso = $('#ba_aviso_dup');
        var $info = $('#ba_cuenta_info');
        var timer = null;

        var $moneda = $form.find('[name=moneda]');
        var $cuenta = $form.find('[name=num_cuenta]');
        var $clabe = $form.find('[name=clabe]');
        var $routing = $form.find('[name=routing_number]');

        // PESOS = cuenta mexicana (cuenta + CLABE); DOLARES = americana (account number + routing number)
        function esUsd() { return $moneda.val() === 'DOLARES'; }
        function aplicarMoneda() {
            var usd = esUsd();
            $('#ba_lbl_cuenta').text(usd ? 'Account number' : 'Cuenta');
            $cuenta.attr('maxlength', usd ? 17 : 20).attr('placeholder', usd ? 'Solo números (4 a 17)' : 'Solo números');
            $('#ba_grp_clabe').toggleClass('d-none', usd);
            $clabe.prop('disabled', usd);
            $('#ba_grp_routing').toggleClass('d-none', !usd);
            $routing.prop('disabled', !usd);
            avisoDuplicado();
        }

        // Dígito verificador ABA del routing number
        function abaValido(r) {
            if (!/^\d{9}$/.test(r)) { return false; }
            var d = r.split('').map(Number);
            return (3 * (d[0] + d[3] + d[6]) + 7 * (d[1] + d[4] + d[7]) + (d[2] + d[5] + d[8])) % 10 === 0;
        }
        function soloDigitos($el) {
            var limpio = $el.val().replace(/\D+/g, '');
            if ($el.val() !== limpio) { $el.val(limpio); }
            return limpio;
        }
        function info($el, texto, clase) { $el.text(texto).removeClass('ok mal').addClass(clase || ''); }

        // Misma regla que bloquea al guardar: CLABE en cualquier banco, o cuenta en el mismo banco,
        // ya registrada en Cuentas bancarias o en otra alta (pendiente, aprobada o borrador).
        function avisoDuplicado() {
            var usd = esUsd();
            var datos = { cuenta: $cuenta.val(), clabe: usd ? '' : $clabe.val(), banco: $form.find('[name=banco]').val() || '' };
            clearTimeout(timer);
            if (datos.cuenta.length < 4 && datos.clabe.length < 18) { $aviso.addClass('d-none'); return; }
            timer = setTimeout(function () {
                $.getJSON($form.data('check-url'), datos).done(function (r) {
                    if (r.mensaje) {
                        $aviso.html('<i class="fas fa-ban"></i> ' + escHtml(r.mensaje) + '<br><small>No se podrá guardar esta solicitud.</small>')
                              .removeClass('d-none alert-warning').addClass('alert-danger');
                    } else {
                        $aviso.addClass('d-none');
                    }
                });
            }, 400);
        }

        $cuenta.on('input', function () {
            var n = soloDigitos($(this)).length;
            if (esUsd()) {
                info($info, n === 0 ? '' : (n >= 4 ? n + ' dígitos' : 'Mínimo 4 dígitos'), n === 0 ? '' : (n >= 4 ? 'ok' : 'mal'));
                avisoDuplicado();
            } else {
                info($info, n === 0 ? '' : (n >= 6 ? n + ' dígitos' : 'Mínimo 6 dígitos'), n === 0 ? '' : (n >= 6 ? 'ok' : 'mal'));
                avisoDuplicado();
            }
        });
        $form.find('[name=banco]').on('change', avisoDuplicado);
        $clabe.on('input', function () {
            var n = soloDigitos($(this)).length;
            info($('#ba_clabe_info'), n === 0 ? '' : (n === 18 ? 'CLABE de 18 dígitos' : n + ' de 18 dígitos'), n === 0 ? '' : (n === 18 ? 'ok' : 'mal'));
            avisoDuplicado();
        });
        $routing.on('input', function () {
            var r = soloDigitos($(this));
            var $i = $('#ba_routing_info');
            if (r.length === 0) { info($i, ''); }
            else if (r.length < 9) { info($i, r.length + ' de 9 dígitos', 'mal'); }
            else { abaValido(r) ? info($i, 'Routing number válido', 'ok') : info($i, 'Dígito verificador inválido: revise el número', 'mal'); }
        });
        $moneda.on('change', aplicarMoneda);
        aplicarMoneda();

        // El botón dice qué pasará: sin carátula queda como borrador
        var $archivo = $form.find('[name=caratula]');
        $archivo.on('change', function () {
            $('#ba_enviar_txt').text(this.files && this.files.length ? 'Enviar a Tesorería' : 'Guardar');
        });

        // Razones sociales que pueden pagar: casillas, al menos una
        var $pagadores = $('#ba_pagadores');
        $pagadores.on('change', 'input', function () { $pagadores.removeClass('is-invalid'); });

        $form.on('submit', function (e) {
            e.preventDefault();
            if (!$pagadores.find('input:checked').length) {
                $pagadores.addClass('is-invalid');
                $('#ba_error').text('Seleccione al menos una razón social que pueda pagar a esta cuenta.').removeClass('d-none');
                $pagadores[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            var $btn = $('#ba_enviar').prop('disabled', true);
            var $err = $('#ba_error').addClass('d-none');
            $.ajax({
                url: $form.data('save-url'), type: 'POST', dataType: 'json',
                data: new FormData(this), processData: false, contentType: false
            }).done(function (resp) {
                if (resp.success) {
                    (resp.warning ? alertify.warning : alertify.success)(escHtml(resp.message));
                    window.location.href = String($form.data('detail-url')).replace(/0\/?$/, resp.id + '/');
                } else {
                    $err.text(resp.message || 'No se pudo enviar la solicitud').removeClass('d-none');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    $btn.prop('disabled', false);
                }
            }).fail(function (xhr) {
                $err.text(errorDe(xhr, 'Error de comunicación')).removeClass('d-none');
                $btn.prop('disabled', false);
            });
        });
    }

    // ---------------- Detalle: aprobar / rechazar (Tesorería) ----------------
    var $det = $('#ba_detalle');

    // Dueño de un borrador: sube la carátula y la solicitud pasa a Tesorería
    $('#ba_subir').on('click', function () {
        var archivo = document.getElementById('ba_archivo');
        if (!archivo || !archivo.files.length) { alertify.error('Seleccione el archivo de la carátula'); return; }
        var datos = new FormData();
        datos.append('caratula', archivo.files[0]);
        var $btn = $(this).prop('disabled', true);
        $.ajax({ url: $det.data('upload-url'), type: 'POST', dataType: 'json', data: datos, processData: false, contentType: false })
            .done(function (resp) {
                if (resp.success) {
                    alertify.success(escHtml(resp.message));
                    window.location.reload();
                } else {
                    alertify.error(escHtml(resp.message || 'No se pudo subir la carátula'));
                    $btn.prop('disabled', false);
                }
            })
            .fail(function (xhr) {
                alertify.error(escHtml(errorDe(xhr, 'Error de comunicación')));
                $btn.prop('disabled', false);
            });
    });
    function decidir(url, data, $botones) {
        $botones.prop('disabled', true);
        $.ajax({ url: url, type: 'POST', dataType: 'json', data: data })
            .done(function (resp) {
                if (resp.success) {
                    alertify.success(escHtml(resp.message));
                    // Tras aprobar o rechazar se vuelve a la lista de solicitudes
                    if ($det.length) { window.location.href = $det.data('index-url'); } else { window.location.reload(); }
                } else {
                    alertify.error(escHtml(resp.message || 'No se pudo completar la acción'));
                    $botones.prop('disabled', false);
                }
            })
            .fail(function (xhr) {
                alertify.error(escHtml(errorDe(xhr, 'Error de comunicación')));
                $botones.prop('disabled', false);
            });
    }
    $('#ba_aprobar').on('click', function () {
        var $botones = $('#ba_aprobar, #ba_rechazar');
        alertify.confirm('Aprobar alta de cuenta', 'La cuenta se agregará al catálogo de cuentas bancarias. ¿Continuar?', function () {
            decidir($det.data('approve-url'), { comentario: $('#ba_comentario').val() }, $botones);
        }, function () {});
    });
    $('#ba_rechazar').on('click', function () {
        var comentario = ($('#ba_comentario').val() || '').trim();
        if (comentario === '') { alertify.error('Escriba el motivo del rechazo en el comentario'); return; }
        var $botones = $('#ba_aprobar, #ba_rechazar');
        alertify.confirm('Rechazar alta de cuenta', '¿Rechazar esta solicitud?', function () {
            decidir($det.data('reject-url'), { comentario: comentario }, $botones);
        }, function () {});
    });
})(jQuery);
