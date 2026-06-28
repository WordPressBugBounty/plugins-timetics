<?php
namespace Ens\Whatsapp;

use Ens\Utils\Helpers;
use WP_HTTP_Response;
use WP_REST_Controller;
use WP_REST_Server;

/**
 * Class TemplatesAPI
 *
 * Fetches WhatsApp message templates from Meta Graph API and returns parsed
 * metadata (body text, example values, parameter count) so the UI can render
 * an accurate, template-aware parameter editor instead of asking the user to
 * guess how many values to provide.
 *
 * @package Ens\Whatsapp
 *
 * @since 1.0.0
 */
class TemplatesAPI extends WP_REST_Controller {

    const GRAPH_API_VERSION = 'v25.0';
    const CACHE_TTL         = 5 * MINUTE_IN_SECONDS;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var string
     */
    protected $rest_base = 'whatsapp/templates';

    /**
     * @var string
     */
    protected $identifier;

    /**
     * Initialize and register REST routes.
     *
     * @since 1.0.0
     *
     * @param string $identifier The consumer plugin identifier.
     *
     * @return void
     */
    public function init( $identifier ) {
        $this->identifier = $identifier;
        $plugin_slug      = Helpers::get_config_data( $this->identifier, 'plugin_slug' );
        $this->namespace  = $plugin_slug . '/v1';

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register the REST routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'get_items_permissions_check' ],
                    'args'                => [
                        'refresh' => [
                            'description' => __( 'Bypass cache and fetch a fresh list from Meta.', 'wp-cafe' ),
                            'type'        => 'boolean',
                            'default'     => false,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check — only logged-in users with manage capability.
     *
     * Consumers can override via the `{prefix}_ens_whatsapp_templates_permission` filter.
     *
     * @since 1.0.0
     *
     * @param \WP_REST_Request $request Current request.
     *
     * @return bool|WP_HTTP_Response
     */
    public function get_items_permissions_check( $request ) {
        $nonce_check = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $nonce_check instanceof WP_HTTP_Response ) {
            return $nonce_check;
        }

        $can = current_user_can( 'manage_options' );

        return (bool) apply_filters(
            Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_templates_permission' ),
            $can,
            $request
        );
    }

    /**
     * Fetch and return parsed templates.
     *
     * @since 1.0.0
     *
     * @param \WP_REST_Request $request Current request.
     *
     * @return \WP_REST_Response
     */
    public function get_items( $request ) {
        $force_refresh = (bool) $request->get_param( 'refresh' );
        $creds         = $this->get_credentials();

        if ( empty( $creds['access_token'] ) || empty( $creds['business_id'] ) ) {
            return rest_ensure_response( [
                'success'     => 0,
                'status_code' => 400,
                'message'     => __( 'WhatsApp credentials missing. Set Access Token and WhatsApp Business Account ID in integration settings.', 'wp-cafe' ),
                'data'        => [
                    'items'           => [],
                    'missing_fields'  => array_values( array_filter( [
                        empty( $creds['access_token'] ) ? 'access_token' : null,
                        empty( $creds['business_id'] )  ? 'business_id'  : null,
                    ] ) ),
                ],
            ] );
        }

        $cache_key = $this->identifier . '_ens_whatsapp_templates_cache';
        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return rest_ensure_response( [
                    'success'     => 1,
                    'status_code' => 200,
                    'message'     => __( 'Templates loaded from cache.', 'wp-cafe' ),
                    'data'        => [ 'items' => $cached, 'cached' => true ],
                ] );
            }
        }

        $version  = apply_filters( 'ens_whatsapp_api_version', self::GRAPH_API_VERSION, $this->identifier );
        $endpoint = add_query_arg(
            [
                'fields' => 'name,language,status,category,parameter_format,components',
                'limit'  => 200,
            ],
            'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( $creds['business_id'] ) . '/message_templates'
        );

        $response = wp_remote_get( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $creds['access_token'],
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return rest_ensure_response( [
                'success'     => 0,
                'status_code' => 502,
                'message'     => $response->get_error_message(),
                'data'        => [ 'items' => [] ],
            ] );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code >= 400 ) {
            $meta_error = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'Unknown error from Meta API.', 'wp-cafe' );
            return rest_ensure_response( [
                'success'     => 0,
                'status_code' => $code,
                'message'     => $meta_error,
                'data'        => [ 'items' => [], 'raw' => $json ],
            ] );
        }

        $items = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : [];
        $parsed = array_values( array_filter( array_map( [ $this, 'parse_template' ], $items ) ) );

        set_transient( $cache_key, $parsed, self::CACHE_TTL );

        return rest_ensure_response( [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Templates loaded.', 'wp-cafe' ),
            'data'        => [ 'items' => $parsed, 'cached' => false ],
        ] );
    }

    /**
     * Reduce a raw Meta template object to the shape the UI needs.
     *
     * Skips non-APPROVED templates so the flow builder cannot pick a template
     * that would fail at send time.
     *
     * @since 1.0.0
     *
     * @param array $tpl Raw template object.
     *
     * @return array|null
     */
    protected function parse_template( $tpl ) {
        if ( ! is_array( $tpl ) || empty( $tpl['name'] ) ) {
            return null;
        }

        $status = isset( $tpl['status'] ) ? strtoupper( (string) $tpl['status'] ) : '';
        if ( 'APPROVED' !== $status ) {
            return null;
        }

        $components       = isset( $tpl['components'] ) && is_array( $tpl['components'] ) ? $tpl['components'] : [];
        $body             = $this->find_component( $components, 'BODY' );
        $header           = $this->find_component( $components, 'HEADER' );
        $footer           = $this->find_component( $components, 'FOOTER' );
        $body_text        = isset( $body['text'] ) ? (string) $body['text'] : '';
        $parameter_format = isset( $tpl['parameter_format'] ) ? strtoupper( (string) $tpl['parameter_format'] ) : 'POSITIONAL';

        $body_params = $this->extract_body_params( $body_text, $body, $parameter_format );

        return [
            'name'             => (string) $tpl['name'],
            'language'         => isset( $tpl['language'] ) ? (string) $tpl['language'] : 'en_US',
            'status'           => $status,
            'category'         => isset( $tpl['category'] ) ? (string) $tpl['category'] : '',
            'parameter_format' => $parameter_format,
            'body_text'        => $body_text,
            'body_params'      => $body_params,
            'param_count'      => count( $body_params ),
            'header_text'      => isset( $header['text'] ) ? (string) $header['text'] : '',
            'footer_text'      => isset( $footer['text'] ) ? (string) $footer['text'] : '',
        ];
    }

    /**
     * Find a component by type (BODY, HEADER, FOOTER, BUTTONS).
     *
     * @since 1.0.0
     *
     * @param array  $components List of component arrays.
     * @param string $type       Component type to find.
     *
     * @return array|null
     */
    protected function find_component( $components, $type ) {
        foreach ( $components as $component ) {
            if ( isset( $component['type'] ) && strtoupper( (string) $component['type'] ) === $type ) {
                return $component;
            }
        }
        return null;
    }

    /**
     * Extract the ordered list of body parameters from the body component.
     *
     * Returns an array of `[ 'key' => string, 'example' => string ]` entries.
     * For POSITIONAL templates `key` is the numeric placeholder (1, 2, 3); for
     * NAMED templates it's the parameter name.
     *
     * @since 1.0.0
     *
     * @param string $body_text        Body text with `{{N}}` or `{{name}}` placeholders.
     * @param array  $body_component   Raw body component (for example values).
     * @param string $parameter_format `POSITIONAL` or `NAMED`.
     *
     * @return array
     */
    protected function extract_body_params( $body_text, $body_component, $parameter_format ) {
        if ( '' === $body_text ) {
            return [];
        }

        preg_match_all( '/{{\s*([a-zA-Z0-9_]+)\s*}}/', $body_text, $matches );
        if ( empty( $matches[1] ) ) {
            return [];
        }

        $keys = array_values( array_unique( $matches[1] ) );
        $examples = [];

        if ( 'NAMED' === $parameter_format ) {
            $named = isset( $body_component['example']['body_text_named_params'] ) ? $body_component['example']['body_text_named_params'] : [];
            if ( is_array( $named ) ) {
                foreach ( $named as $pair ) {
                    if ( isset( $pair['param_name'] ) ) {
                        $examples[ (string) $pair['param_name'] ] = isset( $pair['example'] ) ? (string) $pair['example'] : '';
                    }
                }
            }
        } else {
            $positional = isset( $body_component['example']['body_text'][0] ) && is_array( $body_component['example']['body_text'][0] )
                ? $body_component['example']['body_text'][0]
                : [];
            foreach ( $positional as $index => $value ) {
                $examples[ (string) ( $index + 1 ) ] = (string) $value;
            }
        }

        $result = [];
        foreach ( $keys as $key ) {
            $result[] = [
                'key'     => (string) $key,
                'example' => isset( $examples[ $key ] ) ? (string) $examples[ $key ] : '',
            ];
        }

        return $result;
    }

    /**
     * Read credentials via the same filter MetaCloudProvider uses so a single
     * source of truth maps plugin options into the SDK shape.
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
            'business_id'     => '',
        ];
        $creds = wp_parse_args( $stored, $defaults );

        return apply_filters(
            Helpers::get_hook_name( $this->identifier, 'ens_whatsapp_credentials' ),
            $creds,
            $this->identifier
        );
    }
}
