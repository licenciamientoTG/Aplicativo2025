// Pruebas de tableRouteUrl():  node tests/table_route_url_test.js
const assert = require('node:assert/strict');

globalThis.ROUTES = {
    'administration.datatables_anticipos': {
        pattern: 'administration/datatables-anticipos/<str:from>/<str:until>',
        params: { from: 'str', until: 'str' },
    },
};
require('../_assets/js/route_url.js');

// $ falso: solo las tablas de "presentes" existen en la página
let presentes = [];
globalThis.$ = (selector) => ({ length: presentes.includes(selector) ? 1 : 0 });

const tableRouteUrl = require('../_assets/js/table_route_url.js');
const name = 'administration.datatables_anticipos';
const fechas = { from: '2026-10-01', until: '2026-10-31' };

presentes = [];
assert.equal(tableRouteUrl('#datatables_anticipos', name, fechas), '', 'tabla ausente => sin URL');

presentes = ['#datatables_anticipos'];
assert.equal(tableRouteUrl('#datatables_anticipos', name, { from: '', until: '2026-10-31' }), '', 'parámetro vacío => sin URL');
assert.equal(tableRouteUrl('#datatables_anticipos', name, { from: undefined, until: '2026-10-31' }), '', 'parámetro ausente => sin URL');
assert.equal(tableRouteUrl('#datatables_anticipos', name, { from: null, until: '2026-10-31' }), '', 'parámetro null => sin URL');
assert.equal(tableRouteUrl('#datatables_anticipos', name, fechas), '/administration/datatables-anticipos/2026-10-01/2026-10-31');
assert.doesNotThrow(() => tableRouteUrl('#datatables_anticipos', name, { from: 'a/b', until: 'x' }), 'valor inválido no lanza');
assert.equal(tableRouteUrl('#datatables_anticipos', name, { from: 'a/b', until: 'x' }), '', 'valor inválido => sin URL');

assert.throws(() => tableRouteUrl('#datatables_anticipos', 'no.existe', fechas), /Ruta no definida/, 'un nombre mal escrito sigue fallando');

console.log('Todo bien');
