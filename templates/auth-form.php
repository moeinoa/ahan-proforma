<?php
/**
 * Customer login/sign-up form template — two steps:
 * Step 1) mobile number (+ first/last name for a new user)
 * Step 2) 5-digit SMS code — with the option to go back and correct the number
 *
 * This file is used both inside the modal (templates/auth-modal.php) and
 * standalone (the [ahp_login] shortcode on a separate page); the full logic
 * runs via AJAX in assets/js/ahp-auth.js — this file is just markup.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ahp-auth-wrap" data-ahp-auth-root>

    <!-- Step 1: mobile number -->
    <form class="ahp-auth-step ahp-auth-step-mobile" data-ahp-auth-step="mobile">
        <p class="ahp-auth-intro">
            <?php esc_html_e( 'Enter your mobile number to continue.', 'ahan-proforma' ); ?>
        </p>

        <div class="ahp-auth-field">
            <label for="ahp-auth-mobile"><?php esc_html_e( 'Mobile number', 'ahan-proforma' ); ?></label>
            <input type="tel" id="ahp-auth-mobile" name="mobile" inputmode="numeric"
                   placeholder="0912xxxxxxx" autocomplete="tel" required autofocus
                   data-ahp-auth-mobile>
        </div>

        <!-- Only shown for a new user (via JS, after the server response) -->
        <div class="ahp-auth-name-fields" data-ahp-auth-name-fields style="display:none;">
            <p class="ahp-auth-new-user-hint" data-ahp-auth-new-user-hint>
                <?php esc_html_e( 'Your number isn\'t registered on the site yet; enter your name to create an account.', 'ahan-proforma' ); ?>
            </p>
            <div class="ahp-auth-name-row">
                <div class="ahp-auth-field">
                    <label for="ahp-auth-first-name"><?php esc_html_e( 'First name', 'ahan-proforma' ); ?></label>
                    <input type="text" id="ahp-auth-first-name" name="first_name" data-ahp-auth-first-name>
                </div>
                <div class="ahp-auth-field">
                    <label for="ahp-auth-last-name"><?php esc_html_e( 'Last name', 'ahan-proforma' ); ?></label>
                    <input type="text" id="ahp-auth-last-name" name="last_name" data-ahp-auth-last-name>
                </div>
            </div>
        </div>

        <p class="ahp-auth-error" data-ahp-auth-error-mobile role="alert" style="display:none;"></p>

        <button type="submit" class="ahp-auth-submit" data-ahp-auth-send-code>
            <span class="ahp-auth-btn-label"><?php esc_html_e( 'Continue', 'ahan-proforma' ); ?></span>
            <span class="ahp-auth-spinner" aria-hidden="true"></span>
        </button>
    </form>

    <!-- Step 2: code verification -->
    <form class="ahp-auth-step ahp-auth-step-code" data-ahp-auth-step="code" style="display:none;">
        <button type="button" class="ahp-auth-back" data-ahp-auth-back>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php esc_html_e( 'Change mobile number', 'ahan-proforma' ); ?>
        </button>

        <p class="ahp-auth-step-title" data-ahp-auth-step-title>
            <?php esc_html_e( 'Log in to your account', 'ahan-proforma' ); ?>
        </p>

        <p class="ahp-auth-code-hint">
            <?php esc_html_e( 'A verification code was sent to the number below:', 'ahan-proforma' ); ?>
            <strong data-ahp-auth-mobile-display></strong>
        </p>

        <div class="ahp-auth-field">
            <label for="ahp-auth-code"><?php esc_html_e( '5-digit code', 'ahan-proforma' ); ?></label>
            <input type="text" id="ahp-auth-code" name="code" inputmode="numeric"
                   maxlength="5" autocomplete="one-time-code" required
                   data-ahp-auth-code>
        </div>

        <p class="ahp-auth-error" data-ahp-auth-error-code role="alert" style="display:none;"></p>

        <button type="submit" class="ahp-auth-submit" data-ahp-auth-verify-code>
            <span class="ahp-auth-btn-label"><?php esc_html_e( 'Verify and log in', 'ahan-proforma' ); ?></span>
            <span class="ahp-auth-spinner" aria-hidden="true"></span>
        </button>

        <button type="button" class="ahp-auth-resend" data-ahp-auth-resend disabled>
            <?php esc_html_e( 'Resend code', 'ahan-proforma' ); ?>
            (<span data-ahp-auth-resend-timer>02:00</span>)
        </button>
    </form>

    <!-- Success message (brief, before closing/redirecting) -->
    <div class="ahp-auth-step ahp-auth-step-success" data-ahp-auth-step="success" style="display:none;">
        <div class="ahp-auth-success-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <p data-ahp-auth-success-message><?php esc_html_e( 'Logged in successfully.', 'ahan-proforma' ); ?></p>
    </div>

</div>
