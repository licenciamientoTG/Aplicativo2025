(() => {
  'use strict';

  const dropdown = document.getElementById('notificationsDropdown');
  if (!dropdown) return;

  const apiRoot = '/tableros_api';
  const csrfToken = dropdown.dataset.csrfToken || document.getElementById('tablerosApp')?.dataset.csrfToken || '';
  const list = document.getElementById('notificationsList');
  const header = document.getElementById('notificationsHeader');
  const indicator = document.getElementById('notificationsIndicator');
  const trigger = document.getElementById('alertsDropdown');
  let loading = false;

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = String(text);
    return node;
  };

  async function api(path, options = {}) {
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
    try { result = await response.json(); }
    catch (_) { throw new Error(`Error del servidor (HTTP ${response.status}).`); }
    if (!response.ok || result.success === false) {
      throw new Error(result.error?.message || result.message || `Error del servidor (HTTP ${response.status}).`);
    }
    return result;
  }

  function notificationId(item) {
    return item.notification_id ?? item.id;
  }

  function isUnread(item) {
    return item.is_read === true ? false : !(item.read_at || item.read_on || item.status === 'read');
  }

  function boardHref(item) {
    const boardId = item.board_id ?? item.related_board_id;
    if (boardId == null || !/^[A-Za-z0-9_-]+$/.test(String(boardId))) return '';
    const url = new URL(`/tableros/board/${encodeURIComponent(String(boardId))}`, window.location.origin);
    const payload = item.payload && typeof item.payload === 'object' ? item.payload : {};
    if (['file.comment.mention', 'file.comment.created'].includes(item.event_type)) {
      const fileId = item.file_id ?? item.related_file_id ?? payload.file_id ?? payload.fileId;
      const itemId = item.item_id ?? item.related_item_id ?? payload.item_id ?? payload.itemId;
      const commentId = item.file_comment_id ?? item.comment_id ?? payload.file_comment_id ?? payload.comment_id ?? payload.commentId;
      if (fileId != null && /^[0-9]+$/.test(String(fileId))) url.searchParams.set('notification_file_id', String(fileId));
      if (itemId != null && /^[0-9]+$/.test(String(itemId))) url.searchParams.set('notification_item_id', String(itemId));
      if (commentId != null && /^[0-9]+$/.test(String(commentId))) url.searchParams.set('notification_comment_id', String(commentId));
    }
    return `${url.pathname}${url.search}`;
  }

  function updateUnread(count) {
    const unread = Math.max(0, Number(count) || 0);
    indicator.hidden = unread === 0;
    indicator.textContent = unread > 99 ? '99+' : String(unread);
    trigger.setAttribute('aria-label', unread ? `${unread} notificaciones sin leer` : 'Notificaciones');
    header.textContent = unread === 1 ? '1 notificación nueva' : `${unread} notificaciones nuevas`;
  }

  function render(items) {
    list.replaceChildren();
    if (!items.length) {
      list.append(element('div', 'list-group-item text-muted', 'No tienes notificaciones.'));
      return;
    }

    items.forEach((item) => {
      const href = boardHref(item);
      const row = element(href ? 'a' : 'div', `list-group-item${isUnread(item) ? ' bg-light' : ''}`);
      if (href) row.href = href;
      const eventTitles = {
        'file.comment.mention': 'Te mencionaron en un comentario de archivo',
        'file.comment.created': 'Nuevo comentario en un archivo',
        'comment.created': 'Nuevo comentario en un elemento',
        'board.member.added': 'Compartieron un tablero contigo'
      };
      const title = item.title || item.message || item.body || item.content || eventTitles[item.event_type] || 'Notificación';
      row.append(element('div', 'text-dark', title));
      const actor = item.actor_display_name ? `${item.actor_display_name} · ` : '';
      const fileName = item.payload?.file_name ? `Archivo: ${item.payload.file_name}` : '';
      const detail = item.detail || item.description || (actor + (fileName || item.payload?.board_name || item.board_name || ''));
      if (detail && detail !== title) row.append(element('div', 'text-muted small mt-1', detail));
      if (item.created_at) {
        const date = element('div', 'text-muted small mt-1');
        date.textContent = item.created_at;
        row.append(date);
      }
      if (isUnread(item)) row.setAttribute('aria-label', `${title} (sin leer)`);
      const id = notificationId(item);
      if (id != null) {
        row.addEventListener('click', async (event) => {
          event.preventDefault();
          try {
            await api('/mark_notification_read', {
              method: 'POST',
              body: JSON.stringify({ csrf_token: csrfToken, notification_id: id })
            });
            await loadNotifications();
          } catch (_) {
            // Keep the notification visible and unread when the server cannot save the read state.
          }
          if (href) window.location.assign(href);
        });
      }
      list.append(row);
    });
  }

  async function loadNotifications() {
    if (loading) return;
    loading = true;
    try {
      const result = await api('/notifications');
      const data = result.data && !Array.isArray(result.data) ? result.data : result;
      const items = Array.isArray(data.items) ? data.items : Array.isArray(result.data) ? result.data : [];
      const unreadCount = data.unread_count ?? items.filter(isUnread).length;
      updateUnread(unreadCount);
      render(items);
    } catch (error) {
      list.replaceChildren(element('div', 'list-group-item text-danger', error.message || 'No se pudieron cargar las notificaciones.'));
      header.textContent = 'Notificaciones';
    } finally {
      loading = false;
    }
  }

  loadNotifications();
  dropdown.addEventListener('show.bs.dropdown', loadNotifications);
})();
