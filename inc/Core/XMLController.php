<?php
/**
 * @package 	MrkvUAMmarketplaces
 */

namespace Inc\Core;

use \Inc\Base\BaseController;
use \Inc\Core\WCShopController;
use \Inc\Core\OfferPromua;
use \Inc\Core\WCShopPromuaController;
use \Inc\Core\WCShop\WCShopOffer;
use \Inc\Core\WCShop\WCShopPromua\WCShopPromuaOffer;

require_once ('SimpleXMLElementExtended.php');

class XMLController extends BaseController {

    public $marketplace; // замість хардкоду mrkvuamprozetka.xml (може бути 'rozetka', 'promua')

    public $xml_header;

    public $current_date;

    public $category_name;

    public $xml_rozetka_filepath;
    public $xml_promua_filepath;

    public $plugin_uploads_dir;

    public $plugin_uploads_rozetka_xmlname;
    public $plugin_uploads_promua_xmlname;

    public $plugin_uploads_dir_path;
    public $plugin_uploads_dir_url;

    public $site_total_product_qty;

    public $promua_offer_product;
    public $promua_offer_variation;

    public function __construct($marketplace)
    {
        if ( ! \class_exists( 'WooCommerce' ) ) {
            return;
        }
        global $woocommerce, $product;

        $this->marketplace = $marketplace;

        $this->current_date = \date("Y-m-d H:i");

        $this->xml_header = '<yml_catalog date="' . $this->current_date . '"></yml_catalog>';

        $baseController = new BaseController();

        $this->plugin_uploads_dir = $baseController->plugin_uploads_dir;

        $this->plugin_uploads_rozetka_xmlname = $baseController->plugin_uploads_rozetka_xmlname;
        $this->plugin_uploads_promua_xmlname = $baseController->plugin_uploads_promua_xmlname;

        $this->plugin_uploads_dir_path = $baseController->plugin_uploads_dir_path;
        $this->plugin_uploads_dir_url = $baseController->plugin_uploads_dir_url;

        $this->xml_rozetka_filepath = $baseController->plugin_uploads_dir_path . $this->plugin_uploads_rozetka_xmlname;
        $this->xml_promua_filepath = $baseController->plugin_uploads_dir_path . $this->plugin_uploads_promua_xmlname;

        $this->site_total_product_qty = $this->get_total_site_product_quantity();
    }

    public function array2xml($array, $xml = null)
    {
        if ( $xml === null ) {
            $xml = new SimpleXMLElementExtended( "<?xml version='1.0' encoding='UTF-8'?>
                <!DOCTYPE yml_catalog SYSTEM 'shops.dtd'>" . $this->xml_header );
        }
        $wcShopController = new WCShopController();
        $wcShopOffer = new WCShopOffer();

        $shop = $xml->addChild('shop'); // XML tag <shop>

        foreach( $array as $key => $value ){
            if ( is_array( $value ) ) {

                if ( 'currencies' == $key ) { // XML tag <currencies>
                    $currencies = $shop->addChild( 'currencies' );
                    $currency = $currencies->addChild( 'currency' );
                    $currency->addAttribute( 'id', $value[0] );
                    $currency->addAttribute( 'rate', "1" );

                } else if ( 'categories' == $key ) { // XML tag <categories>
                    $categories = $shop->addChild( 'categories' );
                    foreach ($value as $k => $v) {
                        if ( $v ) {
                            $category = $categories->addChild( 'category',
                                $wcShopController->get_collation_category_name_by_id( $v ) );
                            $category->addAttribute( 'id', $k );
                            $category->addAttribute( 'rz_id', $v );
                        }
                    }
                } else if ( 'offers' == $key ) { // XML tag <offers>
                    $offers = $shop->addChild( 'offers' );
                    foreach ($value as $k => $v) {
                        if ( $v ) {
                            $offer = $wcShopOffer->set_offer( $v, $offers );
                        }
                    }
                } else {
                    $this->array2xml( $value, $shop->addChild( $key ) );
                }
            } else {
                if ( ! \is_numeric( $key ) ) {
                    $shop->addChild( $key, $value );
                }
            }
        }

        // Before create new xml remove old xml
        if ( \file_exists( $this->xml_rozetka_filepath ) && \is_file( $this->xml_rozetka_filepath ) ) {
            \chmod( $this->xml_rozetka_filepath, 0777 );
            if ( ! \unlink( $this->xml_rozetka_filepath ) ) {
                //\error_log( "xml-file cannot be deleted due to an error" );
            }
            else {
                //\error_log( "xml-file has been deleted" );
            }
        }

        // Create XML-file
        // header('Clear-Site-Data: "cache"');
        // header("Cache-Control: no-cache, must-revalidate");
        // header("Content-Type: application/xml; charset=utf-8");
        clearstatcache();

        // Save the output to a variable
        $content = $xml->asXML();

        // Now open a file to write to
        $handle = fopen( $this->xml_rozetka_filepath, "w" );

        // Write the contents to the file
        fwrite( $handle, $content );

        //Close the file
        fclose( $handle );

        return $xml->asXML( $this->xml_rozetka_filepath );
    }

    public function last_xml_file_date()
    {
        // For remove xml link on 'Rozetka' tab when xml-file is not exists yet
        if ( 'mrkv_ua_marketplaces_rozetka' == $_GET['page'] ) {
            // header('Clear-Site-Data: "cache"'); // Clear browser cache for read last xml file
        }

        if ( ! \file_exists( $this->xml_rozetka_filepath ) ) { // This if may be only here!
            return;
        }

        // Add date and time after xml-link
        if ( isset($_POST["mrkvuamp_submit_collation"] ) ) :
            echo '<span>( ' . date( " d.m.Y H:i:s" ) . ' UTC )</span>';
        else :
            echo '<span>( ' . clearstatcache() . date( " d.m.Y H:i:s", filemtime( $this->xml_rozetka_filepath ) ) . ' UTC )</span>';
        endif;
    }

    public function last_promuaxml_file_date()
    {
        // For remove xml link on 'PromUA' tab when xml-file is not exists yet
        if ( 'mrkv_ua_marketplaces_promua' == $_GET['page'] ) {
            // header('Clear-Site-Data: "cache"'); // Clear browser cache for read last xml file
        }

        if ( ! \file_exists( $this->xml_promua_filepath ) ) { // This if may be only here!
            return;
        }

        // Add date and time after xml-link
        if ( isset($_POST["mrkvuamp_submit_promuaxml"] ) ) :
            echo '<span>( ' . date( " d.m.Y H:i:s" ) . ' UTC )</span>';
        else :
            echo '<span>( ' . clearstatcache() . date( " d.m.Y H:i:s", filemtime( $this->xml_promua_filepath ) ) . ' UTC )</span>';
        endif;
    }

    public function get_total_site_product_quantity()
    {
        // Get total product quantity on the site
        $count_posts = wp_count_posts( 'product' );

        return $count_posts->publish;
    }

    public function array2promuaxml($array, $xml = null)
    {
        if ( $xml === null ) {
            $xml = new SimpleXMLElementExtended( "<?xml version='1.0' encoding='UTF-8'?>" . $this->xml_header );
        }
        $wcShopPromuaController = new WCShopPromuaController();
        $wcShopOffer = new WCShopPromuaOffer();

        $shop = $xml->addChild('shop'); // XML tag <shop>

        foreach( $array as $key => $value ){
            if ( is_array( $value ) ) {

                if ( 'currencies' == $key ) { // XML tag <currencies>
                    $currencies = $shop->addChild( 'currencies' );
                    $currency = $currencies->addChild( 'currency' );
                    $currency->addAttribute( 'id', $value[0] );
                    $currency->addAttribute( 'rate', "1" );

                } else if ( 'categories' == $key ) { // XML tag <categories>
                    $categories = $shop->addChild( 'categories' );
                    foreach ($value[0] as $k => $v) {
                        if ( $v ) {
                            $category = $categories->addChild( 'category',
                                $wcShopPromuaController->get_promua_category_name_by_id( $v ) );
                            $category->addAttribute( 'id', $v );
                                $parentCategoryID = $wcShopPromuaController->get_parent_category_id($v);
                            if ( $parentCategoryID ) {
                                $category->addAttribute( 'parentId', $parentCategoryID );
                            }
                        }
                    }
                } else if ( 'offers' == $key ) { // XML tag <offers>
                    $offers = $shop->addChild( 'offers' );
                    foreach ($value as $k => $v) {
                        if ( $v ) {
                            $offer = $wcShopOffer->set_offer( $v, $offers );
                        }
                    }
                } else {
                    $this->array2promuaxml( $value, $shop->addChild( $key ) );
                }
            } else {
                if ( ! \is_numeric( $key ) ) {
                    $shop->addChild( $key, $value );
                }
            }
        }

        // Before create new xml remove old xml
        if ( \file_exists( $this->xml_promua_filepath ) && \is_file( $this->xml_promua_filepath ) ) {
            \chmod( $this->xml_promua_filepath, 0777 );
            if ( ! \unlink( $this->xml_promua_filepath ) ) {
                //\error_log( "xml-file cannot be deleted due to an error" );
            }
            else {
                //\error_log( "xml-file has been deleted" );
            }
        }

        // Create XML-file
        // header('Clear-Site-Data: "cache"');
        // header("Cache-Control: no-cache, must-revalidate");
        // header("Content-Type: application/xml; charset=utf-8");
        clearstatcache();

        // Save the output to a variable
        $content = $xml->asXML();

        // Now open a file to write to
        $handle = fopen( $this->xml_promua_filepath, "w" );

        // Write the contents to the file
        fwrite( $handle, $content );

        //Close the file
        fclose( $handle );

        return $xml->asXML( $this->xml_promua_filepath );
    }

    public function array2promuaxmlpartly()
    {
        $wcShopPromuaController = new WCShopPromuaController();
        $wcShopPromuaOfferPro = new WCShopPromuaOffer();
        $offerPromua = new OfferPromua();

        $promua_temp_xmlname = 'tmp_' . $this->plugin_uploads_promua_xmlname;
        $promua_temp_xmlpath = $this->plugin_uploads_dir_path . $promua_temp_xmlname;
        $promua_status_json = $this->plugin_uploads_dir_path . '/promua_status.json';

        $categories = $wcShopPromuaController->categories[0];
        $interval = DAY_IN_SECONDS;
        $limit = absint( get_option( 'mrkv_uamrkpl_promua_background_proc_xml_step', 200 ) );
        $count_offers = count( $wcShopPromuaController->offers );

        if ( $limit < 1 ) {
            $limit = 200;
        }

        if ( $count_offers <= 0 ) {
            if ( is_file( $promua_temp_xmlpath ) ) {
                unlink( $promua_temp_xmlpath );
            }
            if ( is_file( $promua_status_json ) ) {
                unlink( $promua_status_json );
            }
            return $this->array2promuaxml( (array) $wcShopPromuaController, null );
        }

        if ( $count_offers <= $limit ) {
            if ( is_file( $promua_temp_xmlpath ) ) {
                unlink( $promua_temp_xmlpath );
            }
            if ( is_file( $promua_status_json ) ) {
                unlink( $promua_status_json );
            }
            return $this->array2promuaxml( (array) $wcShopPromuaController, null );
        }

        if ( is_file( $promua_status_json ) ) {
            $status = json_decode( file_get_contents( $promua_status_json ), true );
            if ( ! is_array( $status ) ) {
                $status = array(
                    'date'  => 0,
                    'total' => 0,
                    'step'  => 0
                );
            }
        } else {
            $status = array(
                'date'  => 0,
                'total' => 0,
                'step'  => 0
            );
        }

        if ( $status['total'] == $status['step'] && $status['date'] + $interval < time() ) {
            error_log('Крок 0 - створюємо тимчасовий xml-файл (total == step && date + interval < time())');

            // XML Header
            $xml = '<?xml version="1.0" encoding="UTF-8"?>
<yml_catalog date="' . date('Y-m-d H:i') . '">
    <shop>
        <name>' . $wcShopPromuaController->name . '</name>
        <company>' . $wcShopPromuaController->company . '</company>
        <url>' . $wcShopPromuaController->url . '</url>
        <currencies>
            <currency id="' . $wcShopPromuaController->currencies[0] . '" rate="1"/>
        </currencies>';

            $xml .= '<categories>' . PHP_EOL;
            foreach ( $categories as $key => $value) {
                $xml .= '<category id="' . $value .
                    '" parentId="' . $wcShopPromuaController->get_parent_category_id( $value ) .
                    '">' . $wcShopPromuaController->get_promua_category_name_by_id( $value ) . '</category>' . PHP_EOL;
            }
            $xml .= '</categories>' . PHP_EOL;
            $xml .= '<offers>' . PHP_EOL;

            file_put_contents( $promua_temp_xmlpath, $xml );

            $status = array(
                'date'  => time(),
                'total' => ceil( $count_offers / $limit ),
                'step'  => 0
            );
            file_put_contents( $this->plugin_uploads_dir_path . '/promua_status.json', json_encode( $status ) );

            error_log( 'Крок ' . $status['step'] . ' з ' . $status['total'] . ' - виконаний.' );

        } elseif ( $status['total'] != $status['step'] ) {
            $status_step = intval( $status['step'] ) + 1;
            error_log('Виконання наступних кроків. Крок ' . $status_step . '. (total != step)' );
            $start = $status['step'] * $limit;
            $xml = isset( $xml ) ? $xml : '';

            $ids = $wcShopPromuaController->get_wc_offers_ids(); // Get all products ids

            // Xml Offer
            for ( $i = $start; $i < ( $status['step'] + 1 ) * $limit; $i++ ) {
                if ( $i == $count_offers ) break;

                $offer_product = \wc_get_product( $ids[$i] );
                $this->promua_offer_product = $offer_product;
                if ( is_object( $offer_product ) ) {

                    $offer_product_type = is_object( $offer_product ) ? $offer_product->get_type() : '';

                    if ( ! \shortcode_exists( 'mrkv_promua_description' ) ) {
                        \add_shortcode( 'mrkv_promua_description', array( $this, 'promua_description_shortcode_func' ) );
                    }
                    if ( ! \shortcode_exists( 'mrkv_promua_short_description' ) ) {
                        \add_shortcode( 'mrkv_promua_short_description', array( $this, 'promua_short_description_shortcode_func' ) );
                    }

                    // Simple product
                    if ( 'simple' == $offer_product_type ) {
                        $xml .= '
        <offer id="' . $ids[$i] . '" available="' . $offerPromua->is_available( $ids[$i], $offer_product ) . '">
            <url>' . htmlspecialchars( get_permalink( $offer_product->get_id() ) ) . '</url>
            <price>' . $offerPromua->set_price( $offer_product ) . '</price>
            <currencyId>' . $wcShopPromuaController->currencies[0] . '</currencyId>
            <categoryId>' . $offerPromua->set_category_id( $offer_product) . '</categoryId>'
            . $offerPromua->set_picture( $offer_product ) .
            '<name>' . htmlspecialchars( $offer_product->get_name() ) . '</name>
            <vendor>' . $offerPromua->set_vendor( $offer_product) . '</vendor>
            <description><![CDATA[' . $offerPromua->set_description( $offer_product) . ']]></description>' . PHP_EOL .
            $offerPromua->set_param( $offer_product ) . PHP_EOL .
            '<available>' . $offerPromua->set_available( $offer_product ) . '</available>
            <quantity_in_stock>' . $offerPromua->set_quantity_in_stock( $offer_product ) . '</quantity_in_stock>
            <vendorCode>' . $offerPromua->set_vendorCode( $offer_product ) . '</vendorCode>
        </offer>';
                    }

                    // Variable product
                    if ( 'variable' == $offer_product_type ) {
                        if ( ! \shortcode_exists( 'mrkv_promua_variation_description' ) ) {
                            \add_shortcode( 'mrkv_promua_variation_description', array( $this, 'promua_variation_description_shortcode_func' ) );
                        }
                        $variations_ids = $offer_product->get_children();
                        if ( is_array( $variations_ids ) ) {
                            foreach ( $variations_ids as $variation_id ) { // Variations loop
                                $offer_variation = \wc_get_product( $variation_id );
                                $this->promua_offer_variation = $offer_variation;
                                $xml .= '
        <offer group_id="' . $ids[$i] . '" id="' . $variation_id . '" available="' . $offerPromua->is_available( $variation_id, $offer_variation ) . '">
            <url>' . htmlspecialchars( get_permalink( $offer_variation->get_id() ) ) . '</url>
            <price>' . $offerPromua->set_price( $offer_variation ) . '</price>
            <currencyId>' . $wcShopPromuaController->currencies[0] . '</currencyId>
            <categoryId>' . $offerPromua->set_category_id( $offer_product ) . '</categoryId>'
            . $offerPromua->set_picture( $offer_variation ) .
            '<name>' . htmlspecialchars( $offer_variation->get_name() ) . '</name>
            <vendor>' . $offerPromua->set_vendor( $offer_product ) . '</vendor>
            <description><![CDATA[' . $offerPromua->set_description( $offer_variation) . ']]></description>' . PHP_EOL .
            $offerPromua->set_param( $offer_product ) . PHP_EOL .
            '<available>' . $offerPromua->set_available( $offer_variation ) . '</available>
            <quantity_in_stock>' . $offerPromua->set_quantity_in_stock( $offer_variation ) . '</quantity_in_stock>
            <vendorCode>' . $offerPromua->set_vendorCode( $offer_variation ) . '</vendorCode>
        </offer>';
                            }
                        }
                    }

                } // if ( is_object( $offer_product ) )

                else {
                    continue;
                }

            }

            // Update data in promua_status.json
            $status['step']++;
            file_put_contents( $this->plugin_uploads_dir_path . '/promua_status.json', json_encode( $status ) );

            if ( $status['total'] == $status['step'] ) { // If the last step, xml-tags must be closed
                $xml .= '</offers></shop></yml_catalog>';
            }

            $fp = fopen( $promua_temp_xmlpath, 'a'); // Save xml-price in temporary xml-file
            if ( false !== $fp ) {
                fwrite( $fp, PHP_EOL . $xml );
                fclose( $fp );
            }

            if ( $status['total'] == $status['step'] ) {
                if ( is_file( $promua_temp_xmlpath ) ) {
                    copy( $promua_temp_xmlpath, $this->xml_promua_filepath );
                    unlink( $promua_temp_xmlpath );
                    wp_clear_scheduled_hook( 'mrkvuamp_partial_update_xml_hook_promua' );
                    if ( is_file( $promua_status_json ) ) {
                        unlink( $promua_status_json );
                    }
                }
            }

            // If this were the final step, ZIP archive handling could occur here
            // if ( $status['total'] == $status['step'] ) {
            //     $zip = new ZipArchive();
            //     $zip->open(__DIR__ . '/market.zip', ZipArchive::CREATE|ZipArchive::OVERWRITE);
            //     $zip->addFile(__DIR__ . '/market.xml', 'market.xml');
            //     $zip->close();
            // }

            error_log( 'Крок ' . $status['step'] . ' з ' . $status['total'] . ' - виконаний.' );
        } else {
            // Xml-file is ready
            if ( is_file( $promua_temp_xmlpath ) ) {
                copy( $promua_temp_xmlpath, $this->xml_promua_filepath );
                unlink( $promua_temp_xmlpath );
                wp_clear_scheduled_hook( 'mrkvuamp_partial_update_xml_hook_promua' );
                if ( is_file( $promua_status_json ) ) {
                    unlink( $promua_status_json );
                }
            } else {
                error_log( 'Не вдалось скопіювати:\\n' .  $promua_temp_xmlpath . ' в\\n' . $this->xml_promua_filepath );
                error_log( 'Тимчасовий файл відсутній');
            }
            error_log( 'XML-файл для PromUA сформований, наступне оновлення ' . date('d.m.Y H:i', $status['date'] + $interval) . ' UTC' );
        }
    }

    public function promua_description_shortcode_func($content)
    {
        $content .= $this->promua_offer_product->get_description();
        return $content;
    }

    public function promua_short_description_shortcode_func($content)
    {
        $content .= $this->promua_offer_product->get_short_description();
        return $content;
    }

    public function promua_variation_description_shortcode_func($content)
    {
        $content .= $this->promua_offer_variation->get_description();
        return $content;
    }

}
