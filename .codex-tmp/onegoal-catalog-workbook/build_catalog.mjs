import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, Workbook, SpreadsheetFile } from "@oai/artifact-tool";

const root = path.resolve("..");
const dataDir = path.join(root, "onegoal-catalog-data");
const inputFile = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/outputs/onegoal_catalogo_con_uso_oc_2025_2026-07-22/catalogo_productos_con_uso_oc_desde_2025.xlsx";
const outDir = path.resolve("..", "..", "outputs", "onegoal_catalogo_con_uso_oc_validado_2026-07-22");
const readJson = async (file) => JSON.parse((await fs.readFile(file, "utf8")).replace(/^\uFEFF/, ""));
const source = await readJson(path.join(dataDir, "productos_activos_origen.json"));
const usage = await readJson(path.join(dataDir, "uso_productos_oc_desde_2025.json"));
const userWorkbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputFile));
const approvalRows = userWorkbook.worksheets.getItem("Pendientes de validar").getUsedRange().values.slice(4);

const text = (value) => String(value ?? "").trim();
const norm = (value) => text(value).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase().replace(/[^A-Z0-9]+/g, " ").replace(/\s+/g, " ").trim();
const productKey = (row) => norm(row.Clave) || norm(row.Codigo);
const sorted = (values) => [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b, "es"));
const latest = (rows) => [...rows].sort((a, b) => {
  const delta = new Date(b.FechaUltimaModificacion ?? 0) - new Date(a.FechaUltimaModificacion ?? 0);
  if (delta) return delta;
  return Number(b.EmpresaBase === "1G_TOTALGAS") - Number(a.EmpresaBase === "1G_TOTALGAS");
})[0];
const join = (rows, field, mapper = text) => sorted(rows.map((r) => mapper(r[field]))).join(", ");
const usageKey = (row) => `${row.EmpresaBase}\u001F${row.IdProducto}`;
const usageMap = new Map(usage.map((row) => [usageKey(row), row]));
const approvedSameKey = new Set();
const approvedSameName = [];
for (const row of approvalRows) {
  if (Number(row[5]) !== 1) continue;
  const involved = text(row[0]);
  if (text(row[1]) === "Misma clave, nombres distintos") approvedSameKey.add(norm(involved));
  if (text(row[1]) === "Mismo nombre, atributos distintos") approvedSameName.push(involved.split(",").map(norm));
}

// Paso 1: una clave reutilizada para nombres distintos se separa.
const base = new Map();
for (const row of source) {
  const name = norm(row.Descripcion);
  const id = `${productKey(row)}\u001F${name}`;
  if (!base.has(id)) base.set(id, { key: productKey(row), name, rows: [] });
  base.get(id).rows.push(row);
}
const baseGroups = [...base.values()];

// Paso 2: solo se unen claves distintas cuando el nombre y atributos físicos coinciden.
const safe = new Map();
for (const group of baseGroups) {
  const row = latest(group.rows);
  const signature = [group.name, norm(row.UDMInventario), norm(row.Presentacion), norm(row.Marca)].join("\u001F");
  if (!safe.has(signature)) safe.set(signature, []);
  safe.get(signature).push(group);
}
const parent = baseGroups.map((_, index) => index);
const find = (index) => parent[index] === index ? index : (parent[index] = find(parent[index]));
const unite = (left, right) => { const a = find(left), b = find(right); if (a !== b) parent[b] = a; };
for (const groups of safe.values()) {
  const indices = groups.map((group) => baseGroups.indexOf(group));
  for (let i = 1; i < indices.length; i += 1) unite(indices[0], indices[i]);
}
for (const key of approvedSameKey) {
  const indices = baseGroups.map((group, index) => group.key === key ? index : -1).filter((index) => index >= 0);
  for (let i = 1; i < indices.length; i += 1) unite(indices[0], indices[i]);
}
for (const keys of approvedSameName) {
  const indices = baseGroups.map((group, index) => keys.includes(group.key) ? index : -1).filter((index) => index >= 0);
  for (let i = 1; i < indices.length; i += 1) unite(indices[0], indices[i]);
}
const mergedGroups = new Map();
for (let index = 0; index < baseGroups.length; index += 1) {
  const rootIndex = find(index);
  if (!mergedGroups.has(rootIndex)) mergedGroups.set(rootIndex, []);
  mergedGroups.get(rootIndex).push(baseGroups[index]);
}

let catalog = [];
for (const groups of mergedGroups.values()) {
  const rows = groups.flatMap((g) => g.rows);
  const canonical = latest(rows);
  const uses = rows.map((row) => usageMap.get(usageKey(row))).filter(Boolean);
  catalog.push({
    clave: text(canonical.Clave), codigo: text(canonical.Codigo), descripcion: text(canonical.Descripcion), presentacion: text(canonical.Presentacion), marca: text(canonical.Marca),
    udmInv: text(canonical.UDMInventario), udmCom: text(canonical.UDMCompra), udmVta: text(canonical.UDMVenta), precio: Number(canonical.Precio ?? 0), precioPub: Number(canonical.PrecioPublico ?? 0), costo: Number(canonical.CostoPromedio ?? 0),
    ventas: Number(canonical.VentaHabilitada ?? 0), compras: Number(canonical.CompraHabilitada ?? 0), alta: canonical.FechaAlta ? new Date(canonical.FechaAlta) : null, mod: canonical.FechaUltimaModificacion ? new Date(canonical.FechaUltimaModificacion) : null,
    clavesOrigen: sorted(groups.map((g) => g.key)).join(", "), empresas: join(rows, "EmpresaBase"), empresasCount: sorted(rows.map((r) => r.EmpresaBase)).length, registros: rows.length,
    regla: groups.some((g) => approvedSameKey.has(g.key)) || approvedSameName.some((keys) => keys.every((key) => groups.some((g) => g.key === key))) ? "Equivalencia confirmada por usuario" : (sorted(groups.map((g) => g.key)).length > 1 ? "Nombre + unidad + presentación + marca" : "Clave + nombre"),
    ultimoUso: uses.length ? new Date(Math.max(...uses.map((use) => new Date(use.UltimoUso).getTime()))) : null,
    empresasUso: sorted(uses.map((use) => use.EmpresaBase)).join(", "),
    lineasOC: uses.reduce((sum, use) => sum + Number(use.LineasOrdenNormal ?? 0), 0),
    lineasDirectas: uses.reduce((sum, use) => sum + Number(use.LineasCompraDirecta ?? 0), 0),
    usadoDesde2025: uses.length > 0,
  });
}
const catalogBeforeUsage = catalog.length;
catalog = catalog.filter((row) => row.usadoDesde2025);
catalog.sort((a, b) => a.clave.localeCompare(b.clave, "es") || a.descripcion.localeCompare(b.descripcion, "es"));

const nameConflicts = [];
for (const group of baseGroups) {
  const sameKey = baseGroups.filter((g) => g.key === group.key);
  if (sameKey.length > 1 && !nameConflicts.some((x) => x[0] === group.key)) {
    const allRows = sameKey.flatMap((g) => g.rows);
    nameConflicts.push([group.key, "Misma clave, nombres distintos", sorted(sameKey.map((g) => join(g.rows, "Descripcion"))).join(" | "), sorted(allRows.map((r) => text(r.UDMInventario))).join(" | "), join(allRows, "EmpresaBase")]);
  }
}
const attributeConflicts = [];
const byName = new Map();
for (const group of baseGroups) { if (!byName.has(group.name)) byName.set(group.name, []); byName.get(group.name).push(group); }
for (const [name, groups] of byName) {
  const keys = sorted(groups.map((g) => g.key));
  const signatures = new Set(groups.map((g) => { const r = latest(g.rows); return `${norm(r.UDMInventario)}|${norm(r.Presentacion)}|${norm(r.Marca)}`; }));
  if (keys.length > 1 && signatures.size > 1) {
    const allRows = groups.flatMap((g) => g.rows);
    attributeConflicts.push([keys.join(", "), "Mismo nombre, atributos distintos", sorted(allRows.map((r) => text(r.Descripcion))).join(" | "), sorted(allRows.map((r) => text(r.UDMInventario))).join(" | "), join(allRows, "EmpresaBase")]);
  }
}
const pending = [...nameConflicts, ...attributeConflicts].sort((a, b) => a[0].localeCompare(b[0], "es"));
const pendingOpen = pending.filter((row) => {
  if (row[1] === "Misma clave, nombres distintos") return !approvedSameKey.has(norm(row[0]));
  if (row[1] === "Mismo nombre, atributos distintos") {
    const keys = row[0].split(",").map(norm);
    return !approvedSameName.some((approved) => approved.length === keys.length && approved.every((key) => keys.includes(key)));
  }
  return true;
});
const safeMerged = [...safe.values()].filter((groups) => sorted(groups.map((g) => g.key)).length > 1).length;
const safeReductions = baseGroups.length - catalogBeforeUsage;

const wb = Workbook.create();
const summary = wb.worksheets.add("Resumen");
const products = wb.worksheets.add("Catálogo revisado");
const review = wb.worksheets.add("Pendientes de validar");
const navy = "#17365D", blue = "#1F4E78", light = "#D9EAF7", green = "#E2F0D9", amber = "#FFF2CC", border = "#D9E2F3";
const title = (sheet, range, value) => { sheet.getRange(range).merge(); sheet.getRange(range.split(":")[0]).values = [[value]]; sheet.getRange(range).format = { fill: navy, font: { bold: true, color: "#FFFFFF", size: 15 }, horizontalAlignment: "center" }; };

summary.showGridLines = false; title(summary, "A1:F1", "Catálogo de productos revisado — OneGoal");
summary.getRange("A3:B3").values = [["Indicador", "Resultado"]]; summary.getRange("A3:B3").format = { fill: blue, font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center" };
summary.getRange("A4:B12").values = [["Registros activos de origen", source.length], ["Identidades tras separar clave/nombre", baseGroups.length], ["Grupos fusionados de forma segura", safeMerged], ["Equivalencias confirmadas aplicadas", approvedSameKey.size + approvedSameName.length], ["Productos antes de filtro de uso", catalogBeforeUsage], ["Productos con uso desde 2025", catalog.length], ["Productos descartados sin uso", catalogBeforeUsage - catalog.length], ["Casos pendientes de validar", pendingOpen.length], ["Regla de exclusión", "status = 1"]];
summary.getRange("A3:B12").format.borders = { preset: "all", style: "thin", color: border }; summary.getRange("B4:B11").format = { fill: green, font: { bold: true }, numberFormat: "#,##0" };
summary.getRange("A14:F14").merge(); summary.getRange("A14").values = [["Regla aplicada"]]; summary.getRange("A14:F14").format = { fill: blue, font: { bold: true, color: "#FFFFFF" } };
summary.getRange("A15:F18").merge(); summary.getRange("A15").values = [["Se conservaron únicamente productos con al menos una línea en Orden de compra (normal) o Compra directa entre 2025-01-01 y la fecha de extracción, con documento en estado 1 o 2. No hubo compras directas en el periodo. Además, se aplicó la regla conservadora de identidad: se separan claves con nombres distintos y solo se fusionan claves distintas si coinciden nombre, unidad, presentación y marca."]]; summary.getRange("A15:F18").format = { fill: "#F7FBFF", wrapText: true, verticalAlignment: "top", borders: { preset: "outside", style: "thin", color: border } };
summary.getRange("A:A").format.columnWidth = 42; summary.getRange("B:B").format.columnWidth = 18; summary.getRange("C:F").format.columnWidth = 15;

products.showGridLines = false; title(products, "A1:W1", "Catálogo de productos con uso en OC desde 2025");
products.getRange("A2:W2").merge(); products.getRange("A2").values = [[`${catalog.length.toLocaleString("es-MX")} productos con uso comprobado desde 2025-01-01; se excluyeron desactivados y productos sin uso.`]]; products.getRange("A2:W2").format = { fill: light, font: { italic: true, color: navy } };
const heads = ["Clave", "Código", "Descripción", "Presentación", "Marca", "UDM inv.", "UDM compra", "UDM venta", "Precio", "Precio público", "Costo prom.", "Ventas", "Compras", "Fecha alta", "Última modificación", "Claves origen", "Empresas activas", "# empresas", "Regla aplicada", "Último uso OC", "Empresas con uso", "Líneas OC normal", "Líneas compra directa"];
products.getRange("A4:W4").values = [heads]; products.getRange("A4:W4").format = { fill: blue, font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", wrapText: true };
const data = catalog.map((r) => [r.clave, r.codigo, r.descripcion, r.presentacion, r.marca, r.udmInv, r.udmCom, r.udmVta, r.precio, r.precioPub, r.costo, r.ventas, r.compras, r.alta, r.mod, r.clavesOrigen, r.empresas, r.empresasCount, r.regla, r.ultimoUso, r.empresasUso, r.lineasOC, r.lineasDirectas]);
const last = data.length + 4; products.getRange(`A5:W${last}`).values = data; products.getRange(`I5:K${last}`).format.numberFormat = "$#,##0.00"; products.getRange(`N5:O${last}`).format.numberFormat = "yyyy-mm-dd"; products.getRange(`R5:R${last}`).format.numberFormat = "#,##0"; products.getRange(`T5:T${last}`).format.numberFormat = "yyyy-mm-dd"; products.getRange(`V5:W${last}`).format.numberFormat = "#,##0";
products.tables.add(`A4:W${last}`, true, "CatalogoUsoOC").style = "TableStyleMedium2"; products.freezePanes.freezeRows(4); products.freezePanes.freezeColumns(2);
[15,16,42,18,18,12,12,12,13,14,14,9,9,14,18,28,52,11,34,14,52,16,18].forEach((w, i) => products.getRangeByIndexes(0, i, 1, 1).format.columnWidth = w); products.getRange(`C5:E${last}`).format.wrapText = true; products.getRange(`P5:W${last}`).format.wrapText = true;

review.showGridLines = false; title(review, "A1:E1", "Casos no fusionados automáticamente"); review.getRange("A2:E2").merge(); review.getRange("A2").values = [["Requieren decisión funcional; se conservan separados en el catálogo revisado."]]; review.getRange("A2:E2").format = { fill: amber, font: { italic: true, color: navy } };
review.getRange("A4:E4").values = [["Claves involucradas", "Motivo", "Nombres", "UDM inventario", "Empresas activas"]]; review.getRange("A4:E4").format = { fill: blue, font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", wrapText: true };
const pendLast = pendingOpen.length + 4; review.getRange(`A5:E${pendLast}`).values = pendingOpen; review.tables.add(`A4:E${pendLast}`, true, "PendientesValidar").style = "TableStyleMedium2"; review.freezePanes.freezeRows(4); [28,32,65,24,65].forEach((w, i) => review.getRangeByIndexes(0, i, 1, 1).format.columnWidth = w); review.getRange(`A5:E${pendLast}`).format.wrapText = true;

await fs.mkdir(outDir, { recursive: true });
console.log((await wb.inspect({ kind: "table", range: "Catálogo revisado!A1:W10", include: "values,formulas", tableMaxRows: 10, tableMaxCols: 23 })).ndjson);
console.log((await wb.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 50 }, summary: "formula error scan" })).ndjson);
for (const [sheetName, range, file] of [["Resumen", "A1:F18", "resumen.png"], ["Catálogo revisado", "A1:W11", "catalogo.png"], ["Pendientes de validar", "A1:E12", "pendientes.png"]]) { const image = await wb.render({ sheetName, range, scale: 1, format: "png" }); await fs.writeFile(path.join(outDir, file), new Uint8Array(await image.arrayBuffer())); }
const output = await SpreadsheetFile.exportXlsx(wb); await output.save(path.join(outDir, "catalogo_productos_con_uso_oc_validado.xlsx"));
