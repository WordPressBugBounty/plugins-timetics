<?php
/**
 * Class stripe payment
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Stripe;

/**
 * Class StripePayment
 */
class StripePayment {
    /**
     * Store stripe paymentintent api url
     *
     * @var string
     */
    private $payment_intent_url = 'https://api.stripe.com/v1/payment_intents';

    /**
     * Create stripe paymentintent
     *
     * @param   array  $args  Stripe payment details
     *
     * @return  array
     */
    public function create_payment( $args = [] ) {
        $defaults = [
            'amount'   => '',
            'currency' => '',
            'metadata' => [],
        ];

        $args = wp_parse_args( $args, $defaults );

        $metadata = is_array( $args['metadata'] ) ? $args['metadata'] : [];
        unset( $args['metadata'] );

        $url    = $this->payment_intent_url;
        $secret = timetics_get_option( 'stripe_secret_key' );
        $body   = build_query( $args ) . '&automatic_payment_methods[enabled]=true';

        foreach ( $metadata as $meta_key => $meta_value ) {
            if ( $meta_value === '' || $meta_value === null ) {
                continue;
            }
            $body .= '&metadata[' . rawurlencode( (string) $meta_key ) . ']=' . rawurlencode( (string) $meta_value );
        }

        $params = [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded;charset=UTF-8',
            ],
            'body'    => $body,
        ];

        $response = wp_remote_post( $url, $params );

        if ( ! is_wp_error( $response ) ) {
            return json_decode( wp_remote_retrieve_body( $response ), true );
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/integrations/stripe/create_payment', $response );

        return $response;
    }

    /**
     * Update an existing Stripe PaymentIntent's metadata server-side.
     * Used to bind a PaymentIntent to a booking after the booking is created
     * but before the customer confirms the payment.
     *
     * @param string $intent_id PaymentIntent id (pi_...).
     * @param array  $metadata  Key/value pairs to set on the intent.
     *
     * @return array|\WP_Error Decoded Stripe response, or WP_Error on failure.
     */
    public function update_payment_intent( $intent_id, $metadata = [] ) {
        $intent_id = is_string( $intent_id ) ? trim( $intent_id ) : '';

        if ( '' === $intent_id || strpos( $intent_id, 'pi_' ) !== 0 ) {
            return new \WP_Error( 'timetics_stripe_invalid_intent', __( 'Invalid Stripe payment intent id.', 'timetics' ) );
        }

        $secret = timetics_get_option( 'stripe_secret_key' );

        if ( empty( $secret ) ) {
            return new \WP_Error( 'timetics_stripe_missing_secret', __( 'Stripe secret key is not configured.', 'timetics' ) );
        }

        $body_parts = [];
        foreach ( (array) $metadata as $meta_key => $meta_value ) {
            if ( $meta_value === '' || $meta_value === null ) {
                continue;
            }
            $body_parts[] = 'metadata[' . rawurlencode( (string) $meta_key ) . ']=' . rawurlencode( (string) $meta_value );
        }

        if ( empty( $body_parts ) ) {
            return new \WP_Error( 'timetics_stripe_empty_metadata', __( 'No metadata provided.', 'timetics' ) );
        }

        $response = wp_remote_post(
            $this->payment_intent_url . '/' . rawurlencode( $intent_id ),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                    'Content-Type'  => 'application/x-www-form-urlencoded;charset=UTF-8',
                ],
                'body'    => implode( '&', $body_parts ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $body ) && ! empty( $body['error']['message'] )
                ? $body['error']['message']
                : __( 'Stripe payment intent update failed.', 'timetics' );
            return new \WP_Error( 'timetics_stripe_update_failed', $message, [ 'status' => $code ] );
        }

        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'timetics_stripe_invalid_response', __( 'Unexpected Stripe response.', 'timetics' ) );
        }

        return $body;
    }

    /**
     * Retrieve a Stripe PaymentIntent server-side for verification.
     *
     * @param string $intent_id PaymentIntent id (pi_...).
     *
     * @return array|\WP_Error Decoded Stripe response, or WP_Error on transport / API failure.
     */
    public function retrieve_payment_intent( $intent_id ) {
        $intent_id = is_string( $intent_id ) ? trim( $intent_id ) : '';

        if ( '' === $intent_id || strpos( $intent_id, 'pi_' ) !== 0 ) {
            return new \WP_Error( 'timetics_stripe_invalid_intent', __( 'Invalid Stripe payment intent id.', 'timetics' ) );
        }

        $secret = timetics_get_option( 'stripe_secret_key' );

        if ( empty( $secret ) ) {
            return new \WP_Error( 'timetics_stripe_missing_secret', __( 'Stripe secret key is not configured.', 'timetics' ) );
        }

        $url = $this->payment_intent_url . '/' . rawurlencode( $intent_id );

        $response = wp_remote_get(
            $url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secret,
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $body ) && ! empty( $body['error']['message'] )
                ? $body['error']['message']
                : __( 'Stripe payment intent retrieval failed.', 'timetics' );
            return new \WP_Error( 'timetics_stripe_retrieve_failed', $message, [ 'status' => $code ] );
        }

        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'timetics_stripe_invalid_response', __( 'Unexpected Stripe response.', 'timetics' ) );
        }

        return $body;
    }
}
