<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MRKV_UAMP_LOG' ) ) {
	/**
	 * WooCommerce logger (WooCommerce → Status → Logs, source ua-marketplace). Files live in
	 * uploads/wc-logs, which WooCommerce protects. Never pass a token or password here.
	 */
	class MRKV_UAMP_LOG {
		const SOURCE = 'ua-marketplace';

		public static function error( string $message ): void {
			self::write( 'error', $message );
		}

		public static function warning( string $message ): void {
			self::write( 'warning', $message );
		}

		public static function info( string $message ): void {
			self::write( 'info', $message );
		}

		private static function write( string $level, string $message ): void {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
		}
	}
}
