<?php
/**
 * "Edit information" tab — two independent forms:
 *   1) First/last name: saved directly, no verification needed (ahp_account_update_name)
 *   2) Mobile number: two steps — request a code for the new number, then
 *      verify the code before applying (ahp_account_request_mobile_change / ahp_account_confirm_mobile_change)
 *
 * Available variables: $user (WP_User), $mobile (string, current number)
 */
defined( 'ABSPATH' ) || exit;
?>
<h2 class="ahp-account-panel-title"><?php esc_html_e( 'Edit information', 'ahan-proforma' ); ?></h2>

<form class="ahp-account-form" data-ahp-name-form>
    <h3 class="ahp-account-form-title"><?php esc_html_e( 'First and last name', 'ahan-proforma' ); ?></h3>

    <div class="ahp-account-field-row">
        <label for="ahp-account-first-name"><?php esc_html_e( 'First name', 'ahan-proforma' ); ?></label>
        <input type="text" id="ahp-account-first-name" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" required />
    </div>

    <div class="ahp-account-field-row">
        <label for="ahp-account-last-name"><?php esc_html_e( 'Last name', 'ahan-proforma' ); ?></label>
        <input type="text" id="ahp-account-last-name" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" required />
    </div>

    <p class="ahp-account-form-msg" data-ahp-name-msg role="status" aria-live="polite"></p>

    <button type="submit" class="ahp-account-save-btn"><?php esc_html_e( 'Save', 'ahan-proforma' ); ?></button>
</form>

<hr class="ahp-account-divider" />

<div class="ahp-account-mobile-block" data-ahp-mobile-block>
    <h3 class="ahp-account-form-title"><?php esc_html_e( 'Mobile number', 'ahan-proforma' ); ?></h3>
    <p class="ahp-account-mobile-current">
        <?php esc_html_e( 'Current number:', 'ahan-proforma' ); ?>
        <strong data-ahp-mobile-current-value><?php echo esc_html( $mobile ); ?></strong>
    </p>

    <!-- Step 1: enter the new number -->
    <form class="ahp-account-form" data-ahp-mobile-step1-form>
        <div class="ahp-account-field-row">
            <label for="ahp-account-new-mobile"><?php esc_html_e( 'New mobile number', 'ahan-proforma' ); ?></label>
            <input type="text" id="ahp-account-new-mobile" name="mobile" inputmode="numeric" placeholder="0912xxxxxxx" />
        </div>
        <p class="ahp-account-form-msg" data-ahp-mobile-step1-msg role="status" aria-live="polite"></p>
        <button type="submit" class="ahp-account-save-btn" data-ahp-mobile-step1-submit>
            <?php esc_html_e( 'Send verification code', 'ahan-proforma' ); ?>
        </button>
    </form>

    <!-- Step 2: verify code (hidden until step 1 succeeds) -->
    <form class="ahp-account-form" data-ahp-mobile-step2-form hidden>
        <p class="ahp-account-mobile-code-intro">
            <?php esc_html_e( 'A verification code was sent to the number below:', 'ahan-proforma' ); ?>
            <strong data-ahp-mobile-pending-value></strong>
        </p>
        <div class="ahp-account-field-row">
            <label for="ahp-account-mobile-code"><?php esc_html_e( '5-digit code', 'ahan-proforma' ); ?></label>
            <input type="text" id="ahp-account-mobile-code" name="code" inputmode="numeric" maxlength="5" autocomplete="one-time-code" />
        </div>
        <p class="ahp-account-form-msg" data-ahp-mobile-step2-msg role="status" aria-live="polite"></p>
        <div class="ahp-account-mobile-step2-actions">
            <button type="submit" class="ahp-account-save-btn"><?php esc_html_e( 'Verify and apply', 'ahan-proforma' ); ?></button>
            <button type="button" class="ahp-account-cancel-btn" data-ahp-mobile-cancel><?php esc_html_e( 'Cancel', 'ahan-proforma' ); ?></button>
        </div>
    </form>
</div>
