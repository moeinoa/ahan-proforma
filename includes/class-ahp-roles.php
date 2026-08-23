<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Roles
 *
 * Creates two dedicated WordPress roles:
 *
 *  1) ahan_customer       site customer (replacement/equivalent of the
 *     WooCommerce customer role, but standalone)
 *     - none of WordPress's admin capabilities
 *     - only our own dedicated capability (ahp_view_own_invoices), used in
 *       Phase 6 to restrict the "My invoices" page
 *
 *  2) ahan_invoice_manager   restricted proforma-invoice admin
 *     - has the base 'read' capability (to see the dashboard)
 *     - has the dedicated ahp_manage_invoices capability, used only in
 *       Phase 5 to register the invoice-management admin menu (not
 *       manage_options, not edit_posts, none of the standard WordPress
 *       capabilities — so this role has no access to Posts/Plugins/Themes/
 *       Settings/Users)
 *
 * Design note: we use dedicated capabilities (ahp_*) instead of WordPress's
 * built-in roles (Editor/Author/...), so that restricting the admin panel in
 * Phase 5 stays precise and predictable and doesn't pick up unintended
 * capabilities from WordPress core updates.
 */
class AHP_Roles {

    public const CUSTOMER_ROLE = 'ahan_customer';
    public const MANAGER_ROLE  = 'ahan_invoice_manager';

    public const CAP_VIEW_OWN_INVOICES = 'ahp_view_own_invoices';
    public const CAP_MANAGE_INVOICES   = 'ahp_manage_invoices';

    public static function install(): void {
        self::install_customer_role();
        self::install_manager_role();
    }

    private static function install_customer_role(): void {
        // If it already exists, remove and recreate it with up-to-date capabilities
        // (safe, because this only removes the role definition, not any actual WordPress user)
        remove_role( self::CUSTOMER_ROLE );
        add_role(
            self::CUSTOMER_ROLE,
            __( 'Ahan Shahr Customer', 'ahan-proforma' ),
            [
                // Deliberately not 'read': the standard 'read' capability makes
                // WordPress allow access to wp-admin (the dashboard) by default,
                // even with no menu. A customer should only have access to the
                // front end (and later, the account page in Phase 6); blocking
                // wp-admin access is handled in block_customer_from_admin.
                self::CAP_VIEW_OWN_INVOICES   => true,
            ]
        );
    }

    private static function install_manager_role(): void {
        remove_role( self::MANAGER_ROLE );
        add_role(
            self::MANAGER_ROLE,
            __( 'Proforma Invoice Manager', 'ahan-proforma' ),
            [
                'read'                     => true, // only to view the dashboard; wp-admin access is impossible without it
                self::CAP_MANAGE_INVOICES  => true,
            ]
        );
    }

    /**
     * Can the current user manage proforma invoices?
     * The site's main admin (manage_options) is always true, so they can never lock themselves out.
     */
    public static function current_user_can_manage(): bool {
        return current_user_can( self::CAP_MANAGE_INVOICES ) || current_user_can( 'manage_options' );
    }

    public static function current_user_is_customer(): bool {
        return current_user_can( self::CAP_VIEW_OWN_INVOICES );
    }

    /* ── Restricting customer access to the dashboard ─────────────────────── */

    /**
     * Users who only have the ahan_customer role (and no other admin
     * capability) are blocked from entering wp-admin and redirected to the
     * site's home page (or a destination set via the
     * ahp_customer_admin_redirect_url filter). AJAX requests
     * (admin-ajax.php) are exempt from this rule, because the plugin itself
     * uses admin-ajax.php for logged-in customers too (e.g. in later phases,
     * for actions inside the account page).
     */
    public static function register_admin_restrictions(): void {
        add_action( 'admin_init', [ __CLASS__, 'block_customer_from_admin' ] );
        add_filter( 'show_admin_bar', [ __CLASS__, 'hide_admin_bar_for_customer' ] );
    }

    public static function block_customer_from_admin(): void {
        if ( wp_doing_ajax() ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();

        // If the user has a genuine admin capability (the site's main admin
        // or ahan_invoice_manager), they're allowed into the dashboard.
        if ( current_user_can( 'manage_options' ) || current_user_can( self::CAP_MANAGE_INVOICES ) ) {
            return;
        }

        if ( in_array( self::CUSTOMER_ROLE, (array) $user->roles, true ) ) {
            $redirect = apply_filters( 'ahp_customer_admin_redirect_url', home_url( '/' ), $user );
            wp_safe_redirect( $redirect );
            exit;
        }
    }

    public static function hide_admin_bar_for_customer( $show ) {
        if ( is_user_logged_in() && self::current_user_is_customer() && ! current_user_can( 'manage_options' ) && ! current_user_can( self::CAP_MANAGE_INVOICES ) ) {
            return false;
        }
        return $show;
    }
}
