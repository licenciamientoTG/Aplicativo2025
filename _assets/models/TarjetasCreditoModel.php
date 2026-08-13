<?php

/**
 * Movimientos de tarjeta de crédito del módulo Tesorería
 * (/tesoreria/movimientos_bancos_cheques).
 * Tabla: TG.dbo.tarjetas_credito_movimientos (una fila por movimiento del
 * estado de cuenta —cargo o abono—; dedup por huella SHA1 con índice UNIQUE,
 * mismo patrón que MovimientosBancariosModel).
 * Schema: docs/sql/tarjetas_credito_schema.sql
 * Spec:   docs/superpowers/specs/2026-08-13-tesoreria-tarjetas-credito-design.md
 *
 * Un parser por banco, estático para poder probarlo por CLI:
 *   BANORTE (Empuje Negocio)  PDF de estado de cuenta, texto vía pdftotext
 *                             -layout (mismo binario empaquetado que
 *                             NotaCreditoPdfParser); bloques "Detalle de
 *                             movimientos del Titular" y "... del Adicional
 *                             {numero}", una tabla fecha/concepto/RFC/importe
 *                             por bloque; solo cargos, sin pagos en el mismo
 *                             listado.
 *   AMEX (Platinum/Aeroméxico) PDF de estado de cuenta, misma extracción vía
 *                             pdftotext -layout; dos tipos de bloque —
 *                             "Fecha y Detalle de las operaciones" (pagos,
 *                             marcados "CR" en la línea siguiente) y "Nuevos
 *                             cargos y abonos de {TITULAR}" (gasto, con RFC
 *                             en la línea siguiente)—; fecha en texto
 *                             ("11 de Junio") en vez de DD/MM.
 */
class TarjetasCreditoModel extends Model
{
    /**
     * Parsea el PDF de estado de cuenta de Banorte (Empuje Negocio) sin
     * tocar BD (estático para poder probarlo por CLI).
     *
     * No usa el ícono de "Tipo de transacción" (símbolos gráficos que
     * pdftotext no siempre extrae de forma confiable): solo fecha,
     * concepto, RFC/CURP e importe.
     *
     * @return array ['movimientos' => [...], 'errores' => [...]]
     */
    public static function parse_banorte_credito_pdf(string $rutaPdf): array
    {
        $errores = [];
        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            return ['movimientos' => [], 'errores' => ['No se pudo leer el PDF (pdftotext)']];
        }

        $cuenta = self::extraerCuenta($texto);
        if ($cuenta === null) {
            return ['movimientos' => [], 'errores' => ['No se encontró el número de cuenta/tarjeta en el PDF']];
        }

        // Recorre línea por línea acumulando el bloque activo (Titular o
        // Adicional {numero}); cada bloque termina donde empieza el
        // siguiente encabezado "Detalle de movimientos..." o donde el PDF
        // pasa a otra sección (Comisiones, Glosario, etc.).
        $lineas = preg_split('/\r\n|\r|\n/', $texto);
        $tarjetaActual = null;
        $esAdicionalActual = false;
        $movimientos = [];

        foreach ($lineas as $i => $linea) {
            if (preg_match('/Detalle de movimientos del Titular/ui', $linea)) {
                $tarjetaActual = $cuenta;
                $esAdicionalActual = false;
                continue;
            }
            if (preg_match('/Detalle de movimientos del Adicional\s+(\d+)/ui', $linea, $m)) {
                $tarjetaActual = $m[1];
                $esAdicionalActual = true;
                continue;
            }
            // Cierra el bloque activo al llegar a cualquier otra sección con
            // encabezado propio (Comisiones, pie legal, CFDI, etc.): sin esto,
            // texto de relleno más adelante en el PDF (que a veces trae un
            // importe al final de una oración) podría colarse como movimiento.
            if (preg_match('/^(Comisiones en M\.N\.|Referencia de Abreviaturas|Glosario de Comisiones|Comprobante Fiscal Digital)/ui', trim($linea))) {
                $tarjetaActual = null;
                continue;
            }
            if ($tarjetaActual === null) continue;   // aún no entramos a ningún bloque
            if (preg_match('/^\s*Fecha\s+Concepto\b/ui', $linea)) continue;   // encabezado de tabla

            $mov = self::parsearLineaMovimiento($linea, $cuenta, $tarjetaActual, $esAdicionalActual);
            if ($mov === null) continue;   // línea de continuación / ajena a la tabla
            $movimientos[] = $mov;
        }

        if (!$movimientos) {
            $errores[] = 'No se encontraron movimientos en el PDF';
        }

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /**
     * Una línea de movimiento tiene forma:
     *   DD/MM  CONCEPTO...  [RFC/CURP]  [ícono(s)]  $IMPORTE
     * El RFC/CURP y los íconos son opcionales/variables en ancho porque
     * pdftotext -layout alinea por columnas con espacios, no separadores
     * fijos; se aísla por los extremos (fecha al inicio, importe al final)
     * y lo de en medio que no sea el RFC se trata como parte del concepto.
     *
     * Líneas de continuación (ej. tipo de cambio de una compra en USD) no
     * empiezan con fecha: se descartan devolviendo null.
     */
    private static function parsearLineaMovimiento(string $linea, string $cuenta, string $tarjeta, bool $esAdicional): ?array
    {
        if (!preg_match('/^\s*(\d{2}\/\d{2})\s+(.*\S)\s+\$?([\d,]+\.\d{2})\s*$/u', $linea, $m)) {
            return null;
        }
        [$fechaCorta, $resto, $importeTxt] = [$m[1], $m[2], $m[3]];

        $rfc = null;
        // RFC persona moral/física (3-4 letras + 6 dígitos + 3 alfanum) o
        // CURP (4 letras + 6 dígitos + 8 alfanum), a veces con un espacio
        // interno que mete pdftotext (ej. "GEC 981004RE5"), seguido de 0-2
        // íconos de una letra ("y", "a", "B f", etc.).
        if (preg_match('/\s([A-ZÑ&]{3,4}\s?\d{6}[A-Z\d]{2,3}(?:[A-Z\d]{2})?)\s+[A-Za-zÁÉÍÓÚ]{0,2}(?:\s+[A-Za-zÁÉÍÓÚ]{0,2})?\s*$/u', $resto, $rm)) {
            $rfc = trim(preg_replace('/\s+/', '', $rm[1]));
            $resto = trim(substr($resto, 0, strpos($resto, $rm[0])));
        } else {
            // Sin RFC (ej. "INTERESES SUJETOS A IVA"): quitar solo el/los
            // ícono(s) sueltos de una letra al final si los hay.
            $resto = trim(preg_replace('/\s+[A-Za-zÁÉÍÓÚ]{1,2}(\s+[A-Za-zÁÉÍÓÚ]{1,2})?\s*$/u', '', $resto));
        }
        $resto = trim(preg_replace('/\s+/', ' ', $resto));
        if ($resto === '') return null;

        $importe = (float)str_replace(',', '', $importeTxt);
        // El año no viene en la tabla (solo DD/MM): se resuelve fuera de
        // este parser, en insert_bulk/el llamador, a partir del periodo del
        // estado de cuenta. Aquí se deja el placeholder de fecha corta y el
        // llamador (parse_banorte_credito_pdf) la completa.
        // Banorte no trae pagos en este listado (solo el detalle de cargos
        // por tarjeta): todo movimiento es cargo.
        return [
            'banco'              => 'BANORTE',
            'cuenta'             => $cuenta,
            'tarjeta'            => $tarjeta,
            'es_adicional'       => $esAdicional,
            'titular_adicional'  => null,
            'fecha_corta'        => $fechaCorta,   // DD/MM, se resuelve a fecha completa más abajo
            'descripcion'        => mb_substr($resto, 0, 150),
            'rfc_contraparte'    => $rfc,
            'cargo'              => $importe,
            'abono'              => null,
            'referencia'         => null,
        ];
    }

    /**
     * Parsea el PDF de estado de cuenta de American Express (Platinum /
     * Aeroméxico) sin tocar BD (estático para poder probarlo por CLI).
     *
     * Dos tipos de bloque en el mismo PDF, ambos con formato de línea
     * "DD de MES  CONCEPTO...  IMPORTE":
     *   - "Fecha y Detalle de las operaciones" (resumen, página 1): pagos y
     *     créditos aplicados a la cuenta, con "CR" en la línea siguiente ->
     *     van a 'abono'.
     *   - "Nuevos cargos y abonos de {TITULAR}": gasto con la tarjeta, con
     *     el RFC/CURP del comercio en la línea siguiente -> van a 'cargo'.
     * A diferencia de Banorte, no hay tarjetas adicionales en el PDF de
     * ejemplo: toda la cuenta se trata como una sola tarjeta (la titular).
     */
    public static function parse_amex_credito_pdf(string $rutaPdf): array
    {
        $errores = [];
        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            return ['movimientos' => [], 'errores' => ['No se pudo leer el PDF (pdftotext)']];
        }

        $cuenta = self::extraerCuentaAmex($texto);
        if ($cuenta === null) {
            return ['movimientos' => [], 'errores' => ['No se encontró el número de cuenta en el PDF']];
        }

        $lineas = preg_split('/\r\n|\r|\n/', $texto);
        $enBloque = false;
        $movimientos = [];

        for ($i = 0, $n = count($lineas); $i < $n; $i++) {
            $trim = trim($lineas[$i]);

            // Sin ancla de fin ($): "Fecha y Detalle de las operaciones" trae
            // pegada la columna "Importe en MN." en la misma línea por el
            // layout de pdftotext -layout.
            if (preg_match('/^(Fecha y Detalle de las operaciones|Nuevos cargos y abonos de\s+.+)/ui', $trim)) {
                $enBloque = true;
                continue;
            }
            // Cierra el bloque en el pie de cada tabla o cualquier sección
            // ajena; sin esto el pie legal (con montos en oraciones) podría
            // colarse como movimiento.
            if (preg_match('/^(Total de las transacciones|Paga desde los canales|Este no es un documento)/ui', $trim)) {
                $enBloque = false;
                continue;
            }
            if (!$enBloque) continue;
            if (preg_match('/^Número de Cuenta\b/ui', $trim)) continue;   // subencabezado del bloque de cargos

            // "11 de Junio     VOLARIS   CIUDAD DE MEXIC        7,276.00"
            if (!preg_match('/^(\d{1,2} de \p{L}+)\s+(.*\S)\s+([\d,]+\.\d{2})\s*$/ui', $lineas[$i], $m)) {
                continue;   // línea ajena a la tabla (dirección, leyendas, etc.)
            }
            $fechaTxt = $m[1];
            $concepto = mb_substr(trim(preg_replace('/\s+/', ' ', $m[2])), 0, 150);
            $importe  = (float)str_replace(',', '', $m[3]);

            // El RFC/CURP y el marcador "CR" vienen en línea(s) propia(s)
            // inmediatamente después, indentadas bajo el concepto.
            $rfc = null;
            $esCredito = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $sig = trim($lineas[$j]);
                if ($sig === '' || preg_match('/^\d{1,2} de \p{L}+/ui', $sig)
                    || preg_match('/^(Total de las transacciones|Número de Cuenta)\b/ui', $sig)) {
                    break;
                }
                if (strcasecmp($sig, 'CR') === 0) { $esCredito = true; $i = $j; continue; }
                if (preg_match('/^RFC([A-Z\d]{10,13})$/u', $sig, $rm)) { $rfc = $rm[1]; $i = $j; continue; }
                break;
            }

            $movimientos[] = [
                'banco'             => 'AMEX',
                'cuenta'            => $cuenta,
                'tarjeta'           => $cuenta,
                'es_adicional'      => false,
                'titular_adicional' => null,
                'fecha_txt'         => $fechaTxt,   // "DD de MES", se resuelve a fecha completa más abajo
                'descripcion'       => $concepto,
                'rfc_contraparte'   => $rfc,
                'cargo'             => $esCredito ? null : $importe,
                'abono'             => $esCredito ? $importe : null,
                'referencia'        => null,
            ];
        }

        if (!$movimientos) {
            $errores[] = 'No se encontraron movimientos en el PDF';
        }

        return ['movimientos' => $movimientos, 'errores' => $errores];
    }

    /** Número de cuenta del encabezado del estado de cuenta Amex ("3401-141436-62003"). */
    private static function extraerCuentaAmex(string $texto): ?string
    {
        if (preg_match('/Número de Cuenta:?\s*([\d\-]+)/u', $texto, $m)) {
            return preg_replace('/\D/', '', $m[1]);
        }
        return null;
    }

    /** Número de cuenta/tarjeta titular del encabezado del estado de cuenta. */
    private static function extraerCuenta(string $texto): ?string
    {
        if (preg_match('/Número de tarjeta:\s*([\d\-]+)/u', $texto, $m)) {
            return preg_replace('/\D/', '', $m[1]);
        }
        if (preg_match('/Número de cuenta:\s*([\d\-]+)/u', $texto, $m)) {
            return preg_replace('/\D/', '', $m[1]);
        }
        return null;
    }

    /** Nombres de mes en español -> número, para resolver fechas de ambos formatos. */
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        // abreviaturas de 3 letras usadas por Amex ("08-Jul-2026")
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    /**
     * Completa la fecha corta del movimiento a fecha real usando el año/mes
     * del periodo de corte, y arma la huella SHA1 de dedup. Un movimiento de
     * diciembre en un estado de cuenta cuyo corte es en enero pertenece al
     * año anterior.
     *
     * Acepta dos formatos de fecha corta (según el parser que la generó):
     *  - 'fecha_corta' => 'DD/MM' (Banorte)
     *  - 'fecha_txt'   => 'DD de MES' (Amex)
     */
    private static function resolverFechasYHuellas(array $movimientos, int $anioCorte, int $mesCorte, string $archivo): array
    {
        foreach ($movimientos as &$m) {
            if (isset($m['fecha_corta'])) {
                [$dia, $mes] = array_map('intval', explode('/', $m['fecha_corta']));
                unset($m['fecha_corta']);
            } else {
                preg_match('/^(\d{1,2}) de (\p{L}+)$/ui', $m['fecha_txt'], $fm);
                $dia = (int)$fm[1];
                $mes = self::MESES[mb_strtolower($fm[2])] ?? $mesCorte;
                unset($m['fecha_txt']);
            }
            $anio = ($mes > $mesCorte) ? $anioCorte - 1 : $anioCorte;
            $m['fecha'] = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

            $m['huella'] = sha1(implode('|', [
                $m['banco'], $m['cuenta'], $m['tarjeta'], $m['fecha'],
                $m['descripcion'], $m['rfc_contraparte'], $m['cargo'], $m['abono'],
            ]));
        }
        unset($m);
        return $movimientos;
    }

    /** Extrae el texto del PDF con pdftotext -layout (Poppler empaquetado). */
    private static function extraerTexto(string $rutaPdf): ?string
    {
        $bin = self::binarioPdftotext();
        $cmd = '"' . $bin . '" -layout ' . escapeshellarg($rutaPdf) . ' -';
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) return null;
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ($code === 0 && $out !== false && $out !== '') ? $out : null;
    }

    private static function binarioPdftotext(): ?string
    {
        $empaquetado = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (is_file($empaquetado)) return realpath($empaquetado);
        return 'pdftotext';
    }

    /**
     * Movimientos filtrados. $filtros: desde, hasta (obligatorios), tarjeta,
     * q (texto libre en concepto/RFC).
     */
    public function get_movimientos(array $filtros): array
    {
        $where  = 'WHERE fecha BETWEEN ? AND ?';
        $params = [$filtros['desde'], $filtros['hasta']];

        if (!empty($filtros['cuenta'])) {
            $where   .= ' AND cuenta = ?';
            $params[] = $filtros['cuenta'];
        }
        if (!empty($filtros['q'])) {
            $where .= ' AND (descripcion LIKE ? OR rfc_contraparte LIKE ?)';
            $like   = '%' . $filtros['q'] . '%';
            array_push($params, $like, $like);
        }

        $query = "SELECT id, banco, cuenta, tarjeta, es_adicional, titular_adicional,
                         fecha, descripcion, rfc_contraparte, cargo, abono, referencia,
                         departamento, conf_no, factura, comentarios, centro_costo
                  FROM [TG].[dbo].[tarjetas_credito_movimientos]
                  $where ORDER BY fecha, id;";
        return $this->sql->select($query, $params) ?: [];
    }

    /** Cuentas distintas presentes en la tabla (para el filtro). */
    public function get_cuentas(): array
    {
        $query = 'SELECT DISTINCT banco, cuenta FROM [TG].[dbo].[tarjetas_credito_movimientos]
                  ORDER BY banco, cuenta;';
        return $this->sql->select($query) ?: [];
    }

    /** Un movimiento por id, para precargar el modal de clasificación manual. */
    public function get_by_id(int $id): ?array
    {
        $query = "SELECT id, banco, cuenta, tarjeta, fecha, descripcion, cargo, abono,
                         departamento, conf_no, factura, comentarios, centro_costo
                  FROM [TG].[dbo].[tarjetas_credito_movimientos] WHERE id = ?;";
        $filas = $this->sql->select($query, [$id]) ?: [];
        return $filas[0] ?? null;
    }

    /**
     * Valores distintos ya capturados en 'departamento' / 'centro_costo',
     * para sugerirlos en el <datalist> del modal (evita variantes del mismo
     * valor escritas distinto entre movimientos).
     */
    public function get_departamentos(): array
    {
        $query = "SELECT DISTINCT departamento FROM [TG].[dbo].[tarjetas_credito_movimientos]
                  WHERE departamento IS NOT NULL AND departamento <> '' ORDER BY departamento;";
        return array_column($this->sql->select($query) ?: [], 'departamento');
    }

    public function get_centros_costo(): array
    {
        $query = "SELECT DISTINCT centro_costo FROM [TG].[dbo].[tarjetas_credito_movimientos]
                  WHERE centro_costo IS NOT NULL AND centro_costo <> '' ORDER BY centro_costo;";
        return array_column($this->sql->select($query) ?: [], 'centro_costo');
    }

    /**
     * Guarda la clasificación manual de un movimiento (campos que el PDF no
     * trae). Cadenas vacías se guardan como NULL para no ensuciar los
     * <datalist> de sugerencias con valores en blanco.
     */
    public function update_clasificacion(int $id, array $datos, ?int $usuario): bool
    {
        $limpio = fn($v) => trim((string)($v ?? '')) !== '' ? trim((string)$v) : null;
        $query = 'UPDATE [TG].[dbo].[tarjetas_credito_movimientos]
                   SET departamento = ?, conf_no = ?, factura = ?, comentarios = ?, centro_costo = ?,
                       clasificado_by = ?, clasificado_at = GETDATE()
                   WHERE id = ?;';
        return (bool)$this->sql->update($query, [
            $limpio($datos['departamento'] ?? null),
            $limpio($datos['conf_no'] ?? null),
            $limpio($datos['factura'] ?? null),
            $limpio($datos['comentarios'] ?? null),
            $limpio($datos['centro_costo'] ?? null),
            $usuario,
            $id,
        ]);
    }

    /**
     * Inserta los movimientos parseados saltando los que ya existen
     * (huella UNIQUE). Todo o nada: un error de BD hace rollback.
     * Resuelve aquí 'fecha_corta' -> 'fecha' y la huella, con el año/mes de
     * corte del PDF (no lo sabe el parser estático de líneas).
     *
     * @return array ['insertados' => int, 'duplicados' => int]
     */
    public function insert_bulk(array $movimientos, int $anioCorte, int $mesCorte, string $archivo, ?int $usuario): array
    {
        if (empty($movimientos)) return ['insertados' => 0, 'duplicados' => 0];

        $movimientos = self::resolverFechasYHuellas($movimientos, $anioCorte, $mesCorte, $archivo);

        $fechas = array_column($movimientos, 'fecha');
        $existentes = $this->sql->select(
            'SELECT huella FROM [TG].[dbo].[tarjetas_credito_movimientos] WHERE fecha BETWEEN ? AND ?;',
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
                $vistas[$m['huella']] = true;
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[tarjetas_credito_movimientos]
                     (banco, cuenta, tarjeta, es_adicional, titular_adicional, fecha,
                      descripcion, rfc_contraparte, cargo, abono, referencia, huella,
                      archivo_origen, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
                    [
                        $m['banco'], $m['cuenta'], $m['tarjeta'], $m['es_adicional'] ? 1 : 0,
                        $m['titular_adicional'], $m['fecha'], $m['descripcion'],
                        $m['rfc_contraparte'], $m['cargo'], $m['abono'], $m['referencia'],
                        $m['huella'], $archivo, $usuario,
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
     * Año y mes de corte del PDF, expuestos para que el controlador arme
     * insert_bulk(). Dos formatos de "fecha de corte" según el banco:
     *  - Banorte: "Fecha de corte 24 Julio, 2026"
     *  - Amex:    "Fecha de Corte ... 08-Jul-2026" (día-mes abreviado-año)
     */
    public static function extraer_periodo_corte(string $rutaPdf): ?array
    {
        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) return null;

        if (preg_match('/Fecha de [Cc]orte\s+(\d{1,2})\s+(\p{L}+),?\s+(\d{4})/u', $texto, $m)) {
            $mes = self::MESES[mb_strtolower($m[2])] ?? null;
            if ($mes === null) return null;
            return ['anio' => (int)$m[3], 'mes' => $mes];
        }
        // Amex: el layout separa "Fecha" / "de Corte" / "Siguiente Fecha" en
        // celdas distintas ("Fecha Siguiente Fecha" / "de Corte  de Corte" /
        // "08-Jul-2026  08-Ago-2026"), así que no hay una frase contigua que
        // matchear; se toma la primera fecha DD-Mon-AAAA del documento, que
        // siempre es la fecha de corte (aparece antes que la "siguiente").
        if (preg_match('/(\d{1,2})-(\p{L}{3})-(\d{4})/u', $texto, $m)) {
            $mes = self::MESES[mb_strtolower($m[2])] ?? null;
            if ($mes === null) return null;
            return ['anio' => (int)$m[3], 'mes' => $mes];
        }
        return null;
    }
}
