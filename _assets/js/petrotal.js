// _assets/js/petrotal.js
$(function () {
  let ultimoReporte = null;

  function renderTabla(selector, filas, esVenta) {
    const $tbody = $(selector + ' tbody');
    $tbody.empty();
    filas.forEach(function (f) {
      const contraparteLabel = esVenta ? f.contraparte_nombre : f.contraparte_nombre;
      $tbody.append(
        '<tr>' +
        '<td>' + f.fecha + '</td>' +
        '<td>' + f.folio + '</td>' +
        '<td>' + f.producto_label + '</td>' +
        '<td>' + contraparteLabel + '</td>' +
        '<td>' + (f.permiso_cre || '-') + '</td>' +
        '<td>' + f.volumen_bbl.toFixed(2) + '</td>' +
        '<td>' + f.precio.toFixed(2) + '</td>' +
        '</tr>'
      );
    });
  }

  function renderAdvertencias(advertencias) {
    const $container = $('#advertencias_container');
    $container.empty();
    if (!advertencias.length) return;

    const lista = advertencias.map(function (a) {
      return '<li>' + a.mensaje + '</li>';
    }).join('');

    $container.append(
      '<div class="alert alert-warning">' +
      '<strong>' + advertencias.length + ' advertencia(s) — revisa antes de enviar:</strong>' +
      '<ul>' + lista + '</ul>' +
      '</div>'
    );
  }

  function hayAdvertenciasBloqueantes(advertencias) {
    return advertencias.some(function (a) {
      return a.tipo === 'sin_permiso' || a.tipo === 'permiso_ambiguo' || a.tipo === 'producto_no_clasificado';
    });
  }

  $('#btn_preview').on('click', function () {
    const desde = $('#periodo_desde').val();
    const hasta = $('#periodo_hasta').val();
    if (!desde || !hasta) {
      alert('Selecciona ambas fechas del periodo.');
      return;
    }

    $.get('/petrotal/preview_json', { desde: desde, hasta: hasta })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        ultimoReporte = respuesta;
        renderTabla('#tabla_ventas', respuesta.ventas, true);
        renderTabla('#tabla_compras', respuesta.compras, false);
        renderAdvertencias(respuesta.advertencias);

        const bloqueado = hayAdvertenciasBloqueantes(respuesta.advertencias);
        $('#btn_generar_json').prop('disabled', bloqueado || (!respuesta.ventas.length && !respuesta.compras.length));
      })
      .fail(function () {
        alert('Error al consultar la vista previa. Intenta de nuevo.');
      });
  });

  $('#btn_generar_json').on('click', function () {
    if (!ultimoReporte) return;
    $.post('/petrotal/generar_json', {
      desde: $('#periodo_desde').val(),
      hasta: $('#periodo_hasta').val(),
    })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        $('#json_preview').show().text(respuesta.json);
        $('#btn_enviar_cne').prop('disabled', false).data('envio-id', respuesta.envio_id);
      })
      .fail(function () {
        alert('Error al generar el JSON.');
      });
  });

  $('#btn_enviar_cne').on('click', function () {
    const envioId = $(this).data('envio-id');
    if (!envioId) return;
    if (!confirm('¿Enviar este reporte a la CNE? Esta acción intentará el envío real.')) return;

    $.post('/petrotal/enviar_reporte', { envio_id: envioId })
      .done(function (respuesta) {
        if (respuesta.ok) {
          alert('Reporte enviado. Folio de acuse: ' + (respuesta.folio_acuse || '(pendiente)'));
        } else {
          alert('No se pudo enviar: ' + (respuesta.mensaje || 'error desconocido'));
        }
      })
      .fail(function () {
        alert('Error de red al intentar el envío.');
      });
  });
});
