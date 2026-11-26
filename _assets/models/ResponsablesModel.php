<?php

class ResponsablesModel extends Model{
    public $cod;
    public $den;
    public $dom;
    public $col;
    public $del;
    public $ciu;
    public $est;
    public $tel;
    public $codgas;
    public $hab;
    public $pto;
    public $rsp;
    public $mtacan;
    public $mtamto;
    public $rfc;
    public $codext;
    public $fching;
    public $fchegr;
    public $sumval;
    public $tag;
    public $curp;
    public $nip;
    public $idc;
    public $idccod;
    public $idcpin;
    public $logusu;
    public $logfch;
    public $lognew;
    public $fchsyn;

    /**
     * @param $station_id
     * @return array|false
     * @throws Exception
     */
    public function get_responsables_by_station($station_id) {
        $query = 'SELECT cod Codigo, den Nombre, pto Puesto FROM [SG12].[dbo].[Responsables] WHERE codgas = ? AND hab = 1;';
        $params = [$station_id];
        return ($this->sql->select($query,$params)) ?: false ;
    }



    /** Puestos válidos (pto es varchar(20)). OSC = Oficial de Servicio. */
    const PUESTOS = ['Gerente', 'Subgerente', 'Encargado', 'Supervisor', 'Oficial de Servicio', 'Despachador'];

    /**
     * Lleva un puesto capturado a mano ("DESPACHADOR", "Desoachador", "OSC",
     * "oficial de sevicio"...) a uno de PUESTOS. Si no se reconoce, devuelve
     * el texto original recortado para no perderlo.
     */
    public static function normalizar_puesto(?string $pto): string {
        $raw = trim((string) $pto);
        $k = preg_replace('/[^a-z ]/', '', mb_strtolower($raw, 'UTF-8'));
        $k = trim(preg_replace('/\s+/', ' ', $k));
        return match (true) {
            $k === '' => '',
            str_starts_with($k, 'subg') => 'Subgerente',
            str_starts_with($k, 'ger') => 'Gerente',
            str_starts_with($k, 'enc') => 'Encargado',
            str_starts_with($k, 'sup') => 'Supervisor',
            $k === 'osc' || str_starts_with($k, 'ofici') => 'Oficial de Servicio',
            (bool) preg_match('/^de[a-z]{0,3}ch?ador/', $k) || str_starts_with($k, 'desp') => 'Despachador',
            default => $raw,
        };
    }

    /** "  JUAN  pérez " → "Juan Pérez" (minúsculas salvo iniciales). */
    public static function formatear_nombre(string $nombre): string {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
        return mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * den se guarda como "<reloj> <nombre>". Quita el número (o números,
     * hay registros con el reloj repetido: "5338 5338 juan") del inicio.
     */
    public static function nombre_sin_reloj(string $den): string {
        return trim(preg_replace('/^(\d+\s+)+/', '', trim($den)));
    }

    function get_responsable(int $cod) : array|false {
        $query = "SELECT cod Codigo, den Nombre, pto Puesto, codext, hab Status, codgas FROM [SG12].[dbo].[Responsables] WHERE cod = ?;";
        return ($rs = $this->sql->select($query, [$cod])) ? $rs[0] : false;
    }

    /** Todos los registros con ese número de reloj (un empleado puede estar en varias estaciones). */
    function get_by_reloj(int $reloj) : array {
        return $this->sql->select(
            "SELECT t1.cod, t1.den, t1.codgas, t1.hab, t2.abr Estacion
             FROM [SG12].[dbo].[Responsables] t1
             LEFT JOIN [SG12].[dbo].Gasolineras t2 ON t1.codgas = t2.cod
             WHERE t1.codext = ?
             ORDER BY t1.hab DESC, t1.cod", [$reloj]) ?: [];
    }

    /** ¿El código ya existe en SG12 o en la base de la estación? */
    function codigo_ocupado(int $cod, int $codgas) : bool {
        if ($this->sql->select("SELECT 1 FROM [SG12].[dbo].[Responsables] WHERE cod = ?", [$cod])) {
            return true;
        }
        $db = $this->databases[$codgas] ?? null;
        if ($db && $codgas !== 0) {
            // Si la estación no responde, se asume libre: el INSERT en SG12
            // fallará igual por llave duplicada si hubiera choque.
            $rs = $this->sql->selectSafe("SELECT 1 FROM {$db}.[Responsables] WHERE cod = ?", [$cod]);
            return !empty($rs);
        }
        return false;
    }

    /**
     * Código interno para un alta: el número de reloj si está libre; si no
     * (otro empleado ya lo tiene, o es el mismo empleado en otra estación),
     * el siguiente del consecutivo global (códigos < 100000).
     */
    function siguiente_codigo(int $reloj, int $codgas) : int {
        if ($reloj > 0 && !$this->codigo_ocupado($reloj, $codgas)) {
            return $reloj;
        }
        $rs = $this->sql->select("SELECT ISNULL(MAX(cod), 0) + 1 AS siguiente FROM [SG12].[dbo].[Responsables] WHERE cod < 100000", []);
        $cod = (int) ($rs[0]['siguiente'] ?? 1);
        while ($this->codigo_ocupado($cod, $codgas)) {
            $cod++;
        }
        return $cod;
    }

    function get_all() : array | false {
        $query = "SELECT t2.abr Estacion, t2.cveest, t1.*, CASE 
            WHEN t1.hab = 0 THEN '-Inactivo-' 
            WHEN t1.hab = 1 THEN '-Activo-' 
        END AS Status FROM [SG12].[dbo].[Responsables] t1 LEFT JOIN  [SG12].[dbo].Gasolineras t2 ON t1.codgas = t2.cod;";
        return ($rs=$this->sql->select($query)) ? $rs : false ;
    }

    /**
     * Edita un responsable en SG12 y en las estaciones. El código interno no
     * cambia (se pasa como original y como nuevo).
     *
     * sp_actualizar_responsable fija pto = 'Despachador' en todas las bases,
     * así que después se corrige el puesto en SG12 y en la base de la
     * estación (un responsable solo existe en esas dos).
     *
     * @param array{Nombre:string,NoReloj:int,Puesto:string,Estacion:int,Status:int} $data
     * @throws Exception si falla el SP
     */
    function update(int $cod, array $data) : bool {
        $this->sql->executeStoredProcedure('[TG].[dbo].[sp_actualizar_responsable]',
            [$cod, $cod, $data['Nombre'], $data['Estacion'], $data['Status'], $data['NoReloj']]);

        $this->sql->update("UPDATE [SG12].[dbo].[Responsables] SET [pto] = ? WHERE [cod] = ?", [$data['Puesto'], $cod]);
        $db = $this->databases[$data['Estacion']] ?? null;
        if ($db && (int) $data['Estacion'] !== 0) {
            // Si la estación no responde no se aborta: SG12 ya quedó bien.
            $this->sql->updateSafe("UPDATE {$db}.[Responsables] SET [pto] = ? WHERE [cod] = ?", [$data['Puesto'], $cod]);
        }
        return true;
    }

    /**
     * Alta en SG12; ControlGas la replica a la base de su estación.
     *
     * @param array{Codigo:int,Nombre:string,NoReloj:int,Puesto:string,Estacion:int,Status:int} $data
     * @throws Exception si falla el INSERT (p. ej. código duplicado)
     */
    function insert(array $data) : bool {
        $query = "INSERT INTO [SG12].[dbo].[Responsables] ([cod],[den],[codgas],[hab],[pto],[rsp],[codext],[logfch],[lognew],[fchsyn]) VALUES (?,?,?,?,?,?,?,GETDATE(),GETDATE(),GETDATE())";
        $params = [$data['Codigo'], $data['Nombre'], $data['Estacion'], $data['Status'], $data['Puesto'], 1, $data['NoReloj']];
        return (bool) $this->sql->insert($query, $params);
    }

    /**
     * Actualiza solo la copia central (SG12) y encola la propagación a las
     * 37 BDs de estación remotas en vez de esperar a que terminen los 37
     * UPDATEs secuenciales por linked server dentro del mismo request. La
     * cola la procesa en segundo plano cron/responsables_sync_queue.php
     * (ver process_sync_queue()).
     */
    function deactivate($cod, $hab) : bool {
        $query = "
            UPDATE [SG12].[dbo].[Responsables] SET [hab] = ? WHERE [cod] = ?;
            INSERT INTO [TG].[dbo].[responsables_sync_queue] ([cod],[hab]) VALUES (?, ?);
        ";
        return (bool)$this->sql->update($query, [$hab, $cod, $cod, $hab]);
    }

    /**
     * Propaga a las 37 BDs de estación remotas los cambios de estatus de
     * responsables pendientes en la cola. Pensado para correr desde el cron
     * (Task Scheduler) vía Operations::sync_responsables_queue(), no desde
     * una petición de usuario: recorrer 37 linked servers de forma
     * secuencial puede tardar decenas de segundos.
     *
     * Si un mismo responsable quedó con varias filas pendientes (se
     * activó/desactivó varias veces antes de que corriera el cron), solo se
     * propaga la más reciente; las anteriores se marcan 'superada' sin
     * tocar las estaciones. Las filas en 'error' se reintentan en la
     * siguiente corrida hasta 10 intentos.
     *
     * updateSafe() (a diferencia de update()) no aborta el proceso si una
     * estación falla, así que una estación caída no bloquea la propagación
     * al resto.
     *
     * @return array{procesados:int, ok:int, error:int, detalle:array}
     */
    function process_sync_queue() : array {
        $pendientes = $this->sql->select(
            "SELECT id, cod, hab FROM [TG].[dbo].[responsables_sync_queue]
             WHERE estado = 'pendiente' OR (estado = 'error' AND intentos < 10)
             ORDER BY id ASC"
        ) ?: [];

        if (!$pendientes) {
            return ['procesados' => 0, 'ok' => 0, 'error' => 0, 'detalle' => []];
        }

        // Deduplicar: solo nos interesa el último estado pedido por responsable.
        $ultimaPorCod = [];
        foreach ($pendientes as $fila) {
            $ultimaPorCod[$fila['cod']] = $fila;
        }
        foreach ($pendientes as $fila) {
            if ($fila['id'] != $ultimaPorCod[$fila['cod']]['id']) {
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'superada', fecha_procesado = GETDATE() WHERE id = ?",
                    [$fila['id']]
                );
            }
        }

        $ok = 0;
        $error = 0;
        $detalle = [];
        foreach ($ultimaPorCod as $fila) {
            $fallos = [];
            foreach ($this->databases as $codgas => $db) {
                if ($codgas == 0) {
                    continue; // 0 = SG12, ya actualizado de forma sincrona en deactivate()
                }
                $resultado = $this->sql->updateSafe(
                    "UPDATE {$db}.[Responsables] SET [hab] = ? WHERE [cod] = ?",
                    [$fila['hab'], $fila['cod']]
                );
                if ($resultado === false) {
                    $fallos[] = $codgas;
                }
            }

            if (empty($fallos)) {
                $ok++;
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'ok', fecha_procesado = GETDATE() WHERE id = ?",
                    [$fila['id']]
                );
            } else {
                $error++;
                $detalleFila = "cod {$fila['cod']}: estaciones " . implode(', ', $fallos);
                $detalle[] = $detalleFila;
                $this->sql->update(
                    "UPDATE [TG].[dbo].[responsables_sync_queue] SET estado = 'error', intentos = intentos + 1, fecha_procesado = GETDATE(), detalle_error = ? WHERE id = ?",
                    [$detalleFila, $fila['id']]
                );
            }
        }

        return ['procesados' => count($ultimaPorCod), 'ok' => $ok, 'error' => $error, 'detalle' => $detalle];
    }

    function delete($cod) : bool {
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_eliminar_responsable]', [$cod]);
    }

}