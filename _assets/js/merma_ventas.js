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
    // El RESUMEN DIRECCIÓN no tiene data-zona: mientras está abierto, el
    // Excel exporta la última zona vista (o MARCA Y PROTS por defecto).
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
    $('.merma-tabs-zona .nav-link[data-zona]').on('shown.bs.tab', function () {
        zonaActiva = $(this).data('zona');
        sincronizarEnlaceExportar();
    });

    // Los nombres de zona del resumen abren el tab de esa zona
    $('.vd-ir-zona').on('click', function (e) {
        e.preventDefault();
        var link = document.querySelector('.merma-tabs-zona [data-zona="' + $(this).data('zona-destino') + '"]');
        if (link) bootstrap.Tab.getOrCreateInstance(link).show();
    });

    graficaDiaria();

    // Rentabilidad: consulta aparte a SG12, se pide después de pintar la
    // página para no retrasar el resto del reporte.
    var $rent = $('#vd-rentabilidad');
    if ($rent.length) {
        $.get($rent.data('url'))
            .done(function (html) { $rent.html(html); initRentabilidad(); })
            .fail(function () {
                $rent.html('<div class="alert alert-danger py-2 mb-0">No se pudo calcular la rentabilidad. ' +
                           'Recarga la página o revisa la conexión.</div>');
            });
    }

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

/**
 * RESUMEN DIRECCIÓN: barras con la venta de cada día del mes contra la línea
 * del ritmo diario que pide el presupuesto (presupuesto / días del mes).
 */
function graficaDiaria() {
    var el = document.getElementById('vd-chart-diario');
    if (!el || typeof echarts === 'undefined') return;

    var diario = JSON.parse(el.dataset.diario || '[]');
    var ritmo  = parseFloat(el.dataset.ritmo);
    var tieneRitmo = !isNaN(ritmo);
    var fmt = function (v) { return v == null ? '—' : Math.round(v).toLocaleString('es-MX'); };

    var chart = echarts.init(el);
    chart.setOption({
        grid: { left: 70, right: 20, top: 30, bottom: 44 },
        legend: { top: 0, data: tieneRitmo ? ['Venta del día', 'Ritmo presupuesto'] : ['Venta del día'] },
        tooltip: {
            trigger: 'axis',
            formatter: function (p) {
                var d = diario[p[0].dataIndex];
                var html = '<strong>' + d.dia + ' ' + d.nombre + '</strong>';
                p.forEach(function (s) { html += '<br>' + s.marker + s.seriesName + ': ' + fmt(s.value) + ' L'; });
                return html;
            }
        },
        // Número de día y, debajo, el día de la semana abreviado (Lun, Mar…)
        xAxis: {
            type: 'category',
            data: diario.map(function (d) {
                var dia = String(d.nombre || '').substring(0, 3);
                return d.dia + '\n' + dia.charAt(0).toUpperCase() + dia.slice(1);
            }),
            axisLabel: { interval: 0, fontSize: 10, lineHeight: 13 }
        },
        yAxis: { type: 'value', axisLabel: { formatter: function (v) { return (v / 1000).toLocaleString('es-MX') + ' mil'; } } },
        series: [
            {
                name: 'Venta del día', type: 'bar',
                data: diario.map(function (d) {
                    if (d.total == null) return null;
                    // Debajo del ritmo del presupuesto: barra en ámbar
                    var bajo = tieneRitmo && d.total < ritmo;
                    return { value: d.total, itemStyle: { color: bajo ? '#f59e0b' : '#009559', borderRadius: [3, 3, 0, 0] } };
                })
            }
        ].concat(tieneRitmo ? [{
            name: 'Ritmo presupuesto', type: 'line', symbol: 'none',
            data: diario.map(function () { return ritmo; }),
            lineStyle: { type: 'dashed', color: '#0095DA', width: 2 }, itemStyle: { color: '#0095DA' }
        }] : [])
    });
    $(window).on('resize', function () { chart.resize(); });
}

/**
 * Íconos (?) de las cards del RESUMEN DIRECCIÓN: un clic abre una burbuja
 * con la explicación bajo el ícono; otro clic, Esc o un clic fuera la
 * cierran. Delegado en document porque la sección de Rentabilidad llega
 * por AJAX después de cargar la página.
 */
$(function () {
    var $pop = $('<div class="vd-ayuda-pop" role="dialog" hidden>' +
                 '<button type="button" class="vd-ayuda-cerrar" aria-label="Cerrar">&times;</button>' +
                 '<div class="vd-ayuda-titulo"></div><div class="vd-ayuda-texto"></div></div>').appendTo('body');
    var abierto = null;

    function cerrar() {
        $pop.prop('hidden', true);
        abierto = null;
    }

    $(document).on('click', '.vd-ayuda', function (e) {
        e.stopPropagation();
        if (abierto === this) { cerrar(); return; }
        abierto = this;
        // El texto viene de la plantilla (no de datos del usuario), por eso .html()
        $pop.find('.vd-ayuda-titulo').text($(this).data('titulo'));
        $pop.find('.vd-ayuda-texto').html($(this).data('texto'));
        $pop.prop('hidden', false);

        var r = this.getBoundingClientRect();
        var ancho = $pop.outerWidth();
        var left = Math.min(Math.max(8, r.left + window.scrollX - 12),
                            window.scrollX + document.documentElement.clientWidth - ancho - 8);
        $pop.css({ top: r.bottom + window.scrollY + 6, left: left });
    });
    $pop.on('click', function (e) { e.stopPropagation(); });
    $pop.find('.vd-ayuda-cerrar').on('click', cerrar);
    $(document).on('click', cerrar);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
    $('.merma-tabs-zona .nav-link').on('show.bs.tab', cerrar);
});
