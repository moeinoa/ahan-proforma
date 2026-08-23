<?php
/**
 * Template for the "Settings" page in the admin panel (Phase 5).
 * Available variables (from AHP_Admin::render_settings_page):
 * $colors, $sms, $sms_overridden_by_constant, $admin_notify_mobile, $footer_text, $notice_text, $vat_percent
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Ahan Shahr Proforma Settings', 'ahan-proforma' ); ?></h1>

    <?php if ( $sms_overridden_by_constant ) : ?>
        <div class="notice notice-info">
            <p>
                <?php esc_html_e( 'One or more of AHP_SMS_USERNAME / AHP_SMS_PASSWORD / AHP_SMS_FROM is defined in wp-config.php and currently takes priority over the values on this page. To manage everything from this panel, remove those constants from wp-config.php.', 'ahan-proforma' ); ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'ahp_settings_group' ); ?>

        <h2 class="title"><?php esc_html_e( 'Color and style', 'ahan-proforma' ); ?></h2>
        <p class="description"><?php esc_html_e( 'All of the plugin\'s colors (price-table buttons, proforma cart, login form, account page, proforma-invoice document) are read from these fields. If a field is left empty, the plugin\'s default color is used.', 'ahan-proforma' ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ahp_color_primary"><?php esc_html_e( 'Primary color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_primary" name="ahp_color_primary" value="<?php echo esc_attr( $colors['primary'] ); ?>" class="ahp-color-field" placeholder="#0f9d58"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_primary_dark"><?php esc_html_e( 'Primary Dark color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_primary_dark" name="ahp_color_primary_dark" value="<?php echo esc_attr( $colors['primary_dark'] ); ?>" class="ahp-color-field" placeholder="#0b7a44"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_primary_light"><?php esc_html_e( 'Primary Light color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_primary_light" name="ahp_color_primary_light" value="<?php echo esc_attr( $colors['primary_light'] ); ?>" class="ahp-color-field" placeholder="#e6f7ee"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_accent"><?php esc_html_e( 'Accent color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_accent" name="ahp_color_accent" value="<?php echo esc_attr( $colors['accent'] ); ?>" class="ahp-color-field" placeholder="#d0342c"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_contact"><?php esc_html_e( '"Call us" button color (hover)', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_contact" name="ahp_color_contact" value="<?php echo esc_attr( $colors['contact'] ); ?>" class="ahp-color-field" placeholder="#c9862c"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_contact_dark"><?php esc_html_e( '"Call us" button text color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_contact_dark" name="ahp_color_contact_dark" value="<?php echo esc_attr( $colors['contact_dark'] ); ?>" class="ahp-color-field" placeholder="#b3721f"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_color_contact_light"><?php esc_html_e( '"Call us" button background color', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_color_contact_light" name="ahp_color_contact_light" value="<?php echo esc_attr( $colors['contact_light'] ); ?>" class="ahp-color-field" placeholder="#fdf1e2"></td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Farapayamak SMS panel', 'ahan-proforma' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ahp_sms_username"><?php esc_html_e( 'Username', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_sms_username" name="ahp_sms_username" value="<?php echo esc_attr( $sms['username'] ); ?>" class="regular-text" dir="ltr" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_sms_password"><?php esc_html_e( 'Password', 'ahan-proforma' ); ?></label></th>
                <td><input type="password" id="ahp_sms_password" name="ahp_sms_password" value="<?php echo esc_attr( $sms['password'] ); ?>" class="regular-text" dir="ltr" autocomplete="new-password"></td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_sms_from"><?php esc_html_e( 'Sender number (from)', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_sms_from" name="ahp_sms_from" value="<?php echo esc_attr( $sms['from'] ); ?>" class="regular-text" dir="ltr" autocomplete="off"></td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Admin SMS notification', 'ahan-proforma' ); ?></h2>
        <p class="description"><?php esc_html_e( 'When each new proforma invoice is submitted, a text SMS is sent to the number(s) below. For multiple numbers, separate them with a comma (,). If left empty, this SMS is not sent.', 'ahan-proforma' ); ?></p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ahp_admin_notify_mobile"><?php esc_html_e( 'Admin mobile number(s)', 'ahan-proforma' ); ?></label></th>
                <td><input type="text" id="ahp_admin_notify_mobile" name="ahp_admin_notify_mobile" value="<?php echo esc_attr( $admin_notify_mobile ); ?>" class="regular-text" dir="ltr" placeholder="09121234567, 09359876543"></td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Proforma invoice content', 'ahan-proforma' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ahp_invoice_notice_text"><?php esc_html_e( 'Notice text under the print button', 'ahan-proforma' ); ?></label></th>
                <td>
                    <textarea id="ahp_invoice_notice_text" name="ahp_invoice_notice_text" rows="3" class="large-text"><?php echo esc_textarea( $notice_text ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Plain text only (no HTML). Shown next to a warning icon, right below the print button.', 'ahan-proforma' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ahp_invoice_footer_text"><?php esc_html_e( 'Text at the bottom of the proforma-invoice document', 'ahan-proforma' ); ?></label></th>
                <td>
                    <?php
                    wp_editor(
                        $footer_text,
                        'ahp_invoice_footer_text',
                        [
                            'textarea_name' => 'ahp_invoice_footer_text',
                            'textarea_rows' => 6,
                            'media_buttons' => true,
                            'teeny'         => false,
                            'tinymce'       => true,
                            'quicktags'     => true,
                        ]
                    );
                    ?>
                </td>
            </tr>
        </table>

        <h2 class="title"><?php esc_html_e( 'Tax', 'ahan-proforma' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ahp_vat_rate"><?php esc_html_e( 'VAT rate (%)', 'ahan-proforma' ); ?></label></th>
                <td>
                    <input type="number" id="ahp_vat_rate" name="ahp_vat_rate" value="<?php echo esc_attr( $vat_percent ); ?>" min="0" max="100" step="0.01" class="small-text" dir="ltr">
                    <span class="description"><?php esc_html_e( 'For example, enter 10 for 10%.', 'ahan-proforma' ); ?></span>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save settings', 'ahan-proforma' ) ); ?>
    </form>
</div>