(function () {
    'use strict';

    var storageKey = 'totalgas-theme';
    var root = document.documentElement;

    function applyTheme(theme, persist) {
        var isDark = theme === 'dark';
        root.setAttribute('data-theme', isDark ? 'dark' : 'light');
        if (document.body) {
            document.body.setAttribute('data-theme', isDark ? 'dark' : 'light');
        }
        document.querySelectorAll('#themeSwitch').forEach(function (control) {
            control.checked = isDark;
            control.setAttribute('aria-label', isDark ? 'Activar modo claro' : 'Activar modo oscuro');
            control.setAttribute('aria-checked', isDark ? 'true' : 'false');
        });
        if (persist) {
            try {
                localStorage.setItem(storageKey, isDark ? 'dark' : 'light');
            } catch (error) {
                // The selected theme remains active for this page when storage is unavailable.
            }
        }
    }

    var savedTheme = 'light';
    try {
        savedTheme = localStorage.getItem(storageKey) === 'dark' ? 'dark' : 'light';
    } catch (error) {
        // Default to light when storage is unavailable.
    }
    applyTheme(savedTheme, false);

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('#themeSwitch')) {
            applyTheme(event.target.checked ? 'dark' : 'light', true);
        }
    });
})();
