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
            'amount'                 => '',
            'currency'               => '',
        ];

        $args   = wp_parse_args( $args, $defaults );

        $url    = $this->payment_intent_url;
        $secret = timetics_get_option( 'stripe_secret_key' );
        $args = build_query( $args ) . '&automatic_payment_methods[enabled]=true';
        $params = [
            'headers' => [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded;charset=UTF-8',
            ],
            'body'    => $args,
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
}
