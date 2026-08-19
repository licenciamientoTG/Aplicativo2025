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

    // ---- Carga manual: Balance de Producto (Praxedis) ---------------------
    let balanceFiles = [];
    let balancePreview = [];

    window.abrirModalBalancePraxedis = function () {
        $('#inputBalances').val('');
        $('#balanceArchivosSel').html('');
        $('#balanceResumen').hide();
        $('#balanceTablaWrap').hide();
        $('#balanceLoading').hide();
        $('#tablaBalancePreview tbody').empty();
        $('#btnGuardarBalance').prop('disabled', true);
        balanceFiles = [];
        balancePreview = [];
        $('#balancePraxedisModal').modal('show');
    };

    $(document).on('click', '#balanceDropzone', function () { $('#inputBalances').click(); });
    $(document).on('change', '#inputBalances', function () {
        if (this.files && this.files.length) subirBalancePreview(this.files);
    });
    $(document).on('dragover', '#balanceDropzone', function (e) { e.preventDefault(); $(this).css('background', '#eef2ff'); });
    $(document).on('dragleave drop', '#balanceDropzone', function (e) { e.preventDefault(); $(this).css('background', '#f9fafb'); });
    $(document).on('drop', '#balanceDropzone', function (e) {
        e.preventDefault();
        const files = e.originalEvent.dataTransfer.files;
        if (files && files.length) subirBalancePreview(files);
    });

    function subirBalancePreview(fileList) {
        const files = Array.from(fileList).filter(f => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf'));
        if (files.length === 0) { Swal.fire({ icon: 'warning', title: 'Selecciona PDFs' }); return; }

        balanceFiles = files;
        $('#balanceArchivosSel').html('<i class="fas fa-paperclip"></i> ' + files.length + ' archivo(s) seleccionado(s)');
        $('#balanceLoading').show();
        $('#balanceTablaWrap').hide();
        $('#balanceResumen').hide();

        const fd = new FormData();
        files.forEach(f => fd.append('balances[]', f));

        fetch('/merma/preview_balance_praxedis', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                $('#balanceLoading').hide();
                if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Error al procesar' }); return; }
                balancePreview = res.archivos || [];
                $('#balanceOk').text(res.resumen.ok || 0);
                $('#balanceErr').text(res.resumen.error || 0);
                $('#balanceResumen').show();
                renderBalanceTabla(balancePreview);
                $('#btnGuardarBalance').prop('disabled', res.resumen.ok === 0);
            })
            .catch(err => { $('#balanceLoading').hide(); Swal.fire({ icon: 'error', title: 'Conexión', text: err.message }); });
    }

    function renderBalanceTabla(archivos) {
        let html = '';
        archivos.forEach(function (a) {
            if (!a.ok) {
                html += '<tr style="background:#fef2f2;"><td><small>' + a.archivo + '</small></td>'
                    + '<td colspan="5"><small class="text-danger">' + (a.error || 'Error') + '</small></td>'
                    + '<td><span class="badge bg-danger">Error</span></td></tr>';
                return;
            }
            a.filas.forEach(function (f, idx) {
                html += '<tr style="background:#ecfdf5;">'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '"><small>' + a.archivo + '</small></td>'
                                   + '<td rowspan="' + a.filas.length + '">' + a.fecha + '</td>' : '')
                    + '<td>' + f.producto + '</td>'
                    + '<td class="text-end">' + Number(f.inv_fisico).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.ventas_reales).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.compras).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '"><span class="badge bg-success">Listo</span></td>' : '')
                    + '</tr>';
            });
        });
        $('#tablaBalancePreview tbody').html(html);
        $('#balanceTablaWrap').show();
    }

    window.guardarBalancePraxedis = function () {
        if (balanceFiles.length === 0) { Swal.fire({ icon: 'warning', title: 'Sin archivos' }); return; }

        Swal.fire({
            icon: 'question', title: 'Confirmar carga',
            html: 'Vas a guardar el corte de Praxedis para las fechas leídas. Si ya existía un corte para alguna de esas fechas, se sobrescribirá.',
            showCancelButton: true, confirmButtonText: 'Sí, guardar', cancelButtonText: 'Cancelar',
        }).then(function (r) {
            if (!r.isConfirmed) return;

            const fd = new FormData();
            balanceFiles.forEach(f => fd.append('balances[]', f));

            $('#btnGuardarBalance').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
            fetch('/merma/guardar_balance_praxedis', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (res) {
                    $('#btnGuardarBalance').html('<i class="fas fa-save"></i> Confirmar carga');
                    const detalle = (res.resultados || []).map(x =>
                        '<li>' + (x.success ? '✅' : '❌') + ' <strong>' + x.archivo + '</strong>: ' + x.message + '</li>').join('');
                    Swal.fire({
                        icon: res.success ? 'success' : 'error',
                        title: 'Resultado',
                        html: '<div class="alert alert-' + (res.success ? 'success' : 'danger') + '">'
                            + (res.filas || 0) + ' filas guardadas en ' + ((res.fechas || []).length) + ' fecha(s)</div>'
                            + '<ul style="text-align:left;font-size:.85rem;max-height:300px;overflow:auto;">' + detalle + '</ul>',
                    });
                    if (res.success) {
                        $('#balancePraxedisModal').modal('hide');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        $('#btnGuardarBalance').prop('disabled', false);
                    }
                })
                .catch(function (err) {
                    $('#btnGuardarBalance').prop('disabled', false).html('<i class="fas fa-save"></i> Confirmar carga');
                    Swal.fire({ icon: 'error', title: 'Conexión', text: err.message });
                });
        });
    };

    // ---- Carga de PDF: Ventas Periodo Inventario (Colosio) -----------------
    let colosioFiles = [];
    let colosioPreview = [];

    window.abrirModalColosio = function () {
        $('#inputVentasColosio').val('');
        $('#colosioArchivosSel').html('');
        $('#colosioResumen').hide();
        $('#colosioTablaWrap').hide();
        $('#colosioLoading').hide();
        $('#tablaColosioPreview tbody').empty();
        $('#btnGuardarColosio').prop('disabled', true);
        colosioFiles = [];
        colosioPreview = [];
        $('#colosioModal').modal('show');
    };

    $(document).on('click', '#colosioDropzone', function () { $('#inputVentasColosio').click(); });
    $(document).on('change', '#inputVentasColosio', function () {
        if (this.files && this.files.length) subirColosioPreview(this.files);
    });
    $(document).on('dragover', '#colosioDropzone', function (e) { e.preventDefault(); $(this).css('background', '#eef2ff'); });
    $(document).on('dragleave drop', '#colosioDropzone', function (e) { e.preventDefault(); $(this).css('background', '#f9fafb'); });
    $(document).on('drop', '#colosioDropzone', function (e) {
        e.preventDefault();
        const files = e.originalEvent.dataTransfer.files;
        if (files && files.length) subirColosioPreview(files);
    });

    function subirColosioPreview(fileList) {
        const files = Array.from(fileList).filter(f => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf'));
        if (files.length === 0) { Swal.fire({ icon: 'warning', title: 'Selecciona PDFs' }); return; }

        colosioFiles = files;
        $('#colosioArchivosSel').html('<i class="fas fa-paperclip"></i> ' + files.length + ' archivo(s) seleccionado(s)');
        $('#colosioLoading').show();
        $('#colosioTablaWrap').hide();
        $('#colosioResumen').hide();

        const fd = new FormData();
        files.forEach(f => fd.append('ventas[]', f));

        fetch('/merma/preview_colosio_pdf', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                $('#colosioLoading').hide();
                if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Error al procesar' }); return; }
                colosioPreview = res.archivos || [];
                const yaExisten = colosioPreview.filter(a => a.ya_existe).length;
                $('#colosioOk').text(res.resumen.ok || 0);
                $('#colosioErr').text(res.resumen.error || 0);
                $('#colosioSobrescribe').text(yaExisten);
                $('#colosioResumen').show();
                renderColosioTabla(colosioPreview);
                $('#btnGuardarColosio').prop('disabled', res.resumen.ok === 0);
            })
            .catch(err => { $('#colosioLoading').hide(); Swal.fire({ icon: 'error', title: 'Conexión', text: err.message }); });
    }

    function renderColosioTabla(archivos) {
        let html = '';
        archivos.forEach(function (a) {
            if (!a.ok) {
                html += '<tr style="background:#fef2f2;"><td><small>' + a.archivo + '</small></td>'
                    + '<td colspan="5"><small class="text-danger">' + (a.error || 'Error') + '</small></td>'
                    + '<td><span class="badge bg-danger">Error</span></td></tr>';
                return;
            }
            const filaBg = a.ya_existe ? '#fffbeb' : '#ecfdf5';
            const badge = a.ya_existe
                ? '<span class="badge bg-warning text-dark" title="Ya existe un corte guardado para esta fecha; al confirmar se sobrescribirá">Se sobrescribirá</span>'
                : '<span class="badge bg-success">Listo</span>';
            a.filas.forEach(function (f, idx) {
                html += '<tr style="background:' + filaBg + ';">'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '"><small>' + a.archivo + '</small></td>'
                                   + '<td rowspan="' + a.filas.length + '">' + a.fecha + '</td>' : '')
                    + '<td>' + f.producto + '</td>'
                    + '<td class="text-end">' + Number(f.inv_fisico).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.ventas_reales).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + '<td class="text-end">' + Number(f.compras).toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>'
                    + (idx === 0 ? '<td rowspan="' + a.filas.length + '">' + badge + '</td>' : '')
                    + '</tr>';
            });
        });
        $('#tablaColosioPreview tbody').html(html);
        $('#colosioTablaWrap').show();
    }

    window.guardarColosioPdf = function () {
        if (colosioFiles.length === 0) { Swal.fire({ icon: 'warning', title: 'Sin archivos' }); return; }

        Swal.fire({
            icon: 'question', title: 'Confirmar carga',
            html: 'Vas a guardar el corte de Colosio para las fechas leídas. Si ya existía un corte para alguna de esas fechas, se sobrescribirá.',
            showCancelButton: true, confirmButtonText: 'Sí, guardar', cancelButtonText: 'Cancelar',
        }).then(function (r) {
            if (!r.isConfirmed) return;

            const fd = new FormData();
            colosioFiles.forEach(f => fd.append('ventas[]', f));

            $('#btnGuardarColosio').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
            fetch('/merma/guardar_colosio_pdf', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (res) {
                    $('#btnGuardarColosio').html('<i class="fas fa-save"></i> Confirmar carga');
                    const detalle = (res.resultados || []).map(x =>
                        '<li>' + (x.success ? '✅' : '❌') + ' <strong>' + x.archivo + '</strong>: ' + x.message + '</li>').join('');
                    Swal.fire({
                        icon: res.success ? 'success' : 'error',
                        title: 'Resultado',
                        html: '<div class="alert alert-' + (res.success ? 'success' : 'danger') + '">'
                            + (res.filas || 0) + ' filas guardadas en ' + ((res.fechas || []).length) + ' fecha(s)</div>'
                            + '<ul style="text-align:left;font-size:.85rem;max-height:300px;overflow:auto;">' + detalle + '</ul>',
                    });
                    if (res.success) {
                        $('#colosioModal').modal('hide');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        $('#btnGuardarColosio').prop('disabled', false);
                    }
                })
                .catch(function (err) {
                    $('#btnGuardarColosio').prop('disabled', false).html('<i class="fas fa-save"></i> Confirmar carga');
                    Swal.fire({ icon: 'error', title: 'Conexión', text: err.message });
                });
        });
    };
});
