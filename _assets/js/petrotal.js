// _assets/js/petrotal.js
$(function () {
  let ultimoReporte = null;
  const escapeHtml = value => $('<div>').text(value || '').html();

  function renderTabla(selector, filas) {
    const $tbody = $(selector + ' tbody');
    $tbody.empty();
    filas.forEach(function (f) {
      const contraparteLabel = f.contraparte_nombre;
      $tbody.append(
        '<tr>' +
        '<td>' + escapeHtml(f.fecha) + '</td>' +
        '<td>' + escapeHtml(f.folio) + '</td>' +
        '<td>' + escapeHtml(f.producto_label) + '</td>' +
        '<td>' + escapeHtml(contraparteLabel) + '</td>' +
        '<td>' + escapeHtml(f.permiso_cre || '-') + '</td>' +
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

    // Un permiso ambiguo se repite por cada concepto de la misma factura:
    // se muestra una sola vez por factura.
    const vistos = {};
    const unicas = advertencias.filter(function (a) {
      if (a.tipo !== 'permiso_ambiguo') return true;
      if (vistos[a.factura_id]) return false;
      vistos[a.factura_id] = true;
      return true;
    });

    const lista = unicas.map(function (a) {
      let extra = '';
      if (a.tipo === 'permiso_ambiguo') {
        const opciones = (a.candidatos || []).map(function (p) {
          return '<option value="' + escapeHtml(p) + '">' + escapeHtml(p) + '</option>';
        }).join('');
        extra = ' <select class="form-select form-select-sm d-inline-block w-auto sel-permiso" data-factura="' +
          escapeHtml(String(a.factura_id)) + '"><option value="">— elegir permiso —</option>' + opciones + '</select>';
      }
      return '<li>' + escapeHtml(a.mensaje) + extra + '</li>';
    }).join('');

    const bloqueantes = unicas.filter(function (a) { return a.tipo !== 'comprobantes_omitidos'; }).length;
    const clase = bloqueantes ? 'alert-warning' : 'alert-info';
    const titulo = bloqueantes
      ? bloqueantes + ' advertencia(s) — revisa antes de enviar:'
      : 'Información del periodo:';

    $container.append(
      '<div class="alert ' + clase + '">' +
      '<strong>' + titulo + '</strong>' +
      '<ul class="mb-0">' + lista + '</ul>' +
      '</div>'
    );
  }

  // Permisos elegidos a mano para clientes con varios permisos CRE:
  // { facturaId: permiso }. Se reenvían al consultar la vista previa y al
  // generar el JSON; el servidor solo acepta permisos candidatos del RFC.
  const asignaciones = {};

  $(document).on('change', '.sel-permiso', function () {
    const facturaId = $(this).data('factura');
    const permiso = $(this).val();
    if (permiso) {
      asignaciones[facturaId] = permiso;
      $('#btn_preview').trigger('click');
    }
  });

  function hayAdvertenciasBloqueantes(advertencias) {
    return advertencias.some(function (a) {
      return a.tipo === 'sin_permiso' || a.tipo === 'permiso_ambiguo' || a.tipo === 'producto_no_clasificado' || a.tipo === 'permiso_formato_invalido' || a.tipo === 'valor_fuera_de_rango';
    });
  }

  $('#btn_preview').on('click', function () {
    const desde = $('#periodo_desde').val();
    const hasta = $('#periodo_hasta').val();
    if (!desde || !hasta) {
      alert('Selecciona ambas fechas del periodo.');
      return;
    }

    $.get(routeUrl('petrotal.preview_json'), { desde: desde, hasta: hasta, asignaciones: asignaciones })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        ultimoReporte = respuesta;
        renderTabla('#tabla_ventas', respuesta.ventas);
        renderTabla('#tabla_compras', respuesta.compras);
        renderAdvertencias(respuesta.advertencias);

        const bloqueado = hayAdvertenciasBloqueantes(respuesta.advertencias);
        $('#btn_generar_json').prop('disabled', bloqueado || (!respuesta.ventas.length && !respuesta.compras.length));
      })
      .fail(function () {
        alert('Error al consultar la vista previa. Intenta de nuevo.');
      });
  });

  function generarJson(confirmarReenvio) {
    $.post(routeUrl('petrotal.generar_json'), {
      desde: $('#periodo_desde').val(),
      hasta: $('#periodo_hasta').val(),
      asignaciones: asignaciones,
      confirmar_reenvio: confirmarReenvio ? 1 : 0,
    })
      .done(function (respuesta) {
        if (respuesta.error) {
          alert(respuesta.error);
          return;
        }
        if (respuesta.requiere_confirmacion) {
          if (confirm(respuesta.mensaje + '\n\n¿Crear un envío nuevo?')) generarJson(true);
          return;
        }
        $('#json_preview').show().text(respuesta.json);
        $('#btn_enviar_cne').prop('disabled', false).data('envio-id', respuesta.envio_id);
      })
      .fail(function () {
        alert('Error al generar el JSON.');
      });
  }

  $('#btn_generar_json').on('click', function () {
    if (!ultimoReporte) return;
    generarJson(false);
  });

  $('#btn_enviar_cne').on('click', function () {
    const envioId = $(this).data('envio-id');
    if (!envioId) return;
    if (!confirm('¿Enviar este reporte a la CNE? Esta acción intentará el envío real.')) return;

    $.post(routeUrl('petrotal.enviar_reporte'), { envio_id: envioId })
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
