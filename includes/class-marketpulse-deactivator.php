<?php
/**
 * Fired during plugin deactivation.
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Deactivator {

    /**
     * Clears transients and scheduled hooks on deactivation.
     */
    public static function deactivate() {
        delete_transient('marketpulse_license_status');
        wp_clear_scheduled_hook('marketpulse_daily_status_check');
    }
}
