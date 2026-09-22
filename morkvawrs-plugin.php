<?php
/**
 * @link              https://morkva.co.ua/shop-2/woocommerce-rozetka-sync
 * @since             0.0.3
 * @package           MrkvUAMmarketplaces
 *
 * @wordpress-plugin
 * Plugin Name:       UA Marketplace
 * Plugin URI:        https://morkva.co.ua/shop-2/woocommerce-rozetka-sync
 * Description:       Забезпечує взаїмодію WooCommerce інетернет-магазину з маркетплейсами Rozetka та PromUA.
 * Version:           1.4.20
 * Author:            MORKVA
 * Author URI:        https://morkva.co.ua
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mrkv-ua-marketplaces
 * Domain Path:       /languages
 * WC requires at least: 3.8
 * WC tested up to: 9.8
 * Tested up to: 7.1
 */

 // If this file is called directly, abort.
 defined( 'ABSPATH' ) or die();

 add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// If this file is called directly, abort.
defined( 'ABSPATH' ) or die();

// Require once the Composer Autoload
if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

/**
 * The code that runs during plugin activation
 */
function activate_ua_marketplace_plugin() {
	Inc\Base\Activate::activate();
}
register_activation_hook( __FILE__, 'activate_ua_marketplace_plugin' );

/**
 * The code that runs during plugin deactivation
 */
function deactivate_ua_marketplace_plugin() {
	Inc\Base\Deactivate::deactivate();
}
register_deactivation_hook( __FILE__, 'deactivate_ua_marketplace_plugin' );

/**
 * Initialize all the core classes of the plugin
 */
if ( class_exists( 'Inc\\Init' ) ) {
	Inc\Init::register_services();
}
