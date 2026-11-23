<?php
/**
 * WooCommerce Product
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Woocommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Product class
 */
class Product {
    /**
     * Creat product for woocommer
     *
     * @return bool
     */
    public function  create( $meeting ) {
        $product_id = $meeting->get_wc_product_id();
        $product    = $product_id ? wc_get_product( $product_id ) : false;

        // The saved product may have been deleted in WooCommerce: make a new one.
        if ( ! $product ) {
            $product = new \WC_Product_Simple();
        }

        $product_category    = get_term_by( 'slug', 'timetics-meeting', 'product_cat' );
        $product_category_id = ! empty( $product_category ) ? $product_category->term_id : 0;

        $product->set_name( $meeting->get_name() );
        $product->set_short_description( $meeting->get_description() );
        $product->set_price( $meeting->get_price() );
        $product->set_status( 'publish' );
        $product->set_category_ids( [$product_category_id] );

        $product->update_meta_data( 'timetics_meeting_id', $meeting->get_id() );

        // Store meeting details as product meta for checkout/email display
        $product->update_meta_data( 'timetics_duration', $meeting->get_duration() );
        $product->update_meta_data( 'timetics_timezone', $meeting->get_timezone() );

        $product_id = $product->save();

        $meeting->update( [
            'wc_product_id' => $product->get_id(),
        ] );

        return $product_id;
    }
}
