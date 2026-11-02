<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * Free and Pro share options, post meta and feed files. When the other edition is still
 * installed, only the scheduled actions are removed and the shared data is left for it.
 *
 * Post meta (`mrkvuamp_*`) is kept on purpose: it is per-product content the shop owner typed.
 *
 * @package MrkvUAMP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'mrkv-uamp' );
}
foreach ( array( 'mrkvuamp_update_xml_hook', 'mrkvuamp_update_xml_hook_promua', 'mrkvuamp_partial_update_xml_hook_promua', 'mrkvuamp_partial_update_xml_hook_rozetka' ) as $mrkv_uamp_hook ) {
	wp_clear_scheduled_hook( $mrkv_uamp_hook );
}

$mrkv_uamp_other = basename( __DIR__ ) === 'ua-marketplaces-pro' ? 'ua-marketplace/morkvawrs-plugin.php' : 'ua-marketplaces-pro/ua-marketplaces-pro.php';
if ( file_exists( WP_PLUGIN_DIR . '/' . $mrkv_uamp_other ) ) {
	return;
}

global $wpdb;

// Feed files: every feed's XML and leftover temporary files, then the folder if it is empty.
$mrkv_uamp_feeds   = get_option( 'mrkv_uamp_feeds', array() );
$mrkv_uamp_uploads = wp_upload_dir( null, false );
$mrkv_uamp_dirs    = array( 'uamrktpls' => true );
foreach ( is_array( $mrkv_uamp_feeds ) ? $mrkv_uamp_feeds : array() as $mrkv_uamp_feed ) {
	if ( empty( $mrkv_uamp_feed['dir'] ) || empty( $mrkv_uamp_feed['filename'] ) ) {
		continue;
	}
	$mrkv_uamp_dir = str_replace( array( '..', "\0" ), '', (string) $mrkv_uamp_feed['dir'] );
	$mrkv_uamp_dirs[ $mrkv_uamp_dir ] = true;
	$mrkv_uamp_path = trailingslashit( $mrkv_uamp_uploads['basedir'] ) . $mrkv_uamp_dir . '/';
	$mrkv_uamp_name = str_replace( array( '/', '\\', "\0" ), '', (string) $mrkv_uamp_feed['filename'] );
	foreach ( array_merge( array( $mrkv_uamp_path . $mrkv_uamp_name . '.xml' ), (array) glob( $mrkv_uamp_path . '.' . $mrkv_uamp_name . '.*.part' ) ) as $mrkv_uamp_file ) {
		if ( is_string( $mrkv_uamp_file ) && is_file( $mrkv_uamp_file ) ) {
			wp_delete_file( $mrkv_uamp_file );
		}
	}
}
foreach ( array_keys( $mrkv_uamp_dirs ) as $mrkv_uamp_dir ) {
	$mrkv_uamp_path = trailingslashit( $mrkv_uamp_uploads['basedir'] ) . $mrkv_uamp_dir . '/';
	foreach ( array( 'index.php', '.htaccess' ) as $mrkv_uamp_file ) {
		if ( is_file( $mrkv_uamp_path . $mrkv_uamp_file ) ) {
			wp_delete_file( $mrkv_uamp_path . $mrkv_uamp_file );
		}
	}
	if ( is_dir( $mrkv_uamp_path ) && 2 === count( (array) scandir( $mrkv_uamp_path ) ) ) {
		@rmdir( $mrkv_uamp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mrkv_uamp_rozetka_categories" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'mrkv_uamrkpl_', 'mrkv_uamp_' ) as $mrkv_uamp_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $mrkv_uamp_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
delete_option( 'mrkv_ua_marketplaces' );
delete_transient( 'mrkv_uamp_schedule_ok' );
delete_transient( 'mrkv_uamp_rozetka_cats_error' );
