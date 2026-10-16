// Pruebas de routeUrl():  node tests/route_url_test.js
const assert = require('node:assert/strict');

globalThis.ROUTES = {
    'home': { pattern: '', params: [] },
    'stations.readings': { pattern: 'stations/readings', params: [] },
    'pay': { pattern: 'supply/pay/<int:id>', params: { id: 'int' } },
    'file': { pattern: 'supply/f/<path:p>', params: { p: 'path' } },
    'slug': { pattern: 'a/<slug:s>/<n>', params: { s: 'slug', n: 'str' } },
};
const routeUrl = require('../_assets/js/route_url.js');

assert.equal(routeUrl('home'), '/');
assert.equal(routeUrl('stations.readings'), '/stations/readings');
assert.equal(routeUrl('pay', { id: 15 }), '/supply/pay/15');
assert.equal(routeUrl('pay', { id: '15' }), '/supply/pay/15');
assert.equal(routeUrl('file', { p: 'a/b c.txt' }), '/supply/f/a/b c.txt');
assert.equal(routeUrl('slug', { s: 'x-1', n: 'a b' }), '/a/x-1/a%20b');
assert.throws(() => routeUrl('nope'), /Ruta no definida/);
assert.throws(() => routeUrl('pay'), /Falta el par/);
assert.throws(() => routeUrl('pay', { id: 'abc' }), /Valor inv/);
assert.throws(() => routeUrl('slug', { s: 'con espacio', n: 'x' }), /Valor inv/);
assert.throws(() => routeUrl('slug', { s: 'x', n: 'a/b' }), /Valor inv/);

console.log('Todo bien');
