import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const file = "C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025/outputs/catalogo_productos_servicios_actualizado_2026-07-22/Catálogo de Productos y Servicios actualizado.xlsx";
const workbook = await SpreadsheetFile.importXlsx(await FileBlob.load(file));
console.log((await workbook.inspect({ kind: "workbook,sheet,table", maxChars: 6000, tableMaxRows: 8, tableMaxCols: 7, tableMaxCellChars: 100 })).ndjson);
console.log((await workbook.inspect({ kind: "table", range: "Sheet1!A440:C455", include: "values,formulas", tableMaxRows: 16, tableMaxCols: 3 })).ndjson);
