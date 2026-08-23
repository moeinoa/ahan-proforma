<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Account
 *
 * Phase 6: customer account page — [ahp_account] shortcode, three tabs:
 *   1) Account (default): list of the user's past proforma invoices (from
 *      AHP_DB::get_invoices_for_user; currently usually empty since Phase 4
 *      doesn't yet have real issuance, but it fills in automatically with no
 *      further changes needed)
 *   2) Proforma cart: just a link to the ahp_cart_page_url filter address
 *      (the cart itself was implemented in Phase 2 and isn't duplicated here)
 *   3) Edit info (?tab=edit): first/last name (saved directly via AJAX) +
 *      mobile number (requires SMS-code verification before the change is applied)
 *
 * Tab links are based on the same filters already defined in ahp-auth.js
 * (auth-icon.php): ahp_account_page_url, ahp_cart_page_url,
 * ahp_account_edit_url — this class renders the same page with ?tab=...
 */
class AHP_Account {

    private const NONCE_ACTION = 'ahp_account_action';

    public static function init(): void {
        add_shortcode( 'ahp_account', [ __CLASS__, 'render_account_shortcode' ] );

        add_action( 'wp_ajax_ahp_account_update_name',            [ __CLASS__, 'ajax_update_name' ] );
        add_action( 'wp_ajax_ahp_account_request_mobile_change',  [ __CLASS__, 'ajax_request_mobile_change' ] );
        add_action( 'wp_ajax_ahp_account_confirm_mobile_change',  [ __CLASS__, 'ajax_confirm_mobile_change' ] );
        // These actions only make sense for a logged-in user; a nopriv
        // version is deliberately not registered (same pattern as AHP_Cart
        // for actions that require login).

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    /* ── Main shortcode ────────────────────────────────────────────────────── */

    public static function render_account_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            // Instead of a plain message, the standalone login/sign-up form
            // (the same [ahp_login]) is rendered directly here; because the
            // /account/ page is a direct entry point the user may reach
            // without going through the header popup (direct link, bookmark,
            // redirect, etc.). Another reason: [ahp_login] itself calls
            // prevent_page_caching so the form's nonce doesn't get stuck in
            // page cache.
            //
            // data-ahp-account-guest-login: an explicit marker for
            // ahp-account.js to only refresh the page after a successful
            // login in this specific case (login form instead of account
            // tabs). This decision used to be made by the absence of
            // [data-ahp-account-root] (inverted logic), which — because
            // ahp-account.js is enqueued on every page of the site (not just
            // the account page) — caused the whole page to reload after
            // login on any other page too (e.g. the proforma cart page) —
            // exactly the opposite of the expected behavior (no
            // reload/redirect).
            return '<div data-ahp-account-guest-login>' . AHP_Auth::render_login_shortcode() . '</div>';
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'invoices';
        if ( ! in_array( $tab, [ 'invoices', 'edit' ], true ) ) {
            $tab = 'invoices';
        }

        $user     = wp_get_current_user();
        $mobile   = get_user_meta( $user->ID, AHP_Auth::mobile_meta_key(), true );
        $invoices = AHP_DB::get_invoices_for_user( $user->ID );

        ob_start();
        include AHP_PLUGIN_DIR . 'templates/account-page.php';
        return (string) ob_get_clean();
    }

    public static function enqueue_assets(): void {
        // Only actually needed when the shortcode is used on the page, but
        // since wp_enqueue_scripts runs before the content is rendered (and
        // global $post isn't reliable with some page builders/caches), we
        // always enqueue — same pattern as AHP_Auth/AHP_Cart; the file sizes
        // are negligible.
        wp_enqueue_style( 'ahp-account', AHP_PLUGIN_URL . 'assets/css/ahp-account.css', [ 'ahp-auth' ], AHP_VERSION );

        wp_enqueue_script(
            'ahp-account',
            AHP_PLUGIN_URL . 'assets/js/ahp-account.js',
            [],
            AHP_VERSION,
            true
        );

        wp_localize_script( 'ahp-account', 'AHP_ACCOUNT', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'i18n'    => [
                'saving'               => __( 'Saving…', 'ahan-proforma' ),
                'saved'                => __( 'Saved.', 'ahan-proforma' ),
                'genericError'         => __( 'Something went wrong; please try again.', 'ahan-proforma' ),
                'firstNameRequired'    => __( 'First name cannot be empty.', 'ahan-proforma' ),
                'lastNameRequired'     => __( 'Last name cannot be empty.', 'ahan-proforma' ),
                'invalidMobile'        => __( 'The mobile number is not valid.', 'ahan-proforma' ),
                'sameMobile'           => __( 'This is already your current number.', 'ahan-proforma' ),
                'mobileTaken'          => __( 'This number is already registered to another account.', 'ahan-proforma' ),
                'codeSentTo'           => __( 'A verification code was sent to the number below:', 'ahan-proforma' ),
                'codePlaceholder'      => __( '5-digit code', 'ahan-proforma' ),
                'confirmCode'          => __( 'Verify and apply', 'ahan-proforma' ),
                'sendCode'             => __( 'Send verification code', 'ahan-proforma' ),
                'resendCode'           => __( 'Resend code', 'ahan-proforma' ),
                'mobileChanged'        => __( 'Mobile number changed successfully.', 'ahan-proforma' ),
                'cancel'               => __( 'Cancel', 'ahan-proforma' ),
            ],
        ] );
    }

    /* ── AJAX: change first/last name ─────────────────────────────────────── */

    public static function ajax_update_name(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        self::require_login();

        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );

        if ( $first === '' || $last === '' ) {
            wp_send_json_error( [ 'message' => __( 'First and last name cannot be empty.', 'ahan-proforma' ) ], 400 );
        }

        $user_id = get_current_user_id();
        wp_update_user( [
            'ID'           => $user_id,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ),
            'nickname'     => trim( $first . ' ' . $last ), // per the Phase 3 note: nickname should never stay equal to the number/login
        ] );

        wp_send_json_success( [ 'message' => __( 'Information saved successfully.', 'ahan-proforma' ) ] );
    }

    /* ── AJAX: change mobile number — step 1 (request code) ───────────────── */

    public static function ajax_request_mobile_change(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        self::require_login();

        $mobile = AHP_Auth::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
        if ( ! $mobile ) {
            wp_send_json_error( [ 'message' => __( 'The mobile number is invalid.', 'ahan-proforma' ) ], 400 );
        }

        $user_id       = get_current_user_id();
        $current_mobile = get_user_meta( $user_id, AHP_Auth::mobile_meta_key(), true );

        if ( $mobile === $current_mobile ) {
            wp_send_json_error( [ 'message' => __( 'This is already your current number.', 'ahan-proforma' ) ], 400 );
        }

        // If this number belongs to another user, the change is not allowed
        $existing = AHP_Auth::get_user_by_mobile( $mobile );
        if ( $existing && $existing->ID !== $user_id ) {
            wp_send_json_error( [ 'message' => __( 'This number is already registered to another account.', 'ahan-proforma' ) ], 409 );
        }

        $result = AHP_Auth::issue_otp_for_mobile( $mobile );
        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ], 429 );
        }

        wp_send_json_success( [
            'message'    => __( 'A verification code was sent to the new number.', 'ahan-proforma' ),
            'ttlSeconds' => $result['ttlSeconds'],
            'mobile'     => $mobile,
        ] );
    }

    /* ── AJAX: change mobile number — step 2 (verify code and apply) ───────── */

    public static function ajax_confirm_mobile_change(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        self::require_login();

        $mobile = AHP_Auth::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
        $code   = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );

        if ( ! $mobile || $code === '' ) {
            wp_send_json_error( [ 'message' => __( 'The submitted data is invalid.', 'ahan-proforma' ) ], 400 );
        }

        $user_id = get_current_user_id();

        // Re-check the number conflict right before applying, since it may
        // have been claimed by another user between step 1 and step 2.
        $existing = AHP_Auth::get_user_by_mobile( $mobile );
        if ( $existing && $existing->ID !== $user_id ) {
            wp_send_json_error( [ 'message' => __( 'This number is already registered to another account.', 'ahan-proforma' ) ], 409 );
        }

        $result = AHP_Auth::verify_otp_for_mobile( $mobile, $code );
        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ], 401 );
        }

        update_user_meta( $user_id, AHP_Auth::mobile_meta_key(), $mobile );

        wp_send_json_success( [
            'message' => __( 'Mobile number changed successfully.', 'ahan-proforma' ),
            'mobile'  => $mobile,
        ] );
    }

    /* ── Helper ────────────────────────────────────────────────────────────── */

    private static function require_login(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in to your account first.', 'ahan-proforma' ) ], 401 );
        }
    }
}
