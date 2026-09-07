<?php
class MojoTerminalTicketsService {
    private const BASE_URL = 'https://totalgas.mojohelpdesk.com/api';
    private const SYSTEM_FORM = 51598;
    private const VALERAS_FORM = 84607;
    private const SYSTEM_QUEUE = 53551;
    private string $key;
    public function __construct() { $this->key=(string)getenv('MOJO_ACCESS_KEY'); if ($this->key==='') throw new RuntimeException('La integración de Mojo no está configurada.'); }
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
    public function validateForType(array $ticket, string $type): bool {
        $form=(int)($ticket['ticket_form_id'] ?? 0); if (!$this->isOpen($ticket)) return false;
        return $type==='urovo' ? $form===self::SYSTEM_FORM && (($ticket['custom_field_problema'] ?? '')==='Terminal Urovo') : $form===self::VALERAS_FORM;
    }
}
