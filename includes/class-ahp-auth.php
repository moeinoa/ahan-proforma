<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Auth
 *
 * Customer login/sign-up using only a mobile number + a one-time SMS code;
 * no password and no classic WordPress login form.
 *
 * Flow:
 *  1) User enters their number (ajax_request_code)
 *     - If the number isn't in wp_users, the front end shows a first/last
 *       name form, sent along with the number; the plugin keeps them as
 *       pending in the ahp_otp_requests table (no real user is created
 *       until the number is verified)
 *  2) User enters the 5-digit code (ajax_verify_code)
 *     - On success: if the user didn't exist, they're created (ahan_customer role)
 *     - wp_set_auth_cookie + do_action('wp_login', ...) is called so
 *       WordPress's standard hook (and the guest-cart merge in AHP_Cart) runs
 *
 * WordPress username (user_login) = the normalized mobile number; no
 * separate username field is collected from the user.
 *
 * Rate limiting (sensible defaults, changeable via filter):
 *  - max 3 code requests per 10 minutes per number
 *  - max 5 wrong attempts per issued code
 */
class AHP_Auth {

    private const CODE_LENGTH        = 5;
    private const CODE_TTL_SECONDS   = 2 * MINUTE_IN_SECONDS;
    private const MAX_REQUESTS_WINDOW_MIN = 2;
    private const MAX_REQUESTS_PER_WINDOW = 2;
    private const MAX_VERIFY_ATTEMPTS = 5;
    private const NONCE_ACTION       = 'ahp_auth_action';
    private const USERMETA_MOBILE    = 'ahp_mobile';

    public static function init(): void {
        add_action( 'wp_ajax_nopriv_ahp_request_code', [ __CLASS__, 'ajax_request_code' ] );
        add_action( 'wp_ajax_nopriv_ahp_verify_code',  [ __CLASS__, 'ajax_verify_code' ] );
        // Lightweight action to get a fresh nonce, with no full page reload;
        // mainly to handle a stale nonce left over in HTML cached by page-cache
        // plugins (LiteSpeed/WP Rocket/...).
        add_action( 'wp_ajax_nopriv_ahp_refresh_nonce', [ __CLASS__, 'ajax_refresh_nonce' ] );
        // A logged-in user doesn't need these actions; wp_ajax_ (without nopriv) is deliberately not registered.

        // Clear the helper ahp_logged_in cookie on logout (whether via our
        // plugin or WordPress's own wp-login.php), so the guest icon shows
        // correctly after logout instead of a stale account icon from the
        // browser's old cache.
        add_action( 'wp_logout', [ __CLASS__, 'clear_client_login_cookie' ] );

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_shortcode( 'ahp_login', [ __CLASS__, 'render_login_shortcode' ] );
        add_shortcode( 'ahp_login_button', [ __CLASS__, 'render_login_button_shortcode' ] );
        add_action( 'wp_footer', [ __CLASS__, 'render_auth_modal_in_footer' ] );
    }

    /* ── Mobile number normalization ───────────────────────────────────────── */

    /**
     * Converts the number to the uniform 09xxxxxxxxx format; null if invalid.
     * Accepted inputs: 09xxxxxxxxx , 9xxxxxxxxx , +989xxxxxxxxx , 00989xxxxxxxxx
     */
    public static function normalize_mobile( string $raw ): ?string {
        $raw = trim( $raw );
        // Persian/Arabic digits to Latin
        $raw = str_replace(
            [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩' ],
            [ '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9' ],
            $raw
        );
        $raw = preg_replace( '/[^0-9+]/', '', $raw );

        if ( str_starts_with( $raw, '0098' ) )       $raw = substr( $raw, 4 );
        elseif ( str_starts_with( $raw, '+98' ) )     $raw = substr( $raw, 3 );
        elseif ( str_starts_with( $raw, '98' ) && strlen( $raw ) === 12 ) $raw = substr( $raw, 2 );

        if ( str_starts_with( $raw, '0' ) ) $raw = substr( $raw, 1 );

        // Must now be exactly 10 digits starting with 9 (Iranian mobile)
        if ( ! preg_match( '/^9\d{9}$/', $raw ) ) return null;

        return '0' . $raw;
    }

    /* ── Step 1: request a code ────────────────────────────────────────────── */

    public static function ajax_request_code(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $mobile = self::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
        if ( ! $mobile ) {
            wp_send_json_error( [ 'message' => __( 'The mobile number is invalid.', 'ahan-proforma' ) ], 400 );
        }

        // Per-number rate limit
        $recent = AHP_DB::count_recent_otp_requests( $mobile, self::MAX_REQUESTS_WINDOW_MIN );
        if ( $recent >= self::MAX_REQUESTS_PER_WINDOW ) {
            wp_send_json_error( [
                'message' => __( 'You have made too many requests; please try again shortly.', 'ahan-proforma' ),
            ], 429 );
        }

        // Per-IP rate limit: the per-number limit alone doesn't stop one client
        // from burning SMS credit by cycling through many different numbers.
        if ( ! self::ip_within_request_limit() ) {
            wp_send_json_error( [
                'message' => __( 'You have made too many requests; please try again shortly.', 'ahan-proforma' ),
            ], 429 );
        }

        $existing_user = self::find_user_by_mobile( $mobile );

        $pending_first = null;
        $pending_last  = null;

        if ( ! $existing_user ) {
            // New user: first/last name are required
            $pending_first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
            $pending_last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );

            if ( $pending_first === '' || $pending_last === '' ) {
                wp_send_json_error( [
                    'message'  => __( 'First and last name are required to sign up.', 'ahan-proforma' ),
                    'needsName'=> true,
                ], 400 );
            }
        }

        $code      = self::generate_code();
        $code_hash = wp_hash_password( $code );

        $otp_id = AHP_DB::insert_otp( [
            'mobile'             => $mobile,
            'code_hash'          => $code_hash,
            'pending_first_name' => $pending_first,
            'pending_last_name'  => $pending_last,
            'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS ),
            'ip_address'         => self::client_ip(),
        ] );

        $send_result = AHP_SMS_Gateway::send_otp( $mobile, $code );

        if ( ! $send_result['ok'] ) {
            wp_send_json_error( [
                'message' => __( 'Sending the SMS failed; please try again later.', 'ahan-proforma' ),
            ], 502 );
        }

        wp_send_json_success( [
            'message'    => __( 'A verification code has been sent to you by SMS.', 'ahan-proforma' ),
            'isNewUser'  => ! $existing_user,
            'ttlSeconds' => self::CODE_TTL_SECONDS,
            // Return the normalized value to the front end so that verify()
            // passes back exactly this same string (the one stored in the
            // DB); relying on the raw input value meant different input
            // formats (e.g. Persian digits or a +98 prefix) wouldn't match
            // the OTP record.
            'mobile'     => $mobile,
        ] );
    }

    /* ── Step 2: verify the code and log in/sign up ───────────────────────── */

    public static function ajax_verify_code(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $mobile = self::normalize_mobile( sanitize_text_field( wp_unslash( $_POST['mobile'] ?? '' ) ) );
        $code   = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );

        if ( ! $mobile || $code === '' ) {
            wp_send_json_error( [ 'message' => __( 'The submitted data is invalid.', 'ahan-proforma' ) ], 400 );
        }

        $otp = AHP_DB::get_latest_active_otp( $mobile );

        if ( ! $otp ) {
            wp_send_json_error( [ 'message' => __( 'No code was found for this number; please request one again.', 'ahan-proforma' ) ], 404 );
        }

        if ( (int) $otp['attempts'] >= self::MAX_VERIFY_ATTEMPTS ) {
            wp_send_json_error( [ 'message' => __( 'You have exceeded the allowed number of attempts; please request a new code.', 'ahan-proforma' ) ], 429 );
        }

        if ( strtotime( $otp['expires_at'] . ' UTC' ) < time() ) {
            wp_send_json_error( [ 'message' => __( 'The code has expired; please request a new one.', 'ahan-proforma' ) ], 410 );
        }

        if ( ! wp_check_password( $code, $otp['code_hash'] ) ) {
            AHP_DB::increment_otp_attempts( (int) $otp['id'] );
            wp_send_json_error( [ 'message' => __( 'The code you entered is incorrect.', 'ahan-proforma' ) ], 401 );
        }

        AHP_DB::mark_otp_consumed( (int) $otp['id'] );

        $user = self::find_user_by_mobile( $mobile );
        $is_new_user = ! $user;

        if ( ! $user ) {
            $user_id = self::create_customer_user(
                $mobile,
                (string) ( $otp['pending_first_name'] ?? '' ),
                (string) ( $otp['pending_last_name']  ?? '' )
            );
            if ( is_wp_error( $user_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Error creating the account.', 'ahan-proforma' ) ], 500 );
            }
            $user = get_user_by( 'id', $user_id );
        }

        // Standard WordPress login: we don't use wp_signon because we have no
        // password; instead we set the auth cookie directly and fire the
        // wp_login hook with the standard signature (user_login, WP_User) so
        // Phase 2 code (AHP_Cart::merge_guest_cart_into_user) works unchanged.
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );
        // A lightweight, non-HttpOnly cookie (no sensitive data, just a 0/1
        // flag) so the client side -- even on a stale cached page whose HTML
        // still shows the "guest" icon -- can tell the user is really logged
        // in, and show the correct (account) icon on the next page load.
        // Pattern similar to woocommerce_items_in_cart in WooCommerce, for
        // compatibility with full-page cache plugins.
        setcookie( 'ahp_logged_in', '1', time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false );
        do_action( 'wp_login', $user->user_login, $user );

        wp_send_json_success( [
            'message'    => $is_new_user
                ? __( 'Signed up successfully; welcome!', 'ahan-proforma' )
                : __( 'Logged in successfully.', 'ahan-proforma' ),
            'isNewUser'  => $is_new_user,
            'redirect'   => apply_filters( 'ahp_login_redirect_url', home_url( '/' ), $user ),
        ] );
    }

    /* ── Fresh nonce (for when the page was served from cache and the nonce is expired) ── */

    public static function ajax_refresh_nonce(): void {
        wp_send_json_success( [ 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ] );
    }

    public static function clear_client_login_cookie(): void {
        setcookie( 'ahp_logged_in', '', time() - YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), false );
    }

    /* ── Helpers ───────────────────────────────────────────────────────────── */

    private static function generate_code(): string {
        $max = (int) str_repeat( '9', self::CODE_LENGTH );
        $min = (int) ( '1' . str_repeat( '0', self::CODE_LENGTH - 1 ) );
        return (string) random_int( $min, $max );
    }

    /**
     * Public version of find_user_by_mobile — for use by other plugin
     * classes (e.g. AHP_Account when changing the mobile number in Phase 6)
     * without duplicating the query.
     */
    public static function get_user_by_mobile( string $mobile ): ?WP_User {
        return self::find_user_by_mobile( $mobile );
    }

    /** The mobile-number usermeta key — for use by other plugin classes */
    public static function mobile_meta_key(): string {
        return self::USERMETA_MOBILE;
    }

    /**
     * Generate and send a verification code for a number, independent of the
     * login/sign-up flow — used by Phase 6 (changing the mobile number on
     * the account page). Uses the same rate limit and ahp_otp_requests
     * table; pending_first_name/pending_last_name stay empty because this
     * flow doesn't create a new user.
     *
     * @return array{ok:bool, message?:string, code?:string} 'ok' => true on success
     */
    public static function issue_otp_for_mobile( string $mobile ): array {
        $recent = AHP_DB::count_recent_otp_requests( $mobile, self::MAX_REQUESTS_WINDOW_MIN );
        if ( $recent >= self::MAX_REQUESTS_PER_WINDOW ) {
            return [ 'ok' => false, 'message' => __( 'You have made too many requests; please try again shortly.', 'ahan-proforma' ) ];
        }

        $code      = self::generate_code();
        $code_hash = wp_hash_password( $code );

        AHP_DB::insert_otp( [
            'mobile'             => $mobile,
            'code_hash'          => $code_hash,
            'pending_first_name' => null,
            'pending_last_name'  => null,
            'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS ),
            'ip_address'         => self::client_ip(),
        ] );

        $send_result = AHP_SMS_Gateway::send_otp( $mobile, $code );
        if ( ! $send_result['ok'] ) {
            return [ 'ok' => false, 'message' => __( 'Sending the SMS failed; please try again later.', 'ahan-proforma' ) ];
        }

        return [ 'ok' => true, 'ttlSeconds' => self::CODE_TTL_SECONDS ];
    }

    /**
     * Verify a code for a number, independent of the login/sign-up flow —
     * used by Phase 6. Unlike ajax_verify_code, this creates no user and
     * sets no cookie/session; it only returns whether the code is correct
     * and marks it consumed on success.
     */
    public static function verify_otp_for_mobile( string $mobile, string $code ): array {
        $otp = AHP_DB::get_latest_active_otp( $mobile );

        if ( ! $otp ) {
            return [ 'ok' => false, 'message' => __( 'No code was found for this number; please request one again.', 'ahan-proforma' ) ];
        }
        if ( (int) $otp['attempts'] >= self::MAX_VERIFY_ATTEMPTS ) {
            return [ 'ok' => false, 'message' => __( 'You have exceeded the allowed number of attempts; please request a new code.', 'ahan-proforma' ) ];
        }
        if ( strtotime( $otp['expires_at'] . ' UTC' ) < time() ) {
            return [ 'ok' => false, 'message' => __( 'The code has expired; please request a new one.', 'ahan-proforma' ) ];
        }
        if ( ! wp_check_password( $code, $otp['code_hash'] ) ) {
            AHP_DB::increment_otp_attempts( (int) $otp['id'] );
            return [ 'ok' => false, 'message' => __( 'The code you entered is incorrect.', 'ahan-proforma' ) ];
        }

        AHP_DB::mark_otp_consumed( (int) $otp['id'] );
        return [ 'ok' => true ];
    }

    private static function find_user_by_mobile( string $mobile ): ?WP_User {
        $users = get_users( [
            'meta_key'   => self::USERMETA_MOBILE,
            'meta_value' => $mobile,
            'number'     => 1,
        ] );
        return $users[0] ?? null;
    }

    /**
     * Creates a new user with the ahan_customer role.
     * user_login = mobile number (no separate username field is collected).
     */
    private static function create_customer_user( string $mobile, string $first_name, string $last_name ) {
        $login = $mobile;

        // Unlikely, but for safety: if this login is somehow already taken, add a short suffix
        if ( username_exists( $login ) ) {
            $login .= '_' . wp_generate_password( 4, false, false );
        }

        $random_password = wp_generate_password( 24, true, true ); // never shown/sent to the user; login is always via OTP

        $full_name = trim( $first_name . ' ' . $last_name );

        $user_id = wp_insert_user( [
            'user_login'   => $login,
            'user_pass'    => $random_password,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => $full_name,
            // Without explicitly setting nickname, WordPress defaults it to
            // user_login (which is the mobile number); that caused the
            // number to show up in the "nickname" field. The mobile number
            // has its own dedicated place in usermeta (ahp_mobile) and is
            // shown on the user profile by AHP_User_Fields.
            'nickname'     => $full_name !== '' ? $full_name : $login,
            'role'         => AHP_Roles::CUSTOMER_ROLE,
        ] );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        update_user_meta( $user_id, self::USERMETA_MOBILE, $mobile );

        return $user_id;
    }

    /**
     * Counts one code request for the caller's IP and says whether it is still
     * within the limit (default: 10 requests / 10 minutes, deliberately generous
     * so offices behind one shared IP are not affected). Adjustable via the
     * ahp_otp_ip_limit filter: [ max_requests, window_seconds ]; max 0 disables it.
     */
    private static function ip_within_request_limit(): bool {
        $ip = self::client_ip();
        if ( ! $ip ) {
            return true;
        }

        [ $max, $window ] = apply_filters( 'ahp_otp_ip_limit', [ 10, 10 * MINUTE_IN_SECONDS ] );
        if ( (int) $max <= 0 ) {
            return true;
        }

        $key   = 'ahp_otp_ip_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= (int) $max ) {
            return false;
        }
        set_transient( $key, $count + 1, (int) $window );
        return true;
    }

    private static function client_ip(): ?string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip ? sanitize_text_field( $ip ) : null;
    }

    /* ── Shortcode and script ──────────────────────────────────────────────── */

    public static function enqueue_assets(): void {
        wp_enqueue_style( 'ahp-auth', AHP_PLUGIN_URL . 'assets/css/ahp-auth.css', [], AHP_VERSION );

        wp_enqueue_script(
            'ahp-auth',
            AHP_PLUGIN_URL . 'assets/js/ahp-auth.js',
            [],
            AHP_VERSION,
            true
        );
        wp_localize_script( 'ahp-auth', 'AHP_AUTH', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'cartUrl' => apply_filters( 'ahp_cart_page_url', home_url( '/proforma-cart/' ) ),
            'i18n'    => [
                'mobilePlaceholder'    => __( 'Mobile number (e.g. 0912xxxxxxx)', 'ahan-proforma' ),
                'firstNamePlaceholder' => __( 'First name', 'ahan-proforma' ),
                'lastNamePlaceholder'  => __( 'Last name', 'ahan-proforma' ),
                'codePlaceholder'      => __( '5-digit code', 'ahan-proforma' ),
                'sendCode'             => __( 'Continue', 'ahan-proforma' ),
                'verifyCode'           => __( 'Verify and log in', 'ahan-proforma' ),
                'resendCode'           => __( 'Resend code', 'ahan-proforma' ),
                'invalidMobile'        => __( 'The mobile number is not valid.', 'ahan-proforma' ),
                'genericError'         => __( 'Something went wrong; please try again.', 'ahan-proforma' ),
                'codeSentTo'           => __( 'A verification code was sent to the number below:', 'ahan-proforma' ),
                // Newer messages to distinguish login/sign-up and improve UX
                'loginTitle'           => __( 'Log in to your account', 'ahan-proforma' ),
                'registerTitle'        => __( 'Create a new account', 'ahan-proforma' ),
                'registerIntro'        => __( 'Your number isn\'t registered on the site yet; enter your name to create an account.', 'ahan-proforma' ),
                'loginSuccess'         => __( 'Logged in successfully.', 'ahan-proforma' ),
                'registerSuccess'      => __( 'Signed up successfully; welcome!', 'ahan-proforma' ),
            ],
        ] );
    }

    public static function render_login_shortcode(): string {
        if ( is_user_logged_in() ) {
            return '<p class="ahp-already-logged-in">' .
                esc_html__( 'You are already logged in to your account.', 'ahan-proforma' ) .
                '</p>';
        }

        // This page contains a nonce that expires every 12-24 hours; if a
        // page-cache plugin (e.g. LiteSpeed Cache) serves it statically from
        // cache, the stale nonce remains in the HTML and the first AJAX
        // request gets rejected with a 400 (invalid nonce) error. We
        // explicitly prevent caching here.
        self::prevent_page_caching();

        ob_start();
        include AHP_PLUGIN_DIR . 'templates/login.php';
        return (string) ob_get_clean();
    }

    /**
     * Login/sign-up or account icon shortcode — [ahp_login_button]
     * (the shortcode name is kept as ahp_login_button for backward
     * compatibility, even though it's no longer a text button but an icon)
     *
     * Unlike the earlier version, this shortcode no longer returns an empty
     * string for a logged-in user: both states (guest/logged-in) are
     * rendered in the HTML, and ahp-auth.js switches between them. Reason:
     * login happens in the popup via AJAX (no page reload), so if the
     * logged-in-state icon weren't in the DOM at all, the only way to show
     * it after a successful login would be a full page reload — exactly
     * what asp doesn't want.
     */
    public static function render_login_button_shortcode( $atts ): string {
        ob_start();
        include AHP_PLUGIN_DIR . 'templates/auth-icon.php';
        return (string) ob_get_clean();
    }

    /**
     * Injects the login/sign-up popup once (not once per shortcode) at the
     * bottom of the page, following the same pattern as cart-drawer.php.
     *
     * Important note about caching: this function no longer skips injecting
     * the modal based on is_user_logged_in(). Reason: on a page fully cached
     * by a full-page-cache plugin (where the entire HTML is served from disk
     * and PHP never runs again), if a guest visitor is the first one to
     * cache the page, the modal would be permanently missing from that
     * cached page's HTML -- even for later guests. So always injecting the
     * modal (which is completely inert and hidden until someone clicks) is
     * safer. Its trigger button (data-ahp-open-auth in
     * templates/auth-icon.php) is itself hidden for a logged-in user, so in
     * practice a logged-in user never sees a way to open this modal, even if
     * the markup itself is present on the page.
     */
    public static function render_auth_modal_in_footer(): void {
        include AHP_PLUGIN_DIR . 'templates/auth-modal.php';
    }

    /** Render the form markup (shared between the modal and the standalone page) */
    public static function render_auth_form(): void {
        include AHP_PLUGIN_DIR . 'templates/auth-form.php';
    }

    /**
     * Prevent the current page from being cached by popular cache plugins
     * (LiteSpeed, WP Rocket, W3TC, WP Super Cache, ...). Since this page
     * contains a one-time/short-lived nonce, caching it would cause an
     * authentication error.
     */
    private static function prevent_page_caching(): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) )   define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'LSCACHE_NO_CACHE' ) ) define( 'LSCACHE_NO_CACHE', true );

        if ( function_exists( 'do_action' ) ) {
            // LiteSpeed Cache's standard hook to disable caching for this request
            do_action( 'litespeed_control_set_nocache', 'ahp login form has a short-lived nonce' );
        }
    }
}
