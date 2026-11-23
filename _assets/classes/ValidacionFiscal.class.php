<?php
declare(strict_types=1);

/**
 * Reglas del SAT para RFC y validación de listas de correos (separados por ;).
 *
 * Espejo de _assets/js/validacion_fiscal.js: si cambias una regla aquí,
 * cámbiala allá también (tests/ValidacionFiscalTest.php y
 * tests/validacion_fiscal_test.js usan los mismos casos).
 *
 * Estructura del RFC:
 *   Persona moral  (12): 3 letras + fecha AAMMDD + homoclave de 3
 *   Persona física (13): 4 letras + fecha AAMMDD + homoclave de 3
 * Las letras admiten Ñ y &. El último carácter de la homoclave (dígito
 * verificador) debe ser 0-9 o A; su cálculo (módulo 11) no se verifica.
 */
final class ValidacionFiscal
{
    /** Longitud de la columna Clientes.correo en estaciones y SG12 (varchar(200)). */
    public const CORREO_MAX = 200;

    private const GENERICOS = [
        'XAXX010101000' => 'RFC genérico (público en general)',
        'XEXX010101000' => 'RFC genérico (extranjero)',
    ];
    private const CORREO_REGEX = '/^[A-Za-z0-9._%+\-\']+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}$/';

    /**
     * @return array{nivel:string, mensaje:string, rfc:string}
     *   nivel: 'ok' | 'generico' | 'aviso' (se puede guardar) | 'error' (no se puede guardar)
     *   rfc:   el RFC en mayúsculas y sin espacios en los extremos
     */
    public static function rfc(?string $valor): array
    {
        $original = trim((string)$valor);
        $rfc = mb_strtoupper($original, 'UTF-8');
        $r = fn(string $nivel, string $mensaje) => ['nivel' => $nivel, 'mensaje' => $mensaje, 'rfc' => $rfc];

        if ($rfc === '') {
            return $r('error', 'Sin RFC capturado');
        }
        if (preg_match('/[\s\-]/u', $rfc)) {
            return $r('error', 'Tiene espacios o guiones; el RFC va todo junto');
        }
        if (preg_match_all('/[^A-Z0-9Ñ&]/u', $rfc, $m)) {
            return $r('error', 'Tiene caracteres no permitidos: ' . implode(' ', array_unique($m[0])));
        }
        $len = mb_strlen($rfc, 'UTF-8');
        if ($len !== 12 && $len !== 13) {
            return $r('error', "Tiene {$len} caracteres; debe tener 12 (persona moral) o 13 (persona física)");
        }
        if (isset(self::GENERICOS[$rfc])) {
            return $r('generico', self::GENERICOS[$rfc]);
        }

        $n = $len - 9; // 3 letras (moral) o 4 (física)
        $letras = mb_substr($rfc, 0, $n, 'UTF-8');
        if (!preg_match('/^[A-ZÑ&]+$/u', $letras)) {
            $tipo = $n === 4 ? 'persona física' : 'persona moral';
            return $r('error', "Los primeros {$n} caracteres deben ser letras ({$tipo}); tiene «{$letras}»");
        }

        $fecha = mb_substr($rfc, $n, 6, 'UTF-8');
        if (!preg_match('/^\d{6}$/', $fecha)) {
            return $r('error', "Después de las letras van 6 dígitos de fecha (AAMMDD); tiene «{$fecha}»");
        }
        $errorFecha = self::errorFecha($fecha);
        if ($errorFecha !== null) {
            return $r('error', $errorFecha);
        }

        $homoclave = mb_substr($rfc, $n + 6, 3, 'UTF-8');
        if (!preg_match('/^[A-Z0-9Ñ&]{2}[0-9A]$/u', $homoclave)) {
            $pista = str_ends_with($homoclave, 'O') ? ' (¿una letra O en lugar de cero?)' : '';
            return $r('error', "La homoclave «{$homoclave}» no es válida: debe terminar en un dígito o en A{$pista}");
        }

        if ($original !== $rfc) {
            return $r('aviso', 'Está capturado en minúsculas');
        }
        return $r('ok', '');
    }

    private static function errorFecha(string $aammdd): ?string
    {
        $aa = (int)substr($aammdd, 0, 2);
        $mm = (int)substr($aammdd, 2, 2);
        $dd = (int)substr($aammdd, 4, 2);
        if ($mm < 1 || $mm > 12) {
            return "La fecha «{$aammdd}» (AAMMDD) no existe: el mes " . substr($aammdd, 2, 2) . ' no es válido';
        }
        // Año de 2 dígitos: bisiesto si es múltiplo de 4 (00 se toma como 2000).
        $diasMes = [31, ($aa % 4 === 0) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$mm - 1];
        if ($dd < 1 || $dd > $diasMes) {
            return "La fecha «{$aammdd}» (AAMMDD) no existe: el día " . substr($aammdd, 4, 2) . ' no es válido para ese mes';
        }
        return null;
    }

    /**
     * Valida una lista de correos separados por ; (también acepta , como
     * separador y la corrige). Vacío es válido: no todos los clientes tienen correo.
     *
     * @return array{nivel:string, mensaje:string, normalizado:string, invalidos:string[]}
     *   normalizado: correos en minúsculas, sin duplicados, unidos con ; sin espacios
     */
    public static function correos(?string $valor): array
    {
        $original = trim((string)$valor);
        $partes = array_values(array_filter(array_map('trim', preg_split('/[;,\s]+/u', $original)), 'strlen'));

        $vistos = [];
        $invalidos = [];
        foreach ($partes as $correo) {
            if (!preg_match(self::CORREO_REGEX, $correo)) {
                $invalidos[] = $correo;
            }
            $vistos[mb_strtolower($correo, 'UTF-8')] = true;
        }
        $normalizado = implode(';', array_keys($vistos));
        $r = fn(string $nivel, string $mensaje) => [
            'nivel' => $nivel, 'mensaje' => $mensaje, 'normalizado' => $normalizado, 'invalidos' => $invalidos,
        ];

        if ($invalidos) {
            $lista = implode(', ', array_map(fn($c) => "«{$c}»", $invalidos));
            $plural = count($invalidos) === 1 ? 'Correo no válido' : 'Correos no válidos';
            return $r('error', "{$plural}: {$lista}");
        }
        if (mb_strlen($normalizado, 'UTF-8') > self::CORREO_MAX) {
            return $r('error', 'Los correos suman ' . mb_strlen($normalizado, 'UTF-8') . ' caracteres; el máximo es ' . self::CORREO_MAX);
        }
        // Las mayúsculas se normalizan al guardar pero no ameritan aviso.
        if (mb_strtolower($original, 'UTF-8') !== $normalizado) {
            return $r('aviso', 'Se puede limpiar: separadores con coma, espacios, saltos de línea o correos repetidos');
        }
        return $r('ok', '');
    }
}
