<?php
/**
 * Fired during plugin activation.
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Activator {

    /**
     * Checks prerequisites and initializes default options on activation.
     */
    public static function activate() {
        // Initialize default options
        if (false === get_option('marketpulse_paired')) {
            add_option('marketpulse_paired', false, '', 'no');
        }
    }
}
