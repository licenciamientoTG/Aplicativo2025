import fs from 'node:fs/promises';
import path from 'node:path';
import { Presentation, PresentationFile } from '@oai/artifact-tool';

const OUT = path.resolve('.codex-build/permisos');
const FINAL = path.resolve('entregables/Guia_de_permisos_Tablero.pptx');
const ppt = Presentation.create({ slideSize: { width: 1280, height: 720 } });

const C = {
  navy: '#171B35', navy2: '#222744', ink: '#20243D', muted: '#626980',
  pale: '#F4F6FA', white: '#FFFFFF', blue: '#4E74DF', bluePale: '#E8EEFF',
  teal: '#2E9D98', tealPale: '#E4F4F2', gold: '#D5A637', goldPale: '#FBF3DD',
  red: '#B8565C', redPale: '#F9E8E8', line: '#DDE1EA', soft: '#AEB5CB',
};
const FONT = 'Arial';

function shape(slide, geometry, x, y, w, h, fill = 'none', line = 'none', radius = undefined) {
  return slide.shapes.add({
    geometry, position: { left: x, top: y, width: w, height: h }, fill,
    line: { style: 'solid', fill: line, width: line === 'none' ? 0 : 1 },
    ...(radius ? { borderRadius: radius } : {}),
  });
}
function text(slide, str, x, y, w, h, size = 24, color = C.ink, bold = false, align = 'left') {
  const s = shape(slide, 'textbox', x, y, w, h);
  s.text = str;
  s.text.style = { typeface: FONT, fontSize: size, color, bold, alignment: align };
  s.text.insets = 0;
  return s;
}
function base(title, n, dark = false, subtitle = '') {
  const s = ppt.slides.add();
  s.background.fill = dark ? C.navy : C.pale;
  if (n > 1) {
    shape(s, 'rect', 0, 0, 13, 720, dark ? C.teal : C.blue);
    text(s, title, 64, 48, 1150, 58, 39, dark ? C.white : C.ink, true);
    if (subtitle) text(s, subtitle, 66, 111, 1120, 42, 21, dark ? C.soft : C.muted);
    text(s, String(n).padStart(2, '0'), 1192, 666, 34, 22, 14, dark ? C.soft : C.muted, true, 'right');
  }
  return s;
}
function pill(slide, label, x, y, w, fill, color) {
  shape(slide, 'roundRect', x, y, w, 34, fill, 'none', 16);
  text(slide, label, x + 10, y + 7, w - 20, 20, 15, color, true, 'center');
}
function notes(slide, textValue) { slide.speakerNotes.text = textValue; }
function addRow(slide, y, role, detail, tag, accent, roleW = 230) {
  shape(slide, 'rect', 74, y, 1132, 76, C.white, C.line, 10);
  shape(slide, 'rect', 74, y, 8, 76, accent);
  text(slide, role, 105, y + 20, roleW, 34, 24, C.ink, true);
  text(slide, detail, 105 + roleW, y + 17, 650, 44, 20, C.muted);
  text(slide, tag, 950, y + 20, 225, 32, 17, accent, true, 'right');
}

// 1 — Cover
{
  const s = base('', 1, true);
  shape(s, 'rect', 0, 0, 14, 720, C.teal);
  pill(s, 'GUÍA PARA USUARIOS', 78, 86, 226, C.navy2, '#B9C8FF');
  text(s, 'Permisos en\nTableros', 78, 172, 700, 172, 66, C.white, true);
  text(s, 'Quién puede entrar, qué puede hacer\ny cómo elegir el acceso correcto', 82, 376, 730, 92, 29, '#C2C8DC');
  shape(s, 'roundRect', 884, 138, 296, 362, C.navy2, '#414969', 24);
  text(s, 'ESPACIO', 928, 184, 210, 32, 17, '#91A3E7', true, 'center');
  text(s, 'La puerta', 928, 223, 210, 40, 26, C.white, true, 'center');
  shape(s, 'rect', 1018, 281, 3, 54, '#657193');
  text(s, 'TABLERO', 928, 353, 210, 32, 17, '#66C7BF', true, 'center');
  text(s, 'La sala', 928, 392, 210, 40, 26, C.white, true, 'center');
  text(s, 'Dos niveles que trabajan juntos', 79, 620, 600, 32, 20, '#9DA6C2');
  notes(s, 'La guía explica los permisos tal como funcionan hoy en el módulo Tableros. Fuentes revisadas: _assets/classes/TablerosAccess.class.php; _assets/models/TablerosModel.php; _assets/controllers/tableros_api.php.');
}

// 2 — Two scopes
{
  const s = base('Hay dos niveles de acceso', 2, false, 'Piensa en el espacio como un edificio y en cada tablero como una sala.');
  shape(s, 'roundRect', 76, 203, 520, 342, C.bluePale, '#CED8F5', 22);
  pill(s, 'NIVEL 1', 108, 232, 104, '#D8E2FF', C.blue);
  text(s, 'Espacio de trabajo', 108, 286, 420, 48, 31, C.ink, true);
  text(s, 'Define quién puede entrar\ny qué papel tiene en el espacio.', 108, 354, 416, 74, 23, C.muted);
  shape(s, 'roundRect', 280, 454, 274, 64, C.white, '#C7D2F0', 12);
  text(s, 'Tableros y carpetas', 296, 473, 244, 28, 20, C.blue, true, 'center');
  shape(s, 'roundRect', 684, 203, 520, 342, C.tealPale, '#C7E5E2', 22);
  pill(s, 'NIVEL 2', 716, 232, 104, '#D2EFEB', C.teal);
  text(s, 'Cada tablero', 716, 286, 420, 48, 31, C.ink, true);
  text(s, 'Decide si hereda el acceso\ndel espacio o tiene reglas propias.', 716, 354, 432, 74, 23, C.muted);
  shape(s, 'roundRect', 888, 454, 274, 64, C.white, '#B9DED9', 12);
  text(s, 'Personas y acciones', 904, 473, 244, 28, 20, C.teal, true, 'center');
  text(s, 'Tener acceso al espacio no siempre significa tener acceso a todos sus tableros.', 104, 592, 1080, 40, 23, C.ink, true, 'center');
  notes(s, 'El nivel del espacio define membresía y visibilidad del espacio. El nivel del tablero determina su visibilidad y los permisos específicos de sus miembros. Fuentes: _assets/classes/TablerosAccess.class.php y _assets/models/TablerosModel.php.');
}

// 3 — Workspace visibility
{
  const s = base('La puerta del espacio', 3, true, 'La opción Abierto o Cerrado determina quién puede entrar al espacio.');
  shape(s, 'rect', 642, 197, 1, 356, '#414969');
  text(s, 'CERRADO', 90, 219, 440, 36, 18, '#F0C85E', true);
  text(s, 'Entran las personas\na las que se dio acceso.', 90, 277, 490, 104, 33, C.white, true);
  text(s, 'Es ideal cuando el trabajo\ndebe limitarse a un equipo.', 92, 414, 500, 72, 22, C.soft);
  text(s, 'ABIERTO', 704, 219, 440, 36, 18, '#72D0C8', true);
  text(s, 'Puede entrar cualquier persona\ninterna con acceso al módulo.', 704, 277, 486, 104, 31, C.white, true);
  text(s, 'Al entrar por esta vía, su acceso\na tableros heredados es de lectura.', 706, 414, 486, 72, 22, C.soft);
  shape(s, 'roundRect', 116, 558, 1044, 61, C.navy2, '#414969', 12);
  text(s, 'Abierto no significa público en internet: se refiere a usuarios internos autorizados.', 138, 577, 1000, 28, 19, C.white, true, 'center');
  notes(s, 'El acceso a miembros del espacio requiere acceso al módulo. Si un usuario llega a un tablero con visibilidad Espacio mediante un espacio abierto, su rol en ese tablero es Lector. Fuentes: _assets/models/TablerosModel.php (createWorkspace, listWorkspaces); _assets/classes/TablerosAccess.class.php (workspaceRole, boardAccess).');
}

// 4 — Workspace roles
{
  const s = base('Roles dentro del espacio', 4, false, 'Estos roles se asignan a personas dentro de un espacio de trabajo.');
  addRow(s, 190, 'Propietario', 'Administra quién pertenece al espacio y sus roles.', 'GESTIONA MIEMBROS', C.gold);
  addRow(s, 282, 'Editor', 'Colabora en tableros que heredan acceso del espacio.', 'PUEDE EDITAR', C.blue);
  addRow(s, 374, 'Lector', 'Puede consultar tableros que heredan acceso.', 'SOLO CONSULTA', C.teal);
  shape(s, 'roundRect', 77, 500, 1128, 103, C.goldPale, '#EEDDAD', 14);
  text(s, 'Importante', 104, 521, 172, 29, 20, '#98721D', true);
  text(s, 'Ser propietario del espacio no convierte a alguien en propietario de cada tablero.', 270, 517, 880, 62, 24, C.ink, true);
  notes(s, 'Los roles de espacio admitidos son owner, editor y viewer. Solo el propietario del espacio puede administrar miembros del espacio. En tableros con acceso heredado, propietario/editor del espacio reciben rol Editor; viewer recibe Lector. Fuentes: _assets/models/TablerosModel.php (shareWorkspace, updateWorkspaceMember, requireWorkspaceManager, residualBoardAccess); _assets/classes/TablerosAccess.class.php.');
}

// 5 — Board visibility
{
  const s = base('¿Quién puede ver un tablero?', 5, false, 'La visibilidad del tablero decide de dónde puede venir el acceso.');
  const cols = [
    { x: 76, title: 'Privado', accent: C.red, pale: C.redPale, body: 'Solo su creador y las personas invitadas directamente.', example: 'Como una sala con llave' },
    { x: 464, title: 'Espacio', accent: C.blue, pale: C.bluePale, body: 'Miembros del espacio; también quienes entren si el espacio está abierto.', example: 'Como una sala del equipo' },
    { x: 852, title: 'Organización', accent: C.teal, pale: C.tealPale, body: 'Usuarios internos con acceso al módulo Tableros.', example: 'Visible dentro de la empresa' },
  ];
  for (const c of cols) {
    shape(s, 'rect', c.x, 214, 352, 300, C.white, C.line, 16);
    shape(s, 'rect', c.x, 214, 352, 9, c.accent);
    text(s, c.title, c.x + 26, 248, 302, 42, 29, C.ink, true);
    text(s, c.body, c.x + 26, 310, 300, 96, 21, C.muted);
    text(s, c.example, c.x + 26, 445, 300, 33, 17, c.accent, true);
  }
  text(s, 'Cambiar a “Organización” amplía la audiencia: cualquier usuario interno con acceso al módulo puede consultar.', 98, 562, 1084, 52, 22, C.ink, true, 'center');
  notes(s, 'Los valores actuales para un tablero son Privado, Espacio y Organización. Organización requiere que el usuario interno tenga permiso de acceso al módulo; no es acceso anónimo. Fuentes: _assets/models/TablerosModel.php (createBoard, listBoards); _assets/classes/TablerosAccess.class.php (boardAccess). Referencia conceptual: _assets/sql/tableros/README.md.');
}

// 6 — Board roles
{
  const s = base('Roles dentro de un tablero', 6, false, 'Cada rol permite un nivel distinto de trabajo.');
  addRow(s, 174, 'Propietario', 'Control completo del tablero; puede compartir y gestionar accesos.', 'CONTROL TOTAL', C.gold, 250);
  addRow(s, 258, 'Diseñador', 'Puede cambiar la estructura y administrar el acceso.', 'DISEÑA Y COMPARTE', C.blue, 250);
  addRow(s, 342, 'Editor', 'Edita contenido y también grupos o columnas.', 'EDITA Y ORGANIZA', '#6976C8', 250);
  addRow(s, 426, 'Colaborador', 'Crea y actualiza elementos, pero no cambia la estructura.', 'EDITA ELEMENTOS', C.teal, 250);
  addRow(s, 510, 'Lector', 'Consulta el tablero sin modificarlo.', 'SOLO CONSULTA', C.muted, 250);
  text(s, 'Compartir el tablero y cambiar quién tiene acceso queda en manos del Propietario o Diseñador.', 94, 612, 1092, 30, 18, C.muted, true, 'center');
  notes(s, 'La API restringe el cambio de estructura a owner, designer y editor; operaciones sobre elementos permiten owner, designer, editor y contributor; compartir/revocar miembros se limita a owner y designer. El creador siempre recibe owner. Fuentes: _assets/controllers/tableros_api.php (requireBoardRole en create_group, create_column, create_item, update_cell, share_board, revoke_member); _assets/classes/TablerosAccess.class.php.');
}

// 7 — How access is decided
{
  const s = base('Cómo se decide el rol de una persona', 7, true, 'El acceso más específico al tablero determina lo que puede hacer allí.');
  const steps = [
    ['1', '¿Creó el tablero?', 'Sí → Propietario'],
    ['2', '¿Tiene invitación al tablero?', 'Se aplica el rol asignado en esa invitación'],
    ['3', '¿El tablero hereda del espacio?', 'Se aplica el rol del espacio'],
    ['4', '¿Es visible a la organización?', 'Puede entrar como Lector'],
  ];
  let y = 188;
  for (const [num, q, a] of steps) {
    shape(s, 'ellipse', 94, y, 52, 52, num === '1' ? C.gold : C.navy2, num === '1' ? C.gold : '#596486');
    text(s, num, 94, y + 12, 52, 26, 21, num === '1' ? C.navy : C.white, true, 'center');
    text(s, q, 174, y + 1, 455, 30, 23, C.white, true);
    text(s, a, 644, y + 1, 532, 48, 20, '#C0C6D9');
    y += 94;
  }
  shape(s, 'roundRect', 94, 582, 1084, 55, C.navy2, '#414969', 10);
  text(s, 'Una invitación directa al tablero puede dar más o menos acceso que el rol del espacio.', 116, 598, 1040, 28, 19, '#8DD3CC', true, 'center');
  notes(s, 'Orden aproximado de la resolución actual: el creador del tablero se identifica como owner; luego se revisa una membresía directa; después acceso heredado del espacio en tableros visibility=workspace; organización permite viewer; los administradores globales tienen una excepción. En visibilidad Privado, el rol del espacio por sí solo no concede acceso. Fuentes: _assets/classes/TablerosAccess.class.php (boardAccess); _assets/models/TablerosModel.php (residualBoardAccess).');
}

// 8 — Worked examples
{
  const s = base('Tres ejemplos cotidianos', 8, false, 'El rol en el espacio y el rol en el tablero pueden ser distintos.');
  const examples = [
    { y: 202, name: 'Ana', path: 'Es Editora del espacio. El tablero está configurado como “Espacio”.', result: 'Entra como Editora', color: C.blue, fill: C.bluePale },
    { y: 326, name: 'Luis', path: 'Es Lector del espacio, pero lo invitaron directamente como Colaborador.', result: 'Entra como Colaborador', color: C.teal, fill: C.tealPale },
    { y: 450, name: 'Sofía', path: 'El espacio está abierto y el tablero hereda el acceso del espacio.', result: 'Entra como Lector', color: C.gold, fill: C.goldPale },
  ];
  for (const e of examples) {
    shape(s, 'rect', 82, e.y, 1118, 96, C.white, C.line, 12);
    text(s, e.name, 106, e.y + 20, 122, 34, 24, C.ink, true);
    text(s, e.path, 250, e.y + 17, 616, 60, 19, C.muted);
    shape(s, 'roundRect', 888, e.y + 25, 278, 46, e.fill, 'none', 20);
    text(s, e.result, 902, e.y + 37, 250, 25, 18, e.color, true, 'center');
  }
  text(s, 'El acceso directo de Luis prevalece en ese tablero; no cambia su rol general en el espacio.', 101, 584, 1084, 38, 20, C.ink, true, 'center');
  notes(s, 'Ejemplos basados en boardAccess y residualBoardAccess: los miembros de espacio owner/editor heredan Editor; viewer hereda Viewer; acceso por espacio abierto hereda Viewer; membresía directa de tablero se revisa antes que el acceso heredado. Fuentes: _assets/classes/TablerosAccess.class.php; _assets/models/TablerosModel.php.');
}

// 9 — What to check
{
  const s = base('Si alguien no puede hacer algo', 9, true, 'Revisa estas tres cosas antes de pedir ayuda.');
  const checks = [
    ['01', 'Visibilidad', '¿El tablero es Privado, Espacio u Organización?'],
    ['02', 'Acceso', '¿La persona fue invitada al tablero o solo al espacio?'],
    ['03', 'Rol', '¿Necesita consultar, editar contenido o administrar el tablero?'],
  ];
  let y = 196;
  for (const [n, head, body] of checks) {
    text(s, n, 104, y, 70, 36, 21, '#78D1C8', true);
    text(s, head, 188, y, 240, 36, 25, C.white, true);
    text(s, body, 433, y + 2, 700, 42, 21, '#C0C6D9');
    shape(s, 'rect', 105, y + 60, 1071, 1, '#414969');
    y += 105;
  }
  shape(s, 'roundRect', 105, 536, 1070, 78, C.navy2, '#414969', 13);
  text(s, 'Propietario del espacio: administra miembros del espacio.\nPropietario del tablero: controla ese tablero.', 127, 550, 1028, 50, 20, C.white, true, 'center');
  notes(s, 'Los roles owner son independientes: el rol propietario del espacio no concede automáticamente ownership de cada tablero. Administrar miembros del espacio exige workspaceRole=owner; crear/compartir/revocar personas en el tablero tiene restricciones por board role. Fuentes: _assets/models/TablerosModel.php (requireWorkspaceManager); _assets/controllers/tableros_api.php (share_board, revoke_member); _assets/classes/TablerosAccess.class.php.');
}

await fs.mkdir(OUT, { recursive: true });
await fs.mkdir(path.dirname(FINAL), { recursive: true });
for (const [i, slide] of ppt.slides.items.entries()) {
  const png = await ppt.export({ slide, format: 'png', scale: 1 });
  await fs.writeFile(path.join(OUT, `slide-${String(i + 1).padStart(2, '0')}.png`), new Uint8Array(await png.arrayBuffer()));
}
const exported = await PresentationFile.exportPptx(ppt);
await exported.save(path.join(OUT, 'candidate.pptx'));
console.log(JSON.stringify({ draft: path.join(OUT, 'candidate.pptx'), final: FINAL, slides: ppt.slides.items.length }));
