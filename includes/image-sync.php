<?php
if (!defined('ABSPATH'))
    exit;
$siigo_api = new Siigo_API();
$siigo_product_sync = new Siigo_Product_Sync();

//BUG: Depurar función y verificar que los productos en WP tengan su imagen correspondiente
function save_product_image($image_id, $product_name, $sku)
{
    $url_image = "https://monolithprod.siigo.com/JULIEALEXANDRASANCHEZROMERO/Framework/CuteEditor/DownFile.Aspx?fileid=" . $image_id;
    if (empty($url_image)) {
        return false;
    }

    $response = wp_remote_get($url_image, array('timeout' => 15));
    if (is_wp_error($response)) {
        error_log('Error al descargar la imagen: ' . $response->get_error_message());
        return false;
    }

    $image = wp_remote_retrieve_body($response);
    if (empty($image)) {
        error_log('No se pudo obtener el contenido de la imagen.');
        return false;
    }

    $file_name = sanitize_file_name($sku . '-' . basename($url_image));
    $upload_dir = wp_upload_dir();
    $route = $upload_dir['path'] . '/' . $file_name;

    $result = wp_upload_bits($file_name, null, $image);
    if ($result['error']) {
        error_log('Error al guardar la imagen: ' . $result['error']);
        return false;
    }

    $attachment = [
        'guid' => $upload_dir['url'] . '/' . $image,
        'post_mime_type' => wp_check_filetype($image)['type'],
        'post_title' => $product_name,
        'post_content' => '',
        'post_status' => 'inherit'
    ];

    $attachment_id = wp_insert_attachment($attachment, $result['file']);
    if (is_wp_error($attachment_id)) {
        error_log('Error al añadir la imagen a la biblioteca: ' . $attachment_id->get_error_message());
        return false;
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $resultado['file']);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    return $attachment_id;
}

function update_siigo_product_image($siigo_product)
{
    $product_id = wc_get_product_id_by_sku($siigo_product['productGUID']);
    if (!$product_id) {
        return false;
    }
    ;
    $product = wc_get_product($product_id);
    if (!empty($siigo_product['imagePosition'])) {
        $attachment_id = save_product_image($siigo_product['imagePosition'], $siigo_product['description'], $siigo_product['productGUID']);
        if ($attachment_id) {
            $product->set_image_id($attachment_id);
        }
    }

    $product->save();
    return true;
}

function update_woocommerce_products_images($products)
{
    global $siigo_api;
    if (!$products || !is_array($products)) {
        error_log("No se encontraron productos para actualizar");
        return;
    }
    $created = 0;
    $no_created = 0;

    foreach ($products as $siigo_product) {
        $detail_product = $siigo_api->get_siigo_detail_product($siigo_product['id']);
        $is_created = update_siigo_product_image($detail_product);
        $is_created ? ++$created : ++$no_created;
        sleep(5);
    }
    return ["created" => $created, "no_created" => $no_created];
}

function start_uptade_woocommerce_products_images()
{
    global $siigo_api;
    $response = $siigo_api->get_siigo_products();
    if (!isset($response['results']) || !isset($response['pagination'])) {
        error_log('Error: Respuesta de Siigo inválida en la primera página');
        return false;
    }

    $total_pages = ceil($response['pagination']['total_results'] / 100);
    error_log("Total de páginas a procesar: $total_pages");

    wp_schedule_single_event(time(), 'process_products_images_sync_batch', [1, $total_pages]);
    update_option('siigo_sync_total_pages', $total_pages);
    update_option('siigo_sync_progress', 0);

    return true;
}

add_action('process_products_images_sync_batch', 'process_products_images_batch', 10, 2);
function process_products_images_batch($page, $total_pages)
{
    global $siigo_api;
    $products = $siigo_api->get_siigo_products($page)['results'];
    if ($products) {
        $result = update_woocommerce_products_images($products);
        $percentage = ceil(($page / $total_pages) * 100);
        update_option('siigo_images_sync_progress', $percentage);
        error_log("Página $page/$total_pages procesada: $percentage%, " . $result['created'] . " creados, " . $result['updated'] . " actualizados");

        if ($page < $total_pages) {
            wp_schedule_single_event(time() + 2, 'process_products_images_sync_batch', [$page + 1, $total_pages]);
        } else {
            update_option('siigo_images_sync_progress', 100);
            error_log("Sincronización completada asíncronamente");
        }

        unset($products);
    } else {
        error_log("Error procesando página $page");
    }
}