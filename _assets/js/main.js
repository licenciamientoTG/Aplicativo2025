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