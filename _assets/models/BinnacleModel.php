<?php
class BinnacleModel extends Model{
    function get_binnacle() : array | false {
        $query = 'SELECT t1.*, t2.Usuario, t2.Nombre FROM [TG].[dbo].[tg_binnacle] t1 LEFT JOIN [TG].[dbo].[Usuario] t2 ON t1.user_id = t2.Id ORDER BY t1.id DESC;';
        return ($this->sql->select($query)) ?: false;
    }
}