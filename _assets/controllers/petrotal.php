<?php
class Petrotal {
    public $twig;
    public $route;
    public PetrotalObligacionModel $petrotalObligacionModel;
    public PetrotalFielModel $petrotalFielModel;

    public function __construct($twig) {
        $this->twig = $twig;
        $this->route = 'views/petrotal/';
        $this->petrotalObligacionModel = new PetrotalObligacionModel();
        $this->petrotalFielModel = new PetrotalFielModel();
    }

    // Sugiere la última semana lunes-domingo ya cerrada (hoy - 7 días desde
    // el lunes más reciente) como punto de partida del selector — el
    // usuario puede cambiarla libremente en la pantalla.
    private function semana_sugerida(): array {
        $hoy = new DateTime();
        $diaSemana = (int) $hoy->format('N'); // 1=lunes .. 7=domingo
        $lunesActual = (clone $hoy)->modify('-' . ($diaSemana - 1) . ' days');
        $lunesSemanaAnterior = (clone $lunesActual)->modify('-7 days');
        $domingoSemanaAnterior = (clone $lunesSemanaAnterior)->modify('+6 days');
        return [$lunesSemanaAnterior->format('Y-m-d'), $domingoSemanaAnterior->format('Y-m-d')];
    }

    public function volumetricos() {
        if (!authorized(98)) {
            echo "No autorizado";
            return;
        }
        [$desdeSugerido, $hastaSugerido] = $this->semana_sugerida();
        echo $this->twig->render($this->route . 'volumetricos.html', compact('desdeSugerido', 'hastaSugerido'));
    }

    public function preview_json() {
        header('Content-Type: application/json');
        if (!authorized(98)) {
            json_output(['error' => 'No autorizado']);
            return;
        }

        $desde = $_REQUEST['desde'] ?? '';
        $hasta = $_REQUEST['hasta'] ?? '';
        if (!$desde || !$hasta) {
            json_output(['error' => 'Selecciona un periodo (desde/hasta)']);
            return;
        }

        $reporte = $this->petrotalObligacionModel->construir_reporte($desde, $hasta);
        $envioExistente = $this->petrotalObligacionModel->buscar_envio_periodo($desde, $hasta);

        json_output([
            'ventas' => $reporte['ventas'],
            'compras' => $reporte['compras'],
            'advertencias' => $reporte['advertencias'],
            'envio_existente' => $envioExistente,
        ]);
    }
}
