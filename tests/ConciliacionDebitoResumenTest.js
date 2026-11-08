// Prueba de humo del Resumen de Conciliación débito (sin navegador):  node tests/ConciliacionDebitoResumenTest.js
// Carga _assets/js/conciliation_debit.js con un jQuery de mentira, llena window.ConcDebitoResumen.estado y revisa
// que pintarResumen() no lance con todo vacío y que las cifras de los tres cuadros cuadren.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const Conc = require('../_assets/js/conciliation_common.js');

const html = {};
function crearProxy(sel) {
  const p = new Proxy(function () {}, {
    get(t, k) {
      if (k === 'html' || k === 'text') return (v) => { if (v !== undefined) html[sel] = v; return p; };
      if (k === 'empty') return () => { html[sel] = ''; return p; };
      if (k === Symbol.toPrimitive) return () => '';
      return p;
    },
    set() { return true; },
    apply() { return p; },
  });
  return p;
}
const $ = function (arg) {
  if (typeof arg === 'function') { arg(); return undefined; }
  return crearProxy(typeof arg === 'string' ? arg : '');
};
$.extend = Object.assign;
$.fn = { dataTable: { tables: () => crearProxy(''), util: { escapeRegex: (s) => s } } };

global.$ = $;
global.Conc = Conc;
Conc.initTabla = () => crearProxy('');
Conc.pintarChips = () => {};
Conc.bindAyuda = () => {};
global.window = {};
global.routeUrl = () => '/x';
global.alertify = { error() {} };
global.document = { querySelector: () => null };

vm.runInThisContext(fs.readFileSync(path.join(__dirname, '../_assets/js/conciliation_debit.js'), 'utf8'));
const R = global.window.ConcDebitoResumen;
assert(R && typeof R.pintarResumen === 'function', 'el JS expone pintarResumen');

function ok(msg) { console.log('ok   ' + msg); }

// ---- 1. Todo vacío (mes o razón social sin datos): no lanza, ceros, Conciliado, 0 % cuadrado
['venta', 'facturas', 'anticipos', 'cobrosFactura', 'cobros', 'banco2791', 'banco0956'].forEach((k) => { R.estado[k] = []; });
R.estado.partidasAnticipos = null;
R.estado.bancoListo = { banco2791: true };
assert.doesNotThrow(() => R.pintarResumen());
assert(html['#resEstatusVenta'].includes('Conciliado'), 'venta vacía: Conciliado');
assert(html['#resEstatusAnticipos'].includes('Conciliado'), 'anticipos vacíos: Conciliado');
assert(html['#resEstatusCobros'].includes('0.0% cuadrado'), 'sin cobros: 0.0 % cuadrado');
assert(!/NaN|Infinity/.test(html['#resVenta'] + html['#resAnticipos'] + html['#resCobros'] + html['#resDepositos']), 'sin NaN ni Infinity');
assert(html['#resAnticipos'].includes('Anticipos cancelados del mes: 0'), 'nota de cancelados en cero');
ok('estado vacío: pintarResumen no lanza y deja ceros, Conciliado y 0.0 % cuadrado');

// ---- 2. Con datos
R.estado.venta = [
  { clase: 'Venta', monto: 1000, facturado_mes: 1000, facturado_mes_anterior: 50, dif_cortes: 0, diferencia: 0, estatus: 'Facturado' },
  { clase: 'Venta', monto: 500, facturado_mes: 400, facturado_mes_anterior: 0, dif_cortes: 10, diferencia: 100, estatus: 'Factura cancelada' },
  { clase: 'Venta', monto: 300, facturado_mes: 0, facturado_mes_anterior: 0, dif_cortes: 0, diferencia: 300, estatus: 'Consumo sin factura' },
  { clase: 'Consumo propio', monto: 200, facturado_mes: 0, facturado_mes_anterior: 0, dif_cortes: 0, diferencia: 200, estatus: 'No se factura' },
  { clase: 'Bonificación', monto: 100, facturado_mes: 0, facturado_mes_anterior: 0, dif_cortes: 0, diferencia: 100, estatus: 'No se factura' },
];
const a = R.calcVenta(R.estado.venta);
assert.strictEqual(a.venta, 2100); assert.strictEqual(a.neta, 1800);
assert.strictEqual(a.vigente, 1450); assert.strictEqual(a.fAnt, 50); assert.strictEqual(a.fMes, 1400);
assert.strictEqual(a.diferencia, 400);
assert.deepStrictEqual(a.partidas.map((p) => p.estatus), ['Factura cancelada', 'Consumo sin factura']);
assert.strictEqual(a.sinExplicar, 0);
ok('card A: neta 1,800, facturado del mes 1,400, diferencia 400 = partidas 400, sin explicar 0');

R.estado.anticipos = [{ estatus: 'Cancelada' }, { estatus: 'Con cobro' }, { estatus: 'Cancelada' }];
R.estado.partidasAnticipos = { cobros: 100, anticipos: 90, diferencia: 10, otro_mes: { n: 1, importe: 15 }, sin_factura: { n: 0, importe: 0 }, anticipos_sin_cobro: { n: 1, importe: -5 }, canceladas: 2, sin_explicar: 0 };
const b = R.calcAnticipos(R.estado.partidasAnticipos, R.estado.anticipos);
assert.strictEqual(b.canceladas, 2); assert.strictEqual(b.otroMes.importe, 15);
ok('card B: usa las partidas del servidor y cuenta 2 cancelados');

R.estado.cobros = [
  { estatus: 'Cuadra', monto: 700 }, { estatus: 'No localizado', monto: 300 }, { estatus: 'Cancelado', monto: 999 },
];
const c = R.calcCobros(R.estado.cobros);
assert.strictEqual(c.total, 1000); assert.strictEqual(c.n, 2); assert.strictEqual(c.pct, 70);
assert.strictEqual(c.cancel.n, 1); assert.strictEqual(c.cancel.monto, 999);
ok('card C: Cancelado fuera del total y del % cuadrado (70.0 %)');

const d = R.calcDepositos([
  { abono: 100, cargo: 0, clasificacion: 'Cobro prepago' }, { abono: 50, cargo: 0, clasificacion: 'Cobro crédito' },
  { abono: 20, cargo: 0, clasificacion: 'Traspaso entre cuentas' }, { abono: 10, cargo: 0, clasificacion: 'No identificado' },
  { abono: 0, cargo: 80, clasificacion: 'Retiro' },
]);
assert.strictEqual(d.total, 180); assert.strictEqual(d.noIdentificados, 10);
ok('depósitos 2791: total 180 − 100 − 50 − 20 = 10 no identificados (solo abonos)');

assert.doesNotThrow(() => R.pintarResumen());
R.estado.bancoListo = {};
R.pintarResumen();
assert(html['#resDepositos'].includes('Cargando'), 'sin banco listo: Cargando…');
ok('sin el banco 2791 cargado, resDepositos dice Cargando…');
console.log('ConciliacionDebitoResumenTest: OK');
