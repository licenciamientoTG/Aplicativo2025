$(document).ready(function () {
    let monthly_summary_shortge_table = $('#monthly_summary_shortge_table').DataTable({
        order: [1, "asc"],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        // colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 50,
        fixedColumns: {
            leftColumns: 2
        },
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            url: '/direction/monthly_summary_shortge_table',
            error: function() {
                $('#monthly_summary_shortge_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');

                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>`
                );

            },
            beforeSend: function() {
                $('.table-responsive').addClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
            $('#monthly_summary_shortge_table').addClass('text-center');
            $('td', row).eq(0).addClass(' table-success');
            $('td', row).eq(1).addClass(' table-success');
            dynamicColumns.forEach((element, index) => {
                if (index >= 2) { // Comienza la verificación desde la tercera columna
                    var col_name = element['data'];
                    if (data[col_name] != '0') {
                        // $('td', row).eq(index).addClass('table_color');
                        $('td', row).eq(index).addClass('text-danger fw-bold');
                    }
                }
            });
        },
        initComplete: function (settings) {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
            window.scrollTo(0,180);

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                        i : 0;
            };
            $(api.column(1).footer()).html("Suma Mensual");
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index !== 0 && index !== 1 && index !== api.columns().length - 1) {
                    var column = this;
                    try {
                        var total = column.data().reduce(function (a, b) {
                            return intVal(a) + intVal(b);
                        }, 0);
                        $(column.footer()).html(total.toFixed(2));
                    } catch (e) {
                        console.error('Error en la columna ' + index + ': ', e);
                    }
                }
            });
        }
    });
    $('.refresh_monthly_summary_shortge_table').on('click', function () {
        monthly_summary_shortge_table.clear().draw();
        monthly_summary_shortge_table.ajax.reload();
        $('#monthly_summary_shortge_table').waitMe('hide');
    });


    ////////////////////////////////////////////////////////////////////////////////////////////
    let daily_summary_shortge_table = $('#daily_summary_shortge_table').DataTable({
        order: [1, "asc"],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        // colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 50,
        fixedColumns: {
            leftColumns: 1
        },
        // fixedHeader: true,
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            url: '/direction/daily_summary_shortge_table',
            data: {
                'id_producto': $('#daily_summary_shortge_table').data('id_producto'),
            },
            error: function() {
                $('#daily_summary_shortge_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');

                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>`
                );

            },
            beforeSend: function() {
                $('.table-responsive').addClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
            $('#daily_summary_shortge_table').addClass('text-center');
            if($('#daily_summary_shortge_table').data('id_producto') == 1){
                $('td', row).eq(0).addClass(' table-success');
            }else if($('#daily_summary_shortge_table').data('id_producto') == 2){
                $('td', row).eq(0).addClass(' table-primary');

            }else if($('#daily_summary_shortge_table').data('id_producto') == 3){
                $('td', row).eq(0).addClass(' table-dark');

            }
            $('td', row).eq(0).addClass(' text-nowrap p-2');
            dynamicColumns.forEach((element, index) => {
                if (index >= 1) { // Comienza la verificación desde la tercera columna
                    var col_name = element['data'];
                    if (data[col_name] != '0') {
                        // $('td', row).eq(index).addClass('table_color');
                        $('td', row).eq(index).addClass('text-danger fw-bold');
                    }
                }
            });
        },
        initComplete: function (settings) {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
            window.scrollTo(0,180);

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                        i : 0;
            };
            // $(api.column(1).footer()).html("Suma Mensual");
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index !== 0  && index !== api.columns().length - 1) {
                    var column = this;
                    try {
                        var total = column.data().reduce(function (a, b) {
                            return intVal(a) + intVal(b);
                        }, 0);
                        $(column.footer()).html(total.toFixed(2));
                    } catch (e) {
                        console.error('Error en la columna ' + index + ': ', e);
                    }
                }
            });
        }
    });
    $('.refresh_daily_summary_shortge_table').on('click', function () {
        daily_summary_shortge_table.clear().draw();
        daily_summary_shortge_table.ajax.reload();
        $('#daily_summary_shortge_table').waitMe('hide');
    });


});