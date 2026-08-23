<?php
/**
 * Customer account page template — [ahp_account] shortcode
 * Available variables (from AHP_Account::render_account_shortcode): $tab, $user, $mobile, $invoices
 */
defined( 'ABSPATH' ) || exit;

$account_url = apply_filters( 'ahp_account_page_url', home_url( '/account/' ) );
$cart_url    = apply_filters( 'ahp_cart_page_url',     home_url( '/proforma-cart/' ) );
$edit_url    = apply_filters( 'ahp_account_edit_url',  home_url( '/account/?tab=edit' ) );
?>
<div class="ahp-account-wrap" data-ahp-account-root>

    <nav class="ahp-account-tabs">
        <a href="<?php echo esc_url( $account_url ); ?>" class="ahp-account-tab<?php echo $tab === 'invoices' ? ' is-active' : ''; ?>">
            <?php esc_html_e( 'My proforma invoices', 'ahan-proforma' ); ?>
        </a>
        <a href="<?php echo esc_url( $cart_url ); ?>" class="ahp-account-tab" target="_blank" rel="noopener noreferrer">
            <?php esc_html_e( 'Proforma cart', 'ahan-proforma' ); ?>
        </a>
        <a href="<?php echo esc_url( $edit_url ); ?>" class="ahp-account-tab<?php echo $tab === 'edit' ? ' is-active' : ''; ?>">
            <?php esc_html_e( 'Edit information', 'ahan-proforma' ); ?>
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
