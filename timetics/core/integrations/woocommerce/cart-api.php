<?php
/**
 * WooCommerce Cart API
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations\Woocommerce;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
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

        // Only a booking that still has to be paid through WooCommerce goes to the cart:
        // a free one, a paid one or a Stripe one must never put a line there.
        if ( ! Hooks::is_waiting( $booking ) || ! WC()->cart ) {
            return new \WP_HTTP_Response(
                array(
                    'success'     => 0,
                    'status_code' => 400,
                    'message'     => __( 'This booking cannot be paid through WooCommerce.', 'timetics' ),
                ),
                400
            );
        }

        if ( ! Hooks::add_booking_to_cart( $booking ) ) {
            return new \WP_HTTP_Response(
                array(
                    'success'     => 0,
                    'status_code' => 500,
                    'message'     => __( 'Could not add the booking to the cart.', 'timetics' ),
                ),
                500
            );
        }

        return rest_ensure_response(
            array(
                'success'     => 1,
                'status_code' => 200,
                'data'        => array(
                    'checkout_url' => wc_get_checkout_url(),
                ),
            )
        );
    }
}
