<?php
class inventario_urovo {
    private $twig;
    private InventarioUrovoModel $model;
    private const LOCATIONS = ['estacion','almacen','reparacion','perdida','baja_definitiva'];
    private const CONDITIONS = ['operativa','dañada','desconocida','extraviada'];
    private const DAMAGE_TYPES = ['pantalla_quebrada','pantalla_negra','carcasa_exterior','puerto_carga_punta','usb_danado','falta_bateria','touch','modo_seguro','impresora','tapa_impresora','boton_encendido','tapa_bateria','lector_tarjetas','otro'];

    public function __construct($twig) { $this->twig=$twig; $this->model=new InventarioUrovoModel(); }

    public function index(): void {
        try {
            $this->authorize(); $this->requireMethod('GET');
            $stations=$this->model->stations(); $tableros_csrf=(string)($_SESSION['tableros_csrf']??'');
            echo $this->twig->render('views/inventariourovo/index.html',compact('stations','tableros_csrf'));
        } catch (Throwable $e) { $this->error($e); }
    }

    public function data(): void {
        try {
            $this->authorize();
            $this->requireMethod('GET');
            $station=isset($_GET['station_id']) && $_GET['station_id']!=='' ? filter_var($_GET['station_id'],FILTER_VALIDATE_INT) : null;
            if ($station===false || ($station!==null && !$this->model->activeStation((int)$station))) throw new InvalidArgumentException('Estación inválida.');
            $status=(string)($_GET['status']??'');
            if ($status!=='' && $status!=='sin_serie' && !in_array($status,self::LOCATIONS,true)) throw new InvalidArgumentException('Estado inválido.');
            $payload=$this->model->data(['station_id'=>$station,'status'=>$status,'q'=>mb_substr(trim((string)($_GET['q']??'')),0,120)]);
            $this->respond(['success'=>true]+$payload);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function summary(): void {
        try {
            $this->authorize(); $this->requireMethod('GET');
            $this->respond(['success'=>true,'rows'=>$this->model->summary()]);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function summary_detail(int $station_id): void {
        try {
            $this->authorize(); $this->requireMethod('GET');
            $this->respond(['success'=>true]+$this->model->summaryDetail($station_id));
        } catch (Throwable $e) { $this->error($e); }
    }

    public function batch(): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf();
            $location=$this->location($body);
            if (!isset($body['items']) || !is_array($body['items']) || count($body['items'])<1 || count($body['items'])>500) throw new InvalidArgumentException('Se requiere una lista de 1 a 500 terminales.');
            $items=[];
            foreach ($body['items'] as $item) {
                if (!is_array($item)) throw new InvalidArgumentException('Cada terminal debe ser un objeto.');
                $noSerial=($item['no_serial']??false)===true;
                $serial=trim((string)($item['serial']??''));
                if (($noSerial && $serial!=='') || (!$noSerial && $serial==='') || mb_strlen($serial)>100) throw new InvalidArgumentException($noSerial?'Una terminal sin serie debe tener vacío el número de serie.':'El número de serie es obligatorio y debe tener máximo 100 caracteres.');
                $condition=trim((string)($item['condition']??'desconocida')); if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
                $purchase=$this->dateValue($item['purchase_date']??null,false); $warranty=$this->dateValue($item['warranty_until']??null,true);
                $row=['serial'=>$noSerial?null:$serial,'no_serial'=>$noSerial,'model'=>$this->text($item,'model',100)??'I9000S','ip'=>$this->text($item,'ip',45),'mac'=>$this->text($item,'mac',17),'imei'=>$this->text($item,'imei',32),'purchase_date'=>$purchase,'warranty_until'=>$warranty,'condition'=>$condition,'damage_types'=>$this->damageTypes($item['damage_types']??[],$condition),'mojo_ticket_number'=>$this->ticketNumber($item['mojo_ticket_number']??''),'accessories'=>$this->text($item,'accessories',1000),'notes'=>$this->text($item,'notes',2000)];
                $this->validateIdentifiers($row); $items[]=$row;
            }
            $this->respond(['success'=>true]+$this->model->addBatch($items,$location,$this->actor()));
        } catch (Throwable $e) { $this->error($e); }
    }

    public function move(int $id): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf(); $location=$this->location($body);
            $condition=(string)($body['status']??''); $reason=trim((string)($body['reason']??''));
            if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
            if ($reason==='' || mb_strlen($reason)>500) throw new InvalidArgumentException('El motivo es obligatorio (máximo 500 caracteres).');
            $damageTypes=$this->damageTypes($body['damage_types']??[],$condition);
            $ticket=$this->ticketNumber($body['mojo_ticket_number']??'');
            $this->model->moveTerminal($id,$location,$condition,$reason,$this->actor(),$damageTypes,$ticket); $this->respond(['success'=>true]);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function history(int $id): void {
        try { $this->authorize(); $this->requireMethod('GET'); $this->respond(['success'=>true,'history'=>$this->model->history($id)]); }
        catch (Throwable $e) { $this->error($e); }
    }

    public function edit(int $id): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf();
            $condition=(string)($body['condition']??'desconocida'); if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
            $changes=['model'=>$this->text($body,'model',100)??'I9000S','ip'=>$this->text($body,'ip',45),'mac'=>$this->text($body,'mac',17),'imei'=>$this->text($body,'imei',32),'purchase_date'=>$this->dateValue($body['purchase_date']??null,false),'warranty_until'=>$this->dateValue($body['warranty_until']??null,true),'condition'=>$condition,'damage_types'=>$this->damageTypes($body['damage_types']??[],$condition),'mojo_ticket_number'=>$this->ticketNumber($body['mojo_ticket_number']??''),'accessories'=>$this->text($body,'accessories',1000),'notes'=>$this->text($body,'notes',2000)];
            $this->validateIdentifiers($changes);
            $this->respond(['success'=>true,'row'=>$this->model->editTerminal($id,$changes,$this->actor())]);
        } catch (Throwable $e) { $this->error($e); }
    }

    private function authorize(): void {
        if (empty($_SESSION['tg_user']['Id']) || !$this->model->hasAccess()) { http_response_code(403); throw new RuntimeException('No tienes permiso para consultar el inventario UROVO.'); }
    }
    private function actor(): int { return (int)$_SESSION['tg_user']['Id']; }
    private function location(array $body): array {
        $type=(string)($body['location_type']??''); if (!in_array($type,self::LOCATIONS,true)) throw new InvalidArgumentException('Ubicación inválida.');
        $station=null; $provider=null;
        if ($type==='estacion') { $station=filter_var($body['station_id']??null,FILTER_VALIDATE_INT); if (!$station || !$this->model->activeStation((int)$station)) throw new InvalidArgumentException('Selecciona una estación activa válida.'); }
        if ($type==='reparacion') { $provider=trim((string)($body['provider']??'')); if ($provider==='' || mb_strlen($provider)>200) throw new InvalidArgumentException('Proveedor requerido para reparación (máximo 200 caracteres).'); }
        return ['location_type'=>$type,'station_id'=>$station,'provider'=>$provider];
    }
    private function jsonPost(): array {
        $this->requireMethod('POST');
        if (stripos((string)($_SERVER['CONTENT_TYPE']??''),'application/json')!==0) throw new InvalidArgumentException('Se requiere Content-Type application/json.');
        $raw=file_get_contents('php://input'); $data=json_decode((string)$raw,true);
        if (!is_array($data) || json_last_error()!==JSON_ERROR_NONE) throw new InvalidArgumentException('JSON inválido.'); return $data;
    }
    private function requireMethod(string $method): void {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!==$method) { http_response_code(405); header('Allow: '.$method); throw new RuntimeException('Método no permitido.'); }
    }
    private function csrf(): void {
        $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''); $expected=(string)($_SESSION['tableros_csrf']??'');
        if ($expected==='' || $sent==='' || !hash_equals($expected,$sent)) { http_response_code(403); throw new RuntimeException('Token CSRF inválido.'); }
    }
    private function dateValue($value,bool $allowFuture): ?string {
        if ($value===null || $value==='') return null;
        $date=(string)$value; $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d')!==$date || (!$allowFuture && $date>(new DateTimeImmutable('today'))->format('Y-m-d'))) throw new InvalidArgumentException($allowFuture?'La fecha de garantía no es válida.':'La fecha de compra debe ser válida y no futura.'); return $date;
    }
    private function text(array $item,string $key,int $max): ?string {
        $value=trim((string)($item[$key]??'')); if (mb_strlen($value)>$max) throw new InvalidArgumentException("El campo $key supera $max caracteres."); return $value===''?null:$value;
    }
    private function damageTypes($value,string $condition): array {
        if (!is_array($value) || (function_exists('array_is_list') && !array_is_list($value))) throw new InvalidArgumentException('Los tipos de daño deben ser una lista.');
        $types=[];
        foreach ($value as $type) {
            if (!is_string($type)) throw new InvalidArgumentException('Tipo de daño inválido.');
            if (str_starts_with($type,'otro:')) {
                $description=trim(mb_substr($type,5));
                if ($description==='' || mb_strlen($description)>500) throw new InvalidArgumentException('Describe el daño seleccionado como “Otro” (máximo 500 caracteres).');
                $type='otro: '.$description;
            } elseif ($type==='otro') {
                throw new InvalidArgumentException('Describe el daño seleccionado como “Otro” (máximo 500 caracteres).');
            } elseif (!in_array($type,self::DAMAGE_TYPES,true)) throw new InvalidArgumentException('Tipo de daño inválido.');
            if (!in_array($type,$types,true)) $types[]=$type;
        }
        if ($condition==='dañada' && !$types) throw new InvalidArgumentException('Selecciona al menos un tipo de daño para la terminal dañada.');
        return $condition==='dañada'?$types:[];
    }
    private function ticketNumber($value): string {
        if (!is_string($value)) throw new InvalidArgumentException('El número de ticket Mojo debe contener solo dígitos (máximo 30).');
        $ticket=trim((string)$value);
        if (strlen($ticket)>30 || ($ticket!=='' && !preg_match('/^[0-9]+$/',$ticket))) throw new InvalidArgumentException('El número de ticket Mojo debe contener solo dígitos (máximo 30).');
        return $ticket;
    }
    private function validateIdentifiers(array $fields): void {
        if ($fields['ip']!==null && filter_var($fields['ip'],FILTER_VALIDATE_IP)===false) throw new InvalidArgumentException('La IP debe ser una dirección IPv4 o IPv6 válida.');
        if ($fields['mac']!==null && !preg_match('/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',$fields['mac'])) throw new InvalidArgumentException('La MAC debe tener seis pares hexadecimales separados por dos puntos o guiones.');
        if ($fields['imei']!==null && !preg_match('/^[0-9]{15}$/',$fields['imei'])) throw new InvalidArgumentException('El IMEI debe contener exactamente 15 dígitos.');
        if ($fields['purchase_date']!==null && $fields['warranty_until']!==null && $fields['warranty_until']<$fields['purchase_date']) throw new InvalidArgumentException('La garantía no puede terminar antes de la fecha de compra.');
    }
    private function respond(array $data): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); }
    private function error(Throwable $e): void {
        if (http_response_code()<400) http_response_code(($e instanceof InvalidArgumentException || $e instanceof DomainException)?422:($e instanceof OutOfBoundsException?404:500));
        $status=http_response_code();
        if ($status>=500) { error_log('Inventario UROVO API: '.$e::class.': '.$e->getMessage()); $message='Ocurrió un error al procesar el inventario.'; }
        elseif ($status===403) $message='No tienes permiso para esta operación o el token CSRF no es válido.';
        elseif ($status===405) $message='Método no permitido.';
        elseif ($e instanceof InvalidArgumentException || $e instanceof DomainException || $e instanceof OutOfBoundsException) $message=$e->getMessage();
        else $message='Ocurrió un error al procesar el inventario.';
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
