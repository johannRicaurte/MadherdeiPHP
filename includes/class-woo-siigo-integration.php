<?php
class Woo_Siigo_Integration
{
    private Siigo_API $siigo_api;
    public function __construct()
    {
        $this->siigo_api = new Siigo_API();
        add_action('woocommerce_order_status_changed', [$this, 'generate_siigo_invoice'], 10, 1);
    }

    public function generate_siigo_invoice($order_id)
    {
        $order = wc_get_order($order_id);
        $token = $this->siigo_api->auth();
        if (!$token) {
            error_log('Token de Siigo no configurado.');
            return;
        }

        error_log("PEDIDO: " . json_encode($order));
        // TODO: Mapear datos del pedido a la estructura de Siigo
        // TODO: Hacer que para document["id"] se obtenga el id de Siigo
        $invoice_data = [
            'document' => [
                "id" => 26625
            ],
            'customer' => [
                'person_type' => "person",
                'identification' => strval($order->get_meta("_billing_doc_number")),
                'id_type' => strval($order->get_meta("_billing_doc_type")),
                'name' => array_merge(
                    explode(" ", $order->get_billing_first_name()),
                    explode(" ", $order->get_billing_last_name())
                )
            ],
            'date' => $order->get_date_created()->format('Y-m-d'),
            'due_date' => $order->get_date_created()->modify('+30 days')->format('Y-m-d'),
            'items' => $this->get_order_items($order),
            'payment' => [
                'id' => $this->get_siigo_payment_method($order->get_payment_method()),
            ],
            // Agrega otros campos requeridos por la DIAN (prefijo, resolución, etc.)
        ];

        error_log("Payload: " . json_encode($invoice_data));

        $response = $this->siigo_api->create_invoice($invoice_data);

        if ($response) {
            // Guardar el ID de la factura en los metadatos del pedido
            $order->update_meta_data('_siigo_invoice_id', $response['id']);
            $order->save();
            $order->add_order_note('Factura electrónica generada en Siigo con ID: ' . $response['id']);
        } else {
            $order->add_order_note('Error al generar factura electrónica en Siigo.');
        }
    }

    private function get_siigo_customer_id($order)
    {
        //TODO: Crear lógica para obtención de un cliente de siigo
        // Lógica para obtener o crear el cliente en Siigo
        // Por ejemplo, buscar por email o NIT en Siigo
        // Si no existe, crear un cliente nuevo con los datos de $order->get_billing_*
        return 'CUSTOMER_ID'; // Reemplaza con el ID real
    }

    private function get_order_items($order)
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            error_log("Item: " . json_encode($item->get_meta_data()));
            error_log("Item B: " . $item);
            error_log("Product: " . json_encode($product->get_item_tax()));
            $items[] = [
                'code' => $product->get_sku() ?: $product->get_id(),
                'description' => $product->get_name(),
                'quantity' => $item->get_quantity(),
                'price' => $item->get_subtotal() / $item->get_quantity(),
                'taxes' => [
                    [
                        'id' => $this->get_siigo_tax_id($item), // Mapear el IVA o impuestos
                    ],
                ],
            ];
        }
        return $items;
    }

    private function get_siigo_tax_id($item)
    {
        // TODO: Mapear los impuestos de WooCommerce al ID de impuestos en Siigo
        return 3013; // Reemplaza con el ID real
    }

    private function get_siigo_payment_method($wc_payment_method)
    {
        // TODO: Mapear métodos de pago de WooCommerce a Siigo
        $payment_methods = [
            'cod' => 'PAYMENT_ID_COD',
            'bacs' => 'PAYMENT_ID_BANK',
            // Agrega más métodos según tu configuración
        ];
        return isset($payment_methods[$wc_payment_method]) ? $payment_methods[$wc_payment_method] : 'DEFAULT_PAYMENT_ID';
    }
    //Funcion para traer las categorias
    public function get_account_group_name($group_id)
{
    if (empty($group_id)) {
        return null;
    }

    $url = "https://api.siigo.com/v1/account-groups/$group_id";
    $response = $this->request('GET', $url);

    if (isset($response['name'])) {
        return sanitize_text_field($response['name']);
    }

    error_log("No se pudo obtener el nombre del grupo con ID $group_id");
    return null;
}
}