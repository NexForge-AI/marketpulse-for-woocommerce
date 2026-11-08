<?php
/**
 * MarketPulse Uninstall Script
 *
 * Triggered when the user deletes the plugin in WordPress admin.
 * Cleans up options and transients.
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clean up stored plugin options
delete_option('marketpulse_paired');
delete_option('marketpulse_api_key');
delete_option('marketpulse_api_secret');
delete_option('marketpulse_company_id');
delete_option('marketpulse_store_connection_id');
delete_option('marketpulse_plan_name');
delete_option('marketpulse_trial_expires_at');
delete_option('marketpulse_last_status_check');

// Clean up cached transients
delete_transient('marketpulse_license_status');
delete_transient('marketpulse_products_summary');
