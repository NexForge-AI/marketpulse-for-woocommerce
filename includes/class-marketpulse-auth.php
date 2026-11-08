<?php
/**
 * Authentication & Pairing Service
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Auth {

    const STATUS_TRANSIENT_KEY           = 'marketpulse_license_status';
    const STATUS_CACHE_TTL               = 86400; // 24 hours in seconds
    const PRODUCTS_SUMMARY_TRANSIENT_KEY = 'marketpulse_products_summary';
    const PRODUCTS_SUMMARY_CACHE_TTL     = 300;   // 5 minutes in seconds
    const GRACE_PERIOD_SECONDS           = 172800; // 48-hour network outage grace period
    const ALERT_SETTINGS_OPTION          = 'marketpulse_alert_settings';

    public static function is_paired() {
        return (bool) get_option('marketpulse_paired', false) && !empty(self::get_api_key());
    }

    public static function get_api_key() {
        return get_option('marketpulse_api_key', '');
    }

    public static function get_api_secret() {
        return get_option('marketpulse_api_secret', '');
    }

    public static function get_company_id() {
        return get_option('marketpulse_company_id', '');
    }

    public static function get_trial_expires_at() {
        return get_option('marketpulse_trial_expires_at', '');
    }

    /**
     * Generate HMAC-SHA256 signature and timestamp for request signing.
     */
    public static function sign_request($payload = '') {
        $secret = self::get_api_secret();
        $timestamp = (string) time();
        $message = $timestamp . '.' . (is_string($payload) ? $payload : wp_json_encode($payload));
        $signature = hash_hmac('sha256', $message, $secret);

        return array(
            'X-MarketPulse-Key'       => self::get_api_key(),
            'X-MarketPulse-Signature' => $signature,
            'X-MarketPulse-Timestamp' => $timestamp,
        );
    }

    /**
     * 1-Click Store Pairing & Starter 14-Day Free Trial Provisioning.
     */
    public static function pair_store() {
        $current_user = wp_get_current_user();
        $admin_email  = $current_user->user_email ?: get_option('admin_email');
        $site_url     = get_site_url();
        $site_name    = get_bloginfo('name');

        $body = array(
            'siteUrl'    => $site_url,
            'adminEmail' => $admin_email,
            'siteName'   => $site_name,
            'adminName'  => $current_user->display_name ?: 'Store Admin',
            'currency'   => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
        );

        $response = wp_remote_post(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/pair',
            array(
                'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('connection_failed', $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || empty($data['apiKey']) || empty($data['apiSecret'])) {
            $msg = !empty($data['message']) ? $data['message'] : 'Could not complete pairing with MarketPulse Cloud.';
            return new WP_Error('pairing_rejected', $msg);
        }

        // Store secure credentials in wp_options (autoload = no for security)
        update_option('marketpulse_paired', true, 'no');
        update_option('marketpulse_api_key', sanitize_text_field($data['apiKey']), 'no');
        update_option('marketpulse_api_secret', sanitize_text_field($data['apiSecret']), 'no');
        update_option('marketpulse_company_id', sanitize_text_field($data['companyId']), 'no');
        update_option('marketpulse_store_connection_id', sanitize_text_field($data['storeConnectionId']), 'no');
        update_option('marketpulse_plan_name', sanitize_text_field($data['planName'] ?? 'Starter'), 'no');
        update_option('marketpulse_trial_expires_at', sanitize_text_field($data['trialExpiresAt']), 'no');
        update_option('marketpulse_last_status_check', time(), 'no');

        // Clear cached status
        delete_transient(self::STATUS_TRANSIENT_KEY);

        return $data;
    }

    /**
     * Check License Status & Trial Lock with 24h transient cache and 48h grace period.
     */
    public static function get_status($force_refresh = false) {
        if (!self::is_paired()) {
            return array(
                'isPaired'      => false,
                'status'        => 'NOT_CONNECTED',
                'isLocked'      => true,
                'daysRemaining' => 0,
            );
        }

        if (!$force_refresh) {
            $cached = get_transient(self::STATUS_TRANSIENT_KEY);
            if (false !== $cached && is_array($cached)) {
                return $cached;
            }
        }

        $headers = self::sign_request('');
        $headers['Accept'] = 'application/json';

        $response = wp_remote_get(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/status',
            array('headers' => $headers, 'timeout' => 8)
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Network outage resilience: Grace Period Protection
            $last_check = (int) get_option('marketpulse_last_status_check', 0);
            $time_since_check = time() - $last_check;

            // If within 48-hour grace period, keep store operational using fallback
            if ($time_since_check < self::GRACE_PERIOD_SECONDS) {
                $expires_str = self::get_trial_expires_at();
                $expires_ts  = $expires_str ? strtotime($expires_str) : 0;
                $days_left   = max(0, ceil(($expires_ts - time()) / 86400));

                return array(
                    'isPaired'      => true,
                    'status'        => ($days_left > 0) ? 'ON_TRIAL' : 'EXPIRED',
                    'planName'      => get_option('marketpulse_plan_name', 'Starter'),
                    'daysRemaining' => $days_left,
                    'isLocked'      => ($days_left === 0),
                    'upgradeUrl'    => MARKETPULSE_DASHBOARD_URL . '/select-plan?ref=wp_plugin',
                    'gracePeriod'   => true,
                );
            }

            return array(
                'isPaired'      => true,
                'status'        => 'CONNECTION_ERROR',
                'isLocked'      => true,
                'daysRemaining' => 0,
                'upgradeUrl'    => MARKETPULSE_DASHBOARD_URL . '/select-plan?ref=wp_plugin',
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['status'])) {
            return array('isPaired' => true, 'isLocked' => false, 'status' => 'ON_TRIAL', 'daysRemaining' => 14);
        }

        $data['isPaired'] = true;
        update_option('marketpulse_last_status_check', time(), 'no');
        set_transient(self::STATUS_TRANSIENT_KEY, $data, self::STATUS_CACHE_TTL);

        return $data;
    }

    /**
     * Fetch products summary map for WooCommerce Products list live badges.
     * Cached in transient for 5 minutes (or refreshed on save / repricing).
     *
     * @param bool $force_refresh
     * @return array
     */
    public static function get_products_summary($force_refresh = false) {
        if (!self::is_paired()) {
            return array();
        }

        if (!$force_refresh) {
            $cached = get_transient(self::PRODUCTS_SUMMARY_TRANSIENT_KEY);
            if (false !== $cached && is_array($cached)) {
                return $cached;
            }
        }

        $headers = self::sign_request('');
        $headers['Accept'] = 'application/json';

        $response = wp_remote_get(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/products-summary',
            array('headers' => $headers, 'timeout' => 8)
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return (false !== ($fallback = get_transient(self::PRODUCTS_SUMMARY_TRANSIENT_KEY))) ? (array) $fallback : array();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return array();
        }

        set_transient(self::PRODUCTS_SUMMARY_TRANSIENT_KEY, $data, self::PRODUCTS_SUMMARY_CACHE_TTL);
        return $data;
    }

    /**
     * Compute real-time ROI and margin protection telemetry from cached products summary.
     *
     * @return array
     */
    public static function get_roi_metrics() {
        $summary = self::get_products_summary();
        $total_tracked = 0;
        $winning = 0;
        $price_drops = 0;
        $opportunities = 0;

        if (is_array($summary)) {
            $seen_product_ids = array();
            foreach ($summary as $item) {
                // Deduplicate items: summaryMap contains entries keyed by UUID, SKU, and ownStoreProductId for O(1) lookup
                $pid = !empty($item['productId']) ? $item['productId'] : (!empty($item['sku']) ? $item['sku'] : null);
                if (!$pid || isset($seen_product_ids[$pid])) {
                    continue;
                }
                $seen_product_ids[$pid] = true;

                if (empty($item['competitorCount'])) {
                    continue;
                }
                $total_tracked += (int) $item['competitorCount'];
                $status = $item['status'] ?? 'MONITORING';
                if ($status === 'WINNING') {
                    $winning++;
                } elseif ($status === 'PRICE_DROP') {
                    $price_drops++;
                } elseif ($status === 'OUT_OF_STOCK') {
                    $opportunities++;
                }
            }
        }

        return array(
            'total_tracked' => $total_tracked,
            'winning'       => $winning,
            'price_drops'   => $price_drops,
            'opportunities' => $opportunities,
        );
    }

    /**
     * Request an ephemeral, single-use SSO token for 1-click passwordless admin login.
     *
     * @return string|WP_Error SSO URL on success, WP_Error on failure.
     */
    public static function get_sso_url() {
        if (!self::is_paired()) {
            return new WP_Error('not_paired', __('Store is not paired with MarketPulse.', 'marketpulse-for-woocommerce'));
        }

        $payload = wp_json_encode(array('timestamp' => time()));
        $auth_headers = self::sign_request($payload);

        $response = wp_remote_post(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/sso-token',
            array(
                'headers' => array_merge(
                    array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                    $auth_headers
                ),
                'body'    => $payload,
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('sso_request_failed', sprintf(__('SSO connection error: %s', 'marketpulse-for-woocommerce'), $response->get_error_message()));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code !== 200 || empty($data['ssoUrl'])) {
            $msg = !empty($data['message']) ? $data['message'] : sprintf(__('Could not generate SSO login token (HTTP %s). Please ensure MarketPulse server is running.', 'marketpulse-for-woocommerce'), $status_code ?: 'Error');
            return new WP_Error('sso_rejected', $msg);
        }

        return $data['ssoUrl'];
    }

    /**
     * Get Mobile Alert Settings (WhatsApp & Telegram).
     *
     * @param bool $force_refresh
     * @return array
     */
    public static function get_alert_settings($force_refresh = false) {
        $defaults = array(
            'whatsappEnabled'   => false,
            'whatsappNumber'    => '',
            'telegramEnabled'   => false,
            'telegramChatId'    => '',
            'alertOnPriceDrop'  => true,
            'alertOnOutOfStock' => true,
        );

        if (!self::is_paired()) {
            return $defaults;
        }

        $local = get_option(self::ALERT_SETTINGS_OPTION, false);
        if (!$force_refresh && is_array($local)) {
            return wp_parse_args($local, $defaults);
        }

        $headers = self::sign_request('');
        $headers['Accept'] = 'application/json';

        $response = wp_remote_get(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/alert-settings',
            array('headers' => $headers, 'timeout' => 8)
        );

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data)) {
                $merged = wp_parse_args($data, $defaults);
                update_option(self::ALERT_SETTINGS_OPTION, $merged, 'no');
                return $merged;
            }
        }

        return is_array($local) ? wp_parse_args($local, $defaults) : $defaults;
    }

    /**
     * Save Mobile Alert Settings to both WordPress options and MarketPulse Cloud.
     *
     * @param array $settings
     * @return array|WP_Error
     */
    public static function save_alert_settings($settings) {
        if (!self::is_paired()) {
            return new WP_Error('not_paired', __('Store is not paired with MarketPulse.', 'marketpulse-for-woocommerce'));
        }

        $clean = array(
            'whatsappEnabled'   => !empty($settings['whatsappEnabled']),
            'whatsappNumber'    => !empty($settings['whatsappNumber']) ? preg_replace('/[^0-9]/', '', (string)$settings['whatsappNumber']) : '',
            'telegramEnabled'   => !empty($settings['telegramEnabled']),
            'telegramChatId'    => !empty($settings['telegramChatId']) ? sanitize_text_field($settings['telegramChatId']) : '',
            'alertOnPriceDrop'  => isset($settings['alertOnPriceDrop']) ? (bool)$settings['alertOnPriceDrop'] : true,
            'alertOnOutOfStock' => isset($settings['alertOnOutOfStock']) ? (bool)$settings['alertOnOutOfStock'] : true,
        );

        // Update local option immediately
        update_option(self::ALERT_SETTINGS_OPTION, $clean, 'no');

        // Sync with MarketPulse backend via HMAC
        $payload = wp_json_encode($clean);
        $auth_headers = self::sign_request($payload);

        $response = wp_remote_post(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/alert-settings',
            array(
                'headers' => array_merge(
                    array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                    $auth_headers
                ),
                'body'    => $payload,
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('sync_failed', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $raw = wp_remote_retrieve_body($response);
            $err = json_decode($raw, true);
            return new WP_Error('sync_error', !empty($err['message']) ? $err['message'] : 'Failed to sync with cloud.');
        }

        return $clean;
    }

    /**
     * Send a test mobile alert (WhatsApp or Telegram) via MarketPulse Cloud.
     *
     * @param string $channel 'WHATSAPP' | 'TELEGRAM'
     * @param string $destination
     * @return array|WP_Error
     */
    public static function send_test_alert($channel, $destination) {
        if (!self::is_paired()) {
            return new WP_Error('not_paired', __('Store is not paired with MarketPulse.', 'marketpulse-for-woocommerce'));
        }

        $channel = strtoupper(sanitize_text_field($channel));
        if (!in_array($channel, array('WHATSAPP', 'TELEGRAM'), true)) {
            return new WP_Error('invalid_channel', __('Invalid alert channel.', 'marketpulse-for-woocommerce'));
        }

        $payload = wp_json_encode(array(
            'channel'     => $channel,
            'destination' => sanitize_text_field($destination),
        ));
        $auth_headers = self::sign_request($payload);

        $response = wp_remote_post(
            MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/test-alert',
            array(
                'headers' => array_merge(
                    array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                    $auth_headers
                ),
                'body'    => $payload,
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error('test_failed', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $msg = !empty($data['message']) ? $data['message'] : 'Test alert dispatch failed.';
            return new WP_Error('test_error', $msg);
        }

        return $data;
    }

    /**
     * Disconnect store (clears paired options).
     */
    public static function disconnect() {
        delete_option('marketpulse_paired');
        delete_option('marketpulse_api_key');
        delete_option('marketpulse_api_secret');
        delete_option('marketpulse_company_id');
        delete_option('marketpulse_store_connection_id');
        delete_option('marketpulse_plan_name');
        delete_option('marketpulse_trial_expires_at');
        delete_option('marketpulse_last_status_check');
        delete_option(self::ALERT_SETTINGS_OPTION);
        delete_transient(self::STATUS_TRANSIENT_KEY);
        delete_transient(self::PRODUCTS_SUMMARY_TRANSIENT_KEY);
    }
}
