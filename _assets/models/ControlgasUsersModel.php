<?php
class ControlgasUsersModel extends Model {

    public function disable_user(int $cod): array {
        $query = "UPDATE [SG12].[dbo].[Usuarios]
                  SET [clv]    = 'O5fz43Stf5x2C5W7j3CcCrWGjcWHCdMB',
                      [acc]    = 0,
                      [accx]   = 0,
                      [codrol] = 0,
                      [codest] = 0
                  WHERE cod = ?";
        $ok = $this->sql->update($query, [$cod]);
        return $ok
            ? ['success' => true,  'message' => 'Usuario deshabilitado']
            : ['success' => false, 'message' => 'No se pudo deshabilitar el usuario'];
    }

    public function get_users(): array {
        $query = "SELECT TOP (1000)
                    [cod], [den], [clv], [acc], [accx],
                    [tipopr], [tipusu], [codrol], [codest],
                    [logusu], [logfch], [lognew], [userid],
                    [clvfch], [clvexp]
                  FROM [SG12].[dbo].[Usuarios]
                  ORDER BY [cod]";
        return $this->sql->select($query) ?: [];
    }
}
