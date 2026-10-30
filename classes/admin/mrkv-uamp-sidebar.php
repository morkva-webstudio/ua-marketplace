<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_SIDEBAR' ) ) {
	/**
	 * Right sidebar on the plugin page: review, Pro, other morkva plugins. Same cards as the other
	 * free morkva plugins (mrkv-iban-gateway-free).
	 */
	class MRKV_UAMP_SIDEBAR {
		const WPORG_URL = 'https://wordpress.org/plugins/ua-marketplace/';
		const PRO_URL   = 'https://morkva.co.ua/shop-2/woocommerce-rozetka-sync';
		const SITE_URL  = 'https://morkva.co.ua/';
		const DOCS_URL  = 'https://docs.morkva.co.ua/uk';

		/** Plugin list endpoint and the transient all morkva plugins share for it. */
		const PLUGINS_API = 'https://morkva.co.ua/wp-json/pluginManagementPro/v2';
		const PLUGINS_KEY = 'morkva_plugin_management_data';

		public static function render(): void {
			?>
			<div class="mrkv-uamp-sidebar">
				<?php if ( ! MRKV_UAMP_IS_PRO ) : ?>
					<div class="mrkv-uamp-sidebar__card">
						<h3><?php esc_html_e( 'Like this plugin?', 'ua-marketplace' ); ?></h3>
						<p>
							<?php esc_html_e( 'Support our efforts with a 5-star review at', 'ua-marketplace' ); ?>
							<a href="<?php echo esc_url( self::WPORG_URL . '#reviews' ); ?>" target="_blank" rel="noopener">WordPress.org</a>
						</p>
						<a class="button button-primary" href="<?php echo esc_url( self::WPORG_URL . '#reviews' ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Leave a review', 'ua-marketplace' ); ?>
							<?php echo str_repeat( '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>', 5 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?>
						</a>
						<p><?php esc_html_e( 'Not good enough for 5 stars? Contact us via the widget on our website, or check the documentation.', 'ua-marketplace' ); ?></p>
						<div class="mrkv-uamp-sidebar__buttons">
							<a class="button button-primary" href="<?php echo esc_url( self::utm( self::SITE_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Go to the website', 'ua-marketplace' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::utm( self::DOCS_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'ua-marketplace' ); ?></a>
						</div>
					</div>

					<div class="mrkv-uamp-sidebar__card">
						<h3><?php esc_html_e( 'Check out the Pro version', 'ua-marketplace' ); ?></h3>
						<ul class="mrkv-uamp-sidebar__features">
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Hourly feed updates', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Sale prices and price formulas', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Variation photo galleries', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Prom category mapping, keywords and two languages', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Rozetka category search on Rozetka itself', 'ua-marketplace' ); ?></li>
						</ul>
						<a class="button button-primary" href="<?php echo esc_url( self::utm( self::PRO_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Buy the Pro version', 'ua-marketplace' ); ?></a>
					</div>
				<?php else : ?>
					<div class="mrkv-uamp-sidebar__card">
						<h3><?php esc_html_e( 'Need help?', 'ua-marketplace' ); ?></h3>
						<div class="mrkv-uamp-sidebar__buttons">
							<a class="button button-primary" href="<?php echo esc_url( self::utm( self::SITE_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Go to the website', 'ua-marketplace' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::utm( self::DOCS_URL ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'ua-marketplace' ); ?></a>
						</div>
					</div>
				<?php endif; ?>

				<?php self::other_plugins(); ?>
			</div>
			<?php
		}

		/** "Other free plugins" card, from the morkva plugin list (cached 12 hours, 1 hour after a failure). */
		private static function other_plugins(): void {
			$plugins = self::fetch_plugins();
			unset( $plugins['ua-marketplace'], $plugins['ua-marketplaces-pro'] );
			if ( empty( $plugins ) ) {
				return;
			}
			$ukrainian = 0 === strpos( get_user_locale(), 'uk' );
			?>
			<div class="mrkv-uamp-sidebar__card">
				<h3><?php esc_html_e( 'Other free plugins', 'ua-marketplace' ); ?></h3>
				<p><?php esc_html_e( 'All our plugins are cross-compatible', 'ua-marketplace' ); ?></p>
				<ul class="mrkv-uamp-sidebar__plugins">
					<?php foreach ( $plugins as $plugin ) : ?>
						<?php
						if ( ! is_array( $plugin ) || empty( $plugin['url'] ) || empty( $plugin['label'] ) ) {
							continue;
						}
						$description = $ukrainian ? ( $plugin['description'] ?? '' ) : ( $plugin['description_en'] ?? '' );
						?>
						<li>
							<a href="<?php echo esc_url( self::utm( $plugin['url'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $plugin['label'] ); ?></a>
							<?php if ( '' !== $description ) : ?>
								<span>&ndash; <?php echo esc_html( $description ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		private static function fetch_plugins(): array {
			$cached = get_transient( self::PLUGINS_KEY );
			if ( false === $cached ) {
				$response = wp_remote_get( self::PLUGINS_API, array( 'timeout' => 6 ) );
				$data     = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
					? null
					: json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $data ) || ! is_array( $data ) ) {
					set_transient( self::PLUGINS_KEY, 'error', HOUR_IN_SECONDS );
					return array();
				}
				set_transient( self::PLUGINS_KEY, $data, 12 * HOUR_IN_SECONDS );
				$cached = $data;
			}
			return ( is_array( $cached ) && isset( $cached['plugins'] ) && is_array( $cached['plugins'] ) ) ? $cached['plugins'] : array();
		}

		private static function utm( string $url ): string {
			return add_query_arg(
				array(
					'utm_source'   => 'plugin',
					'utm_medium'   => 'sidebar',
					'utm_campaign' => MRKV_UAMP_IS_PRO ? 'ua_marketplaces_pro' : 'ua_marketplace_free',
				),
				$url
			);
		}
	}
}
