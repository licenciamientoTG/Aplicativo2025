// Formulario para...
$(document).on("submit", "#findClientForm", function (event) {
    event.preventDefault(); // Evita el envío del formulario por defecto
    let formData = $("#findClientForm").serialize(); // Obtén los datos del formulario
    $.ajax({
        type: "POST",
        url: "/marketing/findClientForm", // URL de destino
        data: formData,
        success: function (response) {
            console.log(response); // Puedes mostrar la respuesta en la consola
            if (response === 0) {
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros de clientes con ese código. Intentelo nuevamente.</p>
                    </div>`
                );
                $('#contentWrap').html('');
            } else {
                // Aqui debo mostrar una tabla con los datos del cliente
                $.ajax({
                    url: '/marketing/findClientTable',
                    method: 'POST',
                    data: {
                        'codcli': response
                    },
                    dataType: 'html',
                    success: function(data) {
                        $('#contentWrap').html(data);
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        console.error('AJAX error:', errorThrown);
                    }
                });
            }
        },
        error: function (error) {
            console.error(error); // Manejar errores de la solicitud AJAX aquí
        }
    });
});