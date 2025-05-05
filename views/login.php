<!DOCTYPE html>
<html lang="es">

<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta name="description" content="TotalGas, un sistema de gestión combustible.">
	<meta name="author" content="AdminKit">
	<meta name="keywords" content="adminkit, bootstrap, bootstrap 5, admin, dashboard, template, responsive, css, sass, html, theme, front-end, ui kit, web">

	<link rel="preconnect" href="https://fonts.gstatic.com">
	<link rel="shortcut icon" href="img/icons/icon-48x48.png" />

	<title>TotalGas Login</title>

	<link href="{{ CSS }}app.css" rel="stylesheet">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
</head>

<body>
	<main class="d-flex w-100">
		<div class="container d-flex flex-column">
			<div class="row vh-100">
				<div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 mx-auto d-table h-100">
					<div class="d-table-cell align-middle">
                        <div class="text-center">
                          <img src="{{ IMAGES }}logos\logo_TotalGas_hor.png" class="rounded img-fluid" alt="...">
                        </div>
						<div class="text-center mt-4">
							<h1 class="h2">¡Bienvenid@!</h1>
							<p class="lead">
								Ingresa tus credenciales para poder continuar 
							</p>
						</div>

						<div class="card">
							<div class="card-body">
								<div class="m-sm-3">
								<form action="{{APP_NAME}}_assets/includes/validate.inc.php" method="post">
										<div class="mb-3">
											<label class="form-label">Usuario</label>
											<input class="form-control form-control-lg" type="text" name="username" placeholder="Ingresa tu usuario" />
										</div>
										<div class="mb-3">
											<label class="form-label">Contraseña</label>
											<input class="form-control form-control-lg" type="password" name="password" placeholder="Ingresa tu contraseña">
										</div>
										<div class="mb-3">
										<input text class="form-control form-control-lg" hidden   name="ip" id="ip" >
										<input text class="form-control form-control-lg"  hidden  name="route" id="route" >

                                            <button type="submit" class="btn btn-primary w-100">Ingresar</button>
										</div>
									</form>
								</div>
							</div>
						</div>
						<div class="text-center mb-3">
							¿No tienes una cuenta?
                            <p><a href="javascript:void(0)">Solicítala al departamento de Sistemas</a></p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</main>

	<script src="{{ JS }}app.js"></script>
</body>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var path = window.location.pathname; // Solo el path de la URL
    var route = path.substring(path.indexOf("income")); // Captura desde 'income'
	console.log(path);
	console.log(route);
    document.getElementById('route').value = route;
});

function obtenerIP(response) {
    console.log(response);
    var user_ip = response.ip;

	document.getElementById('ip').value = user_ip;
}
</script>

<script src="https://api.ipify.org?format=jsonp&callback=obtenerIP"></script>
</html>