<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_SCHEDULER' ) ) {
	/**
	 * Action Scheduler (bundled with WooCommerce) drives the builds:
	 *
	 * - `mrkv_uamp_feed_rebuild` (recurring per active feed, at the feed's interval) starts a run;
	 * - `mrkv_uamp_feed_batch` (one pending action per feed) runs one batch and queues the next
	 *   until the run is done.
	 *
	 * Action Scheduler runs from WP-Cron, from a server crontab that calls wp-cron.php with
	 * DISABLE_WP_CRON, or from `wp action-scheduler run`; each batch stays under its time budget,
	 * so a 30-second limit is never reached.
	 */
	class MRKV_UAMP_SCHEDULER {
		const HOOK_REBUILD = 'mrkv_uamp_feed_rebuild';
		const HOOK_BATCH   = 'mrkv_uamp_feed_batch';
		const GROUP        = 'mrkv-uamp';
		/** A run with no progress for this long has lost its batch action; queue a new one. */
		const STALLED      = 15 * MINUTE_IN_SECONDS;

		public function register(): void {
			add_action( self::HOOK_REBUILD, array( __CLASS__, 'rebuild' ) );
			add_action( self::HOOK_BATCH, array( __CLASS__, 'run_batch' ) );
			add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedules' ) );
		}

		/** Starts a new run (unless one is going) and queues its first batch. */
		public static function rebuild( $slug ): void {
			$slug = (string) $slug;
			$feed = MRKV_UAMP_FEEDS::get( $slug );
			if ( ! $feed || 'active' !== $feed['status'] ) {
				return;
			}
			$result = MRKV_UAMP_BUILDER::start( $slug );
			if ( 'started' === $result || 'running' === $result ) {
				self::queue_batch( $slug );
			}
		}

		/** "Build now": restarts the run from scratch, whatever state it was in. */
		public static function build_now( string $slug ): string {
			$result = MRKV_UAMP_BUILDER::start( $slug, true );
			if ( 'started' === $result ) {
				self::queue_batch( $slug );
			}
			return $result;
		}

		public static function run_batch( $slug ): void {
			$slug   = (string) $slug;
			$result = MRKV_UAMP_BUILDER::batch( $slug );
			if ( 'more' === $result ) {
				self::queue_batch( $slug, true );
			} elseif ( 'busy' === $result ) {
				// Another process holds the feed (a CLI run or a stuck batch); look again shortly.
				// Not unique: this action is still "in progress" and would block a unique one.
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_BATCH, array( $slug ), self::GROUP );
			}
		}

		/**
		 * @param bool $from_batch true when called from inside the running batch action, which
		 *                         Action Scheduler still counts as pending.
		 */
		public static function queue_batch( string $slug, bool $from_batch = false ): void {
			if ( $from_batch || ! as_has_scheduled_action( self::HOOK_BATCH, array( $slug ), self::GROUP ) ) {
				as_enqueue_async_action( self::HOOK_BATCH, array( $slug ), self::GROUP, ! $from_batch );
			}
		}

		/**
		 * Keeps the recurring actions in line with the feeds: one per active feed, at its interval.
		 * Checked on admin / cron / CLI requests at most every 10 minutes, never on the storefront.
		 */
		public static function ensure_schedules( bool $force = false ): void {
			if ( ! $force ) {
				if ( ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
					return;
				}
				if ( get_transient( 'mrkv_uamp_schedule_ok' ) ) {
					return;
				}
			}
			$feeds = MRKV_UAMP_FEEDS::all();
			foreach ( array_keys( MRKV_UAMP_FEEDS::marketplaces() ) as $slug ) {
				$feed = $feeds[ $slug ] ?? null;
				if ( ! $feed || 'active' !== $feed['status'] ) {
					as_unschedule_all_actions( self::HOOK_REBUILD, array( $slug ), self::GROUP );
					continue;
				}
				$interval = MRKV_UAMP_FEEDS::schedule_interval( $feed['schedule'] );
				$next     = as_next_scheduled_action( self::HOOK_REBUILD, array( $slug ), self::GROUP );
				if ( false === $next || (int) get_option( 'mrkv_uamp_interval_' . $slug ) !== $interval ) {
					as_unschedule_all_actions( self::HOOK_REBUILD, array( $slug ), self::GROUP );
					as_schedule_recurring_action( time() + $interval, $interval, self::HOOK_REBUILD, array( $slug ), self::GROUP, true );
					update_option( 'mrkv_uamp_interval_' . $slug, $interval, false );
				}

				$state = MRKV_UAMP_FEEDS::get_state( $slug );
				if ( '' !== $state['run_id'] && ! as_has_scheduled_action( self::HOOK_BATCH, array( $slug ), self::GROUP )
					&& (int) $state['started'] < time() - self::STALLED ) {
					self::queue_batch( $slug );
				}
			}
			// The category directory is needed for mapping, also while the feed is paused.
			MRKV_UAMP_ROZETKA_IMPORTER::ensure_schedule( isset( $feeds['rozetka'] ) );
			set_transient( 'mrkv_uamp_schedule_ok', 1, 10 * MINUTE_IN_SECONDS );
		}

		/** Call after a feed is added, paused, resumed, deleted or its schedule changed. */
		public static function feeds_changed(): void {
			delete_transient( 'mrkv_uamp_schedule_ok' );
			if ( function_exists( 'as_schedule_recurring_action' ) ) {
				self::ensure_schedules( true );
			}
		}

		/** Next rebuild time of a feed, or null. */
		public static function next_run( string $slug ): ?int {
			if ( ! function_exists( 'as_next_scheduled_action' ) ) {
				return null;
			}
			$next = as_next_scheduled_action( self::HOOK_REBUILD, array( $slug ), self::GROUP );
			return is_int( $next ) ? $next : null;
		}

		public static function unschedule_all(): void {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( '', array(), self::GROUP );
			}
			foreach ( array_keys( MRKV_UAMP_FEEDS::marketplaces() ) as $slug ) {
				delete_option( 'mrkv_uamp_interval_' . $slug );
			}
			delete_transient( 'mrkv_uamp_schedule_ok' );
		}
	}
}
