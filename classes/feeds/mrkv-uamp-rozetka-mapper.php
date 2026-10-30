<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_ROZETKA_MAPPER' ) ) {
	/**
	 * Rozetka feed (sellerhelp.rozetka.com.ua/p185). Only products from categories mapped to a
	 * Rozetka category are exported; <categoryId> is the shop's own (WC) category id and
	 * <category rz_id> links it to Rozetka.
	 *
	 * Mapping lives in the 1.x option `mrkv_uamrkpl_collation_option`: 'mrkv-uamp-{term_id}' => rz_id.
	 */
	class MRKV_UAMP_ROZETKA_MAPPER extends MRKV_UAMP_MAPPER {
		const COLLATION_OPTION = 'mrkv_uamrkpl_collation_option';
		const COLLATION_PREFIX = 'mrkv-uamp-';

		/** @var array<int, string>|null term_id => rz_id */
		private $mapping;

		public function slug(): string {
			return 'rozetka';
		}

		public function picture_limit(): int {
			return 15;
		}

		public function doctype(): bool {
			return true;
		}

		/** @return array<int, string> WC term_id => Rozetka category id, only mapped, existing terms. */
		public function mapping(): array {
			if ( null === $this->mapping ) {
				$this->mapping = self::read_mapping();
			}
			return $this->mapping;
		}

		public static function read_mapping(): array {
			$option = get_option( self::COLLATION_OPTION, array() );
			$map    = array();
			foreach ( is_array( $option ) ? $option : array() as $key => $rz_id ) {
				$key   = (string) $key;
				$rz_id = trim( (string) $rz_id );
				if ( '' === $rz_id || 0 !== strpos( $key, self::COLLATION_PREFIX ) ) {
					continue;
				}
				$term_id = (int) substr( $key, strlen( self::COLLATION_PREFIX ) );
				if ( $term_id > 0 && term_exists( $term_id, 'product_cat' ) ) {
					$map[ $term_id ] = $rz_id;
				}
			}
			return $map;
		}

		public function categories(): array {
			$mapping = $this->mapping();
			$names   = MRKV_UAMP_ROZETKA_DIRECTORY::names( array_values( $mapping ) );
			$rows    = array();
			foreach ( $mapping as $term_id => $rz_id ) {
				$name = $names[ (int) $rz_id ] ?? '';
				if ( '' === $name ) {
					$term = get_term( $term_id, 'product_cat' );
					$name = $term instanceof WP_Term ? $term->name : '';
				}
				$rows[] = array(
					'id'         => $term_id,
					'name'       => self::clean_text( $name ),
					'attributes' => array( 'rz_id' => $rz_id ),
				);
			}
			return $rows;
		}

		public function product_query_args(): array {
			$mapping = $this->mapping();
			return array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'relation' => 'AND',
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $mapping ? array_keys( $mapping ) : array( 0 ),
					),
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'simple', 'variable' ),
					),
				),
			);
		}

		/**
		 * The product's first mapped category. A product that sits only in a subcategory of a mapped
		 * one is exported too (the query includes children), so its nearest mapped ancestor is used;
		 * 1.x left <categoryId> empty there, which Rozetka rejects.
		 */
		public function category_id( WC_Product $product ): string {
			$mapping = $this->mapping();
			$cats    = $product->get_category_ids();
			foreach ( $cats as $term_id ) {
				if ( isset( $mapping[ (int) $term_id ] ) ) {
					return (string) $term_id;
				}
			}
			foreach ( $cats as $term_id ) {
				foreach ( get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
					if ( isset( $mapping[ (int) $ancestor ] ) ) {
						return (string) $ancestor;
					}
				}
			}
			return '';
		}

		protected function build_offer( WC_Product $product, ?WC_Product $variation ): ?array {
			$target = $variation ?: $product;
			$suffix = (string) $this->option( 'xml_tags_lang' );
			$tags   = array(
				self::tag( 'url', $this->url( $product, $variation ) ),
				self::tag( 'price', $target->get_regular_price() ),
				self::tag( 'currencyId', get_option( 'woocommerce_currency' ) ),
				self::tag( 'categoryId', $this->category_id( $product ) ),
			);
			foreach ( $this->pictures( $product, $variation ) as $url ) {
				$tags[] = self::tag( 'picture', $url );
			}
			$tags[] = self::tag( 'name' . $suffix, $this->title( $product, $variation ) );
			$tags[] = self::tag( 'vendor', $this->vendor( $product ) );
			$sku    = (string) $target->get_sku();
			if ( '' !== $sku ) {
				$tags[] = self::tag( 'article', $sku );
			}
			$tags[] = self::tag( 'description' . $suffix, nl2br( $this->description( $product ) ), array(), true );
			foreach ( $this->params( $product, $variation, ', ' ) as $param ) {
				$tags[] = self::tag( 'param', $param['value'], array( 'name' => $param['name'] ) );
			}
			$tags[] = self::tag( 'stock_quantity', $this->stock_quantity( $target ) );

			$attributes = $variation
				? array( 'group_id' => $product->get_id(), 'id' => $variation->get_id() )
				: array( 'id' => $product->get_id() );
			$attributes['available'] = $target->is_in_stock() ? 'true' : 'false';

			return array(
				'attributes' => $attributes,
				'tags'       => $tags,
			);
		}

		/** `mrkvuamp_rozetka_description` or the product description (the parent's for a variation). */
		public function description( WC_Product $product ): string {
			$custom = (string) $this->meta( $product->get_id(), 'description' );
			return '' !== $custom ? $custom : (string) $product->get_description();
		}

		/** Stock; an in-stock product without stock management counts as 1, as in 1.x. */
		public function stock_quantity( WC_Product $target ): int {
			$qty = (int) ( $target->get_stock_quantity() ?? 0 );
			return 0 === $qty && $target->is_in_stock() ? 1 : $qty;
		}
	}
}
