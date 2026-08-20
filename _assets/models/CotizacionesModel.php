<?php
class CotizacionesModel extends Model{
    public $codmda;
    public $codgas;
    public $fch;
    public $hra;
    public $ctz;
    public $ctzcom;
    public $ctzven;
    public $codpza;
    public $codcpo;
    public $logusu;
    public $logfch;
    public $lognew;

    /**
     * @param $codgas
     * @return float|false
     * @throws Exception
     */
    public function get_last_exchange($codgas): float|false {
        $query = "SELECT TOP 1 ctz AS last_exchange FROM {$this->databases[$codgas]}.Cotizaciones WHERE codgas = ? ORDER BY logfch DESC;";
        if (empty($rs=$this->sql->select($query, [$codgas]))) {
            $query = "SELECT TOP 1 ctz AS last_exchange FROM {$this->databases[$codgas]}.Cotizaciones ORDER BY logfch DESC;";
            return ($rs=$this->sql->select($query, [])) ? number_format($rs[0]['last_exchange'], 2) : false ;
        }
        return ($rs=$this->sql->select($query, [$codgas])) ? number_format($rs[0]['last_exchange'], 2) : false ;
    }

    /**
     * Metadatos de las estaciones que se muestran en el reporte de tipo de cambio.
     * La llave del arreglo es el codgas (que coincide con la llave de
     * $this->linked_server y $this->short_databases en Model.php), y también es
     * el valor usado en el WHERE codgas = ? de la consulta remota.
     */
    private function exchange_rate_stations() : array {
        return [
            2  => ['no_station' => '4188',  'station_name' => 'Gemela Grande',      'description' => 'Sin casa de cambio aledaña'],
            3  => ['no_station' => '11007', 'station_name' => 'Independencia',      'description' => 'Foranea'],
            5  => ['no_station' => '1149',  'station_name' => 'Lerdo',              'description' => 'Sin casa de cambio aledaña'],
            6  => ['no_station' => '2526',  'station_name' => 'Lopez Mateos',       'description' => 'Casa de cambio terceros'],
            7  => ['no_station' => '4179',  'station_name' => 'Gemela Chica',       'description' => 'Sin casa de cambio aledaña'],
            8  => ['no_station' => '5317',  'station_name' => 'Municipio Libre',    'description' => 'Casa de cambio Dollar2Go'],
            9  => ['no_station' => '5465',  'station_name' => 'Aztecas',            'description' => 'Casa de cambio terceros'],
            10 => ['no_station' => '6410',  'station_name' => 'Misiones',           'description' => 'Casa de cambio Dollar2Go'],
            11 => ['no_station' => '6947',  'station_name' => 'Puerto de palos',    'description' => 'Casa de cambio Dollar2Go'],
            12 => ['no_station' => '7167',  'station_name' => 'Miguel de la madrid','description' => 'Sin casa de cambio aledaña'],
            13 => ['no_station' => '8244',  'station_name' => 'Permuta',            'description' => 'Casa de cambio Dollar2Go'],
            14 => ['no_station' => '9191',  'station_name' => 'Electrolux',         'description' => 'Sin casa de cambio aledaña'],
            15 => ['no_station' => '9235',  'station_name' => 'Aeronáutica',        'description' => 'Casa de cambio terceros'],
            16 => ['no_station' => '9885',  'station_name' => 'Custodia',           'description' => 'Casa de cambio terceros'],
            17 => ['no_station' => '9893',  'station_name' => 'Anapra',             'description' => 'Casa de cambio Dollar2Go'],
            18 => ['no_station' => '2172',  'station_name' => 'Parral',             'description' => 'Foranea'],
            19 => ['no_station' => '1376',  'station_name' => 'Delicias',           'description' => 'Foranea'],
            21 => ['no_station' => '5170',  'station_name' => 'Plutarco',           'description' => 'Casa de cambio Dollar2Go'],
            22 => ['no_station' => '1163',  'station_name' => 'Tecnológico',        'description' => 'Sin casa de cambio aledaña'],
            23 => ['no_station' => '9733',  'station_name' => 'Ejército Nacional',  'description' => 'Alianza comercial'],
            24 => ['no_station' => '4457',  'station_name' => 'Satélite',           'description' => 'Alianza comercial'],
            25 => ['no_station' => '1159',  'station_name' => 'Las fuentes',        'description' => 'Alianza comercial'],
            26 => ['no_station' => '1156',  'station_name' => 'Clara',              'description' => 'Alianza comercial'],
            27 => ['no_station' => '10141', 'station_name' => 'Solis',              'description' => 'Alianza comercial'],
            28 => ['no_station' => '12097', 'station_name' => 'Santiago Troncoso',  'description' => 'Alianza comercial'],
            29 => ['no_station' => '1148',  'station_name' => 'Jarudo',             'description' => 'Alianza comercial'],
            30 => ['no_station' => '23214', 'station_name' => 'Hermanos Escobar',   'description' => 'Casa de cambio Dollar2Go'],
            31 => ['no_station' => '1242',  'station_name' => 'Villa Ahumada',      'description' => 'Foranea'],
            32 => ['no_station' => '19190', 'station_name' => 'El castaño',         'description' => 'Foranea'],
            33 => ['no_station' => '24938', 'station_name' => 'Travel Center',      'description' => 'Casa de cambio Dollar2Go'],
            34 => ['no_station' => '24499', 'station_name' => 'Picachos',           'description' => 'Foranea'],
            35 => ['no_station' => '24500', 'station_name' => 'Ventanas',           'description' => 'Foranea'],
            36 => ['no_station' => '14946', 'station_name' => 'San Rafael',         'description' => 'Foranea'],
            37 => ['no_station' => '15071', 'station_name' => 'Puertecito',         'description' => 'Foranea'],
        ];
    }

    /**
     * Devuelve el último tipo de cambio de cada estación.
     *
     * La consulta a las 34 estaciones se delega a ApiER (endpoint
     * /api/TG_php/exchange_rates/), que las ejecuta en paralelo con
     * ThreadPoolExecutor en vez del foreach secuencial que corría antes aquí.
     * Si una estación individual falla, ApiER simplemente la omite del
     * resultado. Si ApiER completo no responde, se loguea y se devuelve un
     * array vacío para que la vista muestre la tabla vacía sin tronar.
     */
    function get_exchange_rates() : array {
        $payload = [];
        foreach ($this->exchange_rate_stations() as $codgas => $meta) {
            if (empty($this->linked_server[$codgas]) || empty($this->short_databases[$codgas])) {
                continue;
            }
            $payload[] = [
                'codgas'       => $codgas,
                'linked_server'=> $this->linked_server[$codgas],
                'short_db'     => $this->short_databases[$codgas],
                'station_name' => $meta['station_name'],
                'no_station'   => $meta['no_station'],
                'description'  => $meta['description'],
            ];
        }

        $ch = curl_init('http://192.168.0.109:82/api/TG_php/exchange_rates/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['estaciones' => $payload]));
        // 20s se cortaba antes de que ApiER terminara de fallar las estaciones caídas
        // (OPENQUERY tarda en reportar el error, no es instantáneo), tirando el reporte
        // completo en vez de solo excluir esas estaciones. Confirmado con pruebas manuales:
        // el lote de 34 estaciones tomó >30s en un caso.
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            error_log("get_exchange_rates: fallo al llamar a ApiER (HTTP {$httpCode}) {$curlError}");
            return [];
        }

        $rows = json_decode($response, true);
        if (!is_array($rows)) {
            error_log("get_exchange_rates: respuesta de ApiER no es un array válido: {$response}");
            return [];
        }

        return $rows;
    }

    function insert($codmda, $codgas, $from, $hour, $cot, $tg_user) : bool {
        $query = "MERGE INTO [SG12].[dbo].[Cotizaciones] AS target
                USING (VALUES (?,?,?,?,?,?,?,0,0,?,GETDATE(),GETDATE())) 
                    AS source([codmda],[codgas],[fch],[hra],[ctz],[ctzcom],[ctzven],[codpza],[codcpo],[logusu],[logfch],[lognew])
                ON target.[codmda] = source.[codmda]
                    AND target.[codgas] = source.[codgas]
                    AND target.[fch] = source.[fch]
                    AND target.[hra] = source.[hra]
                WHEN MATCHED THEN
                    UPDATE SET 
                        [ctz] = source.[ctz],
                        [ctzcom] = source.[ctzcom],
                        [ctzven] = source.[ctzven],
                        [logusu] = source.[logusu],
                        [logfch] = GETDATE(),
                        [lognew] = GETDATE()
                WHEN NOT MATCHED THEN
                    INSERT ([codmda],[codgas],[fch],[hra],[ctz],[ctzcom],[ctzven],[codpza],[codcpo],[logusu],[logfch],[lognew])
                    VALUES (source.[codmda],source.[codgas],source.[fch],source.[hra],source.[ctz],source.[ctzcom],source.[ctzven],source.[codpza],source.[codcpo],source.[logusu],source.[logfch],source.[lognew]);";
        
        $params = [$codmda, $codgas, $from, $hour, $cot, $cot, $cot, $tg_user];
        return (bool)$this->sql->insert($query, $params);
    }

    function insert_remote($codmda, $codgas, $from, $hour, $cot, $tg_user) : bool {
        $codmda = intval($codmda);
        $codgas = intval($codgas);
        $from   = intval($from);
        $hour   = intval($hour);
        $cot    = floatval($cot);
        $db     = $this->databases[$codgas];

        // SQL Server forbids MERGE when the target is a linked server.
        // Use INSERT WHERE NOT EXISTS + UPDATE instead — both work across linked servers.

        // Step 1: insert the row only if it doesn't already exist.
        $insert_query = "INSERT INTO {$db}.[Cotizaciones]
            ([codmda],[codgas],[fch],[hra],[ctz],[ctzcom],[ctzven],[codpza],[codcpo],[logusu],[logfch],[lognew])
            SELECT {$codmda},{$codgas},{$from},{$hour},{$cot},{$cot},{$cot},0,0,?,GETDATE(),GETDATE()
            WHERE NOT EXISTS (
                SELECT 1 FROM {$db}.[Cotizaciones]
                WHERE [codmda] = {$codmda} AND [codgas] = {$codgas}
                  AND [fch] = {$from} AND [hra] = {$hour}
            )";
        $this->sql->insert($insert_query, [$tg_user]);

        // Step 2: update the row (covers both new and pre-existing records).
        $update_query = "UPDATE {$db}.[Cotizaciones]
            SET [ctz] = {$cot}, [ctzcom] = {$cot}, [ctzven] = {$cot},
                [logusu] = ?, [logfch] = GETDATE(), [lognew] = GETDATE()
            WHERE [codmda] = {$codmda} AND [codgas] = {$codgas}
              AND [fch] = {$from} AND [hra] = {$hour}";
        return (bool)$this->sql->update($update_query, [$tg_user]);
    }

    function update($codmda, $codgas, $fch, $hra, $value) : bool {
        $query = "
            UPDATE [SG12].[dbo].[Cotizaciones]
            SET [ctz] = {$value}
                ,[ctzcom] = {$value}
                ,[ctzven] = {$value}
            WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;
        ";

        return (bool)$this->sql->update($query, [$hra]);
    }

    function update_remote($codmda, $codgas, $fch, $hra, $value) : bool {
        $query = "
            UPDATE {$this->databases[$codgas]}.[Cotizaciones]
            SET [ctz] = {$value}
                ,[ctzcom] = {$value}
                ,[ctzven] = {$value}
            WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;
        ";
        return (bool)$this->sql->update($query, [$hra]);
    }

    function delete($codmda, $codgas, $fch, $hra) : bool {
        $query = "DELETE FROM [SG12].[dbo].[Cotizaciones] WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;";
        return (bool)$this->sql->delete($query, [$hra]);
    }

    function delete_remote($codmda, $codgas, $fch, $hra) : bool {
        $query = "DELETE FROM {$this->databases[$codgas]}.[Cotizaciones] WHERE codmda = {$codmda} AND codgas = {$codgas} AND fch = {$fch} AND hra = ?;";
        return (bool)$this->sql->delete($query, [$hra]);
    }
}