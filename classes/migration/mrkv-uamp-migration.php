<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_MIGRATION' ) ) {
	/**
	 * Upgrade from 1.x (Free 1.4.x and Pro 1.5–1.6 share one data schema) to 2.0.
	 *
	 * Turns the 1.x activation checkboxes into feed entries whose file path is exactly the one 1.x
	 * wrote — the marketplaces keep polling the same URL — and removes the 1.x WP-Cron jobs and
	 * temporary files. The 1.x options themselves are kept: the 2.0 settings read the same keys, and
	 * a rollback to 1.x still works.
	 */
	class MRKV_UAMP_MIGRATION {
		const DB_VERSION_OPTION = 'mrkv_uamp_db_version';
		const NOTICE_OPTION     = 'mrkv_uamp_migration_notice';
		const DB_VERSION        = '2.0.0';

		const LEGACY_HOOKS = array(
			'mrkvuamp_update_xml_hook',
			'mrkvuamp_update_xml_hook_promua',
			'mrkvuamp_partial_update_xml_hook_promua',
			'mrkvuamp_partial_update_xml_hook_rozetka',
		);

		public static function needed(): bool {
			return version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), self::DB_VERSION, '<' );
		}

		public static function maybe_run(): void {
			if ( ! self::needed() || ! MRKV_UAMP_LOCK::acquire( 'migration', 120 ) ) {
				return;
			}
			try {
				if ( self::needed() ) {
					self::run();
				}
			} finally {
				MRKV_UAMP_LOCK::release( 'migration' );
			}
		}

		/** @return string[] slugs of the feeds created from 1.x settings */
		public static function run(): array {
			MRKV_UAMP_ROZETKA_DIRECTORY::install();

			$created = array();
			if ( false === get_option( MRKV_UAMP_FEEDS::OPTION ) ) {
				$feeds = self::feeds_from_legacy();
				update_option( MRKV_UAMP_FEEDS::OPTION, $feeds, true );
				$created = array_keys( $feeds );
			}

			foreach ( self::LEGACY_HOOKS as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			self::delete_legacy_temp_files();

			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
			if ( $created ) {
				update_option( self::NOTICE_OPTION, $created, false );
				// Rebuild with 2.0 right away so a problem shows up now, not at the next daily run.
				// Queued rather than run here: this request may be a storefront page. The live 1.x
				// file stays in place until the new one is complete and valid.
				MRKV_UAMP_SCHEDULER::feeds_changed();
				foreach ( $created as $slug ) {
					as_enqueue_async_action( MRKV_UAMP_SCHEDULER::HOOK_REBUILD, array( $slug ), MRKV_UAMP_SCHEDULER::GROUP );
				}
			}
			return $created;
		}

		/**
		 * A feed is carried over when its 1.x checkbox was on, or when its file exists — the second
		 * covers sites that unticked the box but whose file a marketplace still polls.
		 */
		public static function feeds_from_legacy(): array {
			$activation = get_option( 'mrkv_ua_marketplaces', array() );
			$activation = is_array( $activation ) ? $activation : array();
			$dir        = (string) get_option( 'mrkv_uamrkpl_rozetka_xmlfile_dir' ); // 1.x used it for both feeds.
			$feeds      = array();

			foreach ( MRKV_UAMP_FEEDS::marketplaces() as $slug => $marketplace ) {
				$filename = (string) get_option( 'mrkv_uamrkpl_' . $slug . '_xml_filename' );
				$feed     = array_merge( MRKV_UAMP_FEEDS::defaults( $slug ), array(
					'dir'      => '' !== $dir ? $dir : MRKV_UAMP_FEEDS::DEFAULT_DIR,
					'filename' => '' !== $filename ? $filename : $marketplace['filename'],
				) );
				$feed     = MRKV_UAMP_FEEDS::normalize( $slug, $feed );

				$active = ! empty( $activation[ 'mrkvuamp_' . $slug . '_activation' ] );
				if ( ! $active && ! is_file( MRKV_UAMP_FEEDS::file_path( $feed ) ) ) {
					continue;
				}

				$schedule = (string) get_option( 'mrkv_uamrkpl_' . $slug . '_cron_scheduling' );
				if ( isset( MRKV_UAMP_FEEDS::schedules()[ $schedule ] ) ) {
					$feed['schedule'] = $schedule;
				}
				$step = (int) get_option( 'mrkv_uamrkpl_' . $slug . '_background_proc_xml_step' );
				if ( $step > 0 ) {
					$feed['step'] = $step;
				}
				$feeds[ $slug ] = MRKV_UAMP_FEEDS::normalize( $slug, $feed );
			}
			return $feeds;
		}

		/** tmp_*.xml and *_status.json left by an interrupted 1.x background build. */
		private static function delete_legacy_temp_files(): void {
			$dir     = (string) get_option( 'mrkv_uamrkpl_rozetka_xmlfile_dir' );
			$uploads = wp_upload_dir( null, false );
			$path    = trailingslashit( $uploads['basedir'] ) . MRKV_UAMP_FEEDS::sanitize_dir( '' !== $dir ? $dir : MRKV_UAMP_FEEDS::DEFAULT_DIR ) . '/';
			$files   = array_merge(
				(array) glob( $path . 'tmp_*.xml' ),
				(array) glob( $path . '*_status.json' )
			);
			foreach ( $files as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}
}
