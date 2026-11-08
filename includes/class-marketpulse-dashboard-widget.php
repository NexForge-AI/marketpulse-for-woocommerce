<?php
/**
 * WP Admin Home Dashboard Summary Widget
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Dashboard_Widget {

    public function __construct() {
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
    }

    public function register_dashboard_widget() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'marketpulse_dashboard_widget',
            __('📊 MarketPulse Competitor Pricing & Repricing', 'marketpulse-for-woocommerce'),
            array($this, 'render_dashboard_widget')
        );
    }

    public function render_dashboard_widget() {
        $is_paired = MarketPulse_Auth::is_paired();
        if (!$is_paired) {
            ?>
            <div style="text-align: center; padding: 15px 5px;">
                <p style="color: #646970; font-size: 13px; margin-bottom: 12px;">
                    <?php esc_html_e('MarketPulse is not connected yet. Connect your store to unlock automated competitor price tracking.', 'marketpulse-for-woocommerce'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse')); ?>" class="button button-primary">
                    <?php esc_html_e('Connect Store (14-Day Free Trial) →', 'marketpulse-for-woocommerce'); ?>
                </a>
            </div>
            <?php
            return;
        }

        $status = MarketPulse_Auth::get_status();
        $is_locked = !empty($status['isLocked']);
        ?>
        <div class="mp-widget-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <div>
                    <strong><?php esc_html_e('Status:', 'marketpulse-for-woocommerce'); ?></strong>
                    <?php if ($is_locked) : ?>
                        <span style="color: #b32d2e; font-weight: 600;"><?php esc_html_e('🔒 Trial Expired', 'marketpulse-for-woocommerce'); ?></span>
                    <?php else : ?>
                        <span style="color: #1e7e34; font-weight: 600;"><?php esc_html_e('🟢 Active (Monitoring)', 'marketpulse-for-woocommerce'); ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <span style="background: #f0f0f1; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600;">
                        <?php echo esc_html($status['planName'] ?? 'Starter Plan'); ?>
                    </span>
                </div>
            </div>

            <?php $metrics = MarketPulse_Auth::get_roi_metrics(); ?>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 12px 0 14px;">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 8px 6px; text-align: center;">
                    <div style="font-size: 18px; font-weight: 700; color: #166534; line-height: 1.2;">
                        <?php echo absint($metrics['winning']); ?>
                    </div>
                    <div style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: #15803d; margin-top: 2px;">
                        <?php esc_html_e('Winning Prices', 'marketpulse-for-woocommerce'); ?>
                    </div>
                </div>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 8px 6px; text-align: center;">
                    <div style="font-size: 18px; font-weight: 700; color: #991b1b; line-height: 1.2;">
                        <?php echo absint($metrics['price_drops']); ?>
                    </div>
                    <div style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: #b91c1c; margin-top: 2px;">
                        <?php esc_html_e('Price Drops Caught', 'marketpulse-for-woocommerce'); ?>
                    </div>
                </div>
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 8px 6px; text-align: center;">
                    <div style="font-size: 18px; font-weight: 700; color: #1e40af; line-height: 1.2;">
                        <?php echo absint($metrics['total_tracked']); ?>
                    </div>
                    <div style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: #2563eb; margin-top: 2px;">
                        <?php esc_html_e('Links Monitored', 'marketpulse-for-woocommerce'); ?>
                    </div>
                </div>
            </div>

            <p style="font-size: 12px; color: #646970; margin: 6px 0 14px;">
                <?php if ($is_locked) : ?>
                    <?php esc_html_e('Your 14-day Starter trial has expired. Automatic repricing is paused.', 'marketpulse-for-woocommerce'); ?>
                <?php else : ?>
                    <?php printf(esc_html__('Automatic repricing active. %d days remaining on your Starter trial.', 'marketpulse-for-woocommerce'), absint($status['daysRemaining'] ?? 14)); ?>
                <?php endif; ?>
            </p>

            <div style="display: flex; gap: 8px;">
                <?php if ($is_locked) : ?>
                    <a href="<?php echo esc_url($status['upgradeUrl'] ?? MARKETPULSE_API_BASE_URL . '/select-plan?ref=wp_plugin'); ?>" target="_blank" class="button button-primary">
                        <?php esc_html_e('Upgrade to Starter ($29/mo) →', 'marketpulse-for-woocommerce'); ?>
                    </a>
                <?php else : ?>
                    <?php $sso_action_url = wp_nonce_url(admin_url('admin.php?page=marketpulse&action=marketpulse_sso'), 'marketpulse_sso_nonce'); ?>
                    <a href="<?php echo esc_url($sso_action_url); ?>" target="_blank" class="button button-primary">
                        <?php esc_html_e('View Live Dashboard ↗', 'marketpulse-for-woocommerce'); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse')); ?>" class="button">
                    <?php esc_html_e('Settings', 'marketpulse-for-woocommerce'); ?>
                </a>
            </div>
        </div>
        <?php
    }
}
