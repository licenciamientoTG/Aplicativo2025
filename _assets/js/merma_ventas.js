/**
 * Pestaña HISTÓRICO de /merma/ventas.
 *
 * Se carga por AJAX en vez de venir en el render inicial para que sus
 * selectores de año y producto no colisionen con el selector de mes que
 * gobierna las cinco pestañas diarias: si vivieran en el mismo formulario,
 * cambiarlos recargaría la página y arrastraría al otro control.
 */
$(function () {
    var cargado = false;

    function cargarHistorico() {
        var params = {
            desde: $('#hist_desde').val(),
            hasta: $('#hist_hasta').val(),
            prod:  $('#hist_prod').val()
        };
        $('#hist_contenido').html('<p class="text-muted small">Cargando histórico…</p>');
        $.get('/merma/ventas_historico', params)
            .done(function (html) {
                $('#hist_contenido').html(html);
                cargado = true;
            })
            .fail(function () {
                $('#hist_contenido').html(
                    '<div class="alert alert-danger py-2">No se pudo cargar el histórico. ' +
                    'Vuelve a intentarlo o revisa la conexión.</div>'
                );
            });
    }

    // Primera vez que se abre la pestaña
    $('#tab-historico-link').on('shown.bs.tab', function () {
        if (!cargado) cargarHistorico();
    });

    // Cualquier cambio de control recarga solo la tabla
    $('#hist_desde, #hist_hasta, #hist_prod').on('change', cargarHistorico);
});
