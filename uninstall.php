<?php
/**
 * Runs only when the admin clicks "Delete" for the plugin from the
 * WordPress dashboard (not on a simple deactivation). Per the WordPress
 * standard, WordPress directly requires this exact file and it isn't
 * called via the Plugins menu unless the constant below is defined.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// This deletion is irreversible. It's deliberately placed behind a settings
// option (ahp_delete_data_on_uninstall) so accidental data loss doesn't happen; default: false.
if ( get_option( 'ahp_delete_data_on_uninstall' ) !== '1' ) {
    return;
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ahp_invoice_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ahp_invoices" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ahp_otp_requests" );

delete_option( 'ahp_db_version' );
delete_option( 'ahp_roles_version' );
delete_option( 'ahp_invoice_seq' );
delete_option( 'ahp_delete_data_on_uninstall' );

remove_role( 'ahan_customer' );
remove_role( 'ahan_invoice_manager' );
