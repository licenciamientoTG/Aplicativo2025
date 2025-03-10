<?php
class Xml
{
    public function __construct($twig) {
        $this->twig = $twig;
        $this->xsdReportesVolumenesModel = new XsdReportesVolumenesModel();
        $this->xsdEstacionServicioVolumenModel = new XsdEstacionServicioVolumenModel();
        $this->xsdEstacionServicioVolumenVendidoInventariosModel = new XsdEstacionServicioVolumenVendidoInventariosModel();
        $this->xsdEstacionServicioVolumenCompradoModel = new XsdEstacionServicioVolumenCompradoModel();
    }
    function generatexml() : void {
        // Validar que los parámetros estén presentes
        $output        = $_GET['output'];
        $from          = $_GET['from'];

        $codgas_string = $_GET['codgas'];
        // Vamos a hacer un arreglo con las estaciones
        $stations      = explode(',', $codgas_string);
        $cabecera      = $this->xsdReportesVolumenesModel->get_cabecera($from);
        
        $file_name = $_GET['companyDenominacion'] . '_' . $_GET['from'];

        $precioCompraSinDescuento = 0;
        // Crear un nuevo objeto SimpleXMLElement
        $xml           = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ReporteVolumenes/>');
        $xml->addAttribute('FechaReporteEstadistico', $cabecera['fechaReporteEstadistico']);
        $xml->addAttribute('Version', $cabecera['version'] . '.0');
        // Crear el nodo EstacionesServicioVolumen
        $estacionesServicioVolumen = $xml->addChild('EstacionesServicioVolumen');
        foreach ($stations AS $key => $codgas) {
            if ($station = $this->xsdEstacionServicioVolumenModel->get_station($cabecera['id'], $codgas)) {
                $estacionServicioVolumen = $estacionesServicioVolumen->addChild('EstacionServicioVolumen');
                $estacionServicioVolumen->addAttribute('numeroPermisoCRE', strval($station['numeroPermisoCRE']));
                $estacionServicioVolumen->addAttribute('RFC', trim($station['rfc']));
                $estacionServicioVolumen->addAttribute('ImagenComercialId', $station['imagenComercialId']);
                $estacionServicioVolumen->addAttribute('EstatusESId', $station['estatusESId']);

                // Crear el nodo EstacionServicioVolumenesComprados
                $estacionServicioVolumenesComprados = $estacionServicioVolumen->addChild('EstacionServicioVolumenesComprados');

                $station_inventory = $this->xsdEstacionServicioVolumenCompradoModel->get_purchases($cabecera['id'], $station['id']);
                if (is_array($station_inventory) || is_object($station_inventory)) {
                    foreach ($station_inventory as $row) {
                        $precioCompraSinDescuento = round($row['precioCompraSinDescuento'], 2);

                        if (isset($row['volumenComprado']) && is_numeric($row['volumenComprado']) && $row['volumenComprado'] >= 0 && $row['volumenComprado'] <= 999999999) {
                            if ($precioCompraSinDescuento >= 0 && $precioCompraSinDescuento <= 99.99 && preg_match('/^\d+(\.\d{1,2})?$/', $precioCompraSinDescuento)) {
                                // Crear el nodo EstacionServicioVolumenComprado con atributos
                                $estacionServicioVolumenComprado = $estacionServicioVolumenesComprados->addChild('EstacionServicioVolumenComprado');
                                $estacionServicioVolumenComprado->addAttribute('ProductoId', $row['productoId']);
                                $estacionServicioVolumenComprado->addAttribute('SubProductoId', $row['subProductoId']);
                                $estacionServicioVolumenComprado->addAttribute('SubproductoMarcaId', $row['subproductoMarcaId']);
                                $estacionServicioVolumenComprado->addAttribute('TipoCompra', $row['tipoCompra']);
                                $estacionServicioVolumenComprado->addAttribute('TipoDocumento', $row['tipoDocumento']);
                                $estacionServicioVolumenComprado->addAttribute('PermisoProveedorCRE', $row['permisoProveedorCRE']);
                                $estacionServicioVolumenComprado->addAttribute('VolumenComprado', $row['volumenComprado']);
                                $estacionServicioVolumenComprado->addAttribute('PrecioCompraSinDescuento', $precioCompraSinDescuento);
                                $estacionServicioVolumenComprado->addAttribute('RecibioDescuento', $row['recibioDescuento']);
                                if ($row['recibioDescuento'] == 1) {
                                    $estacionServicioVolumenComprado->addAttribute('TipoDescuentoId', $row['tipoDescuentoId']);
                                    $estacionServicioVolumenComprado->addAttribute('PrecioCompraConDescuento', $row['precioCompraConDescuento']);
                                }
                                $estacionServicioVolumenComprado->addAttribute('PagoServicioFlete', $row['pagoServicioFlete']);
                                if ($row['pagoServicioFlete'] == 1) {
                                    $estacionServicioVolumenComprado->addAttribute('CostoFlete', $row['costoFlete']);
                                }
                                $estacionServicioVolumenComprado->addAttribute('PermisoTransportistaCRE', $row['permisoTransportistaCRE']);
                            } else {
                                setFlashMessage('error', 'El valor de precioCompraSinDescuento no es un número o no está en el rango permitido');
                                redirect();
                            }
                        } else {
                            setFlashMessage('error', 'El valor de volumenComprado no es un número o no está en el rango permitido');
                            redirect();
                        }
                    }
                }

                // Crear el nodo EstacionServicioVolumenesVendidosInventarios
                $estacionServicioVolumenesVendidosInventarios = $estacionServicioVolumen->addChild('EstacionServicioVolumenesVendidosInventarios');

                $station_inventory = $this->xsdEstacionServicioVolumenVendidoInventariosModel->get_inventory($station['id']);
                if (is_array($station_inventory) || is_object($station_inventory)) {
                    foreach ($station_inventory as $row) {
                        if (isset($row['inventarioInicial']) && is_numeric($row['inventarioInicial']) && $row['inventarioInicial'] >= 0 && $row['inventarioInicial'] <= 999999999) {
                            if (isset($row['volumenVendido']) && is_numeric($row['volumenVendido']) && $row['volumenVendido'] >= 0 && $row['volumenVendido'] <= 999999999) {
                                if (isset($row['inventarioFinal']) && is_numeric($row['inventarioFinal']) && $row['inventarioFinal'] >= 0 && $row['inventarioFinal'] <= 999999999) {
                                    // Crear el nodo EstacionServicioVolumenVendidoInventarios con atributos
                                    $estacionServicioVolumenVendidoInventarios = $estacionServicioVolumenesVendidosInventarios->addChild('EstacionServicioVolumenVendidoInventarios');
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('ProductoId', $row['productoId']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('SubProductoId', $row['subProductoId']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('SubproductoMarcaId', $row['subproductoMarcaId']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('InventarioInicial', $row['inventarioInicial']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('VolumenVendido', $row['volumenVendido']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('InventarioFinal', $row['inventarioFinal']);
                                    $estacionServicioVolumenVendidoInventarios->addAttribute('ExportaProducto', '0');
                                } else {
                                    setFlashMessage('error', 'El valor de inventarioFinal no es un número o no está en el rango permitido');
                                    redirect();
                                }
                            } else {
                                setFlashMessage('error', 'El valor de volumenVendido no es un número o no está en el rango permitido');
                                redirect();
                            }
                        } else {
                            setFlashMessage('error', 'El valor de inventarioInicial no es un número o no está en el rango permitido');
                            redirect();
                        }
                    }
                }
            }
        }

        if ($output == 'browser') {
            // Guardar el archivo XML en un archivo
            $xml->asXML($file_name . '.xml');
            // Mostrar el contenido XML (opcional)
            header("Content-Type: application/xml; charset=UTF-8");
            print $xml->asXML();
        } elseif ($output == 'file') {
            // Descargar como .xml
            header('Content-Type: application/xml');
            header('Content-Disposition: attachment; filename="'. $file_name .'.xml"');
            print $xml->asXML();

        }
    }
}