<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_PRODUCT_FIELDS' ) ) {
	/**
	 * Per-product overrides on the product screens, one Product data tab per existing feed, with
	 * the 1.x post meta keys (`mrkvuamp_{slug}_*`):
	 *
	 * - not_xml: leave the product out of this marketplace's feed;
	 * - title, image (URL) and description (Rozetka): replace the product's own in the feed;
	 * - variation_image (per variation): replace the main picture of that variation's offer.
	 *
	 * Titles are also on Quick Edit. WooCommerce checks the nonce and the edit capability before
	 * every save hook used here. Pro adds fields on `mrkv_uamp_product_fields`.
	 */
	class MRKV_UAMP_PRODUCT_FIELDS {
		public function register(): void {
			add_filter( 'woocommerce_product_data_tabs', array( $this, 'tabs' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'panels' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
			add_action( 'woocommerce_variation_options_pricing', array( $this, 'variation_fields' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation' ), 10, 2 );
			add_action( 'woocommerce_product_quick_edit_end', array( $this, 'quick_edit_fields' ) );
			add_action( 'woocommerce_product_quick_edit_save', array( $this, 'quick_edit_save' ) );
			add_action( 'manage_product_posts_custom_column', array( $this, 'quick_edit_data' ), 20, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		}

		/** slug => label of the feeds that exist; fields are shown only for those. */
		private function marketplaces(): array {
			$out = array();
			foreach ( array_keys( MRKV_UAMP_FEEDS::all() ) as $slug ) {
				$out[ $slug ] = MRKV_UAMP_FEEDS::marketplaces()[ $slug ]['label'];
			}
			return $out;
		}

		public function assets( string $hook ): void {
			$screen = get_current_screen();
			if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'edit' ), true ) || ! $this->marketplaces() ) {
				return;
			}
			if ( 'post' === $screen->base ) {
				wp_enqueue_media();
			}
			wp_enqueue_script( 'mrkv-uamp-products', MRKV_UAMP_URL . 'assets/js/products.js', array( 'jquery' ), MRKV_UAMP_ADMIN::asset_version( 'assets/js/products.js' ), true );
			wp_localize_script( 'mrkv-uamp-products', 'MrkvUampProducts', array(
				'slugs' => array_keys( $this->marketplaces() ),
				'i18n'  => array(
					'choose' => __( 'Choose an image', 'ua-marketplace' ),
					'use'    => __( 'Use this image', 'ua-marketplace' ),
				),
			) );
		}

		public function tabs( array $tabs ): array {
			foreach ( $this->marketplaces() as $slug => $label ) {
				$tabs[ 'mrkv_uamp_' . $slug ] = array(
					'label'    => $label,
					'target'   => 'mrkv_uamp_' . $slug . '_data',
					'class'    => array(),
					'priority' => 80,
				);
			}
			return $tabs;
		}

		public function panels(): void {
			global $post;
			$id = (int) $post->ID;
			foreach ( $this->marketplaces() as $slug => $label ) {
				echo '<div id="mrkv_uamp_' . esc_attr( $slug ) . '_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';

				woocommerce_wp_checkbox( array(
					'id'          => 'mrkvuamp_' . $slug . '_not_xml',
					'label'       => __( 'Leave out of the feed', 'ua-marketplace' ),
					/* translators: %s: marketplace */
					'description' => sprintf( __( 'Do not send this product to %s.', 'ua-marketplace' ), $label ),
					'value'       => get_post_meta( $id, 'mrkvuamp_' . $slug . '_not_xml', true ) ? 'yes' : 'no',
				) );
				woocommerce_wp_text_input( array(
					'id'          => 'mrkvuamp_' . $slug . '_title',
					/* translators: %s: marketplace */
					'label'       => sprintf( __( 'Name on %s', 'ua-marketplace' ), $label ),
					'value'       => get_post_meta( $id, 'mrkvuamp_' . $slug . '_title', true ),
					'desc_tip'    => true,
					'description' => __( 'Replaces the product name in the feed. Variations keep their attributes after it.', 'ua-marketplace' ),
				) );

				if ( 'rozetka' === $slug ) {
					woocommerce_wp_text_input( array(
						'id'          => 'mrkvuamp_rozetka_image',
						'label'       => __( 'Main image URL', 'ua-marketplace' ),
						'value'       => get_post_meta( $id, 'mrkvuamp_rozetka_image', true ),
						'data_type'   => 'url',
						'desc_tip'    => true,
						'description' => __( 'Replaces the main product image in the feed (HTTPS only).', 'ua-marketplace' ),
						'custom_attributes' => array( 'data-mrkv-uamp-media' => '1' ),
					) );
					// WooCommerce field markup: the label sits in the left column, the editor lines up with the inputs.
					echo '<p class="form-field mrkvuamp_rozetka_description_field"><label for="mrkvuamp_rozetka_description_editor">' . esc_html__( 'Description on Rozetka', 'ua-marketplace' ) . '</label></p>';
					echo '<div style="padding: 0 20px 12px 162px; margin-top: -2.2em;">';
					wp_editor(
						(string) get_post_meta( $id, 'mrkvuamp_rozetka_description', true ),
						'mrkvuamp_rozetka_description_editor',
						array(
							'textarea_name' => 'mrkvuamp_rozetka_description',
							'textarea_rows' => 8,
							'tinymce'       => false,
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => array( 'buttons' => 'strong,em,ul,ol,li,close' ),
						)
					);
					echo '<p class="description">' . esc_html__( 'Replaces the product description in the feed. Leave empty to use the product description.', 'ua-marketplace' ) . '</p></div>';
				}

				do_action( 'mrkv_uamp_product_fields', $slug, $id );
				echo '</div></div>';
			}
		}

		public function save( $post_id ): void {
			$post_id = (int) $post_id;
			foreach ( array_keys( $this->marketplaces() ) as $slug ) {
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce before this hook
				self::update_or_delete( $post_id, 'mrkvuamp_' . $slug . '_not_xml', isset( $_POST[ 'mrkvuamp_' . $slug . '_not_xml' ] ) ? 'yes' : '' );
				self::update_or_delete( $post_id, 'mrkvuamp_' . $slug . '_title', isset( $_POST[ 'mrkvuamp_' . $slug . '_title' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'mrkvuamp_' . $slug . '_title' ] ) ) : '' );
				if ( 'rozetka' === $slug ) {
					self::update_or_delete( $post_id, 'mrkvuamp_rozetka_image', isset( $_POST['mrkvuamp_rozetka_image'] ) ? esc_url_raw( wp_unslash( $_POST['mrkvuamp_rozetka_image'] ) ) : '' );
					self::update_or_delete( $post_id, 'mrkvuamp_rozetka_description', isset( $_POST['mrkvuamp_rozetka_description'] ) ? wp_kses_post( wp_unslash( $_POST['mrkvuamp_rozetka_description'] ) ) : '' );
				}
				// phpcs:enable
				do_action( 'mrkv_uamp_save_product_fields', $slug, $post_id );
			}
		}

		public function variation_fields( $loop, $variation_data, $variation ): void {
			foreach ( $this->marketplaces() as $slug => $label ) {
				woocommerce_wp_text_input( array(
					'id'            => 'mrkvuamp_' . $slug . '_variation_image_' . $loop,
					'name'          => 'mrkvuamp_' . $slug . '_variation_image[' . $loop . ']',
					/* translators: %s: marketplace */
					'label'         => sprintf( __( '%s image URL', 'ua-marketplace' ), $label ),
					'value'         => get_post_meta( $variation->ID, 'mrkvuamp_' . $slug . '_variation_image', true ),
					'data_type'     => 'url',
					'wrapper_class' => 'form-row form-row-full',
					'desc_tip'      => true,
					'description'   => __( 'Replaces the main picture of this variation in the feed.', 'ua-marketplace' ),
				) );
			}
		}

		public function save_variation( $variation_id, $loop ): void {
			foreach ( array_keys( $this->marketplaces() ) as $slug ) {
				$key = 'mrkvuamp_' . $slug . '_variation_image';
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified save-variations
				if ( isset( $_POST[ $key ][ $loop ] ) ) {
					self::update_or_delete( (int) $variation_id, $key, esc_url_raw( wp_unslash( $_POST[ $key ][ $loop ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				}
			}
		}

		/* ---------------------------------------------------------------- quick edit */

		public function quick_edit_fields(): void {
			$marketplaces = $this->marketplaces();
			if ( ! $marketplaces ) {
				return;
			}
			echo '<br class="clear"><h4>' . esc_html__( 'Marketplace names', 'ua-marketplace' ) . '</h4>';
			foreach ( $marketplaces as $slug => $label ) {
				echo '<label><span class="title">' . esc_html( $label ) . '</span><span class="input-text-wrap"><input type="text" name="mrkvuamp_' . esc_attr( $slug ) . '_title" class="text mrkv-uamp-qe-' . esc_attr( $slug ) . '" value=""></span></label><br class="clear">';
			}
		}

		/** Hidden values Quick Edit copies into its fields (see products.js). */
		public function quick_edit_data( $column, $post_id ): void {
			if ( 'name' !== $column ) {
				return;
			}
			echo '<div class="hidden" id="mrkv_uamp_inline_' . esc_attr( (string) $post_id ) . '">';
			foreach ( array_keys( $this->marketplaces() ) as $slug ) {
				echo '<div class="' . esc_attr( $slug ) . '">' . esc_html( (string) get_post_meta( $post_id, 'mrkvuamp_' . $slug . '_title', true ) ) . '</div>';
			}
			echo '</div>';
		}

		public function quick_edit_save( $product ): void {
			foreach ( array_keys( $this->marketplaces() ) as $slug ) {
				$key = 'mrkvuamp_' . $slug . '_title';
				// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress verified the inline-save nonce
				if ( isset( $_POST[ $key ] ) ) {
					self::update_or_delete( $product->get_id(), $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				}
				// phpcs:enable
			}
		}

		private static function update_or_delete( int $post_id, string $key, string $value ): void {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
