<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_SETTINGS' ) ) {
	/**
	 * Fields of a marketplace tab. Each field is stored in its 1.x option
	 * `mrkv_uamrkpl_{slug}_{key}`, so 1.x settings show up as they were.
	 *
	 * A field with 'pro' => true is shown disabled with a Pro badge in Free and has no name, so it is
	 * never submitted or saved. Pro unlocks the same definitions (MRKV_UAMP_IS_PRO) and can add
	 * sections through `mrkv_uamp_settings_sections`.
	 */
	class MRKV_UAMP_SETTINGS {
		public static function option_key( string $slug, string $key ): string {
			return 'mrkv_uamrkpl_' . $slug . '_' . $key;
		}

		/** @return array[] sections: array( 'id', 'title', 'description'?, 'fields' => array[] ) */
		public static function sections( string $slug ): array {
			$sections = array(
				array(
					'id'     => 'shop',
					'title'  => __( 'Shop', 'ua-marketplace' ),
					'fields' => array(
						array(
							'key'         => 'shop_name',
							'type'        => 'text',
							'label'       => __( 'Shop name', 'ua-marketplace' ),
							'placeholder' => get_bloginfo( 'name' ),
							'description' => __( 'The <name> of the shop in the feed. Empty: the site title.', 'ua-marketplace' ),
						),
						array(
							'key'         => 'company',
							'type'        => 'text',
							'label'       => __( 'Company', 'ua-marketplace' ),
							'placeholder' => get_bloginfo( 'description' ),
							'description' => __( 'Legal name of the seller. Empty: the site tagline.', 'ua-marketplace' ),
						),
					),
				),
				array(
					'id'          => 'vendor',
					'title'       => __( 'Brand', 'ua-marketplace' ),
					'description' => 'rozetka' === $slug
						? __( 'Rozetka requires a brand (<vendor>) for every product and groups variations only within one brand.', 'ua-marketplace' )
						: __( 'Prom imports a brand only if it exists in the Prom brand list.', 'ua-marketplace' ),
					'fields'      => array(
						array(
							'key'         => 'global_vendor',
							'type'        => 'text',
							'label'       => __( 'One brand for all products', 'ua-marketplace' ),
							'description' => __( 'When set, every product gets this brand and the source below is ignored.', 'ua-marketplace' ),
						),
						array(
							'key'     => 'custom_vendor',
							'type'    => 'select',
							'label'   => __( 'Brand source', 'ua-marketplace' ),
							'options' => array(
								''                         => __( '— Not set —', 'ua-marketplace' ),
								'vendor_woocommerce'       => __( 'WooCommerce Brands', 'ua-marketplace' ),
								'vendor_pwb_brand'         => __( 'Perfect Brands for WooCommerce', 'ua-marketplace' ),
								'vendor_by_attributes'     => __( 'Product attribute', 'ua-marketplace' ),
								'vendor_all_possibilities' => __( 'Custom field (post meta)', 'ua-marketplace' ),
							),
							'attributes' => array( 'data-mrkv-uamp-toggle' => 'vendor' ),
						),
						array(
							'key'        => 'vendor_by_attributes',
							'type'       => 'select',
							'label'      => __( 'Brand attribute', 'ua-marketplace' ),
							'options'    => self::attribute_options(),
							'row_attrs'  => array( 'data-mrkv-uamp-show' => 'vendor:vendor_by_attributes' ),
						),
						array(
							'key'         => 'vendor_all_possibilities',
							'type'        => 'text',
							'label'       => __( 'Custom field key', 'ua-marketplace' ),
							'placeholder' => '_brand',
							'description' => __( 'The meta key that holds the brand name on the product.', 'ua-marketplace' ),
							'row_attrs'   => array( 'data-mrkv-uamp-show' => 'vendor:vendor_all_possibilities' ),
						),
					),
				),
				array(
					'id'     => 'products',
					'title'  => __( 'Products and prices', 'ua-marketplace' ),
					'fields' => self::product_fields( $slug ),
				),
			);

			/**
			 * Filters the sections of a marketplace tab (Pro adds and unlocks fields here).
			 *
			 * @param array  $sections
			 * @param string $slug rozetka | promua
			 */
			return (array) apply_filters( 'mrkv_uamp_settings_sections', $sections, $slug );
		}

		private static function product_fields( string $slug ): array {
			$fields = array();
			if ( 'rozetka' === $slug ) {
				$fields[] = array(
					'key'         => 'xml_tags_lang',
					'type'        => 'select',
					'label'       => __( 'Name and description tags', 'ua-marketplace' ),
					'options'     => array(
						''    => '<name>, <description>',
						'_ua' => '<name_ua>, <description_ua>',
					),
					'description' => __( 'Choose <name_ua> when your product texts are in Ukrainian; Rozetka translates the other language automatically.', 'ua-marketplace' ),
				);
			}
			$fields[] = array(
				'key'         => 'add_sales_price_chk',
				'type'        => 'checkbox',
				'label'       => __( 'Sale price', 'ua-marketplace' ),
				'text'        => __( 'Send the sale price with the regular price crossed out', 'ua-marketplace' ),
				'pro'         => true,
			);
			$fields[] = array(
				'key'         => 'product_price_formula',
				'type'        => 'text',
				'label'       => __( 'Price formula', 'ua-marketplace' ),
				'placeholder' => '*1.15',
				'description' => __( 'Change every price in the feed: *1.15, +50 or -20.', 'ua-marketplace' ),
				'pro'         => true,
			);
			$fields[] = array(
				'key'         => 'variation_foto_gallery_chk',
				'type'        => 'checkbox',
				'label'       => __( 'Variation galleries', 'ua-marketplace' ),
				'text'        => __( 'Variation photos from Woo Variation Gallery, Variation Images Gallery or Woodmart', 'ua-marketplace' ),
				'pro'         => true,
			);
			$fields[] = array(
				'key'         => 'product_description',
				'type'        => 'textarea',
				'label'       => __( 'Description template', 'ua-marketplace' ),
				'description' => __( 'Build the feed description from the full, short and variation descriptions.', 'ua-marketplace' ),
				'pro'         => true,
			);
			if ( 'promua' === $slug ) {
				$fields[] = array(
					'key'   => 'bilingual_preview',
					'type'  => 'checkbox',
					'label' => __( 'Two languages', 'ua-marketplace' ),
					'text'  => __( '<name_ua> and <description_ua> from Polylang or WPML translations', 'ua-marketplace' ),
					'pro'   => true,
				);
			}
			return $fields;
		}

		/** Global product attributes: slug without "pa_" => label. */
		private static function attribute_options(): array {
			$options = array( '' => __( '— Choose an attribute —', 'ua-marketplace' ) );
			foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attribute ) {
				$options[ $attribute->attribute_name ] = $attribute->attribute_label;
			}
			return $options;
		}

		public static function is_locked( array $field ): bool {
			return ! empty( $field['pro'] ) && ! MRKV_UAMP_IS_PRO;
		}

		/* ---------------------------------------------------------------- render */

		public static function render_section( string $slug, array $section ): void {
			echo '<h2 id="mrkv-uamp-' . esc_attr( $section['id'] ) . '">' . esc_html( $section['title'] ) . '</h2>';
			if ( ! empty( $section['description'] ) ) {
				echo '<p>' . esc_html( $section['description'] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $section['fields'] as $field ) {
				self::render_field( $slug, $field );
			}
			echo '</tbody></table>';
		}

		public static function render_field( string $slug, array $field ): void {
			$locked = self::is_locked( $field );
			$key    = self::option_key( $slug, $field['key'] );
			$id     = 'mrkv-uamp-' . $slug . '-' . $field['key'];
			$value  = $locked ? '' : get_option( $key, '' );
			$name   = $locked ? '' : ' name="' . esc_attr( $key ) . '"';
			$attrs  = $locked ? ' disabled' : '';
			foreach ( (array) ( $field['attributes'] ?? array() ) as $attr => $attr_value ) {
				$attrs .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $attr_value ) . '"';
			}
			$row_attrs = '';
			foreach ( (array) ( $field['row_attrs'] ?? array() ) as $attr => $attr_value ) {
				$row_attrs .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $attr_value ) . '"';
			}

			echo '<tr' . $row_attrs . ( $locked ? ' class="mrkv-uamp-locked"' : '' ) . '><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above
			if ( $locked ) {
				echo ' <span class="mrkv-uamp-pro-badge">Pro</span>';
			}
			echo '</th><td>';

			switch ( $field['type'] ) {
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '"' . $name . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
					foreach ( $field['options'] as $option => $label ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
					}
					echo '</select>';
					break;
				case 'checkbox':
					echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" value="1"' . $name . $attrs . checked( ! empty( $value ), true, false ) . '> ' . esc_html( $field['text'] ?? '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
					break;
				case 'textarea':
					echo '<textarea class="large-text" rows="4" id="' . esc_attr( $id ) . '"' . $name . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
					break;
				default:
					echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '"' . $name . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
			}
			if ( ! empty( $field['description'] ) ) {
				echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
			}
			echo '</td></tr>';
		}

		/* ---------------------------------------------------------------- save */

		/** Saves the submitted fields of a tab from $input (already unslashed). */
		public static function save( string $slug, array $input ): void {
			foreach ( self::sections( $slug ) as $section ) {
				foreach ( $section['fields'] as $field ) {
					if ( self::is_locked( $field ) || ! empty( $field['readonly'] ) ) {
						continue;
					}
					$key = self::option_key( $slug, $field['key'] );
					update_option( $key, self::sanitize( $field, $input[ $key ] ?? null ), false );
				}
			}
		}

		public static function sanitize( array $field, $value ) {
			switch ( $field['type'] ) {
				case 'checkbox':
					return empty( $value ) ? '' : '1';
				case 'select':
					$value = is_scalar( $value ) ? (string) $value : '';
					return array_key_exists( $value, $field['options'] ) ? $value : '';
				case 'textarea':
					return wp_kses_post( is_scalar( $value ) ? (string) $value : '' );
				default:
					return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
			}
		}
	}
}
