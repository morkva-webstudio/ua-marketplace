<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_ROZETKA_IMPORTER' ) ) {
	/**
	 * Keeps the local Rozetka category directory in line with the morkva-hosted file.
	 *
	 * - Weekly (Action Scheduler, only while a Rozetka feed exists) and on "Refresh" it reads
	 *   `categories.meta.json` ({version, count, sha256}) and downloads `categories.json` only when
	 *   the version changed. Without a meta file it asks for the JSON with If-None-Match.
	 * - The file is checked (checksum, row count) before it replaces the table, in one transaction
	 *   with multi-row inserts: ~6k categories take a few seconds, well inside a 30-second request.
	 * - A failure is kept for an hour: the weekly check does not retry before that, the Rozetka
	 *   tab shows the message, and "Refresh" always tries again.
	 *
	 * Rows of the source: {"id": row, "name": "…", "category_id": rz_id, "parent_id": rz_id|0}.
	 */
	class MRKV_UAMP_ROZETKA_IMPORTER {
		const HOOK            = 'mrkv_uamp_rozetka_directory';
		const SOURCE_URL      = 'https://api.morkva.co.ua/morkvafiles/woo-rozetka/categories.json';
		const META_URL        = 'https://api.morkva.co.ua/morkvafiles/woo-rozetka/categories.meta.json';
		const META_OPTION     = 'mrkv_uamp_rozetka_cats_meta';
		const ERROR_TRANSIENT = 'mrkv_uamp_rozetka_cats_error';
		const INTERVAL        = WEEK_IN_SECONDS;
		/** A file with fewer rows is broken or truncated and never replaces the directory. */
		const MIN_ROWS        = 1000;
		const SEPARATOR       = ' › ';

		public function register(): void {
			add_action( self::HOOK, array( __CLASS__, 'check' ) );
		}

		/**
		 * @param bool $force true from "Refresh": download even when the version is the same and
		 *                    ignore a cached error.
		 * @return string updated|current|busy|skipped|error
		 */
		public static function check( $force = false ): string {
			$force = (bool) $force;
			if ( ! $force && get_transient( self::ERROR_TRANSIENT ) ) {
				return 'skipped';
			}
			if ( ! MRKV_UAMP_LOCK::acquire( 'rozetka_directory', 300 ) ) {
				return 'busy';
			}
			try {
				return self::import( $force );
			} finally {
				MRKV_UAMP_LOCK::release( 'rozetka_directory' );
			}
		}

		private static function import( bool $force ): string {
			MRKV_UAMP_ROZETKA_DIRECTORY::install();
			$stored = self::meta();
			$have   = MRKV_UAMP_ROZETKA_DIRECTORY::count() > 0;
			$remote = self::remote_meta();

			if ( ! $force && $have && $remote && $remote['version'] === $stored['version'] ) {
				self::save_meta( array_merge( $stored, array( 'checked_at' => time() ) ) );
				return 'current';
			}

			$headers = array();
			if ( ! $force && $have && ! $remote && '' !== $stored['etag'] ) {
				$headers['If-None-Match'] = $stored['etag'];
			}
			$response = wp_safe_remote_get( self::SOURCE_URL, array( 'timeout' => 30, 'headers' => $headers ) );
			if ( is_wp_error( $response ) ) {
				return self::fail( $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 304 === $code ) {
				self::save_meta( array_merge( $stored, array( 'checked_at' => time() ) ) );
				return 'current';
			}
			if ( 200 !== $code ) {
				/* translators: %d: HTTP status code */
				return self::fail( sprintf( __( 'the server answered with HTTP %d', 'ua-marketplace' ), $code ) );
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( $remote && '' !== $remote['sha256'] && ! hash_equals( $remote['sha256'], hash( 'sha256', $body ) ) ) {
				return self::fail( __( 'the downloaded file does not match its checksum', 'ua-marketplace' ) );
			}
			$rows = self::parse( $body );
			if ( count( $rows ) < self::MIN_ROWS ) {
				/* translators: %d: number of categories in the file */
				return self::fail( sprintf( __( 'the file has only %d valid categories', 'ua-marketplace' ), count( $rows ) ) );
			}
			if ( ! MRKV_UAMP_ROZETKA_DIRECTORY::replace( $rows ) ) {
				return self::fail( __( 'the categories could not be saved to the database', 'ua-marketplace' ) );
			}

			$etag = (string) wp_remote_retrieve_header( $response, 'etag' );
			self::save_meta( array(
				'version'    => $remote ? $remote['version'] : $etag,
				'etag'       => $etag,
				'count'      => count( $rows ),
				'fetched_at' => time(),
				'checked_at' => time(),
			) );
			delete_transient( self::ERROR_TRANSIENT );
			MRKV_UAMP_LOG::info( sprintf( 'Rozetka category directory updated: %d categories.', count( $rows ) ) );
			return 'updated';
		}

		/**
		 * Valid rows of the source file with their display paths ("Дім › Кухня › Посуд").
		 * A parent missing from the file ends the path there; a loop in the parents is cut.
		 *
		 * @return array<int, array> rz_id => array( 'parent_id', 'name', 'path' )
		 */
		public static function parse( string $body ): array {
			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				return array();
			}
			$rows = array();
			foreach ( $data as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$id   = isset( $item['category_id'] ) && is_numeric( $item['category_id'] ) ? (int) $item['category_id'] : 0;
				$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? trim( wp_strip_all_tags( (string) $item['name'] ) ) : '';
				if ( $id <= 0 || '' === $name ) {
					continue;
				}
				$parent      = isset( $item['parent_id'] ) && is_numeric( $item['parent_id'] ) ? (int) $item['parent_id'] : 0;
				$rows[ $id ] = array(
					'parent_id' => $parent > 0 && $parent !== $id ? $parent : 0,
					'name'      => mb_substr( $name, 0, 255 ),
				);
			}
			foreach ( $rows as $id => $row ) {
				$names = array( $row['name'] );
				$seen  = array( $id => true );
				$next  = $row['parent_id'];
				while ( $next && isset( $rows[ $next ] ) && ! isset( $seen[ $next ] ) ) {
					$seen[ $next ] = true;
					array_unshift( $names, $rows[ $next ]['name'] );
					$next = $rows[ $next ]['parent_id'];
				}
				$rows[ $id ]['path'] = implode( self::SEPARATOR, $names );
			}
			return $rows;
		}

		/** @return array{version:string, sha256:string, count:int}|null null when there is no usable meta file */
		private static function remote_meta(): ?array {
			$response = wp_safe_remote_get( self::META_URL, array( 'timeout' => 10 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$meta = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $meta ) || empty( $meta['version'] ) || ! is_scalar( $meta['version'] ) ) {
				return null;
			}
			return array(
				'version' => (string) $meta['version'],
				'sha256'  => isset( $meta['sha256'] ) && is_string( $meta['sha256'] ) ? strtolower( $meta['sha256'] ) : '',
				'count'   => isset( $meta['count'] ) ? (int) $meta['count'] : 0,
			);
		}

		/** @return array{version:string, etag:string, count:int, fetched_at:int, checked_at:int} */
		public static function meta(): array {
			$meta = get_option( self::META_OPTION, array() );
			$meta = is_array( $meta ) ? $meta : array();
			return array(
				'version'    => (string) ( $meta['version'] ?? '' ),
				'etag'       => (string) ( $meta['etag'] ?? '' ),
				'count'      => (int) ( $meta['count'] ?? 0 ),
				'fetched_at' => (int) ( $meta['fetched_at'] ?? 0 ),
				'checked_at' => (int) ( $meta['checked_at'] ?? 0 ),
			);
		}

		private static function save_meta( array $meta ): void {
			update_option( self::META_OPTION, $meta, false );
		}

		/** The last failure, if it is less than an hour old. */
		public static function last_error(): string {
			return (string) get_transient( self::ERROR_TRANSIENT );
		}

		private static function fail( string $message ): string {
			set_transient( self::ERROR_TRANSIENT, $message, HOUR_IN_SECONDS );
			MRKV_UAMP_LOG::error( 'Rozetka category directory: ' . $message );
			// With nothing loaded yet, a week until the next check is too long: try again in an hour.
			// Own args, so the pending weekly action does not count as a duplicate.
			if ( ! MRKV_UAMP_ROZETKA_DIRECTORY::count() && ! as_has_scheduled_action( self::HOOK, array( false ), MRKV_UAMP_SCHEDULER::GROUP ) ) {
				as_schedule_single_action( time() + HOUR_IN_SECONDS, self::HOOK, array( false ), MRKV_UAMP_SCHEDULER::GROUP );
			}
			return 'error';
		}

		/**
		 * Weekly check while a Rozetka feed exists; the first one runs right away, so a new feed
		 * (or a site upgraded from 1.x) gets the directory without a click.
		 */
		public static function ensure_schedule( bool $needed ): void {
			$next = as_next_scheduled_action( self::HOOK, array(), MRKV_UAMP_SCHEDULER::GROUP );
			if ( $needed && false === $next ) {
				as_schedule_recurring_action( time(), self::INTERVAL, self::HOOK, array(), MRKV_UAMP_SCHEDULER::GROUP, true );
			} elseif ( ! $needed && false !== $next ) {
				as_unschedule_all_actions( self::HOOK, array(), MRKV_UAMP_SCHEDULER::GROUP );
			}
		}
	}
}
