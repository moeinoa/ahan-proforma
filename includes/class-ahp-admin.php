<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Admin
 *
 * Phase 5: the restricted proforma-invoice admin panel.
 *
 * Access: entirely controlled by the dedicated capability set up in Phase 0
 * (AHP_Roles::CAP_MANAGE_INVOICES = 'ahp_manage_invoices'); per asp's
 * explicit decision, a separate "view only" role/access level has not been
 * added. The ahan_invoice_manager role always has full access (view +
 * change status + settings); the site's main admin (manage_options) always
 * has access too.
 *
 * Menu: a new, independent top-level menu in the WordPress sidebar ("Ahan
 * Shahr Proforma") with two submenus:
 *   - Invoices : the full list + status filter + status change
 *   - Settings : colors/style, the Farapayamak SMS panel, invoice content, VAT rate
 *
 * All of this phase's settings are stored in wp_options (not
 * wp-config.php); the AHP_SMS_* constants in class-ahp-sms-gateway.php
 * remain only as an optional override for specific technical cases, not the
 * primary settings source.
 */
class AHP_Admin {

    private const MENU_SLUG          = 'ahp-invoices';
    private const SETTINGS_SLUG      = 'ahp-settings';
    private const SETTINGS_GROUP     = 'ahp_settings_group';
    private const NONCE_ACTION_STATUS = 'ahp_update_invoice_status';
    private const NONCE_ACTION_ITEM_UPDATE = 'ahp_update_invoice_item';
    private const NONCE_ACTION_ITEM_DELETE = 'ahp_delete_invoice_item';

    /** wp_options key names for this phase's settings */
    public const OPT_COLOR_PRIMARY       = 'ahp_color_primary';
    public const OPT_COLOR_PRIMARY_DARK  = 'ahp_color_primary_dark';
    public const OPT_COLOR_PRIMARY_LIGHT = 'ahp_color_primary_light';
    public const OPT_COLOR_ACCENT        = 'ahp_color_accent';
    public const OPT_COLOR_CONTACT       = 'ahp_color_contact';
    public const OPT_COLOR_CONTACT_DARK  = 'ahp_color_contact_dark';
    public const OPT_COLOR_CONTACT_LIGHT = 'ahp_color_contact_light';
    public const OPT_SMS_USERNAME        = 'ahp_sms_username';
    public const OPT_SMS_PASSWORD        = 'ahp_sms_password';
    public const OPT_SMS_FROM            = 'ahp_sms_from';
    // Admin mobile number(s) to receive a notification SMS when a new
    // proforma invoice is submitted. Deliberately kept separate from the
    // logged-in user's number; can be multiple numbers (comma-separated) —
    // Farapayamak itself accepts this format.
    public const OPT_ADMIN_NOTIFY_MOBILE = 'ahp_admin_notify_mobile';
    public const OPT_INVOICE_FOOTER      = 'ahp_invoice_footer_text';
    // Warning/notice line shown right under the print button (Phase 5
    // addendum), e.g. reminding the customer this proforma is not a final
    // invoice. Plain text — no HTML, unlike the footer text below.
    public const OPT_INVOICE_NOTICE      = 'ahp_invoice_notice_text';
    public const OPT_VAT_RATE            = 'ahp_vat_rate'; // decimal value, e.g. 0.10 for 10%

    /** Default values — exactly the values that were hardcoded in the code
     *  before Phase 5, so the site's current look and behavior don't change
     *  when this phase is installed/updated. */
    private const DEFAULT_COLOR_PRIMARY       = '#0f9d58';
    private const DEFAULT_COLOR_PRIMARY_DARK  = '#0b7a44';
    private const DEFAULT_COLOR_PRIMARY_LIGHT = '#e6f7ee';
    private const DEFAULT_COLOR_ACCENT        = '#d0342c';
    // The "call us" button color — before Phase 5 this was hardcoded in
    // ahp-table.css (background/text/hover); the same values are kept here as defaults.
    private const DEFAULT_COLOR_CONTACT       = '#c9862c'; // hover/highlight color
    private const DEFAULT_COLOR_CONTACT_DARK  = '#b3721f'; // normal-state text color
    private const DEFAULT_COLOR_CONTACT_LIGHT = '#fdf1e2'; // normal-state background
    private const DEFAULT_VAT_RATE            = 0.10;

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_post_ahp_update_invoice_status', [ __CLASS__, 'handle_update_invoice_status' ] );
        add_action( 'admin_post_ahp_update_invoice_item', [ __CLASS__, 'handle_update_invoice_item' ] );
        add_action( 'admin_post_ahp_delete_invoice_item', [ __CLASS__, 'handle_delete_invoice_item' ] );

        // Injecting custom colors: both on the site front end (for the
        // proforma column/cart/login-form buttons) and on the standalone
        // proforma-invoice HTML output page.
        add_action( 'wp_head', [ __CLASS__, 'print_color_overrides' ] );
        add_action( 'ahp_invoice_output_head', [ __CLASS__, 'print_color_overrides' ] );
    }

    /* ── Menu ──────────────────────────────────────────────────────────────── */

    /**
     * The capability passed to add_menu_page/add_submenu_page themselves.
     * Independent of the logic inside register_menu(), WordPress re-checks
     * this capability against the current user to decide whether to show
     * the menu. Since the dedicated 'ahp_manage_invoices' capability is only
     * given to the ahan_invoice_manager role (not to WordPress's
     * Administrator role), if we passed that same string directly to
     * add_menu_page, the site's main admin — despite passing
     * current_user_can_manage() — still wouldn't see the menu. So 'read'
     * (the lowest level, always true for any logged-in user with wp-admin
     * access) is used here, and precise access control happens in
     * guard()/register_menu() via current_user_can_manage() (which accepts
     * both ahp_manage_invoices and manage_options).
     */
    private const MENU_CAPABILITY = 'read';

    public static function register_menu(): void {
        if ( ! AHP_Roles::current_user_can_manage() ) {
            return;
        }

        add_menu_page(
            __( 'Ahan Shahr Proforma', 'ahan-proforma' ),
            __( 'Ahan Shahr Proforma', 'ahan-proforma' ),
            self::MENU_CAPABILITY,
            self::MENU_SLUG,
            [ __CLASS__, 'render_invoices_page' ],
            'dashicons-media-spreadsheet',
            26
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Proforma Invoices', 'ahan-proforma' ),
            __( 'Invoices', 'ahan-proforma' ),
            self::MENU_CAPABILITY,
            self::MENU_SLUG,
            [ __CLASS__, 'render_invoices_page' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            __( 'Proforma Settings', 'ahan-proforma' ),
            __( 'Settings', 'ahan-proforma' ),
            self::MENU_CAPABILITY,
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    private static function guard(): void {
        if ( ! AHP_Roles::current_user_can_manage() ) {
            wp_die(
                esc_html__( 'You are not allowed to access this page.', 'ahan-proforma' ),
                esc_html__( 'Access not allowed', 'ahan-proforma' ),
                [ 'response' => 403 ]
            );
        }
    }

    /* ── Invoice list page ─────────────────────────────────────────────────── */

    public static function render_invoices_page(): void {
        self::guard();

        $status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        $paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $per_page      = 20;

        $result = AHP_DB::get_all_invoices( [
            'status'   => $status_filter,
            'per_page' => $per_page,
            'paged'    => $paged,
        ] );

        $items       = $result['items'];
        $total       = $result['total'];
        $total_pages = (int) ceil( $total / $per_page );

        // Each proforma invoice on the current page also has its line items
        // fetched, to display in an editable accordion (Phase 5 addendum).
        // Since each page has at most 20 proforma invoices, this N+1 query
        // causes no practical issue.
        $items_by_invoice = [];
        foreach ( $items as $invoice ) {
            $items_by_invoice[ $invoice['id'] ] = AHP_DB::get_items_for_invoice( (int) $invoice['id'] );
        }

        $statuses = [
            ''         => __( 'All', 'ahan-proforma' ),
            'pending'  => __( 'Pending review', 'ahan-proforma' ),
            'approved' => __( 'Approved', 'ahan-proforma' ),
            'rejected' => __( 'Rejected', 'ahan-proforma' ),
            'expired'  => __( 'Expired', 'ahan-proforma' ),
        ];

        include AHP_PLUGIN_DIR . 'templates/admin-invoices-list.php'; // variables: $items, $items_by_invoice, $total, $total_pages, $paged, $per_page, $status_filter, $statuses
    }

    /** Handle the status-change form (submitted to admin-post.php) */
    public static function handle_update_invoice_status(): void {
        self::guard();

        check_admin_referer( self::NONCE_ACTION_STATUS );

        $invoice_id = isset( $_POST['invoice_id'] ) ? (int) $_POST['invoice_id'] : 0;
        $new_status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';
        $admin_note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

        $allowed_statuses = [ 'pending', 'approved', 'rejected', 'expired' ];

        $redirect_args = [ 'page' => self::MENU_SLUG ];
        if ( ! empty( $_POST['status_filter'] ) ) {
            $redirect_args['status'] = sanitize_key( wp_unslash( $_POST['status_filter'] ) );
        }
        if ( ! empty( $_POST['paged'] ) ) {
            $redirect_args['paged'] = (int) $_POST['paged'];
        }

        if ( $invoice_id && in_array( $new_status, $allowed_statuses, true ) ) {
            AHP_DB::update_invoice_status( $invoice_id, $new_status, get_current_user_id(), $admin_note );
            $redirect_args['ahp_notice'] = 'status_updated';
        } else {
            $redirect_args['ahp_notice'] = 'status_update_failed';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Handle the form for editing a proforma-invoice line item (Phase 5
     * addendum). Per asp's explicit decision, this operation is always
     * allowed regardless of the invoice's status (even approved/rejected).
     * After saving, the document's subtotal/total is automatically recomputed.
     */
    public static function handle_update_invoice_item(): void {
        self::guard();

        check_admin_referer( self::NONCE_ACTION_ITEM_UPDATE );

        $item_id = isset( $_POST['item_id'] ) ? (int) $_POST['item_id'] : 0;
        $item    = $item_id ? AHP_DB::get_item( $item_id ) : null;

        $redirect_args = [ 'page' => self::MENU_SLUG ];
        if ( ! empty( $_POST['status_filter'] ) ) {
            $redirect_args['status'] = sanitize_key( wp_unslash( $_POST['status_filter'] ) );
        }
        if ( ! empty( $_POST['paged'] ) ) {
            $redirect_args['paged'] = (int) $_POST['paged'];
        }

        if ( ! $item ) {
            $redirect_args['ahp_notice'] = 'item_update_failed';
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
            exit;
        }

        $description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
        $unit        = isset( $_POST['unit'] ) ? sanitize_key( wp_unslash( $_POST['unit'] ) ) : '';
        $quantity    = isset( $_POST['quantity'] ) ? (float) $_POST['quantity'] : 0;
        $unit_price  = isset( $_POST['unit_price'] ) ? (int) $_POST['unit_price'] : 0;

        $allowed_units = [ 'kg', 'shakhe' ];

        if ( $description === '' || ! in_array( $unit, $allowed_units, true ) || $quantity <= 0 || $unit_price < 0 ) {
            $redirect_args['ahp_notice'] = 'item_update_failed';
        } else {
            AHP_DB::update_item( $item_id, [
                'description' => $description,
                'unit'        => $unit,
                'quantity'    => $quantity,
                'unit_price'  => $unit_price,
            ] );
            AHP_DB::recalculate_invoice_totals( (int) $item['invoice_id'] );
            $redirect_args['ahp_notice'] = 'item_updated';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Handle an admin deleting a proforma-invoice line item (Phase 5 addendum) */
    public static function handle_delete_invoice_item(): void {
        self::guard();

        check_admin_referer( self::NONCE_ACTION_ITEM_DELETE );

        $item_id = isset( $_POST['item_id'] ) ? (int) $_POST['item_id'] : 0;
        $item    = $item_id ? AHP_DB::get_item( $item_id ) : null;

        $redirect_args = [ 'page' => self::MENU_SLUG ];
        if ( ! empty( $_POST['status_filter'] ) ) {
            $redirect_args['status'] = sanitize_key( wp_unslash( $_POST['status_filter'] ) );
        }
        if ( ! empty( $_POST['paged'] ) ) {
            $redirect_args['paged'] = (int) $_POST['paged'];
        }

        if ( $item ) {
            $invoice_id = (int) $item['invoice_id'];
            AHP_DB::delete_item( $item_id );
            AHP_DB::recalculate_invoice_totals( $invoice_id );
            $redirect_args['ahp_notice'] = 'item_deleted';
        } else {
            $redirect_args['ahp_notice'] = 'item_update_failed';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ── Settings page ─────────────────────────────────────────────────────── */

    public static function register_settings(): void {
        $string_opts = [
            self::OPT_COLOR_PRIMARY,
            self::OPT_COLOR_PRIMARY_DARK,
            self::OPT_COLOR_PRIMARY_LIGHT,
            self::OPT_COLOR_ACCENT,
            self::OPT_COLOR_CONTACT,
            self::OPT_COLOR_CONTACT_DARK,
            self::OPT_COLOR_CONTACT_LIGHT,
            self::OPT_SMS_USERNAME,
            self::OPT_SMS_FROM,
            self::OPT_ADMIN_NOTIFY_MOBILE,
        ];
        foreach ( $string_opts as $opt ) {
            register_setting( self::SETTINGS_GROUP, $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
        }

        // Farapayamak password: no extra trim/strip that might remove a
        // special character, but still cleaned of any tag/script.
        register_setting( self::SETTINGS_GROUP, self::OPT_SMS_PASSWORD, [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // The footer text is now filled in via WordPress's full editor
        // (wp_editor); the output can include HTML (paragraphs/tables/
        // images/etc.). wp_kses_post applies WordPress's same author-content
        // security rules (like saving post content) — scripts/iframes are
        // stripped but the editor's safe tags (p, table, img, strong, ...) are kept.
        register_setting( self::SETTINGS_GROUP, self::OPT_INVOICE_FOOTER, [ 'sanitize_callback' => 'wp_kses_post' ] );

        // Notice text: a plain textarea, no HTML allowed (unlike the footer
        // field above), since it's just a short warning line next to an icon.
        register_setting( self::SETTINGS_GROUP, self::OPT_INVOICE_NOTICE, [ 'sanitize_callback' => 'sanitize_textarea_field' ] );

        register_setting( self::SETTINGS_GROUP, self::OPT_VAT_RATE, [
            'sanitize_callback' => [ __CLASS__, 'sanitize_vat_rate' ],
        ] );
    }

    /** Admin input is taken as a percentage (e.g. 10) and stored as a decimal value (0.10) */
    public static function sanitize_vat_rate( $value ): float {
        $percent = (float) $value;
        if ( $percent < 0 ) {
            $percent = 0;
        }
        if ( $percent > 100 ) {
            $percent = 100;
        }
        return round( $percent / 100, 4 );
    }

    public static function render_settings_page(): void {
        self::guard();

        $colors = [
            'primary'       => get_option( self::OPT_COLOR_PRIMARY, self::DEFAULT_COLOR_PRIMARY ),
            'primary_dark'  => get_option( self::OPT_COLOR_PRIMARY_DARK, self::DEFAULT_COLOR_PRIMARY_DARK ),
            'primary_light' => get_option( self::OPT_COLOR_PRIMARY_LIGHT, self::DEFAULT_COLOR_PRIMARY_LIGHT ),
            'accent'        => get_option( self::OPT_COLOR_ACCENT, self::DEFAULT_COLOR_ACCENT ),
            'contact'       => get_option( self::OPT_COLOR_CONTACT, self::DEFAULT_COLOR_CONTACT ),
            'contact_dark'  => get_option( self::OPT_COLOR_CONTACT_DARK, self::DEFAULT_COLOR_CONTACT_DARK ),
            'contact_light' => get_option( self::OPT_COLOR_CONTACT_LIGHT, self::DEFAULT_COLOR_CONTACT_LIGHT ),
        ];

        $sms = [
            'username' => get_option( self::OPT_SMS_USERNAME, '' ),
            'password' => get_option( self::OPT_SMS_PASSWORD, '' ),
            'from'     => get_option( self::OPT_SMS_FROM, '' ),
        ];

        $admin_notify_mobile = get_option( self::OPT_ADMIN_NOTIFY_MOBILE, '' );

        $sms_overridden_by_constant = defined( 'AHP_SMS_USERNAME' ) || defined( 'AHP_SMS_PASSWORD' ) || defined( 'AHP_SMS_FROM' );

        $footer_text = get_option( self::OPT_INVOICE_FOOTER, self::default_footer_text() );
        $notice_text = get_option( self::OPT_INVOICE_NOTICE, self::default_notice_text() );
        $vat_percent = round( self::get_vat_rate() * 100, 2 );

        include AHP_PLUGIN_DIR . 'templates/admin-settings.php'; // + $admin_notify_mobile, $notice_text
    }

    /* ── Values consumed by the rest of the plugin ─────────────────────────── */

    /** VAT rate as a decimal (e.g. 0.10); used by class-ahp-invoice.php */
    public static function get_vat_rate(): float {
        $value = get_option( self::OPT_VAT_RATE, self::DEFAULT_VAT_RATE );
        return is_numeric( $value ) ? (float) $value : self::DEFAULT_VAT_RATE;
    }

    /** Display percentage of the VAT rate, for use in labels (e.g. "10%") */
    public static function get_vat_rate_percent_label(): string {
        $percent = round( self::get_vat_rate() * 100, 2 );
        // Show without decimals if it's a whole number (10% not 10.0%)
        $formatted = ( $percent == (int) $percent ) ? (string) (int) $percent : rtrim( rtrim( number_format( $percent, 2 ), '0' ), '.' );
        return AHP_Helpers::to_persian_digits( $formatted );
    }

    /** Proforma invoice footer text; used by templates/invoice-output.php */
    public static function get_invoice_footer_text(): string {
        return get_option( self::OPT_INVOICE_FOOTER, self::default_footer_text() );
    }

    private static function default_footer_text(): string {
        return __( 'This is a proforma document and is not an official sales invoice until finally approved.', 'ahan-proforma' );
    }

    /** Warning/notice text shown under the print button; used by templates/invoice-output.php */
    public static function get_invoice_notice_text(): string {
        return get_option( self::OPT_INVOICE_NOTICE, self::default_notice_text() );
    }

    private static function default_notice_text(): string {
        return __( 'Please review all information carefully before printing or saving as PDF.', 'ahan-proforma' );
    }

    /**
     * Admin mobile number(s) for the notification SMS; can be
     * comma-separated. Used by AHP_Invoice::issue_from_cart(). If empty, it
     * means this notification isn't configured yet (a silent no-op).
     *
     * @return string[] list of trimmed, non-empty numbers
     */
    public static function get_admin_notify_mobiles(): array {
        $raw = (string) get_option( self::OPT_ADMIN_NOTIFY_MOBILE, '' );
        if ( $raw === '' ) {
            return [];
        }
        $parts = array_map( 'trim', explode( ',', $raw ) );
        return array_values( array_filter( $parts ) );
    }

    /**
     * Prints all CSS color variables as an inline <style>; unlike the
     * earlier Phase 5 version (which only printed an override), this method
     * always prints all 7 color variables — whether the admin has set a
     * custom value or not (in which case the default value is used). This
     * way the settings panel is the single, permanent source of every color
     * used by the plugin site-wide (price table, cart, login form, account
     * page, proforma-invoice HTML output), and no static hex value remains
     * in any CSS file.
     */
    public static function print_color_overrides(): void {
        $pairs = [
            '--ahp-primary'       => get_option( self::OPT_COLOR_PRIMARY, self::DEFAULT_COLOR_PRIMARY ),
            '--ahp-primary-dark'  => get_option( self::OPT_COLOR_PRIMARY_DARK, self::DEFAULT_COLOR_PRIMARY_DARK ),
            '--ahp-primary-light' => get_option( self::OPT_COLOR_PRIMARY_LIGHT, self::DEFAULT_COLOR_PRIMARY_LIGHT ),
            '--ahp-accent'        => get_option( self::OPT_COLOR_ACCENT, self::DEFAULT_COLOR_ACCENT ),
            '--ahp-contact'       => get_option( self::OPT_COLOR_CONTACT, self::DEFAULT_COLOR_CONTACT ),
            '--ahp-contact-dark'  => get_option( self::OPT_COLOR_CONTACT_DARK, self::DEFAULT_COLOR_CONTACT_DARK ),
            '--ahp-contact-light' => get_option( self::OPT_COLOR_CONTACT_LIGHT, self::DEFAULT_COLOR_CONTACT_LIGHT ),
        ];

        $declarations = '';
        foreach ( $pairs as $var => $value ) {
            // Only a valid hex color is accepted, to prevent injecting an
            // arbitrary value into the CSS; if the stored value were invalid
            // (e.g. direct database tampering), that one variable is skipped
            // and the CSS file itself has no fallback for it — so this
            // should preferably never happen; since this value passes
            // through register_setting with sanitize_text_field, in
            // practice it will almost always be valid or empty.
            if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ) {
                continue;
            }
            $declarations .= esc_html( $var ) . ':' . esc_html( $value ) . ';';
        }

        if ( $declarations === '' ) {
            return;
        }

        echo "<style id=\"ahp-color-overrides\">:root{{$declarations}}</style>\n";
    }
}