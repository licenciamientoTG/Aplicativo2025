import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";
import fs from "node:fs/promises";

const inputFile = "C:/Users/daniel.ramirez/Downloads/Catálogo de Productos y Servicios.xlsx";
const finalFile = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/outputs/onegoal_catalogo_con_uso_oc_validado_2026-07-22/catalogo_productos_con_uso_oc_validado.xlsx";
const activeFile = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/.codex-tmp/onegoal-catalog-data/productos_activos_origen.json";
const text = (value) => String(value ?? "").trim();
const norm = (value) => text(value).normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase().replace(/[^A-Z0-9]+/g, " ").replace(/\s+/g, " ").trim();

const input = await SpreadsheetFile.importXlsx(await FileBlob.load(inputFile));
const final = await SpreadsheetFile.importXlsx(await FileBlob.load(finalFile));
const active = JSON.parse((await fs.readFile(activeFile, "utf8")).replace(/^\uFEFF/, ""));
const rows = input.worksheets.getItem("Sheet1").getUsedRange().values.slice(2).map((row) => [text(row[0]), row[1] ?? null, row[2] ?? null]);
const marker = rows.findIndex((row) => norm(row[0]) === "ROTULACION DE GAJOS");
const current = rows.slice(0, marker);
const targetDescriptions = final.worksheets.getItem("Catálogo revisado").getUsedRange().values.slice(4).map((row) => text(row[2])).filter(Boolean);
const target = new Set(targetDescriptions.map(norm));
const activeNames = new Set(active.map((row) => norm(row.Descripcion)).filter(Boolean));
const seen = new Set();
const categories = { duplicate: [], activeNoUse: [], absentOrInactive: [], blank: [] };
const kept = [];
for (const row of current) {
  const key = norm(row[0]);
  if (!key) { categories.blank.push(row[0]); continue; }
  if (target.has(key) && !seen.has(key)) { seen.add(key); kept.push(row[0]); continue; }
  if (target.has(key) && seen.has(key)) { categories.duplicate.push(row[0]); continue; }
  if (activeNames.has(key)) { categories.activeNoUse.push(row[0]); continue; }
  categories.absentOrInactive.push(row[0]);
}
console.log(JSON.stringify({
  productsBeforeMarker: current.length,
  keptMatchingValidatedName: kept.length,
  addedMissingValidatedName: [...target].filter((key) => !seen.has(key)).length,
  protectedFromMarker: rows.length - marker,
  removedDuplicateNames: categories.duplicate.length,
  removedActiveButNotInFinal: categories.activeNoUse.length,
  removedAbsentOrInactive: categories.absentOrInactive.length,
  removedBlank: categories.blank.length,
  finalTargetUniqueNames: target.size,
  examples: {
    duplicate: categories.duplicate.slice(0, 10),
    activeNoUse: categories.activeNoUse.slice(0, 15),
    absentOrInactive: categories.absentOrInactive.slice(0, 15)
  }
}, null, 2));
