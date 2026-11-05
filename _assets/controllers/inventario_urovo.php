<?php
class inventario_urovo {
    private $twig;
    private InventarioUrovoModel $model;
    private const LOCATIONS = ['estacion','almacen','reparacion','perdida','baja_definitiva'];
    private const CONDITIONS = ['operativa','dañada','por_diagnosticar','desconocida'];

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
            if ($status!=='' && !in_array($status,self::LOCATIONS,true)) throw new InvalidArgumentException('Estado inválido.');
            $payload=$this->model->data(['station_id'=>$station,'status'=>$status,'q'=>mb_substr(trim((string)($_GET['q']??'')),0,120)]);
            $this->respond(['success'=>true]+$payload);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function batch(): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf();
            $location=$this->location($body); $individualize=($body['individualize']??false)===true; $source=null;
            if ($individualize) {
                if (!is_array($body['individualize_from']??null)) throw new InvalidArgumentException('Selecciona la existencia anónima que se va a individualizar.');
                $sourceBody=$body['individualize_from']; $sourceLocation=$this->location($sourceBody);
                $sourceCondition=(string)($sourceBody['condition']??'desconocida'); if (!in_array($sourceCondition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición del origen inválida.');
                $source=['model'=>$this->text($sourceBody,'model',100)??'No especificado','condition'=>$sourceCondition]+$sourceLocation;
            }
            if (!isset($body['items']) || !is_array($body['items']) || count($body['items'])<1 || count($body['items'])>500) throw new InvalidArgumentException('Se requiere una lista de 1 a 500 terminales.');
            $items=[];
            foreach ($body['items'] as $item) {
                if (!is_array($item)) throw new InvalidArgumentException('Cada terminal debe ser un objeto.');
                $serial=trim((string)($item['serial']??'')); if ($serial==='' || mb_strlen($serial)>100) throw new InvalidArgumentException('El número de serie es obligatorio y debe tener máximo 100 caracteres.');
                $condition=trim((string)($item['condition']??'desconocida')); if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
                $purchase=$this->dateValue($item['purchase_date']??null,false); $warranty=$this->dateValue($item['warranty_until']??null,true);
                $row=['serial'=>$serial,'model'=>$this->text($item,'model',100)??'No especificado','ip'=>$this->text($item,'ip',45),'mac'=>$this->text($item,'mac',17),'imei'=>$this->text($item,'imei',32),'purchase_date'=>$purchase,'warranty_until'=>$warranty,'condition'=>$condition,'accessories'=>$this->text($item,'accessories',1000),'notes'=>$this->text($item,'notes',2000)];
                $this->validateIdentifiers($row); $items[]=$row;
            }
            $this->respond(['success'=>true]+$this->model->addBatch($items,$location,$source,$this->actor()));
        } catch (Throwable $e) { $this->error($e); }
    }

    public function anonymous(): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf(); $location=$this->location($body);
            $delta=filter_var($body['delta']??null,FILTER_VALIDATE_INT); $reason=trim((string)($body['reason']??''));
            if ($delta===false || $delta===null || $delta===0 || abs($delta)>10000) throw new InvalidArgumentException('La cantidad debe ser un entero distinto de cero.');
            if ($reason==='' || mb_strlen($reason)>500) throw new InvalidArgumentException('El motivo es obligatorio (máximo 500 caracteres).');
            $model=$this->text($body,'model',100)??'No especificado'; $condition=(string)($body['condition']??'desconocida');
            if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
            $this->model->adjustAnonymous(['model'=>$model,'condition'=>$condition,'delta'=>$delta,'reason'=>$reason],$location,$this->actor());
            $this->respond(['success'=>true]);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function anonymous_history(): void {
        try {
            $this->authorize(); $this->requireMethod('GET');
            $location=$this->location(['location_type'=>$_GET['location_type']??'','station_id'=>$_GET['station_id']??null,'provider'=>$_GET['provider']??null]);
            $model=trim((string)($_GET['model']??'')); $condition=(string)($_GET['condition']??'');
            if ($model==='' || mb_strlen($model)>100) throw new InvalidArgumentException('Modelo inválido.');
            if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
            $this->respond(['success'=>true,'history'=>$this->model->anonymousHistory(['model'=>$model,'condition'=>$condition]+$location)]);
        } catch (Throwable $e) { $this->error($e); }
    }

    public function move(int $id): void {
        try {
            $this->authorize(); $body=$this->jsonPost(); $this->csrf(); $location=$this->location($body);
            $condition=(string)($body['status']??''); $reason=trim((string)($body['reason']??''));
            if (!in_array($condition,self::CONDITIONS,true)) throw new InvalidArgumentException('Condición inválida.');
            if ($reason==='' || mb_strlen($reason)>500) throw new InvalidArgumentException('El motivo es obligatorio (máximo 500 caracteres).');
            $this->model->moveTerminal($id,$location,$condition,$reason,$this->actor()); $this->respond(['success'=>true]);
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
            $changes=['model'=>$this->text($body,'model',100)??'No especificado','ip'=>$this->text($body,'ip',45),'mac'=>$this->text($body,'mac',17),'imei'=>$this->text($body,'imei',32),'purchase_date'=>$this->dateValue($body['purchase_date']??null,false),'warranty_until'=>$this->dateValue($body['warranty_until']??null,true),'condition'=>$condition,'accessories'=>$this->text($body,'accessories',1000),'notes'=>$this->text($body,'notes',2000)];
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
