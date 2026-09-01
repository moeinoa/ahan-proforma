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
	<a class="ahp-invoice-card" href="<?php echo esc_url( AHP_Invoice::invoice_url( $invoice['access_token'] ) ); ?>" target="_blank" rel="noopener noreferrer">
		<div class="ahp-invoice-card-info">
			<div class="ahp-invoice-card-main">
				<span class="ahp-invoice-status ahp-invoice-status--<?php echo esc_attr( $invoice['status'] ); ?>">
					<?php echo esc_html( AHP_Helpers::invoice_status_label( $invoice['status'] ) ); ?>
				</span>
				<span class="ahp-invoice-number"><?php echo esc_html( $invoice['invoice_number'] ); ?></span>
			</div>
			<div class="ahp-invoice-card-meta">
				<span class="ahp-invoice-card-time">
					<svg class="ahp-invoice-card-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<span class="ahp-invoice-card-meta-label">ساعت</span>
					<span class="ahp-invoice-card-meta-value"><?php echo esc_html( AHP_Helpers::format_time( $invoice['created_at'] ) ); ?></span>
				</span>
				<span class="ahp-invoice-card-date">
					<svg class="ahp-invoice-card-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3.5" y="4.5" width="17" height="16" rx="2" stroke="currentColor" stroke-width="1.7"/><path d="M3.5 9h17M8 2.5v3M16 2.5v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
					<span class="ahp-invoice-card-meta-label">تاریخ</span>
					<span class="ahp-invoice-card-meta-value"><?php echo esc_html( AHP_Helpers::format_jalali_date( $invoice['created_at'] ) ); ?></span>
				</span>
			</div>
			<div class="ahp-invoice-card-bottom">
				<span class="ahp-invoice-view-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.7"/></svg>
				</span>
				<span class="ahp-invoice-card-total"><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['total'] ) ); ?> <?php esc_html_e( 'Toman', 'ahan-proforma' ); ?></span>
			</div>
		</div>
	</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>