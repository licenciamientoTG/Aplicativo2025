<?php
class SMBRemoteExecution {
    private $servidor;
    private $rutaCompartida;

    public function __construct($servidor,$rutaCompartida) {
        $this->servidor = $servidor;
        $this->rutaCompartida = $rutaCompartida;
    }

    public function conectarCompartido() {
        $comando = "net use \\\\192.168.5.101\\Software\\Scripts\\volumetric_runner";
        
        // Mostrar comando completo para verificar
        echo "Comando ejecutado: " . $comando . "\n";
        
        exec($comando, $salida, $codigoRetorno);
        
        // Mostrar detalles de salida y código de retorno
        echo "Código de retorno: " . $codigoRetorno . "\n";
        echo "Salida del comando:\n";
        print_r($salida);
        die();
        return $codigoRetorno == 0;
    }

    public function ejecutarArchivoRemoto($nombreEjecutable) {
        // Ruta completa del ejecutable compartido
        $rutaEjecutable = "\\\\" . $this->servidor . "\\" . $this->rutaCompartida . "\\" . $nombreEjecutable;

        // Intentar ejecutar el archivo
        $shell = new COM('WScript.Shell');
        try {
            $shell->Run($rutaEjecutable, 0, false);
            return true;
        } catch (Exception $e) {
            error_log("Error al ejecutar: " . $e->getMessage());
            return false;
        }
    }

    public function desconectarCompartido() {
        // Desconectar la unidad de red
        $comando = "net use \\\\" . $this->servidor . "\\" . $this->rutaCompartida . " /delete";
        exec($comando);
    }
}
?>