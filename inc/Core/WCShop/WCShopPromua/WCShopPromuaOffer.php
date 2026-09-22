<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core\WCShop\WCShopPromua;

use \Inc\Base\BaseController;
use \Inc\Core\WCShopPromuaController;

class WCShopPromuaOffer extends WCShopPromuaController {

    public $_product;

    public $product_type;

    public $activations = array();

    public $slug_activations = array();

    public function __construct()
    {
        $baseController = new BaseController();
        $this->activations = $baseController->activations;
        $this->slug_activations = $baseController->slug_activations;
    }

    // Get WooCommerce category id for <categoryId> xml-tag
    public function get_wc_promua_category_id($id)
    {
        // Get all product categories
        $this->_product = \wc_get_product( $id );
        $product_category_ids = $this->_product->get_category_ids();

        // Get first available category
        return $product_category_ids[0];
    }

    // Set <offer> xml-tag
    public function set_offer($id, $offers)
    {
        $this->_product = \wc_get_product( $id ); // Get product object from wc-site

        $wcShopOfferSimple = new WCShopPromuaOfferSimple();
        $wcShopOfferVariable = new WCShopPromuaOfferVariable();

        $this->product_type = $this->_product->get_type();
        if ( 'simple' == $this->product_type ) {
            $wcShopOfferSimple->set_simple_offer( $id, $offers );
        }

        if ( 'variable' == $this->product_type ) {
            $variations_ids = $this->_product->get_children();
            foreach ( $variations_ids as $variation_id ) { // Variations loop
                $wcShopOfferVariable->set_variable_offer( $id, $offers, $variation_id );
            }
        }
    }

    // Get currency value (UAH, USD, EUR, RUR) attribute for <currencyId> xml-tag
    public function get_wc_currency_id()
    {
        $wc_shop = new WCShopPromuaController();
        return $wc_shop->currencies[0];
    }

    // Get product image URL for <picture> xml-tag
    public function get_product_image_urls($id)
    {
        $images_urls = array();
        $main_image_id  = $this->_product->get_image_id();
        $images_urls[0] = wp_get_attachment_image_url( $main_image_id, 'full' );

        $attachment_ids = $this->_product->get_gallery_image_ids();
        foreach( $attachment_ids as $attachment_id ) {
            $images_urls[] =  wp_get_attachment_image_url( $attachment_id, 'full' );
        }
        return empty( $images_urls ) ? '' : $images_urls;
    }

    // Get product Title for <name> xml-tag
    public function get_product_title($id)
    {
        $this->_product = \wc_get_product( $id ); // Get product object
        $product_title = $this->_product->get_title();

        // Title from 'PromUA Title' custom field
        $mrktplc_title = get_post_meta( $id , "mrkvuamp_promua_title", true);
        if ( isset( $mrktplc_title ) && ! empty( $mrktplc_title ) ) return $mrktplc_title;

        return $product_title;
    }

    // Get product brand for <vendor> xml-tag
    public function get_product_vendor($id)
    {
        $this->_product = \wc_get_product( $id );
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
                $vendor_name = $this->_product->get_attribute( 'pa_' . $vendor_taxonomy );

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

    // Get product description for <description> xml-tag
    public function get_product_description($id, $variation_id=null)
    {
        $this->_product = \wc_get_product( $id ); // Get product object from WooCommerce internet shop
        $description = $this->_product->get_description();
        //     // Description from 'PromUA Description' custom field
        //     $mrktplc_description = get_post_meta( $id , "mrkvuamp_promua_description", true );
        //     if (  ! empty( $mrktplc_description ) ) $description = $mrktplc_description;
        return $description;
    }

}
