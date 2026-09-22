<?php

/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Base;

use \Inc\Base\BaseController;
use \Inc\Core\WCShop\WCShopCollation;
use \Inc\Core\XMLController;
use \Inc\Core\WCShopPromuaController;

class AjaxHandler extends BaseController
{
    public $rozetka_collation_script_time;

    public function register()
    {
        if( wp_doing_ajax() ) {
            add_action( 'wp_ajax_mrkvuamp_collation_action', array( $this, 'mrkvuamp_collation_action_cb' ) );
            add_action( 'wp_ajax_mrkvuamp_promuaxml_action', array( $this, 'mrkvuamp_promuaxml_action_cb' ) );
        }
    }

    public function mrkvuamp_collation_action_cb() {

        if ( ! check_ajax_referer( 'mrkv_uamrkpl_collation_form_nonce', 'nonce' )) { // 'nonce' defined in Enqueue php-class
        	wp_die();
        }

        $phpStart = microtime(true);

        if ( isset( $_REQUEST ) ) {
            $response = $_REQUEST;
        }

        // Get categories collations from '#mrkv_uamrkpl_collation_form' Form on Rozetka tab
        if ( is_array( $response )  ) {
            foreach ( $response as $key => $value ) {
                if ( strpos( $key, 'mrkv-uamp-') !== false ) {
                    $cats_collation_arr[$key] = ! empty( $value ) ? sanitize_text_field( $value ) : '';
                }
            }
        }
        update_option( 'mrkv_uamrkpl_collation_option', $cats_collation_arr );

        // Create WooCommerce internet-shop Object
        $mrkv_uamrkpl_shop = new WCShopCollation('shop');
        $mrkv_uamrkpl_shop_arr = (array) $mrkv_uamrkpl_shop;

        // Create XML-price for marketplace Rozetka
        $converter = new \Inc\Core\XMLController( 'rozetka' );
        $xml = $converter->array2xml( $mrkv_uamrkpl_shop_arr );

        $phpEnd = microtime(true);
        $execution_php_time = number_format($phpEnd - $phpStart, 2);
        $this->rozetka_collation_script_time = $execution_php_time; // Save script time in php-class property

        wp_send_json( array( 'rozetka_xml_created_event' => $execution_php_time ) ); // Return response: script time

        wp_die();
    }

    public function mrkvuamp_promuaxml_action_cb()
    {
        if ( ! check_ajax_referer( 'mrkv_uamrkpl_collation_form_nonce' )){ // 'nonce' defined in Enqueue php-class
        	wp_die();
        }

        $phpStart = microtime(true);

        if ( isset( $_REQUEST ) ) {
            $response = $_REQUEST;
        }

        // Create WooCommerce internet-shop Object
        $mrkv_uamrkpl_shop = new WCShopPromuaController('shop');
        $mrkv_uamrkpl_shop_arr = (array) $mrkv_uamrkpl_shop;

        // Create XML-price for marketplace PromUA
        $converter = new \Inc\Core\XMLController( 'promua' );

        if ( ! get_option( 'mrkv_uamrkpl_promua_background_proc_xml_chk' ) ) {
            $xml = $converter->array2promuaxml( $mrkv_uamrkpl_shop_arr, null ); // Async
        } else {
            if ( \get_option( 'mrkv_uamrkpl_promua_background_proc_xml_chk' ) ) {
                if ( \is_file( $converter->plugin_uploads_dir_path . '/promua_status.json' ) ) {
                    if ( ! \unlink( $converter->plugin_uploads_dir_path . '/promua_status.json' ) ) {
                        // \error_log( "promua_status.json cannot be deleted due to an error" );
                    } else {
                        // \error_log( "promua_status.json has been deleted" );
                    }
                }
            }
            $xml = $converter->array2promuaxmlpartly( $mrkv_uamrkpl_shop_arr ); // Background

            if ( get_option( 'mrkv_uamrkpl_promua_background_proc_xml_chk' ) && is_file( $converter->plugin_uploads_dir_path . '/promua_status.json' ) ) {
                if ( ! wp_next_scheduled( 'mrkvuamp_partial_update_xml_hook_promua' ) ) {
                    wp_schedule_event( time(), 'mrkvuamp_one_minute', 'mrkvuamp_partial_update_xml_hook_promua' );
                }
            }
        }

        $phpEnd = microtime(true);
        $execution_php_time = number_format($phpEnd - $phpStart, 2);
        $this->rozetka_collation_script_time = $execution_php_time; // Save script time in php-class property

        wp_send_json( array( 'rozetka_xml_created_event' => $execution_php_time ) ); // Return response: script time

        wp_die();
    }

}
