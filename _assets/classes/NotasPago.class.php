<?php

/**
 * Lógica pura (sin BD) de las notas de cargo que se pagan como fila de pago.
 */
class NotasPago
{
    /**
     * Único emp_cod no nulo de las notas. Devuelve null si ninguna tiene empresa.
     * Lanza InvalidArgumentException si hay más de una empresa distinta.
     * $notas = filas con la llave 'emp_cod'.
     */
    public static function empresaUnica(array $notas): ?int
    {
        $empresas = [];
        foreach ($notas as $nota) {
            $emp = $nota['emp_cod'] ?? null;
            if ($emp === null || $emp === '') {
                continue;
            }
            $empresas[(int)$emp] = true;
        }
        if (count($empresas) > 1) {
            throw new InvalidArgumentException('Las notas de cargo son de empresas distintas: crea un pago por empresa');
        }
        return $empresas ? (int)array_key_first($empresas) : null;
    }
}
