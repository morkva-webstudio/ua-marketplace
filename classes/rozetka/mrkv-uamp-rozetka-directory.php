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
		 * Picker search: by id when the term is a number, otherwise by name or path. Best name
		 * matches first (exact, starts with, contains), then the shorter path: the general
		 * "Товари для дому › Посуд › Чашки" before the same name under a retail chain's branch.
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
				$like   = '%' . $wpdb->esc_like( $term ) . '%';
				$prefix = $wpdb->esc_like( $term ) . '%';
				$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT rz_id, name, path FROM %i WHERE name LIKE %s OR path LIKE %s ORDER BY (name = %s) DESC, (name LIKE %s) DESC, (name LIKE %s) DESC, CHAR_LENGTH(path) ASC LIMIT %d', self::table(), $like, $like, $term, $prefix, $like, $limit ), ARRAY_A );
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

		/**
		 * Replaces the whole directory in one transaction: the picker and running builds see either
		 * the old rows or the new ones (InnoDB), never a half-filled table.
		 *
		 * @param array<int, array> $rows rz_id => array( 'parent_id' => int, 'name' => string, 'path' => string )
		 */
		public static function replace( array $rows, int $chunk = 500 ): bool {
			global $wpdb;
			if ( ! self::ready() ) {
				return false;
			}
			// DELETE, not TRUNCATE: TRUNCATE commits on its own and would empty the table for readers.
			$wpdb->query( 'START TRANSACTION' );
			$ok = false !== $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
			foreach ( array_chunk( $rows, $chunk, true ) as $part ) {
				if ( ! $ok ) {
					break;
				}
				$values = array( self::table() );
				foreach ( $part as $rz_id => $row ) {
					array_push( $values, (int) $rz_id, (int) $row['parent_id'], (string) $row['name'], (string) $row['path'] );
				}
				$placeholders = implode( ',', array_fill( 0, count( $part ), '(%d,%d,%s,%s)' ) );
				$ok           = false !== $wpdb->query( $wpdb->prepare( "INSERT INTO %i (rz_id, parent_id, name, path) VALUES {$placeholders}", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only
			}
			if ( $ok ) {
				$wpdb->query( 'COMMIT' );
			} else {
				$wpdb->query( 'ROLLBACK' );
			}
			return $ok;
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
