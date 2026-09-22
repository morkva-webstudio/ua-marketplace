<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core\WCShop;

use \Inc\Base\BaseController;
use \Inc\Core\WCShopController;
use \Inc\Core\WCShop\WCShopOffer;

class WCShopOfferVariable extends WCShopOffer {

    public $variation;

    public $product_type;

    public function set_variable_offer($id, $offers)
    {
        // Checkbox '{Marketplace} xml' custom field
        foreach ( $this->activations as $activation  ) {
            $slug =  \strtolower( $activation );
            $mrktplc_not_xml = get_post_meta( $id , "mrkvuamp_{$slug}_not_xml", true);
            if ( $mrktplc_not_xml && 'rozetka' == $slug ) return;
        }

        $this->_product = \wc_get_product( $id ); // Get variation product object
        $this->product_type = $this->_product->get_type();
        $variation_permalink = $this->_product->get_permalink();

        $variations_ids = $this->_product->get_children();

        // Variations loop
        foreach ( $variations_ids as $variation_id ) {

            $this->variation = wc_get_product( $variation_id ); // Get variation object

            $variation_attrs = $this->variation->get_attributes(); // Get variation attributes

            $offer = $this->set_offer_content( $id, $offers, $variation_id ); // XML tag <offer>

                $url = $this->set_url( $id, $offer, $variation_permalink, $variation_attrs ); // XML tag <url>

                $price = $this->set_price( $offer ); // XML tag <price>

                $currencyId = $this->set_currency_id( $offer ); // XML tag <currencyId>

                $categoryId = $this->set_category_id( $id, $offer ); // XML tag <categoryId>

                $picture = $this->set_picture( $id, $offer, $variation_id ); // XML tag <picture>

                $name = $this->set_variable_name( $id, $offer, $this->variation, $this->xml_tag_name ); // XML tag <name>

                $vendor = $this->set_vendor( $id, $offer ); // XML tag <vendor>

                $description = $this->set_description( $id, $offer, $variation_id, $this->xml_tag_description ); // XML tag <description>

                $param = $this->set_param( $id, $offer ); // XML tag <param>

                $stock_quantity = $this->set_stock_quantity($id, $offer, $offers); // XML tag <stock_quantity>

                if($this->variation->get_sku())
                {
                    $offer->addChild( 'article', $this->variation->get_sku() );
                }
        }
    }

    public function set_offer_content($id, $offers, $variation_id) // XML tag <offer>
    {
        $offer = $offers->addChild( 'offer' );
        $offer->addAttribute( 'group_id', $id );
        $offer->addAttribute( 'id', $variation_id );
        $is_available = $this->variation->is_in_stock() ? 'true' : 'false';
        $offer->addAttribute( 'available', $is_available );
        return $offer;
    }

    public function set_url($id, $offer, $variation_permalink, $variation_attrs) // XML tag <url>
    {
        return $offer->addChild( 'url',
            esc_html( $variation_permalink . '?' .
                $this->get_variation_params( $this->variation, $variation_attrs ) ) );
    }

    public function set_price($offer) // XML tag <price>
    {
        $price = $this->variation->get_regular_price();
        return $offer->addChild( 'price', $price );
    }

    public function set_currency_id( $offer ) // XML tag <currencyId>
    {
        return $offer->addChild( 'currencyId', $this->get_wc_currency_id() );
    }

    public function set_category_id($id, $offer) // XML tag <categoryId>
    {
        return $offer->addChild( 'categoryId', $this->get_wc_category_id() );
    }

    public function set_picture($id, $offer, $variation_id) // XML tag <picture>
    {
        $image_urls = $this->get_variable_image_urls( $id, $variation_id );
        if ( \is_array( $image_urls ) && ! empty( $image_urls ) ) {
            foreach ( $image_urls as $key => $value ) {
                if ( empty( $value ) ) continue;
                $offer->addChild( 'picture', $value );
            }
        }
    }

    public function get_variable_product_title($id, $variation) // XML tag <name>
    {
        $baseController = new BaseController();
        $this->slug_activations = $baseController->slug_activations;

        foreach ( $this->slug_activations as $slug  ) {
            $marketplace_title = get_post_meta( $id, "mrkvuamp_{$slug}_title", true ); // Get custom variation title
            $variation_name = $variation->get_name(); // Get product variation name with attribute variation names

            if ( empty( $marketplace_title ) ) {
                $product_title = $variation_name;
            }

            if ( ! empty( $marketplace_title ) ) {
                $product_title = $marketplace_title;
                // Add attribute variations names to custom variation title through hyphen
                $product_title .= ' -' . substr( $variation_name, strpos( $variation_name, "-" ) + 1 );
            }

            return $product_title;
        }
    }

    public function set_variable_name($id, $offer, $variation, $xml_tag) // XML tag <name>
    {
        $name = $this->get_variable_product_title( $id, $variation );
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $name = str_replace(array('', '&nbsp;'), array('', ' '), $name);
        $name = htmlspecialchars(strip_tags(trim($name)), ENT_XML1, 'UTF-8');
        return $offer->addChild( $xml_tag, $name );
    }

    public function set_vendor($id, $offer) // XML tag <vendor>
    {
        $vendor_name = $this->get_product_vendor( $id );
        return $offer->addChild( 'vendor', $vendor_name );
    }

    public function set_description($id, $offer, $variation_id, $xml_tag) // XML tag <description>
    {
        return $offer->addChildWithCDATA( $xml_tag, nl2br( $this->get_product_description( $id, $variation_id ) ) );
    }

    public function set_param($id, $offer) // XML tag <param>
    {
        $param_labels = array();
        $param_values = array();
        $params = $this->_product->get_attributes();                // All product attributes
        $variation_params = $this->variation->get_attributes();

        foreach ( $params as $key => $value ) {
            if ( array_key_exists( $key, $variation_params  ) ) {   // Attributes used for variations
                $param_labels[] = \wc_attribute_label( $key );
                $param_values[] = $this->variation->get_attribute( $key );
            } else {                                                // Other product attributes
                if ( false !== strpos( $key, 'pa_' ) ) {
                    $param_labels[] = \wc_attribute_label( $key );
                } else {
                    $param_labels[] = $value->get_name();
                }
                $param_values[] = $this->_product->get_attribute( $key );
                continue;
            }
        }

        for ( $i = 0; $i < \sizeof( $param_values ) ; $i++ ) {
            $param_name = str_replace('"', '', $param_labels[$i]);
            $param = $offer->addChild( 'param', $param_values[$i] );
            $param->addAttribute( 'name', $param_name );
        }
    }

    public function set_stock_quantity($id, $offer, $offers) // XML tag <stock_quantity>
    {
        $is_in_stock = $this->variation->is_in_stock();
        $stock_quantity = $this->variation->get_stock_quantity() ?? 0;

        if ( 0 === $stock_quantity && $is_in_stock ) {
            return $offer->addChild( 'stock_quantity', 1 );
        }

        return $offer->addChild( 'stock_quantity', $stock_quantity );
    }

    // Create GET parameters for product variation URLs
    public function get_variation_params($variation, $variation_attrs)
    {
        $attr_keys = array_keys( $variation_attrs );
        $params = array();

        for ( $i = 0; $i < \sizeof( $attr_keys );  $i++) {
            $key = $attr_keys[$i];
            $value = $variation_attrs[$key];
            $params[$i] = $key . '=' . $value;
        }

        return join( "&", $params );
    }

    // Get variable image URLs for <picture> xml-tag
    public function get_variable_image_urls($id, $variation_id)
    {
        // Get product image urls
        $product_image_urls = array();
        $product_image_urls = $this->get_product_image_urls( $id );
        // Get variation image urls
        $variation_image_urls = array();
        foreach ( $this->slug_activations as $slug  ) {
            if (  ! empty( get_post_meta( $variation_id , "mrkvuamp_{$slug}_variation_image", true) ) ) {
                $variation_image_urls[0] = get_post_meta( $variation_id , "mrkvuamp_{$slug}_variation_image", true);
                $product_image_urls[0] = $variation_image_urls[0];
            }
        }
        return $product_image_urls;
    }

}
