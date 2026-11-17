<?php
declare(strict_types=1);

/**
 * Pruebas de LayoutBancos (layouts Santander/Banorte de pagos extraordinarios):  php tests/LayoutBancosTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/LayoutBancos.class.php';

/** Completa una CLABE de 17 dígitos con su verificador (pesos 3,7,1). */
function con_verificador(string $c17): string
{
    $w = [3, 7, 1];
    $s = 0;
    for ($i = 0; $i < 17; $i++) {
        $s += ((int) $c17[$i] * $w[$i % 3]) % 10;
    }
    return $c17 . ((10 - $s % 10) % 10);
}

$banorteReal = '072164011565717526';           // CLABE Banorte real (ZAIDENERGY, catálogo #3377)
$bbva = con_verificador('01218000123456789');
$mercado = con_verificador('72218000123456789');
$desconocida = con_verificador('99918000123456789');

// --- CLABE ---
check('clabe_valida: CLABE real', LayoutBancos::clabe_valida($banorteReal));
check('clabe_valida: verificador incorrecto', !LayoutBancos::clabe_valida(substr($banorteReal, 0, 17) . '0'));
check('clabe_valida: 17 dígitos', !LayoutBancos::clabe_valida(substr($banorteReal, 0, 17)));
check('banco_de_clabe: 012 => BACOM', LayoutBancos::banco_de_clabe($bbva) === 'BACOM');
check('banco_de_clabe: 722 => MERCA (no bancaria)', LayoutBancos::banco_de_clabe($mercado) === 'MERCA');
check('banco_de_clabe: desconocido => null (sin BACOM por defecto)', LayoutBancos::banco_de_clabe($desconocida) === null);
check('cuenta_banorte: extrae posiciones 8-17 de CLABE 072', LayoutBancos::cuenta_banorte($banorteReal) === '1565717526' || LayoutBancos::cuenta_banorte($banorteReal) === substr($banorteReal, 7, 10));
check('cuenta_banorte: 10 dígitos se queda igual', LayoutBancos::cuenta_banorte('0123456789') === '0123456789');
check('limpiar: acentos y signos', LayoutBancos::limpiar('Gasolinera Ñandú, S.A. de C.V.', 40) === 'GASOLINERA NANDU SA DE CV');
check('limpiar: recorta', strlen(LayoutBancos::limpiar(str_repeat('A', 50), 40)) === 40);

// --- problema() ---
$base = ['cuenta_cargo' => '65501234567', 'cuenta_destino' => $bbva, 'monto' => 1500.5, 'beneficiario' => 'PROV', 'concepto' => 'PE1 PROV'];
check('Santander: renglón correcto', LayoutBancos::problema('SANTANDER', $base) === null);
check('Santander: cargo que no es de 11', LayoutBancos::problema('SANTANDER', ['cuenta_cargo' => '0123456789'] + $base) !== null);
check('Santander: destino de 10 dígitos', LayoutBancos::problema('SANTANDER', ['cuenta_destino' => '0123456789'] + $base) !== null);
check('Santander: banco desconocido', LayoutBancos::problema('SANTANDER', ['cuenta_destino' => $desconocida] + $base) !== null);
check('monto cero', LayoutBancos::problema('SANTANDER', ['monto' => 0] + $base) === 'monto en cero');
$bn = ['cuenta_cargo' => '0123456789'] + $base;
check('Banorte: renglón correcto (CLABE otro banco)', LayoutBancos::problema('BANORTE', $bn) === null);
check('Banorte: cargo como CLABE 072', LayoutBancos::problema('BANORTE', ['cuenta_cargo' => $banorteReal] + $bn) === null);
check('Banorte: destino cuenta de 10', LayoutBancos::problema('BANORTE', ['cuenta_destino' => '1122334455'] + $bn) === null);
check('Banorte: cargo de 11 no sirve', LayoutBancos::problema('BANORTE', $base) !== null);
check('banco no soportado', LayoutBancos::problema('BBVA', $base) === 'banco no soportado');

// --- Santander ---
$txt = LayoutBancos::santander([$base, ['monto' => 10] + $base], 'TESORERIA@EJEMPLO.COM');
$lineas = explode("\r\n", $txt);
check('Santander: CRLF también al final', str_ends_with($txt, "\r\n") && count($lineas) === 3 && $lineas[2] === '');
$l = $lineas[0];
check('Santander: empieza con LTX05 + cargo', str_starts_with($l, 'LTX05 65501234567       '));
check('Santander: destino y banco', substr($l, 24, 18) === $bbva && substr($l, 43, 5) === 'BACOM');
check('Santander: beneficiario a 40', substr($l, 48, 40) === str_pad('PROV', 40));
check('Santander: importe 18 díg. en centavos + 901', substr($l, 88, 4) === '1234' && substr($l, 92, 21) === str_pad('150050', 18, '0', STR_PAD_LEFT) . '901');
check('Santander: concepto y correo', substr($l, 115, 40) === str_pad('PE1 PROV', 40) && substr($l, 155, 7) === '00 00  ' && rtrim(substr($l, 162)) === 'TESORERIA@EJEMPLO.COM');
check('Santander: misma longitud en todas las líneas', strlen($lineas[0]) === strlen($lineas[1]) && strlen($l) === 202);

// --- Banorte ---
$txt = LayoutBancos::banorte([
    $bn + ['clave_id' => '0'],
    ['cuenta_destino' => $banorteReal, 'clave_id' => '45'] + $bn,
], '17112026');
$lineas = explode("\r\n", $txt);
check('Banorte: 2 líneas sin CRLF final', count($lineas) === 2);
$c = explode("\t", $lineas[0]);
check('Banorte: 11 campos', count($c) === 11);
check('Banorte: SPEI otro banco = 04 con CLABE', $c[0] === '04' && $c[3] === $bbva);
check('Banorte: clave 0 va vacía', $c[1] === '');
check('Banorte: importe 2 decimales, referencia año, fecha', $c[4] === '1500.50' && $c[5] === date('Y') && $c[9] === '17112026');
check('Banorte: IVA 0 y descripción = instrucción', $c[8] === '0' && $c[6] === 'PE1 PROV' && $c[10] === 'PE1 PROV');
$c = explode("\t", $lineas[1]);
check('Banorte: CLABE 072 = operación 02 con cuenta de 10', $c[0] === '02' && $c[3] === substr($banorteReal, 7, 10) && $c[1] === '45');

finish();
