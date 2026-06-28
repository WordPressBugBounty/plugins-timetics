<?php
namespace Ens\Whatsapp;

use Ens\Utils\Helpers;

/**
 * Class MetaCloudProvider
 *
 * Built-in WhatsApp transport using Meta Graph Cloud API.
 * Auto-registers on `notification_sdk_send_whatsapp` action.
 *
 * @package Ens\Whatsapp
 *
 * @since 1.0.0
 */
class MetaCloudProvider {

    const DEFAULT_API_VERSION = 'v25.0';
    const TEXT_BODY_LIMIT     = 4096;

    protected $identifier;

    public function __construct( $identifier ) {
        $this->identifier = $identifier;
    }

    /**
     * Register the dispatcher on the SDK send action.
     *
     * @since 1.0.0
     */
    public function register() {
        add_action( 'notification_sdk_send_whatsapp', [ $this, 'dispatch' ], 10, 8 );
    }

    /**
     * Dispatch the WhatsApp message to Meta Cloud API.
     *
     * Fires three prefixed hooks consumers can subscribe to for logging,
     * metrics, or alerting:
     *   - `{hook_prefix}_ens_whatsapp_request`        before the HTTP call
     *   - `{hook_prefix}_ens_whatsapp_send_success`   on 2xx response
     *   - `{hook_prefix}_ens_whatsapp_send_error`     on any failure path
     *
     * @since 1.0.0
     *
     * @param string     $to            Recipient phone number.
     * @param string     $message       Body for text messages (ignored for templates).
     * @param array      $action_data   Hook payload.
     * @param string     $action_name   Triggering action name.
     * @param string     $receiver_type Receiver type key.
     * @param int        $count         Iteration count.
     * @param string     $message_type  `text` (default) or `template`.
     * @param array|null $template      Template config when $message_type=template.
     */
    public function dispatch( $to, $message, $action_data, $action_name, $receiver_type, $count, $message_type = 'text', $template = null ) {
        $handled = apply_filters(
            'ens_whatsapp_provider',
            false,
            $to,
            $message,
            $action_data,
            $action_name,
            $receiver_type,
            $count,
            $message_type,
            $template
        );
        if ( true === $handled ) {
            return;
        }

        $creds = $this->get_credentials();
        if ( empty( $creds['access_token'] ) || empty( $creds['phone_number_id'] ) ) {
            $this->fire_error( 'missing_credentials', $to, [], 0 );
            return;
        }

        $to = $this->normalize_number( $to );
        if ( '' === $to ) {
            $this->fire_error( 'invalid_recipient', $to, [], 0 );
            return;
        }

        if ( 'template' === $message_type ) {
            $payload = $this->build_template_payload( $to, $template );
            if ( null === $payload ) {
                $this->fire_error( 'invalid_template_config', $to, is_array( $template ) ? $template : [], 0 );
                return;
            }
        } else {
            $body = trim( wp_strip_all_tags( $message ) );
            if ( '' === $body ) {
                return;
            }
            if ( strlen( $body ) > self::TEXT_BODY_LIMIT ) {
                $body = substr( $body, 0, self::TEXT_BODY_LIMIT );
            }
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'text',
                'text'              => [ 'body' => $body ],
            ];
        }

        $payload = apply_filters(
            'ens_whatsapp_payload',
            $payload,
            $to,
            $message,
            $action_data,
            $action_name,
            $receiver_type,
            $count,
            $message_type,
            $template
        );

        $version  = apply_filters( 'ens_whatsapp_api_version', self::DEFAULT_API_VERSION, $this->identifier );
        $endpoint = 'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( $creds['phone_number_id'] ) . '/messages';

        do_action( Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_request' ), $endpoint, $payload, $message_type, $to );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $creds['access_token'],
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            $this->fire_error( $response->get_error_message(), $to, $payload, 0 );
            return;
        }

        $code          = (int) wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $code >= 400 ) {
            $this->fire_error( $response_body, $to, $payload, $code );
            return;
        }

        do_action( Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_send_success' ), $response_body, $to, $payload, $code );
    }

    /**
     * Fire the prefixed send-error action with a uniform signature.
     *
     * @since 1.0.0
     *
     * @param mixed  $error_body Raw response body or error string.
     * @param string $to         Recipient (possibly empty when normalization fails).
     * @param array  $payload    Payload that would have been sent.
     * @param int    $http_code  HTTP status (0 for setup/transport errors).
     */
    protected function fire_error( $error_body, $to, $payload, $http_code ) {
        do_action(
            Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_send_error' ),
            $error_body,
            $to,
            $payload,
            (int) $http_code
        );
    }

    /**
     * Build a Meta Cloud API `type: template` payload.
     *
     * Returns null when the template config is missing required fields so the
     * caller can fail closed and surface a diagnostic error.
     *
     * @since 1.0.0
     *
     * @param string     $to       Normalized recipient.
     * @param array|null $template Template config (name, language, body_params).
     *
     * @return array|null
     */
    protected function build_template_payload( $to, $template ) {
        if ( ! is_array( $template ) || empty( $template['name'] ) ) {
            return null;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => (string) $template['name'],
                'language' => [
                    'code' => '' !== trim( (string) ( $template['language'] ?? '' ) )
                        ? (string) $template['language']
                        : 'en_US',
                ],
            ],
        ];

        if ( ! empty( $template['body_params'] ) && is_array( $template['body_params'] ) ) {
            $parameters = array_values( array_filter( array_map(
                function ( $value ) {
                    $value = trim( (string) $value );
                    return '' === $value ? null : [
                        'type' => 'text',
                        'text' => $value,
                    ];
                },
                $template['body_params']
            ) ) );

            if ( ! empty( $parameters ) ) {
                $payload['template']['components'] = [
                    [
                        'type'       => 'body',
                        'parameters' => $parameters,
                    ],
                ];
            }
        }

        return $payload;
    }

    /**
     * Read credentials from the option store with consumer override filter.
     *
     * @since 1.0.0
     *
     * @return array
     */
    protected function get_credentials() {
        $stored = get_option( $this->identifier . '_ens_whatsapp_settings', [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        $defaults = [
            'access_token'    => '',
            'phone_number_id' => '',
        ];
        $creds = wp_parse_args( $stored, $defaults );

        return apply_filters(
            Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_credentials' ),
            $creds,
            $this->identifier
        );
    }

    /**
     * Normalize phone number to E.164 digits-only (Meta Cloud API requirement).
     *
     * @since 1.0.0
     *
     * @param mixed $number
     *
     * @return string
     */
    protected function normalize_number( $number ) {
        if ( ! is_scalar( $number ) ) {
            return '';
        }
        $number = preg_replace( '/\D/', '', (string) $number );
        if ( strlen( $number ) < 7 ) {
            return '';
        }
        return $number;
    }
}
