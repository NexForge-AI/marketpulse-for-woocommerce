/**
 * MarketPulse Admin JS
 * Dynamic row management, live competitor price check, automated repricing controls & quick edit support
 *
 * @package MarketPulse_For_WooCommerce
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        var $container = $('#mp-competitor-rows');
        var $addButton = $('#mp-add-url-btn');

        // 1. Add new competitor URL row (with ⚡ Check Price button & preview container)
        if ($container.length && $addButton.length) {
            $addButton.on('click', function (e) {
                e.preventDefault();
                var newRow = $(
                    '<div class="mp-url-row-container" style="margin-bottom: 10px;">' +
                    '<div class="mp-url-row" style="display: flex; gap: 8px;">' +
                    '<input type="url" name="marketpulse_competitor_urls[]" value="" placeholder="https://www.amazon.com/dp/... or https://www.daraz.com.bd/products/..." style="width: 100%; max-width: 480px;">' +
                    '<button type="button" class="button button-secondary mp-check-url-btn" title="Test live price detection immediately">⚡ Check Price</button>' +
                    '<button type="button" class="button mp-remove-row" style="color: #b32d2e;">Remove</button>' +
                    '</div>' +
                    '<div class="mp-live-check-preview" style="display:none; margin-top: 6px; max-width: 600px; padding: 6px 10px; border-radius: 4px; font-size: 12px;"></div>' +
                    '</div>'
                );
                $container.append(newRow);
                newRow.find('input[type="url"]').focus();
            });

            // Remove competitor URL row
            $container.on('click', '.mp-remove-row', function (e) {
                e.preventDefault();
                $(this).closest('.mp-url-row-container').remove();
            });
        }

        // 2. Instant Live Price Check (⚡ Check Price Button)
        $(document).on('click', '.mp-check-url-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $rowContainer = $btn.closest('.mp-url-row-container');
            var $input = $rowContainer.find('input[type="url"]');
            var $preview = $rowContainer.find('.mp-live-check-preview');
            var targetUrl = $.trim($input.val());

            if (!targetUrl) {
                $input.css('border-color', '#d63638').focus();
                setTimeout(function () {
                    $input.css('border-color', '');
                }, 2000);
                return;
            }

            var $wrap = $('.mp-metabox-wrap');
            var ourPrice = parseFloat($wrap.data('current-price')) || 0;
            var currencySym = $wrap.data('currency-symbol') || '$';

            // Show loading state
            var originalBtnText = $btn.html();
            $btn.prop('disabled', true).html('⏳ Checking...');
            $preview.show().css({
                'background': '#f0f6fc',
                'border': '1px solid #c8d7e1',
                'color': '#1d2327'
            }).html('<em>Connecting to MarketPulse Cloud inspection cluster...</em>');

            $.ajax({
                url: (typeof marketpulseData !== 'undefined' && marketpulseData.ajaxUrl) ? marketpulseData.ajaxUrl : ajaxurl,
                type: 'POST',
                dataType: 'json',
                timeout: 45000, // 45 seconds for deep browser scraper
                data: {
                    action: 'marketpulse_live_check_url',
                    nonce: (typeof marketpulseData !== 'undefined' && marketpulseData.nonce) ? marketpulseData.nonce : '',
                    url: targetUrl,
                    post_id: $('#post_ID').val() || '',
                    sku: $('#_sku').val() || ''
                },
                success: function (res) {
                    $btn.prop('disabled', false).html(originalBtnText);

                    // 1. SaaS Cooldown Check Response (SaaS Parity)
                    if (res && !res.success && res.data && res.data.onCooldown) {
                        var cdMsg = res.data.message || 'This product was checked recently.';
                        var remMins = res.data.remainingMinutes || 1;
                        var intMins = res.data.intervalMinutes || 60;
                        $preview.css({
                            'background': '#fffbeb',
                            'border': '1px solid #fde68a',
                            'color': '#92400e'
                        }).html(
                            '<strong>⏱️ Cooldown Active:</strong> ' + $('<div>').text(cdMsg).html() +
                            '<div style="font-size:11px; margin-top:4px; color:#b45309;">Your plan check interval is ' + intMins + ' minutes. Next on-demand check available in ' + remMins + 'm.</div>'
                        );
                        return;
                    }

                    if (res && res.success && res.data) {
                        var d = res.data;
                        var compPrice = d.price !== null ? parseFloat(d.price) : null;
                        var compCurrency = d.currency || currencySym;
                        var titleText = d.title ? $('<div>').text(d.title).html() : 'Competitor Product';
                        var inStockText = d.inStock ? '<span style="color:#1e7e34; font-weight:600;">✓ In Stock</span>' : '<span style="color:#b32d2e; font-weight:600;">⚠️ Out of Stock</span>';

                        var comparisonHtml = '';
                        if (compPrice !== null && ourPrice > 0) {
                            var diff = ourPrice - compPrice;
                            if (Math.abs(diff) < 0.01) {
                                comparisonHtml = ' | <strong>Equal to your price</strong>';
                            } else if (diff > 0) {
                                comparisonHtml = ' | <span style="color:#b32d2e; font-weight:600;">You are ' + currencySym + diff.toFixed(2) + ' higher</span>';
                            } else {
                                comparisonHtml = ' | <span style="color:#1e7e34; font-weight:600;">You are ' + currencySym + Math.abs(diff).toFixed(2) + ' lower (Winning!)</span>';
                            }
                        }

                        $preview.css({
                            'background': '#f0fdf4',
                            'border': '1px solid #bbf7d0',
                            'color': '#166534'
                        }).html(
                            '<strong>🟢 Live Result:</strong> ' +
                            (compPrice !== null ? '<strong>' + compCurrency + ' ' + compPrice.toFixed(2) + '</strong>' : 'Price Hidden') +
                            ' · ' + inStockText +
                            ' · <em>' + titleText + '</em>' +
                            comparisonHtml
                        );
                    } else {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Could not detect live price.';
                        $preview.css({
                            'background': '#fcf0f0',
                            'border': '1px solid #f5c6cb',
                            'color': '#b32d2e'
                        }).html('<strong>❌ Check Failed:</strong> ' + $('<div>').text(errMsg).html());
                    }
                },
                error: function (xhr, status, error) {
                    $btn.prop('disabled', false).html(originalBtnText);

                    // Check for Cooldown payload in error response
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.onCooldown) {
                        var cdMsg = xhr.responseJSON.data.message || 'This product was checked recently.';
                        var remMins = xhr.responseJSON.data.remainingMinutes || 1;
                        var intMins = xhr.responseJSON.data.intervalMinutes || 60;
                        $preview.css({
                            'background': '#fffbeb',
                            'border': '1px solid #fde68a',
                            'color': '#92400e'
                        }).html(
                            '<strong>⏱️ Cooldown Active:</strong> ' + $('<div>').text(cdMsg).html() +
                            '<div style="font-size:11px; margin-top:4px; color:#b45309;">Your plan check interval is ' + intMins + ' minutes. Next on-demand check available in ' + remMins + 'm.</div>'
                        );
                        return;
                    }

                    var errMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                        ? xhr.responseJSON.data.message
                        : (status === 'timeout' ? 'Inspection request timed out. Please try again.' : 'Request timed out or server unavailable.');
                    $preview.css({
                        'background': '#fcf0f0',
                        'border': '1px solid #f5c6cb',
                        'color': '#b32d2e'
                    }).html('<strong>❌ Connection Error:</strong> ' + $('<div>').text(errMsg).html());
                }
            });
        });

        // 3. Automated Repricing UI: Dynamic Undercut / Beat Offset Toggle
        $(document).on('change', '#mp-strategy-select', function () {
            var val = $(this).val() || '';
            var $offsetGroup = $('#mp-offset-group');
            if (val.indexOf('beat_') === 0) {
                $offsetGroup.slideDown(150);
            } else {
                $offsetGroup.slideUp(150);
            }
        });

        // 4. Automated Repricing UI: Dynamic Out-Of-Stock Raise Percentage Toggle
        $(document).on('change', '#mp-oos-mode-select', function () {
            var val = $(this).val() || '';
            var $oosPercentWrap = $('#mp-oos-percent-wrap');
            if (val === 'raise_by_percent') {
                $oosPercentWrap.slideDown(150);
            } else {
                $oosPercentWrap.slideUp(150);
            }
        });

        // 5. Automated Repricing UI: Collapsible Advanced Settings Drawer Toggle
        $(document).on('click', '#mp-toggle-advanced-btn', function (e) {
            e.preventDefault();
            var $drawer = $('#mp-advanced-drawer');
            var $icon = $('#mp-advanced-toggle-icon');
            var $text = $('#mp-advanced-toggle-text');
            $drawer.slideToggle(180, function () {
                if ($drawer.is(':visible')) {
                    $icon.text('▲');
                    $text.text('Hide Advanced Repricing Settings');
                } else {
                    $icon.text('▼');
                    $text.text('Show Advanced Repricing Settings');
                }
            });
        });

        // 5b. Quick Repricing Rules Guide Drawer Toggle
        $(document).on('click', '#mp-quick-guide-btn', function (e) {
            e.preventDefault();
            $('#mp-quick-guide-drawer').slideToggle(180);
        });

        // 6. Quick Edit Population Support in WooCommerce Product List
        if ($('#the-list').length) {
            $('#the-list').on('click', '.editinline', function () {
                var $row = $(this).closest('tr');
                var postId = $row.attr('id').replace('post-', '');
                var $quickRow = $('#edit-' + postId);

                var floorPrice = $row.find('.mp-quick-data').data('floor') || '';
                var strategy = $row.find('.mp-quick-data').data('strategy') || 'beat_lowest';

                $quickRow.find('input[name="marketpulse_quick_floor_price"]').val(floorPrice);
                $quickRow.find('select[name="marketpulse_quick_strategy"]').val(strategy);
            });
        }

        // 7. Instant Mobile Alert Test Dispatcher (WhatsApp & Telegram)
        $(document).on('click', '.mp-test-alert-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var channel = ($btn.data('channel') || '').toUpperCase();
            var isWhatsApp = (channel === 'WHATSAPP');
            var $input = isWhatsApp ? $('#mp_whatsapp_number') : $('#mp_telegram_chat_id');
            var $feedback = $('.mp-test-alert-feedback[data-channel="' + channel + '"]');
            var destination = $.trim($input.val());

            if (!destination) {
                $input.css('border-color', '#d63638').focus();
                $feedback.show().css({
                    'display': 'block',
                    'background': '#fff8e5',
                    'border': '1px solid #f0b849',
                    'color': '#825a00'
                }).html('<strong>⚠️ Notice:</strong> Please enter a destination ' + (isWhatsApp ? 'WhatsApp number' : 'Telegram Chat ID') + ' first.');
                setTimeout(function () {
                    $input.css('border-color', '');
                }, 3000);
                return;
            }

            var originalText = $btn.html();
            $btn.prop('disabled', true).html('⏳ Sending Test...');
            $feedback.show().css({
                'display': 'block',
                'background': '#f0f6fc',
                'border': '1px solid #c8d7e1',
                'color': '#1d2327'
            }).html('<em>Connecting to MarketPulse Cloud notification dispatcher...</em>');

            $.ajax({
                url: (typeof marketpulseData !== 'undefined' && marketpulseData.ajaxUrl) ? marketpulseData.ajaxUrl : ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'marketpulse_test_alert',
                    nonce: (typeof marketpulseData !== 'undefined' && marketpulseData.nonce) ? marketpulseData.nonce : '',
                    channel: channel,
                    destination: destination
                },
                success: function (res) {
                    $btn.prop('disabled', false).html(originalText);
                    if (res && res.success) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Test alert dispatched successfully!';
                        $feedback.css({
                            'display': 'block',
                            'background': '#f0fdf4',
                            'border': '1px solid #bbf7d0',
                            'color': '#166534'
                        }).html('<strong>✓ Success:</strong> ' + $('<div>').text(msg).html());
                    } else {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Could not dispatch test alert.';
                        $feedback.css({
                            'display': 'block',
                            'background': '#fcf0f0',
                            'border': '1px solid #f5c6cb',
                            'color': '#b32d2e'
                        }).html('<strong>❌ Error:</strong> ' + $('<div>').text(errMsg).html());
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).html(originalText);
                    var errMsg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                        ? xhr.responseJSON.data.message
                        : 'Connection error while contacting MarketPulse Cloud.';
                    $feedback.css({
                        'display': 'block',
                        'background': '#fcf0f0',
                        'border': '1px solid #f5c6cb',
                        'color': '#b32d2e'
                    }).html('<strong>❌ Connection Error:</strong> ' + $('<div>').text(errMsg).html());
                }
            });
        });

        // 7. A/B Split Testing Settings Toggle in Product Metabox
        $(document).on('change', '#mp-ab-enable-toggle', function () {
            if ($(this).is(':checked')) {
                $('#mp-ab-settings-wrap').slideDown(180, function() {
                    updateAbTypeFields();
                    refreshMockupStudio();
                });
            } else {
                $('#mp-ab-settings-wrap').slideUp(180);
            }
        });

        // 8. Visual Editor State
        var currentMockupMode = 'VARIANT_B'; // 'VARIANT_B' or 'CONTROL'

        function getMetaboxData() {
            var $wrap = $('.mp-metabox-wrap');
            return {
                currentPrice: parseFloat($wrap.data('current-price')) || 0,
                regularPrice: parseFloat($wrap.data('regular-price')) || 0,
                currencySymbol: $wrap.data('currency-symbol') || '$',
                productTitle: $wrap.data('product-title') || 'Sample Product',
                productSku: $wrap.data('product-sku') || '',
                imageUrl: $wrap.data('image-url') || '',
                categoryName: $wrap.data('category-name') || 'PREMIUM COLLECTION'
            };
        }

        // Live calculation pill for Price Elasticity test
        function updatePriceCalculationPill() {
            var data = getMetaboxData();
            var $pillWrap = $('#mp-ab-price-calc-wrap');
            var rawPrice = $.trim($('#mp-ab-price-input').val());

            if (!rawPrice || isNaN(rawPrice)) {
                $pillWrap.html('');
                return;
            }

            var testPrice = parseFloat(rawPrice);
            var origPrice = data.currentPrice;
            var curr = data.currencySymbol;

            if (origPrice <= 0) {
                $pillWrap.html('');
                return;
            }

            var diff = testPrice - origPrice;
            var pct = ((diff / origPrice) * 100).toFixed(1);

            if (Math.abs(diff) < 0.001) {
                $pillWrap.html(
                    '<span class="mp-math-pill mp-math-neutral">' +
                    'Original: ' + curr + origPrice.toFixed(2) + ' ➔ Test: ' + curr + testPrice.toFixed(2) + ' (Equal to original price)' +
                    '</span>'
                );
            } else if (diff < 0) {
                $pillWrap.html(
                    '<span class="mp-math-pill mp-math-discount">' +
                    'Original: ' + curr + origPrice.toFixed(2) + ' ➔ Test: ' + curr + testPrice.toFixed(2) + ' (' + pct + '% discount)' +
                    '</span>'
                );
            } else {
                $pillWrap.html(
                    '<span class="mp-math-pill mp-math-markup">' +
                    'Original: ' + curr + origPrice.toFixed(2) + ' ➔ Test: ' + curr + testPrice.toFixed(2) + ' (+' + pct + '% markup)' +
                    '</span>'
                );
            }
        }

        // Refresh Mockup Studio Display (Card Elements)
        function refreshMockupStudio() {
            var data = getMetaboxData();
            var selectedType = $('#mp-ab-type-select').val() || 'PRICE';
            var curr = data.currencySymbol;

            // 1. Title
            var title = data.productTitle;
            if (currentMockupMode === 'VARIANT_B' && selectedType === 'PRODUCT_TITLE') {
                var testTitle = $.trim($('#mp-ab-title-input').val());
                if (testTitle) title = testTitle;
            }
            $('#mp-mockup-title').text(title);

            // 2. Image
            var imgUrl = data.imageUrl;
            if (currentMockupMode === 'VARIANT_B' && selectedType === 'CREATIVE_IMAGE') {
                var testImg = $.trim($('#mp-ab-image-input').val());
                if (testImg) imgUrl = testImg;
            }
            if (imgUrl) {
                $('#mp-mockup-img').attr('src', imgUrl);
            }

            // 3. Price
            var $priceWrap = $('#mp-mockup-price-wrap');
            if (currentMockupMode === 'VARIANT_B' && selectedType === 'PRICE') {
                var rawTestPrice = $.trim($('#mp-ab-price-input').val());
                var testPrice = rawTestPrice && !isNaN(rawTestPrice) ? parseFloat(rawTestPrice) : data.currentPrice;
                var regularPrice = data.regularPrice > testPrice ? data.regularPrice : (data.currentPrice > testPrice ? data.currentPrice : null);

                if (regularPrice) {
                    $priceWrap.html(
                        '<span class="mp-mockup-price-del">' + curr + regularPrice.toFixed(2) + '</span> ' +
                        '<span class="mp-mockup-price-main">' + curr + testPrice.toFixed(2) + '</span>'
                    );
                } else {
                    $priceWrap.html('<span class="mp-mockup-price-main">' + curr + testPrice.toFixed(2) + '</span>');
                }
            } else {
                if (data.regularPrice > data.currentPrice) {
                    $priceWrap.html(
                        '<span class="mp-mockup-price-del">' + curr + data.regularPrice.toFixed(2) + '</span> ' +
                        '<span class="mp-mockup-price-main">' + curr + data.currentPrice.toFixed(2) + '</span>'
                    );
                } else {
                    $priceWrap.html('<span class="mp-mockup-price-main">' + curr + data.currentPrice.toFixed(2) + '</span>');
                }
            }

            // 4. CTA Button Text & Color
            var $cta = $('#mp-mockup-cta-btn');
            if (currentMockupMode === 'VARIANT_B' && selectedType === 'CTA_BUTTON') {
                var testBtnText = $.trim($('#mp-ab-btn-text-input').val()) || 'Add to Cart';
                var testBtnColor = $('#mp-ab-btn-color-input').val() || '#10B981';
                $cta.text(testBtnText).css({
                    'background': testBtnColor,
                    'border-color': testBtnColor,
                    'color': '#ffffff'
                });
            } else {
                $cta.text('Add to Cart').css({
                    'background': '#2563EB',
                    'border-color': '#2563EB',
                    'color': '#ffffff'
                });
            }

            // 5. Targeting Summary Pill
            updateTargetingSummaryPill();
        }

        function updateTargetingSummaryPill() {
            var alloc = $('#mp-ab-alloc-select').val() || '100';
            var dev = $('#mp-ab-device-select option:selected').text().split(' ')[0].toUpperCase();
            var src = $('#mp-ab-source-select option:selected').text().split(' ')[0].toUpperCase();
            var usr = $('#mp-ab-user-type-select option:selected').text().split(' ')[0].toUpperCase();

            $('#mp-ab-summary-pill').text(
                'Targeting: ' + dev + ' | Channel: ' + src + ' | Shopper: ' + usr + ' | Allocation: ' + alloc + '%'
            );
        }

        // Live reactivity event listeners
        $(document).on('input keyup change', '#mp-ab-price-input', function () {
            updatePriceCalculationPill();
            refreshMockupStudio();
        });

        $(document).on('input keyup change', '#mp-ab-title-input', function () {
            refreshMockupStudio();
        });

        $(document).on('input keyup change', '#mp-ab-btn-text-input', function () {
            refreshMockupStudio();
        });

        $(document).on('input change', '#mp-ab-btn-color-input', function () {
            var color = $(this).val();
            $('#mp-ab-color-hex').text(color);
            refreshMockupStudio();
        });

        $(document).on('change', '#mp-ab-alloc-select, #mp-ab-device-select, #mp-ab-source-select, #mp-ab-user-type-select', function () {
            updateTargetingSummaryPill();
        });

        // In-page Variant Switcher: [⚡ Variant B] vs [Control (A)]
        $(document).on('click', '#mp-btn-mockup-varb', function (e) {
            e.preventDefault();
            currentMockupMode = 'VARIANT_B';
            $('#mp-btn-mockup-varb').addClass('mp-active');
            $('#mp-btn-mockup-ctrl').removeClass('mp-active');
            refreshMockupStudio();
        });

        $(document).on('click', '#mp-btn-mockup-ctrl', function (e) {
            e.preventDefault();
            currentMockupMode = 'CONTROL';
            $('#mp-btn-mockup-ctrl').addClass('mp-active');
            $('#mp-btn-mockup-varb').removeClass('mp-active');
            refreshMockupStudio();
        });

        // Device Switcher: [💻 Desktop] vs [📱 Mobile]
        $(document).on('click', '#mp-btn-view-desktop', function (e) {
            e.preventDefault();
            $('#mp-btn-view-desktop').addClass('mp-active');
            $('#mp-btn-view-mobile').removeClass('mp-active');
            $('#mp-mockup-frame').removeClass('mp-device-mobile');
        });

        $(document).on('click', '#mp-btn-view-mobile', function (e) {
            e.preventDefault();
            $('#mp-btn-view-mobile').addClass('mp-active');
            $('#mp-btn-view-desktop').removeClass('mp-active');
            $('#mp-mockup-frame').addClass('mp-device-mobile');
        });

        // 9. A/B Split Testing Dynamic Field Switcher by Test Type (Price vs Title vs Hero Image vs CTA Button)
        function updateAbTypeFields() {
            var selectedType = $('#mp-ab-type-select').val() || 'PRICE';
            $('.mp-ab-type-field').hide();
            if (selectedType === 'PRICE') {
                $('#mp-ab-field-price').show();
            } else if (selectedType === 'PRODUCT_TITLE') {
                $('#mp-ab-field-title').show();
            } else if (selectedType === 'CREATIVE_IMAGE') {
                $('#mp-ab-field-image').show();
            } else if (selectedType === 'CTA_BUTTON') {
                $('#mp-ab-field-button').show();
            }
            refreshMockupStudio();
        }

        $(document).on('change', '#mp-ab-type-select', function () {
            updateAbTypeFields();
        });

        // 10. WordPress Media Library Modal for Hero / Creative Image A/B testing
        var abMediaFrame;
        $(document).on('click', '#mp-ab-upload-image-btn', function (e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                alert('WordPress Media Library is unavailable. Please enter direct image URL.');
                return;
            }

            if (abMediaFrame) {
                abMediaFrame.open();
                return;
            }

            abMediaFrame = wp.media({
                title: 'Select Variant B Hero Image',
                button: { text: 'Use this image' },
                multiple: false,
                library: { type: 'image' }
            });

            abMediaFrame.on('select', function () {
                var attachment = abMediaFrame.state().get('selection').first().toJSON();
                if (attachment && attachment.url) {
                    $('#mp-ab-image-input').val(attachment.url);
                    refreshMockupStudio();
                }
            });

            abMediaFrame.open();
        });

        $(document).on('input change', '#mp-ab-image-input', function () {
            refreshMockupStudio();
        });

        // Initialize on page load
        if ($('#mp-ab-type-select').length) {
            updateAbTypeFields();
            updatePriceCalculationPill();
            refreshMockupStudio();
        }
    });
})(jQuery);
