// Prueba de los helpers puros de la conciliación manual (sin framework): node tests/conciliation_manual_test.js
const assert = require('node:assert/strict');
const CM = require('../_assets/js/conciliation_manual.js');

assert.equal(CM.claveItem({ tipo: 'M', movimiento_id: 7 }), 'M|7');
assert.equal(CM.claveItem({ tipo: 'C', codgas: 3, nro: 55 }), 'C|3|55');

const base = { estatus: 'Cuadra', estacion: 'A', banco: 'B', cuenta: '1', abono: 300, diferencia: 0, origen: 'auto', alertas: [] };
const filas = [
  { ...base, grupo_id: 1, cobro: 10, monto: 100, mov: 5, cliente: 'X', fecha: '2026-10-02', facturas: 'I1 ($100.00)', uuid: 'u1' },
  { ...base, grupo_id: 1, cobro: 10, monto: 50, mov: 5, cliente: 'X', fecha: '2026-10-01', facturas: 'I2 ($50.00)', uuid: 'u2', alertas: ['aviso'] },
  { ...base, grupo_id: 1, cobro: 11, monto: 150, mov: 5, cliente: 'Y', fecha: '2026-10-03', origen: 'manual' },
  { ...base, grupo_id: 2, cobro: null, estacion: null, monto: null, estatus: 'Diferencia' },
  { ...base, grupo_id: null, cobro: 12, monto: 20, estatus: 'Sin banco', cuenta: null },
];
const g = CM.agruparCruce(filas);
assert.equal(g.length, 4);
assert.equal(g[0].cobros, 2);                 // dos renglones del mismo cobro 10
assert.equal(g[0].monto, 150);
assert.equal(g[0].cobros_dep, 2);             // cobros 10 y 11 comparten el grupo 1
assert.equal(g[0].fecha, '2026-10-01');
assert.deepEqual(g[0].alertas, ['aviso']);
assert.equal(g[0].facturas, 'I1 ($100.00); I2 ($50.00)');
assert.equal(g[1].origen, 'manual');
assert.equal(g[2].cobros, 0);                 // sin cobro: agrupado por grupo_id
assert.equal(g[2].folios, '');
assert.equal(g[2].monto, 0);
assert.equal(g[3].cobros_dep, 1);             // sin grupo
assert.equal(g[3].candidatos, null);

// Fila sin cobro con facturas (depósito -> facturas)
const g2 = CM.agruparCruce([{ ...base, grupo_id: 9, cobro: null, estacion: null, monto: null, facturas: 'I9 ($10.00)', uuid: 'u9' }]);
assert.equal(g2[0].facturas, 'I9 ($10.00)');
assert.equal(g2[0].uuids, 'u9');
assert.equal(g2[0].cobros, 0);

// Filtros del modal: los de la búsqueda que fija la vista (sin búsqueda, null)
assert.equal(CM.filtrosBusqueda(), null);
let busqueda = { mes: '2026-10', empresa: '1' };
CM.usarFiltros(() => busqueda);
assert.deepEqual(CM.filtrosBusqueda(), { mes: '2026-10', empresa: '1' });
busqueda = { mes: '2026-11', empresa: '0' };
assert.deepEqual(CM.filtrosBusqueda(), { mes: '2026-11', empresa: '0' });

console.log('conciliation_manual_test: OK');
