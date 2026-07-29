<?php

/**
 * Movimientos bancarios del módulo Tesorería (/tesoreria/...).
 * Tabla: TG.dbo.movimientos_bancarios (una fila por movimiento del estado
 * de cuenta; dedup por huella SHA1 con índice UNIQUE).
 * Schema: docs/sql/tesoreria_schema.sql
 * Spec:   docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
 *
 * Un parser por banco, todos estáticos para poder probarlos por CLI:
 *   SANTANDER  TXT de ancho fijo (630 chars/línea) de Enlace Santander
 *   AFIRME     ".xls" que en realidad es texto separado por tabs
 *   INBURSA    .xlsx real (Estado de Cuenta Individual), un archivo por cuenta
 *   BBVA       ".xls" que en realidad es SpreadsheetML 2003, un archivo por
 *              cuenta y en orden inverso (del más reciente al más viejo)
 *   BANKAOOL   .xlsx real que NO trae la cuenta: se captura al subir
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

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /**
     * Parsea el export de movimientos de Afirme: un ".xls" que en realidad es
     * texto separado por tabs (Windows-1252) con cabecera. Columnas:
     * Concepto | Fecha (DD/MM/AA) | Referencia | Cargo | Abono | Saldo |
     * Cuenta | Código | No. Secuencia.
     *
     * La secuencia es un consecutivo global del export (cruza días) y es el
     * orden real de aplicación al saldo; los movimientos se devuelven
     * ordenados por cuenta+secuencia para que el id de BD conserve ese orden.
     * La huella NO incluye la secuencia: se renumera desde 1 en cada export,
     * y con ella dos exports traslapados duplicarían movimientos.
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

        foreach (preg_split('/\r\n|\r|\n/', $contenido) as $i => $linea) {
            $num = $i + 1;
            if (trim($linea) === '') continue;

            if (!$headerVisto) {
                if (stripos($linea, "Concepto\tFecha") === 0) {
                    $headerVisto = true;
                    continue;
                }
                return ['movimientos' => [], 'errores' =>
                    ['El archivo no tiene la cabecera esperada de Afirme (Concepto, Fecha, Referencia...)']];
            }

            $c = array_map('trim', explode("\t", $linea));
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

            $huella = sha1('AFIRME|' . implode('|', [
                $cuenta, $fecha, $concepto, $referencia, $codigo,
                sprintf('%.2f', $cargo), sprintf('%.2f', $abono), sprintf('%.2f', $saldo),
            ]));

            $movimientos[] = [
                'banco'              => 'AFIRME',
                'cuenta'             => $cuenta,
                'fecha'              => $fecha,
                'hora'               => null,
                'sucursal'           => null,
                'clave_trans'        => $codigo,
                'descripcion'        => $concepto,
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
                'descripcion_larga'  => null,
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
     * Inserta los movimientos parseados saltando los que ya existen
     * (huella UNIQUE). Todo o nada: un error de BD hace rollback.
     *
     * @return array ['insertados' => int, 'duplicados' => int]
     */
    public function insert_bulk(array $movimientos, string $archivo, ?int $usuario): array
    {
        if (empty($movimientos)) return ['insertados' => 0, 'duplicados' => 0];

        $fechas = array_column($movimientos, 'fecha');
        $existentes = $this->sql->select(
            'SELECT huella FROM [TG].[dbo].[movimientos_bancarios] WHERE fecha BETWEEN ? AND ?;',
            [min($fechas), max($fechas)]
        ) ?: [];
        $vistas = array_fill_keys(array_column($existentes, 'huella'), true);

        $insertados = $duplicados = 0;
        $this->sql->beginTransaction();
        try {
            foreach ($movimientos as $m) {
                if (isset($vistas[$m['huella']])) {
                    $duplicados++;
                    continue;
                }
                $vistas[$m['huella']] = true;   // dedup también dentro del mismo archivo
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[movimientos_bancarios]
                     (banco, cuenta, fecha, hora, sucursal, clave_trans, descripcion,
                      cargo, abono, saldo, referencia, concepto, banco_contraparte,
                      cuenta_contraparte, nombre_contraparte, rfc_contraparte,
                      clave_rastreo, descripcion_larga, huella, secuencia,
                      archivo_origen, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
                    [
                        $m['banco'], $m['cuenta'], $m['fecha'], $m['hora'], $m['sucursal'],
                        $m['clave_trans'], $m['descripcion'], $m['cargo'], $m['abono'],
                        $m['saldo'], $m['referencia'], $m['concepto'], $m['banco_contraparte'],
                        $m['cuenta_contraparte'], $m['nombre_contraparte'], $m['rfc_contraparte'],
                        $m['clave_rastreo'], $m['descripcion_larga'], $m['huella'],
                        $m['secuencia'] ?? null, $archivo, $usuario,
                    ]
                );
                $insertados++;
            }
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return ['insertados' => $insertados, 'duplicados' => $duplicados];
    }

    /**
     * Movimientos filtrados. $filtros: desde, hasta (obligatorios),
     * cuenta, tipo ('cargo'|'abono'), q (texto libre).
     */
    public function get_movimientos(array $filtros): array
    {
        $where  = 'WHERE fecha BETWEEN ? AND ?';
        $params = [$filtros['desde'], $filtros['hasta']];

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
        // Se proyectan solo las columnas que la tabla muestra: el resultado
        // viaja como JSON al DataTable y un SELECT * arrastraba huella,
        // archivo_origen y created_* sin que nadie los use.
        $query = "SELECT id, banco, cuenta, fecha, hora, sucursal, descripcion,
                         cargo, abono, saldo, referencia, concepto, clave_rastreo,
                         descripcion_larga, nombre_contraparte, banco_contraparte,
                         cuenta_contraparte
                  FROM [TG].[dbo].[movimientos_bancarios]
                  $where ORDER BY fecha, id;";
        return $this->sql->select($query, $params) ?: [];
    }

    /**
     * Último saldo conocido de cada cuenta al corte de una fecha (el último
     * movimiento aplicado = fecha DESC, id DESC dentro de cada cuenta).
     * Incluye la fecha de ese movimiento: si es anterior al corte, la cuenta
     * no tuvo movimientos ese día y el saldo viene de un día previo.
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

    /** Cuentas distintas presentes en la tabla (para el filtro). */
    public function get_cuentas(): array
    {
        $query = 'SELECT DISTINCT banco, cuenta FROM [TG].[dbo].[movimientos_bancarios]
                  ORDER BY banco, cuenta;';
        return $this->sql->select($query) ?: [];
    }
}
