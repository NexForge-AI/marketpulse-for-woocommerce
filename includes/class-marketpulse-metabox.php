<?php
/**
 * MarketPulse Product Metabox (Full Suite Automated Repricing)
 *
 * 1:1 Parity with MarketPulse SaaS Automated Repricing Suite:
 * - Live Market Intelligence Cards (Lowest, Average, Highest, Our Price vs Lowest)
 * - 6 Comprehensive Repricing Strategies
 * - Dynamic Beat Offset Amount & Currency/% unit
 * - Required Floor Price + Optional Ceiling Price
 * - [✓] Active toggle
 * - Collapsible Advanced Repricing Drawer:
 *     * Out-of-Stock Opportunity Pricing (None, Raise to Ceiling, Raise by %)
 *     * Minimum Margin Clamp (% above cost)
 *     * Schedule Days of Week (UTC, Sun-Sat)
 *     * Schedule Hours Window (UTC, start to end)
 *     * Exclude specific competitor links from rule math
 * - Live Competitor Price Checker (⚡ Check Price via MarketPulse Cloud inspection cluster)
 * - Two-Way Bidirectional Cloud Synchronization
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Product_MetaBox {

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('save_post_product', array($this, 'save_meta_box'), 20);
        add_action('wp_ajax_marketpulse_live_check_url', array($this, 'ajax_live_check_url'));
    }

    public function register_meta_box() {
        add_meta_box(
            'marketpulse_competitor_pricing',
            __('MarketPulse Competitor Tracking & Repricing', 'marketpulse-for-woocommerce'),
            array($this, 'render_meta_box'),
            'product',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('marketpulse_save_product_meta', 'marketpulse_meta_nonce');

        $is_paired = MarketPulse_Auth::is_paired();
        $status    = MarketPulse_Auth::get_status();
        $is_locked = !empty($status['isLocked']);

        // Load Products Summary from cloud cache
        $summary_map     = MarketPulse_Auth::get_products_summary();
        $product_summary = isset($summary_map[$post->ID]) ? $summary_map[$post->ID] : null;

        // Retrieve existing competitor URLs
        $competitor_urls = get_post_meta($post->ID, '_marketpulse_competitor_urls', true);
        if (!is_array($competitor_urls)) {
            $competitor_urls = array_filter(array($competitor_urls));
        }
        if (empty($competitor_urls)) {
            $competitor_urls = array('');
        }

        // Two-Way Sync: Prioritize cloud rule if present, fallback to local postmeta
        $cloud_rule = (!empty($product_summary) && !empty($product_summary['repricingRule'])) ? $product_summary['repricingRule'] : null;

        $strategy      = $cloud_rule ? ($cloud_rule['strategy'] ?? 'beat_lowest') : (get_post_meta($post->ID, '_marketpulse_strategy', true) ?: 'beat_lowest');
        $amount        = $cloud_rule ? ($cloud_rule['amount'] ?? '') : get_post_meta($post->ID, '_marketpulse_amount', true);
        if ($amount === '' || $amount === null) {
            $amount = ($strategy === 'beat_lowest' || strpos($strategy, 'beat_') === 0) ? '1.00' : '';
        }
        $amount_type   = $cloud_rule ? ($cloud_rule['amountType'] ?? 'fixed') : (get_post_meta($post->ID, '_marketpulse_amount_type', true) ?: 'fixed');
        $floor_price   = $cloud_rule ? ($cloud_rule['floorPrice'] ?? '') : get_post_meta($post->ID, '_marketpulse_floor_price', true);
        $ceiling_price = $cloud_rule ? ($cloud_rule['ceilingPrice'] ?? '') : get_post_meta($post->ID, '_marketpulse_ceiling_price', true);
        $is_active     = $cloud_rule ? (!empty($cloud_rule['isActive'])) : (metadata_exists('post', $post->ID, '_marketpulse_active') ? (bool) get_post_meta($post->ID, '_marketpulse_active', true) : true);
        $oos_mode      = $cloud_rule ? ($cloud_rule['outOfStockMode'] ?? 'none') : (get_post_meta($post->ID, '_marketpulse_oos_mode', true) ?: 'none');
        $oos_percent   = $cloud_rule ? ($cloud_rule['outOfStockRaisePercent'] ?? '') : get_post_meta($post->ID, '_marketpulse_oos_percent', true);
        $min_margin    = $cloud_rule ? ($cloud_rule['minMarginPercent'] ?? '') : get_post_meta($post->ID, '_marketpulse_min_margin', true);

        // Schedule days & hours (UTC)
        $schedule_days = $cloud_rule ? ($cloud_rule['scheduleDaysOfWeek'] ?? array()) : (get_post_meta($post->ID, '_marketpulse_schedule_days', true) ?: array());
        if (!is_array($schedule_days)) {
            $schedule_days = array();
        }
        $start_hour = $cloud_rule ? ($cloud_rule['scheduleStartHourUtc'] ?? '') : get_post_meta($post->ID, '_marketpulse_start_hour', true);
        $end_hour   = $cloud_rule ? ($cloud_rule['scheduleEndHourUtc'] ?? '') : get_post_meta($post->ID, '_marketpulse_end_hour', true);

        // Excluded competitor URLs
        $excluded_urls = $cloud_rule ? ($cloud_rule['excludedCompetitorUrls'] ?? array()) : (get_post_meta($post->ID, '_marketpulse_excluded_urls', true) ?: array());
        if (!is_array($excluded_urls)) {
            $excluded_urls = array();
        }

        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        $current_product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
        $current_price   = $current_product ? (float) ($current_product->get_sale_price() ?: $current_product->get_regular_price() ?: 0.0) : 0.0;
        $regular_price   = $current_product ? (float) ($current_product->get_regular_price() ?: $current_price) : $current_price;
        $product_title   = $current_product ? $current_product->get_name() : $post->post_title;
        $product_sku     = $current_product ? ($current_product->get_sku() ?: ('WC-' . $post->ID)) : ('WC-' . $post->ID);
        $product_img_id  = $current_product ? $current_product->get_image_id() : 0;
        $product_img_url = $product_img_id ? wp_get_attachment_image_url($product_img_id, 'medium') : (function_exists('wc_placeholder_img_src') ? wc_placeholder_img_src('medium') : '');
        $cat_terms       = get_the_terms($post->ID, 'product_cat');
        $cat_name        = (!empty($cat_terms) && !is_wp_error($cat_terms)) ? $cat_terms[0]->name : 'PREMIUM COLLECTION';

        $snapshot = (!empty($product_summary) && !empty($product_summary['marketSnapshot'])) ? $product_summary['marketSnapshot'] : null;

        // A/B Split Testing PostMeta (Local First CRO)
        $ab_enabled    = get_post_meta($post->ID, '_marketpulse_ab_enabled', true) ?: 'no';
        $ab_name       = get_post_meta($post->ID, '_marketpulse_ab_name', true) ?: ($post->post_title . ' - 50/50 A/B Test');
        $ab_type       = get_post_meta($post->ID, '_marketpulse_ab_type', true) ?: 'PRICE';
        $ab_price      = get_post_meta($post->ID, '_marketpulse_ab_variant_b_price', true) ?: '';
        $ab_title      = get_post_meta($post->ID, '_marketpulse_ab_variant_b_title', true) ?: '';
        $ab_image      = get_post_meta($post->ID, '_marketpulse_ab_variant_b_image', true) ?: '';
        $ab_btn        = get_post_meta($post->ID, '_marketpulse_ab_variant_b_btn', true) ?: '';
        $ab_color      = get_post_meta($post->ID, '_marketpulse_ab_variant_b_color', true) ?: '#10B981';
        $ab_allocation = get_post_meta($post->ID, '_marketpulse_ab_traffic_allocation', true) ?: '100';
        $ab_device     = get_post_meta($post->ID, '_marketpulse_ab_target_device', true) ?: 'ALL';
        $ab_source     = get_post_meta($post->ID, '_marketpulse_ab_target_source', true) ?: 'ALL';
        $ab_user_type  = get_post_meta($post->ID, '_marketpulse_ab_target_user_type', true) ?: 'ALL';

        // Two-Way Sync Fallback: Check if an active cloud experiment is running for this product
        if ($ab_enabled !== 'yes') {
            $cloud_experiments = get_option(MarketPulse_AB_Testing::EXPERIMENTS_OPTION, array());
            if (is_array($cloud_experiments)) {
                foreach ($cloud_experiments as $cexp) {
                    if (isset($cexp['status']) && $cexp['status'] === 'RUNNING') {
                        $c_pid = isset($cexp['productId']) ? (int)$cexp['productId'] : 0;
                        if (!$c_pid && !empty($cexp['sku']) && function_exists('wc_get_product_id_by_sku')) {
                            $c_pid = wc_get_product_id_by_sku($cexp['sku']);
                        }
                        if (!$c_pid && !empty($cexp['sku']) && preg_match('/(?:wc-)?(\d+)/i', $cexp['sku'], $m)) {
                            $c_pid = (int)$m[1];
                        }
                        if ($c_pid === (int)$post->ID) {
                            $ab_enabled = 'yes';
                            if (!empty($cexp['name'])) $ab_name = $cexp['name'];
                            if (!empty($cexp['type'])) $ab_type = $cexp['type'];
                            $cfg = $cexp['testVariant']['config'] ?? array();
                            if (isset($cfg['price']) && is_numeric($cfg['price'])) $ab_price = (float)$cfg['price'];
                            if (!empty($cfg['title'])) $ab_title = $cfg['title'];
                            if (!empty($cfg['imageUrl'])) $ab_image = $cfg['imageUrl'];
                            if (!empty($cfg['buttonText'])) $ab_btn = $cfg['buttonText'];
                            if (!empty($cfg['color'])) $ab_color = $cfg['color'];
                            if (isset($cfg['trafficAllocation'])) $ab_allocation = (int)$cfg['trafficAllocation'];
                            if (!empty($cfg['targeting']['device'])) $ab_device = $cfg['targeting']['device'];
                            if (!empty($cfg['targeting']['trafficSource'])) $ab_source = $cfg['targeting']['trafficSource'];
                            if (!empty($cfg['targeting']['userType'])) $ab_user_type = $cfg['targeting']['userType'];
                            break;
                        }
                    }
                }
            }
        }
        ?>
        <div class="mp-metabox-wrap" data-current-price="<?php echo esc_attr($current_price); ?>" data-regular-price="<?php echo esc_attr($regular_price); ?>" data-currency-symbol="<?php echo esc_attr($currency_symbol); ?>" data-product-title="<?php echo esc_attr($product_title); ?>" data-product-sku="<?php echo esc_attr($product_sku); ?>" data-image-url="<?php echo esc_url($product_img_url); ?>" data-category-name="<?php echo esc_attr($cat_name); ?>" style="padding: 6px 0;">
            <?php if (!$is_paired) : ?>
                <div style="background: #f0f6fc; border-left: 4px solid #72aee6; padding: 12px; margin-bottom: 14px; border-radius: 4px;">
                    <p style="margin: 0; font-size: 13px;">
                        <?php esc_html_e('MarketPulse is not connected yet. Connect your store in 1 click to activate real-time competitor tracking and automated repricing.', 'marketpulse-for-woocommerce'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse')); ?>" style="font-weight: 600; margin-left: 6px;">
                            <?php esc_html_e('Connect Store (14-Day Free Trial) →', 'marketpulse-for-woocommerce'); ?>
                        </a>
                    </p>
                </div>
            <?php elseif ($is_locked) : ?>
                <div style="background: #fcf0f0; border-left: 4px solid #d63638; padding: 12px; margin-bottom: 14px; border-radius: 4px;">
                    <p style="margin: 0; font-size: 13px; color: #b32d2e;">
                        <strong><?php esc_html_e('🔒 Repricing is Locked:', 'marketpulse-for-woocommerce'); ?></strong>
                        <?php esc_html_e('Your 14-day Starter trial has ended. Automated price updates and competitor sync are currently paused.', 'marketpulse-for-woocommerce'); ?>
                        <a href="<?php echo esc_url($status['upgradeUrl'] ?? MARKETPULSE_DASHBOARD_URL . '/select-plan?ref=wp_plugin'); ?>" target="_blank" style="font-weight: 600; margin-left: 6px;">
                            <?php esc_html_e('Upgrade to Starter ($29/mo) to unlock →', 'marketpulse-for-woocommerce'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Live Market Intelligence Stats Banner (SaaS 1:1 Parity) -->
            <?php if (!empty($product_summary) && !empty($product_summary['competitorCount'])) : ?>
                <div style="margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #475569;">
                            <?php esc_html_e('Live Market Intelligence', 'marketpulse-for-woocommerce'); ?>
                        </span>
                        <span style="font-size: 12px; color: #64748b;">
                            <?php printf(esc_html__('From %d active competitor(s) · %s', 'marketpulse-for-woocommerce'), absint($product_summary['competitorCount']), esc_html($product_summary['currency'] ?? '')); ?>
                        </span>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
                        <!-- Lowest Card -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">
                                <?php esc_html_e('Lowest', 'marketpulse-for-woocommerce'); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 3px;">
                                <?php
                                $lowest_val = $snapshot['lowest'] ?? $product_summary['lowestCompetitorPrice'] ?? null;
                                echo ($lowest_val !== null && is_numeric($lowest_val)) ? esc_html($currency_symbol . number_format($lowest_val, 2)) : '—';
                                ?>
                            </div>
                        </div>

                        <!-- Average Card -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">
                                <?php esc_html_e('Average', 'marketpulse-for-woocommerce'); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 3px;">
                                <?php
                                $avg_val = $snapshot['average'] ?? null;
                                echo ($avg_val !== null && is_numeric($avg_val)) ? esc_html($currency_symbol . number_format($avg_val, 2)) : '—';
                                ?>
                            </div>
                        </div>

                        <!-- Highest Card -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">
                                <?php esc_html_e('Highest', 'marketpulse-for-woocommerce'); ?>
                            </div>
                            <div style="font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 3px;">
                                <?php
                                $high_val = $snapshot['highest'] ?? null;
                                echo ($high_val !== null && is_numeric($high_val)) ? esc_html($currency_symbol . number_format($high_val, 2)) : '—';
                                ?>
                            </div>
                        </div>

                        <!-- Our Price vs Lowest Card -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;">
                            <div style="font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase;">
                                <?php esc_html_e('Our Price vs Lowest', 'marketpulse-for-woocommerce'); ?>
                            </div>
                            <div style="font-size: 17px; font-weight: 700; margin-top: 3px;">
                                <?php
                                if ($lowest_val !== null && $current_price > 0) {
                                    $diff = $current_price - $lowest_val;
                                    if (abs($diff) < 0.01) {
                                        echo '<span style="color: #0284c7;">- 0% (at parity)</span>';
                                    } elseif ($diff < 0) {
                                        $pct = abs(round(($diff / $current_price) * 100, 1));
                                        echo '<span style="color: #16a34a;">-' . esc_html($pct) . '% (winning)</span>';
                                    } else {
                                        $pct = round(($diff / $current_price) * 100, 1);
                                        echo '<span style="color: #dc2626;">+' . esc_html($pct) . '% (higher)</span>';
                                    }
                                } else {
                                    echo '<span style="color: #94a3b8;">—</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Competitor URLs Section -->
            <div class="mp-field-group" style="margin-bottom: 18px;">
                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
                    <?php esc_html_e('Competitor Product URLs (Amazon, Daraz, eBay, or any eCommerce store):', 'marketpulse-for-woocommerce'); ?>
                </label>
                <p class="description" style="margin-bottom: 10px; font-size: 12px; color: #64748b;">
                    <?php esc_html_e('Paste direct competitor product links. Click "⚡ Check Price" to test instant cloud price detection.', 'marketpulse-for-woocommerce'); ?>
                </p>

                <div id="mp-competitor-rows">
                    <?php foreach ($competitor_urls as $url) : ?>
                        <div class="mp-url-row-container" style="margin-bottom: 10px;">
                            <div class="mp-url-row" style="display: flex; gap: 8px;">
                                <input
                                    type="url"
                                    name="marketpulse_competitor_urls[]"
                                    value="<?php echo esc_url($url); ?>"
                                    placeholder="https://www.amazon.com/dp/... or https://www.daraz.com.bd/products/..."
                                    style="width: 100%; max-width: 480px;"
                                    <?php disabled($is_locked); ?>
                                >
                                <button type="button" class="button button-secondary mp-check-url-btn" title="<?php esc_attr_e('Test live price detection immediately', 'marketpulse-for-woocommerce'); ?>">
                                    ⚡ <?php esc_html_e('Check Price', 'marketpulse-for-woocommerce'); ?>
                                </button>
                                <button type="button" class="button mp-remove-row" style="color: #b32d2e;" <?php disabled($is_locked); ?>>
                                    <?php esc_html_e('Remove', 'marketpulse-for-woocommerce'); ?>
                                </button>
                            </div>
                            <div class="mp-live-check-preview" style="display: none; margin-top: 6px; max-width: 600px; padding: 6px 10px; border-radius: 4px; font-size: 12px;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="mp-add-url-btn" class="button" style="margin-top: 4px;" <?php disabled($is_locked); ?>>
                    + <?php esc_html_e('Add Another Competitor URL', 'marketpulse-for-woocommerce'); ?>
                </button>
            </div>

            <!-- Automated Repricing Controls -->
            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-top: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                    <div>
                        <strong style="font-size: 13px; color: #1e293b;"><?php esc_html_e('Automated Repricing Rules', 'marketpulse-for-woocommerce'); ?></strong>
                        <p style="margin: 2px 0 0; font-size: 11px; color: #64748b;">
                            <?php esc_html_e('Automatically computes and adjusts your WooCommerce price whenever competitor prices change.', 'marketpulse-for-woocommerce'); ?>
                            <a href="#" id="mp-quick-guide-btn" style="color: #2563eb; text-decoration: underline; font-weight: 500; margin-left: 6px; cursor: pointer;">
                                <?php esc_html_e('ℹ️ Need help with strategies? Click for quick guide', 'marketpulse-for-woocommerce'); ?>
                            </a>
                        </p>
                    </div>

                    <!-- Active Toggle Checkbox -->
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer; color: #0f172a;">
                        <input
                            type="checkbox"
                            name="marketpulse_active"
                            value="1"
                            <?php checked($is_active); ?>
                            <?php disabled($is_locked); ?>
                        >
                        <?php esc_html_e('Active', 'marketpulse-for-woocommerce'); ?>
                    </label>
                </div>

                <!-- Quick Strategy Guide Drawer -->
                <div id="mp-quick-guide-drawer" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px 14px; margin-bottom: 14px; font-size: 12px; color: #1e3a8a; line-height: 1.5;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <strong style="font-size: 13px; color: #1e40af;">📖 Quick Repricing Rules Guide</strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse&tab=guide')); ?>" target="_blank" style="font-size: 11px; font-weight: 600; color: #2563eb;">
                            <?php esc_html_e('View Full User Guide ↗', 'marketpulse-for-woocommerce'); ?>
                        </a>
                    </div>
                    <ul style="margin: 0 0 8px 16px; list-style-type: disc;">
                        <li><strong>Match Lowest:</strong> Sets your price equal to the cheapest tracked competitor (e.g., Competitor $100 &rarr; You $100).</li>
                        <li><strong>Beat Lowest:</strong> Undercuts the cheapest competitor by your offset (e.g., Competitor $100, Offset $1 &rarr; You $99).</li>
                        <li><strong>Match / Beat Average:</strong> Positions against the market mean average to avoid extreme outliers.</li>
                        <li><strong>Floor Price (Required):</strong> Hard safety net. Your price will NEVER go below this floor, protecting your margin.</li>
                        <li><strong>Ceiling Price (Optional):</strong> Maximum price cap. Your price will never exceed this number.</li>
                        <li><strong>Manual Edits:</strong> If you change price manually in WooCommerce and want it to stay permanent, simply uncheck <code>Active</code>.</li>
                    </ul>
                </div>

                <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-start;">
                    <!-- Strategy Selector (6 Strategies) -->
                    <div style="flex: 2; min-width: 220px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
                            <?php esc_html_e('Repricing Strategy:', 'marketpulse-for-woocommerce'); ?>
                        </label>
                        <select id="mp-strategy-select" name="marketpulse_strategy" style="width: 100%; font-size: 13px;" <?php disabled($is_locked); ?>>
                            <option value="match_lowest" <?php selected($strategy, 'match_lowest'); ?>>
                                <?php esc_html_e('Match the lowest competitor exactly', 'marketpulse-for-woocommerce'); ?>
                            </option>
                            <option value="beat_lowest" <?php selected($strategy, 'beat_lowest'); ?>>
                                <?php esc_html_e('Beat the lowest competitor by', 'marketpulse-for-woocommerce'); ?>
                            </option>
                            <option value="match_average" <?php selected($strategy, 'match_average'); ?>>
                                <?php esc_html_e('Match the average competitor exactly', 'marketpulse-for-woocommerce'); ?>
                            </option>
                            <option value="beat_average" <?php selected($strategy, 'beat_average'); ?>>
                                <?php esc_html_e('Beat the average competitor by', 'marketpulse-for-woocommerce'); ?>
                            </option>
                            <option value="beat_highest" <?php selected($strategy, 'beat_highest'); ?>>
                                <?php esc_html_e('Position below the highest competitor', 'marketpulse-for-woocommerce'); ?>
                            </option>
                            <option value="match_highest" <?php selected($strategy, 'match_highest'); ?>>
                                <?php esc_html_e('Match the highest competitor exactly', 'marketpulse-for-woocommerce'); ?>
                            </option>
                        </select>
                    </div>

                    <!-- Dynamic Beat Offset Amount & Type -->
                    <?php $needs_amount = strpos($strategy, 'beat_') === 0; ?>
                    <div id="mp-offset-group" style="flex: 1.5; min-width: 180px; <?php echo $needs_amount ? '' : 'display: none;'; ?>">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
                            <?php esc_html_e('Undercut By:', 'marketpulse-for-woocommerce'); ?>
                        </label>
                        <div style="display: flex; gap: 4px;">
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                id="mp-amount-input"
                                name="marketpulse_amount"
                                value="<?php echo esc_attr($amount); ?>"
                                placeholder="1.00"
                                style="width: 65%; font-size: 13px;"
                                <?php disabled($is_locked); ?>
                            >
                            <select name="marketpulse_amount_type" style="width: 35%; font-size: 13px;" <?php disabled($is_locked); ?>>
                                <option value="fixed" <?php selected($amount_type, 'fixed'); ?>><?php echo esc_html($currency_symbol); ?></option>
                                <option value="percent" <?php selected($amount_type, 'percent'); ?>>%</option>
                            </select>
                        </div>
                    </div>

                    <!-- Floor Price (Required) -->
                    <div style="flex: 1; min-width: 130px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
                            <?php printf(esc_html__('Floor Price (%s)*:', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="marketpulse_floor_price"
                            value="<?php echo esc_attr($floor_price !== '' ? $floor_price : ''); ?>"
                            placeholder="0.00"
                            style="width: 100%; font-size: 13px;"
                            <?php disabled($is_locked); ?>
                        >
                        <small style="color: #64748b; font-size: 11px; display: block; margin-top: 3px;">
                            <?php esc_html_e('Hard safety net floor', 'marketpulse-for-woocommerce'); ?>
                        </small>
                    </div>

                    <!-- Ceiling Price (Optional) -->
                    <div style="flex: 1; min-width: 130px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
                            <?php printf(esc_html__('Ceiling Price (%s):', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?>
                        </label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="marketpulse_ceiling_price"
                            value="<?php echo esc_attr($ceiling_price !== '' ? $ceiling_price : ''); ?>"
                            placeholder="Optional"
                            style="width: 100%; font-size: 13px;"
                            <?php disabled($is_locked); ?>
                        >
                        <small style="color: #64748b; font-size: 11px; display: block; margin-top: 3px;">
                            <?php esc_html_e('Never price above this', 'marketpulse-for-woocommerce'); ?>
                        </small>
                    </div>
                </div>

                <!-- Collapsible Advanced Repricing Drawer Button -->
                <div style="margin-top: 14px; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                    <button type="button" id="mp-toggle-advanced-btn" class="button button-link" style="font-size: 12px; text-decoration: none; font-weight: 600; color: #2563eb; display: inline-flex; align-items: center; gap: 4px;">
                        <span id="mp-advanced-toggle-icon">▼</span>
                        <span id="mp-advanced-toggle-text"><?php esc_html_e('Show Advanced Repricing Settings', 'marketpulse-for-woocommerce'); ?></span>
                    </button>

                    <!-- Advanced Drawer Content (SaaS 1:1 Parity) -->
                    <div id="mp-advanced-drawer" style="display: none; margin-top: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                            <!-- Minimum Margin Clamp -->
                            <div style="flex: 1; min-width: 200px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px; color: #334155;">
                                    <?php esc_html_e('Minimum margin above cost (%, optional):', 'marketpulse-for-woocommerce'); ?>
                                </label>
                                <input
                                    type="number"
                                    step="0.1"
                                    min="0"
                                    name="marketpulse_min_margin"
                                    value="<?php echo esc_attr($min_margin); ?>"
                                    placeholder="e.g. 20"
                                    style="width: 100%; font-size: 12px;"
                                    <?php disabled($is_locked); ?>
                                >
                                <small style="color: #64748b; font-size: 11px; display: block; margin-top: 3px;">
                                    <?php esc_html_e('Extra guard above Product Cost', 'marketpulse-for-woocommerce'); ?>
                                </small>
                            </div>

                            <!-- Stock-Out Opportunity Mode -->
                            <div style="flex: 2; min-width: 250px;">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px; color: #334155;">
                                    <?php esc_html_e('When every competitor is out of stock:', 'marketpulse-for-woocommerce'); ?>
                                </label>
                                <select id="mp-oos-mode-select" name="marketpulse_oos_mode" style="width: 100%; font-size: 12px;" <?php disabled($is_locked); ?>>
                                    <option value="none" <?php selected($oos_mode, 'none'); ?>>
                                        <?php esc_html_e('Do nothing special', 'marketpulse-for-woocommerce'); ?>
                                    </option>
                                    <option value="raise_to_ceiling" <?php selected($oos_mode, 'raise_to_ceiling'); ?>>
                                        <?php esc_html_e('Raise price to ceiling price', 'marketpulse-for-woocommerce'); ?>
                                    </option>
                                    <option value="raise_by_percent" <?php selected($oos_mode, 'raise_by_percent'); ?>>
                                        <?php esc_html_e('Raise price by percentage', 'marketpulse-for-woocommerce'); ?>
                                    </option>
                                </select>
                                <div id="mp-oos-percent-wrap" style="margin-top: 8px; <?php echo ($oos_mode === 'raise_by_percent') ? '' : 'display: none;'; ?>">
                                    <label style="font-size: 11px; color: #475569; display: block; margin-bottom: 3px;">
                                        <?php esc_html_e('Raise By Percentage (%):', 'marketpulse-for-woocommerce'); ?>
                                    </label>
                                    <input
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        name="marketpulse_oos_percent"
                                        value="<?php echo esc_attr($oos_percent); ?>"
                                        placeholder="e.g. 10"
                                        style="width: 120px; font-size: 12px;"
                                        <?php disabled($is_locked); ?>
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Schedule Row (Days of Week & Hours UTC) -->
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0; display: flex; gap: 20px; flex-wrap: wrap;">
                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 12px; color: #334155;">
                                    <?php esc_html_e('Only run on these days (UTC, optional):', 'marketpulse-for-woocommerce'); ?>
                                </label>
                                <div style="display: flex; gap: 10px; font-size: 12px; align-items: center; flex-wrap: wrap;">
                                    <?php
                                    $days_map = array(0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat');
                                    foreach ($days_map as $day_idx => $day_label) :
                                    ?>
                                        <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; cursor: pointer; color: #475569;">
                                            <input
                                                type="checkbox"
                                                name="marketpulse_schedule_days[]"
                                                value="<?php echo esc_attr($day_idx); ?>"
                                                <?php checked(in_array($day_idx, $schedule_days, false)); ?>
                                                <?php disabled($is_locked); ?>
                                            >
                                            <?php echo esc_html($day_label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div>
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 12px; color: #334155;">
                                    <?php esc_html_e('Hours (UTC, optional):', 'marketpulse-for-woocommerce'); ?>
                                </label>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <input
                                        type="number"
                                        min="0"
                                        max="23"
                                        name="marketpulse_start_hour"
                                        value="<?php echo esc_attr($start_hour !== '' ? $start_hour : ''); ?>"
                                        placeholder="start"
                                        style="width: 65px; font-size: 12px;"
                                        <?php disabled($is_locked); ?>
                                    >
                                    <span style="font-size: 12px; color: #64748b;"><?php esc_html_e('to', 'marketpulse-for-woocommerce'); ?></span>
                                    <input
                                        type="number"
                                        min="0"
                                        max="23"
                                        name="marketpulse_end_hour"
                                        value="<?php echo esc_attr($end_hour !== '' ? $end_hour : ''); ?>"
                                        placeholder="end"
                                        style="width: 65px; font-size: 12px;"
                                        <?php disabled($is_locked); ?>
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Exclude Specific Competitor Links -->
                        <?php
                        $valid_comp_urls = array_filter($competitor_urls, function($u) {
                            return !empty($u) && filter_var($u, FILTER_VALIDATE_URL);
                        });
                        if (!empty($valid_comp_urls)) :
                        ?>
                            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px dashed #e2e8f0;">
                                <label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 12px; color: #334155;">
                                    <?php esc_html_e('Exclude specific competitor links from this rule\'s math (optional):', 'marketpulse-for-woocommerce'); ?>
                                </label>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <?php foreach ($valid_comp_urls as $comp_u) : ?>
                                        <label style="display: flex; align-items: flex-start; gap: 6px; font-size: 12px; color: #475569; word-break: break-all; cursor: pointer;">
                                            <input
                                                type="checkbox"
                                                name="marketpulse_excluded_urls[]"
                                                value="<?php echo esc_url($comp_u); ?>"
                                                <?php checked(in_array($comp_u, $excluded_urls, true)); ?>
                                                <?php disabled($is_locked); ?>
                                                style="margin-top: 2px;"
                                            >
                                            <span><?php echo esc_html($comp_u); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Explanatory Footnotes (1:1 with SaaS) -->
                        <div style="margin-top: 14px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b; line-height: 1.5;">
                            <p style="margin: 0 0 4px;">
                                <?php esc_html_e('All three are optional and off by default. A price outside the scheduled window is still computed and logged, just not pushed live.', 'marketpulse-for-woocommerce'); ?>
                            </p>
                            <p style="margin: 0;">
                                <?php printf(esc_html__('Floor price is a hard safety net — the computed price is never proposed below it, no matter how low competitors go. Amounts/floor/ceiling are in this product\'s currency (%s).', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- A/B Split Testing Section (CRO & Price Optimization) -->
                <div style="margin-top: 18px; border-top: 2px solid #e2e8f0; padding-top: 14px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 13px; color: #0f172a; cursor: pointer;">
                            <input type="checkbox" id="mp-ab-enable-toggle" name="marketpulse_ab_enabled" value="yes" <?php checked($ab_enabled, 'yes'); ?> style="margin: 0;">
                            <span>⚡ <?php esc_html_e('Enable 50/50 A/B Split-Testing for this Product', 'marketpulse-for-woocommerce'); ?></span>
                        </label>
                        <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 12px; font-weight: 600;">
                            <?php esc_html_e('Zero-Flicker CRO', 'marketpulse-for-woocommerce'); ?>
                        </span>
                    </div>

                    <div id="mp-ab-settings-wrap" style="<?php echo ($ab_enabled === 'yes') ? '' : 'display: none;'; ?>">
                        <!-- No-Code Visual Editor & Experiment Builder Card (1:1 with SaaS) -->
                        <div class="mp-ab-builder-card">
                            <div class="mp-ab-builder-header">
                                <div>
                                    <h3 style="margin: 0 0 3px; font-size: 14px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                        <span>✨</span> <?php esc_html_e('No-Code Visual Editor & Experiment Builder', 'marketpulse-for-woocommerce'); ?>
                                    </h3>
                                    <p style="margin: 0; font-size: 11px; color: #64748b;">
                                        <?php esc_html_e('Visually edit CTA buttons, prices & headlines with live mockup preview & audience targeting.', 'marketpulse-for-woocommerce'); ?>
                                    </p>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 10px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 10px; font-weight: 600;">
                                        ⚡ <?php esc_html_e('Server-Side Zero-Flicker', 'marketpulse-for-woocommerce'); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="mp-ab-builder-body">
                                <div class="mp-ab-builder-grid">
                                    <!-- ================= LEFT COLUMN: Configuration ================= -->
                                    <div class="mp-ab-config-col">
                                        <!-- Experiment Name -->
                                        <div style="margin-bottom: 12px;">
                                            <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                <?php esc_html_e('Experiment Name:', 'marketpulse-for-woocommerce'); ?>
                                            </label>
                                            <input
                                                type="text"
                                                id="mp-ab-name-input"
                                                name="marketpulse_ab_name"
                                                value="<?php echo esc_attr($ab_name); ?>"
                                                placeholder="<?php echo esc_attr($product_title . ' - A/B Test'); ?>"
                                                style="width: 100%; font-size: 13px;"
                                            >
                                        </div>

                                        <!-- Test Type Selector -->
                                        <div style="margin-bottom: 14px;">
                                            <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                <?php esc_html_e('Test Type:', 'marketpulse-for-woocommerce'); ?>
                                            </label>
                                            <select id="mp-ab-type-select" name="marketpulse_ab_type" style="width: 100%; font-size: 13px; font-weight: 500;">
                                                <option value="PRICE" <?php selected($ab_type, 'PRICE'); ?>><?php printf(esc_html__('Price Testing (%s Elasticity)', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?></option>
                                                <option value="PRODUCT_TITLE" <?php selected($ab_type, 'PRODUCT_TITLE'); ?>><?php esc_html_e('Product Title & Headline', 'marketpulse-for-woocommerce'); ?></option>
                                                <option value="CREATIVE_IMAGE" <?php selected($ab_type, 'CREATIVE_IMAGE'); ?>><?php esc_html_e('Creative / Hero Image', 'marketpulse-for-woocommerce'); ?></option>
                                                <option value="CTA_BUTTON" <?php selected($ab_type, 'CTA_BUTTON'); ?>><?php esc_html_e('CTA Button Text & Color', 'marketpulse-for-woocommerce'); ?></option>
                                            </select>
                                        </div>

                                        <!-- Target Product Context Card -->
                                        <div class="mp-ab-target-info">
                                            <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px; display: flex; justify-content: space-between;">
                                                <span>🎯 <?php esc_html_e('Target Product:', 'marketpulse-for-woocommerce'); ?> <?php echo esc_html($product_sku); ?></span>
                                                <span style="color: #2563eb;"><?php echo esc_html($currency_symbol . number_format($current_price, 2)); ?></span>
                                            </div>
                                            <div style="color: #64748b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?php echo esc_html($product_title); ?>
                                            </div>
                                        </div>

                                        <!-- Variant B Customization Box -->
                                        <div class="mp-ab-customization-box">
                                            <div style="font-weight: 700; font-size: 12px; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                                                <span>⚙️ <?php esc_html_e('Variant B Customization', 'marketpulse-for-woocommerce'); ?></span>
                                                <span style="font-size: 10px; background: #eff6ff; color: #1e40af; padding: 2px 8px; border-radius: 10px; font-weight: 600;">
                                                    ⚡ <?php esc_html_e('Live Reactivity', 'marketpulse-for-woocommerce'); ?>
                                                </span>
                                            </div>

                                            <!-- Field: Price -->
                                            <div id="mp-ab-field-price" class="mp-ab-type-field" style="<?php echo ($ab_type === 'PRICE') ? '' : 'display: none;'; ?>">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                    <?php printf(esc_html__('Variant B Test Price (%s):', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?>
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    id="mp-ab-price-input"
                                                    name="marketpulse_ab_variant_b_price"
                                                    value="<?php echo esc_attr($ab_price); ?>"
                                                    placeholder="<?php echo esc_attr($current_price > 0 ? number_format($current_price * 0.9, 2, '.', '') : '40.00'); ?>"
                                                    style="width: 100%; font-size: 13px;"
                                                >
                                                <div id="mp-ab-price-calc-wrap">
                                                    <!-- Live calculation pill rendered by JS -->
                                                </div>
                                            </div>

                                            <!-- Field: Title -->
                                            <div id="mp-ab-field-title" class="mp-ab-type-field" style="<?php echo ($ab_type === 'PRODUCT_TITLE') ? '' : 'display: none;'; ?>">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                    <?php esc_html_e('Variant B Alternate Headline / Title:', 'marketpulse-for-woocommerce'); ?>
                                                </label>
                                                <input
                                                    type="text"
                                                    id="mp-ab-title-input"
                                                    name="marketpulse_ab_variant_b_title"
                                                    value="<?php echo esc_attr($ab_title); ?>"
                                                    placeholder="<?php echo esc_attr($product_title); ?>"
                                                    style="width: 100%; font-size: 13px;"
                                                >
                                                <small style="display: block; margin-top: 4px; font-size: 11px; color: #64748b;">
                                                    <?php esc_html_e('Replaces the main product title for 50% of visitors with zero-flicker.', 'marketpulse-for-woocommerce'); ?>
                                                </small>
                                            </div>

                                            <!-- Field: Creative Image -->
                                            <div id="mp-ab-field-image" class="mp-ab-type-field" style="<?php echo ($ab_type === 'CREATIVE_IMAGE') ? '' : 'display: none;'; ?>">
                                                <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                    <?php esc_html_e('Variant B Alternate Hero / Product Image:', 'marketpulse-for-woocommerce'); ?>
                                                </label>
                                                <div style="display: flex; gap: 8px; align-items: center;">
                                                    <input
                                                        type="url"
                                                        id="mp-ab-image-input"
                                                        name="marketpulse_ab_variant_b_image"
                                                        value="<?php echo esc_url($ab_image); ?>"
                                                        placeholder="https://... or choose from Media Library"
                                                        style="width: 100%; font-size: 13px;"
                                                    >
                                                    <button type="button" id="mp-ab-upload-image-btn" class="button button-secondary" style="white-space: nowrap; font-size: 12px;">
                                                        🖼️ <?php esc_html_e('Choose Image', 'marketpulse-for-woocommerce'); ?>
                                                    </button>
                                                </div>
                                                <small style="display: block; margin-top: 4px; font-size: 11px; color: #64748b;">
                                                    <?php esc_html_e('Replaces the main product gallery hero image for 50% of shoppers with zero-flicker.', 'marketpulse-for-woocommerce'); ?>
                                                </small>
                                            </div>

                                            <!-- Field: CTA Button & Color -->
                                            <div id="mp-ab-field-button" class="mp-ab-type-field" style="<?php echo ($ab_type === 'CTA_BUTTON') ? '' : 'display: none;'; ?>">
                                                <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                                                    <div style="flex: 1; min-width: 180px;">
                                                        <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                            <?php esc_html_e('Variant B Button Text & Offer:', 'marketpulse-for-woocommerce'); ?>
                                                        </label>
                                                        <input
                                                            type="text"
                                                            id="mp-ab-btn-text-input"
                                                            name="marketpulse_ab_variant_b_btn"
                                                            value="<?php echo esc_attr($ab_btn); ?>"
                                                            placeholder="e.g. Claim Free Shipping + Add to Cart"
                                                            style="width: 100%; font-size: 13px;"
                                                        >
                                                    </div>
                                                    <div style="width: 120px;">
                                                        <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                            <?php esc_html_e('Button Color:', 'marketpulse-for-woocommerce'); ?>
                                                        </label>
                                                        <div style="display: flex; align-items: center; gap: 6px;">
                                                            <input
                                                                type="color"
                                                                id="mp-ab-btn-color-input"
                                                                name="marketpulse_ab_variant_b_color"
                                                                value="<?php echo esc_attr($ab_color); ?>"
                                                                style="width: 44px; height: 32px; padding: 1px 2px; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer;"
                                                            >
                                                            <span id="mp-ab-color-hex" style="font-size: 11px; font-family: monospace; color: #334155;"><?php echo esc_html($ab_color); ?></span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <small style="display: block; margin-top: 4px; font-size: 11px; color: #64748b;">
                                                    <?php esc_html_e('Customizes the Add-to-Cart button text and background accent for 50% of shoppers.', 'marketpulse-for-woocommerce'); ?>
                                                </small>
                                            </div>
                                        </div>

                                        <!-- Enterprise CRO: Smart Traffic Allocation & Audience Targeting -->
                                        <div style="padding: 12px 14px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px;">
                                            <div style="font-weight: 700; font-size: 12px; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                                                <span>🎯 <?php esc_html_e('Audience Targeting Rules & Allocation', 'marketpulse-for-woocommerce'); ?></span>
                                                <span style="font-size: 10px; background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 10px; font-weight: 600;">Enterprise CRO</span>
                                            </div>
                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px;">
                                                <div>
                                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                        <?php esc_html_e('Traffic Allocation:', 'marketpulse-for-woocommerce'); ?>
                                                    </label>
                                                    <select id="mp-ab-alloc-select" name="marketpulse_ab_traffic_allocation" style="width: 100%; font-size: 12px;">
                                                        <option value="100" <?php selected($ab_allocation, '100'); ?>><?php esc_html_e('100% (Full 50/50 Split)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="50" <?php selected($ab_allocation, '50'); ?>><?php esc_html_e('50% (25% A / 25% B)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="20" <?php selected($ab_allocation, '20'); ?>><?php esc_html_e('20% (Low Risk 10% / 10%)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="10" <?php selected($ab_allocation, '10'); ?>><?php esc_html_e('10% (Cautious 5% / 5%)', 'marketpulse-for-woocommerce'); ?></option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                        <?php esc_html_e('Target Device:', 'marketpulse-for-woocommerce'); ?>
                                                    </label>
                                                    <select id="mp-ab-device-select" name="marketpulse_ab_target_device" style="width: 100%; font-size: 12px;">
                                                        <option value="ALL" <?php selected($ab_device, 'ALL'); ?>><?php esc_html_e('All Devices (Mobile + Desktop)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="MOBILE" <?php selected($ab_device, 'MOBILE'); ?>><?php esc_html_e('Mobile Devices Only', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="DESKTOP" <?php selected($ab_device, 'DESKTOP'); ?>><?php esc_html_e('Desktop Devices Only', 'marketpulse-for-woocommerce'); ?></option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                        <?php esc_html_e('Traffic Source:', 'marketpulse-for-woocommerce'); ?>
                                                    </label>
                                                    <select id="mp-ab-source-select" name="marketpulse_ab_target_source" style="width: 100%; font-size: 12px;">
                                                        <option value="ALL" <?php selected($ab_source, 'ALL'); ?>><?php esc_html_e('All Traffic Sources', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="FACEBOOK" <?php selected($ab_source, 'FACEBOOK'); ?>><?php esc_html_e('Facebook / IG Ads (fbclid/utm)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="GOOGLE" <?php selected($ab_source, 'GOOGLE'); ?>><?php esc_html_e('Google Ads & Search (gclid/utm)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="DIRECT" <?php selected($ab_source, 'DIRECT'); ?>><?php esc_html_e('Direct Visitors Only', 'marketpulse-for-woocommerce'); ?></option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #334155; margin-bottom: 4px;">
                                                        <?php esc_html_e('Visitor Type:', 'marketpulse-for-woocommerce'); ?>
                                                    </label>
                                                    <select id="mp-ab-user-type-select" name="marketpulse_ab_target_user_type" style="width: 100%; font-size: 12px;">
                                                        <option value="ALL" <?php selected($ab_user_type, 'ALL'); ?>><?php esc_html_e('All Visitors (New + Returning)', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="NEW" <?php selected($ab_user_type, 'NEW'); ?>><?php esc_html_e('New First-Time Visitors Only', 'marketpulse-for-woocommerce'); ?></option>
                                                        <option value="RETURNING" <?php selected($ab_user_type, 'RETURNING'); ?>><?php esc_html_e('Returning Customers Only', 'marketpulse-for-woocommerce'); ?></option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ================= RIGHT COLUMN: Live Interactive Mockup Studio ================= -->
                                    <div class="mp-ab-preview-col">
                                        <div class="mp-mockup-frame" id="mp-mockup-frame">
                                            <!-- Top Window Bar -->
                                            <div class="mp-mockup-topbar">
                                                <div class="mp-mockup-dots">
                                                    <span class="mp-dot-red"></span>
                                                    <span class="mp-dot-yellow"></span>
                                                    <span class="mp-dot-green"></span>
                                                </div>
                                                <div class="mp-mockup-url-bar">
                                                    mystore.com/product/<?php echo esc_html($current_product ? $current_product->get_slug() : 'sample-product'); ?>
                                                </div>
                                                <div class="mp-mockup-controls">
                                                    <button type="button" class="mp-toggle-btn mp-active" id="mp-btn-view-desktop" title="Preview on Desktop">💻</button>
                                                    <button type="button" class="mp-toggle-btn" id="mp-btn-view-mobile" title="Preview on Mobile">📱</button>
                                                    <span style="color:#cbd5e1; margin:0 2px;">|</span>
                                                    <button type="button" class="mp-toggle-btn mp-active" id="mp-btn-mockup-varb">⚡ Variant B</button>
                                                    <button type="button" class="mp-toggle-btn" id="mp-btn-mockup-ctrl">Control (A)</button>
                                                </div>
                                            </div>

                                            <!-- Mockup Product Card -->
                                            <div class="mp-mockup-body">
                                                <div class="mp-mockup-card">
                                                    <div class="mp-mockup-img-wrap">
                                                        <img id="mp-mockup-img" src="<?php echo esc_url($product_img_url); ?>" alt="<?php echo esc_attr($product_title); ?>">
                                                    </div>
                                                    <div class="mp-mockup-cat" id="mp-mockup-cat"><?php echo esc_html($cat_name); ?></div>
                                                    <h4 class="mp-mockup-title" id="mp-mockup-title"><?php echo esc_html($product_title); ?></h4>
                                                    <div class="mp-mockup-stars">
                                                        <span>★★★★★</span>
                                                        <span style="color: #64748b; font-size: 10px;">(4.9 · 47 reviews)</span>
                                                    </div>
                                                    <div class="mp-mockup-price-box" id="mp-mockup-price-wrap">
                                                        <span class="mp-mockup-price-main" id="mp-mockup-price-main"><?php echo esc_html($currency_symbol . number_format($current_price, 2)); ?></span>
                                                    </div>
                                                    <button type="button" class="mp-mockup-cta-btn" id="mp-mockup-cta-btn">
                                                        <?php echo esc_html($ab_btn ?: __('Add to Cart', 'marketpulse-for-woocommerce')); ?>
                                                    </button>
                                                    <div class="mp-mockup-trust">
                                                        <span>🛡️ <?php esc_html_e('Free Returns & Guarantee', 'marketpulse-for-woocommerce'); ?></span>
                                                        <span>⚡ <?php esc_html_e('Server-Side Zero-Flicker', 'marketpulse-for-woocommerce'); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="text-align: center; margin-top: 8px; font-size: 11px; color: #059669; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 5px;">
                                            <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #10b981;"></span>
                                            <?php esc_html_e('Live Interactive Mockup (Updates As You Type)', 'marketpulse-for-woocommerce'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Builder Footer Bar -->
                            <div class="mp-ab-builder-footer">
                                <div>
                                    <span id="mp-ab-summary-pill" style="font-size: 11px; font-family: monospace; background: #e2e8f0; color: #334155; padding: 4px 10px; border-radius: 4px; font-weight: 600;">
                                        Targeting: ALL DEVICES | Channel: ALL | Shopper: ALL | Allocation: 100%
                                    </span>
                                </div>
                                <?php
                                $product_permalink = get_permalink($post->ID);
                                if ($product_permalink) :
                                    $preview_b_url = add_query_arg('mp_variant', 'b', $product_permalink);
                                    $preview_a_url = add_query_arg('mp_variant', 'a', $product_permalink);
                                ?>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <a href="<?php echo esc_url($preview_b_url); ?>" target="_blank" class="button" style="background: #15803d; color: #fff; border-color: #166534; font-weight: 600; font-size: 12px;">
                                            ⚡ <?php esc_html_e('Preview Variant B (Test) ↗', 'marketpulse-for-woocommerce'); ?>
                                        </a>
                                        <a href="<?php echo esc_url($preview_a_url); ?>" target="_blank" class="button button-secondary" style="font-size: 12px;">
                                            <?php esc_html_e('Preview Control (Original) ↗', 'marketpulse-for-woocommerce'); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['marketpulse_meta_nonce']) || !wp_verify_nonce($_POST['marketpulse_meta_nonce'], 'marketpulse_save_product_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Local-First Save: Save to wp_postmeta immediately with full sanitization
        $raw_urls = isset($_POST['marketpulse_competitor_urls']) ? (array) $_POST['marketpulse_competitor_urls'] : array();
        $sanitized_urls = array();
        foreach ($raw_urls as $url) {
            $cleaned = esc_url_raw(trim($url));
            if (!empty($cleaned) && filter_var($cleaned, FILTER_VALIDATE_URL)) {
                $sanitized_urls[] = $cleaned;
            }
        }
        update_post_meta($post_id, '_marketpulse_competitor_urls', $sanitized_urls);

        // Security Layer 3: Whitelist sanitization for all repricing fields
        $allowed_strategies = array('beat_lowest', 'match_lowest', 'beat_average', 'match_average', 'beat_highest', 'match_highest');
        $raw_strategy = isset($_POST['marketpulse_strategy']) ? sanitize_text_field($_POST['marketpulse_strategy']) : 'beat_lowest';
        $strategy = in_array($raw_strategy, $allowed_strategies, true) ? $raw_strategy : 'beat_lowest';
        update_post_meta($post_id, '_marketpulse_strategy', $strategy);

        $amount = (isset($_POST['marketpulse_amount']) && is_numeric($_POST['marketpulse_amount'])) ? abs(floatval($_POST['marketpulse_amount'])) : null;
        update_post_meta($post_id, '_marketpulse_amount', $amount !== null ? $amount : '');

        $amount_type = (isset($_POST['marketpulse_amount_type']) && $_POST['marketpulse_amount_type'] === 'percent') ? 'percent' : 'fixed';
        update_post_meta($post_id, '_marketpulse_amount_type', $amount_type);

        $floor_price = (isset($_POST['marketpulse_floor_price']) && is_numeric($_POST['marketpulse_floor_price'])) ? abs(floatval($_POST['marketpulse_floor_price'])) : '';
        update_post_meta($post_id, '_marketpulse_floor_price', $floor_price);

        $ceiling_price = (isset($_POST['marketpulse_ceiling_price']) && is_numeric($_POST['marketpulse_ceiling_price'])) ? abs(floatval($_POST['marketpulse_ceiling_price'])) : '';
        // Boundary validation: ceiling must be >= floor if both set
        if ($ceiling_price !== '' && $floor_price !== '' && $ceiling_price < $floor_price) {
            $ceiling_price = $floor_price;
        }
        update_post_meta($post_id, '_marketpulse_ceiling_price', $ceiling_price);

        $is_active = !empty($_POST['marketpulse_active']) ? 1 : 0;
        update_post_meta($post_id, '_marketpulse_active', $is_active);

        $allowed_oos = array('none', 'raise_to_ceiling', 'raise_by_percent');
        $raw_oos = isset($_POST['marketpulse_oos_mode']) ? sanitize_text_field($_POST['marketpulse_oos_mode']) : 'none';
        $oos_mode = in_array($raw_oos, $allowed_oos, true) ? $raw_oos : 'none';
        update_post_meta($post_id, '_marketpulse_oos_mode', $oos_mode);

        $oos_percent = (isset($_POST['marketpulse_oos_percent']) && is_numeric($_POST['marketpulse_oos_percent'])) ? abs(floatval($_POST['marketpulse_oos_percent'])) : '';
        update_post_meta($post_id, '_marketpulse_oos_percent', $oos_percent);

        $min_margin = (isset($_POST['marketpulse_min_margin']) && is_numeric($_POST['marketpulse_min_margin'])) ? abs(floatval($_POST['marketpulse_min_margin'])) : '';
        update_post_meta($post_id, '_marketpulse_min_margin', $min_margin);

        // Schedule days (0=Sun..6=Sat)
        $raw_days = isset($_POST['marketpulse_schedule_days']) ? (array) $_POST['marketpulse_schedule_days'] : array();
        $schedule_days = array();
        foreach ($raw_days as $d) {
            $d_int = intval($d);
            if ($d_int >= 0 && $d_int <= 6) {
                $schedule_days[] = $d_int;
            }
        }
        update_post_meta($post_id, '_marketpulse_schedule_days', $schedule_days);

        // Schedule start and end hours (0..23)
        $start_hour = (isset($_POST['marketpulse_start_hour']) && is_numeric($_POST['marketpulse_start_hour'])) ? intval($_POST['marketpulse_start_hour']) : '';
        if ($start_hour !== '' && ($start_hour < 0 || $start_hour > 23)) {
            $start_hour = '';
        }
        update_post_meta($post_id, '_marketpulse_start_hour', $start_hour);

        $end_hour = (isset($_POST['marketpulse_end_hour']) && is_numeric($_POST['marketpulse_end_hour'])) ? intval($_POST['marketpulse_end_hour']) : '';
        if ($end_hour !== '' && ($end_hour < 0 || $end_hour > 23)) {
            $end_hour = '';
        }
        update_post_meta($post_id, '_marketpulse_end_hour', $end_hour);

        // Excluded competitor URLs
        $raw_excluded = isset($_POST['marketpulse_excluded_urls']) ? (array) $_POST['marketpulse_excluded_urls'] : array();
        $excluded_urls = array();
        foreach ($raw_excluded as $eu) {
            $cleaned = esc_url_raw(trim($eu));
            if (!empty($cleaned)) {
                $excluded_urls[] = $cleaned;
            }
        }
        update_post_meta($post_id, '_marketpulse_excluded_urls', $excluded_urls);

        // A/B Split Testing Settings Save
        $ab_enabled = isset($_POST['marketpulse_ab_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_marketpulse_ab_enabled', $ab_enabled);
        if (isset($_POST['marketpulse_ab_name'])) {
            update_post_meta($post_id, '_marketpulse_ab_name', sanitize_text_field($_POST['marketpulse_ab_name']));
        }
        if (isset($_POST['marketpulse_ab_type'])) {
            update_post_meta($post_id, '_marketpulse_ab_type', sanitize_text_field($_POST['marketpulse_ab_type']));
        }
        if (isset($_POST['marketpulse_ab_variant_b_price'])) {
            $raw_b_price = trim($_POST['marketpulse_ab_variant_b_price']);
            update_post_meta($post_id, '_marketpulse_ab_variant_b_price', is_numeric($raw_b_price) ? (float) $raw_b_price : '');
        }
        if (isset($_POST['marketpulse_ab_variant_b_title'])) {
            update_post_meta($post_id, '_marketpulse_ab_variant_b_title', sanitize_text_field($_POST['marketpulse_ab_variant_b_title']));
        }
        if (isset($_POST['marketpulse_ab_variant_b_image'])) {
            update_post_meta($post_id, '_marketpulse_ab_variant_b_image', esc_url_raw(trim($_POST['marketpulse_ab_variant_b_image'])));
        }
        if (isset($_POST['marketpulse_ab_variant_b_btn'])) {
            update_post_meta($post_id, '_marketpulse_ab_variant_b_btn', sanitize_text_field($_POST['marketpulse_ab_variant_b_btn']));
        }
        if (isset($_POST['marketpulse_ab_variant_b_color'])) {
            $raw_color = sanitize_hex_color($_POST['marketpulse_ab_variant_b_color']);
            update_post_meta($post_id, '_marketpulse_ab_variant_b_color', $raw_color ?: '#10B981');
        }
        if (isset($_POST['marketpulse_ab_traffic_allocation'])) {
            $raw_alloc = intval($_POST['marketpulse_ab_traffic_allocation']);
            update_post_meta($post_id, '_marketpulse_ab_traffic_allocation', ($raw_alloc > 0 && $raw_alloc <= 100) ? $raw_alloc : 100);
        }
        if (isset($_POST['marketpulse_ab_target_device'])) {
            $allowed_devices = array('ALL', 'MOBILE', 'DESKTOP');
            $device_val = sanitize_text_field($_POST['marketpulse_ab_target_device']);
            update_post_meta($post_id, '_marketpulse_ab_target_device', in_array($device_val, $allowed_devices, true) ? $device_val : 'ALL');
        }
        if (isset($_POST['marketpulse_ab_target_source'])) {
            $allowed_sources = array('ALL', 'FACEBOOK', 'GOOGLE', 'DIRECT');
            $source_val = sanitize_text_field($_POST['marketpulse_ab_target_source']);
            update_post_meta($post_id, '_marketpulse_ab_target_source', in_array($source_val, $allowed_sources, true) ? $source_val : 'ALL');
        }
        if (isset($_POST['marketpulse_ab_target_user_type'])) {
            $allowed_user_types = array('ALL', 'NEW', 'RETURNING');
            $user_type_val = sanitize_text_field($_POST['marketpulse_ab_target_user_type']);
            update_post_meta($post_id, '_marketpulse_ab_target_user_type', in_array($user_type_val, $allowed_user_types, true) ? $user_type_val : 'ALL');
        }

        // Cloud-First Two-Way Sync: push URLs, Repricing Rules, and A/B Testing to MarketPulse SaaS Cloud
        if (MarketPulse_Auth::is_paired()) {
            $product = wc_get_product($post_id);
            if ($product) {
                $price = $product->get_sale_price() ?: $product->get_regular_price() ?: 0.0;
                $ab_type_val  = isset($_POST['marketpulse_ab_type']) ? sanitize_text_field($_POST['marketpulse_ab_type']) : 'PRICE';
                $ab_img_val   = isset($_POST['marketpulse_ab_variant_b_image']) ? esc_url_raw(trim($_POST['marketpulse_ab_variant_b_image'])) : '';
                $ab_btn_val   = isset($_POST['marketpulse_ab_variant_b_btn']) ? sanitize_text_field($_POST['marketpulse_ab_variant_b_btn']) : '';
                $ab_clr_val   = isset($_POST['marketpulse_ab_variant_b_color']) ? sanitize_hex_color($_POST['marketpulse_ab_variant_b_color']) : '#10B981';
                $ab_ttl_val   = isset($_POST['marketpulse_ab_variant_b_title']) ? sanitize_text_field($_POST['marketpulse_ab_variant_b_title']) : '';
                $ab_prc_val   = (isset($_POST['marketpulse_ab_variant_b_price']) && is_numeric($_POST['marketpulse_ab_variant_b_price'])) ? (float) $_POST['marketpulse_ab_variant_b_price'] : null;
                $ab_alloc_val = isset($_POST['marketpulse_ab_traffic_allocation']) ? intval($_POST['marketpulse_ab_traffic_allocation']) : 100;
                $ab_dev_val   = isset($_POST['marketpulse_ab_target_device']) ? sanitize_text_field($_POST['marketpulse_ab_target_device']) : 'ALL';
                $ab_src_val   = isset($_POST['marketpulse_ab_target_source']) ? sanitize_text_field($_POST['marketpulse_ab_target_source']) : 'ALL';
                $ab_usr_val   = isset($_POST['marketpulse_ab_target_user_type']) ? sanitize_text_field($_POST['marketpulse_ab_target_user_type']) : 'ALL';

                $payload = array(
                    'sku'                    => $product->get_sku() ?: ('WC-' . $post_id),
                    'ownStoreProductId'      => (string) $post_id,
                    'title'                  => $product->get_name(),
                    'ourPrice'               => (float) $price,
                    'currency'               => get_woocommerce_currency(),
                    'competitorUrls'         => $sanitized_urls,
                    'strategy'               => $strategy,
                    'floorPrice'             => is_numeric($floor_price) ? (float) $floor_price : (float) $price,
                    'amount'                 => $amount !== null ? (float) $amount : null,
                    'amountType'             => $amount_type,
                    'ceilingPrice'           => is_numeric($ceiling_price) ? (float) $ceiling_price : null,
                    'isActive'               => (bool) $is_active,
                    'outOfStockMode'         => $oos_mode,
                    'outOfStockRaisePercent' => is_numeric($oos_percent) ? (float) $oos_percent : null,
                    'minMarginPercent'       => is_numeric($min_margin) ? (float) $min_margin : null,
                    'scheduleDaysOfWeek'     => $schedule_days,
                    'scheduleStartHourUtc'   => $start_hour !== '' ? $start_hour : null,
                    'scheduleEndHourUtc'     => $end_hour !== '' ? $end_hour : null,
                    'excludedCompetitorUrls' => $excluded_urls,
                    // 1:1 A/B Split-Testing Suite Synchronization with MarketPulse SaaS
                    'abEnabled'              => ($ab_enabled === 'yes'),
                    'abName'                 => isset($_POST['marketpulse_ab_name']) ? sanitize_text_field($_POST['marketpulse_ab_name']) : null,
                    'abType'                 => $ab_type_val,
                    'abVariantBPrice'        => $ab_prc_val,
                    'abVariantBTitle'        => $ab_ttl_val ?: null,
                    'abVariantBImage'        => $ab_img_val ?: null,
                    'abVariantBButton'       => $ab_btn_val ?: null,
                    'abVariantBColor'        => $ab_clr_val ?: null,
                    'abTrafficAllocation'    => $ab_alloc_val,
                    'abTargetDevice'         => $ab_dev_val,
                    'abTargetSource'         => $ab_src_val,
                    'abTargetUserType'       => $ab_usr_val,
                );

                $headers = MarketPulse_Auth::sign_request($payload);
                $headers['Content-Type'] = 'application/json';

                wp_remote_post(
                    MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/competitors',
                    array(
                        'headers'  => $headers,
                        'body'     => wp_json_encode($payload),
                        'timeout'  => 5,
                        'blocking' => false, // Non-blocking: doesn't delay WooCommerce product save!
                    )
                );

                // Clear cached status & products summary so Products Tracked & Badges update immediately
                delete_transient(MarketPulse_Auth::STATUS_TRANSIENT_KEY);
                delete_transient(MarketPulse_Auth::PRODUCTS_SUMMARY_TRANSIENT_KEY);
            }
        }
    }

    /**
     * AJAX Handler: Live check competitor product URL via MarketPulse Engine with SaaS Plan Cooldown Enforced.
     * Complies with all 6 Security Layers (Nonce, Capability, Sanitization, Escaping, Safe HTTP, Fail-safe).
     */
    public function ajax_live_check_url() {
        // Security Layer 1: Nonce Verification
        check_ajax_referer('marketpulse_admin_nonce', 'nonce');

        // Security Layer 2: Role & Capability Verification
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'marketpulse-for-woocommerce')), 403);
        }

        // Security Layer 3: Input Sanitization & Validation
        $url = isset($_POST['url']) ? esc_url_raw(trim($_POST['url'])) : '';
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => __('Please enter a valid HTTP or HTTPS product URL.', 'marketpulse-for-woocommerce')), 400);
        }

        if (!MarketPulse_Auth::is_paired()) {
            wp_send_json_error(array('message' => __('Please connect your store to MarketPulse to check competitor prices.', 'marketpulse-for-woocommerce')), 403);
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $sku     = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';

        $payload = array(
            'url'               => $url,
            'ownStoreProductId' => $post_id ? (string) $post_id : '',
            'sku'               => $sku,
        );

        $payload_json = wp_json_encode($payload);
        $auth_headers = MarketPulse_Auth::sign_request($payload_json);

        // Security Layer 4: External Service Communication via Safe wp_remote_post
        $endpoint = MARKETPULSE_API_BASE_URL . '/api/store-connection/wordpress/live-check';
        $is_local = (strpos(MARKETPULSE_API_BASE_URL, 'localhost') !== false || strpos(MARKETPULSE_API_BASE_URL, '127.0.0.1') !== false);
        $response = wp_remote_post($endpoint, array(
            'headers'     => array_merge(
                array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
                $auth_headers
            ),
            'body'        => $payload_json,
            'timeout'     => 45, // Allow up to 45s for deep headless browser rendering (Daraz, Amazon, etc.)
            'sslverify'   => !$is_local,
        ));

        // Security Layer 5: Defensive Error Handling
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Plan Cooldown Check (SaaS Parity)
        if (!empty($body['onCooldown'])) {
            wp_send_json_error(array(
                'onCooldown'       => true,
                'remainingMinutes' => isset($body['remainingMinutes']) ? (int) $body['remainingMinutes'] : 0,
                'intervalMinutes'  => isset($body['intervalMinutes']) ? (int) $body['intervalMinutes'] : 60,
                'message'          => !empty($body['message']) ? sanitize_text_field($body['message']) : __('This competitor URL was checked recently. Please wait before checking again.', 'marketpulse-for-woocommerce'),
            ));
        }

        if (($code !== 200 && $code !== 201) || empty($body) || empty($body['success']) || !isset($body['price'])) {
            $msg = !empty($body['message']) ? $body['message'] : __('Could not extract price from this URL. Please verify the link is a live product page.', 'marketpulse-for-woocommerce');
            wp_send_json_error(array('message' => $msg));
        }

        // Security Layer 6: Output Sanitization
        wp_send_json_success(array(
            'detected'   => !empty($body['detected']),
            'sourceType' => sanitize_text_field($body['sourceType'] ?? 'SCRAPER'),
            'price'      => isset($body['price']) && is_numeric($body['price']) ? (float) $body['price'] : null,
            'currency'   => sanitize_text_field($body['currency'] ?? ''),
            'inStock'    => !empty($body['inStock']),
            'title'      => sanitize_text_field($body['title'] ?? ''),
        ));
    }
}


class MarketPulse_Metabox extends MarketPulse_Product_MetaBox {}
