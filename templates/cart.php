<?php
/**
 * The proforma cart template — just the initial HTML skeleton; the actual
 * cart content is filled in by assets/js/ahp-cart.js via AJAX (ahp_cart_get)
 * so it always stays in sync with page cache (the cart must never be stored
 * in cached HTML).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ahp-cart-wrap" data-ahp-cart-root>
    <h3 class="ahp-cart-title"><?php esc_html_e( 'Proforma cart', 'ahan-proforma' ); ?></h3>

    <div class="ahp-cart-body" data-ahp-cart-body>
        <p class="ahp-cart-loading"><?php esc_html_e( 'Loading…', 'ahan-proforma' ); ?></p>
    </div>

    <div class="ahp-cart-footer" data-ahp-cart-footer style="display:none;">
        <div class="ahp-cart-total">
            <span class="ahp-cart-total-label"><?php esc_html_e( 'Grand total', 'ahan-proforma' ); ?></span>
            <span><span data-ahp-cart-total></span> <?php esc_html_e( 'Toman', 'ahan-proforma' ); ?></span>
        </div>
        <button type="button" class="ahp-checkout-btn" data-ahp-issue-invoice>
            <?php esc_html_e( 'Issue proforma invoice', 'ahan-proforma' ); ?>
        </button>
        <p class="ahp-cart-issue-msg" data-ahp-issue-msg role="status" aria-live="polite"></p>
    </div>
</div>
