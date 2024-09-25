<?php
/**
 * WooCommerce Cart API
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations\Woocommerce;

use Timetics\Base\Api;
use Timetics\Core\Appointments\Appointment;
use Timetics\Utils\Singleton;

/**
 *
 */
class Cart_Api extends Api {
    use Singleton;

    /**
     * Store api namespace
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @var string
     */
    protected $rest_base = 'woocommerce';

    /**
     * Register rest routes
     *
     * @return  void
     */
    public function register_routes() {
        /**
         * Register route
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, $this->rest_base . '/cart', [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'set_up_woocommerce'],
                    'permission_callback' => function () {
                        return true;
                    },
                ],
            ]
        );
    }

    /**
     * Add to cart booking
     *
     * @param   Object  $requst
     *
     * @return  JSON
     */
    public function set_up_woocommerce( $requst ) {
        $data = json_decode( $requst->get_body(), true );

        $booking_id = ! empty( $data['booking_id'] ) ? intval( $data['booking_id'] ) : 0;
        $meeting_id = ! empty( $data['meeting_id'] ) ? intval( $data['meeting_id'] ) : 0;
        $price      = ! empty( $data['price'] ) ? intval( $data['price'] ) : 0;

        // Set session for timetics data for woocommerce.
        WC()->session->set( 'timetics_data', [
            'booking_id' => $booking_id,
            'meeting_id' => $meeting_id,
            'price'      => $price,
        ] );

        // Remove all items from cart.
        WC()->cart->empty_cart();

        // Product Add to cart
        $this->add_to_cart();

        $data = [
            'success' => 1,
            'status_code' => 200,
            'data' => array_merge([
                'checkout_url' => wc_get_checkout_url(),
            ], WC()->session->get( 'timetics_data' ) )
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Add to cart for woocommerce
     *
     * @return  void
     */
    public function add_to_cart() {
        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! $session_data ) {
            return false;
        }

        $meeting_id = $session_data['meeting_id'];

        $meeting    = new Appointment( $meeting_id );
        $product_id = $meeting->get_wc_product_id();
        $quantity   = 1;

        if ( ! $product_id ) {
            $product    = new Product();
            $product_id = $product->create( $meeting );
        }

        if ( ! $product_id ) {
            return;
        }

        WC()->cart->add_to_cart( $product_id, $quantity );
    }
}
