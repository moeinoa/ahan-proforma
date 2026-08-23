<?php
/**
 * "My proforma invoices" tab — list of the current user's issued proforma invoices.
 *
 * Note: currently (until Phase 4 completes real proforma-invoice issuance)
 * $invoices is usually empty; this tab makes no assumptions about Phase 4's
 * structure and simply reads from AHP_DB::get_invoices_for_user (already
 * ready since Phase 1-b/2). As soon as Phase 4 inserts a row into the
 * ahp_invoices table for the user, this same loop displays it with no
 * further changes needed.
 *
 * Available variables: $invoices (array), $user (WP_User)
 */
defined( 'ABSPATH' ) || exit;
?>
<h2 class="ahp-account-panel-title"><?php esc_html_e( 'My proforma invoices', 'ahan-proforma' ); ?></h2>

<?php if ( empty( $invoices ) ) : ?>
    <p class="ahp-account-empty">
        <?php esc_html_e( 'No proforma invoices have been issued for you yet. Any proforma invoices you issue will show up here.', 'ahan-proforma' ); ?>
    </p>
<?php else : ?>
    <div class="ahp-invoice-list">
        <?php foreach ( $invoices as $invoice ) : ?>
            <div class="ahp-invoice-card">
                <div class="ahp-invoice-card-info">
                    <div class="ahp-invoice-card-main">
                        <span class="ahp-invoice-number"><?php echo esc_html( $invoice['invoice_number'] ); ?></span>
                        <span class="ahp-invoice-status ahp-invoice-status--<?php echo esc_attr( $invoice['status'] ); ?>">
                            <?php echo esc_html( AHP_Helpers::invoice_status_label( $invoice['status'] ) ); ?>
                        </span>
                    </div>
                    <div class="ahp-invoice-card-meta">
                        <span><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['created_at'] ) ); ?></span>
                        <span><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['total'] ) ); ?> <?php esc_html_e( 'Toman', 'ahan-proforma' ); ?></span>
                    </div>
                </div>
                <a class="ahp-invoice-view-btn" href="<?php echo esc_url( AHP_Invoice::invoice_url( $invoice['access_token'] ) ); ?>" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e( 'View', 'ahan-proforma' ); ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
