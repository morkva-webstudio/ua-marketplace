<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_MAPPER' ) ) {
	/**
	 * Marketplace rules for one feed: which products go in, the <shop>/<categories> header and the
	 * tags of each offer. The builder knows nothing about Rozetka or Prom; everything that differs
	 * between them lives in a subclass.
	 *
	 * An offer is array( 'attributes' => array( name => value ), 'tags' => list ), where each tag is
	 * array( 'name' => …, 'value' => …, 'attributes' => array(), 'cdata' => bool ) and may carry
	 * 'children' (a list of tags) instead of a value. Pro adds and replaces tags through the
	 * `mrkv_uamp_offer` filter with the static helpers at the bottom.
	 *
	 * Settings stay in the 1.x options (`mrkv_uamrkpl_{slug}_*`) and post meta (`mrkvuamp_{slug}_*`).
	 */
	abstract class MRKV_UAMP_MAPPER {
		/** Marketplace slug: rozetka | promua. */
		abstract public function slug(): string;

		/** Max <picture> tags per offer the marketplace accepts. */
		abstract public function picture_limit(): int;

		/** Rows for <categories>: array( 'id', 'name', 'parent_id'?, 'attributes'? ). */
		abstract public function categories(): array;

		/** Product query (WP_Query args) without paging; the builder adds the ID cursor. */
		abstract public function product_query_args(): array;

		/** One offer for a simple product ($variation null) or one variation of a variable one. */
		abstract protected function build_offer( WC_Product $product, ?WC_Product $variation ): ?array;

		public function option( string $name ) {
			return get_option( 'mrkv_uamrkpl_' . $this->slug() . '_' . $name );
		}

		public function meta( int $post_id, string $name ) {
			return get_post_meta( $post_id, 'mrkvuamp_' . $this->slug() . '_' . $name, true );
		}

		public function doctype(): bool {
			return false;
		}

		public function shop(): array {
			return array(
				'name'     => (string) ( $this->option( 'shop_name' ) ?: get_bloginfo( 'name' ) ),
				'company'  => (string) ( $this->option( 'company' ) ?: get_bloginfo( 'description' ) ),
				'url'      => (string) get_bloginfo( 'url' ),
				'currency' => (string) get_option( 'woocommerce_currency' ),
			);
		}

		/** @return array[] offers for $product; empty when it is excluded or of another type. */
		public function offers( WC_Product $product ): array {
			if ( $this->meta( $product->get_id(), 'not_xml' ) ) {
				return array();
			}
			$offers = array();
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product ) {
						$offers[] = $this->filtered_offer( $product, $variation );
					}
				}
			} elseif ( $product->is_type( 'simple' ) ) {
				$offers[] = $this->filtered_offer( $product, null );
			}
			return array_values( array_filter( $offers ) );
		}

		private function filtered_offer( WC_Product $product, ?WC_Product $variation ): ?array {
			$offer = $this->build_offer( $product, $variation );
			if ( null === $offer ) {
				return null;
			}
			/**
			 * Filters one offer before it is written. Return null to skip it.
			 *
			 * @param array|null      $offer     array( 'attributes' => …, 'tags' => … )
			 * @param string          $slug      rozetka | promua
			 * @param WC_Product      $product   the simple or parent variable product
			 * @param WC_Product|null $variation the variation, or null for a simple product
			 * @param MRKV_UAMP_MAPPER $mapper
			 */
			$offer = apply_filters( 'mrkv_uamp_offer', $offer, $this->slug(), $product, $variation, $this );
			if ( ! is_array( $offer ) ) {
				return null;
			}
			$offer['tags'] = $this->cap_pictures( $offer['tags'] );
			return $offer;
		}

		private function cap_pictures( array $tags ): array {
			$seen = 0;
			$out  = array();
			foreach ( $tags as $tag ) {
				if ( 'picture' === $tag['name'] && ++$seen > $this->picture_limit() ) {
					continue;
				}
				$out[] = $tag;
			}
			return $out;
		}

		/* ---------------------------------------------------------------- shared field rules */

		/** Vendor from the 1.x strategy options; ' ' where 1.x wrote a blank for "not found". */
		public function vendor( WC_Product $product ): string {
			$global = $this->option( 'global_vendor' );
			if ( ! empty( $global ) ) {
				return (string) $global;
			}
			$id = $product->get_id();
			switch ( $this->option( 'custom_vendor' ) ) {
				case 'vendor_woocommerce':
					$terms = get_the_terms( $id, 'product_brand' );
					return ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->name : ' ';
				case 'vendor_pwb_brand':
					$terms = get_the_terms( $id, 'pwb-brand' );
					return ! empty( $terms ) && ! is_wp_error( $terms ) && ! empty( $terms[0]->name ) ? $terms[0]->name : ' ';
				case 'vendor_by_attributes':
					$name = $product->get_attribute( 'pa_' . $this->option( 'vendor_by_attributes' ) );
					return '' !== (string) $name ? (string) $name : ' ';
				case 'vendor_all_possibilities':
					$key   = (string) $this->option( 'vendor_all_possibilities' );
					$value = '' !== $key ? get_post_meta( $id, $key, true ) : '';
					return is_scalar( $value ) && '' !== (string) $value ? (string) $value : ' ';
			}
			return '';
		}

		/**
		 * <param> rows. Variation attributes take the variation's value; empty values are skipped
		 * (Rozetka forbids empty params). Multi-value attributes are joined with $separator.
		 */
		public function params( WC_Product $product, ?WC_Product $variation, string $separator ): array {
			$variation_attrs = $variation ? $variation->get_attributes() : array();
			$rows            = array();
			foreach ( $product->get_attributes() as $key => $attribute ) {
				$label = false !== strpos( (string) $key, 'pa_' ) ? wc_attribute_label( $key ) : $attribute->get_name();
				if ( $variation && array_key_exists( $key, $variation_attrs ) ) {
					$values = array( $variation->get_attribute( $key ) );
				} elseif ( $attribute->is_taxonomy() ) {
					$values = wc_get_product_terms( $product->get_id(), $key, array( 'fields' => 'names' ) );
				} else {
					$values = $attribute->get_options();
				}
				$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ), 'strlen' ) );
				if ( ! $values ) {
					continue;
				}
				$rows[] = array(
					'name'  => str_replace( '"', '', (string) $label ),
					'value' => implode( $separator, $values ),
				);
			}
			return $rows;
		}

		/**
		 * Main image (or the `mrkvuamp_{slug}_image` override), then the gallery. For a variation
		 * the main image is replaced by `mrkvuamp_{slug}_variation_image` when set — as in 1.x Free.
		 */
		public function pictures( WC_Product $product, ?WC_Product $variation ): array {
			$main     = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
			$override = $this->meta( $product->get_id(), 'image' );
			if ( ! empty( $override ) ) {
				$main = $override;
			}
			if ( $variation ) {
				$override = $this->meta( $variation->get_id(), 'variation_image' );
				if ( ! empty( $override ) ) {
					$main = $override;
				}
			}
			$urls = array( $main );
			foreach ( $product->get_gallery_image_ids() as $image_id ) {
				$urls[] = wp_get_attachment_image_url( $image_id, 'full' );
			}
			return array_values( array_filter( array_map( 'strval', $urls ), 'strlen' ) );
		}

		/**
		 * Offer name. A variation whose WooCommerce name is just the parent's (WC adds attributes to
		 * the name only for up to two of them) gets its attribute values appended, because both
		 * marketplaces reject duplicate names. A custom marketplace title replaces the part before
		 * the first " - ", as in 1.x.
		 */
		public function title( WC_Product $product, ?WC_Product $variation ): string {
			$custom = (string) $this->meta( $product->get_id(), 'title' );
			if ( ! $variation ) {
				return self::clean_text( '' !== $custom ? $custom : $product->get_title() );
			}

			$name   = $variation->get_name();
			$parent = $product->get_name();
			if ( $name === $parent ) {
				$values = array();
				foreach ( array_keys( $variation->get_attributes() ) as $key ) {
					$value = (string) $variation->get_attribute( $key );
					if ( '' !== $value ) {
						$values[] = $value;
					}
				}
				if ( $values ) {
					$name = $parent . ' - ' . implode( ', ', $values );
				}
			}
			if ( '' !== $custom ) {
				// The attribute part is what follows the parent name; 1.x cut at the first "-",
				// which broke names like "T-shirt - Red".
				if ( 0 === strpos( $name, $parent ) ) {
					$suffix = substr( $name, strlen( $parent ) );
				} else {
					$dash   = strpos( $name, ' - ' );
					$suffix = false === $dash ? '' : substr( $name, $dash );
				}
				$name = $custom . $suffix;
			}
			return self::clean_text( $name );
		}

		/**
		 * Offer URL. For a variation WooCommerce's own permalink (parent + attribute_* args) is used:
		 * it preselects the variation. 1.x Rozetka wrote `pa_color=…` without the `attribute_` prefix
		 * (no preselection) and 1.x Prom appended the args twice.
		 */
		public function url( WC_Product $product, ?WC_Product $variation ): string {
			return $variation ? (string) $variation->get_permalink() : (string) get_permalink( $product->get_id() );
		}

		/** Plain text for name-like tags: no tags, entities decoded once, no non-breaking spaces. */
		public static function clean_text( string $text ): string {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
			$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = str_replace( array( "\xC2\xA0", '&nbsp;' ), ' ', $text );
			return trim( $text );
		}

		/* ---------------------------------------------------------------- tag helpers (Pro) */

		public static function tag( string $name, $value, array $attributes = array(), bool $cdata = false ): array {
			return array(
				'name'       => $name,
				'value'      => (string) $value,
				'attributes' => $attributes,
				'cdata'      => $cdata,
			);
		}

		public static function find_tag( array $offer, string $name ): ?int {
			foreach ( $offer['tags'] as $i => $tag ) {
				if ( $tag['name'] === $name ) {
					return $i;
				}
			}
			return null;
		}

		/** Replaces the first tag $name, or inserts it after $after (appends when $after is missing). */
		public static function set_tag( array $offer, array $tag, string $after = '' ): array {
			$i = self::find_tag( $offer, $tag['name'] );
			if ( null !== $i ) {
				$offer['tags'][ $i ] = $tag;
				return $offer;
			}
			$pos = '' !== $after ? self::find_tag( $offer, $after ) : null;
			if ( null === $pos ) {
				$offer['tags'][] = $tag;
			} else {
				array_splice( $offer['tags'], $pos + 1, 0, array( $tag ) );
			}
			return $offer;
		}

		public static function remove_tags( array $offer, string $name ): array {
			$offer['tags'] = array_values( array_filter( $offer['tags'], function ( $tag ) use ( $name ) {
				return $tag['name'] !== $name;
			} ) );
			return $offer;
		}
	}
}
