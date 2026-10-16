/**
 * routeUrl(name, params): equivalente en JS de url() de Twig.
 * Usa window.ROUTES, que base.html publica desde Router::export().
 *
 *   routeUrl('stations.readings')        -> '/stations/readings'
 *   routeUrl('supply.payment', {id: 15}) -> '/supply/payments/15'
 */
(function (global) {
    var TYPES = {
        int: /^[0-9]+$/,
        str: /^[^\/]+$/,
        slug: /^[A-Za-z0-9_-]+$/,
        path: /^.+$/
    };

    function routeUrl(name, params) {
        var routes = global.ROUTES || {};
        var route = routes[name];
        if (!route) {
            throw new Error("Ruta no definida: '" + name + "'");
        }
        params = params || {};

        var path = route.pattern.replace(/<(?:(\w+):)?(\w+)>/g, function (_, type, key) {
            type = type || 'str';
            if (!(key in params)) {
                throw new Error("Falta el parámetro '" + key + "' para la ruta '" + name + "'");
            }
            var value = String(params[key]);
            if (!TYPES[type].test(value)) {
                throw new Error("Valor inválido '" + value + "' para '" + key + "' en la ruta '" + name + "'");
            }
            return type === 'path' ? value : encodeURIComponent(value);
        });

        return '/' + path;
    }

    global.routeUrl = routeUrl;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = routeUrl;
    }
})(typeof window !== 'undefined' ? window : globalThis);
