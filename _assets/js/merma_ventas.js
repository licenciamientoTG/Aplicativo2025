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

    // El enlace de exportación arrastra el rango y producto del histórico,
    // para que la hoja HISTÓRICO del .xlsx refleje lo que está en pantalla.
    function sincronizarEnlaceExportar() {
        var $a = $('#btn_exportar');
        if (!$a.length) return;
        var url = new URL($a.attr('href'), window.location.origin);
        url.searchParams.set('desde', $('#hist_desde').val());
        url.searchParams.set('hasta', $('#hist_hasta').val());
        url.searchParams.set('prod',  $('#hist_prod').val());
        $a.attr('href', url.pathname + url.search);
    }

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
        // Misma lectura de los controles que armó "params": la tabla y el
        // enlace de exportación nunca pueden divergir entre sí.
        sincronizarEnlaceExportar();
    }

    // Primera vez que se abre la pestaña
    $('#tab-historico-link').on('shown.bs.tab', function () {
        if (!cargado) cargarHistorico();
    });

    // Cualquier cambio de control recarga la tabla y re-sincroniza el enlace
    $('#hist_desde, #hist_hasta, #hist_prod').on('change', cargarHistorico);

    // El navegador restaura el valor de los <select> en un F5 o un
    // atrás/adelante SIN disparar "change" (a diferencia de un cambio hecho
    // por el usuario). Sin esto, el enlace de exportación queda apuntando a
    // los valores por defecto que el servidor renderizó, mientras la
    // pestaña —cuando se abra— usará los valores restaurados por el
    // navegador: se rompe en silencio.
    sincronizarEnlaceExportar();
});
