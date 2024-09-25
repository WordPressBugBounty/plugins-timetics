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
    public function get_auth_url() {
        $auth_url = add_query_arg(
            [
				'client_id'     => $this->client_id,
				'scope'         => urlencode_deep( $this->auth_scope ),
				'redirect_uri'  => $this->redirect_uri,
				'response_type' => 'code',
				'access_type'   => 'offline',

			], self::TIMETICS_AUTH_URL
        );

        return $auth_url;
    }

    /**
     * Set google authentication configuration
     *
     * @return  void
     */
    public function set_auth_config( $args = [] ) {
        $defaults = [
            'client_id'      => '',
            'client_secrete' => '',
        ];

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
     * @return void
     */
    public function fetch_access_token_with_auth_code( $code ) {
        if ( strlen( $code ) === 0 ) {
            throw new InvalidArgumentException( 'Invalid code' );
        }

        $args = [
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secrete,
            'code'          => $code,
            'redirect_uri'  => $this->redirect_uri,
            'grant_type'    => 'authorization_code',
        ];

        $response = wp_remote_post( self::TIMETICS_TOKEN_URI, [ 'body' => $args ] );

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 != $status_code ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data;
    }

    /**
     * Fetch access token by using refresh token
     *
     * @return  void
     */
    public function fetch_access_token_with_refresh_token( $refresh_token ) {
        $args = [
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secrete,
            'refresh_token' => $refresh_token,
            'redirect_uri'  => $this->redirect_uri,
            'grant_type'    => 'refresh_token',
        ];

        $response = wp_remote_post( self::TIMETICS_TOKEN_URI, [ 'body' => $args ] );

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 != $status_code ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data;
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
            self::TIMETICS_REVOKE_URI, [
                'headers' => [
                    'content-type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => build_query(
                    [
                        'token' => $token,
                    ]
                ),
            ]
        );

        $status_code = wp_remote_retrieve_response_code( $response );

        return $status_code === 200;
    }
}
