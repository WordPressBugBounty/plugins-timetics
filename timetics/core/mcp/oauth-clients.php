<?php
/**
 * Sign-in clients
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Apps that may ask to connect: a published identity (an https client id that
 * points at the app's own description) or an app that registered itself here.
 */
class Oauth_Clients {

    const PREFIX    = 'timetics_mcp_client_';
    const CACHE     = 'timetics_mcp_cimd_';
    const MAX_BYTES = 10240;

    // Registration is open to anyone, as the protocol needs, so each address gets an
    // hourly budget and a registration stays small.
    const BUDGET        = 20;
    const MAX_REDIRECTS = 10; // Gemini registers 6.
    const MAX_URI       = 2000;
    const MAX_NAME      = 100;
    const UNUSED_DAYS   = 7;

    /**
     * Register an app that has no published identity.
     *
     * @param   array  $meta  client_name, redirect_uris.
     *
     * @return  array|WP_Error  The saved client, or invalid_redirect_uri.
     */
    public static function register( array $meta ) {
        if ( ! self::within_budget( 'register' ) ) {
            return new WP_Error( 'temporarily_unavailable', __( 'Too many apps were registered from this address. Try again in an hour.', 'timetics' ) );
        }

        $redirects = array_values( array_filter( (array) ( $meta['redirect_uris'] ?? array() ), 'is_string' ) );
        if ( ! $redirects || count( $redirects ) > self::MAX_REDIRECTS ) {
            /* translators: %d: most redirect URIs allowed */
            return new WP_Error( 'invalid_redirect_uri', sprintf( __( 'Send 1 to %d redirect URIs.', 'timetics' ), self::MAX_REDIRECTS ) );
        }

        foreach ( $redirects as $uri ) {
            if ( strlen( $uri ) > self::MAX_URI || ! self::safe_redirect( $uri ) ) {
                return new WP_Error( 'invalid_redirect_uri', __( 'A redirect URI must use https, an app\'s own scheme, or http on this computer.', 'timetics' ) );
            }
        }

        $client = array(
            'client_id'     => bin2hex( random_bytes( 16 ) ),
            'client_name'   => mb_substr( sanitize_text_field( (string) ( $meta['client_name'] ?? '' ) ), 0, self::MAX_NAME ),
            'redirect_uris' => $redirects,
            'created'       => time(),
        );
        add_option( self::PREFIX . $client['client_id'], wp_json_encode( $client ), '', false );

        return $client;
    }

    /**
     * Remove registrations older than a week that no connection uses. Runs after the
     * daily token cleanup, so ended connections are gone first.
     *
     * @return  void
     */
    public static function cleanup() {
        global $wpdb;

        $used = Oauth_Tokens::client_ids();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lists this module's own rows, which no API indexes.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );

        foreach ( $rows as $row ) {
            $client = json_decode( $row->option_value, true );
            if ( is_array( $client ) && ( $client['created'] ?? 0 ) < time() - self::UNUSED_DAYS * DAY_IN_SECONDS && ! in_array( $client['client_id'] ?? '', $used, true ) ) {
                delete_option( $row->option_name );
            }
        }
    }

    /**
     * Find an app by its client id.
     *
     * @param   string  $id  Client id.
     *
     * @return  array|WP_Error  client_id, client_name, redirect_uris.
     */
    public static function find( string $id ) {
        if ( 0 === strpos( $id, 'https://' ) ) {
            return self::published( $id );
        }

        $client = json_decode( (string) get_option( self::PREFIX . sanitize_key( $id ), '' ), true );

        return is_array( $client ) ? $client : new WP_Error( 'invalid_client', __( 'This app is not known to the site.', 'timetics' ) );
    }

    /**
     * Read a published identity, kept a day so sign-in does not wait on the network.
     *
     * @param   string  $url  Client id, which is the document's URL.
     *
     * @return  array|WP_Error
     */
    private static function published( string $url ) {
        $key    = self::CACHE . md5( $url );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        if ( ! self::within_budget( 'fetch' ) ) {
            return new WP_Error( 'invalid_client', __( 'Too many app identities were read from this address. Try again in an hour.', 'timetics' ) );
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 5,
                'redirection'         => 0,
                'limit_response_size' => self::MAX_BYTES,
            )
        );
        $doc      = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $doc ) || ( $doc['client_id'] ?? '' ) !== $url || empty( $doc['redirect_uris'] ) || count( (array) $doc['redirect_uris'] ) > self::MAX_REDIRECTS ) {
            return new WP_Error( 'invalid_client', __( 'The app\'s published identity could not be read.', 'timetics' ) );
        }

        // Anyone can publish a document, so it gets the rules register() applies.
        $client = array(
            'client_id'     => $url,
            'client_name'   => mb_substr( sanitize_text_field( (string) ( $doc['client_name'] ?? wp_parse_url( $url, PHP_URL_HOST ) ) ), 0, self::MAX_NAME ),
            'redirect_uris' => array_values(
                array_filter(
                    (array) $doc['redirect_uris'],
                    function ( $uri ) {
                        return is_string( $uri ) && strlen( $uri ) <= self::MAX_URI && self::safe_redirect( $uri );
                    }
                )
            ),
        );
        set_transient( $key, $client, DAY_IN_SECONDS );

        return $client;
    }

    /**
     * Count one more request from this address, and say whether it is within the hourly
     * budget. Two requests at the same moment may both pass, which is enough to stop a flood.
     *
     * @param   string  $kind  What is counted.
     *
     * @return  bool
     */
    private static function within_budget( string $kind ): bool {
        $address = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $key     = 'timetics_mcp_budget_' . md5( $kind . '|' . $address );
        $used    = (int) get_transient( $key );

        if ( $used >= self::BUDGET ) {
            return false;
        }

        set_transient( $key, $used + 1, HOUR_IN_SECONDS );

        return true;
    }

    /**
     * Whether a redirect is safe to send a code to. Desktop apps may use their own scheme,
     * such as cursor://, as RFC 8252 allows: PKCE keeps a stolen code useless.
     *
     * @param   string  $uri  Redirect URI.
     *
     * @return  bool
     */
    public static function safe_redirect( string $uri ): bool {
        $parts = wp_parse_url( $uri );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['fragment'] ) ) {
            return false;
        }

        $scheme = strtolower( $parts['scheme'] );
        if ( 'http' === $scheme ) {
            return in_array( $parts['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true );
        }

        return ! in_array( $scheme, array( 'javascript', 'data', 'file', 'vbscript', 'blob', 'about', 'ftp', 'ws' ), true );
    }
}
