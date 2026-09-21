<?php
/**
 * WooCommerce Cart API
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations\Woocommerce;

defined( 'ABSPATH' ) || exit;

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
        $token      = ! empty( $data['security_token'] ) ? sanitize_text_field( $data['security_token'] ) : '';

        $booking = new \Timetics\Core\Bookings\Booking( $booking_id );
        // everything from the booking record itself once ownership is proven.
        if ( ! $booking_id || '' === $token || ! $booking->is_booking() ) {
            return new \WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 404,
                    'message'     => __( 'Invalid booking.', 'timetics' ),
                ],
                404
            );
        }

        $stored = (string) $booking->get_security_token();

        if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
            return new \WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 403,
                    'message'     => __( 'Invalid booking token.', 'timetics' ),
                ],
                403
            );
        }

        $meeting_id = (int) $booking->get_appointment();
        $price      = (int) $booking->get_total();

        // Hooks::add_product_to_cart() already ran on timetics_after_booking_create and
        // stored the meeting details ( date, time, duration, timezone, location ) in this
        // session. Keep them - overwriting the whole array would drop them before checkout.
        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! is_array( $session_data ) || empty( $session_data['booking_id'] ) || (int) $session_data['booking_id'] !== $booking_id ) {
            $session_data = [];
        }

        // Set session for timetics data for woocommerce.
        WC()->session->set( 'timetics_data', array_merge( $session_data, [
            'booking_id' => $booking_id,
            'meeting_id' => $meeting_id,
            'price'      => $price,
        ] ) );

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
