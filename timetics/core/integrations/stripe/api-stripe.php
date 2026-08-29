<?php
/**
 * Stripe payment api
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Stripe;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
use Timetics\Core\Bookings\Booking;
use Timetics\Utils\Singleton;
use WP_HTTP_Response;

/**
 * Class Api Stripe
 */
class Api_Stripe extends Api {
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
    protected $rest_base = 'stripe';

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
            $this->namespace, $this->rest_base . '/payment', [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_payment' ],
                    'permission_callback' => function () {
                        return true;
                    },
                ],
            ]
        );
    }

    /**
     * Create stripe payment
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function create_payment( $request ) {
        if ( $this->is_rate_limited() ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 429,
                    'message'     => __( 'Too many requests. Please try again later.', 'timetics' ),
                ],
                429
            );
        }

        $data = json_decode( $request->get_body(), true );

        $amount     = ! empty( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
        $currency   = ! empty( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : '';
        $booking_id = ! empty( $data['booking_id'] ) ? absint( $data['booking_id'] ) : 0;
        $token      = ! empty( $data['security_token'] ) ? sanitize_text_field( $data['security_token'] ) : '';

        $metadata = [];

        // The card form (StripePayment.js) creates this PaymentIntent up front, before
        // a booking exists, purely from the meeting's price — so booking_id/token are
        // optional here. Binding happens later via bind_payment_intent(), and the booking
        // can only be marked paid there after its security_token is verified. An intent
        // created without a booking can never complete a payment, so this cannot be used
        // to steal funds; it can only let a caller create inert PaymentIntents in Stripe.
        if ( $booking_id > 0 && '' !== $token ) {
            $booking = new Booking( $booking_id );

            if ( ! $booking->is_booking() ) {
                return new WP_HTTP_Response(
                    [
                        'success'     => 0,
                        'status_code' => 404,
                        'message'     => __( 'Invalid booking id.', 'timetics' ),
                    ],
                    404
                );
            }

            $stored = (string) $booking->get_security_token();

            if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
                return new WP_HTTP_Response(
                    [
                        'success'     => 0,
                        'status_code' => 403,
                        'message'     => __( 'Invalid booking token.', 'timetics' ),
                    ],
                    403
                );
            }

            $metadata['booking_id']     = $booking_id;
            $metadata['security_token'] = $stored;

            // Once bound, trust the booking's own total over whatever the client sent.
            $amount = (float) $booking->get_total();
        }

        // Sanity bounds — reject nonsense amounts regardless of binding.
        if ( $amount <= 0 || $amount > 1000000 || ! preg_match( '/^[A-Za-z]{3}$/', (string) $currency ) ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 400,
                    'message'     => __( 'Invalid amount or currency.', 'timetics' ),
                ],
                400
            );
        }

        $payment = new StripePayment();

        $payment = $payment->create_payment(
            [
                'amount'   => $amount * 100,
                'currency' => $currency,
                'metadata' => $metadata,
            ]
        );

        if ( is_wp_error( $payment ) ) {
            $response = [
                'success'     => 0,
                'status_code' => 403,
                'message'     => $payment->get_error_message(),
            ];

            return new WP_HTTP_Response( $response, 403 );
        }

        return rest_ensure_response( $payment );
    }

    /**
     * Simple per-IP fixed-window limiter for the public payment-intent route.
     *
     * @return  bool
     */
    private function is_rate_limited() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        if ( '' === $ip ) {
            return false;
        }

        $key   = 'tt_stripe_rl_' . md5( $ip );
        $count = (int) get_transient( $key );

        if ( $count >= 20 ) {
            return true;
        }

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS * 10 );

        return false;
    }
}

