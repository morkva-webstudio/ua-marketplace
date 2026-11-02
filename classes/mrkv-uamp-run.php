<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

foreach ( array(
	'core/mrkv-uamp-feeds.php',
	'core/mrkv-uamp-lock.php',
	'core/mrkv-uamp-log.php',
	'rozetka/mrkv-uamp-rozetka-directory.php',
	'feeds/mrkv-uamp-mapper.php',
	'feeds/mrkv-uamp-rozetka-mapper.php',
	'feeds/mrkv-uamp-prom-mapper.php',
	'feeds/mrkv-uamp-xml.php',
	'feeds/mrkv-uamp-builder.php',
	'feeds/mrkv-uamp-scheduler.php',
	'migration/mrkv-uamp-migration.php',
) as $mrkv_uamp_file ) {
	require_once __DIR__ . '/' . $mrkv_uamp_file;
}

if ( ! class_exists( 'MRKV_UAMP_RUN' ) ) {
	/** Wires the plugin together once WooCommerce is loaded. */
	class MRKV_UAMP_RUN {
		/** @var MRKV_UAMP_RUN|null */
		private static $instance;

		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			( new MRKV_UAMP_SCHEDULER() )->register();

			// Not on action_scheduler_init: that fires before WooCommerce registers product_cat /
			// product_type (init 5), and the feed queries would find nothing.
			add_action( 'wp_loaded', array( 'MRKV_UAMP_MIGRATION', 'maybe_run' ) );

			if ( is_admin() ) {
				foreach ( array( 'mrkv-uamp-settings.php', 'mrkv-uamp-mapping-ui.php', 'mrkv-uamp-sidebar.php', 'mrkv-uamp-admin.php', 'mrkv-uamp-product-fields.php' ) as $file ) {
					require_once __DIR__ . '/admin/' . $file;
				}
				( new MRKV_UAMP_ADMIN() )->register();
				( new MRKV_UAMP_PRODUCT_FIELDS() )->register();
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once __DIR__ . '/feeds/mrkv-uamp-cli.php';
				WP_CLI::add_command( 'mrkv-uamp', 'MRKV_UAMP_CLI' );
			}

			do_action( 'mrkv_uamp_loaded' );
		}

		public static function activate(): void {
			MRKV_UAMP_ROZETKA_DIRECTORY::install();
			// The migration itself runs on the next request, once Action Scheduler is up.
			delete_transient( 'mrkv_uamp_schedule_ok' );
		}

		public static function deactivate(): void {
			MRKV_UAMP_SCHEDULER::unschedule_all();
			foreach ( MRKV_UAMP_MIGRATION::LEGACY_HOOKS as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}
}
