<?php
declare(strict_types=1);

/**
 * Pruebas de RouteUsage:  php tests/RouteUsageTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/RouteUsage.class.php';

$twig = <<<'HTML'
<form action="{{ url('stations.readings') }}" method="post">
<a href="{{ url("supply.payment", {id: 15}) }}">x</a>
{% set u = url('home') %}
<style>.a { background: url('img/logo.png'); }</style>
<div style="background:url('fondo.jpg')"></div>
HTML;
check('Twig: encuentra url() dentro de {{ }} y {% %}',
    RouteUsage::inTwig($twig) === ['stations.readings', 'supply.payment', 'home']);
check('Twig: ignora url() de CSS', !in_array('img/logo.png', RouteUsage::inTwig($twig), true)
    && !in_array('fondo.jpg', RouteUsage::inTwig($twig), true));

$js = <<<'JS'
$.get(routeUrl('stations.readings'));
location = routeUrl("supply.payment", {id: 1});
el.style.background = "url('x.png')";
JS;
check('JS: encuentra routeUrl()', RouteUsage::inJs($js) === ['stations.readings', 'supply.payment']);
check('JS: ignora url() de CSS', !in_array('x.png', RouteUsage::inJs($js), true));
check('JS inline en vista: encuentra routeUrl()', RouteUsage::inJs('<script>fetch(routeUrl("arqueo.x"))</script>') === ['arqueo.x']);
check('JS: encuentra tableRouteUrl() (el nombre es el segundo argumento)',
    RouteUsage::inJs("url: tableRouteUrl('#datatables_anticipos', 'administration.datatables_anticipos', {from: f})") === ['administration.datatables_anticipos']);
check('sin usos => []', RouteUsage::inTwig('<p>hola</p>') === [] && RouteUsage::inJs('var a=1;') === []);

finish();
