<?php
/**
 * Plugin Name: Ahan Proforma
 * Plugin URI: https://github.com/moeinoa/
 * Description: Converts price table rows (Ahan Price Tables plugin) into proforma invoices, without requiring WooCommerce.
 * Version: 1.0.4
 * Author: Moein Akbari
 * Author URI: https://moein-akbari.ir/
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Text Domain: ahan-proforma
 * Domain Path: /languages
 *
 * Soft dependency on the "Ahan Price Tables" plugin:
 * This plugin never calls APT_* classes directly and only interacts with it
 * through the apt_table_rendered_html filter. If that plugin is inactive,
 * this plugin still loads without errors (only the "add to invoice" button
 * on price tables will not appear).
 */

defined( 'ABSPATH' ) || exit;

define( 'AHP_VERSION',    '1.0.4' );
define( 'AHP_DB_VERSION', '1.1.2' ); // 1.1.2: explicit access_token column guarantee via ALTER TABLE independent of dbDelta (fixes "Unknown column" error on the live site)
// Kept separate from AHP_DB_VERSION so role capability changes (with no
// database schema change) apply automatically on next load, without needing
// to manually deactivate/reactivate the plugin.
define( 'AHP_ROLES_VERSION', '1.0.1' );
define( 'AHP_PLUGIN_FILE', __FILE__ );
define( 'AHP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AHP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ── Load plugin files ─────────────────────────────────────────────────── */

require_once AHP_PLUGIN_DIR . 'includes/class-ahp-db.php';
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-roles.php';
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-helpers.php';
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-table-integration.php'; // Phase 1-b: add-to-invoice button on tables
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-cart.php';              // Phase 2: proforma cart
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-sms-gateway.php';       // Phase 3: SMS gateway (Farapayamak REST)
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-auth.php';              // Phase 3: customer sign-up/login via OTP
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-user-fields.php';       // Phase 3: dedicated mobile-number column/field in the dashboard
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-account.php';           // Phase 6: customer account page (implemented ahead of Phase 4)
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-invoice.php';           // Phase 4: HTML proforma invoice issuance (basic version, not yet wired to the UI button)
require_once AHP_PLUGIN_DIR . 'includes/class-ahp-admin.php';             // Phase 5: restricted admin panel + settings

/*
 * Files for later phases (commented out until each phase is built and added):
 */

/* ── Auto-update via GitHub Releases ───────────────────────────────────── */
// Uses the Plugin Update Checker library (bundled in /plugin-update-checker).
// Whenever a new "Release" is published on the GitHub repo below, WordPress
// will show an "Update available" notice for this plugin, same as any
// plugin from wordpress.org.

require_once AHP_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5p7\PucFactory;

$ahpUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/moeinoa/ahan-proforma/', // GitHub repo URL
    __FILE__,
    'ahan-proforma'
);

// Tell it to look at GitHub "Releases" (tagged versions) rather than
// just the latest commit on the branch.
$ahpUpdateChecker->getVcsApi()->enableReleaseAssets();
$ahpUpdateChecker->setAuthentication( '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' );
/* ── Install/upgrade ───────────────────────────────────────────────────── */

register_activation_hook( __FILE__, function () {
    AHP_DB::install();
    AHP_Roles::install();
} );

register_deactivation_hook( __FILE__, function () {
    // Roles and tables are intentionally NOT removed on deactivation, to avoid data loss.
    // Full removal only happens via uninstall.php and on the admin's explicit request.
} );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'ahan-proforma', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    AHP_Table_Integration::init();
    AHP_Cart::init();
    AHP_Auth::init();
    AHP_Roles::register_admin_restrictions();
    AHP_User_Fields::init();
    AHP_Account::init();
    AHP_Invoice::init();
    AHP_Admin::init();

    // Check for a database version upgrade (so later plugin updates apply without a manual deactivate/reactivate)
    if ( get_option( 'ahp_db_version' ) !== AHP_DB_VERSION ) {
        AHP_DB::install();
        update_option( 'ahp_db_version', AHP_DB_VERSION );
    }
    if ( get_option( 'ahp_roles_version' ) !== AHP_ROLES_VERSION ) {
        AHP_Roles::install();
        update_option( 'ahp_roles_version', AHP_ROLES_VERSION );
    }
} );

/* ── Enqueue the price-table button styles ─────────────────────────────── */

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'ahp-table',
        AHP_PLUGIN_URL . 'assets/css/ahp-table.css',
        [],
        AHP_VERSION
    );
} );

/* ── Admin notice if the price-table plugin is missing (optional, informational only) ── */

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( class_exists( 'APT_Shortcode' ) ) return;
    if ( ! function_exists( 'get_current_screen' ) ) return;

    echo '<div class="notice notice-warning"><p>' .
        esc_html__( 'The "Ahan Price Tables" plugin was not found or is inactive. The proforma plugin still works without it, but the "Add to invoice" button will not appear on price tables.', 'ahan-proforma' ) .
        '</p></div>';
} );

/*
 * Phase 3/5: SMS gateway (Farapayamak) credentials are stored via the
 * plugin's settings panel (Ahan Proforma ▸ Settings) in wp_options.
 * wp-config.php constants are only an optional override for specific
 * technical cases.
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( get_option( 'ahp_sms_username' ) || defined( 'AHP_SMS_USERNAME' ) ) return;

    $settings_url = admin_url( 'admin.php?page=ahp-settings' );
    echo '<div class="notice notice-warning"><p>' .
        sprintf(
            /* translators: %s: settings page URL */
            esc_html__( 'The SMS gateway (Farapayamak) is not configured yet; customer OTP login will not work. Go to %s to set it up.', 'ahan-proforma' ),
            '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Ahan Proforma ▸ Settings', 'ahan-proforma' ) . '</a>'
        ) .
        '</p></div>';
} );
