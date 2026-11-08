/**
 * MarketPulse Storefront A/B Testing Runner (WooCommerce).
 * Pure Vanilla JavaScript (<2.5 KB, Zero Dependencies).
 *
 * Implements:
 * 1. Immediate Veil Lift (0ms perceived lag)
 * 2. Cookie + LocalStorage Dual-Persistence (ITP resilient)
 * 3. High-Performance navigator.sendBeacon Analytics Dispatch
 * 4. Zero-flicker DOM Fallbacks for Custom WooCommerce Themes
 *
 * @package MarketPulse_For_WooCommerce
 */
(function() {
    'use strict';

    if (typeof marketpulseAB === 'undefined' || !marketpulseAB.experiment) {
        return;
    }

    var exp = marketpulseAB.experiment;
    var group = marketpulseAB.assignedGroup; // 'CONTROL' or 'VARIANT_B'
    var visitorId = marketpulseAB.visitorId;
    var apiUrl = marketpulseAB.apiUrl;

    function applyMutations() {
        // 1. Dual-Persistence: Cookie + localStorage Sync
        if (exp && exp.id && group) {
            try {
                document.cookie = '_mp_ab_exp_' + exp.id + '=' + group + '; path=/; max-age=' + (86400 * 30) + '; SameSite=Lax';
                localStorage.setItem('_mp_ab_exp_' + exp.id, group);
                localStorage.setItem('_mp_ab_visitor_id', visitorId);
            } catch (e) {
                // Fail-safe for restricted storage environments
            }
        }

        // 2. Client-side DOM Mutations (Only if Variant B and needed)
        if (group === 'VARIANT_B' && exp.testVariant && exp.testVariant.config) {
            var cfg = exp.testVariant.config;

            // Product Title Mutation (Fallback if theme bypassed server filters)
            if (exp.type === 'PRODUCT_TITLE' && cfg.title) {
                var titleEls = document.querySelectorAll('.product_title, h1.entry-title');
                for (var i = 0; i < titleEls.length; i++) {
                    if (titleEls[i].textContent !== cfg.title) {
                        titleEls[i].textContent = cfg.title;
                    }
                }
            }

            // Creative Image Mutation (Fallback for custom theme slider wrappers)
            if (exp.type === 'CREATIVE_IMAGE' && cfg.imageUrl) {
                var imgEl = document.querySelector('.woocommerce-product-gallery__image img, .wp-post-image');
                if (imgEl && imgEl.src !== cfg.imageUrl) {
                    imgEl.src = cfg.imageUrl;
                    imgEl.removeAttribute('srcset');
                }
            }

            // CTA Button Mutation (Button text & color fallback)
            if (exp.type === 'CTA_BUTTON') {
                var btnEls = document.querySelectorAll('.single_add_to_cart_button');
                for (var j = 0; j < btnEls.length; j++) {
                    var btn = btnEls[j];
                    if (cfg.buttonText && btn.textContent !== cfg.buttonText) {
                        btn.textContent = cfg.buttonText;
                    }
                    if (cfg.color) {
                        btn.style.setProperty('background-color', cfg.color, 'important');
                        btn.style.setProperty('border-color', cfg.color, 'important');
                    }
                }
            }
        }

        // 3. Lift Anti-Flicker Veil Immediately
        if (typeof window._mpLiftVeil === 'function') {
            window._mpLiftVeil();
        } else {
            document.documentElement.classList.remove('mp-ab-loading');
        }

        // 4. Non-Blocking Analytics Beacon (navigator.sendBeacon with fetch fallback)
        var variantId = (group === 'VARIANT_B') ? exp.testVariant.id : exp.controlVariant.id;
        if (variantId && apiUrl) {
            var endpoint = apiUrl + '/ab-testing/storefront/events';
            var payload = JSON.stringify({
                experimentId: exp.id,
                variantId: variantId,
                visitorUuid: visitorId,
                eventType: 'PAGE_VIEW'
            });

            if (navigator.sendBeacon) {
                var blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon(endpoint, blob);
            } else if (window.fetch) {
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload,
                    keepalive: true
                }).catch(function() {
                    // Non-blocking fail open
                });
            }
        }
    }

    // Execute as early as possible without waiting for full window.load
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        applyMutations();
    } else {
        document.addEventListener('DOMContentLoaded', applyMutations);
    }
})();
