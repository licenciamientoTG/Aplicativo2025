<?php
// Función global para calcular la diferencia entre dos fechas
$datetimeDiffDays = new \Twig\TwigFunction('datetimeDiffDays', function ($dt1, $dt2=false) {
    if (!is_null($dt1) OR $dt1 != '') {
        $t1 = strtotime($dt1);
        $t2 = ($dt2==false) ? strtotime("now") : strtotime($dt2) ;

        if ($t1 > $t2) {
          $dtd = new stdClass();
          $dtd->day = 0;
          $dtd->hour = 0;
          $dtd->min = 0;
          $dtd->sec = 0;
        } else {
          $dtd = new stdClass();
          $dtd->interval = $t2 - $t1;
          $dtd->total_sec = abs($t2-$t1);
          $dtd->total_min = floor($dtd->total_sec/60);
          $dtd->total_hour = floor($dtd->total_min/60);
          $dtd->total_day = floor($dtd->total_hour/24);
          $dtd->total_year = floor($dtd->total_day/365);

          $dtd->year = $dtd->total_year;
          $dtd->day = $dtd->total_day - ($dtd->total_year*365);
          $dtd->hour = $dtd->total_hour -($dtd->total_day*24);
          $dtd->min = $dtd->total_min -($dtd->total_hour*60);
          $dtd->sec = $dtd->total_sec -($dtd->total_min*60);
        }
        return [
          $dtd->year,
          $dtd->day,
          $dtd->hour,
          $dtd->min,
          $dtd->sec
        ];

      } else {
        return 'N/A';
      }
});

// Función global para calcular la diferencia entre dos fechas
$datetimeDiffHours = new \Twig\TwigFunction('datetimeDiffHours', function ($dt1, $dt2=false) {
    if ($dt2!=false) {
        if (!is_null($dt1) OR $dt1 != '') {
            $t1 = strtotime($dt1);
            $t2 = ($dt2==false) ? strtotime("now") : strtotime($dt2) ;

            if ($t1 > $t2) {
              $dtd = new stdClass();
              $dtd->day = 0;
              $dtd->hour = 0;
              $dtd->min = 0;
              $dtd->sec = 0;
            } else {
              $dtd = new stdClass();
              $dtd->interval = $t2 - $t1;
              $dtd->total_sec = abs($t2-$t1);
              $dtd->total_min = floor($dtd->total_sec/60);
              $dtd->total_hour = floor($dtd->total_min/60);
              $dtd->total_day = floor($dtd->total_hour/24);
              $dtd->total_year = floor($dtd->total_day/365);

              $dtd->year = $dtd->total_year;
              $dtd->day = $dtd->total_day - ($dtd->total_year*365);
              $dtd->hour = $dtd->total_hour -($dtd->total_day*24);
              $dtd->min = $dtd->total_min -($dtd->total_hour*60);
              $dtd->sec = $dtd->total_sec -($dtd->total_min*60);
            }
            return [
              $dtd->year,
              $dtd->day,
              $dtd->hour,
              $dtd->min,
              $dtd->sec
            ];
        } else {
            return 'N/A';
        }
    } else {
        return false;
    }

});

// Función global para todos los archivos de Twig, se llama en archivo Index.php
$strpad = new \Twig\TwigFunction('strpad', function ($number, $pad_length, $pad_string) {
    return str_pad($number, $pad_length, $pad_string, STR_PAD_LEFT);
});

// Función global para todos los archivos de Twig, se llama en archivo Index.php
$authorized = new \Twig\TwigFunction('authorized', function ($permission_id) {
  return (in_array($permission_id, explode(",", $_SESSION['tg_user']['permissions']))) ? true : false ;
});

// Función global para todos los archivos de Twig, se llama en archivo Index.php
$foradmin = new \Twig\TwigFunction('foradmin', function () {
    return ($_SESSION['tg_user']["Id"] == 6177) ? true : false ;
});

// Función global para establecer mensajes flash
$getFlashMessage = new \Twig\TwigFunction('getFlashMessage', function ($type) use ($twig) {
  if (isset($_SESSION['flash'][$type])) {
      $message = $_SESSION['flash'][$type];
      unset($_SESSION['flash'][$type]);
      return $message;
  }
  return false;
});



// Función global para todos los archivos de Twig, se llama en archivo Index.php
$get_week_days = new \Twig\TwigFunction('get_week_days', function ($value) {
  // Definir los valores de los días
  $dias = [
      "Lunes"     => 1,
      "Martes"    => 2,
      "Miércoles" => 4,
      "Jueves"    => 8,
      "Viernes"   => 16,
      "Sábado"    => 32,
      "Domingo"   => 64
  ];

  // Inicializar un arreglo para almacenar los días utilizados
  $diasUtilizados = [];

  // Iterar sobre los días y verificar si su valor está presente en la suma
  foreach ($dias as $dia => $valorDia) {
      if ($value & $valorDia) {
          $diasUtilizados[] = $dia;
      }
  }

  return implode(", ", $diasUtilizados);
});

// Función global para establecer mensajes flash
$text_to_int = new \Twig\TwigFunction('text_to_int', function ($hora) use ($twig) {
    $hora = str_pad($hora, 4, "0", STR_PAD_LEFT);
    return substr($hora, 0, 2) . ":" . substr($hora, 2);
});

$twig->addFunction($datetimeDiffDays);
$twig->addFunction($datetimeDiffHours);
$twig->addFunction($strpad);
$twig->addFunction($authorized);
$twig->addFunction($foradmin);
$twig->addFunction($getFlashMessage);
$twig->addFunction($get_week_days);
$twig->addFunction($text_to_int);

