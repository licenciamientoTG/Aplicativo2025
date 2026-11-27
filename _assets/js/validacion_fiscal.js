// Reglas del SAT para RFC y régimen fiscal, y validación de listas de correos (separados por ;).
// Espejo de _assets/classes/ValidacionFiscal.class.php: si cambias una regla aquí,
// cámbiala allá también. Expone window.ValidacionFiscal (y module.exports para
// pruebas en node: node tests/validacion_fiscal_test.js).
(function () {
    var CORREO_MAX = 200;
    var GENERICOS = {
        XAXX010101000: 'RFC genérico (público en general)',
        XEXX010101000: 'RFC genérico (extranjero)'
    };
    var CORREO_REGEX = /^[A-Za-z0-9._%+\-']+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}$/;

    function errorFecha(aammdd) {
        var aa = parseInt(aammdd.slice(0, 2), 10);
        var mm = parseInt(aammdd.slice(2, 4), 10);
        var dd = parseInt(aammdd.slice(4, 6), 10);
        if (mm < 1 || mm > 12) {
            return 'La fecha «' + aammdd + '» (AAMMDD) no existe: el mes ' + aammdd.slice(2, 4) + ' no es válido';
        }
        var diasMes = [31, aa % 4 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][mm - 1];
        if (dd < 1 || dd > diasMes) {
            return 'La fecha «' + aammdd + '» (AAMMDD) no existe: el día ' + aammdd.slice(4, 6) + ' no es válido para ese mes';
        }
        return null;
    }

    // { nivel: 'ok'|'generico'|'aviso'|'error', mensaje, rfc }
    function rfc(valor) {
        var original = String(valor == null ? '' : valor).trim();
        var r = original.toUpperCase();
        var res = function (nivel, mensaje) { return { nivel: nivel, mensaje: mensaje, rfc: r }; };

        if (r === '') return res('error', 'Sin RFC capturado');
        if (/[\s\-]/.test(r)) return res('error', 'Tiene espacios o guiones; el RFC va todo junto');
        var raros = r.match(/[^A-Z0-9Ñ&]/g);
        if (raros) {
            return res('error', 'Tiene caracteres no permitidos: ' + raros.filter(function (c, i) { return raros.indexOf(c) === i; }).join(' '));
        }
        var len = Array.from(r).length;
        if (len !== 12 && len !== 13) {
            return res('error', 'Tiene ' + len + ' caracteres; debe tener 12 (persona moral) o 13 (persona física)');
        }
        if (GENERICOS[r]) return res('generico', GENERICOS[r]);

        var n = len - 9;
        var letras = r.slice(0, n);
        if (!/^[A-ZÑ&]+$/.test(letras)) {
            return res('error', 'Los primeros ' + n + ' caracteres deben ser letras (' + (n === 4 ? 'persona física' : 'persona moral') + '); tiene «' + letras + '»');
        }
        var fecha = r.slice(n, n + 6);
        if (!/^\d{6}$/.test(fecha)) {
            return res('error', 'Después de las letras van 6 dígitos de fecha (AAMMDD); tiene «' + fecha + '»');
        }
        var ef = errorFecha(fecha);
        if (ef) return res('error', ef);

        var homoclave = r.slice(n + 6);
        if (!/^[A-Z0-9Ñ&]{2}[0-9A]$/.test(homoclave)) {
            var pista = homoclave.slice(-1) === 'O' ? ' (¿una letra O en lugar de cero?)' : '';
            return res('error', 'La homoclave «' + homoclave + '» no es válida: debe terminar en un dígito o en A' + pista);
        }
        if (original !== r) return res('aviso', 'Está capturado en minúsculas');
        return res('ok', '');
    }

    // { nivel: 'ok'|'aviso'|'error', mensaje, normalizado, invalidos }
    function correos(valor) {
        var original = String(valor == null ? '' : valor).trim();
        var partes = original.split(/[;,\s]+/).map(function (s) { return s.trim(); }).filter(Boolean);
        var invalidos = [];
        var vistos = [];
        partes.forEach(function (c) {
            if (!CORREO_REGEX.test(c)) invalidos.push(c);
            var low = c.toLowerCase();
            if (vistos.indexOf(low) < 0) vistos.push(low);
        });
        var normalizado = vistos.join(';');
        var res = function (nivel, mensaje) {
            return { nivel: nivel, mensaje: mensaje, normalizado: normalizado, invalidos: invalidos };
        };

        if (invalidos.length) {
            return res('error', (invalidos.length === 1 ? 'Correo no válido: ' : 'Correos no válidos: ') +
                invalidos.map(function (c) { return '«' + c + '»'; }).join(', '));
        }
        if (normalizado.length > CORREO_MAX) {
            return res('error', 'Los correos suman ' + normalizado.length + ' caracteres; el máximo es ' + CORREO_MAX);
        }
        if (original.toLowerCase() !== normalizado) {
            return res('aviso', 'Se puede limpiar: separadores con coma, espacios, saltos de línea o correos repetidos');
        }
        return res('ok', '');
    }

    // Catálogo c_RegimenFiscal (CFDI 4.0): clave → [descripción, aplica 'F' | 'M' | 'FM']
    var REGIMENES = {
        '601': ['General de Ley Personas Morales', 'M'],
        '603': ['Personas Morales con Fines no Lucrativos', 'M'],
        '605': ['Sueldos y Salarios e Ingresos Asimilados a Salarios', 'F'],
        '606': ['Arrendamiento', 'F'],
        '607': ['Régimen de Enajenación o Adquisición de Bienes', 'F'],
        '608': ['Demás ingresos', 'F'],
        '610': ['Residentes en el Extranjero sin Establecimiento Permanente en México', 'FM'],
        '611': ['Ingresos por Dividendos (socios y accionistas)', 'F'],
        '612': ['Personas Físicas con Actividades Empresariales y Profesionales', 'F'],
        '614': ['Ingresos por intereses', 'F'],
        '615': ['Régimen de los ingresos por obtención de premios', 'F'],
        '616': ['Sin obligaciones fiscales', 'F'],
        '620': ['Sociedades Cooperativas de Producción que optan por diferir sus ingresos', 'M'],
        '621': ['Incorporación Fiscal', 'F'],
        '622': ['Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras', 'M'],
        '623': ['Opcional para Grupos de Sociedades', 'M'],
        '624': ['Coordinados', 'M'],
        '625': ['Actividades Empresariales con ingresos a través de Plataformas Tecnológicas', 'F'],
        '626': ['Régimen Simplificado de Confianza', 'FM']
    };
    var REGIMEN_RFC_GENERICO = '616';

    // { nivel: 'ok'|'vacio'|'aviso'|'error', mensaje, regimen }
    function regimen(valor, rfcValor) {
        var original = String(valor == null ? '' : valor);
        var clave = original.trim();
        var res = function (nivel, mensaje) { return { nivel: nivel, mensaje: mensaje, regimen: clave }; };

        if (clave === '') return res('vacio', 'Sin régimen fiscal capturado (se necesita para facturar CFDI 4.0)');
        if (!Object.prototype.hasOwnProperty.call(REGIMENES, clave)) {
            return res('error', 'La clave «' + clave + '» no existe en el catálogo de regímenes fiscales del SAT');
        }

        var vRfc = rfc(rfcValor);
        if (vRfc.nivel === 'generico') {
            if (clave !== REGIMEN_RFC_GENERICO) {
                return res('error', 'Con RFC genérico el régimen debe ser 616 - Sin obligaciones fiscales');
            }
        } else if (vRfc.nivel !== 'error') {
            var fisica = Array.from(vRfc.rfc).length === 13;
            var aplica = REGIMENES[clave][1];
            if (fisica && aplica === 'M') {
                return res('error', 'El régimen ' + clave + ' es solo para personas morales y el RFC es de persona física (13 caracteres)');
            }
            if (!fisica && aplica === 'F') {
                return res('error', 'El régimen ' + clave + ' es solo para personas físicas y el RFC es de persona moral (12 caracteres)');
            }
        }

        if (original !== clave) return res('aviso', 'Tiene espacios; se guardará «' + clave + '»');
        return res('ok', '');
    }

    var ValidacionFiscal = {
        rfc: rfc, correos: correos, regimen: regimen,
        REGIMENES: REGIMENES, CORREO_MAX: CORREO_MAX
    };
    if (typeof window !== 'undefined') window.ValidacionFiscal = ValidacionFiscal;
    if (typeof module !== 'undefined' && module.exports) module.exports = ValidacionFiscal;
})();
