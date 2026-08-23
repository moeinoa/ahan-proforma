<?php
/**
 * The proforma cart's slide-out drawer — injected once into wp_footer (not
 * once per use of the [ahp_cart_icon] shortcode). Its body content is
 * filled exactly like [ahp_cart], via AJAX (ahp_cart_get).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ahp-drawer-overlay" data-ahp-drawer-overlay hidden></div>

<aside class="ahp-drawer" data-ahp-cart-root data-ahp-drawer role="dialog" aria-modal="true" aria-hidden="true">
    <div class="ahp-drawer-header">
        <h3 class="ahp-drawer-title"><?php esc_html_e( 'Proforma cart', 'ahan-proforma' ); ?></h3>
        <button type="button" class="ahp-drawer-close" data-ahp-close-drawer aria-label="<?php esc_attr_e( 'Close', 'ahan-proforma' ); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <div class="ahp-drawer-body" data-ahp-cart-body>
        <p class="ahp-cart-loading"><?php esc_html_e( 'Loading…', 'ahan-proforma' ); ?></p>
    </div>

    <div class="ahp-drawer-footer" data-ahp-cart-footer style="display:none;">
        <div class="ahp-cart-total-row">
            <span class="ahp-cart-total-label"><?php esc_html_e( 'Grand total', 'ahan-proforma' ); ?></span>
            <span class="ahp-cart-total-value"><span data-ahp-cart-total></span> <?php esc_html_e( 'Toman', 'ahan-proforma' ); ?></span>
        </div>
        <div class="ahp-drawer-footer-actions">
            <a href="<?php echo esc_url( apply_filters( 'ahp_cart_page_url', home_url( '/proforma-cart/' ) ) ); ?>" class="ahp-view-cart-btn" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'View proforma cart', 'ahan-proforma' ); ?>
            </a>
            <button type="button" class="ahp-checkout-btn" data-ahp-issue-invoice>
                <?php esc_html_e( 'Issue proforma invoice', 'ahan-proforma' ); ?>
            </button>
        </div>
        <p class="ahp-cart-issue-msg" data-ahp-issue-msg role="status" aria-live="polite"></p>
    </div>
</aside>
