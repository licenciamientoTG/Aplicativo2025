<?php
class PaymentRequestsModel extends Model
{
    public $id;
    public $request_date;
    public $user_id;
    public $comment;
    public $status;
    public $date_added;

    /**
     * Obtiene una lista de solicitudes de pago.
     * @return array|false
     */
    public function get_all_requests() : array|false {
        $query = 'SELECT id, request_date, user_id, comment, status, date_added FROM [TG].[dbo].[payment_requests] ORDER BY request_date DESC;';
        return ($this->sql->select($query)) ?: false;
    }

    /**
     * Obtiene una solicitud de pago por su ID.
     * @param int $id
     * @return array|false
     */
    public function get_request_by_id($id) : array|false {
        $query = 'SELECT id, request_date, user_id, comment, status, date_added FROM [TG].[dbo].[payment_requests] WHERE id = ?;';
        return ($this->sql->select($query, [$id])) ?: false;
    }

    /**
     * Inserta una nueva solicitud de pago.
     * @param string $request_date
     * @param int $user_id
     * @param string $comment
     * @param string $status
     * @return bool
     */
    public function insert_request($request_date, $user_id, $comment, $status) : int|false {
        $query = 'INSERT INTO 
                    [TG].[dbo].[payment_requests] 
                    (request_date, user_id, comment, [status])
                    VALUES (?, ?, ?, ?);';

        $insert = $this->sql->insert($query, [$request_date, $user_id, $comment, $status]);

        return $insert ?: false;
    }

    /**
     * Actualiza una solicitud de pago.
     * @param int $id
     * @param string $status
     * @param string|null $comment
     * @return bool
     */
    public function update_request_status($id, $status, $comment = null) : bool {
        $query = 'UPDATE [TG].[dbo].[payment_requests] SET status = ?, comment = ISNULL(?, comment) WHERE id = ?;';
        return $this->sql->update($query, [$status, $comment, $id]);
    }

    /**
     * Elimina una solicitud de pago por su ID.
     * @param int $id
     * @return bool
     */
    public function delete_request($id) : bool {
        $query = 'DELETE FROM [TG].[dbo].[payment_requests] WHERE id = ?;';
        return $this->sql->delete($query, [$id]);
    }
}
