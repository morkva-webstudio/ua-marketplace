<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_ROZETKA_DIRECTORY' ) ) {
	/**
	 * Local copy of the Rozetka category directory (morkva-hosted JSON), one row per category.
	 *
	 * A table rather than an option: thousands of rows, searched by name from the mapping picker.
	 * Feed builds read names from here, so a build never makes an HTTP request (1.x fetched the
	 * whole JSON once per mapped category on every build).
	 */
	class MRKV_UAMP_ROZETKA_DIRECTORY {
		const DB_VERSION        = '1';
		const DB_VERSION_OPTION = 'mrkv_uamp_rozetka_cats_db';

		public static function table(): string {
			global $wpdb;
			return $wpdb->prefix . 'mrkv_uamp_rozetka_categories';
		}

		public static function install(): void {
			if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
				return;
			}
			global $wpdb;
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$table   = self::table();
			$charset = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE {$table} (
				rz_id bigint(20) unsigned NOT NULL,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				name varchar(255) NOT NULL DEFAULT '',
				path text NOT NULL,
				PRIMARY KEY  (rz_id),
				KEY name (name(191))
			) {$charset};" );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}

		// Custom table: direct queries are the only way in; the table name goes through %i (WP 6.2+).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery

		public static function drop(): void {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall only
			delete_option( self::DB_VERSION_OPTION );
		}

		public static function ready(): bool {
			return self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
		}

		public static function count(): int {
			global $wpdb;
			return self::ready() ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) ) : 0;
		}

		/**
		 * Picker search: by id when the term is a number, otherwise by name. Deeper categories
		 * (longer path) come first: those are the ones products are mapped to.
		 *
		 * @return array[] array( 'id' => int, 'name' => string, 'path' => string )
		 */
		public static function search( string $term, int $limit = 30 ): array {
			global $wpdb;
			$term = trim( $term );
			if ( '' === $term || ! self::ready() ) {
				return array();
			}
			if ( ctype_digit( $term ) ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT rz_id, name, path FROM %i WHERE rz_id = %d', self::table(), (int) $term ), ARRAY_A );
			} else {
				$like = '%' . $wpdb->esc_like( $term ) . '%';
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT rz_id, name, path FROM %i WHERE name LIKE %s OR path LIKE %s ORDER BY (name LIKE %s) DESC, CHAR_LENGTH(path) DESC LIMIT %d', self::table(), $like, $like, $like, $limit ), ARRAY_A );
			}
			$out = array();
			foreach ( (array) $rows as $row ) {
				$out[] = array(
					'id'   => (int) $row['rz_id'],
					'name' => (string) $row['name'],
					'path' => (string) ( $row['path'] ?: $row['name'] ),
				);
			}
			return $out;
		}

		/**
		 * Display paths ("Дім › Кухня › Посуд") for the given ids, in one query.
		 *
		 * @return array<int, string>
		 */
		public static function paths( array $ids ): array {
			$paths = array();
			foreach ( self::rows( $ids ) as $id => $row ) {
				$paths[ $id ] = (string) ( $row['path'] ?: $row['name'] );
			}
			return $paths;
		}

		/**
		 * Names for the given Rozetka ids, in one query.
		 *
		 * @param int[] $ids
		 * @return array<int, string> rz_id => name; ids not in the directory are left out
		 */
		public static function names( array $ids ): array {
			return array_map( function ( $row ) {
				return (string) $row['name'];
			}, self::rows( $ids ) );
		}

		/** @return array<int, array> rz_id => row */
		private static function rows( array $ids ): array {
			global $wpdb;
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			if ( ! $ids || ! self::ready() ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT rz_id, name, path FROM %i WHERE rz_id IN ({$placeholders})", array_merge( array( self::table() ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- %d placeholders only
			$out          = array();
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['rz_id'] ] = $row;
			}
			return $out;
		}

		// phpcs:enable
	}
}
