<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_PROM_MAPPER' ) ) {
	/**
	 * Prom.ua feed (support.prom.ua «Імпорт через YML-формат файлу»). Every published simple or
	 * variable product from a non-empty category is exported, without mapping, as in 1.x;
	 * <categoryId> is the product's first WC category.
	 *
	 * With Polylang the catalog is limited to the site-locale language, as 1.x did.
	 */
	class MRKV_UAMP_PROM_MAPPER extends MRKV_UAMP_MAPPER {
		/** @var int[]|null */
		private $category_ids;

		public function slug(): string {
			return 'promua';
		}

		public function picture_limit(): int {
			return 10;
		}

		/** Non-empty product categories (in the site-locale language with Polylang). */
		public function category_ids(): array {
			if ( null !== $this->category_ids ) {
				return $this->category_ids;
			}
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'orderby'    => 'id',
				'hide_empty' => true,
				'fields'     => 'ids',
			) );
			$ids  = array();
			$lang = self::polylang_language();
			foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
				if ( null === $lang || pll_get_term_language( (int) $term_id ) === $lang ) {
					$ids[] = (int) $term_id;
				}
			}
			$this->category_ids = $ids;
			return $ids;
		}

		/** Language slug 1.x filtered by (the site locale), or null without Polylang. */
		private static function polylang_language(): ?string {
			if ( ! function_exists( 'pll_get_term_language' ) ) {
				return null;
			}
			return (string) ( get_locale() ?: 'uk' );
		}

		public function categories(): array {
			$rows = array();
			foreach ( $this->category_ids() as $term_id ) {
				$term = get_term( $term_id, 'product_cat' );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$row = array(
					'id'   => $term_id,
					'name' => self::clean_text( $term->name ),
				);
				if ( $term->parent ) {
					$row['attributes'] = array( 'parentId' => (int) $term->parent );
				}
				$rows[] = $row;
			}
			return $rows;
		}

		public function product_query_args(): array {
			$ids  = $this->category_ids();
			$args = array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'relation' => 'AND',
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $ids ? $ids : array( 0 ),
					),
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'simple', 'variable' ),
					),
				),
			);
			$lang = self::polylang_language();
			if ( null !== $lang ) {
				$args['lang'] = $lang;
			}
			return $args;
		}

		protected function build_offer( WC_Product $product, ?WC_Product $variation ): ?array {
			$target = $variation ?: $product;
			$cats   = $product->get_category_ids();
			$tags   = array(
				self::tag( 'url', $this->url( $product, $variation ) ),
				self::tag( 'price', $target->get_regular_price() ),
				self::tag( 'currencyId', get_option( 'woocommerce_currency' ) ),
				self::tag( 'categoryId', $cats ? (string) reset( $cats ) : '' ),
			);
			foreach ( $this->pictures( $product, $variation ) as $url ) {
				$tags[] = self::tag( 'picture', $url );
			}
			$tags[] = self::tag( 'name', $this->title( $product, $variation ) );
			$tags[] = self::tag( 'vendor', $this->vendor( $product ) );
			$tags[] = self::tag( 'description', nl2br( (string) $product->get_description() ), array(), true );
			foreach ( $this->params( $product, $variation, ' | ' ) as $param ) {
				$attributes = array( 'name' => $param['name'] );
				$unit       = self::weight_param_unit( $param['name'] );
				if ( null !== $unit ) {
					$attributes['unit'] = $unit;
				}
				$tags[] = self::tag( 'param', $param['value'], $attributes );
			}
			$dimensions = $this->dimensions( $target );
			if ( $dimensions ) {
				$tags[] = array(
					'name'       => 'dimensions',
					'attributes' => array(),
					'children'   => $dimensions,
				);
			}
			$tags[] = self::tag( 'available', 'outofstock' === $target->get_stock_status() ? 'false' : 'true' );
			$tags[] = self::tag( 'quantity_in_stock', $this->quantity_in_stock( $target ) );
			$sku    = (string) $target->get_sku();
			if ( '' !== $sku ) {
				$tags[] = self::tag( 'vendorCode', $sku );
			}

			$attributes = $variation
				? array( 'group_id' => $product->get_id(), 'id' => $variation->get_id() )
				: array( 'id' => $product->get_id() );
			$attributes['available'] = $target->is_in_stock() ? 'true' : 'false';

			return array(
				'attributes' => $attributes,
				'tags'       => $tags,
			);
		}

		/** 1.x adds the store weight unit to a param named «Вага» / «Weight». */
		public static function weight_param_unit( string $name ): ?string {
			if ( 'Вага' !== $name && 'Weight' !== $name ) {
				return null;
			}
			$unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
			$map  = array(
				'kg' => 'кг',
				'g'  => 'г',
			);
			return $map[ $unit ] ?? $unit;
		}

		/** <dimensions> children in the units Prom wants (kg, cm); only the values the product has. */
		public function dimensions( WC_Product $target ): array {
			$children = array();
			$weight   = $target->get_weight();
			if ( '' !== (string) $weight && (float) $weight > 0 ) {
				$children[] = self::tag( 'weight', self::number( wc_get_weight( (float) $weight, 'kg' ) ), array( 'unit' => 'kg' ) );
			}
			foreach ( array( 'width', 'height', 'length' ) as $dimension ) {
				$value = $target->{'get_' . $dimension}();
				if ( '' !== (string) $value && (float) $value > 0 ) {
					$children[] = self::tag( $dimension, self::number( wc_get_dimension( (float) $value, 'cm' ) ), array( 'unit' => 'cm' ) );
				}
			}
			return $children;
		}

		private static function number( float $value ): string {
			return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
		}

		/** As 1.x: without stock management an in-stock (or backorder) product counts as 1. */
		public function quantity_in_stock( WC_Product $target ): int {
			$qty = (int) ( $target->get_stock_quantity() ?: 0 );
			if ( ! $target->get_manage_stock() ) {
				return $target->is_in_stock() || $target->is_on_backorder() ? 1 : 0;
			}
			return $qty;
		}
	}
}
