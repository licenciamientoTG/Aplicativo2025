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

    public function get_by_provider_name($provider_name) {
         $baseName = strtoupper(trim($provider_name));
        $baseName = str_replace('.', '', $baseName);
        $baseName = preg_split('/\s+/', $baseName)[0]; // ← SOLO "PREMIERGAS"
        $query = "
            SELECT 
               *
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE [Descripcion] LIKE ?
            AND Activo = 1
            ORDER BY Banco, CuentaLocal
        ";
        
        $params = ['%' . $baseName . '%'];
    $result = $this->sql->select($query, $params);

        return $result ?: false;
    }

    public function get_cuenta_cargo_santander($emp_cod) {
        $query = "
            SELECT TOP 1
                Id,
                CuentaLocal,
                Descripcion,
                TipoCuenta,
                Banco,
                TitularCuenta,
                emp_cod
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE emp_cod = ?
            AND Tipo = 'Propias'
            AND Banco = 'SANTANDER'
            AND Activo = 1
            ORDER BY FechaAlta DESC
        ";
        
        $params = [$emp_cod];
        $result = $this->sql->select($query, $params);
        
        return $result ? $result[0] : false;
    }

    /**
     * ✅ Obtiene la cuenta TERCERO (abono/beneficiario) de un proveedor
     * Busca por coincidencia en el nombre del titular
     * @param string $proveedor_nombre Nombre del proveedor
     * @param string $proveedor_rfc RFC del proveedor (opcional)
     * @return array|false Datos de la cuenta o false si no existe
     */
    public function get_cuenta_beneficiario_by_proveedor($proveedor_nombre, $proveedor_rfc = null) {
        // Limpiar y extraer primera palabra del nombre del proveedor
        $baseName = strtoupper(trim($proveedor_nombre));
        $baseName = str_replace('.', '', $baseName);
        $palabras = preg_split('/\s+/', $baseName);
        $primeraPalabra = $palabras[0];
        
        $query = "
            SELECT TOP 1
                Id,
                CuentaLocal AS clabe_beneficiario,
                Descripcion,
                TitularCuenta AS titular_beneficiario,
                Banco,
                TipoCuenta
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE Tipo = 'Terceros'
            AND Banco = 'SANTANDER'
            AND Activo = 1
            AND (
                -- Buscar por nombre
                TitularCuenta LIKE ?
                OR Descripcion LIKE ?
                " . ($proveedor_rfc ? "OR TitularCuenta LIKE ?" : "") . "
            )
            ORDER BY 
                CASE 
                    WHEN TitularCuenta LIKE ? THEN 1  -- Coincidencia exacta con primera palabra
                    WHEN Descripcion LIKE ? THEN 2    -- Coincidencia en descripción
                    ELSE 3
                END,
                FechaAlta DESC
        ";
        
        // Construir parámetros
        $params = [
            '%' . $primeraPalabra . '%',  // TitularCuenta LIKE
            '%' . $primeraPalabra . '%'   // Descripcion LIKE
        ];
        
        if ($proveedor_rfc) {
            $params[] = '%' . $proveedor_rfc . '%';  // RFC en TitularCuenta
        }
        
        $params[] = $primeraPalabra . '%';  // ORDER BY primera palabra exacta
        $params[] = $primeraPalabra . '%';  // ORDER BY descripción
        
        $result = $this->sql->select($query, $params);
        
        return $result ? $result[0] : false;
    }

    /**
     * ✅ Obtiene todas las cuentas Santander PROPIAS activas
     * @return array|false
     */
    public function get_cuentas_santander_propias() {
        $query = "
            SELECT 
                emp_cod,
                CuentaLocal,
                TitularCuenta,
                Descripcion
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE Tipo = 'Propias'
            AND Banco = 'SANTANDER'
            AND Activo = 1
            ORDER BY emp_cod
        ";
        
        return $this->sql->select($query) ?: false;
    }

    /**
     * ✅ Obtiene todas las cuentas Santander TERCEROS activas
     * @return array|false
     */
    public function get_cuentas_santander_terceros() {
        $query = "
            SELECT 
                Id,
                CuentaLocal AS clabe,
                TitularCuenta AS titular,
                Descripcion
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE Tipo = 'Terceros'
            AND Banco = 'SANTANDER'
            AND Activo = 1
            ORDER BY TitularCuenta
        ";
        
        return $this->sql->select($query) ?: false;
    }

    /**
     * ✅ Valida que una empresa tenga cuenta Santander configurada
     * @param int $emp_cod
     * @return bool
     */
    public function tiene_cuenta_santander($emp_cod) {
        $cuenta = $this->get_cuenta_cargo_santander($emp_cod);
        return $cuenta !== false;
    }

    /**
     * ✅ Búsqueda flexible de cuenta beneficiario (para debugging)
     * @param string $search Término de búsqueda
     * @return array
     */
    /**
     * ✅ ADMIN: lista TODAS las cuentas (activas e inactivas) con los campos
     * relevantes para administración, incluyendo Tipo, emp_cod y proveedor_cod.
     */
    public function get_cuentas_admin() : array {
        $query = "
            SELECT
                Id,
                FechaAlta,
                CuentaLocal,
                Descripcion,
                TipoCuenta,
                Banco,
                Divisa,
                TitularCuenta,
                Tipo,
                emp_cod,
                proveedor_cod,
                Activo,
                FechaModificacion,
                UsuarioModificacion
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            ORDER BY Descripcion, Banco, CuentaLocal
        ";
        return $this->sql->select($query) ?: [];
    }

    /**
     * ✅ ADMIN: actualiza los campos editables desde la vista de administración.
     * A diferencia de edit(), incluye Tipo, emp_cod y proveedor_cod (claves para
     * que el layout seleccione la cuenta correcta) y registra usuario/fecha.
     */
    public function update_admin($id, array $data, $usuario_id) : bool {
        $query = "
            UPDATE [TG].[dbo].[CatalogosCuentasBancarias] SET
                CuentaLocal         = ?,
                Descripcion         = ?,
                Banco               = ?,
                TitularCuenta       = ?,
                Tipo                = ?,
                Divisa              = ?,
                emp_cod             = ?,
                proveedor_cod       = ?,
                Activo              = ?,
                FechaModificacion   = GETDATE(),
                UsuarioModificacion = ?
            WHERE Id = ?
        ";
        $params = [
            $data['CuentaLocal'],
            $data['Descripcion'],
            $data['Banco'],
            $data['TitularCuenta'],
            $data['Tipo']           !== '' ? $data['Tipo']           : null,
            $data['Divisa']         !== '' ? $data['Divisa']         : null,
            $data['emp_cod']        !== '' ? $data['emp_cod']        : null,
            $data['proveedor_cod']  !== '' ? $data['proveedor_cod']  : null,
            (int)$data['Activo'],
            $usuario_id,
            $id,
        ];
        return (bool)$this->sql->update($query, $params);
    }

    /**
     * ✅ ADMIN: activa/desactiva (Activo = 1/0) registrando usuario y fecha.
     */
    public function set_activo($id, int $activo, $usuario_id) : bool {
        $query = "
            UPDATE [TG].[dbo].[CatalogosCuentasBancarias]
            SET Activo = ?, FechaModificacion = GETDATE(), UsuarioModificacion = ?
            WHERE Id = ?
        ";
        return (bool)$this->sql->update($query, [$activo, $usuario_id, $id]);
    }

    public function buscar_cuenta_tercero($search) {
        $query = "
            SELECT 
                Id,
                CuentaLocal,
                TitularCuenta,
                Descripcion,
                FechaAlta
            FROM [TG].[dbo].[CatalogosCuentasBancarias]
            WHERE Tipo = 'Terceros'
            AND Banco = 'SANTANDER'
            AND Activo = 1
            AND (
                TitularCuenta LIKE ?
                OR Descripcion LIKE ?
                OR CuentaLocal LIKE ?
            )
            ORDER BY TitularCuenta
        ";
        
        $params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
        
        return $this->sql->select($query, $params) ?: [];
    }
}