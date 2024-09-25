<?php
/**
 * Stripe payment api
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Stripe;

use Timetics\Base\Api;
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
        $data = json_decode( $request->get_body(), true );

        $amount   = ! empty( $data['amount'] ) ? floatval( $data['amount'] ) : 0;
        $currency = ! empty( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : '';

        $payment = new StripePayment();

        $payment = $payment->create_payment(
            [
				'amount'   => $amount * 100,
				'currency' => $currency,
			]
        );

        if ( is_wp_error( $data ) ) {
            $response = [
                'success'     => 0,
                'status_code' => 403,
                'message'     => $payment->get_error_message(),
            ];

            return new WP_HTTP_Response( $response, 403 );
        }

        return rest_ensure_response( $payment );
    }
}

