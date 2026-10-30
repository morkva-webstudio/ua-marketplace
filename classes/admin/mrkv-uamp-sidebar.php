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
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Sale and promo prices, price formulas', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Variation photo galleries', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Prom keywords, catalog category and two languages', 'ua-marketplace' ); ?></li>
							<li><span class="dashicons dashicons-yes" aria-hidden="true"></span><?php esc_html_e( 'Description templates, SKU in the name, own feed file names', 'ua-marketplace' ); ?></li>
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

		/** "Other free plugins" card: our plugins on wordpress.org, each linked to its free version there. */
		private static function other_plugins(): void {
			?>
			<div class="mrkv-uamp-sidebar__card">
				<h3><?php esc_html_e( 'Other free plugins', 'ua-marketplace' ); ?></h3>
				<p><?php esc_html_e( 'All our plugins are cross-compatible', 'ua-marketplace' ); ?></p>
				<ul class="mrkv-uamp-sidebar__plugins">
					<?php foreach ( self::free_plugins() as $slug => $plugin ) : ?>
						<li>
							<a href="<?php echo esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $plugin[0] ); ?></a>
							<span>&ndash; <?php echo esc_html( $plugin[1] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		/** wordpress.org slug => array( name, what it does ). */
		private static function free_plugins(): array {
			return array(
				'morkva-ua-shipping'     => array( 'morkva UA Shipping', __( 'Nova Poshta, Ukrposhta and Rozetka Delivery shipping', 'ua-marketplace' ) ),
				'mrkv-nova-post'         => array( 'morkva Nova Post', __( 'Nova Post shipping', 'ua-marketplace' ) ),
				'checkbox'               => array( 'morkva Checkbox Integration', __( 'e-receipts with Checkbox', 'ua-marketplace' ) ),
				'mrkv-vchasno-kasa'      => array( 'morkva Vchasno Kasa Integration', __( 'e-receipts with Vchasno.Kasa', 'ua-marketplace' ) ),
				'mrkv-monobank-extended' => array( 'morkva Plata by Mono Extended', __( 'Monobank card payments', 'ua-marketplace' ) ),
				'mrkv-liqpay-extended'   => array( 'morkva LiqPay Extended', __( 'PrivatBank LiqPay card payments', 'ua-marketplace' ) ),
				'morkva-novapay'         => array( 'morkva NovaPay', __( 'NovaPay payments', 'ua-marketplace' ) ),
				'morkva-buy-one-click'   => array( 'morkva Quick Order Button', __( 'one-click order button', 'ua-marketplace' ) ),
				'morkva-product-video'   => array( 'morkva Product Video', __( 'videos in product galleries', 'ua-marketplace' ) ),
				'mrkv-review-reminder'   => array( 'morkva Product Review Reminder', __( 'reminds customers to review their purchases', 'ua-marketplace' ) ),
			);
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
