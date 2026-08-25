<?php
/**
 * Standalone proforma-invoice HTML output — no site-theme header/footer,
 * ready for printing. Available variables (from
 * AHP_Invoice::maybe_render_invoice_page): $invoice (array), $items (array)
 *
 * Note: this is the actual document the customer sees, so its visible text
 * intentionally stays in Persian (with Persian digits and RTL layout), per
 * asp's decision — unlike the rest of the plugin's admin/UI strings, which
 * are in English and translated via the .po file. Only the code comments
 * below are in English.
 *
 * The colors inside :root below are just a safe fallback for the brief
 * moment before the ahp_invoice_output_head hook runs;
 * AHP_Admin::print_color_overrides always (whether a custom color is set or
 * not) prints and overrides the final --ahp-primary and --ahp-primary-dark
 * value right after this <style> — meaning the "Ahan Shahr Proforma ▸
 * Settings" panel is the only real source of these colors. The VAT rate and
 * footer text are also read from the same settings
 * (AHP_Admin::get_vat_rate_percent_label and AHP_Admin::get_invoice_footer_text).
 */
defined( 'ABSPATH' ) || exit;

$site_name = get_bloginfo( 'name' );
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $invoice['invoice_number'] . ' — ' . $site_name ); ?></title>
<style>
    :root {
        --ahp-primary: #0f9d58;
        --ahp-primary-dark: #0b7a44;
        --ahp-text: #1f2937;
        --ahp-text-muted: #6b7280;
        --ahp-border: #e5e7eb;
        --ahp-bg-soft: #f9fafb;
    }
    * { box-sizing: border-box; }
    body {
        font-family: Tahoma, "Vazirmatn", sans-serif;
        color: var(--ahp-text);
        background: #fff;
        margin: 0;
        padding: 32px;
    }
    .ahp-inv-wrap { max-width: 820px; margin: 0 auto; }
    .ahp-inv-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 3px solid var(--ahp-primary);
        padding-bottom: 16px;
        margin-bottom: 24px;
    }
    .ahp-inv-site-name { font-size: 20px; font-weight: 800; color: var(--ahp-primary-dark); margin: 0; }
    .ahp-inv-title { font-size: 15px; color: var(--ahp-text-muted); margin: 4px 0 0; }
    .ahp-inv-meta { text-align: left; font-size: 13.5px; color: var(--ahp-text-muted); }
    .ahp-inv-meta strong { color: var(--ahp-text); font-size: 15px; display: block; margin-bottom: 4px; }
    .ahp-inv-status {
        display: inline-block;
        margin-top: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        background: #fef3c7;
        color: #92400e;
    }
    .ahp-inv-parties {
        display: flex;
        gap: 24px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .ahp-inv-party {
        flex: 1;
        min-width: 220px;
        background: var(--ahp-bg-soft);
        border-radius: 10px;
        padding: 14px 16px;
    }
    .ahp-inv-party h3 { margin: 0 0 8px; font-size: 13px; color: var(--ahp-text-muted); font-weight: 700; }
    .ahp-inv-party p { margin: 0; font-size: 14.5px; font-weight: 600; }
    table.ahp-inv-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 24px;
        font-size: 13.5px;
    }
    .ahp-inv-table th, .ahp-inv-table td {
        border: 1px solid var(--ahp-border);
        padding: 10px 12px;
        text-align: center;
    }
    .ahp-inv-table th {
        background: var(--ahp-bg-soft);
        font-weight: 700;
    }
    .ahp-inv-table td.ahp-inv-desc { text-align: right; }
    .ahp-inv-totals {
        display: flex;
        justify-content: flex-end;
        margin-bottom: 32px;
    }
    .ahp-inv-totals-box { min-width: 260px; }
    .ahp-inv-totals-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        font-size: 14px;
    }
    .ahp-inv-totals-row.total {
        border-top: 2px solid var(--ahp-primary);
        margin-top: 4px;
        padding-top: 12px;
        font-size: 17px;
        font-weight: 800;
        color: var(--ahp-primary-dark);
    }
    .ahp-inv-footer-note {
        font-size: 12.5px;
        color: var(--ahp-text-muted);
        border-top: 1px solid var(--ahp-border);
        padding-top: 16px;
    }
    .ahp-inv-print-btn {
        display: inline-block;
        margin-bottom: 20px;
        background: var(--ahp-primary);
        color: #fff;
        border: none;
        border-radius: 10px;
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
    }
    /* Admin-configurable notice line, right under the print button
       (AHP_Admin::get_invoice_notice_text). Kept visible in print/PDF
       output too — unlike the print button itself — since it's part of
       the document content, not a UI control. */
    .ahp-inv-notice {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 20px;
        padding: 10px 14px;
        background: #fef3c7;
        border: 1px solid #fde68a;
        border-radius: 10px;
        font-size: 13px;
        color: #92400e;
        line-height: 1.6;
    }
    .ahp-inv-notice svg {
        flex-shrink: 0;
        width: 18px;
        height: 18px;
        margin-top: 1px;
    }
    @media print {
        .ahp-inv-print-btn { display: none; }
        body { padding: 0; }
    }
    /* Status watermark — shown for any status except "approved" (Phase 5
       addendum). position:fixed so it stays fixed and behind the content
       (low z-index) both on screen and when printing/exporting to PDF;
       unlike the print button, which is hidden in @media print, this must
       deliberately remain in the printed version too. */
    .ahp-inv-watermark {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 90px;
        font-weight: 800;
        letter-spacing: 4px;
        white-space: nowrap;
        z-index: 0;
        pointer-events: none;
        user-select: none;
    }
    .ahp-inv-watermark.status-pending  { color: rgba(217, 119, 6, 0.18); }
    .ahp-inv-watermark.status-rejected { color: rgba(185, 28, 28, 0.18); }
    .ahp-inv-watermark.status-expired  { color: rgba(107, 114, 128, 0.18); }
    .ahp-inv-wrap { position: relative; z-index: 1; }
</style>
<?php
/*
 * Hook that injects the custom color override (Phase 5,
 * AHP_Admin::print_color_overrides). Deliberately placed *after* the
 * <style> above, so if the admin has set a custom color, it overrides the
 * default hex values above; if no color has been set in the panel, this
 * hook prints no output and the current look stays unchanged.
 */
do_action( 'ahp_invoice_output_head' );
?>
</head>
<body>
<?php if ( $invoice['status'] !== 'approved' ) : ?>
    <div class="ahp-inv-watermark status-<?php echo esc_attr( $invoice['status'] ); ?>">
        <?php echo esc_html( AHP_Helpers::invoice_status_label( $invoice['status'] ) ); ?>
    </div>
<?php endif; ?>
<div class="ahp-inv-wrap">

    <button type="button" class="ahp-inv-print-btn" onclick="window.print()">
        <?php esc_html_e( 'چاپ / ذخیرهٔ PDF', 'ahan-proforma' ); ?>
    </button>

    <?php $ahp_notice_text = AHP_Admin::get_invoice_notice_text(); ?>
    <?php if ( '' !== trim( (string) $ahp_notice_text ) ) : ?>
    <div class="ahp-inv-notice">
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span><?php echo esc_html( $ahp_notice_text ); ?></span>
    </div>
    <?php endif; ?>

    <div class="ahp-inv-header">
        <div>
            <p class="ahp-inv-site-name"><?php echo esc_html( $site_name ); ?></p>
            <p class="ahp-inv-title"><?php esc_html_e( 'پیش‌فاکتور فروش', 'ahan-proforma' ); ?></p>
        </div>
        <div class="ahp-inv-meta">
            <strong><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['invoice_number'] ) ); ?></strong>
            <span><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['created_at'] ) ); ?></span><br>
            <span class="ahp-inv-status"><?php echo esc_html( AHP_Helpers::invoice_status_label( $invoice['status'] ) ); ?></span>
        </div>
    </div>

    <div class="ahp-inv-parties">
        <div class="ahp-inv-party">
            <h3><?php esc_html_e( 'خریدار', 'ahan-proforma' ); ?></h3>
            <p><?php echo esc_html( $invoice['buyer_name'] ); ?></p>
            <p><?php echo esc_html( AHP_Helpers::to_persian_digits( $invoice['buyer_phone'] ) ); ?></p>
        </div>
        <div class="ahp-inv-party">
            <h3><?php esc_html_e( 'فروشنده', 'ahan-proforma' ); ?></h3>
            <p><?php echo esc_html( $site_name ); ?></p>
        </div>
    </div>

    <table class="ahp-inv-table">
        <thead>
            <tr>
                <th style="width:32px;">#</th>
                <th><?php esc_html_e( 'شرح کالا', 'ahan-proforma' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'واحد', 'ahan-proforma' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'تعداد', 'ahan-proforma' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( 'قیمت واحد (تومان)', 'ahan-proforma' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'مبلغ کل (تومان)', 'ahan-proforma' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $items as $i => $item ) : ?>
                <tr>
                    <td><?php echo esc_html( AHP_Helpers::to_persian_digits( (string) ( $i + 1 ) ) ); ?></td>
                    <td class="ahp-inv-desc">
                        <?php echo esc_html( $item['description'] ); ?>
                        <?php if ( ! empty( $item['product_code'] ) ) : ?>
                            <br><small style="color:var(--ahp-text-muted);"><?php echo esc_html( $item['product_code'] ); ?></small>
                        <?php endif; ?>
                    </td>
                    <?php
                    $unit_display_map = [
                        'kg'     => __( 'کیلوگرم', 'ahan-proforma' ),
                        'shakhe' => __( 'شاخه', 'ahan-proforma' ),
                        'adad'   => __( 'عدد', 'ahan-proforma' ),
                    ];
                    ?>
                    <td><?php echo esc_html( $unit_display_map[ $item['unit'] ] ?? $item['unit'] ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::to_persian_digits( (string) (int) $item['quantity'] ) ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::format_toman( (float) $item['unit_price'] ) ); ?></td>
                    <td><?php echo esc_html( AHP_Helpers::format_toman( (float) $item['line_total'] ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="ahp-inv-totals">
        <div class="ahp-inv-totals-box">
            <div class="ahp-inv-totals-row">
                <span><?php esc_html_e( 'جمع جزء', 'ahan-proforma' ); ?></span>
                <span><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['subtotal'] ) ); ?> <?php esc_html_e( 'تومان', 'ahan-proforma' ); ?></span>
            </div>
            <div class="ahp-inv-totals-row">
                <span><?php echo esc_html( sprintf(
                    /* translators: %s: VAT percentage, Persian digits */
                    __( 'مالیات بر ارزش افزوده (%s٪)', 'ahan-proforma' ),
                    AHP_Admin::get_vat_rate_percent_label()
                ) ); ?></span>
                <span><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['total'] - (float) $invoice['subtotal'] ) ); ?> <?php esc_html_e( 'تومان', 'ahan-proforma' ); ?></span>
            </div>
            <div class="ahp-inv-totals-row total">
                <span><?php esc_html_e( 'مبلغ نهایی', 'ahan-proforma' ); ?></span>
                <span><?php echo esc_html( AHP_Helpers::format_toman( (float) $invoice['total'] ) ); ?> <?php esc_html_e( 'تومان', 'ahan-proforma' ); ?></span>
            </div>
        </div>
    </div>

    <div class="ahp-inv-footer-note">
        <?php echo wp_kses_post( AHP_Admin::get_invoice_footer_text() ); ?>
    </div>

</div>
</body>
</html>