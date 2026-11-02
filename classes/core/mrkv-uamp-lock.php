<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_LOCK' ) ) {
	// A mutex must hit the database itself, uncached.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery

	/**
	 * A row in wp_options used as a mutex, so a cron batch, a "Build now" click and a WP-CLI run
	 * never write the same feed at once.
	 *
	 * INSERT IGNORE is atomic, unlike add_option() (that one is INSERT … ON DUPLICATE KEY UPDATE).
	 * A lock older than its TTL belongs to a dead process and is taken over with a
	 * compare-and-swap on its old value.
	 */
	class MRKV_UAMP_LOCK {
		const PREFIX = 'mrkv_uamp_lock_';
		const TTL    = 600;

		public static function acquire( string $name, int $ttl = self::TTL ): bool {
			global $wpdb;
			$option = self::PREFIX . $name;
			$now    = time();

			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $now ) ) ) {
				return true;
			}
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			if ( null !== $held && (int) $held < $now - $ttl ) {
				return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $option, $held ) );
			}
			return false;
		}

		public static function release( string $name ): void {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::PREFIX . $name ) );
		}
	}
	// phpcs:enable
}
