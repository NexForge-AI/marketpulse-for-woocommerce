<?php
/**
 * Admin Menu, Settings Page, User Guide, FAQ & Trial Notices
 *
 * @package MarketPulse_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class MarketPulse_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_notices', array($this, 'render_admin_notices'));

        // WooCommerce Products List Custom Column & Status Badges
        add_filter('manage_edit-product_columns', array($this, 'add_product_columns'), 20);
        add_action('manage_product_posts_custom_column', array($this, 'render_product_column'), 10, 2);

        // WooCommerce Quick Edit & Bulk Edit Integration
        add_action('quick_edit_custom_box', array($this, 'render_quick_edit_fields'), 10, 2);
        add_action('bulk_edit_custom_box', array($this, 'render_bulk_edit_fields'), 10, 2);
        add_action('woocommerce_product_quick_edit_save', array($this, 'save_quick_edit_fields'));
        add_action('woocommerce_product_bulk_edit_save', array($this, 'save_bulk_edit_fields'));
        add_action('wp_ajax_marketpulse_test_alert', array($this, 'ajax_send_test_alert'));
    }

    public function register_admin_menu() {
        $hook = add_submenu_page(
            'woocommerce',
            __('MarketPulse', 'marketpulse-for-woocommerce'),
            __('MarketPulse', 'marketpulse-for-woocommerce'),
            'manage_woocommerce',
            'marketpulse',
            array($this, 'render_settings_page')
        );

        add_action("load-{$hook}", array($this, 'add_help_tabs'));
    }

    /**
     * Add WordPress native Contextual Help Tabs (Top-Right Screen Options drawer).
     */
    public function add_help_tabs() {
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $screen->add_help_tab(array(
            'id'      => 'mp_overview',
            'title'   => __('Overview', 'marketpulse-for-woocommerce'),
            'content' => '<p><strong>' . __('MarketPulse for WooCommerce', 'marketpulse-for-woocommerce') . '</strong></p><p>' . __('MarketPulse connects your WooCommerce store to the enterprise-grade MarketPulse Cloud cluster for real-time competitor price tracking and automated algorithmic repricing.', 'marketpulse-for-woocommerce') . '</p>',
        ));

        $screen->add_help_tab(array(
            'id'      => 'mp_strategies',
            'title'   => __('Repricing Strategies', 'marketpulse-for-woocommerce'),
            'content' => '<p>' . __('Choose from 6 strategies (Match/Beat Lowest, Match/Beat Average, Match/Beat Highest). Always set a Floor Price as a safety net so your price never drops below your minimum acceptable margin.', 'marketpulse-for-woocommerce') . '</p>',
        ));

        $screen->set_help_sidebar(
            '<p><strong>' . __('Documentation', 'marketpulse-for-woocommerce') . '</strong></p>' .
            '<p><a href="' . esc_url(admin_url('admin.php?page=marketpulse&tab=guide')) . '">' . __('📖 Read Full User Guide →', 'marketpulse-for-woocommerce') . '</a></p>' .
            '<p><a href="' . esc_url(admin_url('admin.php?page=marketpulse&tab=faq')) . '">' . __('❓ View FAQ & Troubleshooting →', 'marketpulse-for-woocommerce') . '</a></p>'
        );
    }

    public function handle_admin_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'marketpulse') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized access.', 'marketpulse-for-woocommerce'));
        }

        // Handle Connect / Pairing Action
        if (isset($_POST['marketpulse_action']) && $_POST['marketpulse_action'] === 'pair') {
            check_admin_referer('marketpulse_pair_nonce');
            $result = MarketPulse_Auth::pair_store();
            if (is_wp_error($result)) {
                add_settings_error('marketpulse_messages', 'pair_error', $result->get_error_message(), 'error');
            } else {
                add_settings_error('marketpulse_messages', 'pair_success', __('Store successfully paired with MarketPulse! Your 14-day Starter trial is now active.', 'marketpulse-for-woocommerce'), 'updated');
            }
        }

        // Handle Disconnect Action
        if (isset($_POST['marketpulse_action']) && $_POST['marketpulse_action'] === 'disconnect') {
            check_admin_referer('marketpulse_disconnect_nonce');
            MarketPulse_Auth::disconnect();
            add_settings_error('marketpulse_messages', 'disconnect_success', __('MarketPulse has been disconnected.', 'marketpulse-for-woocommerce'), 'updated');
        }

        // Handle Save Opt-in Settings Action
        if (isset($_POST['marketpulse_action']) && $_POST['marketpulse_action'] === 'save_settings') {
            check_admin_referer('marketpulse_settings_nonce');
            $enable_badge = !empty($_POST['marketpulse_enable_trust_badge']) ? 1 : 0;
            update_option('marketpulse_enable_trust_badge', $enable_badge);
            add_settings_error('marketpulse_messages', 'settings_saved', __('Settings saved successfully.', 'marketpulse-for-woocommerce'), 'updated');
        }

        // Handle Save Alert Settings Action
        if (isset($_POST['marketpulse_action']) && $_POST['marketpulse_action'] === 'save_alert_settings') {
            check_admin_referer('marketpulse_alert_settings_nonce');

            $settings = array(
                'whatsappEnabled'   => !empty($_POST['marketpulse_whatsapp_enabled']),
                'whatsappNumber'    => isset($_POST['marketpulse_whatsapp_number']) ? sanitize_text_field($_POST['marketpulse_whatsapp_number']) : '',
                'telegramEnabled'   => !empty($_POST['marketpulse_telegram_enabled']),
                'telegramChatId'    => isset($_POST['marketpulse_telegram_chat_id']) ? sanitize_text_field($_POST['marketpulse_telegram_chat_id']) : '',
                'alertOnPriceDrop'  => !empty($_POST['marketpulse_alert_price_drop']),
                'alertOnOutOfStock' => !empty($_POST['marketpulse_alert_out_of_stock']),
            );

            $result = MarketPulse_Auth::save_alert_settings($settings);
            if (is_wp_error($result)) {
                add_settings_error('marketpulse_messages', 'alert_settings_error', sprintf(__('Failed to save mobile alert settings: %s', 'marketpulse-for-woocommerce'), $result->get_error_message()), 'error');
            } else {
                add_settings_error('marketpulse_messages', 'alert_settings_saved', __('Mobile alert settings saved and synced across all monitored products.', 'marketpulse-for-woocommerce'), 'updated');
            }
        }

        // Handle Force Refresh Action
        if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
            check_admin_referer('marketpulse_refresh_nonce');
            MarketPulse_Auth::get_status(true);
            MarketPulse_Auth::get_products_summary(true);
            wp_safe_redirect(admin_url('admin.php?page=marketpulse'));
            exit;
        }

        // Handle 1-Click SSO Auto-Login Action (Security Layer 1: Capability & Nonce validation)
        if (isset($_GET['action']) && $_GET['action'] === 'marketpulse_sso') {
            check_admin_referer('marketpulse_sso_nonce');
            $sso_url = MarketPulse_Auth::get_sso_url();
            if (is_wp_error($sso_url)) {
                add_settings_error('marketpulse_messages', 'sso_error', $sso_url->get_error_message(), 'error');
            } else {
                wp_redirect(esc_url_raw($sso_url));
                exit;
            }
        }
    }

    /**
     * Render the persistent trial expiration warning banner across wp-admin.
     */
    public function render_admin_notices() {
        if (!class_exists('WooCommerce')) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('MarketPulse for WooCommerce', 'marketpulse-for-woocommerce'); ?></strong>:
                    <?php esc_html_e('WooCommerce is required for MarketPulse to function. Please install and activate WooCommerce.', 'marketpulse-for-woocommerce'); ?>
                </p>
            </div>
            <?php
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!MarketPulse_Auth::is_paired()) {
            return;
        }

        $status = MarketPulse_Auth::get_status();
        if (empty($status['isLocked'])) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'marketpulse') !== false) {
            return;
        }

        $upgrade_url = $status['upgradeUrl'] ?? (MARKETPULSE_DASHBOARD_URL . '/select-plan?ref=wp_plugin');
        ?>
        <div class="notice notice-warning is-dismissible" style="border-left-color: #d63638;">
            <p>
                <strong><?php esc_html_e('MarketPulse Repricing Alert:', 'marketpulse-for-woocommerce'); ?></strong>
                <?php esc_html_e('Your 14-day Starter trial has expired. Automatic repricing and competitor tracking are currently paused.', 'marketpulse-for-woocommerce'); ?>
                <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" style="font-weight: 600; margin-left: 6px;">
                    <?php esc_html_e('Upgrade to Starter ($29/mo) to resume →', 'marketpulse-for-woocommerce'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render the main MarketPulse Admin Page with Tabs.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized access.', 'marketpulse-for-woocommerce'));
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        if (!in_array($active_tab, array('settings', 'guide', 'faq'), true)) {
            $active_tab = 'settings';
        }

        $is_paired      = MarketPulse_Auth::is_paired();
        $status         = $is_paired ? MarketPulse_Auth::get_status() : array();
        $alert_settings = $is_paired ? MarketPulse_Auth::get_alert_settings() : array();
        $logo_url       = plugins_url('assets/logo.png', dirname(__FILE__));
        $banner_url     = plugins_url('assets/banner-772x250.png', dirname(__FILE__));
        ?>
        <div class="wrap mp-settings-wrap" style="max-width: 980px;">
            <!-- Top Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-top: 15px; margin-bottom: 16px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="MarketPulse Logo" style="height: 38px; width: auto; vertical-align: middle;">
                    <div>
                        <h1 class="wp-heading-inline" style="margin: 0; padding: 0; font-size: 22px; font-weight: 700; color: #0f172a;">
                            <?php esc_html_e('MarketPulse for WooCommerce', 'marketpulse-for-woocommerce'); ?>
                        </h1>
                        <p style="margin: 2px 0 0; font-size: 12px; color: #64748b;">
                            <?php esc_html_e('Autonomous Competitor Tracking, Dynamic Repricing & Margin Intelligence', 'marketpulse-for-woocommerce'); ?>
                        </p>
                    </div>
                </div>

                <div style="display: flex; gap: 8px;">
                    <?php if ($is_paired) : ?>
                        <?php $sso_action_url = wp_nonce_url(admin_url('admin.php?page=marketpulse&action=marketpulse_sso'), 'marketpulse_sso_nonce'); ?>
                        <a href="<?php echo esc_url($sso_action_url); ?>" target="_blank" class="button button-primary" style="font-weight: 600;">
                            <?php esc_html_e('Open Cloud Dashboard ↗', 'marketpulse-for-woocommerce'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab Navigation (WordPress Native Tabs) -->
            <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ <?php esc_html_e('Settings & Connection', 'marketpulse-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse&tab=guide')); ?>" class="nav-tab <?php echo $active_tab === 'guide' ? 'nav-tab-active' : ''; ?>">
                    📖 <?php esc_html_e('User Guide & Strategies', 'marketpulse-for-woocommerce'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=marketpulse&tab=faq')); ?>" class="nav-tab <?php echo $active_tab === 'faq' ? 'nav-tab-active' : ''; ?>">
                    ❓ <?php esc_html_e('FAQ & Troubleshooting', 'marketpulse-for-woocommerce'); ?>
                </a>
            </nav>

            <?php
            if (function_exists('settings_errors')) {
                settings_errors('marketpulse_messages');
            }
            ?>

            <?php
            if ($active_tab === 'guide') {
                $this->render_user_guide_tab();
            } elseif ($active_tab === 'faq') {
                $this->render_faq_tab();
            } else {
                $this->render_settings_tab($is_paired, $status, $alert_settings, $banner_url);
            }
            ?>
        </div>
        <?php
    }

    /**
     * Tab 1: Settings & Store Connection Hub
     */
    private function render_settings_tab($is_paired, $status, $alert_settings, $banner_url) {
        ?>
        <div class="mp-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; padding: 24px; box-shadow: 0 2px 6px rgba(0,0,0,0.04);">
            <?php if (!$is_paired) : ?>
                <div style="text-align: center; padding: 10px;">
                    <img src="<?php echo esc_url($banner_url); ?>" alt="MarketPulse Banner" style="max-width: 100%; height: auto; border-radius: 6px; margin-bottom: 24px; box-shadow: 0 6px 18px rgba(0,0,0,0.12);">
                    <h2 style="font-size: 22px; color: #1e1e1e; margin-bottom: 8px;">
                        <?php esc_html_e('Automate Repricing & Monitor Competitors', 'marketpulse-for-woocommerce'); ?>
                    </h2>
                    <p style="font-size: 14px; color: #646970; max-width: 520px; margin: 0 auto 24px; line-height: 1.5;">
                        <?php esc_html_e('Connect your WooCommerce store to MarketPulse in 1 click. You will instantly get a 14-day Free Trial of the Starter Plan (50 products, 100 competitor links, 60-min automatic price updates).', 'marketpulse-for-woocommerce'); ?>
                    </p>

                    <form method="post">
                        <?php wp_nonce_field('marketpulse_pair_nonce'); ?>
                        <input type="hidden" name="marketpulse_action" value="pair">
                        <button type="submit" class="button button-primary button-hero" style="background: #2271b1; font-weight: 600;">
                            <?php esc_html_e('Connect to MarketPulse (1-Click) →', 'marketpulse-for-woocommerce'); ?>
                        </button>
                    </form>
                </div>
            <?php else : ?>
                <div class="mp-connected-status">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                        <div>
                            <h2 style="margin: 0; font-size: 18px; color: #1e1e1e;">
                                <?php esc_html_e('Store Connected', 'marketpulse-for-woocommerce'); ?>
                            </h2>
                            <p style="margin: 4px 0 0; color: #646970; font-size: 13px;">
                                <?php echo esc_html(get_site_url()); ?>
                            </p>
                        </div>
                        <div>
                            <?php if (!empty($status['isLocked'])) : ?>
                                <span style="background: #fcf0f0; color: #b32d2e; border: 1px solid #f5c6cb; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px;">
                                    <?php esc_html_e('🔒 Trial Expired', 'marketpulse-for-woocommerce'); ?>
                                </span>
                            <?php elseif (!empty($status['status']) && $status['status'] === 'ACTIVE') : ?>
                                <span style="background: #ecf7ed; color: #1e7e34; border: 1px solid #c3e6cb; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px;">
                                    <?php esc_html_e('🟢 Active Subscription', 'marketpulse-for-woocommerce'); ?>
                                </span>
                            <?php else : ?>
                                <span style="background: #e7f5fe; color: #0073aa; border: 1px solid #bfe7f6; padding: 4px 10px; border-radius: 12px; font-weight: 600; font-size: 12px;">
                                    <?php printf(esc_html__('🟢 Starter Trial: %d days left', 'marketpulse-for-woocommerce'), absint($status['daysRemaining'] ?? 14)); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <table class="form-table" style="margin-bottom: 20px;">
                        <tr>
                            <th scope="row"><?php esc_html_e('Current Plan:', 'marketpulse-for-woocommerce'); ?></th>
                            <td><strong><?php echo esc_html($status['planName'] ?? 'Starter Plan'); ?></strong></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Products Tracked:', 'marketpulse-for-woocommerce'); ?></th>
                            <td>
                                <?php printf(esc_html__('%d / %d products', 'marketpulse-for-woocommerce'), absint($status['productsTracked'] ?? 0), absint($status['productLimit'] ?? 50)); ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Price Check Frequency:', 'marketpulse-for-woocommerce'); ?></th>
                            <td><?php esc_html_e('Every 60 minutes (Automatic Cloud Repricing)', 'marketpulse-for-woocommerce'); ?></td>
                        </tr>
                    </table>

                    <div style="display: flex; gap: 10px; align-items: center; border-top: 1px solid #eee; padding-top: 16px;">
                        <?php $sso_action_url = wp_nonce_url(admin_url('admin.php?page=marketpulse&action=marketpulse_sso'), 'marketpulse_sso_nonce'); ?>
                        <?php $refresh_url = wp_nonce_url(admin_url('admin.php?page=marketpulse&refresh=1'), 'marketpulse_refresh_nonce'); ?>
                        <a href="<?php echo esc_url($sso_action_url); ?>" target="_blank" class="button button-primary" style="font-weight: 600;">
                            <?php esc_html_e('Open MarketPulse Dashboard ↗', 'marketpulse-for-woocommerce'); ?>
                        </a>
                        <a href="<?php echo esc_url($refresh_url); ?>" class="button">
                            <?php esc_html_e('Refresh Status', 'marketpulse-for-woocommerce'); ?>
                        </a>
                        <form method="post" style="margin-left: auto;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to disconnect this store?', 'marketpulse-for-woocommerce')); ?>');">
                            <?php wp_nonce_field('marketpulse_disconnect_nonce'); ?>
                            <input type="hidden" name="marketpulse_action" value="disconnect">
                            <button type="submit" class="button button-link-delete" style="color: #b32d2e;">
                                <?php esc_html_e('Disconnect', 'marketpulse-for-woocommerce'); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Opt-in Features & Conversion Boosters -->
                    <div style="border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px;">
                        <h3 style="font-size: 15px; font-weight: 600; color: #1e1e1e; margin-bottom: 10px;">
                            <?php esc_html_e('Conversion & Trust Boosters', 'marketpulse-for-woocommerce'); ?>
                        </h3>
                        <form method="post" action="">
                            <?php wp_nonce_field('marketpulse_settings_nonce'); ?>
                            <input type="hidden" name="marketpulse_action" value="save_settings">
                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 13px; color: #1e1e1e; line-height: 1.4;">
                                <input type="checkbox" name="marketpulse_enable_trust_badge" value="1" <?php checked((bool) get_option('marketpulse_enable_trust_badge', false)); ?> style="margin-top: 2px;">
                                <span>
                                    <strong><?php esc_html_e('Show "Best Market Price" Trust Badge on Product Pages', 'marketpulse-for-woocommerce'); ?></strong><br>
                                    <span style="color: #646970;">
                                        <?php esc_html_e('Displays a subtle, high-converting trust badge below the price on products with active competitor monitoring, reassuring shoppers they are getting the best market deal.', 'marketpulse-for-woocommerce'); ?>
                                    </span>
                                </span>
                            </label>
                            <div style="margin-top: 14px;">
                                <button type="submit" class="button button-secondary">
                                    <?php esc_html_e('Save Settings', 'marketpulse-for-woocommerce'); ?>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Global Mobile Alerts Hub (WhatsApp & Telegram) -->
                    <div style="border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                            <h3 style="font-size: 16px; font-weight: 600; color: #1e1e1e; margin: 0; display: flex; align-items: center; gap: 8px;">
                                <span>📱 <?php esc_html_e('Instant Mobile Alerts (WhatsApp & Telegram)', 'marketpulse-for-woocommerce'); ?></span>
                            </h3>
                            <span style="background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; border: 1px solid #bfdbfe;">
                                <?php esc_html_e('Global Alert Hub', 'marketpulse-for-woocommerce'); ?>
                            </span>
                        </div>
                        <p style="font-size: 13px; color: #646970; margin: 0 0 16px; line-height: 1.4;">
                            <?php esc_html_e('Receive instant push notifications on your phone whenever competitors drop their prices or run out of stock. All monitored products automatically inherit these settings.', 'marketpulse-for-woocommerce'); ?>
                        </p>

                        <form method="post" action="">
                            <?php wp_nonce_field('marketpulse_alert_settings_nonce'); ?>
                            <input type="hidden" name="marketpulse_action" value="save_alert_settings">

                            <!-- WhatsApp Alerts Box -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; color: #0f172a; cursor: pointer;">
                                        <input type="checkbox" name="marketpulse_whatsapp_enabled" value="1" <?php checked(!empty($alert_settings['whatsappEnabled'])); ?> style="margin-top: 0;">
                                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                                            <span style="color: #25d366; font-size: 16px;">💬</span>
                                            <?php esc_html_e('WhatsApp Price Alerts', 'marketpulse-for-woocommerce'); ?>
                                        </span>
                                    </label>
                                    <button type="button" class="button button-secondary mp-test-alert-btn" data-channel="WHATSAPP" style="font-size: 12px; height: 30px; line-height: 28px;">
                                        <?php esc_html_e('⚡ Send Test WhatsApp Alert', 'marketpulse-for-woocommerce'); ?>
                                    </button>
                                </div>
                                <div style="margin-left: 24px;">
                                    <label for="mp_whatsapp_number" style="display: block; font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px;">
                                        <?php esc_html_e('WhatsApp Phone Number (with country code):', 'marketpulse-for-woocommerce'); ?>
                                    </label>
                                    <input type="text" id="mp_whatsapp_number" name="marketpulse_whatsapp_number" value="<?php echo esc_attr($alert_settings['whatsappNumber'] ?? ''); ?>" placeholder="e.g. 8801700000000 or 14155552671" style="width: 100%; max-width: 340px; font-family: monospace; font-size: 13px;">
                                    <p class="description" style="margin-top: 4px; font-size: 12px; color: #64748b;">
                                        <?php esc_html_e('Include international country code without "+" or spaces (e.g. 88017XXXXXXXX).', 'marketpulse-for-woocommerce'); ?>
                                    </p>
                                    <div class="mp-test-alert-feedback" data-channel="WHATSAPP" style="display: none; margin-top: 8px; padding: 8px 12px; border-radius: 6px; font-size: 12px;"></div>
                                </div>
                            </div>

                            <!-- Telegram Alerts Box -->
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; color: #0f172a; cursor: pointer;">
                                        <input type="checkbox" name="marketpulse_telegram_enabled" value="1" <?php checked(!empty($alert_settings['telegramEnabled'])); ?> style="margin-top: 0;">
                                        <span style="display: inline-flex; align-items: center; gap: 6px;">
                                            <span style="color: #229ed9; font-size: 16px;">✈️</span>
                                            <?php esc_html_e('Telegram Price Alerts', 'marketpulse-for-woocommerce'); ?>
                                        </span>
                                    </label>
                                    <button type="button" class="button button-secondary mp-test-alert-btn" data-channel="TELEGRAM" style="font-size: 12px; height: 30px; line-height: 28px;">
                                        <?php esc_html_e('⚡ Send Test Telegram Alert', 'marketpulse-for-woocommerce'); ?>
                                    </button>
                                </div>
                                <div style="margin-left: 24px;">
                                    <label for="mp_telegram_chat_id" style="display: block; font-size: 12px; font-weight: 500; color: #475569; margin-bottom: 4px;">
                                        <?php esc_html_e('Telegram Chat ID or Channel / Group ID:', 'marketpulse-for-woocommerce'); ?>
                                    </label>
                                    <input type="text" id="mp_telegram_chat_id" name="marketpulse_telegram_chat_id" value="<?php echo esc_attr($alert_settings['telegramChatId'] ?? ''); ?>" placeholder="e.g. 123456789 or @YourStoreAlerts" style="width: 100%; max-width: 340px; font-family: monospace; font-size: 13px;">
                                    <p class="description" style="margin-top: 4px; font-size: 12px; color: #64748b;">
                                        <?php esc_html_e('Start a chat with your MarketPulse Telegram bot or send /start to @userinfobot to find your Telegram Chat ID.', 'marketpulse-for-woocommerce'); ?>
                                    </p>
                                    <div class="mp-test-alert-feedback" data-channel="TELEGRAM" style="display: none; margin-top: 8px; padding: 8px 12px; border-radius: 6px; font-size: 12px;"></div>
                                </div>
                            </div>

                            <!-- Alert Triggers -->
                            <div style="margin-bottom: 16px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;">
                                <div style="font-size: 13px; font-weight: 600; color: #1e1e1e; margin-bottom: 8px;">
                                    <?php esc_html_e('Alert Triggers:', 'marketpulse-for-woocommerce'); ?>
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; cursor: pointer;">
                                        <input type="checkbox" name="marketpulse_alert_price_drop" value="1" <?php checked($alert_settings['alertOnPriceDrop'] !== false); ?>>
                                        <span><strong><?php esc_html_e('Competitor Price Drop', 'marketpulse-for-woocommerce'); ?></strong> — <?php esc_html_e('Trigger immediate alert when competitor lowers price', 'marketpulse-for-woocommerce'); ?></span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #334155; cursor: pointer;">
                                        <input type="checkbox" name="marketpulse_alert_out_of_stock" value="1" <?php checked($alert_settings['alertOnOutOfStock'] !== false); ?>>
                                        <span><strong><?php esc_html_e('Competitor Out of Stock / Restock', 'marketpulse-for-woocommerce'); ?></strong> — <?php esc_html_e('Trigger alert when competitor runs out of stock or restocks', 'marketpulse-for-woocommerce'); ?></span>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <button type="submit" class="button button-primary" style="font-weight: 600;">
                                    <?php esc_html_e('Save Mobile Alert Settings', 'marketpulse-for-woocommerce'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Tab 2: Comprehensive User Guide & Repricing Playbook (English)
     */
    private function render_user_guide_tab() {
        ?>
        <div class="mp-guide-wrap" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <!-- Title banner -->
            <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px;">
                <span style="background: #eff6ff; color: #2563eb; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 12px; border: 1px solid #bfdbfe;">
                    Official Documentation
                </span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 12px 0 6px;">
                    <?php esc_html_e('MarketPulse User Guide & Automated Repricing Playbook', 'marketpulse-for-woocommerce'); ?>
                </h2>
                <p style="font-size: 14px; color: #64748b; margin: 0; line-height: 1.6;">
                    <?php esc_html_e('Everything you need to know about competitor price tracking, algorithmic repricing strategies, safety floor protections, and instant mobile alerts.', 'marketpulse-for-woocommerce'); ?>
                </p>
            </div>

            <!-- Quick Jump Navigation -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; background: #f8fafc; padding: 12px 14px; border-radius: 6px; border: 1px solid #e2e8f0;">
                <span style="font-size: 12px; font-weight: 600; color: #475569; align-self: center; margin-right: 4px;">Jump to:</span>
                <a href="#guide-quickstart" class="button button-secondary" style="font-size: 12px;">🚀 Quick Start (4 Steps)</a>
                <a href="#guide-strategies" class="button button-secondary" style="font-size: 12px;">⚖️ The 6 Strategies</a>
                <a href="#guide-safeties" class="button button-secondary" style="font-size: 12px;">🛡️ Safety Guards (Floor & Ceiling)</a>
                <a href="#guide-manual-vs-auto" class="button button-secondary" style="font-size: 12px;">⚡ Manual vs. Automated Edits</a>
                <a href="#guide-stockout" class="button button-secondary" style="font-size: 12px;">📦 Out-Of-Stock Pricing</a>
                <a href="#guide-alerts" class="button button-secondary" style="font-size: 12px;">📱 WhatsApp & Telegram</a>
                <a href="#guide-badges" class="button button-secondary" style="font-size: 12px;">🏷️ Status Badges</a>
            </div>

            <!-- Section 1: Quick Start in 4 Steps -->
            <div id="guide-quickstart" style="margin-bottom: 34px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                    <span>🚀 1. Quick Start in 4 Simple Steps</span>
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px;">
                    <!-- Step 1 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; font-weight: 700; color: #2563eb; text-transform: uppercase;">Step 1</div>
                        <h4 style="margin: 6px 0 8px; font-size: 14px; color: #0f172a;">Connect Store</h4>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                            Go to <strong>Settings & Connection</strong> tab and click <em>Connect to MarketPulse (1-Click)</em>. Your 14-day Starter trial activates instantly.
                        </p>
                    </div>

                    <!-- Step 2 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; font-weight: 700; color: #2563eb; text-transform: uppercase;">Step 2</div>
                        <h4 style="margin: 6px 0 8px; font-size: 14px; color: #0f172a;">Add Competitor URLs</h4>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                            Edit any product in WooCommerce. In the <strong>MarketPulse</strong> metabox, paste competitor links (Amazon, Daraz, eBay, Shopify, etc.). Click <strong>⚡ Check Price</strong> to test live detection.
                        </p>
                    </div>

                    <!-- Step 3 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; font-weight: 700; color: #2563eb; text-transform: uppercase;">Step 3</div>
                        <h4 style="margin: 6px 0 8px; font-size: 14px; color: #0f172a;">Set Strategy & Floor</h4>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                            Choose your repricing strategy (e.g. <em>Match lowest</em> or <em>Beat lowest by $1</em>). Enter a mandatory <strong>Floor Price</strong> as your absolute bottom price.
                        </p>
                    </div>

                    <!-- Step 4 -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; font-weight: 700; color: #16a34a; text-transform: uppercase;">Step 4</div>
                        <h4 style="margin: 6px 0 8px; font-size: 14px; color: #0f172a;">Enable Active Toggle</h4>
                        <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.5;">
                            Check <strong>[✓] Active</strong> and save the product. MarketPulse Cloud will continuously monitor competitor prices and automatically update your store price live!
                        </p>
                    </div>
                </div>
            </div>

            <!-- Section 2: The 6 Repricing Strategies -->
            <div id="guide-strategies" style="margin-bottom: 34px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>⚖️ 2. The 6 Repricing Strategies Explained</span>
                </h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                    Select the strategy that best matches your business model, catalog volume, and profit margins.
                </p>

                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <!-- Strategy 1 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #2563eb; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">1. Match the lowest competitor exactly</strong>
                            <span style="background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Parity Strategy</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Lowest Competitor Price</code><br>
                            <em>Example:</em> If the cheapest competitor is at $100.00, your price will automatically become <strong>$100.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: Neutral matching without triggering an aggressive downward price war.</small>
                    </div>

                    <!-- Strategy 2 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #16a34a; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">2. Beat the lowest competitor by (Fixed or %)</strong>
                            <span style="background: #f0fdf4; color: #15803d; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Buy Box Winner</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Lowest Competitor Price - Offset</code><br>
                            <em>Fixed Example:</em> Lowest competitor is $100.00, Undercut is $1.00 &rarr; Your price becomes <strong>$99.00</strong>.<br>
                            <em>Percent Example:</em> Lowest competitor is $100.00, Undercut is 5% &rarr; Your price becomes <strong>$95.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: Aggressively winning Google Shopping, price comparison engines, and cost-conscious shoppers.</small>
                    </div>

                    <!-- Strategy 3 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #6366f1; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">3. Match the average competitor exactly</strong>
                            <span style="background: #eef2ff; color: #4338ca; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Market Balance</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Mean Average of all active competitors</code><br>
                            <em>Example:</em> Three competitors priced at $90, $100, and $110 (Average $100) &rarr; Your price becomes <strong>$100.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: Stable pricing in competitive markets without getting dragged down by a single rogue discounter.</small>
                    </div>

                    <!-- Strategy 4 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #0891b2; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">4. Beat the average competitor by</strong>
                            <span style="background: #ecfeff; color: #0e7490; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Smart Value</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Market Average - Offset</code><br>
                            <em>Example:</em> Market average is $100.00, Undercut is $3.00 &rarr; Your price becomes <strong>$97.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: Positioning below the general market without racing all the way to the absolute bottom.</small>
                    </div>

                    <!-- Strategy 5 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #d97706; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">5. Position below the highest competitor</strong>
                            <span style="background: #fffbeb; color: #b45309; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Premium Value</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Highest Competitor Price - Offset</code><br>
                            <em>Example:</em> Most expensive competitor is $150.00, Undercut is $10.00 &rarr; Your price becomes <strong>$140.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: High-ticket or luxury items where perceived brand quality demands a premium price point.</small>
                    </div>

                    <!-- Strategy 6 -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 4px solid #7c3aed; border-radius: 6px; padding: 14px 16px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <strong style="font-size: 14px; color: #0f172a;">6. Match the highest competitor exactly</strong>
                            <span style="background: #f5f3ff; color: #6d28d9; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px;">Maximum Margin</span>
                        </div>
                        <p style="margin: 0 0 6px; font-size: 12px; color: #334155; line-height: 1.5;">
                            <strong>Formula:</strong> <code>Your Price = Highest Competitor Price</code><br>
                            <em>Example:</em> Highest competitor is $150.00 &rarr; Your price becomes <strong>$150.00</strong>.
                        </p>
                        <small style="color: #64748b; font-size: 11px;">Best for: Rare inventory, exclusive distribution rights, or peak-demand items.</small>
                    </div>
                </div>
            </div>

            <!-- Section 3: Safety Guards (Floor & Ceiling) -->
            <div id="guide-safeties" style="margin-bottom: 34px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>🛡️ 3. Safety Net Guards: Protect Your Profits</span>
                </h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 16px;">
                    Automated repricing is powerful, but without safeguards it can lead to unintentional losses. MarketPulse enforces 3 layers of hard safety nets:
                </p>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                    <!-- Floor Price Card -->
                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px;">
                        <h4 style="margin: 0 0 8px; font-size: 14px; color: #991b1b; display: flex; align-items: center; gap: 6px;">
                            <span>⛔ Floor Price (Mandatory)</span>
                        </h4>
                        <p style="margin: 0; font-size: 12px; color: #7f1d1d; line-height: 1.5;">
                            This is your <strong>hard safety floor</strong>. No matter how low competitors drop their prices or even if they make a pricing error, MarketPulse will <strong>NEVER</strong> propose or push a price below your Floor Price.<br>
                            <code>Final Price = MAX(Computed Price, Floor Price)</code>
                        </p>
                    </div>

                    <!-- Ceiling Price Card -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px;">
                        <h4 style="margin: 0 0 8px; font-size: 14px; color: #166534; display: flex; align-items: center; gap: 6px;">
                            <span>🛑 Ceiling Price (Optional)</span>
                        </h4>
                        <p style="margin: 0; font-size: 12px; color: #14532d; line-height: 1.5;">
                            This is your <strong>maximum allowable price</strong>. It prevents your product from becoming unreasonably expensive if competitors suffer supply shortages or set sky-high prices.<br>
                            <code>Final Price = MIN(Computed Price, Ceiling Price)</code>
                        </p>
                    </div>

                    <!-- Min Margin Card -->
                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px;">
                        <h4 style="margin: 0 0 8px; font-size: 14px; color: #92400e; display: flex; align-items: center; gap: 6px;">
                            <span>📈 Min Margin Above Cost (%)</span>
                        </h4>
                        <p style="margin: 0; font-size: 12px; color: #78350f; line-height: 1.5;">
                            An extra safety buffer based on your product's actual Cost Price. For example, entering <strong>20%</strong> guarantees the automated price will always remain at least 20% higher than your wholesale cost.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Section 4: Manual vs Automated Edits (User Question) -->
            <div id="guide-manual-vs-auto" style="margin-bottom: 34px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>⚡ 4. Manual Price Changes vs. Automated Repricing</span>
                </h3>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
                    <h4 style="margin: 0 0 10px; font-size: 14px; color: #0f172a;">
                        "What happens if I manually change a product's price in WooCommerce while Repricing is Active?"
                    </h4>
                    <p style="font-size: 13px; color: #334155; line-height: 1.6; margin: 0 0 12px;">
                        <strong>Scenario:</strong> A product has an active rule to match competitor price ($80). You manually edit the WooCommerce price to <strong>$75</strong> and click Update. What happens?
                    </p>
                    <ul style="margin: 0 0 14px 20px; font-size: 13px; color: #475569; line-height: 1.6; list-style-type: disc;">
                        <li><strong>Instant WooCommerce Save:</strong> Your WooCommerce store immediately changes the live customer price to <strong>$75</strong>.</li>
                        <li><strong>Next Repricing Evaluation:</strong> When MarketPulse evaluates the product (within 15-60 min), the algorithm checks your active rule. Since the rule says <em>"Match lowest competitor ($80)"</em> and <code>[✓] Active</code> is enabled, the algorithm will automatically adjust your price back to <strong>$80</strong> to enforce your automated strategy and protect your margin.</li>
                    </ul>

                    <div style="background: #eff6ff; border-left: 4px solid #2563eb; padding: 12px 14px; border-radius: 4px;">
                        <strong style="color: #1e40af; font-size: 13px;">💡 How to keep your manual price of $75 without robot interference:</strong>
                        <ol style="margin: 6px 0 0 18px; font-size: 12px; color: #1e3a8a; line-height: 1.5;">
                            <li><strong>Uncheck `[ ] Active`</strong> in the product's MarketPulse metabox &rarr; Automation is paused for this product. Your manual price remains permanent.</li>
                            <li><strong>Set `Ceiling Price = $75`</strong> &rarr; MarketPulse will never raise your price above $75, even when competitors raise theirs.</li>
                            <li><strong>Change Strategy to `Beat lowest by $5`</strong> &rarr; $80 - $5 = $75 automatically!</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Section 5: Stock-Out Opportunity Pricing -->
            <div id="guide-stockout" style="margin-bottom: 34px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>📦 5. Stock-Out Opportunity Pricing</span>
                </h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 14px;">
                    When your competitors sell out of inventory, you hold the market monopoly. You can configure what MarketPulse does in this high-demand situation:
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                        <strong style="font-size: 13px; color: #0f172a;">1. Do nothing special (Default)</strong>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                            Your price stays at its normal computed level. Safe and steady.
                        </p>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                        <strong style="font-size: 13px; color: #0f172a;">2. Raise price to Ceiling Price</strong>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                            Immediately increases your live price to your Ceiling Price to capture maximum profit while customers have no alternative sellers.
                        </p>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                        <strong style="font-size: 13px; color: #0f172a;">3. Raise price by Percentage (%)</strong>
                        <p style="margin: 4px 0 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                            Adds an opportunistic percentage boost (e.g. +10% or +15%) on top of your normal price until competitors restock.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Section 6: Mobile Push Alerts (WhatsApp & Telegram) -->
            <div id="guide-alerts" style="margin-bottom: 34px; border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>📱 6. Instant Mobile Push Alerts (WhatsApp & Telegram)</span>
                </h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 14px;">
                    Get alerted on your mobile phone the instant a competitor cuts their prices or sells out:
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px;">
                        <h4 style="margin: 0 0 6px; font-size: 14px; color: #166534;">💬 WhatsApp Setup</h4>
                        <ol style="margin: 0 0 0 16px; font-size: 12px; color: #14532d; line-height: 1.6;">
                            <li>Enter your full phone number with international country code (e.g., <code>88017XXXXXXXX</code> or <code>14155552671</code>). No <code>+</code> or spaces.</li>
                            <li>Click <strong>⚡ Send Test WhatsApp Alert</strong> to verify delivery.</li>
                            <li>Save alert settings. All monitored products inherit these alerts automatically.</li>
                        </ol>
                    </div>

                    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px;">
                        <h4 style="margin: 0 0 6px; font-size: 14px; color: #0369a1;">✈️ Telegram Setup</h4>
                        <ol style="margin: 0 0 0 16px; font-size: 12px; color: #075985; line-height: 1.6;">
                            <li>Send <code>/start</code> to <code>@userinfobot</code> in Telegram to get your numeric <strong>Chat ID</strong> (e.g., <code>123456789</code>).</li>
                            <li>Paste the Chat ID into the Telegram field.</li>
                            <li>Click <strong>⚡ Send Test Telegram Alert</strong> to verify.</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Section 7: Status Badges Reference -->
            <div id="guide-badges" style="border-top: 1px solid #f1f5f9; padding-top: 24px;">
                <h3 style="font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                    <span>🏷️ 7. Product List Live Status Badges</span>
                </h3>
                <p style="font-size: 13px; color: #64748b; margin-bottom: 14px;">
                    In your WooCommerce <strong>Products</strong> list table, MarketPulse adds a live intelligence column:
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px;">
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px;">
                        <span style="background: #ecf7ed; color: #1e7e34; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">🟢 Winning</span>
                        <p style="margin: 6px 0 0; font-size: 11px; color: #14532d;">Your store holds the lowest price across all tracked competitors.</p>
                    </div>

                    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 12px;">
                        <span style="background: #fcf0f0; color: #b32d2e; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">🔴 Price Drop</span>
                        <p style="margin: 6px 0 0; font-size: 11px; color: #991b1b;">A competitor recently lowered their price below yours.</p>
                    </div>

                    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 12px;">
                        <span style="background: #fff8e5; color: #996800; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">🟠 Out of Stock</span>
                        <p style="margin: 6px 0 0; font-size: 11px; color: #78350f;">Tracked competitors are sold out (Opportunity to raise prices!).</p>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                        <span style="background: #f0f6fc; color: #2271b1; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;">🔵 Monitoring</span>
                        <p style="margin: 6px 0 0; font-size: 11px; color: #334155;">Active monitoring links registered; awaiting next cloud check.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Tab 3: FAQ & Troubleshooting Guide (English)
     */
    private function render_faq_tab() {
        ?>
        <div class="mp-faq-wrap" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px;">
                <span style="background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 10px; border-radius: 12px; border: 1px solid #cbd5e1;">
                    Help & Support
                </span>
                <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 12px 0 6px;">
                    <?php esc_html_e('Frequently Asked Questions & Troubleshooting', 'marketpulse-for-woocommerce'); ?>
                </h2>
                <p style="font-size: 14px; color: #64748b; margin: 0; line-height: 1.6;">
                    <?php esc_html_e('Answers to common operational, architectural, and pricing questions.', 'marketpulse-for-woocommerce'); ?>
                </p>
            </div>

            <div style="display: flex; flex-direction: column; gap: 16px;">
                <!-- FAQ 1 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                    <h4 style="margin: 0 0 6px; font-size: 14px; color: #0f172a;">Does MarketPulse slow down my WooCommerce store or checkout?</h4>
                    <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                        <strong>Zero slowdown.</strong> All web scraping, headless Playwright browsers, and pricing calculations run on dedicated MarketPulse Cloud clusters. Your WordPress server never runs heavy scraping scripts. Furthermore, product updates in WooCommerce communicate with MarketPulse asynchronously (<code>blocking =&gt; false</code>), guaranteeing that your admin screens and customer checkout never wait.
                    </p>
                </div>

                <!-- FAQ 2 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                    <h4 style="margin: 0 0 6px; font-size: 14px; color: #0f172a;">What happens if MarketPulse Cloud or my internet goes offline?</h4>
                    <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                        <strong>100% Local-First Resilience.</strong> Your WooCommerce store, catalog, cart, and checkout operate normally with zero interruption. All repricing settings are stored locally in your WordPress database. The plugin includes 48-hour Grace Period Protection, ensuring that temporary network outages never lock your store or corrupt prices.
                    </p>
                </div>

                <!-- FAQ 3 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                    <h4 style="margin: 0 0 6px; font-size: 14px; color: #0f172a;">Which competitor websites can I track?</h4>
                    <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                        You can track products from <strong>Amazon, Daraz, eBay, Walmart, Shopify stores, and any eCommerce website</strong> with publicly visible prices. Simply paste the direct product URL into the metabox and click <code>⚡ Check Price</code> to test live detection.
                    </p>
                </div>

                <!-- FAQ 4 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                    <h4 style="margin: 0 0 6px; font-size: 14px; color: #0f172a;">How does currency conversion work if competitors sell in different currencies?</h4>
                    <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                        MarketPulse automatically normalizes all foreign currencies (USD, EUR, GBP, INR, etc.) into your store's native currency (BDT, USD, etc.) using live daily foreign exchange rates provided by the European Central Bank. You never have to manually convert prices.
                    </p>
                </div>

                <!-- FAQ 5 -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px;">
                    <h4 style="margin: 0 0 6px; font-size: 14px; color: #0f172a;">How often are competitor prices checked?</h4>
                    <p style="margin: 0; font-size: 13px; color: #475569; line-height: 1.6;">
                        On the Starter Plan, competitor prices are automatically re-checked <strong>every 60 minutes</strong>. On Growth and Enterprise tiers, checks run as frequently as <strong>every 15 minutes</strong>.
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add MarketPulse status column to WooCommerce Products list table.
     */
    public function add_product_columns($columns) {
        if (!MarketPulse_Auth::is_paired()) {
            return $columns;
        }

        $new_columns = array();
        foreach ($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'price') {
                $new_columns['marketpulse_status'] = __('MarketPulse', 'marketpulse-for-woocommerce');
            }
        }
        if (!isset($new_columns['marketpulse_status'])) {
            $new_columns['marketpulse_status'] = __('MarketPulse', 'marketpulse-for-woocommerce');
        }
        return $new_columns;
    }

    /**
     * Render MarketPulse live status badge in WooCommerce Products table.
     */
    public function render_product_column($column, $post_id) {
        if ($column !== 'marketpulse_status') {
            return;
        }

        $summary_map = MarketPulse_Auth::get_products_summary();
        $product = wc_get_product($post_id);
        $sku = $product ? $product->get_sku() : '';

        // Match by WooCommerce post_id or SKU
        $data = null;
        if (isset($summary_map[(string) $post_id])) {
            $data = $summary_map[(string) $post_id];
        } elseif (!empty($sku) && isset($summary_map[$sku])) {
            $data = $summary_map[$sku];
        } elseif (isset($summary_map['WC-' . $post_id])) {
            $data = $summary_map['WC-' . $post_id];
        }

        if (!$data || empty($data['competitorCount'])) {
            $has_local_urls = get_post_meta($post_id, '_marketpulse_competitor_urls', true);
            if (!empty($has_local_urls) && is_array(array_filter((array) $has_local_urls))) {
                echo '<span class="mp-badge mp-badge-monitoring" title="' . esc_attr__('Tracking configured. Syncing with MarketPulse Cloud...', 'marketpulse-for-woocommerce') . '">🔵 ' . esc_html__('Monitoring', 'marketpulse-for-woocommerce') . '</span>';
            } else {
                echo '<span style="color: #a7aaad;">—</span>';
            }
            return;
        }

        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        $status = $data['status'] ?? 'MONITORING';
        $lowest = isset($data['lowestCompetitorPrice']) && is_numeric($data['lowestCompetitorPrice']) ? (float) $data['lowestCompetitorPrice'] : null;
        $our_price = isset($data['ourPrice']) && is_numeric($data['ourPrice']) ? (float) $data['ourPrice'] : null;

        switch ($status) {
            case 'WINNING':
                $diff = ($lowest !== null && $our_price !== null && $our_price > 0) ? abs($data['diffPercent'] ?? 0) : null;
                $pct_text = ($diff !== null && $diff > 0) ? sprintf(__(' (%s%% lower)', 'marketpulse-for-woocommerce'), $diff) : '';
                printf(
                    '<span class="mp-badge mp-badge-winning" title="%s">🟢 %s%s</span>',
                    esc_attr__('Your price is winning against all active competitors', 'marketpulse-for-woocommerce'),
                    esc_html__('Winning', 'marketpulse-for-woocommerce'),
                    esc_html($pct_text)
                );
                if ($lowest !== null) {
                    printf(
                        '<span class="mp-badge-subtext">%s: %s%s</span>',
                        esc_html__('Comp price', 'marketpulse-for-woocommerce'),
                        esc_html($currency_symbol),
                        esc_html(number_format($lowest, 2))
                    );
                }
                break;

            case 'PRICE_DROP':
                $diff = abs($data['diffPercent'] ?? 0);
                printf(
                    '<span class="mp-badge mp-badge-pricedrop" title="%s">🔴 %s (-%s%%)</span>',
                    esc_attr__('Competitor price dropped below your price', 'marketpulse-for-woocommerce'),
                    esc_html__('Price Drop', 'marketpulse-for-woocommerce'),
                    esc_html($diff)
                );
                if ($lowest !== null) {
                    printf(
                        '<span class="mp-badge-subtext">%s: %s%s</span>',
                        esc_html__('Comp price', 'marketpulse-for-woocommerce'),
                        esc_html($currency_symbol),
                        esc_html(number_format($lowest, 2))
                    );
                }
                break;

            case 'OUT_OF_STOCK':
                printf(
                    '<span class="mp-badge mp-badge-stockout" title="%s">🟠 %s</span>',
                    esc_attr__('All active competitors are out of stock (Price increase opportunity)', 'marketpulse-for-woocommerce'),
                    esc_html__('Competitors Sold Out', 'marketpulse-for-woocommerce')
                );
                printf(
                    '<span class="mp-badge-subtext">%s</span>',
                    esc_html__('Opportunity', 'marketpulse-for-woocommerce')
                );
                break;

            default:
                printf(
                    '<span class="mp-badge mp-badge-monitoring" title="%s">🔵 %s (%d)</span>',
                    esc_attr__('Monitoring active competitors', 'marketpulse-for-woocommerce'),
                    esc_html__('Monitoring', 'marketpulse-for-woocommerce'),
                    absint($data['competitorCount'])
                );
                if ($lowest !== null) {
                    printf(
                        '<span class="mp-badge-subtext">%s: %s%s</span>',
                        esc_html__('Comp price', 'marketpulse-for-woocommerce'),
                        esc_html($currency_symbol),
                        esc_html(number_format($lowest, 2))
                    );
                }
                break;
        }

        // Render hidden data attributes for Quick Edit inline population
        $quick_floor = get_post_meta($post_id, '_marketpulse_floor_price', true);
        $quick_strat = get_post_meta($post_id, '_marketpulse_strategy', true) ?: 'beat_lowest';
        printf(
            '<span class="mp-quick-data" data-floor="%s" data-strategy="%s" style="display:none;"></span>',
            esc_attr($quick_floor !== '' ? $quick_floor : ''),
            esc_attr($quick_strat)
        );
    }

    /**
     * Render MarketPulse inline fields in WooCommerce Quick Edit.
     */
    public function render_quick_edit_fields($column_name, $post_type) {
        if ($column_name !== 'marketpulse_status' || $post_type !== 'product') {
            return;
        }
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        wp_nonce_field('marketpulse_save_quick_edit', 'marketpulse_quick_nonce');
        ?>
        <fieldset class="inline-edit-col-right inline-edit-marketpulse" style="margin-top: 8px;">
            <div class="inline-edit-col">
                <span class="title" style="font-weight: 600; color: #1e1e1e;">📊 <?php esc_html_e('MarketPulse Repricing', 'marketpulse-for-woocommerce'); ?></span>
                <label style="display: block; margin-top: 6px;">
                    <span class="title"><?php printf(esc_html__('Floor Price (%s)', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?></span>
                    <span class="input-text-wrap">
                        <input type="number" step="0.01" min="0" name="marketpulse_quick_floor_price" class="text" value="" placeholder="0.00" style="max-width: 150px;">
                    </span>
                </label>
                <label style="display: block; margin-top: 6px;">
                    <span class="title"><?php esc_html_e('Strategy', 'marketpulse-for-woocommerce'); ?></span>
                    <select name="marketpulse_quick_strategy">
                        <option value="beat_lowest"><?php printf(esc_html__('Beat lowest by %s1.00', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?></option>
                        <option value="match_lowest"><?php esc_html_e('Match lowest competitor price', 'marketpulse-for-woocommerce'); ?></option>
                        <option value="target_average"><?php esc_html_e('Target market average price', 'marketpulse-for-woocommerce'); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Render MarketPulse inline fields in WooCommerce Bulk Edit.
     */
    public function render_bulk_edit_fields($column_name, $post_type) {
        if ($column_name !== 'marketpulse_status' || $post_type !== 'product') {
            return;
        }
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';
        ?>
        <fieldset class="inline-edit-col-right inline-edit-marketpulse-bulk" style="margin-top: 8px;">
            <div class="inline-edit-col">
                <span class="title" style="font-weight: 600; color: #1e1e1e;">📊 <?php esc_html_e('MarketPulse Bulk Repricing Strategy', 'marketpulse-for-woocommerce'); ?></span>
                <label style="display: block; margin-top: 6px;">
                    <span class="title"><?php esc_html_e('Set Strategy', 'marketpulse-for-woocommerce'); ?></span>
                    <select name="marketpulse_bulk_strategy">
                        <option value=""><?php esc_html_e('— No Change —', 'marketpulse-for-woocommerce'); ?></option>
                        <option value="beat_lowest"><?php printf(esc_html__('Beat lowest by %s1.00', 'marketpulse-for-woocommerce'), esc_html($currency_symbol)); ?></option>
                        <option value="match_lowest"><?php esc_html_e('Match lowest competitor price', 'marketpulse-for-woocommerce'); ?></option>
                        <option value="target_average"><?php esc_html_e('Target market average price', 'marketpulse-for-woocommerce'); ?></option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Save MarketPulse Quick Edit fields.
     */
    public function save_quick_edit_fields($product) {
        $product_id = is_object($product) ? $product->get_id() : absint($product);
        if (!$product_id || !current_user_can('edit_product', $product_id)) {
            return;
        }

        if (!isset($_POST['marketpulse_quick_nonce']) || !wp_verify_nonce($_POST['marketpulse_quick_nonce'], 'marketpulse_save_quick_edit')) {
            return;
        }

        if (isset($_POST['marketpulse_quick_floor_price'])) {
            $raw_floor = trim($_POST['marketpulse_quick_floor_price']);
            $floor = $raw_floor !== '' && is_numeric($raw_floor) ? floatval($raw_floor) : '';
            update_post_meta($product_id, '_marketpulse_floor_price', $floor);
        }

        if (isset($_POST['marketpulse_quick_strategy'])) {
            $allowed = array('beat_lowest', 'match_lowest', 'target_average');
            $strategy = sanitize_text_field($_POST['marketpulse_quick_strategy']);
            if (in_array($strategy, $allowed, true)) {
                update_post_meta($product_id, '_marketpulse_strategy', $strategy);
            }
        }
    }

    /**
     * Save MarketPulse Bulk Edit fields.
     */
    public function save_bulk_edit_fields($product) {
        $product_id = is_object($product) ? $product->get_id() : absint($product);
        if (!$product_id || !current_user_can('edit_product', $product_id)) {
            return;
        }

        if (!empty($_POST['marketpulse_bulk_strategy'])) {
            $allowed = array('beat_lowest', 'match_lowest', 'target_average');
            $strategy = sanitize_text_field($_POST['marketpulse_bulk_strategy']);
            if (in_array($strategy, $allowed, true)) {
                update_post_meta($product_id, '_marketpulse_strategy', $strategy);
            }
        }
    }

    /**
     * AJAX Handler: Send a test mobile alert (WhatsApp or Telegram)
     * Complies with all 6 Security Layers.
     */
    public function ajax_send_test_alert() {
        // Security Layer 1: Nonce Verification
        check_ajax_referer('marketpulse_admin_nonce', 'nonce');

        // Security Layer 2: Capability Verification
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'marketpulse-for-woocommerce')), 403);
        }

        // Security Layer 3: Input Sanitization & Validation
        $channel = isset($_POST['channel']) ? strtoupper(sanitize_text_field($_POST['channel'])) : '';
        $destination = isset($_POST['destination']) ? sanitize_text_field($_POST['destination']) : '';

        if (!in_array($channel, array('WHATSAPP', 'TELEGRAM'), true)) {
            wp_send_json_error(array('message' => __('Invalid alert channel specified.', 'marketpulse-for-woocommerce')), 400);
        }

        if (empty($destination)) {
            $name = $channel === 'WHATSAPP' ? __('WhatsApp Number', 'marketpulse-for-woocommerce') : __('Telegram Chat ID', 'marketpulse-for-woocommerce');
            wp_send_json_error(array('message' => sprintf(__('Please enter a valid %s first.', 'marketpulse-for-woocommerce'), $name)), 400);
        }

        // Security Layer 4: HMAC Authenticated Cloud Dispatch via Safe HTTP
        $res = MarketPulse_Auth::send_test_alert($channel, $destination);

        // Security Layer 5: Defensive Error Handling
        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()), 500);
        }

        // Security Layer 6: Output Escaping
        wp_send_json_success(array(
            'message' => sanitize_text_field($res['message'] ?? __('Test alert sent successfully!', 'marketpulse-for-woocommerce')),
        ));
    }
}
