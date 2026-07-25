/**
 * Análisis de merma diaria: DataTable del resumen, captura inline
 * (merma s/d, comentarios, precio) y modal de sincronización.
 */
$(document).ready(function () {

    if ($('#merma_table').length) {
        $('#merma_table').DataTable({
            paging: false,
            ordering: false,
            info: false,
            // Sin anchos fijos: DataTables clava px calculados al iniciar y la
            // tabla deja de ajustarse al redimensionar la ventana
            autoWidth: false,
            dom: '<"top"Bf>rt',
            buttons: [{
                extend: 'excel',
                title: 'Análisis de merma diaria',
                className: 'btn btn-sm btn-merma-neutro',
                exportOptions: {
                    format: {
                        body: function (data, row, column, node) {
                            var input = $(node).find('input');
                            return input.length ? input.val() : data;
                        }
                    }
                }
            }],
        });
        // La toolbar (Excel + buscador) sube fuera del área con scroll para
        // que no desaparezca al desplazar la tabla
        var $wrap = $('#merma_table').closest('.merma-tabla-wrap');
        if ($wrap.length) {
            $wrap.prepend($wrap.find('.top'));
        }
    }

    // ---- Captura inline: merma s/d y comentarios --------------------------
    function guardarManual(codgas, campo, valor, $el) {
        $.post('/merma/guardar_manual', {
            codgas: codgas, anio: MERMA_CTX.anio, mes: MERMA_CTX.mes,
            campo: campo, valor: valor,
        }, function (res) {
            $el.removeClass('is-invalid is-valid').addClass(res.success ? 'is-valid' : 'is-invalid');
            setTimeout(() => $el.removeClass('is-valid'), 1500);
        }, 'json').fail(() => $el.addClass('is-invalid'));
    }

    $(document).on('change', '.merma-sd', function () {
        guardarManual($(this).data('codgas'), $(this).data('campo'), $(this).val(), $(this));
    });
    $(document).on('change', '.merma-comentario', function () {
        guardarManual($(this).data('codgas'), 'comentarios', $(this).val(), $(this));
    });

    // ---- Precio por litro -------------------------------------------------
    $(document).on('change', '#precio_litro', function () {
        const $el = $(this);
        $.post('/merma/guardar_precio', {
            anio: MERMA_CTX.anio, mes: MERMA_CTX.mes, precio: $el.val(),
        }, function (res) {
            $el.addClass(res.success ? 'is-valid' : 'is-invalid');
            if (res.success) location.reload();
        }, 'json').fail(() => $el.addClass('is-invalid'));
    });

    // ---- Modal de sincronización ------------------------------------------
    $(document).on('click', '#sync_btn', function () {
        const $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando estaciones...');
        $('#sync_result').html('');
        $.post('/merma/sync', {
            from: $('#sync_from').val(),
            to: $('#sync_to').val(),
            codgas: $('#sync_codgas').val(),
        }, function (res) {
            let html = res.success
                ? '<div class="alert alert-success mb-0">' + res.message + ' (' + res.filas + ' registros, ' + res.duracion_seg + 's)'
                : '<div class="alert alert-danger mb-0">' + res.message;
            if (res.errores && res.errores.length) {
                html += '<br><small>Sin conexión: ' + res.errores.join(', ') + '</small>';
            }
            html += '</div>';
            $('#sync_result').html(html);
            if (res.success) setTimeout(() => location.reload(), 2500);
        }, 'json').fail(function (xhr) {
            $('#sync_result').html('<div class="alert alert-danger mb-0">Error de servidor (' + xhr.status + ')</div>');
        }).always(() => $btn.prop('disabled', false).html('<i class="fas fa-sync"></i> Sincronizar'));
    });
});
