// Badge de Mis Recepciones en header y sidebar: facturas que Abastos ligó a
// recepciones programadas de la estación del usuario desde su última visita
// a la vista. Solo se incluye en el layout para usuarios con estación
// asignada y permiso 84; al entrar a /station_portal/mis_recepciones el
// contador vuelve a 0.
(() => {
  'use strict';

  const badges = document.querySelectorAll('.js-alerta-recepciones');
  if (!badges.length) return;

  fetch(routeUrl('station_portal.alertas'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : null))
    .then((resp) => {
      const total = resp ? parseInt(resp.total, 10) || 0 : 0;
      if (!total) return;
      const texto = total > 99 ? '99+' : String(total);
      badges.forEach((b) => {
        b.textContent = texto;
        b.hidden = false;
      });
      const navItem = document.getElementById('navAlertaRecepciones');
      if (navItem) {
        navItem.hidden = false;
        navItem.querySelector('a')?.setAttribute('title', `${total} factura${total === 1 ? '' : 's'} nueva${total === 1 ? '' : 's'} en tus recepciones`);
      }
    })
    .catch(() => {});
})();
