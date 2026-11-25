<?php
declare(strict_types=1);

/**
 * Pruebas de NotaCfdi (lógica pura de notas de crédito/cargo desde CFDI):
 *   php tests/NotaCfdiTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/NotaCfdi.class.php';

check('E -> NOTA_CREDITO', NotaCfdi::clasificar('E', ['Bonificación'], ['']) === 'NOTA_CREDITO');
check('Servicio Asociado con clave -> FALSO_FLETE', NotaCfdi::clasificar('I', ['Servicio Asociado'], ['80141629']) === 'FALSO_FLETE');
check('duplicados con salto de línea y sin clave -> FALSO_FLETE',
    NotaCfdi::clasificar('I', ["Servicio\nasociado", 'Servicio asociado'], ['', '80141629']) === 'FALSO_FLETE');
check('combustible + servicio asociado -> null',
    NotaCfdi::clasificar('I', ['PEMEX MAGNA', 'Servicio asociado'], ['15101514', '80141629']) === null);
check('moratorios',
    NotaCfdi::clasificar('I', ['Intereses Moratorios 2705035811 04-Agosto- 2026'], ['']) === 'MORATORIOS');
check('anticipo -> null', NotaCfdi::clasificar('I', ['Anticipo saldo cliente'], ['']) === null);
check('sin conceptos -> null', NotaCfdi::clasificar('I', [], []) === null);
check('tipo P -> null', NotaCfdi::clasificar('P', ['Pago'], ['']) === null);
check('moratorios + combustible -> null',
    NotaCfdi::clasificar('I', ['PEMEX DIESEL', 'Intereses moratorios'], ['15101515', '']) === null);
check('clave faltante tratada como vacía',
    NotaCfdi::clasificar('I', ['Servicio asociado', 'Servicio asociado'], ['80141629']) === 'FALSO_FLETE');

check('numeroNota serie+folio', NotaCfdi::numeroNota('SE', '034398') === 'SE034398');
check('numeroNota serie null', NotaCfdi::numeroNota(null, '034398') === '034398');
check('numeroNota con espacios', NotaCfdi::numeroNota(' MR ', ' 003109 ') === 'MR003109');

check('carpeta mcg', NotaCfdi::carpetaPorRfc('MME141110IJ9') === 'mcg');
check('carpeta notas', NotaCfdi::carpetaPorRfc('XAXX010101000') === 'notas');

check('noteType DEBIT', NotaCfdi::noteType('FALSO_FLETE') === 'DEBIT');
check('noteType CREDIT', NotaCfdi::noteType('NOTA_CREDITO') === 'CREDIT');
check('descripcion', NotaCfdi::descripcion('NOTA_CREDITO') === 'NOTA DE CRÉDITO'
    && NotaCfdi::descripcion('FALSO_FLETE') === 'FALSO FLETE'
    && NotaCfdi::descripcion('MORATORIOS') === 'INTERESES MORATORIOS');

finish();
