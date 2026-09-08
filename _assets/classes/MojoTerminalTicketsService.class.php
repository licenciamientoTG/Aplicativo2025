<?php
class MojoTerminalTicketsService {
    private const BASE_URL = 'https://totalgas.mojohelpdesk.com/api';
    private const SYSTEM_FORM = 51598;
    private const VALERAS_FORM = 84607;
    private const SYSTEM_QUEUE = 53551;
    private string $key;
    public function __construct() {
        $this->key = $this->loadApiKey();
        if ($this->key === '') throw new RuntimeException('La integración de Mojo no está configurada.');
    }
    private function loadApiKey(): string {
        $key = trim((string)getenv('MOJO_API_KEY'));
        if ($key !== '') return $key;

        // El proyecto no usa un cargador de .env; se lee únicamente esta clave
        // desde la raíz de la aplicación sin registrarla ni exponerla al navegador.
        $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        if (!is_readable($envFile)) return '';
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*MOJO_API_KEY\s*=\s*(.*?)\s*$/', $line, $matches)) {
                return trim($matches[1], " \t\n\r\0\x0B\"'");
            }
        }
        return '';
    }
    private function request(string $method, string $path, ?array $payload=null): array {
        $url=self::BASE_URL.$path.(str_contains($path,'?')?'&':'?').'access_key='.rawurlencode($this->key);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
        if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE));
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
        $data=json_decode((string)$raw,true); if ($raw===false || $code<200 || $code>=300) throw new RuntimeException('No fue posible comunicarse con Mojo'.($error?": $error":''));
        return is_array($data)?$data:[];
    }
    public function getTicket(int $id): array { return $this->request('GET','/v3/tickets/'.$id); }
    public function isOpen(array $ticket): bool { $status=strtolower((string)($ticket['status'] ?? $ticket['status_name'] ?? '')); return !in_array($status,['closed','solved','resolved','cerrado','resuelto'],true) && empty($ticket['solved_on']); }
    public function create(array $incident, string $email, string $stationName): array {
        $valeras=$incident['type']!=='urovo';
        $payload=['title'=>'Terminal '.$incident['label'].' - '.$stationName,'description'=>$incident['description'],'ticket_queue_id'=>self::SYSTEM_QUEUE,'priority_id'=>30,'user'=>['email'=>$email]];
        if ($valeras) $payload += ['ticket_form_id'=>self::VALERAS_FORM,'custom_field_estacion'=>$stationName,'custom_field_tipo_de_terminal'=>$incident['mojo_type'],'custom_field_folio_de_reporte_del_proveedor'=>$incident['provider_folio'],'custom_field_fecha_de_reporte_a_proveedor'=>$incident['provider_date'],'custom_field_descripcion_del_problema'=>$incident['description']];
        else $payload += ['ticket_form_id'=>self::SYSTEM_FORM,'custom_field_area_o_departamento'=>'Operaciones','custom_field_solicitante'=>$email,'custom_field_problema'=>'Terminal Urovo'];
        return $this->request('POST','/v2/tickets',$payload);
    }
    private function normalizeTerminal(string $value): string {
        $value=trim(mb_strtolower($value,'UTF-8'));
        $value=strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return preg_replace('/[^a-z0-9]/','',$value) ?? '';
    }
    private function ticketField(array $ticket, array $keys): string {
        foreach ($keys as $key) if (isset($ticket[$key]) && !is_array($ticket[$key])) return trim((string)$ticket[$key]);
        foreach (($ticket['custom_fields'] ?? []) as $field) {
            if (!is_array($field)) continue;
            $name=$this->normalizeTerminal((string)($field['slug'] ?? $field['name'] ?? $field['label'] ?? ''));
            foreach ($keys as $key) if ($name===$this->normalizeTerminal($key)) {
                $value=$field['value'] ?? $field['display_value'] ?? '';
                if (is_array($value)) $value=$value['name'] ?? $value['value'] ?? '';
                return trim((string)$value);
            }
        }
        return '';
    }
    public function incidentDataFromTicket(array $ticket, string $type): array {
        return [
            'type_terminal'=>$this->ticketField($ticket,['custom_field_tipo_de_terminal','tipo_de_terminal','Tipo de terminal']),
            'provider_folio'=>$this->ticketField($ticket,['custom_field_folio_de_reporte_del_proveedor','folio_de_reporte_del_proveedor','Folio de reporte al proveedor']),
            'provider_date'=>$this->ticketField($ticket,['custom_field_fecha_de_reporte_a_proveedor','fecha_de_reporte_a_proveedor','Fecha de reporte al proveedor']),
            'description'=>trim((string)($ticket['description'] ?? $ticket['title'] ?? '')),
        ];
    }
    public function validateForType(array $ticket, string $type, ?string $mojoType=null): bool {
        $form=(int)($ticket['ticket_form_id'] ?? 0); if (!$this->isOpen($ticket)) return false;
        if ($type==='urovo') return $form===self::SYSTEM_FORM && $this->normalizeTerminal($this->ticketField($ticket,['custom_field_problema','problema','Problema']))==='terminalurovo';
        if ($form!==self::VALERAS_FORM) return false;
        $typeInTicket=$this->incidentDataFromTicket($ticket,$type)['type_terminal'];
        return $this->normalizeTerminal($typeInTicket)===$this->normalizeTerminal($mojoType ?: $type);
    }
}
