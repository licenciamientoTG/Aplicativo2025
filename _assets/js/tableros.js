(() => {
  'use strict';

  const app = document.getElementById('tablerosApp');
  if (!app) return;

  const apiRoot = '/tableros_api';
  const csrfToken = app.dataset.csrfToken || '';
  const canCreateBoard = app.dataset.canCreate === '1';
  const canAdmin = app.dataset.canAdmin === '1';
  let noticeTimer = 0;
  let filePreviewObjectUrl = '';
  let filePreviewRequest = 0;
  let filePreviewPanelRequest = 0;
  let activePreviewFile = null;
  let activePreviewItem = null;
  const els = {
    boardSelect: document.getElementById('boardSelect'),
    workspaceSelect: document.getElementById('workspaceSelect'),
    boardLoading: document.getElementById('boardLoading'),
    boardNotice: document.getElementById('boardNotice'),
    boardsEmpty: document.getElementById('boardsEmpty'),
    boardsEmptyText: document.getElementById('boardsEmptyText'),
    boardWorkspace: document.getElementById('boardWorkspace'),
    boardName: document.getElementById('boardName'),
    boardDescription: document.getElementById('boardDescription'),
    boardRole: document.getElementById('boardRole'),
    boardSearch: document.getElementById('boardSearch'),
    boardItemCount: document.getElementById('boardItemCount'),
    boardTableContainer: document.getElementById('boardTableContainer'),
    boardTableStatus: document.getElementById('boardTableStatus'),
    boardTableFrame: document.getElementById('boardTableFrame'),
    boardRendererArea: document.getElementById('boardRendererArea'),
    boardViewOptions: document.getElementById('boardViewOptions'),
    savedViewSelect: document.getElementById('savedViewSelect'),
    boardFiltersPanel: document.getElementById('boardFiltersPanel'),
    boardFilterRows: document.getElementById('boardFilterRows'),
    boardFilterCount: document.getElementById('boardFilterCount'),
    createBoardDialog: document.getElementById('createBoardDialog'),
    createWorkspaceDialog: document.getElementById('createWorkspaceDialog'),
    createFolderDialog: document.getElementById('createFolderDialog'),
    createGroupDialog: document.getElementById('createGroupDialog'),
    createColumnDialog: document.getElementById('createColumnDialog'),
    manageLabelsDialog: document.getElementById('manageLabelsDialog'),
    createItemDialog: document.getElementById('createItemDialog'),
    shareBoardDialog: document.getElementById('shareBoardDialog'),
    saveViewDialog: document.getElementById('saveViewDialog'),
    itemDetailsDialog: document.getElementById('itemDetailsDialog'),
    filePreviewDialog: document.getElementById('filePreviewDialog'),
    filePreviewIcon: document.getElementById('filePreviewIcon'),
    filePreviewTitle: document.getElementById('filePreviewTitle'),
    filePreviewType: document.getElementById('filePreviewType'),
    filePreviewContent: document.getElementById('filePreviewContent'),
    filePreviewPanel: document.getElementById('filePreviewPanel'),
    automationDialog: document.getElementById('automationDialog'),
    createBoardForm: document.getElementById('createBoardForm'),
    createWorkspaceForm: document.getElementById('createWorkspaceForm'),
    createFolderForm: document.getElementById('createFolderForm'),
    createGroupForm: document.getElementById('createGroupForm'),
    createColumnForm: document.getElementById('createColumnForm'),
    manageLabelsForm: document.getElementById('manageLabelsForm'),
    managedLabelsList: document.getElementById('managedLabelsList'),
    createItemForm: document.getElementById('createItemForm'),
    shareBoardForm: document.getElementById('shareBoardForm'),
    boardMembersList: document.getElementById('boardMembersList'),
    boardMembersStatus: document.getElementById('boardMembersStatus'),
    saveViewForm: document.getElementById('saveViewForm'),
    newItemGroup: document.getElementById('newItemGroup'),
    shareUserSearch: document.getElementById('shareUserSearch'),
    shareUserSelect: document.getElementById('shareUserSelect'),
    shareUserResults: document.getElementById('shareUserResults'),
    clearShareUser: document.getElementById('clearShareUser'),
    newColumnType: document.getElementById('newColumnType'),
    columnOptionsField: document.getElementById('columnOptionsField'),
    newColumnOptions: document.getElementById('newColumnOptions'),
    columnControlsPanel: document.getElementById('columnControlsPanel'),
    columnControlsList: document.getElementById('columnControlsList'),
    columnTypeHelp: document.getElementById('columnTypeHelp'),
    itemCommentsList: document.getElementById('itemCommentsList'),
    itemCommentStatus: document.getElementById('itemCommentStatus'),
    itemCommentForm: document.getElementById('itemCommentForm'),
    itemFilesList: document.getElementById('itemFilesList'),
    itemFilesStatus: document.getElementById('itemFilesStatus'),
    itemFileForm: document.getElementById('itemFileForm'),
    itemFileColumn: document.getElementById('itemFileColumn'),
    itemFileTarget: document.getElementById('itemFileTarget'),
    itemFileInput: document.getElementById('itemFileInput'),
    itemRelationsList: document.getElementById('itemRelationsList'),
    itemRelationsStatus: document.getElementById('itemRelationsStatus'),
    itemRelationForm: document.getElementById('itemRelationForm'),
    relationTargetBoard: document.getElementById('relationTargetBoard'),
    relationTargetItem: document.getElementById('relationTargetItem'),
    itemDependenciesList: document.getElementById('itemDependenciesList'),
    itemDependencyForm: document.getElementById('itemDependencyForm'),
    dependencyTargetItem: document.getElementById('dependencyTargetItem'),
    itemParentSelect: document.getElementById('itemParentSelect'),
    itemSubtasksList: document.getElementById('itemSubtasksList'),
    itemActivityList: document.getElementById('itemActivityList'),
    itemActivityStatus: document.getElementById('itemActivityStatus'),
    automationList: document.getElementById('automationList'),
    automationListStatus: document.getElementById('automationListStatus'),
    automationPermissionsNote: document.getElementById('automationPermissionsNote'),
    automationRunsList: document.getElementById('automationRunsList'),
    automationRunsStatus: document.getElementById('automationRunsStatus'),
    automationForm: document.getElementById('automationForm'),
    automationEditorPanel: document.getElementById('automationEditorPanel'),
    automationFilterField: document.getElementById('automationFilterField'),
    automationFilterValueFields: document.getElementById('automationFilterValueFields'),
    automationActionType: document.getElementById('automationActionType'),
    automationActionFields: document.getElementById('automationActionFields'),
    automationTrigger: document.getElementById('automationTrigger'),
    automationScheduleField: document.getElementById('automationScheduleField')
  };

  const state = {
    boards: [],
    workspaces: [],
    folders: [],
    selectedWorkspaceId: '',
    collapsedFolderIds: new Set(),
    pendingFolderContext: null,
    board: null,
    groups: [],
    columns: [],
    items: [],
    views: [],
    viewsAvailable: true,
    activeViewType: 'table',
    activeSavedViewId: '',
    viewConfig: {},
    filters: [],
    filterDraft: [],
    calendarMonth: new Date(new Date().getFullYear(), new Date().getMonth(), 1),
    collapsedGroups: new Set(),
    selectedItemIds: new Set(),
    bulkMoveInFlight: false,
    inlineAddGroupId: '',
    activeBoardRequest: 0,
    userSearchTimer: null,
    activeDetailRequest: 0,
    detailItemId: '',
    itemComments: [],
    itemActivity: [],
    itemRelations: [],
    itemDependencies: [],
    automations: [],
    automationRuns: [],
    automationRequest: 0,
    targetBoardItems: [],
    fileVersions: {},
    fileHistoryVisible: {},
    fileHistoryLoading: {},
    fileHistoryErrors: {},
    boardMembers: []
  };
  let shareUserSearchRequest = 0;
  let selectedShareUser = null;

  function setNotice(message, type = 'error') {
    window.clearTimeout(noticeTimer);
    noticeTimer = 0;
    els.boardNotice.textContent = message;
    els.boardNotice.className = `boards-notice is-${type}`;
    els.boardNotice.hidden = !message;
    if (message) {
      const duration = type === 'error' ? 8000 : 4500;
      noticeTimer = window.setTimeout(() => {
        els.boardNotice.hidden = true;
        els.boardNotice.textContent = '';
        noticeTimer = 0;
      }, duration);
    }
  }

  function clearNotice() {
    window.clearTimeout(noticeTimer);
    noticeTimer = 0;
    els.boardNotice.hidden = true;
    els.boardNotice.textContent = '';
  }

  function errorMessage(error, fallback = 'No se pudo completar la solicitud.') {
    return error && error.message ? error.message : fallback;
  }

  async function request(path, options = {}) {
    const response = await fetch(`${apiRoot}${path}`, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(options.headers || {})
      },
      ...options
    });
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error(response.ok ? 'El servidor devolvió una respuesta inesperada.' : `Error del servidor (HTTP ${response.status}).`);
    }
    if (!response.ok || result.success === false) {
      const requestError = new Error(result.error?.message || result.message || `Error del servidor (HTTP ${response.status}).`);
      requestError.status = response.status;
      requestError.code = result.error?.code || result.code || '';
      throw requestError;
    }
    return result;
  }

  function post(path, payload) {
    return request(path, {
      method: 'POST',
      body: JSON.stringify({ csrf_token: csrfToken, ...payload })
    });
  }

  function showLoading(show, message = 'Cargando tus tableros…') {
    els.boardLoading.hidden = !show;
    els.boardLoading.lastElementChild.textContent = message;
  }

  function openDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
    else dialog.setAttribute('open', '');
    const field = dialog.querySelector('input:not([type="hidden"]), select, textarea');
    if (field) window.setTimeout(() => field.focus(), 0);
  }

  function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function dialogSubmit(form, callback) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const button = form.querySelector('[type="submit"]');
      const label = button.textContent;
      button.disabled = true;
      button.textContent = 'Guardando…';
      try {
        await callback(new FormData(form));
        form.reset();
      } catch (error) {
        setNotice(errorMessage(error), 'error');
      } finally {
        button.disabled = false;
        button.textContent = label;
      }
    });
  }

  function getRouteBoardId() {
    const match = window.location.pathname.match(/\/tableros\/board\/([^/]+)/);
    if (!match) return '';
    try {
      return decodeURIComponent(match[1]);
    } catch (error) {
      return match[1];
    }
  }

  function updateBoardRoute(id, replace = false) {
    const path = id ? `/tableros/board/${encodeURIComponent(id)}` : '/tableros';
    if (window.location.pathname === path) return;
    window.history[replace ? 'replaceState' : 'pushState']({ boardId: id }, '', path);
  }

  function renderBoardOptions() {
    const previous = els.boardSelect.value;
    els.boardSelect.replaceChildren();
    const prompt = document.createElement('option');
    prompt.value = '';
    prompt.textContent = state.boards.length ? 'Selecciona un tablero' : 'No hay tableros';
    els.boardSelect.append(prompt);
    state.boards.forEach((board) => {
      const option = document.createElement('option');
      option.value = String(board.id);
      option.textContent = board.name || 'Tablero sin nombre';
      els.boardSelect.append(option);
    });
    els.boardSelect.disabled = state.boards.length === 0;
    if (state.boards.some((board) => String(board.id) === String(previous))) {
      els.boardSelect.value = String(previous);
    }
    renderBoardNav();
  }

  function folderPath(folderId, workspaceId) {
    const byId = new Map(state.folders.filter((folder) => String(folder.workspace_id) === String(workspaceId)).map((folder) => [String(folder.id), folder]));
    const parts = [];
    let current = byId.get(String(folderId));
    while (current) {
      parts.unshift(current.name || 'Carpeta sin nombre');
      current = current.parent_folder_id == null ? null : byId.get(String(current.parent_folder_id));
    }
    return parts.join(' / ');
  }

  function folderDepth(folderId, workspaceId) {
    const path = folderPath(folderId, workspaceId);
    return path ? path.split(' / ').length : 0;
  }

  function populateWorkspaceSelectors() {
    const workspaceSelect = document.getElementById('newBoardWorkspace');
    const folderSelect = document.getElementById('newBoardFolder');
    const folderWorkspace = document.getElementById('newFolderWorkspace');
    const parentSelect = document.getElementById('newFolderParent');
    [workspaceSelect, folderWorkspace].forEach((select) => {
      if (!select) return;
      const previous = state.selectedWorkspaceId || select.value;
      select.replaceChildren();
      state.workspaces.filter((workspace) => !workspace.shared_only).forEach((workspace) => {
        const option = makeElement('option', '', workspace.name || 'Espacio sin nombre');
        option.value = String(workspace.id);
        select.append(option);
      });
      if (Array.from(select.options).some((option) => option.value === String(previous))) select.value = String(previous);
    });
    const updateFolders = (select, workspaceId, includeRoot) => {
      if (!select) return;
      const previous = select.value;
      select.replaceChildren();
      if (includeRoot) {
        const root = makeElement('option', '', 'Sin carpeta · raíz del espacio');
        root.value = '';
        select.append(root);
      }
      state.folders.filter((folder) => String(folder.workspace_id) === String(workspaceId)
        && (select.id !== 'newFolderParent' || folderDepth(folder.id, workspaceId) < 3)).forEach((folder) => {
        const option = makeElement('option', '', folderPath(folder.id, workspaceId));
        option.value = String(folder.id);
        select.append(option);
      });
      if (Array.from(select.options).some((option) => option.value === previous)) select.value = previous;
    };
    updateFolders(folderSelect, workspaceSelect?.value || state.selectedWorkspaceId, true);
    updateFolders(parentSelect, folderWorkspace?.value || state.selectedWorkspaceId, true);
  }

  function refreshFolderChoices(workspaceId, preserveParent = true) {
    const folderSelect = document.getElementById('newBoardFolder');
    const parentSelect = document.getElementById('newFolderParent');
    const fill = (select, includeRoot) => {
      if (!select) return;
      const previous = preserveParent ? select.value : '';
      select.replaceChildren();
      if (includeRoot) {
        const root = makeElement('option', '', 'Sin carpeta · raíz del espacio');
        root.value = '';
        select.append(root);
      }
      state.folders.filter((folder) => String(folder.workspace_id) === String(workspaceId)
        && (select.id !== 'newFolderParent' || folderDepth(folder.id, workspaceId) < 3)).forEach((folder) => {
        const option = makeElement('option', '', folderPath(folder.id, workspaceId));
        option.value = String(folder.id);
        select.append(option);
      });
      if (Array.from(select.options).some((option) => option.value === previous)) select.value = previous;
    };
    fill(folderSelect, true);
    fill(parentSelect, true);
  }

  function openCreateFolder(workspaceId = state.selectedWorkspaceId, parentFolderId = '') {
    const workspace = state.workspaces.find((entry) => String(entry.id) === String(workspaceId));
    if (!workspace || workspace.shared_only) {
      setNotice('Solo puedes crear carpetas en espacios de trabajo a los que perteneces.', 'error');
      return;
    }
    populateWorkspaceSelectors();
    const workspaceSelect = document.getElementById('newFolderWorkspace');
    if (workspaceSelect && workspaceId) workspaceSelect.value = String(workspaceId);
    refreshFolderChoices(workspaceSelect?.value, false);
    const parentSelect = document.getElementById('newFolderParent');
    if (parentSelect && parentFolderId) parentSelect.value = String(parentFolderId);
    const parentLabel = document.querySelector('label[for="newFolderParent"]');
    const parentDepth = parentFolderId ? folderDepth(parentFolderId, workspaceSelect?.value) : 0;
    document.getElementById('createFolderTitle').textContent = parentFolderId ? 'Crear subcarpeta' : 'Crear carpeta';
    if (parentLabel) parentLabel.hidden = parentDepth >= 3;
    if (parentSelect) parentSelect.hidden = parentDepth >= 3;
    if (parentDepth >= 3 && parentSelect) parentSelect.value = String(parentFolderId);
    openDialog(els.createFolderDialog);
  }

  function openCreateBoard(workspaceId = state.selectedWorkspaceId, folderId = '') {
    const eligible = state.workspaces.filter((workspace) => !workspace.shared_only);
    if (!eligible.length) {
      openDialog(els.createWorkspaceDialog);
      setNotice('Crea un espacio de trabajo antes de crear tableros.', 'info');
      return;
    }
    if (!eligible.some((workspace) => String(workspace.id) === String(workspaceId))) workspaceId = eligible[0].id;
    populateWorkspaceSelectors();
    const workspaceSelect = document.getElementById('newBoardWorkspace');
    if (workspaceSelect && workspaceId) workspaceSelect.value = String(workspaceId);
    refreshFolderChoices(workspaceSelect?.value, false);
    const folderSelect = document.getElementById('newBoardFolder');
    if (folderSelect && folderId) folderSelect.value = String(folderId);
    openDialog(els.createBoardDialog);
  }

  function renderBoardNav() {
    const list = document.getElementById('boardNavList');
    const search = document.getElementById('boardNavSearch');
    if (!list || !search) return;
    const query = search.value.trim().toLocaleLowerCase('es-MX');
    const activeWorkspace = state.selectedWorkspaceId || state.board?.workspace_id;
    if (!state.workspaces.some((workspace) => String(workspace.id) === String(activeWorkspace))) {
      state.selectedWorkspaceId = String(state.workspaces[0]?.id || '');
    } else {
      state.selectedWorkspaceId = String(activeWorkspace || '');
    }
    const selector = document.getElementById('workspaceSelect');
    if (selector) {
      selector.replaceChildren();
      state.workspaces.forEach((workspace) => {
        const option = makeElement('option', '', workspace.name || 'Espacio sin nombre');
        option.value = String(workspace.id);
        selector.append(option);
      });
      selector.value = state.selectedWorkspaceId;
      if (selector.value) state.selectedWorkspaceId = selector.value;
    }
    list.replaceChildren();
    const workspaceId = String(state.selectedWorkspaceId || '');
    const workspace = state.workspaces.find((entry) => String(entry.id) === workspaceId);
    const rootFolderButton = document.getElementById('navCreateFolder');
    const rootBoardButton = document.getElementById('navCreateBoard');
    if (rootFolderButton) rootFolderButton.hidden = !workspace || Boolean(workspace.shared_only);
    if (rootBoardButton) rootBoardButton.hidden = !workspace || Boolean(workspace.shared_only);
    if (!workspace) {
      list.append(makeElement('p', 'boards-nav-empty', state.workspaces.length ? 'No hay contenido en este espacio.' : 'Crea un espacio de trabajo para empezar.'));
      return;
    }
    const workspaceFolders = state.folders.filter((folder) => String(folder.workspace_id) === workspaceId);
    const restricted = Boolean(workspace.shared_only);
    const folderNodes = new Map(workspaceFolders.map((folder) => [String(folder.id), { ...folder, children: [], boards: [] }]));
    const roots = [];
    folderNodes.forEach((folder) => {
      const parent = folder.parent_folder_id == null ? null : folderNodes.get(String(folder.parent_folder_id));
      (parent ? parent.children : roots).push(folder);
    });
    const workspaceBoards = state.boards.filter((board) => String(board.workspace_id) === workspaceId);
    const rootBoards = [];
    workspaceBoards.forEach((board) => {
      const parent = board.folder_id == null ? null : folderNodes.get(String(board.folder_id));
      (parent ? parent.boards : rootBoards).push(board);
    });
    const matchesText = (label) => !query || String(label || '').toLocaleLowerCase('es-MX').includes(query);
    const renderBoard = (board, depth) => {
      if (!matchesText(board.name)) return;
      const link = makeElement('button', 'boards-nav-board');
      link.type = 'button';
      link.dataset.navBoard = String(board.id);
      link.style.setProperty('--nav-depth', String(depth));
      link.title = board.name || 'Tablero sin nombre';
      if (state.board && String(state.board.id) === String(board.id)) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      }
      const icon = makeElement('i', 'fa-solid fa-table-cells');
      icon.setAttribute('aria-hidden', 'true');
      link.append(icon, makeElement('span', '', board.name || 'Tablero sin nombre'));
      list.append(link);
    };
    const renderFolder = (folder, depth = 0) => {
      const subtreeMatches = (node) => matchesText(node.name)
        || node.boards.some((board) => matchesText(board.name))
        || node.children.some(subtreeMatches);
      if (query && !subtreeMatches(folder)) return;
      const row = makeElement('div', 'boards-nav-folder-row');
      row.style.setProperty('--nav-depth', String(depth));
      const toggle = makeElement('button', 'boards-nav-folder');
      toggle.type = 'button';
      toggle.dataset.toggleFolder = String(folder.id);
      const folderColor = /^#[\da-f]{3,8}$/i.test(String(folder.color || '')) ? String(folder.color) : '#61d5a8';
      toggle.style.setProperty('--folder-color', folderColor);
      const collapsed = state.collapsedFolderIds.has(String(folder.id)) && !query;
      toggle.setAttribute('aria-expanded', String(!collapsed));
      toggle.append(makeElement('i', `fa-solid ${collapsed ? 'fa-chevron-right' : 'fa-chevron-down'}`, ''));
      toggle.append(makeElement('i', 'fa-regular fa-folder', ''));
      toggle.append(makeElement('span', '', folder.name || 'Carpeta sin nombre'));
      row.append(toggle);
      if (canCreateBoard && !workspace.shared_only) {
        const actions = makeElement('span', 'boards-nav-folder-actions');
        if (depth < 2) {
          const addFolder = makeElement('button', '', '');
          addFolder.type = 'button'; addFolder.dataset.createChildFolder = String(folder.id);
          addFolder.title = 'Crear subcarpeta'; addFolder.setAttribute('aria-label', `Crear subcarpeta en ${folder.name}`);
          addFolder.append(makeElement('i', 'fa-regular fa-folder-plus', ''));
          actions.append(addFolder);
        }
        const addBoard = makeElement('button', '', '');
        addBoard.type = 'button'; addBoard.dataset.createBoardFolder = String(folder.id);
        addBoard.title = 'Crear tablero en esta carpeta'; addBoard.setAttribute('aria-label', `Crear tablero en ${folder.name}`);
        addBoard.append(makeElement('i', 'fa-solid fa-plus', ''));
        actions.append(addBoard); row.append(actions);
      }
      list.append(row);
      if (collapsed) return;
      folder.boards.filter((board) => matchesText(board.name)).forEach((board) => renderBoard(board, depth + 1));
      folder.children.forEach((child) => renderFolder(child, depth + 1));
    };
    rootBoards.forEach((board) => renderBoard(board, 0));
    roots.forEach((folder) => renderFolder(folder));
    if (!list.children.length) list.append(makeElement('p', 'boards-nav-empty', query ? 'No hay coincidencias en este espacio.' : 'Crea una carpeta o un tablero para empezar.'));
    populateWorkspaceSelectors();
  }

  async function loadBoards() {
    showLoading(true);
    els.boardWorkspace.hidden = true;
    els.boardsEmpty.hidden = true;
    try {
      const structureResult = await request('/structure');
      const structure = structureResult.data || {};
      state.boards = Array.isArray(structure.boards) ? structure.boards : [];
      state.workspaces = Array.isArray(structure.workspaces) ? structure.workspaces : [];
      state.folders = Array.isArray(structure.folders) ? structure.folders : [];
      state.selectedWorkspaceId = String(state.boards[0]?.workspace_id || state.workspaces[0]?.id || '');
      renderBoardOptions();
      const routeId = getRouteBoardId();
      const requestedId = app.dataset.initialBoardId || routeId;
      const target = state.boards.find((board) => String(board.id) === String(requestedId)) || state.boards[0];
      if (target) {
        els.boardSelect.value = String(target.id);
        await loadBoard(target.id, true);
      } else {
        showLoading(false);
        showEmptyState();
      }
    } catch (error) {
      showLoading(false);
      els.boardSelect.disabled = true;
      setNotice(errorMessage(error, 'No se pudieron cargar los tableros.'), 'error');
      showEmptyState();
    }
  }

  function showEmptyState() {
    state.board = null;
    state.selectedItemIds.clear();
    state.inlineAddGroupId = '';
    els.boardWorkspace.hidden = true;
    els.boardsEmpty.hidden = false;
    renderBoardNav();
    if (state.boards.length === 0 && !canCreateBoard) {
      els.boardsEmptyText.textContent = 'No tienes tableros disponibles. Pide acceso a una persona administradora.';
    } else if (state.boards.length === 0) {
      els.boardsEmptyText.textContent = 'Crea un tablero para reunir el trabajo de tu equipo en un solo lugar.';
    }
  }

  async function loadBoard(id, skipRoute = false) {
    if (!id) {
      state.board = null;
      showLoading(false);
      showEmptyState();
      return;
    }
    const requestId = ++state.activeBoardRequest;
    clearNotice();
    showLoading(true, 'Cargando el tablero…');
    els.boardWorkspace.hidden = true;
    els.boardsEmpty.hidden = true;
    try {
      const result = await request(`/board/${encodeURIComponent(id)}`);
      if (requestId !== state.activeBoardRequest) return;
      const data = result.data || {};
      state.board = data.board || null;
      state.selectedWorkspaceId = String(state.board?.workspace_id || state.selectedWorkspaceId || '');
      state.groups = Array.isArray(data.groups) ? data.groups : [];
      state.columns = Array.isArray(data.columns) ? data.columns : [];
      state.items = Array.isArray(data.items) ? data.items : [];
      state.viewsAvailable = Array.isArray(data.views);
      state.views = state.viewsAvailable ? data.views : [];
      state.groups.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      state.columns.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      state.items.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      if (!state.board) throw new Error('El servidor no devolvió los datos del tablero.');
      state.activeViewType = 'table';
      state.activeSavedViewId = '';
      state.viewConfig = {};
      state.filters = [];
      state.filterDraft = [];
      state.calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
      if (!skipRoute) updateBoardRoute(state.board.id);
      els.boardSelect.value = String(state.board.id);
      renderBoardNav();
      state.collapsedGroups.clear();
      state.selectedItemIds.clear();
      state.inlineAddGroupId = '';
      els.boardSearch.value = '';
      renderWorkspace();
      showLoading(false);
      els.boardWorkspace.hidden = false;
    } catch (error) {
      if (requestId !== state.activeBoardRequest) return;
      showLoading(false);
      setNotice(errorMessage(error, 'No se pudo cargar el tablero.'), 'error');
      els.boardsEmpty.hidden = true;
      els.boardWorkspace.hidden = true;
    }
  }

  function canEditBoard() {
    const role = String(state.board?.role || '').toLowerCase();
    if (!role) return canAdmin;
    return !['viewer', 'read', 'readonly', 'read-only'].includes(role);
  }

  function canManageStructure() {
    const role = String(state.board?.role || '').toLowerCase();
    if (!role) return canAdmin;
    return ['owner', 'designer', 'admin', 'administrator'].includes(role);
  }

  function renderWorkspace() {
    const board = state.board;
    els.boardName.textContent = board.name || 'Tablero sin nombre';
    els.boardDescription.textContent = board.description || '';
    els.boardDescription.hidden = !board.description;
    const role = String(board.role || '').toLowerCase();
    const roleLabels = { owner: 'Propietario', designer: 'Diseñador', admin: 'Administrador', administrator: 'Administrador', editor: 'Puede editar', viewer: 'Solo lectura' };
    els.boardRole.textContent = roleLabels[role] || (board.role ? String(board.role) : 'Tablero');
    els.boardRole.hidden = !board.role;
    const edit = canEditBoard();
    const manage = canManageStructure();
    document.getElementById('openCreateItem').hidden = !edit;
    document.getElementById('openCreateGroup').hidden = !manage;
    document.getElementById('openCreateColumn').hidden = !manage;
    const shareButton = document.getElementById('openShareBoard');
    if (shareButton) shareButton.hidden = !manage;
    const sharedViewOption = document.getElementById('sharedViewOption');
    if (sharedViewOption) sharedViewOption.classList.toggle('d-none', !manage);
    renderColumnControls();
    renderSavedViews();
    renderFilterPanel();
    renderActiveView();
  }

  function stringifyValue(value) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'object') {
      try { return JSON.stringify(value); } catch (error) { return '[Valor estructurado]'; }
    }
    if (typeof value === 'boolean') return value ? 'Sí' : 'No';
    return String(value);
  }

  function safeGroupColor(color) {
    const value = String(color || '').trim();
    return /^#[\da-f]{3,8}$/i.test(value) ? value : '#0f766e';
  }

  function statusLabelColor(label, index = 0) {
    const normalized = String(label || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-MX');
    if (/(estancad|bloquead|rechazad|mal estado|atrasad|cancelad|error)/.test(normalized)) return '#e2445c';
    if (/(listo|hecho|completad|terminad|finalizad|aprobado|publicad|colocad|entregad)/.test(normalized)) return '#00c875';
    if (/(proceso|progreso|curso|trabajando|ejecucion)/.test(normalized)) return '#fdab3d';
    if (/(revision|aprobacion)/.test(normalized)) return '#579bfc';
    if (/(diseno|creativ|boceto)/.test(normalized)) return '#a25ddc';
    if (/(pendiente|por hacer|nuevo|sin iniciar)/.test(normalized)) return '#c4c4c4';
    return ['#579bfc', '#00c875', '#fdab3d', '#a25ddc', '#ff5ac4', '#00c875', '#9aadbd'][index % 7];
  }

  function statusTone(column, value) {
    const stored = String(value ?? '').trim();
    const option = parseOptions(column.options).find((entry) => entry.value === stored);
    const label = String(option?.label ?? stored).trim().toLocaleLowerCase('es-MX');
    if (!label) return 'empty';
    if (/\b(no|sin|pendiente de)\s+(aproba|completa|termina|publica|entrega)/.test(label) || /(bloquead|rechazad|cancelad|atrasad|vencid|error)/.test(label)) return 'blocked';
    if (/(listo|hecho|completad|terminad|finalizad|aprobad|publicad|colocad|entregad)/.test(label)) return 'done';
    if (/(proceso|progreso|curso|revisi[oó]n|aprobaci[oó]n|trabajando|ejecuci[oó]n)/.test(label)) return 'working';
    return 'neutral';
  }

  function parseOptions(options) {
    let source = options;
    if (typeof source === 'string') {
      try { source = JSON.parse(source); } catch (error) { source = source.split(/\r?\n/); }
    }
    if (source && !Array.isArray(source) && Array.isArray(source.values)) source = source.values;
    if (source && !Array.isArray(source) && Array.isArray(source.options)) source = source.options;
    if (source && !Array.isArray(source) && Array.isArray(source.statuses)) source = source.statuses;
    if (source && !Array.isArray(source) && Array.isArray(source.choices)) source = source.choices;
    if (source && !Array.isArray(source) && Array.isArray(source.labels)) source = source.labels;
    if (!Array.isArray(source)) return [];
    return source.map((option, index) => {
      if (option && typeof option === 'object') {
        const value = option.value ?? option.id ?? option.name ?? option.label ?? '';
        const label = option.label ?? option.name ?? option.value ?? option.id ?? '';
        return { value: String(value), label: String(label), color: safeGroupColor(option.color || option.bg_color || statusLabelColor(label, index)) };
      }
      return { value: String(option), label: String(option), color: statusLabelColor(option, index) };
    }).filter((option) => option.value !== '');
  }

  function normalizeColumnViewConfig() {
    const validIds = new Set(state.columns.map((column) => String(column.id)));
    const order = Array.isArray(state.viewConfig.column_order) ? state.viewConfig.column_order.map(String).filter((id, index, list) => validIds.has(id) && list.indexOf(id) === index) : [];
    state.columns.forEach((column) => {
      const id = String(column.id);
      if (!order.includes(id)) order.push(id);
    });
    state.viewConfig.column_order = order;
    state.viewConfig.hidden_column_ids = Array.isArray(state.viewConfig.hidden_column_ids)
      ? state.viewConfig.hidden_column_ids.map(String).filter((id, index, list) => validIds.has(id) && list.indexOf(id) === index)
      : [];
  }

  function orderedColumns(includeHidden = false) {
    normalizeColumnViewConfig();
    const rank = new Map(state.viewConfig.column_order.map((id, index) => [String(id), index]));
    const hidden = new Set(state.viewConfig.hidden_column_ids);
    return state.columns.slice().sort((a, b) => (rank.get(String(a.id)) ?? Number.MAX_SAFE_INTEGER) - (rank.get(String(b.id)) ?? Number.MAX_SAFE_INTEGER))
      .filter((column) => includeHidden || !hidden.has(String(column.id)));
  }

  function renderColumnControls() {
    const list = document.getElementById('columnControlsList');
    if (!list) return;
    normalizeColumnViewConfig();
    list.replaceChildren();
    const columns = orderedColumns(true);
    if (!columns.length) {
      list.append(makeElement('p', 'boards-filter-empty', 'Este tablero todavía no tiene columnas configuradas.'));
      return;
    }
    const hidden = new Set(state.viewConfig.hidden_column_ids);
    columns.forEach((column, index) => {
      const row = makeElement('div', 'boards-column-control-row');
      const label = makeElement('label', 'boards-column-control-label');
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.checked = !hidden.has(String(column.id));
      checkbox.dataset.columnVisibility = String(column.id);
      checkbox.setAttribute('aria-label', `Mostrar columna ${column.name || 'sin nombre'}`);
      label.append(checkbox, makeElement('span', '', column.name || 'Columna sin nombre'), makeElement('small', '', String(column.type || 'text')));
      const actions = makeElement('div', 'boards-column-order-actions');
      [-1, 1].forEach((direction) => {
        const button = makeElement('button', 'boards-column-order-button', direction < 0 ? '↑' : '↓');
        button.type = 'button';
        button.dataset.moveColumn = String(column.id);
        button.dataset.direction = String(direction);
        button.disabled = (direction < 0 && index === 0) || (direction > 0 && index === columns.length - 1);
        button.setAttribute('aria-label', `${direction < 0 ? 'Subir' : 'Bajar'} columna ${column.name || ''}`);
        actions.append(button);
      });
      if (canManageStructure() && ['status', 'dropdown', 'tags'].includes(String(column.type || '').toLowerCase())) {
        const labelsButton = makeElement('button', 'boards-column-labels-button', 'Etiquetas');
        labelsButton.type = 'button';
        labelsButton.dataset.manageColumnLabels = String(column.id);
        labelsButton.setAttribute('aria-label', `Editar etiquetas y colores de ${column.name || 'columna'}`);
        actions.append(labelsButton);
      }
      row.append(label, actions);
      list.append(row);
    });
  }

  function normalizedLabelColor(value, fallback = '#579bfc') {
    const color = String(value || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(color)) return color.toLowerCase();
    if (/^#[0-9a-f]{3}$/i.test(color)) return `#${color.slice(1).split('').map((part) => part + part).join('').toLowerCase()}`;
    return fallback;
  }

  function managedLabelRow(option, index) {
    const row = makeElement('div', 'boards-managed-label-row');
    row.dataset.optionValue = String(option.value || '');
    const swatch = document.createElement('input');
    swatch.type = 'color';
    swatch.className = 'boards-label-color-picker';
    swatch.value = normalizedLabelColor(option.color, statusLabelColor(option.label, index));
    swatch.setAttribute('aria-label', `Color para ${option.label || 'etiqueta'}`);
    const name = document.createElement('input');
    name.type = 'text';
    name.className = 'form-control boards-label-name';
    name.value = String(option.label || '');
    name.maxLength = 120;
    name.required = true;
    name.placeholder = 'Nombre de etiqueta';
    name.setAttribute('aria-label', 'Nombre de etiqueta');
    const preview = makeElement('span', 'boards-label-preview', name.value || 'Etiqueta');
    preview.style.setProperty('--status-color', swatch.value);
    const sync = () => {
      preview.textContent = name.value.trim() || 'Etiqueta';
      preview.style.setProperty('--status-color', swatch.value);
      swatch.setAttribute('aria-label', `Color para ${name.value.trim() || 'etiqueta'}`);
    };
    name.addEventListener('input', sync);
    swatch.addEventListener('input', sync);
    row.append(swatch, name, preview);
    return row;
  }

  function openLabelsEditor(column) {
    if (!canManageStructure()) throw new Error('Se requiere permiso de diseño para editar etiquetas.');
    if (!column || !['status', 'dropdown', 'tags'].includes(String(column.type || '').toLowerCase())) {
      throw new Error('Esta columna no admite etiquetas configurables.');
    }
    els.manageLabelsForm.dataset.columnId = String(column.id);
    document.getElementById('manageLabelsTitle').textContent = `Etiquetas de ${column.name || 'columna'}`;
    document.getElementById('manageLabelsDescription').textContent = 'Elige el nombre y el color que verá el equipo.';
    els.managedLabelsList.replaceChildren(...parseOptions(column.options).map(managedLabelRow));
    openDialog(els.manageLabelsDialog);
  }

  function addManagedLabel() {
    const label = 'Etiqueta nueva';
    const value = `label_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    els.managedLabelsList.append(managedLabelRow({ value, label, color: statusLabelColor(label, els.managedLabelsList.children.length) }, els.managedLabelsList.children.length));
    els.managedLabelsList.lastElementChild?.querySelector('.boards-label-name')?.focus();
  }

  function moveColumnInView(columnId, direction) {
    const order = orderedColumns(true).map((column) => String(column.id));
    const index = order.indexOf(String(columnId));
    const nextIndex = index + direction;
    if (index < 0 || nextIndex < 0 || nextIndex >= order.length) return;
    [order[index], order[nextIndex]] = [order[nextIndex], order[index]];
    state.viewConfig.column_order = order;
    markViewModified();
    renderColumnControls();
    renderActiveView();
  }

  function selectedIds(value) {
    if (Array.isArray(value)) return value.map((entry) => Number(entry?.id ?? entry?.user_id ?? entry)).filter(Number.isInteger);
    if (value && typeof value === 'object') return [Number(value.id ?? value.user_id)].filter(Number.isInteger);
    if (value === null || value === undefined || value === '') return [];
    const parsed = Number(value);
    return Number.isInteger(parsed) ? [parsed] : [];
  }

  function setStructuredEditorValue(textarea, requireJson = false) {
    if (!textarea.value.trim()) return null;
    try {
      const parsed = JSON.parse(textarea.value);
      if (parsed === null || typeof parsed === 'object' || typeof parsed === 'string') return parsed;
      if (requireJson) throw new Error('Este campo requiere una lista u objeto JSON.');
      return textarea.value;
    } catch (error) {
      if (error.message === 'Este campo requiere una lista u objeto JSON.') throw error;
      if (requireJson) throw new Error('Este campo requiere JSON válido (una lista u objeto).');
      return textarea.value;
    }
  }

  function createPeopleEditor(item, column, value, multiple) {
    const wrapper = document.createElement('div');
    wrapper.className = 'boards-people-editor';
    markEditor(wrapper, item, column, 'people');
    wrapper.dataset.multiple = multiple ? '1' : '0';
    const control = document.createElement('div');
    control.className = 'boards-people-control';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'boards-people-search';
    search.placeholder = 'Buscar persona…';
    search.setAttribute('aria-label', `Buscar persona para ${column.name || 'columna'}`);
    search.setAttribute('role', 'combobox');
    search.setAttribute('aria-autocomplete', 'list');
    search.setAttribute('aria-expanded', 'false');
    search.setAttribute('aria-haspopup', 'listbox');
    const selected = document.createElement('div');
    selected.className = 'boards-people-selected';
    const results = document.createElement('div');
    results.className = 'boards-people-results';
    results.hidden = true;
    const options = document.createElement('div');
    options.className = 'boards-people-options';
    options.setAttribute('role', 'listbox');
    const addButton = makeElement('button', 'boards-people-add', '+');
    addButton.type = 'button';
    addButton.setAttribute('aria-label', multiple ? 'Agregar personas responsables' : 'Cambiar persona responsable');
    addButton.hidden = !canEditBoard();
    control.tabIndex = canEditBoard() ? 0 : -1;
    control.setAttribute('role', canEditBoard() ? 'button' : 'group');
    control.setAttribute('aria-label', `Responsables de ${item.name || 'elemento'}`);
    const select = document.createElement('select');
    select.multiple = true;
    select.hidden = true;
    select.setAttribute('aria-label', `${item.name || 'Elemento'}, ${column.name || 'personas'}`);
    const ids = selectedIds(value);
    const people = new Map();
    ids.forEach((id) => people.set(String(id), { id, name: 'Consultando acceso…', username: '' }));
    control.append(selected, addButton);
    results.append(search, options);
    wrapper.append(control, results, select);
    let requestNumber = 0;

    const initialsFor = (person) => {
      const words = String(person.name || person.username || '?').trim().split(/\s+/).filter(Boolean);
      if (!words.length) return '?';
      return (words.length > 1 ? `${words[0][0]}${words[words.length - 1][0]}` : words[0].slice(0, 2)).toLocaleUpperCase('es-MX');
    };
    const personColor = (person) => {
      const palette = ['#6677d8', '#168f85', '#c07835', '#9564c7', '#d05e78', '#398db1'];
      const key = String(person.id || person.username || person.name || '');
      const index = Array.from(key).reduce((sum, char) => sum + char.charCodeAt(0), 0) % palette.length;
      return palette[index];
    };
    const avatar = (person, className = '') => {
      const badge = makeElement('span', `boards-person-avatar ${className}`.trim(), initialsFor(person));
      badge.style.setProperty('--person-color', personColor(person));
      badge.title = person.name || person.username || 'Persona';
      return badge;
    };
    const syncSelection = () => {
      select.replaceChildren();
      people.forEach((person, id) => {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = person.name || person.username || '';
        option.selected = true;
        select.append(option);
      });
      selected.replaceChildren();
      selected.hidden = !people.size;
      people.forEach((person) => {
        const chip = document.createElement('span');
        chip.className = 'boards-people-chip';
        chip.append(avatar(person));
        chip.title = person.name || person.username || 'Sin acceso';
        selected.append(chip);
      });
      addButton.textContent = people.size && !multiple ? '↻' : '+';
      addButton.setAttribute('aria-label', people.size && !multiple ? 'Cambiar persona responsable' : 'Agregar responsable');
      addButton.hidden = !canEditBoard() || (!multiple && people.size > 0);
    };

    const closeResults = () => {
      results.hidden = true;
      search.setAttribute('aria-expanded', 'false');
      search.value = '';
      window.removeEventListener('scroll', positionResults, true);
      window.removeEventListener('resize', positionResults);
      document.removeEventListener('pointerdown', closeOnOutside, true);
      ['position', 'z-index', 'left', 'top', 'bottom', 'width', 'max-height'].forEach((property) => results.style.removeProperty(property));
      if (results.parentElement !== wrapper) wrapper.append(results);
    };
    const closeOnOutside = (event) => {
      if (!wrapper.contains(event.target) && !results.contains(event.target)) closeResults();
    };
    const positionResults = () => {
      const rect = control.getBoundingClientRect();
      const width = Math.min(Math.max(280, rect.width), window.innerWidth - 16);
      const below = Math.max(0, window.innerHeight - rect.bottom - 8);
      const above = Math.max(0, rect.top - 8);
      const desiredHeight = Math.min(360, results.scrollHeight);
      const placeAbove = below < desiredHeight && above > below;
      const availableHeight = placeAbove ? above : below;
      const maxHeight = Math.min(360, Math.max(100, availableHeight));
      const popupHeight = Math.min(desiredHeight, maxHeight);
      results.style.position = 'fixed';
      results.style.zIndex = '10000';
      results.style.width = `${width}px`;
      results.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
      results.style.maxHeight = `${maxHeight}px`;
      if (placeAbove) results.style.top = `${Math.max(8, rect.top - popupHeight - 5)}px`;
      else results.style.top = `${Math.min(rect.bottom + 5, window.innerHeight - popupHeight - 8)}px`;
    };
    const openResults = (message = 'Buscando personas…') => {
      options.replaceChildren(makeElement('div', 'boards-people-results-heading', 'Personas sugeridas'));
      options.append(makeElement('div', 'boards-people-empty', message));
      if (results.parentElement !== document.body) document.body.append(results);
      results.hidden = false;
      search.setAttribute('aria-expanded', 'true');
      positionResults();
      window.addEventListener('scroll', positionResults, true);
      window.addEventListener('resize', positionResults);
      // pointerdown runs before the opening click, so the opening gesture
      // cannot immediately be treated as an outside click.
      document.addEventListener('pointerdown', closeOnOutside, true);
    };
    const searchUsers = async (term = search.value.trim(), reveal = true) => {
      const currentRequest = ++requestNumber;
      const params = new URLSearchParams({ q: term, board_id: String(state.board?.id || '') });
      if (reveal) openResults('Buscando personas…');
      try {
        const response = await request(`/users?${params.toString()}`);
        if (!document.contains(wrapper) || currentRequest !== requestNumber) return;
        const users = Array.isArray(response.data) ? response.data : [];
        users.forEach((user) => { if (people.has(String(user.id))) people.set(String(user.id), user); });
        people.forEach((person, id) => {
          if (person.name === 'Consultando acceso…') people.set(id, { ...person, name: 'Sin acceso en este espacio' });
        });
        syncSelection();
        options.replaceChildren();
        const heading = makeElement('div', 'boards-people-results-heading', term ? 'Resultados' : 'Personas sugeridas');
        options.append(heading);
        if (!reveal) { closeResults(); return; }
        if (!users.length) {
          options.append(makeElement('div', 'boards-people-empty', term ? 'No hay personas con acceso que coincidan.' : 'No hay personas con acceso a este espacio.'));
          options.append(makeElement('div', 'boards-people-empty', 'Solo aparecen usuarios activos con acceso al espacio o tablero. Usa Compartir para dar acceso a otra persona.'));
          positionResults();
          return;
        }
        users.forEach((user) => {
          const id = String(user.id);
          const option = makeElement('button', 'boards-people-option');
          option.type = 'button';
          option.setAttribute('role', 'option');
          option.setAttribute('aria-selected', people.has(id) ? 'true' : 'false');
          const copy = makeElement('span', 'boards-people-option-copy');
          copy.append(makeElement('strong', '', user.name || user.username || 'Persona'));
          if (user.username && user.username !== user.name) copy.append(makeElement('small', '', user.username));
          option.append(avatar(user), copy);
          option.addEventListener('click', () => {
            if (multiple) {
              if (people.has(id)) people.delete(id); else people.set(id, user);
            } else {
              people.clear();
              people.set(id, user);
              closeResults();
            }
            syncSelection();
            if (multiple) { search.value = ''; search.focus(); searchUsers(''); }
            else { search.value = ''; closeResults(); }
            select.dispatchEvent(new Event('change', { bubbles: true }));
          });
          options.append(option);
        });
        positionResults();
      } catch (error) {
        if (!reveal || currentRequest !== requestNumber) return;
        options.replaceChildren(makeElement('div', 'boards-people-results-heading', 'Personas sugeridas'));
        options.append(makeElement('div', 'boards-people-empty', errorMessage(error, 'No se pudo buscar personas.')));
        positionResults();
      }
    };
    let timer = null;
    search.addEventListener('focus', () => searchUsers());
    const showPicker = () => {
      if (!canEditBoard()) return;
      if (results.hidden) {
        search.value = '';
        openResults();
        search.focus({ preventScroll: true });
      } else search.focus({ preventScroll: true });
    };
    control.addEventListener('click', showPicker);
    addButton.addEventListener('click', (event) => { event.stopPropagation(); showPicker(); });
    control.addEventListener('keydown', (event) => {
      if ((event.key === 'Enter' || event.key === ' ') && event.target === control) {
        event.preventDefault();
        showPicker();
      }
    });
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => searchUsers(), 220);
    });
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { closeResults(); search.blur(); }
      if (event.key === 'ArrowDown') options.querySelector('button')?.focus();
      if (event.key === 'Enter' && options.querySelector('button')) { event.preventDefault(); options.querySelector('button').click(); }
    });
    results.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { closeResults(); search.focus(); }
    });
    search.addEventListener('blur', () => window.setTimeout(() => {
      if (!wrapper.contains(document.activeElement) && !results.contains(document.activeElement)) closeResults();
    }, 140));
    syncSelection();
    if (ids.length) searchUsers('', false);
    return wrapper;
  }

  function createStatusEditor(item, column, value) {
    const options = parseOptions(column.options);
    const wrapper = document.createElement('div');
    wrapper.className = 'boards-status-editor';
    markEditor(wrapper, item, column, 'status');
    const select = document.createElement('select');
    select.hidden = true;
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Sin valor';
    select.append(blank);
    options.forEach((entry) => {
      const option = document.createElement('option');
      option.value = entry.value;
      option.textContent = entry.label;
      select.append(option);
    });
    const currentValue = value == null ? '' : String(value);
    if (currentValue && !options.some((entry) => entry.value === currentValue)) {
      const legacy = document.createElement('option');
      legacy.value = currentValue;
      legacy.textContent = currentValue;
      select.append(legacy);
    }
    select.value = currentValue;

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'boards-status-pill';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-label', `${column.name || 'Estado'}: ${currentValue || 'Sin valor'}`);
    const caret = document.createElement('span');
    caret.className = 'boards-status-caret';
    caret.setAttribute('aria-hidden', 'true');
    const menu = document.createElement('div');
    menu.className = 'boards-status-menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;

    const updatePill = () => {
      const selected = options.find((entry) => entry.value === select.value);
      trigger.textContent = selected?.label || (select.value ? select.value : 'Sin valor');
      trigger.append(caret);
      const color = selected?.color || (select.value ? statusLabelColor(select.value) : '#8b91a7');
      trigger.style.setProperty('--status-color', color);
      trigger.dataset.statusTone = statusTone(column, select.value);
      trigger.setAttribute('aria-label', `${column.name || 'Estado'}: ${selected?.label || select.value || 'Sin valor'}`);
    };
    const closeMenu = () => {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      wrapper.closest('.boards-item-row')?.classList.remove('is-status-menu-open');
      menu.classList.remove('opens-up');
      ['position', 'z-index', 'left', 'top', 'bottom', 'width', 'max-height'].forEach((property) => menu.style.removeProperty(property));
      window.removeEventListener('scroll', closeMenu, true);
      window.removeEventListener('resize', closeMenu);
      document.removeEventListener('pointerdown', closeOnOutside);
      if (menu.parentElement !== wrapper) wrapper.append(menu);
    };
    const closeOnOutside = (event) => {
      if (!wrapper.contains(event.target) && !menu.contains(event.target)) closeMenu();
    };
    const openMenu = () => {
      menu.replaceChildren();
      const clear = makeElement('button', 'boards-status-choice is-empty', 'Sin valor');
      clear.type = 'button';
      clear.setAttribute('role', 'option');
      clear.setAttribute('aria-selected', select.value === '' ? 'true' : 'false');
      clear.addEventListener('click', () => {
        select.value = '';
        updatePill();
        closeMenu();
        wrapper.dispatchEvent(new Event('change', { bubbles: true }));
      });
      menu.append(clear);
      options.forEach((entry) => {
        const option = makeElement('button', 'boards-status-choice', entry.label);
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', select.value === entry.value ? 'true' : 'false');
        option.style.setProperty('--status-color', entry.color);
        option.addEventListener('click', () => {
          select.value = entry.value;
          updatePill();
          closeMenu();
          wrapper.dispatchEvent(new Event('change', { bubbles: true }));
        });
        menu.append(option);
      });
      if (canManageStructure()) {
        const manage = makeElement('button', 'boards-status-manage-labels', 'Editar etiquetas y colores');
        manage.type = 'button';
        manage.addEventListener('click', () => {
          closeMenu();
          openLabelsEditor(column);
        });
        menu.append(manage);
      }
      document.body.append(menu);
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      wrapper.closest('.boards-item-row')?.classList.add('is-status-menu-open');
      const triggerRect = trigger.getBoundingClientRect();
      const menuWidth = Math.min(Math.max(190, triggerRect.width), window.innerWidth - 16);
      const availableBelow = window.innerHeight - triggerRect.bottom - 12;
      const availableAbove = triggerRect.top - 12;
      const desiredHeight = Math.min(360, options.length * 35 + (canManageStructure() ? 96 : 52));
      menu.style.position = 'fixed';
      menu.style.zIndex = '10000';
      menu.style.width = `${menuWidth}px`;
      menu.style.left = `${Math.max(8, Math.min(triggerRect.left, window.innerWidth - menuWidth - 8))}px`;
      if (availableBelow < desiredHeight && availableAbove > availableBelow) {
        menu.classList.add('opens-up');
        const menuHeight = Math.min(desiredHeight, availableAbove);
        menu.style.maxHeight = `${menuHeight}px`;
        menu.style.top = `${Math.max(8, triggerRect.top - menuHeight - 5)}px`;
      } else {
        menu.style.maxHeight = `${Math.max(90, Math.min(desiredHeight, availableBelow))}px`;
        menu.style.top = `${triggerRect.bottom + 5}px`;
      }
      window.addEventListener('scroll', closeMenu, true);
      window.addEventListener('resize', closeMenu);
      document.addEventListener('pointerdown', closeOnOutside);
      menu.querySelector('[aria-selected="true"]')?.focus();
    };

    trigger.addEventListener('click', () => menu.hidden ? openMenu() : closeMenu());
    trigger.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeMenu();
      if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openMenu(); }
    });
    menu.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') { closeMenu(); trigger.focus(); }
    });
    menu.addEventListener('focusout', () => window.setTimeout(() => {
      if (!wrapper.contains(document.activeElement) && !menu.contains(document.activeElement)) closeMenu();
    }, 100));
    wrapper.append(select, trigger, menu);
    updatePill();
    return wrapper;
  }

  function createStructuredEditor(item, column, value, type) {
    const wrapper = document.createElement('div');
    wrapper.className = 'boards-structured-editor';
    markEditor(wrapper, item, column, 'structured');
    wrapper.dataset.columnType = type;
    const textarea = document.createElement('textarea');
    textarea.className = 'boards-cell-input boards-json-input';
    textarea.rows = 2;
    textarea.value = value == null ? '' : (typeof value === 'string' ? value : stringifyValue(value));
    textarea.setAttribute('aria-label', `${item.name || 'Elemento'}, ${column.name || 'dato estructurado'}`);
    const help = document.createElement('small');
    help.className = 'boards-editor-help';
    help.textContent = 'Acepta JSON o texto; las relaciones requieren una lista u objeto JSON válido.';
    wrapper.append(textarea, help);
    return wrapper;
  }

  function createFileCellEditor(item, column) {
    const wrapper = makeElement('div', 'boards-file-cell');
    const files = filesForItem(item).filter((file) => String(file.column_id || '') === String(column.id));
    const list = makeElement('div', 'boards-file-cell-list');
    files.forEach((file) => {
      const entry = makeElement('div', 'boards-file-cell-entry');
      const typeInfo = fileTypeInfo(file);
      const icon = makeElement('button', `boards-file-cell-icon is-${typeInfo.previewKind}`);
      icon.type = 'button';
      icon.setAttribute('aria-label', `Previsualizar ${file.name || 'archivo'}`);
      icon.title = `Abrir ${file.name || 'archivo'}`;
      const status = String(file.scan_status || 'unscanned').toLowerCase();
      const downloadable = ['clean', 'unscanned'].includes(status);
      icon.disabled = !downloadable;
      if (typeInfo.previewKind === 'image' && downloadable) {
        icon.classList.add('has-thumbnail');
        const thumbnail = document.createElement('img');
        thumbnail.src = fileEndpoint(file, true);
        thumbnail.alt = `Vista previa de ${file.name || 'imagen'}`;
        thumbnail.addEventListener('error', () => {
          thumbnail.remove();
          icon.classList.remove('has-thumbnail');
          icon.append(makeElement('i', `fa-solid ${typeInfo.icon}`));
        }, { once: true });
        icon.append(thumbnail);
      } else {
        icon.append(makeElement('i', `fa-solid ${typeInfo.icon}`));
      }
      if (downloadable) icon.addEventListener('click', () => openFilePreview(file, item));
      else icon.title = status === 'pending' ? 'Archivo pendiente de habilitar.' : 'Archivo bloqueado.';
      entry.append(icon);
      list.append(entry);
    });
    wrapper.append(list);
    if (!canEditBoard()) {
      if (!files.length) wrapper.append(makeElement('span', 'boards-file-cell-empty', '—'));
      return wrapper;
    }
    const input = document.createElement('input');
    input.type = 'file';
    input.className = 'boards-file-cell-input';
    input.accept = '.pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx';
    input.setAttribute('aria-label', `Seleccionar archivo para ${item.name || 'elemento'}`);
    const button = makeElement('button', 'boards-file-cell-add', files.length ? '＋ Adjuntar' : '＋ Subir archivo');
    button.type = 'button';
    button.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      const validationMessage = fileUploadValidationMessage(file);
      if (validationMessage) {
        setNotice(validationMessage, 'error');
        input.value = '';
        return;
      }
      button.disabled = true;
      button.textContent = 'Subiendo…';
      try {
        await uploadFileToItem(item, column, file);
        renderActiveView();
        setNotice('Archivo subido y vinculado al elemento.', 'success');
      } catch (error) {
        renderActiveView();
        setNotice(errorMessage(error, 'No se pudo subir el archivo.'), 'error');
      } finally {
        input.value = '';
      }
    });
    wrapper.append(input, button);
    return wrapper;
  }

  function createCellEditor(item, column, value) {
    const type = String(column.type || 'text').toLowerCase();
    if (type === 'file') return createFileCellEditor(item, column);
    if (!canEditBoard()) {
      const readonly = document.createElement('span');
      readonly.className = ['status', 'dropdown'].includes(type) ? 'boards-status-readonly' : 'boards-readonly-value';
      const option = ['status', 'dropdown'].includes(type)
        ? parseOptions(column.options).find((entry) => entry.value === String(value ?? ''))
        : null;
      readonly.textContent = option?.label || stringifyValue(value) || '—';
      if (['status', 'dropdown', 'tags'].includes(type) && option) readonly.style.setProperty('--status-color', option.color);
      return readonly;
    }

    if (['status', 'dropdown'].includes(type) && parseOptions(column.options).length) {
      return createStatusEditor(item, column, value);
    }

    const input = document.createElement('input');
    input.className = 'boards-cell-input';
    input.value = value == null ? '' : String(value);
    if (['name', 'text', 'long_text', 'formula'].includes(type)) {
      if (type === 'long_text' || type === 'formula') {
        const textarea = document.createElement('textarea');
        textarea.className = 'boards-cell-input boards-json-input';
        textarea.rows = 1;
        textarea.value = value == null ? '' : String(value);
        if (type === 'formula') textarea.title = 'El servidor valida la sintaxis permitida para fórmulas.';
        return markEditor(textarea, item, column, 'string');
      }
      return markEditor(input, item, column, 'string');
    }
    if (['numbers', 'progress', 'rating', 'item_id'].includes(type)) {
      if (type === 'rating') {
        const select = document.createElement('select');
        select.className = 'boards-status-select';
        const maximum = Math.max(1, Math.min(10, Number(column.options?.max) || 5));
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = 'Sin calificar';
        select.append(blank);
        for (let score = 1; score <= maximum; score += 1) {
          const option = document.createElement('option');
          option.value = String(score);
          option.textContent = `${score} de ${maximum}`;
          select.append(option);
        }
        select.value = value == null ? '' : String(value);
        return markEditor(select, item, column, 'number');
      }
      input.type = 'number';
      input.step = 'any';
      if (type === 'progress') { input.min = '0'; input.max = '100'; input.placeholder = '0–100'; }
      if (type === 'item_id') { input.step = '1'; input.min = '1'; input.placeholder = 'ID del elemento'; }
      input.classList.add('is-number');
      return markEditor(input, item, column, 'number');
    }
    if (type === 'date') {
      input.type = 'date';
      input.classList.add('is-date');
      input.value = dateOnly(value);
      return markEditor(input, item, column, 'string');
    }
    if (type === 'hour') {
      input.type = 'time';
      input.value = value == null ? '' : String(value).slice(0, 5);
      return markEditor(input, item, column, 'string');
    }
    if (type === 'timeline') {
      const range = dateRange(value);
      const wrapper = document.createElement('div');
      wrapper.className = 'boards-timeline-editor';
      markEditor(wrapper, item, column, 'timeline');
      const start = document.createElement('input');
      start.type = 'date';
      start.value = range.start;
      start.dataset.timelinePart = 'start';
      start.setAttribute('aria-label', `${column.name || 'Cronograma'} inicio`);
      const end = document.createElement('input');
      end.type = 'date';
      end.value = range.end;
      end.dataset.timelinePart = 'end';
      end.setAttribute('aria-label', `${column.name || 'Cronograma'} término`);
      const save = document.createElement('button');
      save.type = 'button';
      save.className = 'boards-cell-save';
      save.dataset.saveCell = '1';
      save.textContent = 'Guardar';
      wrapper.append(start, end, save);
      return wrapper;
    }
    if (type === 'dropdown') {
      const options = parseOptions(column.options);
      if (!options.length) {
        input.type = 'text';
        input.placeholder = type === 'status' ? 'Escribe un estado' : 'Escribe un valor';
        input.dataset.statusTone = statusTone(column, value);
        return markEditor(input, item, column, 'string');
      }
      const currentValue = value == null ? '' : String(value);
      const hasCurrentValue = options.some((option) => option.value === currentValue);
      const select = document.createElement('select');
      select.className = 'boards-status-select';
      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = 'Sin valor';
      select.append(blank);
      if (currentValue && !hasCurrentValue) {
        const unavailable = document.createElement('option');
        unavailable.value = currentValue;
        unavailable.textContent = `${currentValue} · opción no disponible`;
        unavailable.selected = true;
        unavailable.disabled = true;
        select.append(unavailable);
      }
      options.forEach((optionData) => {
        const option = document.createElement('option');
        option.value = optionData.value;
        option.textContent = optionData.label;
        select.append(option);
      });
      select.value = currentValue;
      select.dataset.statusTone = statusTone(column, currentValue);
      return markEditor(select, item, column, 'string');
    }
    if (type === 'tags') {
      const options = parseOptions(column.options);
      if (options.length) {
        const selected = new Set(Array.isArray(value) ? value.map(String) : []);
        if (Array.from(selected).some((tag) => !options.some((option) => option.value === tag))) {
          const readonly = makeElement('span', 'boards-readonly-value', stringifyValue(value));
          readonly.title = 'Hay etiquetas que ya no están en las opciones de la columna; ajústalas para poder editarlas.';
          return readonly;
        }
        const select = document.createElement('select');
        select.className = 'boards-status-select boards-tags-select';
        select.multiple = true;
        select.size = Math.min(4, options.length);
        options.forEach((entry) => {
          const option = document.createElement('option');
          option.value = entry.value;
          option.textContent = entry.label;
          option.selected = selected.has(entry.value);
          select.append(option);
        });
        return markEditor(select, item, column, 'tags');
      }
      input.type = 'text';
      input.value = Array.isArray(value) ? value.join(', ') : (value == null ? '' : String(value));
      input.placeholder = 'Separar etiquetas con comas';
      return markEditor(input, item, column, 'tags-text');
    }
    if (type === 'people' || type === 'person') return createPeopleEditor(item, column, value, type === 'people');
    if (type === 'email') { input.type = 'email'; return markEditor(input, item, column, 'string'); }
    if (type === 'phone') { input.type = 'tel'; return markEditor(input, item, column, 'string'); }
    if (type === 'link') {
      input.type = 'url';
      input.value = value && typeof value === 'object' ? String(value.url || value.value || '') : (value == null ? '' : String(value));
      input.placeholder = 'https://…';
      return markEditor(input, item, column, 'string');
    }
    if (['team', 'country', 'location', 'board_relation', 'subtasks', 'dependency'].includes(type)) {
      return createStructuredEditor(item, column, value, type);
    }

    const readonly = document.createElement('span');
    readonly.className = 'boards-readonly-value';
    readonly.textContent = stringifyValue(value) || '—';
    readonly.title = `El tipo «${String(column.type || 'desconocido')}» es de solo lectura en esta versión.`;
    return readonly;
  }

  function markEditor(element, item, column, kind) {
    element.dataset.cellEditor = '1';
    element.dataset.valueKind = kind;
    element.dataset.itemId = String(item.id);
    element.dataset.columnId = String(column.id);
    element.setAttribute('aria-label', `${item.name || 'Elemento'}, ${column.name || 'columna'}`);
    return element;
  }

  function getCellValue(item, column) {
    if (!item.cells || typeof item.cells !== 'object') return null;
    return item.cells[String(column.id)] ?? null;
  }

  function createItemNameControl(item, className = 'boards-item-name-link') {
    if (!canEditBoard()) return makeElement('span', className, item.name || 'Elemento sin nombre');
    const button = makeElement('button', className, item.name || 'Elemento sin nombre');
    button.type = 'button';
    button.dataset.itemNameButton = String(item.id);
    button.title = 'Editar nombre del elemento';
    button.setAttribute('aria-label', `Editar nombre: ${item.name || 'Elemento sin nombre'}`);
    return button;
  }

  function createTableItemName(item) {
    const wrap = makeElement('div', 'boards-table-item-name-wrap');
    wrap.append(makeElement('span', 'boards-item-name-link', item.name || 'Elemento sin nombre'));
    if (canEditBoard()) {
      const edit = makeElement('button', 'boards-item-name-edit', '');
      edit.type = 'button';
      edit.dataset.itemNameButton = String(item.id);
      edit.setAttribute('aria-label', `Editar nombre: ${item.name || 'Elemento sin nombre'}`);
      edit.title = 'Editar nombre';
      const icon = makeElement('i', 'fa-solid fa-pen');
      icon.setAttribute('aria-hidden', 'true');
      edit.append(icon);
      wrap.append(edit);
    }
    return wrap;
  }

  function beginItemNameEdit(button) {
    const item = state.items.find((candidate) => String(candidate.id) === button.dataset.itemNameButton);
    if (!item || !canEditBoard()) return;
    const form = document.createElement('form');
    form.className = 'boards-item-name-form';
    form.dataset.itemNameForm = '1';
    form.dataset.itemId = String(item.id);
    form.dataset.controlClass = button.className;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'boards-item-name-input';
    input.value = item.name || '';
    input.maxLength = 500;
    input.required = true;
    input.setAttribute('aria-label', 'Nuevo nombre del elemento');
    const actions = makeElement('span', 'boards-item-name-actions');
    const save = makeElement('button', 'boards-item-name-save', 'Guardar');
    save.type = 'submit';
    const cancel = makeElement('button', 'boards-item-name-cancel', 'Cancelar');
    cancel.type = 'button';
    cancel.dataset.cancelNameEdit = '1';
    actions.append(save, cancel);
    form.append(input, actions);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      const name = input.value.trim();
      if (!name) return;
      if (name === item.name) { renderActiveView(); return; }
      const controls = Array.from(form.querySelectorAll('input, button'));
      controls.forEach((control) => { control.disabled = true; });
      try {
        const result = await post('/update_item_name', {
          board_id: state.board.id,
          item_id: item.id,
          name,
          version: item.version
        });
        item.name = result.data?.name ?? name;
        item.version = result.data?.version ?? item.version;
        renderActiveView();
        setNotice('Nombre del elemento actualizado.', 'success');
      } catch (error) {
        setNotice(errorMessage(error, 'No se pudo actualizar el nombre.'), 'error');
        if (error.status === 409 || error.code === 'conflict' || /409|actualiz|conflict/i.test(error.message)) await loadBoard(state.board.id, true);
        else controls.forEach((control) => { control.disabled = false; });
      }
    });
    button.replaceWith(form);
    input.focus();
    input.select();
  }

  function canManageAutomation() {
    return canManageStructure();
  }

  function displayDateTime(value) {
    if (!value) return 'Sin fecha';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  function parseStoredValue(value) {
    if (typeof value !== 'string') return value;
    try { return JSON.parse(value); } catch (error) { return value; }
  }

  function storedFilesForItem(item) {
    const files = [];
    state.columns.filter((column) => columnType(column) === 'file').forEach((column) => {
      const value = parseStoredValue(getCellValue(item, column));
      const entries = Array.isArray(value) ? value : (Array.isArray(value?.files) ? value.files : (value && typeof value === 'object' && (value.file_id || value.id) ? [value] : []));
      entries.forEach((entry) => {
        if (!entry || typeof entry !== 'object') return;
        const fileId = Number(entry.file_id ?? entry.id);
        if (!Number.isInteger(fileId) || fileId <= 0) return;
        files.push({ ...entry, file_id: fileId, column_id: entry.column_id ?? column.id });
      });
    });
    return files;
  }

  function filesForItem(item) {
    return storedFilesForItem(item);
  }

  function fileTypeInfo(file) {
    const mime = String(file.content_type || '').toLowerCase().split(';')[0].trim();
    const name = String(file.name || file.original_name || '');
    const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    const imageMime = ['image/webp', 'image/jpeg', 'image/png', 'image/gif'].includes(mime);
    const previewKind = imageMime ? 'image'
      : (mime === 'application/pdf' ? 'pdf'
        : (['text/plain', 'text/csv'].includes(mime) ? 'text' : 'unsupported'));
    const icon = previewKind === 'pdf' ? 'fa-file-pdf'
      : (imageMime || ['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(extension) ? 'fa-file-image'
        : (['doc', 'docx', 'odt'].includes(extension) ? 'fa-file-word'
          : (['xls', 'xlsx', 'csv'].includes(extension) ? 'fa-file-excel'
            : (['ppt', 'pptx'].includes(extension) ? 'fa-file-powerpoint' : 'fa-file'))));
    return { extension, mime, previewKind, icon };
  }

  function fileEndpoint(file, preview = false) {
    const query = new URLSearchParams();
    if (file.version_id) query.set('version_id', String(file.version_id));
    if (preview) query.set('preview', '1');
    const suffix = query.toString();
    return `${apiRoot}/download_file/${encodeURIComponent(file.file_id)}${suffix ? `?${suffix}` : ''}`;
  }

  function clearFilePreviewObjectUrl() {
    filePreviewRequest += 1;
    if (filePreviewObjectUrl) URL.revokeObjectURL(filePreviewObjectUrl);
    filePreviewObjectUrl = '';
  }

  function showFilePreviewError(message) {
    const error = makeElement('div', 'boards-preview-unavailable');
    error.setAttribute('role', 'alert');
    error.append(makeElement('h4', '', 'No se pudo cargar la vista previa'));
    error.append(makeElement('p', '', message));
    els.filePreviewContent.replaceChildren(error);
  }

  function previewMeta(label, value) {
    const row = makeElement('div', 'boards-preview-meta-row');
    row.append(makeElement('span', '', label), makeElement('strong', '', value || '—'));
    return row;
  }

  async function renderFilePreviewPanel(panelName) {
    if (!els.filePreviewPanel || !activePreviewFile) return;
    const panelRequest = ++filePreviewPanelRequest;
    const file = activePreviewFile;
    const panel = els.filePreviewPanel;
    panel.replaceChildren();
    panel.hidden = false;
    const headings = { comments: 'Comentarios', versions: 'Versiones', gallery: 'Galería', information: 'Información' };
    panel.append(makeElement('h4', '', headings[panelName] || 'Detalles'));
    if (panelName === 'comments') {
      const status = makeElement('p', 'boards-preview-panel-status', 'Cargando comentarios…');
      status.setAttribute('role', 'status');
      panel.append(status);
      try {
        const result = await request(`/file_comments/${encodeURIComponent(file.file_id)}`);
        if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
        const comments = Array.isArray(result.data) ? result.data : [];
        status.textContent = comments.length ? '' : 'Todavía no hay comentarios.';
        comments.forEach((comment) => {
          const article = makeElement('article', 'boards-preview-comment');
          const author = comment.created_by_display_name || comment.author_name || comment.user_name || comment.username || 'Usuario desconocido';
          article.append(makeElement('strong', '', author));
          if (comment.created_at) article.append(makeElement('time', '', displayDateTime(comment.created_at)));
          article.append(makeElement('p', '', comment.body || comment.comment || ''));
          if (Array.isArray(comment.attachments) && comment.attachments.length) {
            const attachments = makeElement('div', 'boards-preview-comment-attachments');
            comment.attachments.forEach((attachment) => {
              const link = makeElement('a', '', attachment.name || `Archivo ${attachment.file_id}`);
              link.href = fileEndpoint({ file_id: attachment.file_id });
              link.target = '_blank'; link.rel = 'noopener noreferrer';
              attachments.append(link);
            });
            article.append(attachments);
          }
          panel.append(article);
        });
      } catch (error) {
        if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
        status.textContent = errorMessage(error, 'No se pudieron cargar los comentarios.');
        status.classList.add('is-error');
      }
      const form = document.createElement('form');
      form.className = 'boards-preview-comment-form';
      const textarea = document.createElement('textarea');
      textarea.rows = 3; textarea.maxLength = 10000; textarea.required = true;
      textarea.setAttribute('aria-label', 'Nuevo comentario'); textarea.placeholder = 'Escribe un comentario… Usa @ para mencionar a alguien.';
      const mentionList = makeElement('div', 'boards-preview-mention-list');
      mentionList.hidden = true;
      mentionList.setAttribute('role', 'listbox');
      mentionList.setAttribute('aria-label', 'Usuarios para mencionar');
      const selectedFiles = [];
      const fileInput = document.createElement('input');
      fileInput.type = 'file'; fileInput.multiple = true; fileInput.hidden = true;
      fileInput.setAttribute('aria-label', 'Archivos para adjuntar al comentario');
      const fileList = makeElement('div', 'boards-preview-comment-files');
      const actions = makeElement('div', 'boards-preview-comment-actions');
      const emojiWrap = document.createElement('details');
      emojiWrap.className = 'boards-preview-emoji-picker';
      const emojiToggle = makeElement('summary', '', '😊 Emoji');
      emojiToggle.setAttribute('aria-label', 'Insertar emoji');
      const emojiOptions = makeElement('div', 'boards-preview-emoji-options');
      ['😀', '👍', '🎉', '❤️', '😂', '🙏', '🚀', '✅'].forEach((emoji) => {
        const button = makeElement('button', '', emoji); button.type = 'button';
        button.setAttribute('aria-label', `Insertar ${emoji}`);
        button.addEventListener('click', () => {
          const start = textarea.selectionStart ?? textarea.value.length;
          const end = textarea.selectionEnd ?? start;
          textarea.setRangeText(emoji, start, end, 'end');
          textarea.focus();
          emojiWrap.open = false;
        });
        emojiOptions.append(button);
      });
      emojiWrap.append(emojiToggle, emojiOptions);
      const attachButton = makeElement('button', 'boards-button-light', 'Adjuntar archivo'); attachButton.type = 'button';
      attachButton.addEventListener('click', () => { fileInput.value = ''; fileInput.click(); });
      const submit = makeElement('button', 'boards-preview-submit', 'Comentar'); submit.type = 'submit';
      actions.append(emojiWrap, attachButton, submit);
      form.append(textarea, mentionList, fileInput, fileList, actions);
      let mentionSearchTimer = 0;
      let mentionRequestId = 0;
      let mentionStart = -1;
      let pendingCommentId = null;
      const selectedMentions = new Map();
      const renderSelectedFiles = () => {
        fileList.replaceChildren();
        selectedFiles.forEach((entry, index) => {
          const row = makeElement('span', 'boards-preview-comment-file', entry.name);
          const remove = makeElement('button', '', 'Quitar'); remove.type = 'button';
          remove.addEventListener('click', () => { selectedFiles.splice(index, 1); renderSelectedFiles(); });
          row.append(remove); fileList.append(row);
        });
      };
      fileInput.addEventListener('change', () => {
        Array.from(fileInput.files || []).forEach((selectedFile) => {
          const validationMessage = fileUploadValidationMessage(selectedFile);
          if (validationMessage) { setNotice(validationMessage, 'error'); return; }
          if (!selectedFiles.some((entry) => entry.file === selectedFile)) selectedFiles.push({ file: selectedFile, name: selectedFile.name });
        });
        renderSelectedFiles();
      });
      const findMentionTrigger = () => {
        const caret = textarea.selectionStart ?? textarea.value.length;
        const beforeCaret = textarea.value.slice(0, caret);
        const match = beforeCaret.match(/(^|\s)@([^\s@]*)$/u);
        if (!match) return null;
        return { start: caret - match[0].length + match[1].length, query: match[2] };
      };
      const searchMentions = async () => {
        const trigger = findMentionTrigger();
        if (!trigger || !state.board?.id) {
          mentionRequestId += 1;
          mentionList.hidden = true; mentionList.replaceChildren(); return;
        }
        mentionStart = trigger.start;
        const requestId = ++mentionRequestId;
        const params = new URLSearchParams({ board_id: String(state.board.id), q: trigger.query });
        try {
          const result = await request(`/users?${params.toString()}`);
          if (requestId !== mentionRequestId || !textarea.isConnected) return;
          const users = Array.isArray(result.data) ? result.data : [];
          mentionList.replaceChildren();
          users.slice(0, 8).forEach((user) => {
            const userId = user.id ?? user.user_id;
            const name = user.display_name || user.Usuario || user.name || user.username || user.email || 'Usuario';
            const username = String(user.username || user.Usuario || '').trim();
            if (userId == null) return;
            const option = makeElement('button', 'boards-preview-mention-option', username ? `${name} (@${username})` : name); option.type = 'button';
            option.setAttribute('role', 'option');
            option.addEventListener('click', () => {
              const caret = textarea.selectionStart ?? textarea.value.length;
              const triggerStart = mentionStart;
              if (triggerStart < 0) return;
              const token = `@${username || userId}`;
              textarea.setRangeText(`${token} `, triggerStart, caret, 'end');
              selectedMentions.set(String(userId), token);
              mentionList.hidden = true; mentionList.replaceChildren(); textarea.focus();
            });
            mentionList.append(option);
          });
          mentionList.hidden = mentionList.childElementCount === 0;
        } catch (_) {
          if (requestId === mentionRequestId) { mentionList.hidden = true; mentionList.replaceChildren(); }
        }
      };
      textarea.addEventListener('input', () => {
        window.clearTimeout(mentionSearchTimer);
        mentionSearchTimer = window.setTimeout(searchMentions, 180);
      });
      textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !mentionList.hidden) { mentionList.hidden = true; mentionList.replaceChildren(); }
        if (mentionList.hidden) return;
        const options = Array.from(mentionList.querySelectorAll('[role="option"]'));
        if (!options.length) return;
        const activeIndex = options.findIndex((option) => option.getAttribute('aria-selected') === 'true');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault();
          const next = event.key === 'ArrowDown'
            ? (activeIndex + 1) % options.length
            : (activeIndex <= 0 ? options.length - 1 : activeIndex - 1);
          options.forEach((option, index) => option.setAttribute('aria-selected', index === next ? 'true' : 'false'));
        } else if (event.key === 'Enter' && activeIndex >= 0) {
          event.preventDefault();
          options[activeIndex].click();
        }
      });
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = textarea.value.trim(); if (!body && pendingCommentId == null) return;
        submit.disabled = true;
        try {
          if (pendingCommentId == null) {
            const mentions = Array.from(selectedMentions.entries())
              .filter(([, token]) => body.toLocaleLowerCase().includes(token.toLocaleLowerCase()))
              .map(([userId]) => userId);
            const commentResult = await post('/add_file_comment', { file_id: file.file_id, body, mentions });
            pendingCommentId = commentResult.data?.id ?? commentResult.data?.file_comment_id;
          }
          const commentId = pendingCommentId;
          if (selectedFiles.length) {
            if (!commentId) throw new Error('El servidor no devolvió el identificador del comentario para adjuntar archivos.');
            const item = activePreviewItem;
            if (!item || !state.board?.id) throw new Error('No se encontró el elemento del tablero para subir los archivos.');
            for (const entry of selectedFiles) {
              let uploadedFileId = entry.uploadedFileId;
              if (!uploadedFileId) {
                const payload = new FormData();
                payload.set('csrf_token', csrfToken);
                payload.set('board_id', String(state.board.id));
                payload.set('item_id', String(item.id));
                payload.set('file', entry.file);
                const uploaded = await multipartPost('/upload_file', payload);
                uploadedFileId = uploaded.data?.id ?? uploaded.data?.file_id;
                entry.uploadedFileId = uploadedFileId;
              }
              if (!uploadedFileId) throw new Error('El servidor no devolvió el identificador del archivo adjunto.');
              await post('/attach_comment_file', { file_comment_id: commentId, file_id: uploadedFileId });
            }
          }
          if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
          selectedFiles.splice(0, selectedFiles.length);
          selectedMentions.clear();
          pendingCommentId = null;
          await renderFilePreviewPanel('comments');
        } catch (error) { setNotice(errorMessage(error, 'No se pudo publicar el comentario.'), 'error'); submit.disabled = false; }
      });
      panel.append(form);
    } else if (panelName === 'versions') {
      const status = makeElement('p', 'boards-preview-panel-status', 'Cargando versiones…'); panel.append(status);
      const addVersion = makeElement('button', 'boards-preview-version-add', 'Agregar versión');
      addVersion.type = 'button';
      addVersion.disabled = !canEditBoard();
      const versionInput = document.createElement('input');
      versionInput.type = 'file';
      versionInput.hidden = true;
      versionInput.tabIndex = -1;
      versionInput.setAttribute('aria-label', 'Seleccionar archivo para agregar una versión');
      addVersion.addEventListener('click', () => { versionInput.value = ''; versionInput.click(); });
      versionInput.addEventListener('change', async () => {
        const selectedFile = versionInput.files?.[0];
        const item = activePreviewItem;
        const column = state.columns.find((candidate) => String(candidate.id) === String(file.column_id) && columnType(candidate) === 'file');
        if (!selectedFile || !item || !column) {
          if (selectedFile) setNotice('No se encontró la columna de archivo para agregar esta versión.', 'error');
          return;
        }
        addVersion.disabled = true;
        addVersion.textContent = 'Subiendo…';
        try {
          const reference = await uploadFileToItem(item, column, selectedFile, String(file.file_id));
          openFilePreview(reference, item);
          setNotice('Nueva versión agregada.', 'success');
          await renderFilePreviewPanel('versions');
        } catch (error) {
          setNotice(errorMessage(error, 'No se pudo agregar la versión.'), 'error');
          addVersion.disabled = false;
          addVersion.textContent = 'Agregar versión';
        }
      });
      panel.append(addVersion, versionInput);
      try {
        const result = await request(`/file_versions/${encodeURIComponent(file.file_id)}`);
        if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
        const versions = Array.isArray(result.data) ? result.data : [];
        state.fileVersions[String(file.file_id)] = versions;
        status.textContent = versions.length ? '' : 'No hay versiones registradas.';
        versions.forEach((version) => {
          const button = makeElement('button', 'boards-preview-version', `v${version.version_number || '?'} · ${version.original_name || file.name || 'Archivo'}`);
          button.type = 'button';
          button.setAttribute('aria-current', String(version.id) === String(file.version_id || '') || Boolean(Number(version.is_current)) ? 'true' : 'false');
          button.append(makeElement('small', '', `${formatBytes(version.byte_size)} · ${version.scan_status || 'sin estado'}`));
          button.addEventListener('click', () => {
            const status = String(version.scan_status || '').toLowerCase();
            if (!['clean', 'unscanned'].includes(status)) return;
            openFilePreview({ ...file, version_id: version.id, name: version.original_name || file.name, content_type: version.content_type || file.content_type }, activePreviewItem);
            renderFilePreviewPanel('versions');
          });
          button.disabled = !['clean', 'unscanned'].includes(String(version.scan_status || '').toLowerCase());
          panel.append(button);
        });
      } catch (error) { status.textContent = errorMessage(error, 'No se pudieron cargar las versiones.'); status.classList.add('is-error'); }
    } else if (panelName === 'gallery') {
      const images = (activePreviewItem ? filesForItem(activePreviewItem) : []).filter((candidate) => fileTypeInfo(candidate).previewKind === 'image' && ['clean', 'unscanned'].includes(String(candidate.scan_status || 'unscanned').toLowerCase()));
      if (!images.length) panel.append(makeElement('p', 'boards-preview-panel-status', 'No hay otras imágenes en este elemento.'));
      images.forEach((imageFile) => {
        const button = makeElement('button', 'boards-preview-gallery-item'); button.type = 'button';
        const thumbnail = document.createElement('img'); thumbnail.src = fileEndpoint(imageFile, true); thumbnail.alt = '';
        button.append(thumbnail, makeElement('span', '', imageFile.name || imageFile.original_name || 'Imagen'));
        button.setAttribute('aria-current', String(imageFile.file_id) === String(file.file_id) ? 'true' : 'false');
        button.addEventListener('click', () => openFilePreview(imageFile, activePreviewItem));
        panel.append(button);
      });
    } else if (panelName === 'information') {
      if (!Array.isArray(state.fileVersions[String(file.file_id)])) {
        try {
          const result = await request(`/file_versions/${encodeURIComponent(file.file_id)}`);
          if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
          state.fileVersions[String(file.file_id)] = Array.isArray(result.data) ? result.data : [];
        } catch (error) { /* The file reference still supplies basic metadata. */ }
      }
      if (panelRequest !== filePreviewPanelRequest || String(activePreviewFile?.file_id) !== String(file.file_id)) return;
      const version = (state.fileVersions[String(file.file_id)] || []).find((entry) => String(entry.id) === String(file.version_id))
        || (state.fileVersions[String(file.file_id)] || []).find((entry) => Boolean(Number(entry.is_current)));
      const info = { ...file, ...(version || {}) };
      panel.append(previewMeta('Nombre', info.original_name || info.name), previewMeta('Tipo', info.content_type || 'Desconocido'), previewMeta('Tamaño', formatBytes(info.byte_size)), previewMeta('Versión', info.version_number ? `v${info.version_number}` : 'Actual'), previewMeta('Subido por', info.created_by_display_name || info.uploader_name || info.uploaded_by_name || info.user_name || 'Usuario desconocido'), previewMeta('Fecha', info.created_at ? displayDateTime(info.created_at) : ''));
      if (fileTypeInfo(file).previewKind === 'image') {
        const image = els.filePreviewContent.querySelector('img');
        const dimensions = makeElement('p', 'boards-preview-panel-status', image?.naturalWidth ? `${image.naturalWidth} × ${image.naturalHeight} px` : 'Dimensiones disponibles al cargar la imagen.');
        if (image) image.addEventListener('load', () => { dimensions.textContent = `${image.naturalWidth} × ${image.naturalHeight} px`; }, { once: true });
        panel.append(dimensions);
      }
    }
  }

  function setupFilePreviewRail() {
    document.querySelectorAll('[data-preview-panel]').forEach((button) => button.addEventListener('click', async () => {
      const name = button.dataset.previewPanel;
      const wasOpen = button.getAttribute('aria-expanded') === 'true';
      document.querySelectorAll('[data-preview-panel]').forEach((candidate) => candidate.setAttribute('aria-expanded', 'false'));
      if (wasOpen) { filePreviewPanelRequest += 1; els.filePreviewPanel.hidden = true; return; }
      button.setAttribute('aria-expanded', 'true');
      await renderFilePreviewPanel(name);
    }));
  }

  async function openFilePreview(file, item = null) {
    if (!file?.file_id || !els.filePreviewDialog) return;
    activePreviewFile = { ...file };
    activePreviewItem = item || null;
    filePreviewPanelRequest += 1;
    clearFilePreviewObjectUrl();
    const previewRequest = filePreviewRequest;
    const type = fileTypeInfo(file);
    const name = file.name || file.original_name || 'Archivo';
    els.filePreviewTitle.textContent = name;
    els.filePreviewType.textContent = type.extension ? type.extension.toUpperCase() : (type.mime || 'Archivo');
    els.filePreviewIcon.replaceChildren(makeElement('i', `fa-solid ${type.icon}`));
    els.filePreviewContent.replaceChildren();
    if (els.filePreviewPanel) els.filePreviewPanel.hidden = true;
    document.querySelectorAll('[data-preview-panel]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
    if (type.previewKind === 'image') {
      const image = makeElement('img', 'boards-preview-image');
      image.src = fileEndpoint(file, true);
      image.alt = name;
      els.filePreviewContent.append(image);
    } else if (['pdf', 'text'].includes(type.previewKind)) {
      const loading = makeElement('div', 'boards-preview-unavailable', 'Cargando vista previa…');
      els.filePreviewContent.append(loading);
      openDialog(els.filePreviewDialog);
      try {
        const response = await fetch(fileEndpoint(file, true), {
          credentials: 'same-origin',
          headers: { Accept: type.previewKind === 'pdf' ? 'application/pdf, application/json' : 'text/plain, text/csv, application/json' }
        });
        const blob = await response.blob();
        if (!response.ok || /(?:application\/json|\+json)/i.test(response.headers.get('content-type') || '')) {
          let message = `Error del servidor (HTTP ${response.status}).`;
          try {
            const payload = JSON.parse(await blob.text());
            message = payload.error?.message || payload.message || message;
          } catch (error) {
            // Keep the readable HTTP fallback when the response is not JSON.
          }
          throw new Error(message);
        }
        if (previewRequest !== filePreviewRequest) return;
        if (type.previewKind === 'pdf') {
          filePreviewObjectUrl = URL.createObjectURL(blob);
          const frame = makeElement('iframe', 'boards-preview-frame');
          frame.src = filePreviewObjectUrl;
          frame.title = `Vista previa de ${name}`;
          frame.referrerPolicy = 'no-referrer';
          els.filePreviewContent.replaceChildren(frame);
        } else {
          const text = await blob.text();
          if (previewRequest !== filePreviewRequest) return;
          const pre = makeElement('pre', 'boards-preview-text');
          pre.textContent = text;
          pre.style.cssText = 'box-sizing:border-box;width:100%;height:100%;margin:0;padding:20px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;color:#e7e9f5;background:#1b1e34;font:inherit;text-align:left;';
          els.filePreviewContent.replaceChildren(pre);
        }
      } catch (error) {
        if (previewRequest === filePreviewRequest) showFilePreviewError(errorMessage(error, 'No se pudo conectar con el servidor.'));
      }
      return;
    } else {
      const fallback = makeElement('div', 'boards-preview-unavailable');
      const icon = makeElement('i', `fa-solid ${type.icon} boards-preview-unavailable-icon`);
      icon.setAttribute('aria-hidden', 'true');
      fallback.append(icon, makeElement('h4', '', 'Este formato no se puede previsualizar aquí'));
      fallback.append(makeElement('p', '', 'Descarga el archivo para abrirlo con una aplicación compatible.'));
      const download = makeElement('a', 'boards-button-primary boards-preview-download', 'Descargar archivo');
      download.href = fileEndpoint(file);
      download.setAttribute('download', '');
      fallback.append(download);
      els.filePreviewContent.append(fallback);
    }
    openDialog(els.filePreviewDialog);
  }

  function setDetailStatus(id, message, error = false) {
    const status = document.getElementById(id);
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('is-error', error);
    status.hidden = !message;
  }

  function renderComments() {
    els.itemCommentsList.replaceChildren();
    if (!state.itemComments.length) {
      els.itemCommentsList.append(makeElement('p', 'boards-detail-empty', 'Todavía no hay comentarios.'));
    } else {
      state.itemComments.forEach((comment) => {
        const article = makeElement('article', 'boards-comment-entry');
        const meta = makeElement('div', 'boards-detail-meta', `${comment.created_by_display_name || 'Usuario desconocido'} · ${displayDateTime(comment.created_at)}`);
        const body = makeElement('p', 'boards-comment-body', comment.body || '');
        article.append(meta, body);
        els.itemCommentsList.append(article);
      });
    }
    const form = els.itemCommentForm;
    form.hidden = !canEditBoard();
    form.querySelectorAll('textarea, button').forEach((control) => { control.disabled = !canEditBoard(); });
    setDetailStatus('itemCommentStatus', `${state.itemComments.length} ${state.itemComments.length === 1 ? 'comentario' : 'comentarios'}`);
  }

  function renderActivity() {
    els.itemActivityList.replaceChildren();
    if (!state.itemActivity.length) {
      els.itemActivityList.append(makeElement('p', 'boards-detail-empty', 'Todavía no hay actividad para este elemento.'));
    } else {
      const labels = {
        'comment.created': 'Agregó un comentario', 'item.name_changed': 'Cambió el nombre', 'item.moved': 'Movió el elemento',
        'file.uploaded': 'Subió una versión de archivo', 'relation.created': 'Agregó una relación', 'relation.deleted': 'Quitó una relación',
        'dependency.created': 'Agregó una dependencia', 'dependency.deleted': 'Quitó una dependencia',
        'subtask.parent_changed': 'Cambió el elemento principal'
      };
      state.itemActivity.forEach((event) => {
        const entry = makeElement('article', 'boards-activity-entry');
        entry.append(makeElement('strong', '', labels[event.event_type] || String(event.event_type || 'Actualización')));
        const payload = event.payload && typeof event.payload === 'object' ? stringifyValue(event.payload) : '';
        if (payload && payload !== '{}') entry.append(makeElement('p', 'boards-activity-payload', payload));
        const actorName = event.actor_display_name || (event.actor_user_id ? 'Usuario desconocido' : 'Sistema');
        entry.append(makeElement('div', 'boards-detail-meta', `${actorName} · ${displayDateTime(event.created_at)}`));
        els.itemActivityList.append(entry);
      });
    }
    setDetailStatus('itemActivityStatus', `${state.itemActivity.length} ${state.itemActivity.length === 1 ? 'evento' : 'eventos'}`);
  }

  function renderItemFiles() {
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    els.itemFilesList.replaceChildren();
    if (!item) return;
    const files = filesForItem(item);
    if (!files.length) els.itemFilesList.append(makeElement('p', 'boards-detail-empty', 'No hay archivos adjuntos todavía.'));
    files.forEach((file) => {
      const row = makeElement('article', 'boards-file-entry');
      const heading = makeElement('div', 'boards-file-entry-heading');
      heading.append(makeElement('strong', '', file.name || 'Archivo sin nombre'));
      const versions = state.fileVersions[String(file.file_id)];
      const currentVersion = Array.isArray(versions) ? versions.find((version) => Boolean(Number(version.is_current))) : null;
      const versionLabel = currentVersion?.version_number ? `v${currentVersion.version_number}` : (file.version_number ? `v${file.version_number}` : 'Versión actual');
      heading.append(makeElement('span', 'boards-file-version-label', versionLabel));
      const actions = makeElement('div', 'boards-detail-actions');
      const referenceScanStatus = String(file.scan_status || '').toLowerCase();
      const provisionalStatus = ['pending', 'quarantined', 'failed', 'unscanned', 'clean'].includes(referenceScanStatus) ? referenceScanStatus : 'unknown';
      const scanStatus = String(currentVersion?.scan_status || provisionalStatus || 'unknown').toLowerCase();
      const scanLabels = { clean: 'Disponible', unscanned: 'Sin análisis antivirus', pending: 'Analizando', quarantined: 'Amenaza detectada', failed: 'No se pudo analizar', unknown: 'Estado sin verificar' };
      heading.append(makeElement('span', `boards-file-scan-status is-${scanStatus}`, scanLabels[scanStatus] || scanStatus));
      if (['clean', 'unscanned'].includes(scanStatus)) {
        const preview = makeElement('button', 'boards-text-button', 'Vista previa');
        preview.type = 'button';
        preview.addEventListener('click', () => openFilePreview({ ...file, version_id: currentVersion?.id || file.version_id }, item));
        actions.append(preview);
        const download = makeElement('a', 'boards-text-button', 'Descargar');
        download.href = fileEndpoint(file);
        download.setAttribute('download', '');
        actions.append(download);
      } else {
        const blocked = makeElement('span', 'boards-file-download-blocked', 'Descarga bloqueada');
        blocked.title = 'La descarga se bloquea cuando el análisis detecta una amenaza o no puede completarse.';
        actions.append(blocked);
      }
      const history = makeElement('button', 'boards-text-button', state.fileHistoryVisible[String(file.file_id)] ? 'Ocultar versiones' : 'Historial');
      history.type = 'button';
      history.dataset.fileHistory = String(file.file_id);
      actions.append(history);
      if (canEditBoard()) {
        const newVersion = makeElement('button', 'boards-text-button', 'Nueva versión');
        newVersion.type = 'button';
        newVersion.dataset.fileTarget = String(file.file_id);
        actions.append(newVersion);
      }
      row.append(heading, makeElement('div', 'boards-detail-meta', `${file.content_type || 'Tipo desconocido'} · ${formatBytes(file.byte_size)}`), actions);
      if (state.fileHistoryVisible[String(file.file_id)]) {
        const versionList = makeElement('div', 'boards-file-history');
        if (state.fileHistoryLoading[String(file.file_id)]) {
          versionList.append(makeElement('p', 'boards-detail-empty', 'Cargando versiones…'));
        } else if (!Array.isArray(versions)) {
          versionList.append(makeElement('p', 'boards-detail-empty', state.fileHistoryErrors[String(file.file_id)] || 'No se pudo cargar el historial. Vuelve a abrirlo para intentar de nuevo.'));
        } else if (!versions.length) {
          versionList.append(makeElement('p', 'boards-detail-empty', 'No hay versiones registradas.'));
        } else {
          versions.forEach((entry) => {
            const line = makeElement('div', 'boards-file-history-row');
            line.append(makeElement('span', '', `v${entry.version_number} · ${entry.original_name || file.name || 'Archivo'} · ${formatBytes(entry.byte_size)} · ${entry.scan_status || 'sin estado'}`));
            let versionDownload;
            if (['clean', 'unscanned'].includes(String(entry.scan_status || '').toLowerCase())) {
              versionDownload = makeElement('button', 'boards-text-button', 'Vista previa');
              versionDownload.type = 'button';
              versionDownload.addEventListener('click', () => openFilePreview({
                ...file,
                version_id: entry.id,
                name: entry.original_name || file.name,
                content_type: entry.content_type || file.content_type
              }, item));
              line.append(versionDownload);
              versionDownload = makeElement('a', 'boards-text-button', 'Descargar');
              const query = new URLSearchParams({ version_id: String(entry.id) });
              versionDownload.href = `${apiRoot}/download_file/${encodeURIComponent(file.file_id)}?${query.toString()}`;
              versionDownload.setAttribute('download', '');
            } else {
              versionDownload = makeElement('span', 'boards-file-download-blocked', 'Descarga bloqueada');
            }
            line.append(versionDownload);
            versionList.append(line);
          });
        }
        row.append(versionList);
      }
      els.itemFilesList.append(row);
    });
    setDetailStatus('itemFilesStatus', files.length ? `${files.length} ${files.length === 1 ? 'archivo' : 'archivos'}` : 'La lista se toma de las referencias guardadas en las celdas de archivo.');
  }

  function formatBytes(bytes) {
    const size = Number(bytes);
    if (!Number.isFinite(size) || size < 0) return 'Tamaño desconocido';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
  }

  function renderFileControls() {
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    const fileColumns = state.columns.filter((column) => columnType(column) === 'file');
    const previousColumn = els.itemFileColumn.value;
    els.itemFileColumn.replaceChildren();
    fileColumns.forEach((column) => {
      const option = makeElement('option', '', column.name || 'Archivo');
      option.value = String(column.id);
      els.itemFileColumn.append(option);
    });
    if (fileColumns.some((column) => String(column.id) === previousColumn)) els.itemFileColumn.value = previousColumn;
    els.itemFileForm.hidden = !canEditBoard();
    els.itemFileColumn.disabled = !canEditBoard() || fileColumns.length === 0;
    els.itemFileTarget.disabled = !canEditBoard() || fileColumns.length === 0;
    els.itemFileInput.disabled = !canEditBoard() || fileColumns.length === 0;
    els.itemFileForm.querySelector('[type="submit"]').disabled = !canEditBoard() || fileColumns.length === 0;
    els.itemFileForm.querySelector('.boards-field-help').textContent = fileColumns.length
      ? 'Máximo 100 MB. Las imágenes se convierten a WebP. Los archivos quedan disponibles inmediatamente y no se analizan con antivirus.'
      : 'Agrega una columna de tipo Archivo para adjuntar y consultar archivos desde el tablero.';
    const selectedColumnId = els.itemFileColumn.value || String(fileColumns[0]?.id || '');
    const existing = item ? filesForItem(item).filter((file) => String(file.column_id || '') === selectedColumnId) : [];
    const selectedFile = els.itemFileTarget.value;
    els.itemFileTarget.replaceChildren(makeElement('option', '', 'Crear archivo nuevo'));
    els.itemFileTarget.firstElementChild.value = '';
    existing.forEach((file) => {
      const option = makeElement('option', '', `${file.name || 'Archivo'} · v${file.version_number || 'actual'}`);
      option.value = String(file.file_id);
      els.itemFileTarget.append(option);
    });
    if (existing.some((file) => String(file.file_id) === selectedFile)) els.itemFileTarget.value = selectedFile;
  }

  function renderRelationTargetBoards() {
    const previous = els.relationTargetBoard.value || String(state.board.id);
    els.relationTargetBoard.replaceChildren();
    state.boards.forEach((board) => {
      const option = makeElement('option', '', board.name || 'Tablero');
      option.value = String(board.id);
      els.relationTargetBoard.append(option);
    });
    if (!state.boards.some((board) => String(board.id) === String(state.board.id))) {
      const option = makeElement('option', '', state.board.name || 'Tablero actual');
      option.value = String(state.board.id);
      els.relationTargetBoard.append(option);
    }
    els.relationTargetBoard.value = state.boards.some((board) => String(board.id) === previous) || String(state.board.id) === previous ? previous : String(state.board.id);
    els.relationTargetBoard.disabled = !canEditBoard();
  }

  async function loadRelationTargetItems(boardId) {
    els.relationTargetItem.replaceChildren();
    els.relationTargetItem.disabled = true;
    let items = [];
    try {
      if (String(boardId) === String(state.board.id)) items = state.items;
      else {
        const result = await request(`/board/${encodeURIComponent(boardId)}`);
        items = Array.isArray(result.data?.items) ? result.data.items : [];
      }
      state.targetBoardItems = items;
      const sourceId = state.detailItemId;
      const placeholder = makeElement('option', '', items.length ? 'Selecciona un elemento' : 'No hay elementos disponibles');
      placeholder.value = '';
      els.relationTargetItem.append(placeholder);
      items.forEach((item) => {
        if (String(boardId) === String(state.board.id) && String(item.id) === sourceId) return;
        const option = makeElement('option', '', item.name || `Elemento #${item.id}`);
        option.value = String(item.id);
        els.relationTargetItem.append(option);
      });
      els.relationTargetItem.disabled = !canEditBoard() || els.relationTargetItem.options.length < 2;
    } catch (error) {
      const message = errorMessage(error, 'No se pudieron cargar los elementos del tablero relacionado.');
      els.relationTargetItem.append(makeElement('option', '', message));
      setNotice(message, 'error');
    }
  }

  function renderItemRelations() {
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    els.itemRelationsList.replaceChildren();
    els.itemDependenciesList.replaceChildren();
    if (!item) return;
    if (!state.itemRelations.length) els.itemRelationsList.append(makeElement('p', 'boards-detail-empty', 'No hay relaciones todavía.'));
    state.itemRelations.forEach((relation) => {
      const row = makeElement('article', 'boards-relation-entry');
      const targetBoard = state.boards.find((board) => String(board.id) === String(relation.target_board_id));
      const targetItem = String(relation.target_board_id) === String(state.board.id)
        ? state.items.find((candidate) => String(candidate.id) === String(relation.target_item_id))
        : null;
      row.append(makeElement('span', '', `${relation.relation_type || 'Relacionado'} · ${targetBoard?.name || `Tablero #${relation.target_board_id}`} · ${targetItem?.name || `Elemento #${relation.target_item_id}`}`));
      if (canEditBoard()) {
        const remove = makeElement('button', 'boards-text-button is-danger', 'Quitar');
        remove.type = 'button';
        remove.dataset.removeRelation = String(relation.id);
        row.append(remove);
      }
      els.itemRelationsList.append(row);
    });
    if (!state.itemDependencies.length) els.itemDependenciesList.append(makeElement('p', 'boards-detail-empty', 'No hay dependencias todavía.'));
    state.itemDependencies.forEach((dependency) => {
      const predecessor = state.items.find((candidate) => String(candidate.id) === String(dependency.predecessor_item_id));
      const successor = state.items.find((candidate) => String(candidate.id) === String(dependency.successor_item_id));
      const row = makeElement('article', 'boards-relation-entry');
      row.append(makeElement('span', '', `${predecessor?.name || `#${dependency.predecessor_item_id}`} → ${successor?.name || `#${dependency.successor_item_id}`} · ${dependency.dependency_type || 'fin a inicio'}`));
      if (canEditBoard()) {
        const remove = makeElement('button', 'boards-text-button is-danger', 'Quitar');
        remove.type = 'button';
        remove.dataset.removeDependency = String(dependency.id);
        row.append(remove);
      }
      els.itemDependenciesList.append(row);
    });
    const editable = canEditBoard();
    els.itemRelationForm.hidden = !editable;
    els.itemRelationForm.querySelectorAll('select, button').forEach((control) => { control.disabled = !editable; });
    els.itemDependencyForm.hidden = !editable;
    els.itemDependencyForm.querySelectorAll('select, input, button').forEach((control) => { control.disabled = !editable; });
    renderRelationTargetBoards();
    const previousTarget = els.dependencyTargetItem.value;
    els.dependencyTargetItem.replaceChildren(makeElement('option', '', state.items.length > 1 ? 'Selecciona un elemento' : 'No hay otros elementos'));
    els.dependencyTargetItem.firstElementChild.value = '';
    state.items.forEach((candidate) => {
      if (String(candidate.id) === state.detailItemId) return;
      const option = makeElement('option', '', candidate.name || `Elemento #${candidate.id}`);
      option.value = String(candidate.id);
      els.dependencyTargetItem.append(option);
    });
    if (state.items.some((candidate) => String(candidate.id) === previousTarget)) els.dependencyTargetItem.value = previousTarget;
    els.dependencyTargetItem.disabled = !editable || els.dependencyTargetItem.options.length < 2;
    const parentId = item.parent_item_id == null ? '' : String(item.parent_item_id);
    els.itemParentSelect.replaceChildren(makeElement('option', '', 'Sin elemento principal'));
    els.itemParentSelect.firstElementChild.value = '';
    state.items.forEach((candidate) => {
      if (String(candidate.id) === state.detailItemId) return;
      const option = makeElement('option', '', candidate.name || `Elemento #${candidate.id}`);
      option.value = String(candidate.id);
      els.itemParentSelect.append(option);
    });
    els.itemParentSelect.value = parentId;
    els.itemParentSelect.disabled = !editable;
    document.getElementById('saveItemParent').hidden = !editable;
    const children = state.items.filter((candidate) => String(candidate.parent_item_id ?? '') === state.detailItemId);
    if (!children.length) els.itemSubtasksList.append(makeElement('p', 'boards-detail-empty', 'No hay subelementos todavía.'));
    else children.forEach((child) => els.itemSubtasksList.append(makeElement('div', 'boards-relation-entry', child.name || `Elemento #${child.id}`)));
    setDetailStatus('itemRelationsStatus', `${state.itemRelations.length} relaciones · ${state.itemDependencies.length} dependencias`);
  }

  function renderItemDetails() {
    renderComments();
    renderItemFiles();
    renderFileControls();
    renderItemRelations();
    renderActivity();
  }

  async function openItemDetails(itemId) {
    const item = state.items.find((candidate) => String(candidate.id) === String(itemId));
    if (!item || !state.board) return;
    const requestId = ++state.activeDetailRequest;
    state.detailItemId = String(item.id);
    state.itemComments = [];
    state.itemActivity = [];
    state.itemRelations = [];
    state.itemDependencies = [];
    document.getElementById('itemDetailsTitle').textContent = item.name || 'Elemento sin nombre';
    document.getElementById('itemDetailsSubtitle').textContent = 'Comentarios, archivos, relaciones y actividad.';
    els.itemCommentsList.replaceChildren();
    els.itemFilesList.replaceChildren();
    els.itemRelationsList.replaceChildren();
    els.itemDependenciesList.replaceChildren();
    els.itemActivityList.replaceChildren();
    setDetailStatus('itemCommentStatus', 'Cargando comentarios…');
    setDetailStatus('itemFilesStatus', 'Leyendo referencias de archivo…');
    setDetailStatus('itemRelationsStatus', 'Cargando relaciones y dependencias…');
    setDetailStatus('itemActivityStatus', 'Cargando actividad…');
    renderFileControls();
    renderRelationTargetBoards();
    els.relationTargetBoard.value = String(state.board.id);
    loadRelationTargetItems(String(state.board.id));
    openDialog(els.itemDetailsDialog);
    const params = new URLSearchParams({ board_id: String(state.board.id), item_id: String(item.id) });
    const [comments, activity, relations, dependencies] = await Promise.allSettled([
      request(`/comments?${params.toString()}`),
      request(`/activity?${params.toString()}`),
      request(`/relations?${params.toString()}`),
      request(`/dependencies?${params.toString()}`)
    ]);
    if (requestId !== state.activeDetailRequest) return;
    if (comments.status === 'fulfilled') {
      state.itemComments = Array.isArray(comments.value.data) ? comments.value.data : [];
      renderComments();
    } else setDetailStatus('itemCommentStatus', errorMessage(comments.reason, 'No se pudieron cargar los comentarios.'), true);
    if (activity.status === 'fulfilled') {
      state.itemActivity = Array.isArray(activity.value.data) ? activity.value.data : [];
      renderActivity();
    } else setDetailStatus('itemActivityStatus', errorMessage(activity.reason, 'No se pudo cargar la actividad.'), true);
    if (relations.status === 'fulfilled') state.itemRelations = Array.isArray(relations.value.data) ? relations.value.data : [];
    if (dependencies.status === 'fulfilled') state.itemDependencies = Array.isArray(dependencies.value.data) ? dependencies.value.data : [];
    renderItemRelations();
    if (relations.status === 'rejected') setDetailStatus('itemRelationsStatus', errorMessage(relations.reason, 'No se pudieron cargar las relaciones.'), true);
    else if (dependencies.status === 'rejected') setDetailStatus('itemRelationsStatus', errorMessage(dependencies.reason, 'No se pudieron cargar las dependencias.'), true);
    renderItemFiles();
    await loadAllFileHistory(item);
  }

  async function refreshItemDetails() {
    if (state.detailItemId) await openItemDetails(state.detailItemId);
  }

  async function loadFileHistory(fileId) {
    const key = String(fileId);
    state.fileHistoryLoading[key] = true;
    delete state.fileHistoryErrors[key];
    renderItemFiles();
    try {
      const result = await request(`/file_versions/${encodeURIComponent(fileId)}`);
      state.fileVersions[key] = Array.isArray(result.data) ? result.data : [];
    } catch (error) {
      state.fileHistoryErrors[key] = errorMessage(error, 'No se pudo cargar el historial del archivo.');
      setNotice(errorMessage(error, 'No se pudo cargar el historial del archivo.'), 'error');
    } finally {
      state.fileHistoryLoading[key] = false;
      renderItemFiles();
    }
  }

  async function loadAllFileHistory(item) {
    const files = filesForItem(item);
    files.forEach((file) => { state.fileHistoryLoading[String(file.file_id)] = true; });
    renderItemFiles();
    await Promise.allSettled(files.map(async (file) => {
      const key = String(file.file_id);
      delete state.fileHistoryErrors[key];
      try {
        const result = await request(`/file_versions/${encodeURIComponent(file.file_id)}`);
        state.fileVersions[key] = Array.isArray(result.data) ? result.data : [];
      } catch (error) {
        state.fileHistoryErrors[key] = errorMessage(error, 'No se pudo verificar el archivo.');
      } finally {
        state.fileHistoryLoading[key] = false;
      }
    }));
    if (String(item.id) === state.detailItemId) renderItemFiles();
  }

  async function multipartPost(path, body) {
    const response = await fetch(`${apiRoot}${path}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body
    });
    let result;
    try { result = await response.json(); }
    catch (error) { throw new Error(response.ok ? 'El servidor devolvió una respuesta inesperada.' : `Error del servidor (HTTP ${response.status}).`); }
    if (!response.ok || result.success === false) {
      const requestError = new Error(result.error?.message || result.message || `Error del servidor (HTTP ${response.status}).`);
      requestError.status = response.status;
      requestError.code = result.error?.code || result.code || '';
      throw requestError;
    }
    return result;
  }

  async function submitItemComment(event) {
    event.preventDefault();
    if (!canEditBoard()) return;
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    const textarea = document.getElementById('itemCommentBody');
    const body = textarea.value.trim();
    if (!item || !body) return;
    const button = els.itemCommentForm.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Publicando…';
    try {
      const result = await post('/add_comment', { board_id: state.board.id, item_id: item.id, body });
      if (result.data) state.itemComments.push(result.data);
      textarea.value = '';
      renderComments();
      const params = new URLSearchParams({ board_id: String(state.board.id), item_id: String(item.id) });
      const activity = await request(`/activity?${params.toString()}`);
      state.itemActivity = Array.isArray(activity.data) ? activity.data : [];
      renderActivity();
      setNotice('Comentario agregado.', 'success');
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo agregar el comentario.'), 'error');
    } finally {
      button.disabled = false;
      button.textContent = 'Comentar';
    }
  }

  async function uploadFileToItem(item, column, file, targetFileId = '') {
    if (!canEditBoard()) throw new Error('No tienes permiso para adjuntar archivos.');
    if (!item || !column || columnType(column) !== 'file' || !file) throw new Error('Selecciona un archivo y una columna válida.');
    const validationMessage = fileUploadValidationMessage(file);
    if (validationMessage) throw new Error(validationMessage);
    const payload = new FormData();
    payload.set('csrf_token', csrfToken);
    payload.set('board_id', String(state.board.id));
    payload.set('item_id', String(item.id));
    payload.set('column_id', String(column.id));
    payload.set('file', file);
    if (targetFileId) payload.set('file_id', targetFileId);
    const result = await multipartPost('/upload_file', payload);
    const uploaded = result.data || {};
    if (!uploaded.id) throw new Error('El servidor no devolvió el identificador del archivo.');
    const reference = {
      file_id: Number(uploaded.id), column_id: Number(column.id), name: uploaded.name || file.name,
      version_id: Number(uploaded.version_id), version_number: Number(uploaded.version_number),
      content_type: uploaded.content_type || file.type, byte_size: Number(uploaded.byte_size || file.size),
      scan_status: uploaded.scan_status || 'unscanned'
    };
    const cellValue = parseStoredValue(getCellValue(item, column));
    const existing = storedFilesForItem(item).filter((entry) => String(entry.column_id) === String(column.id));
    const byId = new Map(existing.map((entry) => [String(entry.file_id), entry]));
    byId.set(String(reference.file_id), reference);
    const storedValue = { ...(cellValue && typeof cellValue === 'object' && !Array.isArray(cellValue) ? cellValue : {}), files: Array.from(byId.values()) };
    try {
      const linked = await post('/update_cell', {
        board_id: state.board.id, item_id: item.id, column_id: column.id, value: storedValue, version: item.version
      });
      item.version = linked.data?.version ?? item.version;
      if (!item.cells) item.cells = {};
      item.cells[String(column.id)] = storedValue;
    } catch (linkError) {
      throw new Error(`El archivo se cargó, pero no se confirmó su vínculo con la celda: ${errorMessage(linkError)}. Recarga el tablero antes de volver a intentarlo.`);
    }
    state.fileVersions[String(reference.file_id)] = undefined;
    return reference;
  }

  function fileUploadValidationMessage(file) {
    if (!(file instanceof File)) return 'Selecciona un archivo válido.';
    if (file.size <= 0) return 'El archivo está vacío.';
    if (file.size > 100 * 1024 * 1024) return 'El archivo supera el límite de 100 MB.';
    const extension = String(file.name.split('.').pop() || '').toLowerCase();
    const allowedExtensions = new Set(['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);
    if (!allowedExtensions.has(extension)) return 'Tipo de archivo no permitido. Usa PDF, imagen, texto, CSV u Office.';
    return '';
  }

  async function submitItemFile(event) {
    event.preventDefault();
    if (!canEditBoard()) return;
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    const file = els.itemFileInput.files?.[0];
    const columnId = els.itemFileColumn.value;
    if (!item || !file || !columnId) return;
    const column = state.columns.find((candidate) => String(candidate.id) === columnId && columnType(candidate) === 'file');
    if (!column) return;
    const targetFileId = els.itemFileTarget.value;
    const button = els.itemFileForm.querySelector('[type="submit"]');
    button.disabled = true;
    button.textContent = 'Subiendo…';
    try {
      const reference = await uploadFileToItem(item, column, file, targetFileId);
      els.itemFileForm.reset();
      renderFileControls();
      renderItemFiles();
      await loadFileHistory(reference.file_id);
      setNotice('Archivo subido y vinculado al elemento.', 'success');
      const params = new URLSearchParams({ board_id: String(state.board.id), item_id: String(item.id) });
      const activity = await request(`/activity?${params.toString()}`);
      state.itemActivity = Array.isArray(activity.data) ? activity.data : [];
      renderActivity();
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo subir el archivo.'), 'error');
    } finally {
      button.disabled = !canEditBoard() || !els.itemFileColumn.value;
      button.textContent = 'Subir archivo';
    }
  }

  async function addItemRelation(event) {
    event.preventDefault();
    if (!canEditBoard()) return;
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    const targetItemId = els.relationTargetItem.value;
    if (!item || !targetItemId) return;
    try {
      await post('/add_relation', {
        board_id: state.board.id, source_item_id: item.id,
        target_board_id: els.relationTargetBoard.value, target_item_id: targetItemId,
        relation_type: document.getElementById('relationType').value
      });
      await refreshItemDetails();
      setNotice('Relación agregada.', 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo agregar la relación.'), 'error'); }
  }

  async function addItemDependency(event) {
    event.preventDefault();
    if (!canEditBoard()) return;
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    const otherId = els.dependencyTargetItem.value;
    if (!item || !otherId) return;
    const depends = document.getElementById('dependencyDirection').value === 'depends';
    const payload = {
      board_id: state.board.id,
      predecessor_item_id: depends ? otherId : item.id,
      successor_item_id: depends ? item.id : otherId,
      dependency_type: document.getElementById('dependencyType').value,
      lag_minutes: Number(document.getElementById('dependencyLag').value || 0)
    };
    try {
      await post('/add_dependency', payload);
      await refreshItemDetails();
      setNotice('Dependencia agregada.', 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo agregar la dependencia.'), 'error'); }
  }

  async function saveItemParent() {
    if (!canEditBoard()) return;
    const item = state.items.find((candidate) => String(candidate.id) === state.detailItemId);
    if (!item) return;
    const parentId = els.itemParentSelect.value || null;
    if (String(item.parent_item_id ?? '') === String(parentId ?? '')) return;
    const button = document.getElementById('saveItemParent');
    button.disabled = true;
    try {
      const result = await post('/set_subtask', {
        board_id: state.board.id, item_id: item.id, parent_item_id: parentId, version: item.version
      });
      item.parent_item_id = result.data?.parent_item_id ?? null;
      item.version = result.data?.version ?? item.version;
      renderActiveView();
      await refreshItemDetails();
      setNotice('Jerarquía de subelementos actualizada.', 'success');
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo cambiar el elemento principal.'), 'error');
      if (error.status === 409 || error.code === 'conflict') await loadBoard(state.board.id, true);
    } finally { button.disabled = !canEditBoard(); }
  }

  async function removeRelation(relationId) {
    try {
      await post('/remove_relation', { board_id: state.board.id, relation_id: relationId });
      await refreshItemDetails();
      setNotice('Relación eliminada.', 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo eliminar la relación.'), 'error'); }
  }

  async function removeDependency(dependencyId) {
    try {
      await post('/remove_dependency', { board_id: state.board.id, dependency_id: dependencyId });
      await refreshItemDetails();
      setNotice('Dependencia eliminada.', 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo eliminar la dependencia.'), 'error'); }
  }

  function makeField(labelText, control, id) {
    const label = makeElement('label', 'boards-automation-field', labelText);
    if (id) label.htmlFor = id;
    label.append(control);
    return label;
  }

  function addSelectOptions(select, values, selectedValue = '') {
    values.forEach((entry) => {
      const option = makeElement('option', '', entry.label);
      option.value = String(entry.value);
      select.append(option);
    });
    if (selectedValue && Array.from(select.options).some((option) => option.value === String(selectedValue))) select.value = String(selectedValue);
  }

  function renderAutomationFilterFields() {
    const select = els.automationFilterField;
    const previous = select.value;
    select.replaceChildren();
    const groupOption = makeElement('option', '', 'Grupo');
    groupOption.value = 'group_id';
    select.append(groupOption);
    state.columns.forEach((column) => {
      const option = makeElement('option', '', column.name || 'Columna');
      option.value = String(column.id);
      select.append(option);
    });
    if (Array.from(select.options).some((option) => option.value === previous)) select.value = previous;
    const container = els.automationFilterValueFields;
    container.replaceChildren();
    if (select.value === 'group_id') {
      const group = document.createElement('select');
      group.className = 'form-select';
      group.id = 'automationFilterGroup';
      addSelectOptions(group, state.groups.map((entry) => ({ value: entry.id, label: entry.name || 'Grupo' })));
      container.append(makeField('Es igual a', group, group.id));
      return;
    }
    const column = state.columns.find((entry) => String(entry.id) === select.value);
    if (!column) {
      container.append(makeElement('p', 'boards-detail-empty', 'Agrega una columna o un grupo para definir una condición.'));
      return;
    }
    const operator = document.createElement('select');
    operator.className = 'form-select';
    operator.id = 'automationFilterOperator';
    addSelectOptions(operator, [
      ['equals', 'Es igual a'], ['not_equals', 'No es igual a'], ['contains', 'Contiene'],
      ['is_empty', 'Está vacío'], ['not_empty', 'No está vacío'], ['before', 'Es anterior a'],
      ['after', 'Es posterior a'], ['greater_than', 'Es mayor que'], ['less_than', 'Es menor que']
    ].map(([value, label]) => ({ value, label })));
    const valueField = document.createElement('div');
    valueField.id = 'automationFilterValue';
    const renderValue = () => {
      valueField.replaceChildren();
      if (['is_empty', 'not_empty'].includes(operator.value)) return;
      const inputType = ['date'].includes(columnType(column)) ? 'date' : (['numbers', 'progress', 'rating', 'item_id'].includes(columnType(column)) ? 'number' : 'text');
      let control;
      const choices = ['status', 'dropdown'].includes(columnType(column)) ? parseOptions(column.options) : [];
      if (choices.length) {
        control = document.createElement('select');
        control.className = 'form-select';
        addSelectOptions(control, choices);
      } else {
        control = document.createElement('input');
        control.type = inputType;
        control.className = 'form-control';
        if (inputType === 'number') control.step = 'any';
      }
      control.id = 'automationFilterValueInput';
      valueField.append(makeField('Valor', control, control.id));
    };
    operator.addEventListener('change', renderValue);
    renderValue();
    container.append(makeField('Condición', operator, operator.id), valueField);
  }

  function renderAutomationActionFields() {
    const type = els.automationActionType.value;
    const container = els.automationActionFields;
    container.replaceChildren();
    if (type === 'reminder') {
      const message = document.createElement('textarea');
      message.className = 'form-control';
      message.id = 'automationReminderMessage';
      message.rows = 2;
      message.maxLength = 500;
      message.required = true;
      message.placeholder = 'Mensaje para el recordatorio';
      container.append(makeField('Mensaje', message, message.id));
      return;
    }
    const allowed = type === 'set_status'
      ? state.columns.filter((column) => ['status', 'dropdown'].includes(columnType(column)))
      : state.columns.filter((column) => columnType(column) === 'date');
    if (!allowed.length) {
      container.append(makeElement('p', 'boards-detail-empty', type === 'set_status'
        ? 'Agrega una columna Estado o Lista desplegable para usar esta acción.'
        : 'Agrega una columna Fecha para usar esta acción.'));
      return;
    }
    const columnSelect = document.createElement('select');
    columnSelect.className = 'form-select';
    columnSelect.id = 'automationActionColumn';
    addSelectOptions(columnSelect, allowed.map((column) => ({ value: column.id, label: column.name || 'Columna' })));
    container.append(makeField('Columna', columnSelect, columnSelect.id));
    if (type === 'set_status') {
      const valueWrap = makeElement('div', '');
      const renderValues = () => {
        valueWrap.replaceChildren();
        const column = allowed.find((entry) => String(entry.id) === columnSelect.value) || allowed[0];
        const options = parseOptions(column.options);
        if (!options.length) {
          const input = document.createElement('input');
          input.className = 'form-control';
          input.id = 'automationActionValue';
          input.required = true;
          input.placeholder = 'Nuevo estado';
          valueWrap.append(makeField('Nuevo estado', input, input.id));
          return;
        }
        const select = document.createElement('select');
        select.className = 'form-select';
        select.id = 'automationActionValue';
        select.required = true;
        addSelectOptions(select, options);
        valueWrap.append(makeField('Nuevo estado', select, select.id));
      };
      columnSelect.addEventListener('change', renderValues);
      renderValues();
      container.append(valueWrap);
    } else {
      const mode = document.createElement('select');
      mode.className = 'form-select';
      mode.id = 'automationDateMode';
      addSelectOptions(mode, [{ value: 'value', label: 'Fecha fija' }, { value: 'offset', label: 'Días desde hoy' }]);
      const valueWrap = makeElement('div', '');
      const renderDateValue = () => {
        valueWrap.replaceChildren();
        const control = document.createElement('input');
        control.className = 'form-control';
        control.id = 'automationActionDateValue';
        if (mode.value === 'offset') {
          control.type = 'number';
          control.min = '-3650';
          control.max = '3650';
          control.step = '1';
          control.value = '0';
          valueWrap.append(makeField('Desfase en días', control, control.id));
        } else {
          control.type = 'date';
          control.required = true;
          valueWrap.append(makeField('Fecha', control, control.id));
        }
      };
      mode.addEventListener('change', renderDateValue);
      renderDateValue();
      container.append(makeField('Asignar', mode, mode.id), valueWrap);
    }
  }

  function buildAutomationDefinition() {
    const triggerType = els.automationTrigger.value;
    const trigger = triggerType === 'schedule'
      ? { type: 'schedule', every_minutes: Number(document.getElementById('automationInterval').value) }
      : { type: 'manual' };
    let filter;
    if (els.automationFilterField.value === 'group_id') {
      const groupId = document.getElementById('automationFilterGroup')?.value;
      if (!groupId) throw new Error('Selecciona un grupo para la condición.');
      filter = { group_id: Number(groupId) };
    } else {
      const columnId = els.automationFilterField.value;
      const operator = document.getElementById('automationFilterOperator')?.value;
      if (!columnId || !operator) throw new Error('Selecciona una columna y una condición.');
      filter = { column_id: Number(columnId), operator };
      if (!['is_empty', 'not_empty'].includes(operator)) {
        const valueControl = document.getElementById('automationFilterValueInput');
        const rawValue = valueControl?.value ?? '';
        if (!rawValue) throw new Error('Completa el valor de la condición.');
        filter.value = valueControl.type === 'number' ? Number(rawValue) : rawValue;
      }
    }
    const actionType = els.automationActionType.value;
    let action;
    if (actionType === 'reminder') {
      const message = document.getElementById('automationReminderMessage')?.value.trim();
      if (!message) throw new Error('Escribe el mensaje del recordatorio.');
      action = { type: 'reminder', message };
    } else {
      const columnId = document.getElementById('automationActionColumn')?.value;
      if (!columnId) throw new Error('Selecciona una columna para la acción.');
      if (actionType === 'set_status') {
        const control = document.getElementById('automationActionValue');
        if (!control || !control.value) throw new Error('Selecciona el valor de estado.');
        action = { type: 'set_status', column_id: Number(columnId), value: control.value };
      } else if (document.getElementById('automationDateMode')?.value === 'offset') {
        action = { type: 'set_date', column_id: Number(columnId), offset_days: Number(document.getElementById('automationActionDateValue')?.value || 0) };
      } else {
        const value = document.getElementById('automationActionDateValue')?.value;
        if (!value) throw new Error('Selecciona una fecha para la acción.');
        action = { type: 'set_date', column_id: Number(columnId), value };
      }
    }
    return { trigger, filters: [filter], actions: [action] };
  }

  function automationSummary(automation) {
    const definition = automation.definition && typeof automation.definition === 'object' ? automation.definition : {};
    const trigger = definition.trigger?.type === 'schedule' ? `Programada cada ${definition.trigger.every_minutes} min` : 'Ejecución manual';
    const filter = definition.filters?.[0] || {};
    const filterColumn = state.columns.find((column) => String(column.id) === String(filter.column_id));
    const filterLabel = filter.group_id
      ? `Grupo: ${state.groups.find((group) => String(group.id) === String(filter.group_id))?.name || `#${filter.group_id}`}`
      : `${filterColumn?.name || 'Condición'} ${filter.operator || ''}${filter.value !== undefined ? ` ${stringifyValue(filter.value)}` : ''}`;
    const action = definition.actions?.[0] || {};
    const actionColumn = state.columns.find((column) => String(column.id) === String(action.column_id));
    const actionLabel = action.type === 'reminder' ? `Recordatorio: ${action.message || ''}`
      : action.type === 'set_date' ? `Establece ${actionColumn?.name || 'fecha'}${action.offset_days !== undefined ? ` (${action.offset_days} días)` : ''}`
        : `Cambia ${actionColumn?.name || 'estado'} a ${stringifyValue(action.value)}`;
    return `${trigger} · Si ${filterLabel} · entonces ${actionLabel}`;
  }

  function renderAutomations() {
    els.automationList.replaceChildren();
    if (!state.automations.length) els.automationList.append(makeElement('p', 'boards-detail-empty', 'Este tablero todavía no tiene automatizaciones.'));
    state.automations.forEach((automation) => {
      const card = makeElement('article', 'boards-automation-card');
      const heading = makeElement('div', 'boards-automation-card-heading');
      heading.append(makeElement('strong', '', automation.name || 'Automatización'));
      const status = String(automation.status || 'draft');
      const statusLabels = { draft: 'Borrador', active: 'Activa', paused: 'Pausada', disabled: 'Deshabilitada' };
      heading.append(makeElement('span', `boards-automation-status is-${status}`, statusLabels[status] || status));
      card.append(heading, makeElement('p', 'boards-automation-summary', automationSummary(automation)));
      if (canManageAutomation()) {
        const actions = makeElement('div', 'boards-detail-actions');
        const toggle = makeElement('button', 'boards-text-button', status === 'active' ? 'Pausar' : 'Activar');
        toggle.type = 'button';
        toggle.dataset.automationStatus = String(automation.id);
        toggle.dataset.statusAction = status === 'active' ? 'pause' : 'activate';
        toggle.dataset.version = String(automation.version || '');
        const run = makeElement('button', 'boards-text-button', 'Ejecutar ahora');
        run.type = 'button';
        run.dataset.runAutomation = String(automation.id);
        run.disabled = status !== 'active';
        actions.append(toggle, run);
        card.append(actions);
      }
      els.automationList.append(card);
    });
    setDetailStatus('automationListStatus', `${state.automations.length} ${state.automations.length === 1 ? 'automatización' : 'automatizaciones'}`);
    els.automationEditorPanel.hidden = !canManageAutomation();
    els.automationPermissionsNote.hidden = canManageAutomation();
    els.automationPermissionsNote.textContent = canManageAutomation() ? '' : 'Solo el propietario o una persona diseñadora puede crear, activar o ejecutar automatizaciones.';
  }

  function renderAutomationRuns() {
    els.automationRunsList.replaceChildren();
    if (!state.automationRuns.length) els.automationRunsList.append(makeElement('p', 'boards-detail-empty', 'No hay ejecuciones registradas.'));
    const names = new Map(state.automations.map((automation) => [String(automation.id), automation.name || `Automatización #${automation.id}`]));
    state.automationRuns.forEach((run) => {
      const row = makeElement('article', 'boards-automation-run');
      row.append(makeElement('strong', '', names.get(String(run.automation_id)) || `Automatización #${run.automation_id}`));
      row.append(makeElement('span', `boards-automation-status is-${run.status || 'pending'}`, String(run.status || 'Pendiente')));
      row.append(makeElement('span', 'boards-detail-meta', `${displayDateTime(run.created_at)}${run.finished_at ? ` · finalizó ${displayDateTime(run.finished_at)}` : ''}`));
      if (run.error_message) row.append(makeElement('p', 'boards-automation-summary is-error', run.error_message));
      if (run.result) row.append(makeElement('p', 'boards-automation-summary', stringifyValue(run.result)));
      els.automationRunsList.append(row);
    });
    setDetailStatus('automationRunsStatus', `${state.automationRuns.length} ${state.automationRuns.length === 1 ? 'ejecución' : 'ejecuciones'}`);
  }

  async function loadAutomationData() {
    const requestId = ++state.automationRequest;
    setDetailStatus('automationListStatus', 'Cargando automatizaciones…');
    setDetailStatus('automationRunsStatus', 'Cargando historial…');
    const params = new URLSearchParams({ board_id: String(state.board.id) });
    const [automations, runs] = await Promise.allSettled([
      request(`/automations?${params.toString()}`), request(`/automation_runs?${params.toString()}`)
    ]);
    if (requestId !== state.automationRequest) return;
    if (automations.status === 'fulfilled') state.automations = Array.isArray(automations.value.data) ? automations.value.data : [];
    if (runs.status === 'fulfilled') state.automationRuns = Array.isArray(runs.value.data) ? runs.value.data : [];
    renderAutomations();
    renderAutomationRuns();
    if (automations.status === 'rejected') setDetailStatus('automationListStatus', errorMessage(automations.reason, 'No se pudieron cargar las automatizaciones.'), true);
    if (runs.status === 'rejected') setDetailStatus('automationRunsStatus', errorMessage(runs.reason, 'No se pudo cargar el historial.'), true);
  }

  async function updateAutomationStatus(automationId, action, version) {
    if (!canManageAutomation()) return;
    const endpoint = action === 'pause' ? '/pause_automation' : '/activate_automation';
    try {
      const result = await post(endpoint, { board_id: state.board.id, automation_id: automationId, version });
      const automation = state.automations.find((candidate) => String(candidate.id) === String(automationId));
      if (automation) {
        automation.status = result.data?.status || (action === 'pause' ? 'paused' : 'active');
        automation.version = result.data?.version ?? automation.version;
      }
      renderAutomations();
      setNotice(action === 'pause' ? 'Automatización pausada.' : 'Automatización activada.', 'success');
      await loadAutomationData();
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo cambiar el estado de la automatización.'), 'error');
      if (error.status === 409 || error.code === 'conflict') await loadAutomationData();
    }
  }

  async function runAutomation(automationId) {
    if (!canManageAutomation()) return;
    try {
      await post('/run_automation', { board_id: state.board.id, automation_id: automationId });
      setNotice('Ejecución agregada a la cola.', 'success');
      await loadAutomationData();
    } catch (error) { setNotice(errorMessage(error, 'No se pudo ejecutar la automatización.'), 'error'); }
  }

  async function createAutomation(event) {
    event.preventDefault();
    if (!canManageAutomation()) return;
    const form = els.automationForm;
    const button = form.querySelector('[type="submit"]');
    try {
      const definition = buildAutomationDefinition();
      button.disabled = true;
      button.textContent = 'Creando…';
      const result = await post('/create_automation', {
        board_id: state.board.id,
        name: document.getElementById('automationName').value.trim(),
        definition
      });
      form.reset();
      els.automationTrigger.dispatchEvent(new Event('change'));
      els.automationActionType.dispatchEvent(new Event('change'));
      renderAutomationFilterFields();
      renderAutomationActionFields();
      await loadAutomationData();
      setNotice(`Automatización creada como borrador${result.data?.id ? ` (#${result.data.id})` : ''}.`, 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo crear la automatización.'), 'error'); }
    finally {
      button.disabled = false;
      button.textContent = 'Crear automatización';
    }
  }

  function itemMatchesSearch(item, query) {
    if (!query) return true;
    const searchData = [item.name, ...state.columns.map((column) => stringifyValue(getCellValue(item, column)))].join(' ').toLocaleLowerCase();
    return searchData.includes(query);
  }

  function compareFilter(item, filter) {
    const column = state.columns.find((candidate) => String(candidate.id) === String(filter.column_id));
    if (!column) return true;
    const value = getCellValue(item, column);
    const empty = value === null || value === undefined || value === '';
    if (filter.operator === 'is_empty') return empty;
    if (filter.operator === 'not_empty') return !empty;
    const expected = String(filter.value ?? '').trim();
    const actual = stringifyValue(value);
    const type = String(column.type || '').toLowerCase();
    if (['date', 'timeline'].includes(type) && filter.operator === 'equals') return dateOnly(value) === expected;
    if (filter.operator === 'contains') return actual.toLocaleLowerCase().includes(expected.toLocaleLowerCase());
    if (filter.operator === 'equals') return actual.toLocaleLowerCase() === expected.toLocaleLowerCase();
    if (filter.operator === 'not_equals') return actual.toLocaleLowerCase() !== expected.toLocaleLowerCase();
    if (filter.operator === 'greater_than' || filter.operator === 'less_than') {
      const actualNumber = Number(value);
      const expectedNumber = Number(expected);
      if (!Number.isFinite(actualNumber) || !Number.isFinite(expectedNumber)) return false;
      return filter.operator === 'greater_than' ? actualNumber > expectedNumber : actualNumber < expectedNumber;
    }
    if (filter.operator === 'before' || filter.operator === 'after') {
      const actualDate = dateOnly(value);
      if (!actualDate || !expected) return false;
      return filter.operator === 'before' ? actualDate < expected : actualDate > expected;
    }
    return true;
  }

  function getFilteredItems() {
    const query = els.boardSearch.value.trim().toLocaleLowerCase();
    return state.items.filter((item) => itemMatchesSearch(item, query) && state.filters.every((filter) => compareFilter(item, filter)));
  }

  function createGroupRow(group, itemCount, columnCount) {
    const row = document.createElement('tr');
    row.className = 'boards-group-row';
    const cell = document.createElement('td');
    cell.colSpan = columnCount + 3;
    const groupWrap = document.createElement('div');
    groupWrap.className = 'boards-group-heading';
    groupWrap.style.setProperty('--group-color', safeGroupColor(group.color));

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'boards-group-toggle';
    toggle.dataset.toggleGroup = String(group.id);
    const expanded = !state.collapsedGroups.has(String(group.id));
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    toggle.setAttribute('aria-label', `${expanded ? 'Contraer' : 'Expandir'} grupo ${group.name || ''}`);
    const chevron = document.createElement('i');
    chevron.className = `fa-solid ${expanded ? 'fa-chevron-down' : 'fa-chevron-right'}`;
    chevron.setAttribute('aria-hidden', 'true');
    const marker = document.createElement('span');
    marker.className = 'boards-group-marker';
    marker.setAttribute('aria-hidden', 'true');
    const name = document.createElement('strong');
    name.textContent = group.name || 'Grupo sin nombre';
    toggle.append(chevron, marker, name);
    groupWrap.append(toggle);

    const meta = document.createElement('div');
    meta.className = 'boards-group-meta';
    const count = document.createElement('span');
    count.className = 'boards-group-count';
    count.textContent = `${itemCount} ${itemCount === 1 ? 'elemento' : 'elementos'}`;
    meta.append(count);
    if (canEditBoard()) {
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'boards-add-in-group';
      add.dataset.addItemGroup = String(group.id);
      add.setAttribute('aria-label', `Agregar elemento al grupo ${group.name || ''}`);
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-plus';
      icon.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.textContent = 'Agregar';
      add.append(icon, label);
      meta.append(add);
    }
    groupWrap.append(meta);
    cell.append(groupWrap);
    row.append(cell);
    return row;
  }

  function createMoveEditor(item) {
    const select = document.createElement('select');
    select.className = 'boards-move-select';
    select.dataset.moveEditor = '1';
    select.dataset.itemId = String(item.id);
    select.dataset.currentGroup = String(item.group_id ?? '');
    select.setAttribute('aria-label', `Mover ${item.name || 'elemento'} a otro grupo`);
    const current = document.createElement('option');
    current.value = String(item.group_id ?? '');
    current.textContent = 'Mover…';
    select.append(current);
    state.groups.forEach((group) => {
      if (String(group.id) === String(item.group_id)) return;
      const option = document.createElement('option');
      option.value = String(group.id);
      option.textContent = `Mover a ${group.name || 'grupo'}`;
      select.append(option);
    });
    select.disabled = !canEditBoard() || state.groups.length < 2;
    return select;
  }

  function createDetailsButton(item) {
    const button = makeElement('button', 'boards-row-details');
    button.type = 'button';
    button.dataset.openItemDetails = String(item.id);
    button.setAttribute('aria-label', `Abrir comentarios y detalles de ${item.name || 'elemento'}`);
    button.title = 'Comentarios y detalles';
    const icon = makeElement('i', 'fa-regular fa-comment-dots');
    icon.setAttribute('aria-hidden', 'true');
    button.append(icon);
    return button;
  }

  function renderTable() {
    const query = els.boardSearch.value.trim().toLocaleLowerCase();
    const filteredItems = getFilteredItems();
    const columns = orderedColumns();
    const visibleCount = filteredItems.length;
    updateItemCount(visibleCount);
    els.boardTableContainer.replaceChildren();
    const table = document.createElement('table');
    table.className = 'boards-table';
    table.setAttribute('aria-label', `Elementos de ${state.board?.name || 'tablero'}`);

    const createColumnRow = (group, groupItems) => {
      const row = makeElement('tr', 'boards-group-columns');
      const selectHead = makeElement('th', 'boards-select-head');
      selectHead.scope = 'col';
      if (canEditBoard() && groupItems.length) {
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.dataset.selectGroup = String(group.id);
        checkbox.setAttribute('aria-label', `Seleccionar elementos del grupo ${group.name || 'sin nombre'}`);
        const selected = groupItems.filter((item) => state.selectedItemIds.has(String(item.id))).length;
        checkbox.checked = selected === groupItems.length;
        checkbox.indeterminate = selected > 0 && selected < groupItems.length;
        selectHead.append(checkbox);
      }
      row.append(selectHead);
      const itemHead = makeElement('th', 'boards-item-head', 'Elemento');
      itemHead.scope = 'col';
      row.append(itemHead);
      columns.forEach((column) => {
        const th = makeElement('th', '', column.name || 'Columna');
        th.scope = 'col';
        if (column.required) th.append(makeElement('span', 'boards-required-label', 'Requerido'));
        row.append(th);
      });
      const moveHead = makeElement('th', 'boards-move-head', 'Mover');
      moveHead.scope = 'col';
      row.append(moveHead);
      return row;
    };

    state.groups.forEach((group) => {
      const groupItems = filteredItems.filter((item) => String(item.group_id) === String(group.id));
      const tbody = document.createElement('tbody');
      tbody.className = 'boards-group-body';
      tbody.append(createGroupRow(group, groupItems.length, columns.length));
      if (state.collapsedGroups.has(String(group.id))) {
        table.append(tbody);
        return;
      }
      tbody.append(createColumnRow(group, groupItems));
      if (!groupItems.length) {
        if (query || state.filters.length || !canEditBoard()) {
          const emptyRow = makeElement('tr', 'boards-empty-group-row');
          const emptyCell = makeElement('td', '', query || state.filters.length ? 'No hay coincidencias en este grupo.' : 'Este grupo todavía no tiene elementos.');
          emptyCell.colSpan = columns.length + 3;
          emptyRow.append(emptyCell);
          tbody.append(emptyRow);
        }
      }
      groupItems.forEach((item) => {
        const row = document.createElement('tr');
        row.className = 'boards-item-row';
        row.tabIndex = 0;
        row.dataset.openItemDetails = String(item.id);
        row.setAttribute('aria-label', `Abrir detalles de ${item.name || 'elemento'}`);
        row.style.setProperty('--group-color', safeGroupColor(group.color));
        const selectCell = makeElement('td', 'boards-select-cell');
        if (canEditBoard()) {
          const checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.dataset.selectItem = String(item.id);
          checkbox.checked = state.selectedItemIds.has(String(item.id));
          checkbox.setAttribute('aria-label', `Seleccionar ${item.name || 'elemento'}`);
          selectCell.append(checkbox);
        }
        row.append(selectCell);
        const nameCell = document.createElement('th');
        nameCell.scope = 'row';
        nameCell.className = 'boards-item-name';
        nameCell.append(createTableItemName(item));
        row.append(nameCell);
        columns.forEach((column) => {
          const cell = document.createElement('td');
          if (['status', 'dropdown'].includes(columnType(column))) cell.dataset.statusTone = statusTone(column, getCellValue(item, column));
          if (columnType(column) === 'status') {
            const chosen = parseOptions(column.options).find((entry) => entry.value === String(getCellValue(item, column) ?? ''));
            if (chosen) cell.style.setProperty('--status-color', chosen.color);
          }
          cell.append(createCellEditor(item, column, getCellValue(item, column)));
          row.append(cell);
        });
        const moveCell = document.createElement('td');
        moveCell.className = 'boards-move-cell';
        moveCell.append(createMoveEditor(item));
        row.append(moveCell);
        tbody.append(row);
      });
      if (canEditBoard() && !query && !state.filters.length) {
        const addRow = makeElement('tr', 'boards-add-row');
        const addCell = document.createElement('td');
        addCell.colSpan = columns.length + 3;
        if (state.inlineAddGroupId === String(group.id)) {
          const form = makeElement('form', 'boards-inline-add-form');
          form.dataset.inlineAddForm = String(group.id);
          const input = document.createElement('input');
          input.type = 'text';
          input.name = 'name';
          input.maxLength = 500;
          input.required = true;
          input.placeholder = 'Nombre del elemento';
          input.setAttribute('aria-label', `Nuevo elemento en ${group.name || 'grupo'}`);
          const save = makeElement('button', 'boards-inline-add-save', 'Agregar');
          save.type = 'submit';
          const cancel = makeElement('button', 'boards-inline-add-cancel', 'Cancelar');
          cancel.type = 'button';
          cancel.dataset.cancelInlineAdd = '1';
          form.append(input, save, cancel);
          addCell.append(form);
        } else {
          const add = makeElement('button', '', 'Agregar elemento');
          add.type = 'button';
          add.dataset.inlineAddGroup = String(group.id);
          add.prepend(makeElement('i', 'fa-solid fa-plus'));
          addCell.append(add);
        }
        addRow.append(addCell);
        tbody.append(addRow);
      }
      table.append(tbody);
    });

    if (!state.groups.length) {
      const tbody = document.createElement('tbody');
      const emptyRow = document.createElement('tr');
      const emptyCell = document.createElement('td');
      emptyCell.colSpan = columns.length + 3;
      emptyCell.className = 'boards-table-empty';
      emptyCell.textContent = canManageStructure() ? 'Crea un grupo para empezar a agregar elementos.' : 'Este tablero todavía no tiene grupos.';
      emptyRow.append(emptyCell);
      tbody.append(emptyRow);
      table.append(tbody);
    }
    els.boardTableContainer.append(table);
    els.boardTableStatus.hidden = state.groups.length > 0;
    els.boardTableStatus.textContent = state.groups.length ? '' : 'Agrega un grupo para organizar los elementos de este tablero.';
    updateSelectionBar();
  }

  function updateItemCount(visibleCount) {
    const hasConditions = Boolean(els.boardSearch.value.trim()) || state.filters.length > 0;
    els.boardItemCount.textContent = hasConditions
      ? `${visibleCount} de ${state.items.length} elementos`
      : `${state.items.length} ${state.items.length === 1 ? 'elemento' : 'elementos'}`;
    els.boardFilterCount.textContent = state.filters.length ? String(state.filters.length) : '';
    els.boardFilterCount.hidden = state.filters.length === 0;
  }

  function updateSelectionBar() {
    const bar = document.getElementById('boardSelectionBar');
    const count = document.getElementById('boardSelectionCount');
    const target = document.getElementById('bulkMoveGroup');
    const move = document.getElementById('bulkMoveItems');
    if (!bar || !count || !target || !move) return;
    const validIds = new Set(state.items.map((item) => String(item.id)));
    state.selectedItemIds.forEach((id) => { if (!validIds.has(id)) state.selectedItemIds.delete(id); });
    const total = state.selectedItemIds.size;
    bar.hidden = total === 0 || !canEditBoard();
    count.textContent = `${total} ${total === 1 ? 'elemento seleccionado' : 'elementos seleccionados'}`;
    const previous = target.value;
    target.replaceChildren();
    const prompt = makeElement('option', '', 'Mover a otro grupo');
    prompt.value = '';
    target.append(prompt);
    state.groups.forEach((group) => {
      const option = makeElement('option', '', group.name || 'Grupo sin nombre');
      option.value = String(group.id);
      target.append(option);
    });
    if (state.groups.some((group) => String(group.id) === previous)) target.value = previous;
    move.disabled = state.bulkMoveInFlight || !target.value || !total;
    target.disabled = state.bulkMoveInFlight;
    document.getElementById('clearSelection').disabled = state.bulkMoveInFlight;
    els.boardTableContainer.querySelectorAll('[data-select-item], [data-select-group]').forEach((checkbox) => { checkbox.disabled = state.bulkMoveInFlight; });
    els.boardTableContainer.querySelectorAll('[data-select-group]').forEach((checkbox) => {
      const groupItems = getFilteredItems().filter((item) => String(item.group_id) === checkbox.dataset.selectGroup);
      const selected = groupItems.filter((item) => state.selectedItemIds.has(String(item.id))).length;
      checkbox.checked = groupItems.length > 0 && selected === groupItems.length;
      checkbox.indeterminate = selected > 0 && selected < groupItems.length;
    });
  }

  function columnType(column) {
    return String(column?.type || '').toLowerCase();
  }

  function dateOnly(value) {
    if (value && typeof value === 'object') {
      return dateOnly(value.start_date ?? value.start ?? value.from ?? value.date ?? value.value ?? '');
    }
    const match = String(value ?? '').match(/\d{4}-\d{2}-\d{2}/);
    return match ? match[0] : '';
  }

  function dateRange(value) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      return {
        start: dateOnly(value.start_date ?? value.start ?? value.from ?? value.date ?? value.value ?? ''),
        end: dateOnly(value.end_date ?? value.end ?? value.to ?? value.date ?? value.value ?? '')
      };
    }
    const date = dateOnly(value);
    return { start: date, end: date };
  }

  function localDate(date) {
    return date ? new Date(`${date}T00:00:00`) : null;
  }

  function formatDate(date, options = { day: 'numeric', month: 'short', year: 'numeric' }) {
    const parsed = typeof date === 'string' ? localDate(date) : date;
    return parsed && !Number.isNaN(parsed.getTime())
      ? new Intl.DateTimeFormat('es-MX', options).format(parsed)
      : 'Sin fecha';
  }

  function makeElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined && text !== null) element.textContent = String(text);
    return element;
  }

  function fieldSelect(labelText, setting, columns, selectedValue, emptyText = 'Selecciona una columna') {
    const label = makeElement('label', 'boards-view-field');
    const text = makeElement('span', '', labelText);
    const select = document.createElement('select');
    select.className = 'form-select';
    select.dataset.viewSetting = setting;
    select.setAttribute('aria-label', labelText);
    const prompt = makeElement('option', '', emptyText);
    prompt.value = '';
    select.append(prompt);
    columns.forEach((column) => {
      const option = makeElement('option', '', column.name || 'Columna');
      option.value = String(column.id);
      select.append(option);
    });
    select.value = selectedValue || '';
    label.append(text, select);
    return { label, select };
  }

  function dateColumns(includeTimeline = false) {
    return state.columns.filter((column) => columnType(column) === 'date' || (includeTimeline && columnType(column) === 'timeline'));
  }

  function statusColumns() {
    return state.columns.filter((column) => ['status', 'dropdown'].includes(columnType(column)));
  }

  function ensureViewConfigDefaults() {
    const dates = dateColumns(true);
    const dateFields = dateColumns(true);
    const status = statusColumns();
    if (!dateFields.some((column) => String(column.id) === String(state.viewConfig.calendar_date_column_id))) state.viewConfig.calendar_date_column_id = dateFields.length ? String(dateFields[0].id) : '';
    if (!dates.some((column) => String(column.id) === String(state.viewConfig.timeline_start_column_id))) state.viewConfig.timeline_start_column_id = dates.length ? String(dates[0].id) : '';
    if (state.viewConfig.timeline_end_column_id && !dates.some((column) => String(column.id) === String(state.viewConfig.timeline_end_column_id))) state.viewConfig.timeline_end_column_id = '';
    if (state.viewConfig.kanban_column_id !== 'groups' && !status.some((column) => String(column.id) === String(state.viewConfig.kanban_column_id))) state.viewConfig.kanban_column_id = status.length ? String(status[0].id) : 'groups';
    if (!status.some((column) => String(column.id) === String(state.viewConfig.panel_column_id))) state.viewConfig.panel_column_id = status.length ? String(status[0].id) : '';
  }

  function markViewModified() {
    state.activeSavedViewId = '';
    els.savedViewSelect.value = '';
  }

  function renderViewOptions() {
    ensureViewConfigDefaults();
    const type = state.activeViewType;
    els.boardViewOptions.replaceChildren();
    els.boardViewOptions.hidden = type === 'table';
    if (type === 'table') return;
    const options = makeElement('div', 'boards-view-options-inner');
      const fieldColumns = dateColumns(true);
    if (type === 'calendar') {
      const field = fieldSelect('Fecha del calendario', 'calendar_date_column_id', fieldColumns, state.viewConfig.calendar_date_column_id, 'Selecciona fecha');
      options.append(field.label);
      field.select.addEventListener('change', () => {
        state.viewConfig.calendar_date_column_id = field.select.value;
        markViewModified();
        renderActiveView();
      });
    } else if (type === 'timeline') {
      const start = fieldSelect('Fecha de inicio', 'timeline_start_column_id', fieldColumns, state.viewConfig.timeline_start_column_id, 'Selecciona inicio');
      const end = fieldSelect('Fecha de término', 'timeline_end_column_id', fieldColumns, state.viewConfig.timeline_end_column_id, 'Sin fecha de término');
      options.append(start.label, end.label);
      start.select.addEventListener('change', () => {
        state.viewConfig.timeline_start_column_id = start.select.value;
        markViewModified();
        renderActiveView();
      });
      end.select.addEventListener('change', () => {
        state.viewConfig.timeline_end_column_id = end.select.value;
        markViewModified();
        renderActiveView();
      });
    } else if (type === 'kanban') {
      const label = makeElement('label', 'boards-view-field');
      label.append(makeElement('span', '', 'Agrupar por'));
      const select = document.createElement('select');
      select.className = 'form-select';
      select.dataset.viewSetting = 'kanban_column_id';
      const groupsOption = makeElement('option', '', 'Grupos del tablero');
      groupsOption.value = 'groups';
      select.append(groupsOption);
      statusColumns().forEach((column) => {
        const option = makeElement('option', '', column.name || 'Estado');
        option.value = String(column.id);
        select.append(option);
      });
      select.value = state.viewConfig.kanban_column_id || 'groups';
      select.addEventListener('change', () => {
        state.viewConfig.kanban_column_id = select.value;
        markViewModified();
        renderActiveView();
      });
      label.append(select);
      options.append(label);
    } else if (type === 'dashboard') {
      const status = statusColumns();
      if (status.length) {
        const field = fieldSelect('Resumen por estado', 'panel_column_id', status, state.viewConfig.panel_column_id, 'Sin desglose por estado');
        options.append(field.label);
        field.select.addEventListener('change', () => {
          state.viewConfig.panel_column_id = field.select.value;
          markViewModified();
          renderActiveView();
        });
      }
    }
    const note = makeElement('span', 'boards-view-options-note', type === 'calendar'
      ? 'Los valores se leen de las columnas de fecha.'
      : (type === 'timeline' ? 'Las fechas se muestran como un rango relativo.' : 'La vista usa los datos cargados de este tablero.'));
    options.append(note);
    els.boardViewOptions.append(options);
  }

  function filterOperators(column) {
    const type = columnType(column);
    const common = [['is_empty', 'Está vacío'], ['not_empty', 'No está vacío']];
    if (['number', 'numbers'].includes(type)) return [['equals', 'Es igual a'], ['not_equals', 'No es igual a'], ['greater_than', 'Es mayor que'], ['less_than', 'Es menor que'], ...common];
    if (['date', 'timeline'].includes(type)) return [['equals', 'Es'], ['before', 'Es anterior a'], ['after', 'Es posterior a'], ...common];
    if (['text', 'long_text', 'email', 'phone', 'link'].includes(type)) return [['contains', 'Contiene'], ['equals', 'Es igual a'], ['not_equals', 'No es igual a'], ...common];
    return [['equals', 'Es igual a'], ['not_equals', 'No es igual a'], ...common];
  }

  function renderFilterValueControl(filter, column, index, operator) {
    if (['is_empty', 'not_empty'].includes(operator)) return null;
    const type = columnType(column);
    let control;
    const choices = ['status', 'dropdown'].includes(type) ? parseOptions(column.options) : [];
    if (choices.length) {
      control = document.createElement('select');
      control.className = 'form-select';
      const prompt = makeElement('option', '', 'Selecciona valor');
      prompt.value = '';
      control.append(prompt);
      if (filter.value && !choices.some((choice) => choice.value === String(filter.value))) {
        const current = makeElement('option', '', String(filter.value));
        current.value = String(filter.value);
        control.append(current);
      }
      choices.forEach((choice) => {
        const option = makeElement('option', '', choice.label);
        option.value = choice.value;
        control.append(option);
      });
    } else {
      control = document.createElement('input');
      control.type = ['date', 'timeline'].includes(type) ? 'date' : (['number', 'numbers'].includes(type) ? 'number' : 'text');
      if (control.type === 'number') control.step = 'any';
      control.placeholder = 'Valor';
      control.className = 'form-control';
    }
    control.value = filter.value ?? '';
    control.dataset.filterPart = 'value';
    control.dataset.filterIndex = String(index);
    control.setAttribute('aria-label', `Valor del filtro ${index + 1}`);
    control.addEventListener('input', () => { state.filterDraft[index].value = control.value; });
    control.addEventListener('change', () => { state.filterDraft[index].value = control.value; });
    return control;
  }

  function renderFilterPanel() {
    const rows = els.boardFilterRows;
    rows.replaceChildren();
    const addButton = document.getElementById('addBoardFilter');
    addButton.disabled = state.columns.length === 0;
    if (!state.columns.length) {
      rows.append(makeElement('p', 'boards-filter-empty', 'Agrega una columna para filtrar por campo.'));
      return;
    }
    state.filterDraft.forEach((filter, index) => {
      const column = state.columns.find((candidate) => String(candidate.id) === String(filter.column_id)) || state.columns[0];
      filter.column_id = String(column.id);
      const operators = filterOperators(column);
      if (!operators.some(([key]) => key === filter.operator)) filter.operator = operators[0][0];
      const row = makeElement('div', 'boards-filter-row');
      const columnSelect = document.createElement('select');
      columnSelect.className = 'form-select';
      columnSelect.setAttribute('aria-label', `Columna del filtro ${index + 1}`);
      state.columns.forEach((candidate) => {
        const option = makeElement('option', '', candidate.name || 'Columna');
        option.value = String(candidate.id);
        columnSelect.append(option);
      });
      columnSelect.value = String(column.id);
      columnSelect.addEventListener('change', () => {
        state.filterDraft[index] = { column_id: columnSelect.value, operator: '', value: '' };
        renderFilterPanel();
      });

      const operatorSelect = document.createElement('select');
      operatorSelect.className = 'form-select';
      operatorSelect.setAttribute('aria-label', `Condición del filtro ${index + 1}`);
      operators.forEach(([key, label]) => {
        const option = makeElement('option', '', label);
        option.value = key;
        operatorSelect.append(option);
      });
      operatorSelect.value = filter.operator;
      operatorSelect.addEventListener('change', () => {
        state.filterDraft[index].operator = operatorSelect.value;
        renderFilterPanel();
      });
      const valueControl = renderFilterValueControl(filter, column, index, operatorSelect.value);
      const remove = makeElement('button', 'boards-filter-remove', 'Quitar');
      remove.type = 'button';
      remove.setAttribute('aria-label', `Quitar filtro ${index + 1}`);
      remove.addEventListener('click', () => {
        state.filterDraft.splice(index, 1);
        renderFilterPanel();
      });
      row.append(columnSelect, operatorSelect);
      if (valueControl) row.append(valueControl);
      row.append(remove);
      rows.append(row);
    });
  }

  function renderSavedViews() {
    const select = els.savedViewSelect;
    const selected = state.activeSavedViewId;
    select.replaceChildren();
    const placeholder = makeElement('option', '', state.viewsAvailable ? 'Vistas guardadas' : 'Vistas guardadas no disponibles');
    placeholder.value = '';
    select.append(placeholder);
    state.views.forEach((view) => {
      const option = makeElement('option', '', `${view.name || 'Vista'}${view.is_shared ? ' · compartida' : ''}`);
      option.value = String(view.id);
      select.append(option);
    });
    select.value = selected && state.views.some((view) => String(view.id) === String(selected)) ? String(selected) : '';
    select.disabled = !state.viewsAvailable || state.views.length === 0;
  }

  function normalizeViewType(type) {
    return ['table', 'calendar', 'timeline', 'kanban', 'dashboard'].includes(String(type || '').toLowerCase())
      ? String(type).toLowerCase()
      : 'table';
  }

  function applySavedView(view) {
    let config = view.config;
    if (typeof config === 'string') {
      try { config = JSON.parse(config); } catch (error) { config = {}; }
    }
    state.activeSavedViewId = String(view.id);
    state.activeViewType = normalizeViewType(view.view_type);
    state.viewConfig = config && typeof config === 'object' ? { ...config } : {};
    normalizeColumnViewConfig();
    state.filters = Array.isArray(state.viewConfig.filters) ? state.viewConfig.filters.map((filter) => ({ ...filter })) : [];
    state.filterDraft = state.filters.map((filter) => ({ ...filter }));
    els.savedViewSelect.value = String(view.id);
    renderFilterPanel();
    renderActiveView();
  }

  function savedViewConfig() {
    ensureViewConfigDefaults();
    return {
      filters: state.filters.map((filter) => ({ column_id: String(filter.column_id), operator: filter.operator, value: filter.value ?? '' })),
      calendar_date_column_id: state.viewConfig.calendar_date_column_id || '',
      timeline_start_column_id: state.viewConfig.timeline_start_column_id || '',
      timeline_end_column_id: state.viewConfig.timeline_end_column_id || '',
      kanban_column_id: state.viewConfig.kanban_column_id || 'groups',
      panel_column_id: state.viewConfig.panel_column_id || '',
      column_order: orderedColumns(true).map((column) => String(column.id)),
      hidden_column_ids: state.viewConfig.hidden_column_ids.slice()
    };
  }

  function switchView(type) {
    state.activeViewType = normalizeViewType(type);
    state.activeSavedViewId = '';
    els.savedViewSelect.value = '';
    renderActiveView();
  }

  function renderActiveView() {
    const type = normalizeViewType(state.activeViewType);
    state.activeViewType = type;
    updateItemCount(getFilteredItems().length);
    document.querySelectorAll('.boards-view-tab[data-view-type]').forEach((button) => {
      const active = button.dataset.viewType === type;
      button.classList.toggle('is-active', active);
      if (active) button.setAttribute('aria-current', 'page');
      else button.removeAttribute('aria-current');
    });
    renderViewOptions();
    renderColumnControls();
    const isTable = type === 'table';
    const selectionBar = document.getElementById('boardSelectionBar');
    if (selectionBar && !isTable) selectionBar.hidden = true;
    els.boardTableFrame.hidden = !isTable;
    els.boardTableStatus.hidden = isTable ? state.groups.length > 0 : true;
    els.boardRendererArea.hidden = isTable;
    if (isTable) {
      renderTable();
      return;
    }
    els.boardRendererArea.replaceChildren();
    if (type === 'calendar') renderCalendar();
    else if (type === 'timeline') renderTimeline();
    else if (type === 'kanban') renderKanban();
    else renderPanel();
  }

  function renderEmptyRenderer(title, description) {
    const empty = makeElement('div', 'boards-renderer-empty');
    empty.append(makeElement('h3', '', title), makeElement('p', '', description));
    els.boardRendererArea.append(empty);
  }

  function renderCalendar() {
    const dateColumn = state.columns.find((column) => String(column.id) === String(state.viewConfig.calendar_date_column_id));
    if (!dateColumn || !['date', 'timeline'].includes(columnType(dateColumn))) {
      renderEmptyRenderer('Elige una columna de fecha', dateColumns(true).length
        ? 'Selecciona la columna que determina cuándo aparece cada elemento.'
        : 'Este tablero no tiene columnas de fecha o cronograma. Agrega una para usar el calendario.');
      return;
    }
    const month = state.calendarMonth;
    const year = month.getFullYear();
    const monthIndex = month.getMonth();
    const heading = makeElement('div', 'boards-calendar-heading');
    const monthTitle = makeElement('h3', '', new Intl.DateTimeFormat('es-MX', { month: 'long', year: 'numeric' }).format(month));
    const navigation = makeElement('div', 'boards-calendar-navigation');
    const previous = makeElement('button', 'boards-calendar-nav', '‹');
    previous.type = 'button';
    previous.dataset.calendarShift = '-1';
    previous.setAttribute('aria-label', 'Mes anterior');
    const today = makeElement('button', 'boards-button-light boards-calendar-today', 'Hoy');
    today.type = 'button';
    today.dataset.calendarToday = '1';
    const next = makeElement('button', 'boards-calendar-nav', '›');
    next.type = 'button';
    next.dataset.calendarShift = '1';
    next.setAttribute('aria-label', 'Mes siguiente');
    navigation.append(previous, today, next);
    heading.append(monthTitle, navigation);

    const grid = makeElement('div', 'boards-calendar-grid');
    grid.setAttribute('role', 'grid');
    ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'].forEach((day) => {
      const label = makeElement('div', 'boards-calendar-weekday', day);
      label.setAttribute('role', 'columnheader');
      grid.append(label);
    });
    const firstDay = new Date(year, monthIndex, 1);
    const offset = (firstDay.getDay() + 6) % 7;
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const totalCells = Math.ceil((offset + daysInMonth) / 7) * 7;
    const now = new Date();
    const todayString = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    const events = new Map();
    getFilteredItems().forEach((item) => {
      const date = dateRange(getCellValue(item, dateColumn)).start;
      if (!date) return;
      if (!events.has(date)) events.set(date, []);
      events.get(date).push(item);
    });
    for (let index = 0; index < totalCells; index += 1) {
      const dayNumber = index - offset + 1;
      const cellDate = new Date(year, monthIndex, dayNumber);
      const dateKey = `${cellDate.getFullYear()}-${String(cellDate.getMonth() + 1).padStart(2, '0')}-${String(cellDate.getDate()).padStart(2, '0')}`;
      const cell = makeElement('div', 'boards-calendar-day');
      cell.setAttribute('role', 'gridcell');
      cell.setAttribute('aria-label', formatDate(dateKey, { day: 'numeric', month: 'long', year: 'numeric' }));
      if (cellDate.getMonth() !== monthIndex) cell.classList.add('is-outside');
      if (dateKey === todayString) cell.classList.add('is-today');
      const number = makeElement('span', 'boards-calendar-day-number', cellDate.getDate());
      cell.append(number);
      (events.get(dateKey) || []).forEach((item) => {
        const group = state.groups.find((candidate) => String(candidate.id) === String(item.group_id));
        const event = createItemNameControl(item, 'boards-calendar-event');
        event.title = `${item.name || 'Elemento'} · ${group?.name || 'Sin grupo'}`;
        event.style.setProperty('--group-color', safeGroupColor(group?.color));
        cell.append(event);
      });
      grid.append(cell);
    }
    const monthPrefix = `${year}-${String(monthIndex + 1).padStart(2, '0')}-`;
    const eventCount = Array.from(events.entries()).reduce((total, [date, items]) => total + (date.startsWith(monthPrefix) ? items.length : 0), 0);
    const note = makeElement('p', 'boards-renderer-caption', `${eventCount} elementos tienen fecha en este mes; la fecha proviene de «${dateColumn.name || 'Fecha'}».`);
    els.boardRendererArea.append(heading, grid, note);
    updateItemCount(getFilteredItems().length);
  }

  function renderTimeline() {
    const startColumn = state.columns.find((column) => String(column.id) === String(state.viewConfig.timeline_start_column_id));
    const endColumn = state.columns.find((column) => String(column.id) === String(state.viewConfig.timeline_end_column_id));
    if (!startColumn || !['date', 'timeline'].includes(columnType(startColumn))) {
      renderEmptyRenderer('Elige la fecha de inicio', dateColumns(true).length
        ? 'Selecciona una columna para ordenar y ubicar los elementos en el cronograma.'
        : 'Este tablero no tiene columnas de fecha o cronograma.');
      return;
    }
    const entries = getFilteredItems().map((item) => {
      const sourceRange = dateRange(getCellValue(item, startColumn));
      const start = sourceRange.start;
      let end = endColumn && String(endColumn.id) !== String(startColumn.id) ? dateRange(getCellValue(item, endColumn)).end : sourceRange.end;
      if (!end) end = start;
      if (start && end && end < start) end = start;
      return { item, start, end };
    }).filter((entry) => entry.start).sort((a, b) => a.start.localeCompare(b.start));
    if (!entries.length) {
      renderEmptyRenderer('No hay elementos con fecha de inicio', 'Agrega fechas en la columna elegida o cambia los filtros para ver elementos en el cronograma.');
      return;
    }
    const minDate = entries[0].start;
    const maxDate = entries.reduce((max, entry) => entry.end > max ? entry.end : max, entries[0].end);
    const startMs = localDate(minDate).getTime();
    const endMs = localDate(maxDate).getTime();
    const span = Math.max(1, Math.round((endMs - startMs) / 86400000) + 1);
    const heading = makeElement('div', 'boards-timeline-heading');
    heading.append(makeElement('h3', '', 'Cronograma de trabajo'));
    heading.append(makeElement('p', '', `${entries.length} elementos con fecha · inicio: ${startColumn.name || 'Fecha'}${endColumn ? ` · término: ${endColumn.name || 'Fecha'}` : ''}`));
    const axis = makeElement('div', 'boards-timeline-axis');
    axis.append(makeElement('span', 'boards-timeline-axis-name', 'Elemento'));
    const ticks = makeElement('div', 'boards-timeline-axis-track');
    ticks.append(makeElement('span', '', formatDate(minDate)));
    ticks.append(makeElement('span', '', formatDate(new Date((startMs + endMs) / 2))));
    ticks.append(makeElement('span', '', formatDate(maxDate)));
    axis.append(ticks);
    const list = makeElement('div', 'boards-timeline-list');
    entries.forEach(({ item, start, end }) => {
      const row = makeElement('div', 'boards-timeline-row');
      const label = createItemNameControl(item, 'boards-timeline-item-name');
      const track = makeElement('div', 'boards-timeline-track');
      const group = state.groups.find((candidate) => String(candidate.id) === String(item.group_id));
      const bar = makeElement('div', 'boards-timeline-bar');
      const itemStart = localDate(start).getTime();
      const itemEnd = localDate(end).getTime();
      const left = Math.max(0, ((itemStart - startMs) / (span * 86400000)) * 100);
      const width = Math.max(1.4, (((itemEnd - itemStart) / 86400000 + 1) / span) * 100);
      bar.style.left = `${left}%`;
      bar.style.width = `${Math.min(width, Math.max(1.4, 100 - left))}%`;
      bar.style.setProperty('--group-color', safeGroupColor(group?.color));
      bar.title = `${item.name || 'Elemento'} · ${formatDate(start)}${end !== start ? ` – ${formatDate(end)}` : ''}`;
      track.append(bar);
      const dates = makeElement('span', 'boards-timeline-date', end !== start ? `${formatDate(start)} – ${formatDate(end)}` : formatDate(start));
      row.append(label, track, dates);
      list.append(row);
    });
    els.boardRendererArea.append(heading, axis, list);
    updateItemCount(getFilteredItems().length);
  }

  function renderKanban() {
    const fieldId = state.viewConfig.kanban_column_id || 'groups';
    const column = fieldId === 'groups' ? null : state.columns.find((candidate) => String(candidate.id) === String(fieldId));
    const items = getFilteredItems();
    const board = makeElement('div', 'boards-kanban-board');
    let lanes = [];
    if (!column) {
      lanes = state.groups.map((group) => ({ key: String(group.id), name: group.name || 'Grupo sin nombre', color: group.color, group }));
    } else {
      const values = parseOptions(column.options).map((option) => option.value);
      items.forEach((item) => {
        const value = stringifyValue(getCellValue(item, column));
        if (value && !values.includes(value)) values.push(value);
      });
      if (items.some((item) => !stringifyValue(getCellValue(item, column)))) values.push('');
      lanes = values.map((value, index) => ({ key: value, name: value || 'Sin estado', color: ['#0f766e', '#3574a5', '#8a5ca6', '#bb7a2a', '#b64f57'][index % 5] }));
    }
    if (!lanes.length) {
      renderEmptyRenderer(column ? 'No hay valores para mostrar' : 'No hay grupos para mostrar', column
        ? 'No hay elementos con valores en esta columna y no hay opciones configuradas.'
        : 'Crea un grupo o elige una columna de estado para organizar las tarjetas.');
      return;
    }
    lanes.forEach((lane) => {
      const laneItems = items.filter((item) => column
        ? stringifyValue(getCellValue(item, column)) === lane.key
        : String(item.group_id) === String(lane.key));
      const laneElement = makeElement('section', 'boards-kanban-lane');
      laneElement.style.setProperty('--group-color', safeGroupColor(lane.color));
      const header = makeElement('header', 'boards-kanban-lane-heading');
      header.append(makeElement('span', 'boards-kanban-marker'), makeElement('h3', '', lane.name), makeElement('span', 'boards-kanban-count', laneItems.length));
      const cardList = makeElement('div', 'boards-kanban-cards');
      if (!laneItems.length) cardList.append(makeElement('p', 'boards-kanban-empty', 'Sin elementos'));
      laneItems.forEach((item) => {
        const card = makeElement('article', 'boards-kanban-card');
        card.append(createItemNameControl(item, 'boards-kanban-card-title'));
        const group = state.groups.find((candidate) => String(candidate.id) === String(item.group_id));
        if (column && group) card.append(makeElement('p', 'boards-kanban-card-group', group.name));
        const fields = makeElement('dl', 'boards-kanban-card-fields');
        orderedColumns().forEach((field) => {
          if (column && String(field.id) === String(column.id)) return;
          const value = getCellValue(item, field);
          if (value === null || value === '') return;
          const entry = makeElement('div', 'boards-kanban-card-field');
          entry.append(makeElement('dt', '', field.name || 'Dato'), makeElement('dd', '', stringifyValue(value)));
          fields.append(entry);
        });
        if (fields.childElementCount) card.append(fields);
        const moveControl = makeElement('div', 'boards-kanban-card-action');
        if (column && ['status', 'dropdown'].includes(columnType(column))) {
          moveControl.append(makeElement('span', '', 'Estado'));
          moveControl.append(createCellEditor(item, column, getCellValue(item, column)));
        } else if (!column) {
          moveControl.append(makeElement('span', '', 'Mover'));
          moveControl.append(createMoveEditor(item));
        }
        if (moveControl.childElementCount) card.append(moveControl);
        card.append(createDetailsButton(item));
        cardList.append(card);
      });
      laneElement.append(header, cardList);
      board.append(laneElement);
    });
    els.boardRendererArea.append(board);
    updateItemCount(items.length);
  }

  function renderPanel() {
    const items = getFilteredItems();
    const panel = makeElement('div', 'boards-panel-view');
    const heading = makeElement('div', 'boards-panel-heading');
    heading.append(makeElement('h3', '', 'Resumen del tablero'));
    if (state.filters.length || els.boardSearch.value.trim()) heading.append(makeElement('p', '', 'Los totales reflejan la búsqueda y los filtros activos.'));
    const metrics = makeElement('div', 'boards-panel-metrics');
    [
      ['Elementos', items.length],
      ['Grupos', state.groups.length],
      ['Columnas', state.columns.length]
    ].forEach(([label, value]) => {
      const metric = makeElement('div', 'boards-panel-metric');
      metric.append(makeElement('strong', '', value), makeElement('span', '', label));
      metrics.append(metric);
    });
    const breakdowns = makeElement('div', 'boards-panel-breakdowns');
    const groupSection = makeElement('section', 'boards-panel-breakdown');
    groupSection.append(makeElement('h4', '', 'Elementos por grupo'));
    const maxGroupCount = Math.max(1, ...state.groups.map((group) => items.filter((item) => String(item.group_id) === String(group.id)).length));
    state.groups.forEach((group) => {
      const count = items.filter((item) => String(item.group_id) === String(group.id)).length;
      const row = makeElement('div', 'boards-panel-bar-row');
      const label = makeElement('span', 'boards-panel-bar-label', group.name || 'Grupo sin nombre');
      const track = makeElement('span', 'boards-panel-bar-track');
      const bar = makeElement('span', 'boards-panel-bar');
      bar.style.width = `${(count / maxGroupCount) * 100}%`;
      bar.style.setProperty('--group-color', safeGroupColor(group.color));
      track.append(bar);
      row.append(label, track, makeElement('strong', 'boards-panel-bar-count', count));
      groupSection.append(row);
    });
    if (!state.groups.length) groupSection.append(makeElement('p', 'boards-panel-empty', 'Este tablero aún no tiene grupos.'));
    breakdowns.append(groupSection);
    const statusColumn = state.columns.find((column) => String(column.id) === String(state.viewConfig.panel_column_id));
    if (statusColumn) {
      const statusSection = makeElement('section', 'boards-panel-breakdown');
      statusSection.append(makeElement('h4', '', `Elementos por ${statusColumn.name || 'estado'}`));
      const values = parseOptions(statusColumn.options).map((option) => option.value);
      items.forEach((item) => {
        const value = stringifyValue(getCellValue(item, statusColumn));
        if (value && !values.includes(value)) values.push(value);
      });
      const blankCount = items.filter((item) => !stringifyValue(getCellValue(item, statusColumn))).length;
      if (blankCount) values.push('');
      const statusCounts = values.map((value) => ({ value, count: items.filter((item) => stringifyValue(getCellValue(item, statusColumn)) === value).length }));
      const maxCount = Math.max(1, ...statusCounts.map((entry) => entry.count));
      statusCounts.forEach((entry, index) => {
        const row = makeElement('div', 'boards-panel-bar-row');
        const label = makeElement('span', 'boards-panel-bar-label', entry.value || 'Sin estado');
        const track = makeElement('span', 'boards-panel-bar-track');
        const bar = makeElement('span', 'boards-panel-bar');
        bar.style.width = `${(entry.count / maxCount) * 100}%`;
        bar.style.setProperty('--group-color', ['#0f766e', '#3574a5', '#8a5ca6', '#bb7a2a', '#b64f57'][index % 5]);
        track.append(bar);
        row.append(label, track, makeElement('strong', 'boards-panel-bar-count', entry.count));
        statusSection.append(row);
      });
      if (!statusCounts.length) statusSection.append(makeElement('p', 'boards-panel-empty', 'No hay valores para resumir.'));
      breakdowns.append(statusSection);
    }
    panel.append(heading, metrics, breakdowns);
    els.boardRendererArea.append(panel);
    updateItemCount(items.length);
  }

  function refreshGroupOptions(selectedGroupId = '') {
    els.newItemGroup.replaceChildren();
    state.groups.forEach((group) => {
      const option = document.createElement('option');
      option.value = String(group.id);
      option.textContent = group.name || 'Grupo sin nombre';
      els.newItemGroup.append(option);
    });
    if (selectedGroupId && state.groups.some((group) => String(group.id) === String(selectedGroupId))) {
      els.newItemGroup.value = String(selectedGroupId);
    }
  }

  function openCreateItem(groupId = '') {
    if (!canEditBoard() || !state.groups.length) {
      setNotice(state.groups.length ? 'No tienes permiso para editar este tablero.' : 'Crea un grupo antes de agregar elementos.', 'info');
      return;
    }
    const targetGroupId = String(groupId || state.groups[0].id);
    if (state.activeViewType === 'table' && !els.boardSearch.value.trim() && !state.filters.length) {
      state.inlineAddGroupId = targetGroupId;
      state.collapsedGroups.delete(targetGroupId);
      renderTable();
      const input = els.boardTableContainer.querySelector('[data-inline-add-form] input[name="name"]');
      input?.focus();
      return;
    }
    refreshGroupOptions(groupId);
    openDialog(els.createItemDialog);
  }

  async function submitInlineItem(form) {
    if (!canEditBoard() || !state.board) return;
    const boardId = state.board.id;
    const name = form.querySelector('input[name="name"]')?.value.trim() || '';
    const groupId = form.dataset.inlineAddForm;
    if (!name || !groupId) return;
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const result = await post('/create_item', { board_id: boardId, group_id: groupId, name });
      if (!state.board || String(state.board.id) !== String(boardId)) return;
      const item = result.data || {};
      if (!item.id || item.version == null) {
        await loadBoard(state.board.id, true);
        throw new Error('No se pudo confirmar el elemento. Se recargó el tablero.');
      }
      state.items.push({ ...item, name, group_id: groupId, sort_order: item.sort_order ?? state.items.length, cells: item.cells || {} });
      state.items.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      state.inlineAddGroupId = groupId;
      renderActiveView();
      els.boardTableContainer.querySelector('[data-inline-add-form] input[name="name"]')?.focus();
      setNotice('Elemento agregado.', 'success');
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo agregar el elemento.'), 'error');
      if (document.contains(button)) button.disabled = false;
    }
  }

  function parseEditorValue(editor) {
    const kind = editor.dataset.valueKind;
    if (kind === 'timeline') {
      const start = editor.querySelector('[data-timeline-part="start"]')?.value || '';
      const end = editor.querySelector('[data-timeline-part="end"]')?.value || '';
      if (!start && !end) return null;
      return { ...(start ? { start } : {}), ...(end ? { end } : {}) };
    }
    if (kind === 'structured') {
      const textarea = editor.querySelector('textarea');
      return setStructuredEditorValue(textarea, ['board_relation', 'subtasks', 'dependency'].includes(editor.dataset.columnType));
    }
    if (kind === 'status') return editor.querySelector('select')?.value ?? '';
    if (kind === 'people') {
      return Array.from(editor.querySelector('select')?.selectedOptions || [], (option) => Number(option.value)).filter(Number.isInteger);
    }
    if (kind === 'tags') return Array.from(editor.selectedOptions || [], (option) => option.value);
    if (kind === 'tags-text') return editor.value.split(',').map((tag) => tag.trim()).filter(Boolean);
    const value = editor.value;
    if (kind === 'number') {
      if (value === '') return null;
      const number = Number(value);
      if (!Number.isFinite(number)) throw new Error('Escribe un número válido.');
      return number;
    }
    return value;
  }

  function setEditorDisabled(editor, disabled) {
    if (editor instanceof HTMLInputElement || editor instanceof HTMLSelectElement || editor instanceof HTMLTextAreaElement) {
      editor.disabled = disabled;
      return;
    }
    editor.querySelectorAll('input, select, textarea, button').forEach((control) => { control.disabled = disabled; });
  }

  function editorValidityTarget(editor) {
    return editor instanceof HTMLInputElement || editor instanceof HTMLSelectElement || editor instanceof HTMLTextAreaElement
      ? editor
      : editor.querySelector('textarea, input, select');
  }

  async function saveCell(editor) {
    const item = state.items.find((candidate) => String(candidate.id) === editor.dataset.itemId);
    if (!item || !state.board) return;
    let value;
    try {
      value = parseEditorValue(editor);
    } catch (error) {
      const target = editorValidityTarget(editor);
      if (target) {
        target.setCustomValidity(error.message);
        target.reportValidity();
      } else setNotice(error.message, 'error');
      return;
    }
    const validityTarget = editorValidityTarget(editor);
    validityTarget?.setCustomValidity('');
    const columnId = editor.dataset.columnId;
    const previous = getCellValue(item, { id: columnId });
    if (JSON.stringify(value) === JSON.stringify(previous)) return;
    setEditorDisabled(editor, true);
    try {
      const result = await post('/update_cell', {
        board_id: state.board.id,
        item_id: item.id,
        column_id: columnId,
        value,
        version: item.version
      });
      item.version = result.data?.version ?? item.version;
      if (!item.cells) item.cells = {};
      item.cells[columnId] = value;
      editor.dataset.savedValue = JSON.stringify(value);
      editor.classList.remove('has-error');
      const editedColumn = state.columns.find((column) => String(column.id) === String(columnId));
      if (editedColumn && ['status', 'dropdown'].includes(columnType(editedColumn))) {
        const tone = statusTone(editedColumn, value);
        editor.dataset.statusTone = tone;
        const cell = editor.closest('td');
        if (cell) {
          cell.dataset.statusTone = tone;
          if (columnType(editedColumn) === 'status') {
            const chosen = parseOptions(editedColumn.options).find((entry) => entry.value === String(value ?? ''));
            if (chosen) cell.style.setProperty('--status-color', chosen.color);
            else cell.style.removeProperty('--status-color');
          }
        }
      }
      if (state.activeViewType !== 'table' || els.boardSearch.value.trim() || state.filters.length) renderActiveView();
    } catch (error) {
      editor.classList.add('has-error');
      setNotice(errorMessage(error, 'No se pudo guardar el cambio.'), 'error');
      if (error.status === 409 || error.code === 'conflict' || /409|actualiz|conflict/i.test(error.message)) {
        setNotice('El tablero cambió en otra sesión. Se recargaron los datos más recientes.', 'info');
        await loadBoard(state.board.id, true);
      }
    } finally {
      if (document.contains(editor)) setEditorDisabled(editor, false);
    }
  }

  async function moveItem(select) {
    const item = state.items.find((candidate) => String(candidate.id) === select.dataset.itemId);
    const groupId = select.value;
    if (!item || !groupId || String(item.group_id) === String(groupId)) return;
    select.disabled = true;
    try {
      const result = await post('/move_item', {
        board_id: state.board.id,
        item_id: item.id,
        group_id: groupId,
        version: item.version
      });
      item.group_id = groupId;
      item.version = result.data?.version ?? item.version;
      renderActiveView();
      setNotice('Elemento movido al grupo seleccionado.', 'success');
    } catch (error) {
      setNotice(errorMessage(error, 'No se pudo mover el elemento.'), 'error');
      if (error.status === 409 || error.code === 'conflict' || /409|actualiz|conflict/i.test(error.message)) await loadBoard(state.board.id, true);
      else select.value = select.dataset.currentGroup;
    } finally {
      if (document.contains(select)) select.disabled = false;
    }
  }

  async function moveSelectedItems() {
    const target = document.getElementById('bulkMoveGroup');
    const button = document.getElementById('bulkMoveItems');
    const groupId = target?.value || '';
    if (state.bulkMoveInFlight || !groupId || !canEditBoard() || !state.board || !state.selectedItemIds.size) return;
    const boardId = state.board.id;
    const selected = state.items.filter((item) => state.selectedItemIds.has(String(item.id)));
    let moved = 0;
    let failed = 0;
    state.bulkMoveInFlight = true;
    button.disabled = true;
    updateSelectionBar();
    try {
      for (const item of selected) {
        if (!state.board || String(state.board.id) !== String(boardId)) break;
        if (String(item.group_id) === groupId) {
          state.selectedItemIds.delete(String(item.id));
          continue;
        }
        try {
          const result = await post('/move_item', {
            board_id: boardId,
            item_id: item.id,
            group_id: groupId,
            version: item.version
          });
          if (!state.board || String(state.board.id) !== String(boardId)) break;
          item.group_id = groupId;
          item.version = result.data?.version ?? item.version;
          state.selectedItemIds.delete(String(item.id));
          moved += 1;
        } catch (error) {
          failed += 1;
        }
      }
      if (!state.board || String(state.board.id) !== String(boardId)) return;
      renderActiveView();
      if (failed) {
        setNotice(`${moved} ${moved === 1 ? 'elemento movido' : 'elementos movidos'}; ${failed} sin mover. Recarga el tablero antes de volver a intentarlo.`, 'error');
      } else {
        setNotice(`${moved} ${moved === 1 ? 'elemento movido' : 'elementos movidos'} al grupo seleccionado.`, 'success');
      }
    } finally {
      state.bulkMoveInFlight = false;
      updateSelectionBar();
    }
  }

  function getFormValue(formData, name) {
    return String(formData.get(name) || '').trim();
  }

  function setupDialogs() {
    document.querySelectorAll('[data-close-dialog]').forEach((button) => {
      button.addEventListener('click', () => closeDialog(button.closest('dialog')));
    });
    [els.createBoardDialog, els.createWorkspaceDialog, els.createFolderDialog, els.createGroupDialog, els.createColumnDialog, els.manageLabelsDialog, els.createItemDialog, els.shareBoardDialog, els.saveViewDialog].forEach((dialog) => {
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog(dialog);
      });
    });
    document.getElementById('openCreateBoard')?.addEventListener('click', () => openCreateBoard(state.board?.workspace_id || state.selectedWorkspaceId, state.board?.folder_id || ''));
    document.getElementById('emptyCreateBoard')?.addEventListener('click', () => openCreateBoard());
    document.getElementById('navCreateWorkspace')?.addEventListener('click', () => openDialog(els.createWorkspaceDialog));
    document.getElementById('navCreateFolder')?.addEventListener('click', () => openCreateFolder());
    document.getElementById('openCreateGroup').addEventListener('click', () => {
      if (canManageStructure()) openDialog(els.createGroupDialog);
      else setNotice('Se requiere permiso de administración para agregar grupos.', 'error');
    });
    document.getElementById('openCreateColumn').addEventListener('click', () => {
      if (canManageStructure()) openDialog(els.createColumnDialog);
      else setNotice('Se requiere permiso de administración para configurar columnas.', 'error');
    });
    document.getElementById('addManagedLabel')?.addEventListener('click', addManagedLabel);
    dialogSubmit(els.manageLabelsForm, async () => {
      if (!canManageStructure()) throw new Error('Se requiere permiso de diseño para editar etiquetas.');
      const columnId = String(els.manageLabelsForm.dataset.columnId || '');
      const rows = Array.from(els.managedLabelsList.querySelectorAll('.boards-managed-label-row'));
      const options = rows.map((row) => ({
        value: row.dataset.optionValue || '',
        label: row.querySelector('.boards-label-name')?.value.trim() || '',
        color: normalizedLabelColor(row.querySelector('.boards-label-color-picker')?.value)
      }));
      if (!options.length) throw new Error('Agrega al menos una etiqueta.');
      if (options.some((option) => !option.value || !option.label)) throw new Error('Cada etiqueta necesita un nombre.');
      const result = await post('/update_column_options', { board_id: state.board.id, column_id: columnId, options });
      const column = state.columns.find((entry) => String(entry.id) === columnId);
      if (!column) throw new Error('La columna ya no está disponible; vuelve a cargar el tablero.');
      column.options = result.data?.options || options;
      closeDialog(els.manageLabelsDialog);
      renderActiveView();
      setNotice('Etiquetas y colores guardados.', 'success');
    });
    document.getElementById('openCreateItem').addEventListener('click', () => openCreateItem());
    document.getElementById('openShareBoard')?.addEventListener('click', async () => {
      els.shareUserSearch.value = '';
      els.shareUserSelect.value = '';
      selectedShareUser = null;
      els.clearShareUser.hidden = true;
      els.shareUserResults.replaceChildren();
      openDialog(els.shareBoardDialog);
      await loadBoardMembers();
      await searchUsers('');
    });
    document.getElementById('openSaveView').addEventListener('click', () => {
      document.getElementById('savedViewType').value = state.activeViewType;
      document.getElementById('savedViewName').value = '';
      document.getElementById('savedViewShared').checked = false;
      openDialog(els.saveViewDialog);
    });

    dialogSubmit(els.createBoardForm, async (formData) => {
      if (!canCreateBoard) throw new Error('No tienes permiso para crear tableros.');
      const name = getFormValue(formData, 'name');
      const description = getFormValue(formData, 'description');
      const workspaceId = getFormValue(formData, 'workspace_id');
      const folderId = getFormValue(formData, 'folder_id');
      const visibility = getFormValue(formData, 'visibility') || 'private';
      const result = await post('/create_board', { name, workspace_id: workspaceId, visibility, ...(folderId ? { folder_id: folderId } : {}), ...(description ? { description } : {}) });
      const board = result.data || {};
      if (!board.id) throw new Error('El servidor no devolvió el tablero creado.');
      closeDialog(els.createBoardDialog);
      state.boards.push({ ...board, name: board.name || name });
      state.selectedWorkspaceId = String(workspaceId);
      renderBoardOptions();
      els.boardSelect.value = String(board.id);
      setNotice('Tablero creado.', 'success');
      await loadBoard(board.id);
    });

    dialogSubmit(els.createWorkspaceForm, async (formData) => {
      if (!canCreateBoard) throw new Error('No tienes permiso para crear espacios de trabajo.');
      const name = getFormValue(formData, 'name');
      const visibility = getFormValue(formData, 'visibility') || 'private';
      const result = await post('/create_workspace', { name, visibility });
      const workspace = result.data || {};
      if (!workspace.id) throw new Error('El servidor no devolvió el espacio creado.');
      state.workspaces.push(workspace);
      state.workspaces.sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'es-MX'));
      state.selectedWorkspaceId = String(workspace.id);
      state.board = null;
      closeDialog(els.createWorkspaceDialog);
      renderBoardOptions();
      showEmptyState();
      els.boardsEmptyText.textContent = 'Espacio creado. Agrega una carpeta o crea un tablero para comenzar.';
      setNotice('Espacio de trabajo creado.', 'success');
    });

    dialogSubmit(els.createFolderForm, async (formData) => {
      if (!canCreateBoard) throw new Error('No tienes permiso para crear carpetas.');
      const name = getFormValue(formData, 'name');
      const workspaceId = getFormValue(formData, 'workspace_id');
      const parentFolderId = getFormValue(formData, 'parent_folder_id');
      const color = getFormValue(formData, 'color');
      const result = await post('/create_folder', { name, color, workspace_id: workspaceId, ...(parentFolderId ? { parent_folder_id: parentFolderId } : {}) });
      const folder = result.data || {};
      if (!folder.id) throw new Error('El servidor no devolvió la carpeta creada.');
      state.folders.push(folder);
      state.folders.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0) || String(a.name || '').localeCompare(String(b.name || ''), 'es-MX'));
      state.selectedWorkspaceId = String(workspaceId);
      closeDialog(els.createFolderDialog);
      renderBoardNav();
      setNotice('Carpeta creada.', 'success');
    });

    document.getElementById('newBoardWorkspace')?.addEventListener('change', (event) => refreshFolderChoices(event.target.value, false));
    document.getElementById('newFolderWorkspace')?.addEventListener('change', (event) => refreshFolderChoices(event.target.value, false));

    dialogSubmit(els.createGroupForm, async (formData) => {
      if (!canManageStructure()) throw new Error('Se requiere permiso de administración para agregar grupos.');
      const name = getFormValue(formData, 'name');
      const color = getFormValue(formData, 'color');
      const result = await post('/create_group', { board_id: state.board.id, name, color });
      const group = result.data || {};
      state.groups.push({ id: group.id, name, color, sort_order: group.sort_order ?? state.groups.length });
      state.groups.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      closeDialog(els.createGroupDialog);
      renderActiveView();
      setNotice('Grupo creado.', 'success');
    });

    els.newColumnType.addEventListener('change', () => {
      const optionsType = ['status', 'dropdown', 'tags'].includes(els.newColumnType.value);
      els.columnOptionsField.classList.toggle('d-none', !optionsType);
      const help = {
        status: 'Escribe una opción por línea. El sistema asignará un color inicial a cada estado.',
        formula: 'La API acepta únicamente la sintaxis segura definida por el servidor.',
        people: 'La celda permite buscar usuarios activos de TotalGas.',
        person: 'La celda permite asignar una persona activa de TotalGas.',
        team: 'La celda acepta texto o JSON; no hay catálogo de equipos disponible.',
        file: 'Adjunta archivos desde la celda o desde Detalles. Máximo 100 MB; las imágenes se convierten a WebP. Los archivos quedan disponibles inmediatamente y no se analizan con antivirus.',
        board_relation: 'La relación debe incluir un tablero o elemento al que tengas acceso.',
        subtasks: 'Usa una lista u objeto JSON con IDs de elementos del mismo tablero.',
        dependency: 'Usa una lista u objeto JSON con IDs de elementos del mismo tablero.'
      };
      els.columnTypeHelp.textContent = help[els.newColumnType.value] || 'El tipo determina el editor y las validaciones disponibles para cada celda.';
      const optionsHelp = els.columnOptionsField.querySelector('.boards-field-help');
      if (optionsHelp) optionsHelp.textContent = els.newColumnType.value === 'status'
        ? 'Una opción por línea. Los estados se mostrarán como etiquetas coloreadas.'
        : 'Para Estado, Lista desplegable y Etiquetas: escribe una opción por línea.';
    });
    els.createColumnForm.addEventListener('reset', () => {
      window.setTimeout(() => els.newColumnType.dispatchEvent(new Event('change')), 0);
    });
    dialogSubmit(els.createColumnForm, async (formData) => {
      if (!canManageStructure()) throw new Error('Se requiere permiso de administración para agregar columnas.');
      const name = getFormValue(formData, 'name');
      const type = getFormValue(formData, 'type');
      let options = ['status', 'dropdown', 'tags'].includes(type)
        ? els.newColumnOptions.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean)
        : undefined;
      if (type === 'status' && options?.length) {
        options = options.map((label, index) => ({ value: label, label, color: statusLabelColor(label, index) }));
      }
      const result = await post('/create_column', { board_id: state.board.id, name, type, ...(options?.length ? { options } : {}) });
      const column = result.data || {};
      state.columns.push({ id: column.id, name, type, options: options || [], required: false, sort_order: column.sort_order ?? state.columns.length });
      state.columns.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      closeDialog(els.createColumnDialog);
      renderActiveView();
      setNotice('Columna agregada.', 'success');
    });

    dialogSubmit(els.createItemForm, async (formData) => {
      if (!canEditBoard()) throw new Error('No tienes permiso para editar este tablero.');
      const name = getFormValue(formData, 'name');
      const groupId = getFormValue(formData, 'group_id');
      const result = await post('/create_item', { board_id: state.board.id, group_id: groupId, name });
      const item = result.data || {};
      if (!item.id || item.version == null) {
        await loadBoard(state.board.id, true);
        throw new Error('No se pudo confirmar la versión del elemento. Se actualizaron los datos del tablero.');
      }
      state.items.push({ ...item, name, group_id: groupId, sort_order: item.sort_order ?? state.items.length, cells: item.cells || {} });
      state.items.sort((a, b) => Number(a.sort_order || 0) - Number(b.sort_order || 0));
      closeDialog(els.createItemDialog);
      renderActiveView();
      setNotice('Elemento agregado.', 'success');
    });

    dialogSubmit(els.shareBoardForm, async (formData) => {
      if (!canManageStructure()) throw new Error('Se requiere permiso de administración para compartir este tablero.');
      const userId = getFormValue(formData, 'user_id');
      const role = getFormValue(formData, 'role');
      if (!userId) throw new Error('Selecciona una persona del equipo.');
      await post('/share_board', { board_id: state.board.id, user_id: userId, role });
      await loadBoardMembers();
      closeDialog(els.shareBoardDialog);
      setNotice('Acceso actualizado.', 'success');
    });

    dialogSubmit(els.saveViewForm, async (formData) => {
      const name = getFormValue(formData, 'name');
      const viewType = normalizeViewType(getFormValue(formData, 'view_type'));
      const isShared = Boolean(formData.get('is_shared'));
      if (isShared && !canManageStructure()) throw new Error('Solo el propietario o una persona diseñadora puede compartir vistas.');
      const result = await post('/create_view', {
        board_id: state.board.id,
        name,
        view_type: viewType,
        config: savedViewConfig(),
        is_shared: isShared
      });
      const view = result.data || {};
      if (!view.id) throw new Error('La vista se guardó, pero el servidor no devolvió su identificador.');
      const saved = { ...view, name: view.name || name, view_type: view.view_type || viewType, config: view.config || savedViewConfig(), is_shared: view.is_shared ?? isShared };
      state.views.push(saved);
      state.viewsAvailable = true;
      closeDialog(els.saveViewDialog);
      renderSavedViews();
      applySavedView(saved);
      setNotice('Vista guardada.', 'success');
    });
  }

  async function searchUsers(query) {
    const requestId = ++shareUserSearchRequest;
    const params = new URLSearchParams({ q: query, board_id: String(state.board?.id || ''), directory: '1' });
    if (!state.board) return;
    els.shareUserSearch.setAttribute('aria-busy', 'true');
    els.shareUserResults.replaceChildren(makeElement('div', 'boards-share-user-empty', 'Buscando personas…'));
    els.shareUserResults.hidden = false;
    try {
      const result = await request(`/users?${params.toString()}`);
      if (requestId !== shareUserSearchRequest || !els.shareBoardDialog.open) return;
      const users = Array.isArray(result.data) ? result.data : [];
      els.shareUserResults.replaceChildren();
      if (!users.length) {
        els.shareUserResults.append(makeElement('div', 'boards-share-user-empty', query ? 'No hay usuarios activos de TG que coincidan.' : 'Escribe un nombre para buscar usuarios activos de TG.'));
      }
      users.forEach((user) => {
        const option = makeElement('button', 'boards-share-user-option');
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', String(selectedShareUser?.id) === String(user.id) ? 'true' : 'false');
        const initials = String(user.name || user.username || '?').trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toLocaleUpperCase('es-MX');
        option.append(makeElement('span', 'boards-share-user-avatar', initials || '?'));
        const copy = makeElement('span', 'boards-share-user-copy');
        copy.append(makeElement('strong', '', user.name || user.username || 'Persona'));
        const secondary = user.username && user.username !== user.name ? user.username : (user.email || '');
        if (secondary) copy.append(makeElement('small', '', secondary));
        option.append(copy);
        option.addEventListener('click', () => {
          shareUserSearchRequest += 1;
          window.clearTimeout(state.userSearchTimer);
          selectedShareUser = user;
          els.shareUserSelect.value = String(user.id);
          els.shareUserSearch.value = user.name || user.username || '';
          els.shareUserSearch.setAttribute('aria-expanded', 'false');
          els.shareUserSearch.classList.add('has-selection');
          els.clearShareUser.hidden = false;
          els.shareUserResults.hidden = true;
        });
        els.shareUserResults.append(option);
      });
      els.shareUserSearch.setAttribute('aria-expanded', 'true');
    } catch (error) {
      if (requestId !== shareUserSearchRequest) return;
      els.shareUserResults.replaceChildren(makeElement('div', 'boards-share-user-empty', errorMessage(error, 'No se pudo buscar personas.')));
      els.shareUserSearch.setAttribute('aria-expanded', 'true');
    } finally {
      if (requestId === shareUserSearchRequest) els.shareUserSearch.removeAttribute('aria-busy');
    }
  }

  async function loadBoardMembers() {
    if (!state.board) return;
    setDetailStatus('boardMembersStatus', 'Cargando personas…');
    els.boardMembersList.replaceChildren();
    try {
      const params = new URLSearchParams({ board_id: String(state.board.id) });
      const result = await request(`/members?${params.toString()}`);
      const data = result.data || {};
      state.boardMembers = Array.isArray(data.members) ? data.members : [];
      const entries = [{ ...data.owner, role: 'owner', isOwner: true }, ...state.boardMembers];
      entries.forEach((member) => {
        const id = String(member.user_id ?? '');
        const row = makeElement('div', 'boards-member-row');
        const details = makeElement('div', 'boards-member-person');
        const displayName = member.name || member.username || 'Usuario de TG';
        details.append(makeElement('strong', '', displayName));
        if (member.username && member.username !== displayName) details.append(makeElement('span', '', member.username));
        else if (member.email) details.append(makeElement('span', '', member.email));
        const roleLabels = { owner: 'Propietario', designer: 'Diseñador', editor: 'Puede editar', viewer: 'Solo lectura' };
        row.append(details, makeElement('span', 'boards-member-role', roleLabels[String(member.role || '').toLowerCase()] || String(member.role || 'Acceso')));
        if (!member.isOwner && canManageStructure()) {
          const revoke = makeElement('button', 'boards-text-button is-danger', 'Retirar acceso');
          revoke.type = 'button';
          revoke.dataset.revokeMember = id;
          revoke.setAttribute('aria-label', `Retirar acceso a ${displayName}`);
          row.append(revoke);
        }
        els.boardMembersList.append(row);
      });
      setDetailStatus('boardMembersStatus', `${entries.length} ${entries.length === 1 ? 'persona' : 'personas'} con acceso`);
    } catch (error) {
      setDetailStatus('boardMembersStatus', errorMessage(error, 'No se pudo cargar la lista de acceso.'), true);
      els.boardMembersList.append(makeElement('p', 'boards-detail-empty', 'La lista de personas no está disponible.'));
    }
  }

  async function revokeBoardMember(userId) {
    if (!canManageStructure()) return;
    const member = state.boardMembers.find((entry) => String(entry.user_id) === String(userId));
    const directoryName = member?.name || member?.username || 'usuario de TG';
    if (!window.confirm(`¿Retirar el acceso de ${directoryName} a este tablero?`)) return;
    try {
      await post('/revoke_member', { board_id: state.board.id, user_id: userId });
      await loadBoardMembers();
      await loadBoards();
      setNotice('Acceso retirado.', 'success');
    } catch (error) { setNotice(errorMessage(error, 'No se pudo retirar el acceso.'), 'error'); }
  }

  function attachEvents() {
    document.getElementById('boardNavSearch')?.addEventListener('input', renderBoardNav);
    els.workspaceSelect?.addEventListener('change', () => {
      state.selectedWorkspaceId = els.workspaceSelect.value;
      state.board = null;
      renderBoardNav();
      const firstBoard = state.boards.find((board) => String(board.workspace_id) === String(state.selectedWorkspaceId));
      if (firstBoard) {
        els.boardSelect.value = String(firstBoard.id);
        loadBoard(firstBoard.id);
      } else {
        els.boardSelect.value = '';
        updateBoardRoute('');
        showEmptyState();
        els.boardsEmptyText.textContent = 'Este espacio aún no tiene tableros a los que tengas acceso.';
      }
    });
    document.getElementById('boardNavList')?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-nav-board]');
      if (button) {
        els.boardSelect.value = button.dataset.navBoard;
        loadBoard(button.dataset.navBoard);
        return;
      }
      const folderToggle = event.target.closest('[data-toggle-folder]');
      if (folderToggle) {
        const id = folderToggle.dataset.toggleFolder;
        if (state.collapsedFolderIds.has(id)) state.collapsedFolderIds.delete(id);
        else state.collapsedFolderIds.add(id);
        renderBoardNav();
        return;
      }
      const childFolder = event.target.closest('[data-create-child-folder]');
      if (childFolder) {
        openCreateFolder(state.selectedWorkspaceId, childFolder.dataset.createChildFolder);
        return;
      }
      const createBoardInFolder = event.target.closest('[data-create-board-folder]');
      if (createBoardInFolder) openCreateBoard(state.selectedWorkspaceId, createBoardInFolder.dataset.createBoardFolder);
    });
    document.getElementById('navCreateBoard')?.addEventListener('click', () => openCreateBoard());
    els.boardSelect.addEventListener('change', () => {
      const board = state.boards.find((candidate) => String(candidate.id) === els.boardSelect.value);
      if (board) loadBoard(board.id);
      else {
        updateBoardRoute('');
        showEmptyState();
      }
    });
    window.addEventListener('popstate', () => {
      const id = getRouteBoardId();
      const board = state.boards.find((candidate) => String(candidate.id) === String(id));
      if (board) {
        els.boardSelect.value = String(board.id);
        loadBoard(board.id, true);
      } else if (!id) {
        showEmptyState();
      }
    });
    els.boardSearch.addEventListener('input', renderActiveView);
    document.querySelectorAll('.boards-view-tab[data-view-type]').forEach((button) => {
      button.addEventListener('click', () => switchView(button.dataset.viewType));
    });
    els.savedViewSelect.addEventListener('change', () => {
      const view = state.views.find((candidate) => String(candidate.id) === els.savedViewSelect.value);
      if (view) applySavedView(view);
      else {
        state.activeSavedViewId = '';
        renderActiveView();
      }
    });
    const filterToggle = document.getElementById('toggleBoardFilters');
    filterToggle.addEventListener('click', () => {
      const opening = els.boardFiltersPanel.hidden;
      els.boardFiltersPanel.hidden = !opening;
      filterToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) {
        state.filterDraft = state.filters.map((filter) => ({ ...filter }));
        renderFilterPanel();
      }
    });
    document.getElementById('addBoardFilter').addEventListener('click', () => {
      const column = state.columns[0];
      if (!column) return;
      const operator = filterOperators(column)[0][0];
      state.filterDraft.push({ column_id: String(column.id), operator, value: '' });
      renderFilterPanel();
    });
    document.getElementById('applyBoardFilters').addEventListener('click', () => {
      const incomplete = state.filterDraft.find((filter) => !['is_empty', 'not_empty'].includes(filter.operator) && String(filter.value ?? '').trim() === '');
      if (incomplete) {
        setNotice('Completa el valor de cada filtro o selecciona una condición de campo vacío.', 'error');
        return;
      }
      state.filters = state.filterDraft.map((filter) => ({ ...filter }));
      markViewModified();
      renderActiveView();
      setNotice(state.filters.length ? 'Filtros aplicados.' : 'No hay filtros activos.', 'success');
    });
    document.getElementById('clearBoardFilters').addEventListener('click', () => {
      state.filters = [];
      state.filterDraft = [];
      markViewModified();
      renderFilterPanel();
      renderActiveView();
      setNotice('Filtros eliminados.', 'info');
    });
    const columnsToggle = document.getElementById('toggleColumnControls');
    columnsToggle.addEventListener('click', () => {
      const opening = els.columnControlsPanel.hidden;
      els.columnControlsPanel.hidden = !opening;
      columnsToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) renderColumnControls();
    });
    els.columnControlsList.addEventListener('change', (event) => {
      const checkbox = event.target.closest('[data-column-visibility]');
      if (!checkbox) return;
      const id = checkbox.dataset.columnVisibility;
      const hidden = new Set(state.viewConfig.hidden_column_ids || []);
      if (checkbox.checked) hidden.delete(id);
      else hidden.add(id);
      state.viewConfig.hidden_column_ids = Array.from(hidden);
      markViewModified();
      renderColumnControls();
      renderActiveView();
    });
    els.columnControlsList.addEventListener('click', (event) => {
      const button = event.target.closest('[data-move-column]');
      if (button) moveColumnInView(button.dataset.moveColumn, Number(button.dataset.direction));
      const labelsButton = event.target.closest('[data-manage-column-labels]');
      if (labelsButton) {
        const column = state.columns.find((entry) => String(entry.id) === labelsButton.dataset.manageColumnLabels);
        if (column) openLabelsEditor(column);
      }
    });
    document.getElementById('toggleBoardDensity').addEventListener('click', (event) => {
      const button = event.currentTarget;
      const compact = button.getAttribute('aria-pressed') !== 'true';
      button.setAttribute('aria-pressed', compact ? 'true' : 'false');
      app.classList.toggle('is-compact', compact);
      button.querySelector('span').textContent = compact ? 'Espaciar' : 'Compactar';
    });
    els.boardTableContainer.addEventListener('click', (event) => {
      const toggle = event.target.closest('[data-toggle-group]');
      if (toggle) {
        const id = toggle.dataset.toggleGroup;
        if (state.collapsedGroups.has(id)) state.collapsedGroups.delete(id);
        else state.collapsedGroups.add(id);
        renderActiveView();
        return;
      }
      const add = event.target.closest('[data-add-item-group]');
      if (add) openCreateItem(add.dataset.addItemGroup);
      const inlineAdd = event.target.closest('[data-inline-add-group]');
      if (inlineAdd) openCreateItem(inlineAdd.dataset.inlineAddGroup);
      if (event.target.closest('[data-cancel-inline-add]')) {
        state.inlineAddGroupId = '';
        renderTable();
      }
    });
    els.boardTableContainer.addEventListener('submit', (event) => {
      const form = event.target.closest('[data-inline-add-form]');
      if (!form) return;
      event.preventDefault();
      if (form.reportValidity()) submitInlineItem(form);
    });
    els.boardTableContainer.addEventListener('change', (event) => {
      const itemCheckbox = event.target.closest('[data-select-item]');
      if (itemCheckbox) {
        const id = itemCheckbox.dataset.selectItem;
        if (itemCheckbox.checked) state.selectedItemIds.add(id);
        else state.selectedItemIds.delete(id);
        updateSelectionBar();
        return;
      }
      const groupCheckbox = event.target.closest('[data-select-group]');
      if (groupCheckbox) {
        getFilteredItems()
          .filter((item) => String(item.group_id) === groupCheckbox.dataset.selectGroup)
          .forEach((item) => {
            const id = String(item.id);
            if (groupCheckbox.checked) state.selectedItemIds.add(id);
            else state.selectedItemIds.delete(id);
            const rowCheckbox = els.boardTableContainer.querySelector(`[data-select-item="${id}"]`);
            if (rowCheckbox) rowCheckbox.checked = groupCheckbox.checked;
          });
        updateSelectionBar();
      }
    });
    document.getElementById('bulkMoveGroup')?.addEventListener('change', updateSelectionBar);
    document.getElementById('bulkMoveItems')?.addEventListener('click', moveSelectedItems);
    document.getElementById('clearSelection')?.addEventListener('click', () => {
      state.selectedItemIds.clear();
      els.boardTableContainer.querySelectorAll('[data-select-item]').forEach((checkbox) => { checkbox.checked = false; });
      updateSelectionBar();
    });
    [els.boardTableContainer, els.boardRendererArea].forEach((host) => {
      host.addEventListener('change', (event) => {
        const editor = event.target.closest('[data-cell-editor]');
        if (editor && editor.dataset.valueKind !== 'timeline') saveCell(editor);
        const mover = event.target.closest('[data-move-editor]');
        if (mover) moveItem(mover);
      });
      host.addEventListener('click', (event) => {
        const nameButton = event.target.closest('[data-item-name-button]');
        if (nameButton) {
          beginItemNameEdit(nameButton);
          return;
        }
        const cancelName = event.target.closest('[data-cancel-name-edit]');
        if (cancelName) {
          renderActiveView();
          return;
        }
        const save = event.target.closest('[data-save-cell]');
        if (save) {
          const editor = save.closest('[data-cell-editor]');
          if (editor) saveCell(editor);
        }
      });
      host.addEventListener('keydown', (event) => {
      const input = event.target.closest('[data-cell-editor]:not(textarea)');
      if (input && event.key === 'Enter') {
        event.preventDefault();
        input.blur();
      }
      });
    });
    els.boardRendererArea.addEventListener('click', (event) => {
      const shift = event.target.closest('[data-calendar-shift]');
      if (shift) {
        state.calendarMonth = new Date(state.calendarMonth.getFullYear(), state.calendarMonth.getMonth() + Number(shift.dataset.calendarShift), 1);
        renderActiveView();
        return;
      }
      if (event.target.closest('[data-calendar-today]')) {
        state.calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        renderActiveView();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
      const target = event.target;
      if (target instanceof HTMLElement && (target.matches('input, textarea, select, [contenteditable="true"]') || target.closest('dialog[open]'))) return;
      if (els.boardWorkspace.hidden) return;
      event.preventDefault();
      els.boardSearch.focus();
    });
    els.shareUserSearch?.addEventListener('focus', () => {
      if (!els.shareUserSearch.value.trim()) searchUsers('');
    });
    els.shareUserSearch?.addEventListener('input', () => {
      if (selectedShareUser) {
        selectedShareUser = null;
        els.shareUserSelect.value = '';
        els.clearShareUser.hidden = true;
      }
      els.shareUserSearch.classList.remove('has-selection');
      els.shareUserSearch.setAttribute('aria-expanded', 'true');
      window.clearTimeout(state.userSearchTimer);
      const query = els.shareUserSearch.value.trim();
      state.userSearchTimer = window.setTimeout(() => searchUsers(query), 250);
    });
    els.clearShareUser?.addEventListener('click', () => {
      selectedShareUser = null;
      els.shareUserSelect.value = '';
      els.shareUserSearch.value = '';
      els.clearShareUser.hidden = true;
      els.shareUserSearch.focus();
      searchUsers('');
    });
    els.shareUserSearch?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        shareUserSearchRequest += 1;
        els.shareUserResults.hidden = true;
        els.shareUserSearch.setAttribute('aria-expanded', 'false');
        els.shareUserSearch.removeAttribute('aria-busy');
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        els.shareUserResults.querySelector('button')?.focus();
      }
      if (event.key === 'Enter' && !els.shareUserResults.hidden) {
        const first = els.shareUserResults.querySelector('button');
        if (first) { event.preventDefault(); first.click(); }
      }
    });
    els.shareUserResults?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        shareUserSearchRequest += 1;
        els.shareUserResults.hidden = true;
        els.shareUserSearch.setAttribute('aria-expanded', 'false');
        els.shareUserSearch.removeAttribute('aria-busy');
        els.shareUserSearch.focus();
      }
    });
    document.addEventListener('pointerdown', (event) => {
      if (!event.target.closest('.boards-share-person-picker')) {
        shareUserSearchRequest += 1;
        els.shareUserResults.hidden = true;
        els.shareUserSearch.setAttribute('aria-expanded', 'false');
        els.shareUserSearch.removeAttribute('aria-busy');
      }
    });
  }

  function setupExtendedEvents() {
    els.filePreviewDialog.addEventListener('close', clearFilePreviewObjectUrl);
    setupFilePreviewRail();
    [els.itemDetailsDialog, els.filePreviewDialog, els.automationDialog].forEach((dialog) => {
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeDialog(dialog);
      });
    });
    document.getElementById('openAutomations').addEventListener('click', async () => {
      renderAutomationFilterFields();
      renderAutomationActionFields();
      els.automationEditorPanel.hidden = !canManageAutomation();
      els.automationPermissionsNote.hidden = canManageAutomation();
      openDialog(els.automationDialog);
      await loadAutomationData();
    });
    document.getElementById('automationTrigger').addEventListener('change', () => {
      els.automationScheduleField.hidden = els.automationTrigger.value !== 'schedule';
    });
    els.automationFilterField.addEventListener('change', renderAutomationFilterFields);
    els.automationActionType.addEventListener('change', renderAutomationActionFields);
    els.automationForm.addEventListener('submit', createAutomation);
    document.getElementById('refreshAutomationRuns').addEventListener('click', loadAutomationData);
    els.automationList.addEventListener('click', (event) => {
      const statusButton = event.target.closest('[data-automation-status]');
      if (statusButton) {
        updateAutomationStatus(statusButton.dataset.automationStatus, statusButton.dataset.statusAction, statusButton.dataset.version);
        return;
      }
      const runButton = event.target.closest('[data-run-automation]');
      if (runButton && !runButton.disabled) runAutomation(runButton.dataset.runAutomation);
    });

    [els.boardTableContainer, els.boardRendererArea].forEach((host) => {
      host.addEventListener('click', (event) => {
        const details = event.target.closest('[data-open-item-details]');
        if (!details) return;
        if (details.matches('.boards-item-row')) {
          if (event.target.closest('button, a, input, select, textarea, [contenteditable], [role="button"], [data-cell-editor], .boards-item-name-form')) return;
          details.focus();
          openItemDetails(details.dataset.openItemDetails);
          return;
        }
        openItemDetails(details.dataset.openItemDetails);
      });
    });
    els.boardTableContainer.addEventListener('keydown', (event) => {
      const row = event.target.closest('.boards-item-row');
      if (!row || event.target !== row || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault();
      openItemDetails(row.dataset.openItemDetails);
    });
    const detailTabs = [...els.itemDetailsDialog.querySelectorAll('[role="tab"]')];
    const activateDetailTab = (tab, focus = false) => {
      detailTabs.forEach((candidate) => {
        const selected = candidate === tab;
        candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
        candidate.tabIndex = selected ? 0 : -1;
        document.getElementById(candidate.getAttribute('aria-controls')).hidden = !selected;
      });
      if (focus) tab.focus();
    };
    detailTabs.forEach((tab, index) => {
      tab.addEventListener('click', () => activateDetailTab(tab));
      tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? detailTabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : detailTabs.length - 1)) % detailTabs.length;
        activateDetailTab(detailTabs[next], true);
      });
    });
    els.itemCommentForm.addEventListener('submit', submitItemComment);
    els.itemFileForm.addEventListener('submit', submitItemFile);
    els.itemFileColumn.addEventListener('change', renderFileControls);
    els.boardMembersList.addEventListener('click', (event) => {
      const revoke = event.target.closest('[data-revoke-member]');
      if (revoke) revokeBoardMember(revoke.dataset.revokeMember);
    });
    els.itemFilesList.addEventListener('click', (event) => {
      const history = event.target.closest('[data-file-history]');
      if (history) {
        const fileId = history.dataset.fileHistory;
        const key = String(fileId);
        state.fileHistoryVisible[key] = !state.fileHistoryVisible[key];
        renderItemFiles();
        if (state.fileHistoryVisible[key] && !Array.isArray(state.fileVersions[key]) && !state.fileHistoryLoading[key]) loadFileHistory(fileId);
        return;
      }
      const newVersion = event.target.closest('[data-file-target]');
      if (newVersion) {
        const file = filesForItem(state.items.find((item) => String(item.id) === state.detailItemId) || { id: state.detailItemId, cells: {} })
          .find((entry) => String(entry.file_id) === newVersion.dataset.fileTarget);
        if (file?.column_id) els.itemFileColumn.value = String(file.column_id);
        renderFileControls();
        els.itemFileTarget.value = newVersion.dataset.fileTarget;
        els.itemFileInput.focus();
      }
    });
    els.itemRelationForm.addEventListener('submit', addItemRelation);
    els.relationTargetBoard.addEventListener('change', () => loadRelationTargetItems(els.relationTargetBoard.value));
    els.itemRelationsList.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-relation]');
      if (remove) removeRelation(remove.dataset.removeRelation);
    });
    els.itemDependencyForm.addEventListener('submit', addItemDependency);
    els.itemDependenciesList.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-remove-dependency]');
      if (remove) removeDependency(remove.dataset.removeDependency);
    });
    document.getElementById('saveItemParent').addEventListener('click', saveItemParent);
  }

  setupDialogs();
  attachEvents();
  setupExtendedEvents();
  loadBoards();
})();
