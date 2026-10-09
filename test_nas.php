<?php
header('Content-Type: text/plain; charset=utf-8');

$ruta = '\\\\192.168.0.15\\tableros';

echo "Prueba de acceso al NAS\n\n";

echo "Directorio accesible: ";
echo is_dir($ruta) ? "SI\n" : "NO\n";

echo "Permiso de lectura: ";
echo is_readable($ruta) ? "SI\n" : "NO\n";

echo "Permiso de escritura: ";
echo is_writable($ruta) ? "SI\n" : "NO\n";
