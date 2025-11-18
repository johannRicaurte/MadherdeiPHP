<?php
if (!defined('ABSPATH'))
    exit;

class Siigo_API
{
    private $api_url = "https://api.siigo.com/";

    private function get_siigo_customer_id($order)
    {
        $token = $this->auth();
        if (!$token) {
            error_log("Error: No se pudo obtener el token de Siigo");
            return false;
        }
        $params = [
            "identification" => $order,
        ];
        $url = "{$this->api_url}v1/customers";
        $url = add_query_arg($params, $url);
        $args = [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
                'Partner-Id' => PARTNER_ID
            ]
        ];
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log(message: "Error: no se pudo obtener el cliente" . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data["results"])) {
            return false;
        }
        return $data["results"][0];
    }

    private function create_siigo_customer($order)
    {
        $token = $this->auth();
        $url = "{$this->api_url}v1/customers";
        $args = [
            "body" => json_encode([
                "type" => "Customer",
                "person_type" => "Person",
                "id_type" => "13",
                "identification" => "13832081",
                "check_digit" => "4",
                "name" => [
                    "Marcos",
                    "Castillo"
                ],
                "commercial_name" => "Siigo",
                "branch_office" => 0,
                "active" => true,
                "vat_responsible" => false,
                "fiscal_responsibilities" => [
                    [
                        "code" => "R-99-PN"
                    ]
                ],
                "address" => [
                    "address" => "Cra. 18 #79A - 42",
                    "city" => [
                        "country_code" => "Co",
                        "state_code" => "19",
                        "city_code" => "19001"
                    ],
                    "postal_code" => "110911"
                ],
                "phones" => [
                    [
                        "indicative" => "57",
                        "number" => "3006003345",
                        "extension" => "132"
                    ]
                ],
                "contacts" => [
                    [
                        "first_name" => "Marcos",
                        "last_name" => "Castillo",
                        "email" => "marcos.castillo@contacto.com",
                        "phone" => [
                            "indicative" => "57",
                            "number" => "3006003345",
                            "extension" => "132"
                        ]
                    ]
                ],
                "comments" => "Comentarios",
                "related_users" => [
                    "seller_id" => 629,
                    "collector_id" => 629
                ]
            ]),
            "headers" => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
                'Partner-Id' => PARTNER_ID
            ]
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            error_log(message: 'Error en la solicitud: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function auth($retries = 3, $retry_delay = 5)
    {
        $cached_token = get_transient("siigo_access_token");

        if ($cached_token) {
            return $cached_token;
        }

        $url = "{$this->api_url}auth";
        $args = [
            'timeout' => 120,
            'body' => json_encode([
                "username" => SIIGO_USERNAME,
                "access_key" => SIIGO_KEY,
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ];

        $attempt = 0;

        while ($attempt < $retries) {
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                error_log(message: "Intento $attempt fallido en siigo_auth: " . $response->get_error_message());
                $attempt++;
                if ($attempt < $retries) {
                    sleep($retry_delay);
                    continue;
                }
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($code === 200 && !empty($data["access_token"])) {
                set_transient('siigo_access_token', $data['access_token'], HOUR_IN_SECONDS);
                return $data['access_token'];
            } else {
                error_log("Intento $attempt fallido en siigo_auth: Código $code, Respuesta: $body");
                if ($code === 401) {
                    delete_transient('siigo_access_token');
                }
                $attempt++;
                if ($attempt < $retries) {
                    sleep($retry_delay);
                    continue;
                }
            }

        }

        error_log("Fallo definitivo en siigo_auth después de $retries intentos");
        return false;
    }

    public function get_siigo_products($page = 1, $retries = 3)
    {
        $token = $this->auth();
        if (!$token) {
            error_log("Error: No se pudo obtener el token de Siigo");
            return false;
        }

        $url = $this->api_url . "v1/products?page_size=100&page=" . $page;
        $args = [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
                'Partner-Id' => PARTNER_ID,
            ]
        ];

        $attempt = 0;

        while ($attempt < $retries) {
            $response = wp_remote_get($url, $args);

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if ($code === 200 && isset($data["results"])) {
                    return $data;
                } elseif ($code === 401) {
                    delete_transient("siigo_access_token");
                    $token = auth();
                    if ($token) {
                        $args['headers']["Authorization"] = $token;
                    }
                }
            } else {
                error_log(message: "Intento $attempt fallido para página $page: " . $response->get_error_message());
            }
            $attempt++;
            if ($attempt < $retries)
                sleep(10);

        }
        error_log("Fallo definitivo al obtener página $page después de $retries intentos");
        return false;

    }

    public function get_product($product_id)
    {
        $token = $this->auth();
        $url = "https://api.siigo.com/v1/products/$product_id";
        $args = [
            "headers" => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
                'Partner-Id' => PARTNER_ID
            ]
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log(message: 'Error en la solicitud: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function get_siigo_detail_product($product_id)
    {
        $token = $this->auth();
        $url = "https://services.siigo.com/catalog/api/product/$product_id";
        $args = [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ]
        ];
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log(message: 'Error en la solicitud: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    public function create_invoice($invoice_data)
    {
        $token = $this->auth();
        if (!$token) {
            error_log("Error: No se pudo obtener el token de Siigo");
            return false;
        }
        $endpoint = "{$this->api_url}v1/invoices";
        $args = [
            'headers' => [
                'Authorization' => $token,
                'Content-Type' => 'application/json',
                'Partner-Id' => PARTNER_ID
            ],
            'body' => json_encode($invoice_data),
            'method' => 'POST',
            'timeout' => 30,
        ];

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            error_log('Error en la solicitud a Siigo: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code == 200 || $response_code == 201) {
            return $response_body;
        } else {
            error_log('Error en Siigo API: ' . print_r($response_body, true));
            return false;
        }
    }

    // TODO: Crear función para listar o crear clientes.
    public function list_customers($customer_id)
    {
        $token = $this->auth();
        $url = "{$this->api_url}v1/customers?identification=$customer_id";
        $args = [
            "headers" => [
                'Content-Type' => 'application/json',
                'Authorization' => $token,
                'Partner-Id' => PARTNER_ID
            ]
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log(message: 'Error en la solicitud: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        error_log("CLIENTE: $body");
        return json_decode($body, true);
    }
    
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
private function request($method, $url, $body = null)
{
    $token = $this->auth();
    if (!$token) {
        error_log("Error: No se pudo obtener el token de Siigo");
        return false;
    }

    $args = [
        'method'  => strtoupper($method),
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => $token,
            'Partner-Id' => PARTNER_ID,
        ]
    ];

    if ($body) {
        $args['body'] = json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        error_log("Error en request Siigo: " . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code >= 200 && $code < 300) {
        return $data;
    } else {
        error_log("Error {$code} en request Siigo: " . print_r($data, true));
        return false;
    }
}
    /**
     * Crea o recupera una categoría en WooCommerce según el nombre recibido.
     * Retorna el ID de la categoría.
     */
    public function ensure_woocommerce_category($category_name)
    {
        if (empty($category_name)) {
            return 0;
        }

        // Verificar si ya existe la categoría
        $term = term_exists($category_name, 'product_cat');
        if ($term !== 0 && $term !== null) {
            return (int) $term['term_id'];
        }

        // Crear la categoría
        $new_category = wp_insert_term($category_name, 'product_cat');
        if (is_wp_error($new_category)) {
            error_log("Error al crear categoría '$category_name': " . $new_category->get_error_message());
            return 0;
        }

        return (int) $new_category['term_id'];
    }

}
