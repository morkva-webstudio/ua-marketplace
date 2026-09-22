<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core\WCShop;

use \Inc\Base\BaseController;
use \Inc\Core\WCShopController;

class WCShopOffer extends WCShopController {

    public $_product;

    public $product_type;

    public $activations = array();

    public $slug_activations = array();

    public $xml_tag_name;

    public $xml_tag_description;

    public function __construct()
    {
        $baseController = new BaseController();
        $this->activations = $baseController->activations;
        $this->slug_activations = $baseController->slug_activations;
        $this->xml_tag_name = 'name' . get_option( 'mrkv_uamrkpl_rozetka_xml_tags_lang' );
        $this->xml_tag_description = 'description' . get_option( 'mrkv_uamrkpl_rozetka_xml_tags_lang' );
    }

    // Set <offer> xml-tag
    public function set_offer($id, $offers)
    {
        $this->_product = \wc_get_product( $id ); // Get product object from collation list

        $wcShopOfferSimple = new WCShopOfferSimple();
        $wcShopOfferVariable = new WCShopOfferVariable();

        $this->product_type = $this->_product->get_type();
        if ( 'simple' == $this->product_type ) {
            $wcShopOfferSimple->set_simple_offer( $id, $offers );
        }

        if ( 'variable' == $this->product_type ) {
            $wcShopOfferVariable->set_variable_offer( $id, $offers );
        }
    }

    // Get product params (attributes) for <param> xml-tag
    public function get_product_attributes($id)
    {
        $param_labels = array();
        $param_values = array();
        $params = $this->_product->get_attributes();

        foreach ( $params as $key => $value ) {
            $param_labels[] = \wc_attribute_label( $key );
            $param_values[] = $this->_product->get_attribute( $key );
        }
        return [ $param_labels, $param_values ];
    }

    // Get product Title for <name> xml-tag
    public function get_product_title($id)
    {
        $this->_product = \wc_get_product( $id ); // Get product object from collation list
        $product_title = $this->_product->get_title();

        foreach ( $this->slug_activations as $slug  ) {
            // Title from '{Marketplace} Title' custom field
            $mrktplc_title = get_post_meta( $id , "mrkvuamp_{$slug}_title", true);
            if ( isset( $mrktplc_title ) && ! empty( $mrktplc_title ) ) return $mrktplc_title;
        }
        return $product_title;
    }

    // Get product description for <description> xml-tag
    public function get_product_description($id, $variation_id=null)
    {
        $this->_product = \wc_get_product( $id ); // Get product object from collation list
        $description = $this->_product->get_description();
        // Description from 'Rozetka Description' custom field
        $mrktplc_description = get_post_meta( $id , "mrkvuamp_rozetka_description", true );
        if (  ! empty( $mrktplc_description ) ) $description = $mrktplc_description;
        return $description;
    }

    // Get product short description
    public function get_product_short_description()
    {
        $short_description = $this->_product->get_short_description();
        return $short_description;
    }

    // Get product brand for <vendor> xml-tag
    public function get_product_vendor($id)
    {
        $global_vendor = ( null !== \get_option( 'mrkv_uamrkpl_rozetka_global_vendor' ) )
            ? \get_option( 'mrkv_uamrkpl_rozetka_global_vendor' ) : '';
        $custom_vendor = ( null !== \get_option( 'mrkv_uamrkpl_rozetka_custom_vendor' ) )
            ? \get_option( 'mrkv_uamrkpl_rozetka_custom_vendor' ) : '';
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
                $vendor_taxonomy = \get_option('mrkv_uamrkpl_rozetka_vendor_by_attributes');
                $vendor_name = $this->_product->get_attribute( 'pa_' . $vendor_taxonomy );

                if ( ! empty( $vendor_name ) ) {
                    return $vendor_name;
                }
                return ' ';
            }

            // If brands set by other product metadata
            if ( 'vendor_all_possibilities' == $custom_vendor ) {
                $vendor_taxonomy = \get_option('mrkv_uamrkpl_rozetka_vendor_all_possibilities');
                $vendor_name = ( null !== get_post_meta( $id, $vendor_taxonomy ) )
                    ? get_post_meta( $id, $vendor_taxonomy ) : ' ';

                if ( ! empty( $vendor_name[0] ) ) {
                    return $vendor_name[0];
                }
                return ' ';
            }
        }
    }

    // Get product image URLs for <picture> xml-tag
    public function get_product_image_urls($id)
    {
        $image_urls = array();
        $image_id  = $this->_product->get_image_id(); // Get main product image id
        $image_urls[0] = wp_get_attachment_image_url( $image_id, 'full' );

        foreach ( $this->slug_activations as $slug  ) {
            // Image from '{Marketplace} Image URL' custom field
            if (  ! empty( get_post_meta( $id , "mrkvuamp_{$slug}_image", true) ) ) {
                $image_urls[0] = get_post_meta( $id , "mrkvuamp_{$slug}_image", true);
            }
        }

        // If product image gallery is not exists
        if ( empty( $this->_product->get_gallery_image_ids() ) && ! empty( $image_urls ) ) {
            return $image_urls;
        }

        // If exists product image gallery
        $gallery_image_ids = $this->_product->get_gallery_image_ids(); // Get product gallery ids
        $gallery_image_urls = array();

        foreach ( $gallery_image_ids as $gallery_image_id ) {
            $gallery_image_urls[] = wp_get_attachment_image_url( $gallery_image_id, 'full' );
        }
        return \array_merge( $image_urls, $gallery_image_urls );
    }

    // Get WooCommerce category id for <categoryId> xml-tag
    public function get_wc_category_id()
    {
        if ( empty( get_option( 'mrkv_uamrkpl_collation_option' ) ) ) {
            return;
        }
        // Get all product categories
        $product_category_ids = $this->_product->get_category_ids();
        // Get user collated categories
        $wc_collation_categories_ids = $this->get_wc_collation_categories_ids();

        foreach ( $product_category_ids as $key => $value ) {
            if ( in_array( $value, $wc_collation_categories_ids ) ) {
                return $value;
            }
        }
    }

    // Get marketplace category id for <categoryId> xml-tag (old function)
    public function get_marketplace_category_id()
    {
        if ( empty( get_option( 'mrkv_uamrkpl_collation_option' ) ) ) {
            return;
        }
        // Get all product categories
        $product_category_ids = $this->_product->get_category_ids();

        // Get wc-categories and marketplace-categories collation array
        $collation_option_ids = get_option( 'mrkv_uamrkpl_collation_option' );
        foreach ( $collation_option_ids as $key => $value ) {
            // Get first wc-category id
            $wc_cat_id = substr( $key , strpos( $key, 'mrkv-uamp-' ) + strlen( 'mrkv-uamp-' ) );
            // Is set marketplace-category?
            if ( ! empty( $value ) && \in_array( $wc_cat_id, $product_category_ids ) ) {
                return $value;
            }
        }
    }

    // Get currency value (UAH, USD, EUR, RUR) attribute for <currencyId> xml-tag
    public function get_wc_currency_id()
    {
        $wc_shop = new WCShopController();
        return $wc_shop->currencies[0];
    }

}
