<?php
/**
 * Plugin Name:       UA Marketplaces – Rozetka & Prom.ua XML Feeds for WooCommerce
 * Plugin URI:        https://morkva.co.ua/shop-2/woocommerce-rozetka-sync
 * Description:       XML feeds for Rozetka and Prom.ua from your WooCommerce catalog, generated in the background in small batches.
 * Version:           2.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            morkva
 * Author URI:        https://morkva.co.ua
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ua-marketplace
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package MrkvUAMP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pro is a standalone copy of this plugin with the same class names: with Pro active, Free loads nothing. */
define( 'MRKV_UAMP_FREE_PRO_BASENAME', 'ua-marketplaces-pro/ua-marketplaces-pro.php' );

function mrkv_uamp_free_is_pro_active() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( in_array( MRKV_UAMP_FREE_PRO_BASENAME, $active, true ) ) {
		return true;
	}
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		return isset( $network[ MRKV_UAMP_FREE_PRO_BASENAME ] );
	}
	return false;
}

if ( mrkv_uamp_free_is_pro_active() ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-info is-dismissible"><p>'
			. esc_html__( 'UA Marketplace (free) is idle because UA Marketplaces Pro is active. Your feeds and settings are handled by Pro; you can deactivate the free plugin.', 'ua-marketplace' )
			. '</p></div>';
	} );
	return;
}

define( 'MRKV_UAMP_VERSION', '2.0.0' );
define( 'MRKV_UAMP_FILE', __FILE__ );
define( 'MRKV_UAMP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRKV_UAMP_URL', plugin_dir_url( __FILE__ ) );
define( 'MRKV_UAMP_IS_PRO', false );
define( 'MRKV_UAMP_TEXT_DOMAIN', 'ua-marketplace' );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

require_once MRKV_UAMP_DIR . 'classes/mrkv-uamp-run.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	MRKV_UAMP_RUN::instance();
} );

add_action( 'init', function () {
	load_plugin_textdomain( 'ua-marketplace', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

register_activation_hook( __FILE__, array( 'MRKV_UAMP_RUN', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MRKV_UAMP_RUN', 'deactivate' ) );
