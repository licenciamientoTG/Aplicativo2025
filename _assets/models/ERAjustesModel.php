<?php
class ERAjustesModel extends Model {
    public $id;
    public $empresa;
    public $centro_costo;
    public $estado_resultados;
    public $no_cuenta;
    public $rubro;
    public $concepto;
    public $enero;
    public $febrero;
    public $marzo;
    public $abril;
    public $mayo;
    public $junio;
    public $julio;
    public $agosto;
    public $septiembre;
    public $octubre;
    public $noviembre;
    public $diciembre;
    public $fecha;
    public $fecha_agregado;
    public $usuario; // lo quitaste del formulario, pero existe en la tabla

    public function get_all() : array|false {
        $query = 'SELECT * FROM [TGV2].[dbo].[ERAjustes] ORDER BY fecha_agregado DESC;';
        $params = [];
        return $this->sql->select($query, $params) ?: false;
    }

    public function get_by_id($id) : array|false {
        $query = 'SELECT * FROM [TGV2].[dbo].[ERAjustes] WHERE id = ?;';
        $params = [$id];
        $result = $this->sql->select($query, $params);
        return $result ? $result[0] : false;
    }

    /**
     * Agregar nuevo ajuste
     */
    public function add($data) : int|false {
        $query = 'INSERT INTO [TGV2].[dbo].[ERAjustes] (
            empresa, centro_costo, estado_resultados, no_cuenta, rubro, concepto,
            enero, febrero, marzo, abril, mayo, junio, julio, agosto, septiembre, octubre, noviembre, diciembre, fecha
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);';

        $params = [
            $data['empresa'],
            $data['centro_costo'],
            $data['estado_resultados'],
            $data['no_cuenta'],
            $data['rubro'],
            $data['concepto'],
            $data['enero'],
            $data['febrero'],
            $data['marzo'],
            $data['abril'],
            $data['mayo'],
            $data['junio'],
            $data['julio'],
            $data['agosto'],
            $data['septiembre'],
            $data['octubre'],
            $data['noviembre'],
            $data['diciembre'],
            $data['fecha']
        ];
        return $this->sql->insert($query, $params);
    }

    /**
     * Editar ajuste por id
     */
    public function edit($id, $data) : bool {
        $query = 'UPDATE [TGV2].[dbo].[ERAjustes] SET
            empresa = ?,
            centro_costo = ?,
            estado_resultados = ?,
            no_cuenta = ?,
            rubro = ?,
            concepto = ?,
            enero = ?, febrero = ?, marzo = ?, abril = ?, mayo = ?, junio = ?,
            julio = ?, agosto = ?, septiembre = ?, octubre = ?, noviembre = ?, diciembre = ?,
            fecha = ?
        WHERE id = ?;';
        $params = [
            $data['empresa'],
            $data['centro_costo'],
            $data['estado_resultados'],
            $data['no_cuenta'],
            $data['rubro'],
            $data['concepto'],
            $data['enero'],
            $data['febrero'],
            $data['marzo'],
            $data['abril'],
            $data['mayo'],
            $data['junio'],
            $data['julio'],
            $data['agosto'],
            $data['septiembre'],
            $data['octubre'],
            $data['noviembre'],
            $data['diciembre'],
            $data['fecha'],
            $id
        ];
        return $this->sql->update($query, $params);
    }

    /**
     * Eliminar ajuste por id
     */
    public function delete($id) : bool {
        $query = 'DELETE FROM [TGV2].[dbo].[ERAjustes] WHERE id = ?;';
        $params = [$id];
        return $this->sql->delete($query, $params);
    }
}
