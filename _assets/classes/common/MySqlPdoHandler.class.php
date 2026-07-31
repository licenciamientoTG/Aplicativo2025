<?php
/**
 * Singleton Pattern
 *
 * @author 
 * @version 1.0
 * @created 24-Jul-2013 9:35:55 AM
 */
class MySqlPdoHandler{
	private static $_singleton;
	private $_username;
	private $_password;
	private $_connection;
	private $_host;
	public $dbname;

	/**
	 * Return: Void
	 */
	private function __construct() {}

	/**
	 * Return: Void
	 */
	public function __destruct() {
		$this->_connection = null;	//Close connection. Destroy the object.
	}

	/**
	 * Return: Instance
	 */
	public static function getInstance() {
		if(!self::$_singleton)
			self::$_singleton = new MySqlPdoHandler();
		return self::$_singleton;
	}

	/**
	 * Registra el detalle de un error de BD (mensaje de PDO, consulta y
	 * parámetros) en el log del servidor. El detalle NUNCA se manda al
	 * navegador: expone estructura de tablas y datos (incluso contraseñas
	 * cuando el parámetro es de un login).
	 */
	private function logQueryError($e, $query = '', $params = NULL) {
		error_log('MySqlPdoHandler: ' . $e->getMessage()
			. ($query !== '' ? "\nQuery: " . $query : '')
			. (!empty($params) ? "\nParams: " . print_r($params, true) : ''));
	}

	/**
	 * PDO convierte los floats de PHP a string al hacer bind; para valores
	 * como 0.07 esa conversión puede salir en notación científica
	 * (ej. "7.0000000000000007E-2"), que SQL Server no puede convertir a
	 * money/decimal. Se formatean a notación decimal plana antes del bind.
	 */
	private function normalizeFloatParams($params) {
		if (empty($params)) {
			return $params;
		}
		foreach ($params as $key => $param) {
			if (is_float($param)) {
				$params[$key] = rtrim(rtrim(sprintf('%.6F', $param), '0'), '.');
			}
		}
		return $params;
	}

	/**
	 * Termina la ejecución con un mensaje genérico y status 500, para los
	 * métodos que históricamente hacían die() ante un error de BD.
	 */
	private function failGeneric() {
		if (!headers_sent()) {
			http_response_code(500);
		}
		die('Error en la base de datos. Contacte al administrador del sistema.');
	}

	/**
	 * Return: Void
	 */
	public function connect($dbname, $host = "192.168.0.6", $username = 'cguser', $password = 'sahei1712') {
		// Evitar reconexiones redundantes: cada modelo llama connect() en su
		// constructor, así que una petición que instancia varios modelos abría
		// una conexión TCP nueva por cada uno. Si ya estamos conectados al
		// mismo servidor/BD/usuario, se reutiliza la conexión existente.
		if ($this->_connection !== null && $this->dbname === $dbname
			&& $this->_host === $host && $this->_username === $username) {
			return;
		}

		$this->_username = $username;
		$this->_password = $password;
		$this->_host = $host;
		$this->dbname = $dbname;

		try{
			$this->_connection = null;	//Close connection. Destroy the object.
			// LoginTimeout: si el servidor destino está caído, fallar en 10s
			// en lugar de colgar la página el timeout por defecto del driver.
			$this->_connection = new PDO("sqlsrv:Server=$host;Database=$dbname;TrustServerCertificate=yes;MultipleActiveResultSets=1;LoginTimeout=10", $this->_username, $this->_password);
			$this->_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}catch(PDOException $e) {
			error_log("MySqlPdoHandler: fallo de conexion a $host/$dbname: " . $e->getMessage());
			if (!headers_sent()) {
				http_response_code(500);
			}
			die("Database connection could not be established!");
		}
	}

	/**
	 * Return: 2D Array
	 */
	public function select($query, $params=NULL) {
		$records=array();
		//Make sure query contains the word "select" and connection is valid
		if(stristr($query,"select") && !empty($this->_connection)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$stmt->execute($this->normalizeFloatParams($params));
				$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
				$stmt->closeCursor();	//Release database resources before issuing next call
			} catch(Exception $e) {
				$this->logQueryError($e, $query, $params);
				$this->failGeneric();
			}
		} else {
			error_log('MySqlPdoHandler: query mal formado (select): ' . $query);
			$this->failGeneric();
		}
		return $records;
	}

	/**
	 * Igual que select() pero NO termina la ejecución (die) cuando una consulta
	 * falla. Devuelve false ante cualquier error para que el llamador pueda
	 * decidir qué hacer (por ejemplo, omitir una estación sin conexión).
	 *
	 * @return array|false
	 */
	public function selectSafe($query, $params = NULL) {
		if (!stristr($query, "select") || empty($this->_connection)) {
			return false;
		}
		$records = array();
		try {
			$stmt = $this->_connection->prepare($query);
			$stmt->execute($this->normalizeFloatParams($params));
			$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$stmt->closeCursor();
		} catch (Exception $e) {
			error_log('selectSafe error: ' . $e->getMessage());
			return false;
		}
		return $records;
	}

	/**
	 * Return: Bool
	 */
	public function update($query, $params) {
		$records=array();
		//Make sure query contains the word "update", connection is valid, and params is valid
		if(stristr($query,"update") && !empty($this->_connection) && !empty($params)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$status=$stmt->execute($this->normalizeFloatParams($params));
				//$rowsAffected = $stmt->rowCount();
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					$this->logQueryError($e, $query, $params);
					$this->failGeneric();
				}
		}
		return false;
	}

	/**
	 * Return: Primary key or true
	 */
	public function insert($query, $params) {
		$pk=0;
		//Make sure query contains the word "insert", connection is valid, and params is valid
		if(stristr($query,"insert") && !empty($this->_connection)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$status = !empty($params) ? $stmt->execute($this->normalizeFloatParams($params)) : $stmt->execute();
				$pk=$this->_connection->lastInsertId();
				$stmt->closeCursor();	//Release database resources before issuing next call
				if(is_numeric($pk) && $pk>0)
					return $pk;
				//If table doesn't have pk and insert is successful, return true
				elseif($status)
					return true;
				} catch(Exception $e) {
					// Se relanza (no die): los flujos con transacciones atrapan
					// esta excepción para hacer rollBack.
					$this->logQueryError($e, $query, $params);
					throw new Exception("Error en la base de datos", 1);
				}
		} else {
			error_log('MySqlPdoHandler: query mal formado (insert): ' . $query);
			$this->failGeneric();
		}
		return $pk;
	}

	/**
	 * Return: Bool
	 */
	public function delete($query, $params) {
		//Make sure query contains the word "delete", connection is valid, and params is valid
		if(stristr($query,"delete") && !empty($this->_connection) && !empty($params)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$status=$stmt->execute($this->normalizeFloatParams($params));
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					$this->logQueryError($e, $query, $params);
					$this->failGeneric();
				}
		}
		return false;
	}

	/**
	 * Ejecuta un Stored Procedure de SQL Server y devuelve un arreglo asociativo con los resultados.
	 * @param string $procedureName El nombre del Stored Procedure a ejecutar.
	 * @param array $params Los parámetros para el Stored Procedure en formato ['nombre_parametro' => 'valor'].
	 * @return array Un arreglo asociativo con los resultados del Stored Procedure.
	 */
	function executeStoredProcedure($procedureName, $params = array()) : array {
		$records = array();
		if (!empty($this->_connection) && !empty($procedureName)) {
			try {
				// Construir el llamado al stored procedure con los parámetros adecuados
				$paramPlaceholders = implode(',', array_fill(0, count($params), '?'));
				$query = "EXEC $procedureName $paramPlaceholders";
				$stmt = $this->_connection->prepare($query);
				// Asociar los parámetros al statement
				$i = 1;
				foreach ($this->normalizeFloatParams($params) as $param) {
					$stmt->bindValue($i++, $param);
				}

				$stmt->execute();

				$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

				$stmt->closeCursor();

			} catch(Exception $e) {
				// Se relanza (no die): hay callers que atrapan esta excepción.
				$this->logQueryError($e, $query ?? $procedureName, $params);
				throw new Exception("Error en la base de datos", 1);
			}
		}

		return $records;
	}

	/**
	 * NOTE: This function allows for flexibility. Only use this function, if you know what you are doing.
	 * Return: Bool or 2D array
	 */
	public function query($query, $params=NULL) {
		$records=array();
		//Make sure connection is valid
		if(!empty($query) && !empty($this->_connection)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$status=$stmt->execute($this->normalizeFloatParams($params));
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					// Se relanza (no die): hay callers que atrapan esta excepción.
					$this->logQueryError($e, $query, $params);
					throw new Exception("Error en la base de datos", 1);
				}
		} else {
			return false;
		}
	}



    public function selectMultiple($query, $params = NULL) {
        $results = [];
        if (!empty($this->_connection)) {
            try {
                $stmt = $this->_connection->prepare($query);
                $stmt->execute($this->normalizeFloatParams($params) ?: []);
                do {
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $results[] = $rows ?: false;
                } while ($stmt->nextRowset());
                $stmt->closeCursor();
            } catch (Exception $e) {
                $this->logQueryError($e, $query, $params);
                $this->failGeneric();
            }
        }
        return $results;
    }

    public function getConnection() {
        return $this->_connection;
    }

	 /**
     * Inicia una transacción.
     * @return void
     */
    public function beginTransaction() {
        if ($this->_connection) {
            $this->_connection->beginTransaction();
        }
    }

    /**
     * Realiza commit de la transacción actual.
     * @return void
     */
    public function commit() {
        if ($this->_connection && $this->_connection->inTransaction()) {
            $this->_connection->commit();
        }
    }

    /**
     * Realiza un rollback de la transacción actual.
     * @return void
     */
    public function rollBack() {
        if ($this->_connection && $this->_connection->inTransaction()) {
            $this->_connection->rollBack();
        }
    }
}
?>
