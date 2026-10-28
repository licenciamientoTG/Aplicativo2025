<?php

/**
 * Compras de combustible (Abastos).
 *
 * Reemplaza poco a poco el Excel COMPRAS 2026.xlsx: cada vista agrega las
 * columnas del Excel que el sistema ya puede armar o capturar.
 * Diseño: docs/compras_combustible_documentacion.html
 *
 * Rutas: routes/compras.php
 */
class Compras
{
    private const PERM_VER = 32;  // Ver módulo de Abastos (permiso propio pendiente)

    private $twig;
    private $route;

    public function __construct($twig)
    {
        $this->twig  = $twig;
        $this->route = 'views/compras/';
    }

    /** Registro de compras: el renglón por factura del Excel COMPRAS. */
    public function registro(): void
    {
        if (!authorized(self::PERM_VER)) {
            (new Errors())->get404();
            return;
        }
        echo $this->twig->render($this->route . 'registro.html', []);
    }
}
