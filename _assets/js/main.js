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

// async function porcent_estacion_info() {
//   console.log("Llamando a porcent_estacion_info");

//   // Llamada AJAX clásica, puedes usar fetch
//   const response = await fetch("/administration/porcent_estacion_info", {
//     method: "POST",
//     headers: {
//       Accept: "application/json, text/javascript, */*",
//       "Content-Type": "application/x-www-form-urlencoded",
//     },
//     credentials: "include",
//   });
//   const contenedor = document.getElementById("cards_estaciones");
//   contenedor.innerHTML = ""; // Limpia antes de renderizar
//   const data = await response.json();
//   console.log("Datos recibidos:", data);
//   data.forEach((estacion) => {
//     // Construye HTML para cada card de estación
//     let card = document.createElement("div");
//     card.classList.add("card-estacion");
//     // Puedes darle estilos CSS a .card-estacion

//     // Título de la estación
//     let title = `<h3>Estación: ${estacion.Estacion}</h3>
//                      <p><strong>Servidor:</strong> ${estacion.Servidor}</p>
//                      <p><strong>Base de Datos:</strong> ${estacion.BaseDatos}</p>
//                      <hr>`;

//     // Tabla de resultados diarios:
//     let table = `
//             <table>
//                 <thead>
//                     <tr>
//                         <th>Fecha</th>
//                         <th>TotalSG12</th>
//                         <th>TotalRemoto</th>
//                         <th>Diferencia</th>
//                         <th>Porcentaje</th>
//                     </tr>
//                 </thead>
//                 <tbody>
//         `;
//     estacion.Resultados.forEach((r) => {
//       table += `
//                 <tr>
//                     <td>${r.Fecha}</td>
//                     <td>${r.TotalSG12}</td>
//                     <td>${r.TotalRemoto}</td>
//                     <td>${r.Diferencia}</td>
//                     <td>${r.Porcentaje ?? "N/A"}%</td>
//                 </tr>
//             `;
//     });
//     table += `
//                 </tbody>
//             </table>
//         `;

//     card.innerHTML = title + table;
//     contenedor.appendChild(card);
//   });

// }




// Función para pintar las cards
function renderEstacionesCards(payload) {
  console.log('Renderizando cards con payload:', payload);
    // Actualiza solo la fecha
    const fechaDiv = document.getElementById('fecha_actualizacion');
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
    const contenedor = document.getElementById('cards_estaciones');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">`;
        let fechas = (estacion.Resultados || []).slice(0, 7).map(r => {
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


async function porcent_estacion_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    console.log(`[${timestamp}] Llamada a la API`);
    const despachos_relation = document.getElementById('despachos_relation');
     despachos_relation.classList.add('loading');
    try {
        const response = await fetch('/administration/porcent_estacion_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        localStorage.setItem('porcent_estaciones', JSON.stringify(payload));
        renderEstacionesCards(payload);
        const final = new Date().toLocaleString();
        console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        despachos_relation.classList.remove('loading');
    }
}




async function porcent_estacion_facturados_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    console.log(`[${timestamp}] Llamada a la API`);
    const despachos_facturados = document.getElementById('despachos_facturados');
    despachos_facturados.classList.add('loading');
    try {
        const response = await fetch('/administration/porcent_estacion_facturados_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        localStorage.setItem('porcent_estaciones', JSON.stringify(payload));
        renderEstacionesFacturadasCards(payload);
        const final = new Date().toLocaleString();
        console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        despachos_facturados.classList.remove('loading');
    }
}

function renderEstacionesFacturadasCards(payload) {
  console.log('Renderizando cards con payload:', payload);
    // Actualiza solo la fecha
    const fechaDiv = document.getElementById('fecha_actualizacion_facturados');
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
    const contenedor = document.getElementById('cards_estaciones_facturados');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">`;
        let fechas = (estacion.Resultados || []).slice(0, 7).map(r => {
            let clase = (r.Porcentaje >= 100 ) ? 'porciento-verde' : 'porciento-rojo';
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



async function porcent_estacion_facturados_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    console.log(`[${timestamp}] Llamada a la API`);
    const despachos_facturados = document.getElementById('despachos_facturados');
    despachos_facturados.classList.add('loading');
    try {
        const response = await fetch('/administration/porcent_estacion_facturados_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        localStorage.setItem('porcent_estaciones', JSON.stringify(payload));
        renderEstacionesFacturadasCards(payload);
        const final = new Date().toLocaleString();
        console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        despachos_facturados.classList.remove('loading');
    }
}

function renderEstacionesFacturadasCards(payload) {
  console.log('Renderizando cards con payload:', payload);
    // Actualiza solo la fecha
    const fechaDiv = document.getElementById('fecha_actualizacion_facturados');
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
    const contenedor = document.getElementById('cards_estaciones_facturados');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">`;
        let fechas = (estacion.Resultados || []).slice(0, 7).map(r => {
            let clase = (r.Porcentaje >= 100 ) ? 'porciento-verde' : 'porciento-rojo';
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


async function porcent_facturas_info() {
    const ahora = new Date();
    const timestamp = ahora.toLocaleString();
    console.log(`[${timestamp}] Llamada a la API`);
    const facturas = document.getElementById('facturas');
    facturas.classList.add('loading');
    try {
        const response = await fetch('/administration/porcent_facturas_info', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
        });
        const data = await response.json();
        const fechaActualizacion = new Date().toLocaleString();

        console.log(`[${timestamp}] Datos recibidos:`, data);
        const payload = {
            datos: data,
            ultimaActualizacion: fechaActualizacion
        };

        localStorage.setItem('porcent_estaciones', JSON.stringify(payload));
        renderEstacionesFacturadasCards(payload);
        const final = new Date().toLocaleString();
        console.log(`[${final}] Datos actualizados y cards renderizadas.`);
    } catch (err) {
      console.error('Error en la petición:', err);
    } finally {
        facturas.classList.remove('loading');
    }
}

function renderEstacionesFacturadasCards(payload) {
  console.log('Renderizando cards con payload:', payload);
    // Actualiza solo la fecha
    const fechaDiv = document.getElementById('fecha_actualizacion_facturas');
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
    const contenedor = document.getElementById('cards_facturas');
    contenedor.innerHTML = '';
    (payload.datos || []).forEach(estacion => {
        let card = document.createElement('div');
        card.classList.add('card-estacion');
        let title = `<h3>${estacion.Nombre || 'Estación ' + estacion.Estacion}</h3>
                     <div class="resumen-fechas">`;
        let fechas = (estacion.Resultados || []).slice(0, 7).map(r => {
            let clase = (r.Porcentaje >= 100 ) ? 'porciento-verde' : 'porciento-rojo';
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