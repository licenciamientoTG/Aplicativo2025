<?php
$server = "192.168.0.6"; $db = "TG"; $user = "cguser"; $pass = "sahei1712"; 
try {
    $conn = new PDO("sqlsrv:Server=$server;Database=$db", $user, $pass);
    
    echo "--- CHEQUEO DUPLICADOS EN banco_amex ---
";
    $sql = "SELECT Afiliacion, Fecha_Transaccion, Monto, Terminal, ID_Externo, COUNT(*) as Total 
            FROM banco_amex 
            GROUP BY Afiliacion, Fecha_Transaccion, Monto, Terminal, ID_Externo 
            HAVING COUNT(*) > 1";
    $stmt = $conn->query($sql);
    $found = false;
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Duplicado: Afil '{$row['Afiliacion']}' | Fecha '{$row['Fecha_Transaccion']}' | Monto '{$row['Monto']}' | ID_Ext '{$row['ID_Externo']}' | Repetido: {$row['Total']}
";
        $found = true;
    }
    if (!$found) echo "No se encontraron duplicados exactos.
";

} catch (Exception $e) { echo $e->getMessage(); }
