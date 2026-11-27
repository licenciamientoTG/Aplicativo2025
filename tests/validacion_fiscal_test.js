// Reglas de RFC y correos (sin framework): node tests/validacion_fiscal_test.js
// Usa los mismos casos que tests/ValidacionFiscalTest.php.
const assert = require('node:assert/strict');
const VF = require('../_assets/js/validacion_fiscal.js');
const casos = require('./fixtures/validacion_fiscal_casos.json');

for (const c of casos.rfc) {
    const r = VF.rfc(c.valor);
    const nombre = 'rfc ' + JSON.stringify(c.valor);
    assert.equal(r.nivel, c.nivel, `${nombre}: ${r.mensaje}`);
    if (c.contiene) assert.ok(r.mensaje.includes(c.contiene), `${nombre} debe mencionar ${c.contiene}: ${r.mensaje}`);
}

for (const c of casos.correos) {
    const r = VF.correos(c.valor);
    const nombre = 'correos ' + JSON.stringify(c.valor);
    assert.equal(r.nivel, c.nivel, `${nombre}: ${r.mensaje}`);
    if ('normalizado' in c) assert.equal(r.normalizado, c.normalizado, nombre);
    if (c.contiene) assert.ok(r.mensaje.includes(c.contiene), `${nombre} debe mencionar ${c.contiene}: ${r.mensaje}`);
}

for (const c of casos.regimen) {
    const r = VF.regimen(c.valor, c.rfc);
    const nombre = 'regimen ' + JSON.stringify(c.valor) + ' con RFC ' + JSON.stringify(c.rfc);
    assert.equal(r.nivel, c.nivel, `${nombre}: ${r.mensaje}`);
    if (c.contiene) assert.ok(r.mensaje.includes(c.contiene), `${nombre} debe mencionar ${c.contiene}: ${r.mensaje}`);
}
assert.equal(Object.keys(VF.REGIMENES).length, 19);

const largo =Array.from({ length: 10 }, (_, i) => `correo${i + 1}@empresa.com.mx`).join(';');
assert.equal(VF.correos(largo).nivel, 'error');

console.log('validacion_fiscal_test: OK');
