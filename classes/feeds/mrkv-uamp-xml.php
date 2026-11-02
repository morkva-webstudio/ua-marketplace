<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_XML' ) ) {
	/**
	 * Serialises the feed in three kinds of fragment — header, offers, footer — written by
	 * different PHP requests into one file.
	 *
	 * XMLWriter escapes values and CDATA. The frame tags that stay open across requests
	 * (<yml_catalog>, <shop>, <offers>) are fixed strings with no user data: an XMLWriter flushed
	 * with an element still open does not reliably close its start tag.
	 */
	class MRKV_UAMP_XML {
		public static function header( MRKV_UAMP_MAPPER $mapper ): string {
			$shop = $mapper->shop();
			/**
			 * Filters the <categories> rows. Pro adds Prom portal_id here.
			 *
			 * @param array            $rows   array( 'id', 'name', 'attributes'? )
			 * @param string           $slug
			 * @param MRKV_UAMP_MAPPER $mapper
			 */
			$categories = (array) apply_filters( 'mrkv_uamp_categories', $mapper->categories(), $mapper->slug(), $mapper );

			$w = self::writer();
			$w->writeElement( 'name', self::text( $shop['name'] ) );
			$w->writeElement( 'company', self::text( $shop['company'] ) );
			$w->writeElement( 'url', self::text( $shop['url'] ) );
			$w->startElement( 'currencies' );
			$w->startElement( 'currency' );
			$w->writeAttribute( 'id', self::text( $shop['currency'] ) );
			$w->writeAttribute( 'rate', '1' );
			$w->endElement();
			$w->endElement();
			$w->startElement( 'categories' );
			foreach ( $categories as $row ) {
				$w->startElement( 'category' );
				$w->writeAttribute( 'id', (string) $row['id'] );
				foreach ( (array) ( $row['attributes'] ?? array() ) as $name => $value ) {
					$w->writeAttribute( (string) $name, self::text( $value ) );
				}
				$w->text( self::text( $row['name'] ) );
				$w->endElement();
			}
			$w->endElement();

			return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. ( $mapper->doctype() ? "<!DOCTYPE yml_catalog SYSTEM \"shops.dtd\">\n" : '' )
				. '<yml_catalog date="' . esc_attr( wp_date( 'Y-m-d H:i' ) ) . '"><shop>'
				. $w->outputMemory()
				. "<offers>\n";
		}

		public static function offer( array $offer ): string {
			$w = self::writer();
			$w->startElement( 'offer' );
			foreach ( $offer['attributes'] as $name => $value ) {
				$w->writeAttribute( (string) $name, self::text( $value ) );
			}
			self::tags( $w, $offer['tags'] );
			$w->endElement();
			return $w->outputMemory() . "\n";
		}

		public static function footer(): string {
			return "</offers></shop></yml_catalog>\n";
		}

		private static function tags( XMLWriter $w, array $tags ): void {
			foreach ( $tags as $tag ) {
				$w->startElement( $tag['name'] );
				foreach ( (array) ( $tag['attributes'] ?? array() ) as $name => $value ) {
					$w->writeAttribute( (string) $name, self::text( $value ) );
				}
				if ( isset( $tag['children'] ) ) {
					self::tags( $w, $tag['children'] );
				} elseif ( ! empty( $tag['cdata'] ) ) {
					$w->writeCdata( str_replace( ']]>', ']]]]><![CDATA[>', self::text( $tag['value'] ) ) );
				} else {
					$w->text( self::text( $tag['value'] ) );
				}
				$w->endElement();
			}
		}

		private static function writer(): XMLWriter {
			$w = new XMLWriter();
			$w->openMemory();
			$w->setIndent( false );
			return $w;
		}

		/**
		 * Valid UTF-8 without the control characters XML 1.0 forbids (both marketplaces reject
		 * them; 1.x let them through and the whole file failed to parse).
		 */
		public static function text( $value ): string {
			$value = (string) $value;
			if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
				$value = (string) mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
			}
			return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		}
	}
}
