<?php
/**
 * Customer account page template — [ahp_account] shortcode
 * Available variables (from AHP_Account::render_account_shortcode): $tab, $user, $mobile, $invoices
 */
defined( 'ABSPATH' ) || exit;

$account_url = apply_filters( 'ahp_account_page_url', home_url( '/account/' ) );
$cart_url    = apply_filters( 'ahp_cart_page_url',     home_url( '/proforma-cart/' ) );
$edit_url    = apply_filters( 'ahp_account_edit_url',  home_url( '/account/?tab=edit' ) );
$current_url  = home_url( $_SERVER['REQUEST_URI'] ?? '/' );
$logout_url   = wp_logout_url( $current_url );
?>
<div class="ahp-account-wrap" data-ahp-account-root>

	<nav class="ahp-account-tabs">
		<a href="<?php echo esc_url( $account_url ); ?>" class="ahp-account-tab<?php echo $tab === 'invoices' ? ' is-active' : ''; ?>">
			<svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" stroke="currentColor" stroke-width="1.7"/><path d="M4.5 20c1.2-3.5 4.2-5.5 7.5-5.5s6.3 2 7.5 5.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
			<?php esc_html_e( 'My proforma invoices', 'ahan-proforma' ); ?>
		</a>
		<a href="<?php echo esc_url( $cart_url ); ?>" class="ahp-account-tab" target="_blank" rel="noopener noreferrer">
			<svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h1.8l1.2 2M7 6h13l-1.6 8.2a2 2 0 0 1-2 1.6H8.9a2 2 0 0 1-2-1.65L5 6Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9.5" cy="20" r="1.4" fill="currentColor"/><circle cx="16.5" cy="20" r="1.4" fill="currentColor"/></svg>
			<?php esc_html_e( 'Proforma cart', 'ahan-proforma' ); ?>
		</a>
		<a href="<?php echo esc_url( $edit_url ); ?>" class="ahp-account-tab<?php echo $tab === 'edit' ? ' is-active' : ''; ?>">
			<svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php esc_html_e( 'Edit information', 'ahan-proforma' ); ?>
		</a>
		<a href="<?php echo esc_url( $logout_url ); ?>" class="ahp-account-tab">
			<svg viewBox="0 0 24 24" width="17" height="17" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 17l5-5-5-5M21 12H9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php esc_html_e( 'Log out', 'ahan-proforma' ); ?>
		</a>
	</nav>

	<div class="ahp-account-panel">
		<?php if ( $tab === 'edit' ) : ?>
		<?php include AHP_PLUGIN_DIR . 'templates/account-tab-edit.php'; ?>
		<?php else : ?>
		<?php include AHP_PLUGIN_DIR . 'templates/account-tab-invoices.php'; ?>
		<?php endif; ?>
	</div>

</div>
