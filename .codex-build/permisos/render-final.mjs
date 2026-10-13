import fs from 'node:fs/promises';
import path from 'node:path';
import { FileBlob, PresentationFile } from '@oai/artifact-tool';

const deck = await PresentationFile.importPptx(await FileBlob.load('entregables/Guia_de_permisos_Tablero.pptx'));
for (const [i, slide] of deck.slides.items.entries()) {
  const png = await deck.export({ slide, format: 'png', scale: 1 });
  await fs.writeFile(path.join('.codex-build/permisos', `final-${String(i + 1).padStart(2, '0')}.png`), new Uint8Array(await png.arrayBuffer()));
}
console.log(`Rendered ${deck.slides.items.length} finalized slides.`);
