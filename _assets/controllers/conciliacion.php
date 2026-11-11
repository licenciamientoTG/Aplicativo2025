<?php
/**
 * Conciliacion — conciliaciones entre sistemas (One Goal ↔ ControlGas). Permiso 14 (Ingresos), declarado en routes/conciliacion.php.
 */
class Conciliacion {
    public $twig;
    public $route;

    public function __construct($twig) {
        $this->twig  = $twig;
        $this->route = 'views/conciliacion/';
    }

    /** GET: página con filtro de mes. */
    public function onegoal_controlgas() : void {
        echo $this->twig->render($this->route . 'onegoal_controlgas.html', [
            'mes' => date('Y-m', strtotime('first day of last month')),
        ]);
    }

    /** POST: anticipos del mes de ControlGas cruzados por UUID con One Goal, más el resumen por estatus. */
    public function onegoal_controlgas_anticipos() : void {
        set_time_limit(300);
        $mes = preg_match('/^\d{4}-\d{2}$/', $_POST['mes'] ?? '') ? $_POST['mes'] : date('Y-m', strtotime('first day of last month'));
        $desde = $mes . '-01';
        $hasta = date('Y-m-t', strtotime($desde));
        $model = new ConciliacionOneGoalModel;
        $cg = $model->anticipos_controlgas(dateToInt($desde), dateToInt($hasta));
        $og = $model->cfdi_onegoal($desde, $hasta, array_column($cg, 'uuid'));
        $fac = $model->facturas_onegoal($desde, $hasta, array_column($cg, 'nro'));
        $r = ConciliacionOneGoal::conciliar($cg, $og, $fac, $desde, $hasta);
        json_output([
            'data' => $r['filas'], 'resumen' => $r['resumen'],
            'onegoal' => ConciliacionOneGoal::filasOneGoal($og, array_column($cg, 'uuid'), $fac),
        ]);
    }
}
