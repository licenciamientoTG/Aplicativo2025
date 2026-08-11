<?php

/**
 * Presupuesto de ventas para /merma/ventas, tomado de
 * hrms.dbo.incentives_presupuestoventa (equipo de Incentivos, solo lectura
 * para este proyecto). Reemplaza a TGV2.dbo.Budget en este flujo.
 *
 * team_key es inconsistente en origen (unas filas traen el número de
 * estación, otras el nombre en texto libre) y no tiene FK a
 * TG.dbo.Estaciones.Codigo. resolverCodgas() replica la regla validada
 * manualmente contra las BDs reales, en este orden:
 *   1. team_key numérico == sufijo de Estaciones.Estacion (quitando la letra
 *      inicial E/P y ceros a la izquierda). Ej: '11007' → 'E11007' → 3.
 *   2. Si no matchea lo anterior: team_key == prefijo numérico de
 *      Estaciones.email (patrón es{N}_...@totalgas.com). Único caso real:
 *      '12900' → 'es12900_elcastano@totalgas.com' → 32 (El Castaño).
 *   3. team_key es texto (nombre de estación): diccionario manual, no hay
 *      forma confiable de hacer match automático por texto libre.
 * team_key sin match en ninguna de las 3 reglas se ignora (esa estación
 * simplemente no tiene presupuesto ese mes).
 */
class IncentivesPresupuestoModel extends Model
{
    /** Caso especial: comparte Estacion=E05170 con Codigo=20 ("NO FUNCIONA",
     *  registro dado de baja/duplicado) — el sufijo real es Plutarco (21). */
    private const TEAM_KEY_ESPECIAL = ['5170' => 21];

    /** team_key en texto (nombre de estación) sin match numérico/email posible. */
    private const TEAM_KEY_POR_NOMBRE = [
        'Clara'            => 26,
        'Colosio'          => 199,
        'Ejercito'         => 23,
        'Fuentes'          => 25,
        'Gabriela Mistral' => 39,
        'Jarudo'           => 29,
        'Jesus Maria'      => 38,
        'Picachos'         => 34,
        'Praxedis'         => 40,
        'Puertecito'       => 37,
        'San Rafael'       => 36,
        'Santiago'         => 28,
        'Satelite'         => 24,
        'Solis'            => 27,
        'Travel Center'    => 33,
        'Ventanas'         => 35,
        'Villahumada'      => 31,
    ];

    /**
     * Presupuesto del mes por estación y familia.
     *
     * @return array [codgas => ['maxima' => float, 'super' => float, 'diesel' => float]]
     */
    public function getPresupuesto(int $mes, int $anio): array
    {
        $mesBudget = sprintf('%04d-%02d-01', $anio, $mes);

        $query = "SELECT team_key, maxima, gasolina_super, diesel
                   FROM [hrms].[dbo].[incentives_presupuestoventa]
                   WHERE mes = ?;";
        $filas = $this->sql->select($query, [$mesBudget]) ?: [];
        if ($filas === []) return [];

        $estaciones = $this->sql->select(
            'SELECT Codigo, Estacion, email FROM [TG].[dbo].[Estaciones] WHERE Codigo NOT IN (0, 4, 20);'
        ) ?: [];

        $porSufijo = [];
        $porEmail  = [];
        foreach ($estaciones as $e) {
            $sufijo = ltrim(preg_replace('/^[A-Za-z]/', '', (string) $e['Estacion']), '0');
            if ($sufijo !== '') $porSufijo[$sufijo] = (int) $e['Codigo'];

            if (preg_match('/^es0*(\d+)_/i', (string) $e['email'], $m)) {
                $porEmail[$m[1]] = (int) $e['Codigo'];
            }
        }

        $presupuesto = [];
        foreach ($filas as $f) {
            $teamKey = trim((string) $f['team_key']);
            $codgas  = $this->resolverCodgas($teamKey, $porSufijo, $porEmail);
            if ($codgas === null) continue;

            $presupuesto[$codgas] = [
                'maxima' => (float) $f['maxima'],
                'super'  => (float) $f['gasolina_super'],
                'diesel' => (float) $f['diesel'],
            ];
        }

        return $presupuesto;
    }

    private function resolverCodgas(string $teamKey, array $porSufijo, array $porEmail): ?int
    {
        if (isset(self::TEAM_KEY_ESPECIAL[$teamKey])) return self::TEAM_KEY_ESPECIAL[$teamKey];

        if (ctype_digit($teamKey)) {
            $sufijo = ltrim($teamKey, '0');
            if (isset($porSufijo[$sufijo])) return $porSufijo[$sufijo];
            if (isset($porEmail[$sufijo]))  return $porEmail[$sufijo];
            return null;
        }

        return self::TEAM_KEY_POR_NOMBRE[$teamKey] ?? null;
    }
}
