<?php
defined( 'ABSPATH' ) || exit;

/**
 * AHP_Invoice
 *
 * Phase 4 (basic version): issuing a proforma invoice from the logged-in
 * user's current cart + a standalone HTML output page that can be
 * printed/saved as PDF (via the browser's print function).
 *
 * This phase is deliberately not wired to any UI button (per asp's
 * decision); only the public static method AHP_Invoice::issue_from_cart()
 * is ready for the next phase (wiring up the "issue proforma invoice"
 * button in the cart) to call.
 *
 * The output page is accessed via a dedicated query var, not a shortcode:
 *   /?ahp_invoice=<access_token>
 * Reason for choosing a query var over a shortcode: the output page must be
 * standalone, without the theme's header/footer/sidebar (clean printing); if
 * it were a shortcode, wherever the user opened that page the site's full
 * theme would wrap around it too. Here, template_include controls the
 * entire page output (not just the main content).
 *
 * access_token (not a numeric id) is used in the URL so that invoice
 * numbers remain unguessable/non-enumerable for anyone who only has their own link.
 */
class AHP_Invoice {

    private const QUERY_VAR = 'ahp_invoice';

    public static function init(): void {
        add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_include', [ __CLASS__, 'maybe_render_invoice_page' ] );
    }

    public static function register_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /* ── Issuing a proforma invoice from the logged-in user's current cart ── */

    /**
     * Converts the current user's cart into a final proforma invoice (a row
     * in ahp_invoices + rows in ahp_invoice_items, each a full snapshot) and
     * clears the cart afterward. The cart must have at least one item and
     * the user must be logged in (per the comment on ahp_invoices.user_id in
     * class-ahp-db.php: "issuing requires login").
     *
     * @return array{ok:bool, message?:string, invoice_id?:int, url?:string}
     */
    public static function issue_from_cart(): array {
        if ( ! is_user_logged_in() ) {
            return [ 'ok' => false, 'message' => __( 'Please log in to your account first to issue a proforma invoice.', 'ahan-proforma' ) ];
        }

        // Atomic claim of the cart: a double-click / two tabs can never issue two
        // invoices from the same cart. Items are given back if anything fails below.
        $items = AHP_Cart::take_items_for_issue();
        if ( $items === null ) {
            return [ 'ok' => false, 'message' => __( 'Your proforma cart is empty.', 'ahan-proforma' ) ];
        }

        $user   = wp_get_current_user();
        $mobile = get_user_meta( $user->ID, AHP_Auth::mobile_meta_key(), true );

        [ $jalali_year, , ] = AHP_Helpers::today_jalali();
        $seq_number     = AHP_DB::get_next_seq_number();
        $invoice_number = AHP_Helpers::build_invoice_number( $seq_number, $jalali_year );

        $subtotal = 0.0;
        foreach ( $items as $item ) {
            $subtotal += $item['unit_price'] * $item['quantity'];
        }
        // VAT — computed on the subtotal and added to the final amount
        // (total); the subtotal itself stays untouched so the HTML output
        // can show the pre-tax amount separately.
        // The rate is read from the admin panel's settings (Phase 5); if no
        // explicit setting exists, AHP_Admin::get_vat_rate() returns the
        // default 10% (the same value that was hardcoded before Phase 5).
        $vat_rate   = AHP_Admin::get_vat_rate();
        $vat_amount = $subtotal * $vat_rate;
        $total      = $subtotal + $vat_amount;

        $invoice_id = AHP_DB::insert_invoice( [
            'invoice_number' => $invoice_number,
            'seq_number'     => $seq_number,
            'jalali_year'    => $jalali_year,
            'user_id'        => $user->ID,
            // A snapshot at issuance time; per the comment on ahp_invoices
            // in class-ahp-db.php, later changes to the user's profile don't
            // affect the issued document
            'buyer_name'     => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
            'buyer_phone'    => (string) $mobile,
            'subtotal'       => (int) round( $subtotal ),
            'total'          => (int) round( $total ),
            'status'         => 'pending',
            'access_token'   => wp_generate_password( 40, false, false ),
        ] );

        if ( ! $invoice_id ) {
            // In a debug environment (WP_DEBUG), also log wpdb's actual
            // error so the real cause (e.g. a missing column after a schema
            // update, or a UNIQUE KEY conflict) can be identified; the
            // message returned to the user is deliberately kept generic.
            global $wpdb;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $wpdb->last_error ) {
                error_log( 'AHP_Invoice::issue_from_cart insert_invoice failed: ' . $wpdb->last_error );
            }
            AHP_Cart::restore_items( $items );
            return [ 'ok' => false, 'message' => __( 'Error saving the proforma invoice; please try again.', 'ahan-proforma' ) ];
        }

        $sort = 0;
        foreach ( $items as $item ) {
            $item_id = AHP_DB::insert_item( [
                'invoice_id'      => $invoice_id,
                'source_table_id' => $item['table_id'] ?: null,
                'source_row_hash' => $item['row_hash'] ?: null,
                'product_code'    => $item['code'] ?: null,
                'description'     => $item['description'],
                'unit'            => $item['unit'],
                'quantity'        => $item['quantity'],
                'unit_price'      => (int) round( $item['unit_price'] ),
                'line_total'      => (int) round( $item['unit_price'] * $item['quantity'] ),
                'sort_order'      => $sort++,
            ] );

            if ( ! $item_id ) {
                // A line item failed to save: never leave a partial invoice behind
                // and never lose the customer's cart.
                global $wpdb;
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $wpdb->last_error ) {
                    error_log( 'AHP_Invoice::issue_from_cart insert_item failed: ' . $wpdb->last_error );
                }
                AHP_DB::delete_invoice( $invoice_id );
                AHP_Cart::restore_items( $items );
                return [ 'ok' => false, 'message' => __( 'Error saving the proforma invoice; please try again.', 'ahan-proforma' ) ];
            }
        }

        AHP_Cart::clear();

        $invoice = AHP_DB::get_invoice( $invoice_id );

        // Admin SMS notification (Phase 5 addendum). This send is
        // deliberately non-blocking with respect to the user: issuing the
        // proforma invoice is considered successful even if the SMS send
        // fails (only logged to error_log), since this is a side
        // notification, not a critical part of the issuance process.
        // Currently only the admin is notified by SMS; a customer SMS
        // (approved/rejected) will be added in a later phase.
        self::notify_admin_new_invoice( $invoice );

        return [
            'ok'         => true,
            'invoice_id' => $invoice_id,
            'url'        => self::invoice_url( $invoice['access_token'] ),
        ];
    }

    /** Send an admin notification SMS when a new proforma invoice is issued */
    private static function notify_admin_new_invoice( array $invoice ): void {
        $mobiles = AHP_Admin::get_admin_notify_mobiles();
        if ( empty( $mobiles ) ) {
            return; // not configured; the notification isn't set up yet
        }

        $text = sprintf(
            /* translators: 1: invoice number, 2: buyer name */
            __( 'New proforma invoice issued: %1$s - Buyer: %2$s', 'ahan-proforma' ),
            $invoice['invoice_number'],
            $invoice['buyer_name']
        );

        foreach ( $mobiles as $mobile ) {
            $result = AHP_SMS_Gateway::send_text( $mobile, $text );
            if ( ! $result['ok'] ) {
                error_log( sprintf(
                    '[Ahan Proforma] Admin notify SMS failed for %s (invoice #%s): %s',
                    $mobile,
                    $invoice['invoice_number'],
                    $result['error'] ?? 'unknown'
                ) );
            }
        }
    }

    /** Build the output page URL from an access_token */
    public static function invoice_url( string $access_token ): string {
        return add_query_arg( self::QUERY_VAR, $access_token, home_url( '/' ) );
    }

    /* ── Rendering the standalone output page ──────────────────────────────── */

    /**
     * If the query var is set, the site's full theme (header/footer/sidebar)
     * is bypassed and only templates/invoice-output.php is printed — exactly
     * like a standalone print page. If the token is invalid, WordPress's
     * standard 404.
     */
    public static function maybe_render_invoice_page( string $template ): string {
        $token = get_query_var( self::QUERY_VAR );
        if ( ! $token ) {
            return $template;
        }

        $token = sanitize_text_field( $token );
        $invoice = AHP_DB::get_invoice_by_token( $token );

        if ( ! $invoice ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return $template; // show the theme's standard 404 template
        }

        // Only the proforma invoice's owner or an admin can view the output
        // page; even with a valid access_token (which is itself
        // random/unguessable), this is a second defensive layer for when a
        // link leaks to someone else by mistake or intent.
        if ( ! self::current_user_can_view( $invoice ) ) {
            wp_die(
                esc_html__( 'You are not allowed to view this proforma invoice.', 'ahan-proforma' ),
                esc_html__( 'Access not allowed', 'ahan-proforma' ),
                [ 'response' => 403 ]
            );
        }

        $items = AHP_DB::get_items_for_invoice( (int) $invoice['id'] );

        include AHP_PLUGIN_DIR . 'templates/invoice-output.php';
        exit; // the output is fully standalone; continuing WordPress's normal cycle (footer/widgets/etc.) isn't needed
    }

    private static function current_user_can_view( array $invoice ): bool {
        if ( current_user_can( 'manage_options' ) || AHP_Roles::current_user_can_manage() ) {
            return true;
        }
        return is_user_logged_in() && get_current_user_id() === (int) $invoice['user_id'];
    }
}
