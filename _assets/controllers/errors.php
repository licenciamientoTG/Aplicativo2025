<?php
class Errors{

    public function fail(): void {
        echo 'Error';
    }

    public function get404(): void {
        echo '<pre>';
        var_dump("Ruta no encontrada");
        die();
    }
}