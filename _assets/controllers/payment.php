<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/code128.php';

class Payment
{
    private const PDF_IMPORT_API_URL = 'http://192.168.0.109:82/api/importar_factura_pdf/';
    private const ALLOWED_PROVIDERS = ['lobo', 'mcg', 'tesoro', 'aemsa', 'enerey', 'essafuel', 'premiergas', 'petrotal'];
    private const BASE_ATTACHMENTS_PATH = 'C:\\Software\\TareasProgramadas\\Facturas_proveedores\\correoFacturas\\attachments';

    public $twig;
    public $route;
    public GasolinerasModel $gasolinerasModel;
    public EstacionesModel $estacionesModel;
    public DocumentosModel $documentosModel;
    public FacturasRecibidasModel $facturasRecibidasModel;
    public PaymentRequestsModel $PaymentRequestsModel;
    public PaymentRequestInvoicesModel $paymentRequestInvoicesModel;
    public ProveedoresModel $proveedores;
    public PaymentRequestAuthorizationsModel $paymentRequestAuthorizationsModel;
    public PaymentTransactionsModel $paymentTransactionsModel;
    public CuentasBancariasModel $CuentasBancariasModel;
    public UsuariosModel $UsuariosModel;
    public InvoiceCreditDebitNotesModel $InvoiceCreditDebitNotesModel;
    public InvoiceCreditDebitNotesDocModel $InvoiceCreditDebitNotesDocModel;
    public CreditNoteApplicationsModel $CreditNoteApplicationsModel;
    public PaymentAccountingGroupsModel $PaymentAccountingGroupsModel;
    public PaymentNotificationRecipientsModel $PaymentNotificationRecipientsModel;
    public PaymentTransactionDocumentsModel $PaymentTransactionDocumentsModel;
    public PaymentBatchesModel $PaymentBatchesModel;
    public PaymentRequestAuditLogModel $PaymentRequestAuditLogModel;

    // 🚧 MODO PRUEBAS: cuando es true, todo correo de pagos va solo a este buzón.
    private const TEST_MODE_EMAIL = 'alejandro.martinez@totalgas.com';
    private const TEST_MODE = false;

    public function __construct($twig)
    {
        $this->twig                                = $twig;
        $this->route                               = 'views/payment/';
        $this->gasolinerasModel                    = new GasolinerasModel;
        $this->estacionesModel                     = new EstacionesModel();
        $this->documentosModel                     = new DocumentosModel();
        $this->facturasRecibidasModel               = new FacturasRecibidasModel();
        $this->PaymentRequestsModel                = new PaymentRequestsModel();
        $this->paymentRequestInvoicesModel         = new PaymentRequestInvoicesModel();
        $this->proveedores                         = new ProveedoresModel();
        $this->paymentRequestAuthorizationsModel   = new PaymentRequestAuthorizationsModel();
        $this->paymentTransactionsModel            = new PaymentTransactionsModel();
        $this->CuentasBancariasModel               = new CuentasBancariasModel();
        $this->UsuariosModel                       = new UsuariosModel();
        $this->InvoiceCreditDebitNotesModel        = new InvoiceCreditDebitNotesModel();
        $this->InvoiceCreditDebitNotesDocModel     = new InvoiceCreditDebitNotesDocModel();
        $this->CreditNoteApplicationsModel        = new CreditNoteApplicationsModel();
        $this->PaymentAccountingGroupsModel       = new PaymentAccountingGroupsModel();
        $this->PaymentNotificationRecipientsModel   = new PaymentNotificationRecipientsModel();
        $this->PaymentTransactionDocumentsModel     = new PaymentTransactionDocumentsModel();
        $this->PaymentBatchesModel                  = new PaymentBatchesModel();
        $this->PaymentRequestAuditLogModel          = new PaymentRequestAuditLogModel();
    }

    /**
     * Bloquea modificaciones a una requisición ya incluida en un archivo de contabilidad.
     * Excepción: usuario 6296 (autorizado a corregir requisiciones agrupadas).
     * @return string|null  Mensaje de error si está bloqueada, null si se puede modificar.
     */
    private function assert_payment_not_grouped(array $payment, int $user_id): ?string
    {
        if (!empty($payment['accounting_group_id']) && $user_id !== 6296) {
            return 'Esta requisición ya fue incluida en un archivo de contabilidad y no puede modificarse. Contacte a Contabilidad.';
        }
        return null;
    }

    /**
     * Banco asignado por empresa (mismo criterio que los queries de pagos).
     */
    private function banco_por_emp_cod($emp_cod): string
    {
        if (in_array((int)$emp_cod, [1, 10, 17, 18, 21, 23], true)) return 'Banorte';
        if (in_array((int)$emp_cod, [11, 14, 15, 16, 19, 20], true)) return 'Santander';
        return 'Sin asignar';
    }

    /**
     * Registra un lote de pago: crea la cabecera (payment_batches), ejecuta el
     * pago de las facturas (process_bulk_payment) ligándolas al lote, marca las
     * requisiciones saldadas como PAGADO y adjunta el comprobante al lote.
     *
     * @param array      $facturas_procesar Arreglo para process_bulk_payment
     * @param array      $datos             fecha_pago, referencia, observaciones, emp_cod, provider_cod, monto_total
     * @param array|null $file              Item de $_FILES (o null si no hay comprobante)
     * @param int        $user_id
     * @return array ['success','message','total_pagado','batch_id', ...]
     */
    private function registrar_lote_y_pago(array $facturas_procesar, array $datos, ?array $file, int $user_id): array
    {
        $batch_id = $this->PaymentBatchesModel->create([
            'fecha_pago'    => $datos['fecha_pago'],
            'referencia'    => $datos['referencia'] ?? null,
            'banco'         => $datos['banco'] ?? $this->banco_por_emp_cod($datos['emp_cod'] ?? null),
            'emp_cod'       => $datos['emp_cod'] ?? null,
            'provider_cod'  => $datos['provider_cod'] ?? null,
            'monto_total'   => $datos['monto_total'] ?? 0,
            'observaciones' => $datos['observaciones'] ?? null,
            'created_by'    => $user_id,
        ]);

        if (!$batch_id) {
            return ['success' => false, 'message' => 'No se pudo crear el lote de pago'];
        }

        $result = $this->paymentTransactionsModel->process_bulk_payment(
            $facturas_procesar,
            $user_id,
            $datos['fecha_pago'],
            $datos['observaciones'] ?? '',
            $datos['referencia'] ?? null,
            'TRANSFERENCIA',
            $batch_id
        );

        if (!$result['success']) {
            return ['success' => false, 'message' => $result['message'], 'batch_id' => $batch_id];
        }

        // Marcar requisiciones completamente pagadas
        $prids = array_unique(array_column($facturas_procesar, 'payment_request_id'));
        foreach ($prids as $prid) {
            if ($this->paymentTransactionsModel->check_all_invoices_paid($prid)) {
                $this->PaymentRequestsModel->update_request_status(
                    $prid,
                    PaymentRequestsModel::STATUS_PAID,
                    "Pago registrado el " . date('d/m/Y', strtotime($datos['fecha_pago'])) . " - Ref: " . ($datos['referencia'] ?? '')
                );
            }
        }

        // Adjuntar el comprobante al lote (un PDF por lote, visible para todas sus
        // facturas). Se liga al batch_id y también a la primera transacción del
        // lote (transaction_id es NOT NULL en la tabla y mantiene compatibilidad).
        $comprobante_msg = '';
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $primera_tx = $result['transaction_ids'][0] ?? $result['last_transaction_id'] ?? null;
            $up = $this->PaymentTransactionDocumentsModel->upload($primera_tx, $file, $user_id, $batch_id);
            $comprobante_msg = $up['success'] ? ' Comprobante guardado.' : ' (comprobante no guardado: ' . $up['message'] . ')';
        }

        return [
            'success'      => true,
            'message'      => $result['message'] . $comprobante_msg,
            'total_pagado' => $result['total_pagado'],
            'batch_id'     => $batch_id,
            'facturas_procesadas' => $result['facturas_procesadas'],
        ];
    }

    function fuel_payments()
    {
        $stations = $this->gasolinerasModel->get_active_stations();
        echo $this->twig->render($this->route . 'fuel_payments.html', compact('stations'));
    }


    function add_payment()
    {
        $all_stations = $this->gasolinerasModel->get_stations();

        // Filtrar estaciones para quitar la que tiene cod = 0
        $stations = array_filter($all_stations, function ($station) {
            return $station['cod'] != 0; // o !== '0' si cod es string
        });

        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();
        echo $this->twig->render($this->route . 'add_payment.html', compact('stations', 'companys', 'proveedores'));
    }


    /**
     * Vista para agregar más facturas a un pago existente
     */
    function add_more_invoices_to_payment()
    {
        $payment_id = $_GET['payment_id'] ?? 0;

        if (!$payment_id) {
            header('Location: /payment/payment_list');
            return;
        }

        // Obtener información del pago
        $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

        if (!$payment) {
            header('Location: /payment/payment_list');
            return;
        }

        // Verificar que no esté pagado
        if ($payment['status'] == PaymentRequestsModel::STATUS_PAID) {
            header('Location: /payment/payment_detail?id=' . $payment_id);
            return;
        }

        $all_stations = $this->gasolinerasModel->get_stations();

        // Filtrar estaciones para quitar la que tiene cod = 0
        $stations = array_filter($all_stations, function ($station) {
            return $station['cod'] != 0;
        });

        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();

        // Obtener facturas ya incluidas en el pago
        $existing_invoices = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);

        // Pasar variables adicionales para modo edición
        $edit_mode = true;
        $selected_provider = $payment['provider_cod'];
        $selected_company = $payment['emp_cod'];

        echo $this->twig->render($this->route . 'add_payment.html', compact(
            'stations',
            'companys',
            'proveedores',
            'edit_mode',
            'payment_id',
            'payment',
            'selected_provider',
            'selected_company',
            'existing_invoices'
        ));
    }


    /**
     * Agregar facturas adicionales a un pago existente
     */
    function add_invoices_bulk_to_payment()
    {
        header('Content-Type: application/json');
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);

            $payment_id = $data['payment_id'] ?? 0;
            $documents  = $data['documentos'] ?? [];

            if (!$payment_id || empty($documents)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Datos inválidos'
                ]);
                return;
            }

            // Verificar que el pago existe y no está pagado
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Pago no encontrado'
                ]);
                return;
            }

            if ($payment['status'] == PaymentRequestsModel::STATUS_PAID) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se pueden modificar pagos ya ejecutados'
                ]);
                return;
            }

            $user_id = (int)($_SESSION['tg_user']['Id'] ?? 0);
            $user_name = $_SESSION['tg_user']['Nombre'] ?? null;
            $blockMessage = $this->assert_payment_not_grouped($payment, $user_id);
            if ($blockMessage !== null) {
                echo json_encode([
                    'success' => false,
                    'message' => $blockMessage
                ]);
                return;
            }
            // Comenzar transacción
            // $this->PaymentRequestsModel->sql->beginTransaction();

            $added = 0;
            $skipped = 0;
            $errors = [];

            foreach ($documents as $doc) {
                $result = $this->paymentRequestInvoicesModel->add_invoice_to_payment($payment_id, $doc);

                if ($result['success']) {
                    $added++;
                    $this->PaymentRequestAuditLogModel->log_add_invoice(
                        $payment_id, $doc, $result['invoice_id'] ?? null, $user_id, $user_name,
                        $payment['accounting_group_id'] ?? null
                    );
                } else {
                    $skipped++;
                    $errors[] = $result['message'];
                }
            }

            if ($added > 0) {
                $this->PaymentRequestsModel->recalculate_payment_total($payment_id);
                $this->PaymentRequestsModel->reset_authorizations($payment_id);
                // $this->PaymentRequestsModel->sql->commit();
                echo json_encode([
                    'success' => true,
                    'message' => "Se agregaron {$added} factura(s). Las autorizaciones han sido reiniciadas.",
                    'added' => $added,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'payment_id' => $payment_id
                ]);
            } else {
                // $this->PaymentRequestsModel->sql->rollback();

                echo json_encode([
                    'success' => false,
                    'message' => 'No se pudo agregar ninguna factura. ' . implode(', ', $errors)
                ]);
            }
        } catch (Exception $e) {
            $this->PaymentRequestsModel->sql->rollback();
            error_log("Error en add_invoices_bulk_to_payment: " . $e->getMessage());

            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Endpoint para obtener las facturas de compra según filtros en la vista de conciliación de pagos
     */
    public function payment_control_table()
    {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');
        $postData = [
            'from' => dateToInt($_POST['fromDate']),
            'until' => dateToInt($_POST['untilDate']),
            'codgas' => $_POST['codgas'] ? $_POST['codgas'] : '0',
            'proveedor' => $_POST['proveedor'] ? $_POST['proveedor'] : '0',
            'company' => $_POST['company'] ? $_POST['company'] : '0'
        ];

        $ch = curl_init('http://192.168.0.109:82/api/estacion_documentos_compra/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_POST, true);

        // Ejecutar y obtener respuesta
        $response = curl_exec($ch);
        curl_close($ch);
        $apiData = json_decode($response, true);
        $data = [];
        $hoy = date('Y-m-d');
        if (isset($apiData) && is_array($apiData)) {
            foreach ($apiData as $row) {
                if (empty($row['satuid'])) {
                    continue; // Skip rows with empty 'nro'
                }
                $fechaVencimiento = !empty($row['fecha_vencimiento_credito']) ? $row['fecha_vencimiento_credito'] : ($row['fechaVto'] ?? null);
                if (!empty($fechaVencimiento) && $fechaVencimiento < '2026-05-01') {
                    continue;
                }
                $estaVencida = !empty($fechaVencimiento) && $fechaVencimiento < $hoy;
                $statusLabel = 'Pendiente';
                if ($row['payment_status'] == '0') {
                    $statusLabel = '<span class="badge bg-light text-dark">Enviado</span>';
                } elseif ($row['payment_status'] == '1') {
                    $statusLabel = '<span class="badge bg-secondary">Autorizado</span>';
                } elseif ($row['payment_status'] == '2') {
                    $statusLabel = '<span class="badge bg-success">Pagado</span>';
                } elseif ($estaVencida) {
                    $statusLabel = '<span class="badge bg-danger">Vencido</span>';
                }
                // Origen del total: si existe la factura en TG.dbo.FacturasRecibidas usamos su Total,
                // de lo contrario caemos al total calculado desde ControlGas (SG12). El front marca en rojo el de CG.
                $tieneFacturaRecibida = !empty($row['tiene_factura_recibida']) ? 1 : 0;
                $totalControlGas      = $row['total_fac'];
                $totalFacturaRecibida = $row['total_factura_recibida'] ?? null;
                $totalMostrar         = $tieneFacturaRecibida ? $totalFacturaRecibida : $totalControlGas;

                // Origen de la fecha de emisión: misma lógica que el total. fecha_emision_efectiva ya viene
                // resuelta por el API (factura si existe, si no ControlGas) y es la base del cálculo de vencimiento.
                $fechaDeFactura     = !empty($row['fecha_de_factura']) ? 1 : 0;
                $fechaEmisionMostrar = $row['fecha_emision_efectiva'] ?? ($row['fecha'] ?? null);

                $data[] = array(
                    'nro'              => $row['nro'],
                    'Factura'          => $row['Factura'],
                    'Remision'         => isset($row['Remision']) ? substr($row['Remision'], 0, 15) : '',
                    'fecha'            => $fechaEmisionMostrar,
                    'fecha_de_factura' => $fechaDeFactura,
                    'fechaVto'         => $fechaVencimiento,
                    'producto'         => $row['producto'],
                    'proveedor'        => $row['proveedor'],
                    'proveedor_codigo' => $row['proveedor_codigo'],
                    'volrec'           => $row['volrec'],
                    'can'              => $row['can'],
                    'pre'              => $row['pre'],
                    'mto'              => $row['mto'],
                    'mtoiie'           => $row['mtoiie'],
                    'iva8'             => $row['iva8'],
                    'iva'              => $row['iva'],
                    'iva_total'        => $row['iva_total'],
                    'servicio'         => $row['servicio'],
                    'iva_servicio'     => $row['iva_servicio'],
                    'total_fac'        => $row['total_fac'],
                    'total_factura_recibida' => $totalFacturaRecibida,
                    'tiene_factura_recibida' => $tieneFacturaRecibida,
                    'total_mostrar'    => $totalMostrar,
                    'total_origen'     => $tieneFacturaRecibida ? 'FR' : 'CG',
                    'satuid'           => $row['satuid'],
                    'gasolinera'       => $row['gasolinera'],
                    'codgas'           => $row['codgas'],
                    'en_orden_pago'    => $row['en_orden_pago'],
                    'payment_status'   => $row['payment_status'],
                    'codigo_empresa'   => $row['codigo_empresa'],
                    'statusLabel'      => $statusLabel
                );
            }
        }
        json_output(array("data" => $data));
    }


    function uploadPdf()
    {
        $uploadDir = __DIR__ . '/../../_assets/uploads/creAcuses/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (
            isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] === UPLOAD_ERR_OK
            && isset($_POST['companyDenominacion']) && isset($_POST['from'])
        ) {
            // Validar que llegue el archivo y las variables necesarias
            $fileTmpPath = $_FILES['pdfFile']['tmp_name'];
            $fileExtension = strtolower(pathinfo($_FILES['pdfFile']['name'], PATHINFO_EXTENSION));
            // Validar la extensión
            if ($fileExtension !== 'pdf') {
                die('Error: Solo se permiten archivos PDF.');
            }
            // Tomar las variables y formar el nombre
            $companyDenominacion = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['companyDenominacion']);
            $from = preg_replace('/[^0-9\-]/', '', $_POST['from']);

            $newFileName = "{$companyDenominacion}_{$from}.pdf";

            $destPath = $uploadDir . $newFileName;

            if (file_exists($destPath)) {
                die('Error: Ya existe un archivo con ese nombre.');
            }

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                setFlashMessage('success', 'El archivo PDF se subió correctamente.');
                redirect();
            } else {
                echo "Error al mover el archivo PDF.";
            }
        } else {
            echo "Error: Faltan los siguientes datos o hay un problema en la subida.";
            if (!isset($_FILES['pdfFile'])) {
                echo " - Archivo PDF";
            }
            if (!isset($_POST['companyDenominacion'])) {
                echo " - Denominación de la empresa";
            }
            if (!isset($_POST['from'])) {
                echo " - Fecha";
            }
            if (isset($_FILES['pdfFile']) && $_FILES['pdfFile']['error'] !== UPLOAD_ERR_OK) {
                echo " - Error en la subida del archivo PDF: " . $_FILES['pdfFile']['error'];
            }
        }
    }


    function descargar_facturas()
    {
        echo $this->twig->render($this->route . 'descargar_facturas.html');
    }


    function procesar_uuids_facturas()
    {
        header('Content-Type: application/json');

        try {
            // Validar que llegue el archivo
            if (!isset($_FILES['archivo_excel']) || $_FILES['archivo_excel']['error'] !== UPLOAD_ERR_OK) {
                json_output(['success' => false, 'message' => 'No se recibió el archivo o hubo un error']);
                return;
            }

            $archivo = $_FILES['archivo_excel']['tmp_name'];
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($archivo);
            $sheet = $spreadsheet->getActiveSheet();

            $uuidsValidos = [];
            $uuidsInvalidos = [];
            $highestRow = $sheet->getHighestRow();

            // Leer TODOS los UUIDs de la primera columna
            for ($row = 2; $row <= $highestRow; $row++) {
                $uuid = $sheet->getCell('A' . $row)->getValue();
                if (!empty($uuid)) {
                    $uuid = trim($uuid);
                    $uuidOriginal = $uuid;
                    $uuid = strtoupper($uuid);
                    // Validar formato UUID
                    if (strlen($uuid) !== 36) {
                        // UUID inválido - longitud incorrecta
                        $uuidsInvalidos[] = [
                            'fila' => $row,
                            'uuid' => $uuid,
                            'estado' => 'formato_invalido',
                            'error' => 'UUID con formato inválido (longitud: ' . strlen($uuid) . ', debe ser 36 caracteres)'
                        ];
                    } else if (!preg_match('/^[A-F0-9]{8}[-_][A-F0-9]{4}[-_][A-F0-9]{4}[-_][A-F0-9]{4}[-_][A-F0-9]{12}$/i', $uuid)) {
                        // UUID inválido - formato incorrecto
                        $uuidsInvalidos[] = [
                            'fila' => $row,
                            'uuid' => $uuid,
                            'estado' => 'formato_invalido',
                            'error' => 'UUID con formato inválido (no cumple patrón 8-4-4-4-12 caracteres)'
                        ];
                    } else {
                        // UUID válido
                        // IMPORTANTE: Aunque el Regex ahora acepte guiones bajos,
                        // tu base de datos seguramente usa guiones medios.
                        // Convertimos a formato estándar para buscarlo correctamente:
                        $uuidsValidos[] = str_replace('_', '-', $uuid);
                    }
                }
            }

            $totalSolicitados = count($uuidsValidos) + count($uuidsInvalidos);
            if ($totalSolicitados === 0) {
                json_output(['success' => false, 'message' => 'No se encontraron UUIDs en el archivo']);
                return;
            }


            // Buscar facturas en la base de datos (solo con UUIDs válidos)
            $facturas = [];
            if (!empty($uuidsValidos)) {
                $facturas = $this->facturasRecibidasModel->buscarPorUUIDs($uuidsValidos);
            }

            // CAMBIO CLAVE: Usar arrays separados desde el inicio
            $facturasExitosas = [];
            $facturasFallidas = [];
            $uuidsEncontrados = [];

            if ($facturas) {
                foreach ($facturas as $factura) {
                    // ✅ SOLUCIÓN: Guardar UUID en MAYÚSCULAS para comparación consistente
                    $uuidBD = strtoupper($factura['UUID']);
                    $uuidsEncontrados[] = $uuidBD;

                    // Verificar que el archivo exista ANTES de agregarlo como exitosa
                    if (!empty($factura['RutaArchivo']) && file_exists($factura['RutaArchivo'])) {
                        // ✅ Factura completa y disponible
                        $facturasExitosas[] = [
                            'id' => $factura['Id'],
                            'uuid' => $factura['UUID'],
                            'nombre_archivo' => $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']),
                            'folio' => $factura['Folio'],
                            'emisor' => $factura['EmisorNombre'],
                            'total' => $factura['Total'],
                            'estado' => 'success'
                        ];
                    } else {
                        // ❌ Factura en BD pero archivo no existe
                        $facturasFallidas[] = [
                            'uuid' => $factura['UUID'],
                            'folio' => $factura['Folio'],
                            'estado' => 'archivo_no_existe',
                            'error' => 'Factura encontrada en BD pero archivo físico no existe: ' .
                                ($factura['NombreArchivo'] ?? basename($factura['RutaArchivo'] ?? 'desconocido'))
                        ];
                    }
                }
            }

            // Identificar UUIDs válidos que NO se encontraron en la BD
            // ✅ SOLUCIÓN: Ahora la comparación es case-insensitive (ambos en MAYÚSCULAS)
            foreach ($uuidsValidos as $uuid) {
                if (!in_array($uuid, $uuidsEncontrados)) {
                    $facturasFallidas[] = [
                        'uuid' => $uuid,
                        'folio' => null,
                        'estado' => 'no_encontrado_bd',
                        'error' => 'UUID no encontrado en la base de datos'
                    ];
                }
            }

            // Agregar UUIDs con formato inválido a fallidas
            $facturasFallidas = array_merge($facturasFallidas, $uuidsInvalidos);

            // Resultado final
            json_output([
                'success' => true,
                'facturas' => array_values($facturasExitosas),
                'facturas_fallidas' => array_values($facturasFallidas),
                'total_solicitados' => $totalSolicitados,
                'total_encontrados' => count($facturasExitosas),
                'total_fallidos' => count($facturasFallidas)
            ]);
        } catch (Exception $e) {
            json_output([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Descargar archivo de factura individual
     */
    function descargar_factura($id)
    {
        try {
            $factura = $this->facturasRecibidasModel->obtenerPorId($id);

            if (!$factura) {
                http_response_code(404);
                echo json_encode(['message' => 'Factura no encontrada']);
                return;
            }

            if (empty($factura['RutaArchivo']) || !file_exists($factura['RutaArchivo'])) {
                http_response_code(404);
                echo json_encode(['message' => 'Archivo no encontrado en el servidor']);
                return;
            }

            $nombreArchivo = $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']);
            $rutaArchivo = $factura['RutaArchivo'];

            // Establecer headers para descarga
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
            header('Content-Length: ' . filesize($rutaArchivo));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: public');

            // Limpiar buffer de salida
            ob_clean();
            flush();

            // Leer y enviar el archivo
            readfile($rutaArchivo);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Error al descargar: ' . $e->getMessage()]);
        }
    }


    /**
     * Descargar múltiples facturas en un archivo ZIP
     */
    function descargar_facturas_zip()
    {
        header('Content-Type: application/json');

        try {
            // Recibir los IDs de las facturas a descargar
            $input = json_decode(file_get_contents('php://input'), true);
            $ids = $input['ids'] ?? [];

            if (empty($ids)) {
                json_output(['success' => false, 'message' => 'No se proporcionaron IDs de facturas']);
                return;
            }

            // Crear directorio temporal si no existe
            $tempDir = __DIR__ . '/../temp/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }

            // Nombre único para el archivo ZIP
            $zipFileName = 'facturas_' . date('YmdHis') . '_' . uniqid() . '.zip';
            $zipPath = $tempDir . $zipFileName;

            // Crear archivo ZIP
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
                json_output(['success' => false, 'message' => 'No se pudo crear el archivo ZIP']);
                return;
            }

            $archivosAgregados = 0;
            $archivosNoEncontrados = [];

            // Agregar cada factura al ZIP
            foreach ($ids as $id) {
                $factura = $this->facturasRecibidasModel->obtenerPorId($id);

                if ($factura && !empty($factura['RutaArchivo']) && file_exists($factura['RutaArchivo'])) {
                    $nombreArchivo = $factura['NombreArchivo'] ?? basename($factura['RutaArchivo']);

                    // Agregar archivo al ZIP
                    if ($zip->addFile($factura['RutaArchivo'], $nombreArchivo)) {
                        $archivosAgregados++;
                    } else {
                        $archivosNoEncontrados[] = $nombreArchivo;
                    }
                } else {
                    $archivosNoEncontrados[] = 'Factura ID: ' . $id;
                }
            }

            $zip->close();

            // Verificar que se agregó al menos un archivo
            if ($archivosAgregados === 0) {
                unlink($zipPath); // Eliminar ZIP vacío
                json_output([
                    'success' => false,
                    'message' => 'No se encontraron archivos para descargar',
                    'archivos_no_encontrados' => $archivosNoEncontrados
                ]);
                return;
            }

            // Retornar información del ZIP creado
            json_output([
                'success' => true,
                'zip_file' => $zipFileName,
                'archivos_agregados' => $archivosAgregados,
                'archivos_no_encontrados' => $archivosNoEncontrados,
                'download_url' => '/payment/download_zip/' . $zipFileName
            ]);
        } catch (Exception $e) {
            json_output([
                'success' => false,
                'message' => 'Error al crear ZIP: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Descargar el archivo ZIP generado
     */
    function download_zip($zipFileName)
    {
        try {
            // Validar nombre de archivo (seguridad)
            if (!preg_match('/^facturas_\d{14}_[a-z0-9]+\.zip$/', $zipFileName)) {
                http_response_code(400);
                die('Nombre de archivo inválido');
            }

            $zipPath = __DIR__ . '/../temp/' . $zipFileName;

            if (!file_exists($zipPath)) {
                http_response_code(404);
                die('Archivo ZIP no encontrado');
            }

            // Limpiar buffer
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Headers para descarga
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
            header('Content-Length: ' . filesize($zipPath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: public');

            // Enviar archivo
            readfile($zipPath);

            // Eliminar archivo temporal después de enviarlo
            unlink($zipPath);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            die('Error al descargar ZIP: ' . $e->getMessage());
        }
    }


    /**
     * Limpiar archivos ZIP antiguos (ejecutar periódicamente)
     */
    function limpiar_zips_antiguos()
    {
        $tempDir = __DIR__ . '/../temp/';

        if (!is_dir($tempDir)) {
            return;
        }

        $archivos = glob($tempDir . 'facturas_*.zip');
        $horaLimite = time() - (3600 * 2); // 2 horas
        $eliminados = 0;

        foreach ($archivos as $archivo) {
            if (filemtime($archivo) < $horaLimite) {
                unlink($archivo);
                $eliminados++;
            }
        }

        json_output([
            'success' => true,
            'archivos_eliminados' => $eliminados
        ]);
    }


    public function resumen_payment_table()
    {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');

        $postData = [
            'from' => isset($_POST['fromDate']) ? dateToInt($_POST['fromDate']) : null,
            'until' => isset($_POST['untilDate']) ? dateToInt($_POST['untilDate']) : null,
            'codgas' => isset($_POST['codgas']) ? $_POST['codgas'] : '0',
            'proveedor' => isset($_POST['proveedor']) ? $_POST['proveedor'] : '0',
            'company' => isset($_POST['company']) ? $_POST['company'] : '0'
        ];

        if (!$postData['from'] || !$postData['until']) {
            json_output(['error' => true, 'message' => 'Fechas requeridas', 'data' => []]);
            return;
        }

        try {
            $ch = curl_init('http://192.168.0.109:82/api/resumen_movimientos_tanques/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (curl_errno($ch)) {
                throw new Exception('Error de cURL: ' . curl_error($ch));
            }

            curl_close($ch);

            if ($httpCode !== 200) {
                throw new Exception("Error HTTP: $httpCode");
            }

            $apiData = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
            }

            $data = [];

            if (isset($apiData) && is_array($apiData)) {
                foreach ($apiData as $row) {
                    if (empty($row['nrotrn'])) {
                        continue;
                    }

                    // Normalizar combustible
                    $raw = isset($row['combustible']) ? trim($row['combustible']) : '';
                    $norm = mb_strtolower($raw, 'UTF-8');
                    $norm = str_replace(['.', '-', '_'], ' ', $norm);
                    $norm = preg_replace('/\s+/', ' ', $norm);
                    $norm = strtr($norm, "áéíóúÁÉÍÓÚñÑ", "aeiouAEIOUnN");

                    $combustible = $raw;
                    if (preg_match('/\b(regular|menor a 91|91 octanos|t ?maxima|maxima regular|t ?maxima regular)\b/i', $norm)) {
                        $combustible = 'Regular';
                    } elseif (preg_match('/\b(diesel|diesel automotriz)\b/i', $norm)) {
                        $combustible = 'Diesel';
                    } elseif (preg_match('/\b(premium|super premium|mayor o igual a 91|91 octanos)\b/i', $norm)) {
                        $combustible = 'Premium';
                    } else {
                        $combustible = mb_convert_case($norm, MB_CASE_TITLE, "UTF-8");
                    }

                    // Normalizar proveedor
                    $proveedor_controlgas = $row['proveedor_controlgas'];
                    if ($row['proveedor_controlgas'] == 'TESORO MEXICO SUPPLY & MARKETING S. DE R.L. DE C.V.') {
                        $proveedor_controlgas = 'TESORO';
                    }
                    if ($row['proveedor_controlgas'] == 'PREMIERGAS S.A. P. I. DE C.V.') {
                        $proveedor_controlgas = 'PREMIERGAS';
                    }
                    if ($row['proveedor_controlgas'] == 'MGC MEXICO S.A. DE C.V.') {
                        $proveedor_controlgas = 'MGC';
                    }

                    // ========== DATOS YA VIENEN CON LA FACTURA ASIGNADA ==========
                    $tieneFactura = (bool)($row['tiene_factura_asignada'] ?? 0);
                    $uuidMostrar = $tieneFactura ? ($row['uuid_asignado'] ?? '') : ($row['uuid_original'] ?? '');
                    $data[] = [
                        'fecha'                       => $row['fecha'] ?? '',
                        'hora'                        => $row['hora_formateada'] ?? '',
                        'nrotrn'                      => $row['nrotrn'],
                        'codgas'                      => $row['codgas'] ?? '',
                        'estacion'                    => $row['estacion'] ?? '',
                        'numero_estacion'             => $row['numero_estacion'] ?? '',
                        'proveedor_original'          => $proveedor_controlgas,
                        'factura_proveedor'           => $row['factura_proveedor'],
                        'proveedor_final'             => (strtoupper($proveedor_controlgas) === 'PETROTAL S.A. DE C.V.') ? '' : $proveedor_controlgas,
                        'combustible'                 => $combustible,
                        'capmax'                      => $row['capmax'] ?? 0,
                        'recaudado'                   => $row['recaudado'] ?? 0,
                        'fac_rec'                     => $row['fac_rec'] ?? 0,
                        'nro_fac'                     => $row['nro_fac'] ?? '',
                        'uuid'                        => $uuidMostrar,
                        'proveedor_controlgas'        => $proveedor_controlgas,
                        'monto_factura_controlgas'    => $row['monto_factura_controlgas'] ?? 0,
                        'cantidad_factura_controlgas' => $row['cantidad_factura_controlgas'] ?? 0,
                        'precio_factura_controlgas'   => $row['precio_factura_controlgas'] ?? 0,
                        'graprd'                      => $row['graprd'] ?? '',

                        // ========== INFORMACIÓN DE LA FACTURA ASIGNADA ==========
                        'tiene_factura'               => $tieneFactura,
                        'factura_id'                  => $tieneFactura ? ($row['factura_asignacion_id'] ?? null) : null,
                        'fecha_asignacion'            => $tieneFactura ? ($row['fecha_asignacion'] ?? null) : null,
                        'usuario_asignacion'          => $tieneFactura ? ($row['usuario_asignacion'] ?? null) : null,
                        'observaciones_asignacion'    => $tieneFactura ? ($row['observaciones_asignacion'] ?? null) : null,
                        'total_factura_asignada'      => $tieneFactura ? ($row['total_factura_asignada'] ?? 0) : 0,
                        'emisor_factura_asignada'     => $tieneFactura ? ($row['emisor_factura_asignada'] ?? '') : '',
                        'destino_factura'             => $tieneFactura ? ($row['destino_factura_asignada'] ?? '') : '',
                        'remision_factura'            => $tieneFactura ? ($row['remision_factura_asignada'] ?? '') : ''
                    ];
                }
            }

            json_output(['data' => $data]);
        } catch (Exception $e) {
            error_log("Error en resumen_payment_table: " . $e->getMessage());
            json_output(['error' => true, 'message' => $e->getMessage(), 'data' => []]);
        }
    }


    private function normalizarProducto($producto)
    {
        if (empty($producto)) return 'N/A';

        $prod = strtoupper($producto);

        if (preg_match('/\b(REGULAR|MAGNA|87)\b/i', $prod)) {
            return 'Regular';
        } elseif (preg_match('/\b(PREMIUM|SUPER|91|93)\b/i', $prod)) {
            return 'Premium';
        } elseif (preg_match('/\b(DIESEL)\b/i', $prod)) {
            return 'Diesel';
        }

        return substr($producto, 0, 50);
    }


    private function normalizarProveedor($proveedor)
    {
        if (empty($proveedor)) return 'N/A';

        $prov = strtoupper($proveedor);

        if (strpos($prov, 'TESORO') !== false) return 'TESORO';
        if (strpos($prov, 'MGC') !== false) return 'MGC';
        if (strpos($prov, 'LOBO') !== false) return 'LOBO';
        if (strpos($prov, 'PETROTAL') !== false) return 'PETROTAL';
        if (strpos($prov, 'ESSAFUEL') !== false || strpos($prov, 'ESSA') !== false) return 'ESSAFUEL';
        if (strpos($prov, 'PREMIER') !== false) return 'PREMIERGAS';
        if (strpos($prov, 'ENEREY') !== false) return 'ENEREY';
        if (strpos($prov, 'AEMSA') !== false || strpos($prov, 'ALTOS') !== false) return 'AEMSA';

        return substr($proveedor, 0, 30);
    }


    public function ModalinvoicePdf()
    {
        $facturaId = $_POST['FacturaId'] ?? null;
        if (!$facturaId) {
            echo '<div class="modal-body">Factura no especificada.</div>';
            return;
        }

        $factura = $this->facturasRecibidasModel->obtenerPorId($facturaId);
        if (!$factura) {
            echo '<div class="modal-body">Factura no encontrada.</div>';
            return;
        }

        // Renderiza un partial twig que contiene el iframe
        echo $this->twig->render($this->route . 'modals/invoice_pdf.html', compact('factura'));
    }


    public function invoiceFile()
    {
        // Acepta GET o POST según tu preferencia
        $facturaId = $_GET['FacturaId'] ?? $_POST['FacturaId'] ?? null;
        if (!$facturaId) {
            header("HTTP/1.1 400 Bad Request");
            echo "FacturaId requerido";
            exit;
        }

        $factura = $this->facturasRecibidasModel->obtenerPorId($facturaId);
        if (!$factura) {
            header("HTTP/1.1 404 Not Found");
            echo "Factura no encontrada";
            exit;
        }

        $ruta = $factura['RutaArchivo']; // ruta almacenada en DB

        // Normaliza separadores (Windows)
        $ruta = str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $ruta);
        // opcional: si la DB almacena con barras invertidas dobles, limpiarlas:
        $ruta = str_replace('\\\\', DIRECTORY_SEPARATOR, $ruta);

        // Seguridad: restringir a un directorio base permitido
        $baseAllowed = realpath('C:\\Software\\TareasProgramadas\\Facturas_proveedores'); // ajusta si hace falta
        $real = realpath($ruta);

        if ($real === false || strpos($real, $baseAllowed) !== 0) {
            header("HTTP/1.1 403 Forbidden");
            echo "Acceso al archivo denegado.";
            exit;
        }

        if (!file_exists($real) || !is_readable($real)) {
            header("HTTP/1.1 404 Not Found");
            echo "Archivo no encontrado o no legible.";
            exit;
        }

        // Enviar headers para visualizar inline en el navegador
        header('Content-Type: application/pdf');
        // inline para mostrar en el navegador; attachment para forzar descarga
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        // Evitar que PHP añada buffering adicional
        readfile($real);
        exit;
    }


    function payment_list(){
        $stations = $this->gasolinerasModel->get_active_stations();
        $companys = $this->gasolinerasModel->get_company();
        $proveedores = $this->proveedores->get_actives();

        echo $this->twig->render($this->route . 'payment_list.html', compact('stations', 'companys', 'proveedores'));
    }


    /**
     * Endpoint para obtener lista de pagos programados (DataTable)
     */
    function payment_list_table()
    {
        header('Content-Type: application/json');

        $status = isset($_POST['status']) ? $_POST['status'] : 'all';
        $type = isset($_POST['type']) ? $_POST['type'] : 'all';

        try {
            // Obtener datos del modelo
            $results = $this->PaymentRequestsModel->get_requests_with_summary($type, $status);

            if (!$results) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($results as $row) {
                $statusBadge = $this->getStatusBadge($row['status']);
                $authIndicator = $this->buildAuthorizationIndicator(
                    $row['auth_abastos'],
                    $row['auth_contabilidad'],
                    $row['auth_admin'],
                    $row['auth_tesoreria'],
                    $row['auth_abastos_user'],
                    $row['auth_contabilidad_user'],
                    $row['auth_admin_user'],
                    $row['auth_tesoreria_user'],
                    $row['auth_abastos_date'],
                    $row['auth_contabilidad_date'],
                    $row['auth_admin_date'],
                    $row['auth_tesoreria_date']
                );

                $canDelete = in_array(69, explode(',', $_SESSION['tg_user']['permissions']));
                $deleteBtn = $canDelete
                    ? '<button class="btn btn-sm" style="color:#dc2626;background:#fef2f2;border:none;border-radius:5px;padding:.3rem .5rem;" onclick="deletePayment(' . $row['id'] . ')" title="Eliminar"><i class="fas fa-trash" style="font-size:.8rem;"></i></button>'
                    : '';
                $actions = '
                    <div class="d-flex align-items-center justify-content-center gap-1">
                        <button class="btn btn-sm btn-toggle-invoices" data-payment-id="' . $row['id'] . '" style="color:#0891b2;background:#ecfeff;border:none;border-radius:5px;padding:.3rem .5rem;" title="Ver facturas"><i class="fas fa-info-circle" style="font-size:.8rem;"></i></button>
                        <a href="/payment/payment_detail/' . $row['id'] . '" class="btn btn-sm" style="color:#2563eb;background:#eff6ff;border:none;border-radius:5px;padding:.3rem .5rem;" title="Ver detalle"><i class="fas fa-eye" style="font-size:.8rem;"></i></a>
                        ' . $deleteBtn . '
                    </div>
                ';

                $totalFacturas = floatval($row['total_amount']);
                $totalNC = floatval($row['total_notas_credito']);
                $totalND = floatval($row['total_notas_cargo']);
                $montoNeto = max(0, $totalFacturas - $totalNC + $totalND);

                $esAnticipo = intval($row['tipo'] ?? 0) === 1;

                $pdfStatus = $row['pdf_status'] ?? 'no_invoices';
                $pdfDot = match($pdfStatus) {
                    'complete'    => '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#16a34a;margin-left:4px;vertical-align:middle;" title="Todas las facturas tienen PDF"></span>',
                    'missing'     => '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-left:4px;vertical-align:middle;" title="Faltan PDFs de facturas"></span>',
                    default       => '',
                };

                $anticipoBadge = $esAnticipo
                    ? '<span style="display:inline-block;font-size:.6rem;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;border-radius:3px;padding:0 4px;margin-left:4px;vertical-align:middle;letter-spacing:.03em;">ANT</span>'
                    : '';

                // Para anticipos: monto_total es el monto del anticipo, no suma de facturas
                $montoAnticipo = $esAnticipo ? floatval($row['monto_total'] ?? 0) : $totalFacturas;
                $montoNetoFinal = $esAnticipo ? $montoAnticipo : $montoNeto;

                $data[] = [
                    'id'             => $row['id'] . $pdfDot . $anticipoBadge,
                    'tipo'           => $esAnticipo ? 1 : 0,
                    'pdf_status'     => $pdfStatus,
                    'request_date'   => date('d/m/Y H:i', strtotime($row['request_date'])),
                    'scheduled_payment_date' => $row['scheduled_payment_date'] ? date('d/m/Y', strtotime($row['scheduled_payment_date'])) : null,
                    'usuario'        => $row['usuario_nombre'],
                    'provider_name'  => $row['provider_name'],
                    'emp_name'       => $row['emp_name'],
                    'total_invoices' => $esAnticipo ? '—' : $row['total_invoices'],
                    'total_amount'   => '$' . number_format($montoAnticipo, 2),
                    'total_notas_credito' => $esAnticipo ? 0 : $totalNC,
                    'total_notas_cargo'   => $esAnticipo ? 0 : $totalND,
                    'monto_neto'     => '$' . number_format($montoNetoFinal, 2),
                    'total_paid'     => '$' . number_format($row['total_paid'], 2),
                    'authorized_invoices_count' => $esAnticipo ? '—' : $row['authorized_invoices_count'],
                    'authorized_amount_total' => $esAnticipo ? '—' : '$' . number_format($row['authorized_amount_total'], 2),
                    'status'         => $statusBadge,
                    'authorizations' => $authIndicator,
                    'comment'        => $row['comment'] ?: '-',
                    'actions'        => $actions
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
    }


    function loadAnticiposList()
    {
        header('Content-Type: application/json');

        $status = isset($_POST['status']) ? $_POST['status'] : 'all';
        $type = isset($_POST['type']) ? $_POST['type'] : 'anticipo'; // ✅ CAMBIO

        try {
            $results = $this->PaymentRequestsModel->get_anticipos_with_summary($type, $status);

            if (!$results) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($results as $row) {
                $statusBadge = $this->getStatusBadge($row['status']);
                $authIndicator = $this->buildAuthorizationIndicator(
                    $row['auth_abastos'],
                    $row['auth_contabilidad'],
                    $row['auth_admin'],
                    $row['auth_tesoreria'],
                    $row['auth_abastos_user'],
                    $row['auth_contabilidad_user'],
                    $row['auth_admin_user'],
                    $row['auth_tesoreria_user'],
                    $row['auth_abastos_date'],
                    $row['auth_contabilidad_date'],
                    $row['auth_admin_date'],
                    $row['auth_tesoreria_date']
                );

                // ✅ CALCULAR SALDO
                $monto_total = floatval($row['monto_total']);
                // $monto_aplicado = floatval($row['monto_aplicado']);
                $monto_aplicado = '0';
                $saldo = $monto_total - $monto_aplicado;

                // ✅ ACCIONES ESPECÍFICAS PARA ANTICIPOS
                $actions = '
                    <div class="d-flex align-items-center justify-content-center gap-1">
                        <a href="/payment/anticipo_detail/' . $row['id'] . '" class="btn btn-sm" style="color:#2563eb;background:#eff6ff;border:none;border-radius:5px;padding:.3rem .5rem;" title="Ver detalle">
                            <i class="fas fa-eye" style="font-size:.8rem;"></i>
                        </a>
                        <button class="btn btn-sm" style="color:#dc2626;background:#fef2f2;border:none;border-radius:5px;padding:.3rem .5rem;" onclick="deletePayment(' . $row['id'] . ')" title="Eliminar">
                            <i class="fas fa-trash" style="font-size:.8rem;"></i>
                        </button>
                    </div>
                ';

                $data[] = [
                    'id'                => $row['id'],
                    'request_date'      => date('d/m/Y H:i', strtotime($row['request_date'])),
                    'usuario'           => $row['usuario_nombre'],
                    'provider_name'     => $row['provider_name'],
                    'emp_name'          => $row['emp_name'],
                    'total_invoices'    => $row['total_invoices'], // Ahora es "aplicaciones"
                    'total_amount'      => '$' . number_format($monto_total, 2),
                    'total_aplicado'    => '$' . number_format($monto_aplicado, 2), // ✅ NUEVO
                    'saldo_disponible'  => '$' . number_format($saldo, 2), // ✅ NUEVO
                    'status'            => $statusBadge,
                    'authorizations'    => $authIndicator,
                    'comment'           => $row['comment'] ?: '-',
                    'actions'           => $actions
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Endpoint para generar pago (ya existente pero mejorado)
     */
    function generate_payment()
    {
        header('Content-Type: application/json');
        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if ($data === null) {
                json_output(['success' => false, 'detail' => 'JSON inválido']);
                return;
            }

            $payment = $data['total_amount'] ?? null;
            $documents = $data['documentos'] ?? null;
            $user = $_SESSION['tg_user']['Id'] ?? null;
            $comment = $data['comment'] ?? 'Pago programado';
            $provider_cod = $data['provider_cod'] ?? null; // ✅ RECIBIR
            $provider_name = $data['provider_name'] ?? null; // ✅ OPCIONAL
            $empresa_cod = $data['empresa_cod'] ?? null; // ✅ OPCIONAL
            $scheduled_payment_date = $data['fecha_pago'] ?? null;
            $pending_notes = $data['pending_notes'] ?? [];


            if (!$user) {
                json_output(['success' => false, 'detail' => 'Usuario no autenticado']);
                return;
            }

            $indep_notes = array_filter($pending_notes ?? [], fn($n) => ($n['invoice_temp_key'] ?? null) === null);
            if ((!$documents || count($documents) === 0) && count($indep_notes) === 0) {
                json_output(['success' => false, 'detail' => 'No hay documentos para procesar']);
                return;
            }
            if (!$documents) $documents = [];
            if (!$provider_cod) {
                json_output(['success' => false, 'detail' => 'Código de proveedor requerido']);
                return;
            }
            $total_reques = 0;
            foreach ($documents as $doc) {
                // Preferir el total efectivo (FacturasRecibidas si existe, si no ControlGas)
                $monto_doc = $doc['total_mostrar'] ?? ($doc['total_fac'] ?? 0);
                $total_reques += floatval($monto_doc);
            }

            // Llamar al modelo para crear el pago con transacción
            $result = $this->PaymentRequestsModel->create_payment_with_invoices($user, $documents, $comment, $provider_cod, $empresa_cod, $total_reques, $scheduled_payment_date, $pending_notes);

            if ($result['success']) {
                //$this->enviar_notificacion_nuevo_pago($result['payment_id'],$provider_name ?? 'Proveedor',$result['total_documents'],$payment,$comment,$_SESSION['tg_user']['Nombre'] ?? 'Usuario');
                //se cambiara a uno pro dia

                json_output([
                    'success' => true,
                    'message' => $result['message'],
                    'payment_id' => $result['payment_id'],
                    'total_documents' => $result['total_documents'],
                    'total_amount' => $payment
                ]);
            } else {
                json_output([
                    'success' => false,
                    'detail' => $result['message']
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en generate_payment: " . $e->getMessage());
            json_output([
                'success' => false,
                'detail' => 'Error al procesar el pago: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * JSON: facturas de una requisición para el panel expandible (child row)
     * de la tabla de pagos. Incluye indicador de si el archivo (PDF/XML) está
     * recibido, cruzando el UUID contra FacturasRecibidas.
     */
    function payment_invoices_json($payment_id)
    {
        header('Content-Type: application/json');
        try {
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
            if (!$payment) {
                json_output(['success' => false, 'message' => 'Pago no encontrado', 'data' => []]);
                return;
            }

            $invoices = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);
            if (!$invoices) {
                json_output(['success' => true, 'data' => []]);
                return;
            }

            // Determinar qué facturas tienen archivo recibido (RutaArchivo no vacío)
            $uuids = array_values(array_filter(array_map(fn($i) => $i['uuid'] ?? null, $invoices)));
            $archivos_por_uuid = [];
            if (!empty($uuids)) {
                foreach ($this->facturasRecibidasModel->buscarPorUUIDs($uuids) as $fr) {
                    $archivos_por_uuid[strtoupper(trim($fr['UUID']))] = [
                        'fr_id'          => $fr['Id'],
                        'nombre_archivo' => $fr['NombreArchivo'],
                    ];
                }
            }

            $data = [];
            foreach ($invoices as $inv) {
                $uuidKey       = strtoupper(trim($inv['uuid'] ?? ''));
                $archivo       = $archivos_por_uuid[$uuidKey] ?? null;
                $nombreArchivo = $archivo['nombre_archivo'] ?? null;
                $frId          = $archivo['fr_id'] ?? null;
                $paid_amount   = (float)($inv['paid_amount'] ?? 0);
                $saldo         = (float)$inv['amount'] - $paid_amount;
                $neto_notas    = (float)$inv['total_notas_cargo'] - (float)$inv['total_notas_credito'];

                $esNotaCargo = (int)($inv['is_debit_note'] ?? 0) === 1;
                $data[] = [
                    'id'                    => $inv['id'],
                    'folio'                 => $inv['folio'],
                    'invoice_number'        => $inv['invoice_number'],
                    'proveedor_nombre'      => $inv['proveedor_nombre'],
                    'estacion_nombre'       => $inv['estacion_nombre'],
                    'amount'                => (float)$inv['amount'],
                    'saldo'                 => $esNotaCargo ? 0 : $saldo,
                    'total_notas_credito'   => $esNotaCargo ? 0 : (float)($inv['total_notas_credito'] ?? 0),
                    'total_notas_cargo'     => $esNotaCargo ? 0 : (float)($inv['total_notas_cargo'] ?? 0),
                    'saldo_neto'            => $esNotaCargo ? (float)$inv['amount'] : ($saldo + $neto_notas),
                    'status'                => (int)$inv['status'],
                    'payment_authorized'    => (int)($inv['payment_authorized'] ?? 0),
                    'authorized_amount'     => (float)($inv['authorized_amount'] ?? 0),
                    'expiration_date'       => $inv['expiration_date'],
                    'uuid'                  => $inv['uuid'],
                    'fr_id'                 => $frId,
                    'nombre_archivo'        => $nombreArchivo,
                    'tiene_archivo'         => !empty($frId),
                    'is_debit_note'         => (int)($inv['is_debit_note'] ?? 0),
                    'nota_id'               => $inv['nota_id'] ?? null,
                    'nota_doc_path'         => !empty($inv['nota_id']) ? true : null,
                ];
            }

            json_output(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            error_log('Error en payment_invoices_json: ' . $e->getMessage());
            json_output(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }


    public function update_payment_fields()
    {
        header('Content-Type: application/json');

        if (!authorized(66)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $payment_id = intval($_POST['payment_id'] ?? 0);
        $comment    = trim($_POST['comment'] ?? '');
        $fecha_pago = $_POST['scheduled_payment_date'] ?? null;

        if (!$payment_id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
        if (!$payment || $payment['status'] > 1) {
            json_output(['success' => false, 'message' => 'Pago no editable']);
            return;
        }

        $query = "UPDATE [TG].[dbo].[payment_requests] SET comment = ?, scheduled_payment_date = ? WHERE id = ?";
        $ok = $this->PaymentRequestsModel->sql->update($query, [
            $comment ?: null,
            $fecha_pago ?: null,
            $payment_id
        ]);

        json_output(['success' => (bool)$ok]);
    }


    function payment_detail($payment_id)
    {
        // try {
        $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
        if (!$payment) {
            setFlashMessage('error', 'Pago no encontrado');
            redirect('/payment/payment_list');
            return;
        }

        // ✅ Obtener facturas con cálculos desde el modelo
        $invoices = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);
        $facturas_autorizadas = 0;
        $total_monto_autorizado = 0;
        // Obtener autorizaciones
        $authorizations = $this->paymentRequestAuthorizationsModel->get_by_payment_request($payment_id);
        $authorization_status = $this->paymentRequestAuthorizationsModel->get_authorization_status($payment_id);
        // Aplicaciones de notas de crédito/cargo ligadas a este pago
        $note_applications = $this->CreditNoteApplicationsModel->getByPayment($payment_id);
        $notes_totals      = $this->CreditNoteApplicationsModel->getTotalsByPayment($payment_id);
        // Crear array con información de cada autorización
        $auth_info = [
            'abastos'       => null,
            'contabilidad'  => null,
            'admin_finanzas'=> null,
            'tesoreria'     => null
        ];
        if ($authorizations) {
            foreach ($authorizations as $auth) {
                if ($auth['permission_number'] == 66) {
                    $auth_info['abastos'] = $auth;
                } elseif ($auth['permission_number'] == 70) {
                    $auth_info['contabilidad'] = $auth;
                } elseif ($auth['permission_number'] == 67) {
                    $auth_info['admin_finanzas'] = $auth;
                } elseif ($auth['permission_number'] == 68) {
                    $auth_info['tesoreria'] = $auth;
                }
            }
        }
        $transactions = $this->paymentTransactionsModel->get_by_payment_request($payment_id);

        // Historial de movimientos (alta/baja de facturas) de esta requisición.
        // Se decodifica el snapshot JSON aquí para mantener la vista libre de lógica de parseo.
        $audit_log_raw = $this->PaymentRequestAuditLogModel->get_by_payment($payment_id);
        $audit_log = array_map(function ($row) {
            $datos = json_decode($row['DatosNuevos'] ?? $row['DatosAnteriores'] ?? '', true) ?: [];
            return [
                'operacion'           => $row['Operacion'],
                'fecha'               => $row['Fecha'],
                'usuario_nombre'      => $row['UsuarioNombre'],
                'folio'               => $datos['folio'] ?? ($datos['nro'] ?? null),
                'invoice_number'      => $datos['invoice_number'] ?? ($datos['Factura'] ?? null),
                'amount'              => $datos['amount'] ?? ($datos['total_fac'] ?? null),
                'fue_post_agrupacion' => !empty($row['AccountingGroupId']),
            ];
        }, $audit_log_raw);

        // ✅ Obtener resumen desde el modelo
        $summary = $this->paymentRequestInvoicesModel->get_payment_summary_from_transactions($payment_id);
        $payment_calculation = [
            'invoice_total' => $summary['total_amount'] ?? 0,
            'advance_total' => $summary['total_advances'] ?? 0,
            'credit_notes_total' => $notes_totals['total_credits'],
            'debit_notes_total' => $notes_totals['total_debits'],
            'net_adjustment' => $notes_totals['net_adjustment'],
            'final_amount' => max(
                0,
                ($summary['total_amount'] ?? 0) -
                    ($summary['total_advances'] ?? 0) -
                    $notes_totals['total_credits'] +
                    $notes_totals['total_debits']
            )
        ];
        echo $this->twig->render($this->route . 'payment_detail.html', compact(
            'payment',
            'invoices',
            'authorizations',
            'authorization_status',
            'auth_info',
            'summary',
            'transactions',
            'facturas_autorizadas',
            'total_monto_autorizado',
            'note_applications',
            'notes_totals',
            'payment_calculation',
            'audit_log'
        ));
        // } catch (Exception $e) {
        //     setFlashMessage('error', 'Error al cargar el detalle: ' . $e->getMessage());
        //     redirect('/payment/payment_list');
        // }
    }




    function addNoteModal()
    {
        $provider_id = $_POST['provider_id'] ?? null;
        $provider    = $this->proveedores->get_by_id($provider_id);
        echo $this->twig->render($this->route . 'modals/addNoteModal.html', compact('provider_id', 'provider'));
    }


    /**
     * Vista: Todos los pagos registrados
     */
    public function all_payments()
    {
        $proveedores = $this->proveedores->get_actives();
        $companys = $this->gasolinerasModel->get_company();
        echo $this->twig->render($this->route . 'all_payments.html', compact('proveedores', 'companys'));
    }


    /**
     * API JSON: lotes de pago agrupados (nivel 1 de la tabla)
     */
    public function all_payments_table()
    {
        header('Content-Type: application/json');
        try {
            $rows = $this->paymentTransactionsModel->get_lotes_with_filters(
                $_POST['from']     ?? null,
                $_POST['until']    ?? null,
                $_POST['provider'] ?? null,
                $_POST['company']  ?? null
            );

            $statusLabels = [
                0 => '<span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;">Pendiente</span>',
                1 => '<span class="badge" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;">Procesado</span>',
                2 => '<span class="badge" style="background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;">Confirmado</span>',
                3 => '<span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;">Rechazado</span>',
            ];

            $data = [];
            foreach ($rows as $r) {
                $data[] = [
                    'id'               => $r['id'],
                    'transaction_ids'  => $r['transaction_ids'],
                    'payment_date'     => $r['payment_date']      ? date('d/m/Y', strtotime($r['payment_date'])) : '-',
                    'payment_reference'=> $r['payment_reference'] ?? '-',
                    'payment_method'   => $r['payment_method']    ?? '-',
                    'proveedor'        => $r['proveedor']         ?? '-',
                    'empresa'          => $r['empresa']           ?? '-',
                    'beneficiary_name' => $r['beneficiary_name']  ?? '-',
                    'total_facturas'   => (int)$r['total_facturas'],
                    'total_monto'      => '$' . number_format(floatval($r['total_monto']), 2, '.', ','),
                    'status'           => $statusLabels[$r['status']] ?? '<span class="badge bg-secondary">-</span>',
                    'notes'            => $r['notes']             ?? '-',
                    'created_at'       => $r['created_at']        ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-',
                    'creado_por'       => $r['creado_por']        ?? '-',
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['data' => [], 'error' => $e->getMessage()]);
        }
    }


    /**
     * API JSON: detalle de facturas de un lote (child row)
     */
    public function all_payments_lote_detail()
    {
        header('Content-Type: application/json');
        try {
            $ids_raw = $_POST['transaction_ids'] ?? '';
            $ids = array_filter(array_map('intval', explode(',', $ids_raw)));

            if (empty($ids)) {
                echo json_encode(['data' => []]);
                return;
            }

            $rows = $this->paymentTransactionsModel->get_lote_detail($ids);

            $statusLabels = [
                0 => '<span class="badge" style="background:#fef3c7;color:#92400e;">Pendiente</span>',
                1 => '<span class="badge" style="background:#dbeafe;color:#1e40af;">Procesado</span>',
                2 => '<span class="badge" style="background:#d1fae5;color:#065f46;">Confirmado</span>',
                3 => '<span class="badge" style="background:#fee2e2;color:#991b1b;">Rechazado</span>',
            ];

            $data = [];
            foreach ($rows as $r) {
                $doc_id = $r['doc_id'] ?? null;
                $doc_ext = $r['doc_ext'] ?? null;
                $docBtn = $doc_id
                    ? '<button class="btn btn-sm" style="background:#eff6ff;color:#2563eb;border:none;border-radius:4px;padding:2px 7px;" onclick="abrirComprobanteModal(' . $doc_id . ',\'' . $doc_ext . '\')" title="Ver comprobante"><i class="fas fa-' . ($doc_ext === 'pdf' ? 'file-pdf' : 'file-image') . '" style="font-size:.8rem;"></i></button>'
                    : '<span style="color:#cbd5e1;font-size:.75rem;">—</span>';

                $data[] = [
                    'id'                 => $r['id'],
                    'payment_request_id' => '<a href="/payment/payment_detail/' . $r['payment_request_id'] . '" class="text-primary text-decoration-none fw-semibold">#' . $r['payment_request_id'] . '</a>',
                    'folio'              => $r['folio']          ?? '-',
                    'invoice_number'     => $r['invoice_number'] ?? '-',
                    'estacion'           => $r['estacion']       ?? '-',
                    'payment_amount'     => '$' . number_format(floatval($r['payment_amount']), 2, '.', ','),
                    'status'             => $statusLabels[$r['status']] ?? '-',
                    'notes'              => $r['notes']          ?? '-',
                    'created_at'         => $r['created_at']     ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-',
                    'comprobante'        => $docBtn,
                ];
            }

            echo json_encode(['data' => $data]);
        } catch (Exception $e) {
            echo json_encode(['data' => [], 'error' => $e->getMessage()]);
        }
    }


    /**
     * Autorizar un pago
     */
    function authorize_payment()
    {
        header('Content-Type: application/json');
        try {
            $payment_id = $_POST['payment_id'] ?? null;
            $permission = $_POST['permission'] ?? null; // Permiso específico del botón
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            if (!$payment_id || !$user_id || !$permission) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            // Verificar que el usuario tenga el permiso que está intentando usar
            if (!authorized($permission)) {
                json_output(['success' => false, 'message' => 'No tienes permiso para autorizar en este nivel']);
                return;
            }


            // Verificar si puede autorizar con ese permiso específico
            $can_authorize = $this->paymentRequestAuthorizationsModel->can_user_authorize(
                $payment_id,
                $user_id,
                $permission
            );

            if (!$can_authorize['can_authorize']) {
                json_output(['success' => false, 'message' => $can_authorize['reason']]);
                return;
            }

            // Insertar autorización
            $auth_id = $this->paymentRequestAuthorizationsModel->insert_authorization(
                $payment_id,
                $user_id,
                $permission
            );

            if (!$auth_id) {
                json_output(['success' => false, 'message' => 'Error al registrar autorización']);
                return;
            }

            // Verificar si ya están todas las autorizaciones
            $next_level = $this->paymentRequestAuthorizationsModel->get_next_authorization_level($payment_id);

            // Con un solo nivel (Tesorería), next_level siempre será null tras autorizar
            $this->PaymentRequestsModel->update_request_status(
                $payment_id,
                PaymentRequestsModel::STATUS_AUTHORIZED
            );
            $message = '✅ Pago autorizado por Tesorería. Puede proceder al pago.';

            json_output([
                'success'      => true,
                'message'      => $message,
                'all_authorized' => true
            ]);
        } catch (Exception $e) {
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    /**
     * Aprobación implícita de Tesorería: al autorizar facturas de un pago
     * Pendiente se registra la autorización (auditoría) y se sube el status,
     * sin requerir el paso previo de "Aprobar".
     */
    private function ensure_tesoreria_approval($payment_id, $current_status, $user_id)
    {
        if ($current_status == PaymentRequestsModel::STATUS_AUTHORIZED) {
            return ['success' => true];
        }

        if ($current_status != PaymentRequestsModel::STATUS_PENDING) {
            return [
                'success' => false,
                'message' => 'El pago no puede autorizarse, su estado es: ' . PaymentRequestsModel::getStatusText($current_status)
            ];
        }

        if (!$this->paymentRequestAuthorizationsModel->is_authorized_by_permission($payment_id, PaymentRequestAuthorizationsModel::PERM_TESORERIA)) {
            $auth_id = $this->paymentRequestAuthorizationsModel->insert_authorization(
                $payment_id,
                $user_id,
                PaymentRequestAuthorizationsModel::PERM_TESORERIA
            );
            if (!$auth_id) {
                return ['success' => false, 'message' => 'Error al registrar la autorización de Tesorería'];
            }
        }

        $this->PaymentRequestsModel->update_request_status($payment_id, PaymentRequestsModel::STATUS_AUTHORIZED);

        return ['success' => true];
    }


    function authorize_payment_execution()
    {
        header('Content-Type: application/json');

        try {
            // Obtener datos JSON
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if ($data === null) {
                json_output(['success' => false, 'message' => 'Datos JSON inválidos']);
                return;
            }

            $payment_id = $data['payment_id'] ?? null;
            $facturas = $data['facturas'] ?? [];
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            // ========================================
            // VALIDACIONES
            // ========================================

            if (!$payment_id || !$user_id) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            if (empty($facturas)) {
                json_output(['success' => false, 'message' => 'Debe seleccionar al menos una factura']);
                return;
            }

            // Verificar que el pago exista y esté AUTORIZADO (status = 1)
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                json_output(['success' => false, 'message' => 'Solicitud de pago no encontrada']);
                return;
            }

            // Verificar que el usuario tenga permiso de Tesorería (68)
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede autorizar facturas para ejecución de pago']);
                return;
            }

            // Aprobación implícita: si el pago sigue Pendiente, se registra la
            // autorización de Tesorería y se sube el status en este mismo paso
            $approval = $this->ensure_tesoreria_approval($payment_id, $payment['status'], $user_id);
            if (!$approval['success']) {
                json_output(['success' => false, 'message' => $approval['message']]);
                return;
            }

            // Si Tesorería autoriza una requisición que Abastos aún no agrupó/envió
            // por correo, se agrupa en este momento para no perder trazabilidad contable.
            $auto_grouped = false;
            $accounting_id = null;
            if (empty($payment['accounting_group_id'])) {
                $group_result = $this->PaymentAccountingGroupsModel->auto_group_single_request(
                    (int)$payment_id,
                    (string)($payment['emp_cod'] ?? ''),
                    $user_id
                );
                if ($group_result['success']) {
                    $auto_grouped = true;
                    $accounting_id = $group_result['accounting_id'];
                }
            }

            // ========================================
            // PROCESAR AUTORIZACIONES USANDO EL MODELO
            // ========================================

            $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                $payment_id,
                $facturas,
                $user_id
            );

            // Responder con el resultado del modelo
            if ($result['success']) {
                // Construir mensaje con detalles
                $mensaje = $result['message'];

                if (!empty($result['errores'])) {
                    $mensaje .= "\n\nAdvertencias:\n" . implode("\n", $result['errores']);
                }

                json_output([
                    'success' => true,
                    'message' => $mensaje,
                    'facturas_autorizadas' => $result['facturas_autorizadas'],
                    'total_autorizado' => number_format($result['total_autorizado'], 2, '.', ''),
                    'errores' => $result['errores'] ?? [],
                    'auto_grouped' => $auto_grouped,
                    'accounting_id' => $accounting_id
                ]);
            } else {
                json_output(['success' => false, 'message' => $result['message']]);
            }
        } catch (Exception $e) {
            error_log("Error en authorize_payment_execution: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }


    /**
     * Endpoint para obtener todas las facturas pendientes de autorización de pago
     * de todos los pagos autorizados. Para el modal masivo en payment_list.
     */
    function get_pending_payment_invoices()
    {
        header('Content-Type: application/json');
        try {
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede acceder a esta información']);
                return;
            }

            $invoices = $this->paymentRequestInvoicesModel->get_all_pending_payment_invoices();

            if (!$invoices) {
                json_output(['success' => true, 'data' => [], 'message' => 'No hay facturas pendientes de autorización de pago']);
                return;
            }

            $data = [];
            foreach ($invoices as $invoice) {
                $data[] = [
                    'id' => $invoice['id'],
                    'payment_request_id' => $invoice['payment_request_id'],
                    'folio' => $invoice['folio'],
                    'invoice_number' => $invoice['invoice_number'],
                    'estacion_nombre' => $invoice['estacion_nombre'] ?? 'N/A',
                    'amount' => floatval($invoice['amount']),
                    'paid_amount' => floatval($invoice['paid_amount'] ?? 0),
                    'saldo' => floatval($invoice['saldo']),
                    'total_notas_credito' => floatval($invoice['total_notas_credito'] ?? 0),
                    'total_notas_cargo' => floatval($invoice['total_notas_cargo'] ?? 0),
                    'notas_count' => intval($invoice['notas_count'] ?? 0),
                    'saldo_neto' => floatval($invoice['saldo_neto']),
                    'expiration_date' => $invoice['expiration_date'],
                    'empresa_nombre' => $invoice['empresa_nombre'] ?? 'N/A',
                    'proveedor_nombre' => $invoice['proveedor_nombre'] ?? 'N/A',
                    'uuid' => $invoice['uuid'],
                    'pago_id' => $invoice['pago_id'],
                    'pago_fecha' => $invoice['pago_fecha']
                ];
            }

            json_output(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            error_log("Error en get_pending_payment_invoices: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    /**
     * Autorización masiva de facturas para ejecución de pago.
     * Maneja facturas de MÚLTIPLES payment_requests a la vez.
     */
    function bulk_authorize_payment_execution()
    {
        header('Content-Type: application/json');

        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if ($data === null) {
                json_output(['success' => false, 'message' => 'Datos JSON inválidos']);
                return;
            }

            $facturas = $data['facturas'] ?? [];
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            if (!$user_id) {
                json_output(['success' => false, 'message' => 'Usuario no identificado']);
                return;
            }

            if (empty($facturas)) {
                json_output(['success' => false, 'message' => 'Debe seleccionar al menos una factura']);
                return;
            }

            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede autorizar facturas para ejecución de pago']);
                return;
            }

            // Agrupar facturas por payment_request_id
            $facturas_por_pago = [];
            foreach ($facturas as $factura) {
                $pid = $factura['payment_id'] ?? null;
                if (!$pid) continue;
                if (!isset($facturas_por_pago[$pid])) {
                    $facturas_por_pago[$pid] = [];
                }
                $facturas_por_pago[$pid][] = $factura;
            }

            $total_autorizadas = 0;
            $total_autorizado = 0;
            $todos_errores = [];
            $pagos_procesados = 0;

            // Procesar cada grupo de facturas por pago
            foreach ($facturas_por_pago as $payment_id => $facturas_del_pago) {
                // Verificar que el pago exista y esté AUTORIZADO
                $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

                if (!$payment) {
                    $todos_errores[] = "Pago #{$payment_id}: no encontrado";
                    continue;
                }

                // Aprobación implícita de Tesorería si el pago sigue Pendiente
                $approval = $this->ensure_tesoreria_approval($payment_id, $payment['status'], $user_id);
                if (!$approval['success']) {
                    $todos_errores[] = "Pago #{$payment_id}: " . $approval['message'];
                    continue;
                }

                // Usar el mismo método del modelo que ya funciona
                $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                    $payment_id,
                    $facturas_del_pago,
                    $user_id
                );

                if ($result['success']) {
                    $total_autorizadas += $result['facturas_autorizadas'];
                    $total_autorizado += $result['total_autorizado'];
                    $pagos_procesados++;
                }

                if (!empty($result['errores'])) {
                    foreach ($result['errores'] as $err) {
                        $todos_errores[] = "Pago #{$payment_id}: {$err}";
                    }
                }
            }

            if ($total_autorizadas > 0) {
                $mensaje = "{$total_autorizadas} factura(s) autorizada(s) de {$pagos_procesados} pago(s)";
                $mensaje .= " - Total: $" . number_format($total_autorizado, 2);

                if (!empty($todos_errores)) {
                    $mensaje .= "\n\nAdvertencias:\n" . implode("\n", $todos_errores);
                }

                json_output([
                    'success' => true,
                    'message' => $mensaje,
                    'facturas_autorizadas' => $total_autorizadas,
                    'total_autorizado' => number_format($total_autorizado, 2, '.', ''),
                    'pagos_procesados' => $pagos_procesados,
                    'errores' => $todos_errores
                ]);
            } else {
                $msg = 'No se pudo autorizar ninguna factura';
                if (!empty($todos_errores)) {
                    $msg .= ': ' . implode(', ', $todos_errores);
                }
                json_output(['success' => false, 'message' => $msg]);
            }
        } catch (Exception $e) {
            error_log("Error en bulk_authorize_payment_execution: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }


    function execute_authorized_payments()
    {
        header('Content-Type: application/json');
        try {
            $invoice_ids = json_decode($_POST['invoice_ids'] ?? '[]', true);
            $fecha_pago = $_POST['fecha_pago'] ?? null;
            $referencia_bancaria = $_POST['referencia_bancaria'] ?? null;
            $observaciones = $_POST['observaciones'] ?? '';
            $user_id = $_SESSION['tg_user']['Id'] ?? null;


            if (empty($invoice_ids)) {
                json_output(['success' => false, 'message' => 'No se proporcionaron facturas']); return;
            }
            if (!$fecha_pago || !$referencia_bancaria) {
                json_output(['success' => false, 'message' => 'Faltan datos obligatorios: fecha y referencia bancaria']); return;
            }
            if (!$user_id) {
                json_output(['success' => false, 'message' => 'Usuario no identificado']); return;
            }
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede ejecutar pagos']); return;
            }

            // $facturas_autorizadas = $this->paymentRequestInvoicesModel->get_authorized_pending_payment($payment_id);
            $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_autorizadas_by_ids($invoice_ids);


            if (!$facturas_data || empty($facturas_data)) {
                json_output(['success' => false, 'message' => 'No hay facturas autorizadas pendientes de pago']);
                return;
            }

            // ✅ PREPARAR DATOS PARA PROCESAR (formato que espera el modelo)
            $facturas_procesar = [];
            $payment_request_ids_unicos = [];
            $monto_total = 0.0;
            foreach ($facturas_data as $factura) {
                if ($factura['payment_authorized'] != 1) {
                    json_output(['success' => false, 'message' => "La factura {$factura['folio']} no está autorizada"]); return;
                }
                $facturas_procesar[] = [
                    'invoice_id' => $factura['id'],
                    'folio' => $factura['folio'],
                    'monto_pagar' => $factura['authorized_amount'], // ✅ Usar monto autorizado
                    'saldo_anterior' => $factura['saldo'],
                    'payment_request_id' => $factura['payment_request_id'] // ✅ Incluir para el proceso

                ];

                $payment_request_ids_unicos[$factura['payment_request_id']] = true;
                $monto_total += (float)$factura['authorized_amount'];
            }

            // Derivar empresa/proveedor para la cabecera del lote
            $primer_prid = $facturas_data[0]['payment_request_id'];
            $req = $this->PaymentRequestsModel->get_request_by_id($primer_prid);

            $comprobanteFile = (!empty($_FILES['comprobante']['name']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK)
                ? $_FILES['comprobante'] : null;

            // ✅ Crear lote + ejecutar pago + marcar pagado + adjuntar comprobante al lote
            $result = $this->registrar_lote_y_pago(
                $facturas_procesar,
                [
                    'fecha_pago'    => $fecha_pago,
                    'referencia'    => $referencia_bancaria,
                    'observaciones' => $observaciones,
                    'emp_cod'       => $req['emp_cod'] ?? null,
                    'provider_cod'  => $req['provider_cod'] ?? null,
                    'monto_total'   => $monto_total,
                ],
                $comprobanteFile,
                $user_id
            );

            if ($result['success']) {
                // Contar requisiciones completadas (para el resumen del front)
                $solicitudes_completadas = 0;
                foreach (array_keys($payment_request_ids_unicos) as $payment_request_id) {
                    if ($this->paymentTransactionsModel->check_all_invoices_paid($payment_request_id)) {
                        $solicitudes_completadas++;
                    }
                }

                json_output([
                    'success'                 => true,
                    'message'                 => $result['message'],
                    'facturas_procesadas'     => $result['facturas_procesadas'],
                    'total_pagado'            => $result['total_pagado'],
                    'fecha_pago'              => date('d/m/Y', strtotime($fecha_pago)),
                    'referencia_bancaria'     => $referencia_bancaria,
                    'solicitudes_completadas' => $solicitudes_completadas,
                    'total_solicitudes'       => count($payment_request_ids_unicos)
                ]);
            } else {
                json_output(['success' => false, 'message' => $result['message']]);
            }
        } catch (Exception $e) {
            error_log("Error en execute_authorized_payments: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }


    //         if ($result['success']) {
    //             // Si todas las facturas están completamente pagadas, cambiar estado del pago
    //             if ($result['all_paid']) {
    //                 $this->PaymentRequestsModel->update_request_status($payment_id,PaymentRequestsModel::STATUS_PAID);
    //             }

    //             json_output([
    //                 'success' => true,
    //                 'message' => $result['message'],
    //                 'facturas_procesadas' => $result['facturas_procesadas'],
    //                 'total_pagado' => $result['total_pagado']
    //             ]);
    //         } else {
    //             json_output(['success' => false, 'message' => $result['message']]);
    //         }
    //     } catch (Exception $e) {
    //         error_log("Error en process_payment: " . $e->getMessage());
    //         json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    //     }
    // }
    function get_payment_history()
    {
        header('Content-Type: application/json');

        $invoice_id = $_GET['invoice_id'] ?? null;

        if (!$invoice_id) {
            json_output(['success' => false, 'message' => 'ID de factura requerido']);
            return;
        }

        try {
            $transactions = $this->paymentTransactionsModel->get_payment_history($invoice_id);

            if ($transactions) {
                json_output([
                    'success' => true,
                    'data' => $transactions
                ]);
            } else {
                json_output([
                    'success' => true,
                    'data' => [],
                    'message' => 'No hay pagos registrados'
                ]);
            }
        } catch (Exception $e) {
            json_output([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }


    function download_layout($filename)
    {
        // ✅ Sanitizar filename (seguridad básica)
        $filename = basename($filename); // Elimina path traversal
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename); // Solo caracteres seguros

        // ✅ Verificar extensión
        if (!str_ends_with($filename, '.xls')) {
            http_response_code(400);
            exit('Extensión no permitida');
        }

        $file = __DIR__ . '/../../_assets/temp/layouts/' . $filename;

        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename=' . basename($file));
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            ob_clean();
            flush();
            readfile($file);
            exit;
        } else {
            http_response_code(404);
            exit('Archivo no encontrado');
        }
    }


    function delete_layout()
    {
        header('Content-Type: application/json');

        $filename = $_POST['filename'] ?? '';
        $filename = basename($filename); // Seguridad

        if (empty($filename)) {
            json_output(['success' => false, 'message' => 'Filename requerido']);
            return;
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'xls') {
            json_output(['success' => false, 'message' => 'Extensión no válida']);
            return;
        }

        $file = __DIR__ . '/../../_assets/temp/layouts/' . $filename;

        if (file_exists($file)) {
            if (unlink($file)) {
                error_log("Archivo eliminado: $filename");
                json_output(['success' => true, 'message' => 'Archivo eliminado']);
            } else {
                error_log("ERROR: No se pudo eliminar: $filename");
                json_output(['success' => false, 'message' => 'Error al eliminar']);
            }
        } else {
            json_output(['success' => false, 'message' => 'Archivo no existe']);
        }
    }


    /**
     * Botón "Mandar a pagos" (Abastos): agrupa las requisiciones del día por empresa
     * y envía el correo de solicitud a los destinatarios configurados.
     * También lo ejecuta el cron a las 11am si Abastos no lo hizo antes.
     *
     * Puede llamarse:
     *   - Por POST desde la UI (usuario con permiso 66)
     *   - Por el cron vía token: POST cron_token=CRON_SECRET
     */
    public function send_to_payments()
    {
        header('Content-Type: application/json');
        try {
            $cronToken  = $_POST['cron_token'] ?? $_GET['cron_token'] ?? null;
            $validToken = defined('CRON_SECRET') ? CRON_SECRET : null;

            $isAuthorized = ($validToken && $cronToken === $validToken)
                || authorized(66);

            if (!$isAuthorized) {
                json_output(['success' => false, 'message' => 'No autorizado']);
                return;
            }

            $user_id = $_SESSION['tg_user']['Id'] ?? 0;
            $today   = date('Y-m-d');

            // 1. Agrupar requisiciones del día por empresa
            $group_result = $this->PaymentAccountingGroupsModel->auto_group_by_date($today, $user_id);

            // 2. Obtener pagos pendientes con PDF completo para el correo
            $pagos = $this->PaymentRequestsModel->get_payments_ready_for_request();

            if (empty($pagos)) {
                json_output([
                    'success'        => true,
                    'message'        => 'Requisiciones agrupadas pero no hay pagos con PDF completo para notificar.' . ($group_result['grupos'] > 0 ? " Se crearon {$group_result['grupos']} grupo(s)." : ''),
                    'grupos_creados' => $group_result['grupos'] ?? 0
                ]);
                return;
            }

            // 3. Destinatarios
            if (self::TEST_MODE) {
                $emails = [self::TEST_MODE_EMAIL];
            } else {
                $emails = $this->PaymentNotificationRecipientsModel->get_active_emails('solicitud_pago');
            }

            if (empty($emails)) {
                json_output(['success' => false, 'message' => 'No hay destinatarios configurados para la notificación.']);
                return;
            }

            $total_general = array_sum(array_map(fn($p) => (float)$p['monto_neto'], $pagos));

            $subject = 'Solicitud de pago a proveedores - ' . count($pagos) . ' pago(s) - ' . date('d/m/Y');
            $body    = $this->generar_html_solicitud_pagos($pagos, $total_general);
            $from    = 'no-reply@totalgas.com';

            $mailError = null;
            $ok = send_mail($subject, $body, $emails, $from, false, false, $mailError);

            if ($ok) {
                error_log('send_to_payments: agrupados ' . ($group_result['grupos'] ?? 0) . ' grupos, correo enviado a ' . implode(', ', $emails));
                json_output([
                    'success'        => true,
                    'message'        => 'Listo. Se crearon ' . ($group_result['grupos'] ?? 0) . ' grupo(s) y se notificaron ' . count($pagos) . ' pago(s).' . (self::TEST_MODE ? ' [MODO PRUEBAS]' : ''),
                    'grupos_creados' => $group_result['grupos'] ?? 0,
                    'total_pagos'    => count($pagos),
                    'total_monto'    => $total_general,
                    'destinatarios'  => $emails
                ]);
            } else {
                $detalle = $this->_describir_error_correo($mailError);
                error_log('send_to_payments: agrupación OK (' . ($group_result['grupos'] ?? 0) . ' grupos) pero FALLÓ el envío. Motivo: ' . ($mailError ?? 'desconocido'));
                json_output([
                    'success'        => false,
                    'mail_failed'    => true,
                    'grupos_creados' => $group_result['grupos'] ?? 0,
                    'total_pagos'    => count($pagos),
                    'destinatarios'  => $emails,
                    'mail_error'     => $mailError,
                    'message'        => 'Las requisiciones SÍ se agruparon (' . ($group_result['grupos'] ?? 0) . ' grupo(s)), pero el correo a '
                        . count($emails) . ' destinatario(s) NO se pudo enviar.' . "\n\nMotivo: " . $detalle
                        . "\n\nPuedes reintentar el envío con el botón \"Reenviar correo\" sin volver a agrupar.",
                ]);
            }
        } catch (Exception $e) {
            error_log('Error en send_to_payments: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
        }
    }

    /**
     * Traduce el error crudo de PHPMailer/SMTP a un mensaje accionable para el usuario.
     */
    private function _describir_error_correo(?string $rawError): string
    {
        $raw = trim((string)$rawError);
        if ($raw === '') {
            return 'El servidor de correo rechazó el envío sin dar detalle. Revisa la conexión a internet del servidor y la configuración SMTP.';
        }

        $lower = mb_strtolower($raw);

        if (str_contains($lower, 'could not authenticate') || str_contains($lower, 'username and password') || str_contains($lower, '535') || str_contains($lower, 'webloginrequired') || str_contains($lower, '534')) {
            return 'La cuenta de correo del sistema (no-reply@totalgas.com) no pudo autenticarse con el servidor SMTP. '
                . 'Es muy probable que la contraseña de aplicación haya expirado o que Google haya bloqueado el acceso. '
                . 'Hay que regenerar la contraseña de aplicación y actualizarla en el sistema. '
                . '[Detalle técnico: ' . $raw . ']';
        }
        if (str_contains($lower, 'could not connect') || str_contains($lower, 'smtp connect') || str_contains($lower, 'timed out') || str_contains($lower, 'connection refused')) {
            return 'No se pudo conectar con el servidor de correo (smtp.gmail.com:465). '
                . 'Revisa la conexión a internet del servidor o si un firewall está bloqueando el puerto 465. '
                . '[Detalle técnico: ' . $raw . ']';
        }
        if (str_contains($lower, 'invalid address') || str_contains($lower, 'address')) {
            return 'Uno de los correos destinatarios tiene un formato inválido. Revisa el catálogo de destinatarios. '
                . '[Detalle técnico: ' . $raw . ']';
        }

        return 'El servidor de correo reportó: ' . $raw;
    }
 

    /**
     * Reenvía el correo de solicitud SIN volver a agrupar/cerrar nada.
     * Toma los pagos cuyos grupos de contabilidad se crearon HOY (es decir, los
     * que ya "se cerraron" hoy) y vuelve a mandar el correo a los destinatarios.
     *
     * Pensado para cuando la agrupación funcionó pero el correo falló.
     * Restringido al usuario con Id = 6296.
     */
    public function resend_today_payments()
    {
        header('Content-Type: application/json');
        try {
            $user_id = (int)($_SESSION['tg_user']['Id'] ?? 0);
            if ($user_id !== 6296) {
                json_output(['success' => false, 'message' => 'No autorizado para reenviar el correo.']);
                return;
            }

            $today = date('Y-m-d');

            // Pagos cuyos grupos de contabilidad se crearon hoy (ya cerrados hoy).
            $pagos = $this->PaymentAccountingGroupsModel->get_payments_by_group_date($today);

            if (empty($pagos)) {
                json_output([
                    'success' => false,
                    'message' => 'No hay pagos agrupados hoy para reenviar. (No se encontró ningún grupo de contabilidad creado el ' . date('d/m/Y') . '.)'
                ]);
                return;
            }

            // Destinatarios
            if (self::TEST_MODE) {
                $emails = [self::TEST_MODE_EMAIL];
            } else {
                $emails = $this->PaymentNotificationRecipientsModel->get_active_emails('solicitud_pago');
            }

            if (empty($emails)) {
                json_output(['success' => false, 'message' => 'No hay destinatarios configurados para la notificación.']);
                return;
            }

            $total_general = array_sum(array_map(fn($p) => (float)$p['monto_neto'], $pagos));

            $subject = 'Solicitud de pago a proveedores (reenvío) - ' . count($pagos) . ' pago(s) - ' . date('d/m/Y');
            $body    = $this->generar_html_solicitud_pagos($pagos, $total_general);
            $from    = 'no-reply@totalgas.com';

            $mailError = null;
            $ok = send_mail($subject, $body, $emails, $from, false, false, $mailError);

            if ($ok) {
                error_log('resend_today_payments: correo REENVIADO a ' . implode(', ', $emails) . ' con ' . count($pagos) . ' pago(s).');
                json_output([
                    'success'       => true,
                    'message'       => 'Correo reenviado correctamente con ' . count($pagos) . ' pago(s) cerrados hoy.' . (self::TEST_MODE ? ' [MODO PRUEBAS]' : ''),
                    'total_pagos'   => count($pagos),
                    'total_monto'   => $total_general,
                    'destinatarios' => $emails,
                ]);
            } else {
                $detalle = $this->_describir_error_correo($mailError);
                error_log('resend_today_payments: FALLÓ el reenvío. Motivo: ' . ($mailError ?? 'desconocido'));
                json_output([
                    'success'     => false,
                    'mail_failed' => true,
                    'mail_error'  => $mailError,
                    'message'     => 'No se pudo reenviar el correo a ' . count($emails) . ' destinatario(s).'
                        . "\n\nMotivo: " . $detalle,
                ]);
            }
        } catch (Exception $e) {
            error_log('Error en resend_today_payments: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
        }
    }


    // send_ready_payments() eliminado — reemplazado por send_to_payments() (Abastos)



    /**
     * HTML del correo de solicitud de pagos listos.
     */
    private function generar_html_solicitud_pagos(array $pagos, float $total_general): string
    {
        $td  = 'style="padding:8px;border:1px solid #e2e8f0;"';
        $tdr = 'style="padding:8px;border:1px solid #e2e8f0;text-align:right;"';
        $tdc = 'style="padding:8px;border:1px solid #e2e8f0;text-align:center;"';

        $filas = '';
        foreach ($pagos as $p) {
            $fechaPago   = !empty($p['scheduled_payment_date'])
                ? date('d/m/Y', strtotime($p['scheduled_payment_date']))
                : '-';
            $nc          = (float)$p['total_notas_credito'];
            $nd          = (float)$p['total_notas_cargo'];
            $neto        = (float)$p['monto_neto'];
            $ncStr       = $nc > 0 ? '<span style="color:#16a34a;">-$' . number_format($nc, 2) . '</span>' : '<span style="color:#94a3b8;">$0.00</span>';
            $ndStr       = $nd > 0 ? '<span style="color:#dc2626;">+$' . number_format($nd, 2) . '</span>' : '<span style="color:#94a3b8;">$0.00</span>';
            $filas .= '<tr>'
                . '<td ' . $td  . '>#' . htmlspecialchars($p['id']) . '</td>'
                . '<td ' . $td  . '>' . htmlspecialchars($p['emp_name'] ?? '-') . '</td>'
                . '<td ' . $td  . '>' . htmlspecialchars($p['provider_name'] ?? '-') . '</td>'
                . '<td ' . $tdc . '>' . (int)$p['total_invoices'] . '</td>'
                . '<td ' . $tdc . '>' . $fechaPago . '</td>'
                . '<td ' . $tdr . '>$' . number_format((float)$p['total_amount'], 2) . '</td>'
                . '<td ' . $tdr . '>' . $ncStr . '</td>'
                . '<td ' . $tdr . '>' . $ndStr . '</td>'
                . '<td ' . $tdr . ' style="padding:8px;border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#1e293b;"><strong>$' . number_format($neto, 2) . '</strong></td>'
                . '</tr>';
        }

        $th = 'style="padding:8px;border:1px solid #e2e8f0;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#475569;"';
        $thc = 'style="padding:8px;border:1px solid #e2e8f0;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#475569;"';
        $thr = 'style="padding:8px;border:1px solid #e2e8f0;text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#475569;"';

        return '
        <div style="font-family:Arial,sans-serif;max-width:820px;margin:0 auto;color:#1e293b;">
            <div style="background:#16a34a;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;">
                <h2 style="margin:0;font-size:18px;">Solicitud de pago a proveedores</h2>
                <p style="margin:4px 0 0;font-size:13px;">' . date('d/m/Y H:i') . ' &middot; ' . count($pagos) . ' pago(s) listos para su pago</p>
            </div>
            <div style="border:1px solid #e2e8f0;border-top:none;padding:20px;border-radius:0 0 8px 8px;">
                <p style="font-size:14px;">Los siguientes pagos tienen <strong>todas sus facturas con PDF recibido</strong> y están listos para solicitar su pago a Tesorería:</p>
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th ' . $th  . '>ID</th>
                            <th ' . $th  . '>Empresa</th>
                            <th ' . $th  . '>Proveedor</th>
                            <th ' . $thc . '>Facturas</th>
                            <th ' . $thc . '>Fecha pago esp.</th>
                            <th ' . $thr . '>Total Facturas</th>
                            <th ' . $thr . '>N. Crédito</th>
                            <th ' . $thr . '>N. Cargo</th>
                            <th ' . $thr . '>Monto Neto</th>
                        </tr>
                    </thead>
                    <tbody>' . $filas . '</tbody>
                    <tfoot>
                        <tr style="background:#f0fdf4;font-weight:bold;">
                            <td colspan="8" style="padding:8px;border:1px solid #e2e8f0;text-align:right;color:#475569;">TOTAL A PAGAR</td>
                            <td style="padding:8px;border:1px solid #e2e8f0;text-align:right;font-size:15px;color:#16a34a;"><strong>$' . number_format($total_general, 2) . '</strong></td>
                        </tr>
                    </tfoot>
                </table>
                <p style="font-size:12px;color:#64748b;margin-top:16px;">Este correo se generó desde el Sistema de Gestión TotalGas.</p>
            </div>
        </div>';
    }


    private function enviar_notificacion_nuevo_pago($payment_id, $provider_name, $total_documents, $total_amount, $comment, $created_by)
    {
        try {
            // ============================================================
            // 🚧 MODO PRUEBAS - solo enviar a manuelmtz9k@gmail.com
            // ============================================================
            $emails = ['manuelmtz9k@gmail.com'];

            // Crear el cuerpo del correo
            $subject = "Nuevo Pago Creado - ID #{$payment_id}";
            $body = $this->generar_html_notificacion_pago(
                $payment_id,
                $provider_name,
                $total_documents,
                $total_amount,
                $comment,
                $created_by
            );

            // Enviar correo
            $from = 'no-reply@totalgas.com';

            // Capturar salida para evitar problemas con JSON
            $resultado = send_mail($subject, $body, $emails, $from);

            if ($resultado) {
                error_log("Notificación de pago #{$payment_id} enviada a: " . implode(', ', $emails));
            } else {
                error_log("Error al enviar notificación de pago #{$payment_id}");
            }
        } catch (Exception $e) {
            error_log("Error en enviar notificacion_nuevo_pago: " . $e->getMessage());
        }
    }


    private function enviar_notificacion_nuevo_anticipo($anticipo_id, $provider_name, $monto, $comentario, $created_by)
    {
        try {
            $emails = $this->UsuariosModel->get_emails_by_permission(66);

            if (empty($emails)) {
                error_log("No hay usuarios con permiso de Abastos para notificar anticipo");
                return;
            }
            $emails = array_filter($emails, function ($email) {
                return strtolower(trim($email)) !== 'kuwait.valenzuela@totalgas.com';
            });

            $subject = "Nuevo Anticipo Creado - ID #{$anticipo_id}";
            $body = $this->generar_html_notificacion_anticipo(
                $anticipo_id,
                $provider_name,
                $monto,
                $comentario,
                $created_by
            );

            $from = 'no-reply@totalgas.com';

            $resultado = send_mail($subject, $body, $emails, $from);

            if ($resultado) {
                error_log("Notificación de anticipo #{$anticipo_id} enviada a: " . implode(', ', $emails));
            } else {
                error_log("Error al enviar notificación de anticipo #{$anticipo_id}");
            }
        } catch (Exception $e) {
            error_log("Error en enviar_notificacion_nuevo_anticipo: " . $e->getMessage());
        }
    }


    private function enviar_notificacion_autorizacion_pendiente($payment_id, $next_level_permission, $authorized_permission, $user_id)
    {
        try {
            // ============================================================
            // 🚧 MODO PRUEBAS - solo enviar a manuelmtz9k@gmail.com
            // ============================================================
            $emails = ['manuelmtz9k@gmail.com'];
            // Obtener información del pago
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
            if (!$payment) {
                error_log("Pago #{$payment_id} no encontrado");
                return;
            }

            // Obtener nombre del proveedor
            $proveedor = $this->proveedores->get_by_id($payment['provider_cod']);
            $provider_name = $proveedor ? $proveedor['den'] : 'Proveedor';

            $next_department = $next_level_permission === 68 ? 'Tesorería' : 'Desconocido';

            // Crear el cuerpo del correo
            $subject = "Pago #{$payment_id} requiere tu autorización - {$next_department}";
            $body = $this->generar_html_notificacion_autorizacion(
                $payment_id,
                $provider_name,
                $payment['total_invoices'] ?? 0,
                $payment['monto_total'] ?? 0,
                $user_id,
                $next_department,
                $payment['comment'] ?? ''
            );

            // Enviar correo
            $from = 'no-reply@totalgas.com';

            $resultado = send_mail($subject, $body, $emails, $from);

            if ($resultado) {
                error_log("Notificación de autorización pendiente para pago #{$payment_id} enviada a {$next_department}: " . implode(', ', $emails));
            } else {
                error_log("Error al enviar notificación de autorización pendiente para pago #{$payment_id}");
            }
        } catch (Exception $e) {
            error_log("Error en enviar notificacion_autorizacion_pendiente: " . $e->getMessage());
        }
    }


    function authorized_pending_invoices()
    {
        try {
            // Obtener facturas autorizadas pendientes
            $invoices = $this->paymentRequestInvoicesModel->get_authorized_pending_invoices();

            // Obtener resumen por banco
            $summary_by_bank = $this->paymentRequestInvoicesModel->get_authorized_pending_summary_by_bank();

            echo $this->twig->render($this->route . 'payment_list.html', compact(
                'invoices',
                'summary_by_bank'
            ));
        } catch (Exception $e) {
            setFlashMessage('error', 'Error al cargar facturas: ' . $e->getMessage());
            redirect('/payment/payment_list');
        }
    }


    function authorized_pending_invoices_grouped_table()
    {
        header('Content-Type: application/json');

        try {
            $invoices = $this->paymentRequestInvoicesModel->get_authorized_pending_grouped();

            if (!$invoices) {
                json_output(['data' => []]);
                return;
            }

            $data = [];
            foreach ($invoices as $invoice) {
                $data[] = [
                    'emp_cod' => $invoice['emp_cod'],
                    'provider_cod' => $invoice['provider_cod'],
                    'empresa_nombre' => $invoice['empresa_nombre'] ?? 'N/A',
                    'empresa_rfc' => $invoice['empresa_rfc'] ?? 'N/A',
                    'proveedor_nombre' => $invoice['proveedor_nombre'] ?? 'N/A',
                    'proveedor_rfc' => $invoice['proveedor_rfc'] ?? 'N/A',
                    'banco_asignado' => $invoice['banco_asignado'],
                    'banco_color' => $invoice['banco_color'],
                    'total_facturas'        => $invoice['total_facturas'],
                    'total_autorizado'      => $invoice['total_autorizado'],
                    'total_notas_credito'   => $invoice['total_notas_credito'] ?? 0,
                    'total_notas_cargo'     => $invoice['total_notas_cargo'] ?? 0,
                    'total_saldo'           => $invoice['total_saldo'],
                    'vencimiento_mas_proximo' => $invoice['vencimiento_mas_proximo'],
                    'vencimiento_mas_lejano' => $invoice['vencimiento_mas_lejano'],
                    'invoice_ids' => $invoice['invoice_ids'],
                    'folios_list' => $invoice['folios_list'],
                    'authorized_by_name' => $invoice['authorized_by_name'] ?? 'N/A',
                    'ultima_autorizacion' => $invoice['ultima_autorizacion'],
                    'tipo_registro' => $invoice['tipo_registro'],
                    'payment_request_id' => $invoice['payment_request_id'],
                    'request_date' => $invoice['request_date'] ?? null,
                    'scheduled_payment_date' => $invoice['scheduled_payment_date'] ?? null
                ];
            }

            json_output(['data' => $data]);
        } catch (Exception $e) {
            error_log("Error en authorized_pending_invoices_grouped_table: " . $e->getMessage());
            json_output(['data' => [], 'error' => $e->getMessage()]);
        }
    }


    function get_invoices_detail()
    {
        header('Content-Type: application/json');
        try {
            $invoice_ids = $_POST['invoice_ids'] ?? '';
            if (empty($invoice_ids)) {
                json_output(['success' => false, 'message' => 'IDs de facturas requeridos']);
                return;
            }

            // Obtener facturas individuales
            $invoices = $this->paymentRequestInvoicesModel->get_invoices_detail_by_ids($invoice_ids);

            if (!$invoices) {
                json_output(['success' => false, 'message' => 'No se encontraron facturas']);
                return;
            }

            $data = [];
            foreach ($invoices as $invoice) {
                $data[] = [
                    'id' => $invoice['id'],
                    'payment_request_id' => $invoice['payment_request_id'],
                    'folio' => $invoice['folio'],
                    'invoice_number' => $invoice['invoice_number'],
                    'estacion_nombre' => $invoice['estacion_nombre'] ?? 'N/A',
                    'amount' => $invoice['amount'],
                    'paid_amount' => $invoice['paid_amount'] ?? 0,
                    'authorized_amount' => $invoice['authorized_amount'],
                    'saldo' => $invoice['saldo'],
                    'total_notas_credito' => floatval($invoice['total_notas_credito'] ?? 0),
                    'total_notas_cargo' => floatval($invoice['total_notas_cargo'] ?? 0),
                    'notas_count' => intval($invoice['notas_count'] ?? 0),
                    'saldo_neto' => $invoice['saldo_neto'],
                    'expiration_date' => $invoice['expiration_date'],
                    'authorized_by_name' => $invoice['authorized_by_name'] ?? 'N/A',
                    'authorized_at' => $invoice['authorized_at'],
                    'empresa_nombre' => $invoice['empresa_nombre'] ?? 'N/A',
                    'proveedor_nombre' => $invoice['proveedor_nombre'] ?? 'N/A',
                    'uuid' => $invoice['uuid']
                ];
            }

            json_output(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            error_log("Error en get_invoices_detail: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    /**
     * Desautoriza ("limpia") facturas autorizadas para pago, regresándolas a la
     * cola de autorización de Tesorería. Solo Tesorería (68). No permite
     * desautorizar facturas con pagos registrados.
     */
    public function unauthorize_invoices()
    {
        header('Content-Type: application/json');
        try {
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede desautorizar facturas']);
                return;
            }

            $raw = $_POST['invoice_ids'] ?? '';
            // Acepta JSON (["1","2"]) o lista separada por comas ("1,2").
            $invoice_ids = json_decode($raw, true);
            if (!is_array($invoice_ids)) {
                $invoice_ids = array_filter(array_map('trim', explode(',', (string)$raw)));
            }

            if (empty($invoice_ids)) {
                json_output(['success' => false, 'message' => 'No se proporcionaron facturas']);
                return;
            }

            $result = $this->paymentRequestInvoicesModel->unauthorize_invoices($invoice_ids);
            json_output($result);
        } catch (Exception $e) {
            error_log("Error en unauthorize_invoices: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    public function generate_santander_layout()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $invoice_ids = $input['invoice_ids'] ?? [];
            $anticipo_ids = $input['anticipo_ids'] ?? [];


            if (empty($invoice_ids) && empty($anticipo_ids)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se proporcionaron facturas ni anticipos']);
                return;
            }
            $todos_los_datos = [];


            if (!empty($invoice_ids)) {
                $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_para_layout($invoice_ids);
                if ($facturas_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $facturas_data);
                }
            }
            // ✅ OBTENER ANTICIPOS si hay
            if (!empty($anticipo_ids)) {
                $anticipos_data = $this->PaymentRequestsModel->get_anticipos_para_layout($anticipo_ids);
                if ($anticipos_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $anticipos_data);
                }
            }

            if (empty($todos_los_datos)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se encontraron datos válidos para generar el layout']);
                return;
            }

            // ✅ VALIDACIONES
            $sin_cuenta_cargo = [];
            $sin_clabe = [];

            foreach ($todos_los_datos as $pago) {
                if (!$pago['cuenta_cargo_empresa'] || strlen($pago['cuenta_cargo_empresa']) != 11) {
                    $sin_cuenta_cargo[] = "Empresa: {$pago['empresa_nombre']} (emp_cod: {$pago['empresa_cod']})";
                }
                if (!$pago['clabe_beneficiario'] || strlen($pago['clabe_beneficiario']) != 18) {
                    $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                    $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                        ? "Anticipo #{$pago['payment_request_id']}"
                        : "Folio {$pago['folio']}";
                    $sin_clabe[] = "{$referencia} - {$pago['proveedor_nombre']}";
                }
            }

            if (!empty($sin_cuenta_cargo) || !empty($sin_clabe)) {
                $mensaje = '<strong>No se puede generar el layout:</strong><br><br>';
                if (!empty($sin_cuenta_cargo)) {
                    $mensaje .= '<strong class="text-danger">❌ Empresas sin cuenta Santander PROPIA:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_cargo)) . '<br><br>';
                }
                if (!empty($sin_clabe)) {
                    $mensaje .= '<strong class="text-warning">⚠️ Proveedores sin cuenta TERCERO:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_clabe)) . '<br><br>';
                }
                $mensaje .= '<small class="text-muted">Configure las cuentas faltantes en el catálogo de cuentas bancarias.</small>';

                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $mensaje]);
                return;
            }

            // ✅ GENERAR LAYOUT
            $layout_content = $this->generar_layout_santander_multi_empresa(
                $todos_los_datos,
                'SUSANA.PANTOJA@TOTALGAS.COM'
            );

            // ✅ GENERAR NOMBRE DE ARCHIVO
            $empresas_unicas = array_unique(array_column($todos_los_datos, 'empresa_nombre'));
            $empresa_label = count($empresas_unicas) === 1
                ? $empresas_unicas[0]
                : 'MULTI_EMPRESAS';

            $filename = 'LAYOUT_SANTANDER_' . str_replace(' ', '_', $empresa_label) . '_' . date('YmdHis') . '.txt';

            // ✅ ENVIAR ARCHIVO
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($layout_content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $layout_content;
            exit;
        } catch (Exception $e) {
            error_log('Error en generate_santander_layout: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error al generar layout: ' . $e->getMessage()]);
            exit;
        }
    }


    private function generar_layout_santander_multi_empresa($pagos, $email_notificacion)
    {
        $lineas = [];
        $consolidados = [];
        foreach ($pagos as $pago) {
            $key = $pago['cuenta_cargo_empresa'] . '|' . $pago['proveedor_codigo'];

            if (!isset($consolidados[$key])) {
                $consolidados[$key] = [
                    'cuenta_cargo' => $pago['cuenta_cargo_empresa'],
                    'clabe_beneficiario' => $pago['clabe_beneficiario'],
                    'titular_beneficiario' => $pago['titular_beneficiario'] ?: $pago['proveedor_nombre'],
                    'proveedor_nombre' => $pago['proveedor_nombre'],
                    'proveedor_codigo' => $pago['proveedor_codigo'],
                    'monto_total' => 0,
                    'referencias' => [],
                    'es_anticipo' => false
                ];
            }
            $consolidados[$key]['monto_total'] += floatval($pago['monto_autorizado']);
            // ✅ Manejar referencias según tipo
            if ($pago['tipo_pago'] === 'ANTICIPO') {
                $consolidados[$key]['referencias'][] = $pago['folio']; // "ANTICIPO #55"
                $consolidados[$key]['es_anticipo'] = true;
            } else {
                $consolidados[$key]['referencias'][] = $pago['invoice_number'] ?? $pago['folio'];
            }
        }
        // ✅ GENERAR LÍNEAS
        foreach ($consolidados as $grupo) {
            $codigo_banco = $this->obtener_codigo_banco_desde_clabe($grupo['clabe_beneficiario']);
            $monto_centavos = intval($grupo['monto_total'] * 100);
            // Importe Santander: 18 dígitos en centavos + plaza "901".
            $monto_con_plaza = str_pad($monto_centavos, 18, '0', STR_PAD_LEFT) . '901';
            $nombre_beneficiario = $this->limpiar_texto_layout($grupo['titular_beneficiario'], 40);

            // ✅ Concepto adaptado
            $cantidad_refs = count($grupo['referencias']);
            $primera_ref = $grupo['referencias'][0];

            if ($grupo['es_anticipo']) {
                // Para anticipos: "ANTICIPO #55 NOMBRE PROVEEDOR"
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else if ($cantidad_refs === 1) {
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else {
                $concepto_texto = 'C' . $primera_ref . ' ' . $grupo['proveedor_nombre'];
            }

            $concepto = $this->limpiar_texto_layout($concepto_texto, 40);

            // Layout posicional Santander. Anchos exactos (validados contra archivo del banco):
            //   nombre beneficiario 40, importe = 18 díg. centavos + "901", concepto 40,
            //   correo beneficiario 40. El nombre va pegado a "1234" y el concepto pegado a
            //   "00 00" (sin espacios intermedios) — cualquier corrimiento desfasa los campos
            //   siguientes y el banco rechaza con "VALOR INCORRECTO" / "CUENTA NO REGISTRADA".
            $linea = sprintf(
                "LTX05 %-11s       %-18s %-5s%-40s1234%s  %-40s00 00  %-40s",
                $grupo['cuenta_cargo'],
                $grupo['clabe_beneficiario'],
                $codigo_banco,
                $nombre_beneficiario,
                $monto_con_plaza,
                $concepto,
                substr($email_notificacion, 0, 40)
            );

            $lineas[] = $linea;
        }

        return implode("\r\n", $lineas);
    }


    private function obtener_codigo_banco_desde_clabe($clabe)
    {
        $codigo_banco_clabe = substr($clabe, 0, 3);

        $mapeo_bancos = [
            '002' => 'BANCO', // Banxico
            '006' => 'BCEXT', // Bancomext
            '009' => 'BOBRA', // Banobras
            '012' => 'BACOM', // BBVA México
            '014' => 'BANME', // Santander
            '019' => 'BEJER', // Banjercito
            '021' => 'BITAL', // HSBC
            '030' => 'BAJIO', // Bajío
            '036' => 'BINBU', // Inbursa
            '042' => 'MIFEL', // Banca Mifel
            '044' => 'COMER', // Scotia Bank
            '058' => 'BANRE', // Banregio
            '059' => 'BINVE', // Invex
            '060' => 'BANSI', // Bansi
            '062' => 'BAFIR', // Afirme
            '072' => 'BBANO', // Banorte
            '106' => 'BAMSA', // Bank of America
            '108' => 'MUFG',  // MUFG Bank
            '110' => 'CHASE', // JP Morgan
            '112' => 'CMCA',  // Bmonex
            '113' => 'DRESD', // Ve por Mas
            '124' => 'DEUTB', // CBM Banco
            '127' => 'BAZTE', // Azteca
            '128' => 'BAUTO', // Banco Autofin
            '129' => 'BARCL', // Barclays
            '130' => 'BCOMP', // Compartamos
            '132' => 'MULTI', // Multiva
            '133' => 'PRUDE', // Actinver
            '136' => 'REGIO', // Intercam
            '137' => 'COPEL', // Bancoppel
            '138' => 'AMIGO', // ABC Capital
            '140' => 'FACIL', // Consubanco
            '141' => 'VOLKS', // Volkswagen
            '143' => 'CONSU', // CI Banco
            '145' => 'BBASE', // Bbase
            '147' => 'AGROF', // Bankaool
            '148' => 'PTODO', // Pagatodo
            '150' => 'INMOB', // Inmobiliario
            '151' => 'DONDE', // Donde
            '152' => 'BCREA', // Bancrea
            '154' => 'COVAL', // Covalto
            '155' => 'ICBCH', // ICBC
            '156' => 'SABAD', // Sabadell
            '157' => 'SHINH', // Shinhan
            '158' => 'MISUO', // Mizuho
            '159' => 'BOCHI', // Bank of China
            '160' => 'BCOS3', // Banco S3
            '166' => 'BANSE', // Bansefi
            '168' => 'HIFED', // Hipotecaria Federal
        ];

        return $mapeo_bancos[$codigo_banco_clabe] ?? 'BACOM';
    }


    private function limpiar_texto_layout($texto, $max_length)
    {
        $texto = strtoupper($texto);
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $texto = preg_replace('/[^A-Z0-9 ]/', '', $texto);
        $texto = preg_replace('/\s+/', ' ', trim($texto));
        return substr($texto, 0, $max_length);
    }


    public function generate_banorte_layout()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $invoice_ids = $input['invoice_ids'] ?? [];
            $anticipo_ids = $input['anticipo_ids'] ?? [];

            if (empty($invoice_ids) && empty($anticipo_ids)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se proporcionaron facturas ni anticipos']);
                return;
            }

            $todos_los_datos = [];

            // ✅ Obtener facturas
            if (!empty($invoice_ids)) {
                $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_para_layout($invoice_ids);
                if ($facturas_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $facturas_data);
                }
            }

            // ✅ Obtener anticipos
            if (!empty($anticipo_ids)) {
                $anticipos_data = $this->PaymentRequestsModel->get_anticipos_para_layout($anticipo_ids);
                if ($anticipos_data) {
                    $todos_los_datos = array_merge($todos_los_datos, $anticipos_data);
                }
            }

            if (empty($todos_los_datos)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'No se encontraron datos válidos para generar el layout']);
                return;
            }

            // ✅ VALIDACIONES
            $sin_cuenta_cargo = [];
            $sin_cuenta_abono = [];


            foreach ($todos_los_datos as $pago) {
                // Validar cuenta cargo de la empresa: 10 dígitos, o CLABE Banorte
                // de 18 (072...) de la que se extrae la cuenta de 10
                $cuenta_cargo = $this->normalizar_cuenta_banorte($pago['cuenta_cargo_banorte']);
                if (!$cuenta_cargo || strlen($cuenta_cargo) != 10) {
                    $sin_cuenta_cargo[] = "Empresa: {$pago['empresa_nombre']} (emp_cod: {$pago['empresa_cod']})";
                }

                // Validar cuenta abono del proveedor (puede ser CLABE de 18 o cuenta de 10)
                if (!$pago['clabe_beneficiario']) {
                    $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                    $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                        ? "Anticipo #{$pago['payment_request_id']}"
                        : "Folio {$pago['folio']}";
                    $sin_cuenta_abono[] = "{$referencia} - {$pago['proveedor_nombre']}";
                } else {
                    // Si es CLABE de 18, validar que se pueda extraer cuenta de 10
                    $longitud = strlen($pago['clabe_beneficiario']);
                    if ($longitud != 10 && $longitud != 18) {
                        $tipo = $pago['tipo_pago'] ?? 'FACTURA';
                        $referencia = $pago['tipo_pago'] === 'ANTICIPO'
                            ? "Anticipo #{$pago['payment_request_id']}"
                            : "Folio {$pago['folio']}";
                        $sin_cuenta_abono[] = "{$referencia} - {$pago['proveedor_nombre']} (cuenta inválida: {$longitud} dígitos)";
                    }
                }
            }

            if (!empty($sin_cuenta_cargo) || !empty($sin_cuenta_abono)) {
                $mensaje = '<strong>No se puede generar el layout de Banorte:</strong><br><br>';
                if (!empty($sin_cuenta_cargo)) {
                    $mensaje .= '<strong class="text-danger">❌ Empresas sin cuenta Banorte PROPIA (10 dígitos):</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_cargo)) . '<br><br>';
                }
                if (!empty($sin_cuenta_abono)) {
                    $mensaje .= '<strong class="text-warning">⚠️ Proveedores sin cuenta válida:</strong><br>';
                    $mensaje .= implode('<br>', array_unique($sin_cuenta_abono)) . '<br><br>';
                }
                $mensaje .= '<small class="text-muted">Configure las cuentas faltantes en el catálogo de cuentas bancarias.</small>';

                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $mensaje]);
                return;
            }

            // ✅ GENERAR LAYOUT
            $layout_content = $this->generar_layout_banorte_multi_empresa($todos_los_datos);

            // ✅ GENERAR NOMBRE DE ARCHIVO
            $empresas_unicas = array_unique(array_column($todos_los_datos, 'empresa_nombre'));
            $empresa_label = count($empresas_unicas) === 1
                ? $empresas_unicas[0]
                : 'MULTI_EMPRESAS';

            $filename = 'LAYOUT_BANORTE_' . str_replace(' ', '_', $empresa_label) . '_' . date('YmdHis') . '.txt';

            // ✅ ENVIAR ARCHIVO
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($layout_content));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            echo $layout_content;
            exit;
        } catch (Exception $e) {
            error_log('Error en generate_banorte_layout: ' . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error al generar layout: ' . $e->getMessage()]);
            exit;
        }
    }


    /**
     * Normaliza una cuenta propia Banorte: si viene como CLABE de 18 (072...)
     * extrae la cuenta de 10 dígitos (posiciones 8-17, se omite el 0 inicial
     * de la cuenta de 11 dentro de la CLABE).
     */
    private function normalizar_cuenta_banorte($cuenta)
    {
        $cuenta = trim((string)$cuenta);
        if (strlen($cuenta) == 18 && substr($cuenta, 0, 3) === '072') {
            return substr($cuenta, 7, 10);
        }
        return $cuenta;
    }


    private function generar_layout_banorte_multi_empresa($pagos)
    {
        $lineas = [];
        $consolidados = [];

        // ✅ Consolidar por cuenta cargo + proveedor
        foreach ($pagos as $pago) {
            $key = $pago['cuenta_cargo_banorte'] . '|' . $pago['proveedor_codigo'];

            if (!isset($consolidados[$key])) {
                $consolidados[$key] = [
                    'cuenta_cargo' => $this->normalizar_cuenta_banorte($pago['cuenta_cargo_banorte']),
                    'cuenta_destino' => $pago['clabe_beneficiario'],
                    'clave_id' => $pago['clave_id_beneficiario'] ?? '',
                    'rfc' => $pago['proveedor_rfc'] ?? '',
                    'proveedor_nombre' => $pago['proveedor_nombre'],
                    'proveedor_codigo' => $pago['proveedor_codigo'],
                    'monto_total' => 0,
                    'referencias' => [],
                    'es_anticipo' => false
                ];
            }

            $consolidados[$key]['monto_total'] += floatval($pago['monto_autorizado']);

            // ✅ Manejar referencias según tipo
            if ($pago['tipo_pago'] === 'ANTICIPO') {
                $consolidados[$key]['referencias'][] = $pago['folio']; // "ANTICIPO #55"
                $consolidados[$key]['es_anticipo'] = true;
            } else {
                $consolidados[$key]['referencias'][] = $pago['invoice_number'] ?? $pago['folio'];
            }
        }

        // ✅ GENERAR LÍNEAS
        $fecha_operacion = date('dmY'); // Formato DDMMAAAA
        $referencia = date('Y');        // Referencia numérica (Tesorería usa el año)

        foreach ($consolidados as $grupo) {
            // Operación y cuenta destino según el tipo de cuenta del beneficiario:
            //   04 = interbancaria SPEI (CLABE completa de 18)
            //   02 = cuenta Banorte (10 dígitos; si viene CLABE 072, extraer cuenta)
            //   01 = solo traspasos entre cuentas propias (no aplica a proveedores)
            $destino = trim($grupo['cuenta_destino']);
            if (strlen($destino) == 18 && substr($destino, 0, 3) !== '072') {
                $operacion = '04';
                $cuenta_abono = $destino;
            } else {
                // Cuenta Banorte (10 dígitos directos o extraídos de CLABE 072)
                $operacion = '02';
                $cuenta_abono = $this->normalizar_cuenta_banorte($destino);
            }

            // ✅ Importe en PESOS con 2 decimales (Banorte NO usa centavos)
            $importe = number_format($grupo['monto_total'], 2, '.', '');

            // ✅ Concepto adaptado
            $cantidad_refs = count($grupo['referencias']);
            $primera_ref = $grupo['referencias'][0];

            if ($grupo['es_anticipo'] || $cantidad_refs === 1) {
                $concepto_texto = $primera_ref . ' ' . $grupo['proveedor_nombre'];
            } else {
                $concepto_texto = 'C' . $primera_ref . ' ' . $grupo['proveedor_nombre'];
            }

            $concepto = $this->limpiar_texto_layout($concepto_texto, 30);

            // Clave ID del beneficiario registrado en el portal Banorte (catálogo
            // CatalogosCuentasBancarias.IdBeneficiario); '0' o vacío = sin clave
            $clave_id = trim((string)$grupo['clave_id']);
            if ($clave_id === '0') {
                $clave_id = '';
            }

            $rfc = $this->limpiar_texto_layout((string)$grupo['rfc'], 13);

            // ✅ Formato Banorte pago a proveedores (11 campos separados por TAB):
            // OPERACION | CLAVE ID | CUENTA ORIGEN | CUENTA CLABE/DESTINO | IMPORTE
            // | REFERENCIA | DESCRIPCION | RFC | IVA | FECHA APLICACION | INSTRUCCION DE PAGO
            $linea = implode("\t", [
                $operacion,
                $clave_id,
                $grupo['cuenta_cargo'],
                $cuenta_abono,
                $importe,
                $referencia,
                $concepto,
                $rfc,
                '0',
                $fecha_operacion,
                $concepto
            ]);

            $lineas[] = $linea;
        }

        return implode("\r\n", $lineas);
    }


    public function get_pending_counts_all()
    {
        header('Content-Type: application/json');

        try {
            $userPermissions = $_SESSION['tg_user']['permissions'] ?? '';

            // Convertir a array si es string
            if (is_string($userPermissions)) {
                $userPermissions = explode(',', $userPermissions);
                $userPermissions = array_map('trim', $userPermissions);
                $userPermissions = array_map('intval', $userPermissions);
            }

            $countTesoreria = $this->PaymentRequestsModel->getPendingAuthorizationCount(68);
            echo json_encode([
                'success'          => true,
                'tesoreria'        => $countTesoreria,
                'user_permissions' => $userPermissions
            ]);
        } catch (Exception $e) {
            error_log("Error en getPendingCountsAll: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener contadores'
            ]);
        }
    }


    public function get_pending_bulk_authorization()
    {
        header('Content-Type: application/json');

        try {
            // Obtener el nivel solicitado desde la petición
            $permissionNumber = isset($_GET['permission']) ? intval($_GET['permission']) : null;

            if ($permissionNumber !== 68) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Nivel de autorización inválido'
                ]);
                return;
            }

            if (!authorized(68)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No tienes permisos para este nivel de autorización'
                ]);
                return;
            }

            $pagosPendientes = $this->PaymentRequestsModel->getPendingPaymentsForBulkAuthorization($permissionNumber);
            echo json_encode([
                'success' => true,
                'data' => $pagosPendientes ?: [],
                'nivel_autorizacion' => $this->getNombrePermiso($permissionNumber),
                'permission_number' => $permissionNumber
            ]);
        } catch (Exception $e) {
            error_log("Error en getPendingBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener pagos pendientes: ' . $e->getMessage()
            ]);
        }
    }


    public function process_bulk_authorization()
    {
        header('Content-Type: application/json');
        try {

            // Validar datos de entrada
            $paymentIds = $_POST['payment_ids'] ?? [];
            $permissionNumber = isset($_POST['permission_number']) ? intval($_POST['permission_number']) : null;
            $comentario = $_POST['comentario'] ?? '';

            if (empty($paymentIds) || !is_array($paymentIds)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se recibieron pagos para aprobar'
                ]);
                return;
            }

            if ($permissionNumber !== 68) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Nivel de autorización inválido'
                ]);
                return;
            }

            if (!authorized(68)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No tienes permisos para autorizar en este nivel'
                ]);
                return;
            }

            // Limpiar y validar IDs
            $paymentIds = array_map('intval', $paymentIds);
            $paymentIds = array_filter($paymentIds, function ($id) {
                return $id > 0;
            });

            if (empty($paymentIds)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'IDs de pago inválidos'
                ]);
                return;
            }

            $userId   = $_SESSION['tg_user']['Id'] ?? 0;
            $userName = $_SESSION['tg_user']['name'] ?? 'Unknown';

            // Procesar aprobación masiva
            $resultado = $this->PaymentRequestsModel->processBulkAuthorization(
                $paymentIds,
                $permissionNumber,
                $userId,
                $userName,
                $comentario
            );
            if ($resultado['success']) {
                // Enviar notificaciones si es necesario
                $this->enviarNotificacionesAprobacionMasiva(
                    $resultado['bulk_id'],
                    $paymentIds,
                    $permissionNumber
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Aprobación masiva completada exitosamente',
                    'resumen' => [
                        'aprobados' => $resultado['aprobados'],
                        'errores' => $resultado['errores'],
                        'monto_total' => number_format($resultado['monto_total'], 2),
                        'bulk_id' => $resultado['bulk_id']
                    ]
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => $resultado['message'],
                    'detalles' => $resultado['detalles'] ?? []
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en processBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al procesar aprobación masiva: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Obtener nombre legible del permiso
     */
    private function getNombrePermiso($permissionNumber)
    {
        return $permissionNumber === 68 ? 'Tesorería' : 'Desconocido';
    }


    /**
     * Enviar notificaciones de aprobación masiva
     */
    private function enviarNotificacionesAprobacionMasiva($bulkId, $paymentIds, $permissionNumber)
    {
        // Tesorería es el único y último nivel — no hay siguiente nivel al que notificar.
        $detalles = $this->PaymentRequestsModel->getBulkAuthorizationDetails($bulkId);
        error_log("Aprobación masiva registrada - ID: {$bulkId}, Usuario: " . ($detalles['user_name'] ?? 'N/A') . ", Pagos: " . count($paymentIds));
    }


    private function generar_html_notificacion_aprobacion_masiva(
        $bulk_id,
        $autorizado_por,
        $authorized_department,
        $next_department,
        $total_pagos,
        $monto_total,
        $pagos
    ) {
        $fecha = date('d/m/Y H:i:s');
        $monto_formatted = number_format($monto_total, 2, '.', ',');
        $url_lista = "http://totalgasonline.net:400/payment/payment_list";

        $filas_pagos = '';
        $monto_total_neto = 0;
        foreach ($pagos as $pago) {
            $monto_bruto   = floatval($pago['monto_total'] ?? 0);
            $notas_credito = floatval($pago['total_notas_credito'] ?? 0);
            $notas_cargo   = floatval($pago['total_notas_cargo'] ?? 0);
            $monto_neto    = $monto_bruto - $notas_credito + $notas_cargo;
            $monto_total_neto += $monto_neto;

            $monto_pago = number_format($monto_neto, 2, '.', ',');
            $proveedor  = htmlspecialchars($pago['proveedor_nombre'] ?? 'N/A');
            $empresa    = htmlspecialchars($pago['empresa_nombre'] ?? 'N/A');

            // Detalle de notas si aplica
            $notas_html = '';
            if ($notas_credito > 0 || $notas_cargo > 0) {
                $bruto_fmt = number_format($monto_bruto, 2, '.', ',');
                $notas_html .= "<br><small style='color:#999;'>Bruto: \${$bruto_fmt}";
                if ($notas_credito > 0) {
                    $nc_fmt = number_format($notas_credito, 2, '.', ',');
                    $notas_html .= " &minus; NC: \${$nc_fmt}";
                }
                if ($notas_cargo > 0) {
                    $nd_fmt = number_format($notas_cargo, 2, '.', ',');
                    $notas_html .= " + ND: \${$nd_fmt}";
                }
                $notas_html .= "</small>";
            }

            $filas_pagos .= "
                <tr>
                    <td style='padding:8px 10px; border-bottom:1px solid #eee; color:#333;'>#{$pago['id']}</td>
                    <td style='padding:8px 10px; border-bottom:1px solid #eee; color:#333;'>{$proveedor}</td>
                    <td style='padding:8px 10px; border-bottom:1px solid #eee; color:#333;'>{$empresa}</td>
                    <td style='padding:8px 10px; border-bottom:1px solid #eee; color:#333; text-align:center;'>{$pago['num_facturas']}</td>
                    <td style='padding:8px 10px; border-bottom:1px solid #eee; color:#28a745; font-weight:600; text-align:right;'>\${$monto_pago}{$notas_html}</td>
                </tr>";
        }
        // Reemplazar el monto_total recibido por el neto calculado desde los pagos
        $monto_total = $monto_total_neto;

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
                .container { max-width: 680px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; padding: 30px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .header p { margin: 5px 0 0 0; opacity: 0.9; }
                .content { padding: 30px; }
                .badge { display: inline-block; background: #17a2b8; color: white; padding: 5px 15px; border-radius: 20px; font-size: 14px; margin-bottom: 20px; }
                .alert-box { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 8px; margin: 20px 0; }
                .status-box { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin: 20px 0; }
                .status-label { font-weight: 600; color: #495057; margin-bottom: 8px; }
                .status-item { display: flex; align-items: center; padding: 6px 0; }
                .status-completed { color: #28a745; }
                .status-pending { color: #ffc107; }
                .total-box { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center; }
                .total-label { color: #666; font-size: 14px; margin-bottom: 5px; }
                .total-amount { color: #17a2b8; font-size: 32px; font-weight: bold; }
                .button { display: inline-block; background: #17a2b8; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: 600; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; font-size: 13px; }
                thead th { background: #17a2b8; color: white; padding: 10px; text-align: left; }
                thead th:last-child { text-align: right; }
                thead th:nth-child(4) { text-align: center; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏳ Autorización Masiva Requerida</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>

                <div class='content'>
                    <div class='badge'>🔔 Notificación - {$next_department}</div>

                    <div class='alert-box'>
                        <strong>{$autorizado_por}</strong> ({$authorized_department}) ha realizado una aprobación masiva de <strong>{$total_pagos} pago(s)</strong>.<br>
                        Ahora requieren tu autorización para continuar con el proceso.
                    </div>

                    <div class='total-box'>
                        <div class='total-label'>Monto Total Aprobado</div>
                        <div class='total-amount'>\${$monto_formatted}</div>
                    </div>

                    <div class='status-box'>
                        <div class='status-label'>📊 Estado de Autorizaciones:</div>
                        <div class='status-item status-completed'>
                            ✅ <span style='margin-left:8px;'><strong>{$authorized_department}:</strong> Autorizado</span>
                        </div>
                        <div class='status-item status-pending'>
                            ⏳ <span style='margin-left:8px;'><strong>{$next_department}:</strong> Pendiente (Tu autorización)</span>
                        </div>
                    </div>

                    <p style='font-weight:600; color:#333; margin-bottom:10px;'>📋 Pagos aprobados en esta autorización masiva:</p>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Proveedor</th>
                                <th>Empresa</th>
                                <th style='text-align:center;'>Facturas</th>
                                <th style='text-align:right;'>Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$filas_pagos}
                        </tbody>
                    </table>

                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_lista}' class='button'>
                            Ir a Autorizaciones Masivas →
                        </a>
                    </div>

                    <p style='color: #666; font-size: 14px; margin-top: 20px; text-align: center;'>
                        <strong>⚠️ Acción Requerida:</strong><br>
                        Estos pagos necesitan tu autorización para continuar con el flujo de aprobación.
                    </p>
                </div>

                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>© " . date('Y') . " TotalGas. Todos los derechos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }


    /**
     * Obtener historial de aprobaciones masivas
     */
    public function getBulkAuthorizationHistory()
    {
        header('Content-Type: application/json');

        try {
            $userId = $_SESSION['tg_user']['id'] ?? 0;
            $permissionNumber = isset($_GET['permission']) ? intval($_GET['permission']) : null;

            $whereClause = "ba.user_id = ?";
            $params = [$userId];

            if ($permissionNumber === 68) {
                $whereClause .= " AND ba.authorization_level = ?";
                $params[] = $permissionNumber;
            }

            $query = "
            SELECT
                ba.*,
                u.Nombre as user_name,
                CASE
                    WHEN ba.authorization_level = 68 THEN 'Tesorería'
                    ELSE 'Desconocido'
                END as nivel_nombre,
                DATEDIFF(minute, ba.created_at, GETDATE()) as minutos_desde_creacion,
                CASE 
                    WHEN ba.is_undone = 1 THEN 'Deshecha'
                    WHEN ba.processed_at IS NOT NULL THEN 'Completada'
                    ELSE 'En proceso'
                END as estado
            FROM [TG].[dbo].[payment_request_bulk_authorizations] ba
            LEFT JOIN [TG].[dbo].[Usuario] u ON ba.user_id = u.Id
            WHERE $whereClause
            ORDER BY ba.created_at DESC
        ";

            $paymentModel = new PaymentRequestsModel();
            $historial = $paymentModel->sql->select($query, $params);

            echo json_encode([
                'success' => true,
                'data' => $historial ?: []
            ]);
        } catch (Exception $e) {
            error_log("Error en getBulkAuthorizationHistory: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al obtener historial'
            ]);
        }
    }


    public function undoBulkAuthorization()
    {
        header('Content-Type: application/json');

        try {
            $bulkId = $_POST['bulk_id'] ?? 0;
            $userId = $_SESSION['tg_user']['id'] ?? 0;

            if (!$bulkId) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de aprobación masiva no válido'
                ]);
                return;
            }

            $paymentModel = new PaymentRequestsModel();
            $resultado = $paymentModel->undoBulkAuthorization($bulkId, $userId);

            echo json_encode($resultado);
        } catch (Exception $e) {
            error_log("Error en undoBulkAuthorization: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al deshacer aprobación masiva: ' . $e->getMessage()
            ]);
        }
    }


    public function add_invoice_to_payment()
    {
        header('Content-Type: application/json');

        try {
            $payment_id = $_POST['payment_id'] ?? 0;
            $document = $_POST['document'] ?? [];

            if (!$payment_id || empty($document)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Datos inválidos'
                ]);
                return;
            }

            // Verificar que el pago existe y no está pagado
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Pago no encontrado'
                ]);
                return;
            }

            if ($payment['status'] == PaymentRequestsModel::STATUS_PAID) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se pueden modificar pagos ya ejecutados'
                ]);
                return;
            }

            // Agregar la factura
            $result = $this->paymentRequestInvoicesModel->add_invoice_to_payment($payment_id, $document);

            if (!$result['success']) {
                echo json_encode($result);
                return;
            }

            $user_id = (int)($_SESSION['tg_user']['Id'] ?? 0);
            $user_name = $_SESSION['tg_user']['Nombre'] ?? null;
            $this->PaymentRequestAuditLogModel->log_add_invoice(
                $payment_id, $document, $result['invoice_id'] ?? null, $user_id, $user_name,
                $payment['accounting_group_id'] ?? null
            );

            // Recalcular total
            $this->PaymentRequestsModel->recalculate_payment_total($payment_id);

            // Reiniciar autorizaciones
            $this->PaymentRequestsModel->reset_authorizations($payment_id);

            echo json_encode([
                'success' => true,
                'message' => 'Factura agregada correctamente. Las autorizaciones han sido reiniciadas.'
            ]);
        } catch (Exception $e) {
            error_log("Error en add_invoice_to_payment: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Quitar factura de un pago existente
     */
    public function remove_invoice_from_payment()
    {
        header('Content-Type: application/json');

        try {
            $invoice_id = $_POST['invoice_id'] ?? 0;
            $payment_id = $_POST['payment_id'] ?? 0;

            if (!$invoice_id || !$payment_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Datos inválidos'
                ]);
                return;
            }

            // Verificar que el pago existe y no está pagado
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Pago no encontrado'
                ]);
                return;
            }

            if ($payment['status'] == PaymentRequestsModel::STATUS_PAID) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se pueden modificar pagos ya ejecutados'
                ]);
                return;
            }

            $user_id = (int)($_SESSION['tg_user']['Id'] ?? 0);
            $blockMessage = $this->assert_payment_not_grouped($payment, $user_id);
            if ($blockMessage !== null) {
                echo json_encode([
                    'success' => false,
                    'message' => $blockMessage
                ]);
                return;
            }

            // Quitar la factura (soft-delete)
            $result = $this->paymentRequestInvoicesModel->remove_invoice_from_payment($invoice_id, $user_id);

            if (!$result['success']) {
                echo json_encode($result);
                return;
            }

            $user_name = $_SESSION['tg_user']['Nombre'] ?? null;
            $this->PaymentRequestAuditLogModel->log_remove_invoice(
                $payment_id, $result['invoice_snapshot'] ?? [], $user_id, $user_name,
                $payment['accounting_group_id'] ?? null
            );

            // Recalcular total
            $this->PaymentRequestsModel->recalculate_payment_total($payment_id);

            // Reiniciar autorizaciones
            $this->PaymentRequestsModel->reset_authorizations($payment_id);

            echo json_encode([
                'success' => true,
                'message' => 'Factura eliminada correctamente. Las autorizaciones han sido reiniciadas.'
            ]);
        } catch (Exception $e) {
            error_log("Error en remove_invoice_from_payment: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }


    /**
     * Buscar facturas disponibles para agregar a un pago (no incluidas en otros pagos)
     */
    public function search_available_invoices()
    {
        header('Content-Type: application/json');

        try {
            $provider_cod = $_GET['provider_cod'] ?? null;
            $search = $_GET['search'] ?? '';

            if (!$provider_cod) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Proveedor no especificado'
                ]);
                return;
            }

            // Buscar facturas del proveedor que NO estén en payment_request_invoices
            $query = "
                SELECT TOP 50
                    dc.nro,
                    dc.Factura,
                    dc.codgas,
                    dc.total_fac,
                    dc.fechaVto,
                    dc.satuid,
                    g.abr as estacion_nombre,
                    dc.fechaRec
                FROM SG12.dbo.DocumentosC dc
                LEFT JOIN SG12.dbo.Gasolineras g ON dc.codgas = g.cod
                WHERE dc.codopr = ?
                AND dc.tip = 1
                AND dc.satuid IS NOT NULL
                AND dc.satuid NOT IN (
                    SELECT uuid
                    FROM TG.dbo.payment_request_invoices
                    WHERE uuid IS NOT NULL AND is_deleted = 0
                )
                AND (
                    dc.Factura LIKE ?
                    OR dc.nro LIKE ?
                    OR g.abr LIKE ?
                )
                ORDER BY dc.fechaRec DESC
            ";

            $search_param = '%' . $search . '%';
            $facturas = $this->documentosModel->sql->select($query, [
                $provider_cod,
                $search_param,
                $search_param,
                $search_param
            ]);

            echo json_encode([
                'success' => true,
                'data' => $facturas ?: []
            ]);
        } catch (Exception $e) {
            error_log("Error en search_available_invoices: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error al buscar facturas: ' . $e->getMessage()
            ]);
        }
    }


    // =====================================================================
    // ARCHIVOS DE CONTABILIDAD (PDP-38 / PDP-39)
    // =====================================================================

    /**
     * Renderiza el modal para crear un archivo de contabilidad.
     * Recibe opcionalmente provider_cod y emp_cod por POST para precargar la tabla.
     */
    public function modalCrearArchivoContabilidad()
    {
        try {
            $requisiciones  = $this->PaymentAccountingGroupsModel->get_ungrouped_abastos_approved();
            $next_id        = $this->PaymentAccountingGroupsModel->get_next_accounting_id();

            echo $this->twig->render(
                $this->route . 'modals/crear_archivo_contabilidad.html',
                compact('requisiciones', 'next_id')
            );
        } catch (Exception $e) {
            error_log('Error en modalCrearArchivoContabilidad: ' . $e->getMessage());
            echo '<div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Error al cargar el formulario: ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                  </div>';
        }
    }


    /**
     * POST: Crea un grupo de contabilidad con las requisiciones seleccionadas.
     */
    public function create_accounting_group()
    {
        header('Content-Type: application/json');
        try {
            $accounting_id = trim($_POST['accounting_id'] ?? '');
            $emp_cod       = trim($_POST['emp_cod']       ?? '');
            $emp_name      = trim($_POST['razon_social']  ?? ''); // nombre de la empresa
            $request_ids   = $_POST['request_ids']        ?? [];
            $user_id = $_SESSION['tg_user']['Id'] ?? 0;


            if (!$accounting_id) {
                echo json_encode(['success' => false, 'message' => 'El ID de contabilidad es requerido']);
                return;
            }
            if (empty($request_ids)) {
                echo json_encode(['success' => false, 'message' => 'Seleccione al menos una requisición']);
                return;
            }

            $result = $this->PaymentAccountingGroupsModel->create_group(
                $accounting_id,
                null, // provider_cod no se usa para agrupar, la agrupación es por empresa
                $emp_cod,
                $emp_name,
                (int)$user_id,
                $request_ids
            );

            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Error en create_accounting_group: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    /**
     * Agrupación automática de requisiciones por fecha.
     * Puede recibir ?date=YYYY-MM-DD (opcional, default = hoy).
     * Protegido por permiso 70 (Contabilidad) o token de cron.
     */
    public function auto_group_accounting()
    {
        header('Content-Type: application/json');
        try {
            $cronToken  = $_POST['cron_token'] ?? $_GET['cron_token'] ?? null;
            $validToken = defined('CRON_SECRET') ? CRON_SECRET : null;

            $isAuthorized = ($validToken && $cronToken === $validToken)
                || authorized(70);

            if (!$isAuthorized) {
                json_output(['success' => false, 'message' => 'No autorizado']);
                return;
            }

            $date    = $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d');
            $user_id = $_SESSION['tg_user']['Id'] ?? 0;

            $result = $this->PaymentAccountingGroupsModel->auto_group_by_date($date, $user_id);
            json_output($result);
        } catch (Exception $e) {
            error_log('Error en auto_group_accounting: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }


    /**
     * JSON para el DataTable del tab "Archivos Contabilidad".
     */
    public function get_accounting_groups_table()
    {
        header('Content-Type: application/json');
        try {
            $groups = $this->PaymentAccountingGroupsModel->get_all_groups();
            echo json_encode(['success' => true, 'data' => $groups]);
        } catch (Exception $e) {
            error_log('Error en get_accounting_groups_table: ' . $e->getMessage());
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }


    /**
     * Renderiza el template estático del modal de detalle de facturas del grupo.
     */
    public function modalDetalleFacturasGrupo()
    {
        echo $this->twig->render($this->route . 'modals/detalle_facturas_grupo.html', []);
    }


    /**
     * JSON: Facturas de todas las requisiciones de un grupo.
     */
    public function get_accounting_group_invoices()
    {
        header('Content-Type: application/json');
        try {
            $group_id = (int)($_POST['group_id'] ?? 0);
            if (!$group_id) {
                echo json_encode(['success' => false, 'message' => 'group_id requerido']);
                return;
            }
            $invoices = $this->PaymentAccountingGroupsModel->get_invoices_by_group($group_id);
            echo json_encode(['success' => true, 'data' => $invoices]);
        } catch (Exception $e) {
            error_log('Error en get_accounting_group_invoices: ' . $e->getMessage());
            echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
        }
    }


    /**
     * PDF: Comprobantes de compra de todas las facturas agrupadas.
     * Replica print_purchase_receipts4 usando los invoice_number del grupo.
     */
    public function print_accounting_group_receipts($group_id)
    {
        $group_id = (int)$group_id;
        if (!$group_id) {
            echo 'ID de grupo inválido';
            return;
        }

        $pairs = $this->PaymentAccountingGroupsModel->get_folio_codgas_pairs_by_group($group_id);

        if (empty($pairs)) {
            echo 'No se encontraron facturas para este grupo.';
            return;
        }

        $rows = $this->documentosModel->movement_analysis_by_nro_codgas($pairs);

        if (!$rows) {
            echo 'No se encontraron documentos en ControlGas para las facturas de este grupo.';
            return;
        }

        // --- 1. Generar comprobantes con FPDF normal ---
        $pdf = new PDF_Code128();
        $pdf->SetMargins(5, 5, 5);
        $pdf->SetAutoPageBreak(true, 12);

        foreach ($rows as $row) {
            $pdf->AddPage('P');
            $pdf->SetFont('Arial', 'B', 9);

            $pdf->Cell(200, 11.5, '', 0, 1, 'C');
            $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Empresa']), 0, 1, 'C');
            $pdf->Cell(200, 3.9, $row['Domicilio'], 0, 1, 'C');
            $pdf->Cell(200, 3.9, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Ciudad']), 0, 1, 'C');
            $pdf->Cell(200, 3.9, $row['RFC'], 0, 1, 'C');
            $pdf->Cell(200, 3.9, '', 0, 1, 'C');
            $pdf->Cell(200, 3.9, 'COMPROBANTE DE COMPRA', 0, 1, 'C');

            $pdf->SetFont('Arial', 'IB', 7);
            $pdf->Cell(200, 3, '', 0, 1, 'C');
            $pdf->Cell(23, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['Estación']), 0, 0, 'l');
            $pdf->Cell(5, 3.6, ':', 0, 0, 'C');
            $pdf->Cell(176, 3.6, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['DocDenominacion'] . ' (' . $row['nropcc'] . ')'), 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Documento ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['NroDocumento'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Fecha ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocFecha'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Turno ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['DocTurno'], 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Proveedor ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $row['Proveedor'], 0, 1, 'L');

            $factura = (!empty(trim($row['Factura']))) ? 'Factura ' . $row['Factura'] : '';
            $pdf->Cell(23, 3.6, 'Referencias ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, $factura . ' ' . iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $row['RemisionVehiculo']), 0, 1, 'L');
            $pdf->Cell(23, 3.6, 'Notas ', 0, 0, 'l'); $pdf->Cell(5, 3.6, ':', 0, 0, 'C'); $pdf->Cell(176, 3.6, '', 0, 1, 'L');

            $pdf->Cell(200, 3.5, '', 0, 1, 'C');
            $pdf->Cell(40, 3.5, 'Concepto', 'TB', 0, 'L'); $pdf->Cell(63, 3.5, 'Producto', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Cantidad', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, 'Precio', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, 'Importe', 'TB', 0, 'L'); $pdf->Cell(32, 3.5, 'Destino', 'TB', 1, 'L');
            $pdf->SetFont('Arial', '', 7);
            $subtotal = 0;
            $iva_concepto = 0;
            if ($conceptos = $this->documentosModel->get_concepts($row['codgas'], $row['Número'])) {
                foreach ($conceptos as $concepto) {
                    $subtotal += $concepto['Monto'];
                    if (str_contains($concepto['Concepto'], 'IVA')) {
                        $iva_concepto += $concepto['Monto'];
                    }
                    $pdf->Cell(40, 3.5, $concepto['Concepto'], 0, 0, 'L');
                    $pdf->Cell(63, 3.5, $concepto['Producto'], 0, 0, 'L');
                    $pdf->Cell(20, 3.5, number_format($concepto['Cantidad'] ?? 0, 3, '.', ','), 0, 0, 'L');
                    $pdf->Cell(20, 3.5, number_format($concepto['Precio'] ?? 0, 5, '.', ','), 0, 0, 'L');
                    $pdf->Cell(25, 3.5, number_format($concepto['Monto'] ?? 0, 2, '.', ','), 0, 0, 'L');
                    $pdf->Cell(32, 3.5, $concepto['Producto'], 0, 1, 'L');
                }
            }

            $pdf->SetFont('Arial', 'B', 7);
            $pdf->Cell(123, 3.5, 'SUBTOTAL', 'T', 0, 'L'); $pdf->Cell(20, 3.5, '', 'T', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['Importe'] + $row['Recargos']), 2, '.', ','), 'T', 0, 'L'); $pdf->Cell(32, 3.5, '', 'T', 1, 'L');
            $pdf->Cell(123, 3.5, 'I.V.A.', 'B', 0, 'L'); $pdf->Cell(20, 3.5, '', 'B', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($row['I.V.A.'] + $iva_concepto), 2, '.', ','), 'B', 0, 'L'); $pdf->Cell(32, 3.5, '', 'B', 1, 'L');
            $pdf->Cell(123, 3.5, 'TOTAL', 'TB', 0, 'L'); $pdf->Cell(20, 3.5, '', 'TB', 0, 'L'); $pdf->Cell(25, 3.5, number_format(($subtotal + $row['I.V.A.']), 2, '.', ','), 'TB', 0, 'L'); $pdf->Cell(32, 3.5, '', 'TB', 1, 'L');

            $pdf->Cell(200, 10, '', 0, 1, 'L');
            $pdf->Cell(33.3, 3.5, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Recepción'), 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Tanque', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Fecha', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Hora', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Volumen', 'TB', 0, 'L');
            $pdf->Cell(33.3, 3.5, 'Aplicado', 'TB', 1, 'L');
            if ($receptions = $this->documentosModel->get_receptions($row['codgas'], $row['Número'])) {
                $pdf->SetFont('Arial', '', 7);
                foreach ($receptions as $rec) {
                    $pdf->Cell(33.3, 3.5, $rec['nrotrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Tanque'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['Fecha'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, $rec['hratrn'], 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 0, 'L'); $pdf->Cell(33.3, 3.5, number_format($rec['VolumenRecibido'], 3, '.', ','), 'TB', 1, 'L');
                }
            }

            $pdf->SetFont('Arial', '', 7);
            $pdf->Cell(40, 10, 'Conformidad Registro', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, $row['LogRegistro'], 0, 1, 'L');
            $pdf->Cell(40, 10, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Conformidad Estación'), 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');
            $pdf->Cell(40, 10, 'Conformidad Transportista', 0, 0, 'L'); $pdf->Cell(5, 10, ':', 0, 0, 'C'); $pdf->Cell(159, 10, '', 0, 1, 'L');

            $currentY = $pdf->GetY();
            $pdf->SetY(-18);
            $pdf->SetFont('Arial', 'I', 7);
            $pdf->Cell(200, 1, '', 'B', 1, 'L');
            $pdf->SetY($currentY);
        }

        // Volcar comprobantes a string en memoria
        $comprobantesStr = $pdf->Output('S');

        // --- 2. Obtener rutas de facturas PDF del grupo ---
        $facturasPdf = $this->PaymentAccountingGroupsModel->get_invoice_pdf_paths_by_group($group_id);

        // Si no hay facturas PDF, entregar solo los comprobantes
        if (empty($facturasPdf)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="comprobantes_grupo_' . $group_id . '.pdf"');
            echo $comprobantesStr;
            return;
        }

        // --- 3. Combinar con FPDI ---
        $fpdi = new \setasign\Fpdi\Fpdi();
        $fpdi->SetAutoPageBreak(false);

        // Importar páginas de los comprobantes
        $totalComprobantes = $fpdi->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($comprobantesStr));
        for ($i = 1; $i <= $totalComprobantes; $i++) {
            $tpl  = $fpdi->importPage($i);
            $size = $fpdi->getTemplateSize($tpl);
            $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
        }

        // Importar cada factura PDF (si no se puede leer, se omite sin error fatal)
        $baseAllowed = realpath('C:\\Software\\TareasProgramadas\\Facturas_proveedores') ?: '';
        foreach ($facturasPdf as $ruta) {
            $ruta = str_replace(['/', '\\\\'], DIRECTORY_SEPARATOR, $ruta);
            $real = realpath($ruta);
            if (!$real || !is_readable($real)) {
                error_log("Factura PDF no accesible, omitida: $ruta");
                continue;
            }
            if ($baseAllowed !== '' && strpos($real, $baseAllowed) !== 0) {
                error_log("Factura PDF fuera de ruta permitida, omitida: $real");
                continue;
            }
            try {
                $totalPages = $fpdi->setSourceFile($real);
                for ($i = 1; $i <= $totalPages; $i++) {
                    $tpl  = $fpdi->importPage($i);
                    $size = $fpdi->getTemplateSize($tpl);
                    $fpdi->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $fpdi->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
                }
            } catch (\Exception $e) {
                error_log("Error importando factura PDF ($real): " . $e->getMessage());
            }
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="comprobantes_grupo_' . $group_id . '.pdf"');
        echo $fpdi->Output('S');
    }


    public function addCreditDebitNote()
    {
        try {
            // Validar datos requeridos
            $requiredFields = ['note_type', 'note_date', 'amount', 'provider_id'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("El campo {$field} es requerido");
                }
            }

            // Validar tipo de nota
            if (!in_array($_POST['note_type'], ['CREDIT', 'DEBIT'])) {
                throw new Exception('Tipo de nota inválido');
            }

            // PASO 1: Guardar la nota en BD (sin ligar a pago ni factura)
            $noteData = [
                'provider_id' => $_POST['provider_id'],
                'note_type' => $_POST['note_type'],
                'note_number' => $_POST['note_number'] ?? null,
                'note_date' => $_POST['note_date'],
                'amount' => $_POST['amount'],
                'description' => $_POST['description'],
                'reason_code' => $_POST['reason_code'] ?? null,
                'created_by' => $_SESSION['tg_user']['Id']
            ];

            $noteId = $this->InvoiceCreditDebitNotesModel->addCreditDebitNote($noteData);
            if (!$noteId) {
                throw new Exception('Error al guardar la nota en la base de datos');
            }

            $filePath = null;
            $docId = null;

            // PASO 2: Procesar archivo si existe
            if (isset($_FILES['note_file']) && $_FILES['note_file']['error'] === UPLOAD_ERR_OK) {
                
                // Validar tipo de archivo
                $fileType = mime_content_type($_FILES['note_file']['tmp_name']);
                if ($fileType !== 'application/pdf') {
                    throw new Exception('Solo se permiten archivos PDF');
                }

                // Validar tamaño (10MB máximo)
                if ($_FILES['note_file']['size'] > 10 * 1024 * 1024) {
                    throw new Exception('El archivo no debe exceder 10MB');
                }

                $extension = pathinfo($_FILES['note_file']['name'], PATHINFO_EXTENSION);

                // PASO 3: Crear registro del documento en BD
                $docData = [
                    'credit_note_id' => $noteId,
                    'file_extension' => $extension,
                    'created_by' => $_SESSION['tg_user']['Id']
                ];

                $docId = $this->InvoiceCreditDebitNotesDocModel->createDocumentRecord($docData);
                if (!$docId) {
                    throw new Exception('Error al crear registro del documento');
                }

                // PASO 4: Renombrar archivo con el ID del documento y subirlo
                $uploadDir = __DIR__ . '/../uploads/credit_debit_notes/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $newFilename = "{$docId}.{$extension}";
                $fullPath = $uploadDir . $newFilename;

                // Mover archivo
                if (!move_uploaded_file($_FILES['note_file']['tmp_name'], $fullPath)) {
                    throw new Exception('Error al guardar el archivo');
                }

                // Ruta relativa para guardar en BD
                $filePath = 'uploads/credit_debit_notes/' . $newFilename;

                // PASO 5: Actualizar ruta en el registro del documento
                if (!$this->InvoiceCreditDebitNotesDocModel->updateFilePath($docId, $filePath)) {
                    throw new Exception('Error al actualizar la ruta del archivo');
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Nota guardada correctamente',
                'note_id' => $noteId,
                'doc_id' => $docId,
                'has_file' => $filePath !== null
            ]);

        } catch (Exception $e) {
            // Cleanup en caso de error
            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    /** RFC del emisor MGC (única empresa habilitada en esta fase). */
    private const NOTAS_RFC_MGC = 'MME141110IJ9';

    /**
     * Carga masiva de notas de crédito — PREVIEW (no persiste).
     * Recibe varios PDFs ($_FILES['notas']), los lee con NotaCreditoPdfParser,
     * resuelve el proveedor por RFC emisor, detecta duplicados, y devuelve la
     * tabla de revisión. Solo MGC en esta fase.
     */
    public function preview_notas_credito()
    {
        header('Content-Type: application/json');
        try {
            if (!authorized(66)) {
                json_output(['success' => false, 'message' => 'No tienes permiso para cargar notas']);
                return;
            }
            if (empty($_FILES['notas']) || !is_array($_FILES['notas']['name'])) {
                $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
                // Si se excede max_file_uploads, PHP descarta $_FILES y deja el warning.
                json_output(['success' => false, 'message' => "No se recibieron PDFs (¿superaste el máximo de {$maxUploads} archivos por carga? Súbelos en grupos más pequeños)"]);
                return;
            }

            $files = $_FILES['notas'];
            $total = count($files['name']);

            $maxUploads = (int) ini_get('max_file_uploads') ?: 20;
            if ($total > $maxUploads) {
                json_output(['success' => false, 'message' => "Enviaste {$total} archivos; el máximo por carga es {$maxUploads}. Súbelos en grupos."]);
                return;
            }
            $notas = [];
            $resumen = ['ok' => 0, 'duplicado' => 0, 'error' => 0, 'total' => 0, 'monto_total' => 0.0];

            for ($i = 0; $i < $total; $i++) {
                $nombre = $files['name'][$i];

                if ($files['error'][$i] !== UPLOAD_ERR_OK || strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                    $notas[] = ['archivo' => $nombre, 'estado' => 'error', 'mensaje' => 'Archivo inválido', 'raw_ok' => false];
                    $resumen['error']++; $resumen['total']++;
                    continue;
                }

                $d = NotaCreditoPdfParser::parse($files['tmp_name'][$i], $nombre);

                if (!$d['raw_ok']) {
                    $d['estado'] = 'error';
                    $d['mensaje'] = $d['error'] ?? 'No se pudo leer la nota';
                    $notas[] = $d; $resumen['error']++; $resumen['total']++;
                    continue;
                }

                // Solo MGC en esta fase
                if ($d['rfc_emisor'] !== self::NOTAS_RFC_MGC) {
                    $d['estado'] = 'error';
                    $d['mensaje'] = 'Emisor no habilitado (solo MGC en esta fase): ' . $d['rfc_emisor'];
                    $notas[] = $d; $resumen['error']++; $resumen['total']++;
                    continue;
                }

                $prov = $this->InvoiceCreditDebitNotesModel->getProviderByRfc($d['rfc_emisor']);
                if (!$prov) {
                    $d['estado'] = 'error';
                    $d['mensaje'] = 'Proveedor no encontrado para RFC ' . $d['rfc_emisor'];
                    $notas[] = $d; $resumen['error']++; $resumen['total']++;
                    continue;
                }
                $d['provider_id']   = $prov['cod'];
                $d['provider_name'] = $prov['den'];

                if ($this->InvoiceCreditDebitNotesModel->existsNote($prov['cod'], $d['note_number'])) {
                    $d['estado'] = 'duplicado';
                    $d['mensaje'] = 'Ya registrada en el catálogo';
                    $notas[] = $d; $resumen['duplicado']++; $resumen['total']++;
                    continue;
                }

                $d['estado'] = 'ok';
                $d['mensaje'] = 'Lista para guardar';
                $notas[] = $d;
                $resumen['ok']++; $resumen['total']++;
                $resumen['monto_total'] += (float)$d['total'];
            }

            json_output(['success' => true, 'resumen' => $resumen, 'notas' => $notas]);
        } catch (Exception $e) {
            error_log('Error en preview_notas_credito: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al procesar notas: ' . $e->getMessage()]);
        }
    }

    /**
     * Guarda las notas seleccionadas (reenviadas) + su PDF. Tolerante a fallo parcial.
     */
    public function guardar_notas_credito()
    {
        header('Content-Type: application/json');
        try {
            if (!authorized(66)) {
                json_output(['success' => false, 'message' => 'No tienes permiso para guardar notas']);
                return;
            }
            $user_id = $_SESSION['tg_user']['Id'] ?? null;
            $items = json_decode($_POST['notas'] ?? '[]', true);
            if (!is_array($items) || empty($items)) {
                json_output(['success' => false, 'message' => 'No se recibieron notas para guardar']);
                return;
            }

            $resultados = [];
            $guardadas = 0;

            foreach ($items as $it) {
                $archivo_idx = isset($it['archivo_idx']) ? (int)$it['archivo_idx'] : -1;
                $nombre = $it['archivo'] ?? "nota #{$archivo_idx}";
                try {
                    if (empty($it['provider_id']) || empty($it['note_number']) || empty($it['total'])) {
                        throw new Exception('Datos incompletos');
                    }
                    // Revalidar duplicado por si se cargó dos veces en la misma tanda
                    if ($this->InvoiceCreditDebitNotesModel->existsNote($it['provider_id'], $it['note_number'])) {
                        throw new Exception('Ya existe (duplicado)');
                    }

                    $noteId = $this->InvoiceCreditDebitNotesModel->addCreditDebitNote([
                        'provider_id' => (int)$it['provider_id'],
                        'note_type'   => $it['note_type'] ?? 'CREDIT',
                        'note_number' => $it['note_number'],
                        'note_date'   => $it['fecha'] ?? date('Y-m-d'),
                        'amount'      => (float)$it['total'],
                        'description' => 'Carga masiva - UUID ' . ($it['uuid'] ?? ''),
                        'reason_code' => null,
                        'created_by'  => $user_id,
                    ]);
                    if (!$noteId) {
                        throw new Exception('No se pudo guardar la nota');
                    }

                    // Guardar el PDF reenviado
                    $docMsg = '';
                    if ($archivo_idx >= 0 && isset($_FILES['notas']['name'][$archivo_idx])
                        && $_FILES['notas']['error'][$archivo_idx] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['notas']['name'][$archivo_idx], PATHINFO_EXTENSION) ?: 'pdf';
                        $docId = $this->InvoiceCreditDebitNotesDocModel->createDocumentRecord([
                            'credit_note_id' => $noteId,
                            'file_extension' => $ext,
                            'created_by'     => $user_id,
                        ]);
                        if ($docId) {
                            $uploadDir = __DIR__ . '/../uploads/credit_debit_notes/';
                            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                            $fullPath = $uploadDir . "{$docId}.{$ext}";
                            if (move_uploaded_file($_FILES['notas']['tmp_name'][$archivo_idx], $fullPath)) {
                                $this->InvoiceCreditDebitNotesDocModel->updateFilePath($docId, 'uploads/credit_debit_notes/' . "{$docId}.{$ext}");
                                $docMsg = ' PDF guardado.';
                            } else {
                                $docMsg = ' (PDF no se pudo guardar)';
                            }
                        }
                    }

                    $guardadas++;
                    $resultados[] = ['archivo' => $nombre, 'success' => true, 'message' => "Nota {$it['note_number']} guardada." . $docMsg];
                } catch (Exception $e) {
                    $resultados[] = ['archivo' => $nombre, 'success' => false, 'message' => $e->getMessage()];
                }
            }

            json_output(['success' => $guardadas > 0, 'guardadas' => $guardadas, 'total' => count($items), 'resultados' => $resultados]);
        } catch (Exception $e) {
            error_log('Error en guardar_notas_credito: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al guardar: ' . $e->getMessage()]);
        }
    }


    /**
     * Servir el PDF de un documento de nota de crédito/cargo
     */
    public function viewNoteDocument($docId)
    {
        $doc = $this->InvoiceCreditDebitNotesDocModel->getDocumentById((int)$docId);

        if (!$doc) {
            http_response_code(404);
            echo "Documento no encontrado";
            exit;
        }

        if (empty($doc['file_path'])) {
            http_response_code(404);
            echo "Este documento no tiene archivo";
            exit;
        }

        $fullPath = realpath(__DIR__ . '/../' . $doc['file_path']);
        $baseAllowed = realpath(__DIR__ . '/../uploads/credit_debit_notes');

        if ($fullPath === false || $baseAllowed === false || strpos($fullPath, $baseAllowed) !== 0) {
            http_response_code(403);
            echo "Acceso denegado";
            exit;
        }

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            http_response_code(404);
            echo "Archivo no encontrado";
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }


    /**
     * Sirve el PDF del primer documento de una nota de cargo dado su nota_id.
     * Usado desde el child row de payment_list para filas is_debit_note=1.
     */
    public function view_note_doc($nota_id)
    {
        $nota_id = (int)$nota_id;
        if (!$nota_id) { http_response_code(400); echo "nota_id requerido"; exit; }

        $docs = $this->InvoiceCreditDebitNotesDocModel->getDocumentsByNoteId($nota_id);
        if (empty($docs) || empty($docs[0]['file_path'])) {
            http_response_code(404); echo "Sin documento"; exit;
        }

        $doc      = $docs[0];
        $fullPath = realpath(__DIR__ . '/../' . $doc['file_path']);
        $base     = realpath(__DIR__ . '/../uploads/credit_debit_notes');

        if ($fullPath === false || $base === false || strpos($fullPath, $base) !== 0) {
            http_response_code(403); echo "Acceso denegado"; exit;
        }
        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            http_response_code(404); echo "Archivo no encontrado"; exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }


    /**
     * Devuelve la lista de documentos (PDFs) de una nota de crédito/cargo
     */
    public function getNoteDocuments()
    {
        try {
            $noteId = (int) ($_POST['note_id'] ?? 0);
            if (!$noteId) throw new Exception('note_id requerido');

            $docs = $this->InvoiceCreditDebitNotesDocModel->getDocumentsByNoteId($noteId);

            echo json_encode([
                'success' => true,
                'docs'    => $docs ?: []
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Subir archivo a una nota de crédito/cargo existente
     */
    public function uploadNoteFile()
    {
        try {
            if (empty($_POST['note_id'])) {
                throw new Exception('El campo note_id es requerido');
            }

            $noteId = (int) $_POST['note_id'];

            // Verificar que la nota existe
            $note = $this->InvoiceCreditDebitNotesModel->getNoteById($noteId);
            if (!$note) {
                throw new Exception('Nota no encontrada');
            }

            if (!isset($_FILES['note_file']) || $_FILES['note_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibió ningún archivo o ocurrió un error al subirlo');
            }

            // Validar tipo de archivo
            $fileType = mime_content_type($_FILES['note_file']['tmp_name']);
            if ($fileType !== 'application/pdf') {
                throw new Exception('Solo se permiten archivos PDF');
            }

            // Validar tamaño (10MB máximo)
            if ($_FILES['note_file']['size'] > 10 * 1024 * 1024) {
                throw new Exception('El archivo no debe exceder 10MB');
            }

            $extension = pathinfo($_FILES['note_file']['name'], PATHINFO_EXTENSION);

            // Crear registro del documento en BD
            $docData = [
                'credit_note_id' => $noteId,
                'file_extension' => $extension,
                'created_by' => $_SESSION['tg_user']['Id']
            ];

            $docId = $this->InvoiceCreditDebitNotesDocModel->createDocumentRecord($docData);
            if (!$docId) {
                throw new Exception('Error al crear registro del documento');
            }

            // Subir el archivo
            $uploadDir = __DIR__ . '/../uploads/credit_debit_notes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $newFilename = "{$docId}.{$extension}";
            $fullPath = $uploadDir . $newFilename;

            if (!move_uploaded_file($_FILES['note_file']['tmp_name'], $fullPath)) {
                throw new Exception('Error al guardar el archivo');
            }

            // Actualizar ruta en el registro del documento
            $filePath = 'uploads/credit_debit_notes/' . $newFilename;
            if (!$this->InvoiceCreditDebitNotesDocModel->updateFilePath($docId, $filePath)) {
                throw new Exception('Error al actualizar la ruta del archivo');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Archivo subido correctamente',
                'doc_id' => $docId
            ]);

        } catch (Exception $e) {
            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    /**
     * Pantalla independiente de gestión de notas de crédito/cargo
     */
    public function credit_notes()
    {
        $proveedores = $this->proveedores->get_actives();
        // Límite de archivos por carga (lo impone PHP: max_file_uploads)
        $max_uploads = (int) ini_get('max_file_uploads') ?: 20;
        echo $this->twig->render($this->route . 'credit_notes.html', compact('proveedores', 'max_uploads'));
    }


    public function import_invoices()
    {
        echo $this->twig->render($this->route . 'import_invoices.html');
    }


    public function import_invoice_pdf()
    {
        header('Content-Type: application/json');

        try {
            $rut = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments\aemsa\procesadas';
            $existe = is_dir($rut);
            // 1. Validaciones iniciales
            $this->validate_pdf_request();
            $proveedor = $this->get_validated_provider();
            $forzar = !empty($_POST['forzar']) && $_POST['forzar'] === '1';

            // 2. Comunicación con la API (Lógica delegada)
            $apiResponse = $this->call_pdf_import_api($_FILES['pdf'], $proveedor, $forzar);

            // 3. Gestión de archivos local (Lógica delegada)
            $fileData = $this->save_imported_pdf($apiResponse, $proveedor, $_FILES['pdf']['tmp_name']);

            // 4. Actualizar base de datos
            $this->facturasRecibidasModel->update_ruta(
                $apiResponse['factura_id'], 
                $fileData['ruta_completa'], 
                $fileData['nombre_archivo']
            );

            // 5. Respuesta Exitosa
            echo json_encode([
                'success'    => true,
                'factura_id' => $apiResponse['factura_id'],
                'uuid'       => $apiResponse['uuid'],
                'ruta'       => $fileData['ruta_completa'],
                'mensaje'    => $apiResponse['mensaje'] ?? 'Importación exitosa',
                'debug'      => $fileData['debug']
            ]);

        } catch (Exception $e) {
            http_response_code($e->getCode() === 400 ? 400 : 500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'estado'  => $e->getCode() === 400 ? 'validacion' : 'error'
            ]);
        }
    }

    private function validate_pdf_request()
    {
        if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No se recibió el archivo PDF correctamente.', 400);
        }
    }

    /**
     * Carga masiva de comprobantes de pago — PREVIEW (no persiste nada).
     * Recibe varios PDFs ($_FILES['comprobantes']), los parsea y devuelve la
     * relación propuesta de cada comprobante con un grupo de facturas autorizadas
     * pendientes (empresa+proveedor). El usuario revisa/corrige antes de guardar
     * (la persistencia es una fase posterior).
     */
    public function preview_comprobantes_match()
    {
        header('Content-Type: application/json');

        try {
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede conciliar comprobantes']);
                return;
            }

            if (empty($_FILES['comprobantes']) || !is_array($_FILES['comprobantes']['name'])) {
                json_output(['success' => false, 'message' => 'No se recibieron comprobantes PDF']);
                return;
            }

            $files = $_FILES['comprobantes'];
            $total = count($files['name']);
            $comprobantes = [];

            for ($i = 0; $i < $total; $i++) {
                $nombre = $files['name'][$i];

                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    $comprobantes[] = [
                        'archivo' => $nombre,
                        'banco' => 'Desconocido', 'rfc_ordenante' => '', 'nombre_ordenante' => '',
                        'rfc_beneficiario' => '', 'nombre_beneficiario' => '', 'cuenta_cargo' => '',
                        'cuenta_abono' => '', 'importe' => 0.0, 'referencia' => '', 'fecha' => '',
                        'raw_ok' => false, 'error' => 'Error al recibir el archivo',
                    ];
                    continue;
                }

                if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'pdf') {
                    $comprobantes[] = [
                        'archivo' => $nombre,
                        'banco' => 'Desconocido', 'rfc_ordenante' => '', 'nombre_ordenante' => '',
                        'rfc_beneficiario' => '', 'nombre_beneficiario' => '', 'cuenta_cargo' => '',
                        'cuenta_abono' => '', 'importe' => 0.0, 'referencia' => '', 'fecha' => '',
                        'raw_ok' => false, 'error' => 'Solo se permiten archivos PDF',
                    ];
                    continue;
                }

                // Parsear desde tmp_name (no se guarda en disco).
                $comprobantes[] = ComprobantePagoParser::parse($files['tmp_name'][$i], $nombre);
            }

            $match = $this->paymentRequestInvoicesModel->match_comprobantes_con_grupos($comprobantes);

            // Resumen para el front
            $resumen = ['matched' => 0, 'ambiguo' => 0, 'unmatched' => 0, 'total' => 0, 'monto_total' => 0.0];
            foreach ($match['comprobantes'] as $r) {
                $resumen[$r['estado']]++;
                $resumen['total']++;
                $resumen['monto_total'] += (float)($r['comprobante']['importe'] ?? 0);
            }

            json_output([
                'success'      => true,
                'resumen'      => $resumen,
                'grupos'       => $match['grupos'],        // para el dropdown de reasignación manual
                'comprobantes' => $match['comprobantes'],
            ]);
        } catch (Exception $e) {
            error_log('Error en preview_comprobantes_match: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al procesar comprobantes: ' . $e->getMessage()]);
        }
    }

    /**
     * Conciliación: marca como pagado cada grupo seleccionado y guarda su PDF.
     *
     * El front reenvía los PDFs en $_FILES['comprobantes'] (necesario porque
     * PaymentTransactionDocumentsModel::upload usa move_uploaded_file, que exige
     * un archivo recibido por HTTP en esta misma petición) + un JSON 'asignaciones'
     * que mapea cada archivo (por índice) a su grupo y datos de pago.
     *
     * Cada comprobante se procesa de forma independiente (fallo parcial seguro):
     * ejecuta el pago de las facturas del grupo, marca la requisición PAGADO si
     * quedó saldada, y adjunta el PDF a la transacción generada.
     */
    public function conciliar_comprobantes()
    {
        header('Content-Type: application/json');

        try {
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede conciliar pagos']);
                return;
            }

            $user_id = $_SESSION['tg_user']['Id'] ?? null;
            if (!$user_id) {
                json_output(['success' => false, 'message' => 'Usuario no identificado']);
                return;
            }

            $asignaciones = json_decode($_POST['asignaciones'] ?? '[]', true);
            if (!is_array($asignaciones) || empty($asignaciones)) {
                json_output(['success' => false, 'message' => 'No se recibieron asignaciones']);
                return;
            }

            $resultados = [];
            $aplicados = 0;
            $total_aplicado = 0.0;

            foreach ($asignaciones as $a) {
                $archivo_idx = isset($a['archivo_idx']) ? (int)$a['archivo_idx'] : -1;
                $invoice_ids = array_values(array_filter(array_map('intval', $a['invoice_ids'] ?? [])));
                $fecha_pago  = $a['fecha_pago'] ?? null;
                $referencia  = trim($a['referencia'] ?? '');
                $observaciones = trim($a['observaciones'] ?? '');
                $nombre_archivo = $a['archivo'] ?? "comprobante #{$archivo_idx}";

                if (empty($invoice_ids)) {
                    $resultados[] = ['archivo' => $nombre_archivo, 'success' => false, 'message' => 'Sin facturas asignadas'];
                    continue;
                }
                if (!$fecha_pago || $referencia === '') {
                    $resultados[] = ['archivo' => $nombre_archivo, 'success' => false, 'message' => 'Falta fecha o referencia bancaria'];
                    continue;
                }

                try {
                    // Traer datos de las facturas autorizadas y armar el arreglo de pago
                    $facturas_data = $this->paymentRequestInvoicesModel->get_facturas_autorizadas_by_ids($invoice_ids);
                    if (!$facturas_data) {
                        $resultados[] = ['archivo' => $nombre_archivo, 'success' => false, 'message' => 'Facturas no encontradas o no autorizadas'];
                        continue;
                    }

                    $facturas_procesar = [];
                    $monto_total = 0.0;
                    foreach ($facturas_data as $f) {
                        if ($f['payment_authorized'] != 1) {
                            throw new Exception("La factura {$f['folio']} no está autorizada");
                        }
                        $facturas_procesar[] = [
                            'invoice_id'         => $f['id'],
                            'folio'              => $f['folio'],
                            'monto_pagar'        => $f['authorized_amount'],
                            'saldo_anterior'     => $f['saldo'],
                            'payment_request_id' => $f['payment_request_id'],
                        ];
                        $monto_total += (float)$f['authorized_amount'];
                    }

                    // Derivar empresa/proveedor/banco de la requisición para la cabecera del lote
                    $primer_prid = $facturas_data[0]['payment_request_id'];
                    $req = $this->PaymentRequestsModel->get_request_by_id($primer_prid);
                    $emp_cod = $req['emp_cod'] ?? null;
                    $provider_cod = $req['provider_cod'] ?? null;

                    // Preparar el item de $_FILES del comprobante reenviado
                    $fileItem = null;
                    if ($archivo_idx >= 0 && isset($_FILES['comprobantes']['name'][$archivo_idx])) {
                        $fileItem = [
                            'name'     => $_FILES['comprobantes']['name'][$archivo_idx],
                            'type'     => $_FILES['comprobantes']['type'][$archivo_idx],
                            'tmp_name' => $_FILES['comprobantes']['tmp_name'][$archivo_idx],
                            'error'    => $_FILES['comprobantes']['error'][$archivo_idx],
                            'size'     => $_FILES['comprobantes']['size'][$archivo_idx],
                        ];
                    }

                    // Crear lote + ejecutar pago + marcar pagado + adjuntar comprobante al lote
                    $result = $this->registrar_lote_y_pago(
                        $facturas_procesar,
                        [
                            'fecha_pago'    => $fecha_pago,
                            'referencia'    => $referencia,
                            'observaciones' => $observaciones,
                            'emp_cod'       => $emp_cod,
                            'provider_cod'  => $provider_cod,
                            'monto_total'   => $monto_total,
                        ],
                        $fileItem,
                        $user_id
                    );

                    if (!$result['success']) {
                        $resultados[] = ['archivo' => $nombre_archivo, 'success' => false, 'message' => $result['message']];
                        continue;
                    }

                    $aplicados++;
                    $total_aplicado += (float)$result['total_pagado'];
                    $resultados[] = [
                        'archivo'    => $nombre_archivo,
                        'success'    => true,
                        'message'    => $result['message'],
                        'total'      => $result['total_pagado'],
                    ];
                } catch (Exception $e) {
                    error_log("conciliar_comprobantes [$nombre_archivo]: " . $e->getMessage());
                    $resultados[] = ['archivo' => $nombre_archivo, 'success' => false, 'message' => $e->getMessage()];
                }
            }

            json_output([
                'success'        => $aplicados > 0,
                'aplicados'      => $aplicados,
                'total'          => count($asignaciones),
                'total_aplicado' => number_format($total_aplicado, 2, '.', ''),
                'resultados'     => $resultados,
            ]);
        } catch (Exception $e) {
            error_log('Error en conciliar_comprobantes: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error al conciliar: ' . $e->getMessage()]);
        }
    }

    private function get_validated_provider(): string
    {
        $proveedor = strtolower(trim($_POST['proveedor'] ?? ''));
        if (!in_array($proveedor, self::ALLOWED_PROVIDERS)) {
            throw new Exception('Proveedor no autorizado.', 400);
        }
        return $proveedor;
    }

    private function call_pdf_import_api($pdfFile, $proveedor, bool $forzar = false): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => self::PDF_IMPORT_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [
                'pdf'       => new CURLFile($pdfFile['tmp_name'], 'application/pdf', $pdfFile['name']),
                'proveedor' => $proveedor,
                'forzar'    => $forzar ? '1' : '0',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $responseRaw = curl_exec($curl);
        $curlError   = curl_error($curl);
        curl_close($curl);

        if ($curlError) {
            throw new Exception("Error de conexión con la API: $curlError");
        }

        $response = json_decode($responseRaw, true);
        if (!$response || ($response['estado'] ?? '') !== 'exitosa') {
            throw new Exception($response['mensaje'] ?? 'Respuesta inválida de la API.');
        }

        return $response;
    }

    /**
     * Gestiona el guardado físico del archivo PDF en el servidor local.
     * 
     * @param array  $apiData   Datos recibidos de la API (contiene el UUID).
     * @param string $proveedor Nombre del proveedor (para la estructura de carpetas).
     * @param string $tmpFile   Ruta temporal del archivo subido ($_FILES['pdf']['tmp_name']).
     * @return array            Datos del archivo guardado (ruta y nombre).
     * @throws Exception        Si no se puede crear el directorio o copiar el archivo.
     */
    private function save_imported_pdf(array $apiData, string $proveedor, string $tmpFile): array
    {
        // 1. Obtener el identificador único (UUID) generado por la API
        $uuid = $apiData['uuid'] ?? '';

        // 2. Construir la ruta del directorio: Base + Proveedor + "procesadas"
        $rutaDir = self::BASE_ATTACHMENTS_PATH . '\\' . $proveedor . '\\procesadas';
        // 3. Generar el nombre del archivo final (UUID en mayúsculas y sin guiones medios)
        $nombreArchivo = strtoupper(str_replace('-', '_', $uuid)) . '.pdf';

        // 4. Ruta completa final (Directorio + Nombre)
        $rutaCompleta = $rutaDir . '\\' . $nombreArchivo;

        // 5. Verificar si el directorio existe. Si no, intenta crearlo de forma recursiva.
        if (!is_dir($rutaDir)) {
            if (!mkdir($rutaDir, 0777, true)) {
                throw new Exception("No se pudo crear el directorio: $rutaDir");
            }
        }

        if (!file_exists($tmpFile)) {
            throw new Exception("El archivo temporal no existe: $tmpFile");
        }

        if (!is_writable($rutaDir)) {
            throw new Exception("El directorio no tiene permisos de escritura: $rutaDir");
        }
        // 6. Mover el archivo desde la carpeta temporal de PHP a la ruta final
        if (!move_uploaded_file($tmpFile, $rutaCompleta)) {
            throw new Exception("Error al guardar el archivo PDF en el servidor local.");
        }

        // 7. Retornar información necesaria para actualizar la Base de Datos
        return [
            'ruta_completa'  => $rutaCompleta,
            'nombre_archivo' => $nombreArchivo,
            'debug'          => [
                'dir_exists'   => true,
                'rutaDir'      => $rutaDir,
                'rutaCompleta' => $rutaCompleta
            ]
        ];
    }


    public function delete_payment()
    {
        header('Content-Type: application/json');
        if (!in_array(69, explode(',', $_SESSION['tg_user']['permissions']))) {
            echo json_encode(['success' => false, 'message' => 'Sin permiso para eliminar pagos.']);
            return;
        }
        $payment_id = intval($_POST['payment_id'] ?? 0);
        if ($payment_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de pago inválido.']);
            return;
        }
        echo json_encode($this->PaymentRequestsModel->delete_payment_by_id($payment_id));
    }


    /** TEMPORAL PRUEBAS - quitar cuando terminen las pruebas */
    public function reset_test_data()
    {
        header('Content-Type: application/json');
        echo json_encode($this->PaymentRequestsModel->reset_all_test_data());
    }


    /**
     * API JSON: notas disponibles (con saldo > 0) de un proveedor
     */
    public function getProviderNotes()
    {
        header('Content-Type: application/json');
        try {
            $provider_id = $_POST['provider_id'] ?? '0';
            $notes = $this->InvoiceCreditDebitNotesModel->getAvailableNotesByProvider($provider_id);
            echo json_encode(['success' => true, 'notes' => $notes ?: []]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * API JSON: todas las notas de un proveedor (para la pantalla de catálogo)
     */
    public function getAllProviderNotes()
    {
        header('Content-Type: application/json');
        try {
            $provider_id = $_POST['provider_id'] ?? null;
            $notes = $this->InvoiceCreditDebitNotesModel->getNotesByProvider($provider_id);
            echo json_encode([
                'success'    => true,
                'notes'      => $notes ?: [],
                'can_manage' => authorized(66),
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * API JSON: notas ya aplicadas a una factura específica (independiente del pago)
     */
    public function getInvoiceNoteApplications()
    {
        header('Content-Type: application/json');
        try {
            $invoiceId = $_POST['invoice_id'] ?? null;
            if (!$invoiceId) {
                throw new Exception('invoice_id es requerido');
            }
            $apps = $this->CreditNoteApplicationsModel->getByInvoice((int)$invoiceId);
            echo json_encode(['success' => true, 'applications' => $apps ?: []]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Aplicar una nota de crédito/cargo a una factura dentro de un pago
     */
    public function applyCreditNote()
    {
        header('Content-Type: application/json');
        try {
            if (!authorized(66)) {
                json_output(['success' => false, 'message' => 'Sin permiso para aplicar notas']);
                return;
            }

            $requiredFields = ['credit_note_id', 'payment_request_id', 'applied_amount'];
            foreach ($requiredFields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("El campo {$field} es requerido");
                }
            }

            $creditNoteId     = (int)$_POST['credit_note_id'];
            $paymentRequestId = (int)$_POST['payment_request_id'];
            $invoiceId        = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
            $appliedAmount    = (float)$_POST['applied_amount'];

            if ($appliedAmount <= 0) {
                throw new Exception('El monto a aplicar debe ser mayor a cero');
            }

            // Verificar que la nota existe
            $note = $this->InvoiceCreditDebitNotesModel->getNoteById($creditNoteId);
            if (!$note) {
                throw new Exception('Nota no encontrada');
            }

            // Verificar saldo disponible de la nota
            $available = $this->InvoiceCreditDebitNotesModel->getAvailableBalance($creditNoteId);
            if ($appliedAmount > $available + 0.001) {
                throw new Exception("El monto a aplicar ($appliedAmount) supera el saldo disponible ($available)");
            }

            // Verificar que no se exceda el saldo de la factura (si aplica a una factura)
            if ($invoiceId) {
                $invoiceDetail = $this->paymentRequestInvoicesModel->get_invoices_detail_by_ids((string)$invoiceId);
                if ($invoiceDetail) {
                    $inv = $invoiceDetail[0];
                    $saldoFactura = (float)$inv['amount'] - (float)$inv['paid_amount']
                        - (float)$inv['total_notas_credito'] + (float)$inv['total_notas_cargo'];
                    if ($note['note_type'] === 'CREDIT' && $appliedAmount > $saldoFactura + 0.001) {
                        throw new Exception("El monto a aplicar ($appliedAmount) supera el saldo pendiente de la factura ($saldoFactura)");
                    }
                }
            }

            $appId = $this->CreditNoteApplicationsModel->applyNote([
                'credit_note_id'     => $creditNoteId,
                'payment_request_id' => $paymentRequestId,
                'invoice_id'         => $invoiceId,
                'applied_amount'     => $appliedAmount,
                'created_by'         => $_SESSION['tg_user']['Id']
            ]);

            if (!$appId) {
                throw new Exception('Error al registrar la aplicación');
            }

            // Recalcular totales de notas en payment_requests
            $this->CreditNoteApplicationsModel->updatePaymentNoteTotals($paymentRequestId);

            echo json_encode([
                'success'    => true,
                'message'    => 'Nota aplicada correctamente',
                'app_id'     => $appId
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Quitar una aplicación de nota (libera el saldo)
     */
    public function removeCreditNoteApplication()
    {
        header('Content-Type: application/json');
        try {
            $appId = $_POST['application_id'] ?? null;
            if (!$appId) {
                throw new Exception('application_id es requerido');
            }

            $app = $this->CreditNoteApplicationsModel->getById((int)$appId);
            if (!$app) {
                throw new Exception('Aplicación no encontrada');
            }

            if (!$this->CreditNoteApplicationsModel->removeApplication((int)$appId)) {
                throw new Exception('Error al eliminar la aplicación');
            }

            // Recalcular totales de notas en payment_requests
            $paymentRequestId = $app['payment_request_id'];
            $this->CreditNoteApplicationsModel->updatePaymentNoteTotals($paymentRequestId);

            echo json_encode(['success' => true, 'message' => 'Aplicación eliminada correctamente']);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }


    /**
     * Eliminar nota de crédito/cargo (soft delete)
     */
    public function deleteCreditDebitNote($noteId)
    {
        try {
            // Verificar que la nota existe y obtener su información
            $note = $this->InvoiceCreditDebitNotesModel->getNoteById($noteId);

            if (!$note) {
                throw new Exception('Nota no encontrada o ya fue eliminada');
            }

            // Soft delete: cambiar status a 0
            $deleted = $this->InvoiceCreditDebitNotesModel->deleteCreditDebitNote(
                $noteId,
                $_SESSION['tg_user']['Id']
            );

            if (!$deleted) {
                throw new Exception('Error al eliminar la nota');
            }

            echo json_encode([
                'success' => true,
                'message' => 'Nota eliminada correctamente'
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }


    // function delete_payment()
    // {
    //     header('Content-Type: application/json');

    //     try {
    //         $payment_id = $_POST['payment_id'] ?? null;
    //         if (!$payment_id) {
    //             json_output(['success' => false, 'message' => 'ID de pago requerido']);
    //             return;
    //         }
    //         // Llamar al modelo para eliminar con transacción
    //         $result = $this->PaymentRequestsModel->delete_payment_complete($payment_id);
    //         json_output($result);
    //     } catch (Exception $e) {
    //         json_output(['success' => false, 'message' => $e->getMessage()]);
    //     }
    // }

    private function getStatusBadge($status)
    {
        return PaymentRequestsModel::getStatusBadge($status);
    }


    /**
     * Método auxiliar para get_department_name (también en el modelo)
     */
    public function get_department_name($permission_number)
    {
        return $permission_number === 68 ? 'Tesorería' : 'Desconocido';
    }


    public function modalCrearAnticipo()
    {
        try {
            $proveedores = $this->proveedores->get_actives();
            $companys = $this->gasolinerasModel->get_company();

            // Renderizar vista Twig
            echo $this->twig->render($this->route . 'modals/crear_anticipo.html', compact('proveedores', 'companys'));
        } catch (Exception $e) {
            error_log('Error en modalCrearAnticipo: ' . $e->getMessage());
            echo '<div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        Error al cargar el formulario: ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>';
        }
    }


    /**
     * Crear un nuevo anticipo (pago sin factura)
     */
    public function create_anticipo()
    {
        header('Content-Type: application/json');
        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            $user_id = $_SESSION['tg_user']['Id'] ?? null;
            // Validar que el usuario esté autenticado
            if (!isset($user_id)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ]);
                return;
            }
            // Extraer y validar datos
            $provider_cod = $data['provider_cod'] ?? null;
            $empresa_cod = $data['empresa_cod'] ?? null;
            $monto = $data['monto'] ?? 0;
            $comentario = trim($data['comentario'] ?? '');
            $scheduled_payment_date = $data['fecha_pago'] ?? null;


            // Validaciones
            if (empty($provider_cod)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe seleccionar un proveedor'
                ]);
                return;
            }

            if (empty($empresa_cod)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Debe seleccionar una empresa'
                ]);
                return;
            }

            if (!is_numeric($monto) || $monto <= 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'El monto debe ser mayor a cero'
                ]);
                return;
            }

            // Obtener nombre del proveedor
            $proveedor = $this->proveedores->get_by_id($provider_cod);
            if (!$proveedor) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Proveedor no encontrado'
                ]);
                return;
            }

            // Preparar datos para el modelo
            $anticipo_data = [
                'provider_cod' => $provider_cod,
                'empresa_cod' => $empresa_cod,
                'monto_total' => $monto,
                'nombre_request' => 'ANTICIPO - ' . $proveedor['den'],
                'comentario' => $comentario,
                'user_id' => $user_id,
                'scheduled_payment_date' => $scheduled_payment_date
            ];

            // Crear anticipo usando el modelo
            $result = $this->PaymentRequestsModel->create_anticipo($anticipo_data);

            if ($result['success']) {
                // Log de auditoría
                error_log("ANTICIPO CREADO: ID={$result['anticipo_id']}, Provider=$provider_cod, Empresa=$empresa_cod, Monto=$monto, User=$user_id");

            }

            // Retornar resultado
            echo json_encode($result);
        } catch (Exception $e) {
            error_log('Error en create_anticipo: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            echo json_encode([
                'success' => false,
                'message' => 'Error al procesar la solicitud: ' . $e->getMessage()
            ]);
        }
    }


    public function configLayoutModal()
    {
        try {
            $payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;

            if (!$payment_id) {
                echo '<div class="alert alert-danger">ID de pago requerido</div>';
                return;
            }

            // Obtener datos del pago
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);
            if (!$payment || count($payment) === 0) {
                echo '<div class="alert alert-danger">Pago no encontrado</div>';
                return;
            }
            $payment = $payment[0];

            // Obtener datos del proveedor
            $proveedor = $this->proveedores->get_by_id($payment['provider_cod']);
            if (!$proveedor) {
                echo '<div class="alert alert-danger">Proveedor no encontrado</div>';
                return;
            }

            // Obtener TODAS las cuentas bancarias del proveedor
            $cuentas_bancarias = $this->CuentasBancariasModel->get_cuentas($proveedor['den']);

            if (!$cuentas_bancarias || count($cuentas_bancarias) === 0) {
                echo '<div class="alert alert-warning">' .
                    '<i class="fas fa-exclamation-triangle"></i> ' .
                    'No se encontraron cuentas bancarias para el proveedor: <strong>' . htmlspecialchars($proveedor['den']) . '</strong>' .
                    '</div>';
                return;
            }

            // Obtener facturas del pago
            $facturas = $this->paymentRequestInvoicesModel->get_by_payment_request_with_transactions($payment_id);

            // Renderizar vista Twig
            echo $this->twig->render($this->route . 'modals/configLayoutModal.html', [
                'proveedor' => [
                    'codigo' => $payment['provider_cod'],
                    'nombre' => $proveedor['den']
                ],
                'cuentas_bancarias' => $cuentas_bancarias,
                'facturas' => $facturas
            ]);
        } catch (Exception $e) {
            error_log('Error en configLayoutModal: ' . $e->getMessage());
            echo '<div class="alert alert-danger">' .
                '<i class="fas fa-exclamation-circle"></i> ' .
                'Error al cargar la configuración: ' . htmlspecialchars($e->getMessage()) .
                '</div>';
        }
    }


    public function anticipo_summary_json($anticipo_id)
    {
        header('Content-Type: application/json');
        $summary = $this->PaymentRequestsModel->get_anticipo_summary((int)$anticipo_id);
        if (!$summary) {
            json_output(['success' => false, 'message' => 'Anticipo no encontrado']);
            return;
        }
        json_output(['success' => true, 'data' => $summary]);
    }


    public function anticipo_detail($anticipo_id)
    {
        //  try {
        // Obtener datos del anticipo - USAR MÉTODO CORRECTO
        $anticipo = $this->PaymentRequestsModel->get_request_by_id($anticipo_id);
        if (!$anticipo || $anticipo['tipo'] != 1) { // tipo 1 = anticipo
            $_SESSION['error'] = 'Anticipo no encontrado';
            header('Location: /payment/payment_list');
            return;
        }
        // Obtener aplicaciones del anticipo
        $aplicaciones = $this->PaymentRequestsModel->get_anticipo_applications($anticipo_id);
        // Obtener resumen (totales)
        $summary = $this->PaymentRequestsModel->get_anticipo_summary($anticipo_id);
        // Si no hay summary, crear uno vacío
        if (!$summary) {
            $summary = [
                'id' => $anticipo_id,
                'monto_original' => $anticipo['monto_total'],
                'total_aplicado' => 0,
                'saldo_disponible' => $anticipo['monto_total'],
                'total_aplicaciones' => 0
            ];
        }
        // Obtener autorizaciones
        $authorizations = $this->PaymentRequestsModel->getPaymentAuthorizations($anticipo_id);
        // Estado de autorizaciones
        $auth_status = $this->PaymentRequestsModel->getAuthorizationStatus($anticipo_id);
        // Renderizar vista
        // echo $this->twig->render($this->route . 'anticipo_detail.html', compact());

        echo $this->twig->render($this->route . 'anticipo_detail.html', [
            'anticipo' => $anticipo,
            'aplicaciones' => $aplicaciones,
            'summary' => $summary,
            'authorizations' => $authorizations,
            'authorization_status' => $auth_status,
            'auth_info' => [
                'abastos'       => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 66),
                'contabilidad'  => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 70),
                'admin_finanzas'=> $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 67),
                'tesoreria'     => $this->PaymentRequestsModel->getAuthorizationInfo($anticipo_id, 68)
            ]
        ]);
        // } catch (Exception $e) {
        //     error_log("Error en anticipo_detail: " . $e->getMessage());
        //     $_SESSION['error'] = 'Error al cargar el detalle del anticipo';
        //     header('Location: /payment/payment_list');

        //     // $this->redirectTo('supply/payment_list');
        // }
    }


    public function apply_anticipo_to_invoices()
    {
        header('Content-Type: application/json');

        try {
            $anticipo_id = $_POST['anticipo_id'] ?? null;
            $aplicaciones = json_decode($_POST['aplicaciones'] ?? '[]', true);

            if (!$anticipo_id || empty($aplicaciones)) {
                json_output(['success' => false, 'message' => 'Datos incompletos']);
                return;
            }

            // Validar saldo disponible
            $anticipo = $this->PaymentRequestsModel->get_request_by_id($anticipo_id);

            if (!$anticipo || $anticipo['tipo'] != 1) {
                json_output(['success' => false, 'message' => 'Anticipo no válido']);
                return;
            }

            $saldo = $this->PaymentRequestsModel->get_saldo_disponible($anticipo_id);
            $total_aplicar = array_sum(array_column($aplicaciones, 'monto'));

            if ($total_aplicar > $saldo) {
                json_output(['success' => false, 'message' => 'Excede saldo disponible']);
                return;
            }

            // Registrar aplicaciones
            $result = $this->PaymentRequestsModel->register_anticipo_applications(
                $anticipo_id,
                $aplicaciones,
                $_SESSION['tg_user']['Id']
            );

            json_output($result);
        } catch (Exception $e) {
            error_log("Error en apply_anticipo_to_invoices: " . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error del servidor']);
        }
    }


    private function generar_html_notificacion_pago($payment_id, $provider_name, $total_documents, $total_amount, $comment, $created_by)
    {
        $fecha = date('d/m/Y H:i:s');
        $total_formatted = number_format($total_amount, 2, '.', ',');

        // URL del detalle del pago (ajustar según tu dominio)
        $url_detalle = "http://totalgasonline.net:400/payment/payment_detail/{$payment_id}";

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .badge {
                    display: inline-block;
                    background: #667eea;
                    color: white;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 0;
                    border-bottom: 1px solid #eee;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #666;
                    font-weight: 500;
                }
                .info-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                    text-align: center;
                }
                .total-label {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 5px;
                }
                .total-amount {
                    color: #28a745;
                    font-size: 32px;
                    font-weight: bold;
                }
                .comment-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .comment-label {
                    font-weight: 600;
                    color: #856404;
                    margin-bottom: 5px;
                }
                .comment-text {
                    color: #856404;
                }
                .button {
                    display: inline-block;
                    background: #667eea;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .button:hover {
                    background: #5568d3;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
                .icon {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    margin-right: 8px;
                    vertical-align: middle;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✓ Nuevo Pago Creado</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>
                
                <div class='content'>
                    <div class='badge'>🔔 Notificación - Departamento de Abastos</div>
                    
                    <p style='color: #333; line-height: 1.6;'>
                        Se ha creado un nuevo pago que requiere autorización del departamento de Abastos.
                    </p>
                    
                    <div style='margin: 20px 0;'>
                        <div class='info-row'>
                            <span class='info-label'>📋 ID de Pago:</span>
                            <span class='info-value'>#{$payment_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🏢 Proveedor:</span>
                            <span class='info-value'>{$provider_name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📄 Documentos:</span>
                            <span class='info-value'>{$total_documents}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>👤 Creado por:</span>
                            <span class='info-value'>{$created_by}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📅 Fecha:</span>
                            <span class='info-value'>{$fecha}</span>
                        </div>
                    </div>
                    
                    <div class='total-box'>
                        <div class='total-label'>Monto Total del Pago</div>
                        <div class='total-amount'>{$total_formatted}</div>
                    </div>
                    
                    " . (!empty($comment) ? "
                    <div class='comment-box'>
                        <div class='comment-label'>💬 Comentario:</div>
                        <div class='comment-text'>{$comment}</div>
                    </div>
                    " : "") . "
                    
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_detalle}' class='button'>
                            Ver Detalle del Pago →
                        </a>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                        Este pago requiere su autorización para poder continuar con el proceso de pago. 
                        Por favor, revise los documentos y autorice según corresponda.
                    </p>
                </div>
                
                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>
                        © " . date('Y') . " TotalGas. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }


    private function generar_html_notificacion_anticipo($anticipo_id, $provider_name, $monto, $comentario, $created_by)
    {
        $fecha = date('d/m/Y H:i:s');
        $monto_formatted = number_format($monto, 2, '.', ',');
        $url_detalle = "http://totalgasonline.net:400/payment/payment_detail/{$anticipo_id}";

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .badge {
                    display: inline-block;
                    background: #f5576c;
                    color: white;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 0;
                    border-bottom: 1px solid #eee;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #666;
                    font-weight: 500;
                }
                .info-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                    text-align: center;
                }
                .total-label {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 5px;
                }
                .total-amount {
                    color: #f5576c;
                    font-size: 32px;
                    font-weight: bold;
                }
                .comment-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .comment-label {
                    font-weight: 600;
                    color: #856404;
                    margin-bottom: 5px;
                }
                .comment-text {
                    color: #856404;
                }
                .button {
                    display: inline-block;
                    background: #f5576c;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .button:hover {
                    background: #e04458;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Nuevo Anticipo Creado</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>

                <div class='content'>
                    <div class='badge'>Notificación - Departamento de Abastos</div>

                    <p style='color: #333; line-height: 1.6;'>
                        Se ha creado un nuevo anticipo que requiere autorización del departamento de Abastos.
                    </p>

                    <div style='margin: 20px 0;'>
                        <div class='info-row'>
                            <span class='info-label'>ID de Anticipo:</span>
                            <span class='info-value'>#{$anticipo_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Proveedor:</span>
                            <span class='info-value'>{$provider_name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Creado por:</span>
                            <span class='info-value'>{$created_by}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Fecha:</span>
                            <span class='info-value'>{$fecha}</span>
                        </div>
                    </div>

                    <div class='total-box'>
                        <div class='total-label'>Monto del Anticipo</div>
                        <div class='total-amount'>\${$monto_formatted}</div>
                    </div>

                    " . (!empty($comentario) ? "
                    <div class='comment-box'>
                        <div class='comment-label'>Justificación:</div>
                        <div class='comment-text'>{$comentario}</div>
                    </div>
                    " : "") . "

                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_detalle}' class='button'>
                            Ver Detalle del Anticipo
                        </a>
                    </div>

                    <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                        Este anticipo requiere su autorización para poder continuar con el proceso.
                        Por favor, revise la información y autorice según corresponda.
                    </p>
                </div>

                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>
                        © " . date('Y') . " TotalGas. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }


    private function generar_html_notificacion_autorizacion(
        $payment_id,
        $provider_name,
        $total_documents,
        $total_amount,
        $authorized_department,
        $next_department,
        $comment
    ) {
        $fecha = date('d/m/Y H:i:s');
        $total_formatted = number_format($total_amount, 2, '.', ',');
        $url_detalle = "http://totalgasonline.net:400/payment/payment_detail/{$payment_id}";

        return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f4f4f4;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                .header h1 {
                    margin: 0;
                    font-size: 24px;
                }
                .header p {
                    margin: 5px 0 0 0;
                    opacity: 0.9;
                }
                .content {
                    padding: 30px;
                }
                .badge {
                    display: inline-block;
                    background: #17a2b8;
                    color: white;
                    padding: 5px 15px;
                    border-radius: 20px;
                    font-size: 14px;
                    margin-bottom: 20px;
                }
                .status-box {
                    background: #d1ecf1;
                    border: 1px solid #bee5eb;
                    border-radius: 8px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .status-box .status-label {
                    font-weight: 600;
                    color: #0c5460;
                    margin-bottom: 8px;
                }
                .status-item {
                    display: flex;
                    align-items: center;
                    padding: 8px 0;
                }
                .status-item i {
                    margin-right: 10px;
                    font-size: 18px;
                }
                .status-completed {
                    color: #28a745;
                }
                .status-pending {
                    color: #ffc107;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 15px 0;
                    border-bottom: 1px solid #eee;
                }
                .info-row:last-child {
                    border-bottom: none;
                }
                .info-label {
                    color: #666;
                    font-weight: 500;
                }
                .info-value {
                    color: #333;
                    font-weight: 600;
                }
                .total-box {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 8px;
                    margin: 20px 0;
                    text-align: center;
                }
                .total-label {
                    color: #666;
                    font-size: 14px;
                    margin-bottom: 5px;
                }
                .total-amount {
                    color: #17a2b8;
                    font-size: 32px;
                    font-weight: bold;
                }
                .comment-box {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .comment-label {
                    font-weight: 600;
                    color: #856404;
                    margin-bottom: 5px;
                }
                .comment-text {
                    color: #856404;
                }
                .button {
                    display: inline-block;
                    background: #17a2b8;
                    color: white;
                    padding: 12px 30px;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                    font-weight: 600;
                }
                .button:hover {
                    background: #138496;
                }
                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                }
                .alert-box {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    color: #155724;
                    padding: 15px;
                    border-radius: 8px;
                    margin: 20px 0;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏳ Autorización Requerida</h1>
                    <p>Sistema de Gestión de Pagos - TotalGas</p>
                </div>

                <div class='content'>
                    <div class='badge'>🔔 Notificación - {$next_department}</div>

                    <div class='alert-box'>
                        <br>Se ha autorizado el pago.<br>
                        Ahora requiere tu autorización para continuar con el proceso.
                    </div>

                    <div style='margin: 20px 0;'>
                        <div class='info-row'>
                            <span class='info-label'>📋 ID de Pago:</span>
                            <span class='info-value'>#{$payment_id}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🏢 Proveedor:</span>
                            <span class='info-value'>{$provider_name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📄 Documentos:</span>
                            <span class='info-value'>{$total_documents}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📅 Fecha:</span>
                            <span class='info-value'>{$fecha}</span>
                        </div>
                    </div>

                    <div class='total-box'>
                        <div class='total-label'>Monto Total del Pago</div>
                        <div class='total-amount'>\${$total_formatted}</div>
                    </div>

                    <div class='status-box'>
                        <div class='status-label'>📊 Estado de Autorizaciones:</div>
                        <div class='status-item status-completed'>
                            <i class='fas fa-check-circle'></i>
                            <span><strong>{$authorized_department}:</strong> Autorizado</span>
                        </div>
                        <div class='status-item status-pending'>
                            <i class='fas fa-clock'></i>
                            <span><strong>{$next_department}:</strong> Pendiente (Tu autorización)</span>
                        </div>
                    </div>

                    " . (!empty($comment) ? "
                    <div class='comment-box'>
                        <div class='comment-label'>💬 Comentario:</div>
                        <div class='comment-text'>{$comment}</div>
                    </div>
                    " : "") . "

                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='{$url_detalle}' class='button'>
                            Revisar y Autorizar Pago →
                        </a>
                    </div>

                    <p style='color: #666; font-size: 14px; margin-top: 30px; text-align: center;'>
                        <strong>⚠️ Acción Requerida:</strong><br>
                        Este pago necesita tu autorización para continuar con el flujo de aprobación.
                    </p>
                </div>

                <div class='footer'>
                    <p><strong>TotalGas - Sistema de Gestión de Pagos</strong></p>
                    <p>Este es un correo automático, por favor no responda a este mensaje.</p>
                    <p style='margin-top: 10px; font-size: 11px;'>
                        © " . date('Y') . " TotalGas. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

        private function buildAuthorizationIndicator(
        $abastos,
        $contabilidad,
        $admin,
        $tesoreria,
        $abastos_user,
        $contabilidad_user,
        $admin_user,
        $tesoreria_user,
        $abastos_date,
        $contabilidad_date,
        $admin_date,
        $tesoreria_date
    ) {
        $html = '<div class="d-flex gap-1 align-items-center justify-content-center">';

        // Solo Tesorería
        if ($tesoreria) {
            $tooltip = "Tesorería ✓\n" . ($tesoreria_user ?: 'N/A') . "\n" . ($tesoreria_date ? date('d/m/Y H:i', strtotime($tesoreria_date)) : '');
            $html .= '<div class="auth-box bg-success" title="' . htmlspecialchars($tooltip) . '" data-bs-toggle="tooltip">
                        <i class="fas fa-check text-white"></i>
                    </div>';
        } else {
            $html .= '<div class="auth-box bg-warning" title="Esperando: Tesorería" data-bs-toggle="tooltip">
                        <i class="fas fa-clock text-white"></i>
                    </div>';
        }

        $html .= '</div>';
        return $html;
    }


    /**
     * JSON: documentos adjuntos de una transacción.
     * GET /payment/get_transaction_documents?transaction_id=X
     */
    public function get_transaction_documents()
    {
        header('Content-Type: application/json');
        $transaction_id = (int)($_GET['transaction_id'] ?? 0);
        if (!$transaction_id) {
            json_output(['success' => false, 'message' => 'transaction_id requerido']);
            return;
        }
        $docs = $this->PaymentTransactionDocumentsModel->get_by_transaction($transaction_id);
        json_output(['success' => true, 'data' => $docs]);
    }


    /**
     * Sirve el archivo de un comprobante de pago.
     * GET /payment/view_payment_document/ID
     */
    public function view_payment_document($doc_id)
    {
        $doc = $this->PaymentTransactionDocumentsModel->get_by_id((int)$doc_id);
        if (!$doc) {
            http_response_code(404);
            echo 'Documento no encontrado';
            return;
        }

        $fullPath = realpath(__DIR__ . '/../../' . $doc['file_path']);
        $base     = realpath(__DIR__ . '/../../_assets/uploads/payment_documents/');

        if (!$fullPath || !str_starts_with($fullPath, $base) || !file_exists($fullPath)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }

        $mime = match($doc['file_extension']) {
            'pdf'        => 'application/pdf',
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            default      => 'application/octet-stream'
        };

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
    }


    public function invoices_due()
    {
        $stations    = $this->gasolinerasModel->get_active_stations();
        $proveedores = $this->proveedores->get_actives();
        echo $this->twig->render($this->route . 'invoices_due.html', compact('stations', 'proveedores'));
    }


    /**
     * Vista de administración del catálogo de cuentas bancarias (permiso 66).
     */
    public function bank_accounts()
    {
        if (!authorized(66)) {
            $this->twig->render('views/errors/403.html');
            echo '<h3 class="text-center mt-5">Sin permiso para ver esta sección.</h3>';
            return;
        }
        $proveedores = $this->proveedores->get_actives() ?: [];
        echo $this->twig->render($this->route . 'bank_accounts.html', compact('proveedores'));
    }

    /**
     * JSON con todas las cuentas bancarias para el DataTable de administración.
     */
    public function bank_accounts_table()
    {
        header('Content-Type: application/json');
        if (!authorized(66)) {
            json_output(['data' => [], 'error' => 'Sin permiso']);
            return;
        }
        $cuentas = $this->CuentasBancariasModel->get_cuentas_admin();
        json_output(['data' => $cuentas]);
    }

    /**
     * Actualiza una cuenta bancaria desde el modal de edición (permiso 66).
     */
    public function update_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(66)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $cuenta = $this->CuentasBancariasModel->get_by_id($id);
        if (!$cuenta) {
            json_output(['success' => false, 'message' => 'La cuenta no existe']);
            return;
        }

        $cuenta_local = trim($_POST['CuentaLocal'] ?? '');
        if ($cuenta_local === '') {
            json_output(['success' => false, 'message' => 'La cuenta (CLABE/Cuenta) es obligatoria']);
            return;
        }

        $data = [
            'CuentaLocal'   => $cuenta_local,
            'Descripcion'   => trim($_POST['Descripcion'] ?? ''),
            'Banco'         => trim($_POST['Banco'] ?? ''),
            'TitularCuenta' => trim($_POST['TitularCuenta'] ?? ''),
            'Tipo'          => trim($_POST['Tipo'] ?? ''),
            'Divisa'        => trim($_POST['Divisa'] ?? ''),
            'emp_cod'       => trim($_POST['emp_cod'] ?? ''),
            'proveedor_cod' => trim($_POST['proveedor_cod'] ?? ''),
            'Activo'        => intval($_POST['Activo'] ?? 1),
        ];

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->CuentasBancariasModel->update_admin($id, $data, $user_id);

        json_output([
            'success' => (bool)$ok,
            'message' => $ok ? 'Cuenta actualizada correctamente' : 'No se pudo actualizar la cuenta',
        ]);
    }

    /**
     * Activa o desactiva una cuenta bancaria (permiso 66).
     */
    public function toggle_bank_account()
    {
        header('Content-Type: application/json');
        if (!authorized(66)) {
            json_output(['success' => false, 'message' => 'Sin permiso']);
            return;
        }

        $id     = intval($_POST['id'] ?? 0);
        $activo = intval($_POST['activo'] ?? 0) === 1 ? 1 : 0;
        if (!$id) {
            json_output(['success' => false, 'message' => 'ID inválido']);
            return;
        }

        $user_id = $_SESSION['tg_user']['Id'] ?? 0;
        $ok = $this->CuentasBancariasModel->set_activo($id, $activo, $user_id);

        json_output([
            'success' => (bool)$ok,
            'activo'  => $activo,
            'message' => $ok
                ? ($activo ? 'Cuenta activada' : 'Cuenta desactivada')
                : 'No se pudo cambiar el estado',
        ]);
    }


    public function invoices_due_table()
    {
        ini_set('max_execution_time', 5000);
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        header('Content-Type: application/json');

        $from_due  = $_POST['from_due']  ?? '';
        $until_due = $_POST['until_due'] ?? '';

        if (!$from_due || !$until_due) {
            json_output(['data' => [], 'error' => 'Faltan fechas']);
            return;
        }

        $from_int  = dateToInt($from_due);
        $until_int = dateToInt($until_due);

        $postData = [
            'codgas'    => !empty($_POST['codgas'])    ? $_POST['codgas']    : '0',
            'proveedor' => !empty($_POST['proveedor']) ? $_POST['proveedor'] : '0',
            'from_due'  => $from_due,
            'until_due' => $until_due,
            'from_int'  => $from_int,
            'until_int' => $until_int,
        ];

        $ch = curl_init('http://192.168.0.109:82/api/facturas_vencen_hoy/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $response = curl_exec($ch);
        curl_close($ch);

        $apiData = json_decode($response, true);
        $data    = [];

        if (is_array($apiData)) {
            foreach ($apiData as $row) {
                $data[] = [
                    'nro'                       => $row['nro'],
                    'Factura'                   => $row['Factura'],
                    'fecha'                     => $row['fecha'],
                    'fecha_vencimiento_credito' => $row['fecha_vencimiento_credito'] ?? null,
                    'dias_credito'              => $row['dias_credito'] ?? 0,
                    'proveedor'                 => $row['proveedor'],
                    'proveedor_codigo'          => $row['proveedor_codigo'],
                    'producto'                  => $row['producto'],
                    'can'                       => $row['can'],
                    'total_fac'                 => $row['total_fac'],
                    'satuid'                  => $row['satuid'] ?? null,
                    'fr_id'                   => $row['fr_id'] ?? null,
                    'gasolinera'              => $row['gasolinera'],
                    'razon_social_estacion'   => $row['razon_social_estacion'] ?? null,
                    'codgas'                  => $row['codgas'],
                    'codigo_empresa'          => $row['codigo_empresa'],
                ];
            }
        }

        json_output(['data' => $data]);
    }


    // DEV-ONLY: eliminar antes de producción ↓
    public function dev_reset_piloto()
    {
        header('Content-Type: application/json');
        $db = $this->PaymentRequestsModel->sql;
        $db->beginTransaction();
        try {
            $db->query("DELETE FROM [TG].[dbo].[payment_transaction_documents]");
            $db->query("DELETE FROM [TG].[dbo].[payment_transactions]");
            $db->query("DELETE FROM [TG].[dbo].[payment_request_authorizations]");
            $db->query("DELETE FROM [TG].[dbo].[credit_note_applications]");
            $db->query("DELETE FROM [TG].[dbo].[invoice_credit_debit_notes_doc]");
            $db->query("DELETE FROM [TG].[dbo].[invoice_credit_debit_notes]");
            $db->query("DELETE FROM [TG].[dbo].[payment_request_invoices]");
            $db->query("UPDATE [TG].[dbo].[payment_requests] SET accounting_group_id = NULL");
            $db->query("DELETE FROM [TG].[dbo].[payment_accounting_groups]");
            $db->query("DELETE FROM [TG].[dbo].[payment_request_bulk_authorizations]");
            $db->query("DELETE FROM [TG].[dbo].[payment_requests]");
            $db->commit();

            // Limpiar archivos físicos
            $dirs = [
                __DIR__ . '/../../_assets/uploads/payment_documents/',
                __DIR__ . '/../../_assets/uploads/credit_debit_notes/',
            ];
            foreach ($dirs as $dir) {
                if (is_dir($dir)) {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($it as $file) {
                        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
                    }
                }
            }

            json_output(['success' => true, 'message' => 'Datos de prueba eliminados correctamente']);
        } catch (Exception $e) {
            $db->rollBack();
            json_output(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    // /DEV-ONLY ↑

}
