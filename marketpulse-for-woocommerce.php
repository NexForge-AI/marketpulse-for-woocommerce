<?php
/**
 * Plugin Name: MarketPulse for WooCommerce
 * Plugin URI:  https://usemarketpulse.com
 * Description: Automated repricing, competitor price tracking, margin protection, and A/B split-testing for WooCommerce.
 * Version:     1.0.0
 * Author:      NexForge AI
 * Author URI:  https://usemarketpulse.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: marketpulse-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:      9.4
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('MARKETPULSE_VERSION', '1.0.0');
define('MARKETPULSE_PLUGIN_FILE', __FILE__);
define('MARKETPULSE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARKETPULSE_PLUGIN_URL', plugin_dir_url(__FILE__));
if (!defined('MARKETPULSE_API_BASE_URL')) {
    $site_url = function_exists('get_option') ? (string) get_option('siteurl', '') : '';
    $is_local_wp = (strpos($site_url, 'localhost') !== false || strpos($site_url, '.local') !== false || strpos($site_url, '.test') !== false || strpos($site_url, '127.0.0.1') !== false);
    define('MARKETPULSE_API_BASE_URL', $is_local_wp ? 'http://localhost:4000' : 'https://usemarketpulse.com');
}
if (!defined('MARKETPULSE_DASHBOARD_URL')) {
    $is_local = (strpos(MARKETPULSE_API_BASE_URL, 'localhost') !== false || strpos(MARKETPULSE_API_BASE_URL, '127.0.0.1') !== false);
    define('MARKETPULSE_DASHBOARD_URL', $is_local ? 'http://localhost:3001' : 'https://usemarketpulse.com');
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Check WooCommerce dependency upon plugin activation.
 */
function marketpulse_activate() {
    require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-activator.php';
    MarketPulse_Activator::activate();
}
register_activation_hook(__FILE__, 'marketpulse_activate');

/**
 * Clean up plugin resources on deactivation.
 */
function marketpulse_deactivate() {
    require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-deactivator.php';
    MarketPulse_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'marketpulse_deactivate');

/**
 * Bootstraps the MarketPulse core plugin orchestrator.
 */
function marketpulse_init() {
    require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse.php';
    MarketPulse::get_instance();
}
marketpulse_init();
