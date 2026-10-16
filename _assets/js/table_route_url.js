/**
 * tableRouteUrl(selector, name, params): URL de una tabla (DataTables) con routeUrl(),
 * pero sin romper la página si la tabla no está o si le falta un dato.
 *
 * Varios JS (p. ej. administration.js) inicializan sus tablas al cargarse, en todas las
 * páginas que los incluyen, y arman la URL con valores de inputs que solo existen en una
 * de ellas. routeUrl() lanza si un parámetro falta o no es válido, y eso detendría el resto
 * del archivo. Aquí:
 *   - la tabla no está en la página, o un parámetro viene vacío / no es válido -> '' (sin petición)
 *   - la ruta no existe -> lanza igual que routeUrl() (es un error de programación)
 *
 *   url: tableRouteUrl('#datatables_anticipos', 'administration.datatables_anticipos',
 *                      { from: $('input#from').val(), until: $('input#until').val() })
 */
(function (global) {
    function tableRouteUrl(selector, name, params) {
        if (!(global.ROUTES || {})[name]) {
            throw new Error("Ruta no definida: '" + name + "'");
        }
        if (!global.$(selector).length) {
            return '';
        }
        params = params || {};
        for (var key in params) {
            if (params[key] === undefined || params[key] === null || params[key] === '') {
                return '';
            }
        }
        try {
            return global.routeUrl(name, params);
        } catch (e) {
            return '';
        }
    }

    global.tableRouteUrl = tableRouteUrl;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = tableRouteUrl;
    }
})(typeof window !== 'undefined' ? window : globalThis);
