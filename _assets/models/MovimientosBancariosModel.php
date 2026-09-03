<?php

/**
 * Movimientos bancarios del módulo Tesorería (/tesoreria/...).
 * Tabla: TG.dbo.movimientos_bancarios (una fila por movimiento del estado
 * de cuenta; dedup por huella SHA1 con índice UNIQUE).
 * Schema: docs/sql/tesoreria_schema.sql
 * Spec:   docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
 *
 * Un parser por banco, todos estáticos para poder probarlos por CLI:
 *   SANTANDER  TXT de ancho fijo (630 chars/línea) de Enlace Santander: el
 *              diario, el que se sube todos los días
 *   SANTANDER  CSV de "Consulta de movimientos > Chequeras", otra pantalla
 *   (Chequeras) del mismo portal para bajar un periodo ya cerrado.
 *   SANTANDER  CSV de "Movimientos SPEI detallados", tercera pantalla del
 *   (SPEI)     mismo portal: mismos movimientos que Chequeras (misma
 *              descripción carácter por carácter, confirmado contra BD
 *              real) pero con la contraparte completa en columnas propias.
 *              Los tres traen los MISMOS movimientos cuando se traslapan;
 *              se guardan igual, con banco SANTANDER, y la dedup cruzada la
 *              hace la llave natural de llave_natural() (folio de cada
 *              export no es comparable con los otros, se usa cuenta+fecha+
 *              hora+importes+saldo corrido) más la huella (Chequeras y SPEI
 *              comparten el mismo esquema de huella por tener la misma
 *              descripción). upload_movimientos() prueba los tres parsers en
 *              orden hasta que uno reconoce el archivo, sin que el usuario
 *              tenga que elegir cuál de los tres está subiendo.
 *   AFIRME     ".xls" que en realidad es texto separado por tabs
 *   INBURSA    .xlsx real (Estado de Cuenta Individual), un archivo por cuenta
 *   BBVA       ".xls" que en realidad es SpreadsheetML 2003, un archivo por
 *              cuenta y en orden inverso (del más reciente al más viejo)
 *   BANKAOOL   .xlsx real que NO trae la cuenta: se captura al subir
 *   VANTAGE    .xls binario (OLE2/BIFF) de verdad, en dólares, en orden
 *              inverso y con la cuenta enmascarada a 4 dígitos
 *   BANREGIO   CSV real; el más completo: la cabecera trae cuenta, CLABE,
 *              razón social y RFC, y la descripción trae la contraparte
 *   MIFEL      CSV con BOM, en orden inverso y con pie legal después de los
 *              datos; la cabecera declara el saldo final para cuadrar
 *   BANORTE    CSV de Cuentas de Cheques; trae folio consecutivo del banco
 *              y la contraparte dentro de la descripción detallada
 *   BANORTE    TXT de ancho fijo (95 chars) del Estado de Cuenta Electrónico
 *   (EDC)      Especial Diario: las mismas cuentas y movimientos que el CSV,
 *              pero las 19 en un solo archivo. Se guarda igual, con banco
 *              BANORTE, y la dedup contra lo que subió el CSV la hace la
 *              llave natural de llave_natural()
 */
class MovimientosBancariosModel extends Model
{
    /**
     * Parsea el TXT de movimientos de Enlace Santander sin tocar BD
     * (estático para poder probarlo por CLI).
     *
     * Layout por línea (offsets 0-based, ancho fijo 630):
     *   0-15 cuenta · 16-23 fecha MMDDAAAA · 24-27 hora HHMM · 28-31 sucursal
     *   32-35 clave transacción · 36-75 descripción · 76 signo · 77-90 importe
     *   (2 decimales implícitos) · 91-104 saldo · 105-112 referencia
     *   113-202 concepto · 203-242 banco contraparte · 243-262 cuenta contraparte
     *   263-302 nombre contraparte · 383-499 zona RFC/clave de rastreo
     *   500-629 descripción larga
     *
     * @return array ['movimientos' => array[], 'errores' => string[]]
     */
    public static function parse_santander_txt(string $contenido): array
    {
        $movimientos = [];
        $errores     = [];

        foreach (preg_split('/\r\n|\r|\n/', $contenido) as $i => $linea) {
            $num = $i + 1;
            if (trim($linea) === '') continue;
            if (strlen($linea) < 500) {
                $errores[] = "Línea $num: largo inválido (" . strlen($linea) . ' caracteres)';
                continue;
            }

            $mes  = substr($linea, 16, 2);
            $dia  = substr($linea, 18, 2);
            $anio = substr($linea, 20, 4);
            if (!ctype_digit($mes . $dia . $anio) || !checkdate((int)$mes, (int)$dia, (int)$anio)) {
                $errores[] = "Línea $num: fecha inválida (" . substr($linea, 16, 8) . ')';
                continue;
            }

            $signo   = substr($linea, 76, 1);
            $importe = substr($linea, 77, 14);
            $saldo   = substr($linea, 91, 14);
            if (!in_array($signo, ['+', '-']) || !ctype_digit($importe) || !ctype_digit($saldo)) {
                $errores[] = "Línea $num: importe/saldo inválido";
                continue;
            }
            $monto = (float)$importe / 100;

            // RFC y clave de rastreo vienen en posiciones que varían entre
            // abonos y cargos SPEI: se extraen por forma del token.
            $rfc = $rastreo = null;
            $tokens = preg_split('/\s+/', trim(substr($linea, 383, 117))) ?: [];
            foreach ($tokens as $t) {
                if ($rfc === null && preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{0,3}$/u', $t)) {
                    $rfc = $t;
                } elseif (strlen($t) >= 18 && ($rastreo === null || strlen($t) > strlen($rastreo))) {
                    $rastreo = $t;
                }
            }

            // Huella sobre los campos crudos: resubir el mismo archivo (o dos
            // archivos traslapados) produce huellas idénticas → dedup en BD.
            $huella = sha1('SANTANDER|' . substr($linea, 0, 203));

            $movimientos[] = [
                'banco'              => 'SANTANDER',
                'cuenta'             => self::campo($linea, 0, 16),
                'fecha'              => "$anio-$mes-$dia",
                'hora'               => substr($linea, 24, 2) . ':' . substr($linea, 26, 2),
                'sucursal'           => self::campo($linea, 28, 4),
                'clave_trans'        => self::campo($linea, 32, 4),
                'descripcion'        => self::campo($linea, 36, 40),
                'cargo'              => $signo === '-' ? $monto : null,
                'abono'              => $signo === '+' ? $monto : null,
                'saldo'              => (float)$saldo / 100,
                'referencia'         => self::campo($linea, 105, 8),
                'concepto'           => self::campo($linea, 113, 90),
                'banco_contraparte'  => self::campo($linea, 203, 40),
                'cuenta_contraparte' => self::campo($linea, 243, 20),
                'nombre_contraparte' => self::campo($linea, 263, 40),
                'rfc_contraparte'    => $rfc,
                'clave_rastreo'      => $rastreo,
                'descripcion_larga'  => self::campo($linea, 500, 130),
                'huella'             => $huella,
            ];
        }

        // Aviso no bloqueante: la cadena de saldos delata movimientos que el
        // banco omitió del export, sin depender de comparar contra nada más
        // (ver santander_huecos() en el controlador para revisar un rango
        // completo). No detiene la importación: solo se suma a los avisos
        // del modal de resultado.
        $rotas = self::roturas_cadena_saldos($movimientos);
        if ($rotas) {
            $errores[] = 'La cadena de saldos no cierra en ' . count($rotas) . ' de '
                       . (count($movimientos) - 1) . ' movimientos: probablemente faltan movimientos en el archivo';
        }

        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => ['cuadra' => $movimientos ? !$rotas : null],
        ];
    }

    /**
     * Parsea el CSV de "Consulta de movimientos > Chequeras" de Santander
     * (distinto del TXT diario de Enlace: mismo banco, otra pantalla del
     * portal, para descargar un periodo ya cerrado en vez del día a día).
     *
     * Cabecera de 8 líneas antes de los datos:
     *   1 Usuario · 2 Último acceso · 3-4 título de la consulta ·
     *   5 Contrato: NNN RAZÓN SOCIAL · 6 Cuenta: NNN,Total de cargos: ... ·
     *   7 Periodo de: DD/MM/AAAA al DD/MM/AAAA,Total de abonos: ... ·
     *   8 encabezado de columnas
     * Columnas (línea 8): Fecha,Hora,Sucursal,Descripcion,Importe Cargo,
     * Importe Abono,Saldo,Referencia,Concepto,Descripcion Larga.
     *
     * Particularidades:
     *  - Fecha/Sucursal vienen entre apóstrofos y la fecha en DDMMAAAA
     *    (formato Excel-como-texto), a diferencia del MMDDAAAA del TXT.
     *  - Referencia y Concepto NO son comparables con los del TXT diario: son
     *    folios de sistemas distintos para el mismo movimiento (confirmado
     *    contra BD real, ningún substring calza). Por eso NO entran a la
     *    huella ni a la llave natural — ver llave_natural().
     *  - La cuenta no viene por fila: se toma de la línea 6 ("Cuenta: NNN,...").
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_santander_chequeras_csv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        if (count($lineas) < 9 || stripos($lineas[2] ?? '', 'Consulta de movimientos') === false) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout de Consulta de movimientos > Chequeras de Santander'], 'info' => []];
        }

        $cuenta = '';
        if (preg_match('/Cuenta:\s*(\d+)/u', $lineas[5] ?? '', $mc)) $cuenta = $mc[1];

        $enc = str_getcsv($lineas[7] ?? '');
        $enc = array_map(fn($x) => self::limpia(mb_strtoupper((string)$x)), $enc);
        if (($enc[0] ?? '') !== 'FECHA' || !in_array('IMPORTE CARGO', $enc, true)
            || !in_array('IMPORTE ABONO', $enc, true) || !in_array('REFERENCIA', $enc, true)) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout de Consulta de movimientos > Chequeras de Santander'], 'info' => []];
        }

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, implode("\n", array_slice($lineas, 8)));
        rewind($fh);
        $filas = [];
        while (($c = fgetcsv($fh, 0, ',')) !== false) $filas[] = $c;
        fclose($fh);

        $movimientos = [];
        $errores     = [];
        $importe = function ($v) {
            $v = str_replace(['$', ',', ' '], '', trim((string)$v));
            return ($v === '' || $v === '-') ? 0.0 : (float)$v;
        };

        foreach ($filas as $n => $c) {
            $linea = $n + 9;
            if (count($c) < 8) continue;

            $fechaRaw = trim(self::limpia((string)$c[0]), "'");
            if ($fechaRaw === '') continue;

            if (!preg_match('#^(\d{2})(\d{2})(\d{4})$#', $fechaRaw, $mf)
                || !checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = "Línea $linea: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            $hora  = self::limpia((string)($c[1] ?? ''));
            $desc  = self::limpia((string)($c[3] ?? ''));
            $cargo = $importe($c[4] ?? 0);
            $abono = $importe($c[5] ?? 0);
            $saldo = $importe($c[6] ?? 0);
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = "Línea $linea: movimiento sin cargo ni abono ($desc)";
                continue;
            }

            // Referencia/Concepto de este export no son el mismo folio que el
            // TXT diario (ver docblock): se guardan igual como texto libre,
            // pero no entran a huella/llave_natural.
            $movimientos[] = [
                'banco'              => 'SANTANDER',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => $hora !== '' ? $hora : null,
                'sucursal'           => mb_substr(trim(self::limpia((string)($c[2] ?? '')), "'"), 0, 10) ?: null,
                'descripcion'        => mb_substr($desc, 0, 150),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr(self::limpia((string)($c[7] ?? '')), 0, 40),
                'concepto'           => mb_substr(self::limpia((string)($c[8] ?? '')), 0, 120) ?: null,
                'descripcion_larga'  => mb_substr(self::limpia((string)($c[9] ?? '')), 0, 400) ?: null,
                'huella'             => sha1('SANTANDER_CSV|' . implode('|', [
                    $cuenta, $fecha, $hora, $desc,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // Aviso no bloqueante, mismo criterio que parse_santander_txt: la
        // cadena de saldos delata movimientos que el banco omitió del
        // export. Así se detectó el caso real que motivó este parser: 4
        // "DEPOSITO SALVO BUEN COBRO" ausentes del TXT diario rompían la
        // cadena por exactamente su importe.
        $rotas = self::roturas_cadena_saldos($movimientos);
        if ($rotas) {
            $errores[] = 'La cadena de saldos no cierra en ' . count($rotas) . ' de '
                       . (count($movimientos) - 1) . ' movimientos: probablemente faltan movimientos en el archivo';
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'saldo_inicial' => $movimientos
                    ? $movimientos[0]['saldo'] - ($movimientos[0]['abono'] ?? 0) + ($movimientos[0]['cargo'] ?? 0)
                    : null,
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? !$rotas : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el CSV "Movimientos SPEI detallados" de Santander: tercera
     * pantalla del portal con los MISMOS movimientos que el TXT diario y el
     * CSV de Chequeras (mismas fechas/horas/saldos, confirmado contra BD
     * real), pero con la contraparte completa desglosada en columnas propias
     * en vez de embebida en el concepto.
     *
     * Sin cabecera previa (el encabezado de columnas es la línea 1), 21
     * columnas:
     *   Cuenta · Fecha · Hora · Sucursal · Descripcion · Cargo/Abono (+/-) ·
     *   Importe · Saldo · Referencia · Concepto · Banco Participante ·
     *   Clabe Beneficiario · Nombre Beneficiario · Cta Ordenante ·
     *   Nombre Ordenante · Codigo Devolucion · Causa Devolucion ·
     *   RFC Beneficiario · RFC Ordenante · Clave de Rastreo ·
     *   Descripcion Larga
     *
     * Particularidades:
     *  - Cuenta/Fecha/Sucursal vienen entre apóstrofos (Excel-como-texto,
     *    igual que Chequeras) y la fecha en DDMMAAAA.
     *  - Cargo/Abono es una columna de signo aparte ('+'/'-') en vez de dos
     *    columnas de importe: el importe siempre viene positivo.
     *  - Mismo caso que Chequeras: Referencia/Concepto no son comparables
     *    con los del TXT diario, así que no entran a huella ni a llave
     *    natural — ver llave_natural(). La contraparte (banco/cuenta/nombre/
     *    RFC) y la clave de rastreo sí se guardan: información que Chequeras
     *    no trae.
     *  - Cargo puede ser beneficiario o RFC beneficiario según el signo: en
     *    un abono la contraparte es quien envía (columna "Nombre Ordenante"
     *    trae la razón social propia, no la contraparte real — se usa
     *    Nombre/RFC Ordenante solo en abono, Beneficiario solo en cargo).
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_santander_spei_csv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $contenido);
        rewind($fh);
        $filas = [];
        while (($c = fgetcsv($fh, 0, ',')) !== false) $filas[] = $c;
        fclose($fh);

        $enc = array_map(fn($x) => self::limpia(mb_strtoupper(ltrim((string)$x, "'"))), $filas[0] ?? []);
        if (($enc[0] ?? '') !== 'CUENTA' || !in_array('CARGO/ABONO', $enc, true)
            || !in_array('IMPORTE', $enc, true) || !in_array('CLAVE DE RASTREO', $enc, true)) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout de Movimientos SPEI detallados de Santander'], 'info' => []];
        }

        $movimientos = [];
        $errores     = [];
        $dato    = fn($v) => trim(self::limpia((string)$v), "'");
        $importe = function ($v) {
            $v = str_replace(['$', ',', ' '], '', trim((string)$v));
            return ($v === '' || $v === '-') ? 0.0 : (float)$v;
        };

        foreach (array_slice($filas, 1) as $n => $c) {
            $linea = $n + 2;
            if (count($c) < 8) continue;

            $cuenta   = $dato($c[0] ?? '');
            $fechaRaw = $dato($c[1] ?? '');
            if ($cuenta === '' && $fechaRaw === '') continue;

            if (!preg_match('#^(\d{2})(\d{2})(\d{4})$#', $fechaRaw, $mf)
                || !checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = "Línea $linea: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            $hora   = self::limpia((string)($c[2] ?? ''));
            $desc   = self::limpia((string)($c[4] ?? ''));
            $signo  = trim((string)($c[5] ?? ''));
            $monto  = $importe($c[6] ?? 0);
            $saldo  = $importe($c[7] ?? 0);
            if (!in_array($signo, ['+', '-'], true) || $monto == 0.0) {
                $errores[] = "Línea $linea: movimiento sin cargo ni abono ($desc)";
                continue;
            }
            $cargo = $signo === '-' ? $monto : null;
            $abono = $signo === '+' ? $monto : null;

            $movimientos[] = [
                'banco'              => 'SANTANDER',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => $hora !== '' ? $hora : null,
                'sucursal'           => mb_substr($dato($c[3] ?? ''), 0, 10) ?: null,
                'descripcion'        => mb_substr($desc, 0, 150),
                'cargo'              => $cargo,
                'abono'              => $abono,
                'saldo'              => $saldo,
                'referencia'         => mb_substr(self::limpia((string)($c[8] ?? '')), 0, 40),
                'concepto'           => mb_substr(self::limpia((string)($c[9] ?? '')), 0, 120) ?: null,
                'banco_contraparte'  => mb_substr(self::limpia((string)($c[10] ?? '')), 0, 60) ?: null,
                'cuenta_contraparte' => mb_substr($dato($c[11] ?? ''), 0, 30) ?: null,
                // Beneficiario en un cargo (salió de la cuenta), Ordenante en
                // un abono (llegó a la cuenta) — el otro lado del par es la
                // cuenta propia, no la contraparte.
                'nombre_contraparte' => mb_substr(self::limpia((string)($cargo !== null ? ($c[12] ?? '') : ($c[14] ?? ''))), 0, 60) ?: null,
                'rfc_contraparte'    => mb_substr(self::limpia((string)($cargo !== null ? ($c[17] ?? '') : ($c[18] ?? ''))), 0, 15) ?: null,
                'clave_rastreo'      => mb_substr($dato($c[19] ?? ''), 0, 40) ?: null,
                'descripcion_larga'  => mb_substr(self::limpia((string)($c[20] ?? '')), 0, 400) ?: null,
                // Misma huella que Chequeras (SANTANDER_CSV): son la misma
                // fuente lógica (Consulta de movimientos de Santander) en dos
                // layouts; comparten prefijo para que un mismo movimiento
                // exportado por cualquiera de los dos produzca la misma
                // huella y no se dupliquen entre sí, además de la llave
                // natural que ya los cruza contra el TXT.
                'huella'             => sha1('SANTANDER_CSV|' . implode('|', [
                    $cuenta, $fecha, $hora, $desc,
                    sprintf('%.2f', $cargo ?? 0), sprintf('%.2f', $abono ?? 0), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        $rotas = self::roturas_cadena_saldos($movimientos);
        if ($rotas) {
            $errores[] = 'La cadena de saldos no cierra en ' . count($rotas) . ' de '
                       . (count($movimientos) - 1) . ' movimientos: probablemente faltan movimientos en el archivo';
        }

        $cuentas = array_unique(array_column($movimientos, 'cuenta'));
        $fechas  = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => implode(', ', $cuentas),
                'saldo_inicial' => $movimientos
                    ? $movimientos[0]['saldo'] - ($movimientos[0]['abono'] ?? 0) + ($movimientos[0]['cargo'] ?? 0)
                    : null,
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? !$rotas : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el export de movimientos de Afirme: texto con cabecera
     * (Windows-1252), separado por tabs en el ".xls" viejo o por comas en el
     * ".csv" que Afirme empezó a entregar después. Columnas:
     * Concepto | Fecha (DD/MM/AA) | Referencia | Cargo | Abono | Saldo |
     * Cuenta | Código | No. Secuencia.
     *
     * La secuencia es un consecutivo global del export (cruza días) y es el
     * orden real de aplicación al saldo; los movimientos se devuelven
     * ordenados por cuenta+secuencia para que el id de BD conserve ese orden.
     * La huella NO incluye la secuencia: se renumera desde 1 en cada export,
     * y con ella dos exports traslapados duplicarían movimientos.
     * Tampoco incluye la referencia: Afirme la reporta en '0' en el export
     * del día y la corrige a su valor real (folio TPV) en exports
     * posteriores del mismo movimiento — incluirla generó 25 duplicados
     * reales en BD (limpiados 2026-09-01) porque el dedup por huella no
     * detectaba que era el mismo movimiento.
     *
     * @return array ['movimientos' => array[], 'errores' => string[]]
     */
    public static function parse_afirme_tsv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $movimientos = [];
        $errores     = [];
        $headerVisto = false;
        $delim       = "\t";

        foreach (preg_split('/\r\n|\r|\n/', $contenido) as $i => $linea) {
            $num = $i + 1;
            if (trim($linea) === '') continue;

            if (!$headerVisto) {
                if (stripos($linea, "Concepto\tFecha") === 0) {
                    $delim = "\t";
                } elseif (stripos($linea, 'Concepto,Fecha') === 0) {
                    $delim = ',';
                } else {
                    return ['movimientos' => [], 'errores' =>
                        ['El archivo no tiene la cabecera esperada de Afirme (Concepto, Fecha, Referencia...)']];
                }
                $headerVisto = true;
                continue;
            }

            $c = array_map('trim', explode($delim, $linea));
            if (count($c) < 9) {
                $errores[] = "Línea $num: columnas insuficientes (" . count($c) . ' de 9)';
                continue;
            }
            [$concepto, $fechaRaw, $referencia, $cargoRaw, $abonoRaw, $saldoRaw, $cuenta, $codigo, $secuencia] = $c;

            if (!preg_match('#^(\d{2})/(\d{2})/(\d{2,4})$#', $fechaRaw, $f)) {
                $errores[] = "Línea $num: fecha inválida ($fechaRaw)";
                continue;
            }
            $anio = strlen($f[3]) === 2 ? 2000 + (int)$f[3] : (int)$f[3];
            if (!checkdate((int)$f[2], (int)$f[1], $anio)) {
                $errores[] = "Línea $num: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = sprintf('%04d-%s-%s', $anio, $f[2], $f[1]);

            $cargo = (float)str_replace(',', '', $cargoRaw);
            $abono = (float)str_replace(',', '', $abonoRaw);
            $saldo = (float)str_replace(',', '', $saldoRaw);
            if (!is_numeric(str_replace(',', '', $cargoRaw)) || !is_numeric(str_replace(',', '', $abonoRaw))
                || !is_numeric(str_replace(',', '', $saldoRaw))) {
                $errores[] = "Línea $num: importe/saldo inválido";
                continue;
            }

            // Sobre el concepto truncado a 150 (lo que de verdad se guarda en
            // "descripcion"): usar el crudo aquí desalinearía la huella del
            // registro con la de BD para los SPEI, que superan ese límite.
            $huella = sha1('AFIRME|' . implode('|', [
                $cuenta, $fecha, mb_substr($concepto, 0, 150), $codigo,
                sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
            ]));

            // Los movimientos normales caben sin problema en descripcion
            // (NVARCHAR(150)), pero los SPEI traen rastreo/CLABE/RFC pegados
            // al concepto y se van hasta 200+: se truncan para no romper el
            // INSERT y el texto completo va aparte en descripcion_larga.
            $movimientos[] = [
                'banco'              => 'AFIRME',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => $codigo,
                'descripcion'        => mb_substr($concepto, 0, 150),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => $referencia,
                'concepto'           => null,
                'banco_contraparte'  => '',
                'cuenta_contraparte' => '',
                'nombre_contraparte' => '',
                'rfc_contraparte'    => null,
                'clave_rastreo'      => null,
                'descripcion_larga'  => mb_strlen($concepto) > 150 ? mb_substr($concepto, 0, 400) : null,
                'secuencia'          => (int)$secuencia,
                'huella'             => $huella,
            ];
        }

        if (!$headerVisto) {
            $errores[] = 'Archivo vacío o sin cabecera de Afirme';
        }

        // El id de BD debe conservar el orden de aplicación al saldo
        usort($movimientos, fn($a, $b) => [$a['cuenta'], $a['secuencia']] <=> [$b['cuenta'], $b['secuencia']]);

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /**
     * Parsea el "Estado de Cuenta Individual" de Inbursa: un .xlsx real, una
     * hoja, un archivo POR CUENTA (a diferencia de Santander y Afirme, que
     * traen la cuenta en cada renglón, aquí viene en la cabecera).
     *
     *   A3  Razón social: ...            A4  Cuenta: <CLABE 18 dígitos>
     *   A6  Moneda: NACIONAL             A7  Fecha Inicial dd/mm/aaaa  Fecha Fin dd/mm/aaaa
     *   A9  encabezados                  A10..N  movimientos
     *   Columnas: A Fecha · B Referencia · C Ref. Externa · D Referencia Leyenda
     *   E Ref. Numérica · F Movimiento · G Cargo · H Abono · I Saldo
     *   J Ordenante · K Clave de Rastreo
     *
     * Se saltan dos filas que no son movimientos:
     *  - "Totales": son fórmulas (=sum(...)), no valores.
     *  - "SALDO INICIAL": no tiene cargo ni abono, y con archivos de rangos
     *    traslapados entraría con id mayor que los movimientos reales de ese
     *    día, rompiendo el orden (fecha, id) del que depende la cadena de
     *    saldos y, con ella, el saldo final del panel de cuentas. Su valor se
     *    devuelve en 'info' para poder cuadrar contra el archivo.
     *
     * @param string $ruta Ruta al .xlsx (PhpSpreadsheet necesita un archivo)
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_inbursa_xlsx(string $ruta): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);   // sin estilos y sin calcular fórmulas
            $hoja = $reader->load($ruta)->getActiveSheet();
        } catch (Exception $e) {
            return ['movimientos' => [], 'errores' => ['No se pudo leer el archivo como .xlsx: ' . $e->getMessage()], 'info' => []];
        }

        // La cabecera identifica el layout: sin ella no es un estado de cuenta
        // de Inbursa y se aborta antes de interpretar nada.
        if (stripos(self::celda($hoja, 'B1'), 'Estado de Cuenta') === false
            || strcasecmp(self::celda($hoja, 'A9'), 'Fecha') !== 0
            || stripos(self::celda($hoja, 'F9'), 'Movimiento') === false) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del Estado de Cuenta Individual de Inbursa'], 'info' => []];
        }

        if (!preg_match('/Cuenta:\s*(\d+)/', self::celda($hoja, 'A4'), $mc)) {
            return ['movimientos' => [], 'errores' =>
                ['No se encontró el número de cuenta en la cabecera (celda A4)'], 'info' => []];
        }
        $cuenta = $mc[1];

        preg_match('/Razón social:\s*(.+)$/u', self::celda($hoja, 'A3'), $mr);
        preg_match('/Moneda:\s*(.+)$/u',       self::celda($hoja, 'A6'), $mm);

        $movimientos  = [];
        $errores      = [];
        $saldoInicial = null;
        $ultimoSaldo  = null;
        $sumCargos    = 0.0;
        $sumAbonos    = 0.0;

        for ($f = 10; $f <= $hoja->getHighestRow(); $f++) {
            $movimiento = self::celda($hoja, "F$f");
            if ($movimiento === '') continue;
            if (strcasecmp($movimiento, 'Totales') === 0) continue;
            if (strcasecmp($movimiento, 'SALDO INICIAL') === 0) {
                $saldoInicial = self::monto($hoja, "I$f");
                continue;
            }

            $fechaRaw = self::celda($hoja, "A$f");
            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fechaRaw, $mf)
                || !checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = "Fila $f: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            $cargo = self::monto($hoja, "G$f");
            $abono = self::monto($hoja, "H$f");
            $saldo = self::monto($hoja, "I$f");
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = "Fila $f: movimiento sin cargo ni abono ($movimiento)";
                continue;
            }

            $referencia = self::limpia(self::celda($hoja, "B$f"));   // polimórfica: rastreo, RFC+nombre, No. de cheque o folio
            $ordenante  = self::limpia(self::celda($hoja, "J$f"));

            // En las domiciliaciones el Ordenante viene vacío y la referencia
            // trae "RFC   NOMBRE": de ahí sale la contraparte.
            $rfc = null;
            if ($ordenante === '' && $referencia !== '') {
                $tokens = preg_split('/\s+/', $referencia) ?: [];
                if (preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{0,3}$/u', $tokens[0] ?? '')) {
                    $rfc       = array_shift($tokens);
                    $ordenante = implode(' ', $tokens);
                }
            }

            $sumCargos += $cargo;
            $sumAbonos += $abono;
            $ultimoSaldo = $saldo;

            $movimientos[] = [
                'banco'              => 'INBURSA',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => mb_substr(self::celda($hoja, "C$f"), 0, 10) ?: null,
                'descripcion'        => mb_substr($movimiento, 0, 60),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr(self::celda($hoja, "E$f"), 0, 20),
                'concepto'           => mb_substr(self::celda($hoja, "D$f"), 0, 120),
                'banco_contraparte'  => '',
                'cuenta_contraparte' => '',
                'nombre_contraparte' => mb_substr($ordenante, 0, 60),
                'rfc_contraparte'    => $rfc,
                'clave_rastreo'      => mb_substr(self::celda($hoja, "K$f"), 0, 40) ?: null,
                'descripcion_larga'  => mb_substr($referencia, 0, 150),
                // El saldo entra a la huella: cambia tras cada movimiento, así
                // que dos filas del mismo día por el mismo importe no colisionan.
                'huella'             => sha1('INBURSA|' . implode('|', [
                    $cuenta, $fecha, $referencia, self::celda($hoja, "D$f"),
                    self::celda($hoja, "E$f"), $movimiento,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // El archivo trae saldo inicial y saldos corridos: si la cadena no
        // cierra, se leyó mal o el archivo viene incompleto. Es un aviso, no
        // un bloqueo: los movimientos igual se importan.
        $cuadra = null;
        if ($saldoInicial !== null && $ultimoSaldo !== null) {
            $cuadra = abs(($saldoInicial + $sumAbonos - $sumCargos) - $ultimoSaldo) < 0.01;
            if (!$cuadra) {
                $errores[] = sprintf(
                    'La cadena de saldos no cuadra: %s + %s - %s = %s, pero el último saldo es %s',
                    number_format($saldoInicial, 2), number_format($sumAbonos, 2),
                    number_format($sumCargos, 2),
                    number_format($saldoInicial + $sumAbonos - $sumCargos, 2),
                    number_format($ultimoSaldo, 2)
                );
            }
        }

        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'razon_social'  => trim($mr[1] ?? ''),
                'moneda'        => trim($mm[1] ?? ''),
                'saldo_inicial' => $saldoInicial,
                'saldo_final'   => $ultimoSaldo,
                'cargos'        => $sumCargos,
                'abonos'        => $sumAbonos,
                'cuadra'        => $cuadra,
            ],
        ];
    }

    /**
     * Parsea el reporte de movimientos de BBVA: un ".xls" que en realidad es
     * SpreadsheetML 2003 (XML de Excel). Tres hojas, los datos en Hoja1, y
     * un archivo POR CUENTA (la cuenta va en la cabecera, como Inbursa).
     *
     *   A1  Cuenta · B1 <número de cuenta>
     *   A2  Fecha Operación · Concepto · Referencia · Referencia Ampliada ·
     *       Cargo · Abono · Saldo
     *   A3..N  movimientos, sin fila de totales ni de saldo inicial
     *
     * Dos particularidades frente a los otros bancos:
     *
     *  - La fecha es un SERIAL de Excel (46232 = 2026-07-29), no texto.
     *  - El archivo viene del movimiento MÁS RECIENTE al más viejo. Las filas
     *    se invierten antes de devolverlas para que el id de BD quede en el
     *    orden real de aplicación al saldo: get_movimientos ordena por
     *    (fecha, id) y get_saldos_finales toma (fecha DESC, id DESC), así que
     *    conservar el orden del archivo haría que el saldo final del panel
     *    fuera el del primer movimiento del día en vez del último.
     *
     * @param string $ruta Ruta al archivo (PhpSpreadsheet lee desde disco)
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_bbva_xml(string $ruta): array
    {
        try {
            $libro = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xml')->load($ruta);
            $hoja  = $libro->getSheetByName('Hoja1') ?: $libro->getSheet(0);
        } catch (Exception $e) {
            return ['movimientos' => [], 'errores' =>
                ['No se pudo leer el archivo como reporte de BBVA: ' . $e->getMessage()], 'info' => []];
        }

        if (stripos(self::celda($hoja, 'A1'), 'Cuenta') !== 0
            || stripos(self::celda($hoja, 'A2'), 'Fecha') !== 0
            || strcasecmp(self::celda($hoja, 'E2'), 'Cargo') !== 0
            || strcasecmp(self::celda($hoja, 'F2'), 'Abono') !== 0
            || strcasecmp(self::celda($hoja, 'G2'), 'Saldo') !== 0) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del reporte de movimientos de BBVA'], 'info' => []];
        }

        $cuenta = self::celda($hoja, 'B1');
        if ($cuenta === '') {
            return ['movimientos' => [], 'errores' =>
                ['No se encontró el número de cuenta en la cabecera (celda B1)'], 'info' => []];
        }

        $movimientos = [];
        $errores     = [];

        for ($f = 3; $f <= $hoja->getHighestRow(); $f++) {
            $concepto = self::celda($hoja, "B$f");
            $fechaRaw = self::celda($hoja, "A$f");
            if ($concepto === '' && $fechaRaw === '') continue;

            $fecha = self::fecha_bbva($fechaRaw);
            if ($fecha === null) {
                $errores[] = "Fila $f: fecha inválida ($fechaRaw)";
                continue;
            }

            $cargo = self::monto($hoja, "E$f");
            $abono = self::monto($hoja, "F$f");
            $saldo = self::monto($hoja, "G$f");
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = "Fila $f: movimiento sin cargo ni abono ($concepto)";
                continue;
            }

            $referencia = self::limpia(self::celda($hoja, "C$f"));
            $ampliada   = self::limpia(self::celda($hoja, "D$f"));

            $movimientos[] = [
                'banco'              => 'BBVA',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => null,
                'descripcion'        => mb_substr($concepto, 0, 60),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr($referencia, 0, 20),
                'concepto'           => mb_substr($ampliada, 0, 120),
                'banco_contraparte'  => '',
                'cuenta_contraparte' => '',
                'nombre_contraparte' => '',
                'rfc_contraparte'    => null,
                'clave_rastreo'      => null,
                'descripcion_larga'  => null,
                'huella'             => sha1('BBVA|' . implode('|', [
                    $cuenta, $fecha, $concepto, $referencia, $ampliada,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // Del más reciente al más viejo -> orden cronológico
        $movimientos = array_reverse($movimientos);

        // Ya en orden, cada saldo debe salir del anterior. Si la cadena no
        // cierra, el archivo viene incompleto o se leyó mal: se avisa sin
        // bloquear la importación.
        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $esperado = $movimientos[$i - 1]['saldo']
                      + ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs($esperado - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de "
                       . (count($movimientos) - 1) . ' movimientos';
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'razon_social'  => '',
                'moneda'        => '',
                'saldo_inicial' => null,   // BBVA no lo declara
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? ($rotas === 0) : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el export de movimientos de Bankaool (.xlsx real, una hoja,
     * encabezados en la fila 1 y datos desde la 2):
     *
     *   Fecha · Descripción · Referencia · Monto · Saldo · Clave Rastreo ·
     *   Comprobante Electrónico
     *
     * Tres particularidades:
     *
     *  - NO trae el número de cuenta en ningún lado (ni cabecera, ni renglón,
     *    ni nombre de archivo), así que se recibe por parámetro: el modal de
     *    subida lo pide cuando el banco es Bankaool.
     *  - Un solo campo Monto con signo (negativo = cargo, positivo = abono),
     *    en vez de dos columnas.
     *  - La fecha es un serial de Excel CON HORA, así que a diferencia de
     *    Afirme, Inbursa y BBVA aquí sí se llena la columna hora.
     *
     * @param string $ruta   Ruta al .xlsx
     * @param string $cuenta Cuenta a la que pertenece el archivo
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_bankaool_xlsx(string $ruta, string $cuenta = ''): array
    {
        $cuenta = trim($cuenta);
        if ($cuenta === '') {
            return ['movimientos' => [], 'errores' =>
                ['El archivo de Bankaool no incluye la cuenta: hay que capturarla al subirlo'], 'info' => []];
        }

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $hoja = $reader->load($ruta)->getActiveSheet();
        } catch (Exception $e) {
            return ['movimientos' => [], 'errores' =>
                ['No se pudo leer el archivo como .xlsx: ' . $e->getMessage()], 'info' => []];
        }

        if (strcasecmp(self::celda($hoja, 'A1'), 'Fecha') !== 0
            || stripos(self::celda($hoja, 'B1'), 'Descrip') !== 0
            || strcasecmp(self::celda($hoja, 'D1'), 'Monto') !== 0
            || strcasecmp(self::celda($hoja, 'E1'), 'Saldo') !== 0) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del export de movimientos de Bankaool'], 'info' => []];
        }

        $movimientos = [];
        $errores     = [];

        for ($f = 2; $f <= $hoja->getHighestRow(); $f++) {
            $descripcion = self::limpia(self::celda($hoja, "B$f"));
            $fechaRaw    = self::celda($hoja, "A$f");
            if ($descripcion === '' && $fechaRaw === '') continue;

            if (!is_numeric($fechaRaw) || (float)$fechaRaw <= 0) {
                $errores[] = "Fila $f: fecha inválida ($fechaRaw)";
                continue;
            }
            $momento = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$fechaRaw);

            $monto = self::monto($hoja, "D$f");
            if ($monto == 0.0) {
                $errores[] = "Fila $f: movimiento en cero ($descripcion)";
                continue;
            }

            // La descripción trae la contraparte; sin extraerla se perdería,
            // porque Bankaool no manda columnas de contraparte.
            $cuentaContra = preg_match('/Cuenta:\s*(\d{10,18})/', $descripcion, $mc) ? $mc[1] : '';
            $nombreContra = preg_match('/^\w+ POR SPEI\s*-\s*(.+?)\s*-\s*\S+$/u', $descripcion, $mn)
                          ? trim($mn[1]) : '';

            $saldo = self::monto($hoja, "E$f");

            $movimientos[] = [
                'banco'              => 'BANKAOOL',
                'cuenta'             => $cuenta,
                'fecha'              => $momento->format('Y-m-d'),
                'hora'               => $momento->format('H:i'),
                'sucursal'           => null,
                'clave_trans'        => null,
                'descripcion'        => mb_substr($descripcion, 0, 150),
                'cargo'              => $monto < 0 ? abs($monto) : null,
                'abono'              => $monto > 0 ? $monto : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr(self::celda($hoja, "C$f"), 0, 20),
                'concepto'           => null,
                'banco_contraparte'  => '',
                'cuenta_contraparte' => mb_substr($cuentaContra, 0, 30),
                'nombre_contraparte' => mb_substr($nombreContra, 0, 60),
                'rfc_contraparte'    => null,
                'clave_rastreo'      => mb_substr(self::limpia(self::celda($hoja, "F$f")), 0, 40) ?: null,
                'descripcion_larga'  => null,
                'huella'             => sha1('BANKAOOL|' . implode('|', [
                    $cuenta, $fechaRaw, $descripcion, self::celda($hoja, "C$f"),
                    sprintf('%.2f', $monto), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // El archivo viene en orden cronológico; se verifica que cada saldo
        // salga del anterior para detectar un export incompleto.
        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs(($movimientos[$i - 1]['saldo'] + $delta) - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de "
                       . (count($movimientos) - 1) . ' movimientos';
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'razon_social'  => '',
                'moneda'        => '',
                'saldo_inicial' => null,   // Bankaool no lo declara
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? ($rotas === 0) : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el AccountHistory de Vantage Bank: un .xls binario de verdad
     * (OLE2/BIFF), a diferencia de Afirme y BBVA que solo usan esa extensión.
     * Hoja Sheet1, encabezados en la fila 1 y datos desde la 2:
     *
     *   Account Number · Post Date · Check · Description · Debit · Credit ·
     *   Status · Balance
     *
     * Particularidades:
     *
     *  - La cuenta viene ENMASCARADA a 4 dígitos (5577) y se guarda así: el
     *    archivo no da el número completo. El catálogo debe darse de alta con
     *    ese mismo valor para que el saldo agrupe por empresa.
     *  - Viene del movimiento MÁS RECIENTE al más viejo, como BBVA: las filas
     *    se invierten para que el id de BD quede en el orden real de
     *    aplicación al saldo.
     *  - Es un banco de EEUU: los importes son dólares. La moneda no se
     *    guarda aquí, la toma el panel de saldos de la Divisa del catálogo.
     *  - El saldo puede ser negativo (sobregiro).
     *
     * @param string $ruta Ruta al .xls
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_vantage_xls(string $ruta): array
    {
        try {
            $libro = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls')->load($ruta);
            $hoja  = $libro->getSheetByName('Sheet1') ?: $libro->getSheet(0);
        } catch (Exception $e) {
            return ['movimientos' => [], 'errores' =>
                ['No se pudo leer el archivo como .xls de Vantage: ' . $e->getMessage()], 'info' => []];
        }

        if (stripos(self::celda($hoja, 'A1'), 'Account') !== 0
            || stripos(self::celda($hoja, 'B1'), 'Post Date') !== 0
            || strcasecmp(self::celda($hoja, 'E1'), 'Debit')   !== 0
            || strcasecmp(self::celda($hoja, 'F1'), 'Credit')  !== 0
            || strcasecmp(self::celda($hoja, 'H1'), 'Balance') !== 0) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del AccountHistory de Vantage Bank'], 'info' => []];
        }

        $movimientos = [];
        $errores     = [];
        $pendientes  = 0;

        for ($f = 2; $f <= $hoja->getHighestRow(); $f++) {
            $cuenta      = self::celda($hoja, "A$f");
            $descripcion = self::limpia(self::celda($hoja, "D$f"));
            if ($cuenta === '' && $descripcion === '') continue;

            $fechaRaw = self::celda($hoja, "B$f");
            if (!is_numeric($fechaRaw) || (float)$fechaRaw <= 0) {
                $errores[] = "Fila $f: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$fechaRaw)->format('Y-m-d');

            $cargo = self::monto($hoja, "E$f");
            $abono = self::monto($hoja, "F$f");
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = "Fila $f: movimiento sin cargo ni abono ($descripcion)";
                continue;
            }

            $estatus = self::celda($hoja, "G$f");
            if ($estatus !== '' && strcasecmp($estatus, 'Posted') !== 0) $pendientes++;

            // La contraparte solo existe dentro de la descripción de los giros
            $contraparte = preg_match('/^WIRE (?:TRANSFER|FEE)\s+(?:FROM\s+|TO\s+)?(.+?)(?:\s*MENOMXMT|\s+\d)/u',
                                      $descripcion, $mc) ? trim($mc[1]) : '';

            $saldo = self::monto($hoja, "H$f");

            $movimientos[] = [
                'banco'              => 'VANTAGE',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => null,
                'descripcion'        => mb_substr($descripcion, 0, 150),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr(self::celda($hoja, "C$f"), 0, 20),   // No. de cheque
                // El estatus se conserva para poder identificar después los
                // movimientos que se importaron sin confirmar por el banco.
                'concepto'           => mb_substr($estatus, 0, 120),
                'banco_contraparte'  => '',
                'cuenta_contraparte' => '',
                'nombre_contraparte' => mb_substr($contraparte, 0, 60),
                'rfc_contraparte'    => null,
                'clave_rastreo'      => null,
                'descripcion_larga'  => null,
                'huella'             => sha1('VANTAGE|' . implode('|', [
                    $cuenta, $fecha, self::celda($hoja, "C$f"), $descripcion,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // Del más reciente al más viejo -> orden cronológico
        $movimientos = array_reverse($movimientos);

        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs(($movimientos[$i - 1]['saldo'] + $delta) - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de "
                       . (count($movimientos) - 1) . ' movimientos';
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => implode(', ', array_unique(array_column($movimientos, 'cuenta'))),
                'razon_social'  => '',
                'moneda'        => 'DOLAR AMERICANO',
                'saldo_inicial' => null,
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? ($rotas === 0) : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
                'pendientes'    => $pendientes,
            ],
        ];
    }

    /**
     * Parsea el reporte de movimientos de Banregio: un CSV de verdad (UTF-8,
     * separado por comas). Es el archivo más completo de todos los bancos.
     *
     * Cabecera (9 líneas, la primera columna vacía):
     *   CUENTA: 066001530012 · CLABE: 058164660015300120 · razón social ·
     *   RFC · domicilio · Fecha inicio / Fecha fin
     * Luego los encabezados y los movimientos:
     *   Fecha · Descripción · Referencia · Cargo · Abonos · Saldo · Clasificación
     *
     * Se parsea con fgetcsv y no partiendo por comas: la razón social de la
     * cabecera es "DIAZ GAS, S.A. DE C.V." y esa coma interna partiría la
     * línea en tres campos.
     *
     * La descripción de los SPEI trae la contraparte completa, separada por
     * puntos, y de ahí salen cuatro columnas que ningún otro banco llena
     * salvo Santander:
     *   YUWM447 SPEI. BANORTE. 072164006015009472. DIAZ GAS SA DE CV.
     *   8846APR2202607075509271085. 0000007. TRASPASO ENTRE CUENTAS
     *   folio         banco     cuenta CLABE       nombre     clave rastreo
     *
     * La fila "Saldo Inicial" se salta por el mismo motivo que en Inbursa: no
     * es un movimiento y con archivos traslapados rompería el orden del que
     * depende la cadena de saldos.
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_banregio_csv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $contenido);
        rewind($fh);
        $filas = [];
        while (($c = fgetcsv($fh, 0, ',')) !== false) $filas[] = $c;
        fclose($fh);

        // Los encabezados marcan dónde empiezan los datos; lo de arriba es
        // la cabecera con los datos de la cuenta.
        $iEnc = null;
        foreach ($filas as $i => $c) {
            if (strcasecmp(trim((string)($c[0] ?? '')), 'Fecha') === 0 && in_array('Saldo', $c, true)) {
                $iEnc = $i;
                break;
            }
        }
        if ($iEnc === null) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del reporte de movimientos de Banregio'], 'info' => []];
        }

        $cabecera = '';
        foreach (array_slice($filas, 0, $iEnc) as $c) $cabecera .= implode(',', $c) . "\n";

        if (!preg_match('/CUENTA:\s*(\S+)/u', $cabecera, $mc)) {
            return ['movimientos' => [], 'errores' =>
                ['No se encontró el número de cuenta en la cabecera'], 'info' => []];
        }
        $cuenta = $mc[1];
        preg_match('/CLABE:\s*(\S+)/u', $cabecera, $mcl);

        // La razón social va en la línea siguiente a la CLABE. Se localiza por
        // posición y no por "la línea sin etiqueta": la primera de la cabecera
        // es el título "Saldo", que tampoco lleva etiqueta.
        // Sus campos se vuelven a unir con coma porque "DIAZ GAS, S.A. DE C.V."
        // trae una y el CSV la partió.
        $razon = '';
        foreach (array_slice($filas, 0, $iEnc) as $j => $c) {
            $etiqueta = self::limpia((string)($c[1] ?? ''));
            if (stripos($etiqueta, 'CLABE:') === 0 && isset($filas[$j + 1])) {
                $razon = self::limpia(implode(', ', array_slice($filas[$j + 1], 1)));
                break;
            }
        }

        $movimientos  = [];
        $errores      = [];
        $saldoInicial = null;
        $importe = fn($v) => (float)str_replace(['$', ',', ' '], '', trim((string)$v));

        foreach (array_slice($filas, $iEnc + 1) as $n => $c) {
            $linea       = $iEnc + $n + 2;
            $fechaRaw    = trim((string)($c[0] ?? ''));
            $descripcion = self::limpia((string)($c[1] ?? ''));
            if ($fechaRaw === '' && $descripcion === '') continue;

            if (stripos($descripcion, 'Saldo Inicial') !== false) {
                $saldoInicial = $importe($c[5] ?? 0);
                continue;
            }
            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fechaRaw, $mf)
                || !checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = "Línea $linea: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            $cargo = $importe($c[3] ?? 0);
            $abono = $importe($c[4] ?? 0);
            $saldo = $importe($c[5] ?? 0);
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = "Línea $linea: movimiento sin cargo ni abono ($descripcion)";
                continue;
            }

            // El guion bajo es un prefijo del propio archivo, no parte del dato
            $referencia = ltrim(self::limpia((string)($c[2] ?? '')), '_');
            $clasific   = self::limpia((string)($c[6] ?? ''));

            $contraparte = self::contraparte_banregio($descripcion);

            $movimientos[] = [
                'banco'              => 'BANREGIO',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => mb_substr($contraparte['folio'], 0, 10) ?: null,
                'descripcion'        => mb_substr($descripcion, 0, 150),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr($referencia, 0, 40),
                // La Clasificación es la categoría con la que el banco agrupa
                // el movimiento; sirve para conciliar y no cabe en clave_trans.
                'concepto'           => mb_substr($clasific, 0, 120),
                'banco_contraparte'  => mb_substr($contraparte['banco'], 0, 60),
                'cuenta_contraparte' => mb_substr($contraparte['cuenta'], 0, 30),
                'nombre_contraparte' => mb_substr($contraparte['nombre'], 0, 60),
                'rfc_contraparte'    => null,
                'clave_rastreo'      => mb_substr($contraparte['rastreo'], 0, 40) ?: null,
                'descripcion_larga'  => null,
                'huella'             => sha1('BANREGIO|' . implode('|', [
                    $cuenta, $fecha, $descripcion, $referencia,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // El archivo viene en orden cronológico y declara el saldo inicial:
        // se verifica que la cadena cierre para detectar un export incompleto.
        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs(($movimientos[$i - 1]['saldo'] + $delta) - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        $cuadra = null;
        if ($saldoInicial !== null && $movimientos) {
            $delta  = ($movimientos[0]['abono'] ?? 0) - ($movimientos[0]['cargo'] ?? 0);
            if (abs(($saldoInicial + $delta) - $movimientos[0]['saldo']) > 0.011) $rotas++;
            $cuadra = ($rotas === 0);
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de " . count($movimientos) . ' movimientos';
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'clabe'         => $mcl[1] ?? '',
                'razon_social'  => $razon,
                'moneda'        => '',
                'saldo_inicial' => $saldoInicial,
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $cuadra,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el reporte de movimientos de Mifel: un CSV con BOM UTF-8.
     *
     *   1  Mifel Empresas | Reporte de movimientos: Cuenta 01600408972
     *   4  Saldo total (MXN):,$33758.32     (y disponible / retenido / tránsito)
     *   9  Fecha · Descripción · Folio · Referencia · Cargo (MXN) · Abono (MXN)
     *      · Saldo total (MXN) · RFC · IVA(MXN) · Cheque
     *   10..N  movimientos
     *   N+2..  pie legal del banco
     *
     * Particularidades:
     *
     *  - Es el único archivo con contenido DESPUÉS de los datos (tres líneas
     *    de pie legal), así que se corta en la primera fila cuyo primer campo
     *    no sea una fecha.
     *  - Viene del movimiento MÁS RECIENTE al más viejo, como BBVA y Vantage:
     *    las filas se invierten para que el id de BD quede en el orden real de
     *    aplicación al saldo.
     *  - Referencia, RFC, IVA y Cheque vienen con los rellenos "-" y
     *    "No aplica"; se tratan como vacío en vez de guardarlos literalmente.
     *    El identificador real del movimiento es el Folio.
     *  - La cabecera declara el saldo total, que debe coincidir con el del
     *    último movimiento: es una verificación cruzada contra el banco que
     *    ningún otro export ofrece tan directa.
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_mifel_csv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $contenido);
        rewind($fh);
        $filas = [];
        while (($c = fgetcsv($fh, 0, ',')) !== false) $filas[] = $c;
        fclose($fh);

        $iEnc = null;
        foreach ($filas as $i => $c) {
            if (strcasecmp(trim((string)($c[0] ?? '')), 'Fecha') === 0
                && stripos(implode(',', $c), 'Saldo') !== false) {
                $iEnc = $i;
                break;
            }
        }
        if ($iEnc === null) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout del reporte de movimientos de Mifel'], 'info' => []];
        }

        $cabecera = '';
        foreach (array_slice($filas, 0, $iEnc) as $c) $cabecera .= implode(',', $c) . "\n";

        if (!preg_match('/Cuenta\s+(\S+)/u', $cabecera, $mc)) {
            return ['movimientos' => [], 'errores' =>
                ['No se encontró el número de cuenta en la cabecera'], 'info' => []];
        }
        $cuenta = $mc[1];

        $importe    = fn($v) => (float)str_replace(['$', ',', ' '], '', trim((string)$v));
        // "-" y "No aplica" son rellenos del propio reporte, no datos
        $dato       = function ($v) {
            $v = self::limpia((string)$v);
            return ($v === '' || $v === '-' || strcasecmp($v, 'No aplica') === 0) ? '' : $v;
        };
        $saldoDeclarado = preg_match('/Saldo total \(MXN\):,\$?\s*([\d.,]+)/u', $cabecera, $ms)
                        ? $importe($ms[1]) : null;

        $movimientos = [];
        $errores     = [];

        foreach (array_slice($filas, $iEnc + 1) as $n => $c) {
            $fechaRaw = trim((string)($c[0] ?? ''));
            // El pie legal del banco va después de los movimientos: en cuanto
            // una fila no empieza con fecha, se acabaron los datos.
            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fechaRaw, $mf)) break;
            if (!checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = 'Línea ' . ($iEnc + $n + 2) . ": fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            $cargo = $importe($c[4] ?? 0);
            $abono = $importe($c[5] ?? 0);
            $saldo = $importe($c[6] ?? 0);
            $descripcion = self::limpia((string)($c[1] ?? ''));
            if ($cargo == 0.0 && $abono == 0.0) {
                $errores[] = 'Línea ' . ($iEnc + $n + 2) . ": movimiento sin cargo ni abono ($descripcion)";
                continue;
            }

            $folio = $dato($c[2] ?? '');
            $rfc   = $dato($c[7] ?? '');

            $movimientos[] = [
                'banco'              => 'MIFEL',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => null,
                'descripcion'        => mb_substr($descripcion, 0, 150),
                'cargo'              => $cargo > 0 ? $cargo : null,
                'abono'              => $abono > 0 ? $abono : null,
                'saldo'              => $saldo,
                // El Folio es el identificador real; la columna Referencia
                // siempre trae "-".
                'referencia'         => mb_substr($folio, 0, 40),
                'concepto'           => null,
                'banco_contraparte'  => '',
                'cuenta_contraparte' => '',
                'nombre_contraparte' => '',
                'rfc_contraparte'    => $rfc !== '' ? mb_substr($rfc, 0, 15) : null,
                'clave_rastreo'      => null,
                'descripcion_larga'  => null,
                'huella'             => sha1('MIFEL|' . implode('|', [
                    $cuenta, $fecha, $descripcion, $folio,
                    sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
                ])),
            ];
        }

        // Del más reciente al más viejo -> orden cronológico
        $movimientos = array_reverse($movimientos);

        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs(($movimientos[$i - 1]['saldo'] + $delta) - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de "
                       . (count($movimientos) - 1) . ' movimientos';
        }

        // El saldo que declara la cabecera debe ser el del último movimiento
        $saldoFinal = $movimientos ? end($movimientos)['saldo'] : null;
        $coincide   = null;
        if ($saldoDeclarado !== null && $saldoFinal !== null) {
            $coincide = abs($saldoDeclarado - $saldoFinal) < 0.01;
            if (!$coincide) {
                $errores[] = sprintf(
                    'El saldo del último movimiento (%s) no coincide con el saldo total que declara el archivo (%s)',
                    number_format($saldoFinal, 2), number_format($saldoDeclarado, 2));
            }
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => $cuenta,
                'razon_social'  => '',
                'moneda'        => '',
                'saldo_inicial' => null,   // Mifel declara el final, no el inicial
                'saldo_final'   => $saldoFinal,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? ($rotas === 0 && $coincide !== false) : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
            ],
        ];
    }

    /**
     * Parsea el CSV "Cuentas de Cheques" de Banorte: BOM UTF-8, todo
     * entrecomillado, 13 columnas y una sola cuenta por archivo.
     *
     *   CUENTA · FECHA DE OPERACIÓN · FECHA · REFERENCIA · DESCRIPCIÓN ·
     *   COD. TRANSAC · SUCURSAL · DEPÓSITOS · RETIROS · SALDO · MOVIMIENTO ·
     *   DESCRIPCIÓN DETALLADA · CHEQUE
     *
     * Particularidades:
     *
     *  - La cuenta viene con un apóstrofo delante ('0601500947): es el truco
     *    de Excel para forzar texto, no parte del dato.
     *  - MOVIMIENTO es el folio del banco, consecutivo y sin huecos: entra a
     *    la huella y sirve para detectar movimientos faltantes.
     *  - Las dos fechas son la misma en todo el archivo; se usa FECHA.
     *  - DESCRIPCIÓN DETALLADA llega a 304 caracteres y trae la contraparte
     *    de los SPEI, que se extrae a sus columnas. Por eso descripcion_larga
     *    se amplió a 400.
     *
     * Se llama "Cheques Banorte" porque más adelante entran otras cuentas del
     * mismo banco con otro layout.
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_banorte_cheques_csv(string $contenido): array
    {
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $contenido);
        rewind($fh);
        $filas = [];
        while (($c = fgetcsv($fh, 0, ',')) !== false) $filas[] = $c;
        fclose($fh);

        $enc = array_map(fn($x) => self::limpia(mb_strtoupper((string)$x)), $filas[0] ?? []);
        if (($enc[0] ?? '') !== 'CUENTA' || !in_array('DEPÓSITOS', $enc, true)
            || !in_array('RETIROS', $enc, true) || !in_array('MOVIMIENTO', $enc, true)) {
            return ['movimientos' => [], 'errores' =>
                ['El archivo no tiene el layout de Cuentas de Cheques de Banorte'], 'info' => []];
        }

        $movimientos = [];
        $errores     = [];
        $cuentas     = [];
        // '-' es el vacío de este export
        $dato    = function ($v) { $v = self::limpia((string)$v); return $v === '-' ? '' : $v; };
        $importe = function ($v) {
            $v = str_replace(['$', ',', ' '], '', trim((string)$v));
            return ($v === '-' || $v === '') ? 0.0 : (float)$v;
        };

        foreach (array_slice($filas, 1) as $n => $c) {
            $linea = $n + 2;
            if (count($c) < 12) continue;

            $cuenta = ltrim(self::limpia((string)$c[0]), "'");
            $fechaRaw = self::limpia((string)$c[2]);
            if ($cuenta === '' && $fechaRaw === '') continue;

            if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fechaRaw, $mf)
                || !checkdate((int)$mf[2], (int)$mf[1], (int)$mf[3])) {
                $errores[] = "Línea $linea: fecha inválida ($fechaRaw)";
                continue;
            }
            $fecha = "$mf[3]-$mf[2]-$mf[1]";

            // FECHA DE OPERACIÓN (columna B) puede venir distinta de FECHA
            // (columna C, la de aplicación/liquidación): se guarda aparte en
            // vez de descartarla. Si no valida, no bloquea el movimiento.
            $fechaOperacionRaw = self::limpia((string)($c[1] ?? ''));
            $fechaOperacion = null;
            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $fechaOperacionRaw, $mfo)
                && checkdate((int)$mfo[2], (int)$mfo[1], (int)$mfo[3])) {
                $fechaOperacion = "$mfo[3]-$mfo[2]-$mfo[1]";
            }

            $deposito = $importe($c[7] ?? 0);
            $retiro   = $importe($c[8] ?? 0);
            $saldo    = $importe($c[9] ?? 0);
            $desc     = self::limpia((string)($c[4] ?? ''));
            if ($deposito == 0.0 && $retiro == 0.0) {
                $errores[] = "Línea $linea: movimiento sin depósito ni retiro ($desc)";
                continue;
            }

            $detalle = self::limpia((string)($c[11] ?? ''));
            $cp      = self::contraparte_banorte($detalle);
            $folio   = $dato($c[10] ?? '');
            $cuentas[$cuenta] = true;

            $movimientos[] = [
                'banco'              => 'BANORTE',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'fecha_operacion'    => $fechaOperacion,
                'hora'               => $cp['hora'] !== '' ? $cp['hora'] : null,
                'sucursal'           => mb_substr($dato($c[6] ?? ''), 0, 10) ?: null,
                'clave_trans'        => mb_substr($dato($c[5] ?? ''), 0, 10) ?: null,
                'descripcion'        => mb_substr($desc, 0, 150),
                'cargo'              => $retiro   > 0 ? $retiro   : null,
                'abono'              => $deposito > 0 ? $deposito : null,
                'saldo'              => $saldo,
                'referencia'         => mb_substr($dato($c[3] ?? ''), 0, 40),
                'concepto'           => mb_substr($cp['concepto'], 0, 120) ?: null,
                'banco_contraparte'  => mb_substr($cp['banco'], 0, 60),
                'cuenta_contraparte' => mb_substr($cp['cuenta'], 0, 30),
                'nombre_contraparte' => mb_substr($cp['nombre'], 0, 60),
                'rfc_contraparte'    => $cp['rfc'] !== '' ? mb_substr($cp['rfc'], 0, 15) : null,
                'clave_rastreo'      => $cp['rastreo'] !== '' ? mb_substr($cp['rastreo'], 0, 40) : null,
                'descripcion_larga'  => mb_substr($detalle, 0, 400) ?: null,
                // El folio del banco entra a la huella: es único por cuenta y
                // hace imposible que dos movimientos iguales se confundan.
                'huella'             => sha1('BANORTE|' . implode('|', [
                    $cuenta, $fecha, $folio, $desc,
                    sprintf('%.2f', $deposito), sprintf('%.2f', $retiro), sprintf('%.2f', $saldo),
                ])),
                'secuencia'          => is_numeric($folio) ? (int)$folio : null,
            ];
        }

        $rotas = 0;
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            if (abs(($movimientos[$i - 1]['saldo'] + $delta) - $movimientos[$i]['saldo']) > 0.011) $rotas++;
        }
        if ($rotas > 0) {
            $errores[] = "La cadena de saldos no cierra en $rotas de "
                       . (count($movimientos) - 1) . ' movimientos';
        }

        // El folio del banco es consecutivo: un hueco significa que al export
        // le faltan movimientos del rango.
        $folios = array_filter(array_column($movimientos, 'secuencia'), fn($v) => $v !== null);
        $huecos = 0;
        if (count($folios) > 1) {
            $huecos = (max($folios) - min($folios) + 1) - count($folios);
            if ($huecos > 0) {
                $errores[] = "Faltan $huecos movimientos en la numeración del banco (folios "
                           . min($folios) . ' a ' . max($folios) . ')';
            }
        }

        $fechas = array_column($movimientos, 'fecha');
        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'        => implode(', ', array_keys($cuentas)),
                'razon_social'  => '',
                'moneda'        => '',
                // El export no lo declara: se deduce del primer movimiento
                'saldo_inicial' => $movimientos
                    ? $movimientos[0]['saldo'] - ($movimientos[0]['abono'] ?? 0) + ($movimientos[0]['cargo'] ?? 0)
                    : null,
                'saldo_final'   => $movimientos ? end($movimientos)['saldo'] : null,
                'cargos'        => array_sum(array_column($movimientos, 'cargo')),
                'abonos'        => array_sum(array_column($movimientos, 'abono')),
                'cuadra'        => $movimientos ? ($rotas === 0 && $huecos === 0) : null,
                'desde'         => $fechas ? min($fechas) : null,
                'hasta'         => $fechas ? max($fechas) : null,
                'folios'        => $folios ? min($folios) . '-' . max($folios) : '',
            ],
        ];
    }

    /**
     * Parsea el TXT "Estado de Cuenta Electrónico Especial Diario" de Banorte
     * (interconexión bancaria). Es el MISMO movimiento que trae el CSV de
     * Cuentas de Cheques, en otro formato: se guarda igual con banco BANORTE y
     * la dedup cruzada la resuelve llave_natural() en insert_bulk().
     *
     * Ancho fijo de 95 caracteres, multicuenta, con cinco tipos de registro
     * (offsets 0-based; el layout del banco los numera desde 1):
     *
     *   11  abre cuenta   25-34 cuenta · 35-40 fecha inicial AAMMDD · 41-46 fecha
     *                     final · 47 clave saldo (1=deudor) · 48-61 saldo inicial
     *                     (2 decimales implícitos) · 62-64 divisa (484=MXN,
     *                     840=USD) · 66-91 nombre abreviado
     *   22  movimiento    6-9 oficina · 10-15 fecha de operación · 16-21 fecha
     *                     valor · 22-26 UTC · 27 clave (1=cargo, 2=abono) ·
     *                     28-41 importe · 42-51 no. de documento/cheque ·
     *                     52-81 referencia · 82-94 folio del banco
     *   23  complemento   4-79 concepto, hasta 5 por movimiento
     *   33  cierra cuenta 35-39 # abonos · 40-53 importe abonos · 54-58 # cargos ·
     *                     59-72 importe cargos · 73 clave saldo · 74-87 saldo final
     *   88  fin de fichero  20-25 total de registros
     *
     * Tres cosas que el layout del banco no dice y el archivo real sí hace:
     *
     *  - El campo 82-94 que el .doc declara "Libre" trae el folio consecutivo
     *    del banco, el mismo que el CSV publica como MOVIMIENTO.
     *  - El archivo cierra con un byte 0x1A (Ctrl-Z, fin de archivo de DOS) en
     *    su propia línea, que no cuenta como registro para el 88.
     *  - El texto sustituye : - ( ) _ por espacios, así que la contraparte
     *    necesita su propio extractor (contraparte_banorte_txt).
     *
     * El movimiento no trae saldo: se acumula desde el saldo inicial del 11 y
     * tiene que caer en el saldo final que declara el 33 de cada cuenta.
     *
     * @return array ['movimientos' => array[], 'errores' => string[], 'info' => array]
     */
    public static function parse_banorte_edc_txt(string $contenido): array
    {
        $lineas = preg_split('/\r\n|\r|\n/', $contenido);

        $movimientos = [];
        $errores     = [];
        $cuentas     = [];
        $registros   = 0;      // para cuadrar contra el total que declara el 88
        $declarados  = null;
        $abierta     = null;   // cuenta abierta por el último 11
        $ultimo      = -1;     // índice del último 22, al que se le cuelgan sus 23

        // AAMMDD → AAAA-MM-DD
        $fecha = function (string $v): ?string {
            if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m)) return null;
            return checkdate((int)$m[2], (int)$m[3], 2000 + (int)$m[1])
                ? '20' . $m[1] . '-' . $m[2] . '-' . $m[3] : null;
        };
        // 14 dígitos con dos decimales implícitos; la clave 1 es saldo deudor
        $monto = function (string $v, string $clave = '2'): ?float {
            $v = trim($v);
            if ($v === '' || !ctype_digit($v)) return null;
            return ($clave === '1' ? -1 : 1) * ((float)$v) / 100;
        };

        foreach ($lineas as $n => $cruda) {
            $linea = rtrim($cruda, "\r\n");
            // línea vacía o el Ctrl-Z final: no son registros
            if (trim($linea, " \t\x1A\x00") === '') continue;
            $num  = $n + 1;
            $tipo = substr($linea, 0, 2);

            if (!in_array($tipo, ['11', '22', '23', '33', '88'], true)) {
                $errores[] = "Línea $num: tipo de registro desconocido ($tipo)";
                continue;
            }
            $registros++;

            if ($tipo === '11') {
                if ($abierta !== null) {
                    $errores[] = "Línea $num: la cuenta {$abierta['cuenta']} quedó sin su registro de cierre";
                }
                $cuenta = self::campo($linea, 25, 10);
                $saldo  = $monto(substr($linea, 48, 14), substr($linea, 47, 1));
                if ($cuenta === '' || $saldo === null) {
                    $errores[] = "Línea $num: cabecera de cuenta ilegible";
                    $abierta = null;
                    continue;
                }
                $abierta = [
                    'cuenta'  => $cuenta,
                    'nombre'  => self::campo($linea, 66, 26),
                    'divisa'  => self::campo($linea, 62, 3),
                    'inicial' => $saldo,
                    'saldo'   => $saldo,   // saldo corrido, arranca en el inicial
                    'cargos'  => 0.0,
                    'abonos'  => 0.0,
                    'n_cargos' => 0,
                    'n_abonos' => 0,
                ];
                $ultimo = -1;
                continue;
            }

            if ($tipo === '88') {
                $declarados = (int)trim(substr($linea, 20, 6));
                continue;
            }

            if ($abierta === null) {
                $errores[] = "Línea $num: registro $tipo fuera de una cuenta abierta";
                continue;
            }

            if ($tipo === '22') {
                $fv = $fecha(substr($linea, 16, 6));   // fecha valor → columna fecha
                $fo = $fecha(substr($linea, 10, 6));   // fecha de operación
                $clave  = substr($linea, 27, 1);
                $imp    = $monto(substr($linea, 28, 14));
                if ($fv === null || $imp === null || !in_array($clave, ['1', '2'], true)) {
                    $errores[] = "Línea $num: movimiento ilegible (fecha, importe o clave de cargo/abono)";
                    continue;
                }
                $cargo = $clave === '1' ? $imp : null;
                $abono = $clave === '2' ? $imp : null;
                $abierta['saldo'] += ($abono ?? 0) - ($cargo ?? 0);
                $abierta['cargos'] += $cargo ?? 0;
                $abierta['abonos'] += $abono ?? 0;
                $abierta[$cargo !== null ? 'n_cargos' : 'n_abonos']++;

                $folio = ltrim(self::campo($linea, 82, 13), '0');
                $movimientos[] = [
                    'banco'           => 'BANORTE',   // el layout es otro, el banco es el mismo
                    'cuenta'          => $abierta['cuenta'],
                    'divisa'          => $abierta['divisa'],
                    'fecha'           => $fv,
                    'fecha_operacion' => $fo,
                    'sucursal'        => self::campo($linea, 6, 4) ?: null,
                    // Los últimos 3 dígitos del UTC son la clave de transacción
                    // que publica el CSV, ceros incluidos ("000").
                    'clave_trans'     => self::campo($linea, 22, 5) !== ''
                                         ? substr(self::campo($linea, 22, 5), -3) : null,
                    'descripcion'     => mb_substr(self::campo($linea, 52, 30), 0, 150),
                    'documento'       => self::campo($linea, 42, 10),
                    'cargo'           => $cargo,
                    'abono'           => $abono,
                    'saldo'           => round($abierta['saldo'], 2),
                    'secuencia'       => is_numeric($folio) ? (int)$folio : null,
                    'detalle'         => [],
                    'linea'           => $num,
                ];
                $ultimo = count($movimientos) - 1;
                continue;
            }

            if ($tipo === '23') {
                if ($ultimo < 0) {
                    $errores[] = "Línea $num: complemento sin movimiento al que pertenecer";
                    continue;
                }
                $texto = self::campo($linea, 4, 76);
                if ($texto !== '') $movimientos[$ultimo]['detalle'][] = $texto;
                continue;
            }

            // 33: cierra la cuenta y valida lo acumulado contra lo que declara
            $cta = $abierta['cuenta'];
            $nAbonos = (int)trim(substr($linea, 35, 5));
            $nCargos = (int)trim(substr($linea, 54, 5));
            $iAbonos = $monto(substr($linea, 40, 14));
            $iCargos = $monto(substr($linea, 59, 14));
            $final   = $monto(substr($linea, 74, 14), substr($linea, 73, 1));

            if ($final === null) {
                $errores[] = "Línea $num: cierre de la cuenta $cta ilegible";
            } else {
                if ($nAbonos !== $abierta['n_abonos'] || $nCargos !== $abierta['n_cargos']) {
                    $errores[] = "Cuenta $cta: el archivo declara $nAbonos abonos y $nCargos cargos, "
                               . "pero trae {$abierta['n_abonos']} y {$abierta['n_cargos']}";
                }
                if ($iAbonos !== null && abs($iAbonos - $abierta['abonos']) > 0.005) {
                    $errores[] = "Cuenta $cta: la suma de abonos no coincide con la declarada por el banco";
                }
                if ($iCargos !== null && abs($iCargos - $abierta['cargos']) > 0.005) {
                    $errores[] = "Cuenta $cta: la suma de cargos no coincide con la declarada por el banco";
                }
                if (abs($abierta['saldo'] - $final) > 0.005) {
                    $errores[] = "Cuenta $cta: el saldo corrido termina en "
                               . number_format($abierta['saldo'], 2) . ' y el banco declara '
                               . number_format($final, 2);
                }
            }
            $cuentas[$cta] = [
                'nombre'  => $abierta['nombre'],
                'divisa'  => $abierta['divisa'],
                'inicial' => $abierta['inicial'],
                'final'   => $final ?? $abierta['saldo'],
                'cargos'  => $abierta['cargos'],
                'abonos'  => $abierta['abonos'],
            ];
            $abierta = null;
            $ultimo  = -1;
        }

        if ($abierta !== null) {
            $errores[] = "La cuenta {$abierta['cuenta']} quedó sin su registro de cierre";
        }
        if ($declarados === null) {
            $errores[] = 'El archivo no trae el registro de fin de fichero (88): puede estar incompleto';
        } elseif ($declarados !== $registros) {
            $errores[] = "El archivo declara $declarados registros y se leyeron $registros";
        }

        // Lo que depende de los complementos se arma al final, cuando el
        // movimiento ya tiene todos sus 23 pegados.
        foreach ($movimientos as &$m) {
            // El primer complemento arranca con la referencia del banco en su
            // propio hueco ("0000000017     , DE LA CUENTA..."): es la columna
            // REFERENCIA del CSV, que tampoco la deja dentro de la descripción
            // detallada. Se separa igual aquí.
            $ref = '';
            if (isset($m['detalle'][0]) && preg_match('/^(\d{4,20})\s+(.*)$/s', $m['detalle'][0], $mr)) {
                $ref = $mr[1];
                $m['detalle'][0] = $mr[2];
            }
            $detalle = trim(implode(' ', array_filter($m['detalle'], fn($d) => trim($d) !== '')));
            $cp      = self::contraparte_banorte_txt($detalle);
            unset($m['detalle'], $m['linea']);

            // Sin referencia en el complemento, la del banco es el número de
            // documento (los cheques la traen ahí).
            if ($ref === '') $ref = $m['documento'];
            unset($m['documento']);

            $m['hora']               = $cp['hora'] !== '' ? $cp['hora'] : null;
            $m['referencia']         = mb_substr($ref, 0, 40);
            $m['concepto']           = mb_substr($cp['concepto'], 0, 120) ?: null;
            $m['banco_contraparte']  = mb_substr($cp['banco'], 0, 60);
            $m['cuenta_contraparte'] = mb_substr($cp['cuenta'], 0, 30);
            $m['nombre_contraparte'] = mb_substr($cp['nombre'], 0, 60);
            $m['rfc_contraparte']    = $cp['rfc'] !== '' ? mb_substr($cp['rfc'], 0, 15) : null;
            $m['clave_rastreo']      = $cp['rastreo'] !== '' ? mb_substr($cp['rastreo'], 0, 40) : null;
            $m['descripcion_larga']  = mb_substr($detalle, 0, 400) ?: null;
            // La huella es la de este archivo; la dedup contra lo que subió el
            // CSV la hace insert_bulk() con la llave natural.
            $m['huella'] = sha1('BANORTE_TXT|' . implode('|', [
                $m['cuenta'], $m['fecha'], (string)$m['secuencia'],
                sprintf('%.2f', $m['cargo'] ?? 0), sprintf('%.2f', $m['abono'] ?? 0),
            ]));
        }
        unset($m);

        // El TXT puede traer cuentas en pesos y en dólares mezcladas: sumarlas
        // en una sola cifra daría un número que no significa nada, así que el
        // resumen se separa un bloque de totales por cada moneda presente.
        $fechas    = array_column($movimientos, 'fecha');
        $usd       = array_keys(array_filter($cuentas, fn($c) => $c['divisa'] === '840'));
        $porMoneda = [];
        foreach ($cuentas as $c) {
            $mon = $c['divisa'] === '840' ? 'USD' : 'MXN';
            if (!isset($porMoneda[$mon])) {
                $porMoneda[$mon] = ['saldo_inicial' => 0.0, 'saldo_final' => 0.0, 'cargos' => 0.0, 'abonos' => 0.0];
            }
            $porMoneda[$mon]['saldo_inicial'] += $c['inicial'];
            $porMoneda[$mon]['saldo_final']   += $c['final'];
            $porMoneda[$mon]['cargos']        += $c['cargos'];
            $porMoneda[$mon]['abonos']        += $c['abonos'];
        }

        return [
            'movimientos' => $movimientos,
            'errores'     => $errores,
            'info'        => [
                'cuenta'       => count($cuentas) . ' cuenta' . (count($cuentas) === 1 ? '' : 's')
                                  . ' · ' . count($movimientos) . ' movimientos',
                'razon_social' => '',
                'moneda'       => $usd ? 'MXN y USD' : 'MXN',
                'cuadra'       => $movimientos ? empty($errores) : null,
                'desde'        => $fechas ? min($fechas) : null,
                'hasta'        => $fechas ? max($fechas) : null,
                'cuentas_usd'  => $usd,
                'por_moneda'   => $porMoneda,
            ],
        ];
    }

    /**
     * Contraparte del complemento (23) del TXT de Banorte. Es el mismo texto
     * que la DESCRIPCIÓN DETALLADA del CSV pero sin puntuación: el TXT cambia
     * : - ( ) _ por espacios, así que las regex de contraparte_banorte() no
     * enganchan y hacen falta estas, que separan por palabra clave.
     *
     *  enviado:  <ref> =REFERENCIA  CTA/CLABE  <clabe>, BEM SPEI BCO <n>
     *            BENEF <nombre>  DATO NO VERIF POR ESTA INST , <concepto>
     *            CVE RASTREO  <clave> RFC  <rfc> IVA  <n> <BANCO> HORA LIQ  hh mm ss
     *  recibido: SPEI RECIBIDO, BCO <n> <BANCO>  HR LIQ  hh mm ss DEL CLIENTE
     *            <nombre> DE LA CLABE <clabe>  CON RFC <rfc> CONCEPTO  <texto>
     *            REFERENCIA  <n> CVE RAST  <clave>
     *
     * @return array{banco:string,cuenta:string,nombre:string,rfc:string,rastreo:string,concepto:string,hora:string}
     */
    private static function contraparte_banorte_txt(string $detalle): array
    {
        $r = ['banco'=>'', 'cuenta'=>'', 'nombre'=>'', 'rfc'=>'', 'rastreo'=>'',
              'concepto'=>'', 'hora'=>''];
        if (trim($detalle) === '') return $r;

        $tomar = function (string $re) use ($detalle) {
            return preg_match($re, $detalle, $m) ? trim($m[1]) : '';
        };

        $r['cuenta'] = $tomar('#(?:CTA/CLABE|DE LA CLABE|A LA CLABE)\s+(\d{10,18})#u');
        $r['rfc']    = $tomar('#\bRFC\s+([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{0,3})#u');
        // En los recibidos la clave de rastreo lleva espacios dentro
        // ("BE 14807591 260817"), así que corre hasta el RFC o el fin del texto.
        $r['rastreo'] = $tomar('#CVE\s+RAST(?:REO)?\s+(.+?)(?:\s+RFC\s|\s*$)#u');
        // hh mm ss donde el CSV pone hh:mm:ss
        $r['hora'] = preg_match('#H(?:ORA|R)\s+LIQ\s+(\d{2})\s+(\d{2})#u', $detalle, $m)
            ? "$m[1]:$m[2]" : '';

        // Recibido: el concepto va entre CONCEPTO y la referencia o la clave
        $r['concepto'] = $tomar('#\bCONCEPTO\s+(.+?)(?:\s+REFERENCIA\s|\s+CVE\s+RAST|\s*$)#u');
        // Enviado: va suelto entre la leyenda del beneficiario y CVE RASTREO
        if ($r['concepto'] === '') {
            $r['concepto'] = $tomar('#DATO NO VERIF[^,]*,\s*(.+?)\s+CVE\s+RAST#u');
        }

        $r['nombre'] = $tomar('#\bBENEF\s+(.+?)\s+DATO NO VERIF#u');
        if ($r['nombre'] === '') $r['nombre'] = $tomar('#\bBENEF\s+([^,]+)#u');
        if ($r['nombre'] === '') $r['nombre'] = $tomar('#DEL CLIENTE\s+(.+?)\s+DE LA CLABE#u');

        // El banco va justo antes de la hora de liquidación en los dos formatos
        $r['banco'] = $tomar('#(?:IVA\s+[\d.]+|BCO\s+\d+)\s+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ .]{2,29}?)\s+H(?:ORA|R)\s+LIQ#u');

        return $r;
    }

    /**
     * Extrae la contraparte de la DESCRIPCIÓN DETALLADA de Banorte, que usa
     * dos formatos según la dirección del SPEI:
     *
     *  enviado:  =REFERENCIA CTA/CLABE: <clabe>, BEM SPEI, BCO:<n> BENEF:<nombre>
     *            (DATO NO VERIFICADO...), <concepto>, CVE RASTREO: <clave>
     *            RFC: <rfc>, IVA: <n> <BANCO> HORA LIQ: <hh:mm:ss>
     *  recibido: SPEI RECIBIDO, BCO:<n> <BANCO> HR LIQ: <hh:mm:ss>, DEL CLIENTE
     *            <nombre>, DE LA CLABE <clabe> CON RFC <rfc>, CONCEPTO: <texto>,
     *            REFERENCIA: <n> CVE RAST: <clave>
     *
     * Lo que no sea un SPEI (comisiones, pagos, traspasos) no trae nada que
     * extraer y devuelve todo vacío.
     *
     * @return array{banco:string,cuenta:string,nombre:string,rfc:string,rastreo:string,concepto:string,hora:string}
     */
    private static function contraparte_banorte(string $detalle): array
    {
        $r = ['banco'=>'', 'cuenta'=>'', 'nombre'=>'', 'rfc'=>'', 'rastreo'=>'', 'concepto'=>'', 'hora'=>''];
        if ($detalle === '') return $r;

        $tomar = function (string $re) use ($detalle) {
            return preg_match($re, $detalle, $m) ? trim($m[1]) : '';
        };

        // CLABE de la contraparte, en cualquiera de las dos redacciones
        $r['cuenta']  = $tomar('#(?:CTA/CLABE:|DE LA CLABE|A LA CLABE)\s*(\d{10,18})#u');
        $r['rastreo'] = $tomar('#CVE\s*RAST(?:REO)?:?\s*([A-Z0-9]{10,})#u');
        $r['rfc']     = $tomar('#RFC:?\s*([A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{0,3})#u');
        $r['concepto'] = $tomar('#CONCEPTO:\s*([^,]+)#u');
        $r['hora']    = $tomar('#H(?:ORA|R)\s*LIQ:\s*(\d{2}:\d{2})#u');

        // Enviado: el beneficiario va tras BENEF: y termina en el paréntesis
        // de la leyenda "(DATO NO VERIFICADO...)"
        $r['nombre'] = $tomar('#BENEF:\s*([^(,]+)#u');
        if ($r['nombre'] === '') $r['nombre'] = $tomar('#DEL CLIENTE\s+([^,]+)#u');

        // El banco va justo antes de la hora de liquidación en los dos formatos
        $r['banco'] = $tomar('#(?:IVA:\s*[\d.]+|BCO:\s*\d+)\s+([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ .]{2,29}?)\s+H(?:ORA|R)\s*LIQ:#u');

        return $r;
    }

    /**
     * Saca la contraparte de la descripción de Banregio, que en los SPEI trae
     * los datos separados por puntos. En los movimientos que no son SPEI
     * (pagos de crédito, cargos) no hay nada que extraer y se devuelve vacío.
     *
     * @return array{folio:string,banco:string,cuenta:string,nombre:string,rastreo:string}
     */
    private static function contraparte_banregio(string $descripcion): array
    {
        $vacio = ['folio' => '', 'banco' => '', 'cuenta' => '', 'nombre' => '', 'rastreo' => ''];
        if (stripos($descripcion, 'SPEI') === false) return $vacio;

        $p = array_map('trim', explode('.', $descripcion));
        if (count($p) < 4) return $vacio;

        // El primer segmento es "FOLIO SPEI"; el folio es lo que va antes
        $folio = trim(preg_replace('/\s*SPEI\s*$/i', '', $p[0]));

        return [
            'folio'   => preg_match('/^[A-Z0-9]{4,10}$/i', $folio) ? $folio : '',
            'banco'   => $p[1],
            'cuenta'  => ctype_digit($p[2]) ? $p[2] : '',
            'nombre'  => $p[3],
            'rastreo' => isset($p[4]) && preg_match('/^[A-Z0-9]{10,}$/i', $p[4]) ? $p[4] : '',
        ];
    }

    /**
     * Fecha de BBVA: normalmente un serial de Excel (46232), con dd/mm/aaaa
     * como respaldo por si el export cambia a texto.
     */
    private static function fecha_bbva(string $v): ?string
    {
        if (is_numeric($v) && (float)$v > 0) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v)->format('Y-m-d');
        }
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return "$m[3]-$m[2]-$m[1]";
        }
        return null;
    }

    /**
     * Texto de una celda. Las celdas de texto de Inbursa son inlineStr y
     * PhpSpreadsheet puede devolverlas como RichText en vez de string.
     */
    private static function celda($hoja, string $ref): string
    {
        $v = $hoja->getCell($ref)->getValue();
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        // Bankaool rellena las celdas vacías con NBSP (U+00A0), que trim() no
        // quita: sin esto una celda "vacía" se guardaría como un espacio duro
        // en vez de NULL.
        return trim(str_replace("\xC2\xA0", ' ', (string)$v));
    }

    /** Importe de una celda; vacío o no numérico cuenta como 0. */
    private static function monto($hoja, string $ref): float
    {
        $v = str_replace([',', '$', ' '], '', self::celda($hoja, $ref));
        return is_numeric($v) ? (float)$v : 0.0;
    }

    /** Colapsa las corridas de espacios con las que Inbursa rellena las celdas. */
    private static function limpia(string $v): string
    {
        return trim(preg_replace('/\s+/', ' ', $v));
    }

    /**
     * Roturas en la cadena de saldos de una lista de movimientos ya
     * ordenada cronológicamente: cuenta cuántas veces saldo_anterior +
     * abono - cargo no llega al saldo del siguiente movimiento. Cada rotura
     * es indicio de un movimiento que el export omitió entre ambos —así se
     * detectaron los 4 "DEPOSITO SALVO BUEN COBRO" que motivaron este
     * chequeo (ver santander_huecos() en el controlador).
     *
     * @param array[] $movimientos con 'saldo', 'cargo', 'abono'
     * @return array[] una entrada por rotura: ['anterior' => movimiento,
     *   'actual' => movimiento, 'diferencia' => float]
     */
    private static function roturas_cadena_saldos(array $movimientos): array
    {
        $roturas = [];
        for ($i = 1; $i < count($movimientos); $i++) {
            $delta = ($movimientos[$i]['abono'] ?? 0) - ($movimientos[$i]['cargo'] ?? 0);
            $esperado = $movimientos[$i - 1]['saldo'] + $delta;
            $diferencia = $movimientos[$i]['saldo'] - $esperado;
            if (abs($diferencia) > 0.011) {
                $roturas[] = [
                    'anterior'   => $movimientos[$i - 1],
                    'actual'     => $movimientos[$i],
                    'diferencia' => $diferencia,
                ];
            }
        }
        return $roturas;
    }

    /** Extrae un campo de ancho fijo, recortado y normalizado a UTF-8. */
    private static function campo(string $linea, int $ini, int $len): string
    {
        $v = trim(substr($linea, $ini, $len));
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
        return $v;
    }

    /**
     * Segunda llave de dedup, para los bancos que publican el MISMO movimiento
     * en dos archivos con formatos distintos, donde la huella (que se calcula
     * distinto en cada parser) nunca coincide entre ellos:
     *
     *  - BANORTE: el CSV de Cuentas de Cheques y el TXT de interconexión.
     *    El TXT escribe la descripción sin puntuación y no trae saldo, así
     *    que la huella no los reconoce como el mismo movimiento. Lo único
     *    idéntico entre ambos es cuenta + fecha + folio del banco + importes,
     *    y el folio es único por cuenta: esa es la llave.
     *
     *  - SANTANDER: el TXT diario de Enlace y los CSV de "Consulta de
     *    movimientos > Chequeras" y "Movimientos SPEI detallados" (otras dos
     *    pantallas del mismo portal, para periodos ya cerrados). Aquí NO hay
     *    folio comparable: la Referencia de cada export es un folio de
     *    sistema distinto para el mismo movimiento (confirmado contra BD
     *    real: ningún substring calza entre ellos). La llave es cuenta +
     *    fecha + HORA (solo HH, sin minutos) + importes + saldo corrido.
     *
     *    Los minutos se descartan a propósito: un mismo movimiento puede
     *    venir con la hora desfasada un minuto entre dos exports (caso real
     *    2026-09-01, cuenta 65505528588: 12:44 en un CSV vs 12:45 en el TXT
     *    para el mismo cargo/saldo — se habría duplicado sin este ajuste).
     *    cuenta+fecha+cargo+abono+saldo corrido, SIN hora, casi siempre basta
     *    (verificado: solo 2 colisiones en 17,055 movimientos reales de
     *    Santander, ambas de movimientos legítimamente distintos que
     *    coinciden en importe/saldo por casualidad), así que se necesita algo
     *    de hora para separarlos — pero el minuto exacto no es confiable
     *    entre exports. HH es el punto medio: separa esas 2 colisiones (verificado
     *    0 colisiones con este criterio) y absorbe el desfase de minuto.
     *
     * Devuelve null —y entonces solo manda la huella, como siempre— cuando el
     * banco no es ninguno de los dos anteriores, o le falta el dato que arma
     * su llave (folio en Banorte, hora/saldo en Santander). Es deliberado: en
     * los bancos cuya secuencia se renumera en cada export (Afirme) o que no
     * la traen, dos movimientos legítimamente iguales del mismo día se
     * perderían si se les aplicara esta lógica.
     */
    private static function llave_natural(
        ?string $banco, ?string $cuenta, ?string $fecha, $secuencia, $cargo, $abono,
        ?string $hora = null, $saldo = null
    ): ?string {
        $banco = strtoupper(trim((string)$banco));

        if ($banco === 'BANORTE') {
            if ($secuencia === null || $secuencia === '' || !is_numeric($secuencia)) return null;
            return implode('|', [
                'BANORTE', trim((string)$cuenta), substr((string)$fecha, 0, 10),
                (int)$secuencia,
                sprintf('%.2f', (float)$cargo),
                sprintf('%.2f', (float)$abono),
            ]);
        }

        if ($banco === 'SANTANDER') {
            if ($hora === null || $hora === '' || $saldo === null || $saldo === '') return null;
            return implode('|', [
                'SANTANDER', trim((string)$cuenta), substr((string)$fecha, 0, 10), substr(trim($hora), 0, 2),
                sprintf('%.2f', (float)$cargo),
                sprintf('%.2f', (float)$abono),
                sprintf('%.2f', (float)$saldo),
            ]);
        }

        return null;
    }

    /**
     * Inserta los movimientos parseados saltando los que ya existen (huella
     * UNIQUE, más la llave natural de llave_natural() para Banorte y
     * Santander, que publican el mismo movimiento en dos formatos de
     * archivo distintos). Todo o nada: un error de BD hace rollback.
     *
     * @return array ['insertados' => int, 'duplicados' => int]
     */
    public function insert_bulk(array $movimientos, string $archivo, ?int $usuario): array
    {
        if (empty($movimientos)) return ['insertados' => 0, 'duplicados' => 0];

        $fechas = array_column($movimientos, 'fecha');
        $existentes = $this->sql->select(
            'SELECT huella, banco, cuenta, CONVERT(varchar(10), fecha, 23) AS fecha,
                    secuencia, cargo, abono, hora, saldo
             FROM [TG].[dbo].[movimientos_bancarios] WHERE fecha BETWEEN ? AND ?;',
            [min($fechas), max($fechas)]
        ) ?: [];
        $vistas = array_fill_keys(array_column($existentes, 'huella'), true);

        // Llaves naturales de lo ya guardado en el rango, calculadas igual que
        // las de los movimientos entrantes.
        $llaves = [];
        foreach ($existentes as $e) {
            $l = self::llave_natural($e['banco'], $e['cuenta'], $e['fecha'],
                                     $e['secuencia'], $e['cargo'], $e['abono'], $e['hora'], $e['saldo']);
            if ($l !== null) $llaves[$l] = true;
        }

        $insertados = $duplicados = 0;
        // cuenta+fecha de Santander tocadas en este insert: al terminar se
        // renumera orden_dia solo para esas (ver recalcula_orden_dia_santander).
        $diasSantander = [];
        $this->sql->beginTransaction();
        try {
            foreach ($movimientos as $m) {
                $llave = self::llave_natural($m['banco'], $m['cuenta'], $m['fecha'],
                                             $m['secuencia'] ?? null, $m['cargo'] ?? 0, $m['abono'] ?? 0,
                                             $m['hora'] ?? null, $m['saldo'] ?? null);
                if (isset($vistas[$m['huella']]) || ($llave !== null && isset($llaves[$llave]))) {
                    $duplicados++;
                    continue;
                }
                $vistas[$m['huella']] = true;   // dedup también dentro del mismo archivo
                if ($llave !== null) $llaves[$llave] = true;
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[movimientos_bancarios]
                     (banco, cuenta, fecha, fecha_operacion, hora, sucursal, clave_trans, descripcion,
                      cargo, abono, saldo, referencia, concepto, banco_contraparte,
                      cuenta_contraparte, nombre_contraparte, rfc_contraparte,
                      clave_rastreo, descripcion_larga, huella, secuencia,
                      archivo_origen, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
                    [
                        $m['banco'], $m['cuenta'], $m['fecha'], $m['fecha_operacion'] ?? null,
                        $m['hora'], $m['sucursal'] ?? null,
                        $m['clave_trans'] ?? null, $m['descripcion'], $m['cargo'], $m['abono'],
                        $m['saldo'], $m['referencia'] ?? null, $m['concepto'] ?? null, $m['banco_contraparte'] ?? null,
                        $m['cuenta_contraparte'] ?? null, $m['nombre_contraparte'] ?? null, $m['rfc_contraparte'] ?? null,
                        $m['clave_rastreo'] ?? null, $m['descripcion_larga'] ?? null, $m['huella'],
                        $m['secuencia'] ?? null, $archivo, $usuario,
                    ]
                );
                $insertados++;
                if (strtoupper(trim((string)$m['banco'])) === 'SANTANDER') {
                    $diasSantander[$m['cuenta'] . '|' . $m['fecha']] = [$m['cuenta'], $m['fecha']];
                }
            }
            foreach ($diasSantander as [$cuenta, $fecha]) {
                self::recalcula_orden_dia_santander($this->sql, $cuenta, $fecha);
            }
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return ['insertados' => $insertados, 'duplicados' => $duplicados];
    }

    /**
     * Renumera orden_dia para una cuenta+fecha de Santander después de
     * insertar movimientos nuevos ahí: los ordena por hora (con el TXT antes
     * que el CSV en empate — el TXT es la fuente diaria de siempre, el CSV
     * solo rellena huecos —, y el id como desempate final) y les asigna
     * 1..n en ese orden.
     *
     * Solo Santander: es el único banco con dos formatos de archivo que
     * pueden traer el mismo día en momentos distintos (ver llave_natural());
     * el resto sigue ordenando por id como siempre. Misma lógica que el
     * backfill de docs/sql/backfill_orden_dia_santander.sql, aplicada aquí
     * solo al día que acaba de cambiar en vez de a toda la tabla.
     */
    private static function recalcula_orden_dia_santander($db, string $cuenta, string $fecha): void
    {
        $db->update(
            "UPDATE m SET orden_dia = d.rn
             FROM [TG].[dbo].[movimientos_bancarios] m
             JOIN (
                 SELECT id, ROW_NUMBER() OVER (ORDER BY
                     hora,
                     CASE WHEN archivo_origen LIKE '%.csv' THEN 1 ELSE 0 END,
                     id
                 ) AS rn
                 FROM [TG].[dbo].[movimientos_bancarios]
                 WHERE banco = 'SANTANDER' AND cuenta = ? AND fecha = ?
             ) d ON d.id = m.id;",
            [$cuenta, $fecha]
        );
    }

    /**
     * Movimientos filtrados. $filtros: desde, hasta (obligatorios),
     * cuenta, tipo ('cargo'|'abono'), q (texto libre).
     *
     * $cuentasPermitidas: si viene no vacío, restringe SIEMPRE a esas cuentas
     * sin importar lo que traiga $filtros['cuenta'] (perfiles con permiso
     * acotado, p.ej. Crédito, ver Tesoreria::CUENTAS_PERFIL_CREDITO).
     */
    public function get_movimientos(array $filtros, ?array $cuentasPermitidas = null): array
    {
        $where  = 'WHERE fecha BETWEEN ? AND ?';
        $params = [$filtros['desde'], $filtros['hasta']];

        if ($cuentasPermitidas !== null) {
            if (!$cuentasPermitidas) return [];
            $ph      = implode(',', array_fill(0, count($cuentasPermitidas), '?'));
            $where  .= " AND cuenta IN ($ph)";
            array_push($params, ...$cuentasPermitidas);
        }
        if (!empty($filtros['cuenta'])) {
            $where   .= ' AND cuenta = ?';
            $params[] = $filtros['cuenta'];
        }
        if (($filtros['tipo'] ?? '') === 'cargo') $where .= ' AND cargo IS NOT NULL';
        if (($filtros['tipo'] ?? '') === 'abono') $where .= ' AND abono IS NOT NULL';
        if (!empty($filtros['q'])) {
            $where .= ' AND (concepto LIKE ? OR nombre_contraparte LIKE ? OR descripcion LIKE ?
                             OR referencia LIKE ? OR cuenta_contraparte LIKE ?)';
            $like   = '%' . $filtros['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        // fecha ASC + id ASC (formato estado de cuenta): días viejos arriba y
        // el saldo final como última fila. El id conserva el orden de línea
        // del archivo, que es el orden real de aplicación al saldo (la hora
        // NO lo es: el banco registra movimientos con hora de operación pero
        // los aplica después; verificado 2026-07-23 contra el TXT del 20/07 —
        // 0 roturas de cadena de saldo en orden de archivo vs 11 por hora).
        //
        // ISNULL(orden_dia, id): en Santander el id deja de ser ese orden
        // real cuando dos archivos de origen distinto traen el mismo día en
        // momentos distintos (el TXT diario y el CSV de Consulta de
        // movimientos > Chequeras) — ver orden_dia en tesoreria_schema.sql y
        // recalcula_orden_dia_santander(). El resto de bancos no tiene
        // orden_dia (NULL) y sigue ordenando por id exactamente como antes.
        //
        // Se proyectan solo las columnas que la tabla muestra: el resultado
        // viaja como JSON al DataTable y un SELECT * arrastraba huella,
        // archivo_origen y created_* sin que nadie los use.
        $query = "SELECT id, banco, cuenta, fecha, fecha_operacion, hora, sucursal, descripcion,
                         cargo, abono, saldo, referencia, concepto, clave_rastreo,
                         descripcion_larga, nombre_contraparte, banco_contraparte,
                         cuenta_contraparte, clave_trans, secuencia, comentario
                  FROM [TG].[dbo].[movimientos_bancarios]
                  $where ORDER BY fecha, ISNULL(orden_dia, id), id;";
        return $this->sql->select($query, $params) ?: [];
    }

    /**
     * Guarda el comentario libre de un movimiento (edición inline con lápiz
     * en la tabla, permiso 93). $cuenta se pasa para que el controlador
     * pueda validarla contra cuentas_permitidas() antes de llamar aquí, pero
     * el UPDATE la vuelve a filtrar por id+cuenta para no depender solo de
     * esa validación previa.
     */
    public function update_comentario(int $id, string $cuenta, string $comentario): bool
    {
        $query = 'UPDATE [TG].[dbo].[movimientos_bancarios] SET comentario = ? WHERE id = ? AND cuenta = ?;';
        $afectadas = $this->sql->updateSafe($query, [$comentario !== '' ? $comentario : null, $id, $cuenta]);
        return $afectadas !== false && $afectadas > 0;
    }

    /**
     * Último saldo conocido de cada cuenta al corte de una fecha (el último
     * movimiento aplicado = fecha DESC, id DESC dentro de cada cuenta).
     * Incluye la fecha de ese movimiento: si es anterior al corte, la cuenta
     * no tuvo movimientos ese día y el saldo viene de un día previo.
     *
     * El día de hoy nunca está cerrado: si el corte pedido es hoy, se recorta
     * al día anterior para todas las cuentas por igual. Con corte a una fecha
     * pasada no cambia nada (ese día ya está cerrado).
     *
     * El LEFT JOIN al catálogo trae la empresa (Descripcion) y la divisa para
     * agrupar los saldos por empresa. Es match exacto de CuentaLocal a
     * propósito: no hay CuentaLocal duplicada en el catálogo, así que el join
     * no puede duplicar filas de saldo ni inflar los totales. Las cuentas sin
     * match salen con descripcion NULL y la vista las manda al grupo
     * "SIN CATÁLOGO" en vez de esconderlas tras un match difuso.
     */
    public function get_saldos_finales(string $hasta): array
    {
        if ($hasta === date('Y-m-d')) {
            $hasta = date('Y-m-d', strtotime($hasta . ' -1 day'));
        }

        $query = "SELECT t.banco, t.cuenta, t.fecha, t.saldo,
                         c.Descripcion AS descripcion,
                         c.Divisa      AS divisa,
                         c.Tipo        AS tipo
                  FROM (
                      SELECT banco, cuenta, fecha, saldo,
                             ROW_NUMBER() OVER (PARTITION BY banco, cuenta
                                                ORDER BY fecha DESC, id DESC) AS rn
                      FROM [TG].[dbo].[movimientos_bancarios]
                      WHERE fecha <= ?
                  ) t
                  LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c
                         ON c.CuentaLocal = t.cuenta
                  WHERE t.rn = 1 ORDER BY t.cuenta;";
        return $this->sql->select($query, [$hasta]) ?: [];
    }

    /**
     * Cuentas distintas presentes en la tabla (para el filtro).
     * $cuentasPermitidas restringe el selector a esas cuentas (perfiles
     * acotados, p.ej. Crédito).
     */
    public function get_cuentas(?array $cuentasPermitidas = null): array
    {
        if ($cuentasPermitidas !== null) {
            if (!$cuentasPermitidas) return [];
            $ph    = implode(',', array_fill(0, count($cuentasPermitidas), '?'));
            $query = "SELECT DISTINCT m.banco, m.cuenta, c.Descripcion AS descripcion
                      FROM [TG].[dbo].[movimientos_bancarios] m
                      LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = m.cuenta
                      WHERE m.cuenta IN ($ph) ORDER BY m.banco, m.cuenta;";
            return $this->sql->select($query, $cuentasPermitidas) ?: [];
        }
        $query = 'SELECT DISTINCT m.banco, m.cuenta, c.Descripcion AS descripcion
                  FROM [TG].[dbo].[movimientos_bancarios] m
                  LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = m.cuenta
                  ORDER BY m.banco, m.cuenta;';
        return $this->sql->select($query) ?: [];
    }

    /**
     * Diagnóstico "¿faltan movimientos de Santander en este rango?": recorre
     * cada cuenta de Santander con movimientos en el rango y busca roturas
     * en su cadena de saldos (mismo chequeo que se hace al parsear un
     * archivo nuevo, aplicado aquí a lo que ya está en BD). Una rotura no
     * prueba que falte un movimiento —el banco puede corregir un saldo por
     * otra razón—, pero en la práctica es la señal más confiable sin tener
     * el archivo del banco a la mano.
     *
     * $cuentasPermitidas restringe a esas cuentas (mismo criterio que
     * get_cuentas), para que un perfil acotado no vea cuentas fuera de lo
     * que ya le corresponde en la tabla de movimientos.
     *
     * @return array[] una entrada por cuenta con roturas: ['cuenta' => ...,
     *   'descripcion' => ..., 'roturas' => array de
     *   ['fecha' => ..., 'hora' => ..., 'descripcion' => ..., 'diferencia' => float]]
     *   Cuentas sin roturas no aparecen en el resultado.
     */
    public function huecos_santander(string $desde, string $hasta, ?array $cuentasPermitidas = null): array
    {
        if ($cuentasPermitidas !== null && !$cuentasPermitidas) return [];

        $where  = "WHERE m.banco = 'SANTANDER' AND m.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($cuentasPermitidas !== null) {
            $ph      = implode(',', array_fill(0, count($cuentasPermitidas), '?'));
            $where  .= " AND m.cuenta IN ($ph)";
            array_push($params, ...$cuentasPermitidas);
        }

        // Mismo orden que get_movimientos(): fecha + orden_dia (o id si no
        // hay orden_dia), que es el orden real de aplicación al saldo.
        $query = "SELECT m.cuenta, m.fecha, m.hora, m.descripcion, m.cargo, m.abono, m.saldo,
                         c.Descripcion AS descripcion_cuenta
                  FROM [TG].[dbo].[movimientos_bancarios] m
                  LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = m.cuenta
                  $where ORDER BY m.cuenta, m.fecha, ISNULL(m.orden_dia, m.id), m.id;";
        $filas = $this->sql->select($query, $params) ?: [];

        $porCuenta = [];
        foreach ($filas as $f) {
            $porCuenta[$f['cuenta']]['descripcion'] ??= trim((string)($f['descripcion_cuenta'] ?? ''));
            $porCuenta[$f['cuenta']]['movimientos'][] = $f;
        }

        $resultado = [];
        foreach ($porCuenta as $cuenta => $datos) {
            $roturas = self::roturas_cadena_saldos($datos['movimientos']);
            if (!$roturas) continue;

            $resultado[] = [
                'cuenta'      => $cuenta,
                'descripcion' => $datos['descripcion'],
                'roturas'     => array_map(fn($r) => [
                    'fecha'       => substr((string)$r['actual']['fecha'], 0, 10),
                    'hora'        => $r['actual']['hora'],
                    'descripcion' => trim((string)$r['actual']['descripcion']),
                    'diferencia'  => round($r['diferencia'], 2),
                ], $roturas),
            ];
        }

        // Más roturas primero: las cuentas que probablemente más necesitan
        // revisión van arriba.
        usort($resultado, fn($a, $b) => count($b['roturas']) <=> count($a['roturas']));

        return $resultado;
    }

    /**
     * Relación cuentas bancarias <-> movimientos: solo las descripciones
     * (razón social/cuenta del catálogo) que tienen al menos un movimiento,
     * con el conteo. Alimenta el filtro "Descripción" del reporte de
     * Contabilidad (/accounting/movimientos_bancos), que consulta por
     * descripción en vez de por cuenta exacta.
     *
     * Las cuentas sin match en el catálogo (Descripcion NULL) se agrupan
     * bajo su propio número de cuenta, para no perderlas del filtro.
     */
    public function get_cuentas_con_movimientos(): array
    {
        $query = "SELECT COALESCE(c.Descripcion, m.cuenta) AS descripcion,
                         COUNT(*) AS n_movimientos
                  FROM [TG].[dbo].[movimientos_bancarios] m
                  LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = m.cuenta
                  GROUP BY COALESCE(c.Descripcion, m.cuenta)
                  ORDER BY descripcion;";
        return $this->sql->select($query) ?: [];
    }

    /**
     * Cuentas (CuentaLocal) que corresponden a una Descripcion del catálogo,
     * para resolver el filtro "Descripción" a la lista de cuentas que hay
     * que consultar en movimientos_bancarios.
     */
    public function get_cuentas_por_descripcion(string $descripcion): array
    {
        $query = "SELECT DISTINCT m.cuenta
                  FROM [TG].[dbo].[movimientos_bancarios] m
                  LEFT JOIN [TG].[dbo].[CatalogosCuentasBancarias] c ON c.CuentaLocal = m.cuenta
                  WHERE COALESCE(c.Descripcion, m.cuenta) = ?;";
        return array_column($this->sql->select($query, [$descripcion]) ?: [], 'cuenta');
    }
}
