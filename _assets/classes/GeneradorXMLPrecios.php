<?php
/**
 * Generador de XMLs de precios CRE y envío por correo
 * Versión mejorada con eliminación automática de archivos
 * 
 * Uso: generarYEnviarXML('14:00', true); // true para eliminar archivos
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class GeneradorXMLPrecios {
    
    // Configuración de Base de Datos
    private $dbConfig = [
        'server' => '192.168.0.6',
        'database' => 'TG',
        'username' => 'cguser',
        'password' => 'sahei1712'
    ];
    
    // Configuración de correo
    private $emailConfig = [
        'from' => 'no-reply@totalgas.com',
        'password' => 'sysdhepknmlkigbs',
        'smtp_server' => 'smtp.gmail.com',
        'smtp_port' => 465,
        'to' => 'aldo.ochoa@totalgas.com'
    ];
    
    private $errores = [];
    private $outputDir = 'xml_output';
    private $eliminarDespuesDeEnviar = true; // Por defecto elimina
    
    /**
     * Constructor
     * 
     * @param bool $eliminarArchivos Si es true, elimina archivos después de enviarlos
     */
    public function __construct($eliminarArchivos = true) {
        $this->eliminarDespuesDeEnviar = $eliminarArchivos;
        
        // Crear directorio si no existe
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Agrega correos adicionales a la configuración de envío
     * 
     * @param array $emailsExtra Array de correos adicionales
     */
    public function setEmailsAdicionales(array $emailsExtra)
    {
        // Limpiar y filtrar vacíos
        $emailsExtra = array_filter(array_map('trim', $emailsExtra));

        if (empty($emailsExtra)) {
            return;
        }

        // Correos base configurados en la clase
        $base = $this->emailConfig['to']; // string con comas

        $listaBase = array_filter(array_map('trim', explode(',', $base)));

        // Unir base + extras y quitar duplicados
        $todos = array_unique(array_merge($listaBase, $emailsExtra));

        // Guardar de nuevo en la config
        $this->emailConfig['to'] = implode(',', $todos);
    }
    
    /**
     * Obtiene conexión a SQL Server
     */
    private function getConnection() {
        $serverName = $this->dbConfig['server'];
        $connectionOptions = [
            "Database" => $this->dbConfig['database'],
            "Uid" => $this->dbConfig['username'],
            "PWD" => $this->dbConfig['password'],
            "TrustServerCertificate" => true,
            "Encrypt" => false
        ];
        
        $conn = sqlsrv_connect($serverName, $connectionOptions);
        
        if ($conn === false) {
            throw new Exception("Error de conexión: " . print_r(sqlsrv_errors(), true));
        }
        
        return $conn;
    }
    
    /**
     * Obtiene los últimos precios de combustible agrupados por RFC
     */
    private function obtenerPreciosAgrupadosPorRFC() {
        $conn = $this->getConnection();
        
        $sql = "
            WITH Ultimos AS (
                SELECT
                    codgas,
                    codprd,
                    precio,
                    price_date,
                    hour,
                    cre_permission,
                    rfc,
                    creProductId,
                    creSubProductId,
                    creSubProductBrandId,
                    created_at,
                    ROW_NUMBER() OVER (PARTITION BY codgas, codprd ORDER BY id DESC) AS rn
                FROM [TG].[dbo].[FuelPrices]
            )
            SELECT
                codgas,
                codprd,
                precio,
                price_date,
                hour,
                cre_permission,
                rfc,
                creProductId,
                creSubProductId,
                creSubProductBrandId,
                created_at
            FROM Ultimos
            WHERE rn = 1
        ";
        
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt === false) {
            sqlsrv_close($conn);
            throw new Exception("Error al ejecutar query: " . print_r(sqlsrv_errors(), true));
        }
        
        $agrupado = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rfc = $row['rfc'];
            if (!isset($agrupado[$rfc])) {
                $agrupado[$rfc] = [];
            }
            $agrupado[$rfc][] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        sqlsrv_close($conn);
        
        return $agrupado;
    }
    
    /**
     * Limpia el directorio de XMLs anteriores
     */
    private function limpiarDirectorioXML() {
        $archivos = glob($this->outputDir . '/*.xml');
        foreach ($archivos as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
                echo "Archivo previo eliminado: $archivo\n";
            }
        }
    }
    
    /**
     * Limpia el nombre de archivo
     */
    private function limpiarNombreArchivo($texto) {
        return preg_replace('/[^\w\-]/', '', $texto);
    }
    
    /**
     * Genera XMLs agrupados por RFC y permiso CRE
     * 
     * @param array $datosAgrupados Datos agrupados por RFC
     * @param string $hora Hora de aplicación en formato HH:MM
     * @param int $zonaHorariaId ID de zona horaria
     */
    private function generarXMLsPorRFC($datosAgrupados, $hora, $zonaHorariaId = 1) {
        $fechaHoy = date('d/m/Y');
        
        $this->limpiarDirectorioXML();
        
        foreach ($datosAgrupados as $rfc => $registros) {
            // Agrupar por permiso CRE
            $permisosPorPermiso = [];
            foreach ($registros as $rec) {
                $permiso = $rec['cre_permission'];
                if (!isset($permisosPorPermiso[$permiso])) {
                    $permisosPorPermiso[$permiso] = [];
                }
                $permisosPorPermiso[$permiso][] = $rec;
            }
            
            // Crear XML
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->formatOutput = true;
            
            // Elemento raíz
            $root = $dom->createElement('ReportePrecios');
            $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $root->setAttribute('xsi:noNamespaceSchemaLocation', 'https://xsd.cne.gob.mx/Produccion/2641/v1/Esquema.xsd');
            $dom->appendChild($root);
            
            // Agregar permisos
            foreach ($permisosPorPermiso as $crePermission => $permisoRegistros) {
                $permisoElement = $dom->createElement('Permiso');
                $permisoElement->setAttribute('Numero', $crePermission);
                $permisoElement->setAttribute('RFC', $rfc);
                $permisoElement->setAttribute('ZonaHorariaId', $zonaHorariaId);
                $root->appendChild($permisoElement);
                
                // Agregar precios
                foreach ($permisoRegistros as $rec) {
                    $nuevoPrecio = $dom->createElement('NuevoPrecio');
                    $nuevoPrecio->setAttribute('TipoProductoId', $rec['creProductId']);
                    $nuevoPrecio->setAttribute('TipoSubProductoId', $rec['creSubProductId']);
                    $nuevoPrecio->setAttribute('TipoSubProductoMarcaId', $rec['creSubProductBrandId']);
                    $nuevoPrecio->setAttribute('OtraMarca', '');
                    $nuevoPrecio->setAttribute('Precio', number_format($rec['precio'], 2, '.', ''));
                    $nuevoPrecio->setAttribute('FechaAplicacion', $fechaHoy);
                    $nuevoPrecio->setAttribute('HoraAplicacion', $hora);
                    $permisoElement->appendChild($nuevoPrecio);
                }
            }
            
            // Guardar XML
            $rfcLimpio = $this->limpiarNombreArchivo($rfc);
            $fechaActualHora = date('Ymd_Hi');
            $nombreArchivo = "{$rfcLimpio}_{$fechaActualHora}.xml";
            $rutaCompleta = $this->outputDir . '/' . $nombreArchivo;
            
            $dom->save($rutaCompleta);
        }
    }
    
    /**
     * Envía correo con archivos XML adjuntos
     * 
     * @param string $asunto Asunto del correo
     * @param string $cuerpo Cuerpo del mensaje
     * @param array $adjuntos Array de rutas de archivos
     * @return bool True si el correo se envió exitosamente
     */
    private function enviarCorreoConAdjuntos($asunto, $cuerpo, $adjuntos) {
        
        $mail = new PHPMailer(true);
        
        try {
            // Configuración del servidor
            $mail->isSMTP();
            $mail->Host = $this->emailConfig['smtp_server'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->emailConfig['from'];
            $mail->Password = $this->emailConfig['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $this->emailConfig['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
            // Destinatarios
            $mail->setFrom($this->emailConfig['from'], 'TotalGas Desarrollo');
            
            $destinatarios = explode(',', $this->emailConfig['to']);
            foreach ($destinatarios as $destinatario) {
                $mail->addAddress(trim($destinatario));
            }
            
            // Contenido
            $mail->isHTML(false);
            $mail->Subject = $asunto;
            $mail->Body = $cuerpo;
            
            // Adjuntos
            foreach ($adjuntos as $adjunto) {
                if (file_exists($adjunto)) {
                    $mail->addAttachment($adjunto);
                }
            }
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            $this->errores[] = "Error al enviar correo: {$mail->ErrorInfo}";
            return false;
        }
    }
    
    /**
     * Elimina los archivos XML del directorio
     * 
     * @param array $archivos Array de rutas de archivos a eliminar
     * @return int Número de archivos eliminados
     */
    private function eliminarArchivosXML($archivos) {
        $eliminados = 0;
        
        foreach ($archivos as $archivo) {
            if (file_exists($archivo) && is_file($archivo)) {
                if (unlink($archivo)) {
                    $eliminados++;
                } else {
                    $this->errores[] = "No se pudo eliminar el archivo: " . basename($archivo);
                }
            }
        }
        
        return $eliminados;
    }
    
    /**
     * Obtiene los errores registrados
     * 
     * @return array Array de mensajes de error
     */
    public function getErrores() {
        return $this->errores;
    }
    
    /**
     * Obtiene estadísticas del último proceso
     * 
     * @return array Array con estadísticas
     */
    public function getEstadisticas() {
        $archivosRestantes = glob($this->outputDir . '/*.xml');
        
        return [
            'directorio' => $this->outputDir,
            'archivos_restantes' => count($archivosRestantes),
            'limpieza_automatica' => $this->eliminarDespuesDeEnviar,
            'errores_count' => count($this->errores)
        ];
    }
    
    /**
     * Función principal: genera XMLs y los envía por correo
     * 
     * @param string $hora Hora de aplicación en formato HH:MM (ej: '14:00')
     * @param bool|null $eliminarArchivos Sobrescribe configuración de constructor
     * @return bool True si todo fue exitoso
     */
    public function generarYEnviarXML($hora = '14:00', $eliminarArchivos = null) {
        try {
            
            // Si se pasa parámetro, sobrescribir configuración
            if ($eliminarArchivos !== null) {
                $this->eliminarDespuesDeEnviar = $eliminarArchivos;
            }
            
            // Validar formato de hora
            if (!preg_match('/^\d{2}:\d{2}$/', $hora)) {
                throw new Exception("Formato de hora inválido. Use HH:MM (ej: 14:00)");
            }
            
            // Obtener datos
            $datosAgrupados = $this->obtenerPreciosAgrupadosPorRFC();
            
            if (empty($datosAgrupados)) {
                throw new Exception("No se obtuvieron datos para generar XML");
            }
            
            
            // Generar XMLs
            $this->generarXMLsPorRFC($datosAgrupados, $hora);
            
            // Obtener archivos XML generados
            $archivosXML = glob($this->outputDir . '/*.xml');
            
            if (empty($archivosXML)) {
                throw new Exception("No se generaron archivos XML");
            }
            
            $totalArchivos = count($archivosXML);
            
            // Enviar correo
            
            $asunto = "Archivos XML Generados - Precios CRE";
            $cuerpo = "Adjunto encontrarás los archivos XML generados con hora de aplicación: $hora\n\n";
            $cuerpo .= "Fecha de generación: " . date('d/m/Y H:i:s') . "\n";
            $cuerpo .= "Total de archivos: $totalArchivos\n\n";
            
            if (!empty($this->errores)) {
                $cuerpo .= "Incidentes durante la ejecución:\n";
                foreach ($this->errores as $error) {
                    $cuerpo .= "- $error\n";
                }
            } else {
                $cuerpo .= "No se registraron incidentes durante la ejecución.\n";
            }
            
            $correoEnviado = $this->enviarCorreoConAdjuntos($asunto, $cuerpo, $archivosXML);
            
            // Eliminar archivos si está configurado y el correo se envió correctamente
            if ($this->eliminarDespuesDeEnviar && $correoEnviado) {
                $archivosEliminados = $this->eliminarArchivosXML($archivosXML);
            }

            return true;
            
        } catch (Exception $e) {
            $this->errores[] = $e->getMessage();
            return false;
        }
    }
}
?>