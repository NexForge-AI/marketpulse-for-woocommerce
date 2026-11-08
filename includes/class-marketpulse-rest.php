<?php
/**
 * REST API Endpoint for Cache-Busting Repricing Updates & A/B Testing Sync
 *
 * Route: POST /wp-json/marketpulse/v1/update-price
 * Route: POST /wp-json/marketpulse/v1/ab-experiments/sync
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_REST {

    const REST_NAMESPACE = 'marketpulse/v1';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/update-price',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_price_update'),
                'permission_callback' => array($this, 'validate_hmac_permission'),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/ab-experiments/sync',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'handle_ab_experiments_sync'),
                'permission_callback' => array($this, 'validate_hmac_permission'),
            )
        );
    }

    /**
     * Cryptographic timing-safe HMAC validation.
     */
    public function validate_hmac_permission(WP_REST_Request $request) {
        $secret = MarketPulse_Auth::get_api_secret();
        if (empty($secret)) {
            return new WP_Error('not_configured', 'MarketPulse plugin is not paired.', array('status' => 401));
        }

        $api_key   = $request->get_header('x_marketpulse_key') ?: $request->get_header('x-marketpulse-key');
        $signature = $request->get_header('x_marketpulse_signature') ?: $request->get_header('x-marketpulse-signature');
        $timestamp = $request->get_header('x_marketpulse_timestamp') ?: $request->get_header('x-marketpulse-timestamp');

        if (empty($api_key) || empty($signature) || empty($timestamp)) {
            return new WP_Error('missing_auth_headers', 'Required HMAC authentication headers missing.', array('status' => 401));
        }

        // Check key matches
        if (!hash_equals(MarketPulse_Auth::get_api_key(), $api_key)) {
            return new WP_Error('invalid_key', 'Invalid MarketPulse API Key.', array('status' => 403));
        }

        // Verify timestamp freshness (5 minutes tolerance)
        $tolerance = 300;
        $req_time  = (int) $timestamp;
        if ($req_time > 1e11) {
            $req_time = (int) round($req_time / 1000);
        }
        if (abs(time() - $req_time) > $tolerance) {
            return new WP_Error('expired_timestamp', 'Request timestamp is outside the valid window.', array('status' => 401));
        }

        // Validate HMAC signature
        $raw_body = $request->get_body();
        $message  = $timestamp . '.' . $raw_body;
        $expected = hash_hmac('sha256', $message, $secret);

        if (!hash_equals($expected, $signature)) {
            return new WP_Error('invalid_signature', 'HMAC signature verification failed.', array('status' => 403));
        }

        return true;
    }

    /**
     * Direct Cache-Busting Reprice Handler.
     */
    public function handle_price_update(WP_REST_Request $request) {
        $params     = $request->get_json_params();
        $product_id = isset($params['productId']) ? absint($params['productId']) : 0;
        $sku        = isset($params['sku']) ? sanitize_text_field($params['sku']) : '';
        $new_price  = isset($params['newPrice']) ? (float) $params['newPrice'] : null;

        if ($new_price === null || $new_price <= 0) {
            return new WP_Error('invalid_price', 'A valid positive newPrice is required.', array('status' => 400));
        }

        $product = null;
        if ($product_id > 0) {
            $product = wc_get_product($product_id);
        }

        if (!$product && !empty($sku)) {
            $found_id = wc_get_product_id_by_sku($sku);
            if ($found_id > 0) {
                $product = wc_get_product($found_id);
            }
        }

        if (!$product) {
            return new WP_Error('product_not_found', 'Product matching the supplied ID or SKU was not found.', array('status' => 404));
        }

        $old_price = $product->get_regular_price();
        $target_id = $product->get_id();

        // 1. Update WooCommerce Price in database
        $product->set_regular_price($new_price);
        $product->set_price($new_price);
        $product->save();

        // 2. Clear WooCommerce internal transients
        wc_delete_product_transients($target_id);
        if ($product->is_type('variation')) {
            wc_delete_product_transients($product->get_parent_id());
        }

        // 3. Cache-Busting Integration: Purge WP Rocket
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($target_id);
            if ($product->is_type('variation')) {
                rocket_clean_post($product->get_parent_id());
            }
        }

        // 4. Cache-Busting Integration: Purge W3 Total Cache
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($target_id);
            if ($product->is_type('variation')) {
                w3tc_flush_post($product->get_parent_id());
            }
        }

        // Invalidate cached product summary so table badges reflect new price immediately
        delete_transient(MarketPulse_Auth::PRODUCTS_SUMMARY_TRANSIENT_KEY);

        return rest_ensure_response(array(
            'success'      => true,
            'productId'    => $target_id,
            'oldPrice'     => (float) $old_price,
            'newPrice'     => (float) $new_price,
            'cachePurged'  => true,
            'timestamp'    => time(),
        ));
    }

    /**
     * Synchronizes active A/B test experiments pushed from MarketPulse SaaS.
     * Updates option and keeps product postmeta in 100% two-way sync for admin metabox & storefront.
     */
    public function handle_ab_experiments_sync(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $experiments = isset($params['experiments']) && is_array($params['experiments']) ? $params['experiments'] : array();

        // 1. Update cloud experiments option
        update_option(MarketPulse_AB_Testing::EXPERIMENTS_OPTION, $experiments);

        $active_product_ids = array();

        // 2. Sync each running experiment to WooCommerce product post meta
        foreach ($experiments as $exp) {
            if (!isset($exp['status']) || $exp['status'] !== 'RUNNING') {
                continue;
            }

            $product_id = 0;
            if (!empty($exp['productId']) && is_numeric($exp['productId'])) {
                $product_id = (int)$exp['productId'];
            } elseif (!empty($exp['sku'])) {
                if (function_exists('wc_get_product_id_by_sku')) {
                    $product_id = wc_get_product_id_by_sku($exp['sku']);
                }
                if (!$product_id && preg_match('/(?:wc-)?(\d+)/i', $exp['sku'], $matches)) {
                    $product_id = (int)$matches[1];
                }
            }

            if (!$product_id || get_post_type($product_id) !== 'product') {
                continue;
            }

            $active_product_ids[] = $product_id;

            $cfg = isset($exp['testVariant']['config']) && is_array($exp['testVariant']['config']) ? $exp['testVariant']['config'] : array();

            update_post_meta($product_id, '_marketpulse_ab_enabled', 'yes');
            if (!empty($exp['name'])) {
                update_post_meta($product_id, '_marketpulse_ab_name', sanitize_text_field($exp['name']));
            }
            if (!empty($exp['type'])) {
                update_post_meta($product_id, '_marketpulse_ab_type', sanitize_text_field($exp['type']));
            }

            // Price test configuration
            if (isset($cfg['price']) && is_numeric($cfg['price'])) {
                update_post_meta($product_id, '_marketpulse_ab_variant_b_price', (float)$cfg['price']);
            }
            // Title test configuration
            if (isset($cfg['title'])) {
                update_post_meta($product_id, '_marketpulse_ab_variant_b_title', sanitize_text_field($cfg['title']));
            }
            // Hero image test configuration
            if (isset($cfg['imageUrl'])) {
                update_post_meta($product_id, '_marketpulse_ab_variant_b_image', esc_url_raw($cfg['imageUrl']));
            }
            // CTA button test configuration
            if (isset($cfg['buttonText'])) {
                update_post_meta($product_id, '_marketpulse_ab_variant_b_btn', sanitize_text_field($cfg['buttonText']));
            }
            if (isset($cfg['color'])) {
                update_post_meta($product_id, '_marketpulse_ab_variant_b_color', sanitize_hex_color($cfg['color']) ?: '#10B981');
            }

            // Traffic allocation & Audience targeting
            $allocation = isset($cfg['trafficAllocation']) ? (int)$cfg['trafficAllocation'] : (isset($exp['trafficAllocation']) ? (int)$exp['trafficAllocation'] : 100);
            update_post_meta($product_id, '_marketpulse_ab_traffic_allocation', ($allocation > 0 && $allocation <= 100) ? $allocation : 100);

            $targeting = isset($cfg['targeting']) && is_array($cfg['targeting']) ? $cfg['targeting'] : (isset($exp['targeting']) && is_array($exp['targeting']) ? $exp['targeting'] : array());
            if (!empty($targeting['device'])) {
                update_post_meta($product_id, '_marketpulse_ab_target_device', sanitize_text_field($targeting['device']));
            }
            if (!empty($targeting['trafficSource'])) {
                update_post_meta($product_id, '_marketpulse_ab_target_source', sanitize_text_field($targeting['trafficSource']));
            }
            if (!empty($targeting['userType'])) {
                update_post_meta($product_id, '_marketpulse_ab_target_user_type', sanitize_text_field($targeting['userType']));
            }
        }

        // 3. Deactivate any products whose experiments have been stopped, paused, or deleted
        $previous_active_ids = get_option('marketpulse_active_ab_product_ids', array());
        if (is_array($previous_active_ids)) {
            foreach ($previous_active_ids as $prev_id) {
                if (!in_array($prev_id, $active_product_ids, true)) {
                    update_post_meta($prev_id, '_marketpulse_ab_enabled', 'no');
                }
            }
        }
        update_option('marketpulse_active_ab_product_ids', $active_product_ids);

        return rest_ensure_response(array(
            'success'          => true,
            'count'            => count($experiments),
            'activeProductIds' => $active_product_ids,
            'timestamp'        => time(),
        ));
    }
}
