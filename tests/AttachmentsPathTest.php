<?php
declare(strict_types=1);

/**
 * Pruebas de AttachmentsPath (resolvedor de rutas de facturas, lectura doble NAS + ruta vieja):
 *   php tests/AttachmentsPathTest.php
 * No necesita la NAS: usa carpetas temporales como "legacy" y "nas".
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/common/AttachmentsPath.php';

// ---------- 1) candidatos() sin tocar disco (raíces reales) ----------
AttachmentsPath::setRoots();
$nas = '\\\\192.168.0.15\\FacturasProveedores\\';
$leg = 'C:\\Software\\TareasProgramadas\\Facturas_proveedores\\';

$c = AttachmentsPath::candidatos('combustible\\mcg\\procesadas\\A.pdf');
check('combustible\\ -> NAS primero', $c[0] === $nas . 'combustible\\mcg\\procesadas\\A.pdf');
check('combustible\\ -> respaldo vieja attachments\\', $c[1] === $leg . 'correoFacturas\\attachments\\mcg\\procesadas\\A.pdf');

$c = AttachmentsPath::candidatos('attachments\\tesoro\\procesadas\\B.pdf');
check('attachments\\ relativa -> NAS primero', $c[0] === $nas . 'combustible\\tesoro\\procesadas\\B.pdf');
check('attachments\\ relativa -> vieja después', $c[1] === $leg . 'correoFacturas\\attachments\\tesoro\\procesadas\\B.pdf');

$abs = 'C:\\Software\\TareasProgramadas\\Facturas_proveedores\\correoFacturas\\attachments\\aemsa\\procesadas\\C.pdf';
$c = AttachmentsPath::candidatos($abs);
check('absoluta vieja -> ella misma primero', $c[0] === $abs && count($c) === 2);
check('absoluta vieja -> NAS después', $c[1] === $nas . 'combustible\\aemsa\\procesadas\\C.pdf');

$c = AttachmentsPath::candidatos('c:/software/tareasprogramadas/facturas_proveedores/correoFacturas/attachments/petrotal/procesadas/D.pdf');
check('barras "/" y minúsculas se aceptan', $c[1] === $nas . 'combustible\\petrotal\\procesadas\\D.pdf');

$c = AttachmentsPath::candidatos('\\\\192.168.0.15\\FacturasProveedores\\combustible\\mcg\\E.pdf');
check('UNC se respeta tal cual', $c === ['\\\\192.168.0.15\\FacturasProveedores\\combustible\\mcg\\E.pdf']);

$c = AttachmentsPath::candidatos('//192.168.0.15/FacturasProveedores/combustible/mcg/E.pdf');
check('UNC con "/" se normaliza a "\\\\"', $c[0] === '\\\\192.168.0.15\\FacturasProveedores\\combustible\\mcg\\E.pdf');

$c = AttachmentsPath::candidatos('remisiones\\x.pdf');
check('otra relativa -> bajo correoFacturas viejo', $c === [$leg . 'correoFacturas\\remisiones\\x.pdf']);

check('vacía -> sin candidatas', AttachmentsPath::candidatos('') === [] && AttachmentsPath::candidatos('   ') === []);

check('dentroDeBase NAS', AttachmentsPath::dentroDeBase($nas . 'combustible\\mcg\\a.pdf'));
check('dentroDeBase vieja (sin importar mayúsculas)', AttachmentsPath::dentroDeBase('c:\\software\\tareasprogramadas\\facturas_proveedores\\x.pdf'));
check('fuera de base: Windows', !AttachmentsPath::dentroDeBase('C:\\Windows\\win.ini'));
check('fuera de base: otra NAS', !AttachmentsPath::dentroDeBase('\\\\192.168.0.15\\tableros\\x.pdf'));
check('fuera de base: prefijo parecido', !AttachmentsPath::dentroDeBase('\\\\192.168.0.15\\FacturasProveedoresX\\x.pdf'));

// ---------- 2) resolver() con carpetas temporales ----------
// realpath() del temporal: en Windows el TEMP puede venir en nombre corto 8.3 y realpath() lo expande.
$tmp = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'atp_' . bin2hex(random_bytes(4));
$legacyDir = $tmp . DIRECTORY_SEPARATOR . 'legacy';
$nasDir = $tmp . DIRECTORY_SEPARATOR . 'nas';
mkdir($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas', 0777, true);
mkdir($legacyDir . '\\correoFacturas\\attachments\\tesoro\\procesadas', 0777, true);
mkdir($nasDir . '\\combustible\\mcg\\procesadas', 0777, true);
mkdir($tmp . '\\fuera', 0777, true);
file_put_contents($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\SOLO_VIEJA.pdf', 'vieja');
file_put_contents($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\EN_AMBAS.pdf', 'vieja');
file_put_contents($nasDir . '\\combustible\\mcg\\procesadas\\EN_AMBAS.pdf', 'nas');
file_put_contents($nasDir . '\\combustible\\mcg\\procesadas\\SOLO_NAS.pdf', 'nas');
file_put_contents($legacyDir . '\\correoFacturas\\attachments\\tesoro\\procesadas\\T.pdf', 'vieja');
file_put_contents($tmp . '\\fuera\\secreto.txt', 'no');

AttachmentsPath::setRoots($legacyDir, $nasDir);
$leerDe = fn(?string $p) => $p === null ? null : file_get_contents($p);

check('solo en la vieja (relativa)', $leerDe(AttachmentsPath::resolver('attachments\\mcg\\procesadas\\SOLO_VIEJA.pdf')) === 'vieja');
check('en ambas -> gana la NAS', $leerDe(AttachmentsPath::resolver('attachments\\mcg\\procesadas\\EN_AMBAS.pdf')) === 'nas');
check('solo en NAS con ruta combustible\\', $leerDe(AttachmentsPath::resolver('combustible\\mcg\\procesadas\\SOLO_NAS.pdf')) === 'nas');
check('solo en NAS con ruta relativa vieja', $leerDe(AttachmentsPath::resolver('attachments\\mcg\\procesadas\\SOLO_NAS.pdf')) === 'nas');
check('absoluta vieja de un archivo que sigue en la vieja',
    $leerDe(AttachmentsPath::resolver($legacyDir . '\\correoFacturas\\attachments\\tesoro\\procesadas\\T.pdf')) === 'vieja');
check('no existe -> null', AttachmentsPath::resolver('attachments\\mcg\\procesadas\\NO_EXISTE.pdf') === null);

// Seguridad: salirse de las raíces con ".." debe bloquearse
$ataque = 'attachments\\mcg\\..\\..\\..\\..\\fuera\\secreto.txt';
check('".." fuera de base -> null', AttachmentsPath::resolver($ataque) === null);
check('".." fuera de base -> fueraDeBase() = true (403)', AttachmentsPath::fueraDeBase($ataque) === true);
check('archivo normal -> fueraDeBase() = false', AttachmentsPath::fueraDeBase('attachments\\mcg\\procesadas\\SOLO_VIEJA.pdf') === false);
check('absoluta ajena -> null', AttachmentsPath::resolver($tmp . '\\fuera\\secreto.txt') === null);
check('directorio no es archivo -> null', AttachmentsPath::resolver('attachments\\mcg\\procesadas') === null);

// Nombre con ".." que NO es segmento (adjunto de correo) se sirve
file_put_contents($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\F-123..pdf', 'puntos');
check('"F-123..pdf" (no es segmento) se sirve', $leerDe(AttachmentsPath::resolver('attachments\\mcg\\procesadas\\F-123..pdf')) === 'puntos');

// NAS no disponible: se omite y se sirve la vieja sin error
AttachmentsPath::setRoots($legacyDir, $tmp . '\\nas_no_existe');
check('NAS caída -> se usa la vieja', $leerDe(AttachmentsPath::resolver('attachments\\mcg\\procesadas\\EN_AMBAS.pdf')) === 'vieja');
check('NAS caída -> archivo solo en NAS = null', AttachmentsPath::resolver('combustible\\mcg\\procesadas\\SOLO_NAS.pdf') === null);

// Escritura (fase 4): NAS si responde, vieja si no; en BD la ruta lógica
check('NAS caída -> se escribe en la vieja',
    AttachmentsPath::procesadasDir('mcg') === $legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas');
check('vieja -> rutaParaBd la deja absoluta',
    AttachmentsPath::rutaParaBd($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\X.pdf')
        === $legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\X.pdf');
AttachmentsPath::setRoots($legacyDir, $nasDir);
check('NAS arriba -> PDF a combustible\\<prov>\\procesadas',
    AttachmentsPath::procesadasDir('petrotal') === $nasDir . '\\combustible\\petrotal\\procesadas');
check('NAS arriba -> XML a combustible\\<prov>\\procesadasXml',
    AttachmentsPath::procesadasXmlDir('petrotal') === $nasDir . '\\combustible\\petrotal\\procesadasXml');
$lógica = AttachmentsPath::rutaParaBd($nasDir . '\\combustible\\petrotal\\procesadas\\X.pdf');
check('NAS -> rutaParaBd = combustible\\...', $lógica === 'combustible\\petrotal\\procesadas\\X.pdf');
check('ruta lógica guardada se vuelve a resolver en la NAS',
    AttachmentsPath::candidatos($lógica)[0] === $nasDir . '\\combustible\\petrotal\\procesadas\\X.pdf');
check('rutaParaBd acepta "/" y mayúsculas distintas',
    AttachmentsPath::rutaParaBd(str_replace('\\', '/', strtoupper($nasDir)) . '/combustible/mcg/procesadas/Y.pdf') === 'combustible\\mcg\\procesadas\\Y.pdf');
mkdir($tmp . '\\nas_sin_comb', 0777, true);
AttachmentsPath::setRoots($legacyDir, $tmp . '\\nas_sin_comb');
check('NAS responde pero sin combustible\\ -> se escribe en la vieja',
    AttachmentsPath::procesadasDir('mcg') === $legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas');

// Absoluta vieja: gana la vieja (versión escrita con la NAS caída), si no existe la NAS
AttachmentsPath::setRoots($legacyDir, $nasDir);
check('absoluta vieja existente en ambas -> gana la vieja',
    $leerDe(AttachmentsPath::resolver($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\EN_AMBAS.pdf')) === 'vieja');
check('absoluta vieja que ya solo está en la NAS -> NAS',
    $leerDe(AttachmentsPath::resolver($legacyDir . '\\correoFacturas\\attachments\\mcg\\procesadas\\SOLO_NAS.pdf')) === 'nas');

// UUID -> nombre de archivo
check('nombreDesdeUuid normal', AttachmentsPath::nombreDesdeUuid('ec88babf-4fbb-4588-8465-a5eea90306d3', 'pdf') === 'EC88BABF_4FBB_4588_8465_A5EEA90306D3.pdf');
foreach (['', '..\\..\\x', 'EC88BABF-4FBB-4588-8465-A5EEA90306D3\\..\\a', 'no-es-uuid'] as $malo) {
    $lanzo = false;
    try { AttachmentsPath::nombreDesdeUuid($malo, 'xml'); } catch (Exception $e) { $lanzo = true; }
    check('nombreDesdeUuid rechaza "' . $malo . '"', $lanzo);
}

// limpieza
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
rmdir($tmp);
AttachmentsPath::setRoots();

finish();
