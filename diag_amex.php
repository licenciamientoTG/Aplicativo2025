<?php
$server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 
try {
    $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
    
    echo "--- CONFIG AMEX (entidad_id = 3) ---
";
    $sqlConf = "SELECT * FROM Conciliacion_Configuracion WHERE entidad_id = 3";
    $stmt = $conn->query($sqlConf);
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Estacion: {$row['estacion_id']} | Afil: '{$row['afiliacion']}'
";
    }

    echo "
--- DATA AMEX (TOP 10) ---
";
    $sqlData = "SELECT TOP 10 Afiliacion, Fecha_Transaccion, Monto FROM banco_amex ORDER BY Fecha_Transaccion DESC";
    $stmtData = $conn->query($sqlData);
    while($row = $stmtData->fetch(PDO::FETCH_ASSOC)) {
        echo "Afil: '{$row['Afiliacion']}' | Fecha: {$row['Fecha_Transaccion']} | Monto: {$row['Monto']}
";
    }

    echo "
--- CONTEO POR AFILIACION (Top 10) ---
";
    $sqlCounts = "SELECT Afiliacion, COUNT(*) as Total FROM banco_amex GROUP BY Afiliacion ORDER BY Total DESC";
    $stmtCounts = $conn->query($sqlCounts);
    while($row = $stmtCounts->fetch(PDO::FETCH_ASSOC)) {
        echo "Afil: '{$row['Afiliacion']}' | Total: {$row['Total']}
";
    }

} catch (Exception $e) { echo $e->getMessage(); }
