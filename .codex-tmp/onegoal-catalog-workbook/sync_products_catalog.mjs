import fs from "node:fs/promises";
import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputFile = "C:/Users/daniel.ramirez/Downloads/Catálogo de Productos y Servicios.xlsx";
const validatedFile = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/outputs/onegoal_catalogo_con_uso_oc_validado_2026-07-22/catalogo_productos_con_uso_oc_validado.xlsx";
const outDir = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/outputs/catalogo_productos_servicios_actualizado_2026-07-22";
const outputFile = path.join(outDir, "Catálogo de Productos y Servicios actualizado.xlsx");

const text = (value) => String(value ?? "").trim();
const norm = (value) => text(value).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase().replace(/[^A-Z0-9]+/g, " ").replace(/\s+/g, " ").trim();

const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(inputFile));
const catalogSheet = workbook.worksheets.getItem("Sheet1");
const validated = await SpreadsheetFile.importXlsx(await FileBlob.load(validatedFile));
const validatedSheet = validated.worksheets.getItem("Catálogo revisado");

const inputRange = catalogSheet.getUsedRange();
const inputRows = inputRange.values;
const descriptionRows = inputRows.slice(2).map((row) => [text(row[0]), row[1] ?? null, row[2] ?? null]);
const markerIndex = descriptionRows.findIndex((row) => norm(row[0]) === "ROTULACION DE GAJOS");
if (markerIndex < 0) throw new Error("No se encontró el delimitador 'Rotulación de gajos'.");

const validatedRows = validatedSheet.getUsedRange().values.slice(4);
const targetProducts = [];
const targetSeen = new Set();
for (const row of validatedRows) {
  const description = text(row[2]);
  const key = norm(description);
  if (key && !targetSeen.has(key)) { targetSeen.add(key); targetProducts.push(description); }
}

const beforeMarker = descriptionRows.slice(0, markerIndex);
const protectedRows = descriptionRows.slice(markerIndex);
const protectedKeys = new Set(protectedRows.map((row) => norm(row[0])).filter(Boolean));
const keptKeys = new Set();
const keptRows = [];
let removedRows = 0;
for (const row of beforeMarker) {
  const key = norm(row[0]);
  if (!key) continue;
  if (targetSeen.has(key) && !keptKeys.has(key)) {
    keptRows.push(row);
    keptKeys.add(key);
  } else {
    removedRows += 1;
  }
}
const addedRows = targetProducts.filter((description) => {
  const key = norm(description);
  return !keptKeys.has(key) && !protectedKeys.has(key);
}).map((description) => [description, null, null]);
const finalRows = [...keptRows, ...addedRows, ...protectedRows];

const lastInputRow = inputRows.length;
catalogSheet.getRange(`A3:C${lastInputRow}`).clear({ applyTo: "contents" });
catalogSheet.getRange(`A3:C${finalRows.length + 2}`).values = finalRows;

await fs.mkdir(outDir, { recursive: true });
const check = await workbook.inspect({ kind: "table", range: `Sheet1!A1:C${Math.min(finalRows.length + 2, 25)}`, include: "values,formulas", tableMaxRows: 25, tableMaxCols: 3 });
console.log(check.ndjson);
console.log(JSON.stringify({ targetProducts: targetProducts.length, retainedExisting: keptRows.length, addedRows: addedRows.length, removedRows, protectedRows: protectedRows.length, finalRows: finalRows.length }));
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 50 }, summary: "formula error scan" });
console.log(errors.ndjson);
const preview = await workbook.render({ sheetName: "Sheet1", range: "A1:G45", scale: 1.2, format: "png" });
await fs.writeFile(path.join(outDir, "preview.png"), new Uint8Array(await preview.arrayBuffer()));
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputFile);
