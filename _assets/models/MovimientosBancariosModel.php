<?php

/**
 * Movimientos bancarios del módulo Tesorería (/tesoreria/...).
 * Tabla: TG.dbo.movimientos_bancarios (una fila por movimiento del estado
 * de cuenta; dedup por huella SHA1 con índice UNIQUE).
 * Schema: docs/sql/tesoreria_schema.sql
 * Spec:   docs/superpowers/specs/2026-07-22-tesoreria-movimientos-bancos-design.md
 *
 * Por ahora solo se importa el TXT diario de Enlace Santander (ancho fijo,
 * 630 chars/línea). La columna banco deja listo el parser de Banorte.
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
                      clave_rastreo, descripcion_larga, huella, archivo_origen, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
                    [
                        $m['banco'], $m['cuenta'], $m['fecha'], $m['hora'], $m['sucursal'],
                        $m['clave_trans'], $m['descripcion'], $m['cargo'], $m['abono'],
                        $m['saldo'], $m['referencia'], $m['concepto'], $m['banco_contraparte'],
                        $m['cuenta_contraparte'], $m['nombre_contraparte'], $m['rfc_contraparte'],
                        $m['clave_rastreo'], $m['descripcion_larga'], $m['huella'],
                        $archivo, $usuario,
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

        // fecha DESC + id DESC: el id conserva el orden de línea del archivo,
        // que es el orden real de aplicación al saldo (la hora NO lo es: el
        // banco registra movimientos con hora de operación pero los aplica
        // después; verificado 2026-07-23 contra el TXT del 20/07 — 0 roturas
        // de cadena de saldo en orden de archivo vs 11 ordenando por hora).
        $query = "SELECT * FROM [TG].[dbo].[movimientos_bancarios]
                  $where ORDER BY fecha DESC, id DESC;";
        return $this->sql->select($query, $params) ?: [];
    }

    /**
     * Último saldo conocido de cada cuenta al corte de una fecha (el último
     * movimiento aplicado = fecha DESC, id DESC dentro de cada cuenta).
     * Incluye la fecha de ese movimiento: si es anterior al corte, la cuenta
     * no tuvo movimientos ese día y el saldo viene de un día previo.
     */
    public function get_saldos_finales(string $hasta): array
    {
        $query = "SELECT banco, cuenta, fecha, saldo FROM (
                      SELECT banco, cuenta, fecha, saldo,
                             ROW_NUMBER() OVER (PARTITION BY banco, cuenta
                                                ORDER BY fecha DESC, id DESC) AS rn
                      FROM [TG].[dbo].[movimientos_bancarios]
                      WHERE fecha <= ?
                  ) t WHERE rn = 1 ORDER BY cuenta;";
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
