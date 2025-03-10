$(document).ready(function () {

});

function toggleVisibility() {
    // Selecciona los elementos
    var divGraph = document.getElementById('div_graph');
    var noSelected = document.getElementById('no_selected');

    // Quita el atributo 'hidden' de div_graph y se lo pone a no_selected
    divGraph.removeAttribute('hidden');
    noSelected.setAttribute('hidden', true);
}

async function graph_week(ctx,product) {
    var Id_plaza = document.getElementById('plaza_id').value;
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;


    try {
        var response = await fetch('/direction/week_graph', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `product=${product}&Id_plaza=${Id_plaza}&fromDate=${fromDate}&untilDate=${untilDate}`
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        var data = await response.json();
        var datasets = data.map(grupo => {
            // Filtrar los valores cero en el conjunto de datos actual
            const filteredData = grupo.precios.map((precio, index) => {
                return precio === 0 ? null : { x: grupo.fechas[index], y: precio };
            }).filter(point => point !== null); // Eliminar puntos nulos (ceros)

            return {
                label: grupo.label,
                data: filteredData,
                borderColor: getColorByLabel(grupo.label),
                fill: false,
                tension: 0.1,
                borderWidth: grupo.label === 'TOTALGAS' ? 3 : 1
            };
        });

        if (ctx.chart) {
            // Eliminar los datos existentes y destruir el gráfico anterior
            removeData(ctx.chart);
            addData(ctx.chart, data[0].fechas, data[0].precios);
        }else{
            var config = {type: 'line',
                data: {
                    labels: data[0].fechas, // Usamos las fechas del primer grupo como etiquetas del eje X
                    datasets: datasets
                },
                options: {
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Fecha'
                            }
                        },

                        yAxes: [{
                            title: {
                                display: true,
                                text: 'Precio'
                            },
                            // beginAtZero: false,
                            // min: 10,
                            ticks: {
                                stepSize: 0.2,// Ajusta el paso de los valores en el eje Y a 0.2
                                // min: 16,
                            }
                        }]
                    },
                    legend: {
                        position:'right'
                    }
    
                }};
             new Chart(ctx, config);


        }
        
    } catch (error) {
        console.error('Error:', error);
    }
}

function addData(chart, label, newData) {
    chart.data.labels.push(label);
    chart.data.datasets.forEach((dataset) => {
        dataset.data.push(newData);
    });
    chart.update();
}

function removeData(chart) {
    chart.data.labels.pop();
    chart.data.datasets.forEach((dataset) => {
        dataset.data.pop();
    });
    chart.update();
}
 
async function graph_month(ctx,product) {
    var Id_plaza = document.getElementById('plaza_id').value;
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    try {
        var response = await fetch('/direction/graph_month', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `product=${product}&Id_plaza=${Id_plaza}&fromDate=${fromDate}&untilDate=${untilDate}`
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        var data = await response.json();
        var currentYear = new Date().getFullYear();
        var filteredData = data.map(grupo => {
            var filteredFechas = [];
            var filteredPrecios = [];

            grupo.year_num.forEach((year, index) => {
                var month = grupo.month_num[index]; // Obtener el mes correspondiente al mismo índice
                // Crear la fecha en formato YYYY-MM usando year y month
                var formattedDate = `${year}-${month.toString().padStart(2, '0')}`;
                filteredFechas.push(formattedDate); // Agregar la fecha formateada al arreglo
                // Agregar el precio correspondiente al mismo índice
                filteredPrecios.push(grupo.precios[index]);
            });

            return {
                label: grupo.label,
                fechas: filteredFechas,
                precios: filteredPrecios
            };
        });
        var datasets = filteredData.map(grupo => ({
            label: grupo.label,
            data: grupo.precios,
            borderColor: getColorByLabel(grupo.label), // Genera un color aleatorio para cada grupo
            fill: false,
            tension: 0.1,
            pointStyle: 'rectRot',
            pointRadius: 5,
            spanGaps: true,
            borderWidth:grupo.label === 'TOTALGAS' ? 4 : 1.25 ,////ancho de la linea
        }));
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: filteredData[0].fechas, // Usamos las fechas del primer grupo como etiquetas del eje X
                datasets: datasets
            },
            options: {
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Fecha'
                        }
                    },
                    yAxes: [{
                        title: {
                            display: true,
                            text: 'Precio'
                        },
                        beginAtZero: false,
                        ticks: {
                            stepSize: 0.2, // Ajusta el paso de los valores en el eje Y a 0.2
                            // min: 16,
                        }
                    }]
                },
                legend: {
                    position:'right',
                }

            }
        });
    } catch (error) {
        console.error('Error:', error);
    }
}


function getColorByLabel(label) {
    var colorMapping = {
        "ARCO": "#0000ff",
        "AUTOPRONTO": "#FF9F40",
        "GAZPRO": "#4BC0C0",
        "GOCALVI": "#FFCD56",
        "GRUPO LINDAVISTA": "#FF9A56",
        "HERRERA": "#9966FF",
        "IMPERIAL": "#00ffff",
        "OXXO": "#FF0000",
        "PETROL": "#808080",
        "RAPIDITOS": "#8B4513",
        "TOTALGAS": "#47D147",
        "AUTOMOTRIZ": "#FF5733",
        "ENERG Y SERV TERRA": "#572364",
        "GASOLINEROS DE MEX": "#A133FF ",
        "REPSOL": "#00cde6",
        "TECNOCENTRO": "#FF33A1",
        "G500": "#FF3333",
        "PEMEX PL/10110": "#8B4513",
        "PEMEX ES 15172": "#FF5733",
        "HERRERA PL/10706": "#Ff0000",
        "ARCO PL/2256": "#12f8fe",
        "ARCO PL/2273": "#010bc5",
        "BIP GAS PL/4601": "#fe6ee7",
        "VALERO": "#FFB733",
        "VALERO PL22558": "#1E90FF",
        "BP (ALBA)": "#FF5733",
        "BP (ASTRA)": "#FFD433",
        "HIDROCALIDA": "#FF33A1",
        "BELLAVISTA": "#1E90FF",
        "GASOLINERA J V": "#FF3333",
        "Gasolinera G.J.V.": "#FF6B6B",
        "GASOLINERAS G J V PL5935": "#F39C12",
        "JURADO": "#9B59B6",
        "OXXO PL3351": "#3498DB",
        "PETRODERIVADOS": "#34495E",
        "WINDSTAR": "#F1C40F",

        "GIPOWER": "#D35400",
        "BLACK GOLD": "#34495E",
        "OXXOGAS PL/11379": "#8E44AD",
        "OXXOGAS PL/9770": "#F1C40F",
        "US FUEL": "#FF33A1",
        "COMBUSTIBLES Y LUBRICANTES": "#2ECC71",
        "VENCEDORES DEL DESIERTO": "#3498DB",

        "BP": "#FF4500",
        "PEMEX CARACOL": "#00BFFF",
        "SERVIFACIL": "#FF1493",




    };

    // Si el label existe en el mapeo, retorna el color correspondiente
    if (colorMapping[label]) {
        return colorMapping[label];
    }
    return generateNonGreenColor();
    // Si no existe, genera un color aleatorio
    // var letters = '0123456789ABCDEF';
    // let color = '#';
    // for (let i = 0; i < 6; i++) {
    //     color += letters[Math.floor(Math.random() * 16)];
    // }
    // return color;
}
function generateNonGreenColor() {
    var letters = '0123456789ABCDEF';
    let color = '#';

    // Asegúrate de que el componente verde (G) no sea significativamente mayor que rojo (R) y azul (B)
    let r = Math.floor(Math.random() * 256);
    let g = Math.floor(Math.random() * 128); // Limita el valor de verde (G) para evitar tonos verdes
    let b = Math.floor(Math.random() * 256);

    // Convertir a formato hexadecimal y asegurar dos dígitos
    color += r.toString(16).padStart(2, '0');
    color += g.toString(16).padStart(2, '0');
    color += b.toString(16).padStart(2, '0');

    return color;
}


async function update_graph(table_id) {
    toggleVisibility();
    var class_name;
    if (table_id === 'historic_price_table_pivot') {
        class_name = 'table-success';
    }
    if (table_id === 'historic_price_super_table_pivot') {
        class_name = 'table-primary';
        
    }
    if (table_id === 'historic_price_diesel_table_pivot') {
        class_name = 'table-dark';
        
    }
   
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
  
    var [fromYear, fromMonth, fromDay] = fromDate.split('-').map(Number);
    var from = new Date(fromYear, fromMonth - 1, fromDay);
    
    var [untilYear, untilMonth, untilDay] = untilDate.split('-').map(Number);  
    var until = new Date(untilYear, untilMonth - 1, untilDay);

    // Verifica que las fechas sean válidas
    if (isNaN(from) || isNaN(until) || from > until) {
        console.error("Por favor, selecciona un rango de fechas válido.");
        return;
    }

    var table = document.getElementById(table_id);
    if (!table) {
        console.warn(`Tabla con ID ${table_id} no encontrada.`);
        return;
    }

    var thead = table.querySelector('thead tr');
    var tfoot = table.querySelector('tfoot tr');
    var tbody = table.querySelector('tbody ');
    tbody.innerHTML = '';  // Limpia el cuerpo de la tabla

    // Limpia las cabeceras y pie de tabla completamente
    thead.innerHTML = '<th class=" '+class_name +' ">Grupo</th>';  // Mantén la columna "Grupo"
    tfoot.innerHTML = '<th>Grupo</th>';  // Columna de pie vacía para "Grupo"

    var currentDate = new Date(from);
    while (currentDate <= until) {
        var th = document.createElement('th');
        th.className = class_name;
        th.textContent = currentDate.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
        thead.appendChild(th);

        var tf = document.createElement('th');
        tfoot.appendChild(tf);

        currentDate.setDate(currentDate.getDate() + 1);
    }

    var thAverage = document.createElement('th');
    thAverage.textContent = 'Promedio';
    thead.appendChild(thAverage);

    var tfAverage = document.createElement('th');
    tfoot.appendChild(tfAverage);
}

async function historic_price_table_pivot(){
    if ($.fn.DataTable.isDataTable('#historic_price_table_pivot')) {
        $('#historic_price_table_pivot').DataTable().destroy();  // Destruye la tabla existente
        console.log('Tabla destruida');
    }
    var plaza_id = document.getElementById('plaza_id').value;
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateDateColumns(fromDate, untilDate);
    dynamicColumns.unshift({ data: "grupo", className: "table-success" });
    dynamicColumns.push({  data: "average" });
    // if ($.fn.DataTable.isDataTable('#historic_price_table_pivot')) {
    //     $('#historic_price_table_pivot').DataTable().destroy();
    // }

    await update_graph('historic_price_table_pivot');

   $('#historic_price_table_pivot').DataTable({
        order: [0, "asc"],
        // colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 25,
        fixedColumns: true,
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            data: {
                'product': 1,
                'Id_plaza':plaza_id,
                'fromDate':fromDate,
                'untilDate':untilDate
            },
            url: '/direction/historic_price_table_pivot2',
            error: function() {
                $('#historic_price_table_pivot').waitMe('hide');
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
        destroy: true, 
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
            $('td', row).eq(0).addClass(' table-success');
        },
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para convertir los valores a números
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                        i : 0;
            };

            $(api.column(0).footer()).html("Promedio Zona");
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index !== 0 && index !== api.columns().length - 1) {

                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    var count = column.data().length;// Contar el número de elementos en la columna
                    var average = count > 0 ? total / count : 0;// Calcular el promedio
                    $(column.footer()).html(// Mostrar el promedio en el footer
                        average.toFixed(2) // Ajusta el número de decimales según tus necesidades
                    );
                }
            });
        }
    });
    // Agregar un evento clic de refresh
    // $('.refresh_historic_price_table_pivot').on('click', function () {
    //     historic_price_table_pivot.clear().draw();
    //     historic_price_table_pivot.ajax.reload();
    //     $('#historic_price_table_pivot').waitMe('hide');
    // });
}


async function historic_price_super_table_pivot(){
    if ($.fn.DataTable.isDataTable('#historic_price_super_table_pivot')) {
        $('#historic_price_super_table_pivot').DataTable().destroy();  // Destruye la tabla existente
        console.log('Tabla destruida');
    }
    var plaza_id = document.getElementById('plaza_id').value;
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateDateColumns(fromDate, untilDate);
    dynamicColumns.unshift({ data: "grupo", className: "table-primary" });
    dynamicColumns.push({  data: "average" });
    // if ($.fn.DataTable.isDataTable('#historic_price_super_table_pivot')) {
    //     $('#historic_price_super_table_pivot').DataTable().destroy();
    // }

    await update_graph('historic_price_super_table_pivot');

   $('#historic_price_super_table_pivot').DataTable({
        order: [0, "asc"],
        // colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 25,
        fixedColumns: true,
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            data: {
                'product': 2,
                'Id_plaza':plaza_id,
                'fromDate':fromDate,
                'untilDate':untilDate
            },
            url: '/direction/historic_price_table_pivot2',
            error: function() {
                $('#historic_price_super_table_pivot').waitMe('hide');
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
        destroy: true, 
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
            $('td', row).eq(0).addClass(' table-primary');
        },
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para convertir los valores a números
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                        i : 0;
            };

            $(api.column(0).footer()).html("Promedio Zona");
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index !== 0 && index !== api.columns().length - 1) {

                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    var count = column.data().length;// Contar el número de elementos en la columna
                    var average = count > 0 ? total / count : 0;// Calcular el promedio
                    $(column.footer()).html(// Mostrar el promedio en el footer
                        average.toFixed(2) // Ajusta el número de decimales según tus necesidades
                    );
                }
            });
        }
    });
    // Agregar un evento clic de refresh
    // $('.refresh_historic_price_super_table_pivot').on('click', function () {
    //     historic_price_super_table_pivot.clear().draw();
    //     historic_price_super_table_pivot.ajax.reload();
    //     $('#historic_price_super_table_pivot').waitMe('hide');
    // });
}


async function historic_price_diesel_table_pivot(){
    if ($.fn.DataTable.isDataTable('#historic_price_diesel_table_pivot')) {
        $('#historic_price_diesel_table_pivot').DataTable().destroy();  // Destruye la tabla existente
        console.log('Tabla destruida');
    }
    var plaza_id = document.getElementById('plaza_id').value;
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateDateColumns(fromDate, untilDate);
    dynamicColumns.unshift({ data: "grupo", className: "table-dark" });
    dynamicColumns.push({  data: "average" });
    // if ($.fn.DataTable.isDataTable('#historic_price_diesel_table_pivot')) {
    //     $('#historic_price_diesel_table_pivot').DataTable().destroy();
    // }

    await update_graph('historic_price_diesel_table_pivot');

   $('#historic_price_diesel_table_pivot').DataTable({
        order: [0, "asc"],
        // colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 25,
        fixedColumns: true,
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            data: {
                'product': 3,
                'Id_plaza':plaza_id,
                'fromDate':fromDate,
                'untilDate':untilDate
            },
            url: '/direction/historic_price_table_pivot2',
            error: function() {
                $('#historic_price_diesel_table_pivot').waitMe('hide');
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
        destroy: true, 
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
            $('td', row).eq(0).addClass(' table-dark');
        },
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para convertir los valores a números
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                        i : 0;
            };

            $(api.column(0).footer()).html("Promedio Zona");
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index !== 0 && index !== api.columns().length - 1) {

                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    var count = column.data().length;// Contar el número de elementos en la columna
                    var average = count > 0 ? total / count : 0;// Calcular el promedio
                    $(column.footer()).html(// Mostrar el promedio en el footer
                        average.toFixed(2) // Ajusta el número de decimales según tus necesidades
                    );
                }
            });
        }
    });
    // Agregar un evento clic de refresh
    // $('.refresh_historic_price_diesel_table_pivot').on('click', function () {
    //     historic_price_diesel_table_pivot.clear().draw();
    //     historic_price_diesel_table_pivot.ajax.reload();
    //     $('#historic_price_diesel_table_pivot').waitMe('hide');
    // });
}

function generateDateColumns(fromDate, untilDate) {
    let columns = [];

    var [fromYear, fromMonth, fromDay] = fromDate.split('-').map(Number);
    var currentDate = new Date(fromYear, fromMonth - 1, fromDay);
    
    var [untilYear, untilMonth, untilDay] = untilDate.split('-').map(Number);  
    var endDate = new Date(untilYear, untilMonth - 1, untilDay);




    // let currentDate = new Date(fromDate);
    // var endDate = new Date(untilDate);

    while (currentDate <= endDate) {
        let dateStr = currentDate.toLocaleDateString('es-ES', { month: '2-digit',day: '2-digit' });
        dateStr = dateStr.replace(/\//g, '_'); // Cambia / por _
        columns.push({
            data:  dateStr // Ajusta para que coincida con el formato de tus datos
        });
        currentDate.setDate(currentDate.getDate() + 1); // Avanza al siguiente día
    }

    return columns;
}