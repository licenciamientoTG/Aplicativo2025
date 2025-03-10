<?php
class Stations{
    public $twig;
    public $route;

    /**
     * @param $twig
     */
    public function __construct($twig) {
        $this->twig  = $twig;
        $this->route = 'views/stations/';
    }

    /**
     * @return void
     * @throws Exception
     */
    public function readings(): void {
        $tabulatorModel = new TabulatorModel();
        $bombasModel = new BombasModel();
        $tabulators = $tabulatorModel->get_tabulators();
        if (preg_match('/GET/i',$_SERVER['REQUEST_METHOD'])){
            $post = 0;
            echo $this->twig->render($this->route . 'index.html', compact('tabulators', 'post'));
        } else {
            $tab_id = $_POST['tab_id'];
            $tabulator = $tabulatorModel->sp_obtener_info_tabulador($tab_id);
            $bombas = $bombasModel->get_pumps_by_station($tabulator['CodigoEstacion']);
            $post = 1;

            // echo '<pre>';
            // var_dump(count($bombas));
            // die();
            echo $this->twig->render($this->route . 'index.html', compact('tabulators', 'post', 'bombas'));
        }
    }
}