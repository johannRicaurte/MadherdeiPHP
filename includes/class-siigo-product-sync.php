<?php
if (!defined('ABSPATH'))
    exit;

class Siigo_Product_Sync
{
    private Siigo_API $siigo_api;
    
    public function __construct()
    {
        $this->siigo_api = new Siigo_API();
        add_action('process_siigo_sync_batch', [$this, 'process_siigo_batch'], 10, 2);
    }

    public function get_all_siigo_products()
    {
        $response = $this->siigo_api->get_siigo_products();
        if (!isset($response['results']) || !isset($response['pagination'])) {
            error_log('Error: Respuesta de Siigo inválida en la primera página');
            return false;
        }

        $total_pages = ceil($response['pagination']['total_results'] / 100);
        error_log("Total de páginas a procesar: $total_pages");

        wp_schedule_single_event(time(), 'process_siigo_sync_batch', [1, $total_pages]);
        update_option('siigo_sync_total_pages', $total_pages);
        update_option('siigo_sync_progress', 0);

        return true;
    }

    public function process_siigo_batch($page, $total_pages)
    {
        $products = $this->siigo_api->get_siigo_products($page)['results'];
        if ($products) {
            $result = $this->update_woocommerce_products($products);
            $percentage = ceil(($page / $total_pages) * 100);
            update_option('siigo_sync_progress', $percentage);
            error_log("Página $page/$total_pages procesada: $percentage%, " . $result['created'] . " creados, " . $result['updated'] . " actualizados");

            if ($page < $total_pages) {
                wp_schedule_single_event(time() + 2, 'process_siigo_sync_batch', [$page + 1, $total_pages]);
            } else {
                update_option('siigo_sync_progress', 100);
                error_log("Sincronización completada asíncronamente");
            }

            unset($products);
        } else {
            error_log("Error procesando página $page");
        }
    }

    public function create_tax_class_and_rate($tax_type, $percentage, $country = 'CO')
    {
        $tax_class_slug = sanitize_title($tax_type);
        $tax_class_name = strtoupper($tax_type);

        $tax_classes = WC_Tax::get_tax_classes();
        if (!in_array($tax_class_name, $tax_classes)) {
            $result = WC_Tax::create_tax_class($tax_class_name, $tax_class_slug);
            if (is_wp_error($result)) {
                error_log("Error al crear clase de impuesto '$tax_class_name': " . $result->get_error_message());
                return false;
            }
            error_log("Clase de impuesto creada: $tax_class_name ($tax_class_slug)");
        }

        $existing_rates = WC_Tax::get_rates_for_tax_class($tax_class_slug);
        $rate_exists = false;
        foreach ($existing_rates as $rate) {
            if ($rate->tax_rate_country === $country && abs($rate->tax_rate - $percentage) < 0.01) {
                $rate_exists = true;
                break;
            }
        }

        if (!$rate_exists) {
            $rate_args = [
                'tax_rate_country' => $country,
                'tax_rate_state' => '',
                'tax_rate' => $percentage,
                'tax_rate_name' => "$tax_class_name $percentage%",
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_class' => $tax_class_slug,
            ];
            $rate_id = WC_Tax::_insert_tax_rate($rate_args);
            if ($rate_id) {
                error_log("Tasa de impuesto creada: $tax_class_name $percentage% para $country");
            } else {
                error_log("Error al crear tasa de impuesto para $tax_class_name $percentage%");
                return false;
            }
        }

        return $tax_class_slug;
    }


    public function update_woocommerce_products($products)
    {
        if (!$products || !is_array($products)) {
            error_log("No se encontraron productos para actualizar");
            return;
        }
        $created = 0;
        $updated = 0;

        foreach ($products as $siigo_product) {
            $product_id = wc_get_product_id_by_sku($siigo_product['id']);
            $product = null;

            if ($product_id) {
                $product = wc_get_product($product_id);
                error_log("Message: Producto actualizado con SKU " . $siigo_product["id"]);
                ++$updated;
            } else {
                $product = new WC_Product_Simple();
                $product->set_sku($siigo_product['id']);
                error_log("Message: Producto creado con SKU " . $siigo_product["id"]);
                ++$created;
            }

            // Establecer propiedades del producto
            if (isset($siigo_product['prices'][0]['price_list'][1]['value'])) {
                $product->set_regular_price($siigo_product['prices'][0]['price_list'][1]['value']);
            } else {
                error_log("Advertencia: No se encontró precio para el producto con SKU " . $siigo_product['id']);
                $product->set_regular_price(0);
            }
            
            $product->set_description($siigo_product['description'] ?? '');
            $product->set_manage_stock(true);
            $product->set_status('publish');

            // Manejo de la marca
            if (!empty($siigo_product['reference'])) {
                $brand_name = sanitize_text_field($siigo_product['reference']);
                $term = term_exists($brand_name, 'product_brand');
                if (!$term) {
                    $term = wp_insert_term($brand_name, 'product_brand');
                    if (!is_wp_error($term)) {
                        error_log("Marca creada: $brand_name");
                    } else {
                        error_log("Error al crear marca $brand_name: " . $term->get_error_message());
                    }
                }
                if ($term && !is_wp_error($term)) {
                    wp_set_object_terms($product->get_id(), (int) $term['term_id'], 'product_brand', true);
                }
            }

            // Manejo de impuestos
            $tax_class = '';
            if (!empty($siigo_product['taxes']) && is_array($siigo_product['taxes'])) {
                $iva_tax = null;
                $other_taxes = [];

                foreach ($siigo_product['taxes'] as $tax) {
                    if ($tax['type'] === 'IVA') {
                        $iva_tax = $tax;
                    } else {
                        $other_taxes[] = $tax;
                    }
                }

                if ($iva_tax) {
                    $tax_type = $iva_tax['type'];
                    $percentage = floatval($iva_tax['percentage']);
                    $tax_class = $this->create_tax_class_and_rate($tax_type, $percentage);
                    if ($tax_class) {
                        $product->set_tax_class($tax_class);
                        error_log("Asignada clase de impuesto '$tax_class' al producto con ID {$siigo_product['id']}");
                    }
                }

                if (!empty($other_taxes)) {
                    foreach ($other_taxes as $other_tax) {
                        error_log("Impuesto adicional detectado para ID {$siigo_product['id']}: {$other_tax['type']} {$other_tax['percentage']}% (no asignado)");
                    }
                }
            }

            if (!$tax_class) {
                $product->set_tax_class('standard');
                error_log("No se encontraron impuestos para el producto con ID {$siigo_product['id']}, usando clase estándar");
            }

              // ==========================
        // Categoría según account_group de Siigo
        // ==========================
        if (!empty($siigo_product['account_group']) && !empty($siigo_product['account_group']['name'])) {

            $category_name = sanitize_text_field($siigo_product['account_group']['name']);
            $category_slug = sanitize_title($category_name);

            // Verificar si existe categoría
            $category = term_exists($category_name, 'product_cat');

            // Crear si no existe
            if (!$category) {
                $category = wp_insert_term(
                    $category_name,
                    'product_cat',
                    ['slug' => $category_slug]
                );

                if (!is_wp_error($category)) {
                    error_log("Categoría creada: $category_name");
                } else {
                    error_log("Error al crear categoría $category_name: " . $category->get_error_message());
                }
            }

            // Asignar categoría al producto
            if ($category && !is_wp_error($category)) {
                wp_set_object_terms(
                    $product->get_id(),
                    [(int)$category['term_id']],
                    'product_cat',
                    false // Reemplaza categorías existentes
                );

                error_log("Categoría asignada a SKU {$siigo_product['id']}: $category_name");
            }
        }

            $product->save();
        }
        return ["updated" => $updated, "created" => $created];
    }
}