// Extend existing 'alert' dialog

if (!alertify.myAlert) {
  //define a new myAlert base on alert
  alertify.dialog('myAlert', function factory() {
    return {
      build: function () {
        let errorHeader = '';
        this.setHeader(errorHeader);
      }
    };
  }, true, 'alert');
}

// Agregar un evento clic al enlace
$('#exportExcel').on('click', function () {
  // Disparar el evento clic del botón de exportación de Excel del DataTable
  $('.buttons-excel').trigger('click');
});

// Agregar un evento clic al enlace
$('#exportPDF').on('click', function () {
  // Disparar el evento clic del botón de exportación de Excel del DataTable
  $('.buttons-pdf').trigger('click');
});

// Agregar un evento clic al enlace
$('#exportPrinter').on('click', function () {
  // Disparar el evento clic del botón de exportación de Excel del DataTable
  $('.buttons-print').trigger('click');
});


// Este codigo es para mantener el menu lateral abierto o cerrado, segun la eleccion del usuario:
document.addEventListener("DOMContentLoaded", function() {
  const sidebar = document.getElementById("sidebar");
  const toggleButton = document.getElementsByClassName("js-sidebar-toggle")[0];

  // Verifica si hay un valor en el almacenamiento local para el menú
  const isMenuCollapsed = localStorage.getItem("menuCollapsed") === "true";

  // Función para alternar el estado del menú y guardar el estado en localStorage
  function toggleMenu() {
    sidebar.classList.toggle("collapsed"), sidebar.addEventListener("transitionend", (() => {
      window.dispatchEvent(new Event("resize"))
    }));
    if (sidebar.classList.contains("collapsed")) {
      sidebar.classList.remove("collapsed");
      localStorage.setItem("menuCollapsed", "false");
    } else {
      sidebar.classList.add("collapsed");
      localStorage.setItem("menuCollapsed", "true");
    }
  }

  // Configura el estado inicial del menú
  if (isMenuCollapsed) {
    sidebar.classList.add("collapsed");
  }

  // Agrega un event listener al botón para alternar el menú
  toggleButton.addEventListener("click", toggleMenu);
});

toastr.options = {
  "closeButton": true,
  "positionClass": "toast-bottom-right"
}




// Función para pintar las cards

///////////////////////////////////////////////////////////////
async function porcent_estacion_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    // console.log(`[${timestamp}] Llamada a la API`);
    const despachos_relation = document.getElementById('despachos_relation');
    despachos_relation.classList.add('loading');

    var from_desp = document.getElementById('from_desp').value;
    var until_desp = document.getElementById('until_desp').value;
    var estacion_desp = document.getElementById('estacion_desp').value;
    
    try {
        const response = await fetch('/administration/porcent_estacion_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body:'from=' + from_desp + '&until=' + until_desp + '&estacion=' + estacion_desp
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        // console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };
        renderEstacionesCards(payload);
        const final = new Date().toLocaleString();
        // console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        despachos_relation.classList.remove('loading');
    }
}

function renderEstacionesCards(payload) {
    console.log('renderEstacionesCards', payload);
    

    var fechaDiv = document.getElementById('fecha_actualizacion');
    if (payload.ultimaActualizacion) {
        fechaDiv.innerHTML = `Última actualización: ${payload.ultimaActualizacion}`;
        fechaDiv.style.marginBottom = '10px';
        fechaDiv.style.fontSize = '0.9em';
        fechaDiv.style.color = '#666';
        fechaDiv.style.fontStyle = 'italic';
        fechaDiv.style.textAlign = 'center';
    } else {
        fechaDiv.innerHTML = '';
    }

    // Renderiza las cards normalmente
    var contenedor = document.getElementById('cards_estaciones');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">`;
        let fechas = (estacion.Resultados || []).map(r => {
            let clase = (r.Porcentaje === 100 || r.Porcentaje === 100.0) ? 'porciento-verde' : 'porciento-rojo';
            if (r.Porcentaje === null || r.Porcentaje === undefined) clase = '';
            return `
                <div class="fecha-row">
                    <span>${r.Fecha}</span>
                    <span class="${clase}">${r.Porcentaje != null ? r.Porcentaje + '%' : 'N/A'}</span>
                </div>`;
        }).join('');
        title += fechas + "</div>";
        card.innerHTML = title;
        contenedor.appendChild(card);
    });
}


/////////////////////////////////////////////////////


async function porcent_estacion_facturados_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    // console.log(`[${timestamp}] Llamada a la API`);
    const despachos_facturados = document.getElementById('despachos_facturados');
    despachos_facturados.classList.add('loading');
    var from_desp = document.getElementById('from_desp2').value;
    var until_desp = document.getElementById('until_desp2').value;
    var estacion_desp = document.getElementById('estacion_desp2').value;
    try {
        const response = await fetch('/administration/porcent_estacion_facturados_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
             body:'from=' + from_desp + '&until=' + until_desp + '&estacion=' + estacion_desp
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        // console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        // localStorage.setItem('porcent_estaciones', JSON.stringify(payload));
        renderDespachosFacturadasCards(payload);
        const final = new Date().toLocaleString();
        // console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        despachos_facturados.classList.remove('loading');
    }
}

function renderDespachosFacturadasCards(payload) {
    // Actualiza solo la fecha
    var fechaDiv = document.getElementById('fecha_actualizacion_facturados');
    if (payload.ultimaActualizacion) {
        fechaDiv.innerHTML = `Última actualización: ${payload.ultimaActualizacion}`;
        fechaDiv.style.marginBottom = '10px';
        fechaDiv.style.fontSize = '0.9em';
        fechaDiv.style.color = '#666';
        fechaDiv.style.fontStyle = 'italic';
        fechaDiv.style.textAlign = 'center';
    } else {
        fechaDiv.innerHTML = '';
    }

    // Renderiza las cards normalmente
    var contenedor = document.getElementById('cards_estaciones_facturados');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">
                     <div class="fecha-row">
                    <span>Fecha</span>
                    <span class="">Remoto 100</span>
                    <span class="">Corpo 100</span>

                </div>`;
        let fechas = (estacion.Resultados || []).map(r => {
            let clase = (r.Porcentaje >= 100 ) ? 'porciento-verde' : 'porciento-rojo';
            if (r.Porcentaje === null || r.Porcentaje === undefined) clase = '';
            return `
                <div class="fecha-row">
                    <span>${r.Fecha}</span>
                    <span class="${clase}">${r.Porcentaje != null ? r.Porcentaje + '%' : 'N/A'}</span>
                    <span class="${clase}">${r.Porcentaje_corpo_base != null ? r.Porcentaje_corpo_base + '%' : 'N/A'}</span>

                </div>`;
        }).join('');
        title += fechas + "</div>";
        card.innerHTML = title;
        contenedor.appendChild(card);
    });
}

///////////////////////////////////////////////////////////////////
async function porcent_facturas_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    // console.log(`[${timestamp}] Llamada a la API`);
    const facturas = document.getElementById('facturas');
    facturas.classList.add('loading');
    var from_desp = document.getElementById('from_desp3').value;
    var until_desp = document.getElementById('until_desp3').value;
    var estacion_desp = document.getElementById('estacion_desp3').value;
    try {
        var contenedor = document.getElementById('cards_facturas');
        contenedor.innerHTML = '';
        const response = await fetch('/administration/porcent_facturas_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body:'from=' + from_desp + '&until=' + until_desp + '&estacion=' + estacion_desp

        });
        const data = await response.json();
        console.log('data', data);
        const fechaActualizacion = new Date().toLocaleString();

        // console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        renderEstacionesFacturadasCards(payload);
        const final = new Date().toLocaleString();
        // console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        facturas.classList.remove('loading');
    }
}

function renderEstacionesFacturadasCards(payload) {
    // Actualiza solo la fecha
    var fechaDiv = document.getElementById('fecha_actualizacion_facturas');
    if (payload.ultimaActualizacion) {
        fechaDiv.innerHTML = `Última actualización: ${payload.ultimaActualizacion}`;
        fechaDiv.style.marginBottom = '10px';
        fechaDiv.style.fontSize = '0.9em';
        fechaDiv.style.color = '#666';
        fechaDiv.style.fontStyle = 'italic';
        fechaDiv.style.textAlign = 'center';
    } else {
        fechaDiv.innerHTML = '';
    }

    // Renderiza las cards normalmente
    var contenedor = document.getElementById('cards_facturas');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');

        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>`;
        
        // Agrega encabezado de tabla con 5 columnas
        title += `
            <div class="resumen-fechas resumen-5columnas encabezado">
                <span>Fecha</span>
                <span>Serie</span>
                <span>Corp</span>
                <span>Remoto</span>
                <span>Dif.</span>
            </div>
        `;
        let filas = (estacion.Resultados || []).map(r => {
            let clase = (r.Diferencia == 0 ) ? 'porciento-verde' : 'porciento-rojo';
            return `
                <div class="resumen-fechas resumen-5columnas">
                    <span>${r.Fecha}</span>
                    <span>${r.Serie}</span>
                    <span>${r.TotalSG12}</span>
                    <span>${r.TotalRemoto}</span>
                    <span class="${clase}">${r.Diferencia}</span>
                </div>
            `;
        }).join('');

        card.innerHTML = title + filas;
        contenedor.appendChild(card);
    });
}