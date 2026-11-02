<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_MAPPING_UI' ) ) {
	/**
	 * WooCommerce categories → Rozetka categories, as a table of the shop's category tree with a
	 * search picker (selectWoo) per row. Stored in the 1.x option `mrkv_uamrkpl_collation_option`
	 * ('mrkv-uamp-{term_id}' => rz_id), so 1.x mappings show up as they were.
	 *
	 * Only products from mapped categories (and their subcategories) go into the Rozetka feed.
	 */
	class MRKV_UAMP_MAPPING_UI {
		public static function render(): void {
			$terms   = self::tree();
			$mapping = MRKV_UAMP_ROZETKA_MAPPER::read_mapping();
			$paths   = MRKV_UAMP_ROZETKA_DIRECTORY::paths( array_values( $mapping ) );
			$count   = MRKV_UAMP_ROZETKA_DIRECTORY::count();
			?>
			<h2 id="mrkv-uamp-mapping"><?php esc_html_e( 'Category mapping', 'ua-marketplace' ); ?></h2>
			<p>
				<?php esc_html_e( 'Pick the Rozetka category for each of your categories. Only products from mapped categories and their subcategories go into the feed.', 'ua-marketplace' ); ?>
			</p>
			<p class="mrkv-uamp-mapping-summary">
				<strong>
					<?php
					echo esc_html( sprintf(
						/* translators: 1: mapped categories, 2: all categories */
						__( '%1$d of %2$d categories mapped', 'ua-marketplace' ),
						count( array_intersect_key( $mapping, $terms ) ),
						count( $terms )
					) );
					?>
				</strong>
				·
				<?php self::directory_status( $count ); ?>
			</p>
			<?php if ( ! $terms ) : ?>
				<p class="description"><?php esc_html_e( 'Your shop has no product categories yet.', 'ua-marketplace' ); ?></p>
				<?php
				return;
			endif;
			?>
			<table class="widefat striped mrkv-uamp-mapping">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Your category', 'ua-marketplace' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rozetka category', 'ua-marketplace' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $terms as $term_id => $row ) : ?>
						<?php
						$rz_id = $mapping[ $term_id ] ?? '';
						$text  = '' === $rz_id ? '' : ( isset( $paths[ (int) $rz_id ] ) ? $paths[ (int) $rz_id ] . ' (' . $rz_id . ')' : '#' . $rz_id );
						$id    = 'mrkv-uamp-map-' . $term_id;
						?>
						<tr>
							<td>
								<label for="<?php echo esc_attr( $id ); ?>" style="padding-left: <?php echo esc_attr( (string) ( 20 * $row['depth'] ) ); ?>px">
									<?php echo esc_html( ( $row['depth'] ? '— ' : '' ) . $row['term']->name ); ?>
								</label>
								<span class="description">(<?php echo esc_html( (string) $row['term']->count ); ?>)</span>
							</td>
							<td>
								<select id="<?php echo esc_attr( $id ); ?>" name="mrkv_uamp_map[<?php echo esc_attr( (string) $term_id ); ?>]" class="mrkv-uamp-rz-picker" style="width: 100%">
									<option value=""><?php esc_html_e( '— Not exported —', 'ua-marketplace' ); ?></option>
									<?php if ( '' !== $rz_id ) : ?>
										<option value="<?php echo esc_attr( $rz_id ); ?>" selected><?php echo esc_html( $text ); ?></option>
									<?php endif; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		}

		/** "Rozetka directory: N categories, updated …" or why it is empty, with a Refresh link. */
		private static function directory_status( int $count ): void {
			$meta  = MRKV_UAMP_ROZETKA_IMPORTER::meta();
			$error = MRKV_UAMP_ROZETKA_IMPORTER::last_error();
			if ( $count ) {
				echo esc_html( sprintf(
					/* translators: 1: number of Rozetka categories, 2: date */
					_n( 'Rozetka directory: %1$d category, updated %2$s', 'Rozetka directory: %1$d categories, updated %2$s', $count, 'ua-marketplace' ),
					$count,
					$meta['fetched_at'] ? wp_date( get_option( 'date_format' ), $meta['fetched_at'] ) : '—'
				) );
			} elseif ( '' === $error ) {
				esc_html_e( 'The Rozetka category directory is loading in the background. Until then, type a Rozetka category ID in the picker.', 'ua-marketplace' );
			}
			echo ' <a href="' . esc_url( MRKV_UAMP_ADMIN::directory_refresh_url() ) . '" class="mrkv-uamp-dir-refresh">' . esc_html__( 'Refresh', 'ua-marketplace' ) . '</a>';
			if ( '' !== $error ) {
				echo '<br><span class="mrkv-uamp-error">' . esc_html( sprintf(
					/* translators: %s: reason */
					__( 'Could not update the Rozetka category directory: %s. Type a Rozetka category ID in the picker meanwhile.', 'ua-marketplace' ),
					$error
				) ) . '</span>';
			}
		}

		/** All product categories, depth-first, parents before children: term_id => array( term, depth ). */
		public static function tree(): array {
			$terms = get_terms( array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			) );
			if ( ! is_array( $terms ) ) {
				return array();
			}
			$children = array();
			foreach ( $terms as $term ) {
				$children[ (int) $term->parent ][] = $term;
			}
			$out  = array();
			$walk = function ( int $parent, int $depth ) use ( &$walk, &$out, $children ) {
				foreach ( $children[ $parent ] ?? array() as $term ) {
					$out[ (int) $term->term_id ] = array( 'term' => $term, 'depth' => $depth );
					$walk( (int) $term->term_id, $depth + 1 );
				}
			};
			$walk( 0, 0 );
			// Orphans (parent not returned, e.g. filtered out by a language plugin) at the end.
			foreach ( $terms as $term ) {
				if ( ! isset( $out[ (int) $term->term_id ] ) ) {
					$out[ (int) $term->term_id ] = array( 'term' => $term, 'depth' => 0 );
				}
			}
			return $out;
		}

		/**
		 * Saves the submitted rows. Rozetka ids are digits; anything else is dropped. Rows of
		 * categories not on the form (other languages, created meanwhile) are kept as they were.
		 */
		public static function save( array $submitted ): void {
			$option = get_option( MRKV_UAMP_ROZETKA_MAPPER::COLLATION_OPTION, array() );
			$option = is_array( $option ) ? $option : array();
			foreach ( $submitted as $term_id => $rz_id ) {
				$term_id = absint( $term_id );
				if ( ! $term_id ) {
					continue;
				}
				$rz_id = is_scalar( $rz_id ) ? trim( (string) $rz_id ) : '';
				$option[ MRKV_UAMP_ROZETKA_MAPPER::COLLATION_PREFIX . $term_id ] = ctype_digit( $rz_id ) ? $rz_id : '';
			}
			update_option( MRKV_UAMP_ROZETKA_MAPPER::COLLATION_OPTION, $option );
		}
	}
}
