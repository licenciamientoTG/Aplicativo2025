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
	 * Return: Void
	 */
	public function connect($dbname, $host = "192.168.0.6", $username = 'cguser', $password = 'sahei1712') {
		// print_r(PDO::getAvailableDrivers());

		$this->_username = $username;
		$this->_password = $password;
		$this->dbname = $dbname;

		try{
			$this->_connection = null;	//Close connection. Destroy the object.
			$this->_connection = new PDO("sqlsrv:Server=$host;Database=$dbname;TrustServerCertificate=yes;MultipleActiveResultSets=1", $this->_username, $this->_password);
			$this->_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->_connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}catch(PDOException $e) {
			echo $e->getMessage();
			die("Database connection could not be established!<br/>");
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
				$stmt->execute($params);
				$i=0;
				while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
					$records[$i++] = $row;	//Put row into array
				}
				$stmt->closeCursor();	//Release database resources before issuing next call
			} catch(Exception $e) {
				$errorMessage = 'Error en la consulta: ' . $e->getMessage();
				$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
				$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error

				echo $errorMessage; // Muestra el mensaje de error en pantalla
				echo '<pre>';
				var_dump($errorMessage);
				die();
				throw new Exception("Error en la base de datos", 1);
			}
		} else {
			echo '<pre>';
			var_dump("Query mal formado");
			die();
		}
		return $records;
	}

	public function select2($query, $params=NULL) {
		$records=array();
		//Make sure query contains the word "select" and connection is valid
		try{
			$stmt = $this->_connection->prepare($query);
			$stmt->execute($params);
			$i=0;
			echo '<pre>';
			var_dump($stmt->fetch(PDO::FETCH_COLUMN, 0));
			die();
			while($row = $stmt->fetch(PDO::FETCH_COLUMN, 0)) {
				$records[$i++] = $row;	//Put row into array
			}
			$stmt->closeCursor();	//Release database resources before issuing next call
		} catch(Exception $e) {
			$errorMessage = 'Error en la consulta: ' . $e->getMessage();
			$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
			$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error

			echo $errorMessage; // Muestra el mensaje de error en pantalla
			echo '<pre>';
			var_dump($errorMessage);
			die();
			throw new Exception("Error en la base de datos", 1);
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
				$status=$stmt->execute($params);
				//$rowsAffected = $stmt->rowCount();
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					$errorMessage = 'Error en la consulta: ' . $e->getMessage();
					$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
					$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error
	
					echo $errorMessage; // Muestra el mensaje de error en pantalla
					echo '<pre>';
					var_dump($errorMessage);
					die();
					throw new Exception("Error en la base de datos", 1);
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
		if(stristr($query,"insert") && !empty($this->_connection) && !empty($params)) {
			try{
				$stmt = $this->_connection->prepare($query);
				$status=$stmt->execute($params);
				$pk=$this->_connection->lastInsertId();
				$stmt->closeCursor();	//Release database resources before issuing next call
				if(is_numeric($pk) && $pk>0)
					return $pk;
				//If table doesn't have pk and insert is successful, return true
				elseif($status)
					return true;
				} catch(Exception $e) {
					$errorMessage = 'Error en la consulta: ' . $e->getMessage();
					$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
					$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error
	
					echo $errorMessage; // Muestra el mensaje de error en pantalla
					echo '<pre>';
					var_dump($errorMessage);
					die();
					throw new Exception("Error en la base de datos", 1);
				}
		} else {
			echo '<pre>';
			var_dump("Query mal formado");
			die();
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
				$status=$stmt->execute($params);
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					$errorMessage = 'Error en la consulta: ' . $e->getMessage();
					$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
					$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error
	
					echo $errorMessage; // Muestra el mensaje de error en pantalla
					echo '<pre>';
					var_dump($errorMessage);
					die();
					throw new Exception("Error en la base de datos", 1);
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
				foreach ($params as $param) {
					$stmt->bindValue($i++, $param);
				}

				$stmt->execute();

				$i = 0;
				while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
					$records[$i++] = $row;
				}

				$stmt->closeCursor();

			} catch(Exception $e) {
				$errorMessage = 'Error en la consulta: ' . $e->getMessage();
				$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
				$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error

				echo $errorMessage; // Muestra el mensaje de error en pantalla
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
				$status=$stmt->execute($params);
				$stmt->closeCursor();	//Release database resources before issuing next call
				if($status)
					return true;
				} catch(Exception $e) {
					$errorMessage = 'Error en la consulta: ' . $e->getMessage();
					$errorMessage .= "\nQuery: " . $query; // Agrega la consulta al mensaje de error
					$errorMessage .= "\nParams: " . print_r($params, true); // Agrega los parámetros al mensaje de error
	
					echo $errorMessage; // Muestra el mensaje de error en pantalla
					throw new Exception("Error en la base de datos", 1);
				}
		} else {
			return false;
		}
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
