<?php

/**
 * Rentabilidad mensual para el RESUMEN DIRECCIÓN de /merma/ventas. Todo sale
 * de las copias consolidadas de ControlGas en SG12 (mismo servidor que TG,
 * sin OPENQUERY a estaciones), así que el mes completo se resuelve en ~1 s:
 *
 *   - Venta:  SG12.Ventas.mtoven — precio de bomba, IVA incluido.
 *   - Costo:  SG12.Movimientos tip 11 (recepciones) can × pre — sin IVA, con
 *             IEPS; validado 1:1 contra el SubTotal de la factura del proveedor.
 *   - IVA:    tasa de cada estación (8% frontera / 16%) deducida de sus
 *             documentos de compra de los últimos 120 días
 *             (Documentos.mtoiva / mto), no solo del mes: ver abajo.
 *   - Factura / proveedor: DocumentosC.satuid → TG.dbo.FacturasRecibidas.UUID
 *             (Total con IVA, EmisorNombre). Una recepción sin satuid o sin
 *             CFDI importado cuenta como "sin factura".
 *   - Descuentos: notas de crédito (CFDI tipo E) de esos mismos proveedores
 *             fechadas en el mes, sin las aplicaciones de anticipo (que no
 *             son descuento, solo forma de pago).
 *
 * En el mes en curso la mayoría de las recepciones todavía no tienen
 * precio (pre = 0): ControlGas lo llena al capturar la factura. Esas
 * recepciones NO entran al costo promedio (si no, abaratan el litro a casi
 * cero) y se reportan aparte como "sin precio".
 *
 * El estímulo fiscal del IEPS lo aplica el importador (Tesoro, MGC…) y ya
 * viene en el precio de la factura: las estaciones de frontera pagan casi lo
 * mismo por litro que las del interior. No se agrega aparte. Praxedis y
 * Colosio no están en SG12 (se cargan por PDF en merma_diaria) y quedan sin
 * datos.
 */
class VentasRentabilidadModel extends Model
{
    /** Rango plausible de precio de venta por litro ($, IVA incluido). */
    private const PRECIO_MIN = 10;
    private const PRECIO_MAX = 40;

    /** Nombre del "proveedor" de las recepciones sin CFDI ligado. */
    public const SIN_FACTURA = 'Sin factura ligada';

    /**
     * @return array [
     *   'estaciones' => [codgas => [
     *       'tasa_iva' => ?float,
     *       'familias' => [familia => ['litros_venta','pesos_venta','litros_compra','litros_con_precio','costo_compra']],
     *       'factura_total','docs','docs_sin_factura','costo_sin_factura','docs_sin_precio','litros_sin_precio','ventas_atipicas',
     *   ]],
     *   'diario'      => ['Y-m-d' => [codgas => [familia => ['litros' => float, 'pesos' => float]]]],
     *   'compras_dia' => ['Y-m-d' => [codgas => [familia => ['litros' => float, 'costo' => float]]]]  (solo con precio),
     *   'proveedores' => [['proveedor','codgas','familia','litros','costo'], ...]  (solo con precio),
     *   'prov_docs'   => [proveedor => ['docs' => int, 'facturado' => float]],
     *   'descuentos'  => [proveedor => float]  (sin IVA),
     * ]
     */
    public function get_mes(string $desde, string $hasta): array
    {
        $d = dateToInt($desde);
        $h = dateToInt($hasta);
        $familia = $this->familiaSql('codprd');
        $prds = implode(',', array_merge(...array_values(MermaDiariaModel::FAMILIAS)));
        // fch de ControlGas → fecha: inverso de dateToInt()
        $fecha = "CONVERT(char(10), DATEADD(day, fch - 1, '1900-01-01'), 23)";

        $out = [];

        // Un renglón de Ventas (isla × turno) con importe absurdo para sus
        // litros es un error de captura en la estación (caso real: Clara,
        // 05-oct-2026, isla 271 turno 41: $1,690,699 por 616 L de diesel,
        // mientras sus Despachos suman bien). Esos litros se valoran al
        // precio promedio del mes de los renglones sanos de la misma estación
        // y familia, y se reportan para corregirlos en origen.
        $ok = 'v.canven > 0 AND v.mtoven / v.canven BETWEEN ' . self::PRECIO_MIN . ' AND ' . self::PRECIO_MAX;
        $ventas = $this->sql->select(
            "SELECT CONVERT(char(10), DATEADD(day, v.fch - 1, '1900-01-01'), 23) AS fecha,
                    i.codgas, {$this->familiaSql('v.codprd')} AS familia,
                    SUM(v.canven) AS litros,
                    SUM(CASE WHEN $ok THEN v.mtoven END) AS pesos_ok,
                    SUM(CASE WHEN $ok THEN v.canven END) AS litros_ok,
                    SUM(CASE WHEN v.canven > 0 AND NOT ($ok) THEN 1 ELSE 0 END) AS atipicos
             FROM [SG12].[dbo].[Ventas] v
             JOIN [SG12].[dbo].[Islas] i ON i.cod = v.codisl
             WHERE v.fch BETWEEN ? AND ? AND v.codprd IN ($prds)
             GROUP BY v.fch, i.codgas, {$this->familiaSql('v.codprd')};",
            [$d, $h]) ?: [];

        // Precio promedio del mes (renglones sanos) por estación y familia
        $sano = [];
        foreach ($ventas as $r) {
            $k = $r['codgas'] . '|' . $r['familia'];
            $sano[$k]['l'] = ($sano[$k]['l'] ?? 0) + (float) $r['litros_ok'];
            $sano[$k]['p'] = ($sano[$k]['p'] ?? 0) + (float) $r['pesos_ok'];
        }
        $diario = [];
        foreach ($ventas as $r) {
            $cod = (int) $r['codgas'];
            $k   = $r['codgas'] . '|' . $r['familia'];
            $precioMes = $sano[$k]['l'] > 0 ? $sano[$k]['p'] / $sano[$k]['l'] : 0.0;
            $litros = (float) $r['litros'];
            $pesos  = (float) $r['pesos_ok'] + ($litros - (float) $r['litros_ok']) * $precioMes;

            $diario[$r['fecha']][$cod][$r['familia']] = ['litros' => $litros, 'pesos' => $pesos];

            $f = &$this->fila($out, $cod, $r['familia']);
            $f['litros_venta'] += $litros;
            $f['pesos_venta']  += $pesos;
            unset($f);
            $out[$cod]['ventas_atipicas'] += (int) $r['atipicos'];
        }
        ksort($diario);

        $compras = $this->sql->select(
            "SELECT $fecha AS fecha, codgas, $familia AS familia, SUM(can) AS litros,
                    SUM(CASE WHEN pre > 0 THEN can END) AS litros_precio,
                    SUM(CASE WHEN pre > 0 THEN can * pre END) AS costo
             FROM [SG12].[dbo].[Movimientos]
             WHERE fch BETWEEN ? AND ? AND tip = 11 AND can > 0 AND codprd IN ($prds)
             GROUP BY fch, codgas, $familia;",
            [$d, $h]) ?: [];
        $comprasDia = [];
        foreach ($compras as $r) {
            $cod = (int) $r['codgas'];
            $f = &$this->fila($out, $cod, $r['familia']);
            $f['litros_compra']     += (float) $r['litros'];
            $f['litros_con_precio'] += (float) $r['litros_precio'];
            $f['costo_compra']      += (float) $r['costo'];
            unset($f);
            if ((float) $r['litros_precio'] > 0) {
                $comprasDia[$r['fecha']][$cod][$r['familia']] = [
                    'litros' => (float) $r['litros_precio'],
                    'costo'  => (float) $r['costo'],
                ];
            }
        }
        ksort($comprasDia);

        // Una fila por documento de compra (recepción) con su factura, si la
        // hay. La misma CTE alimenta el resumen por estación y el desglose
        // por proveedor.
        $cteDocs = "WITH m AS (
                 SELECT codgas, nro, $familia AS familia,
                        SUM(can) AS litros, SUM(can * pre) AS costo,
                        SUM(CASE WHEN pre > 0 THEN can END) AS litros_precio,
                        MAX(CASE WHEN pre > 0 THEN 0 ELSE 1 END) AS sin_precio,
                        SUM(CASE WHEN pre > 0 THEN 0 ELSE can END) AS litros_sin_precio
                 FROM [SG12].[dbo].[Movimientos]
                 WHERE fch BETWEEN ? AND ? AND tip = 11 AND can > 0 AND codprd IN ($prds)
                 GROUP BY codgas, nro, $familia
             ),
             x AS (
                 SELECT m.*, f.UUID, f.Total, f.EmisorNombre
                 FROM m
                 LEFT JOIN [SG12].[dbo].[DocumentosC] c
                        ON c.nro = m.nro AND c.codgas = m.codgas AND c.tip = 1
                 LEFT JOIN [TG].[dbo].[FacturasRecibidas] f
                        ON f.UUID = NULLIF(c.satuid, '')
             )";

        // Por estación: un documento puede traer varias familias, así que
        // primero se colapsa a una fila por documento.
        $docs = $this->sql->select(
            "$cteDocs,
             porDoc AS (
                 SELECT codgas, nro, MAX(UUID) AS UUID, MAX(Total) AS Total,
                        SUM(costo) AS costo, MAX(sin_precio) AS sin_precio,
                        SUM(litros_sin_precio) AS litros_sin_precio
                 FROM x GROUP BY codgas, nro
             )
             SELECT codgas,
                    COUNT(*) AS docs,
                    SUM(CASE WHEN UUID IS NULL THEN 1 ELSE 0 END) AS docs_sin_factura,
                    SUM(CASE WHEN UUID IS NULL THEN costo ELSE 0 END) AS costo_sin_factura,
                    SUM(Total) AS factura_total,
                    SUM(sin_precio) AS docs_sin_precio,
                    SUM(litros_sin_precio) AS litros_sin_precio
             FROM porDoc GROUP BY codgas;",
            [$d, $h]) ?: [];
        foreach ($docs as $r) {
            $cod = (int) $r['codgas'];
            $this->estacion($out, $cod);
            $out[$cod]['docs']              = (int) $r['docs'];
            $out[$cod]['docs_sin_precio']   = (int) $r['docs_sin_precio'];
            $out[$cod]['litros_sin_precio'] = (float) $r['litros_sin_precio'];
            $out[$cod]['docs_sin_factura']  = (int) $r['docs_sin_factura'];
            $out[$cod]['costo_sin_factura'] = (float) $r['costo_sin_factura'];
            $out[$cod]['factura_total']     = (float) $r['factura_total'];
        }

        // Por proveedor × estación × familia (solo litros con precio, para
        // que el $/L no se abarate con las recepciones aún sin capturar)
        $sinFactura = self::SIN_FACTURA;
        $provRows = $this->sql->select(
            "$cteDocs
             SELECT ISNULL(EmisorNombre, '$sinFactura') AS proveedor, codgas, familia,
                    SUM(litros_precio) AS litros, SUM(CASE WHEN litros_precio > 0 THEN costo END) AS costo
             FROM x
             WHERE litros_precio > 0
             GROUP BY ISNULL(EmisorNombre, '$sinFactura'), codgas, familia;",
            [$d, $h]) ?: [];
        $proveedores = array_map(fn($r) => [
            'proveedor' => $r['proveedor'],
            'codgas'    => (int) $r['codgas'],
            'familia'   => $r['familia'],
            'litros'    => (float) $r['litros'],
            'costo'     => (float) $r['costo'],
        ], $provRows);

        // Descargas y total facturado (con IVA) por proveedor; cada CFDI
        // cuenta una sola vez aunque cubra varias familias.
        $provDocs = [];
        $rows = $this->sql->select(
            "$cteDocs,
             porDoc AS (
                 SELECT codgas, nro, MAX(EmisorNombre) AS EmisorNombre, MAX(UUID) AS UUID, MAX(Total) AS Total
                 FROM x GROUP BY codgas, nro
             )
             SELECT ISNULL(EmisorNombre, '$sinFactura') AS proveedor, COUNT(*) AS docs, SUM(Total) AS facturado
             FROM porDoc GROUP BY ISNULL(EmisorNombre, '$sinFactura');",
            [$d, $h]) ?: [];
        foreach ($rows as $r) {
            $provDocs[$r['proveedor']] = ['docs' => (int) $r['docs'], 'facturado' => (float) $r['facturado']];
        }

        // Notas de crédito del mes de los proveedores de combustible, sin
        // aplicaciones de anticipo. Importe de concepto = sin IVA.
        $descuentos = [];
        $rows = $this->sql->select(
            "SELECT f.EmisorNombre AS proveedor, SUM(k.Importe) AS importe
             FROM [TG].[dbo].[FacturasRecibidas] f
             JOIN [TG].[dbo].[FacturasRecibidasConceptos] k ON k.FacturaId = f.Id
             WHERE f.TipoDeComprobante = 'E'
               AND f.Fecha >= ? AND f.Fecha < DATEADD(day, 1, CAST(? AS date))
               AND k.Descripcion NOT LIKE '%anticipo%'
               AND f.EmisorRfc IN (
                   SELECT DISTINCT f2.EmisorRfc
                   FROM [SG12].[dbo].[DocumentosC] c
                   JOIN [TG].[dbo].[FacturasRecibidas] f2 ON f2.UUID = NULLIF(c.satuid, '')
                   WHERE c.tip = 1 AND c.fch BETWEEN ? AND ?)
             GROUP BY f.EmisorNombre;",
            [$desde, $hasta, $d, $h]) ?: [];
        foreach ($rows as $r) $descuentos[$r['proveedor']] = (float) $r['importe'];

        // Tasa de IVA por estación: ventana de 120 días hasta el fin del
        // periodo, para no depender de que las recepciones del mes ya tengan
        // precio. El cociente real queda en ~7.75% / ~15.5% (el IVA no se
        // cobra sobre toda la base); se redondea a la tasa nominal.
        $tasas = $this->sql->select(
            "SELECT c.codgas, SUM(d.mtoiva) / NULLIF(SUM(d.mto), 0) AS ratio
             FROM [SG12].[dbo].[DocumentosC] c
             JOIN [SG12].[dbo].[Documentos] d ON d.nro = c.nro AND d.codgas = c.codgas AND d.tip = c.tip
             WHERE c.tip = 1 AND c.fch BETWEEN ? AND ? AND d.mto > 0 AND d.codprd IN ($prds)
             GROUP BY c.codgas;",
            [$h - 120, $h]) ?: [];
        foreach ($tasas as $r) {
            $cod = (int) $r['codgas'];
            if (!isset($out[$cod]) || $r['ratio'] === null) continue;
            $out[$cod]['tasa_iva'] = (float) $r['ratio'] < 0.12 ? 0.08 : 0.16;
        }

        return [
            'estaciones'  => $out,
            'diario'      => $diario,
            'compras_dia' => $comprasDia,
            'proveedores' => $proveedores,
            'prov_docs'   => $provDocs,
            'descuentos'  => $descuentos,
        ];
    }

    /** CASE codprd → familia, con los mismos códigos que merma_diaria. */
    private function familiaSql(string $col): string
    {
        $sql = 'CASE';
        foreach (MermaDiariaModel::FAMILIAS as $fam => $codes) {
            $sql .= " WHEN $col IN (" . implode(',', $codes) . ") THEN '$fam'";
        }
        return $sql . ' END';
    }

    private function estacion(array &$out, int $cod): void
    {
        $out[$cod] ??= [
            'tasa_iva' => null, 'familias' => [],
            'factura_total' => 0.0, 'docs' => 0, 'docs_sin_factura' => 0, 'costo_sin_factura' => 0.0,
            'docs_sin_precio' => 0, 'litros_sin_precio' => 0.0, 'ventas_atipicas' => 0,
        ];
    }

    private function &fila(array &$out, int $cod, string $familia): array
    {
        $this->estacion($out, $cod);
        $out[$cod]['familias'][$familia] ??= [
            'litros_venta' => 0.0, 'pesos_venta' => 0.0, 'litros_compra' => 0.0,
            'litros_con_precio' => 0.0, 'costo_compra' => 0.0,
        ];
        return $out[$cod]['familias'][$familia];
    }
}
