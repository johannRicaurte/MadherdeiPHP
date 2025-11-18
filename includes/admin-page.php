<?php
if (!defined('ABSPATH'))
    exit;

$siigo_product_sync = new Siigo_Product_Sync();

add_action('admin_menu', 'create_menu');
function create_menu()
{
    add_menu_page(
        'Sincronizar productos',
        'Sincronizar productos',
        'manage_options',
        'sync-products',
        'show_sync_products_page',
        'dashicons-admin-tools'
    );
}

function show_sync_products_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'start_siigo_sync' && check_admin_referer('start_siigo_sync_nonce')) {
        global $siigo_product_sync;
        $result = $siigo_product_sync->get_all_siigo_products();
        if ($result) {
            wp_send_json_success('Sincronización iniciada en segundo plano');
        } else {
            wp_send_json_error('Error al iniciar la sincronización con Siigo');
        }
        wp_die();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'start_images_sync' && check_admin_referer('start_images_sync_nonce')) {
        $result = start_uptade_woocommerce_products_images();
        if ($result) {
            wp_send_json_success('Sincronización de imagenes iniciada en segundo plano');
        } else {
            wp_send_json_error('Error al iniciar la sincronización con Siigo');
        }
        wp_die();
    }
    ?>
    <div class="wrap">
        <h1>Sincronizar Productos</h1>
        <p>Haz clic en el botón para sincronizar los productos de WooCommerce con los de Siigo (ejecución asíncrona).</p>
        <button id="start-sync" class="button-primary">Sincronizar Productos</button>
        <button id="start-image-sync" class="button-primary">Sincronizar imagenes Johann</button>
        <div id="sync-progress" style="margin-top: 20px;">
            <p>Productos: <span id="progress-percentage">0%</span></p>
            <div id="progress-bar" style="width: 0%; height: 20px; background: #0073aa; transition: width 0.5s;"></div>
            <p>Imagenes: <span id="progress-image-percentage">0%</span></p>
            <div id="progress-image-bar" style="width: 0%; height: 20px; background: #0073aa; transition: width 0.5s;">
            </div>
        </div>
        <div id="sync-result"></div>
    </div>
    <?php
}

add_action('wp_ajax_get_siigo_sync_progress', 'get_siigo_sync_progress');
function get_siigo_sync_progress()
{
    $progress = get_option('siigo_sync_progress', 0);
    $progress_image = get_option('siigo_images_sync_progress', 0);
    wp_send_json_success([
        "products" => $progress,
        "images" => $progress_image
    ]);
    wp_die();
}

add_action('wp_ajax_start_siigo_sync', 'show_sync_products_page');