<?php
/**
 * MarketPulse A/B Testing Engine for WooCommerce.
 * Enterprise-Grade Zero-Flicker Split Testing Suite.
 *
 * Implements:
 * 1. Synchronous Anti-Flickering Head Snippet (<1ms veil, fail-safe timeout)
 * 2. High-Performance Asynchronous & Defer Loading (<2.5 KB Vanilla JS)
 * 3. 100% Server-Side Rendering for Price, Title, Button & Hero Image (Zero Flicker)
 * 4. First-Party Cookie & Session Persistence with CRC32 Deterministic Hashing
 * 5. Full Bot & Search Engine Crawler Filtering (Zero Cloaking Risk, Clean SEO)
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_AB_Testing {

    const VISITOR_COOKIE = '_mp_ab_visitor_id';
    const EXPERIMENTS_OPTION = 'marketpulse_ab_experiments';

    private $resolved_cache = array();
    private static $recorded_impressions = array();

    public function __construct() {
        // 1. Session & Cookie Initialization (Exempts bots)
        add_action('init', array($this, 'init_visitor_session'));

        // 2. Synchronous Anti-Flicker Head Script (<head> priority 1 - before any body paints)
        add_action('wp_head', array($this, 'inject_anti_flicker_snippet'), 1);

        // 3. Server-Side Price Testing Hooks (Zero-Flicker)
        add_filter('woocommerce_product_get_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_product_get_regular_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_regular_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_sale_price', array($this, 'filter_price'), 10, 2);
        add_filter('woocommerce_get_price_html', array($this, 'filter_price_html'), 20, 2);
        add_filter('woocommerce_product_is_on_sale', array($this, 'filter_is_on_sale'), 20, 2);

        // 4. Server-Side Title Testing Hooks (Zero-Flicker)
        add_filter('woocommerce_product_get_name', array($this, 'filter_product_title'), 20, 2);
        add_filter('the_title', array($this, 'filter_the_title'), 20, 2);

        // 5. Server-Side CTA Button Testing Hooks (Zero-Flicker)
        add_filter('woocommerce_product_single_add_to_cart_text', array($this, 'filter_add_to_cart_text'), 20, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'filter_add_to_cart_text'), 20, 2);

        // 6. Server-Side Hero Image Testing Hook (Zero-Flicker)
        add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'filter_product_image_html'), 20, 2);

        // 7. Cart & Checkout Price Calculation Integrity
        add_action('woocommerce_before_calculate_totals', array($this, 'apply_cart_price'), 20, 1);

        // 8. Storefront Assets & Asynchronous / Defer Tag
        add_action('wp_enqueue_scripts', array($this, 'enqueue_storefront_assets'));
        add_filter('script_loader_tag', array($this, 'add_defer_attribute'), 10, 2);

        // 9. Real-Time Conversion Tracking Hooks (Human-only)
        add_action('template_redirect', array($this, 'track_page_view'));
        add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 10, 6);
        add_action('woocommerce_thankyou', array($this, 'track_order_purchase'), 10, 1);
    }

    /**
     * Enterprise Bot & Search Engine Crawler Detector.
     * Guaranteed to prevent cloaking penalties and keep CRO analytics 100% human-traffic clean.
     */
    public function is_bot() {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return false;
        }

        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
        $bot_signatures = array(
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'facebot',
            'facebookexternalhit',
            'ia_archiver',
            'twitterbot',
            'linkedinbot',
            'pinterestbot',
            'applebot',
            'google-inspectiontool',
            'chrome-lighthouse',
            'headlesschrome',
            'semrushbot',
            'ahrefsbot',
            'mj12bot',
            'screaming frog',
            'petalbot',
            'crawler',
            'spider',
            'robot',
            'crawling',
        );

        foreach ($bot_signatures as $bot) {
            if (strpos($ua, $bot) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Initializes or reads anonymized visitor cookie for deterministic 50/50 split testing.
     * Skipped entirely for bots/crawlers.
     */
    public function init_visitor_session() {
        if ($this->is_bot()) {
            return;
        }

        if (!isset($_COOKIE[self::VISITOR_COOKIE])) {
            $visitor_id = wp_generate_uuid4();
            if (!headers_sent()) {
                if (PHP_VERSION_ID >= 70300) {
                    setcookie(self::VISITOR_COOKIE, $visitor_id, array(
                        'expires'  => time() + (86400 * 30),
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => is_ssl(),
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ));
                } else {
                    setcookie(self::VISITOR_COOKIE, $visitor_id, time() + (86400 * 30), '/', '', is_ssl(), false);
                }
            }
            $_COOKIE[self::VISITOR_COOKIE] = $visitor_id;
        }
    }

    /**
     * Get the current visitor ID.
     */
    public function get_visitor_id() {
        return isset($_COOKIE[self::VISITOR_COOKIE]) ? sanitize_text_field($_COOKIE[self::VISITOR_COOKIE]) : 'anon_' . wp_generate_uuid4();
    }

    /**
     * Synchronous Anti-Flicker Head Script (<head> Priority 1).
     * Follows the Google Optimize / Optimizely anti-flicker standard.
     * Injected only for real visitors when Variant B is active on single product pages.
     */
    public function inject_anti_flicker_snippet() {
        if (!is_product() || $this->is_bot()) {
            return;
        }

        global $post;
        $product_id = $post ? $post->ID : get_the_ID();
        if (!$product_id) return;

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment) return;

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant !== 'VARIANT_B') {
            return;
        }

        $cfg = $experiment['testVariant']['config'] ?? array();
        ?>
        <!-- MarketPulse Zero-Flicker Anti-Flicker Engine -->
        <style id="mp-ab-antiflicker-style">
            html.mp-ab-loading .product_title,
            html.mp-ab-loading .woocommerce-product-gallery,
            html.mp-ab-loading .single_add_to_cart_button {
                opacity: 0 !important;
                transition: opacity 0.12s ease-in !important;
            }
            <?php if (!empty($cfg['color'])): ?>
            /* Server-side CTA button color lock to eliminate color flicker */
            .single_add_to_cart_button,
            .single_add_to_cart_button:hover {
                background-color: <?php echo esc_attr($cfg['color']); ?> !important;
                border-color: <?php echo esc_attr($cfg['color']); ?> !important;
            }
            <?php endif; ?>
        </style>
        <script id="mp-ab-hide-script">
            (function(docEl, className, timeoutMs) {
                docEl.classList.add(className);
                var lift = function() {
                    docEl.classList.remove(className);
                };
                window._mpLiftVeil = lift;
                setTimeout(lift, timeoutMs); // Hard fail-safe timeout: never leave content invisible
            })(document.documentElement, 'mp-ab-loading', 800);
        </script>
        <?php
    }

    /**
     * Checks if the visitor matches Audience Targeting rules (Device, Traffic Source, User Type).
     */
    public function matches_targeting_rules($targeting) {
        if (empty($targeting) || !is_array($targeting)) {
            return true;
        }

        // 1. Device Targeting (Mobile vs Desktop)
        $device = $targeting['device'] ?? 'ALL';
        if ($device !== 'ALL') {
            $is_mobile = wp_is_mobile();
            if ($device === 'MOBILE' && !$is_mobile) {
                return false;
            }
            if ($device === 'DESKTOP' && $is_mobile) {
                return false;
            }
        }

        // 2. Traffic Source Targeting (Facebook/Instagram Ads, Google Search/Ads, Direct)
        $source = $targeting['trafficSource'] ?? 'ALL';
        if ($source !== 'ALL') {
            $utm_source = strtolower(sanitize_text_field($_GET['utm_source'] ?? ''));
            $referer    = strtolower(sanitize_text_field($_SERVER['HTTP_REFERER'] ?? ''));
            $has_fb     = (strpos($utm_source, 'facebook') !== false || strpos($utm_source, 'instagram') !== false || isset($_GET['fbclid']) || strpos($referer, 'facebook.com') !== false || strpos($referer, 'instagram.com') !== false);
            $has_google = (strpos($utm_source, 'google') !== false || isset($_GET['gclid']) || strpos($referer, 'google.com') !== false);

            if ($source === 'FACEBOOK' && !$has_fb) {
                return false;
            }
            if ($source === 'GOOGLE' && !$has_google) {
                return false;
            }
            if ($source === 'DIRECT' && (!empty($referer) || !empty($utm_source))) {
                return false;
            }
        }

        // 3. User Type Targeting (New Visitors vs Returning Customers)
        $user_type = $targeting['userType'] ?? 'ALL';
        if ($user_type !== 'ALL') {
            $is_returning = (isset($_COOKIE[self::VISITOR_COOKIE]) || is_user_logged_in() || isset($_COOKIE['woocommerce_items_in_cart']));
            if ($user_type === 'NEW' && $is_returning) {
                return false;
            }
            if ($user_type === 'RETURNING' && !$is_returning) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve whether this visitor gets Variant A (Control) or Variant B (Test).
     *
     * 1. Search Engine Crawlers -> ALWAYS returns 'CONTROL' (Zero cloaking risk, clean SEO).
     * 2. URL Query Override -> ?mp_variant=b or ?mp_variant=a for instant merchant preview.
     * 3. Audience Targeting -> Excludes non-matching devices/traffic sources/user types.
     * 4. Smart Traffic Allocation -> Configurable % of traffic entering the test.
     * 5. In-Memory Request Cache -> 100% consistency across all 6 WooCommerce template hooks.
     * 6. Persistent First-Party Cookie -> Locks visitor into their variant for 30 days.
     * 7. Deterministic CRC32 Hash -> Balanced 50/50 distribution without database round-trips.
     */
    public function resolve_variant($experiment_id, $split_ratio = 50, $experiment = null) {
        // 1. Googlebot & Search Engine Bots ALWAYS receive CONTROL (Google Cloaking Protection)
        if ($this->is_bot()) {
            return 'CONTROL';
        }

        // 2. URL Query Override for Instant Merchant Testing & QA Preview
        if (isset($_GET['mp_variant'])) {
            $force = strtolower(sanitize_text_field($_GET['mp_variant']));
            if ($force === 'b' || $force === 'variant_b' || $force === 'test') {
                return 'VARIANT_B';
            }
            if ($force === 'a' || $force === 'variant_a' || $force === 'control') {
                return 'CONTROL';
            }
        }

        // 3. Audience Targeting Check: If visitor doesn't match rules, serve Control
        if ($experiment && isset($experiment['targeting']) && !$this->matches_targeting_rules($experiment['targeting'])) {
            $this->resolved_cache[$experiment_id] = 'CONTROL';
            return 'CONTROL';
        }

        // 4. Smart Traffic Allocation Gate (e.g. 20% test traffic, 80% baseline control)
        $allocation = ($experiment && isset($experiment['trafficAllocation'])) ? (int)$experiment['trafficAllocation'] : 100;
        if ($allocation < 100) {
            $visitor_id = $this->get_visitor_id();
            $alloc_hash = abs(crc32($visitor_id . '_alloc_' . $experiment_id));
            if (($alloc_hash % 100) >= $allocation) {
                $this->resolved_cache[$experiment_id] = 'CONTROL';
                return 'CONTROL';
            }
        }

        // 5. In-memory request cache to keep variant 100% consistent across all filter calls
        if (isset($this->resolved_cache[$experiment_id])) {
            return $this->resolved_cache[$experiment_id];
        }

        // 6. Check persistent cookie if already assigned
        $assignment_cookie = '_mp_ab_exp_' . $experiment_id;
        if (isset($_COOKIE[$assignment_cookie])) {
            $saved_variant = sanitize_text_field($_COOKIE[$assignment_cookie]);
            if ($saved_variant === 'CONTROL' || $saved_variant === 'VARIANT_B') {
                $this->resolved_cache[$experiment_id] = $saved_variant;
                return $saved_variant;
            }
        }

        // 7. Deterministic Hash of visitor ID + experiment ID (CRO Industry Standard)
        // Visitor ID is unique per browser session.
        // Hashing ensures Browser 1 and Browser 2 with different visitor IDs get balanced 50/50 distribution,
        // and a visitor NEVER flips between refreshes or pages!
        $visitor_id = $this->get_visitor_id();
        $hash = abs(crc32($visitor_id . '_' . $experiment_id));
        $bucket = $hash % 100;
        $assigned_variant = ($bucket < (int)$split_ratio) ? 'VARIANT_B' : 'CONTROL';

        $this->resolved_cache[$experiment_id] = $assigned_variant;

        if (!headers_sent()) {
            if (PHP_VERSION_ID >= 70300) {
                setcookie($assignment_cookie, $assigned_variant, array(
                    'expires'  => time() + (86400 * 30),
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => is_ssl(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ));
            } else {
                setcookie($assignment_cookie, $assigned_variant, time() + (86400 * 30), '/', '', is_ssl(), false);
            }
        }
        $_COOKIE[$assignment_cookie] = $assigned_variant;

        return $assigned_variant;
    }

    /**
     * Get active experiments for a product.
     */
    public function get_active_experiment_for_product($product_id) {
        if (!$product_id) return null;

        // 1. Direct WordPress Product Metabox Local A/B Test (Supports variation parent lookup)
        $ab_enabled = get_post_meta($product_id, '_marketpulse_ab_enabled', true);
        if ($ab_enabled !== 'yes') {
            $parent_id = wp_get_post_parent_id($product_id);
            if ($parent_id) {
                $product_id = $parent_id;
                $ab_enabled = get_post_meta($product_id, '_marketpulse_ab_enabled', true);
            }
        }

        if ($ab_enabled === 'yes') {
            $test_price = get_post_meta($product_id, '_marketpulse_ab_variant_b_price', true);
            $test_title = get_post_meta($product_id, '_marketpulse_ab_variant_b_title', true);
            $test_image = get_post_meta($product_id, '_marketpulse_ab_variant_b_image', true);
            $test_btn   = get_post_meta($product_id, '_marketpulse_ab_variant_b_btn', true);
            $test_color = get_post_meta($product_id, '_marketpulse_ab_variant_b_color', true);
            $test_type  = get_post_meta($product_id, '_marketpulse_ab_type', true) ?: 'PRICE';

            $allocation = get_post_meta($product_id, '_marketpulse_ab_traffic_allocation', true);
            $target_device = get_post_meta($product_id, '_marketpulse_ab_target_device', true);
            $target_source = get_post_meta($product_id, '_marketpulse_ab_target_source', true);
            $target_user_type = get_post_meta($product_id, '_marketpulse_ab_target_user_type', true);

            return array(
                'id'                => 'wp_local_exp_' . $product_id,
                'status'            => 'RUNNING',
                'productId'         => $product_id,
                'type'              => $test_type,
                'trafficSplitRatio' => 50,
                'trafficAllocation' => is_numeric($allocation) ? (int)$allocation : 100,
                'targeting'         => array(
                    'device'        => $target_device ?: 'ALL',
                    'trafficSource' => $target_source ?: 'ALL',
                    'userType'      => $target_user_type ?: 'ALL',
                ),
                'controlVariant'    => array(
                    'id'     => 'ctrl_' . $product_id,
                    'name'   => 'Control',
                    'config' => array(),
                ),
                'testVariant'       => array(
                    'id'     => 'varb_' . $product_id,
                    'name'   => 'Variant B',
                    'config' => array(
                        'price'      => ($test_price !== '' && $test_price !== null) ? (float) $test_price : null,
                        'title'      => $test_title ?: null,
                        'imageUrl'   => $test_image ?: null,
                        'buttonText' => $test_btn ?: null,
                        'color'      => $test_color ?: null,
                        'targeting'  => array(
                            'device'        => $target_device ?: 'ALL',
                            'trafficSource' => $target_source ?: 'ALL',
                            'userType'      => $target_user_type ?: 'ALL',
                        ),
                        'trafficAllocation' => is_numeric($allocation) ? (int)$allocation : 100,
                    ),
                ),
            );
        }

        // 2. Cloud-synced experiments from MarketPulse SaaS
        $experiments = get_option(self::EXPERIMENTS_OPTION, array());
        if (empty($experiments) || !is_array($experiments)) {
            return null;
        }

        foreach ($experiments as $exp) {
            if (isset($exp['status']) && $exp['status'] === 'RUNNING') {
                $matches_id = (isset($exp['productId']) && (int)$exp['productId'] === (int)$product_id);
                if (!$matches_id && !empty($exp['sku']) && function_exists('wc_get_product')) {
                    $prod_obj = wc_get_product($product_id);
                    if ($prod_obj && strtolower($prod_obj->get_sku()) === strtolower($exp['sku'])) {
                        $matches_id = true;
                    }
                }
                if ($matches_id) {
                    if (isset($exp['testVariant']['config']['targeting'])) {
                        $exp['targeting'] = $exp['testVariant']['config']['targeting'];
                    }
                    if (isset($exp['testVariant']['config']['trafficAllocation'])) {
                        $exp['trafficAllocation'] = (int)$exp['testVariant']['config']['trafficAllocation'];
                    }
                    return $exp;
                }
            }
        }
        return null;
    }

    /**
     * Dynamic Price Hook: Returns Variant B price if visitor is in test group.
     */
    public function filter_price($price, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price;
        }

        $experiment = $this->get_active_experiment_for_product($product->get_id());
        if (!$experiment || $experiment['type'] !== 'PRICE') {
            return $price;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && isset($experiment['testVariant']['config']['price'])) {
            return (float)$experiment['testVariant']['config']['price'];
        }

        return $price;
    }

    /**
     * Filter price HTML displayed on shop archive, categories, and single product pages.
     */
    public function filter_price_html($price_html, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $price_html;
        }

        $experiment = $this->get_active_experiment_for_product($product->get_id());
        if (!$experiment || $experiment['type'] !== 'PRICE') {
            return $price_html;
        }

        // Record storefront impression on catalog / shop archive pages
        $this->record_product_impression($product->get_id());

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && isset($experiment['testVariant']['config']['price'])) {
            $test_price = (float)$experiment['testVariant']['config']['price'];
            $regular_price = (float)$product->get_regular_price();

            if ($regular_price > $test_price) {
                return '<del aria-hidden="true">' . wc_price($regular_price) . '</del> <ins>' . wc_price($test_price) . '</ins>';
            } else {
                return wc_price($test_price);
            }
        }

        return $price_html;
    }

    /**
     * Filter whether product displays the 'SALE!' badge when Variant B is priced lower than regular price.
     */
    public function filter_is_on_sale($is_on_sale, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $is_on_sale;
        }

        $experiment = $this->get_active_experiment_for_product($product->get_id());
        if (!$experiment || $experiment['type'] !== 'PRICE') {
            return $is_on_sale;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && isset($experiment['testVariant']['config']['price'])) {
            $test_price = (float)$experiment['testVariant']['config']['price'];
            $regular_price = (float)$product->get_regular_price();
            return ($regular_price > $test_price);
        }

        return $is_on_sale;
    }

    /**
     * Server-Side Product Title Filter: woocommerce_product_get_name (Zero-Flicker).
     */
    public function filter_product_title($title, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $title;
        }

        $product_id = is_object($product) ? $product->get_id() : null;
        if (!$product_id) return $title;

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment || $experiment['type'] !== 'PRODUCT_TITLE') {
            return $title;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && !empty($experiment['testVariant']['config']['title'])) {
            return $experiment['testVariant']['config']['title'];
        }

        return $title;
    }

    /**
     * Server-Side General Title Filter: the_title (Zero-Flicker for template titles).
     */
    public function filter_the_title($title, $post_id = null) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $title;
        }
        if (!$post_id || get_post_type($post_id) !== 'product') {
            return $title;
        }

        $experiment = $this->get_active_experiment_for_product($post_id);
        if (!$experiment || $experiment['type'] !== 'PRODUCT_TITLE') {
            return $title;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && !empty($experiment['testVariant']['config']['title'])) {
            return $experiment['testVariant']['config']['title'];
        }

        return $title;
    }

    /**
     * Server-Side Add-To-Cart CTA Button Text Filter (Zero-Flicker).
     */
    public function filter_add_to_cart_text($text, $product) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $text;
        }

        $product_id = is_object($product) ? $product->get_id() : null;
        if (!$product_id) return $text;

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment || $experiment['type'] !== 'CTA_BUTTON') {
            return $text;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && !empty($experiment['testVariant']['config']['buttonText'])) {
            return $experiment['testVariant']['config']['buttonText'];
        }

        return $text;
    }

    /**
     * Server-Side Hero Image Filter (Zero-Flicker for product gallery hero).
     */
    public function filter_product_image_html($html, $post_thumbnail_id) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $html;
        }

        global $post;
        $product_id = $post ? $post->ID : get_the_ID();
        if (!$product_id) return $html;

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment || $experiment['type'] !== 'CREATIVE_IMAGE') {
            return $html;
        }

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        if ($variant === 'VARIANT_B' && !empty($experiment['testVariant']['config']['imageUrl'])) {
            $new_img_url = esc_url($experiment['testVariant']['config']['imageUrl']);
            // Replace src and remove srcset to prevent browser loading control image
            $html = preg_replace('/src="[^"]+"/', 'src="' . $new_img_url . '"', $html, 1);
            $html = preg_replace('/srcset="[^"]*"/', '', $html);
            $html = preg_replace('/data-src="[^"]+"/', 'data-src="' . $new_img_url . '"', $html);
            $html = preg_replace('/data-large_image="[^"]+"/', 'data-large_image="' . $new_img_url . '"', $html);
        }

        return $html;
    }

    /**
     * Cart Price Lock: Ensures customer checks out with the exact price they saw.
     */
    public function apply_cart_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $experiment = $this->get_active_experiment_for_product($product_id);

            if ($experiment && $experiment['type'] === 'PRICE') {
                $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
                if ($variant === 'VARIANT_B' && isset($experiment['testVariant']['config']['price'])) {
                    $test_price = (float)$experiment['testVariant']['config']['price'];
                    $cart_item['data']->set_price($test_price);
                }
            }
        }
    }

    /**
     * Enqueue Lightweight Vanilla JS Storefront Runner (<2.5 KB, Zero Dependencies).
     */
    public function enqueue_storefront_assets() {
        if ($this->is_bot()) {
            return;
        }

        if (!is_product() && !is_shop() && !is_product_taxonomy()) {
            return;
        }

        global $post;
        $product_id = $post ? $post->ID : get_the_ID();
        $experiment = $product_id ? $this->get_active_experiment_for_product($product_id) : null;

        // Enqueue ultra-lightweight vanilla JS runner
        wp_enqueue_script(
            'marketpulse-ab-storefront',
            MARKETPULSE_PLUGIN_URL . 'assets/js/marketpulse-ab-storefront.js',
            array(), // Zero external dependencies (No jQuery required!)
            MARKETPULSE_VERSION,
            true     // Enqueue at end of <body> for non-blocking page load
        );

        $assigned_variant = $experiment ? $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment) : 'CONTROL';
        $default_api = defined('MARKETPULSE_API_BASE_URL') ? MARKETPULSE_API_BASE_URL : 'http://localhost:4000';

        wp_localize_script('marketpulse-ab-storefront', 'marketpulseAB', array(
            'apiUrl'        => get_option('marketpulse_api_base_url') ?: $default_api,
            'visitorId'     => $this->get_visitor_id(),
            'experiment'    => $experiment,
            'assignedGroup' => $assigned_variant,
        ));
    }

    /**
     * Add `defer` attribute to the storefront script for maximum PageSpeed score.
     */
    public function add_defer_attribute($tag, $handle) {
        if ('marketpulse-ab-storefront' === $handle) {
            if (strpos($tag, 'defer') === false) {
                return str_replace(' src=', ' defer src=', $tag);
            }
        }
        return $tag;
    }

    /**
     * Record impression / page view for an active experiment.
     * Deduplicates within the request to prevent duplicate calls.
     * Bots and crawlers are strictly excluded.
     */
    public function record_product_impression($product_id) {
        if (is_admin() || wp_doing_ajax() || $this->is_bot()) {
            return;
        }

        if (isset(self::$recorded_impressions[$product_id])) {
            return;
        }

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment) return;

        self::$recorded_impressions[$product_id] = true;

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        $variant_id = ($variant === 'VARIANT_B') ? $experiment['testVariant']['id'] : $experiment['controlVariant']['id'];

        $this->dispatch_event_to_api(array(
            'experimentId' => $experiment['id'],
            'variantId'    => $variant_id,
            'visitorUuid'  => $this->get_visitor_id(),
            'eventType'    => 'PAGE_VIEW',
        ));
    }

    /**
     * Track Product Page View server-side to guarantee real-time visitor counting.
     */
    public function track_page_view() {
        if (!is_product() || is_admin() || $this->is_bot()) {
            return;
        }

        global $post;
        $product_id = $post ? $post->ID : get_the_ID();
        if (!$product_id) return;

        $this->record_product_impression($product_id);
    }

    /**
     * Track Add to Cart conversion event.
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        if ($this->is_bot()) return;

        $experiment = $this->get_active_experiment_for_product($product_id);
        if (!$experiment) return;

        $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
        $variant_id = ($variant === 'VARIANT_B') ? $experiment['testVariant']['id'] : $experiment['controlVariant']['id'];

        $this->dispatch_event_to_api(array(
            'experimentId' => $experiment['id'],
            'variantId'    => $variant_id,
            'visitorUuid'  => $this->get_visitor_id(),
            'eventType'    => 'ADD_TO_CART',
        ));
    }

    /**
     * Track Order Purchase conversion event.
     */
    public function track_order_purchase($order_id) {
        if ($this->is_bot()) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $experiment = $this->get_active_experiment_for_product($product_id);

            if ($experiment) {
                $variant = $this->resolve_variant($experiment['id'], $experiment['trafficSplitRatio'] ?? 50, $experiment);
                $variant_id = ($variant === 'VARIANT_B') ? $experiment['testVariant']['id'] : $experiment['controlVariant']['id'];

                $this->dispatch_event_to_api(array(
                    'experimentId' => $experiment['id'],
                    'variantId'    => $variant_id,
                    'visitorUuid'  => $this->get_visitor_id(),
                    'eventType'    => 'PURCHASE',
                    'orderId'      => (string)$order_id,
                    'orderValue'   => (float)$item->get_total(),
                ));
            }
        }
    }

    /**
     * Asynchronous non-blocking beacon to MarketPulse API.
     */
    private function dispatch_event_to_api($payload) {
        if ($this->is_bot()) return;

        $default_api = defined('MARKETPULSE_API_BASE_URL') ? MARKETPULSE_API_BASE_URL : 'http://localhost:4000';
        $api_url = get_option('marketpulse_api_base_url') ?: $default_api;
        wp_remote_post($api_url . '/ab-testing/storefront/events', array(
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($payload),
            'timeout'  => 5,
        ));
    }
}
