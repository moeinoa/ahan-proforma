<?php
/**
 * Template for [ahp_login_button] — an icon button for login/account,
 * matching the style of [ahp_cart_icon] (templates/cart-icon.php).
 *
 * Both states (guest / logged-in) are always rendered in the HTML, and one
 * is hidden via data-ahp-auth-state (which PHP sets based on
 * is_user_logged_in() at initial page load). This is deliberate: since
 * login happens in the popup via AJAX (no page reload), if only the guest
 * state existed in the HTML, there would be no way to show the account icon
 * after a successful login other than a full page reload. With this
 * structure, ahp-auth.js only toggles a class/hidden state — no reload or
 * new server request needed.
 */
defined( 'ABSPATH' ) || exit;

$is_logged_in = is_user_logged_in();
$account_url  = apply_filters( 'ahp_account_page_url', home_url( '/account/' ) );
$cart_url     = apply_filters( 'ahp_cart_page_url', home_url( '/proforma-cart/' ) );
$edit_url     = apply_filters( 'ahp_account_edit_url', home_url( '/account/?tab=edit' ) );
$current_url  = home_url( $_SERVER['REQUEST_URI'] ?? '/' );
$logout_url   = wp_logout_url( $current_url );
?>
<div class="ahp-auth-icon-wrap" data-ahp-auth-icon-wrap data-ahp-auth-state="<?php echo $is_logged_in ? 'in' : 'out'; ?>">

    <!-- Guest state: clicking opens the login/sign-up popup -->
    <button type="button" class="ahp-auth-icon-btn" data-ahp-open-auth
            style="<?php echo $is_logged_in ? 'display:none;' : ''; ?>"
            aria-label="<?php esc_attr_e( 'Log in / Sign up', 'ahan-proforma' ); ?>">
        <svg viewBox="0 0 24 24" width="21" height="21" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.8"/>
            <path d="M4.5 20c1.2-3.5 4.2-5.5 7.5-5.5s6.3 2 7.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
    </button>

    <!-- Logged-in state: clicking opens the mini-menu (account/cart/edit/logout) -->
    <div class="ahp-account-menu-root" style="<?php echo $is_logged_in ? '' : 'display:none;'; ?>" data-ahp-account-menu-root>
        <button type="button" class="ahp-auth-icon-btn ahp-auth-icon-btn-active" data-ahp-toggle-account-menu
                aria-label="<?php esc_attr_e( 'Account', 'ahan-proforma' ); ?>" aria-haspopup="true" aria-expanded="false">
            <svg viewBox="0 0 24 24" width="21" height="21" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.8"/>
                <path d="M4.5 20c1.2-3.5 4.2-5.5 7.5-5.5s6.3 2 7.5 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </button>

        <div class="ahp-account-menu" data-ahp-account-menu hidden>
            <a href="<?php echo esc_url( $account_url ); ?>" class="ahp-account-menu-item">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.7"/><path d="M4.5 20c1.2-3.5 4.2-5.5 7.5-5.5s6.3 2 7.5 5.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <?php esc_html_e( 'Account', 'ahan-proforma' ); ?>
            </a>
            <a href="<?php echo esc_url( $cart_url ); ?>" class="ahp-account-menu-item">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.4" fill="currentColor"/><circle cx="16.5" cy="20" r="1.4" fill="currentColor"/></svg>
                <?php esc_html_e( 'Proforma cart', 'ahan-proforma' ); ?>
            </a>
            <a href="<?php echo esc_url( $edit_url ); ?>" class="ahp-account-menu-item">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php esc_html_e( 'Edit information', 'ahan-proforma' ); ?>
            </a>
            <a href="<?php echo esc_url( $logout_url ); ?>" class="ahp-account-menu-item ahp-account-menu-item-danger">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?php esc_html_e( 'Log out', 'ahan-proforma' ); ?>
            </a>
        </div>
    </div>

</div>
