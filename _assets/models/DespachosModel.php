<?php
declare(strict_types=1);

/**
 * DespachosModel — acceso a datos de despachos (tabla [SG12].[dbo].[Despachos]
 * del servidor central y las copias locales de cada estación).
 *
 * Organización del archivo (cada sección tiene su banner):
 *   1. Utilidades internas (resolución de estación, validaciones)
 *   2. Consulta puntual de despachos
 *   3. Liberación / marcado de despachos (escrituras)
 *   4. Tabulador y corte de turno
 *   5. Despachos de crédito/débito (liberación y verificación)
 *   6. Reporte "Control de Despachos" (corporativo y por estación)
 *   7. Ventas por estación / hora, dashboards
 *   8. Facturación global y CFDI
 *   9. Detección de anomalías de facturación por cliente
 *
 * Convenciones del esquema (importante para leer el SQL):
 *  - fchtrn / fchcor / fch son ENTEROS: (días desde 1900-01-01) + 1. La fecha
 *    real es DATEADD(day, -1, x). Desde PHP se obtienen con dateToInt().
 *  - hratrn es hhmm (p. ej. 1405 = 14:05).
 *  - nrotur: turno*10 + subcorte (11, 21, 31, 41).
 *  - La llave de Despachos es (nrotrn, codgas): nrotrn por sí solo NO es único
 *    entre estaciones. Toda deduplicación o JOIN debe incluir codgas.
 *  - fchcor = fecha del corte (la que usan tabulador y liberación);
 *    fchtrn = fecha del despacho (la que usan los reportes de facturación).
 *
 * Tipado: el archivo usa strict_types, pero los controladores no, así que
 * "5" llega como 5 sin problema; un valor no numérico ('', 'abc', null) en un
 * parámetro int lanza TypeError en lugar de generar SQL roto. Todo valor que
 * se interpola en SQL es int (por tipo), pasa por DespachosSql::listaEnteros()
 * / nombreSeguro(), o va como parámetro `?`.
 *
 * @phpstan-type Row array<string,mixed>
 * @phpstan-type Rows list<array<string,mixed>>
 */
class DespachosModel extends Model
{
    // =====================================================================
    // 1. UTILIDADES INTERNAS
    // =====================================================================

    /**
     * Nombre de cuatro partes de la base de una estación: [ip].[bd].[dbo].
     * Se usa para leer/escribir la copia local vía linked server.
     *
     * @throws InvalidArgumentException si la estación no está configurada.
     */
    private function stationDb(int $codgas): string
    {
        return $this->databases[$codgas]
            ?? throw new InvalidArgumentException("La estación $codgas no tiene base configurada.");
    }

    /**
     * Linked server de una estación: [ip]. Para OPENQUERY.
     *
     * @throws InvalidArgumentException si la estación no está configurada.
     */
    private function linkedServer(int $codgas): string
    {
        return $this->linked_server[$codgas]
            ?? throw new InvalidArgumentException("La estación $codgas no tiene linked server configurado.");
    }

    /**
     * Base de la estación sin servidor: [bd].[dbo]. Es el nombre que se usa
     * DENTRO del texto de un OPENQUERY (ya estamos "parados" en el servidor remoto).
     *
     * @throws InvalidArgumentException si la estación no está configurada.
     */
    private function shortDb(int $codgas): string
    {
        return $this->short_databases[$codgas]
            ?? throw new InvalidArgumentException("La estación $codgas no tiene base corta configurada.");
    }

    /**
     * Ejecuta un SELECT y normaliza "sin resultados" a false (contrato histórico
     * de este modelo: array con filas o false).
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>|false
     */
    private function fetchAll(string $query, array $params = []): array|false
    {
        return $this->sql->select($query, $params) ?: false;
    }

    /**
     * Conteo diario de despachos identificados como Bankaool a partir de las 23:00.
     * La vista de conciliación lo usa únicamente para Villa Ahumada (codgas 31).
     *
     * @return list<array{fecha:string,cantidad:int}>|false
     */
    public function get_bankaool_late_dispatch_counts(int $codgas, string $from, string $until): array|false
    {
        $desde = dateToInt($from);
        $hasta = dateToInt($until);
        $db = $this->stationDb($codgas);
        $query = "SELECT
                    CONVERT(varchar(10), DATEADD(day, -1, CAST(fchtrn AS datetime)), 23) AS fecha,
                    COUNT(*) AS cantidad
                  FROM {$db}.[despachos]
                  WHERE codgas = ?
                    AND fchtrn BETWEEN ? AND ?
                    AND hratrn >= 2300
                    AND rut LIKE '%BANKAOOL%'
                  GROUP BY fchtrn
                  ORDER BY fchtrn";

        return $this->fetchAll($query, [$codgas, $desde, $hasta]);
    }

    /**
     * Turno anterior y siguiente dentro de la secuencia 11 → 21 → 31 → 41 → 11.
     *
     * @return array{previous:int,next:int}
     * @throws InvalidArgumentException si $turno no es un turno válido.
     */
    private function neighbourShifts(int $turno): array
    {
        $shifts = DespachosSql::TURNOS;
        $index  = array_search($turno, $shifts, true);
        if ($index === false) {
            throw new InvalidArgumentException("Turno inválido: $turno.");
        }
        $n = count($shifts);
        return [
            'previous' => $shifts[($index + $n - 1) % $n],
            'next'     => $shifts[($index + 1) % $n],
        ];
    }

    // =====================================================================
    // 2. CONSULTA PUNTUAL DE DESPACHOS
    // =====================================================================

    /**
     * Despachos de una estación en un rango de fechas de corte.
     *
     * OJO: filtra con CAST sobre la columna, por lo que no usa índice por fecha.
     * Pendiente de migrar a comparar enteros (dateToInt) cuando el llamador lo permita.
     *
     * @param string $from  Fecha 'Y-m-d' (inclusive).
     * @param string $until Fecha 'Y-m-d' (inclusive).
     * @return list<array<string,mixed>>|false
     */
    public function get_rows(int $codgas, string $from, string $until): array|false
    {
        $query = 'SELECT
                        t5.abr,
                        t1.nrotrn,
                        CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                        t1.nrotrn Despacho,
                        t1.nrobom Posicion,
                        t2.abr Producto,
                        t1.can Cantidad,
                        t1.pre Precio,
                        t1.mto Importe,
                        t1.nrocte Nota,
                        t1.nrofac Factura,
                        t1.satuid UUID,
                        t3.den Cliente,
                        t3.cod Codigo,
                        t1.nroveh Vehiculo,
                        --t4.plc Placas,
                        t1.tiptrn,
                        t1.nrotur
                    FROM [SG12].[dbo].[Despachos] t1
                        LEFT JOIN [SG12].[dbo].[Productos] 			t2 ON t1.codprd = t2.cod
                        LEFT JOIN [SG12].[dbo].[Clientes] 			t3 ON t1.codcli = t3.cod
                        --LEFT JOIN [SG12].[dbo].[ClientesVehiculos] 	t4 ON t1.nroveh = t4.nroveh
                        LEFT JOIN [SG12].[dbo].[Gasolineras]		t5 ON t1.codgas = t5.cod
                    WHERE
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) BETWEEN ? AND ?
                    AND t1.codgas = ?
                    ;';
        return $this->fetchAll($query, [$from, $until, $codgas]);
    }

    /**
     * Un despacho de la copia LOCAL de una estación (por nrotrn).
     *
     * @return array<string,mixed>|false Fila completa o false si no existe.
     */
    public function get_dispatch(int $codest, int $nrotrn): array|false
    {
        $rs = $this->sql->select("SELECT * FROM {$this->stationDb($codest)}.[Despachos] WHERE nrotrn = ?;", [$nrotrn]);
        return $rs ? $rs[0] : false;
    }

    /**
     * Igual que get_dispatch() pero con los parámetros en el orden (despacho, estación).
     *
     * @return array<string,mixed>|false
     */
    public function get_dispatch_by_transaction(int $dispatch, int $codest): array|false
    {
        return $this->get_dispatch($codest, $dispatch);
    }

    /**
     * Busca un despacho central por nrotrn + fecha de corte, SIN estación.
     * Devuelve la fila solo si es única; si no existe o es ambigua devuelve false.
     *
     * @return array<string,mixed>|false
     */
    public function get_dispatch_by_nrotrn_and_date(int $nrotrn, int $fchcor): array|false
    {
        $data = $this->sql->select(
            'SELECT t1.*, t2.tipval, t3.abr Estacion
               FROM [SG12].[dbo].[Despachos] t1
               LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
               LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod
              WHERE t1.nrotrn = ? AND t1.fchcor = ?;',
            [$nrotrn, $fchcor]
        );
        return count($data) === 1 ? $data[0] : false;
    }

    /**
     * Despacho central (con hora 'hh:mm' y tipo de cliente) para validarlo
     * antes de liberarlo.
     *
     * @return list<array<string,mixed>> Lista vacía si no existe.
     */
    public function check_dispatch(int $nrotrn, int $codgas, int $fecha): array
    {
        $query = "SELECT
                    t1.*,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
                    t2.tipval
                FROM [SG12].[dbo].[Despachos] t1 LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                WHERE t1.nrotrn = ? AND t1.codgas = ? AND t1.fchcor = ?;";
        return $this->sql->select($query, [$nrotrn, $codgas, $fecha]);
    }

    /** ¿Ya existe el despacho en despachos_liberados? */
    public function check_dispatch_released(int $nrotrn, int $codgas): bool
    {
        return (bool) $this->sql->select(
            'SELECT 1 AS existe FROM [TG].[dbo].[despachos_liberados] WHERE nrotrn = ? AND codgas = ?;',
            [$nrotrn, $codgas]
        );
    }

    /**
     * Datos de un despacho en la copia local de la estación, con su cliente
     * (para la pantalla de liberación de TI).
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_to_release(int $nrotrn, int $codgas): array|false
    {
        $db = $this->stationDb($codgas);
        $query = "SELECT
                    t1.nrotrn, t1.fchtrn, t1.can, t1.mto, t1.nrofac, t1.gasfac, t1.satuid, t1.satrfc, t1.logfch, t2.abr AS station, t1.codcli, t3.den AS cliente
                FROM $db.[Despachos] t1
                    LEFT JOIN $db.[Gasolineras] t2 ON t1.codgas = t2.cod
                    LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                WHERE t1.nrotrn = ?;";
        return $this->fetchAll($query, [$nrotrn]);
    }

    // =====================================================================
    // 3. LIBERACIÓN / MARCADO DE DESPACHOS (ESCRITURAS)
    // =====================================================================

    /**
     * Libera en bloque los despachos de la copia local de una estación que
     * quedaron "a medias" (RFC sin UUID, o RFC genérico sin factura): limpia
     * UUID, RFC, cliente y factura para que puedan volver a facturarse.
     *
     * Solo toca la base de la estación, no el central.
     */
    public function release_dispatches(int $from, int $until, int $codgas): bool
    {
        $query = "UPDATE {$this->stationDb($codgas)}.[Despachos]
                    SET satuid = NULL, satrfc = NULL, codcli = 0, gasfac = 0, nrofac = 0
                    WHERE
                        (fchtrn BETWEEN ? AND ?) AND
                        (
                            (satuid IS NULL AND satrfc IS NOT NULL)
                            OR
                            (satrfc LIKE 'XAXX010101000' AND satuid = '' AND nrofac = 0)
                            OR
                            (satrfc IS NOT NULL AND satuid = '' AND nrofac = 0)
                            OR
                            (satrfc = 'XAXX010101000' AND nrofac = 0)
                        );";
        return (bool) $this->sql->update($query, [$from, $until]);
    }

    /**
     * Libera UN despacho: limpia UUID, RFC, cliente y factura.
     *
     * @param int $central 1 = actualiza la tabla del servidor central (por nrotrn+codgas);
     *                     cualquier otro valor = actualiza la copia local de la estación.
     */
    public function release_dispatch(int $nrotrn, int $codgas, int $central = 0): bool
    {
        $set = 'SET satuid = NULL, satrfc = NULL, codcli = 0, gasfac = 0, nrofac = 0';

        if ($central === 1) {
            $query  = "UPDATE [SG12].[dbo].[Despachos] $set WHERE nrotrn = ? AND codgas = ?;";
            $params = [$nrotrn, $codgas];
        } else {
            $query  = "UPDATE {$this->stationDb($codgas)}.[Despachos] $set WHERE nrotrn = ?;";
            $params = [$nrotrn];
        }
        return (bool) $this->sql->update($query, $params);
    }

    /**
     * Despachos de una estación y fecha de corte sin factura, candidatos a
     * marcarse como jarreo.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_dispatches_to_mark_jarreo(int $codgas, int $fecha): array|false
    {
        $db = $this->stationDb($codgas);
        $query = "SELECT
                    t1.nrotrn, t1.fchtrn, t1.nrobom, t1.can, t1.mto, t1.nrofac, t1.tiptrn, t2.abr AS station
                FROM $db.[Despachos] t1
                    LEFT JOIN $db.[Gasolineras] t2 ON t1.codgas = t2.cod
                WHERE
                    t1.fchcor = ?
                    AND (t1.nrofac IS NULL OR t1.nrofac = 0);";
        return $this->fetchAll($query, [$fecha]);
    }

    /**
     * Cambia el tipo de transacción (jarreo = 74) en la estación y en el central
     * DENTRO DE UNA TRANSACCIÓN: o se aplican las dos escrituras o ninguna.
     *
     * La escritura a la estación cruza un linked server, así que SQL Server la
     * promueve a transacción DISTRIBUIDA: requiere MSDTC habilitado en el central
     * y en la estación, y XACT_ABORT ON (se activa aquí). Si MSDTC no está
     * disponible, la escritura remota falla, se hace rollback y devuelve false.
     *
     * Devuelve false (y deja todo como estaba) si cualquiera de las dos escrituras
     * falla o no encuentra el despacho.
     */
    public function mark_dispatch_as_jarreo(int $nrotrn, int $codgas, int $tiptrn = DespachosSql::TIPTRN_JARREO): bool
    {
        $station = $this->stationDb($codgas); // valida la estación antes de abrir la transacción

        $pdo = $this->sql->getConnection();
        $ownsTransaction = !$pdo->inTransaction(); // si el llamador ya abrió una, nos unimos a ella
        if ($ownsTransaction) {
            $this->sql->beginTransaction();
        }

        try {
            // Directo por PDO::exec (no por consulta preparada): un SET dentro de un
            // lote preparado se revierte al terminar el lote.
            $pdo->exec('SET XACT_ABORT ON');

            $stationRows = $this->sql->updateSafe("UPDATE $station.[Despachos] SET tiptrn = ? WHERE nrotrn = ?;", [$tiptrn, $nrotrn]);
            $centralRows = $stationRows
                ? $this->sql->updateSafe('UPDATE [SG12].[dbo].[Despachos] SET tiptrn = ? WHERE nrotrn = ? AND codgas = ?;', [$tiptrn, $nrotrn, $codgas])
                : false;

            if (!$stationRows || !$centralRows) {
                throw new RuntimeException("No se pudo actualizar el despacho $nrotrn de la estación $codgas en ambos servidores.");
            }
        } catch (Throwable $e) {
            error_log('mark_dispatch_as_jarreo: ' . $e->getMessage());
            if ($ownsTransaction) {
                try {
                    $this->sql->rollBack();
                } catch (Throwable) {
                    // Con XACT_ABORT ON el servidor puede haber revertido ya la transacción.
                }
            }
            return false;
        } finally {
            $pdo->exec('SET XACT_ABORT OFF');
        }

        if ($ownsTransaction) {
            $this->sql->commit();
        }
        return true;
    }

    /** Revierte el jarreo (tiptrn = 0, venta normal). */
    public function unmark_dispatch_as_jarreo(int $nrotrn, int $codgas): bool
    {
        return $this->mark_dispatch_as_jarreo($nrotrn, $codgas, DespachosSql::TIPTRN_EFECTIVO);
    }

    /** Quita RFC y tarjeta de un despacho en la copia LOCAL de la estación. */
    public function dismark_dispatch_station(int $codest, int $nrotrn): bool
    {
        return (bool) $this->sql->update(
            "UPDATE {$this->stationDb($codest)}.[Despachos] SET rut = NULL, tar = 0 WHERE nrotrn = ?;",
            [$nrotrn]
        );
    }

    /** Quita RFC y tarjeta de un despacho en el CENTRAL. */
    public function dismark_dispatch_central(int $codest, int $nrotrn): bool
    {
        return (bool) $this->sql->update(
            'UPDATE [SG12].[dbo].[Despachos] SET rut = NULL, tar = 0 WHERE nrotrn = ? AND codgas = ?;',
            [$nrotrn, $codest]
        );
    }

    /**
     * Registra en [TG].[despachos_liberados] que un despacho de crédito/débito
     * ya fue liberado por el usuario en sesión.
     *
     * @param array<string,mixed> $dispatch Fila de check_dispatch() (incluye hora_formateada y tipval).
     * @return bool true si se insertó el registro.
     */
    public function release_dispatch_TG(array $dispatch): bool
    {
        $query = 'INSERT INTO [TG].[dbo].[despachos_liberados] ([nrotrn],[codgas],[codisl],[nrotur],[codcli],[tipval],[codprd],[can],[mto],[codemp],[fchtrn],[fchcor],[hratrn]) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?);';
        $params = [
            $dispatch['nrotrn'], $dispatch['codgas'], $dispatch['codisl'], $dispatch['nrotur'], $dispatch['codcli'],
            $dispatch['tipval'] ?? 0,
            $dispatch['codprd'], $dispatch['can'], $dispatch['mto'],
            $_SESSION['tg_user']['Id'],
            $dispatch['fchtrn'], $dispatch['fchcor'], $dispatch['hora_formateada'],
        ];
        return (bool) $this->sql->insert($query, $params);
    }

    /** Guarda la nota de un despacho liberado. */
    public function save_notes(int $id, string $notes): bool
    {
        return (bool) $this->sql->update('UPDATE [TG].[dbo].[despachos_liberados] SET [notes] = ? WHERE id = ?', [$notes, $id]);
    }

    // =====================================================================
    // 4. TABULADOR Y CORTE DE TURNO
    // =====================================================================

    /**
     * Venta de combustible de un turno (sin jarreos), leída de la estación vía OPENQUERY.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_turn_sales(int $codgas, int $fchtrn, int $nrotur): array|false
    {
        $linked = $this->linkedServer($codgas);
        $short  = $this->shortDb($codgas);
        $query = "SELECT * FROM OPENQUERY($linked, 'SELECT
                    CAST(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END) AS DECIMAL(10, 2)) AS Monto,  -- Descartamos los jarreos
                    CAST(SUM(can) AS DECIMAL(10, 3)) AS Volumen,
                    CASE
                    WHEN codprd IN (179,192) THEN ''T-Maxima Regular''
                    WHEN codprd IN (180,193) THEN ''T-Super Premium''
                    WHEN codprd = 181 THEN ''Diesel Automotriz''
                    END AS Producto
                FROM $short.[Despachos]
                WHERE codprd IN (179,180,181,192,193)
                    AND fchcor = $fchtrn AND nrotur = $nrotur
                GROUP BY codprd
                ORDER BY codprd;')";
        return $this->fetchAll($query);
    }

    /**
     * Jarreos (muestras de calibración) de un turno, leídos de la estación.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_jarreos(int $codgas, int $tabDate, int $turno): array|false
    {
        $linked = $this->linkedServer($codgas);
        $short  = $this->shortDb($codgas);
        $query = "SELECT * FROM OPENQUERY($linked, 'SELECT TOP 100 d.nrotrn AS Transaccion
                        , d.codgas AS Gasolinera
                        , CONVERT(VARCHAR, CAST(d.fchcor as DATETIME) -1, 23) AS Fecha
                        , SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 2, 2) + '':'' + SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 4, 2) Hora
                        , d.nrotur AS Turno
                        , d.nrobom AS Bomba
                        , d.codprd AS Producto
                        , LTRIM(p.den) AS Descripcion
                        , ROUND(d.can, 3) AS Cantidad
                        , d.mto AS Total
                    FROM $short.Despachos d(NOLOCK)
                        INNER JOIN $short.Productos p(NOLOCK) ON d.codprd = p.cod
                    WHERE
                        d.codgas = $codgas
                        AND d.fchcor = $tabDate
                        AND d.tiptrn IN(65, 74)
                        AND d.nrotur = $turno
                    ORDER BY nrotrn DESC;')";
        return $this->fetchAll($query);
    }

    /**
     * Despachos por marcar en un turno (copia local vía OPENQUERY): solo los
     * que NO son de cliente crédito/débito y tienen monto.
     *
     * @return list<array<string,mixed>>|false
     */
    public function sp_obtener_despachos_para_marcar(int $CodigoEstacion, int $date, int $turno): array|false
    {
        $linked = $this->linkedServer($CodigoEstacion);
        $short  = $this->shortDb($CodigoEstacion);
        $query = "
        SELECT * FROM OPENQUERY($linked, 'SELECT
			CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) AS Fecha,
			t1.nrotrn AS Despacho,
			t1.nrobom AS Posicion,
			t2.den AS Producto,
			t1.pre AS Precio,
			CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
			CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
			t1.nrocte AS Nota,
			t1.nrofac AS Factura,
			t1.satuid AS UUID,
			t3.den AS Cliente,
			t3.cod AS Codigo,
			t1.nroveh AS Vehiculo,
			t1.tiptrn,
			t1.nrotur,
			t5.den AS Isla,
			t1.nrobom AS Bomba,
			t1.codgas,
			t1.tar,
			t1.nrofac,
			t3.tipval,
			t6.trxmsg,
			t1.satuid,
			t6.trxcod,
			t7.den Valor
		FROM
			$short.[Despachos] t1
		LEFT JOIN
			$short.[Productos] t2 ON t1.codprd = t2.cod
		LEFT JOIN
			$short.[Clientes] t3 ON t1.codcli = t3.cod
		LEFT JOIN
			$short.[Bombas] t4 ON t1.codgas = t4.codgas AND t1.graprd = t4.graprd AND t1.nrobom = t4.nro
		LEFT JOIN
			$short.[Islas] t5 ON t4.codgas = t5.codgas AND t4.codisl = t5.cod
		LEFT JOIN (
            SELECT
                mt1.codbco,
                mt1.nrotrn,
                mt1.trxmsg,
                mt1.trxcod
            FROM
                $short.[MovimientosTar] mt1
            WHERE
                NOT EXISTS (
                    SELECT 1
                    FROM $short.[MovimientosTar] mt2
                    WHERE mt1.nrotrn = mt2.nrotrn
                    AND (mt2.fchmov > mt1.fchmov OR (mt2.fchmov = mt1.fchmov AND mt2.fchlog > mt1.fchlog))
                )
        ) t6 ON t1.nrotrn = t6.nrotrn
		LEFT JOIN
			$short.[Valores] t7 ON t6.codbco = t7.cod
		WHERE
			t1.codgas = $CodigoEstacion
			AND t1.fchcor = $date
			AND t1.nrotur = $turno
			AND (t1.tiptrn IN (0, 49, 53, 51) OR (t1.tiptrn = 51 AND t1.codcli = 0))
			AND (t3.tipval IS NULL OR t3.tipval NOT IN (3, 4))
			AND t1.mto > 0
		-- AND t1.nrofac < 1
		ORDER BY
			t1.nrotrn DESC;');
        ";
        return $this->fetchAll($query);
    }

    /**
     * Marca un despacho contra un tabulador OneGoal (stored procedure).
     *
     * @param array<string,mixed> $tabular     Fila del tabulador (Id, TipoCambio, Usuario, CodigoEstacion, Turno).
     * @param array<string,mixed> $dispatch    Fila de get_dispatch() (codisl, mto, nrotrn).
     * @param array<string,mixed> $CodigoValor Botón de valor (ValorButt_Num, ValorButt_Id, ValorButt_CodigoTar).
     * @param string|int|null     $CodigoTar   Tarjeta capturada.
     * @param string|int|null     $CodigoRut   RFC/rut capturado.
     * @return bool true si el SP actualizó el despacho (devuelve 1), false si no.
     */
    public function sp_marcar_despacho_onegoal(array $tabular, array $dispatch, array $CodigoValor, string|int|null $CodigoTar, string|int|null $CodigoRut): bool
    {
        return (bool) $this->sql->executeStoredProcedure('[TG].[dbo].[sp_marcar_despacho_onegoal]', [
            'TabulatorId'        => $tabular['Id'],
            'IslaId'             => $dispatch['codisl'],
            'ValorButtNum'       => $CodigoValor['ValorButt_Num'],
            'Monto'              => $dispatch['mto'],
            'TipoCambio'         => $tabular['TipoCambio'],
            'Usuario'            => $tabular['Usuario'],
            'CodigoEstacion'     => $tabular['CodigoEstacion'],
            'Turno'              => $tabular['Turno'],
            'IdUsuario'          => $_SESSION['tg_user']['Id'],
            'CodigoValor'        => $CodigoValor['ValorButt_Id'],
            'ValorButtCodigoTar' => $CodigoValor['ValorButt_CodigoTar'],
            'CodigoTar'          => $CodigoTar,
            'CodigoRut'          => $CodigoRut,
            'DatabaseName'       => $this->stationDb((int) $tabular['CodigoEstacion']),
            'nrotrn'             => $dispatch['nrotrn'],
            'Resultado'          => 0,
        ]);
    }

    /**
     * Despachos de crédito (tipval 3) en un rango de horas de un corte, con la
     * hora del despacho anterior del mismo cliente (para detectar repetidos).
     * Lee de la copia LOCAL de la estación (e incluye la isla).
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_dispatches_tabular(int $fchtrn, int $hratrn_init, int $hratrn_final, int $codgas): array|false
    {
        return $this->fetchAll($this->tabularDispatchesSql($this->stationDb($codgas), 3, true), [$fchtrn, $hratrn_init, $hratrn_final, $codgas]);
    }

    /**
     * Despachos de débito (tipval 4) en un rango de horas de un corte. Igual que
     * el de crédito: lee de la copia LOCAL de la estación e incluye la isla.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_debit_dispatches_tabular(int $fchtrn, int $hratrn_init, int $hratrn_final, int $codgas): array|false
    {
        return $this->fetchAll($this->tabularDispatchesSql($this->stationDb($codgas), 4, true), [$fchtrn, $hratrn_init, $hratrn_final, $codgas]);
    }

    /**
     * SQL común de los despachos de crédito/débito del tabulador.
     *
     * @param string $db        Prefijo de base (p. ej. '[SG12].[dbo]' o '[ip].[bd].[dbo]').
     * @param int    $tipval    3 = crédito, 4 = débito.
     * @param bool   $conIsla   true => agrega Islas.den como "Isla".
     */
    private function tabularDispatchesSql(string $db, int $tipval, bool $conIsla): string
    {
        $isla     = $conIsla ? ",\n                t7.den Isla" : '';
        $joinIsla = $conIsla ? "\n                LEFT JOIN $db.[Islas]  t7 ON t1.codisl = t7.cod" : '';

        return "
            SELECT
                t1.nrotrn Despacho,
                t1.nrofac Factura,
                t1.satuid UUID,
                t1.satrfc AS RFC,
                t1.codcli,
                t2.den AS Cliente,
                CASE t2.tipval
                    WHEN 3 THEN N'Crédito'
                    WHEN 4 THEN N'Débito'
                    ELSE 'Otro'
                END AS Tipo,
                t1.can,
                CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                t1.mto,
                t3.abr,
                t3.den Producto,
                t1.hratrn,
                ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
                LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
                t4.abr Estacion,
                t5.tar Tarjeta,
                t5.grp Grupo,
                t5.den Descripcion,
                t5.plc Placas,
                t1.nrobom Bomba,
                t6.pos Posicion,
                t1.rut$isla
            FROM
                $db.[Despachos] t1
                LEFT JOIN $db.[Clientes] t2 ON t1.codcli = t2.cod
                LEFT JOIN $db.[Productos] t3 ON t1.codprd = t3.cod
                LEFT JOIN $db.[Gasolineras] t4 ON t1.codgas = t4.cod
                LEFT JOIN $db.[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
                LEFT JOIN $db.[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro$joinIsla
            WHERE
                t1.fchcor = ?
                AND t1.hratrn BETWEEN ? AND ?
                AND t1.codprd IN (179,180,181,192,193)
                AND t1.codgas = ?
                AND t2.tipval = $tipval
        ";
    }

    /**
     * Despachos de una isla y fecha de corte para cuadrar contra ValesR
     * (marcados por isla y turno). Solo clientes crédito/débito.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_mark_dispatches_by_island_shift(int $fch, int $CodigoEstacion): array|false
    {
        $query = "SELECT
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    CAST(DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, CAST('00:00' AS TIME))) AS TIME) AS Hora,
                    t1.nrotrn Despacho,
                    t1.nrobom Posicion,
                    CASE
                        WHEN t1.codprd IN (179,192) THEN 'T-Maxima Regular'
                        WHEN t1.codprd IN (180,193) THEN 'T-Super Premium'
                        WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                    END AS Producto,
                    t1.pre Precio,
                    CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
                    CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
                    t1.nrocte Nota,
                    t1.nrofac Factura,
                    t1.satuid UUID,
                    t3.den Cliente,
                    t3.cod Codigo,
                    t1.nroveh Vehiculo,
                    t1.tiptrn,
                    t1.nrotur,
                    (t1.nrotur / 10) AS turno,
                    (t1.nrotur % 10) AS subcorte,
                    t5.den Isla,
                    t1.nrobom Bomba,
                    t1.codgas,
                    t1.tar,
                    t1.nrofac,
                    t3.tipval,
                    CASE
                        WHEN t3.tipval = 3 THEN N'Crédito'
                        WHEN t3.tipval = 4 THEN N'Débito'
                    END Tipo,
                    CASE
                        WHEN t6.Despacho IS NOT NULL AND t1.mto = t6.mto THEN 1
                        ELSE 0
                    END AS CoincidenciaEncontrada,
                t8.den Valor,
                t9.abr Estacion
                FROM [SG12].[dbo].[Despachos] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t5 ON t1.codisl = t5.cod
                    LEFT JOIN (SELECT DISTINCT ABS(t1.sec) Despacho, mto FROM [SG12].[dbo].[ValesR] t1 WHERE t1.codgas = $CodigoEstacion AND t1.codval IN (28,127,145) AND t1.fch = $fch) t6 ON t1.nrotrn = t6.Despacho
                    LEFT JOIN [SG12].[dbo].[MovimientosTar] t7 ON t1.nrotrn = t7.nrotrn AND t7.codgas = $CodigoEstacion
                    LEFT JOIN [SG12].[dbo].[Valores] t8 ON t7.codbco = t8.cod
                    LEFT JOIN [SG12].[dbo].[Gasolineras] t9 ON t1.codgas = t9.cod
                WHERE
                    t1.fchcor = $fch
                    AND t3.tipval IN (3,4)
                AND t1.codgas = $CodigoEstacion
                AND t1.mto > 0
                ORDER BY t1.nrotrn DESC;";
        return $this->fetchAll($query);
    }

    // =====================================================================
    // 4b. DIFERENCIAS, DUPLICADOS Y LEALTAD
    // =====================================================================

    /**
     * Despachos duplicados (mismo cliente en una ventana de tiempo) — stored procedure.
     *
     * @param int              $dispatches_date Fecha inicial (entero de fecha).
     * @param int              $until           Fecha final (entero de fecha).
     * @param int|string|false $interval        Intervalo en minutos, o false si no se filtra.
     * @param int              $codgas          Estación (0 = todas).
     * @param int|string       $clientName      Nombre (o parte) del cliente; 0 = todos.
     * @return list<array<string,mixed>>|false
     */
    public function sp_obtener_despachos_duplicados(int $dispatches_date, int $until, int|string|false $interval, int $codgas, int|string $clientName): array|false
    {
        return $this->sql->executeStoredProcedure(
            '[TG].[dbo].[sp_obtener_despachos_duplicados]',
            [$dispatches_date, $until, $interval, $codgas, $clientName]
        ) ?: false;
    }

    /**
     * Diferencias por valor de pago entre corte y despachos (stored procedure).
     *
     * Con $codgas = 0 consulta TODAS las estaciones activas, pero antes verifica
     * por socket que su SQL Server (puerto 1433) responda, para no colgar el SP
     * con una estación caída. Cada estación caída cuesta hasta 5 s de timeout.
     *
     * @return list<array<string,mixed>>|false
     */
    public function sp_obtener_diferencias_por_valor(int $from, int $until, int $codgas): array|false
    {
        if ($codgas === 0) {
            $codigos = $this->reachableStationCodes();
            if ($codigos === null) {
                return false;
            }
            $stations = implode(',', $codigos);
        } else {
            $stations = (string) $codgas;
        }

        set_time_limit(0);
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', '300');

        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_diferencias]', [$from, $until, $stations]) ?: false;
    }

    /**
     * Códigos de las estaciones cuyo SQL Server responde en el puerto 1433.
     *
     * @return list<int>|null null si no hay estaciones configuradas en [TG].[Estaciones].
     */
    private function reachableStationCodes(): ?array
    {
        $stations = $this->sql->select('SELECT Codigo, Servidor FROM [TG].dbo.Estaciones WHERE Codigo NOT IN (0,4,20)', []);
        if (!$stations) {
            return null;
        }

        $reachable = [];
        foreach ($stations as $station) {
            $connection = @fsockopen((string) $station['Servidor'], 1433, $errno, $errstr, 5);
            if ($connection) {
                $reachable[] = (int) $station['Codigo'];
                fclose($connection);
            }
        }
        return $reachable;
    }

    /**
     * Venta del día (monto y litros) de los 3 combustibles principales, todas las estaciones.
     *
     * @param int $todayInt Fecha de hoy como entero de fecha.
     * @return list<array<string,mixed>>|false
     */
    public function get_today_sales(int $todayInt): array|false
    {
        $query = 'SELECT
                    CAST(SUM(mto) AS DECIMAL(18, 2)) AS Monto,
                    CAST(SUM(can) AS DECIMAL(18, 3)) AS Volumen,
                    CASE
                    WHEN codprd IN (179, 192) THEN \'T-Maxima Regular\'
                    WHEN codprd IN (180, 193) THEN \'T-Super Premium\'
                    WHEN codprd = 181 THEN \'Diesel Automotriz\'
                    END AS Producto
                FROM [SG12].[dbo].[Despachos]
                WHERE codprd IN (179,180,181,192,193)
                    AND fchtrn = ?
                GROUP BY codprd
                ORDER BY codprd;';

        return $this->fetchAll($query, [$todayInt]);
    }

    // =====================================================================
    // 5. DESPACHOS DE CRÉDITO / DÉBITO: VERIFICACIÓN Y LIBERACIÓN
    // =====================================================================

    /**
     * Despachos de clientes crédito/débito de un periodo, con la hora del
     * despacho anterior del mismo cliente.
     *
     * @param int        $from   Fecha inicial (entero de fecha).
     * @param int        $until  Fecha final (entero de fecha).
     * @param int        $codgas Estación (0 = todas).
     * @param string|int $client Nombre (o parte) del cliente; '0' / '' = todos.
     * @param int        $tipval 0 = crédito y débito, 3 = solo crédito, 4 = solo débito.
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_and_debit_dispatches(int $from, int $until, int $codgas, string|int $client, int $tipval): array|false
    {
        $client = trim((string) $client);
        $params = [];

        $whereCodgas = $codgas !== 0 ? "AND t1.codgas = $codgas" : '';
        $whereTipval = match ($tipval) {
            0       => 'AND t2.tipval IN (3, 4)',
            3, 4    => "AND t2.tipval = $tipval",
            default => 'AND 1 = 0', // tipo de cliente desconocido: sin resultados
        };
        $whereClient = '';
        if ($client !== '' && $client !== '0') {
            $whereClient = 'AND t2.den LIKE ?';
            $params[]    = '%' . $client . '%';
        }

        $query = "
        SELECT
            t1.nrotrn Despacho,
            t1.nrofac Factura,
            t1.satuid UUID,
            t1.satrfc AS RFC,
            t1.codcli,
            t2.den AS Cliente,
            CASE t2.tipval
                WHEN 3 THEN N'Crédito'
                WHEN 4 THEN N'Débito'
                ELSE 'Otro'
            END AS Tipo,
            t1.can,
            CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
            CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            t1.mto,
            t3.abr,
            t3.den Producto,
            t1.hratrn,
            ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
            LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
            t4.abr Estación,
            t5.tar Tarjeta,
            t5.grp Grupo,
            t5.den Descripcion,
            t5.plc Placas,
            t1.nrobom Bomba,
            t6.pos Posicion,
            t1.rut
        FROM
            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
            LEFT JOIN [SG12].[dbo].[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro
        WHERE
            t1.fchtrn BETWEEN $from AND $until
            AND t1.codcli > 0
            $whereCodgas
            $whereTipval
            $whereClient
        ";

        return $this->fetchAll($query, $params);
    }

    /**
     * Despachos pendientes (o ya aplicados) de facturar de clientes crédito/débito.
     *
     * @param int          $type   3 = crédito, 4 = débito, otro = ambos.
     * @param string|false $status 'pendiente' => sin factura (nrofac = 0); cualquier otro => con factura.
     * @return list<array<string,mixed>>|false
     */
    public function get_pending_dispatches_for_invoice(int $from, int $until, int $type, string|false $status): array|false
    {
        $whereStatus = $status === 'pendiente' ? 'AND t1.nrofac = 0' : 'AND t1.nrofac != 0';
        $whereType   = match ($type) {
            4       => 'AND t2.tipval = 4',
            3       => 'AND t2.tipval = 3',
            default => 'AND t2.tipval IN (4, 3)',
        };

        $query = "
            WITH ValuesTable AS (
                    SELECT
                            CONVERT(DATE, DATEADD(DAY, -1, t1.fchtrn)) AS Fecha,
                            t1.nrotrn,
                            t3.abr Estacion,
                            t4.den as Producto,
                            t1.codcli,
                            t2.den Cliente,
                            t1.can Volumen,
                            t1.mto Monto,
                            CASE
                                WHEN t2.tipval = 3 THEN 'Crédito'
                                WHEN t2.tipval = 4 THEN 'Débito'
                            END Tipo,
                            t1.nrofac Factura,
                            t1.satuid UUID
                        FROM
                            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                            LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod
                            LEFT JOIN [SG12].[dbo].[Productos] t4 ON t1.codprd = t4.cod
                        WHERE
                            t1.fchtrn BETWEEN $from AND $until
                            AND t1.mto != 0
                            AND t1.can != 0
                            $whereType
                            $whereStatus
            )
            SELECT * FROM ValuesTable ORDER BY Fecha DESC;
            ";
        return $this->fetchAll($query);
    }

    // =====================================================================
    // 6. REPORTE "CONTROL DE DESPACHOS"
    //    6a. Corporativo (servidor central, tabla [SG12].[dbo].[Despachos])
    // =====================================================================

    /**
     * Construye "WITH CTE AS (...)" del reporte corporativo: un renglón por
     * despacho con cliente, factura, UUID, tipo de cliente, forma de pago, etc.
     *
     * Reglas que conviene saber:
     *  - El LEFT JOIN a MovimientosTar/Valores (banco) y a ClientesVehiculos puede
     *    multiplicar renglones; por eso se numera con ROW_NUMBER y los
     *    consumidores filtran `rn = 1`. La partición es (nrotrn, codgas):
     *    nrotrn solo NO es único entre estaciones (PK = nrotrn + codgas).
     *  - MovimientosTar se filtra con ±1 día de margen porque los movimientos de
     *    tarjeta cerca de la medianoche pueden quedar con fecha distinta al despacho.
     *
     * @param string      $where         Filtros ya validados (DespachosSql::filtrosReporte()).
     * @param string      $periodMode    'dispatch' = periodo por fecha del despacho;
     *                                   'invoice'  = periodo por fecha de la factura (exige DocumentosC).
     * @param int|null    $dispatchFrom  Acota además por fecha de despacho (solo con ambos extremos).
     * @param int|null    $dispatchUntil
     */
    private function dispatchesCte(int $from, int $until, string $where, string $periodMode = 'dispatch', ?int $dispatchFrom = null, ?int $dispatchUntil = null, int $codgas = 0, string $innerWhere = ''): string
    {
        $movFrom  = $from - 1;
        $movUntil = $until + 1;
        // Sin esto la subconsulta agrupa los movimientos de las ~40 estaciones aunque se pida una.
        $movCodgas = $codgas !== 0 ? "AND codgas = $codgas" : '';
        $periodWhere = $periodMode === 'invoice'
            ? "t3.nro IS NOT NULL AND t3.fch BETWEEN $from AND $until"
            : "t1.fchtrn BETWEEN $from AND $until";
        $dispatchWhere = $dispatchFrom !== null && $dispatchUntil !== null
            ? " AND t1.fchtrn BETWEEN $dispatchFrom AND $dispatchUntil"
            : '';

        $fecha          = DespachosSql::fechaIso('t1.fchtrn');
        $hora           = DespachosSql::horaTime();
        $folioFactura   = DespachosSql::folio('t3.nro');
        $folioDespacho  = DespachosSql::folio('t1.nrofac');
        $tipoCliente    = DespachosSql::tipoCliente();
        $tipoAplicativo = DespachosSql::tipoCliente(false, true);
        $pagoFactura    = DespachosSql::pagoFactura();
        $pagoDespacho   = DespachosSql::pagoDespacho();

        return "WITH CTE AS (
                    SELECT
                    $fecha AS fecha,
                    $hora AS hora_formateada,
                    SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1) AS turno,
                    t1.nrotrn AS despacho,
                    t1.codgas AS codgas,
                    t8.den AS producto,
                    t6.abr AS estacion,
                    t7.den AS empresa,
                    t2.den AS cliente_des,
                    t5.den AS cliente_fac,
                    t1.can AS cantidad,
                    t1.mto AS importe,
                    t1.pre AS precio,
                    t1.gasfac,
                    t1.nrofac,
                    CASE WHEN t9.cod <> 0 THEN t9.den ELSE '' END AS despachador,
                    $folioFactura AS factura,
                    CONVERT(DATE, DATEADD(DAY, -1, t3.fch)) AS FechaFactura,
                    $folioDespacho AS factura_desp,
                    CASE WHEN t1.satuid IS NOT NULL THEN t1.satuid ELSE t3.satuid END AS UUID,
                    t3.satuid AS UUID_fac,
                    t1.satuid AS UUID_dep,
                    t1.rut,
                    t13.den AS denominacion,
                    t1.codcli AS codigo_cliente,
                    t10.codval,
                    t2.tipval,
                    $tipoAplicativo AS tipo_cliente_aplicativo,
                    $tipoCliente AS tipo_cliente,
                    t14.den AS efectos_c,
                    t11.nroveh AS vehiculo,
                    t11.plc AS placas,
                    t3.txtref,
                    t3.tipref,
                    $pagoFactura AS tipo_pago,
                    $pagoDespacho AS tipo_pago_despacho,
                    t1.tiptrn,
                    t6.cvecli,
                    -- Desempate determinista: los LEFT JOIN a ClientesValores (28 crédito / 127 débito)
                    -- y a MovimientosTar (varios bancos) pueden duplicar el despacho; sin esto la fila
                    -- que sobrevive (rn = 1) cambiaba entre el conteo, la página y el Excel.
                    -- Crédito (28) gana sobre débito (127), igual que en el CASE de tipo de cliente.
                    ROW_NUMBER() OVER(PARTITION BY t1.nrotrn, t1.codgas ORDER BY t1.fchtrn ASC, t1.hratrn ASC, t10.codval ASC, t12.codbco ASC) AS rn
                    FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                    LEFT JOIN [SG12].[dbo].Clientes t2 WITH (NOLOCK) ON t1.codcli = t2.cod
                    LEFT JOIN [SG12].[dbo].DocumentosC t3 WITH (NOLOCK) ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
                    LEFT JOIN [SG12].[dbo].Clientes t5 WITH (NOLOCK) ON t3.codopr = t5.cod
                    LEFT JOIN [SG12].[dbo].Gasolineras t6 WITH (NOLOCK) ON t1.codgas = t6.cod
                    LEFT JOIN [SG12].[dbo].[Empresas] t7 WITH (NOLOCK) ON t6.codemp = t7.cod
                    LEFT JOIN [SG12].[dbo].[Productos] t8 WITH (NOLOCK) ON t1.codprd = t8.cod
                    LEFT JOIN [SG12].[dbo].[Responsables] t9 WITH (NOLOCK) ON t1.codres = t9.cod
                    LEFT JOIN [SG12].[dbo].[ClientesValores] t10 WITH (NOLOCK) ON t1.codcli = t10.codcli AND t10.codest != -1 AND t10.codval IN (127, 28)
                    LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t11 WITH (NOLOCK) ON t1.codcli = t11.codcli AND t1.nroveh = t11.nroveh
                    LEFT JOIN (
                        SELECT nrotrn, SUM(mto) AS mto, codbco, codgas
                        FROM [SG12].[dbo].MovimientosTar WITH (NOLOCK)
                        WHERE tipmov != 86 AND tipmov != 97 AND mto != 0 AND fchmov BETWEEN $movFrom AND $movUntil $movCodgas
                        GROUP BY nrotrn, codgas, codbco
                    ) t12 ON t1.nrotrn = t12.nrotrn AND t1.codgas = t12.codgas
                    LEFT JOIN [SG12].[dbo].Valores t13 WITH (NOLOCK) ON t12.codbco = t13.cod
                    LEFT JOIN [SG12].[dbo].[EfectosC] t14 WITH (NOLOCK) ON t3.tip = t14.tipope AND t3.subope = t14.subope
                    WHERE
                        t1.mto != 0 AND
                        t1.tiptrn NOT IN (65, 74) AND
                        $periodWhere$dispatchWhere $where $innerWhere
                )";
    }

    /**
     * Reporte completo (sin paginar) de despachos del corporativo.
     *
     * @param int        $from         Fecha inicial (entero de fecha).
     * @param int        $until        Fecha final (entero de fecha).
     * @param int        $codgas       Estación (0 = todas).
     * @param int        $uuid         0 = todos, 1 = sin timbrar, 2 = timbrados.
     * @param string|int $tipo_cliente Slug ('cliente_credito', 'cliente_debito', 'monedero',
     *                                 'contado', 'factura_global'); 0 / '' = todos.
     * @param int        $billed       0 = todos, 1 = sin facturar, 2 = facturados.
     * @return list<array<string,mixed>>|false
     */
    public function control_dispatches2(int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed): array|false
    {
        $where = DespachosSql::filtrosReporte($codgas, $uuid, $billed, $tipo_cliente);
        $cte   = $this->dispatchesCte($from, $until, $where, 'dispatch', null, null, $codgas);

        return $this->fetchAll("$cte
            SELECT *
            FROM CTE WITH (NOLOCK)
            WHERE rn = 1
            ORDER BY fecha, hora_formateada;");
    }

    /**
     * Igual que control_dispatches2() pero agrega los datos del CFDI en el
     * portal de facturación (TGV2.dbo.Facturas: Folio, UUID_sat, FechaTimbrado)
     * y NO incluye despachador, vehículo ni placas.
     *
     * @return list<array<string,mixed>>|false
     */
    public function control_dispatches_invoiced(int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed): array|false
    {
        $where = DespachosSql::filtrosReporte($codgas, $uuid, $billed, $tipo_cliente);

        $movFrom        = $from - 1;
        $movUntil       = $until + 1;
        $fecha          = DespachosSql::fechaIso('t1.fchtrn');
        $hora           = DespachosSql::horaTime();
        $folioFactura   = DespachosSql::folio('t3.nro');
        $folioDespacho  = DespachosSql::folio('t1.nrofac');
        $tipoCliente    = DespachosSql::tipoCliente();
        $tipoAplicativo = DespachosSql::tipoCliente(false, true);
        $pagoFactura    = DespachosSql::pagoFactura();
        $pagoDespacho   = DespachosSql::pagoDespacho();

        $query = "WITH CTE AS (
                    SELECT
                        $fecha AS fecha,
                        $hora AS hora_formateada,
                        SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1) AS turno,
                        t1.nrotrn AS despacho,
                        t8.den AS producto,
                        t6.abr AS estacion,
                        t7.den AS empresa,
                        t2.den AS cliente_des,
                        t5.den AS cliente_fac,
                        t1.can AS cantidad,
                        t1.mto AS importe,
                        t1.pre AS precio,
                        t1.gasfac,
                        t1.nrofac,
                        $folioFactura AS factura,
                        $folioDespacho AS factura_desp,
                        t15.Folio,
                        CASE WHEN t1.satuid IS NOT NULL THEN t1.satuid ELSE t3.satuid END AS UUID,
                        t15.UUID AS UUID_sat,
                        t3.satuid AS UUID_fac,
                        t1.satuid AS UUID_dep,
                        t1.rut,
                        t13.den AS denominacion,
                        t1.codcli AS codigo_cliente,
                        t10.codval,
                        t2.tipval,
                        $tipoAplicativo AS tipo_cliente_aplicativo,
                        $tipoCliente AS tipo_cliente,
                        t14.den AS efectos_c,
                        t3.txtref,
                        t3.tipref,
                        $pagoFactura AS tipo_pago,
                        $pagoDespacho AS tipo_pago_despacho,
                        t1.tiptrn,
                        t6.cvecli,
                        t15.FechaTimbrado,
                        ROW_NUMBER() OVER(PARTITION BY t1.nrotrn, t1.codgas ORDER BY t1.fchtrn ASC, t1.hratrn ASC) AS rn
                        FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                        LEFT JOIN [SG12].[dbo].Clientes t2 ON t1.codcli = t2.cod
                        LEFT JOIN [SG12].[dbo].DocumentosC t3 ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
                        LEFT JOIN [SG12].[dbo].Clientes t5 ON t3.codopr = t5.cod
                        LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                        LEFT JOIN [SG12].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                        LEFT JOIN [SG12].[dbo].[Productos] t8 ON t1.codprd = t8.cod
                        LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli AND t10.codest != -1 AND t10.codval IN (127, 28)
                        LEFT JOIN (
                            SELECT nrotrn, SUM(mto) AS mto, codbco, codgas
                            FROM [SG12].[dbo].MovimientosTar WITH (NOLOCK)
                            WHERE tipmov != 86 AND tipmov != 97 AND mto != 0 AND fchmov BETWEEN $movFrom AND $movUntil
                            GROUP BY nrotrn, codgas, codbco
                        ) t12 ON t1.nrotrn = t12.nrotrn AND t1.codgas = t12.codgas
                        LEFT JOIN [SG12].[dbo].Valores t13 ON t12.codbco = t13.cod
                        LEFT JOIN [SG12].[dbo].[EfectosC] t14 ON t3.tip = t14.tipope AND t3.subope = t14.subope
                        LEFT JOIN TGV2.dbo.Facturas t15 ON t1.satuid = t15.UUID
                    WHERE
                        t1.mto != 0 AND
                        t1.tiptrn NOT IN (65, 74) AND
                        t1.fchtrn BETWEEN ? AND ? $where
                )
            SELECT *
            FROM CTE
            WHERE rn = 1
            ORDER BY fecha, hora_formateada;";

        return $this->fetchAll($query, [$from, $until]);
    }

    /**
     * Reporte corporativo paginado en el servidor (OFFSET/FETCH) para no traer
     * todo el dataset a memoria.
     *
     * @param array<string,mixed> $columnSearches Búsqueda por columna ['clave' => 'texto'].
     * @param string              $periodMode     'dispatch' o 'invoice' (ver dispatchesCte()).
     * @return array{data:list<array<string,mixed>>,recordsTotal:int,recordsFiltered:int}
     */
    private function dispatchesPaginated(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed,
        int $start, int $length, string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        string $periodMode = 'dispatch', ?int $dispatchFrom = null, ?int $dispatchUntil = null,
        int $totalHint = 0
    ): array {
        $columns = DespachosSql::COLUMNAS_REPORTE;
        $where   = DespachosSql::filtrosReporte($codgas, $uuid, $billed, $tipo_cliente);

        // Filtros exactos (despacho, cliente, fecha) van antes del ROW_NUMBER; el resto, como LIKE sobre el CTE.
        [$innerWhere, $columnSearches] = DespachosSql::filtrosExactos($columnSearches);
        [$searchWhere, $params] = DespachosSql::busqueda($columns, $columnSearches, $globalSearch, DespachosSql::COLUMNAS_BUSQUEDA_GLOBAL);

        // OFFSET/FETCH con enteros ya validados (sin parámetros: evita problemas de tipo en sqlsrv).
        $start  = max(0, $start);
        $length = $length > 0 ? min($length, 1000) : 1000; // nunca traer "todos"
        [$orderExpr, $orderDirection] = DespachosSql::orden($columns, $orderColKey, $orderDir);

        // Página + conteo filtrado en una sola pasada (COUNT(*) OVER()).
        $cteFiltrado = $this->dispatchesCte($from, $until, $where, $periodMode, $dispatchFrom, $dispatchUntil, $codgas, $innerWhere);
        $data = $this->sql->select(
            "$cteFiltrado SELECT *, COUNT(*) OVER() AS total_filtrado FROM CTE WHERE rn = 1 $searchWhere
             ORDER BY $orderExpr $orderDirection, codgas ASC, despacho ASC
             OFFSET $start ROWS FETCH NEXT $length ROWS ONLY",
            $params
        ) ?: [];

        if ($data !== []) {
            $recordsFiltered = (int) $data[0]['total_filtrado'];
        } elseif ($start === 0) {
            $recordsFiltered = 0;
        } else {
            // Página fuera de rango (los filtros cambiaron): no hay fila de dónde leer el conteo.
            $row             = $this->sql->select("$cteFiltrado SELECT COUNT(*) AS c FROM CTE WHERE rn = 1 $searchWhere", $params);
            $recordsFiltered = (int) ($row[0]['c'] ?? 0);
        }
        foreach ($data as &$fila) {
            unset($fila['total_filtrado']);
        }
        unset($fila);

        // recordsTotal: sin la búsqueda de DataTables (los filtros del reporte ya van en el CTE).
        if ($searchWhere === '' && $innerWhere === '') {
            $recordsTotal = $recordsFiltered;
        } elseif ($totalHint > 0) {
            $recordsTotal = $totalHint; // lo informó el propio front en el draw anterior
        } else {
            $cteTotal     = $this->dispatchesCte($from, $until, $where, $periodMode, $dispatchFrom, $dispatchUntil, $codgas);
            $totalRow     = $this->sql->select("$cteTotal SELECT COUNT(*) AS c FROM CTE WHERE rn = 1", []);
            $recordsTotal = (int) ($totalRow[0]['c'] ?? 0);
        }

        return [
            'data'            => $data,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
        ];
    }

    /**
     * Reporte "Control de Despachos" paginado (periodo por fecha de despacho).
     *
     * @param array<string,mixed> $columnSearches
     * @return array{data:list<array<string,mixed>>,recordsTotal:int,recordsFiltered:int}
     */
    public function control_dispatches2_paginated(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed,
        int $start, int $length, string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '', int $totalHint = 0
    ): array {
        return $this->dispatchesPaginated(
            $from, $until, $codgas, $uuid, $tipo_cliente, $billed,
            $start, $length, $orderColKey, $orderDir, $columnSearches, $globalSearch,
            'dispatch', null, null, $totalHint
        );
    }

    /**
     * Reporte independiente por FECHA DE FACTURA. Siempre exige la relación
     * real con DocumentosC (solo despachos facturados) y conserva las columnas
     * del reporte de Control de Despachos.
     *
     * @param array<string,mixed> $columnSearches
     * @return array{data:list<array<string,mixed>>,recordsTotal:int,recordsFiltered:int}
     */
    public function control_dispatches_by_billing_paginated(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente,
        int $start, int $length, string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        ?int $dispatchFrom = null, ?int $dispatchUntil = null, int $totalHint = 0
    ): array {
        return $this->dispatchesPaginated(
            $from, $until, $codgas, $uuid, $tipo_cliente, 2,
            $start, $length, $orderColKey, $orderDir, $columnSearches, $globalSearch,
            'invoice', $dispatchFrom, $dispatchUntil, $totalHint
        );
    }

    /**
     * Igual que dispatchesPaginated() pero sin OFFSET/FETCH: entrega TODAS las
     * filas una por una desde el cursor de SQL Server (memoria constante), para
     * exportar a Excel en streaming sin límite de registros.
     *
     * @param array<string,mixed> $columnSearches
     * @return Generator<int,array<string,mixed>>
     */
    private function streamDispatchesAll(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed,
        string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        string $periodMode = 'dispatch', ?int $dispatchFrom = null, ?int $dispatchUntil = null
    ): Generator {
        $columns = DespachosSql::COLUMNAS_REPORTE;
        $where   = DespachosSql::filtrosReporte($codgas, $uuid, $billed, $tipo_cliente);

        [$innerWhere, $columnSearches] = DespachosSql::filtrosExactos($columnSearches);
        $cte = $this->dispatchesCte($from, $until, $where, $periodMode, $dispatchFrom, $dispatchUntil, $codgas, $innerWhere);

        [$searchWhere, $params]       = DespachosSql::busqueda($columns, $columnSearches, $globalSearch, DespachosSql::COLUMNAS_BUSQUEDA_GLOBAL);
        [$orderExpr, $orderDirection] = DespachosSql::orden($columns, $orderColKey, $orderDir);

        $stmt = $this->sql->getConnection()->prepare(
            "$cte SELECT * FROM CTE WITH (NOLOCK) WHERE rn = 1 $searchWhere
             ORDER BY $orderExpr $orderDirection, codgas ASC, despacho ASC"
        );
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
        $stmt->closeCursor();
    }

    /**
     * @param array<string,mixed> $columnSearches
     * @return Generator<int,array<string,mixed>>
     */
    public function stream_dispatches2_all(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed,
        string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = ''
    ): Generator {
        return $this->streamDispatchesAll(
            $from, $until, $codgas, $uuid, $tipo_cliente, $billed,
            $orderColKey, $orderDir, $columnSearches, $globalSearch
        );
    }

    /**
     * @param array<string,mixed> $columnSearches
     * @return Generator<int,array<string,mixed>>
     */
    public function stream_dispatches_by_billing_all(
        int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente,
        string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        ?int $dispatchFrom = null, ?int $dispatchUntil = null
    ): Generator {
        return $this->streamDispatchesAll(
            $from, $until, $codgas, $uuid, $tipo_cliente, 2,
            $orderColKey, $orderDir, $columnSearches, $globalSearch,
            'invoice', $dispatchFrom, $dispatchUntil
        );
    }

    /**
     * Resumen ligero de Factura Global (codigo_cliente = 21701354) agrupado por
     * forma de pago y número de factura. Reemplaza el cálculo que antes hacía el
     * front recorriendo TODO el detalle.
     *
     * @return list<array<string,mixed>>
     */
    public function factura_global_summary(int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed): array
    {
        $where = DespachosSql::filtrosReporte($codgas, $uuid, $billed, $tipo_cliente);
        $cte   = $this->dispatchesCte($from, $until, $where, 'dispatch', null, null, $codgas);
        $query = "$cte
                  SELECT
                      COALESCE(tipo_pago, tipo_pago_despacho) AS tipo_pago,
                      COALESCE(factura, factura_desp)         AS factura,
                      COUNT(*)                                AS numero_f,
                      MIN(txtref)                             AS txtref
                  FROM CTE WITH (NOLOCK)
                  WHERE rn = 1 AND codigo_cliente = " . DespachosSql::CODOPR_FACTURA_GLOBAL . "
                  GROUP BY COALESCE(tipo_pago, tipo_pago_despacho), COALESCE(factura, factura_desp)";
        return $this->sql->select($query, []) ?: [];
    }

    /**
     * Importe por estación y tipo de cliente (pivot), sin detalle.
     *
     * @return list<array<string,mixed>>|false
     */
    public function pivot_dispatches(int $from, int $until, int $codgas): array|false
    {
        return $this->fetchAll(
            $this->pivotTipoClienteSql(false, $codgas),
            [$from, $until]
        );
    }

    /**
     * Igual que pivot_dispatches() pero desglosado por día (agrega codgas y fecha).
     *
     * @return list<array<string,mixed>>|false
     */
    public function pivot_daily_dispatches_table(int $from, int $until, int $codgas): array|false
    {
        return $this->fetchAll(
            $this->pivotTipoClienteSql(true, $codgas),
            [$from, $until]
        );
    }

    /**
     * SQL común de los pivots por tipo de cliente. Los parámetros `?` son
     * (desde, hasta) como enteros de fecha.
     *
     * @param bool $porDia true => agrega codgas y fecha al detalle y ordena por estación y fecha.
     */
    private function pivotTipoClienteSql(bool $porDia, int $codgas): string
    {
        $tipoCliente = DespachosSql::tipoCliente(true);
        $whereCodgas = $codgas !== 0 ? "AND t1.codgas = $codgas" : '';
        $extra       = $porDia ? ",\n                        t1.codgas,\n                        " . DespachosSql::fechaIso('t1.fchtrn') . ' AS fecha' : '';
        $orderBy     = $porDia ? 'estacion, fecha' : 'estacion';

        // El pivot solo necesita Despachos, DocumentosC (para distinguir factura
        // global), Gasolineras (nombre) y ClientesValores (crédito/débito).
        return "SELECT *,
                    ISNULL([cliente_credito], 0) +
                    ISNULL([cliente_debito], 0) +
                    ISNULL([monedero], 0) +
                    ISNULL([contado], 0) +
                    ISNULL([factura_global], 0) +
                    ISNULL([N/A], 0) AS total
                FROM (
                    SELECT
                        t6.abr AS estacion$extra,
                        $tipoCliente AS tipo_cliente,
                        t1.mto AS importe
                    FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                    LEFT JOIN [SG12].[dbo].DocumentosC t3 ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
                    LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                    LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli AND t10.codest != -1 AND t10.codval IN (127, 28)
                    WHERE
                        t1.tiptrn NOT IN (65, 74) AND
                        t1.fchtrn BETWEEN ? AND ? $whereCodgas
                ) AS SourceTable
                PIVOT (
                    SUM(importe)
                    FOR tipo_cliente IN ([cliente_credito], [cliente_debito], [monedero], [contado], [factura_global], [N/A])
                ) AS PivotTable
                ORDER BY $orderBy;";
    }

    // =====================================================================
    //    6b. Por estación (SQL Server local de cada estación)
    // =====================================================================

    /**
     * Query de despachos de UNA estación con todas las transformaciones resueltas
     * en SQL y SOLO las 24 columnas que consume el DataTable.
     *
     * @param string $prefix Antepone "[BD].[dbo]." a las tablas cuando corre vía linked
     *                       server (OPENQUERY); vacío si la conexión ya está parada en la
     *                       base de la estación.
     * @param int    $uuid   0 = todos, 1 = sin timbrar, otro valor = timbrados.
     * @param int    $billed 0 = todos, 1 = sin facturar, otro valor = facturados.
     */
    private function stationDispatchesSql(int $from, int $until, int $uuid, int $billed, string $prefix = ''): string
    {
        $movFrom  = $from - 1;
        $movUntil = $until + 1;

        // Aquí cualquier valor distinto de 0/1 se trata como "sí" (comportamiento histórico de esta pantalla).
        $uuidCondition   = $uuid   !== 0 ? DespachosSql::filtroTimbrado($uuid === 1 ? 1 : 2)  : '';
        $billedCondition = $billed !== 0 ? DespachosSql::filtroFacturado($billed === 1 ? 1 : 2) : '';

        $fecha          = DespachosSql::fechaIso('t1.fchtrn');
        $hora           = DespachosSql::horaTexto();
        $factura        = DespachosSql::folioFinal();
        $tipoCliente    = DespachosSql::tipoCliente();
        $tipoAplicativo = DespachosSql::tipoCliente(false, true);
        $pago           = DespachosSql::pagoFinal();

        return "
            SELECT fecha, hora_formateada, turno, despacho, producto, estacion, empresa,
                   cliente_fac, cantidad, importe, precio, despachador, tipo_pago, factura,
                   FechaFactura, [UUID], txtref, rut, denominacion, codigo_cliente,
                   tipo_cliente, tipo_cliente_aplicativo, vehiculo, placas
            FROM (
            SELECT
                $fecha AS fecha,
                $hora AS hora_formateada,
                SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1) AS turno,
                t1.nrotrn AS despacho,
                t8.den AS producto,
                t6.abr AS estacion,
                t7.den AS empresa,
                ISNULL(t5.den, t2.den) AS cliente_fac,
                t1.can AS cantidad,
                t1.mto AS importe,
                t1.pre AS precio,
                CASE WHEN t9.cod <> 0 THEN t9.den ELSE '' END AS despachador,
                $factura AS factura,
                CONVERT(DATE, DATEADD(DAY, -1, t3.fch)) AS FechaFactura,
                ISNULL(CASE WHEN t1.satuid IS NOT NULL THEN t1.satuid ELSE t3.satuid END, '.') AS [UUID],
                t3.txtref,
                t1.rut,
                t13.den AS denominacion,
                CASE WHEN t1.codcli < 0 THEN '' ELSE CAST(t1.codcli AS VARCHAR(20)) END AS codigo_cliente,
                $tipoCliente AS tipo_cliente,
                $tipoAplicativo AS tipo_cliente_aplicativo,
                t11.nroveh AS vehiculo,
                t11.plc AS placas,
                $pago AS tipo_pago,
                ROW_NUMBER() OVER(PARTITION BY t1.nrotrn, t1.codgas ORDER BY t1.fchtrn ASC, t1.hratrn ASC) AS rn
            FROM {$prefix}Despachos t1 WITH (NOLOCK)
            LEFT JOIN {$prefix}Clientes t2 WITH (NOLOCK) ON t1.codcli = t2.cod
            LEFT JOIN {$prefix}DocumentosC t3 WITH (NOLOCK) ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
            LEFT JOIN {$prefix}Clientes t5 WITH (NOLOCK) ON t3.codopr = t5.cod
            LEFT JOIN {$prefix}Gasolineras t6 WITH (NOLOCK) ON t1.codgas = t6.cod
            LEFT JOIN {$prefix}Empresas t7 WITH (NOLOCK) ON t6.codemp = t7.cod
            LEFT JOIN {$prefix}Productos t8 WITH (NOLOCK) ON t1.codprd = t8.cod
            LEFT JOIN {$prefix}Responsables t9 WITH (NOLOCK) ON t1.codres = t9.cod
            LEFT JOIN {$prefix}ClientesValores t10 WITH (NOLOCK) ON t1.codcli = t10.codcli AND t10.codest != -1 AND t10.codval IN (127, 28)
            LEFT JOIN {$prefix}ClientesVehiculos t11 WITH (NOLOCK) ON t1.codcli = t11.codcli AND t1.nroveh = t11.nroveh
            LEFT JOIN (
                SELECT nrotrn, SUM(mto) AS mto, codbco, codgas
                FROM {$prefix}MovimientosTar WITH (NOLOCK)
                WHERE tipmov != 86 AND tipmov != 97 AND mto != 0 AND fchmov BETWEEN $movFrom AND $movUntil
                GROUP BY nrotrn, codgas, codbco
            ) t12 ON t1.nrotrn = t12.nrotrn AND t1.codgas = t12.codgas
            LEFT JOIN {$prefix}Valores t13 WITH (NOLOCK) ON t12.codbco = t13.cod
            WHERE t1.fchtrn BETWEEN $from AND $until
              AND t1.mto != 0
              AND t1.tiptrn NOT IN (65, 74)
              $uuidCondition
              $billedCondition
            ) AS q WHERE q.rn = 1
        ";
    }

    /**
     * Consulta despachos conectándose DIRECTAMENTE al SQL Server de la estación
     * (sin API intermedio ni linked server/OPENQUERY).
     *
     * @param array<string,mixed> $station Fila de estación: servidor, base_datos, usuario, contra.
     * @return list<array<string,mixed>>
     * @throws PDOException si la estación no responde; el llamador decide el respaldo.
     */
    public function control_dispatches_est_direct(int $from, int $until, int $uuid, int $billed, array $station): array
    {
        $dsn = "sqlsrv:Server={$station['servidor']};Database={$station['base_datos']};TrustServerCertificate=yes;LoginTimeout=10";
        $pdo = new PDO($dsn, (string) $station['usuario'], (string) $station['contra']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET NOCOUNT ON');

        $stmt = $pdo->query($this->stationDispatchesSql($from, $until, $uuid, $billed));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $rows;
    }

    /**
     * Mismo resultado que control_dispatches_est_direct() pero vía linked server
     * (OPENQUERY) desde el central; sirve de respaldo cuando la conexión directa
     * falla. Con varias estaciones las une con UNION ALL.
     *
     * $codgas y $tipo_cliente se conservan en la firma por compatibilidad: el
     * endpoint siempre manda $tipo_cliente = 0 y la estación sale de $estations.
     *
     * @param list<array<string,mixed>>|array<string,mixed> $estations Lista de estaciones (o una sola fila).
     * @return list<array<string,mixed>>|false
     */
    public function control_dispatches_est(int $from, int $until, int $codgas, int $uuid, string|int $tipo_cliente, int $billed, array $estations): array|false
    {
        // Tolerar que llegue una sola fila de estación (assoc) en lugar de una lista.
        if (isset($estations['servidor'])) {
            $estations = [$estations];
        }

        $union = [];
        foreach ($estations as $station) {
            if (empty($station['servidor']) || empty($station['base_datos'])) {
                continue;
            }
            $server   = DespachosSql::nombreSeguro((string) $station['servidor']);
            $database = DespachosSql::nombreSeguro((string) $station['base_datos']);

            // OPENQUERY recibe el query como literal (máx. 8,000 caracteres): se
            // compactan los espacios y se duplican las comillas simples. rn = 1 se
            // filtra DENTRO del OPENQUERY para que los renglones repetidos por los
            // joins no viajen por la red.
            $inner = $this->stationDispatchesSql($from, $until, $uuid, $billed, "[$database].[dbo].");
            $inner = trim((string) preg_replace('/\s+/', ' ', $inner));
            $union[] = "SELECT * FROM OPENQUERY([$server], '" . str_replace("'", "''", $inner) . "')";
        }
        if ($union === []) {
            return false;
        }

        return $this->fetchAll(implode(' UNION ALL ', $union));
    }

    /**
     * Reporte "por facturación" para UNA estación (consulta local): DocumentosC es
     * obligatorio y el periodo se aplica, con parámetros PDO, a la FECHA DE LA
     * FACTURA. No reutiliza stationDispatchesSql() porque aquella está basada en la
     * fecha del despacho.
     *
     * Parámetros posicionales del CTE: (fechaFacturaDesde, fechaFacturaHasta); después
     * de él van los de $typeParams y $searchParams, en ese orden.
     *
     * @param array<string,mixed> $columnSearches
     * @return array{cte:string,typeWhere:string,typeParams:list<string>,search:string,searchParams:list<string>,order:string,direction:string}
     */
    private function stationBillingSql(int $uuid, string|int $tipoCliente, string $orderColKey, string $orderDir, array $columnSearches = [], string $globalSearch = '', ?int $dispatchFrom = null, ?int $dispatchUntil = null): array
    {
        $columns = DespachosSql::COLUMNAS_ESTACION;

        $uuidWhere = DespachosSql::filtroTimbrado($uuid);

        $typeWhere  = '';
        $typeParams = [];
        $label = DespachosSql::etiquetaTipoCliente($tipoCliente);
        if ($label !== null && (string) $tipoCliente !== '0') {
            $typeWhere    = ' AND tipo_cliente = ?';
            $typeParams[] = $label;
        }

        $dispatchWhere = $dispatchFrom !== null && $dispatchUntil !== null
            ? " AND t1.fchtrn BETWEEN $dispatchFrom AND $dispatchUntil"
            : '';

        $fecha          = DespachosSql::fechaIso('t1.fchtrn');
        $hora           = DespachosSql::horaTexto();
        $folio          = DespachosSql::folio('t3.nro');
        $tipoClienteSql = DespachosSql::tipoCliente();
        $tipoAplicativo = DespachosSql::tipoCliente(false, true);
        $pago           = DespachosSql::pagoFinal();

        $cte = "WITH CTE AS (
            SELECT $fecha AS fecha,
                $hora AS hora_formateada,
                SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1) AS turno,
                t1.nrotrn AS despacho,
                t8.den AS producto,
                t6.abr AS estacion,
                t7.den AS empresa,
                ISNULL(t5.den, t2.den) AS cliente_fac,
                t1.can AS cantidad,
                t1.mto AS importe,
                t1.pre AS precio,
                CASE WHEN t9.cod <> 0 THEN t9.den ELSE '' END AS despachador,
                $folio AS factura,
                CONVERT(DATE, DATEADD(DAY, -1, t3.fch)) AS FechaFactura,
                ISNULL(CASE WHEN t1.satuid IS NOT NULL THEN t1.satuid ELSE t3.satuid END, '.') AS [UUID],
                t3.txtref,
                t1.rut,
                t13.den AS denominacion,
                CASE WHEN t1.codcli < 0 THEN '' ELSE CAST(t1.codcli AS VARCHAR(20)) END AS codigo_cliente,
                $tipoClienteSql AS tipo_cliente,
                $tipoAplicativo AS tipo_cliente_aplicativo,
                t11.nroveh AS vehiculo,
                t11.plc AS placas,
                $pago AS tipo_pago,
                ROW_NUMBER() OVER(PARTITION BY t1.nrotrn, t1.codgas ORDER BY t1.fchtrn, t1.hratrn) AS rn
            FROM Despachos t1 WITH (NOLOCK)
            INNER JOIN DocumentosC t3 ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
            LEFT JOIN Clientes t2 WITH (NOLOCK) ON t1.codcli = t2.cod
            LEFT JOIN Clientes t5 WITH (NOLOCK) ON t3.codopr = t5.cod
            LEFT JOIN Gasolineras t6 WITH (NOLOCK) ON t1.codgas = t6.cod
            LEFT JOIN Empresas t7 WITH (NOLOCK) ON t6.codemp = t7.cod
            LEFT JOIN Productos t8 WITH (NOLOCK) ON t1.codprd = t8.cod
            LEFT JOIN Responsables t9 WITH (NOLOCK) ON t1.codres = t9.cod
            LEFT JOIN ClientesValores t10 WITH (NOLOCK) ON t1.codcli = t10.codcli AND t10.codest != -1 AND t10.codval IN (127, 28)
            LEFT JOIN ClientesVehiculos t11 WITH (NOLOCK) ON t1.codcli = t11.codcli AND t1.nroveh = t11.nroveh
            LEFT JOIN (
                SELECT nrotrn, SUM(mto) AS mto, codbco, codgas
                FROM MovimientosTar WITH (NOLOCK)
                WHERE tipmov NOT IN (86, 97) AND mto != 0
                GROUP BY nrotrn, codgas, codbco
            ) t12 ON t1.nrotrn = t12.nrotrn AND t1.codgas = t12.codgas
            LEFT JOIN Valores t13 WITH (NOLOCK) ON t12.codbco = t13.cod
            WHERE t1.mto != 0 AND t1.tiptrn NOT IN (65, 74) AND t3.fch BETWEEN ? AND ?$dispatchWhere $uuidWhere
        )";

        [$search, $searchParams] = DespachosSql::busqueda($columns, $columnSearches, $globalSearch);
        [$order, $direction]     = DespachosSql::orden($columns, $orderColKey, $orderDir);

        return [
            'cte'          => $cte,
            'typeWhere'    => $typeWhere,
            'typeParams'   => $typeParams,
            'search'       => $search,
            'searchParams' => $searchParams,
            'order'        => $order,
            'direction'    => $direction,
        ];
    }

    /**
     * Abre una conexión directa al SQL Server de una estación. (protected: las pruebas
     * la sustituyen por una conexión simulada.)
     *
     * @param array<string,mixed> $station Fila de estación: servidor, base_datos, usuario, contra.
     * @param float|null          $deadline Instante límite (microtime) de toda la consulta consolidada.
     *                                      Un servidor fuera de línea no debe consumir el tiempo de las demás.
     * @throws RuntimeException si ya no queda tiempo para iniciar el login.
     */
    protected function stationPdo(array $station, ?float $deadline = null): PDO
    {
        // No iniciar el login (hasta 3 s) sin espacio para una consulta mínima y el cierre de la respuesta.
        if ($deadline !== null && ($deadline - microtime(true)) <= 7) {
            throw new RuntimeException('Station query deadline reached');
        }
        $dsn = "sqlsrv:Server={$station['servidor']};Database={$station['base_datos']};TrustServerCertificate=yes;LoginTimeout=3";
        $pdo = new PDO($dsn, (string) $station['usuario'], (string) $station['contra']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->stationQueryTimeout($pdo, $deadline);
        $pdo->exec('SET NOCOUNT ON');
        return $pdo;
    }

    /**
     * Ajusta el timeout de cada operación al margen que queda antes del deadline
     * (sin deadline: 30 s). Cuando sqlsrv no soporta el atributo no hace nada.
     *
     * @throws RuntimeException si ya no cabe trabajo remoto en el margen.
     */
    protected function stationQueryTimeout(PDO $pdo, ?float $deadline): void
    {
        if ($deadline === null) {
            if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
                $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), 30);
            }
            return;
        }
        // Tres segundos quedan reservados para cerrar cursores y serializar la respuesta.
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining <= 4) {
            throw new RuntimeException('Station query deadline reached');
        }
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $pdo->setAttribute(constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT'), min(30, max(1, $remaining - 3)));
        }
    }

    /**
     * Nombre legible de una estación para mensajes de error al usuario.
     *
     * @param array<string,mixed> $station
     */
    private static function stationLabel(array $station): string
    {
        return (string) ($station['estacion_nombre'] ?? 'Estación');
    }

    /**
     * Reporte "por facturación" paginado, consultando directo cada estación.
     *
     * - 1 estación: pagina en SQL (OFFSET/FETCH).
     * - Varias: recorre un cursor por estación y las combina con un merge k-way
     *   reteniendo UNA fila por cursor, así no materializa start+length por
     *   estación. Por eso el desplazamiento profundo tiene un límite explícito.
     * - Una estación caída no tira el reporte: se omite y se reporta en stationErrors.
     *
     * @param list<array<string,mixed>> $stations
     * @param array<string,mixed>       $columnSearches
     * @return array{data:list<array<string,mixed>>,recordsTotal:int,recordsFiltered:int,stationErrors:list<string>,error?:string}
     */
    public function dispatches_by_billing_stations_paginated(
        int $from, int $until, array $stations, int $uuid, string|int $tipoCliente,
        int $start, int $length, string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        ?int $dispatchFrom = null, ?int $dispatchUntil = null, ?float $deadline = null
    ): array {
        $start  = max(0, $start);
        $length = min(100, max(1, $length));
        $multi  = count($stations) > 1;

        if ($multi && $start > 100000) {
            return [
                'data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'stationErrors' => [],
                'error' => 'El desplazamiento solicitado es demasiado grande para la consulta por estaciones. Aplique filtros para continuar.',
            ];
        }

        $q = $this->stationBillingSql($uuid, $tipoCliente, $orderColKey, $orderDir, $columnSearches, $globalSearch, $dispatchFrom, $dispatchUntil);
        $cte = $q['cte'];
        $baseParams = array_merge([$from, $until], $q['typeParams']);
        $searchParams = array_merge($baseParams, $q['searchParams']);

        $deadline ??= microtime(true) + 285;
        $stationDeadline = $multi ? $deadline : null;

        // --- Fase 1: totales (todas las estaciones; las que fallan se omiten) ---
        $total = 0;
        $filtered = 0;
        $errors = [];
        $availableStations = [];
        foreach ($stations as $station) {
            if ($multi && microtime(true) >= $deadline) {
                $errors[] = self::stationLabel($station);
                continue;
            }
            try {
                $pdo = $this->stationPdo($station, $stationDeadline);

                $this->stationQueryTimeout($pdo, $stationDeadline);
                $stmt = $pdo->prepare("$cte SELECT COUNT(*) c FROM CTE WHERE rn=1 {$q['typeWhere']}");
                $stmt->execute($baseParams);
                $total += (int) $stmt->fetchColumn();
                $stmt->closeCursor();

                $this->stationQueryTimeout($pdo, $stationDeadline);
                $stmt = $pdo->prepare("$cte SELECT COUNT(*) c FROM CTE WHERE rn=1 {$q['typeWhere']} {$q['search']}");
                $stmt->execute($searchParams);
                $filtered += (int) $stmt->fetchColumn();
                $stmt->closeCursor();

                $pdo = null;
                $availableStations[] = $station;
            } catch (Throwable $e) {
                error_log('dispatches_by_billing_station failed station ' . (int) ($station['codigo'] ?? 0));
                $errors[] = self::stationLabel($station);
            }
        }

        if ($availableStations === []) {
            return [
                'data' => [], 'recordsTotal' => 0, 'recordsFiltered' => 0, 'stationErrors' => $errors,
                'error' => 'No se pudo consultar ninguna estación.',
            ];
        }

        $orderBy = "{$q['order']} {$q['direction']}, despacho ASC";

        // --- Fase 2a: una sola estación => paginación en SQL ---
        if (!$multi) {
            $station = $stations[0];
            $rows = [];
            try {
                $pdo  = $this->stationPdo($station);
                $stmt = $pdo->prepare("$cte SELECT * FROM CTE WHERE rn=1 {$q['typeWhere']} {$q['search']} ORDER BY $orderBy OFFSET $start ROWS FETCH NEXT $length ROWS ONLY");
                $stmt->execute($searchParams);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rows[] = $row;
                }
                $stmt->closeCursor();
            } catch (Throwable $e) {
                error_log('dispatches_by_billing_station data failed station ' . (int) ($station['codigo'] ?? 0));
                $errors[] = self::stationLabel($station);
            }
            return ['data' => $rows, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'stationErrors' => $errors];
        }

        // --- Fase 2b: varias estaciones => merge k-way de cursores ---
        $active   = [];
        $timedOut = false;
        foreach ($availableStations as $station) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            try {
                $pdo = $this->stationPdo($station, $deadline);
                $this->stationQueryTimeout($pdo, $deadline);
                $stmt = $pdo->prepare("$cte SELECT * FROM CTE WHERE rn=1 {$q['typeWhere']} {$q['search']} ORDER BY $orderBy");
                $stmt->execute($searchParams);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $active[] = ['pdo' => $pdo, 'stmt' => $stmt, 'row' => $row, 'station' => $station];
                } else {
                    $stmt->closeCursor();
                }
            } catch (Throwable $e) {
                error_log('dispatches_by_billing_station cursor failed station ' . (int) ($station['codigo'] ?? 0));
                $errors[] = self::stationLabel($station);
            }
        }

        $rows    = [];
        $seen    = 0;
        $sortKey = trim($q['order'], '[]');
        try {
            while ($active !== [] && count($rows) < $length) {
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    break;
                }

                // Cursor cuya fila actual es la siguiente en el orden global.
                $next = 0;
                for ($i = 1, $n = count($active); $i < $n; $i++) {
                    if ($this->compareStationRows($active[$i], $active[$next], $sortKey, $q['direction']) < 0) {
                        $next = $i;
                    }
                }

                if ($seen++ >= $start) {
                    $rows[] = $active[$next]['row'];
                }

                try {
                    $nextRow = $active[$next]['stmt']->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    error_log('dispatches_by_billing_station fetch failed station ' . (int) ($active[$next]['station']['codigo'] ?? 0));
                    $errors[] = self::stationLabel($active[$next]['station']);
                    $nextRow  = false;
                }

                if ($nextRow === false) {
                    $active[$next]['stmt']->closeCursor();
                    array_splice($active, $next, 1);
                } else {
                    $active[$next]['row'] = $nextRow;
                }
            }
        } finally {
            foreach ($active as $item) {
                try {
                    $item['stmt']->closeCursor();
                } catch (Throwable $e) {
                    // Cursor ya cerrado o conexión caída: nada que liberar.
                }
            }
        }

        $result = ['data' => $rows, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'stationErrors' => array_values(array_unique($errors))];
        if ($timedOut) {
            $result['error'] = 'La consulta alcanzó el límite de tiempo; se muestran resultados parciales.';
        }
        return $result;
    }

    /**
     * Compara las filas actuales de dos cursores para el merge k-way.
     * Orden: columna pedida (asc/desc), luego despacho ASC, luego nombre de estación.
     *
     * @param array{row:array<string,mixed>,station:array<string,mixed>} $a
     * @param array{row:array<string,mixed>,station:array<string,mixed>} $b
     * @return int <0 si $a va antes que $b.
     */
    private function compareStationRows(array $a, array $b, string $sortKey, string $direction): int
    {
        $result = ($a['row'][$sortKey] ?? '') <=> ($b['row'][$sortKey] ?? '');
        if ($result !== 0) {
            return $direction === 'DESC' ? -$result : $result;
        }

        $result = ($a['row']['despacho'] ?? 0) <=> ($b['row']['despacho'] ?? 0);
        if ($result !== 0) {
            return $result;
        }

        return ($a['row']['estacion'] ?? $a['station']['estacion_nombre'] ?? '')
           <=> ($b['row']['estacion'] ?? $b['station']['estacion_nombre'] ?? '');
    }

    /**
     * Exporta el reporte "por facturación" estación por estación, en streaming,
     * para no retener el conjunto completo en memoria.
     *
     * @param list<array<string,mixed>> $stations
     * @param array<string,mixed>       $columnSearches
     * @return Generator<int,array<string,mixed>>
     * @throws RuntimeException si ninguna estación pudo consultarse.
     */
    public function stream_dispatches_by_billing_stations(
        int $from, int $until, array $stations, int $uuid, string|int $tipoCliente,
        string $orderColKey, string $orderDir,
        array $columnSearches = [], string $globalSearch = '',
        ?int $dispatchFrom = null, ?int $dispatchUntil = null
    ): Generator {
        $q = $this->stationBillingSql($uuid, $tipoCliente, $orderColKey, $orderDir, $columnSearches, $globalSearch, $dispatchFrom, $dispatchUntil);
        $params = array_merge([$from, $until], $q['typeParams'], $q['searchParams']);
        $query  = "{$q['cte']} SELECT * FROM CTE WHERE rn=1 {$q['typeWhere']} {$q['search']} ORDER BY {$q['order']} {$q['direction']}, despacho ASC";

        $ok = 0;
        foreach ($stations as $station) {
            try {
                $pdo  = $this->stationPdo($station);
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $ok++;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    yield $row;
                }
                $stmt->closeCursor();
                $pdo = null;
            } catch (Throwable $e) {
                error_log('dispatches_by_billing_station export failed station ' . (int) ($station['codigo'] ?? 0));
            }
        }
        if ($ok === 0) {
            throw new RuntimeException('No station was available');
        }
    }

    // =====================================================================
    // 4c. SALDOS POR ISLA (TABULADOR)
    // =====================================================================

    /**
     * Todo lo que necesita la pantalla de proceso del tabulador en UNA ida a la
     * base: saldos por isla, jarreos del turno e islas aún sin asignar.
     *
     * @param int              $Turno   Turno actual (11, 21, 31 o 41).
     * @param array|string|int $Islands Códigos de isla (array o "1,2,3").
     * @return array{saldos:list<array<string,mixed>>|false,samplings:list<array<string,mixed>>|false,islands:list<array<string,mixed>>|false}
     * @throws InvalidArgumentException si el turno o la lista de islas no son válidos.
     */
    public function get_tab_process_data(int $CodigoEstacion, int $FechaTabular, int $Turno, array|string|int $Islands, int $tabId): array
    {
        $linked  = $this->linkedServer($CodigoEstacion);
        $short   = $this->shortDb($CodigoEstacion);
        $db      = $this->stationDb($CodigoEstacion);
        $islands = DespachosSql::listaEnteros($Islands);

        $nrotur = $this->neighbourShifts($Turno)['previous'];
        $fch    = $nrotur === 41 ? $FechaTabular - 1 : $FechaTabular;

        $query = "
            -- Resultado 1: saldos por isla
            WITH IslasRemotas AS (
                SELECT den, cod AS codisl
                FROM OPENQUERY({$linked}, 'SELECT den, cod FROM {$short}.[Islas] WHERE codgas = {$CodigoEstacion};')
            ),
            DatosRemotos AS (
                SELECT codisl, Amount, Tipo
                FROM OPENQUERY({$linked}, '
                    SELECT codisl, CAST(SUM(mto) AS FLOAT) AS Amount, ''reportadas'' AS Tipo
                    FROM {$short}.[MovimientosTar]
                    WHERE fchmov = {$FechaTabular} AND codgas = {$CodigoEstacion} AND nrotur = {$Turno}
                        AND codisl IN ({$islands})
                        AND NOT (codbco = 0 AND tiptar IN (84, 68, 67, 72))
                    GROUP BY codisl

                    UNION ALL

                    SELECT t1.codisl, CAST(SUM(t1.mto) AS FLOAT) AS Amount, ''reportadas'' AS Tipo
                    FROM {$short}.[Despachos] t1
                    INNER JOIN {$short}.[Clientes] t2 ON t1.codcli = t2.cod
                    WHERE t1.fchtrn = {$FechaTabular} AND t1.codgas = {$CodigoEstacion} AND t1.nrotur = {$Turno}
                        AND t1.codisl IN ({$islands})
                        AND t2.tipval IN (3, 4)
                    GROUP BY t1.codisl

                    UNION ALL

                    SELECT t2.codisl, COALESCE(t2.amount, 0) AS Amount, ''totales'' AS Tipo
                    FROM {$short}.[Medicion] t1
                    LEFT JOIN (
                        SELECT nrobom, COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount, codprd, codisl
                        FROM {$short}.[Despachos]
                        WHERE fchcor = {$FechaTabular} AND nrotur = {$Turno}
                            AND codprd IN (1,2,3,179,180,181,192,193) AND codisl IN ({$islands})
                        GROUP BY nrobom, codprd, codisl
                    ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                    WHERE t1.fch = {$fch} AND t1.nrotur = {$nrotur} AND t1.codisl IN ({$islands})

                    UNION ALL

                    SELECT codisl, COALESCE(mto, 0) AS Amount, ''totales'' AS Tipo
                    FROM {$short}.[Despachos]
                    WHERE fchcor = {$FechaTabular} AND nrotur = {$Turno}
                        AND codisl IN ({$islands}) AND codprd NOT IN (0,179,180,181,192,193)

                    UNION ALL

                    SELECT 0 AS codisl, CAST(SUM(mto) AS FLOAT) AS Amount, ''productos'' AS Tipo
                    FROM {$short}.[Despachos]
                    WHERE fchtrn = {$FechaTabular} AND codgas = {$CodigoEstacion} AND nrotur = {$Turno}
                        AND codprd NOT IN (0, 179, 180, 181)
                ') AS RemoteRows
            ),
            VentasReportadas AS (
                SELECT codisl, SUM(Amount) AS TotalVentasReportadas
                FROM (
                    SELECT codisl, Amount FROM DatosRemotos WHERE Tipo = 'reportadas'
                    UNION ALL
                    SELECT t1.Isla AS codisl, COALESCE(SUM(t1.Monto), 0) AS Amount
                    FROM [TG].[dbo].[TabuladorDetalle] t1
                    WHERE t1.Id = {$tabId} AND t1.CodigoValor IN (6, 192) AND t1.Isla IN ({$islands})
                    GROUP BY t1.Isla
                ) AS combined
                GROUP BY codisl
            ),
            VentasTotales AS (
                SELECT codisl, SUM(Amount) AS TotalVentas
                FROM DatosRemotos
                WHERE Tipo = 'totales' AND codisl IS NOT NULL
                GROUP BY codisl
            ),
            TotalProductos AS (
                SELECT COALESCE(SUM(Amount), 0) AS TotalProductos
                FROM DatosRemotos WHERE Tipo = 'productos'
            )
            SELECT ir.codisl, ir.den Isla,
                COALESCE(vr.TotalVentasReportadas, 0) AS TotalVentasReportadas,
                COALESCE(vt.TotalVentas, 0) AS TotalVentas,
                COALESCE(vr.TotalVentasReportadas, 0) - COALESCE(vt.TotalVentas, 0) AS Diferencia,
                tp.TotalProductos
            FROM IslasRemotas ir
            LEFT JOIN VentasReportadas vr ON ir.codisl = vr.codisl
            LEFT JOIN VentasTotales vt ON ir.codisl = vt.codisl
            CROSS JOIN TotalProductos tp
            ORDER BY ir.codisl;

            -- Resultado 2: jarreos (muestras de turno)
            SELECT * FROM OPENQUERY({$linked}, 'SELECT TOP 100 d.nrotrn AS Transaccion
                , d.codgas AS Gasolinera
                , CONVERT(VARCHAR, CAST(d.fchcor as DATETIME) -1, 23) AS Fecha
                , SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 2, 2) + '':'' + SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 4, 2) Hora
                , d.nrotur AS Turno
                , d.nrobom AS Bomba
                , d.codprd AS Producto
                , LTRIM(p.den) AS Descripcion
                , ROUND(d.can, 3) AS Cantidad
                , d.mto AS Total
            FROM {$short}.Despachos d(NOLOCK)
                INNER JOIN {$short}.Productos p(NOLOCK) ON d.codprd = p.cod
            WHERE d.codgas = {$CodigoEstacion} AND d.fchcor = {$FechaTabular}
                AND d.tiptrn IN(65, 74) AND d.nrotur = {$Turno}
            ORDER BY nrotrn DESC;');

            -- Resultado 3: islas disponibles (sin asignación en este tabulador)
            SELECT t1.cod, t1.den Isla, t1.codgas
            FROM {$db}.[Islas] t1
            LEFT JOIN (SELECT Isla FROM [TG].[dbo].[Asignaciones] WHERE IdTabulador = {$tabId}) t2 ON t1.cod = t2.Isla
            WHERE t1.codgas = {$CodigoEstacion} AND t2.Isla IS NULL;
        ";

        $results = $this->sql->selectMultiple($query, []);
        return [
            'saldos'    => ($results[0] ?? []) ?: false,
            'samplings' => ($results[1] ?? []) ?: false,
            'islands'   => ($results[2] ?? []) ?: false,
        ];
    }

    /**
     * Saldo de UNA isla (misma lógica que get_tab_process_data pero a nivel isla),
     * para recalcular una sola fila de la pantalla.
     *
     * @param int $fch    Fecha del corte ANTERIOR (entero de fecha).
     * @param int $nrotur Turno ANTERIOR (11, 21, 31 o 41).
     * @return list<array<string,mixed>>|false
     */
    public function get_saldos_isla(int $CodigoEstacion, int $fch, int $nrotur, int $Island, int $FechaTabular, int $Turno, int $tabId): array|false
    {
        $linked = $this->linkedServer($CodigoEstacion);
        $short  = $this->shortDb($CodigoEstacion);

        // El turno siguiente al del corte; si cruza a las 11 es el día siguiente.
        $next_shift = $this->neighbourShifts($nrotur)['next'];
        $next_date  = $next_shift === 11 ? $fch + 1 : $fch;

        $islaEspecifica = $Island;
        $query = "
            WITH VentasReportadas AS ( -- Paso 1: Consulta de Ventas Reportadas Agrupadas por Isla
                SELECT
                    codisl,
                    SUM(Total) AS TotalVentasReportadas
                FROM (
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM OPENQUERY({$linked}, '
                        SELECT
                            t1.codisl,
                            t1.mto
                        FROM {$short}.[MovimientosTar] t1
                        LEFT JOIN {$short}.[Islas] t2 ON t1.codisl = t2.cod
                        WHERE
                            t1.fchmov = {$FechaTabular} AND
                            t1.codgas = {$CodigoEstacion} AND
                            t1.nrotur = {$Turno} AND
                            t1.codisl = {$islaEspecifica} AND
                            NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                    ') t1
                    GROUP BY
                        t1.codisl
        
                    UNION ALL
        
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM OPENQUERY({$linked}, '
                        SELECT
                            t1.codisl,
                            t1.mto
                        FROM {$short}.[Despachos] t1
                        LEFT JOIN {$short}.[Clientes] t2 ON t1.codcli = t2.cod
                        LEFT JOIN {$short}.[Islas] t4 ON t1.codisl = t4.cod
                        WHERE
                            t1.fchtrn = {$FechaTabular} AND
                            t1.codgas = {$CodigoEstacion} AND
                            t1.nrotur = {$Turno} AND
                            t1.codisl = {$islaEspecifica} AND
                            t2.tipval IN (3, 4)
                    ') t1
                    GROUP BY
                        t1.codisl
        
                    UNION ALL
        
                    SELECT
                        t1.Isla AS codisl,
                        COALESCE(SUM(t1.Monto), 0) AS Total
                    FROM [TG].[dbo].[TabuladorDetalle] t1
                    RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                    WHERE
                        t1.Id = {$tabId} AND
                        t1.CodigoValor IN (6, 192) AND
                        t1.Isla = {$islaEspecifica}
                    GROUP BY
                        t1.Isla
                ) AS combined
                GROUP BY
                    codisl
            ),
        
            VentasTotales AS ( -- Paso 2: Consulta de Ventas Totales Agrupadas por Isla
            
                SELECT
                    codisl,
                    SUM(finalAmount) AS TotalVentas
                FROM OPENQUERY({$linked}, '
                    SELECT
                        COALESCE(t2.amount, 0) AS finalAmount,
                        t2.codisl
                    FROM
                        {$short}.[Medicion] t1
                        LEFT JOIN (
                            SELECT nrobom, COALESCE(SUM(can), 0) AS volume, COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount, codprd, codisl
                            FROM {$short}.[Despachos]
                            WHERE
                                fchcor = {$next_date}
                                AND nrotur = {$next_shift}
                                AND codprd IN (1,2,3,179,180,181,192,193)
                                AND codisl = {$islaEspecifica}
                            GROUP BY nrobom, codprd, codisl
                        ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                    WHERE
                        t1.fch = {$fch} AND t1.nrotur = {$nrotur} AND t1.codisl = {$islaEspecifica}
        
                    UNION ALL
        
                    SELECT
                        COALESCE(t1.mto, 0) AS finalAmount,
                        t1.codisl
                    FROM {$short}.[Despachos] t1
                    WHERE t1.fchcor = {$next_date} AND t1.nrotur = {$next_shift} AND t1.codisl = {$islaEspecifica} AND t1.codprd NOT IN (0,179,180,181,192,193)
                ') AS SubQuery
                WHERE codisl IS NOT NULL
                GROUP BY codisl
            )
        
            SELECT -- Paso 3: Combinar las CTEs y Calcular la Diferencia
                '{$islaEspecifica}' AS codisl,
                ir.den Isla,
                COALESCE(vr.TotalVentasReportadas, 0) AS TotalVentasReportadas,
                COALESCE(vt.TotalVentas, 0) AS TotalVentas,
                COALESCE(vr.TotalVentasReportadas, 0) - COALESCE(vt.TotalVentas, 0) AS Diferencia
            FROM (
                SELECT '{$islaEspecifica}' AS codisl, den
                FROM OPENQUERY({$linked}, 'SELECT den, cod FROM {$short}.[Islas] WHERE codgas = {$CodigoEstacion} AND cod = {$islaEspecifica};')
            ) ir
            LEFT JOIN VentasReportadas vr ON ir.codisl = vr.codisl
            LEFT JOIN VentasTotales vt ON ir.codisl = vt.codisl
            ORDER BY ir.codisl;
        ";
        return $this->fetchAll($query);
    }

    // =====================================================================
    // 5b. DESPACHOS CRÉDITO / DÉBITO: PENDIENTES Y LIBERADOS
    // =====================================================================

    /**
     * Despachos de crédito del día/turno con su estado de liberación (y quién
     * los verificó). Lee del central.
     *
     * @param string $from  Fecha del corte ('Y-m-d' o cualquier formato que acepte dateToInt()).
     * @param int    $shift 0 = todos los turnos; 1..4 = turno.
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_dispatches_to_release(string $from, int $codgas, int $shift): array|false
    {
        return $this->dispatchesToRelease(DespachosSql::TIPVAL_CREDITO, $from, $codgas, $shift);
    }

    /**
     * Igual que get_credit_dispatches_to_release() para clientes de débito.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_debit_dispatches_to_release(string $from, int $codgas, int $shift): array|false
    {
        return $this->dispatchesToRelease(DespachosSql::TIPVAL_DEBITO, $from, $codgas, $shift);
    }

    /**
     * Despachos de un tipo de cliente (3 crédito / 4 débito) del corte, con su
     * registro de liberación si existe.
     *
     * @return list<array<string,mixed>>|false
     */
    private function dispatchesToRelease(int $tipval, string $from, int $codgas, int $shift): array|false
    {
        $query = "
        DECLARE @Fecha INT = ?;
        DECLARE @shift INT = ?;
        SELECT
            t5.id,
            t1.nrotrn Despacho,
            t4.abr Estacion,
            t7.den Isla,
            t1.codcli,
            t2.den AS Cliente,
            t1.can Volumen,
            t1.mto Monto,
            t1.nroveh,
            t1.rut,
            COALESCE(t5.notes, '--') AS notes,
            CASE t2.tipval
                WHEN 3 THEN N'Crédito'
                WHEN 4 THEN N'Débito'
            END AS Tipo,
            CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
            CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            (t1.nrotur / 10) AS turno,
            t3.abr,
            t3.den Producto,
            COALESCE(Nombre, 'Sin verificar') AS Verificador,
            CASE
                WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t5.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t5.created_at AS DATE)) > 2
                THEN 1
                ELSE 0
            END AS incidencia
        FROM
            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            LEFT JOIN [TG].[dbo].[despachos_liberados] t5 ON t1.codgas = t5.codgas AND t1.nrotrn = t5.nrotrn
            LEFT JOIN [TG].[dbo].[Usuario] t6 ON t5.codemp = t6.Id
            LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
        WHERE
            t1.fchcor = @Fecha
            AND t1.codcli > 0
            AND t2.tipval IN ($tipval)
            AND t1.codgas = ?
            AND (
                @shift = 0
                OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
            )
            AND t1.mto > 0
        ";
        return $this->fetchAll($query, [dateToInt($from), $shift, $codgas]);
    }

    /**
     * Despachos YA liberados del día/turno (leídos de [TG].[despachos_liberados]).
     *
     * @param string $from          Fecha del corte (formato que acepte dateToInt()).
     * @param int    $shift         0 = todos los turnos; 1..4 = turno.
     * @param string|null $dispatch_type 'Payworks' => tipval 0; cualquier otro (o null) => crédito y débito.
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_and_debit_dispatches_released(string $from, int $codgas, int $shift, ?string $dispatch_type): array|false
    {
        $tipvals = $dispatch_type === 'Payworks' ? '0' : '3, 4';

        $query = "DECLARE @Fecha INT = ?;
                  DECLARE @shift INT = ?;
                  SELECT
                    t1.id,
                    t1.nrotrn Despacho,
                    t4.abr Estacion,
                    t7.den Isla,
                    t1.codcli,
                    t2.den AS Cliente,
                    t1.can Volumen,
                    t1.mto Monto,
                    COALESCE(t1.notes, '--') AS notes,
                    CASE t2.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    t1.hratrn AS hora_formateada,
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    (t1.nrotur / 10) AS turno,
                    t3.abr,
                    t3.den Producto,
                    COALESCE(t5.Nombre, 'Sin verificar') AS Verificador,
                    CASE
                        WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t1.created_at AS DATE)) > 2
                        THEN 1
                        ELSE 0
                    END AS incidencia
                  FROM
                    [TG].[dbo].[despachos_liberados] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
                    LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
                    LEFT JOIN [TG].[dbo].[Usuario] t5 ON t1.codemp = t5.Id
                    LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                  WHERE
                    fchcor = @Fecha AND
                    t1.codgas = ? AND
                    (
                        @shift = 0
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                    AND t1.tipval IN ($tipvals)
                  ;";
        return $this->fetchAll($query, [dateToInt($from), $shift, $codgas]);
    }

    /**
     * Despachos de crédito del corte que AÚN no se han liberado (sin registro
     * en [TG].[despachos_liberados]).
     *
     * @param string $from  Fecha del corte (formato que acepte dateToInt()).
     * @param int    $shift 0 = todos los turnos; 1..4 = turno.
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_dispatches_just_to_release(string $from, int $codgas, int $shift): array|false
    {
        return $this->dispatchesJustToRelease(DespachosSql::TIPVAL_CREDITO, $from, $codgas, $shift);
    }

    /**
     * Igual que get_credit_dispatches_just_to_release() para clientes de débito.
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_debit_dispatches_just_to_release(string $from, int $codgas, int $shift): array|false
    {
        return $this->dispatchesJustToRelease(DespachosSql::TIPVAL_DEBITO, $from, $codgas, $shift);
    }

    /**
     * Despachos de un tipo de cliente (3 crédito / 4 débito) del corte SIN registro
     * de liberación. Anti-join contra [TG].[despachos_liberados] por (nrotrn, codgas).
     *
     * @return list<array<string,mixed>>|false
     */
    private function dispatchesJustToRelease(int $tipval, string $from, int $codgas, int $shift): array|false
    {
        $query = "DECLARE @Fecha INT = ?;
                  DECLARE @shift INT = ?;
                  SELECT
                    t2.id,
                    t1.nrotrn Despacho,
                    t4.abr Estacion,
                    t7.den Isla,
                    t1.codcli,
                    t6.den AS Cliente,
                    t1.can Volumen,
                    t1.mto Monto,
                    COALESCE(t2.notes, '--') AS notes,
                    CASE t6.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    (t1.nrotur / 10) AS turno,
                    t5.abr,
                    t5.den Producto,
                    'Sin verificar' AS Verificador,
                    CASE
                        WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t2.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t2.created_at AS DATE)) > 2
                        THEN 1
                        ELSE 0
                    END AS incidencia
                  FROM SG12.dbo.Despachos t1
                    LEFT JOIN (SELECT * FROM [TG].[dbo].[despachos_liberados] WHERE fchcor = @Fecha AND codgas = ?) t2 ON t1.nrotrn = t2.nrotrn AND t1.codgas = t2.codgas
                    LEFT JOIN SG12.dbo.Clientes t3 ON t1.codcli = t3.cod
                    LEFT JOIN SG12.dbo.Gasolineras t4 ON t1.codgas = t4.cod
                    LEFT JOIN SG12.dbo.Productos t5 ON t1.codprd = t5.cod
                    LEFT JOIN SG12.dbo.Clientes t6 ON t1.codcli = t6.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                  WHERE
                    t2.nrotrn IS NULL
                    AND t2.codgas IS NULL
                    AND t1.fchcor = @Fecha
                    AND t1.codgas = ?
                    AND (
                        @shift = 0
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                    AND t1.codcli > 0
                    AND t3.tipval IN ($tipval)
                    AND t1.mto > 0
                  ORDER BY t1.nrotrn ASC;";
        return $this->fetchAll($query, [dateToInt($from), $shift, $codgas, $codgas]);
    }

    /**
     * Despachos de crédito/débito del día (todas las estaciones) que aún no se han liberado.
     *
     * @param int $from Fecha de corte (entero de fecha).
     * @return list<array<string,mixed>>|false
     */
    public function get_all_dispatches_just_to_release(int $from): array|false
    {
        $query = "DECLARE @Fecha INT = ?;
                  SELECT
                    dl.id,
                    d.nrotrn AS Despacho,
                    g.abr AS Estacion,
                    i.den AS Isla,
                    d.codcli,
                    c.den AS Cliente,
                    d.can AS Volumen,
                    d.mto AS Monto,
                    COALESCE(dl.notes, '--') AS notes,
                    CASE c.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, d.hratrn % 100, DATEADD(HOUR, d.hratrn / 100, 0)), 108) AS hora_formateada,
                    CONVERT(VARCHAR(10), DATEADD(DAY, -1, CAST(d.fchtrn AS DATETIME)), 23) AS Fecha,
                    (d.nrotur / 10) AS turno,
                    p.abr,
                    p.den AS Producto,
                    'Sin verificar' AS Verificador,
                    CASE
                        WHEN dl.fchcor IS NOT NULL AND DATEDIFF(DAY, DATEADD(DAY, -1, CAST(dl.fchcor AS DATETIME)), CAST(dl.created_at AS DATE)) > 2
                        THEN 1
                        ELSE 0
                    END AS incidencia
                FROM SG12.dbo.Despachos d
                LEFT JOIN (
                    SELECT *
                    FROM [TG].[dbo].[despachos_liberados]
                    WHERE fchcor = @Fecha
                ) dl ON d.nrotrn = dl.nrotrn AND d.codgas = dl.codgas
                LEFT JOIN SG12.dbo.Clientes c ON d.codcli = c.cod
                LEFT JOIN SG12.dbo.Gasolineras g ON d.codgas = g.cod
                LEFT JOIN SG12.dbo.Productos p ON d.codprd = p.cod
                LEFT JOIN [SG12].[dbo].[Islas] i ON d.codisl = i.cod
                WHERE
                    dl.nrotrn IS NULL
                    AND d.fchcor = @Fecha
                    AND d.codcli > 0
                    AND c.tipval IN (3, 4)
                    AND d.mto > 0
                ORDER BY d.nrotrn ASC;";
        return $this->fetchAll($query, [$from]);
    }

    /**
     * Cobros con tarjeta (Payworks) del día/turno, con su estado de liberación.
     *
     * @param int $fch      Fecha del corte (entero de fecha).
     * @param int $nrotur   0 = todos los turnos; 1..4 = turno.
     * @return list<array<string,mixed>>|false
     */
    public function get_payworks_dispatches_to_release(int $codgas, int $fch, int $nrotur): array|false
    {
        $query = "
            DECLARE @fch INT = ?;
            DECLARE @shift INT = ?;
            SELECT
                t6.id,
                t1.nrotrn Despacho,
                t5.den Estacion,
                t7.den Isla,
                t2.codcli,
                t4.den AS Cliente,
                t3.cod,
                t3.den Tipo,
                t1.mto Monto,
                t2.can Volumen,
                t2.rut,
                t2.nroveh,
                COALESCE(t6.notes, '--') AS notes,
                CONVERT(VARCHAR(5), DATEADD(MINUTE, t2.hratrn % 100, DATEADD(HOUR, t2.hratrn / 100, 0)), 108) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                (t1.nrotur / 10) AS turno,
                t8.abr,
                t8.den Producto,
                COALESCE(t9.Nombre, 'Sin verificar') AS Verificador,
                CASE
                    WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t6.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t6.created_at AS DATE)) > 2
                    THEN 1
                    ELSE 0
                END AS incidencia
            FROM [SG12].[dbo].[MovimientosTar] t1
                LEFT JOIN [SG12].[dbo].[Despachos] t2 ON t1.nrotrn = t2.nrotrn AND t1.codgas = t2.codgas
                LEFT JOIN [SG12].[dbo].[Valores] t3 ON t1.codbco = t3.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t4 ON t2.codcli = t4.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t5 ON t1.codgas = t5.cod
                LEFT JOIN [TG].[dbo].[despachos_liberados] t6 ON t1.nrotrn= t6.nrotrn AND t1.codgas = t6.codgas
                LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                LEFT JOIN [SG12].[dbo].[Productos] t8 ON t2.codprd = t8.cod
                LEFT JOIN [TG].[dbo].[Usuario] t9 ON t6.codemp = t9.Id
            WHERE
                t1.fchmov = @fch
                AND t1.codgas = ?
                AND (
                        @shift = 0
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72)) AND t3.cod NOT IN (-1128) AND t1.mto > 0
            ORDER BY t2.nrotrn ASC;
        ";
        return $this->fetchAll($query, [$fch, $nrotur, $codgas]);
    }

    // =====================================================================
    // 7. VENTAS POR ESTACIÓN / HORA Y DASHBOARDS
    // =====================================================================

    /**
     * Ventas de combustible por día, estación y producto en un rango de fechas de corte.
     *
     * @param int $codgas Estación (0 = todas).
     * @param int $codprd Producto (0 = los 5 combustibles principales).
     * @return list<array<string,mixed>>|false
     */
    public function get_sales_stations(int $from, int $until, int $codgas, int $codprd): array|false
    {
        $whereProducto = $codprd === 0
            ? 'AND t1.codprd IN (179,180,181,192,193)'
            : "AND t1.codprd = $codprd";
        $whereStation = $codgas === 0 ? '' : "AND t1.codgas = $codgas";

        $query = "
        DECLARE @from INT = $from;
        DECLARE @until INT = $until;

        WITH Datos AS (
            SELECT
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) -1, 23) AS VARCHAR(10)) AS Fecha,
                t1.fchcor AS FechaCompleta, -- Agregar la fecha completa
                t4.cod AS CodigoGasolinera, -- Agregar el código de la gasolinera
                t4.abr AS Gasolinera,
                t2.cod AS CodigoProducto, -- Agregar el código del producto
                t2.den AS Producto,
                COUNT(*) AS TotalDespachos,
                SUM(t1.can) AS Volumen,
                ROUND(SUM(t1.mto), 2) AS Importe,
                ROUND(SUM(CASE WHEN t3.tipval = 3 THEN t1.can ELSE 0 END), 3) AS Credito,
                ROUND(SUM(CASE WHEN t3.tipval = 4 THEN t1.can ELSE 0 END), 3) AS Debito,
                MIN(CASE WHEN t1.pre > 0 AND t1.codprd IN (179, 180, 181, 192, 193) THEN t1.pre ELSE NULL END) AS precio_minimo,
                MAX(CASE WHEN t1.pre > 0 AND t1.codprd IN (179, 180, 181, 192, 193) THEN t1.pre ELSE NULL END) AS precio_maximo
            FROM
                [SG12].[dbo].[Despachos] t1
                LEFT JOIN [SG12].[dbo].[Productos] t2 ON t1.codprd = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            WHERE
                t1.fchcor BETWEEN @from AND @until
                $whereProducto
                $whereStation
            GROUP BY
                t1.fchcor, t1.codgas, t4.abr, t4.cod, t2.cod, t2.den
        )

        SELECT
            Fecha,
            LTRIM(Gasolinera) AS Gasolinera,
            CodigoProducto,
            CodigoGasolinera,
            FechaCompleta,
            LTRIM(Producto) AS Producto,
            TotalDespachos,
            ROUND(Volumen, 3) AS Volumen,
            CASE
                WHEN ROUND(precio_minimo, 2) < ROUND(precio_maximo, 2) THEN CONCAT(precio_minimo, ' - ', precio_maximo)
                ELSE CAST(precio_maximo AS VARCHAR)
            END AS Precio,
            Importe,
            Credito,
            Debito
        FROM
            Datos
        ORDER BY Fecha ASC, Gasolinera DESC OPTION (RECOMPILE);";

        return $this->fetchAll($query);
    }

    /**
     * Despachos individuales de una estación/producto/fecha de corte (detalle de get_sales_stations).
     *
     * @return list<array<string,mixed>>|false
     */
    public function get_sales_details(int $fch, int $codgas, int $prd): array|false
    {
        $query = "
        SELECT
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) -1, 23) AS VARCHAR(10)) AS Fecha,
                t1.fchcor AS FechaCompleta, -- Agregar la fecha completa
                t4.cod AS CodigoGasolinera, -- Agregar el código de la gasolinera
                t4.abr AS Gasolinera,
                t2.cod AS CodigoProducto, -- Agregar el código del producto
                t2.den AS Producto,
                t1.can AS Volumen,
                ROUND(t1.mto, 2) AS Importe,
                t1.pre Precio,
                CASE
                    WHEN t3.tipval = 3 THEN 'Crédito'
                    WHEN t3.tipval = 4 THEN 'Débito'
                    ELSE 'Otro'
                END AS Tipo
            FROM
                [SG12].[dbo].[Despachos] t1
                LEFT JOIN [SG12].[dbo].[Productos] t2 ON t1.codprd = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            WHERE
                t1.fchcor = ?
                AND t1.codgas = ?
                AND t1.codprd = ?
        ";
        return $this->fetchAll($query, [$fch, $codgas, $prd]);
    }

    /**
     * Venta en efectivo (clientes que no son crédito/débito) por hora y día, en
     * formato pivot: una fila por hora y una columna por fecha.
     *
     * Ya no se une a MovimientosTar, Valores ni ClientesValores: sus columnas no
     * se usaban y un despacho con varios renglones relacionados se sumaba varias veces.
     *
     * @param string $fromDate  'Y-m-d' (inclusive).
     * @param string $untilDate 'Y-m-d' (inclusive).
     * @return list<array<string,mixed>>
     * @throws InvalidArgumentException si las fechas no son 'Y-m-d' válidas.
     */
    public function sales_cash_hour_table(string $fromDate, string $untilDate, int $codgas): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $untilDate);
        if ($start === false || $end === false) {
            throw new InvalidArgumentException('Las fechas deben tener formato Y-m-d.');
        }
        if ($start > $end) {
            return [];
        }
        $from_int  = dateToInt($fromDate);
        $until_int = dateToInt($untilDate);

        // Una columna por día para el PIVOT (los nombres salen de DateTime::format, no del usuario).
        $dates = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }
        $columnsList = implode(',', array_map(static fn (string $d): string => "[$d]", $dates));
        $selectList  = implode(',', array_merge(
            ['Hora'],
            array_map(static fn (string $d): string => "ISNULL([$d], 0) AS [$d]", $dates)
        ));

        $query = "
            WITH datosDespachos AS (
                SELECT
                    DATEPART(HOUR, CAST(DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)) AS TIME)) AS Hora,
                    CONVERT(VARCHAR, DATEADD(DAY, t1.fchtrn - 1, '19000101'), 23) AS FechaTexto,
                    t1.mto
                FROM [SG12].[dbo].[Despachos] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                WHERE
                    t1.codgas = $codgas
                    AND (t2.tipval NOT IN (3, 4) OR t2.tipval IS NULL)
                    AND t1.tar >= 0
                    AND t1.fchtrn BETWEEN $from_int AND $until_int

            ),
            resumen AS (
                SELECT Hora, FechaTexto, SUM(ISNULL(mto, 0)) AS TotalMto
                FROM datosDespachos
                GROUP BY Hora, FechaTexto
            )
            SELECT $selectList
            FROM (
                SELECT Hora, FechaTexto, TotalMto
                FROM resumen
            ) AS SourceTable
            PIVOT (
                SUM(TotalMto)
                FOR FechaTexto IN ($columnsList)
            ) AS PivotTable
            ORDER BY Hora;
        ";

        return $this->sql->select($query, []);
    }

    /**
     * Dashboard crédito/débito: monto, volumen y participación acumulada por cliente.
     *
     * @param int                         $from    Fecha inicial de corte (entero de fecha).
     * @param int                         $until   Fecha final de corte (entero de fecha).
     * @param int|string|array<int|string> $clients Códigos de cliente (array o "1,2,3"); 0 = todos.
     * @return list<array<string,mixed>>|false
     * @throws InvalidArgumentException si la lista de clientes trae algo que no sea entero.
     */
    public function get_dashboard_debit_credit(int $from, int $until, int|string|array $clients): array|false
    {
        $whereClients = $this->clientsFilter($clients);

        $query = "
        -- Rango de fechas de corte
        DECLARE @from INT = $from;
        DECLARE @until INT = $until;
        -- CTE para agrupar y sumar datos relevantes
        WITH Clientes AS (
            SELECT
                t2.den Clientes, -- Nombre del cliente
                SUM(t1.mto) MontosTotales, -- Suma total de los montos
                SUM(t1.can) VolumenesTotales, -- Suma total de los volúmenes
                COUNT(*) TotalRegistros, -- Conteo total de registros
                CASE
                    WHEN t2.tipval = 3 THEN N'Crédito' -- Asigna Crédito si tipval es 3
                    WHEN t2.tipval = 4 THEN N'Débito' -- Asigna Débito si tipval es 4
                END Tipos
            FROM
                [SG12].[dbo].[Despachos] t1 WITH (NOLOCK) -- Tabla de despachos con NOLOCK para evitar bloqueos
                INNER JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod -- Unión con la tabla de clientes
                LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod -- Unión opcional con la tabla de gasolineras
            WHERE
                t1.fchcor BETWEEN @from AND @until -- Rango de fechas
                AND t2.tipval IN (3, 4) -- Filtra por tipos de valor 3 (Crédito) o 4 (Débito)
                AND t1.mto > 0 -- Filtra registros con monto mayor a 0
                AND t1.can > 0 -- Filtra registros con cantidad mayor a 0
                $whereClients -- Filtra por clientes específicos
            GROUP BY t2.den, t2.tipval -- Agrupa por nombre de cliente y tipo de transacción
        )
        -- Selección final con cálculos adicionales
        SELECT
            Clientes, -- Nombre del cliente
            Tipos, -- Tipo de transacción (Crédito o Débito)
            VolumenesTotales, -- Suma total de los volúmenes
            -- Acumulado de VolumenesTotales dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(VolumenesTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) AcumuladoVolumenesTotales,
            TotalRegistros, -- Conteo total de registros
            -- Acumulado de TotalRegistros dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(TotalRegistros) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) AcumuladoRegistros,
            MontosTotales, -- Suma total de los montos
            -- Acumulado de MontosTotales dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(MontosTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) TotalesGenerales,
            -- Porcentaje de MontosTotales respecto al total de MontosTotales dentro de cada tipo
            (MontosTotales / SUM(MontosTotales) OVER (PARTITION BY Tipos)) * 100 Porcentaje,
            -- Porcentaje acumulado de MontosTotales dentro de cada tipo, ordenado por MontosTotales descendente
            (SUM(MontosTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) / SUM(MontosTotales) OVER (PARTITION BY Tipos)) * 100 Acumulado
        FROM
            Clientes -- Utiliza el CTE Clientes
        ORDER BY
            MontosTotales DESC; -- Ordena por monto total descendente
        ";

        return $this->fetchAll($query);
    }

    /**
     * Monto crédito/débito por mes (gráfica del dashboard).
     *
     * MontosCredito suma clientes crédito (tipval 3) y MontosDebito clientes
     * débito (tipval 4). (Antes estaban cruzadas; la gráfica rotulaba al revés.)
     *
     * @param int|string|array<int|string> $clients Códigos de cliente (array o "1,2,3"); 0 = todos.
     * @return list<array<string,mixed>>|false
     */
    public function get_graph1(int $from, int $until, int|string|array $clients): array|false
    {
        $whereClients = $this->clientsFilter($clients);

        $query = "
            DECLARE @from INT = $from;
            DECLARE @until INT = $until;
            SELECT
                 YEAR(d)                 AS [Year],
                    MONTH(d) AS [Month],
                    DATENAME(MONTH, d) AS [MonthName],
                SUM(CASE WHEN t2.tipval = 4 THEN t1.mto ELSE 0 END) AS MontosDebito,
                SUM(CASE WHEN t2.tipval = 3 THEN t1.mto ELSE 0 END) AS MontosCredito
            FROM
                [SG12].[dbo].[Despachos] t1
            INNER JOIN
                SG12.dbo.Clientes t2 ON t1.codcli = t2.cod
                CROSS APPLY (SELECT DATEADD(DAY, TRY_CONVERT(INT, t1.fchcor), '1899-12-30')) AS x(d)

            WHERE
                t2.tipval IN (3, 4) AND
                t1.fchcor BETWEEN @from AND @until
                $whereClients
           GROUP BY YEAR(d), MONTH(d), DATENAME(MONTH, d)
            ORDER BY YEAR(d), MONTH(d);
        ";
        return $this->fetchAll($query);
    }

    /**
     * Filtro "AND t1.codcli IN (...)" a partir de la selección de clientes de
     * los dashboards; 0 / '0' / lista vacía significan "todos".
     *
     * @param int|string|array<int|string> $clients
     * @throws InvalidArgumentException si algún código no es entero.
     */
    private function clientsFilter(int|string|array $clients): string
    {
        if (is_array($clients)) {
            return $clients === [] ? '' : 'AND t1.codcli IN (' . DespachosSql::listaEnteros($clients) . ')';
        }
        $clients = trim((string) $clients);
        return ($clients === '' || $clients === '0') ? '' : 'AND t1.codcli IN (' . DespachosSql::listaEnteros($clients) . ')';
    }

    /**
     * Cartera de crédito de ValesR (formato ancho para el reporte de crédito).
     * Usa funciones escalares de la base ("dbo.Serie"), por lo que las tablas van sin prefijo.
     *
     * @param string $from  Fecha inicial en cualquier formato que entienda strtotime() (se normaliza a Y-m-d).
     * @param string $until Fecha final.
     * @return list<array<string,mixed>>|false
     */
    public function get_credit_dispatches(string $from, string $until): array|false
    {
        // date() produce solo dígitos y guiones: seguro para interpolar.
        $from  = date('Y-m-d', strtotime(str_replace('/', '-', $from)));
        $until = date('Y-m-d', strtotime(str_replace('/', '-', $until)));
        $query = "
                DECLARE @Inicial date = '{$from}';
                DECLARE @Final   date = '{$until}';
                DECLARE @fini int  = DATEDIFF(day,0,@Inicial);
                DECLARE @ffin int  = DATEDIFF(day,0,DATEADD(day,1,@Final));

                SELECT @fini = DATEDIFF(dd, 0, @Inicial)+1
                SELECT @ffin = DATEDIFF(dd, 0, @Final)+1

                Select 
                convert(varchar,convert(smalldatetime,fch,100)-1,103) as [date],
                t2.abr as station,
                t3.codext as cod_client,
                t3.den as client, 
                Case t3.tipval when 3 then 'Credito' when 4 then 'Debito' END as Tipo,
                Case t1.nrotur when 11 then 1 when 21 then 2 when 31 then 3 when 41 then 4 else t1.nrotur END as Turno,
                t4.den as [product],
                t1.can as Cantidad,
                val as Valor,t1.sec*-1 dispatch,
                imp as import,
                dbo.Serie(t1.nrofac) as series, 
                (t1.nrofac-(t1.nrofac/100000000*100000000)) as nrofac ,
                t5.can as can
                from ValesR t1 Inner Join Gasolineras t2 
                ON t1.codgas=t2.cod Inner Join Clientes t3
                ON t1.codcli=t3.cod Inner Join Productos t4
                ON t1.codprd=t4.cod
                Left join Despachos t5 ON ABS(t1.sec)=t5.nrotrn and t1.codgas=t5.codgas
                where t1.fch>=@fini and t1.fch<=@ffin and t3.tipval=3
                order by t1.fch,t3.tipval
                ";
        return $this->fetchAll($query);
    }

    // =====================================================================
    // 8. FACTURACIÓN GLOBAL Y CFDI
    // =====================================================================

    /**
     * Facturas globales (cliente 21701354) emitidas por estación y su estado de
     * vigencia: "Incorrecto" si la factura global agrupa despachos de un mes
     * distinto al de su fecha de vigencia.
     *
     * @param int                       $from     Fecha inicial de factura (entero de fecha).
     * @param int                       $until    Fecha final de factura (entero de fecha).
     * @param list<array<string,mixed>> $stations Estaciones (codigo, servidor, base_datos, estacion_nombre).
     * @param int|string               $status   0 = solo las incorrectas; cualquier otro valor = todas.
     * @return list<array<string,mixed>>|false
     */
    public function overal_invoice_out_table(int $from, int $until, array $stations, int|string $status): array|false
    {
        $estusQuery = '';
        if ((string) $status === '0') {
            $estusQuery = " AND (
                            CASE
                                WHEN NOT EXISTS (
                                    SELECT 1
                                    FROM STRING_SPLIT(t3.FechasConcatenadas, '','') fechas
                                    WHERE DATEPART(MONTH, CAST(fechas.value AS DATE)) != DATEPART(MONTH,  CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23))
                                )
                                THEN ''Correcto''
                                ELSE ''Incorrecto''
                            END
                        ) = ''Incorrecto''";
        }

        // El folio va dentro de un OPENQUERY: las comillas simples se duplican.
        $folio = str_replace("'", "''", DespachosSql::folio('t1.nro'));

        $queryParts = [];
        foreach ($stations as $station) {
            // Estación 39 excluida históricamente de este reporte.
            if ((int) $station['codigo'] === 39) {
                continue;
            }
            $station_name = str_replace(' ', '_', (string) $station['estacion_nombre']);
            $server_ip    = DespachosSql::nombreSeguro((string) $station['servidor']);
            $database     = DespachosSql::nombreSeguro((string) $station['base_datos']);

            // La estación 33 tiene otro collation; hay que forzarlo para poder hacer UNION.
            $collationsatuid = '';
            $collationtxtref = '';
            if ((int) $station['codigo'] === 33) {
                $collationsatuid = 'COLLATE  Modern_Spanish_CI_AS as satuid';
                $collationtxtref = 'COLLATE  Modern_Spanish_CI_AS as txtref';
            }

            $queryParts[] = "SELECT *
                    FROM OPENQUERY([$server_ip],
                        '
                        SELECT
                            t1.nro,
                            $folio AS ''factura'',
                            t1.satuid $collationsatuid,
                            t1.tip,
                            CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fch), 23) AS fecha ,
                            CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23) AS vigencia,
                            t3.FechasConcatenadas,
                            t1.txtref $collationtxtref,
                            CASE
                                WHEN t1.tipref = 1 THEN ''Efectivo''
                                WHEN t1.tipref = 2 THEN ''Cheque Nominativo''
                                WHEN t1.tipref = 3 THEN ''Transferencia Electronica''
                                WHEN t1.tipref = 4 THEN ''Tarjeta de crédito''
                                WHEN t1.tipref = 28 THEN ''Tarjeta de débito''
                                ELSE ''Otro''
                            END AS TipoPago,
                            t3.NrotrnConcatenados,
                            ''$station_name'' as estacion,
                            CASE
                            WHEN NOT EXISTS (
                                SELECT 1
                                FROM STRING_SPLIT(t3.FechasConcatenadas, '','') fechas
                                WHERE DATEPART(MONTH, CAST(fechas.value AS DATE)) != DATEPART(MONTH,  CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23))
                            )
                            THEN ''Correcto''
                            ELSE ''Incorrecto''
                        END AS estado
                        FROM
                            [$database].[dbo].[DocumentosC] t1
                        LEFT JOIN (
                            SELECT
                                t5.nrofac,
                                STUFF((
                                    SELECT DISTINCT
                                        '', '' + CONVERT(VARCHAR(10), DATEADD(day, -1, fchtrn), 23)
                                    FROM [$database].[dbo].[despachos] t2
                                    WHERE t2.nrofac = t5.nrofac
                                    FOR XML PATH(''''), TYPE).value(''.'', ''NVARCHAR(MAX)''), 1, 2, '''') AS FechasConcatenadas,
                            COUNT(*) as NrotrnConcatenados
                            FROM [$database].[dbo].[despachos] t5
                            GROUP BY t5.nrofac
                        ) t3 ON t1.nro = t3.nrofac
                        LEFT JOIN [$database].[dbo].Gasolineras t4 ON t1.codgas = t4.cod
                        WHERE t1.codopr = " . DespachosSql::CODOPR_FACTURA_GLOBAL . "
                        AND t1.fch BETWEEN $from AND $until
                        AND t1.tip = 3
                        $estusQuery
                        ORDER BY t1.nro ASC
                        '
                    )";
        }
        if ($queryParts === []) {
            return false;
        }

        return $this->fetchAll(implode(' UNION ALL ', $queryParts));
    }

    /**
     * Total diario de Factura Global por estación, en pivot (una columna por
     * estación). Estaciones con vista local (vis_fac = 1) la leen de
     * v_factura_global_diaria; el resto lo calcula sobre Despachos.
     *
     * @param int                       $from       Fecha inicial (entero de fecha).
     * @param int                       $until      Fecha final (entero de fecha).
     * @param string                    $from_date  Misma fecha inicial como 'Y-m-d'.
     * @param string                    $until_date Misma fecha final como 'Y-m-d'.
     * @param list<array<string,mixed>> $estations  Estaciones (codigo, servidor, base_datos, estacion_nombre, vis_fac).
     * @return list<array<string,mixed>>|false
     * @throws InvalidArgumentException si las fechas de texto no son 'Y-m-d' válidas.
     */
    public function pivot_facturacion_diaria_table(int $from, int $until, string $from_date, string $until_date, array $estations): array|false
    {
        foreach ([$from_date, $until_date] as $date) {
            if (DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                throw new InvalidArgumentException('Las fechas deben tener formato Y-m-d.');
            }
        }
        if ($estations === []) {
            return false;
        }

        $select_columns       = ['fecha'];
        $pivot_columns        = [];
        $final_select_columns = [];
        $union_queries        = [];
        $global               = DespachosSql::CODOPR_FACTURA_GLOBAL;

        foreach ($estations as $station) {
            $station_name = str_replace(' ', '_', (string) $station['estacion_nombre']);
            $select_columns[]       = "ISNULL([$station_name], 0) as [$station_name]";
            $pivot_columns[]        = "[$station_name]";
            $final_select_columns[] = "ISNULL(dq.[$station_name], 0) as [$station_name]";

            $collation = (int) $station['codigo'] === 33 ? 'COLLATE  Modern_Spanish_CI_AS as fecha' : '';
            $server_ip = DespachosSql::nombreSeguro((string) $station['servidor']);
            $database  = DespachosSql::nombreSeguro((string) $station['base_datos']);

            if ((int) $station['vis_fac'] === 1) {
                $union_queries[] = "
                 SELECT * FROM OPENQUERY([$server_ip],
                'SELECT  fecha $collation , ''$station_name'' as estacion, total
                FROM [$database].[dbo].[v_factura_global_diaria] WITH (NOLOCK) WHERE fecha BETWEEN ''$from_date'' AND ''$until_date'' ')
                ";
            } else {
                $union_queries[] = "
                SELECT * FROM OPENQUERY([$server_ip],
                'SELECT
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) AS fecha,
                    ''$station_name'' as estacion,
                    CAST(ROUND(SUM(CASE WHEN t3.codopr = $global THEN t1.mto ELSE 0 END), 2) AS DECIMAL(18, 2)) as total
                FROM [$database].[dbo].[Despachos] t1 WITH (NOLOCK)
                LEFT JOIN [$database].[dbo].DocumentosC t3 ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
                WHERE t3.codopr = $global and t1.fchtrn BETWEEN $from AND $until
                GROUP BY CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23)')";
            }
        }

        $query = "
         WITH DateRange AS (
            SELECT CAST('$from_date' AS DATE) AS fecha
            UNION ALL
            SELECT DATEADD(day, 1, fecha)
            FROM DateRange
            WHERE fecha < '$until_date'
        ),
        DataQuery AS (
            SELECT " . implode(",\n        ", $select_columns) . "
            FROM (
                " . implode("\n        UNION ALL\n        ", $union_queries) . "
            ) AS SourceTable
            PIVOT (
                MAX(total)
                FOR estacion IN (
                    " . implode(",\n            ", $pivot_columns) . "
                )
            ) AS PivotTable
        )
             SELECT
                d.fecha,
                " . implode(",\n        ", $final_select_columns) . "
            FROM DateRange d
            LEFT JOIN DataQuery dq ON d.fecha = dq.fecha
            ORDER BY d.fecha;";

        return $this->fetchAll($query);
    }

    /**
     * Facturas de clientes que NO son crédito/débito agrupadas por día, cliente,
     * estación y folio, con el método de pago dominante (el de mayor monto).
     *
     * @return list<array<string,mixed>>
     */
    public function invoice_client_desp(int $from, int $until): array
    {
        $folio = DespachosSql::folio('t1.nrofac');
        $query = "
        ;WITH Base AS (
            SELECT
                " . DespachosSql::fechaIso('t1.fchtrn') . " AS fecha,
                t1.codcli,
                t2.den,
                t1.codgas,
                t3.abr,
                $folio AS factura,
                t1.mto,
                -- monto por método en cada fila (despacho)
                CASE WHEN t1.tiptrn=51 AND t1.datref LIKE '%@P:04%' THEN t1.mto ELSE 0 END AS m_tc,
                CASE WHEN t1.tiptrn=52 AND t1.datref LIKE '%@P:28%' THEN t1.mto ELSE 0 END AS m_td,
                CASE WHEN t1.tiptrn=49 AND t1.datref LIKE '%@P:01%' THEN t1.mto ELSE 0 END AS m_ef,
                CASE WHEN t1.tiptrn=53 AND t1.datref LIKE '%@P:05%' THEN t1.mto ELSE 0 END AS m_mo,
                CASE WHEN t1.tiptrn=50 AND t1.datref LIKE '%@P:02%' THEN t1.mto ELSE 0 END AS m_ch
            FROM SG12.dbo.Despachos t1
            LEFT JOIN SG12.dbo.Clientes    t2 ON t1.codcli = t2.cod
            LEFT JOIN SG12.dbo.Gasolineras t3 ON t1.codgas = t3.cod
            WHERE
                t1.fchtrn BETWEEN ? AND ?
                AND t2.tipval NOT IN (3, 4)
                AND t1.codcli <> " . DespachosSql::CODOPR_FACTURA_GLOBAL . "
        ),
        Agg AS (
            SELECT
                fecha, codcli, den, codgas, abr, factura,
                COUNT(*) AS n_despachos,           -- cuenta de despachos por factura
                SUM(mto) AS monto,
                SUM(m_tc) AS m_tc,
                SUM(m_td) AS m_td,
                SUM(m_ef) AS m_ef,
                SUM(m_mo) AS m_mo,
                SUM(m_ch) AS m_ch
            FROM Base
            GROUP BY fecha, codcli, den, codgas, abr, factura
        )
        SELECT
            A.fecha,
            A.codcli,
            A.den,
            A.codgas,
            A.abr,
            A.factura,
            A.n_despachos,                         -- total de despachos por factura
            A.monto,
            COALESCE(M.metodo_pago, 'Sin dato') AS metodo_pago
        FROM Agg A
        OUTER APPLY (
            SELECT TOP 1 metodo_pago
            FROM (VALUES
                ('Tarjeta de crédito', A.m_tc),
                ('Tarjeta de débito',  A.m_td),
                ('Efectivo',           A.m_ef),
                ('Monedero',           A.m_mo),
                ('Cheque',             A.m_ch)
            ) v(metodo_pago, monto)
            WHERE v.monto > 0
            ORDER BY v.monto DESC
        ) M
        ORDER BY A.codcli;
        ";

        return $this->sql->select($query, [$from, $until]);
    }

    /**
     * CFDI timbrados en el central para clientes crédito/débito, junto al UUID
     * que tiene la estación (para detectar facturas que no se sincronizaron).
     *
     * @param int $from   Fecha de factura (entero de fecha).
     * @param int $codgas Estación.
     * @return list<array<string,mixed>>|false
     */
    public function getCFDIs(int $from, int $codgas): array|false
    {
        $query = "
        SELECT
        G.den AS Estacion,
        D.nrotrn AS Despacho,
        Docc.satuid AS UUID,
        D.nrofac AS Factura,
        Docc.fch AS FechaFactura,
        Docc.logfch AS FechaLogFactura,
        dbo.fnNoFactura('', Docc.nro) AS serie,
        COALESCE(C.densat, C.den) AS Cliente,
        ISNULL(C.rfc, D.satrfc) AS RFC,
        C.codext,
        DR.nrofac AS [Factura Estación],
        DR.satuid AS [UUID Estación]
    FROM Despachos D
        INNER JOIN DocumentosC Docc ON Docc.nro = D.nrofac
            AND Docc.tip = 3
            AND Docc.codgas = D.gasfac
            AND Docc.satuid IS NOT NULL
            AND Docc.satuid <> ''
            AND Docc.fch = $from
        INNER JOIN Documentos Doc ON Doc.nro = Docc.nro
            AND Doc.tip = Docc.tip
            AND Doc.codgas = Docc.codgas
            AND Doc.nroitm > 0
            AND Doc.codprd = D.codprd
            AND D.nrotrn = CASE
                WHEN CHARINDEX('@d:', Doc.satdat) > 0
                THEN [dbo].[GetRef](Doc.satdat, 'D')
                ELSE D.nrotrn
            END
        INNER JOIN Gasolineras G ON G.cod = D.codgas
        INNER JOIN Clientes C ON C.cod = Docc.codopr AND C.tipval IN (3, 4)

        -- Comparación con linked server (misma clave)
        LEFT JOIN {$this->stationDb($codgas)}.[Despachos] DR ON DR.codgas = D.codgas AND DR.nrotrn = D.nrotrn
    WHERE D.codgas = $codgas;
    ";
        return $this->fetchAll($query);
    }

    /**
     * Compara CFDI del central contra los de la estación: 'match' (mismo UUID),
     * 'missing' (la estación no lo tiene) o 'mismatch' (UUID distinto).
     *
     * @param int|string|null $codgas Estación; null o 'all' = todas.
     * @param string          $bd     Base de datos de la estación (linked server).
     * @param string          $server Linked server de la estación.
     * @return list<array<string,mixed>>
     * @throws InvalidArgumentException si $bd o $server traen caracteres no válidos.
     */
    public function cfdi_comparison_advance(int $from, int $until, int|string|null $codgas, string $bd, string $server): array
    {
        $server = DespachosSql::nombreSeguro($server);
        $bd     = DespachosSql::nombreSeguro($bd);

        $query = "
        SELECT
            G.den AS Estacion,
            D.nrotrn AS Despacho,
            Docc.satuid AS UUIDCorp,
            D.nrofac AS FacturaCorp,
            Docc.fch AS FechaFactura,
            CONVERT(VARCHAR, CONVERT(SMALLDATETIME, Docc.fch, 100) - 1, 103) AS FechaFac,
            Docc.logfch AS FechaLogFactura,
            dbo.fnNoFactura('', Docc.nro) AS Serie,
            COALESCE(C.densat, C.den) AS Cliente,
            ISNULL(C.rfc, D.satrfc) AS RFC,
            C.codext,
            DR.nrotrn AS [despacho_estacion],
            DR.nrofac AS FacturaEstacion,
            DR.satuid AS UUIDEstacion,
            D.codgas,
            -- Determinar el estado
            CASE
                WHEN DR.satuid IS NOT NULL AND DR.satuid <> '' AND DR.satuid = Docc.satuid THEN 'match'
                WHEN DR.nrofac IS NULL OR DR.satuid IS NULL OR DR.satuid = '' THEN 'missing'
                WHEN DR.satuid <> Docc.satuid THEN 'mismatch'
                ELSE 'pending'
            END AS Estado
        FROM SG12.dbo.Despachos D
            INNER JOIN SG12.dbo.DocumentosC Docc ON Docc.nro = D.nrofac
                AND Docc.tip = 3
                AND Docc.codgas = D.gasfac
                AND Docc.satuid IS NOT NULL
                AND Docc.satuid <> ''
                AND Docc.fch BETWEEN ? AND ?
            INNER JOIN SG12.dbo.Documentos Doc ON Doc.nro = Docc.nro
                AND Doc.tip = Docc.tip
                AND Doc.codgas = Docc.codgas
                AND Doc.nroitm > 0
                AND Doc.codprd = D.codprd
                AND D.nrotrn = CASE
                    WHEN CHARINDEX('@d:', Doc.satdat) > 0
                        THEN [dbo].[GetRef](Doc.satdat, 'D')
                    ELSE D.nrotrn
                END
            INNER JOIN SG12.dbo.Gasolineras G ON G.cod = D.codgas
            INNER JOIN SG12.dbo.Clientes C ON C.cod = Docc.codopr
                AND C.tipval IN (3, 4)
            -- Comparación con linked server
            LEFT JOIN [$server].[$bd].dbo.[Despachos] DR
                ON DR.codgas = D.codgas
                AND DR.nrotrn = D.nrotrn
    ";

        $params = [$from, $until];

        // Filtro opcional por estación.
        if ($codgas !== null && $codgas !== 'all') {
            $query   .= ' WHERE D.codgas = ?';
            $params[] = $codgas;
        }

        $query .= ' ORDER BY Docc.fch DESC, G.den, D.nrotrn';

        return $this->sql->select($query, $params);
    }

    // =====================================================================
    // 9. DETECCIÓN DE ANOMALÍAS DE FACTURACIÓN POR CLIENTE
    //
    // Todas leen TG.dbo.vw_DespachosConFactura (despacho + factura ya unidos;
    // [codopr (F)] = cliente de la FACTURA). Método estadístico: se calcula una
    // línea base diaria con los meses previos completos y se marca como
    // anómalo un día con facturas por encima de media + k·σ o de Q3 + 1.5·IQR.
    // Los parámetros son enteros de fecha (fchtrn).
    // =====================================================================

    /**
     * Clientes con días anómalos (σ o IQR) en el periodo evaluado, con
     * indicadores de gravedad y banderas de facturas con varios productos o montos.
     *
     * @param int $desde_eval_i Inicio del periodo evaluado (entero de fecha).
     * @param int $hasta_eval_i Fin del periodo evaluado (entero de fecha).
     * @return list<array<string,mixed>>
     */
    public function anomalies_by_client(int $desde_eval_i, int $hasta_eval_i): array
    {
        $query = "
/* ========= INPUT ========= */
DECLARE @desde_eval date = DATEADD(DAY, ? - 1, '1900-01-01');
DECLARE @hasta_eval date = DATEADD(DAY, ? - 1, '1900-01-01');

/* ====== 1) HISTÓRICO (2 meses previos completos) ====== */
DECLARE @desde_hist date = DATEFROMPARTS(
    YEAR(DATEADD(MONTH, -3, @desde_eval)),
    MONTH(DATEADD(MONTH, -3, @desde_eval)),
    1
);
DECLARE @hasta_hist date = EOMONTH(DATEADD(MONTH, -1, @desde_eval));

/* ====== 2) fchtrn (entero) ====== */
DECLARE @desde_hist_i int = DATEDIFF(DAY,'1900-01-01',@desde_hist) + 1;
DECLARE @hasta_hist_i int = DATEDIFF(DAY,'1900-01-01',@hasta_hist) + 1;
DECLARE @desde_eval_i int = DATEDIFF(DAY,'1900-01-01',@desde_eval) + 1;
DECLARE @hasta_eval_i int = DATEDIFF(DAY,'1900-01-01',@hasta_eval) + 1;

/* ====== 3) Parámetros de umbral ====== */
DECLARE @k_sigma  float = 3.0;   -- σ
DECLARE @iqr_mult float = 1.5;   -- IQR

;WITH
-- Hist: facturas únicas por (cliente, día histórico)
HistDaily AS (
    SELECT  H.codopr, H.fchtrn, COUNT(*) AS cnt
    FROM (
        SELECT DISTINCT
            [codopr (F)] AS codopr,
            fchtrn,
            nrofac
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @desde_hist_i AND @hasta_hist_i
    ) H
    GROUP BY H.codopr, H.fchtrn
),
-- Baseline por cliente (ventanas sobre el histórico)
Baseline AS (
    SELECT DISTINCT
        codopr,
        AVG(CAST(cnt AS float))  OVER (PARTITION BY codopr) AS media_hist,
        STDEV(CAST(cnt AS float))OVER (PARTITION BY codopr) AS desv_hist,
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY cnt) OVER (PARTITION BY codopr) AS q1_hist,
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY cnt) OVER (PARTITION BY codopr) AS q3_hist
    FROM HistDaily
),
-- Eval: facturas únicas por (cliente, día evaluado)
EvalDaily AS (
    SELECT  E.codopr, E.fchtrn, COUNT(*) AS facturas_dia
    FROM (
        SELECT DISTINCT
            [codopr (F)] AS codopr,
            fchtrn,
            nrofac
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
    ) E
    GROUP BY E.codopr, E.fchtrn
),
-- Por cada factura, cuántos productos distintos tiene
EvalMulti AS (
    SELECT
        [codopr (F)] AS codopr,
        fchtrn,
        nrofac,
        COUNT(DISTINCT codprd) AS productos_distintos
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
    GROUP BY [codopr (F)], fchtrn, nrofac
),
-- Flag a nivel cliente: ¿tiene al menos una factura con >1 producto?
MultiFlag AS (
    SELECT
        codopr,
        MAX(CASE WHEN productos_distintos > 1 THEN 1 ELSE 0 END) AS tiene_factura_multi_prod
    FROM EvalMulti
    GROUP BY codopr
),
-- NUEVO: por cada factura, cuántos montos distintos tiene
EvalMontos AS (
    SELECT
        [codopr (F)] AS codopr,
        fchtrn,
        nrofac,
        COUNT(DISTINCT mto) AS montos_distintos
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
    GROUP BY [codopr (F)], fchtrn, nrofac
),
-- NUEVO: flag a nivel cliente: ¿tiene al menos una factura con montos distintos?
MontosFlag AS (
    SELECT
        codopr,
        MAX(CASE WHEN montos_distintos > 1 THEN 1 ELSE 0 END) AS tiene_factura_montos_diferentes
    FROM EvalMontos
    GROUP BY codopr
)
SELECT
    ed.codopr                              AS codopr,
    c.den                                  AS cliente,

    -- Días anómalos (σ o IQR)
    SUM(CASE
            WHEN ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
              OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist)
            THEN 1 ELSE 0
        END)                                AS dias_anomalos,

    CAST(SUM(CASE
                WHEN ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
                  OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist)
                THEN (ed.facturas_dia - b.media_hist) ELSE 0
             END) AS decimal(18,2))         AS total_incremento_abs,

    CAST(AVG(CASE
                WHEN ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
                  OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist)
                THEN (ed.facturas_dia - b.media_hist)
             END) AS decimal(18,2))         AS prom_incremento_abs,

    CAST(AVG(CASE
                WHEN (   ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
                      OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist))
                 AND NULLIF(b.media_hist,0) IS NOT NULL
                THEN (ed.facturas_dia - b.media_hist) / b.media_hist * 100.0
             END) AS decimal(18,2))         AS prom_incremento_pct,

    CAST((
        SUM(CASE
                WHEN (   ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
                      OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist))
                 AND NULLIF(b.desv_hist,0) IS NOT NULL
                THEN (ed.facturas_dia - b.media_hist) / b.desv_hist
                ELSE 0
            END)
        /
        NULLIF(SUM(CASE
                      WHEN (   ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
                            OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist))
                       AND NULLIF(b.desv_hist,0) IS NOT NULL
                      THEN 1 ELSE 0
                  END),0)
    ) AS decimal(18,2))                      AS prom_zscore,

    -- Máximo de tickets en un día en el periodo evaluado
    MAX(ed.facturas_dia)                     AS max_facturas_dia,

    -- Flag “tiene al menos una factura con varios productos”
    ISNULL(mf.tiene_factura_multi_prod, 0)   AS tiene_factura_multi_prod,

    -- NUEVO: flag “tiene al menos una factura con montos distintos”
    ISNULL(mf2.tiene_factura_montos_diferentes, 0) AS tiene_factura_montos_diferentes

FROM EvalDaily ed
JOIN Baseline b  ON b.codopr = ed.codopr
LEFT JOIN MultiFlag  mf  ON mf.codopr  = ed.codopr
LEFT JOIN MontosFlag mf2 ON mf2.codopr = ed.codopr
LEFT JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = ed.codopr
GROUP BY ed.codopr, c.den, mf.tiene_factura_multi_prod, mf2.tiene_factura_montos_diferentes
HAVING
    SUM(CASE
            WHEN ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
              OR ed.facturas_dia > b.q3_hist   + @iqr_mult * (b.q3_hist - b.q1_hist)
            THEN 1 ELSE 0
        END) > 0
ORDER BY prom_zscore DESC, ed.codopr
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [$desde_eval_i, $hasta_eval_i]);
    }

    /**
     * Días anómalos de UN cliente con z-score e incremento porcentual contra su histórico.
     *
     * @return list<array<string,mixed>>
     */
    public function anomalies_by_client_days(int $desde_eval_i, int $hasta_eval_i, int $codopr): array
    {
        $query = "
/* ====== PARÁMETROS (desde/hasta vienen como enteros) ====== */
DECLARE @desde_eval date = DATEADD(DAY, ? - 1, '1900-01-01');
DECLARE @hasta_eval date = DATEADD(DAY, ? - 1, '1900-01-01');
DECLARE @codopr     int  = ?;

DECLARE @k_sigma float = 3.0;   -- umbral σ
DECLARE @iqr_mult float = 1.5;  -- umbral IQR

/* ====== 1) HISTÓRICO (2 meses previos completos) ====== */
DECLARE @desde_hist date = DATEFROMPARTS(
    YEAR(DATEADD(MONTH, -3, @desde_eval)),
    MONTH(DATEADD(MONTH, -3, @desde_eval)),
    1
);
DECLARE @hasta_hist date = EOMONTH(DATEADD(MONTH, -1, @desde_eval));

/* ====== 2) fchtrn (entero) ====== */
DECLARE @desde_hist_i int = DATEDIFF(DAY,'1900-01-01',@desde_hist)+1;
DECLARE @hasta_hist_i int = DATEDIFF(DAY,'1900-01-01',@hasta_hist)+1;
DECLARE @desde_eval_i int = DATEDIFF(DAY,'1900-01-01',@desde_eval)+1;
DECLARE @hasta_eval_i int = DATEDIFF(DAY,'1900-01-01',@hasta_eval)+1;

/* ====== 3) HISTÓRICO diario ====== */
;WITH Hist AS (
    SELECT
        [codopr (F)] AS codopr,
        CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha,
        COUNT(DISTINCT nrofac) AS cnt_facturas_dia
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_hist_i AND @hasta_hist_i
      AND [codopr (F)] = @codopr
    GROUP BY [codopr (F)],
             CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date)
),
Baseline AS (
    SELECT DISTINCT
        codopr,
        AVG(CAST(cnt_facturas_dia AS float))  OVER (PARTITION BY codopr) AS media_hist,
        STDEV(CAST(cnt_facturas_dia AS float))OVER (PARTITION BY codopr) AS desv_hist,
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY cnt_facturas_dia)
            OVER (PARTITION BY codopr) AS q1_hist,
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY cnt_facturas_dia)
            OVER (PARTITION BY codopr) AS q3_hist,
        COUNT(*) OVER (PARTITION BY codopr) AS dias_hist
    FROM Hist
),
EvalDaily AS (
    SELECT
        [codopr (F)] AS codopr,
        CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha,
        COUNT(DISTINCT nrofac) AS facturas_dia
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
      AND [codopr (F)] = @codopr
    GROUP BY [codopr (F)],
             CAST(DATEADD(DAY, fchtrn - 1, CONVERT(date,'1900-01-01')) AS date)
)
SELECT
    e.codopr                                       AS codopr,
    e.fecha,
    e.facturas_dia,
    CAST(b.media_hist AS decimal(18,2))            AS baseline_media_hist,
    CAST(b.desv_hist  AS decimal(18,2))            AS baseline_desv_hist,
    CAST(b.q1_hist    AS decimal(18,2))            AS q1_hist,
    CAST(b.q3_hist    AS decimal(18,2))            AS q3_hist,
    CAST((e.facturas_dia - b.media_hist) / NULLIF(b.desv_hist,0)
         AS decimal(18,2))                         AS zscore_hist,
    CAST((e.facturas_dia - b.media_hist) / NULLIF(NULLIF(b.media_hist,0),0) * 100.0
         AS decimal(18,2))                         AS incremento_pct_vs_hist,
    1                                              AS es_anomalia
FROM EvalDaily e
JOIN Baseline b
  ON b.codopr = e.codopr
WHERE
    e.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
    OR e.facturas_dia > b.q3_hist  + @iqr_mult * (b.q3_hist - b.q1_hist)
ORDER BY zscore_hist DESC, e.fecha, e.codopr
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [
            $desde_eval_i,
            $hasta_eval_i,
            $codopr
        ]);
    }

    /**
     * Detalle de tickets (despachos facturados) de UN cliente en el periodo, con
     * serie y concepto del folio. Excluye a la factura global y a clientes crédito/débito.
     *
     * @return list<array<string,mixed>>
     */
    public function anomalies_by_client_tickets(int $desde_eval_i, int $hasta_eval_i, int $codopr): array
    {
        $query = "
DECLARE @desde_int int = ?;
DECLARE @hasta_int int = ?;
DECLARE @codopr   int = ?;

;WITH D AS (
    SELECT
        nrotrn, codgas, nrobom, fchtrn, hratrn, fchcor, nrotur,
        codprd, can, mto, codcli, codisl, codres, nrofac, tiptrn, datref, satuid, satrfc
    FROM SG12.dbo.Despachos WITH (NOLOCK)
    WHERE nrofac > 0
      AND fchtrn BETWEEN @desde_int AND @hasta_int
),
DC AS (
    SELECT nro, codgas, codopr, tip, satuid
    FROM SG12.dbo.DocumentosC WITH (NOLOCK)
    WHERE codopr NOT IN (21701354, 1710801)
      AND codopr = @codopr
),
C AS (
    SELECT cod, den
    FROM SG12.dbo.Clientes WITH (NOLOCK)
    WHERE tipval NOT IN (3,4)
)
SELECT
      t1.nrotrn
    , t1.codgas
    , g.abr                 AS estacion
    , t1.nrobom
    , t1.fchtrn
    , t2.tip
    , CAST(DATEADD(DAY, t1.fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha
    , t1.hratrn
    , t1.fchcor
    , t1.nrotur
    , t1.codprd
    , p.den                 AS nomPrd
    , t1.can
    , t1.mto
    , t1.codcli             AS codcli_d
    , c.den                 AS nombreCliente
    , t2.codopr             AS codopr_f
    , t1.codisl
    , i.den                 AS isla
    , t1.codres
    , CASE WHEN t1.codres = 0 THEN 'No encontrado' ELSE r.den END AS responsable
    , t1.nrofac
    , calc.Factura          AS factura
    , calc.serie
    , calc.conceptofac
    , t1.tiptrn
    , t1.datref
    , t1.satuid
    , t1.satrfc
FROM D AS t1
JOIN DC AS t2
  ON t1.nrofac = t2.nro
 AND t1.codgas = t2.codgas
JOIN C  AS c
  ON t2.codopr = c.cod
JOIN SG12.dbo.Gasolineras  AS g ON t1.codgas = g.cod
JOIN SG12.dbo.Productos    AS p ON t1.codprd = p.cod
LEFT JOIN SG12.dbo.Responsables AS r ON t1.codres = r.cod
LEFT JOIN SG12.dbo.Islas        AS i ON t1.codisl = i.cod
CROSS APPLY (
    SELECT n2 = (t2.nro / 100000000)
) k
CROSS APPLY (
    SELECT
        Factura =
            CASE WHEN t1.nrofac BETWEEN 1000000000 AND 2499999999
                 THEN t1.nrofac - ((t1.nrofac / 100000000) * 100000000)
                 ELSE t1.nrofac
            END,
        serie =
            CASE k.n2
                WHEN 10 THEN 'B' WHEN 11 THEN 'C' WHEN 12 THEN 'D' WHEN 13 THEN 'E'
                WHEN 14 THEN 'F' WHEN 15 THEN 'G' WHEN 16 THEN 'H' WHEN 17 THEN 'I'
                WHEN 18 THEN 'J' WHEN 19 THEN 'K' WHEN 20 THEN 'T'
                WHEN 21 THEN 'Z' WHEN 22 THEN 'Z' WHEN 23 THEN 'Z' WHEN 24 THEN 'Z'
                ELSE 'Unknown'
            END,
        conceptofac =
            CASE k.n2
                WHEN 10 THEN 'Contado Independencia'
                WHEN 11 THEN 'Contado'
                WHEN 12 THEN 'Anticipos'
                WHEN 13 THEN 'Consumos'
                WHEN 14 THEN 'Notas de crédito'
                WHEN 15 THEN 'Notas de crédito'
                WHEN 16 THEN 'Web'
                WHEN 17 THEN 'Crédito'
                WHEN 18 THEN 'Crédito'
                WHEN 19 THEN 'Web'
                WHEN 20 THEN 'Terminales'
                WHEN 21 THEN 'Global' WHEN 22 THEN 'Global' WHEN 23 THEN 'Global' WHEN 24 THEN 'Global'
                ELSE ''
            END
) calc
ORDER BY fecha DESC, t1.hratrn ASC
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [
            $desde_eval_i,
            $hasta_eval_i,
            $codopr
        ]);
    }

    /**
     * Totales del periodo por cliente (despachos, facturas únicas, monto).
     *
     * @return list<array<string,mixed>>
     */
    public function anomalies_clients_totals(int $desde_eval_i, int $hasta_eval_i): array
    {
        $query = "
DECLARE @desde_int int = ?;
DECLARE @hasta_int int = ?;

;WITH V AS (
    SELECT
        [codopr (F)] AS codopr,
        nombreCliente,
        nrotrn,
        nrofac,
        codgas,
        can,
        mto
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_int AND @hasta_int
)
SELECT
      v.codopr                         AS codopr
    , MIN(v.nombreCliente)             AS nombreCliente
    , COUNT(DISTINCT v.nrotrn)         AS n_despachos
    , COUNT(DISTINCT v.nrofac)         AS facturas_unicas
    , SUM(v.mto)                       AS total_mto
FROM V AS v
GROUP BY v.codopr
ORDER BY v.codopr
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [
            $desde_eval_i,
            $hasta_eval_i
        ]);
    }

    /**
     * Totales del periodo de UN cliente (incluye litros y facturas únicas por estación).
     *
     * @return list<array<string,mixed>>
     */
    public function anomalies_client_totals_period(int $desde_int, int $hasta_int, int $codopr): array
    {
        $query = "
DECLARE @desde_int int = ?;
DECLARE @hasta_int int = ?;
DECLARE @codopr     int = ?;

;WITH V AS (
    SELECT
        [codopr (F)] AS codopr,
        nombreCliente,
        nrotrn,
        nrofac,
        codgas,
        can,
        mto
    FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
    WHERE fchtrn BETWEEN @desde_int AND @hasta_int
      AND [codopr (F)] = @codopr
)
SELECT
      v.codopr                                     AS codopr
    , MIN(v.nombreCliente)                         AS nombreCliente
    , COUNT(DISTINCT v.nrotrn)                     AS n_despachos
    , COUNT(DISTINCT v.nrofac)                     AS facturas_unicas
    , COUNT(DISTINCT CAST(CONCAT(v.nrofac,':',v.codgas) AS nvarchar(40)))
                                                  AS facturas_codgas_unicas
    , SUM(v.can)                                   AS total_cant
    , SUM(v.mto)                                   AS total_mto
FROM V AS v
GROUP BY v.codopr
ORDER BY v.codopr
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [
            $desde_int,
            $hasta_int,
            $codopr
        ]);
    }

    /**
     * Estaciones con más tickets en los días anómalos de los clientes.
     *
     * @return list<array<string,mixed>>
     */
    public function anomalies_top_station(int $desde_eval_i, int $hasta_eval_i): array
    {
        $query = "
/* ========= INPUT ========= */
DECLARE @desde_eval date = DATEADD(DAY, ? - 1, '1900-01-01');
DECLARE @hasta_eval date = DATEADD(DAY, ? - 1, '1900-01-01');

/* ====== 1) HISTÓRICO (2 meses previos completos) ====== */
DECLARE @desde_hist date = DATEFROMPARTS(
    YEAR(DATEADD(MONTH, -3, @desde_eval)),
    MONTH(DATEADD(MONTH, -3, @desde_eval)),
    1
);
DECLARE @hasta_hist date = EOMONTH(DATEADD(MONTH, -1, @desde_eval));

/* ====== 2) fchtrn (entero) ====== */
DECLARE @desde_hist_i int = DATEDIFF(DAY,'1900-01-01',@desde_hist) + 1;
DECLARE @hasta_hist_i int = DATEDIFF(DAY,'1900-01-01',@hasta_hist) + 1;
DECLARE @desde_eval_i int = DATEDIFF(DAY,'1900-01-01',@desde_eval) + 1;
DECLARE @hasta_eval_i int = DATEDIFF(DAY,'1900-01-01',@hasta_eval) + 1;

/* ====== 3) Parámetros de umbral ====== */
DECLARE @k_sigma  float = 3.0;   -- σ
DECLARE @iqr_mult float = 1.5;   -- IQR

;WITH
-- Hist: facturas únicas por (cliente, día histórico)
HistDaily AS (
    SELECT  H.codopr, H.fchtrn, COUNT(*) AS cnt
    FROM (
        SELECT DISTINCT
            [codopr (F)] AS codopr,
            fchtrn,
            nrofac
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @desde_hist_i AND @hasta_hist_i
    ) H
    GROUP BY H.codopr, H.fchtrn
),
-- Baseline por cliente (ventanas sobre el histórico)
Baseline AS (
    SELECT DISTINCT
        codopr,
        AVG(CAST(cnt AS float))  OVER (PARTITION BY codopr) AS media_hist,
        STDEV(CAST(cnt AS float))OVER (PARTITION BY codopr) AS desv_hist,
        PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY cnt) OVER (PARTITION BY codopr) AS q1_hist,
        PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY cnt) OVER (PARTITION BY codopr) AS q3_hist
    FROM HistDaily
),
-- Eval: facturas únicas por (cliente, día evaluado)
EvalDaily AS (
    SELECT  E.codopr, E.fchtrn, COUNT(*) AS facturas_dia
    FROM (
        SELECT DISTINCT
            [codopr (F)] AS codopr,
            fchtrn,
            nrofac
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
    ) E
    GROUP BY E.codopr, E.fchtrn
),
-- Días anómalos por cliente
AnomDays AS (
    SELECT
        ed.codopr,
        ed.fchtrn
    FROM EvalDaily ed
    JOIN Baseline b
      ON b.codopr = ed.codopr
    WHERE
        ed.facturas_dia > b.media_hist + @k_sigma * ISNULL(b.desv_hist,0)
        OR ed.facturas_dia > b.q3_hist  + @iqr_mult * (b.q3_hist - b.q1_hist)
),
-- Tickets por estación en días anómalos
TicketsPorEstacion AS (
    SELECT
        v.codgas,
        v.Estacion,
        COUNT(*) AS tickets_anomalos
    FROM TG.dbo.vw_DespachosConFactura v WITH (NOLOCK)
    JOIN AnomDays a
      ON a.codopr = v.[codopr (F)]
     AND a.fchtrn = v.fchtrn
    WHERE v.fchtrn BETWEEN @desde_eval_i AND @hasta_eval_i
    GROUP BY v.codgas, v.Estacion
)
SELECT
    codgas,
    Estacion,
    tickets_anomalos
FROM TicketsPorEstacion
ORDER BY tickets_anomalos DESC, Estacion
OPTION (RECOMPILE);
";

        return $this->sql->select($query, [
            $desde_eval_i,
            $hasta_eval_i
        ]);
    }

    /**
     * Datos de la pantalla visual de anomalías: por cliente, conteo y monto por
     * mes del año, más los indicadores de exceso (k·σ con k = 4).
     *
     * Consulta pesada: sube el límite de tiempo y memoria del proceso. El
     * llamador es quien debe enviar el Content-Type JSON.
     *
     * @param string $eval_i_ini  Inicio del periodo evaluado ('Y-m-d').
     * @param string $eval_i_fin  Fin del periodo evaluado ('Y-m-d').
     * @param string $hist_i_ini  Inicio del histórico ('Y-m-d').
     * @param string $hist_i_fin  Fin del histórico ('Y-m-d').
     * @param string $visual_fin  Fin de la ventana de gráficas ('Y-m-d').
     * @return list<array<string,mixed>>
     */
    public function get_anomalies_visual_data(string $eval_i_ini, string $eval_i_fin, string $hist_i_ini, string $hist_i_fin, string $visual_fin): array
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $cod_eval_i_ini = $this->parseDayNumber($eval_i_ini);
        $cod_eval_i_fin = $this->parseDayNumber($eval_i_fin);
        $cod_hist_i_ini = $this->parseDayNumber($hist_i_ini);
        $cod_hist_i_fin = $this->parseDayNumber($hist_i_fin);
        $visual_fin_int = $this->parseDayNumber($visual_fin);

        $sql = "
    DECLARE @eval_i_ini int = ?;
    DECLARE @eval_i_fin int = ?;
    DECLARE @hist_i_ini int = ?;
    DECLARE @hist_i_fin int = ?;
    DECLARE @visual_fin int = ?;

    DECLARE @k_sigma float = 4.0;
    DECLARE @min_hist_avg float = 0.5;
    DECLARE @min_mto_exceso float = 5000;
    DECLARE @min_incr_abs int = 5;

    ;WITH 
    -- 1. HISTÓRICO: Contamos DESPACHOS por día
    HistData AS (
        SELECT [codopr (F)] as codopr, fchtrn, COUNT(*) as cnt
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @hist_i_ini AND @hist_i_fin
        GROUP BY [codopr (F)], fchtrn
    ),
    HistStats AS (
        SELECT 
            codopr,
            AVG(CAST(cnt AS float)) AS media_hist,
            STDEV(CAST(cnt AS float)) AS desv_hist,
            COUNT(*) AS dias_base_promedio 
        FROM HistData
        GROUP BY codopr
    ),
    -- 2. EVALUACIÓN: Contamos DESPACHOS reales del día
    EvalData AS (
        SELECT 
            [codopr (F)] as codopr, 
            fchtrn, 
            COUNT(*) as cnt,
            SUM(mto) as suma_mto,
            CASE WHEN COUNT(*) > 0 THEN SUM(mto) / COUNT(*) ELSE 0 END as ticket_promedio
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @eval_i_ini AND @eval_i_fin
        GROUP BY [codopr (F)], fchtrn
    ),
    -- 3. VISUAL: Gráficas (CORREGIDO: Se agregaron líneas para el mes 1 - Enero)
    VisualData AS (
        SELECT [codopr (F)] as codopr, 
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 1 THEN 1 END) as M01, -- <--- AGREGADO
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 2 THEN 1 END) as M02,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 3 THEN 1 END) as M03,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 4 THEN 1 END) as M04,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 5 THEN 1 END) as M05,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 6 THEN 1 END) as M06,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 7 THEN 1 END) as M07,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 8 THEN 1 END) as M08,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 9 THEN 1 END) as M09,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 10 THEN 1 END) as M10,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 11 THEN 1 END) as M11,
            COUNT(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 12 THEN 1 END) as M12,
            
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 1 THEN mto ELSE 0 END) as Money01, -- <--- AGREGADO
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 2 THEN mto ELSE 0 END) as Money02,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 3 THEN mto ELSE 0 END) as Money03,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 4 THEN mto ELSE 0 END) as Money04,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 5 THEN mto ELSE 0 END) as Money05,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 6 THEN mto ELSE 0 END) as Money06,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 7 THEN mto ELSE 0 END) as Money07,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 8 THEN mto ELSE 0 END) as Money08,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 9 THEN mto ELSE 0 END) as Money09,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 10 THEN mto ELSE 0 END) as Money10,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 11 THEN mto ELSE 0 END) as Money11,
            SUM(CASE WHEN MONTH(DATEADD(DAY, fchtrn-1, '1900-01-01')) = 12 THEN mto ELSE 0 END) as Money12

        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE fchtrn BETWEEN @hist_i_ini AND @visual_fin
        GROUP BY [codopr (F)]
    ),
    StatsCalculator AS (
        SELECT 
            v.*, h.media_hist, h.desv_hist, h.dias_base_promedio,
            (h.media_hist + (@k_sigma * ISNULL(h.desv_hist,0))) as umbral_sigma
        FROM VisualData v
        JOIN HistStats h ON v.codopr = h.codopr
    ),
    DailyFlags AS (
        SELECT 
            sc.codopr,
            ed.cnt,
            -- Banderas de Anomalía Estadística
            CASE 
                WHEN (ed.cnt > sc.umbral_sigma) AND (ed.cnt - sc.media_hist) >= @min_incr_abs 
                THEN 1 ELSE 0 
            END as is_suspicious,
            CASE 
                WHEN (ed.cnt > sc.umbral_sigma) AND (ed.cnt - sc.media_hist) >= @min_incr_abs 
                THEN (ed.suma_mto - (sc.media_hist * ed.ticket_promedio)) 
                ELSE 0 
            END as daily_excess_money,
            
            -- Banderas de Patrones
            CASE WHEN ed.cnt > 10 THEN 1 ELSE 0 END as flag_volumen_alto
        FROM StatsCalculator sc
        JOIN EvalData ed ON sc.codopr = ed.codopr
    ),
    InvoicePatterns AS (
         SELECT 
            [codopr (F)] as codopr,
            MAX(CASE WHEN cnt_prods > 1 THEN 1 ELSE 0 END) as has_mixed_products,
            MAX(CASE WHEN cnt_mtos > 1 THEN 1 ELSE 0 END) as has_mixed_amounts
         FROM (
             SELECT [codopr (F)], nrofac, 
                    COUNT(DISTINCT codprd) as cnt_prods,
                    COUNT(DISTINCT mto) as cnt_mtos
             FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
             WHERE fchtrn BETWEEN @eval_i_ini AND @eval_i_fin
             GROUP BY [codopr (F)], nrofac
         ) sub
         GROUP BY [codopr (F)]
    ),
    EvalLogic AS (
        SELECT 
            df.codopr,
            MAX(df.cnt) as Max_Pico_Ago,
            SUM(df.is_suspicious) as Dias_4Sigma,
            SUM(df.daily_excess_money) as Exceso_Dinero,
            COUNT(*) as Dias_Con_Venta,
            MAX(df.flag_volumen_alto) as Has_High_Vol
        FROM DailyFlags df
        GROUP BY df.codopr
    )
    SELECT TOP 100
        sc.codopr AS [Codigo],
        LEFT(c.den, 40) AS [Cliente],
        
        -- CORREGIDO: SE AGREGARON M01 Y Money01 AL SELECT FINAL
        sc.M01, sc.M02, sc.M03, sc.M04, sc.M05, sc.M06, sc.M07, sc.M08, sc.M09, sc.M10, sc.M11, sc.M12,
        sc.Money01, sc.Money02, sc.Money03, sc.Money04, sc.Money05, sc.Money06, sc.Money07, sc.Money08, sc.Money09, sc.Money10, sc.Money11, sc.Money12,

        CAST(sc.media_hist AS DECIMAL(10,1)) AS [Promedio_Normal],
        el.Max_Pico_Ago AS [Pico_Agosto],
        sc.dias_base_promedio AS [dias_base_promedio],
        el.Dias_Con_Venta AS [dias_con_venta],
        el.Dias_4Sigma AS [Dias_Alerta],
        CAST((el.Max_Pico_Ago - sc.media_hist) AS DECIMAL(10,1)) AS [Diferencia_Absoluta],
        CAST(el.Exceso_Dinero AS DECIMAL(12,2)) AS [Monto_Exceso],
        CAST(CASE WHEN sc.media_hist > 0 THEN ((el.Max_Pico_Ago - sc.media_hist) / sc.media_hist) * 100 ELSE 0 END AS DECIMAL(10,0)) AS [Porcentaje_Aumento],

        ISNULL(ip.has_mixed_products, 0) as [has_mixed_products],
        ISNULL(ip.has_mixed_amounts, 0) as [has_mixed_amounts],
        ISNULL(el.Has_High_Vol, 0) as [has_high_vol]

    FROM StatsCalculator sc
    JOIN EvalLogic el ON el.codopr = sc.codopr
    LEFT JOIN InvoicePatterns ip ON ip.codopr = sc.codopr 
    LEFT JOIN SG12.dbo.Clientes c WITH (NOLOCK) ON c.cod = sc.codopr
    WHERE 
        el.Dias_4Sigma > 0             
        AND el.Exceso_Dinero > @min_mto_exceso
    ORDER BY el.Exceso_Dinero DESC;
    ";

        return $this->sql->select($sql, [$cod_eval_i_ini, $cod_eval_i_fin, $cod_hist_i_ini, $cod_hist_i_fin, $visual_fin_int]);
    }

    /**
     * Fecha de texto → entero de fecha del esquema ((días desde 1900-01-01) + 1).
     * Tolerante: devuelve 0 si la fecha viene vacía o no se puede interpretar.
     */
    private function parseDayNumber(string $date): int
    {
        if ($date === '') {
            return 0;
        }

        try {
            $interval = (new DateTime('1900-01-01'))->diff(new DateTime($date));
            return ($interval->invert ? -$interval->days : $interval->days) + 1;
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * Tickets sospechosos de UN cliente en el periodo: cada despacho con su
     * factura y banderas de producto mixto, monto mixto y día de alto volumen (>10).
     *
     * @param string $target_month_ini Inicio del periodo ('Y-m-d').
     * @param string $target_month_fin Fin del periodo ('Y-m-d').
     * @param string $hist_ini         (sin uso; se conserva por compatibilidad de firma)
     * @param string $hist_fin         (sin uso; se conserva por compatibilidad de firma)
     * @return list<array<string,mixed>>
     */
    public function get_suspicious_tickets_details(string $target_month_ini, string $target_month_fin, string $hist_ini, string $hist_fin, int $codopr): array
    {
        $eval_i_ini = $this->parseDayNumber($target_month_ini);
        $eval_i_fin = $this->parseDayNumber($target_month_fin);

        $sql = "
    DECLARE @codopr int = ?;
    DECLARE @eval_i_ini int = ?;
    DECLARE @eval_i_fin int = ?;

    ;WITH 
    -- 1. IDENTIFICAR FOLIOS (VISTA)
    UniverseTickets AS (
        SELECT DISTINCT nrofac, codgas
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE [codopr (F)] = @codopr
          AND fchtrn BETWEEN @eval_i_ini AND @eval_i_fin
    ),
    -- 2. DETALLE OPERATIVO
    RawDetails AS (
        SELECT
            d.nrotrn, d.codgas, d.nrobom, d.fchtrn, d.hratrn, d.fchcor, d.nrotur,
            d.codprd, d.can, d.mto, d.codcli, d.codisl, d.codres, d.nrofac, d.tiptrn, d.datref, d.satuid, d.satrfc
        FROM SG12.dbo.Despachos d WITH (NOLOCK)
        INNER JOIN UniverseTickets u ON d.nrofac = u.nrofac AND d.codgas = u.codgas
        WHERE d.nrofac > 0 
    ),
    -- 3. ESTADÍSTICAS POR FACTURA (Para Prod. Mixtos y Montos Mixtos)
    FacStats AS (
        SELECT 
            nrofac, codgas,
            COUNT(DISTINCT codprd) as cnt_prods,
            COUNT(DISTINCT mto) as cnt_mtos
        FROM RawDetails
        GROUP BY nrofac, codgas
    ),
    C AS ( SELECT cod, den FROM SG12.dbo.Clientes WITH (NOLOCK) )
    
    SELECT
          t1.nrotrn
        , t1.codgas
        , g.abr AS estacion
        , t1.nrobom
        , CAST(DATEADD(DAY, t1.fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha
        , t1.hratrn
        , t1.nrotur
        , p.den AS nomPrd
        , t1.can
        , t1.mto
        , c.den AS nombreCliente
        , i.den AS isla
        , CASE WHEN t1.codres = 0 THEN 'No encontrado' ELSE r.den END AS responsable
        , t1.nrofac
        , calc.Factura AS factura
        , calc.serie
        , calc.conceptofac
        , ISNULL(dc.satuid, '⚠️ SIN TIMBRAR / NO ENCONTRADO') as satuid 

        -- === BANDERAS LÓGICAS ===
        
        -- 1. Factura con Productos Mixtos
        , CASE WHEN fs.cnt_prods > 1 THEN 1 ELSE 0 END as is_mixed_prod
        
        -- 2. Factura con Montos Mixtos
        , CASE WHEN fs.cnt_mtos > 1 THEN 1 ELSE 0 END as is_mixed_mto
        
        -- 3. DÍA con Volumen Alto (>10 tickets en el día, independiente de la factura)
        -- Usamos Window Function sobre la fecha (fchtrn)
        , CASE WHEN COUNT(*) OVER (PARTITION BY t1.fchtrn) > 10 THEN 1 ELSE 0 END as is_high_vol_day

    FROM RawDetails AS t1
    INNER JOIN FacStats fs ON t1.nrofac = fs.nrofac AND t1.codgas = fs.codgas
    LEFT JOIN SG12.dbo.DocumentosC dc WITH(NOLOCK) ON t1.nrofac = dc.nro AND t1.codgas = dc.codgas
    LEFT JOIN C  AS c  ON ISNULL(dc.codopr, @codopr) = c.cod 
    LEFT JOIN SG12.dbo.Gasolineras AS g ON t1.codgas = g.cod
    LEFT JOIN SG12.dbo.Productos   AS p ON t1.codprd = p.cod
    LEFT JOIN SG12.dbo.Responsables AS r ON t1.codres = r.cod
    LEFT JOIN SG12.dbo.Islas        AS i ON t1.codisl = i.cod
    CROSS APPLY ( SELECT n2 = (t1.nrofac / 100000000) ) k
    CROSS APPLY (
        SELECT
            Factura = CASE WHEN t1.nrofac BETWEEN 1000000000 AND 2499999999 THEN t1.nrofac - ((t1.nrofac / 100000000) * 100000000) ELSE t1.nrofac END,
            serie = CASE k.n2 WHEN 10 THEN 'B' WHEN 11 THEN 'C' WHEN 12 THEN 'D' WHEN 13 THEN 'E' WHEN 14 THEN 'F' WHEN 15 THEN 'G' WHEN 16 THEN 'H' WHEN 17 THEN 'I' WHEN 18 THEN 'J' WHEN 19 THEN 'K' WHEN 20 THEN 'T' WHEN 21 THEN 'Z' WHEN 22 THEN 'Z' WHEN 23 THEN 'Z' WHEN 24 THEN 'Z' ELSE 'Unknown' END,
            conceptofac = CASE k.n2 WHEN 10 THEN 'Contado Independencia' WHEN 11 THEN 'Contado' WHEN 12 THEN 'Anticipos' WHEN 13 THEN 'Consumos' WHEN 14 THEN 'Notas de crédito' WHEN 15 THEN 'Notas de crédito' WHEN 16 THEN 'Web' WHEN 17 THEN 'Crédito' WHEN 18 THEN 'Crédito' WHEN 19 THEN 'Web' WHEN 20 THEN 'Terminales' WHEN 21 THEN 'Global' ELSE '' END
    ) calc
    ORDER BY fecha DESC, t1.hratrn ASC
    OPTION (RECOMPILE);
    ";

        return $this->sql->select($sql, [$codopr, $eval_i_ini, $eval_i_fin]);
    }

    /**
     * Desglose día por día de UN cliente: despachos reales, monto, límite k·σ,
     * dinero excedente y bandera de día crítico.
     *
     * @param string $hist_ini Inicio del histórico ('Y-m-d').
     * @param string $hist_fin Fin del histórico ('Y-m-d').
     * @return list<array<string,mixed>>
     */
    public function get_client_anomaly_breakdown(int $codopr, string $target_month_ini, string $target_month_fin, string $hist_ini, string $hist_fin): array
    {
        $cod_eval_i_ini = $this->parseDayNumber($target_month_ini);
        $cod_eval_i_fin = $this->parseDayNumber($target_month_fin);
        $cod_hist_i_ini = $this->parseDayNumber($hist_ini);
        $cod_hist_i_fin = $this->parseDayNumber($hist_fin);

        $sql = "
    DECLARE @codopr int = ?;
    DECLARE @eval_i_ini int = ?;
    DECLARE @eval_i_fin int = ?;
    DECLARE @hist_i_ini int = ?;
    DECLARE @hist_i_fin int = ?;

    DECLARE @k_sigma float = 4.0;
    DECLARE @min_incr_abs int = 5;

    ;WITH 
    -- 1. HISTÓRICO
    HistData AS (
        SELECT fchtrn, COUNT(*) as cnt
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE [codopr (F)] = @codopr AND fchtrn BETWEEN @hist_i_ini AND @hist_i_fin
        GROUP BY fchtrn
    ),
    HistStats AS (
        SELECT AVG(CAST(cnt AS float)) AS media_hist, STDEV(CAST(cnt AS float)) AS desv_hist
        FROM HistData
    ),
    -- 2. DATOS DIARIOS DEL MES ACTUAL
    EvalDays AS (
        SELECT 
            fchtrn, 
            COUNT(*) as cnt, 
            SUM(mto) as mto_real,
            CASE WHEN COUNT(*) > 0 THEN SUM(mto)/COUNT(*) ELSE 0 END as ticket_prom
        FROM TG.dbo.vw_DespachosConFactura WITH (NOLOCK)
        WHERE [codopr (F)] = @codopr AND fchtrn BETWEEN @eval_i_ini AND @eval_i_fin
        GROUP BY fchtrn
    )
    -- 3. SELECCIONAR DETALLE DÍA POR DÍA
    SELECT 
        CAST(DATEADD(DAY, e.fchtrn - 1, CONVERT(date,'1900-01-01')) AS date) AS fecha,
        e.cnt as despachos_reales,
        e.mto_real,
        
        -- Estadísticas
        CAST(h.media_hist as decimal(10,2)) as media_historica,
        CAST((h.media_hist + (@k_sigma * ISNULL(h.desv_hist,0))) as decimal(10,2)) as limite_sigma,
        
        -- Cálculo del Exceso Monetario
        -- Exceso = Lo que vendí HOY - (Lo que suelo vender en cant. * ticket promedio de hoy)
        CAST((e.cnt * e.ticket_prom) as decimal(12,2)) as dinero_total_real,
        CAST((h.media_hist * e.ticket_prom) as decimal(12,2)) as dinero_esperado_teorico,
        CAST((e.mto_real - (h.media_hist * e.ticket_prom)) as decimal(12,2)) as dinero_excedente,

        -- Banderas
        CASE WHEN (e.cnt > (h.media_hist + (@k_sigma * ISNULL(h.desv_hist,0)))) AND (e.cnt - h.media_hist) >= @min_incr_abs THEN 1 ELSE 0 END as is_critical
    FROM EvalDays e, HistStats h
    ORDER BY e.cnt DESC -- Ordenar por pico más alto primero
    ";

        return $this->sql->select($sql, [$codopr, $cod_eval_i_ini, $cod_eval_i_fin, $cod_hist_i_ini, $cod_hist_i_fin]);
    }
}
