<?php
/**
 * The core plugin class orchestrator.
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse {

    protected static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->set_locale();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-auth.php';
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-admin.php';
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-metabox.php';
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-rest.php';
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-dashboard-widget.php';
        require_once MARKETPULSE_PLUGIN_DIR . 'includes/class-marketpulse-ab-testing.php';
    }

    private function set_locale() {
        add_action('plugins_loaded', function () {
            load_plugin_textdomain(
                'marketpulse-for-woocommerce',
                false,
                dirname(dirname(plugin_basename(__FILE__))) . '/languages/'
            );
        });
    }

    private function init_hooks() {
        // Defer WooCommerce-dependent component registration until all plugins are loaded
        add_action('plugins_loaded', array($this, 'init_components'), 20);

        // Initialize REST API endpoints (registers rest_api_init action)
        new MarketPulse_REST();
    }

    public function init_components() {
        // Initialize admin panels
        if (is_admin()) {
            new MarketPulse_Admin();
            if (class_exists('WooCommerce')) {
                new MarketPulse_Product_MetaBox();
                new MarketPulse_Dashboard_Widget();
            }

            // Enqueue admin assets
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }

        // Frontend Storefront Trust Badge and A/B Testing Engine
        if (class_exists('WooCommerce')) {
            new MarketPulse_AB_Testing();
            add_action('woocommerce_single_product_summary', array($this, 'render_storefront_trust_badge'), 15);
        }
    }

    public function enqueue_admin_assets($hook) {
        // Only load on WooCommerce/Product pages and MarketPulse settings
        $screen = get_current_screen();
        if (!$screen) return;

        $is_product_edit = ($screen->post_type === 'product') || in_array($screen->id, array('product', 'edit-product'), true);
        $is_mp_settings = strpos($screen->id, 'marketpulse') !== false;

        if ($is_product_edit || $is_mp_settings) {
            wp_enqueue_media();
            wp_enqueue_style(
                'marketpulse-admin-css',
                MARKETPULSE_PLUGIN_URL . 'assets/css/marketpulse-admin.css',
                array(),
                MARKETPULSE_VERSION
            );
            wp_enqueue_script(
                'marketpulse-admin-js',
                MARKETPULSE_PLUGIN_URL . 'assets/js/marketpulse-admin.js',
                array('jquery'),
                MARKETPULSE_VERSION,
                true
            );
            wp_localize_script('marketpulse-admin-js', 'marketpulseData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('marketpulse_admin_nonce'),
            ));
        }
    }

    public function render_storefront_trust_badge() {
        $alert_settings = MarketPulse_Auth::get_alert_settings();
        if (empty($alert_settings['trustBadgeEnabled'])) {
            return;
        }

        echo '<div class="marketpulse-trust-badge" style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px;margin:8px 0;">'
           . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>'
           . esc_html__('Market Best Price Guaranteed â€¢ Verified by MarketPulse', 'marketpulse-for-woocommerce')
           . '</div>';
    }
}
