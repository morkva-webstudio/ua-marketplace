<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_ADMIN' ) ) {
	/**
	 * WooCommerce → UA Marketplaces feeds: the feed list (index) and one tab per marketplace with all of its
	 * settings in a single form. Plain WordPress admin markup (nav-tab-wrapper, widefat, form-table)
	 * with the morkva sidebar, as in the other free morkva plugins.
	 *
	 * Every state change goes through admin-post.php (nonce + capability) and redirects back with a
	 * notice code; the only JavaScript-driven parts are build progress and the category picker.
	 */
	class MRKV_UAMP_ADMIN {
		const PAGE = 'mrkv-ua-marketplaces';
		const CAP  = 'manage_woocommerce';

		/** Seconds one progress request may spend building, per feed. */
		const PAGE_BATCH_BUDGET = 10.0;

		/** 1.x menu slugs, redirected so old bookmarks and links in docs still land somewhere. */
		const LEGACY_PAGES = array(
			'mrkv_ua_marketplaces'         => 'feeds',
			'mrkv_ua_marketplaces_rozetka' => 'rozetka',
			'mrkv_ua_marketplaces_promua'  => 'promua',
			'mrkv_ua_marketplaces_support' => 'feeds',
		);

		public function register(): void {
			add_action( 'admin_menu', array( $this, 'menu' ), 60 );
			// Not admin_init: menu.php refuses an unregistered page slug before admin_init fires.
			add_action( 'admin_page_access_denied', array( $this, 'redirect_legacy_pages' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
			add_action( 'admin_notices', array( $this, 'migration_notice' ) );
			add_action( 'admin_post_mrkv_uamp_feed', array( $this, 'handle_feed_action' ) );
			add_action( 'admin_post_mrkv_uamp_save', array( $this, 'handle_save' ) );
			add_action( 'admin_post_mrkv_uamp_rozetka_directory', array( $this, 'handle_directory_refresh' ) );
			add_action( 'wp_ajax_mrkv_uamp_feed_status', array( $this, 'ajax_feed_status' ) );
			add_action( 'wp_ajax_mrkv_uamp_rozetka_search', array( $this, 'ajax_rozetka_search' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( MRKV_UAMP_FILE ), array( $this, 'action_links' ) );
			// WordPress drops these from the address bar, so a reload does not repeat the notice.
			add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		}

		public function removable_query_args( array $args ): array {
			$args[] = 'mrkv_uamp_notice';
			return $args;
		}

		public static function url( string $tab = 'feeds', array $args = array() ): string {
			return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
		}

		public static function title(): string {
			return MRKV_UAMP_IS_PRO ? 'UA Marketplaces Pro' : 'UA Marketplaces';
		}

		public function menu(): void {
			// "feeds": later versions add order import pages next to this one.
			add_submenu_page( 'woocommerce', self::title(), 'UA Marketplaces feeds', self::CAP, self::PAGE, array( $this, 'render' ) );
		}

		public function action_links( array $links ): array {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ua-marketplace' ) . '</a>' );
			return $links;
		}

		public function redirect_legacy_pages(): void {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
			if ( isset( self::LEGACY_PAGES[ $page ] ) && current_user_can( self::CAP ) ) {
				wp_safe_redirect( self::url( self::LEGACY_PAGES[ $page ] ) );
				exit;
			}
		}

		private function is_page(): bool {
			return isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
		}

		public function assets( string $hook ): void {
			if ( ! $this->is_page() ) {
				return;
			}
			wp_enqueue_style( 'mrkv-uamp-admin', MRKV_UAMP_URL . 'assets/css/admin.css', array(), self::asset_version( 'assets/css/admin.css' ) );
			$deps = array( 'jquery' );
			if ( wp_script_is( 'selectWoo', 'registered' ) ) {
				$deps[] = 'selectWoo';
				// WooCommerce ships the select2 styles inside its admin stylesheet.
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			wp_enqueue_script( 'mrkv-uamp-admin', MRKV_UAMP_URL . 'assets/js/admin.js', $deps, self::asset_version( 'assets/js/admin.js' ), true );
			wp_localize_script( 'mrkv-uamp-admin', 'MrkvUamp', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mrkv_uamp_admin' ),
				'i18n'    => array(
					'copied'       => __( 'Copied', 'ua-marketplace' ),
					'searching'    => __( 'Searching…', 'ua-marketplace' ),
					'noResults'    => __( 'Nothing found. You can also type a Rozetka category ID.', 'ua-marketplace' ),
					'typeMore'     => __( 'Type a category name or ID', 'ua-marketplace' ),
					/* translators: %s: Rozetka category ID typed in the picker */
					'useId'        => __( 'Use Rozetka ID %s', 'ua-marketplace' ),
					'urlChanges'   => __( 'Changing the folder or file name changes the feed URL. Update it in the marketplace cabinet afterwards. Continue?', 'ua-marketplace' ),
				),
			) );
		}

		public static function asset_version( string $relative ): string {
			$mtime = file_exists( MRKV_UAMP_DIR . $relative ) ? filemtime( MRKV_UAMP_DIR . $relative ) : false;
			return $mtime ? MRKV_UAMP_VERSION . '.' . $mtime : MRKV_UAMP_VERSION;
		}

		/** Tabs: the feed list, one per existing feed, and whatever Pro adds (Orders). */
		public static function tabs(): array {
			$tabs = array( 'feeds' => __( 'Feeds', 'ua-marketplace' ) );
			foreach ( MRKV_UAMP_FEEDS::all() as $slug => $feed ) {
				$tabs[ $slug ] = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'];
			}
			/**
			 * Filters the tabs of the plugin page; render a custom tab on `mrkv_uamp_render_tab_{tab}`.
			 *
			 * @param array $tabs tab => label
			 */
			return (array) apply_filters( 'mrkv_uamp_admin_tabs', $tabs );
		}

		/* ---------------------------------------------------------------- page */

		public function render(): void {
			if ( ! current_user_can( self::CAP ) ) {
				return;
			}
			$tabs = self::tabs();
			$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feeds'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
			if ( ! isset( $tabs[ $tab ] ) ) {
				$tab = 'feeds';
			}
			?>
			<div class="wrap mrkv-uamp-wrap">
				<div class="mrkv-uamp-header">
					<h1><?php echo esc_html( self::title() ); ?></h1>
					<a class="mrkv-uamp-logo" href="<?php echo esc_url( MRKV_UAMP_SIDEBAR::SITE_URL ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( MRKV_UAMP_URL . 'assets/images/morkva-logo.svg' ); ?>" alt="morkva" width="121" height="24">
					</a>
				</div>
				<hr class="wp-header-end">
				<nav class="nav-tab-wrapper">
					<?php foreach ( $tabs as $id => $label ) : ?>
						<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="nav-tab<?php echo $id === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<?php $this->notice(); ?>
				<div class="mrkv-uamp-layout">
					<div class="mrkv-uamp-main">
						<?php
						if ( 'feeds' === $tab ) {
							$this->render_feeds();
						} elseif ( MRKV_UAMP_FEEDS::is_marketplace( $tab ) ) {
							$this->render_marketplace( $tab );
						} else {
							do_action( 'mrkv_uamp_render_tab_' . $tab );
						}
						?>
					</div>
					<?php MRKV_UAMP_SIDEBAR::render(); ?>
				</div>
			</div>
			<?php
		}

		private function notice(): void {
			$code = isset( $_GET['mrkv_uamp_notice'] ) ? sanitize_key( wp_unslash( $_GET['mrkv_uamp_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message code only
			$map  = array(
				'saved'       => array( 'success', __( 'Settings saved. They apply from the next feed build.', 'ua-marketplace' ) ),
				'added'       => array( 'success', __( 'Feed created. Fill in the settings below, then build the feed.', 'ua-marketplace' ) ),
				'started'     => array( 'success', __( 'The build has started in the background. You can leave this page.', 'ua-marketplace' ) ),
				'busy'        => array( 'warning', __( 'The feed is being written right now. Try again in a minute.', 'ua-marketplace' ) ),
				'paused'      => array( 'success', __( 'Feed paused: it is no longer updated, the last file stays available.', 'ua-marketplace' ) ),
				'resumed'     => array( 'success', __( 'Feed resumed.', 'ua-marketplace' ) ),
				'deleted'     => array( 'success', __( 'Feed deleted.', 'ua-marketplace' ) ),
				'error'       => array( 'error', /* translators: %s: log source name */ sprintf( __( 'Something went wrong. See WooCommerce → Status → Logs (%s).', 'ua-marketplace' ), MRKV_UAMP_LOG::SOURCE ) ),
				'dir_updated' => array( 'success', __( 'Rozetka category directory updated.', 'ua-marketplace' ) ),
				'dir_busy'    => array( 'warning', __( 'The Rozetka category directory is being updated right now. Reload the page in a minute.', 'ua-marketplace' ) ),
				'dir_error'   => array( 'error', __( 'The Rozetka category directory could not be updated. The reason is shown below.', 'ua-marketplace' ) ),
			);
			if ( isset( $map[ $code ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $code ][0] ), esc_html( $map[ $code ][1] ) );
			}
		}

		/** One-time notice after the 1.x → 2.0 migration, on every admin screen until seen. */
		public function migration_notice(): void {
			$migrated = get_option( MRKV_UAMP_MIGRATION::NOTICE_OPTION );
			if ( ! is_array( $migrated ) || ! $migrated || ! current_user_can( self::CAP ) ) {
				return;
			}
			delete_option( MRKV_UAMP_MIGRATION::NOTICE_OPTION );
			$labels = array();
			foreach ( $migrated as $slug ) {
				$labels[] = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'] ?? $slug;
			}
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: %s: list of marketplaces */
					__( 'UA Marketplaces 2.0: your feeds (%s) were moved to the new settings. Feed URLs did not change, so nothing needs updating in the marketplace cabinets. The feeds are being rebuilt in the background.', 'ua-marketplace' ),
					implode( ', ', $labels )
				) )
				. ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the feeds', 'ua-marketplace' ) . '</a></p></div>';
		}

		/* ---------------------------------------------------------------- feeds tab */

		private function render_feeds(): void {
			$feeds = MRKV_UAMP_FEEDS::all();
			$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
			$feed  = isset( $_GET['feed'] ) ? sanitize_key( wp_unslash( $_GET['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only
			if ( 'delete' === $view && isset( $feeds[ $feed ] ) ) {
				$this->render_delete_confirm( $feed, $feeds[ $feed ] );
				return;
			}

			echo '<p>' . esc_html__( 'Each feed is an XML file the marketplace downloads on its own schedule. Copy its URL into the marketplace cabinet once; it never changes.', 'ua-marketplace' ) . '</p>';

			if ( $feeds ) {
				?>
				<table class="widefat striped mrkv-uamp-feeds">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Marketplace', 'ua-marketplace' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Feed URL', 'ua-marketplace' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'ua-marketplace' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Build', 'ua-marketplace' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $feeds as $slug => $feed ) : ?>
							<?php $this->render_feed_row( $slug, $feed ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				$this->render_server_cron();
			} else {
				echo '<div class="mrkv-uamp-empty"><p>' . esc_html__( 'No feeds yet. Add one for each marketplace you sell on.', 'ua-marketplace' ) . '</p></div>';
			}

			$missing = array_diff_key( MRKV_UAMP_FEEDS::marketplaces(), $feeds );
			if ( $missing ) {
				?>
				<h2><?php esc_html_e( 'Add a feed', 'ua-marketplace' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrkv-uamp-add">
					<input type="hidden" name="action" value="mrkv_uamp_feed">
					<input type="hidden" name="op" value="add">
					<?php wp_nonce_field( 'mrkv_uamp_feed_add' ); ?>
					<label for="mrkv-uamp-add-feed" class="screen-reader-text"><?php esc_html_e( 'Marketplace', 'ua-marketplace' ); ?></label>
					<select name="feed" id="mrkv-uamp-add-feed">
						<?php foreach ( $missing as $slug => $marketplace ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $marketplace['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Add feed', 'ua-marketplace' ), 'primary', 'submit', false ); ?>
				</form>
				<?php
			}
		}

		/**
		 * The crontab line for wp-cron.php. WP-Cron runs only on visits, so on a quiet site, or with
		 * DISABLE_WP_CRON, Action Scheduler (and the builds) wait until a server cron calls it.
		 */
		private function render_server_cron(): void {
			$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
			$command  = '*/5 * * * * wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
			?>
			<details class="mrkv-uamp-cron"<?php echo $disabled ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Server cron', 'ua-marketplace' ); ?></summary>
				<?php if ( $disabled ) : ?>
					<p class="mrkv-uamp-error"><?php esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Feeds are updated only if a server cron calls wp-cron.php.', 'ua-marketplace' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Feeds are built by WP-Cron, which runs only when someone opens the site. On a site with few visitors, add this line to the server cron (in the hosting panel or crontab) so feeds are updated on time:', 'ua-marketplace' ); ?></p>
				<div class="mrkv-uamp-url">
					<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $command ); ?>" aria-label="<?php esc_attr_e( 'Server cron command', 'ua-marketplace' ); ?>">
					<button type="button" class="button mrkv-uamp-copy" data-mrkv-uamp-copy="<?php echo esc_attr( $command ); ?>"><?php esc_html_e( 'Copy', 'ua-marketplace' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Every 5 minutes. If your hosting panel asks only for a URL, use the address in quotes.', 'ua-marketplace' ); ?></p>
			</details>
			<?php
		}

		private function render_feed_row( string $slug, array $feed ): void {
			$state   = MRKV_UAMP_FEEDS::get_state( $slug );
			$label   = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'];
			$url     = MRKV_UAMP_FEEDS::file_url( $feed );
			$exists  = is_file( MRKV_UAMP_FEEDS::file_path( $feed ) );
			$running = '' !== $state['run_id'];
			$next    = 'active' === $feed['status'] ? MRKV_UAMP_SCHEDULER::next_run( $slug ) : null;
			?>
			<?php $paused = 'paused' === $feed['status']; ?>
			<tr data-mrkv-uamp-feed="<?php echo esc_attr( $slug ); ?>"<?php echo $running ? ' data-mrkv-uamp-running="1"' : ''; ?>>
				<td class="column-primary">
					<strong><a href="<?php echo esc_url( self::url( $slug ) ); ?>"><?php echo esc_html( $label ); ?></a></strong>
					<?php if ( $paused ) : ?>
						<span class="mrkv-uamp-badge mrkv-uamp-badge--paused"><?php esc_html_e( 'Paused', 'ua-marketplace' ); ?></span>
					<?php else : ?>
						<span class="mrkv-uamp-badge mrkv-uamp-badge--active"><?php esc_html_e( 'Active', 'ua-marketplace' ); ?></span>
					<?php endif; ?>
					<div class="row-actions visible">
						<span><a href="<?php echo esc_url( self::url( $slug ) ); ?>"><?php esc_html_e( 'Settings', 'ua-marketplace' ); ?></a> | </span>
						<span><a href="<?php echo esc_url( self::action_url( $slug, $paused ? 'resume' : 'pause' ) ); ?>"><?php echo esc_html( $paused ? __( 'Resume', 'ua-marketplace' ) : __( 'Pause', 'ua-marketplace' ) ); ?></a> | </span>
						<span class="trash"><a class="mrkv-uamp-delete" href="<?php echo esc_url( self::url( 'feeds', array( 'view' => 'delete', 'feed' => $slug ) ) ); ?>"><?php esc_html_e( 'Delete', 'ua-marketplace' ); ?></a></span>
					</div>
				</td>
				<td>
					<div class="mrkv-uamp-url">
						<input type="text" readonly class="regular-text code" value="<?php echo esc_attr( $url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: marketplace */ __( '%s feed URL', 'ua-marketplace' ), $label ) ); ?>">
						<button type="button" class="button mrkv-uamp-copy" data-mrkv-uamp-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'ua-marketplace' ); ?></button>
					</div>
					<?php if ( $exists ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'ua-marketplace' ); ?></a>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'The file appears after the first build.', 'ua-marketplace' ); ?></span>
					<?php endif; ?>
				</td>
				<td class="mrkv-uamp-status">
					<div class="mrkv-uamp-last"><?php echo esc_html( self::last_build_text( $state ) ); ?></div>
					<div class="description">
						<?php
						if ( $paused ) {
							esc_html_e( 'Not updated while paused', 'ua-marketplace' );
						} else {
							/* translators: 1: next update time, 2: schedule such as "Once a day" */
							echo esc_html( sprintf( __( 'Next: %1$s (%2$s)', 'ua-marketplace' ), $next ? self::format_time( $next ) : __( 'scheduling…', 'ua-marketplace' ), self::schedule_label( $feed['schedule'] ) ) );
						}
						?>
					</div>
					<div class="mrkv-uamp-progress"<?php echo $running ? '' : ' hidden'; ?>>
						<progress max="<?php echo esc_attr( max( 1, (int) $state['total'] ) ); ?>" value="<?php echo esc_attr( (int) $state['products'] ); ?>"></progress>
						<span class="mrkv-uamp-progress__text"><?php echo esc_html( self::progress_text( $state ) ); ?></span>
					</div>
					<?php if ( '' !== $state['last_error'] ) : ?>
						<p class="mrkv-uamp-error"><?php echo esc_html( $state['last_error'] ); ?></p>
					<?php endif; ?>
				</td>
				<td class="mrkv-uamp-actions">
					<?php $this->action_button( $slug, 'build', $running ? __( 'Restart build', 'ua-marketplace' ) : __( 'Build now', 'ua-marketplace' ), true ); ?>
				</td>
			</tr>
			<?php
		}

		/** Signed admin-post link for one-click feed actions (pause, resume). */
		public static function action_url( string $slug, string $op ): string {
			return wp_nonce_url( add_query_arg( array( 'action' => 'mrkv_uamp_feed', 'op' => $op, 'feed' => $slug ), admin_url( 'admin-post.php' ) ), 'mrkv_uamp_feed_' . $op . '_' . $slug );
		}

		private function action_button( string $slug, string $op, string $label, bool $primary = false ): void {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrkv-uamp-inline-form">
				<input type="hidden" name="action" value="mrkv_uamp_feed">
				<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
				<input type="hidden" name="feed" value="<?php echo esc_attr( $slug ); ?>">
				<?php wp_nonce_field( 'mrkv_uamp_feed_' . $op . '_' . $slug ); ?>
				<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button>
			</form>
			<?php
		}

		private function render_delete_confirm( string $slug, array $feed ): void {
			$label = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'];
			?>
			<h2><?php echo esc_html( sprintf( /* translators: %s: marketplace */ __( 'Delete the %s feed?', 'ua-marketplace' ), $label ) ); ?></h2>
			<p><?php esc_html_e( 'The feed stops updating. If the marketplace still downloads this URL, keep the file so your products are not taken off the marketplace until you remove the price list there.', 'ua-marketplace' ); ?></p>
			<p><code><?php echo esc_html( MRKV_UAMP_FEEDS::file_url( $feed ) ); ?></code></p>
			<p><?php esc_html_e( 'Settings and category mapping are kept: adding the feed again restores them.', 'ua-marketplace' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mrkv_uamp_feed">
				<input type="hidden" name="op" value="delete">
				<input type="hidden" name="feed" value="<?php echo esc_attr( $slug ); ?>">
				<?php wp_nonce_field( 'mrkv_uamp_feed_delete_' . $slug ); ?>
				<p><label><input type="checkbox" name="delete_file" value="1"> <?php esc_html_e( 'Also delete the XML file', 'ua-marketplace' ); ?></label></p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Delete feed', 'ua-marketplace' ); ?></button>
					<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'ua-marketplace' ); ?></a>
				</p>
			</form>
			<?php
		}

		public static function progress_text( array $state ): string {
			return sprintf(
				/* translators: 1: products done, 2: products total */
				__( 'Building: %1$d of %2$d products', 'ua-marketplace' ),
				(int) $state['products'],
				(int) $state['total']
			);
		}

		public static function last_build_text( array $state ): string {
			if ( ! $state['last_built'] ) {
				return __( 'Not built yet', 'ua-marketplace' );
			}
			return sprintf(
				/* translators: 1: date and time, 2: number of offers */
				_n( '%1$s · %2$d offer', '%1$s · %2$d offers', (int) $state['last_offers'], 'ua-marketplace' ),
				self::format_time( (int) $state['last_built'] ),
				(int) $state['last_offers']
			);
		}

		public static function schedule_label( string $schedule ): string {
			$labels = array(
				'hourly'     => __( 'Every hour', 'ua-marketplace' ),
				'fourhour'   => __( 'Every 4 hours', 'ua-marketplace' ),
				'twicedaily' => __( 'Twice a day', 'ua-marketplace' ),
				'daily'      => __( 'Once a day', 'ua-marketplace' ),
			);
			return $labels[ $schedule ] ?? $labels['daily'];
		}

		public static function format_time( int $timestamp ): string {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		}

		/* ---------------------------------------------------------------- marketplace tab */

		private function render_marketplace( string $slug ): void {
			$feed  = MRKV_UAMP_FEEDS::get( $slug );
			$label = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'];
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrkv-uamp-settings">
				<input type="hidden" name="action" value="mrkv_uamp_save">
				<input type="hidden" name="feed" value="<?php echo esc_attr( $slug ); ?>">
				<?php wp_nonce_field( 'mrkv_uamp_save_' . $slug ); ?>

				<?php
				foreach ( MRKV_UAMP_SETTINGS::sections( $slug ) as $section ) {
					MRKV_UAMP_SETTINGS::render_section( $slug, $section );
					if ( 'vendor' === $section['id'] && 'rozetka' === $slug ) {
						// Mapping right after the brand: both decide whether Rozetka accepts a product.
						MRKV_UAMP_MAPPING_UI::render();
					}
				}
				do_action( 'mrkv_uamp_render_marketplace_sections', $slug, $feed );
				$this->render_file_section( $slug, $feed );
				submit_button( sprintf( /* translators: %s: marketplace */ __( 'Save %s settings', 'ua-marketplace' ), $label ) );
				?>
			</form>
			<?php
		}

		private function render_file_section( string $slug, array $feed ): void {
			$url  = MRKV_UAMP_FEEDS::file_url( $feed );
			$pro  = MRKV_UAMP_IS_PRO;
			?>
			<h2 id="mrkv-uamp-file"><?php esc_html_e( 'Feed file and updates', 'ua-marketplace' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Feed URL', 'ua-marketplace' ); ?></th>
					<td>
						<div class="mrkv-uamp-url">
							<input type="text" readonly class="regular-text code" value="<?php echo esc_attr( $url ); ?>" aria-label="<?php esc_attr_e( 'Feed URL', 'ua-marketplace' ); ?>">
							<button type="button" class="button mrkv-uamp-copy" data-mrkv-uamp-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'ua-marketplace' ); ?></button>
						</div>
					</td>
				</tr>
				<tr<?php echo $pro ? '' : ' class="mrkv-uamp-locked"'; ?>>
					<th scope="row"><label for="mrkv-uamp-filename"><?php esc_html_e( 'Folder and file name', 'ua-marketplace' ); ?></label><?php echo $pro ? '' : ' <span class="mrkv-uamp-pro-badge">Pro</span>'; ?></th>
					<td>
						<code>uploads/</code>
						<input type="text" class="regular-text code mrkv-uamp-short" value="<?php echo esc_attr( $feed['dir'] ); ?>" aria-label="<?php esc_attr_e( 'Folder', 'ua-marketplace' ); ?>"<?php echo $pro ? ' name="mrkv_uamp_feed_dir" data-mrkv-uamp-url-field="' . esc_attr( $feed['dir'] ) . '"' : ' disabled'; ?>>
						<code>/</code>
						<input type="text" id="mrkv-uamp-filename" class="regular-text code mrkv-uamp-short" value="<?php echo esc_attr( $feed['filename'] ); ?>"<?php echo $pro ? ' name="mrkv_uamp_feed_filename" data-mrkv-uamp-url-field="' . esc_attr( $feed['filename'] ) . '"' : ' disabled'; ?>>
						<code>.xml</code>
					</td>
				</tr>
				<tr<?php echo $pro ? '' : ' class="mrkv-uamp-locked"'; ?>>
					<th scope="row"><label for="mrkv-uamp-schedule"><?php esc_html_e( 'Update', 'ua-marketplace' ); ?></label><?php echo $pro ? '' : ' <span class="mrkv-uamp-pro-badge">Pro</span>'; ?></th>
					<td>
						<select id="mrkv-uamp-schedule"<?php echo $pro ? ' name="mrkv_uamp_feed_schedule"' : ' disabled'; ?>>
							<?php foreach ( ( $pro ? MRKV_UAMP_FEEDS::schedules() : array( 'hourly' => 0, 'fourhour' => 0, 'twicedaily' => 0, 'daily' => 0 ) ) as $schedule => $interval ) : ?>
								<option value="<?php echo esc_attr( $schedule ); ?>"<?php selected( $pro ? $feed['schedule'] : 'daily', $schedule ); ?>><?php echo esc_html( self::schedule_label( $schedule ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! $pro ) : ?>
							<p class="description"><?php esc_html_e( 'The free version rebuilds every feed once a day. Rozetka downloads price lists every hour.', 'ua-marketplace' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mrkv-uamp-step"><?php esc_html_e( 'Products per query', 'ua-marketplace' ); ?></label></th>
					<td>
						<input type="number" min="10" max="5000" step="10" id="mrkv-uamp-step" name="mrkv_uamp_feed_step" value="<?php echo esc_attr( $feed['step'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'The feed is built in short background steps of about 20 seconds, so the server time limit never matters. Lower this only if builds stop with memory errors.', 'ua-marketplace' ); ?></p>
					</td>
				</tr>
			</tbody></table>
			<?php
		}

		/* ---------------------------------------------------------------- handlers */

		private function guard( string $nonce_action ): void {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'You are not allowed to manage marketplace feeds.', 'ua-marketplace' ), 403 );
			}
			check_admin_referer( $nonce_action );
		}

		public function handle_feed_action(): void {
			// Buttons post, pause/resume links are signed GET requests; both carry _wpnonce.
			$op   = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard()
			$slug = isset( $_REQUEST['feed'] ) ? sanitize_key( wp_unslash( $_REQUEST['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard()
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
			if ( 'get' === $method && ! in_array( $op, array( 'pause', 'resume' ), true ) ) {
				wp_die( esc_html__( 'This action needs a form submission.', 'ua-marketplace' ), 400 );
			}
			$this->guard( 'add' === $op ? 'mrkv_uamp_feed_add' : 'mrkv_uamp_feed_' . $op . '_' . $slug );

			$feed = MRKV_UAMP_FEEDS::get( $slug );
			if ( 'add' === $op ) {
				$ok = MRKV_UAMP_FEEDS::add( $slug );
				MRKV_UAMP_SCHEDULER::feeds_changed();
				wp_safe_redirect( $ok ? self::url( $slug, array( 'mrkv_uamp_notice' => 'added' ) ) : self::url( 'feeds', array( 'mrkv_uamp_notice' => 'error' ) ) );
				exit;
			}
			if ( ! $feed ) {
				wp_safe_redirect( self::url( 'feeds', array( 'mrkv_uamp_notice' => 'error' ) ) );
				exit;
			}

			$notice = 'error';
			switch ( $op ) {
				case 'build':
					$result = MRKV_UAMP_SCHEDULER::build_now( $slug );
					$notice = 'started' === $result ? 'started' : ( 'busy' === $result ? 'busy' : 'error' );
					break;
				case 'pause':
				case 'resume':
					$feed['status'] = 'pause' === $op ? 'paused' : 'active';
					MRKV_UAMP_FEEDS::save( $slug, $feed );
					if ( 'pause' === $op ) {
						MRKV_UAMP_BUILDER::cancel( $slug );
					}
					MRKV_UAMP_SCHEDULER::feeds_changed();
					$notice = 'pause' === $op ? 'paused' : 'resumed';
					break;
				case 'delete':
					MRKV_UAMP_BUILDER::cancel( $slug );
					if ( ! empty( $_POST['delete_file'] ) && is_file( MRKV_UAMP_FEEDS::file_path( $feed ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
						wp_delete_file( MRKV_UAMP_FEEDS::file_path( $feed ) );
					}
					MRKV_UAMP_FEEDS::delete( $slug );
					MRKV_UAMP_SCHEDULER::feeds_changed();
					$notice = 'deleted';
					break;
			}
			wp_safe_redirect( self::url( 'feeds', array( 'mrkv_uamp_notice' => $notice ) ) );
			exit;
		}

		public function handle_save(): void {
			$slug = isset( $_POST['feed'] ) ? sanitize_key( wp_unslash( $_POST['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard()
			$this->guard( 'mrkv_uamp_save_' . $slug );
			$feed = MRKV_UAMP_FEEDS::get( $slug );
			if ( ! $feed ) {
				wp_safe_redirect( self::url( 'feeds', array( 'mrkv_uamp_notice' => 'error' ) ) );
				exit;
			}
			$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard(); each field is sanitised by its definition

			MRKV_UAMP_SETTINGS::save( $slug, $input );
			if ( 'rozetka' === $slug ) {
				MRKV_UAMP_MAPPING_UI::save( (array) ( $input['mrkv_uamp_map'] ?? array() ) );
			}

			$feed['step'] = max( 10, min( 5000, (int) ( $input['mrkv_uamp_feed_step'] ?? $feed['step'] ) ) );
			if ( MRKV_UAMP_IS_PRO ) {
				if ( isset( $input['mrkv_uamp_feed_schedule'] ) && isset( MRKV_UAMP_FEEDS::schedules()[ $input['mrkv_uamp_feed_schedule'] ] ) ) {
					$feed['schedule'] = $input['mrkv_uamp_feed_schedule'];
				}
				if ( isset( $input['mrkv_uamp_feed_dir'], $input['mrkv_uamp_feed_filename'] ) ) {
					$dir      = MRKV_UAMP_FEEDS::clean_dir_input( (string) $input['mrkv_uamp_feed_dir'] );
					$filename = MRKV_UAMP_FEEDS::clean_filename_input( (string) $input['mrkv_uamp_feed_filename'] );
					// Only touched values are re-cleaned: an untouched 1.x name keeps its exact URL.
					if ( $input['mrkv_uamp_feed_dir'] !== $feed['dir'] ) {
						$feed['dir'] = $dir;
					}
					if ( $input['mrkv_uamp_feed_filename'] !== $feed['filename'] && '' !== $filename ) {
						$feed['filename'] = $filename;
					}
				}
			}
			MRKV_UAMP_FEEDS::save( $slug, $feed );
			MRKV_UAMP_SCHEDULER::feeds_changed();
			do_action( 'mrkv_uamp_settings_saved', $slug, $input );

			wp_safe_redirect( self::url( $slug, array( 'mrkv_uamp_notice' => 'saved' ) ) );
			exit;
		}

		/** "Refresh" next to the directory status: a signed link, so it works inside the settings form. */
		public function handle_directory_refresh(): void {
			$this->guard( 'mrkv_uamp_rozetka_directory' );
			$result = MRKV_UAMP_ROZETKA_IMPORTER::check( true );
			$notice = array(
				'updated' => 'dir_updated',
				'busy'    => 'dir_busy',
			)[ $result ] ?? 'dir_error';
			wp_safe_redirect( self::url( 'rozetka', array( 'mrkv_uamp_notice' => $notice ) ) . '#mrkv-uamp-mapping' );
			exit;
		}

		public static function directory_refresh_url(): string {
			return wp_nonce_url( add_query_arg( 'action', 'mrkv_uamp_rozetka_directory', admin_url( 'admin-post.php' ) ), 'mrkv_uamp_rozetka_directory' );
		}

		/**
		 * Live progress for the Feeds table. While the page is open, each call also builds the next
		 * `step` products of every running feed, so the bar moves batch by batch; when the page is
		 * closed, Action Scheduler carries on. A feed held by an Action Scheduler batch is skipped.
		 */
		public function ajax_feed_status(): void {
			check_ajax_referer( 'mrkv_uamp_admin' );
			if ( ! current_user_can( self::CAP ) ) {
				wp_send_json_error( null, 403 );
			}
			$out = array();
			foreach ( MRKV_UAMP_FEEDS::all() as $slug => $feed ) {
				if ( '' !== MRKV_UAMP_FEEDS::get_state( $slug )['run_id'] ) {
					MRKV_UAMP_BUILDER::batch( $slug, self::PAGE_BATCH_BUDGET, 1 );
				}
				$state        = MRKV_UAMP_FEEDS::get_state( $slug );
				$out[ $slug ] = array(
					'running'  => '' !== $state['run_id'],
					'products' => (int) $state['products'],
					'total'    => (int) $state['total'],
					'progress' => self::progress_text( $state ),
					'last'     => self::last_build_text( $state ),
					'error'    => (string) $state['last_error'],
				);
			}
			wp_send_json_success( $out );
		}

		/** Category picker search over the local Rozetka directory (Pro adds a live search). */
		public function ajax_rozetka_search(): void {
			check_ajax_referer( 'mrkv_uamp_admin' );
			if ( ! current_user_can( self::CAP ) ) {
				wp_send_json_error( null, 403 );
			}
			$term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
			$results = array();
			foreach ( MRKV_UAMP_ROZETKA_DIRECTORY::search( $term ) as $row ) {
				$results[] = array(
					'id'   => (string) $row['id'],
					'text' => $row['path'] . ' (' . $row['id'] . ')',
				);
			}
			/**
			 * Filters the Rozetka category picker results.
			 *
			 * @param array  $results array( 'id', 'text' )
			 * @param string $term
			 */
			wp_send_json( array( 'results' => array_values( (array) apply_filters( 'mrkv_uamp_rozetka_search_results', $results, $term ) ) ) );
		}
	}
}
