<?php
class Home{
    public $twig;
    public $route;
    public DespachosModel $despachosModel;
    public int $todayInt;

    public function __construct($twig) {
        $this->despachosModel = new DespachosModel;
        $this->twig         = $twig;
        $this->todayInt     = (new DateTime())->diff(new DateTime('1900-01-01'))->days + 1;
        $this->route        = 'views/home/';
    }

    public function index() : void {
        $today_sales = $this->despachosModel->get_today_sales($this->todayInt);
        echo $this->twig->render($this->route . 'index.html', compact('today_sales'));
    }
}