<?php
$server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 
try {
    $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
    
    echo "--- CHEQUEO CEROS A LA IZQUIERDA EN banco_amex ---
";
    $sqlData = "SELECT DISTINCT Afiliacion FROM banco_amex WHERE Afiliacion LIKE '0%'";
    $stmtData = $conn->query($sqlData);
    $found = false;
    while($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
        echo "Afil con ceros: '{$row['Afiliacion']}'
";
        $found = true;
    }
    if (!$found) echo "No hay afiliaciones con ceros a la izquierda.
";

    echo "
--- CHEQUEO CONFIGURACION VS DATA (Solis Estacion 27) ---
";
    $sqlSolis = "SELECT * FROM Conciliacion_Configuracion WHERE estacion_id = 27 AND entidad_id = 3";
    $solisConf = $conn->query($sqlSolis)->fetch(PDO::FETCH_ASSOC);
    if ($solisConf) {
        $afilSolis = $solisConf['afiliacion'];
        echo "Config Solis: '$afilSolis'
";
        $sqlDataSolis = "SELECT COUNT(*) FROM banco_amex WHERE LTRIM(RTRIM(Afiliacion)) = '$afilSolis' AND YEAR(Fecha_Transaccion) = 2026 AND MONTH(Fecha_Transaccion) = 2";
        $countSolis = $conn->query($sqlDataSolis)->fetchColumn();
        echo "Total registros Solis Feb 2026: $countSolis
";
    }

} catch (Exception $e) { echo $e->getMessage(); }
