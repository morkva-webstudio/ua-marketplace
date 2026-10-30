<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_FEEDS' ) ) {
	/**
	 * The feed registry: one feed per marketplace, stored in the `mrkv_uamp_feeds` option.
	 *
	 * A feed entry only says where the file lives and when it is rebuilt. Marketplace settings
	 * (shop name, vendor, category mapping…) stay in the 1.x `mrkv_uamrkpl_*` options, so the
	 * marketplace tabs and the offer mappers read exactly what 1.x stored.
	 *
	 * Build progress lives separately in `mrkv_uamp_feed_state_{slug}` (not autoloaded), because
	 * it is rewritten after every batch.
	 */
	class MRKV_UAMP_FEEDS {
		const OPTION       = 'mrkv_uamp_feeds';
		const STATE_PREFIX = 'mrkv_uamp_feed_state_';
		/** 1.x default folder under uploads, shared by both feeds. */
		const DEFAULT_DIR  = 'uamrktpls';
		const DEFAULT_STEP = 200;

		/** Marketplace slug => label and the 1.x default file name (without .xml). */
		public static function marketplaces(): array {
			return array(
				'rozetka' => array(
					'label'    => 'Rozetka',
					'filename' => 'mrkvuamprozetka',
				),
				'promua'  => array(
					'label'    => 'Prom.ua',
					'filename' => 'mrkvuamppromua',
				),
			);
		}

		public static function is_marketplace( string $slug ): bool {
			return isset( self::marketplaces()[ $slug ] );
		}

		/** Schedules the plugin offers; Free only rebuilds daily. */
		public static function schedules(): array {
			$all = array(
				'hourly'     => HOUR_IN_SECONDS,
				'fourhour'   => 4 * HOUR_IN_SECONDS,
				'twicedaily' => 12 * HOUR_IN_SECONDS,
				'daily'      => DAY_IN_SECONDS,
			);
			return MRKV_UAMP_IS_PRO ? $all : array( 'daily' => DAY_IN_SECONDS );
		}

		public static function schedule_interval( string $schedule ): int {
			$schedules = self::schedules();
			return $schedules[ $schedule ] ?? DAY_IN_SECONDS;
		}

		/** @return array<string, array> slug => feed entry, only for feeds that exist. */
		public static function all(): array {
			$feeds = get_option( self::OPTION, array() );
			if ( ! is_array( $feeds ) ) {
				return array();
			}
			$out = array();
			foreach ( $feeds as $slug => $feed ) {
				if ( self::is_marketplace( (string) $slug ) && is_array( $feed ) ) {
					$out[ $slug ] = self::normalize( (string) $slug, $feed );
				}
			}
			return $out;
		}

		public static function get( string $slug ): ?array {
			$feeds = self::all();
			return $feeds[ $slug ] ?? null;
		}

		public static function defaults( string $slug ): array {
			$marketplaces = self::marketplaces();
			return array(
				'status'   => 'active',
				'dir'      => self::DEFAULT_DIR,
				'filename' => $marketplaces[ $slug ]['filename'] ?? $slug,
				'schedule' => 'daily',
				'step'     => self::DEFAULT_STEP,
				'created'  => time(),
			);
		}

		/** Fills missing keys and clamps values a stale or hand-edited option could hold. */
		public static function normalize( string $slug, array $feed ): array {
			$feed             = array_merge( self::defaults( $slug ), $feed );
			$feed['status']   = 'paused' === $feed['status'] ? 'paused' : 'active';
			$feed['dir']      = self::sanitize_dir( (string) $feed['dir'] );
			$feed['filename'] = self::sanitize_filename( (string) $feed['filename'] ) ?: self::defaults( $slug )['filename'];
			$feed['step']     = max( 1, min( 5000, (int) $feed['step'] ) );
			if ( ! isset( self::schedules()[ $feed['schedule'] ] ) ) {
				$feed['schedule'] = 'daily';
			}
			return $feed;
		}

		public static function save( string $slug, array $feed ): void {
			$feeds          = self::all();
			$feeds[ $slug ] = self::normalize( $slug, $feed );
			update_option( self::OPTION, $feeds, true );
		}

		/** Creates the feed with defaults; returns false if it already exists. */
		public static function add( string $slug ): bool {
			if ( ! self::is_marketplace( $slug ) || self::get( $slug ) ) {
				return false;
			}
			self::save( $slug, self::defaults( $slug ) );
			return true;
		}

		public static function delete( string $slug ): void {
			$feeds = self::all();
			unset( $feeds[ $slug ] );
			update_option( self::OPTION, $feeds, true );
			delete_option( self::STATE_PREFIX . $slug );
		}

		/**
		 * Folder under uploads, as stored. 1.x saved whatever the user typed and used it raw, and a
		 * feed URL must not change on upgrade, so stored values are only made safe (no traversal,
		 * no control characters) — not narrowed to a character set. New input goes through
		 * clean_dir_input() instead.
		 */
		public static function sanitize_dir( string $dir ): string {
			$parts = array();
			foreach ( explode( '/', str_replace( '\\', '/', $dir ) ) as $part ) {
				$part = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $part ) );
				if ( '' !== $part && '.' !== $part && '..' !== $part ) {
					$parts[] = $part;
				}
			}
			return $parts ? implode( '/', $parts ) : self::DEFAULT_DIR;
		}

		/** File name without extension, as stored; see sanitize_dir() for why it is not narrowed. */
		public static function sanitize_filename( string $name ): string {
			$name = (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\]/', '', trim( $name ) );
			$name = (string) preg_replace( '/\.xml$/i', '', $name );
			return '.' === $name || '..' === $name ? '' : $name;
		}

		/** New folder typed in the admin: path segments of [A-Za-z0-9_-]. */
		public static function clean_dir_input( string $dir ): string {
			$parts = array();
			foreach ( explode( '/', str_replace( '\\', '/', $dir ) ) as $part ) {
				$part = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $part );
				if ( '' !== $part ) {
					$parts[] = $part;
				}
			}
			return $parts ? implode( '/', $parts ) : self::DEFAULT_DIR;
		}

		/** New file name typed in the admin: [A-Za-z0-9_-]. */
		public static function clean_filename_input( string $name ): string {
			$name = (string) preg_replace( '/\.xml$/i', '', trim( $name ) );
			return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $name );
		}

		public static function dir_path( array $feed ): string {
			$uploads = wp_upload_dir( null, false );
			return trailingslashit( $uploads['basedir'] ) . $feed['dir'] . '/';
		}

		public static function file_path( array $feed ): string {
			return self::dir_path( $feed ) . $feed['filename'] . '.xml';
		}

		public static function file_url( array $feed ): string {
			$uploads = wp_upload_dir( null, false );
			return trailingslashit( $uploads['baseurl'] ) . $feed['dir'] . '/' . $feed['filename'] . '.xml';
		}

		/**
		 * Creates the feed folder. Only temporary `.part` files are denied over HTTP: the XML
		 * itself must stay public, the marketplaces download it.
		 */
		public static function ensure_dir( array $feed ): bool {
			$dir = self::dir_path( $feed );
			if ( ! wp_mkdir_p( $dir ) ) {
				return false;
			}
			if ( ! file_exists( $dir . 'index.php' ) ) {
				@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
			if ( ! file_exists( $dir . '.htaccess' ) ) {
				@file_put_contents( $dir . '.htaccess', "<FilesMatch \"\\.part$\">\n\tRequire all denied\n\t<IfModule !mod_authz_core.c>\n\t\tDeny from all\n\t</IfModule>\n</FilesMatch>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
			return true;
		}

		public static function state_defaults(): array {
			return array(
				'run_id'        => '',
				'cursor'        => 0,
				'offers'        => 0,
				'products'      => 0,
				'total'         => 0,
				'started'       => 0,
				'last_built'    => 0,
				'last_duration' => 0,
				'last_offers'   => 0,
				'last_error'    => '',
				'last_error_at' => 0,
			);
		}

		public static function get_state( string $slug ): array {
			$state = get_option( self::STATE_PREFIX . $slug, array() );
			return array_merge( self::state_defaults(), is_array( $state ) ? $state : array() );
		}

		public static function save_state( string $slug, array $state ): void {
			update_option( self::STATE_PREFIX . $slug, array_merge( self::state_defaults(), $state ), false );
		}

		public static function is_running( string $slug ): bool {
			return '' !== self::get_state( $slug )['run_id'];
		}
	}
}
