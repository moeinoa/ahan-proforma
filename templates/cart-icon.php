<?php
/**
 * Template for [ahp_cart_icon] — a cart icon button the user places
 * anywhere in the theme (usually the header). Clicking it opens the shared
 * drawer (cart-drawer.php, injected into wp_footer). No position: fixed, so
 * placement is entirely up to the theme.
 */
defined( 'ABSPATH' ) || exit;
?>
<button type="button" class="ahp-cart-icon-btn" data-ahp-open-drawer aria-label="<?php esc_attr_e( 'Proforma cart', 'ahan-proforma' ); ?>">
    <span class="ahp-cart-icon-svg" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="9.5" cy="20" r="1.5" fill="currentColor"/>
            <circle cx="16.5" cy="20" r="1.5" fill="currentColor"/>
        </svg>
    </span>
    <span class="ahp-cart-icon-badge" data-ahp-cart-count><?php echo (int) self::count_items(); ?></span>
</button>
