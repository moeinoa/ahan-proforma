<?php
/**
 * Login/sign-up popup (modal) — injected once into wp_footer, independent
 * of how many times [ahp_login_button] is used on the page.
 *
 * The form content is exactly the same as what was in templates/login.php;
 * that file stays unchanged for possible use on a standalone page (fallback).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ahp-modal-overlay" data-ahp-auth-overlay hidden></div>

<div class="ahp-modal ahp-auth-modal" data-ahp-auth-modal role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="ahp-auth-modal-title">
    <div class="ahp-modal-header">
        <h3 class="ahp-modal-title" id="ahp-auth-modal-title" data-ahp-auth-modal-title>
            <?php esc_html_e( 'Log in / Sign up', 'ahan-proforma' ); ?>
        </h3>
        <button type="button" class="ahp-modal-close" data-ahp-auth-close aria-label="<?php esc_attr_e( 'Close', 'ahan-proforma' ); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </button>
    </div>

    <div class="ahp-modal-body">
        <?php AHP_Auth::render_auth_form(); ?>
    </div>
</div>
