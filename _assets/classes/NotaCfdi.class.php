<?php

/**
 * Lógica pura (sin BD) para reconocer notas de crédito/cargo a partir de un CFDI.
 */
class NotaCfdi
{
    const NOTA_CREDITO = 'NOTA_CREDITO';
    const FALSO_FLETE = 'FALSO_FLETE';
    const MORATORIOS = 'MORATORIOS';

    const CLAVE_SERVICIO_ASOCIADO = '80141629';
    const RFC_MCG = 'MME141110IJ9';

    /**
     * Clasifica un CFDI. Devuelve NOTA_CREDITO | FALSO_FLETE | MORATORIOS | null.
     * $descripciones y $claves son arreglos paralelos (clave faltante = '').
     */
    public static function clasificar(string $tipoComprobante, array $descripciones, array $claves): ?string
    {
        $tipo = strtoupper(trim($tipoComprobante));
        if ($tipo === 'E') {
            return self::NOTA_CREDITO;
        }
        if ($tipo !== 'I' || count($descripciones) === 0) {
            return null;
        }

        $descripciones = array_values($descripciones);
        $claves = array_values($claves);

        $todosServicio = true;
        $hayMoratorios = false;
        foreach ($descripciones as $i => $desc) {
            $norm = self::normalizar((string)$desc);
            $clave = trim((string)($claves[$i] ?? ''));

            $esServicio = strpos($norm, 'servicio asociado') !== false
                || $clave === self::CLAVE_SERVICIO_ASOCIADO;
            $esMoratorio = strpos($norm, 'intereses moratorios') !== false;

            if ($esMoratorio) {
                $hayMoratorios = true;
            }
            if (!$esServicio) {
                $todosServicio = false;
            }
        }

        if ($hayMoratorios) {
            // Un concepto distinto de moratorios/servicio asociado (p. ej. combustible) anula la clasificación
            foreach ($descripciones as $i => $desc) {
                $norm = self::normalizar((string)$desc);
                $clave = trim((string)($claves[$i] ?? ''));
                $esServicio = strpos($norm, 'servicio asociado') !== false || $clave === self::CLAVE_SERVICIO_ASOCIADO;
                if (strpos($norm, 'intereses moratorios') === false && !$esServicio) {
                    return null;
                }
            }
            return self::MORATORIOS;
        }

        return $todosServicio ? self::FALSO_FLETE : null;
    }

    public static function noteType(string $clase): string
    {
        return $clase === self::NOTA_CREDITO ? 'CREDIT' : 'DEBIT';
    }

    public static function descripcion(string $clase): string
    {
        switch ($clase) {
            case self::NOTA_CREDITO: return 'NOTA DE CRÉDITO';
            case self::FALSO_FLETE: return 'FALSO FLETE';
            case self::MORATORIOS: return 'INTERESES MORATORIOS';
        }
        return '';
    }

    /** Solo para mostrar; las notas nuevas se identifican por UUID. */
    public static function numeroNota(?string $serie, string $folio): string
    {
        return trim((string)$serie) . trim($folio);
    }

    public static function carpetaPorRfc(string $rfcEmisor): string
    {
        return strtoupper(trim($rfcEmisor)) === self::RFC_MCG ? 'mcg' : 'notas';
    }

    /** Colapsa espacios/saltos de línea, minúsculas y sin acentos. */
    private static function normalizar(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        $texto = mb_strtolower($texto, 'UTF-8');
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
