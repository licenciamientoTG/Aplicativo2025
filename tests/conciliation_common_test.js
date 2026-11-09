// Prueba de las utilidades comunes de conciliación (sin framework): node tests/conciliation_common_test.js
const assert = require('node:assert/strict');
const Conc = require('../_assets/js/conciliation_common.js');

assert.equal(Conc.fmtMonto(1234.5), '1,234.50');
assert.equal(Conc.fmtMonto(null), '0.00');
assert.equal(Conc.fmtLitros(2), '2.000');
assert.equal(Conc.fmtFecha('2026-09-05'), '05/09/2026');
assert.equal(Conc.esc('<a&"x">'), '&lt;a&amp;&quot;x&quot;&gt;');
assert.equal(Conc.suma([{ m: 1 }, { m: 2.5 }, { m: 'x' }], 'm'), 3.5);
assert.equal(Conc.suma([{ m: 1 }, { m: 2.5 }, { m: 'x' }], 'm', r => r.m > 1), 2.5);
assert.equal(Conc.mesTexto('2026-09'), 'septiembre 2026');
assert.equal(Conc.mesTexto(''), '');
assert.ok(Conc.badge('Conciliado', 'display').includes('cv-ok'));
assert.ok(Conc.badge('Algo raro', 'display').includes('cv-muted'));
assert.equal(Conc.monto(null, 'display'), '');

console.log('conciliation_common_test: OK');
