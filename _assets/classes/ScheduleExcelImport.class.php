<?php
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Lee una hoja del libro "Programa <Mes> <Año>.xlsx" que Abastos llena fuera
 * de la app y la convierte en filas listas para fuel_reception_schedule.
 *
 * La hoja es una cuadrícula de bloques (uno por proveedor/terminal) que se
 * mueven de lugar de un día a otro, así que no se lee por posición fija:
 * cada bloque se ubica por su fila de encabezados (celda "FECHA") y su
 * título es la celda inmediatamente arriba (ej. "MGC (Gaso Mex) 1"). Las
 * columnas del bloque se resuelven por el nombre de su encabezado.
 *
 * parse_sheet() no toca la BD; resolve() cruza contra los catálogos que le
 * pasa el controlador (estaciones, terminales, transportistas).
 */
class ScheduleExcelImport
{
    // Mismos ids que FuelReceptionScheduleModel::IDS_PROVEEDORES_COMBUSTIBLE.
    const PROVEEDORES = [
        'PREMIER' => 138,
        'TESORO'  => 123,
        'MGC'     => 139,
        'ENEREY'  => 150,
        'AEMSA'   => 163,
        'ESSA'    => 151,
        'PETROTAL' => 122,
    ];

    // Texto libre de la hoja -> fragmento del nombre real en Estaciones.
    const ALIAS_ESTACIONES = [
        'g grande'      => 'gemela grande',
        'g chica'       => 'gemela chica',
        'pto de palos'  => 'puerto de palos',
        'hnos escobar'  => 'hermanos escobar',
        'hno escobar'   => 'hermanos escobar',
        'piccahos'      => 'picachos',
        'municipio'     => 'municipio libre',
        'madrid'        => 'miguel de la madrid',
        'independencia' => 'aguascalientes',
        'castano'       => 'el castano',
        'santiago'      => 'santiago troncoso',
        'ejercito'      => 'ejercito nacional',
        'ahumada'       => 'villa ahumada',
        'fuentes'       => 'las fuentes',
        'tecnologico'   => 'tecnologico',
    ];

    public static function norm(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $s = strtr(mb_strtolower($s, 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Nombres de hoja de un libro, sin cargar sus celdas. */
    public static function sheet_names(string $path): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        return $reader->listWorksheetNames($path);
    }

    /**
     * Devuelve ['bloques' => [...], 'filas' => [...], 'errores' => [...]].
     * Cada fila trae los textos crudos (estación, transportista…) todavía sin
     * resolver contra catálogos.
     */
    public static function parse_sheet(string $path, string $sheetName): array
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $ws = $reader->load($path)->getSheetByName($sheetName);
        if (!$ws) {
            throw new RuntimeException('Hoja no encontrada');
        }
        $g = $ws->toArray(null, false, false, false);
        $nRows = count($g);

        $celda = function (int $r, int $c) use ($g) {
            $v = $g[$r][$c] ?? null;
            return is_string($v) ? trim($v) : $v;
        };
        $vacia = fn($v) => $v === null || $v === '';
        $esHeader = fn(int $r, int $c) => is_string($celda($r, $c)) && self::norm($celda($r, $c)) === 'fecha';

        // 1) Ubicar encabezados: celdas "FECHA".
        $headers = [];
        for ($r = 0; $r < $nRows; $r++) {
            foreach (array_keys($g[$r]) as $c) {
                if ($esHeader($r, $c)) {
                    $headers[] = [$r, $c];
                }
            }
        }

        $bloques = $filas = $errores = [];
        foreach ($headers as [$hr, $hc]) {
            $titulo = $celda($hr - 1, $hc);
            $tituloTxt = is_string($titulo) ? $titulo : '';

            // Columnas del bloque: del FECHA hasta el siguiente FECHA de la misma fila.
            $cols = [];
            $maxC = count($g[$hr]);
            $ultimaCol = $hc;
            $ultimoNombre = '';
            for ($cc = $hc; $cc < $maxC; $cc++) {
                if ($cc > $hc && $esHeader($hr, $cc)) break;
                $nombre = $celda($hr, $cc);
                if (is_string($nombre) && $nombre !== '') {
                    $ultimoNombre = self::norm($nombre);
                    $cols[$ultimoNombre] = $cc;
                    $ultimaCol = $cc;
                } elseif ($ultimoNombre !== 'producto') {
                    // Columna en blanco = separador entre bloques, salvo justo
                    // después de PRODUCTO (MGC deja ahí estación y otra celda sin rótulo).
                    break;
                }
            }
            // MGC no rotula la columna de estación: va justo después de PRODUCTO.
            if (!isset($cols['estacion']) && isset($cols['producto'])) {
                $cols['estacion'] = $cols['producto'] + 1;
            }

            $bloque = ['titulo' => $tituloTxt, 'fila_encabezado' => $hr + 1, 'filas' => 0];
            foreach (['litros', 'producto', 'estacion'] as $req) {
                if (!isset($cols[$req])) {
                    $errores[] = "Bloque «{$tituloTxt}» (fila " . ($hr + 1) . "): falta la columna " . strtoupper($req);
                    continue 2;
                }
            }
            if ($tituloTxt === '') {
                $errores[] = 'Fila ' . ($hr + 1) . ': encabezado sin título de proveedor encima';
                continue;
            }

            // 2) Filas de datos hasta el siguiente título o varias filas vacías.
            $enBlanco = 0;
            for ($r = $hr + 1; $r < $nRows; $r++) {
                if (is_string($celda($r, $hc)) && $celda($r, $hc) !== '' && $esHeader($r + 1, $hc)) break; // título del siguiente bloque
                $campos = [];
                $hayAlgo = false;
                for ($cc = $hc; $cc <= $ultimaCol + 1; $cc++) {
                    $campos[$cc] = $celda($r, $cc);
                    if (!$vacia($campos[$cc])) $hayAlgo = true;
                }
                if (!$hayAlgo) {
                    if (++$enBlanco >= 5) break;
                    continue;
                }
                $enBlanco = 0;

                $get = fn(string $k) => isset($cols[$k]) ? ($campos[$cols[$k]] ?? null) : null;
                $litros = $get('litros');
                $producto = $get('producto');
                $estacion = $get('estacion');
                if (!is_numeric($litros) || (float)$litros <= 0 || $vacia($producto) || $vacia($estacion)) {
                    $errores[] = "Hoja fila " . ($r + 1) . " (bloque «{$tituloTxt}»): fila incompleta (litros/producto/estación)";
                    continue;
                }

                $horaRaw = $get('horario');
                $transp = $get('transp');
                $esMgc = isset($cols['destino']) || isset($cols['embarque']);
                // En MGC el rótulo HORARIO trae al transportista (T2/T3), no una hora.
                if ($esMgc && !$vacia($horaRaw) && self::parse_hora($horaRaw) === null && $vacia($transp)) {
                    $transp = $horaRaw;
                    $horaRaw = null;
                }

                $filas[] = [
                    'fila_excel' => $r + 1,
                    'bloque'     => $tituloTxt,
                    'fecha_raw'  => $campos[$hc],
                    'litros'     => (float)$litros,
                    'producto'   => (string)$producto,
                    'estacion'   => (string)$estacion,
                    'hora_raw'   => $horaRaw,
                    'transp'     => $vacia($transp) ? '' : trim((string)$transp),
                    'factura'    => $vacia($get('factura')) ? '' : trim((string)$get('factura')),
                    'prov'       => $vacia($get('prov')) ? '' : trim((string)$get('prov')),
                    'destino'    => $vacia($get('destino')) ? '' : trim((string)$get('destino')),
                    'embarque'   => $vacia($get('embarque')) ? '' : trim((string)$get('embarque')),
                ];
                $bloque['filas']++;
            }
            $bloques[] = $bloque;
        }
        return ['bloques' => $bloques, 'filas' => $filas, 'errores' => $errores];
    }

    public static function parse_fecha($raw): ?string
    {
        if (is_numeric($raw) && (float)$raw > 20000) {
            return Date::excelToDateTimeObject((float)$raw)->format('Y-m-d');
        }
        if (is_string($raw) && preg_match('#^(\d{1,2})/+(\d{1,2})/+(\d{4})$#', trim($raw), $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        return null;
    }

    public static function parse_hora($raw): ?string
    {
        if (is_numeric($raw) && (float)$raw > 0 && (float)$raw < 1) {
            $min = (int)round((float)$raw * 1440);
            return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
        }
        if (is_string($raw) && preg_match('/^(\d{1,2}):(\d{2})\s*([ap])?\.?\s*m?\.?$/i', trim($raw), $m)) {
            $h = (int)$m[1];
            if (!empty($m[3])) {
                $pm = strtolower($m[3]) === 'p';
                if ($pm && $h !== 12) $h += 12;
                if (!$pm && $h === 12) $h = 0;
            }
            return $h < 24 ? sprintf('%02d:%s', $h, $m[2]) : null;
        }
        return null;
    }

    /** Título del bloque -> [supplier_id, nombre de terminal] (null si no se reconoce). */
    public static function proveedor_terminal(string $titulo): array
    {
        $t = self::norm($titulo);
        $supplier = null;
        foreach (self::PROVEEDORES as $clave => $id) {
            if ($clave === 'PETROTAL') continue;
            if (str_contains($t, strtolower($clave))) { $supplier = $id; break; }
        }
        if ($supplier === null && str_starts_with($t, 'petrotal')) {
            $supplier = self::PROVEEDORES['PETROTAL'];
        }

        $terminal = null;
        if (preg_match('/\(([^)]+)\)/', $titulo, $m)) {
            $terminal = trim($m[1]);
        } elseif (str_contains($t, 'aemsa')) {
            $terminal = str_contains($t, 'sma') ? 'San Miguel de Allende' : (str_contains($t, 'ags') ? 'Aguascalientes' : null);
        } elseif (str_starts_with($t, 'petrotal')) {
            $terminal = 'Petrotal';
        } elseif (str_contains($t, 'essa')) {
            $terminal = 'Sin terminal';
        }
        return [$supplier, $terminal];
    }

    /**
     * Cruza las filas crudas con los catálogos. $cat = [
     *   'estaciones'     => [['Codigo'=>..,'Nombre'=>..], ...],
     *   'terminales'     => [['id'=>..,'nombre'=>..], ...],
     *   'transportistas' => [['id'=>..,'nombre'=>..], ...],
     * ]
     * Devuelve ['filas' => resueltas, 'errores' => [...], 'transportistas_nuevos' => [...]].
     */
    public static function resolve(array $filasCrudas, array $cat): array
    {
        $estNorm = [];
        foreach ($cat['estaciones'] as $e) {
            if ((int)$e['Codigo'] <= 0 || stripos($e['Nombre'], 'NO FUNCIONA') !== false) continue;
            $estNorm[(int)$e['Codigo']] = trim(preg_replace('/^\d+\s*/', '', self::norm($e['Nombre'])));
        }
        $termNorm = [];
        foreach ($cat['terminales'] as $t) {
            $termNorm[self::norm($t['nombre'])] = (int)$t['id'];
        }
        $carrNorm = [];
        foreach ($cat['transportistas'] as $c) {
            $carrNorm[self::norm($c['nombre'])] = ['id' => (int)$c['id'], 'nombre' => $c['nombre']];
        }

        $buscarEstacion = function (string $raw) use ($estNorm): ?int {
            $txt = trim(preg_replace('/^\d+\s*/', '', self::norm($raw)));
            $txt = self::ALIAS_ESTACIONES[$txt] ?? $txt;
            $exactas = array_keys($estNorm, $txt, true);
            if (count($exactas) === 1) return $exactas[0];
            $parciales = [];
            foreach ($estNorm as $cod => $nom) {
                if ($txt !== '' && (str_contains($nom, $txt) || str_contains($txt, $nom))) $parciales[] = $cod;
            }
            return count($parciales) === 1 ? $parciales[0] : null;
        };

        $out = $errores = $nuevos = [];
        foreach ($filasCrudas as $f) {
            $ref = "Hoja fila {$f['fila_excel']} ({$f['bloque']})";
            $fila = ['fila_excel' => $f['fila_excel'], 'bloque' => $f['bloque'], 'avisos' => []];

            $fecha = self::parse_fecha($f['fecha_raw']);
            if (!$fecha) { $errores[] = "$ref: fecha no válida"; continue; }

            [$supplier, $terminalNombre] = self::proveedor_terminal($f['bloque']);
            if (!$supplier) { $errores[] = "$ref: proveedor del título no reconocido"; continue; }
            $terminalId = $terminalNombre !== null ? ($termNorm[self::norm($terminalNombre)] ?? null) : null;
            if (!$terminalId) { $errores[] = "$ref: terminal «" . ($terminalNombre ?? 'sin indicar') . "» no existe en el catálogo"; continue; }

            $estacion = $buscarEstacion($f['estacion']);
            if (!$estacion) { $errores[] = "$ref: estación «{$f['estacion']}» no reconocida"; continue; }

            $hora = null;
            if ($f['hora_raw'] !== null && $f['hora_raw'] !== '') {
                $hora = self::parse_hora($f['hora_raw']);
                if ($hora === null) $fila['avisos'][] = 'hora no legible, se dejó vacía';
            }

            // Transportista (o "importado", que es una nota y no un transportista).
            $carrierId = null;
            $carrierNombre = '';
            $notas = [];
            $transp = $f['transp'];
            if ($transp !== '' && self::norm($transp) === 'importado') {
                $notas[] = 'importado';
            } elseif ($transp !== '') {
                $n = self::norm($transp);
                $hit = $carrNorm[$n] ?? null;
                if (!$hit && strlen($n) >= 5) {
                    foreach ($carrNorm as $k => $v) {
                        if (levenshtein($n, $k) <= 1) { $hit = $v; $fila['avisos'][] = "transportista «{$transp}» interpretado como «{$v['nombre']}»"; break; }
                    }
                }
                if ($hit) {
                    $carrierId = $hit['id'];
                    $carrierNombre = $hit['nombre'];
                } else {
                    $carrierNombre = strtoupper($transp) === $transp ? $transp : trim($transp);
                    $nuevos[$n] = $carrierNombre;
                    $fila['avisos'][] = "transportista «{$carrierNombre}» no existe, se creará";
                }
            }
            if ($f['prov'] !== '') $notas[] = $f['prov'];
            if ($f['destino'] !== '') $notas[] = 'Destino ' . $f['destino'];

            // Producto, con la regla vigente de Mixta: "N/M Mixta" -> Regular N*1000 + Premium M*1000.
            $prod = self::norm($f['producto']);
            $partes = [];
            if (preg_match('#^(\d+) (\d+) mixta$#', $prod, $m)) {
                $facReg = $facPre = $f['factura'] !== '' ? $f['factura'] : $f['embarque'];
                if (preg_match('#^(\d+)/(\d+)$#', $f['factura'], $fm)) {
                    $facReg = $fm[1];
                    $facPre = substr($fm[1], 0, strlen($fm[1]) - strlen($fm[2])) . $fm[2];
                }
                $partes[] = ['Regular', (int)$m[1] * 1000, $facReg];
                $partes[] = ['Premium', (int)$m[2] * 1000, $facPre];
            } elseif (in_array($prod, ['regular', 'premium', 'diesel'], true)) {
                $partes[] = [ucfirst($prod), (int)round($f['litros']), $f['factura'] !== '' ? $f['factura'] : $f['embarque']];
            } else {
                $errores[] = "$ref: producto «{$f['producto']}» no reconocido";
                continue;
            }

            foreach ($partes as [$p, $litros, $referencia]) {
                $out[] = $fila + [
                    'fecha' => $fecha, 'hora' => $hora, 'supplier_id' => $supplier,
                    'terminal_id' => $terminalId, 'terminal_nombre' => $terminalNombre,
                    'station_code' => $estacion, 'product' => $p, 'litros' => $litros,
                    'carrier_id' => $carrierId, 'carrier_nombre' => $carrierNombre,
                    'referencia' => $referencia !== '' ? $referencia : null,
                    'notas' => $notas ? implode(' · ', $notas) : null,
                ];
            }
        }
        return ['filas' => $out, 'errores' => $errores, 'transportistas_nuevos' => array_values($nuevos)];
    }
}
