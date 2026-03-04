<?php
$server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 
try {
    $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
    
    echo "--- CONFIGURACION AMEX ---
";
    $sqlConf = "SELECT estacion_id, afiliacion FROM Conciliacion_Configuracion WHERE entidad_id = 3";
    $stmt = $conn->query($sqlConf);
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($configs as $c) {
        echo "Estacion {$c['estacion_id']} -> Afiliacion: {$c['afiliacion']}
";
    }

    echo "
--- CONTEO EN TABLA banco_amex ---
";
    $sqlCount = "SELECT COUNT(*) FROM banco_amex";
    $count = $conn->query($sqlCount)->fetchColumn();
    echo "Total registros en banco_amex: $count
";

    if ($count > 0) {
        echo "
--- ULTIMOS 5 REGISTROS ---
";
        $sqlLast = "SELECT TOP 5 * FROM banco_amex ORDER BY Fecha_Transaccion DESC";
        $stmtLast = $conn->query($sqlLast);
        while($row = $stmtLast->fetch(PDO::FETCH_ASSOC)) {
            print_r($row);
        }
    }
} catch (Exception $e) { echo $e->getMessage(); }
