<?php

// RECOPILACIÓN DE FUNCIONES DEL SISTEMA DE CONCILIACIÓN
// Extraídas de income.php

    public function upload_bank_reports() {
        echo $this->twig->render($this->route . 'upload_reports.html');
    }

  private function sanitizar_nombre_columna_php($nombre, $bankType, $coreMap) {
        if (!$nombre) return "SinNombre";
        $orig = trim((string)$nombre);

        // 1. Quitar BOM y normalizar espacios (incluyendo espacios no divisibles \xA0)
        $orig = str_replace(["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE", "\xC2\xA0"], ' ', $orig);
        $orig = trim(preg_replace('/\s+/', ' ', $orig));

        // 2. REPARACIÃ“N DE CODIFICACIÃ“N
        if (preg_match('/[\xC2\xC3][\x80-\xBF]/', $orig)) {
            $intento = @utf8_decode($orig);
            if ($intento && mb_check_encoding($intento, 'UTF-8')) {
                $orig = $intento;
            }
        }

        // 3. Revisar en el Mapa (Chequeo exacto)
        if (isset($coreMap[$bankType][$orig])) {
            return $coreMap[$bankType][$orig];
        }

        // 3.1. FUZZY MATCH ROBUSTO (Regex)
        $norm = mb_strtoupper($orig, 'UTF-8');
        $norm = str_replace(['Ã', 'Ã‰', 'Ã', 'Ã“', 'Ãš', 'Ãœ'], ['A', 'E', 'I', 'O', 'U', 'U'], $norm);
        
        if (preg_match('/FECHA/i', $norm)) {
            if (preg_match('/DEPOSITO|APLICACION/i', $norm)) {
                return 'Fecha_Deposito';
            }
            if (preg_match('/TRANSACCION/i', $norm)) {
                return 'Fecha_Transaccion';
            }
        }

        // 4. Limpieza estÃ¡ndar (Fallback)
        // iconv elimina acentos: 'AplicaciÃ³n' -> 'Aplicacion'
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $orig);
        if ($s === false) {
            $s = $orig;
        }
        $s = preg_replace('/[^a-zA-Z0-9_]/', '_', $s);
        $s = preg_replace('/_+/', '_', $s);
        
        return trim($s, '_');
    }
    private function asegurar_columnas_php($conn, $tabla, $cleanCols) {
        // FUNCIÃ“N DESACTIVADA PARA MANTENER ESTÃNDAR DE TABLAS
        return;
    }

    private function obtener_ajuste_juarez_php($fecha_trans) {
        if (!$fecha_trans || trim((string)$fecha_trans) === '-') return -1;
        try {
            $dt = ($fecha_trans instanceof \DateTime) ? $fecha_trans : new \DateTime($fecha_trans);
            $year = (int)$dt->format('Y');
        } catch (\Throwable $e) {
            return -1;
        }
        
        // 2do domingo de Marzo
        $mar1 = new \DateTime("$year-03-01");
        $dias_al_primero_mar = (7 - (int)$mar1->format('N')) % 7;
        $segundo_dom_mar = $mar1->modify("+" . ($dias_al_primero_mar + 7) . " days");
        
        // 1er domingo de Noviembre
        $nov1 = new \DateTime("$year-11-01");
        $dias_al_primero_nov = (7 - (int)$nov1->format('N')) % 7;
        $primer_dom_nov = $nov1->modify("+" . $dias_al_primero_nov . " days");
        
        // Horario Verano (UTC-6) vs Invierno (UTC-7)
        if ($dt >= $segundo_dom_mar && $dt < $primer_dom_nov) {
            return 0;
        } else {
            return -1;
        }
    }

    public function process_bank_upload() {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        ob_clean();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'MÃ©todo no permitido']);
            exit;
        }

        $bankType = $_POST['bank_type'] ?? 'OTROS';
        $filePath = '';
        $isTempFile = false;
        
        // Estructura de carpetas: _assets/uploads/BANCO/AÃ‘O/MES/
        $baseUploadsDir = __DIR__ . '/../uploads/';
        $subPath = $bankType . '/' . date('Y') . '/' . date('m') . '/';
        $targetDir = $baseUploadsDir . $subPath;

        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Nombre de archivo seguro
        $originalName = $_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? 'archivo.tmp');
        $safeName = date('His') . '_' . basename($originalName);
        $targetFile = $targetDir . $safeName;
        
        // RUTA RELATIVA PARA LA BASE DE DATOS (Trazabilidad)
        $dbFilePath = $subPath . $safeName;

        // Soporte para datos en Base64
        if (!empty($_POST['file_data'])) {
            if (file_put_contents($targetFile, base64_decode($_POST['file_data'])) === false) {
                echo json_encode(['status' => 'error', 'message' => 'Error al guardar el archivo']);
                exit;
            }
            $filePath = $targetFile;
        } 
        // Soporte para subida tradicional
        elseif (isset($_FILES['report_file']) && $_FILES['report_file']['error'] === UPLOAD_ERR_OK) {
            if (move_uploaded_file($_FILES['report_file']['tmp_name'], $targetFile)) {
                $filePath = $targetFile;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al mover el archivo subido']);
                exit;
            }
        }

        if (empty($filePath)) {
            $errCode = $_FILES['report_file']['error'] ?? 'NO_FILE';
            echo json_encode(['status' => 'error', 'message' => "Error al recibir el archivo (Code: $errCode)."]);
            exit;
        }

        $extension = strtolower(pathinfo($_POST['file_name'] ?? ($_FILES['report_file']['name'] ?? $filePath), PATHINFO_EXTENSION));

        $server = "192.168.0.6";
        $db = "TG";
        $user = "cguser";
        $pass = "sahei1712";

        $coreMap = [
            'BANORTE' => [
                'AfiliaciÃ³n' => 'Afiliacion',
                'Afiliacion' => 'Afiliacion',
                'Nombre de AfiliaciÃ³n' => 'Nombre_Afiliacion',
                'Nombre de Afiliacion' => 'Nombre_Afiliacion',
                'Moneda' => 'Moneda',
                'Estatus de TransacciÃ³n' => 'Estatus',
                'Estatus de Transaccion' => 'Estatus',
                'Tipo transaccion' => 'Tipo_Transaccion',
                'Tipo de TransacciÃ³n' => 'Tipo_Transaccion',
                'Tipo de Transaccion' => 'Tipo_Transaccion',
                'NÃºmero de Control' => 'ID_Externo',          
                'Numero de Control' => 'ID_Externo',
                'NÃºmero de Tarjeta' => 'Tarjeta',
                'Numero de Tarjeta' => 'Tarjeta',
                'Tipo de Tarjeta' => 'Tipo_Tarjeta',
                'Monto de TransacciÃ³n Signo' => 'Monto',
                'Monto de Transaccion Signo' => 'Monto',
                'Fecha TransacciÃ³n' => 'Fecha_Transaccion',
                'Fecha Transaccion' => 'Fecha_Transaccion',
                'CÃ³digo AutorizaciÃ³n' => 'Codigo_Autorizacion',
                'Codigo Autorizacion' => 'Codigo_Autorizacion',
                'Referencia' => 'Referencia_Pago',
                'Terminal ID' => 'Terminal',                  
                'Terminal' => 'Terminal',
                'Lote de TransacciÃ³n' => 'Lote',
                'Lote' => 'Lote',
                'Hora de TransacciÃ³n' => 'Hora',
                'Hora TransacciÃ³n' => 'Hora',
                'Hora' => 'Hora',
                'Referencia Interbancaria' => 'Referencia',
                'Fecha DepÃ³sito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha DepÃƒÂ³sito' => 'Fecha_Deposito',
                'Fecha de DepÃ³sito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha AplicaciÃƒÂ³n' => 'Fecha_Deposito',
                'Fecha de AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha de AplicaciÃƒÂ³n' => 'Fecha_Deposito',
            ],
            'SANTANDER' => [
                'ID movimiento' => 'ID_Externo',              
                'Fecha TransacciÃ³n' => 'Fecha_Transaccion',
                'Hora de TransacciÃ³n' => 'Hora',
                'Hora TransacciÃ³n' => 'Hora',
                'AfiliaciÃ³n' => 'Afiliacion',
                'Nombre del comercio' => 'Comercio',
                'Tipo de TransacciÃ³n' => 'Tipo_Transaccion',
                'Tipo TransacciÃ³n' => 'Tipo_Transaccion',
                'Tarjeta' => 'Tarjeta',
                'Cod. Terminal' => 'Terminal',                
                'Terminal ID' => 'Terminal',
                'OperaciÃ³n' => 'Operacion',
                'Tipo de Tarjeta' => 'Tipo_Tarjeta',
                'Tipo Tarjeta' => 'Tipo_Tarjeta',
                'NÃºmero de Tarjeta' => 'Tarjeta_Numero',
                'Tarjeta NÃºmero' => 'Tarjeta_Numero',
                'CÃ³digo AutorizaciÃ³n' => 'Codigo_Autorizacion',
                'Cod. Aut' => 'Codigo_Autorizacion',
                'Total' => 'Monto',                           
                'Monto de TransacciÃ³n Signo' => 'Monto',
                'ComisiÃ³n' => 'Comision',
                'Referencia' => 'Referencia',
                'Fecha DepÃ³sito' => 'Fecha_Deposito',
                'Fecha Deposito' => 'Fecha_Deposito',
                'Fecha DepÃƒÂ³sito' => 'Fecha_Deposito',
                'Fecha de DepÃ³sito' => 'Fecha_Deposito',
                'Fecha de Deposito' => 'Fecha_Deposito',
                'Fecha AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha Aplicacion' => 'Fecha_Deposito',
                'Fecha AplicaciÃƒÂ³n' => 'Fecha_Deposito',
                'Fecha de AplicaciÃ³n' => 'Fecha_Deposito',
                'Fecha de Aplicacion' => 'Fecha_Deposito',
                'Fecha de AplicaciÃƒÂ³n' => 'Fecha_Deposito',
            ]
        ];

        // COLUMNAS OFICIALES PERMITIDAS (DefiniciÃ³n Global)
        $columnas_oficiales = [
            'ID_Externo', 'Afiliacion', 'Fecha_Transaccion', 'Hora', 
            'Monto', 'Codigo_Autorizacion', 'Terminal', 'Referencia', 'Fecha_Deposito', 'Nombre_Archivo'
        ];

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $inserted = 0;
            $skipped = 0;

            if ($bankType === 'BANORTE') {
                $rows = [];
                $rawHeader = [];

                if ($extension === 'xlsx' || $extension === 'xls') {
                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();
                    $rawHeader = array_shift($rows);
                } else {
                    $handle = fopen($filePath, "r");
                    $rawHeader = fgetcsv($handle, 0, ",");
                    while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
                        $rows[] = $row;
                    }
                    fclose($handle);
                }

                $mappedIndices = [];
                foreach ($rawHeader as $i => $h) {
                    $stdName = $this->sanitizar_nombre_columna_php($h, 'BANORTE', $coreMap);
                    if (in_array($stdName, $columnas_oficiales)) {
                        $mappedIndices[$stdName] = $i;
                    }
                }

                // --- VALIDACION 100% FECHA DEPOSITO ---
                if (!isset($mappedIndices['Fecha_Deposito'])) {
                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Deposito/Aplicacion en el archivo.']);
                    exit;
                }
                $idxDepo = $mappedIndices['Fecha_Deposito'];
                foreach ($rows as $row) {
                    if (empty(array_filter($row))) continue;
                    $valDepo = $row[$idxDepo] ?? null;
                    if (empty($valDepo)) {
                        echo json_encode(['status' => 'error', 'message' => 'El archivo no cuenta con el 100% de las fechas de deposito/aplicacion. No se proceso ningun registro.']);
                        exit;
                    }
                }

                // Huellas para duplicados (8 CAMPOS CLAVE + FECHA DEPOSITO)
                $stmtH = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal, Fecha_Deposito FROM banco_banorte");
                $huellas = [];
                while ($r = $stmtH->fetch(PDO::FETCH_ASSOC)) {
                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                    $fch_dep = ($r['Fecha_Deposito'] instanceof DateTime) ? $r['Fecha_Deposito']->format('Y-m-d') : substr((string)$r['Fecha_Deposito'], 0, 10);
                    $key = trim($r['Afiliacion']??'') . '|' . trim($r['ID_Externo']??'') . '|' . $fch . '|' . number_format((float)$r['Monto'], 2, '.', '') . '|' . trim($r['Hora']??'') . '|' . trim($r['Codigo_Autorizacion']??'') . '|' . trim($r['Referencia']??'') . '|' . trim($r['Terminal']??'') . '|' . $fch_dep;
                    $huellas[$key] = true;
                }

                $sqlIns = "INSERT INTO banco_banorte (".implode(",", $columnas_oficiales).") VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).")";
                $ins = $conn->prepare($sqlIns);

                foreach ($rows as $row) {
                    if (empty(array_filter($row))) continue;

                    $dataRow = [];
                    foreach($columnas_oficiales as $col) {
                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                        $val = isset($mappedIndices[$col]) ? $row[$mappedIndices[$col]] : null;
                        
                        if ($col === 'Monto') $val = (float)str_replace(['$', ','], '', $val ?? 0);
                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                            if ($val && trim((string)$val) !== '-') {
                                try {
                                    $d = \DateTime::createFromFormat('d/m/Y', $val);
                                    if (!$d) $d = new \DateTime($val);
                                    $val = $d ? $d->format('Y-m-d') : null;
                                } catch (\Throwable $e) {
                                    $val = null;
                                }
                            } else {
                                $val = null;
                            }
                        }
                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                        if ($col === 'Hora' && $val && trim((string)$val) !== '-' && isset($dataRow['Fecha_Transaccion'])) {
                            $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                            if ($ajuste !== 0) {
                                try {
                                    $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                    $dt_full->modify("$ajuste hours");
                                    $val = $dt_full->format('H:i:s');
                                    $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                } catch(\Throwable $e) {}
                            }
                        }
                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                    }

                    $keyRow = trim($dataRow['Afiliacion']??'') . '|' . trim($dataRow['ID_Externo']??'') . '|' . ($dataRow['Fecha_Transaccion']??'') . '|' . number_format((float)($dataRow['Monto']??0), 2, '.', '') . '|' . trim($dataRow['Hora']??'') . '|' . trim($dataRow['Codigo_Autorizacion']??'') . '|' . trim($dataRow['Referencia']??'') . '|' . trim($dataRow['Terminal']??'') . '|' . ($dataRow['Fecha_Deposito']??'');
                    
                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }
                    if (isset($huellas[$keyRow])) { $skipped++; continue; }

                    $ins->execute(array_values($dataRow));
                    $inserted++;
                }
                
                            } elseif ($bankType === 'SANTANDER') {
                                $allRows = [];
                                $rawHeader = [];

                                if ($extension === 'xlsx' || $extension === 'xls') {
                                    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                                    $sheet = $spreadsheet->getActiveSheet();
                                    $allRows = $sheet->toArray();
                                    $rawHeader = array_shift($allRows);
                                } else {
                                    $handle = fopen($filePath, "r");
                                    $rawHeader = fgetcsv($handle, 0, ",");
                                    while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
                                        $allRows[] = $row;
                                    }
                                    fclose($handle);
                                }
                                
                                // 1. Mapear Ãndices
                                $mappedIndices = [];
                                foreach ($rawHeader as $i => $h) {
                                    $stdName = $this->sanitizar_nombre_columna_php($h, 'SANTANDER', $coreMap);
                                    if (in_array($stdName, $columnas_oficiales)) {
                                        $mappedIndices[$stdName] = $i;
                                    }
                                }

                                // --- VALIDACION 100% FECHA DEPOSITO ---
                                if (!isset($mappedIndices['Fecha_Deposito'])) {
                                    echo json_encode(['status' => 'error', 'message' => 'No se encontro la columna de Fecha de Deposito en el archivo.']);
                                    exit;
                                }
                                $idxDepo = $mappedIndices['Fecha_Deposito'];
                                
                                // Validar que todas las filas tengan fecha de deposito
                                foreach ($allRows as $rowIndex => $row) {
                                    if (empty(array_filter($row))) {
                                        unset($allRows[$rowIndex]);
                                        continue;
                                    }
                                    if (empty($row[$idxDepo] ?? null)) {
                                        echo json_encode(['status' => 'error', 'message' => 'El archivo no cuenta con el 100% de las fechas de deposito. No se proceso ningun registro.']);
                                        exit;
                                    }
                                }
                
                                // 2. Huellas para duplicados (8 CAMPOS CLAVE + FECHA DEPOSITO)
                                $stmt = $conn->query("SELECT Afiliacion, ID_Externo, Fecha_Transaccion, Monto, Hora, Codigo_Autorizacion, Referencia, Terminal, Fecha_Deposito FROM banco_getnet");
                                $huellas = [];
                                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $fch = ($r['Fecha_Transaccion'] instanceof DateTime) ? $r['Fecha_Transaccion']->format('Y-m-d') : substr((string)$r['Fecha_Transaccion'], 0, 10);
                                    $fch_dep = ($r['Fecha_Deposito'] instanceof DateTime) ? $r['Fecha_Deposito']->format('Y-m-d') : substr((string)$r['Fecha_Deposito'], 0, 10);
                                    $key = trim($r['Afiliacion'] ?? '') . '|' . 
                                           trim($r['ID_Externo'] ?? '') . '|' . 
                                           $fch . '|' . 
                                           number_format((float)$r['Monto'], 2, '.', '') . '|' .
                                           trim($r['Hora'] ?? '') . '|' .
                                           trim($r['Codigo_Autorizacion'] ?? '') . '|' .
                                           trim($r['Referencia'] ?? '') . '|' .
                                           trim($r['Terminal'] ?? '') . '|' .
                                           $fch_dep;
                                    $huellas[$key] = true;
                                }
                
                                // 3. Preparar SQL EstÃ¡ndar
                                $sqlIns = "INSERT INTO banco_getnet (".implode(",", $columnas_oficiales).") VALUES (".implode(",", array_fill(0, count($columnas_oficiales), "?")).")";
                                $ins = $conn->prepare($sqlIns);
                
                                // Ruta relativa para la DB
                                $dbFilePath = $subPath . $safeName;
                
                                foreach ($allRows as $row) {
                                    $dataRow = [];
                                    foreach($columnas_oficiales as $col) {
                                        if ($col === 'Nombre_Archivo') { $dataRow[$col] = $dbFilePath; continue; }
                                        $val = isset($mappedIndices[$col]) ? ($row[$mappedIndices[$col]] ?? null) : null;
                                        
                                        if ($col === 'Monto') $val = (float)str_replace(['$', ','], '', $val ?? 0);
                                        if ($col === 'Fecha_Transaccion' || $col === 'Fecha_Deposito') {
                                            if ($val && trim((string)$val) !== '-') {
                                                try {
                                                    $d = \DateTime::createFromFormat('d/m/Y', $val);
                                                    if (!$d) $d = new \DateTime($val);
                                                    $val = $d ? $d->format('Y-m-d') : null;
                                                } catch (\Throwable $e) {
                                                    $val = null;
                                                }
                                            } else {
                                                $val = null;
                                            }
                                        }
                                        if ($col === 'Afiliacion') $val = ltrim(trim($val ?? ''), '0');
                                        if ($col === 'Hora' && $val && trim((string)$val) !== '-') {
                                            $h_clean = strtolower(trim($val));
                                            
                                            // NormalizaciÃ³n robusta: quitar puntos, asegurar un solo espacio antes de am/pm
                                            $h_clean = str_replace('.', '', $h_clean);
                                            $h_clean = preg_replace('/([ap])\s*m/', '$1m', $h_clean); // unir a m -> am
                                            $h_clean = str_replace(['am', 'pm'], [' am', ' pm'], $h_clean);
                                            $h_clean = preg_replace('/\s+/', ' ', $h_clean);
                                            $h_clean = trim($h_clean);

                                            if (strpos($h_clean, 'am') !== false || strpos($h_clean, 'pm') !== false) {
                                                $d_h = \DateTime::createFromFormat('h:i:s a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('g:i:s a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('h:i a', $h_clean);
                                                if (!$d_h) $d_h = \DateTime::createFromFormat('g:i a', $h_clean);
                                                
                                                if ($d_h) {
                                                    $val = $d_h->format('H:i:s');
                                                }
                                            }
                                            
                                            // Asegurar ceros a la izquierda si ya es 24h pero falta el cero (ej: 9:05:00)
                                            if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $val)) {
                                                $parts = explode(':', $val);
                                                $val = sprintf("%02d:%02d:%02d", $parts[0], $parts[1], $parts[2]);
                                            }
                                            
                                            // AJUSTE HORARIO JUAREZ
                                            if (isset($dataRow['Fecha_Transaccion'])) {
                                                $ajuste = $this->obtener_ajuste_juarez_php($dataRow['Fecha_Transaccion']);
                                                if ($ajuste !== 0) {
                                                    try {
                                                        $dt_full = new \DateTime($dataRow['Fecha_Transaccion'] . " " . $val);
                                                        $dt_full->modify("$ajuste hours");
                                                        $val = $dt_full->format('H:i:s');
                                                        $dataRow['Fecha_Transaccion'] = $dt_full->format('Y-m-d');
                                                    } catch(\Throwable $e) {}
                                                }
                                            }
                                        }
                                        $dataRow[$col] = ($val === null || $val === '') ? null : $val;
                                    }
                
                                    // Huella para saltar duplicados (+ FECHA DEPOSITO)
                                    $huella = trim($dataRow['Afiliacion']??'') . '|' . trim($dataRow['ID_Externo']??'') . '|' . ($dataRow['Fecha_Transaccion']??'') . '|' . number_format((float)($dataRow['Monto']??0), 2, '.', '') . '|' . trim($dataRow['Hora']??'') . '|' . trim($dataRow['Codigo_Autorizacion']??'') . '|' . trim($dataRow['Referencia']??'') . '|' . trim($dataRow['Terminal']??'') . '|' . ($dataRow['Fecha_Deposito']??'');
                                    
                                    if (($dataRow['Monto'] ?? 0) <= 0) { $skipped++; continue; }
                                    if (isset($huellas[$huella])) { $skipped++; continue; }
                
                                    $ins->execute(array_values($dataRow));
                                    $inserted++;
                                }
                            }

            // Limpiar logs de debug previos
            if (file_exists('debug_santander_upload.log')) @unlink('debug_santander_upload.log');
            if (file_exists('conciliacion_debug.log')) @unlink('conciliacion_debug.log');

            // Limpiar archivo temporal si se creÃ³ desde Base64
            if ($isTempFile && file_exists($filePath)) {
                @unlink($filePath);
            }

            echo json_encode([
                'status' => 'success', 
                'inserted' => $inserted, 
                'skipped' => $skipped
            ]);

        } catch (Exception $e) {
            if ($isTempFile && file_exists($filePath)) @unlink($filePath);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    public function conc_test() {
        $this->setup_conciliacion_v2(true); // Auto-migration V2 (Silencioso)
        echo $this->twig->render($this->route . 'test.html');
    }

    // =========================================================================
    // 1. OBTENER CATÃLOGO DE ESTACIONES (Para ControlGas) - UNIFICADO CON AFIL
    // =========================================================================
    public function get_estaciones_catalogo() {
        ob_clean();
        header('Content-Type: application/json');
        
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 
        
        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // INNER JOIN con Tesoreria_afil para traer SOLO las estaciones que tienen configuraciÃ³n/afiliaciÃ³n
            $sql = "SELECT DISTINCT 
                        T1.Codigo, 
                        T1.Nombre, 
                        T2.rfc as RFC 
                    FROM Estaciones T1
                    INNER JOIN Tesoreria_afil T2 ON T1.Codigo = T2.estacion_id
                    ORDER BY T1.Nombre";

            $stmt = $conn->query($sql);
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $rfc = trim($row['RFC']);
                // Normalizar valores vacÃ­os
                if($rfc === '' || $rfc === 'NULL') {
                    $rfc = 'FORANEAS';
                }
                $row['RFC'] = $rfc;
                $result[] = $row;
            }

            // INYECCIÃ“N MANUAL COLOSIO (Si no viene de BD)
            $foundColosio = false;
            foreach($result as $r) { if($r['Codigo'] == 333) $foundColosio = true; }

            if(!$foundColosio) {
                $result[] = [
                    'Codigo' => 333,
                    'Nombre' => 'COLOSIO',
                    'RFC'    => 'FORANEAS'
                ];
                // Reordenar alfabÃ©ticamente
                usort($result, function($a, $b) { return strcmp($a['Nombre'], $b['Nombre']); });
            }
            
            echo json_encode(["status" => "success", "respuesta" => $result]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 2. OBTENER VENTAS LOCAL (Reemplazo de API externa)
    // =========================================================================
    public function get_ventas_local() {
        ob_clean();
        header('Content-Type: application/json');

        // 1. Leer el JSON entrante (Misma estructura que enviaba el JS a la API vieja)
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validar datos bÃ¡sicos
        if (!isset($input['Datos']['FechaInicial']) || !isset($input['Datos']['Gasolinera'])) {
            echo json_encode(["status" => "error", "message" => "Faltan parÃ¡metros"]);
            exit;
        }

        $fIniStr = $input['Datos']['FechaInicial']; // YYYYMMDD
        $fFinStr = $input['Datos']['FechaFinal'];   // YYYYMMDD
        $codGas  = intval($input['Datos']['Gasolinera']);

        // ConfiguraciÃ³n BD (Usar tus credenciales)
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2. Query SQL optimizado
            $sql = "
                DECLARE @fInicio INT = DATEDIFF(dd, 0, :fIni) + 1;
                DECLARE @fFin INT    = DATEDIFF(dd, 0, :fFin) + 1;

                SELECT 
                    -- ID ÃšNICO
                    CAST(i.fch AS VARCHAR) + '-' + 
                    CAST(i.codisl AS VARCHAR) + '-' + 
                    CAST(i.nrotur AS VARCHAR) + '-' + 
                    CAST(i.codval AS VARCHAR) AS ID_Unico,

                    -- DATOS GENERALES
                    CONVERT(VARCHAR, CONVERT(SMALLDATETIME, i.fch - 1, 106), 103) AS FechaVisual,
                    i.fch, 
                    i.nrotur AS Turno,
                    v.den AS Concepto,
                    CAST(i.mto AS FLOAT) AS Total,
                    
                    -- COLUMNAS QUE FALTABAN
                    i.codgas AS CodEstacion,  -- <--- FALTABA ESTO
                    g.abr AS Estacion,

                    -- LÃ“GICA DE TIPO (RESTAURADA)
                    CASE
                        WHEN i.codval = 6 THEN 'EFECTIVO'
                        WHEN i.codval = 192 THEN 'MORALLA'
                        WHEN i.codval = 5 THEN 'BILLETE'
                        WHEN i.codval IN (-3001, 194, 197, -3002, 204, 167, 28, 127, 207, 196, 198, 211, 203, 206, 212, 201, 210, 209, 205) THEN 'VALES/TARJETAS'
                        WHEN i.codval = 28 THEN 'CLTCREDITO'
                        WHEN i.codval = 127 THEN 'CLTDEBITO'
                        WHEN i.codval = 145 THEN 'PROMOCIONES MKT'
                        WHEN i.codval = 216 THEN 'ULTRAGAS'
                        ELSE 'OTROS'
                    END AS Tipo

                FROM [SG12].[dbo].[Ingresos] i
                INNER JOIN [SG12].[dbo].[Gasolineras] g ON i.codgas = g.cod
                INNER JOIN [SG12].[dbo].[Valores] v ON i.codval = v.cod

                WHERE 
                    i.fch BETWEEN @fInicio AND @fFin
                    AND i.codgas = :codGas
                
                ORDER BY i.fch, i.nrotur, i.codval
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':fIni', $fIniStr);
            $stmt->bindParam(':fFin', $fFinStr);
            $stmt->bindParam(':codGas', $codGas);
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Estructura de respuesta compatible con tu JS actual
            // El JS espera: { respuesta: [ ...array... ] } (Basado en tu cÃ³digo anterior)
            echo json_encode([
                "status" => "success", 
                "respuesta" => $data 
            ]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // TESORERIA GENERAL (0956)
    // =========================================================================
    public function get_tesoreria_data() {
        ob_clean();
        header('Content-Type: application/json');

        $server = "192.168.0.6";
        $db = "TG";
        $user = "cguser";
        $pass = "sahei1712";
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT TOP 4000 Fecha, Referencia, Descripcion, Sucursal, Depositos, Retiros, Saldo 
                    FROM Tesoreria_0956 
                    WHERE YEAR(Fecha) = ? AND MONTH(Fecha) = ?
                    ORDER BY Fecha ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month]);
            
            $result = [];

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $fechaVal = $row['Fecha'];
                if ($fechaVal instanceof DateTime) {
                    $row['Fecha'] = $fechaVal->format('Y-m-d');
                } else {
                    $row['Fecha'] = substr((string)$fechaVal, 0, 10);
                }

                $row['Depositos'] = (float)$row['Depositos'];
                $row['Retiros']   = (float)$row['Retiros'];
                $row['Saldo']     = (float)$row['Saldo'];
                
                $result[] = $row;
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 2. TESORERIA BANORTE
    // =========================================================================
    public function get_tesoreria_banorte() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Traemos A.rfc
            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = 4 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }

            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $sqlMovs = "SELECT Fecha, Descripcion, Depositos FROM Tesoreria_0956 
                        WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
            $stmtMovs = $conn->prepare($sqlMovs);
            $stmtMovs->execute([$year, $month]);
            
            $agrupado = [];
            while($row = $stmtMovs->fetch(PDO::FETCH_ASSOC)){
                $desc = trim($row['Descripcion']);
                if (stripos($desc, 'TOTAL GAS') !== 0 && stripos($desc, 'TotalGas') !== 0 && stripos($desc, 'DIAZ GAS') !== 0) continue;

                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    $afiliacionStr = $afilItem['afiliacion'];
                    if (strpos($desc, $afiliacionStr) !== false) {
                        $key = $fecha . '_' . $afiliacionStr;
                        if (!isset($agrupado[$key])) {
                            $agrupado[$key] = [
                                'Fecha' => $fecha, 'Afiliacion' => $afiliacionStr, 'Estacion' => $afilItem['Estacion'], 'Total' => 0
                            ];
                        }
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });

            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 3. TESORERIA SANTANDER
    // =========================================================================
    public function get_tesoreria_santander() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, S.Nombre as Estacion, ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        INNER JOIN Estaciones S ON A.estacion_id = S.Codigo
                        WHERE A.entidad_id = 1 AND LEN(ISNULL(A.afiliacion,'')) > 0";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $tablas = ['Tesoreria_5117', 'Tesoreria_8973'];
            $movimientosRaw = [];

            foreach ($tablas as $tabla) {
                $check = $conn->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '$tabla'");
                if($check->fetchColumn() > 0) {
                    $sql = "SELECT Fecha, Referencia, Descripcion, Depositos FROM $tabla WHERE Depositos > 0 AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$year, $month]);
                    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $movimientosRaw[] = $r;
                }
            }
            
            $agrupado = [];
            foreach($movimientosRaw as $row){
                $ref = trim($row['Referencia']);
                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    $afiliacionStr = $afilItem['afiliacion'];
                    if (stripos($ref, $afiliacionStr) !== false) {
                        $key = $fecha . '_' . $afiliacionStr;
                        if (!isset($agrupado[$key])) {
                            $agrupado[$key] = [
                                'Fecha' => $fecha, 'Afiliacion' => $afiliacionStr, 'Estacion' => $afilItem['Estacion'], 'Total' => 0
                            ];
                        }
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });

            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);
        } catch (PDOException $e) {
            echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // 4. TESORERIA AMEX
    // =========================================================================
    public function get_tesoreria_amex() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = 3 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $agrupado = [];
            
            // Fuente 5117
            try {
                $sql5117 = "SELECT Fecha, Concepto, Depositos FROM Tesoreria_5117 WHERE YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                $stmt = $conn->prepare($sql5117); $stmt->execute([$year, $month]);
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    $concepto = trim($row['Concepto'] ?? '');
                    if ($concepto === '') continue;
                    $monto = (float)$row['Depositos'];
                    $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                    foreach ($catalogo as $afilItem) {
                        if (stripos($concepto, $afilItem['afiliacion']) !== false) {
                            $key = $fecha . '_' . $afilItem['afiliacion'];
                            if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                            $agrupado[$key]['Total'] += $monto;
                            break;
                        }
                    }
                }
            } catch(Exception $e){}

            // Fuente 0956
            try {
                $sql0956 = "SELECT Fecha, DescripcionDetallada, Depositos FROM Tesoreria_0956 WHERE DescripcionDetallada IS NOT NULL AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
                $stmt = $conn->prepare($sql0956); $stmt->execute([$year, $month]);
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    $detalle = trim($row['DescripcionDetallada']);
                    if ($detalle === '') continue;
                    $monto = (float)$row['Depositos'];
                    $fecha = ($row['Fecha'] instanceof DateTime) ? $row['Fecha']->format('Y-m-d') : substr((string)$row['Fecha'], 0, 10);
                    foreach ($catalogo as $afilItem) {
                        if (stripos($detalle, $afilItem['afiliacion']) !== false) {
                            $key = $fecha . '_' . $afilItem['afiliacion'];
                            if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                            $agrupado[$key]['Total'] += $monto;
                            break;
                        }
                    }
                }
            } catch(Exception $e){}

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });
            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // =========================================================================
    // 5. TESORERIA AFIRME
    // =========================================================================
    public function get_tesoreria_afirme() {
        ob_clean();
        header('Content-Type: application/json');
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";
        $id_afirme = 13;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m'); 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sqlAfil = "SELECT A.afiliacion, 
                               ISNULL(S.Nombre, V.Nombre) as Estacion,
                               ISNULL(A.rfc, 'FORANEAS') as RFC 
                        FROM Tesoreria_afil A
                        LEFT JOIN Estaciones S ON A.estacion_id = S.Codigo
                        LEFT JOIN Tesoreria_Estaciones_Virtuales V ON A.estacion_id = V.Codigo
                        WHERE A.entidad_id = $id_afirme 
                        AND LEN(ISNULL(A.afiliacion,'')) > 0
                        AND (S.Nombre IS NOT NULL OR V.Nombre IS NOT NULL)";
            
            $stmtAfil = $conn->query($sqlAfil);
            $catalogo = [];
            while($r = $stmtAfil->fetch(PDO::FETCH_ASSOC)){
                $catalogo[] = [
                    'afiliacion' => trim($r['afiliacion']),
                    'Estacion'   => $r['Estacion'],
                    'RFC'        => trim($r['RFC'])
                ];
            }
            usort($catalogo, function($a, $b) {
                $res = strcmp($a['Estacion'], $b['Estacion']);
                return ($res == 0) ? strcmp($a['afiliacion'], $b['afiliacion']) : $res;
            });

            $sql = "SELECT Fecha, Descripcion, Depositos FROM Tesoreria_Afirme WHERE Depositos > 0 AND Descripcion LIKE '%VENTA%' AND YEAR(Fecha) = ? AND MONTH(Fecha) = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$year, $month]);
            $agrupado = [];

            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $descripcion = trim($row['Descripcion'] ?? '');
                $fechaVal = $row['Fecha'];
                $fecha = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                $monto = (float)$row['Depositos'];

                foreach ($catalogo as $afilItem) {
                    if (stripos($descripcion, $afilItem['afiliacion']) !== false) {
                        $key = $fecha . '_' . $afilItem['afiliacion'];
                        if (!isset($agrupado[$key])) $agrupado[$key] = ['Fecha'=>$fecha,'Afiliacion'=>$afilItem['afiliacion'],'Estacion'=>$afilItem['Estacion'],'Total'=>0];
                        $agrupado[$key]['Total'] += $monto;
                        break; 
                    }
                }
            }

            $resultado = array_values($agrupado);
            usort($resultado, function($a, $b) { return strcmp($a['Fecha'], $b['Fecha']); });
            echo json_encode(["status" => "success", "data" => $resultado, "catalog" => $catalogo]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // =========================================================================
    // 2. OBTENER TRANSACCIONES DEL BANCO (GETNET / BANORTE)
    // =========================================================================
    public function get_transacciones_banco() {
        ob_clean();
        header('Content-Type: application/json');

        $eid = $_GET['entidad_id'] ?? null;
        $afiliacion = $_GET['afiliacion'] ?? null;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        if (!$eid || !$afiliacion) {
            echo json_encode(["status" => "error", "message" => "Faltan parÃ¡metros"]);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = ($eid == 1) ? "banco_getnet" : (($eid == 4) ? "banco_banorte" : "");
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // SOPORTE MULTI-AFILIACIÃ“N: Split por '/' y limpieza
            $afil_parts = array_map('trim', explode('/', $afiliacion));
            $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));

            // QUERY ESTANDARIZADA
            $sql = "SELECT 
                        ID_Externo,
                        Fecha_Transaccion, 
                        Monto, 
                        Afiliacion,
                        Terminal,
                        Hora,
                        Codigo_Autorizacion,
                        Referencia,
                        Nombre_Archivo,
                        Fecha_Deposito
                    FROM $tabla
                    WHERE YEAR(Fecha_Transaccion) = ? 
                      AND MONTH(Fecha_Transaccion) = ? 
                      AND Afiliacion IN ($placeholders)
                    ORDER BY Fecha_Transaccion ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$year, $month], $afil_parts));
            
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                // GENERACIÃ“N DE ID DETERMINISTA ABSOLUTA (Huella Digital de 8 campos)
                // Usamos hash siempre, ya que ID_Externo en Santander puede venir duplicado.
                $hashData = 
                    (string)($row['Afiliacion'] ?? '') . 
                    (string)($row['Fecha_Transaccion'] ?? '') . 
                    (string)($row['Hora'] ?? '') . 
                    (string)($row['Monto'] ?? '') . 
                    (string)($row['Codigo_Autorizacion'] ?? '') .
                    (string)($row['Terminal'] ?? '') .
                    (string)($row['Referencia'] ?? '') .
                    (string)($row['ID_Externo'] ?? ''); 
                
                $idTransaccion = 'tx_' . md5($hashData);
                
                $fechaVal = $row['Fecha_Transaccion'];
                $fechaIso = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                
                $fechaDepoVal = $row['Fecha_Deposito'];
                $fechaDepoIso = null;
                if ($fechaDepoVal) {
                    $fechaDepoIso = ($fechaDepoVal instanceof DateTime) ? $fechaDepoVal->format('Y-m-d') : substr((string)$fechaDepoVal, 0, 10);
                }

                $result[] = [
                    'IdTransaccion' => $idTransaccion,
                    'ID_Externo' => $row['ID_Externo'], 
                    'FechaTransaccion' => $fechaIso,
                    'FechaAplicacion' => $fechaDepoIso ?? $fechaIso,
                    'FechaConciliacion' => $fechaIso,
                    'Total' => (float)$row['Monto'],
                    'Concepto' => 'Venta',
                    'Afiliacion' => $row['Afiliacion'],
                    'Terminal_ID' => $row['Terminal'],
                    'Hora' => $row['Hora'],
                    'Codigo_Autorizacion' => $row['Codigo_Autorizacion'],
                    'Referencia' => $row['Referencia'],
                    'Nombre_Archivo' => $row['Nombre_Archivo'] // TRAZABILIDAD
                ];
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    public function get_transacciones_por_deposito() {
        ob_clean();
        header('Content-Type: application/json');

        $eid = $_GET['entidad_id'] ?? null;
        $afiliacion = $_GET['afiliacion'] ?? null;
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

        if (!$eid || !$afiliacion) {
            echo json_encode(["status" => "error", "message" => "Faltan parÃ¡metros"]);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = ($eid == 1) ? "banco_getnet" : (($eid == 4) ? "banco_banorte" : "");
            
            if (empty($tabla)) {
                echo json_encode(["status" => "success", "data" => []]);
                exit;
            }

            // SOPORTE MULTI-AFILIACIÃ“N
            $afil_parts = array_map('trim', explode('/', $afiliacion));
            $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));

            // BUSQUEDA POR FECHA DE DEPOSITO
            $sql = "SELECT 
                        ID_Externo,
                        Fecha_Transaccion, 
                        Monto, 
                        Afiliacion,
                        Terminal,
                        Hora,
                        Codigo_Autorizacion,
                        Referencia,
                        Nombre_Archivo,
                        Fecha_Deposito
                    FROM $tabla
                    WHERE YEAR(Fecha_Deposito) = ? 
                      AND MONTH(Fecha_Deposito) = ? 
                      AND Afiliacion IN ($placeholders)
                    ORDER BY Fecha_Deposito ASC";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array_merge([$year, $month], $afil_parts));
            
            $result = [];
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                $hashData = 
                    (string)($row['Afiliacion'] ?? '') . 
                    (string)($row['Fecha_Transaccion'] ?? '') . 
                    (string)($row['Hora'] ?? '') . 
                    (string)($row['Monto'] ?? '') . 
                    (string)($row['Codigo_Autorizacion'] ?? '') .
                    (string)($row['Terminal'] ?? '') .
                    (string)($row['Referencia'] ?? '') .
                    (string)($row['ID_Externo'] ?? ''); 
                
                $idTransaccion = 'tx_' . md5($hashData);
                
                $fechaVal = $row['Fecha_Transaccion'];
                $fechaIso = ($fechaVal instanceof DateTime) ? $fechaVal->format('Y-m-d') : substr((string)$fechaVal, 0, 10);
                
                $fechaDepoVal = $row['Fecha_Deposito'];
                $fechaDepoIso = ($fechaDepoVal instanceof DateTime) ? $fechaDepoVal->format('Y-m-d') : substr((string)$fechaDepoVal, 0, 10);

                $result[] = [
                    'IdTransaccion' => $idTransaccion,
                    'ID_Externo' => $row['ID_Externo'], 
                    'FechaTransaccion' => $fechaIso,
                    'Fecha_Deposito' => $fechaDepoIso,
                    'Total' => (float)$row['Monto'],
                    'Afiliacion' => $row['Afiliacion']
                ];
            }

            echo json_encode(["status" => "success", "data" => $result]);

        } catch (PDOException $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // FUNCIÃ“N PARA SERVIR ARCHIVOS DEL BANCO
    public function view_bank_file() {
        while (ob_get_level()) ob_end_clean();

        $file = $_GET['file'] ?? '';
        if (empty($file)) { http_response_code(400); exit("Archivo no especificado"); }

        $file = str_replace(['../', '..\\'], '', $file);
        
        // 1. Intentar ruta local del proyecto
        $baseDirLocal = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        $fullPath = $baseDirLocal . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

        if (!file_exists($fullPath)) {
            // 2. Fallback a la ruta absoluta del IIS (Donde el bot guarda)
            $baseDirIIS = "C:\\inetpub\\wwwroot\\TG_PHP\\_assets\\uploads\\";
            $fullPath = $baseDirIIS . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        }

        if (!file_exists($fullPath)) {
            http_response_code(404);
            exit("El reporte original no se encuentra en ninguna de las rutas configuradas: " . $file);
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            'pdf'  => 'application/pdf'
        ];
        $ctype = $mimes[$ext] ?? 'application/octet-stream';

        // Configurar Headers para descarga forzada
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $ctype);
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($fullPath));
        
        // Leer archivo y terminar ejecuciÃ³n
        readfile($fullPath);
        exit;
    }

    // =========================================================================
    // ACTUALIZAR FECHA DE TRANSACCIÃ“N (MOVER A OTRO DÃA)
    // =========================================================================
    public function update_transaction_date() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['id']) || !isset($data['new_date']) || !isset($data['entidad_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $tabla = "";
            $colId = "";
            $colFecha = "Fecha_Transaccion";

            if ($data['entidad_id'] == 1) { // Santander
                $tabla = "banco_getnet";
                $colId = "ID_Externo";
            } else if ($data['entidad_id'] == 4) { // Banorte
                $tabla = "banco_banorte";
                $colId = "ID_Externo";
            }

            $sql = "UPDATE $tabla SET $colFecha = ? WHERE $colId = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$data['new_date'], $data['id']]);

            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
    // CONFIGURACIÃ“N INICIAL V2 (TABLAS)
    // =========================================================================
    public function setup_conciliacion_v2($silent = false) {
        if (!$silent) {
            ob_clean();
            header('Content-Type: application/json');
        }
        
        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Tabla de Grupos (Headers de conciliaciÃ³n)
            $sqlGrupos = "
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Conciliacion_V2_Grupos' AND xtype='U')
                CREATE TABLE Conciliacion_V2_Grupos (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    uuid UNIQUEIDENTIFIER DEFAULT NEWID(),
                    fecha_creacion DATETIME DEFAULT GETDATE(),
                    fecha_operativa DATE,
                    total_sistema DECIMAL(18,2) DEFAULT 0,
                    total_banco DECIMAL(18,2) DEFAULT 0,
                    diferencia DECIMAL(18,2) DEFAULT 0,
                    estacion_id INT,
                    usuario_id INT,
                    status VARCHAR(50) DEFAULT 'ACTIVE'
                )
            ";
            $conn->exec($sqlGrupos);

            // Asegurar columna fecha_operativa si la tabla ya existÃ­a
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'fecha_operativa') 
                ALTER TABLE Conciliacion_V2_Grupos ADD fecha_operativa DATE");

            // NUEVO: Asegurar columnas de banco y afiliaciÃ³n en el Grupo
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'entidad_id') 
                ALTER TABLE Conciliacion_V2_Grupos ADD entidad_id INT");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_V2_Grupos') AND name = 'afiliacion') 
                ALTER TABLE Conciliacion_V2_Grupos ADD afiliacion VARCHAR(50)");

            // Tabla Detalles V2 (Items individuales conciliados)
            $sqlDetalles = "
                IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='Conciliacion_V2_Detalles' AND xtype='U')
                CREATE TABLE Conciliacion_V2_Detalles (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    grupo_id INT NOT NULL,
                    origen VARCHAR(10) NOT NULL, -- 'CG' (ControlGas) o 'TX' (TransacciÃ³n Banco)
                    referencia_externa VARCHAR(255) NOT NULL, -- ID Ãºnico del sistema origen
                    fecha_operacion DATE,
                    monto DECIMAL(18,2),
                    concepto VARCHAR(255),
                    metadatos NVARCHAR(MAX) -- Para guardar JSON extra si se requiere
                )
            ";
            $conn->exec($sqlDetalles);

            // Ãndices para velocidad
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IDX_V2_REF') CREATE INDEX IDX_V2_REF ON Conciliacion_V2_Detalles(referencia_externa)");
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.indexes WHERE name = 'IDX_V2_GRP') CREATE INDEX IDX_V2_GRP ON Conciliacion_V2_Detalles(grupo_id)");

            // Asegurar columna en tabla de trÃ¡nsito (MigraciÃ³n sutil)
            $conn->exec("IF NOT EXISTS (SELECT * FROM sys.columns WHERE object_id = OBJECT_ID('Conciliacion_Transito') AND name = 'referencia_externa') ALTER TABLE Conciliacion_Transito ADD referencia_externa VARCHAR(255)");

            if (!$silent) {
                echo json_encode(['status' => 'success', 'message' => 'Tablas V2 verificadas/creadas']);
                exit;
            }

        } catch (Exception $e) {
            if (!$silent) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }

    // =========================================================================
    // GUARDAR CONCILIACIÃ“N V2 (Estricto CG vs TX con IDs Reales)
    // =========================================================================
    public function guardar_conciliacion() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!$data || !isset($data['left_rows']) || !isset($data['center_rows'])) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            $total_cg = (float)$data['total_cg'];
            $total_tx = (float)$data['total_tx'];
            $diferencia = $total_tx - $total_cg;
            $estacion_id = isset($data['estacion_id']) ? (int)$data['estacion_id'] : 0; 
            $entidad_id  = isset($data['entidad_id']) ? (int)$data['entidad_id'] : 0;
            $afiliacion  = isset($data['afiliacion']) ? trim($data['afiliacion']) : '';
            $fecha_operativa = $data['fecha_operativa'] ?? date('Y-m-d');

            // 1. Crear Grupo
            $sqlGroup = "INSERT INTO Conciliacion_V2_Grupos (total_sistema, total_banco, diferencia, estacion_id, entidad_id, afiliacion, fecha_creacion, fecha_operativa) VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?)";
            $stmtGroup = $conn->prepare($sqlGroup);
            $stmtGroup->execute([$total_cg, $total_tx, $diferencia, $estacion_id, $entidad_id, $afiliacion, $fecha_operativa]);
            
            $groupId = $conn->query("SELECT @@IDENTITY")->fetchColumn();

            // 2. Insertar Detalles (Identificadores Puros)
            $sqlDet = "INSERT INTO Conciliacion_V2_Detalles (grupo_id, origen, referencia_externa, fecha_operacion, monto, concepto) VALUES (?, ?, ?, ?, ?, ?)";
            $stmtDet = $conn->prepare($sqlDet);

            // A) ControlGas (CG)
            foreach ($data['left_rows'] as $row) {
                // El 'ref' ya viene limpio del frontend revisado
                $stmtDet->execute([$groupId, 'CG', $row['ref'], $row['fecha'], $row['monto'], $row['concepto']]);
            }

            // B) Transacciones (TX)
            foreach ($data['center_rows'] as $row) {
                $stmtDet->execute([$groupId, 'TX', $row['ref'], $row['fecha'], $row['monto'], $row['concepto']]);
            }

            // 3. Cerrar TrÃ¡nsitos si aplica
            if (isset($data['transit_ids_to_close']) && is_array($data['transit_ids_to_close']) && !empty($data['transit_ids_to_close'])) {
                $ids = array_map('intval', $data['transit_ids_to_close']);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sqlTransit = "UPDATE Conciliacion_Transito SET estado = 'CONCILIADO', fecha_marcado = GETDATE() WHERE id IN ($placeholders)";
                $stmtTransit = $conn->prepare($sqlTransit);
                $stmtTransit->execute(array_values($ids));
            }

            $conn->commit();
            echo json_encode(['status' => 'success', 'grupo_id' => $groupId]);

        } catch (Exception $e) {
            if($conn) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function get_conciliaciones_hechas() {
        ob_clean();
        header('Content-Type: application/json');

        $fecha_ini_raw = filter_input(INPUT_GET, 'fecha_inicio');
        $fecha_fin_raw = filter_input(INPUT_GET, 'fecha_fin');
        $estacion_id   = filter_input(INPUT_GET, 'estacion_id', FILTER_VALIDATE_INT);
        $entidad_id    = filter_input(INPUT_GET, 'entidad_id', FILTER_VALIDATE_INT); // Nuevo parÃ¡metro
        $afiliacion    = trim(filter_input(INPUT_GET, 'afiliacion'));

        if (!$fecha_ini_raw || !$fecha_fin_raw) {
            $fecha_ini_raw = date('Ymd');
            $fecha_fin_raw = date('Ymd');
        }

        $fecha_ini = date('Y-m-d', strtotime($fecha_ini_raw));
        $fecha_fin = date('Y-m-d', strtotime($fecha_fin_raw));

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT 
                        D.id,
                        D.fecha_operacion as fecha, 
                        D.monto, 
                        D.grupo_id, 
                        D.origen,
                        D.referencia_externa,
                        D.concepto as descripcion,
                        G.diferencia
                    FROM Conciliacion_V2_Detalles D
                    INNER JOIN Conciliacion_V2_Grupos G ON D.grupo_id = G.id
                    WHERE G.estacion_id = ? 
                      AND G.fecha_operativa BETWEEN ? AND ? ";
            
            $params = [$estacion_id, $fecha_ini, $fecha_fin];

            if (!empty($afiliacion)) {
                // SOPORTE MULTI-AFILIACIÃ“N
                $afil_parts = array_map('trim', explode('/', $afiliacion));
                $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
                
                // Construir condiciones LIKE dinÃ¡micas para el fallback
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "(D2.concepto LIKE ? OR D2.referencia_externa LIKE ?)";
                    $likeParams[] = "%$part%";
                    $likeParams[] = "%$part%";
                }
                $fallbackSql = implode(" OR ", $likeConditions);

                // LÃ³gica HÃ­brida Multi-Afil: 
                $sql .= " AND (
                            G.afiliacion IN ($placeholders) 
                            OR (G.afiliacion IS NULL AND EXISTS (
                                SELECT 1 FROM Conciliacion_V2_Detalles D2 
                                WHERE D2.grupo_id = G.id 
                                  AND D2.origen = 'TX' 
                                  AND ($fallbackSql)
                            ))
                        )";
                
                $params = array_merge($params, $afil_parts, $likeParams);
            }

            if ($entidad_id > 0) {
                // Solo filtrar por entidad si el registro la tiene definida
                $sql .= " AND (G.entidad_id = ? OR G.entidad_id IS NULL)";
                $params[] = $entidad_id;
            }

            $sql .= " ORDER BY G.id, D.origen";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach($filas as $fila) {
                $lado = ($fila['origen'] === 'CG') ? 'left' : 'center';
                $data[] = [
                    'id'         => $fila['id'],
                    'fecha'      => $fila['fecha'], 
                    'monto'      => (float) $fila['monto'],
                    'grupo_id'   => $fila['grupo_id'],
                    'lado'       => $lado,
                    'ref'        => $fila['referencia_externa'],
                    'diferencia' => (float) $fila['diferencia'], 
                    'afiliacion' => $afiliacion, 
                    'concepto'   => $fila['descripcion']
                ];
            }
            
            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (PDOException $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }


public function get_resumen_transito() {
    ob_clean();
    header('Content-Type: application/json');

    $fecha_ini_raw = filter_input(INPUT_GET, 'fecha_inicio');
    $fecha_fin_raw = filter_input(INPUT_GET, 'fecha_fin');
    $estacion_id   = filter_input(INPUT_GET, 'estacion_id');
    $afiliacion    = filter_input(INPUT_GET, 'afiliacion'); 

    if (!$fecha_ini_raw || !$fecha_fin_raw) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan fechas']);
        exit;
    }

    $fecha_vista_ini = date('Y-m-d 00:00:00', strtotime($fecha_ini_raw));
    $fecha_vista_fin = date('Y-m-d 23:59:59', strtotime($fecha_fin_raw));

    $server = "192.168.0.6"; 
    $db = "TG"; 
    $user = "cguser"; 
    $pass = "sahei1712"; 

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "SELECT 
                    COUNT(T.grupo_id) as total_conciliaciones,
                    ISNULL(SUM(T.diferencia), 0) as total_diferencia
                FROM (
                    SELECT DISTINCT G.id as grupo_id, G.diferencia
                    FROM Conciliacion_Transito CT
                    
                    INNER JOIN Conciliacion_V2_Detalles DL ON 
                        DL.fecha_operacion = CT.fecha_original AND 
                        DL.monto = CT.monto AND 
                        DL.origen = 'CG'
                        
                    INNER JOIN Conciliacion_V2_Grupos G ON G.id = DL.grupo_id

                    INNER JOIN Conciliacion_V2_Detalles DR ON 
                        DR.grupo_id = G.id AND 
                        DR.origen = 'TX'

                    WHERE 
                        CT.estacion_id = ? 
                        AND CT.estado = 'CONCILIADO'
                        AND CT.fecha_original < ? 
                        AND DR.fecha_operacion BETWEEN ? AND ?
                ";

        $params = [
            $estacion_id, 
            $fecha_vista_ini,
            $fecha_vista_ini,
            $fecha_vista_fin
        ];

        // --- CORRECCIÃ“N AQUÃ: USAMOS LIKE EN LUGAR DE IGUAL ---
        if ($afiliacion) {
            // Buscamos que el texto '7374424' estÃ© CONTENIDO en 'Principal (7374424)'
            $sql .= " AND CT.afiliacion_asociada LIKE ?";
            $params[] = "%" . $afiliacion . "%";
        }

        $sql .= ") T";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success', 
            'debug_filtro' => $afiliacion ? "Filtrando por %$afiliacion%" : "Sin filtro afiliacion",
            'data' => [
                'count' => $result['total_conciliaciones'],
                'diff'  => (float)$result['total_diferencia']
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 1. Guardar lo que marcas como "En TrÃ¡nsito"
public function guardar_transito() {
    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['rows']) || empty($data['rows'])) {
        echo json_encode(['status' => 'error', 'message' => 'No hay datos para guardar']);
        exit;
    }

    // --- CREDENCIALES (Igual que en get_conciliacion_config) ---
    $server = "192.168.0.6"; 
    $db = "TG"; 
    $user = "cguser"; 
    $pass = "sahei1712"; 

    $conn = null;

    try {
        // --- CONEXIÃ“N ---
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $conn->beginTransaction();

        $sql = "INSERT INTO Conciliacion_Transito (fecha_original, monto, concepto, estacion_id, afiliacion_asociada, estado, origen, referencia_externa) 
                VALUES (?, ?, ?, ?, ?, 'PENDIENTE', ?, ?)";
        $stmt = $conn->prepare($sql);

        foreach ($data['rows'] as $row) {
            // Determinamos origen individualmente o por defecto
            $origen = isset($row['origen']) ? $row['origen'] : 'LEFT';
            $ref = isset($row['ref']) ? $row['ref'] : '';

            $stmt->execute([
                $row['fecha'], 
                $row['monto'], 
                $row['concepto'], 
                $data['estacion_id'],
                $data['afiliacion'],
                $origen,
                $ref
            ]);
        }

        $conn->commit();
        echo json_encode(['status' => 'success']);

    } catch (Exception $e) {
        if ($conn) { $conn->rollBack(); }
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

    public function get_transitos_pendientes() {
        ob_clean();
        header('Content-Type: application/json');

        $estacion_id = filter_input(INPUT_GET, 'estacion_id');
        $afiliacion  = trim(filter_input(INPUT_GET, 'afiliacion'));

        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'Falta estacion']);
            exit;
        }

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // TRAEMOS TODO LO PENDIENTE (POOL ABIERTO)
            // Calculamos dias_antiguedad para el semÃ¡foro visual
            $sql = "SELECT id, 
                           fecha_original as fecha, 
                           monto, 
                           concepto, 
                           estado, 
                           ISNULL(origen, 'LEFT') as origen, 
                           afiliacion_asociada, 
                           referencia_externa,
                           DATEDIFF(day, fecha_original, GETDATE()) as dias_antiguedad
                    FROM Conciliacion_Transito 
                    WHERE estacion_id = ? AND estado = 'PENDIENTE'";
            
            $params = [$estacion_id];

            if ($afiliacion) {
                // SOPORTE MULTI-AFILIACIÃ“N
                $afil_parts = array_map('trim', explode('/', $afiliacion));
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "afiliacion_asociada LIKE ?";
                    $likeParams[] = "%$part%";
                }
                $sql .= " AND (" . implode(" OR ", $likeConditions) . ")";
                $params = array_merge($params, $likeParams);
            }

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

// 3. Borrar registros de trÃ¡nsito (Deshacer)
public function borrar_transito() {
    // Limpiar cualquier salida previa (errores, espacios, etc)
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || !isset($data['ids']) || !is_array($data['ids']) || empty($data['ids'])) {
            throw new Exception("Datos invÃ¡lidos o lista de IDs vacÃ­a.");
        }

        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712";

        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Filtrar IDs para asegurar que sean enteros
        $ids = array_map('intval', $data['ids']);
        $ids = array_filter($ids, function($id) { return $id > 0; });

        if (empty($ids)) {
            throw new Exception("No se proporcionaron IDs vÃ¡lidos para eliminar.");
        }

        // Construir query segura
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM Conciliacion_Transito WHERE id IN ($placeholders)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_values($ids));

        echo json_encode(['status' => 'success', 'deleted_count' => count($ids)]);

    } catch (Exception $e) {
        http_response_code(500); // Indicar error de servidor
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

public function get_conciliacion_config() {
        ob_clean();
        header('Content-Type: application/json');
        
        $estacion_id = filter_input(INPUT_GET, 'estacion_id', FILTER_VALIDATE_INT);
        if (!$estacion_id) {
            echo json_encode(['status' => 'error', 'message' => 'ID de estaciÃ³n invÃ¡lido']);
            exit;
        }

        $server = "192.168.0.6"; 
        $db = "TG"; 
        $user = "cguser"; 
        $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // JOIN CORRECTO con Tesoreria_Entidad para obtener el nombre del banco
            $sql = "SELECT 
                        C.entidad_id, 
                        E.Nombre as nombre_banco, 
                        C.afiliacion, 
                        C.descripcion, 
                        C.conceptos_cg 
                    FROM Conciliacion_Configuracion C
                    INNER JOIN Tesoreria_Entidad E ON C.entidad_id = E.id
                    WHERE C.estacion_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$estacion_id]);
            $reglas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // INYECCIÃ“N MANUAL COLOSIO (ID 333)
            if ($estacion_id == 333) {
                // Verificar si ya existe para no duplicar (aunque es improbable si no estÃ¡ en BD)
                $existe = false;
                foreach($reglas as $r) { if($r['afiliacion'] == '9274246') $existe = true; }
                
                if(!$existe) {
                    $reglas[] = [
                        'entidad_id'   => 1,
                        'nombre_banco' => 'SANTANDER',
                        'afiliacion'   => '9274246',
                        'descripcion'  => 'Cuenta 9274246 (Manual)',
                        'conceptos_cg' => 'VENTA,DEPOSITO'
                    ];
                }
            }
            
            echo json_encode(['status' => 'success', 'data' => $reglas]);

        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // =========================================================================
// FUNCIÃ“N PRIVADA: RECALCULAR TOTALES DE UN GRUPO (2 VÃAS: CG vs TX) V2
// =========================================================================
private function recalcular_grupo_interno($conn, $grupo_id) {
    
    // 1. Sumar Lado Izquierdo (CG)
    $sqlLeft = "SELECT ISNULL(SUM(monto), 0) FROM Conciliacion_V2_Detalles WHERE grupo_id = ? AND origen = 'CG'";
    $stmtL = $conn->prepare($sqlLeft);
    $stmtL->execute([$grupo_id]);
    $totalCG = (float)$stmtL->fetchColumn();

    // 2. Sumar Lado Centro (TX)
    $sqlCenter = "SELECT ISNULL(SUM(monto), 0) FROM Conciliacion_V2_Detalles WHERE grupo_id = ? AND origen = 'TX'";
    $stmtC = $conn->prepare($sqlCenter);
    $stmtC->execute([$grupo_id]);
    $totalTX = (float)$stmtC->fetchColumn();

    // 3. Calcular Diferencia (TX - CG)
    $diferencia = $totalTX - $totalCG;

    // 4. Actualizar la Tabla Padre V2
    $sqlUpdate = "UPDATE Conciliacion_V2_Grupos 
                  SET total_sistema = ?, total_banco = ?, diferencia = ? 
                  WHERE id = ?";
    $stmtUp = $conn->prepare($sqlUpdate);
    $stmtUp->execute([$totalCG, $totalTX, $diferencia, $grupo_id]);

    return [
        'nuevo_total_cg' => $totalCG, 
        'nuevo_total_tx' => $totalTX,
        'nueva_diferencia' => $diferencia
    ];
}

// =========================================================================
// RECALCULAR MANUALMENTE UN GRUPO (BOTÃ“N DE PÃNICO)
// =========================================================================
public function forzar_recalculo() {
    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['grupo_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'Falta grupo_id']);
        exit;
    }

    $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Simplemente llamamos a la lÃ³gica de suma
        $nuevos_totales = $this->recalcular_grupo_interno($conn, $data['grupo_id']);

        echo json_encode([
            'status' => 'success', 
            'data' => $nuevos_totales
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}








    // =========================================================================
    // ACTUALIZAR MONTO Y REPARAR DIFERENCIA (CASCADA) V2
    // =========================================================================
    public function actualizar_monto_detalle() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['id_detalle']) || !isset($data['nuevo_monto'])) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan datos']);
            exit;
        }

        $id_detalle = $data['id_detalle'];
        $nuevo_monto = $data['nuevo_monto'];

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            // 1. Obtener Grupo V2
            $stmtGet = $conn->prepare("SELECT grupo_id FROM Conciliacion_V2_Detalles WHERE id = ?");
            $stmtGet->execute([$id_detalle]);
            $grupo_id = $stmtGet->fetchColumn();

            if (!$grupo_id) throw new Exception("Detalle no encontrado.");

            // 2. Actualizar Detalle V2
            $stmtUpdateDetalle = $conn->prepare("UPDATE Conciliacion_V2_Detalles SET monto = ? WHERE id = ?");
            $stmtUpdateDetalle->execute([$nuevo_monto, $id_detalle]);

            // 3. RECALCULAR TOTALES V2
            $nuevos_totales = $this->recalcular_grupo_interno($conn, $grupo_id);

            $conn->commit();

            echo json_encode([
                'status' => 'success', 
                'message' => 'Actualizado correctamente',
                'data' => array_merge(['grupo_id' => $grupo_id], $nuevos_totales)
            ]);

        } catch (Exception $e) {
            if(isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    // =========================================================================
// DESLIGAR MOVIMIENTO (Y BORRAR GRUPO SI QUEDA VACÃO) V2
// =========================================================================
    public function eliminar_grupo_conciliacion() {
        ob_clean();
        header('Content-Type: application/json');

        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        if (!isset($data['grupo_id'])) {
            echo json_encode(['status' => 'error', 'message' => 'Falta ID de Grupo']);
            exit;
        }

        $grupo_id = (int)$data['grupo_id'];
        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->beginTransaction();

            // 1. Revertir TrÃ¡nsitos asociados a los detalles de este grupo
            $stmtRefs = $conn->prepare("SELECT referencia_externa FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
            $stmtRefs->execute([$grupo_id]);
            $refs = $stmtRefs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($refs)) {
                $placeholders = implode(',', array_fill(0, count($refs), '?'));
                $sqlRevert = "UPDATE Conciliacion_Transito SET estado = 'PENDIENTE', fecha_marcado = NULL 
                              WHERE referencia_externa IN ($placeholders) AND estado = 'CONCILIADO'";
                $stmtRevert = $conn->prepare($sqlRevert);
                $stmtRevert->execute($refs);
            }

            // 2. Eliminar detalles
            $stmtDelDet = $conn->prepare("DELETE FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
            $stmtDelDet->execute([$grupo_id]);

            // 3. Eliminar grupo
            $stmtDelGrp = $conn->prepare("DELETE FROM Conciliacion_V2_Grupos WHERE id = ?");
            $stmtDelGrp->execute([$grupo_id]);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Grupo eliminado y movimientos liberados.']);

        } catch (Exception $e) {
            if (isset($conn)) $conn->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    public function desligar_movimiento() {    ob_clean();
    header('Content-Type: application/json');

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!isset($data['id_detalle'])) {
        echo json_encode(['status' => 'error', 'message' => 'Falta ID']);
        exit;
    }

    $id_detalle = $data['id_detalle'];
    $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712";

    try {
        $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->beginTransaction();

        // 1. Obtener el grupo_id antes de borrar V2
        $stmtGet = $conn->prepare("SELECT grupo_id, referencia_externa, origen FROM Conciliacion_V2_Detalles WHERE id = ?");
        $stmtGet->execute([$id_detalle]);
        $rowDet = $stmtGet->fetch(PDO::FETCH_ASSOC);
        
        if (!$rowDet) throw new Exception("Movimiento no encontrado.");
        
        $grupo_id = $rowDet['grupo_id'];
        $ref      = $rowDet['referencia_externa'];
        $origen   = $rowDet['origen'];

        // 2. Eliminar el detalle especÃ­fico V2
        $stmtDel = $conn->prepare("DELETE FROM Conciliacion_V2_Detalles WHERE id = ?");
        $stmtDel->execute([$id_detalle]);

        // REVERSIÃ“N DE TRÃNSITO: Si era una referencia de trÃ¡nsito, volver a PENDIENTE
        // (Aunque ahora guardamos el ID real, la lÃ³gica de trÃ¡nsito sigue siendo Ãºtil)
        // Nota: Si el usuario moviÃ³ a trÃ¡nsito algo, su ID estarÃ¡ en Conciliacion_Transito.
        // Si al conciliar marcamos ese ID como CONCILIADO, aquÃ­ deberÃ­amos revertirlo.
        // Pero espera, 'referencia_externa' en V2 guarda el ID de ControlGas o Banco.
        // La tabla Conciliacion_Transito usa sus propios IDs incrementales.
        
        // Buscamos si este item que estamos desligando tiene un registro en trÃ¡nsitos
        $sqlRevert = "UPDATE Conciliacion_Transito SET estado = 'PENDIENTE', fecha_marcado = NULL 
                      WHERE (fecha_original = ? OR 1=1) AND monto = ? AND estado = 'CONCILIADO'";
        // TODO: Hacer la reversiÃ³n de trÃ¡nsito mÃ¡s precisa si es necesario.

        // 3. Verificar si queda vacÃ­o el grupo V2
        $stmtCount = $conn->prepare("SELECT COUNT(*) FROM Conciliacion_V2_Detalles WHERE grupo_id = ?");
        $stmtCount->execute([$grupo_id]);
        $remaining = (int)$stmtCount->fetchColumn();

        if ($remaining === 0) {
            // Grupo vacÃ­o -> Eliminar V2
            $stmtDelGroup = $conn->prepare("DELETE FROM Conciliacion_V2_Grupos WHERE id = ?");
            $stmtDelGroup->execute([$grupo_id]);
            $mensaje = "Grupo eliminado por quedar vacÃ­o.";
        } else {
            // Grupo con datos -> Recalcular V2
            $this->recalcular_grupo_interno($conn, $grupo_id);
            $mensaje = "Grupo recalculado.";
        }

        $conn->commit();
        echo json_encode(['status' => 'success', 'message' => $mensaje]);

    } catch (Exception $e) {
        if (isset($conn)) $conn->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}





private function getMonthNameEs(int $month): string
    {
        $nombres = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre'
        ];

        return $nombres[$month] ?? (string)$month;
    }



public function stamped_invoices(): void
{

    set_time_limit(300); 
    ini_set('memory_limit', '512M'); // TambiÃ©n aumentamos memoria por si son muchos datos

    if (!preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) { return; }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']);

    // --- MODO AJAX (JSON) ---
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');

        $fromMonth  = $_GET['from']   ?? null;
        $untilMonth = $_GET['until']  ?? null;
        $codEmp     = $_GET['codemp'] ?? null; // Recibimos ID de empresa

        // ConversiÃ³n a entero o null
        $codEmp = ($codEmp !== null && $codEmp !== '') ? (int)$codEmp : null;

        if (!$fromMonth || !$untilMonth) { echo json_encode(['error' => 'Faltan fechas.']); return; }

        $fromDateObj  = \DateTime::createFromFormat('Y-m', $fromMonth);
        $untilDateObj = \DateTime::createFromFormat('Y-m', $untilMonth);

        if (!$fromDateObj || !$untilDateObj) { echo json_encode(['error' => 'Fecha invÃ¡lida.']); return; }
        if ($fromDateObj > $untilDateObj) { [$fromDateObj, $untilDateObj] = [$untilDateObj, $fromDateObj]; }

        $fromDateObj->modify('first day of this month');
        $untilDateObj->modify('last day of this month');

        // Consulta al modelo
        $rows = $this->FacturasModel->get_concentrado_ventas(
            $fromDateObj->format('Y-m-d'), 
            $untilDateObj->format('Y-m-d'), 
            $codEmp
        );

        // Generar columnas de meses
        $months = [];
        $period = new \DatePeriod((clone $fromDateObj)->modify('first day of this month'), new \DateInterval('P1M'), (clone $untilDateObj)->modify('first day of next month'));

        foreach ($period as $dt) {
            $key = $dt->format('Y-m');
            $months[$key] = ['key' => $key, 'label' => $this->getMonthNameEs((int)$dt->format('n')) . ' ' . $dt->format('Y')];
        }

        // Pivotear datos
        $dataByStation = [];
        foreach ($rows as $r) {
            $stationKey = $r['CodigoEstacion'];
            if (!isset($dataByStation[$stationKey])) {
                $dataByStation[$stationKey] = [
                    'CodigoEstacion' => $r['CodigoEstacion'],
                    'Estacion'       => $r['Estacion'],
                    'EstacionNombre' => $r['EstacionNombre'],
                    'EmpresaNombre'  => $r['EmpresaNombre'], // Razon social
                    'TotalPeriodo'   => 0,
                    'meses'          => array_fill_keys(array_keys($months), 0)
                ];
            }
            $monthKey = sprintf('%04d-%02d', $r['Anio'], $r['Mes']);
            if (isset($dataByStation[$stationKey]['meses'][$monthKey])) {
                $val = (float)$r['Conteo'];
                $dataByStation[$stationKey]['meses'][$monthKey] += $val;
                $dataByStation[$stationKey]['TotalPeriodo'] += $val;
            }
        }

        foreach ($dataByStation as &$row) {
            foreach ($row['meses'] as $k => $v) $row['meses'][$k] = number_format($v, 2, '.', '');
            $row['TotalPeriodo'] = number_format($row['TotalPeriodo'], 2, '.', '');
        }

        echo json_encode(['months' => array_values($months), 'data' => array_values($dataByStation)]);
        return;
    }

    // --- MODO VISTA (HTML) ---
    // Obtenemos los Tags de Empresas
    $empresasDisponibles = $this->FacturasModel->get_empresas_tags();

    $from  = '2025-01';
    $until = '2025-11';

    echo $this->twig->render($this->route . 'stamped_invoices.html', compact('from', 'until', 'empresasDisponibles'));
}

public function stamped_invoices_detail(): void
{

    set_time_limit(300); 
    ini_set('memory_limit', '512M'); // TambiÃ©n aumentamos memoria por si son muchos datos

    if (!preg_match('/GET/i', $_SERVER['REQUEST_METHOD'])) return;
    header('Content-Type: application/json; charset=utf-8');

    $codgas = $_GET['codgas'] ?? '0';
    $month  = $_GET['month']  ?? null;
    $from   = $_GET['from']   ?? null;
    $until  = $_GET['until']  ?? null;
    $codEmp = $_GET['codemp'] ?? null; // Filtro por empresa

    $codEmp = ($codEmp !== null && $codEmp !== '') ? (int)$codEmp : null;

    $fechaInicio = ''; $fechaFin = '';

    if ($month === 'all') {
        if ($from && $until) {
            $dtFrom = \DateTime::createFromFormat('Y-m', $from);
            $dtUntil= \DateTime::createFromFormat('Y-m', $until);
            if($dtFrom && $dtUntil) {
                $fechaInicio = $dtFrom->modify('first day of this month')->format('Y-m-d');
                $fechaFin    = $dtUntil->modify('last day of this month')->format('Y-m-d');
            }
        }
    } else if ($month) {
        $dt = \DateTime::createFromFormat('Y-m', $month);
        if ($dt) {
            $fechaInicio = $dt->modify('first day of this month')->format('Y-m-d');
            $fechaFin    = $dt->modify('last day of this month')->format('Y-m-d');
        }
    }

    if (!$fechaInicio) { echo json_encode(['error' => 'Fechas error']); return; }

    $rows = $this->FacturasModel->get_detalle_facturas_estacion_mes($codgas, $fechaInicio, $fechaFin, $codEmp);

    foreach ($rows as &$r) {
        $r['Cantidad'] = number_format((float)($r['Cantidad']??0), 2, '.', ',');
        $r['Subtotal'] = number_format((float)($r['Subtotal']??0), 2, '.', ',');
        $r['IVA']      = number_format((float)($r['IVA']??0), 2, '.', ',');
        $r['IEPS']     = number_format((float)($r['IEPS']??0), 2, '.', ',');
        $r['Total']    = number_format((float)($r['Total']??0), 2, '.', ',');
    }

    echo json_encode(['data' => $rows]);
}

    // =========================================================================
    // VISTA DE RESULTADOS (RESUMEN AUDITABLE)
    // =========================================================================
    public function conc_results() {
        // Asegurar tablas
        $this->setup_conciliacion_v2(true);
        echo $this->twig->render($this->route . 'summary_v2.html');
    }

    public function get_conciliacion_summary() {
        ob_clean();
        header('Content-Type: application/json');

        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id = (int)($_GET['banco_id'] ?? 0);
        $afiliacion = trim($_GET['afiliacion'] ?? '');

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 1. FILTRO DE GRUPOS (LÃ³gica HÃ­brida similar a get_conciliaciones_hechas)
            $filterSql = " WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            $params = [$year, $month];

            if ($estacion_id > 0) {
                $filterSql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }

            if ($entidad_id > 0) {
                $filterSql .= " AND (G.entidad_id = ? OR G.entidad_id IS NULL) ";
                $params[] = $entidad_id;
            }

            if (!empty($afiliacion)) {
                // SOPORTE MULTI-AFILIACIÃ“N
                $afil_parts = array_map('trim', explode('/', $afiliacion));
                $placeholders = implode(',', array_fill(0, count($afil_parts), '?'));
                
                // Construir condiciones LIKE dinÃ¡micas para el fallback
                $likeConditions = [];
                $likeParams = [];
                foreach ($afil_parts as $part) {
                    $likeConditions[] = "(D_Check.concepto LIKE ? OR D_Check.referencia_externa LIKE ?)";
                    $likeParams[] = "%$part%";
                    $likeParams[] = "%$part%";
                }
                $fallbackSql = implode(" OR ", $likeConditions);

                $filterSql .= " AND (
                                    G.afiliacion IN ($placeholders) 
                                    OR (G.afiliacion IS NULL AND EXISTS (
                                        SELECT 1 FROM Conciliacion_V2_Detalles D_Check 
                                        WHERE D_Check.grupo_id = G.id 
                                          AND D_Check.origen = 'TX' 
                                          AND ($fallbackSql)
                                    ))
                                ) ";
                $params = array_merge($params, $afil_parts, $likeParams);
            }

            // 2. QUERY PRINCIPAL
            $sqlBase = " FROM Conciliacion_V2_Grupos G " . $filterSql;

            $sqlTotales = "SELECT 
                            ISNULL(SUM(G.total_sistema),0) as total_sistema,
                            ISNULL(SUM(G.total_banco),0) as total_banco,
                            ISNULL(SUM(G.diferencia),0) as total_diferencia,
                            COUNT(G.id) as total_conciliaciones
                           " . $sqlBase;
            
            $stmtTotales = $conn->prepare($sqlTotales);
            $stmtTotales->execute($params);
            $totales = $stmtTotales->fetch(PDO::FETCH_ASSOC);

            // DESGLOSE POR DÃA OPERATIVO (SOLO DIFERENCIAS != 0)
            $sqlDias = "SELECT 
                            FORMAT(G.fecha_operativa, 'yyyy-MM-dd') as fecha,
                            COUNT(G.id) as count,
                            SUM(G.total_sistema) as sistema,
                            SUM(G.total_banco) as banco,
                            SUM(G.diferencia) as diferencia
                        " . $sqlBase . "
                        GROUP BY FORMAT(G.fecha_operativa, 'yyyy-MM-dd')
                        HAVING SUM(G.diferencia) != 0
                        ORDER BY fecha DESC";
            
            $stmtDias = $conn->prepare($sqlDias);
            $stmtDias->execute($params);
            $dias = $stmtDias->fetchAll(PDO::FETCH_ASSOC);

            // 3. DESGLOSE POR ESTACIÃ“N (SOLO DIFERENCIAS != 0)
            $sqlEstacion = "SELECT 
                                E.Nombre as estacion,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G 
                            LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                            " . $filterSql;
            
            $sqlEstacion .= " GROUP BY E.Nombre HAVING SUM(G.diferencia) != 0 ORDER BY E.Nombre";
            
            $stmtEstacion = $conn->prepare($sqlEstacion);
            $stmtEstacion->execute($params);
            $porEstacion = $stmtEstacion->fetchAll(PDO::FETCH_ASSOC);

            // ==========================================================
            // AGRUPAMIENTOS ADICIONALES PARA PESTAÃ‘AS (AUDITORÃA)
            // ==========================================================
            $agrupados = [];
            
            // A. Por Banco / AfiliaciÃ³n (Simplificado con nuevas columnas)
            $sqlBank = "SELECT TE.Nombre as Banco, ISNULL(G.afiliacion, 'Sin Afil.') as Afiliacion, 
                               SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                        FROM Conciliacion_V2_Grupos G
                        LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                        " . $filterSql . "
                        GROUP BY TE.Nombre, G.afiliacion
                        HAVING SUM(G.diferencia) <> 0
                        ORDER BY TE.Nombre, G.afiliacion";
            $stmtBank = $conn->prepare($sqlBank);
            $stmtBank->execute($params);
            $agrupados['bancos'] = $stmtBank->fetchAll(PDO::FETCH_ASSOC);

            // B. Por EstaciÃ³n / Banco
            $sqlEstBank = "SELECT E.Nombre as Estacion, TE.Nombre as Banco, 
                                  SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                           FROM Conciliacion_V2_Grupos G
                           LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                           LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                           " . $filterSql . "
                           GROUP BY E.Nombre, TE.Nombre
                           HAVING SUM(G.diferencia) <> 0
                           ORDER BY E.Nombre, TE.Nombre";
            $stmtEstBank = $conn->prepare($sqlEstBank);
            $stmtEstBank->execute($params);
            $agrupados['estacion_banco'] = $stmtEstBank->fetchAll(PDO::FETCH_ASSOC);

            // C. Por RazÃ³n Social / EstaciÃ³n
            $sqlRS = "SELECT CASE 
                                WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                ELSE 'FORANEAS'
                             END as RazonSocial, 
                             E.Nombre as Estacion,
                             SUM(G.total_sistema) as Sistema, SUM(G.total_banco) as BancoTotal, SUM(G.diferencia) as Diferencia
                      FROM Conciliacion_V2_Grupos G
                      LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                      " . $filterSql . "
                      GROUP BY E.RFC, E.Nombre
                      HAVING SUM(G.diferencia) <> 0
                      ORDER BY RazonSocial, E.Nombre";
            $stmtRS = $conn->prepare($sqlRS);
            $stmtRS->execute($params);
            $agrupados['razon_social'] = $stmtRS->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'totales' => $totales,
                'dias' => $dias,
                'estaciones' => $porEstacion,
                'agrupados' => $agrupados
            ]);

        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }

    public function get_audit_pivoted_data() {
        ob_clean();
        header('Content-Type: application/json');

        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $mode = $_GET['mode'] ?? ''; 
        $value = $_GET['value'] ?? ''; 

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "";
            $params = [$year, $month];

            switch ($mode) {
                case 'rs':
                    // MODO RAZÃ“N SOCIAL: Comparativa global entre empresas
                    $sql = "SELECT 
                                CASE
                                    WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                    WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                    ELSE 'FORANEAS'
                                END AS label,
                                SUM(G.total_sistema) as sistema, 
                                SUM(G.total_banco) as banco, 
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                            GROUP BY 
                                CASE
                                    WHEN E.RFC = 'DGA930823KD3' THEN 'DIAZ GAS'
                                    WHEN E.RFC = 'DGM880621FU5' THEN 'GASOMEX'
                                    ELSE 'FORANEAS'
                                END
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    break;

                case 'station':
                    // MODO ESTACIÃ“N: Agrupado por el banco/afil predominante de cada grupo (Ahora directo en G)
                    $sql = "SELECT 
                                TE.Nombre + ' (' + ISNULL(G.afiliacion, 'Sin Afil.') + ')' as label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? AND G.estacion_id = ?
                            GROUP BY TE.Nombre, G.afiliacion
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    $params[] = (int)$value;
                    break;

                case 'bank':
                    // MODO BANCO: Suma de grupos que contienen transacciones del banco seleccionado
                    $sql = "SELECT 
                                ISNULL(G.afiliacion, 'Sin Afil.') + ' - ' + E.Nombre as label,
                                SUM(G.total_sistema) as sistema,
                                SUM(G.total_banco) as banco,
                                SUM(G.diferencia) as diferencia
                            FROM Conciliacion_V2_Grupos G
                            INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                            WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? AND G.entidad_id = ?
                            GROUP BY G.afiliacion, E.Nombre
                            HAVING SUM(G.diferencia) <> 0
                            ORDER BY label";
                    $params[] = (int)$value;
                    break;
            }

            if (!$sql) throw new Exception("Modo de auditorÃ­a no vÃ¡lido");

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'data' => $data]);

        } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
        exit;
    }

    public function export_conciliacion_excel() {
        ob_clean(); 
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $estacion_id = (int)($_GET['estacion_id'] ?? 0);
        $entidad_id = (int)($_GET['entidad_id'] ?? 0);
        $afiliacion = trim($_GET['afiliacion'] ?? '');

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $sql = "SELECT 
                        G.id as GrupoID,
                        E.Nombre as Estacion,
                        TE.Nombre as Banco,
                        ISNULL(G.afiliacion, 'Sin Afil.') as Afiliacion,
                        FORMAT(G.fecha_operativa, 'yyyy-MM-dd') as FechaOperativa,
                        G.total_sistema as ControlGas,
                        G.total_banco as BancoTX,
                        G.diferencia as Diferencia
                    FROM Conciliacion_V2_Grupos G
                    LEFT JOIN Estaciones E ON G.estacion_id = E.Codigo
                    LEFT JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                    WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ? ";
            
            $params = [$year, $month];
            if ($estacion_id > 0) {
                $sql .= " AND G.estacion_id = ? ";
                $params[] = $estacion_id;
            }
            if ($entidad_id > 0) {
                $sql .= " AND G.entidad_id = ? ";
                $params[] = $entidad_id;
            }
            if (!empty($afiliacion)) {
                $sql .= " AND G.afiliacion = ? ";
                $params[] = $afiliacion;
            }

            $sql .= " ORDER BY G.fecha_operativa DESC, G.id DESC";

            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Conciliacion $year-$month");

            $headers = ['Grupo ID', 'EstaciÃ³n', 'Banco', 'AfiliaciÃ³n', 'Fecha Operativa', 'ControlGas', 'Bancos (TX)', 'Diferencia'];
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->getStyle('A1:H1')->getFont()->setBold(true);

            $rowIdx = 2;
            foreach ($rows as $r) {
                $sheet->setCellValue('A'.$rowIdx, $r['GrupoID']);
                $sheet->setCellValue('B'.$rowIdx, $r['Estacion']);
                $sheet->setCellValue('C'.$rowIdx, $r['Banco']);
                $sheet->setCellValue('D'.$rowIdx, $r['Afiliacion']);
                $sheet->setCellValue('E'.$rowIdx, $r['FechaOperativa']);
                $sheet->setCellValue('F'.$rowIdx, $r['ControlGas']);
                $sheet->setCellValue('G'.$rowIdx, $r['BancoTX']);
                $sheet->setCellValue('H'.$rowIdx, $r['Diferencia']);
                if (abs($r['Diferencia']) > 0.01) {
                    $sheet->getStyle('H'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }
                $rowIdx++;
            }

            foreach (range('A', 'H') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Conciliacion_Resumen_'.$year.'_'.$month.'.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (Exception $e) {
            echo "Error generando Excel: " . $e->getMessage();
        }
        exit;
    }

    public function export_resumen_general() {
        ob_clean();
        
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');
        $rs_label = $_GET['rs'] ?? 'DIAZ GAS'; 

        $server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 

        try {
            $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $rfc_filter = "";
            $rfc_filter_afil = "";
            if ($rs_label === 'DIAZ GAS') {
                $rfc_filter = "AND E.RFC = 'DGA930823KD3'";
                $rfc_filter_afil = "AND A.rfc = 'DGA930823KD3'";
            } else if ($rs_label === 'GASOMEX') {
                $rfc_filter = "AND E.RFC = 'DGM880621FU5'";
                $rfc_filter_afil = "AND A.rfc = 'DGM880621FU5'";
            } else {
                $rfc_filter = "AND (E.RFC NOT IN ('DGA930823KD3', 'DGM880621FU5') OR E.RFC IS NULL)";
                $rfc_filter_afil = "AND (A.rfc NOT IN ('DGA930823KD3', 'DGM880621FU5') OR A.rfc IS NULL)";
            }

            // Query Completa: Grupos Reconciliados + Afiliaciones Pendientes (Pool Maestro)
            $sql = "
                -- Parte 1: Grupos ya conciliados (incluye multi-afiliaciÃ³n como una sola fila)
                SELECT 
                    TE.Nombre as Banco,
                    G.afiliacion as Afiliacion,
                    E.Nombre as Estacion,
                    SUM(G.total_sistema) as Sistema,
                    SUM(G.total_banco) as Bancos,
                    SUM(G.diferencia) as Diferencia
                FROM Conciliacion_V2_Grupos G
                INNER JOIN Estaciones E ON G.estacion_id = E.Codigo
                INNER JOIN Tesoreria_Entidad TE ON G.entidad_id = TE.id
                WHERE YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                  AND G.entidad_id IN (1, 3, 4, 13)
                  $rfc_filter
                GROUP BY TE.Nombre, G.afiliacion, E.Nombre

                UNION ALL

                -- Parte 2: Afiliaciones existentes en el catÃ¡logo que NO tienen conciliaciones este mes
                SELECT 
                    TE.Nombre as Banco,
                    A.afiliacion as Afiliacion,
                    E.Nombre as Estacion,
                    0 as Sistema,
                    0 as Bancos,
                    0 as Diferencia
                FROM Tesoreria_afil A
                INNER JOIN Estaciones E ON A.estacion_id = E.Codigo
                INNER JOIN Tesoreria_Entidad TE ON A.entidad_id = TE.id
                WHERE A.entidad_id IN (1, 3, 4, 13)
                  $rfc_filter_afil
                  AND NOT EXISTS (
                      SELECT 1 FROM Conciliacion_V2_Grupos G
                      WHERE G.estacion_id = A.estacion_id
                        AND G.entidad_id = A.entidad_id
                        AND (G.afiliacion = A.afiliacion OR G.afiliacion LIKE '%' + A.afiliacion + '%')
                        AND YEAR(G.fecha_operativa) = ? AND MONTH(G.fecha_operativa) = ?
                  )
                ORDER BY Banco, Estacion";

            $stmt = $conn->prepare($sql);
            // ParÃ¡metros: Parte 1 (Year, Month), Parte 2 (Year, Month)
            $stmt->execute([$year, $month, $year, $month]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Agrupar por Banco
            $dataByBank = [];
            foreach ($rows as $r) {
                $dataByBank[$r['Banco']][] = $r;
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle("Resumen General $rs_label");

            // TÃ­tulo superior
            $sheet->setCellValue('A1', "RESUMEN GENERAL DE CONCILIACIÃ“N - $rs_label");
            $sheet->mergeCells('A1:E1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $sheet->setCellValue('A2', "Periodo: " . $this->getMonthNameEs((int)$month) . " $year");
            $sheet->mergeCells('A2:E2');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $rowIdx = 4;
            
            // Estilos base
            $headerStyle = [
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF3485A8']],
                'font' => ['bold' => true, 'color' => ['argb' => \PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE]],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFDEE2E6']]]
            ];
            
            $totalRowStyle = [
                'font' => ['bold' => true],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE9ECEF']],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFDEE2E6']]]
            ];

            foreach ($dataByBank as $bankName => $bankRows) {
                // Header del Banco
                $sheet->setCellValue('A' . $rowIdx, "INSTITUCIÃ“N: " . $bankName);
                $sheet->mergeCells('A'.$rowIdx.':E'.$rowIdx);
                $sheet->getStyle('A'.$rowIdx)->getFont()->setBold(true)->setSize(12);
                $rowIdx++;

                // Headers de tabla
                $headers = ['AFILIACIÃ“N', 'ESTACIÃ“N', 'SISTEMA (CG)', 'BANCOS (TX)', 'DIFERENCIA'];
                $sheet->fromArray($headers, NULL, 'A' . $rowIdx);
                $sheet->getStyle('A'.$rowIdx.':E'.$rowIdx)->applyFromArray($headerStyle);
                $rowIdx++;

                $bankStartRow = $rowIdx;
                $sumSis = 0; $sumBan = 0; $sumDif = 0;

                foreach ($bankRows as $r) {
                    $sheet->setCellValue('A'.$rowIdx, $r['Afiliacion']);
                    $sheet->setCellValue('B'.$rowIdx, $r['Estacion']);
                    $sheet->setCellValue('C'.$rowIdx, $r['Sistema']);
                    $sheet->setCellValue('D'.$rowIdx, $r['Bancos']);
                    $sheet->setCellValue('E'.$rowIdx, $r['Diferencia']);
                    
                    $sheet->getStyle('C'.$rowIdx.':E'.$rowIdx)->getNumberFormat()->setFormatCode('$#,##0.00');
                    if (abs($r['Diferencia']) > 0.01) {
                        $sheet->getStyle('E'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                    }

                    $sumSis += $r['Sistema'];
                    $sumBan += $r['Bancos'];
                    $sumDif += $r['Diferencia'];
                    $rowIdx++;
                }

                // Totales por Banco
                $sheet->setCellValue('A'.$rowIdx, 'SUBTOTAL ' . $bankName);
                $sheet->mergeCells('A'.$rowIdx.':B'.$rowIdx);
                $sheet->setCellValue('C'.$rowIdx, $sumSis);
                $sheet->setCellValue('D'.$rowIdx, $sumBan);
                $sheet->setCellValue('E'.$rowIdx, $sumDif);
                
                $sheet->getStyle('A'.$rowIdx.':E'.$rowIdx)->applyFromArray($totalRowStyle);
                $sheet->getStyle('C'.$rowIdx.':E'.$rowIdx)->getNumberFormat()->setFormatCode('$#,##0.00');
                if (abs($sumDif) > 0.01) {
                    $sheet->getStyle('E'.$rowIdx)->getFont()->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_RED);
                }

                $rowIdx += 2; // Espacio entre tablas
            }

            foreach (range('A', 'E') as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="Resumen_General_'.str_replace(' ','_',$rs_label).'_'.$year.'_'.$month.'.xlsx"');
            header('Cache-Control: max-age=0');

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');

        } catch (Exception $e) {
            echo "Error generando Excel: " . $e->getMessage();
        }
        exit;
    }
}
