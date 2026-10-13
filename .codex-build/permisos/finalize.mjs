import path from 'node:path';
import { pathToFileURL } from 'node:url';

const SKILL_DIR = 'C:/Users/daniel.ramirez/.codex/plugins/cache/openai-primary-runtime/presentations/26.1007.11041/skills/presentations';
const workspaceDir = 'C:/Users/daniel.ramirez/Documents/codigo/Aplicativo2025';
const candidatePath = path.join(workspaceDir, '.codex-build/permisos/candidate.pptx');
const finalPath = path.join(workspaceDir, 'entregables/Guia_de_permisos_Tablero.pptx');
const { finalizePresentation } = await import(pathToFileURL(path.join(SKILL_DIR, 'container_tools/artifact_tool_utils.mjs')).href);
const result = await finalizePresentation({
  explicitTotalSlideCount: 9,
  workspaceDir,
  candidatePath,
  finalPath,
  pythonExecutable: 'C:/Users/daniel.ramirez/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe',
  integrityValidatorPath: path.join(SKILL_DIR, 'container_tools/inspect_presentation_package_integrity.py'),
  layoutValidatorPath: path.join(SKILL_DIR, 'container_tools/inspect_presentation_layout_geometry.py'),
  layoutArgs: ['--expected-slide-size-emu', '12192000,6858000', '--validate-bullet-geometry', '--validate-heading-fit'],
  requiredNativeTableOwnerSlides: [],
  fontPolicy: { basis: 'design', families: ['Arial'] },
  verifyArtifactToolImport: true,
  receiptPath: path.join(workspaceDir, '.codex-build/permisos/Guia_de_permisos_Tablero.validation.json'),
});
console.log(JSON.stringify(result));
