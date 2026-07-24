<?php
/**
 * Google Calendar Auth Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Google;

use Exception;
use InvalidArgumentException;

/**
 * Class Auth
 */
class Client {
    const TIMETICS_REVOKE_URI = 'https://oauth2.googleapis.com/revoke';
    const TIMETICS_TOKEN_URI  = 'https://oauth2.googleapis.com/token';
    const TIMETICS_AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const API_BASE_PATH       = 'https://www.googleapis.com';

    /**
     * Store google app client id
     *
     * @var string
     */
    private $client_id;

    /**
     * Store google app secrete
     *
     * @var string
     */
    private $client_secrete;

    /**
     * Store google app auth scope
     *
     * @var string
     */
    private $auth_scope;

    /**
     * Store google auth redirect uri
     *
     * @var string
     */
    private $redirect_uri;

    /**
     * Get app client id
     *
     * @return  string
     */
    public function get_client_id() {
        return $this->client_id;
    }

    /**
     * Get app secrete
     *
     * @return  string
     */
    public function get_client_secrete() {
        return $this->client_secrete;
    }

    /**
     * Get app auth scope
     *
     * @return  string
     */
    public function get_auth_scope() {
        return $this->auth_scope;
    }

    /**
     * Get auth redirect uri
     *
     * @return  string
     */
    public function get_redirect_uri() {
        return $this->redirect_uri;
    }

    /**
     * Get auth url
     *
     * @return  string
     */
    public function get_auth_url( $state = '' ) {
        $params = array(
            'client_id'     => $this->client_id,
            'scope'         => urlencode_deep( $this->auth_scope ),
            'redirect_uri'  => $this->redirect_uri,
            'response_type' => 'code',
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        );

        if ( ! empty( $state ) ) {
            $params['state'] = $state;
        }

        return add_query_arg( $params, self::TIMETICS_AUTH_URL );
    }

    /**
     * Set google authentication configuration
     *
     * @return  void
     */
    public function set_auth_config( $args = array() ) {
        $defaults = array(
            'client_id'      => '',
            'client_secrete' => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $this->client_id      = $args['client_id'];
        $this->client_secrete = $args['client_secrete'];
    }

    /**
     * Set authentication scope
     *
     * @return void
     */
    public function add_scope( $scope ) {
        $this->auth_scope = $scope;
    }

    /**
     * Set redirect uri
     *
     * @param   string  $uri  Redirect uri
     *
     * @return void
     */
    public function set_redirect_uri( $uri ) {
        $this->redirect_uri = $uri;
    }

    /**
     * Fetch access token
     *
     * @param   string  $code
     *
     * @return array Token data on success.
     * @throws \InvalidArgumentException When the code is empty.
     * @throws \Exception On transport failure or a non-200 token response.
     */
    public function fetch_access_token_with_auth_code( $code ) {
        if ( strlen( $code ) === 0 ) {
            throw new InvalidArgumentException( 'Invalid code' );
        }

        $args = array(
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secrete,
            'code'          => $code,
            'redirect_uri'  => $this->redirect_uri,
            'grant_type'    => 'authorization_code',
        );

        $response = wp_remote_post( self::TIMETICS_TOKEN_URI, array( 'body' => $args ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( 200 !== $status_code || empty( $data['access_token'] ) ) {
            $message = ! empty( $data['error_description'] ) ? $data['error_description'] : __( 'Failed to obtain Google access token.', 'timetics' );
            throw new Exception( $message );
        }

        return $data;
    }

    /**
     * Fetch access token by using refresh token
     *
     * @param   string  $refresh_token
     *
     * @return array|\WP_Error Token data on success, or a WP_Error whose error_data carries a boolean `transient` flag.
     */
    public function fetch_access_token_with_refresh_token( $refresh_token ) {
        if ( empty( $refresh_token ) ) {
            return new \WP_Error(
                'timetics_google_no_refresh_token',
                'No Google refresh token is available for this account.',
                array( 'transient' => false )
            );
        }

        $args = array(
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secrete,
            'refresh_token' => $refresh_token,
            'redirect_uri'  => $this->redirect_uri,
            'grant_type'    => 'refresh_token',
        );

        $response = wp_remote_post( self::TIMETICS_TOKEN_URI, array( 'body' => $args ) );

        // Transport-level failure (DNS, timeout, connection refused). Transient:
        // the credential is fine, Google was just briefly unreachable.
        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'timetics_google_refresh_http_error',
                $response->get_error_message(),
                array( 'transient' => true )
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( 200 === $status_code && ! empty( $data['access_token'] ) ) {
            return $data;
        }

        $google_error = isset( $data['error'] ) ? $data['error'] : '';
        $transient    = ( 'invalid_grant' !== $google_error );

        if ( 429 === $status_code || $status_code >= 500 || 0 === $status_code ) {
            $transient = true;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is guarded by WP_DEBUG checks.
            error_log( sprintf( 'Timetics Google token refresh failed (HTTP %d): %s', $status_code, $body ) );
        }

        return new \WP_Error(
            'timetics_google_refresh_failed',
            ! empty( $data['error_description'] ) ? $data['error_description'] : 'Failed to refresh Google access token.',
            [
                'transient'   => $transient,
                'status_code' => $status_code,
                'error'       => $google_error,
            ]
        );
    }

    /**
     * Revoke authorization
     *
     * @param   string  $token
     *
     * @return bool
     */
    public function revoke( $token ) {
        $response = wp_remote_post(
            self::TIMETICS_REVOKE_URI, array(
                'headers' => array(
                    'content-type' => 'application/x-www-form-urlencoded',
                ),
                'body'    => build_query(
                    array(
                        'token' => $token,
                    )
                ),
            )
        );

        $status_code = wp_remote_retrieve_response_code( $response );

        return $status_code === 200;
    }
}
