<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Cart
 *
 * Phase 2: the proforma cart. No dependency on WooCommerce/the core cart;
 * storage is entirely our own.
 *
 * Storage:
 *  - Logged-in user : usermeta under the key 'ahp_cart' (a serialized array, single source of truth)
 *  - Guest           : a row in wp_options under the key 'ahp_cart_guest_{token}'
 *                       (the token is read from our own httponly cookie, not
 *                       a standard PHP session, so it doesn't conflict with
 *                       the site's page cache)
 *
 * Each cart item is a full snapshot array (the same thing put into the
 * button's data-ahp-* attributes in Phase 1-b) plus a quantity and a unique
 * item_key for referencing it when removing/updating quantity.
 */
class AHP_Cart {

    private const COOKIE_NAME   = 'ahp_cart_token';
    private const COOKIE_TTL    = 30 * DAY_IN_SECONDS;
    private const OPTION_PREFIX = 'ahp_cart_guest_';
    private const USERMETA_KEY  = 'ahp_cart';
    private const NONCE_ACTION  = 'ahp_cart_action';

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'ensure_guest_token_cookie' ] );
        add_action( 'wp_login', [ __CLASS__, 'merge_guest_cart_into_user' ], 10, 2 );

        add_action( 'wp_ajax_ahp_cart_add',              [ __CLASS__, 'ajax_add' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_add',       [ __CLASS__, 'ajax_add' ] );
        add_action( 'wp_ajax_ahp_cart_remove',           [ __CLASS__, 'ajax_remove' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_remove',    [ __CLASS__, 'ajax_remove' ] );
        add_action( 'wp_ajax_ahp_cart_update_qty',       [ __CLASS__, 'ajax_update_qty' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_update_qty', [ __CLASS__, 'ajax_update_qty' ] );
        add_action( 'wp_ajax_ahp_cart_get',              [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_get',       [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_ahp_cart_issue_invoice',        [ __CLASS__, 'ajax_issue_invoice' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_issue_invoice', [ __CLASS__, 'ajax_issue_invoice' ] );
        // A nopriv version is also registered so a guest gets the clear "log
        // in first" message from ajax_issue_invoice itself; if it weren't
        // registered, WordPress would return a raw "0" output before ever
        // reaching our logic, which breaks in ahp-cart.js as invalid JSON
        // and shows the user a vague/unrelated message. The method itself
        // still checks and rejects the login; a guest can never actually issue an invoice.

        // The cart nonce (AHP_CART.nonce) is generated at page-load time,
        // based on the user's uid at that moment. If the user was a guest
        // (uid=0) and logs in/signs up right there via the popup, the old
        // nonce is no longer valid for their new uid, even with no page
        // reload at all — and the subsequent automatic attempt to issue the
        // proforma invoice (pendingIssueAfterLogin in ahp-cart.js) gets
        // rejected with a 403. This action, exactly like ahp_refresh_nonce
        // in AHP_Auth, returns a fresh nonce (based on the currently
        // logged-in user) so the JS can swap it in before retrying. This
        // action itself doesn't need a valid nonce (otherwise there'd be no
        // way to fix an invalid-nonce problem in the first place).
        add_action( 'wp_ajax_ahp_cart_refresh_nonce',        [ __CLASS__, 'ajax_refresh_nonce' ] );
        add_action( 'wp_ajax_nopriv_ahp_cart_refresh_nonce', [ __CLASS__, 'ajax_refresh_nonce' ] );

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        add_shortcode( 'ahp_cart',       [ __CLASS__, 'render_cart_shortcode' ] );
        add_shortcode( 'ahp_cart_count', [ __CLASS__, 'render_count_shortcode' ] );
        add_shortcode( 'ahp_cart_icon',  [ __CLASS__, 'render_cart_icon_shortcode' ] );

        // The drawer is injected into the footer of every page so the
        // [ahp_cart_icon] shortcode, wherever it sits in the theme (e.g. the
        // header), can open the shared drawer.
        add_action( 'wp_footer', [ __CLASS__, 'render_cart_drawer_markup' ] );
    }

    /* ── Guest cart identifier cookie ──────────────────────────────────────── */

    public static function ensure_guest_token_cookie(): void {
        if ( is_user_logged_in() ) return; // a logged-in user doesn't need a token
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) && self::is_valid_token( $_COOKIE[ self::COOKIE_NAME ] ) ) return;
        if ( headers_sent() ) return;

        $token = wp_generate_password( 32, false, false );
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[ self::COOKIE_NAME ] = $token; // usable within this same request too
    }

    private static function is_valid_token( string $token ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9]{32}$/', $token );
    }

    private static function current_guest_token(): ?string {
        $token = $_COOKIE[ self::COOKIE_NAME ] ?? '';
        return self::is_valid_token( $token ) ? $token : null;
    }

    /* ── Reading/writing the cart ──────────────────────────────────────────── */

    /**
     * Returns the current cart: a logged-in user's from usermeta, a guest's from wp_options.
     * @return array<int, array> list of items in the order they were added
     */
    public static function get_items(): array {
        if ( is_user_logged_in() ) {
            $items = get_user_meta( get_current_user_id(), self::USERMETA_KEY, true );
            return is_array( $items ) ? $items : [];
        }

        $token = self::current_guest_token();
        if ( ! $token ) return [];

        $items = get_option( self::OPTION_PREFIX . $token, [] );
        return is_array( $items ) ? $items : [];
    }

    private static function save_items( array $items ): void {
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), self::USERMETA_KEY, array_values( $items ) );
            return;
        }

        $token = self::current_guest_token();
        if ( ! $token ) return; // saving for a guest is impossible without a valid token

        update_option( self::OPTION_PREFIX . $token, array_values( $items ), false );
    }

    /**
     * Add a row to the cart. If an item with the same row_hash (the exact
     * same product, from the same table) is already in the cart, the
     * quantity is increased instead of a duplicate row.
     */
    public static function add_item( array $item ): array {
        $item = self::sanitize_item( $item );
        if ( $item === null ) {
            return [ 'ok' => false, 'error' => 'invalid_item' ];
        }

        $items      = self::get_items();
        $dedupe_key = $item['table_id'] . '|' . $item['row_hash'];
        $found      = false;

        foreach ( $items as &$existing ) {
            if ( $existing['table_id'] . '|' . $existing['row_hash'] === $dedupe_key ) {
                $existing['quantity'] = (int) ( $existing['quantity'] + $item['quantity'] );
                $found = true;
                break;
            }
        }
        unset( $existing );

        if ( ! $found ) {
            $item['item_key'] = wp_generate_password( 12, false, false );
            $items[]          = $item;
        }

        self::save_items( $items );
        return [ 'ok' => true, 'items' => $items, 'count' => self::count_items( $items ) ];
    }

    public static function remove_item( string $item_key ): array {
        $items = self::get_items();
        $items = array_values( array_filter( $items, fn( $i ) => $i['item_key'] !== $item_key ) );
        self::save_items( $items );
        return [ 'ok' => true, 'items' => $items, 'count' => self::count_items( $items ) ];
    }

    public static function update_quantity( string $item_key, float $quantity ): array {
        $quantity = (int) round( $quantity );
        if ( $quantity <= 0 ) {
            return self::remove_item( $item_key );
        }

        $items = self::get_items();
        $found = false;
        foreach ( $items as &$item ) {
            if ( $item['item_key'] === $item_key ) {
                $item['quantity'] = $quantity;
                $found = true;
                break;
            }
        }
        unset( $item );

        if ( ! $found ) {
            return [ 'ok' => false, 'error' => 'item_not_found' ];
        }

        self::save_items( $items );
        return [ 'ok' => true, 'items' => $items, 'count' => self::count_items( $items ) ];
    }

    public static function clear(): void {
        self::save_items( [] );
    }

    /**
     * Atomically takes the logged-in user's cart for issuing a proforma
     * invoice. The cart's usermeta row is deleted with a single query whose
     * affected-row count decides the winner, so two simultaneous requests
     * (double-click, two tabs) can never both issue an invoice from the same
     * cart. Returns null if the cart is empty or another request took it first.
     * On a later failure the caller must give the items back via restore_items().
     */
    public static function take_items_for_issue(): ?array {
        if ( ! is_user_logged_in() ) return null;

        global $wpdb;
        $user_id = get_current_user_id();
        $items   = self::get_items();
        if ( empty( $items ) ) return null;

        $deleted = $wpdb->delete(
            $wpdb->usermeta,
            [ 'user_id' => $user_id, 'meta_key' => self::USERMETA_KEY ],
            [ '%d', '%s' ]
        );
        wp_cache_delete( $user_id, 'user_meta' );

        return $deleted ? $items : null;
    }

    /** Gives back items taken by take_items_for_issue() when issuing failed */
    public static function restore_items( array $items ): void {
        self::save_items( $items );
    }

    public static function count_items( ?array $items = null ): int {
        $items = $items ?? self::get_items();
        return count( $items );
    }

    public static function get_total(): float {
        $total = 0.0;
        foreach ( self::get_items() as $item ) {
            $total += $item['unit_price'] * $item['quantity'];
        }
        return $total;
    }

    /* ── Merging the guest cart into the user's cart on login ─────────────── */

    public static function merge_guest_cart_into_user( string $user_login, WP_User $user ): void {
        $token = self::current_guest_token();
        if ( ! $token ) return;

        $guest_items = get_option( self::OPTION_PREFIX . $token, [] );
        if ( ! is_array( $guest_items ) || empty( $guest_items ) ) return;

        $user_items = get_user_meta( $user->ID, self::USERMETA_KEY, true );
        $user_items = is_array( $user_items ) ? $user_items : [];

        foreach ( $guest_items as $g_item ) {
            $dedupe_key = $g_item['table_id'] . '|' . $g_item['row_hash'];
            $found      = false;
            foreach ( $user_items as &$u_item ) {
                if ( $u_item['table_id'] . '|' . $u_item['row_hash'] === $dedupe_key ) {
                    $u_item['quantity'] += $g_item['quantity'];
                    $found = true;
                    break;
                }
            }
            unset( $u_item );
            if ( ! $found ) {
                $user_items[] = $g_item;
            }
        }

        update_user_meta( $user->ID, self::USERMETA_KEY, array_values( $user_items ) );
        delete_option( self::OPTION_PREFIX . $token );
    }

    /* ── Cart item input validation ────────────────────────────────────────── */

    /**
     * @return array{table_id:int, row_hash:string, description:string, code:string,
     *               unit_price:float, unit:string, quantity:float}|null
     */
    private static function sanitize_item( array $raw ): ?array {
        $table_id    = isset( $raw['table_id'] ) ? (int) $raw['table_id'] : 0;
        $row_hash    = isset( $raw['row_hash'] ) ? sanitize_text_field( $raw['row_hash'] ) : '';
        $description = isset( $raw['description'] ) ? sanitize_text_field( $raw['description'] ) : '';
        $code        = isset( $raw['code'] ) ? sanitize_text_field( $raw['code'] ) : '';
        $unit_price  = isset( $raw['unit_price'] ) ? (float) $raw['unit_price'] : 0.0;
        $unit        = isset( $raw['unit'] ) ? sanitize_text_field( $raw['unit'] ) : '';
        $quantity    = isset( $raw['quantity'] ) ? (int) round( (float) $raw['quantity'] ) : 0;

        if ( $description === '' )        return null;
        if ( $unit_price <= 0.0 )         return null; // an item with no valid price should never enter the cart
        if ( ! in_array( $unit, AHP_Helpers::allowed_units(), true ) ) return null; // only these units are allowed anywhere in the system
        if ( $quantity <= 0 )             $quantity = 1;

        return [
            'table_id'    => $table_id,
            'row_hash'    => $row_hash,
            'description' => $description,
            'code'        => $code,
            'unit_price'  => $unit_price,
            'unit'        => $unit,
            'quantity'    => $quantity,
        ];
    }

    /* ── AJAX handlers ─────────────────────────────────────────────────────── */

    private static function verify_nonce_or_die(): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    public static function ajax_add(): void {
        self::verify_nonce_or_die();

        // WordPress adds slashes to $_POST; without wp_unslash a description such
        // as 6" or 1/2' was stored with backslashes ( 6\" ).
        $post   = wp_unslash( $_POST );
        $result = self::add_item( [
            'table_id'    => $post['table_id']    ?? 0,
            'row_hash'    => $post['row_hash']    ?? '',
            'description' => $post['description'] ?? '',
            'code'        => $post['code']         ?? '',
            'unit_price'  => $post['unit_price']   ?? 0,
            'unit'        => $post['unit']         ?? '',
            'quantity'    => $post['quantity']     ?? 1,
        ] );

        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => __( 'The row data is invalid.', 'ahan-proforma' ) ], 400 );
        }
        wp_send_json_success( [ 'count' => $result['count'], 'items' => self::format_items_for_response( $result['items'] ) ] );
    }

    public static function ajax_remove(): void {
        self::verify_nonce_or_die();
        $item_key = sanitize_text_field( $_POST['item_key'] ?? '' );
        if ( $item_key === '' ) {
            wp_send_json_error( [ 'message' => __( 'The row identifier is invalid.', 'ahan-proforma' ) ], 400 );
        }
        $result = self::remove_item( $item_key );
        wp_send_json_success( [ 'count' => $result['count'], 'items' => self::format_items_for_response( $result['items'] ) ] );
    }

    public static function ajax_update_qty(): void {
        self::verify_nonce_or_die();
        $item_key = sanitize_text_field( $_POST['item_key'] ?? '' );
        $quantity = (float) ( $_POST['quantity'] ?? 0 );
        if ( $item_key === '' ) {
            wp_send_json_error( [ 'message' => __( 'The row identifier is invalid.', 'ahan-proforma' ) ], 400 );
        }
        $result = self::update_quantity( $item_key, $quantity );
        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => __( 'The requested row was not found.', 'ahan-proforma' ) ], 404 );
        }
        wp_send_json_success( [ 'count' => $result['count'], 'items' => self::format_items_for_response( $result['items'] ) ] );
    }

    public static function ajax_get(): void {
        self::verify_nonce_or_die();
        $items = self::get_items();
        wp_send_json_success( [ 'count' => self::count_items( $items ), 'items' => self::format_items_for_response( $items ) ] );
    }

    /**
     * Issue a proforma invoice from the current cart — the bridge between
     * the UI button and AHP_Invoice::issue_from_cart (Phase 4). This class
     * deliberately doesn't implement the issuance logic itself; it only
     * registers the AJAX action and delegates to AHP_Invoice, keeping
     * responsibilities separate.
     */
    public static function ajax_issue_invoice(): void {
        self::verify_nonce_or_die();

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [
                'message'    => __( 'Please log in to your account first to issue a proforma invoice.', 'ahan-proforma' ),
                'needsLogin' => true,
            ], 401 );
        }

        $result = AHP_Invoice::issue_from_cart();
        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => $result['message'] ], 400 );
        }

        wp_send_json_success( [
            'message'    => __( 'Proforma invoice issued successfully.', 'ahan-proforma' ),
            'url'        => $result['url'],
            // So that after automatic issuance (when the user just logged in
            // via the popup and their focus isn't necessarily on the cart),
            // they can immediately link to their issued proforma invoices,
            // instead of having to go find the account page themselves.
            'accountUrl' => apply_filters( 'ahp_account_page_url', home_url( '/account/' ) ),
        ] );
    }

    /** Fresh cart nonce; fixes the stale (guest-uid) nonce problem after login without a page reload */
    public static function ajax_refresh_nonce(): void {
        wp_send_json_success( [ 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ] );
    }

    private static function format_items_for_response( array $items ): array {
        $out = [];
        foreach ( $items as $item ) {
            $out[] = [
                'item_key'         => $item['item_key'],
                'description'      => $item['description'],
                'code'             => $item['code'],
                'unit'             => AHP_Helpers::unit_label( $item['unit'] ),
                'unit_price'       => $item['unit_price'],
                'unit_price_fa'    => AHP_Helpers::format_toman( $item['unit_price'] ),
                'quantity'         => $item['quantity'],
                'line_total'       => $item['unit_price'] * $item['quantity'],
                'line_total_fa'    => AHP_Helpers::format_toman( $item['unit_price'] * $item['quantity'] ),
            ];
        }
        return $out;
    }

    /* ── Shortcodes and script ─────────────────────────────────────────────── */

    public static function enqueue_assets(): void {
        wp_enqueue_style( 'ahp-cart', AHP_PLUGIN_URL . 'assets/css/ahp-cart.css', [], AHP_VERSION );

        wp_enqueue_script(
            'ahp-cart',
            AHP_PLUGIN_URL . 'assets/js/ahp-cart.js',
            [],
            AHP_VERSION,
            true
        );
        wp_localize_script( 'ahp-cart', 'AHP_CART', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'i18n'    => [
                'added'        => __( 'Added to proforma invoice', 'ahan-proforma' ),
                'error'        => __( 'Error; please try again', 'ahan-proforma' ),
                'emptyCart'    => __( 'Your proforma cart is empty.', 'ahan-proforma' ),
                'confirmRemove'=> __( 'Remove this row?', 'ahan-proforma' ),
                'closeCart'    => __( 'Close', 'ahan-proforma' ),
                'cartTitle'    => __( 'Proforma cart', 'ahan-proforma' ),
                'addToProforma'=> __( 'Add to invoice', 'ahan-proforma' ),
                'issuing'      => __( 'Issuing proforma invoice…', 'ahan-proforma' ),
                'viewMyInvoices' => __( 'View my proforma invoices', 'ahan-proforma' ),
                'increaseQty'  => __( 'Increase quantity', 'ahan-proforma' ),
                'decreaseQty'  => __( 'Decrease quantity', 'ahan-proforma' ),
                'colDescription' => __( 'Description', 'ahan-proforma' ),
                'colUnit'      => __( 'Unit', 'ahan-proforma' ),
                'colQty'       => __( 'Qty', 'ahan-proforma' ),
                'colUnitPrice' => __( 'Unit price', 'ahan-proforma' ),
                'colLineTotal' => __( 'Total', 'ahan-proforma' ),
            ],
        ] );
    }

    public static function render_count_shortcode(): string {
        return '<span class="ahp-cart-count" data-ahp-cart-count>' . (int) self::count_items() . '</span>';
    }

    public static function render_cart_shortcode(): string {
        ob_start();
        include AHP_PLUGIN_DIR . 'templates/cart.php';
        return (string) ob_get_clean();
    }

    /**
     * [ahp_cart_icon] shortcode — a floating cart icon the user places
     * anywhere in the theme (usually the header). Clicking opens the shared
     * drawer injected into wp_footer. The output is just the button; no
     * fixed/absolute positioning is applied to it, so it fits correctly
     * wherever it's placed in the theme.
     */
    public static function render_cart_icon_shortcode(): string {
        ob_start();
        include AHP_PLUGIN_DIR . 'templates/cart-icon.php';
        return (string) ob_get_clean();
    }

    /**
     * The cart drawer marker, printed once at the end of every page
     * (wp_footer). Its content is populated via AJAX exactly like [ahp_cart].
     */
    public static function render_cart_drawer_markup(): void {
        include AHP_PLUGIN_DIR . 'templates/cart-drawer.php';
    }
}