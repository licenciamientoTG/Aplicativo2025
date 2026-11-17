<?php
/**
 * Formatos de layout de transferencias Santander y Banorte para pagos extraordinarios.
 * Sin acceso a BD: recibe renglones ya resueltos (cuenta de cargo, cuenta destino, monto, concepto)
 * y devuelve el contenido del .txt. Una línea por renglón.
 *
 * Los anchos y campos son los mismos que payment.php::generar_layout_santander_multi_empresa() y
 * ::generar_layout_banorte_multi_empresa() (pago a proveedores), validados contra archivos del banco.
 * Si se cambia un formato aquí, revisar también allá (pendiente: que payment.php use esta clase).
 *
 * Renglón: ['cuenta_cargo', 'cuenta_destino', 'monto', 'beneficiario', 'concepto', 'clave_id'?, 'rfc'?]
 * Pruebas: php tests/LayoutBancosTest.php
 */
class LayoutBancos
{
    /** Correo de notificación que Tesorería usa en el layout de Santander (igual que pago a proveedores). */
    const EMAIL_SANTANDER = 'SUSANA.PANTOJA@TOTALGAS.COM';

    /**
     * Código de banco por los 3 primeros dígitos de la CLABE, para Santander. Los bancos son copia exacta de
     * payment.php::obtener_codigo_banco_desde_clabe() (en uso en producción, incluido 002 => 'BANCO' y
     * 124 => 'DEUTB'); las no bancarias del final son nuevas. A diferencia de payment.php, un prefijo
     * desconocido NO cae a BACOM: el renglón se rechaza.
     */
    const BANCO_POR_CLABE = [
        '002' => 'BANCO', '006' => 'BCEXT', '009' => 'BOBRA', '012' => 'BACOM', '014' => 'BANME', '019' => 'BEJER',
        '021' => 'BITAL', '030' => 'BAJIO', '036' => 'BINBU', '042' => 'MIFEL', '044' => 'COMER', '058' => 'BANRE',
        '059' => 'BINVE', '060' => 'BANSI', '062' => 'BAFIR', '072' => 'BBANO', '106' => 'BAMSA', '108' => 'MUFG',
        '110' => 'CHASE', '112' => 'CMCA', '113' => 'DRESD', '124' => 'DEUTB', '127' => 'BAZTE', '128' => 'BAUTO',
        '129' => 'BARCL', '130' => 'BCOMP', '132' => 'MULTI', '133' => 'PRUDE', '136' => 'REGIO', '137' => 'COPEL',
        '138' => 'AMIGO', '140' => 'FACIL', '141' => 'VOLKS', '143' => 'CONSU', '145' => 'BBASE', '147' => 'AGROF',
        '148' => 'PTODO', '150' => 'INMOB', '151' => 'DONDE', '152' => 'BCREA', '154' => 'COVAL', '155' => 'ICBCH',
        '156' => 'SABAD', '157' => 'SHINH', '158' => 'MISUO', '159' => 'BOCHI', '160' => 'BCOS3', '166' => 'BANSE',
        '168' => 'HIFED',
        // Instituciones no bancarias (SPEI): clave = la de la hoja CLAVES de alta de cuenta / bank_account_banks
        '638' => '90638', '646' => '90646', '653' => '90653', '659' => '90659', '661' => 'ALTER', '722' => 'MERCA',
        '728' => 'SPINB',
    ];

    /** Código de banco de una CLABE; null si el prefijo no se conoce (no se adivina: el banco rechazaría). */
    public static function banco_de_clabe(string $clabe): ?string
    {
        return self::BANCO_POR_CLABE[substr($clabe, 0, 3)] ?? null;
    }

    /** Dígito verificador de una CLABE de 18 dígitos. */
    public static function clabe_valida(string $clabe): bool
    {
        if (!preg_match('/^\d{18}$/', $clabe)) {
            return false;
        }
        $w = [3, 7, 1];
        $s = 0;
        for ($i = 0; $i < 17; $i++) {
            $s += ((int) $clabe[$i] * $w[$i % 3]) % 10;
        }
        return (10 - $s % 10) % 10 === (int) $clabe[17];
    }

    /** Cuenta Banorte de 10 dígitos; si viene CLABE Banorte (072...) se extrae (posiciones 8-17). */
    public static function cuenta_banorte(string $cuenta): string
    {
        $cuenta = trim($cuenta);
        if (strlen($cuenta) === 18 && substr($cuenta, 0, 3) === '072') {
            return substr($cuenta, 7, 10);
        }
        return $cuenta;
    }

    /**
     * Mayúsculas ASCII, solo letras/dígitos/espacios, recortado. Los acentos se quitan con tabla explícita:
     * strtoupper + iconv TRANSLIT (lo que hace payment.php) pierde la letra ("ÑANDÚ" => "NAND").
     */
    public static function limpiar(string $texto, int $max): string
    {
        $texto = strtr(mb_strtoupper($texto, 'UTF-8'), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
        ]);
        $texto = preg_replace('/[^A-Z0-9 ]/', '', $texto);
        $texto = preg_replace('/\s+/', ' ', trim($texto));
        return substr($texto, 0, $max);
    }

    /**
     * Motivo por el que un renglón NO cabe en el layout del banco, o null si está bien.
     * Santander: cargo de 11 dígitos y destino CLABE de 18 con banco conocido.
     * Banorte: cargo de 10 (o CLABE 072) y destino CLABE de 18 o cuenta Banorte de 10.
     */
    public static function problema(string $banco, array $r): ?string
    {
        $cargo   = trim((string) ($r['cuenta_cargo'] ?? ''));
        $destino = trim((string) ($r['cuenta_destino'] ?? ''));
        if ((float) ($r['monto'] ?? 0) <= 0) {
            return 'monto en cero';
        }
        if ($banco === 'SANTANDER') {
            if (!preg_match('/^\d{11}$/', $cargo)) {
                return 'la empresa no tiene cuenta Santander propia de 11 dígitos';
            }
            if (!self::clabe_valida($destino)) {
                return 'la cuenta destino no es una CLABE válida de 18 dígitos';
            }
            if (self::banco_de_clabe($destino) === null) {
                return 'banco de la CLABE ' . substr($destino, 0, 3) . ' desconocido para el layout';
            }
            return null;
        }
        if ($banco === 'BANORTE') {
            if (!preg_match('/^\d{10}$/', self::cuenta_banorte($cargo))) {
                return 'la empresa no tiene cuenta Banorte propia de 10 dígitos';
            }
            $esClabe = strlen($destino) === 18;
            if ($esClabe ? !self::clabe_valida($destino) : !preg_match('/^\d{10}$/', $destino)) {
                return 'la cuenta destino debe ser CLABE de 18 dígitos o cuenta Banorte de 10';
            }
            return null;
        }
        return 'banco no soportado';
    }

    /**
     * Santander (posicional). Anchos: nombre beneficiario 40, importe = 18 díg. en centavos + plaza "901",
     * concepto 40, correo 40. El nombre va pegado a "1234" y el concepto pegado a "00 00".
     */
    public static function santander(array $renglones, string $email = self::EMAIL_SANTANDER): string
    {
        $lineas = [];
        foreach ($renglones as $r) {
            $centavos = intval(round(((float) $r['monto']) * 100));
            $lineas[] = sprintf(
                "LTX05 %-11s       %-18s %-5s%-40s1234%s  %-40s00 00  %-40s",
                trim((string) $r['cuenta_cargo']),
                trim((string) $r['cuenta_destino']),
                self::banco_de_clabe(trim((string) $r['cuenta_destino'])) ?? '',
                self::limpiar((string) $r['beneficiario'], 40),
                str_pad((string) $centavos, 18, '0', STR_PAD_LEFT) . '901',
                self::limpiar((string) $r['concepto'], 40),
                substr($email, 0, 40)
            );
        }
        // CRLF también al final de la última línea, como lo genera el portal del banco.
        return implode("\r\n", $lineas) . "\r\n";
    }

    /**
     * Banorte (11 campos separados por TAB): OPERACION | CLAVE ID | CUENTA ORIGEN | CUENTA DESTINO | IMPORTE
     * | REFERENCIA | DESCRIPCION | RFC | IVA | FECHA APLICACION | INSTRUCCION DE PAGO.
     * Operación 04 = SPEI a CLABE de otro banco; 02 = cuenta Banorte (10 díg. o extraída de CLABE 072).
     * Importe en pesos con 2 decimales; referencia numérica = año (la larga la rechaza el portal).
     */
    public static function banorte(array $renglones, ?string $fecha = null): string
    {
        $fecha = $fecha ?? date('dmY');
        $lineas = [];
        foreach ($renglones as $r) {
            $destino = trim((string) $r['cuenta_destino']);
            if (strlen($destino) === 18 && substr($destino, 0, 3) !== '072') {
                [$operacion, $abono] = ['04', $destino];
            } else {
                [$operacion, $abono] = ['02', self::cuenta_banorte($destino)];
            }
            $clave = trim((string) ($r['clave_id'] ?? ''));
            $concepto = self::limpiar((string) $r['concepto'], 30);
            $lineas[] = implode("\t", [
                $operacion,
                $clave === '0' ? '' : $clave,
                self::cuenta_banorte((string) $r['cuenta_cargo']),
                $abono,
                number_format((float) $r['monto'], 2, '.', ''),
                date('Y'),
                $concepto,
                self::limpiar((string) ($r['rfc'] ?? ''), 13),
                '0',
                $fecha,
                $concepto,
            ]);
        }
        return implode("\r\n", $lineas);
    }
}
