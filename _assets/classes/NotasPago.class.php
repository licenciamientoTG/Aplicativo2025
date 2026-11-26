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

    /**
     * Empresa final del pago. Con facturas manda la del pago; sin facturas, la de
     * las notas y, si no tienen, la del filtro. Puede devolver null (el llamador rechaza).
     */
    public static function empresaFinal(bool $hayDocumentos, ?int $empresaPago, ?int $empresaNotas): ?int
    {
        $empresaPago = $empresaPago ?: null;
        if ($hayDocumentos) {
            return $empresaPago;
        }
        return $empresaNotas ?: $empresaPago;
    }

    /**
     * True si las notas son de una empresa y el pago de otra distinta (ambas con valor).
     */
    public static function empresaEnConflicto(?int $empresaPago, ?int $empresaNotas): bool
    {
        return !empty($empresaPago) && !empty($empresaNotas) && $empresaPago !== $empresaNotas;
    }

    /**
     * Id repetido en la lista de notas (devuelve el primero repetido o null).
     */
    public static function idRepetido(array $ids): ?int
    {
        $vistos = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (isset($vistos[$id])) {
                return $id;
            }
            $vistos[$id] = true;
        }
        return null;
    }

    /**
     * La nota de cargo se cobra completa: el saldo debe cubrir su monto.
     */
    public static function saldoCubreNota(float $saldo, float $monto): bool
    {
        return $saldo >= $monto - 0.01;
    }
}
