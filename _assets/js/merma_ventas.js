/**
 * Pestaña HISTÓRICO de /merma/ventas, repetida una vez por cada tab de
 * zona (marca_prots/tsa_ags/zona3). Se carga por AJAX en vez de venir en
 * el render inicial para que sus selectores de año y producto no
 * colisionen con el selector de mes que gobierna las cinco pestañas
 * diarias: si vivieran en el mismo formulario, cambiarlos recargaría la
 * página y arrastraría al otro control.
 */
$(function () {
    var cargadas = {};   // zonaClave -> bool, para no recargar el histórico de una zona ya vista
    var zonaActiva = $('.merma-tabs-zona .nav-link.active').data('zona') || 'marca_prots';

    function controlesDe(zonaClave) {
        return {
            desde: $('#hist_desde-' + zonaClave),
            hasta: $('#hist_hasta-' + zonaClave),
            prod:  $('#hist_prod-' + zonaClave),
            contenido: $('#hist_contenido-' + zonaClave)
        };
    }

    // El enlace de exportación arrastra el rango, producto y zona activa,
    // para que la hoja HISTÓRICO del .xlsx (y el resto del libro) reflejen
    // lo que está en pantalla.
    function sincronizarEnlaceExportar() {
        var $a = $('#btn_exportar');
        if (!$a.length) return;
        var c = controlesDe(zonaActiva);
        var url = new URL($a.attr('href'), window.location.origin);
        url.searchParams.set('desde', c.desde.val());
        url.searchParams.set('hasta', c.hasta.val());
        url.searchParams.set('prod',  c.prod.val());
        url.searchParams.set('zona',  zonaActiva);
        $a.attr('href', url.pathname + url.search);
    }

    function cargarHistorico(zonaClave) {
        var c = controlesDe(zonaClave);
        var params = {
            desde: c.desde.val(),
            hasta: c.hasta.val(),
            prod:  c.prod.val(),
            zona:  zonaClave
        };
        c.contenido.html('<p class="text-muted small">Cargando histórico…</p>');
        $.get('/merma/ventas_historico', params)
            .done(function (html) {
                c.contenido.html(html);
                cargadas[zonaClave] = true;
            })
            .fail(function () {
                c.contenido.html(
                    '<div class="alert alert-danger py-2">No se pudo cargar el histórico. ' +
                    'Vuelve a intentarlo o revisa la conexión.</div>'
                );
            });
        if (zonaClave === zonaActiva) sincronizarEnlaceExportar();
    }

    // Primera vez que se abre la pestaña HISTÓRICO de cada zona
    $('[id^="tab-historico-link-"]').on('shown.bs.tab', function () {
        var zonaClave = this.id.replace('tab-historico-link-', '');
        if (!cargadas[zonaClave]) cargarHistorico(zonaClave);
    });

    // Cambiar de tab de ZONA actualiza cuál es la zona activa (para el
    // enlace de exportación) y sincroniza el enlace con los controles de
    // esa zona, ya estén cargados o con los valores por defecto del render.
    $('.merma-tabs-zona .nav-link').on('shown.bs.tab', function () {
        zonaActiva = $(this).data('zona');
        sincronizarEnlaceExportar();
    });

    // Cualquier cambio de control de histórico recarga SU tabla y
    // re-sincroniza el enlace si es la zona actualmente activa.
    $('.hist-control').on('change', function () {
        var zonaClave = this.id.replace(/^hist_(desde|hasta|prod)-/, '');
        cargarHistorico(zonaClave);
    });

    // El navegador restaura el valor de los <select> en un F5 o un
    // atrás/adelante SIN disparar "change" (a diferencia de un cambio hecho
    // por el usuario). Sin esto, el enlace de exportación queda apuntando a
    // los valores por defecto que el servidor renderizó, mientras la
    // pestaña —cuando se abra— usará los valores restaurados por el
    // navegador: se rompe en silencio.
    sincronizarEnlaceExportar();
});
