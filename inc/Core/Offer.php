<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core;

use \Inc\Base\BaseController;
use \Inc\Core\XMLController;

// If this file is called directly, abort.
defined( 'ABSPATH' ) or die();

class Offer extends BaseController {

    // XML tag <offer>

    public function is_available($id, $offer) // XML tag <offer> - available attribute
    {
        return $offer->is_in_stock() ? 'true' : 'false';
    }

    public function set_price($offer) // XML tag <price>
    {
        return $offer->get_regular_price();
    }

    public function set_currency_id($offer) // XML tag <currencyId>
    {
        return $this->get_wc_currency_id();
    }

    // Get currency value (UAH, USD, EUR, RUR) attribute for <currencyId> xml-tag
    public function get_wc_currency_id()
    {
        $wc_shop = new WCShopPromuaController();
        return $wc_shop->currencies[0];
    }

    public function set_category_id($offer) // XML tag <categoryId>
    {
        return $offer->get_category_ids()[0];
    }

    public function set_picture($offer) // XML tag <picture>
    {
        return $this->get_product_image_urls($offer);
    }

    // Get product image URLs for <picture> xml-tag
    public function get_product_image_urls($offer)
    {
        $images_urls = '';
        $main_image_id  = $offer->get_image_id();
        
        $main_url = wp_get_attachment_image_url( $main_image_id, 'full' );
        if ( $main_url ) {
            $images_urls = '<picture>' . esc_url( $main_url ) . '</picture>';
        }

        $attachment_ids = $offer->get_gallery_image_ids();
        foreach( $attachment_ids as $attachment_id ) {
            $gallery_url = wp_get_attachment_image_url( $attachment_id, 'full' );
            if ( $gallery_url ) {
                $images_urls .= '<picture>' . esc_url( $gallery_url ) . '</picture>';
            }
        }
        
        return empty( $images_urls ) ? '<picture> </picture>' : $images_urls;
    }

    public function set_vendor($offer) // XML tag <vendor>
    {
        $global_vendor = ( null !== \get_option( 'mrkv_uamrkpl_promua_global_vendor' ) )
            ? \get_option( 'mrkv_uamrkpl_promua_global_vendor' ) : '';
        $custom_vendor = ( null !== \get_option( 'mrkv_uamrkpl_promua_custom_vendor' ) )
            ? \get_option( 'mrkv_uamrkpl_promua_custom_vendor' ) : '';
        if ( isset( $global_vendor ) ) { // If Global Vendor is exists
            if ( ! empty( $global_vendor ) ) {
                return $global_vendor;
            }
        }

        if ( empty( $global_vendor ) ) {  // If Global Vendor is not exists
            // If `default brands WooCommerce` is active
            if ( 'vendor_woocommerce' == $custom_vendor ) {
                $id = $offer->get_id();
                $brands = get_the_terms( $id, 'product_brand' );

                if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
                    return $brands[0]->name;
                }
                return ' ';
            }
            // If `Perfect Brands for WooCommerce` plugin is active
            if ( 'vendor_pwb_brand' == $custom_vendor ) {
                $vendor_taxonomy = 'pwb-brand';
                $pwb_brand_obj = get_the_terms( $id, $vendor_taxonomy );

                if ( ! empty( $pwb_brand_obj[0]->name ) ) {
                    return $pwb_brand_obj[0]->name;
                }
                return ' ';
            }

            // If brands set by product attributes
            if ( 'vendor_by_attributes' == $custom_vendor ) {
                $vendor_taxonomy = \get_option('mrkv_uamrkpl_promua_vendor_by_attributes');
                $vendor_name = $offer->get_attribute( 'pa_' . $vendor_taxonomy );

                if ( ! empty( $vendor_name ) ) {
                    return $vendor_name;
                }
                return ' ';
            }

            // If brands set by other product metadata
            if ( 'vendor_all_possibilities' == $custom_vendor ) {
                $vendor_taxonomy = \get_option('mrkv_uamrkpl_promua_vendor_all_possibilities');
                $vendor_name = ( null !== get_post_meta( $id, $vendor_taxonomy ) )
                    ? get_post_meta( $id, $vendor_taxonomy ) : ' ';

                if ( ! empty( $vendor_name[0] ) ) {
                    return $vendor_name[0];
                }
                return ' ';
            }
        }
    }

    public function set_description($offer) // XML tag <description>
    {
        return $offer->get_description();
    }

    public function set_param($offer) // XML tag <param>
    {
        $param_labels = array();
        $param_values = array();
        $params = $offer->get_attributes();
        foreach ( $params as $key => $value ) {
            if ( false !== strpos( $key, 'pa_' ) ) {
                $param_labels[] = \wc_attribute_label( $key );
            } else {
                $param_labels[] = $value->get_name();
            }
            $param_values[] = $offer->get_attribute( $key );
            continue;
        }
        $param = array();
        for ( $i = 0; $i < \sizeof( $param_values ) ; $i++ ) {
            $param_name = str_replace('"', '', $param_labels[$i]);
            $param[] = '<param name="' . $param_name . '">' . $param_values[$i] . '</param>';
        }
        return implode( PHP_EOL, $param );
    }

    public function set_available($offer) // XML tag <available>
    {
        $stock_status = $offer->get_stock_status();
        if ( 'instock' == $stock_status ) {
            $availability = 'true';
        }
        if ( 'outofstock' == $stock_status ) {
            $availability = ' ';
        }
        if ( 'onbackorder' == $stock_status ) {
            $availability = 'false';
        }

        return $availability;
    }

}
