<?php
/*
Plugin Name: Siigo WooCommerce Sync
Plugin URI:
Description: Sincronización de productos en Siigo a Wordpress
Version: 1.0
Author: Kingdom Studio MC
Author URI: 
License: GPL2
*/

if (!defined('ABSPATH')) {
    exit;
}

define("API_URL", "https://api.siigo.com/v1/products");
define("SIIGO_USERNAME", "julsan15@hotmail.com");
define("SIIGO_KEY", "OGQwNzEwNmEtNDRmMy00ZjdiLTk1MmYtNGIxYmUxODAwYmNhOmszN2VCNzguckg=");
define("PARTNER_ID", "wordpressEcommerce");
define("PLUGIN_DIR", plugin_dir_path(__FILE__));
define("PLUGIN_URL", plugin_dir_url(__FILE__));


require_once PLUGIN_DIR . "includes/class-siigo-api.php";
require_once PLUGIN_DIR . "includes/class-siigo-product-sync.php";
require_once PLUGIN_DIR . "includes/image-sync.php";
require_once PLUGIN_DIR . "includes/admin-page.php";

function is_woocommerce_enabled()
{
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
}

add_action("admin_enqueue_scripts", "enqueue_assets");
function enqueue_assets($hook)
{
    if ($hook !== 'toplevel_page_sync-products') {
        return;
    }

    wp_enqueue_style("plugin-woo-admin", PLUGIN_URL . "assets/css/admin.css", [], "1.0");
    wp_enqueue_script("plugin-woo-admin", PLUGIN_URL . "assets/js/admin.js", [], "1.0", true);
    wp_localize_script("plugin-woo-admin", "pluginWooAdmin", [
        "ajaxurl" => admin_url("admin-ajax.php"),
        "nonce" => wp_create_nonce("start_siigo_sync_nonce")
    ]);
    wp_localize_script("plugin-woo-admin", "pluginWooImage", [
        "ajaxurl" => admin_url("admin-ajax.php"),
        "nonce" => wp_create_nonce("start_images_sync_nonce")
    ]);
}

if (is_woocommerce_enabled()) {
    require_once PLUGIN_DIR . 'includes/class-woo-siigo-integration.php';
    $woo_siigo_integration = new Woo_Siigo_Integration();
}

register_deactivation_hook(__FILE__, 'deactivate_plugin');
function deactivate_plugin()
{
    wp_clear_scheduled_hook('process_siigo_sync_batch');
    delete_option("siigo_sync_progress");
}
?>