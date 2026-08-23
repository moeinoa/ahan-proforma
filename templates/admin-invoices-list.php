<?php
/**
 * Template for the "Invoices" page in the admin panel (Phase 5 + item-editing addendum).
 * Available variables (from AHP_Admin::render_invoices_page):
 * $items, $items_by_invoice, $total, $total_pages, $paged, $per_page, $status_filter, $statuses
 */
defined( 'ABSPATH' ) || exit;

$notice = isset( $_GET['ahp_notice'] ) ? sanitize_key( wp_unslash( $_GET['ahp_notice'] ) ) : '';

$unit_labels = [
    'kg'     => __( 'kg', 'ahan-proforma' ),
    'shakhe' => __( 'bundle', 'ahan-proforma' ),
];
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Ahan Shahr Proforma Invoices', 'ahan-proforma' ); ?></h1>

    <?php if ( $notice === 'status_updated' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The proforma invoice status was updated successfully.', 'ahan-proforma' ); ?></p></div>
    <?php elseif ( $notice === 'status_update_failed' ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The status update failed; the input was invalid.', 'ahan-proforma' ); ?></p></div>
    <?php elseif ( $notice === 'item_updated' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The proforma invoice row was updated and the document total was recomputed.', 'ahan-proforma' ); ?></p></div>
    <?php elseif ( $notice === 'item_deleted' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The proforma invoice row was deleted and the document total was recomputed.', 'ahan-proforma' ); ?></p></div>
    <?php elseif ( $notice === 'item_update_failed' ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Saving the row failed; the input was invalid.', 'ahan-proforma' ); ?></p></div>
    <?php endif; ?>

    <ul class="subsubsub">
        <?php
        $links = [];
        foreach ( $statuses as $key => $label ) {
            $url  = add_query_arg( array_filter( [ 'page' => 'ahp-invoices', 'status' => $key ] ), admin_url( 'admin.php' ) );
            $cls  = ( $status_filter === $key ) ? 'current' : '';
            $links[] = sprintf(
                '<li><a href="%s" class="%s">%s</a></li>',
                esc_url( $url ),
                esc_attr( $cls ),
                esc_html( $label )
            );
        }
        echo implode( ' | ', $links ); // phpcs:ignore -- every value above is built with esc_
        ?>
    </ul>
    <br class="clear">

    <p class="description">
        <?php esc_html_e( 'Click a proforma invoice\'s number to view and edit its line items (description, unit, quantity, price). The document total is automatically recomputed after any row change; editing is always possible regardless of the proforma invoice\'s status.', 'ahan-proforma' ); ?>
    </p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:24px;"></th>
                <th><?php esc_html_e( 'Proforma invoice number', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Buyer', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Contact number', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Final amount (Toman)', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Status', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Date issued', 'ahan-proforma' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'ahan-proforma' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $items ) ) : ?>
            <tr><td colspan="8"><?php esc_html_e( 'No proforma invoices were found.', 'ahan-proforma' ); ?></td></tr>
        <?php else : ?>
            <?php foreach ( $items as $invoice ) :
                $row_id        = 'ahp-inv-row-' . (int) $invoice['id'];
                $invoice_items = $items_by_invoice[ $invoice['id'] ] ?? [];
            ?>
                <tr>
                    <td>
                        <button type="button" class="button-link ahp-accordion-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>" data-target="<?php echo esc_attr( $row_id ); ?>" style="text-decoration:none;">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                            <span class="screen-reader-text"><?php esc_html_e( 'Show/hide items', 'ahan-proforma' ); ?></span>
                        </button>
                    </td>
                    <td>
                        <button type="button" class="button-link ahp-accordion-toggle" data-target="<?php echo esc_attr( $row_id ); ?>" style="text-decoration:none;">
                            <strong><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['invoice_number'] ) ); ?></strong>
                        </button>
                        <?php if ( ! empty( $invoice['access_token'] ) ) : ?>
                            <br>
                            <a href="<?php echo esc_url( add_query_arg( 'ahp_invoice', $invoice['access_token'], home_url( '/' ) ) ); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e( 'View document', 'ahan-proforma' ); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $invoice['buyer_name'] ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['buyer_phone'] ) ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['total'] ) ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::invoice_status_label( $invoice['status'] ) ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['created_at'] ) ); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                            <?php wp_nonce_field( 'ahp_update_invoice_status' ); ?>
                            <input type="hidden" name="action" value="ahp_update_invoice_status">
                            <input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice['id'] ); ?>">
                            <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                            <input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>">
                            <select name="new_status">
                                <?php foreach ( [ 'pending', 'approved', 'rejected', 'expired' ] as $st ) : ?>
                                    <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $invoice['status'], $st ); ?>>
                                        <?php echo esc_html( AHP_Helpers::invoice_status_label( $st ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Apply', 'ahan-proforma' ); ?></button>
                        </form>
                    </td>
                </tr>
                <tr id="<?php echo esc_attr( $row_id ); ?>" class="ahp-accordion-panel" hidden>
                    <td colspan="8" style="background:#f6f7f7;">
                        <table class="wp-list-table widefat" style="margin:8px 0;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Product description', 'ahan-proforma' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'Unit', 'ahan-proforma' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'Quantity', 'ahan-proforma' ); ?></th>
                                    <th style="width:150px;"><?php esc_html_e( 'Unit price (Toman)', 'ahan-proforma' ); ?></th>
                                    <th style="width:150px;"><?php esc_html_e( 'Line total (Toman)', 'ahan-proforma' ); ?></th>
                                    <th style="width:170px;"><?php esc_html_e( 'Actions', 'ahan-proforma' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ( empty( $invoice_items ) ) : ?>
                                <tr><td colspan="6"><?php esc_html_e( 'This proforma invoice has no rows.', 'ahan-proforma' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $invoice_items as $item ) : ?>
                                    <tr>
                                        <td colspan="6" style="padding:0;">
                                        <div style="display:flex; gap:8px; align-items:center; padding:8px; flex-wrap:wrap;">
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; flex:1;">
                                                <?php wp_nonce_field( 'ahp_update_invoice_item' ); ?>
                                                <input type="hidden" name="action" value="ahp_update_invoice_item">
                                                <input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
                                                <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                                                <input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>">
                                                <input type="text" name="description" value="<?php echo esc_attr( $item['description'] ); ?>" class="regular-text" style="flex:2; min-width:180px;">
                                                <select name="unit" style="width:110px;">
                                                    <?php foreach ( $unit_labels as $u_key => $u_label ) : ?>
                                                        <option value="<?php echo esc_attr( $u_key ); ?>" <?php selected( $item['unit'], $u_key ); ?>><?php echo esc_html( $u_label ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="number" step="0.01" min="0.01" name="quantity" value="<?php echo esc_attr( $item['quantity'] ); ?>" style="width:90px;" dir="ltr" title="<?php esc_attr_e( 'Quantity', 'ahan-proforma' ); ?>">
                                                <input type="number" step="1" min="0" name="unit_price" value="<?php echo esc_attr( (int) $item['unit_price'] ); ?>" style="width:120px;" dir="ltr" title="<?php esc_attr_e( 'Unit price (Toman)', 'ahan-proforma' ); ?>">
                                                <span style="min-width:100px;"><?php echo esc_html( AHP_Helpers::format_toman( (float) $item['line_total'] ) ); ?> <?php esc_html_e( 'Toman', 'ahan-proforma' ); ?></span>
                                                <button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'ahan-proforma' ); ?></button>
                                            </form>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this row?', 'ahan-proforma' ) ); ?>');">
                                                <?php wp_nonce_field( 'ahp_delete_invoice_item' ); ?>
                                                <input type="hidden" name="action" value="ahp_delete_invoice_item">
                                                <input type="hidden" name="item_id" value="<?php echo esc_attr( $item['id'] ); ?>">
                                                <input type="hidden" name="status_filter" value="<?php echo esc_attr( $status_filter ); ?>">
                                                <input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>">
                                                <button type="submit" class="button button-small" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'ahan-proforma' ); ?></button>
                                            </form>
                                        </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( [ // phpcs:ignore -- paginate_links output is already escaped
                    'base'      => add_query_arg( array_filter( [ 'page' => 'ahp-invoices', 'status' => $status_filter, 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => __( '&laquo; Previous', 'ahan-proforma' ),
                    'next_text' => __( 'Next &raquo;', 'ahan-proforma' ),
                ] );
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.ahp-accordion-toggle');
    if (!btn) return;
    var targetId = btn.getAttribute('data-target');
    var panel = document.getElementById(targetId);
    if (!panel) return;
    var isHidden = panel.hasAttribute('hidden');
    if (isHidden) {
        panel.removeAttribute('hidden');
        btn.setAttribute('aria-expanded', 'true');
    } else {
        panel.setAttribute('hidden', 'hidden');
        btn.setAttribute('aria-expanded', 'false');
    }
});
</script>
