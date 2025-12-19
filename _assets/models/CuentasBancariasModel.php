<?php

class CuentasBancariasModel extends Model {
    // Propiedades de la tabla
    public $Id;
    public $FechaAlta;
    public $CuentaLocal;
    public $Descripcion;
    public $TipoCuenta;
    public $Banco;
    public $Pais;
    public $Ciudad;
    public $EntidadCuentaLocalABASwift;
    public $Divisa;
    public $IndicadorCuentaActiva;
    public $Cesta;
    public $Producto;
    public $Subtipo;
    public $Estado;
    public $IdBeneficiario;
    public $TitularCuenta;
    public $FechaRegistro;
    public $UsuarioRegistro;
    public $FechaModificacion;
    public $UsuarioModificacion;
    public $Activo;

    /**
     * Obtiene todas las cuentas bancarias activas
     */
    public function get_cuentas() : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE Activo = 1;';
        $params = [];
        return $this->sql->select($query, $params) ?: false;
    }

    /**
     * Obtiene una cuenta específica por su Id
     */
    public function get_by_id($id) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE Id = ?;';
        $params = [$id];
        $result = $this->sql->select($query, $params);
        return $result ? $result[0] : false;
    }
    public function get_by_name($name) : array|false {
        $query = 'SELECT * FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE Descripcion LIKE ?;';
        $params = ["%$name%"];
        $result = $this->sql->select($query, $params);
        return $result ? $result[0] : false;
    }

    /**
     * Agrega una nueva cuenta bancaria
     */
    public function add($data, $usuario_id) : int {
        // Validación de duplicados (ejemplo por número de cuenta)
        if ($this->sql->select('SELECT TOP (1) Id FROM [TG].[dbo].[CatalogosCuentasBancarias] WHERE CuentaLocal = ?;', [$data['CuentaLocal']])) {
            return 2; // Duplicado
        }

        $query = 'INSERT INTO [TG].[dbo].[CatalogosCuentasBancarias] (
                    [FechaAlta], [CuentaLocal], [Descripcion], [TipoCuenta], [Banco], 
                    [Pais], [Ciudad], [EntidadCuentaLocalABASwift], [Divisa], [IndicadorCuentaActiva], 
                    [Cesta], [Producto], [Subtipo], [Estado], [IdBeneficiario], 
                    [TitularCuenta], [FechaRegistro], [UsuarioRegistro], [Activo]
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, 1);';

        $params = [
            $data['FechaAlta'], $data['CuentaLocal'], $data['Descripcion'], $data['TipoCuenta'], $data['Banco'],
            $data['Pais'], $data['Ciudad'], $data['EntidadCuentaLocalABASwift'], $data['Divisa'], $data['IndicadorCuentaActiva'],
            $data['Cesta'], $data['Producto'], $data['Subtipo'], $data['Estado'], $data['IdBeneficiario'],
            $data['TitularCuenta'], $usuario_id
        ];

        return ($this->sql->insert($query, $params)) ? 1 : 0;
    }

    /**
     * Edita una cuenta existente
     */
    public function edit($id, $data, $usuario_id) : bool {
        $query = 'UPDATE [TG].[dbo].[CatalogosCuentasBancarias] SET 
                    [FechaAlta] = ?, [CuentaLocal] = ?, [Descripcion] = ?, [TipoCuenta] = ?, [Banco] = ?, 
                    [Pais] = ?, [Ciudad] = ?, [EntidadCuentaLocalABASwift] = ?, [Divisa] = ?, [IndicadorCuentaActiva] = ?, 
                    [Cesta] = ?, [Producto] = ?, [Subtipo] = ?, [Estado] = ?, [IdBeneficiario] = ?, 
                    [TitularCuenta] = ?, [FechaModificacion] = GETDATE(), [UsuarioModificacion] = ?, [Activo] = ?
                WHERE [Id] = ?;';

        $params = [
            $data['FechaAlta'], $data['CuentaLocal'], $data['Descripcion'], $data['TipoCuenta'], $data['Banco'],
            $data['Pais'], $data['Ciudad'], $data['EntidadCuentaLocalABASwift'], $data['Divisa'], $data['IndicadorCuentaActiva'],
            $data['Cesta'], $data['Producto'], $data['Subtipo'], $data['Estado'], $data['IdBeneficiario'],
            $data['TitularCuenta'], $usuario_id, $data['Activo'], $id
        ];

        return (bool)$this->sql->update($query, $params);
    }

    /**
     * Desactivación lógica de la cuenta
     */
    public function delete($id) : bool {
        $query = 'UPDATE [TG].[dbo].[CatalogosCuentasBancarias] SET [Activo] = 0 WHERE Id = ?;';
        return (bool)$this->sql->update($query, [$id]);
    }
}