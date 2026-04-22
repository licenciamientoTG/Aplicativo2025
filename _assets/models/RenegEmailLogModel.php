<?php
class RenegEmailLogModel {
    public $sql;
    function __construct() {
        $this->sql = MySqlPdoHandler::getInstance();
        $this->sql->connect('TG');
    }

    public function log_send(array $data): int {
        $query = "INSERT INTO [TG].[dbo].[reneg_email_log]
                    (upload_id, responsable, correo, total_facturas, monto_total, enviado_por, status, error_msg)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        return $this->sql->insert($query, [
            $data['upload_id']     ?? null,
            $data['responsable'],
            $data['correo'],
            $data['total_facturas'] ?? 0,
            $data['monto_total']    ?? null,
            $data['enviado_por']    ?? null,
            $data['status']         ?? 'sent',
            $data['error_msg']      ?? null,
        ]);
    }

    public function get_log(?int $upload_id = null): array|false {
        $params = [];
        $where  = '';
        if ($upload_id) {
            $where  = 'WHERE upload_id = ?';
            $params = [$upload_id];
        }
        $query = "SELECT id, upload_id, responsable, correo, total_facturas,
                         monto_total, sent_at, status, error_msg
                  FROM [TG].[dbo].[reneg_email_log]
                  {$where}
                  ORDER BY sent_at DESC";
        return $this->sql->select($query, $params) ?: false;
    }
}
