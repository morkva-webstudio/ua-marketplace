<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_CLI' ) ) {
	/**
	 * `wp mrkv-uamp` — build feeds from the command line in one process (no time limit in PHP CLI),
	 * e.g. from a server crontab: `0 3 * * * wp --path=/var/www/site mrkv-uamp build all`.
	 */
	class MRKV_UAMP_CLI {
		/**
		 * Builds a feed to the end in this process.
		 *
		 * ## OPTIONS
		 *
		 * <feed>
		 * : rozetka, promua or all (every active feed).
		 *
		 * [--restart]
		 * : Drop a run in progress and start from the first product.
		 *
		 * @when after_wp_load
		 */
		public function build( $args, $assoc_args ) {
			$which = (string) ( $args[0] ?? '' );
			$feeds = MRKV_UAMP_FEEDS::all();
			if ( 'all' === $which ) {
				$slugs = array_keys( array_filter( $feeds, function ( $feed ) {
					return 'active' === $feed['status'];
				} ) );
			} elseif ( isset( $feeds[ $which ] ) ) {
				$slugs = array( $which );
			} else {
				WP_CLI::error( sprintf( 'No feed "%s". Existing feeds: %s.', $which, implode( ', ', array_keys( $feeds ) ) ?: 'none' ) );
				return;
			}

			$failed = false;
			foreach ( $slugs as $slug ) {
				$result = MRKV_UAMP_BUILDER::run_to_completion( $slug, ! empty( $assoc_args['restart'] ) );
				$state  = MRKV_UAMP_FEEDS::get_state( $slug );
				if ( 'done' === $result ) {
					WP_CLI::success( sprintf( '%s: %d offers in %d s → %s', $slug, $state['last_offers'], $state['last_duration'], MRKV_UAMP_FEEDS::file_url( $feeds[ $slug ] ) ) );
				} elseif ( 'busy' === $result ) {
					WP_CLI::warning( sprintf( '%s: another build holds the feed right now; try again in a minute.', $slug ) );
				} else {
					$failed = true;
					WP_CLI::warning( sprintf( '%s: %s', $slug, $state['last_error'] ?: $result ) );
				}
			}
			if ( $failed ) {
				WP_CLI::halt( 1 );
			}
		}

		/**
		 * Shows every feed: file, status, last build, run in progress.
		 *
		 * @when after_wp_load
		 */
		public function status( $args, $assoc_args ) {
			$rows = array();
			foreach ( MRKV_UAMP_FEEDS::all() as $slug => $feed ) {
				$state  = MRKV_UAMP_FEEDS::get_state( $slug );
				$next   = MRKV_UAMP_SCHEDULER::next_run( $slug );
				$rows[] = array(
					'feed'       => $slug,
					'status'     => $feed['status'],
					'url'        => MRKV_UAMP_FEEDS::file_url( $feed ),
					'schedule'   => $feed['schedule'],
					'last_built' => $state['last_built'] ? wp_date( 'Y-m-d H:i', $state['last_built'] ) : '—',
					'offers'     => $state['last_offers'],
					'running'    => '' !== $state['run_id'] ? sprintf( '%d / %d products', $state['products'], $state['total'] ) : '',
					'next_run'   => $next ? wp_date( 'Y-m-d H:i', $next ) : '—',
					'last_error' => $state['last_error'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'feed', 'status', 'url', 'schedule', 'last_built', 'offers', 'running', 'next_run', 'last_error' ) );
		}
	}
}
