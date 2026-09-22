<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core\WCShop;

use \Inc\Core\WCShopController;
use \Inc\Core\WCShop\WCShopOffer;

class WCShopOfferSimple extends WCShopOffer {

    public function set_simple_offer($id, $offers)
    {
        // Checkbox '{Marketplace} xml' custom field
        foreach ( $this->slug_activations as $slug  ) {
            $mrktplc_not_xml = get_post_meta( $id , "mrkvuamp_{$slug}_not_xml", true);
            if ( $mrktplc_not_xml ) return;
        }

        $offer = $this->set_offer_content( $id, $offers ); // XML tag <offer>

            $url = $this->set_url( $id, $offer ); // XML tag <url>

            $price = $this->set_price( $offer ); // XML tag <price>

            $currencyId = $this->set_currency_id( $offer ); // XML tag <currencyId>

            $categoryId = $this->set_category_id( $id, $offer ); // XML tag <categoryId>

            $picture = $this->set_picture( $id, $offer ); // XML tag <picture>

            $name = $this->set_name( $id, $offer, $this->xml_tag_name ); // XML tag <name>

            $vendor = $this->set_vendor( $id, $offer ); // XML tag <vendor>
            
            if($this->_product->get_sku())
            {
                $offer->addChild( 'article', $this->_product->get_sku() );    
            }

            $description = $this->set_description( $id, $offer, $variation_id=null, $this->xml_tag_description ); // XML tag <description>

            $param = $this->set_param( $id, $offer ); // XML tag <param>

            $stock_quantity = $this->set_stock_quantity($id, $offer, $offers); // XML tag <stock_quantity>
    }

    public function set_offer_content($id, $offers) // XML tag <offer>
    {
        $offer = $offers->addChild( 'offer' );
        $offer->addAttribute('id', $id);
        $this->_product = \wc_get_product( $id ); // Get product object
        $is_available = $this->_product->is_in_stock() ? 'true' : 'false';
        $offer->addAttribute( 'available', $is_available );
        return $offer;
    }

    public function set_url($id, $offer) // XML tag <url>
    {
        return $offer->addChild( 'url', \get_permalink( $id ) );
    }

    public function set_price($offer) // XML tag <price>
    {
        $price = $this->_product->get_regular_price();
        return $offer->addChild( 'price', $price );
    }

    public function set_currency_id($offer) // XML tag <currencyId>
    {
        return $offer->addChild( 'currencyId', $this->get_wc_currency_id() );
    }

    public function set_category_id($id, $offer) // XML tag <categoryId>
    {
        return $offer->addChild( 'categoryId', $this->get_wc_category_id() );
    }

    public function set_picture($id, $offer) // XML tag <picture>
    {
        $image_urls = $this->get_product_image_urls( $id );
        if ( \is_array( $image_urls ) ) {
            foreach ( $image_urls as $key => $value ) {
                if ( empty( $value ) ) continue;
                $offer->addChild( 'picture', $value );
            }
        }
    }

    public function set_name($id, $offer, $xml_tag)
    {
        $name = $this->get_product_title( $id );
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
        $params = $this->_product->get_attributes();
        foreach ( $params as $key => $value ) {
            if ( false !== strpos( $key, 'pa_' ) ) {
                $param_labels[] = \wc_attribute_label( $key );
            } else {
                $param_labels[] = $value->get_name();
            }
            $param_values[] = $this->_product->get_attribute( $key );
            continue;
        }

        for ( $i = 0; $i < \sizeof( $param_values ) ; $i++ ) {
            $param_name = str_replace('"', '', $param_labels[$i]);
            $param = $offer->addChild( 'param', $param_values[$i] );
            $param->addAttribute( 'name', $param_name );
        }
    }

    public function set_stock_quantity($id, $offer, $offers) // XML tag <stock_quantity>
    {
        $is_in_stock = $this->_product->is_in_stock();
        $stock_quantity = $this->_product->get_stock_quantity() ?? 0;

        if ( 0 === $stock_quantity && $is_in_stock ) {
            return $offer->addChild( 'stock_quantity', 1 );
        }

        return $offer->addChild( 'stock_quantity', $stock_quantity );
    }

}
