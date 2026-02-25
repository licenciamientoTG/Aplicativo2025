<?php
$path = '_assets/controllers/income.php';
$content = file_get_contents($path);

// Target: reemplazar el bloque de fuzzy match (lineas 2261-2272)
// Cadena exacta incluyendo \r\n (Windows line endings)
$old = "        // 3.1. FUZZY MATCH ROBUSTO (Regex)\r\n" .
    "        \$norm = mb_strtoupper(\$orig, 'UTF-8');\r\n" .
    "        \$norm = str_replace(['\xc3\x83', '\xc3\x83\xe2\x80\xb0', '\xc3\x83', '\xc3\x83\xe2\x80\x9c', '\xc3\x83\xc5\xa1', '\xc3\x83\xc5\x93'], ['A', 'E', 'I', 'O', 'U', 'U'], \$norm);\r\n" .
    "        \r\n" .
    "        if (preg_match('/FECHA/i', \$norm)) {\r\n" .
    "            if (preg_match('/DEPOSITO|APLICACION|DEP.SITO|APLICACI.N/i', \$norm)) {\r\n" .
    "                return 'Fecha_Deposito';\r\n" .
    "            }\r\n" .
    "            if (preg_match('/TRANSACCION|TRANSACCI.N/i', \$norm)) {\r\n" .
    "                return 'Fecha_Transaccion';\r\n" .
    "            }\r\n" .
    "        }";

$new = "        // 3.1. FUZZY MATCH ROBUSTO (Regex) - normalizar acentos\r\n" .
    "        \$norm = mb_strtoupper(\$orig, 'UTF-8');\r\n" .
    "        \$norm = str_replace(\r\n" .
    "            ['\xC3\x80','\xC3\x81','\xC3\x82','\xC3\x83','\xC3\x84','\xC3\x88','\xC3\x89','\xC3\x8A','\xC3\x8B',\r\n" .
    "             '\xC3\x8C','\xC3\x8D','\xC3\x8E','\xC3\x8F','\xC3\x92','\xC3\x93','\xC3\x94','\xC3\x95','\xC3\x96',\r\n" .
    "             '\xC3\x99','\xC3\x9A','\xC3\x9B','\xC3\x9C'],\r\n" .
    "            ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U'],\r\n" .
    "            \$norm\r\n" .
    "        );\r\n" .
    "        \r\n" .
    "        if (preg_match('/FECHA/i', \$norm)) {\r\n" .
    "            if (preg_match('/DEPOSITO|APLICACION/i', \$norm)) {\r\n" .
    "                return 'Fecha_Deposito';\r\n" .
    "            }\r\n" .
    "            if (preg_match('/TRANSACCION/i', \$norm)) {\r\n" .
    "                return 'Fecha_Transaccion';\r\n" .
    "            }\r\n" .
    "        }";

// Buscar el bloque con una expresion regular flexible
$pattern = '/\/\/ 3\.1\. FUZZY MATCH ROBUSTO \(Regex\)\r?\n' .
    '        \$norm = mb_strtoupper\(\$orig, \'UTF-8\'\);\r?\n' .
    '        \$norm = str_replace\(\[.*?\], \[.*?\], \$norm\);\r?\n' .
    '        \r?\n' .
    '        if \(preg_match\(\'\/FECHA\/i\', \$norm\)\) \{\r?\n' .
    '            if \(preg_match\(\'\/DEPOSITO.*?\/i\', \$norm\)\) \{\r?\n' .
    '                return \'Fecha_Deposito\';\r?\n' .
    '            \}\r?\n' .
    '            if \(preg_match\(\'\/TRANSACCION.*?\/i\', \$norm\)\) \{\r?\n' .
    '                return \'Fecha_Transaccion\';\r?\n' .
    '            \}\r?\n' .
    '        \}/s';

$result = preg_replace($pattern, $new, $content, 1, $count);

if ($count > 0) {
    file_put_contents($path, $result);
    echo "SUCCESS: Replaced fuzzy match block ($count occurrences)" . PHP_EOL;
}
else {
    echo "ERROR: Pattern not found. Trying line-based replacement..." . PHP_EOL;
    // Fallback: reemplazar linea por linea
    $lines = explode("\n", str_replace("\r\n", "\n", $content));
    $changed = 0;
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], "// 3.1. FUZZY MATCH ROBUSTO") !== false) {
            // Encontramos la linea 2261, reemplazar hasta linea 2272
            $replacement = "        // 3.1. FUZZY MATCH ROBUSTO (Regex) - normalizar acentos\n" .
                "        \$norm = mb_strtoupper(\$orig, 'UTF-8');\n" .
                "        \$norm = str_replace(\n" .
                "            ['\xC3\x80','\xC3\x81','\xC3\x82','\xC3\x83','\xC3\x84','\xC3\x88','\xC3\x89','\xC3\x8A','\xC3\x8B',\n" .
                "             '\xC3\x8C','\xC3\x8D','\xC3\x8E','\xC3\x8F','\xC3\x92','\xC3\x93','\xC3\x94','\xC3\x95','\xC3\x96',\n" .
                "             '\xC3\x99','\xC3\x9A','\xC3\x9B','\xC3\x9C'],\n" .
                "            ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U'],\n" .
                "            \$norm\n" .
                "        );\n" .
                "        \n" .
                "        if (preg_match('/FECHA/i', \$norm)) {\n" .
                "            if (preg_match('/DEPOSITO|APLICACION/i', \$norm)) {\n" .
                "                return 'Fecha_Deposito';\n" .
                "            }\n" .
                "            if (preg_match('/TRANSACCION/i', \$norm)) {\n" .
                "                return 'Fecha_Transaccion';\n" .
                "            }\n" .
                "        }";
            // Remove lines i through i+11 (12 lines)
            array_splice($lines, $i, 12, explode("\n", $replacement));
            $changed = 1;
            break;
        }
    }
    if ($changed) {
        $newContent = implode("\r\n", $lines);
        file_put_contents($path, $newContent);
        echo "SUCCESS via line replacement" . PHP_EOL;
    }
    else {
        echo "COMPLETE FAILURE: Could not find target" . PHP_EOL;
    }
}

// Verify
$verify = file_get_contents($path);
if (strpos($verify, "normalizar acentos") !== false && strpos($verify, "xC3\\x93") !== false) {
    echo "VERIFICATION: Changes confirmed in file." . PHP_EOL;
}
else {
    echo "VERIFICATION: Something wrong, check manually." . PHP_EOL;
}
?>
