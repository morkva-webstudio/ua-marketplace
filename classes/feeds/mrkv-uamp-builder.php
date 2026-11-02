<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_BUILDER' ) ) {
	/**
	 * Builds a feed in batches that each fit into one short request (wp-cron, server cron or a
	 * WP-CLI loop), so the time limit never matters.
	 *
	 * A run writes the header into a hidden `.{filename}.{run_id}.part` file next to the feed, then
	 * each batch appends offers for products with an ID above the saved cursor (stable even when
	 * products are added or deleted between batches, unlike offset paging) until the batch has used
	 * its time or memory budget. The last batch writes the footer, checks the XML parses and only
	 * then rename()s the file over the live feed, so the marketplace never downloads a missing or
	 * half-written file.
	 *
	 * Every step holds MRKV_UAMP_LOCK for the feed.
	 */
	class MRKV_UAMP_BUILDER {
		const BUDGET  = 20;
		const MEMORY  = 0.8;

		/** @var bool */
		private static $where_filter = false;

		/** The mapper for a feed; Pro swaps in its subclasses through `mrkv_uamp_mapper`. */
		public static function mapper( string $slug ): ?MRKV_UAMP_MAPPER {
			$mapper = 'rozetka' === $slug ? new MRKV_UAMP_ROZETKA_MAPPER() : ( 'promua' === $slug ? new MRKV_UAMP_PROM_MAPPER() : null );
			$mapper = apply_filters( 'mrkv_uamp_mapper', $mapper, $slug );
			return $mapper instanceof MRKV_UAMP_MAPPER ? $mapper : null;
		}

		/**
		 * Starts a new run: writes the header and resets the cursor. A run already in progress is
		 * left alone unless $restart.
		 *
		 * @return string started | running | busy | error
		 */
		public static function start( string $slug, bool $restart = false ): string {
			$feed   = MRKV_UAMP_FEEDS::get( $slug );
			$mapper = self::mapper( $slug );
			if ( ! $feed || ! $mapper ) {
				return 'error';
			}
			if ( ! MRKV_UAMP_LOCK::acquire( 'feed_' . $slug ) ) {
				return 'busy';
			}
			try {
				$state = MRKV_UAMP_FEEDS::get_state( $slug );
				if ( '' !== $state['run_id'] && ! $restart ) {
					return 'running';
				}
				if ( '' !== $state['run_id'] ) {
					self::delete_part( $feed, $state['run_id'] );
				}
				if ( ! MRKV_UAMP_FEEDS::ensure_dir( $feed ) ) {
					self::fail( $slug, $state, __( 'Could not create the feed folder in uploads.', 'ua-marketplace' ) );
					return 'error';
				}

				$run_id = strtolower( wp_generate_password( 12, false ) );
				if ( false === file_put_contents( self::part_path( $feed, $run_id ), MRKV_UAMP_XML::header( $mapper ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					self::fail( $slug, $state, __( 'Could not write to the feed folder in uploads.', 'ua-marketplace' ) );
					return 'error';
				}

				$state['run_id']   = $run_id;
				$state['cursor']   = 0;
				$state['offers']   = 0;
				$state['products'] = 0;
				$state['total']    = self::count_products( $mapper );
				$state['started']  = time();
				MRKV_UAMP_FEEDS::save_state( $slug, $state );
				return 'started';
			} finally {
				MRKV_UAMP_LOCK::release( 'feed_' . $slug );
			}
		}

		/**
		 * Appends offers until the budget runs out or the catalog ends.
		 *
		 * @param float|null $budget    seconds; null = filtered default, INF = no limit (WP-CLI)
		 * @param int        $max_pages stop after this many pages of `step` products; 0 = no limit
		 * @return string done | more | busy | idle | error
		 */
		public static function batch( string $slug, ?float $budget = null, int $max_pages = 0 ): string {
			$feed   = MRKV_UAMP_FEEDS::get( $slug );
			$mapper = self::mapper( $slug );
			if ( ! $feed || ! $mapper ) {
				return 'error';
			}
			if ( ! MRKV_UAMP_LOCK::acquire( 'feed_' . $slug ) ) {
				return 'busy';
			}
			try {
				$state = MRKV_UAMP_FEEDS::get_state( $slug );
				if ( '' === $state['run_id'] ) {
					return 'idle';
				}
				$part = self::part_path( $feed, $state['run_id'] );
				if ( ! is_file( $part ) ) {
					self::fail( $slug, $state, __( 'The temporary feed file disappeared during the build; a new build will start on the next run.', 'ua-marketplace' ) );
					return 'error';
				}

				$budget   = null === $budget ? (float) apply_filters( 'mrkv_uamp_batch_budget', self::BUDGET ) : $budget;
				$deadline = microtime( true ) + $budget;
				$memory   = self::memory_limit();

				$pages = 0;
				while ( true ) {
					$ids = self::next_ids( $mapper, (int) $state['cursor'], (int) $feed['step'] );
					if ( ! $ids ) {
						return self::finish( $slug, $feed, $mapper, $state ) ? 'done' : 'error';
					}

					$xml = '';
					foreach ( $ids as $id ) {
						$xml               .= self::product_xml( $mapper, (int) $id, $state );
						$state['cursor']    = (int) $id;
						$state['products'] += 1;
						if ( microtime( true ) >= $deadline || ( $memory && memory_get_usage( true ) > $memory ) ) {
							break;
						}
					}
					if ( '' !== $xml && false === file_put_contents( $part, $xml, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
						self::fail( $slug, $state, __( 'Could not write to the feed folder in uploads.', 'ua-marketplace' ) );
						return 'error';
					}
					MRKV_UAMP_FEEDS::save_state( $slug, $state );
					self::free_memory();

					if ( microtime( true ) >= $deadline || ( $memory && memory_get_usage( true ) > $memory ) ) {
						return 'more';
					}
					if ( $max_pages && ++$pages >= $max_pages ) {
						return 'more';
					}
				}
			} finally {
				MRKV_UAMP_LOCK::release( 'feed_' . $slug );
			}
		}

		/** Drops the run in progress; the live feed is untouched. */
		public static function cancel( string $slug ): void {
			$feed  = MRKV_UAMP_FEEDS::get( $slug );
			$state = MRKV_UAMP_FEEDS::get_state( $slug );
			if ( $feed && '' !== $state['run_id'] ) {
				self::delete_part( $feed, $state['run_id'] );
			}
			$state['run_id'] = '';
			MRKV_UAMP_FEEDS::save_state( $slug, $state );
		}

		/** Start (or continue) and drive a run to the end in this process; for WP-CLI. */
		public static function run_to_completion( string $slug, bool $restart = false ): string {
			$started = self::start( $slug, $restart );
			if ( 'error' === $started || 'busy' === $started ) {
				return $started;
			}
			do {
				$result = self::batch( $slug, INF );
			} while ( 'more' === $result );
			return $result;
		}

		private static function product_xml( MRKV_UAMP_MAPPER $mapper, int $id, array &$state ): string {
			try {
				$product = wc_get_product( $id );
				if ( ! $product instanceof WC_Product ) {
					return '';
				}
				$xml = '';
				foreach ( $mapper->offers( $product ) as $offer ) {
					$xml             .= MRKV_UAMP_XML::offer( $offer );
					$state['offers'] += 1;
				}
				return $xml;
			} catch ( Throwable $e ) {
				MRKV_UAMP_LOG::error( sprintf( '%s: product #%d skipped: %s', $mapper->slug(), $id, $e->getMessage() ) );
				return '';
			}
		}

		private static function finish( string $slug, array $feed, MRKV_UAMP_MAPPER $mapper, array $state ): bool {
			$part = self::part_path( $feed, $state['run_id'] );
			if ( false === file_put_contents( $part, MRKV_UAMP_XML::footer(), FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				self::fail( $slug, $state, __( 'Could not write to the feed folder in uploads.', 'ua-marketplace' ) );
				return false;
			}

			$offers = self::count_offers( $part );
			if ( null === $offers ) {
				self::fail( $slug, $state, /* translators: %s: log source name */ sprintf( __( 'The new feed is not valid XML, so the previous file was kept. See WooCommerce → Status → Logs (%s).', 'ua-marketplace' ), MRKV_UAMP_LOG::SOURCE ) );
				return false;
			}
			// An empty build replacing a feed that had offers is almost always a lost mapping or a
			// broken query; publishing it would take every product off the marketplace.
			$live = MRKV_UAMP_FEEDS::file_path( $feed );
			if ( 0 === $offers && (int) $state['last_offers'] > 0 && is_file( $live ) ) {
				self::fail( $slug, $state, __( 'The new feed has no products, so the previous file was kept. Check the category mapping and product filters.', 'ua-marketplace' ) );
				return false;
			}
			if ( ! @rename( $part, $live ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				self::fail( $slug, $state, __( 'Could not replace the feed file. Check the folder permissions in uploads.', 'ua-marketplace' ) );
				return false;
			}
			@chmod( $live, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

			$state['last_built']    = time();
			$state['last_duration'] = max( 0, time() - (int) $state['started'] );
			$state['last_offers']   = $offers;
			$state['last_error']    = '';
			$state['last_error_at'] = 0;
			$state['run_id']        = '';
			MRKV_UAMP_FEEDS::save_state( $slug, $state );
			self::delete_stale_parts( $feed );
			MRKV_UAMP_LOG::info( sprintf( '%s feed built: %d offers from %d products in %d s.', $mapper->slug(), $offers, (int) $state['products'], $state['last_duration'] ) );
			do_action( 'mrkv_uamp_feed_built', $slug, $state );
			return true;
		}

		private static function fail( string $slug, array $state, string $message ): void {
			$feed = MRKV_UAMP_FEEDS::get( $slug );
			if ( $feed && '' !== $state['run_id'] ) {
				self::delete_part( $feed, $state['run_id'] );
			}
			$state['run_id']        = '';
			$state['last_error']    = $message;
			$state['last_error_at'] = time();
			MRKV_UAMP_FEEDS::save_state( $slug, $state );
			MRKV_UAMP_LOG::error( $slug . ': ' . $message );
		}

		/** Streams the file once: number of <offer> elements, or null when it does not parse. */
		public static function count_offers( string $path ): ?int {
			$reader = new XMLReader();
			if ( ! @$reader->open( $path, 'UTF-8', LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return null;
			}
			$previous = libxml_use_internal_errors( true );
			$count    = 0;
			$ok       = true;
			while ( true ) {
				$read = $reader->read();
				if ( ! $read ) {
					$ok = ! libxml_get_errors();
					break;
				}
				if ( XMLReader::ELEMENT === $reader->nodeType && 'offer' === $reader->name ) {
					$count++;
				}
			}
			foreach ( libxml_get_errors() as $error ) {
				MRKV_UAMP_LOG::error( sprintf( 'XML error in %s, line %d: %s', basename( $path ), $error->line, trim( $error->message ) ) );
			}
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$reader->close();
			return $ok ? $count : null;
		}

		/* ---------------------------------------------------------------- product query */

		/** @return int[] the next $limit product ids above $after, ascending */
		public static function next_ids( MRKV_UAMP_MAPPER $mapper, int $after, int $limit ): array {
			self::add_where_filter();
			$query = new WP_Query( array_merge( $mapper->product_query_args(), array(
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'mrkv_uamp_after_id'     => $after,
			) ) );
			return array_map( 'intval', $query->posts );
		}

		public static function count_products( MRKV_UAMP_MAPPER $mapper ): int {
			$query = new WP_Query( array_merge( $mapper->product_query_args(), array(
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			) ) );
			return (int) $query->found_posts;
		}

		private static function add_where_filter(): void {
			if ( self::$where_filter ) {
				return;
			}
			self::$where_filter = true;
			add_filter( 'posts_where', array( __CLASS__, 'where_after_id' ), 10, 2 );
		}

		/** posts_where: the ID cursor of a feed query (`mrkv_uamp_after_id`); other queries untouched. */
		public static function where_after_id( $where, $query ) {
			$after = $query->get( 'mrkv_uamp_after_id' );
			if ( '' !== $after && null !== $after ) {
				global $wpdb;
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", (int) $after );
			}
			return $where;
		}

		/* ---------------------------------------------------------------- files and memory */

		public static function part_path( array $feed, string $run_id ): string {
			return MRKV_UAMP_FEEDS::dir_path( $feed ) . '.' . $feed['filename'] . '.' . $run_id . '.part';
		}

		private static function delete_part( array $feed, string $run_id ): void {
			$part = self::part_path( $feed, $run_id );
			if ( is_file( $part ) ) {
				wp_delete_file( $part );
			}
		}

		/** Leftovers of runs that were cancelled or whose state was lost. */
		private static function delete_stale_parts( array $feed ): void {
			foreach ( (array) glob( MRKV_UAMP_FEEDS::dir_path( $feed ) . '.' . $feed['filename'] . '.*.part' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) && filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
					wp_delete_file( $file );
				}
			}
		}

		/** 80 % of memory_limit in bytes, or 0 when there is no limit. */
		private static function memory_limit(): int {
			$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
			return $limit > 0 ? (int) ( $limit * self::MEMORY ) : 0;
		}

		/** WooCommerce caches every product it loads; a long run would otherwise grow without bound. */
		private static function free_memory(): void {
			global $wpdb;
			$wpdb->queries = array();
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
	}
}
