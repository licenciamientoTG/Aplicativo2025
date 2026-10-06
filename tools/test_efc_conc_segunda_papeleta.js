// Pruebas sin red/DOM de las filas operativas usadas por la consola triple.
// Ejecutar: node tools/test_efc_conc_segunda_papeleta.js
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../views/income/cash_reconciliation_triple.html'), 'utf8');
const start = source.indexOf('function makeRows(');
const end = source.indexOf('function visible()', start);
assert.ok(start >= 0 && end > start, 'Debe existir la función operativa makeRows');
const context = vm.createContext({
  num: value => Number(value || 0),
  date: value => String(value || '').slice(0, 10),
  key: (day, turn, concept) => `${day}|${String(turn).match(/\d+/)?.[0] || turn}|${concept}`,
  sum: (items, field) => items.reduce((total, item) => total + Number(item[field] || 0), 0),
  isParralStation: () => false,
  MATCH_TOLERANCE: 1,
});
vm.runInContext(source.slice(start, end), context);
const makeRows = context.makeRows;
const day = '2026-09-20';
const raw = [{Fecha: day, Turno: '1', MN: 100}];
const work = {
  papers: [{id: 10, real_mn: 40, declared_mn: 41, remittance: 'R1'},
           {id: 11, real_mn: 60, declared_mn: 59, remittance: 'R2'}],
  links: [{id: 7, fecha_cg: day, turno: '1', concepto: 'MN', papeleta_id: 10, papeleta_secundaria_id: 11}],
};
const sourceKey = `cg-2-${day}-1-MN`;
const banks = [{id: 'mb_1', amount: 40}, {id: 'mb_2', amount: 60}];
const group = bank => [{id: 1, cg: [{id: sourceKey}], bank}];
let rows = makeRows(raw, work, {}, [], 2);
assert.equal(rows.length, 1, 'Dos papeletas conservan un turno CG');
assert.equal(rows[0].cg, 100);
assert.equal(rows[0].papers.length, 2);
assert.equal(rows[0].regio, 100);
assert.equal(rows[0].declared, 100);
assert.equal(rows[0].rd, 0);
assert.equal(rows[0].status, 'PENDIENTE BANCO');
assert.equal(makeRows(raw, work, {}, group([banks[0]]), 2)[0].status, 'PENDIENTE BANCO', 'Un depósito no completa el par');
rows = makeRows(raw, work, {}, group(banks), 2);
assert.equal(rows[0].status, 'CONCILIADA COMPLETA');
assert.equal(rows[0].bank, 100);
assert.equal(rows[0].bd, 0);
const missingPaper = {...work, papers: [work.papers[0]]};
assert.equal(makeRows(raw, missingPaper, {}, group(banks), 2)[0].status, 'SIN PAPELETA');

const usdRaw = [{Fecha: day, Turno: '1', Dolares: 100}];
const usdWork = {
  papers: [{id: 10, real_usd: 2, declared_usd: 2}, {id: 11, real_usd: 3, declared_usd: 3}],
  links: [{...work.links[0], concepto: 'USD', exchange_rate: 20}],
};
rows = makeRows(usdRaw, usdWork, {}, [], 2);
assert.equal(rows[0].regio, 100);
assert.equal(rows[0].declared, 100);
assert.equal(rows[0].regioOnly, false, 'El par USD requiere banco');
assert.equal(rows[0].status, 'PENDIENTE BANCO');
const usdSingle = {...usdWork, links: [{...usdWork.links[0], papeleta_secundaria_id: null}]};
assert.equal(makeRows(usdRaw, usdSingle, {}, [], 2)[0].regioOnly, true, 'USD simple conserva su regla');

const transit = {id: 8, date: day, turn: '1', currency: 'MN', amount: 100, source_key: sourceKey, status: 'PENDIENTE'};
rows = makeRows([], work, {}, [], 2, {incoming: [transit]});
assert.equal(rows.length, 1);
assert.equal(rows[0].id, 'TR:8');
assert.equal(rows[0].regio, 100);
assert.equal(rows[0].status, 'PENDIENTE BANCO');
rows = makeRows([], work, {}, [{id: 1, cg: [{id: 'TR:8'}], bank: banks}], 2, {incoming: [transit]});
assert.equal(rows[0].status, 'CONCILIADA COMPLETA');
assert.equal(rows[0].bank, 100);
assert.equal(makeRows(raw, work, {}, [], 2, {origin: [transit]}).length, 0, 'El mes origen no duplica un tránsito');
assert.equal(makeRows(raw, work, {}, [], 23, {}, 'GASOMEX')[0].status, 'PENDIENTE BANCO');
console.log('PASS: papeletas dobles, cardinalidad bancaria, USD simple/doble, tránsito y conteo CG.');
