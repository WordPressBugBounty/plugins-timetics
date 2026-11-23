<?php
/**
 * Sign-in for AI apps
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Connector sign-in: tells AI apps where to log in, gives tokens, and lets a token
 * stand for its WordPress user on the Timetics MCP URL and nowhere else.
 *
 * Every discovery address carries a Timetics path, so another plugin's sign-in on
 * the same site (Booktics answers the bare /.well-known/ addresses) never clashes.
 */
class Oauth_Server {

    const CLEANUP_HOOK = 'timetics_mcp_oauth_cleanup';

    /**
     * Path of the sign-in server's name after the site address.
     */
    const ISSUER_PATH = '/timetics';

    /**
     * Scopes of the token behind this request, or null when no token was used.
     *
     * @var string[]|null
     */
    private static $scope = null;

    /**
     * Register hooks.
     *
     * @return  void
     */
    public static function register() {
        add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
        // Before Booktics, which answers every path that starts with its /.well-known/ prefix.
        add_action( 'parse_request', array( self::class, 'answer_public_pages' ), -1 );
        add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 30 );
        add_filter( 'rest_pre_dispatch', array( self::class, 'keep_token_on_mcp' ), 0 );
        add_filter( 'rest_post_dispatch', array( self::class, 'add_challenge' ), 10, 3 );
        add_filter( 'mcp_adapter_tools_list', array( self::class, 'filter_tools_list' ), 10, 2 );
        add_action( 'update_option_timetics_settings', array( self::class, 'end_all_when_off' ), 10, 2 );
        add_action( 'wp_set_password', array( self::class, 'end_user_connections' ), 10, 2 );
        add_action( 'delete_user', array( self::class, 'end_user_connections_on_delete' ) );
        add_action( self::CLEANUP_HOOK, array( Oauth_Tokens::class, 'cleanup' ) );
        add_action( self::CLEANUP_HOOK, array( Oauth_Clients::class, 'cleanup' ), 20 );

        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /**
     * The Timetics MCP URL, which every token is bound to.
     *
     * @return  string
     */
    public static function mcp_url(): string {
        return rest_url( 'timetics/v1/mcp' );
    }

    /**
     * The sign-in server's name.
     *
     * @return  string
     */
    public static function issuer(): string {
        return home_url( self::ISSUER_PATH );
    }

    /**
     * The MCP URL's path after the site address, as the resource document's address
     * ends with it. Plain links give the MCP URL no path of its own.
     *
     * @return  string
     */
    public static function resource_suffix(): string {
        $mcp = self::mcp_url();
        if ( false !== strpos( $mcp, '?' ) ) {
            return '/timetics/v1/mcp';
        }

        $home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );

        return substr( untrailingslashit( (string) wp_parse_url( $mcp, PHP_URL_PATH ) ), strlen( $home ) );
    }

    /**
     * What the MCP URL says about itself.
     *
     * @return  array
     */
    public static function protected_resource(): array {
        return array(
            'resource'                 => self::mcp_url(),
            'resource_name'            => __( 'WPTimetics', 'timetics' ),
            'authorization_servers'    => array( self::issuer() ),
            'scopes_supported'         => array( Oauth_Tokens::READ, Oauth_Tokens::WRITE ),
            'bearer_methods_supported' => array( 'header' ),
        );
    }

    /**
     * Where to log in and get tokens.
     *
     * @return  array
     */
    public static function authorization_server(): array {
        return array(
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => add_query_arg( Oauth_Authorize::QUERY_VAR, '1', home_url( '/' ) ),
            'token_endpoint'                        => rest_url( 'timetics/v1/oauth/token' ),
            'registration_endpoint'                 => rest_url( 'timetics/v1/oauth/register' ),
            'response_types_supported'              => array( 'code' ),
            'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
            'code_challenge_methods_supported'      => array( 'S256' ),
            'token_endpoint_auth_methods_supported' => array( 'none' ),
            'scopes_supported'                      => array( Oauth_Tokens::READ, Oauth_Tokens::WRITE ),
            'client_id_metadata_document_supported' => true,
        );
    }

    /**
     * Which discovery document a path asks for. Apps try several standard forms; a
     * site in a subfolder sees only the ones under its own path.
     *
     * @param   string  $path       Request path.
     * @param   string  $home_path  Path of the site address, '' at the domain root.
     *
     * @return  string|null  protected_resource, authorization_server or null.
     */
    public static function well_known( string $path, string $home_path ) {
        $own    = self::resource_suffix();
        $issuer = $home_path . self::ISSUER_PATH;
        $docs   = array(
            $home_path . '/.well-known/oauth-protected-resource' . $own => 'protected_resource',
            '/.well-known/oauth-protected-resource' . $home_path . $own => 'protected_resource',
            '/.well-known/oauth-authorization-server' . $issuer => 'authorization_server',
            '/.well-known/openid-configuration' . $issuer => 'authorization_server',
            $issuer . '/.well-known/oauth-authorization-server' => 'authorization_server',
            $issuer . '/.well-known/openid-configuration' => 'authorization_server',
        );

        return $docs[ untrailingslashit( $path ) ] ?? null;
    }

    /**
     * The WWW-Authenticate header for a refused MCP call. It asks for both permissions,
     * and the Allow page lets the user drop changes.
     *
     * @return  string
     */
    public static function challenge(): string {
        return sprintf(
            'Bearer resource_metadata="%s", scope="%s %s"',
            home_url( '/.well-known/oauth-protected-resource' . self::resource_suffix() ),
            Oauth_Tokens::READ,
            Oauth_Tokens::WRITE
        );
    }

    /**
     * Scopes of the token behind this request.
     *
     * @return  string[]|null  Null when the request did not use a token.
     */
    public static function token_scope() {
        return self::$scope;
    }

    /**
     * Drop write tools when the connection may only view, and owner tools for staff.
     *
     * @param   string[]  $names  Tool names as the adapter shows them.
     *
     * @return  string[]
     */
    public static function visible_tools( array $names ): array {
        $user       = get_current_user_id();
        $can_change = Mcp_Access::can_change( $user );
        $is_owner   = Mcp_Access::is_owner( $user );
        $hidden     = array();

        foreach ( Abilities::tools() as $name => $tool ) {
            if ( ( ! $can_change && empty( $tool['readonly'] ) ) || ( ! $is_owner && ! empty( $tool['owner'] ) ) ) {
                $hidden[] = str_replace( '/', '-', $name );
            }
        }

        return array_values( array_diff( $names, $hidden ) );
    }

    /**
     * Register the sign-in routes and the connection list. Registration and token
     * are public, as OAuth needs.
     *
     * @return  void
     */
    public static function register_routes() {
        register_rest_route(
            'timetics/v1',
            '/oauth/register',
            array(
                'methods'             => 'POST',
                'callback'            => array( self::class, 'register_client' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            'timetics/v1',
            '/oauth/token',
            array(
                'methods'             => 'POST',
                'callback'            => array( self::class, 'token' ),
                'permission_callback' => '__return_true',
            )
        );
        register_rest_route(
            'timetics/v1',
            '/mcp-connections',
            array(
                'methods'             => 'GET',
                'callback'            => array( self::class, 'list_connections' ),
                'permission_callback' => array( self::class, 'panel_access' ),
            )
        );
        register_rest_route(
            'timetics/v1',
            '/mcp-connections/(?P<id>[a-f0-9]{32})',
            array(
                'methods'             => 'DELETE',
                'callback'            => array( self::class, 'end_connection' ),
                'permission_callback' => array( self::class, 'panel_access' ),
            )
        );
    }

    /**
     * Register an app that has no published identity.
     *
     * @param   WP_REST_Request  $request  Request.
     *
     * @return  WP_REST_Response
     */
    public static function register_client( WP_REST_Request $request ) {
        $meta = json_decode( $request->get_body(), true );
        if ( ! is_array( $meta ) ) {
            return self::oauth_error( 'invalid_client_metadata', __( 'Send the app details as JSON.', 'timetics' ) );
        }

        $client = Oauth_Clients::register( $meta );
        if ( is_wp_error( $client ) ) {
            $refused = self::oauth_error( $client->get_error_code(), $client->get_error_message() );
            // 429 tells the app to try later; 400 would read as a broken request.
            if ( 'temporarily_unavailable' === $client->get_error_code() ) {
                $refused->set_status( 429 );
                $refused->header( 'Retry-After', (string) HOUR_IN_SECONDS );
            }

            return $refused;
        }

        return self::no_store(
            new WP_REST_Response(
                array(
                    'client_id'                  => $client['client_id'],
                    'client_name'                => $client['client_name'],
                    'redirect_uris'              => $client['redirect_uris'],
                    'grant_types'                => array( 'authorization_code', 'refresh_token' ),
                    'response_types'             => array( 'code' ),
                    'token_endpoint_auth_method' => 'none',
                ),
                201
            )
        );
    }

    /**
     * Whether the logged-in user may use the connection list.
     *
     * @return  bool
     */
    public static function panel_access(): bool {
        return Mcp_Access::can_use_tools( get_current_user_id() );
    }

    /**
     * Connections the current user may see.
     *
     * @return  WP_REST_Response
     */
    public static function list_connections() {
        return new WP_REST_Response(
            array(
                'success' => 1,
                'data'    => Oauth_Tokens::connections( get_current_user_id() ),
            )
        );
    }

    /**
     * End a connection. Staff may end only their own.
     *
     * @param   WP_REST_Request  $request  Request.
     *
     * @return  WP_REST_Response
     */
    public static function end_connection( WP_REST_Request $request ) {
        $id    = (string) $request['id'];
        $owner = Oauth_Tokens::owner_of( $id );
        $user  = get_current_user_id();

        if ( ! $owner || ( $owner !== $user && ! Mcp_Access::is_owner( $user ) ) ) {
            return new WP_REST_Response(
                array(
                    'success' => 0,
                    'message' => __( 'Connection not found.', 'timetics' ),
                ),
                404
            );
        }

        Oauth_Tokens::revoke( $id );

        return new WP_REST_Response(
            array(
                'success' => 1,
                'message' => __( 'Connection ended. The app can no longer reach WPTimetics.', 'timetics' ),
            )
        );
    }

    /**
     * Swap a code or a refresh token for tokens.
     *
     * @param   WP_REST_Request  $request  Request.
     *
     * @return  WP_REST_Response
     */
    public static function token( WP_REST_Request $request ) {
        $type   = (string) $request->get_param( 'grant_type' );
        $client = (string) $request->get_param( 'client_id' );

        if ( 'refresh_token' === $type ) {
            $tokens = Oauth_Tokens::refresh( (string) $request->get_param( 'refresh_token' ), $client );

            return $tokens ? self::no_store( new WP_REST_Response( $tokens ) ) : self::oauth_error( 'invalid_grant' );
        }

        if ( 'authorization_code' !== $type ) {
            return self::oauth_error( 'unsupported_grant_type' );
        }

        $grant = Oauth_Tokens::take_code( (string) $request->get_param( 'code' ) );
        if ( ! $grant || $grant['client_id'] !== $client || $grant['redirect_uri'] !== (string) $request->get_param( 'redirect_uri' ) || ! self::pkce_matches( (string) $request->get_param( 'code_verifier' ), $grant['challenge'] ) ) {
            return self::oauth_error( 'invalid_grant' );
        }

        $resource = $request->get_param( 'resource' );
        if ( null !== $resource && $grant['resource'] !== $resource ) {
            return self::oauth_error( 'invalid_target' );
        }

        return self::no_store( new WP_REST_Response( Oauth_Tokens::start( $grant ) ) );
    }

    /**
     * Answer discovery and the Allow page before WordPress looks for a post.
     *
     * @return  void
     */
    public static function answer_public_pages() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request; Oauth_Authorize checks its own nonce.
        if ( isset( $_GET[ Oauth_Authorize::QUERY_VAR ] ) ) {
            Oauth_Authorize::handle();
        }

        $path = (string) wp_parse_url( self::request_uri(), PHP_URL_PATH );
        $doc  = self::well_known( $path, untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ) );
        if ( ! $doc ) {
            return;
        }

        status_header( 200 );
        header( 'Content-Type: application/json' );
        header( 'Access-Control-Allow-Origin: *' );
        echo wp_json_encode( 'protected_resource' === $doc ? self::protected_resource() : self::authorization_server(), JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Let a valid token stand for its user, on the MCP URL only. Runs after
     * Application Passwords, so those keep working for developer apps.
     *
     * @param   int|false  $user  User found so far.
     *
     * @return  int|false
     */
    public static function authenticate( $user ) {
        self::$scope = null;
        $header      = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
        if ( 0 !== stripos( $header, 'Bearer ' ) || ! self::is_mcp_request() ) {
            return $user;
        }

        $found = Oauth_Tokens::user_for( trim( substr( $header, 7 ) ), self::mcp_url() );
        if ( ! $found ) {
            return 0;
        }

        self::$scope = $found['scope'];

        return $found['user'];
    }

    /**
     * Tell a refused MCP caller where to sign in.
     *
     * @param   WP_REST_Response  $response  Response.
     * @param   mixed             $server    Server.
     * @param   WP_REST_Request   $request   Request.
     *
     * @return  WP_REST_Response
     */
    public static function add_challenge( $response, $server, $request ) {
        if ( 401 === $response->get_status() && '/timetics/v1/mcp' === untrailingslashit( $request->get_route() ) ) {
            $response->header( 'WWW-Authenticate', self::challenge() );
        }

        return $response;
    }

    /**
     * Hide write tools from a view-only connection on the Timetics server.
     *
     * @param   array   $tools   Tool records.
     * @param   object  $server  The MCP server.
     *
     * @return  array
     */
    public static function filter_tools_list( $tools, $server ) {
        if ( ! is_array( $tools ) || ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) || 'timetics' !== $server->get_server_id() ) {
            return $tools;
        }

        $name = function ( $tool ) {
            return is_object( $tool ) && method_exists( $tool, 'getName' ) ? $tool->getName() : '';
        };
        $keep = self::visible_tools( array_map( $name, $tools ) );

        return array_values(
            array_filter(
                $tools,
                function ( $tool ) use ( $keep, $name ) {
                    return in_array( $name( $tool ), $keep, true );
                }
            )
        );
    }

    /**
     * Turning MCP off ends every connection at once.
     *
     * @param   mixed  $old       Old settings.
     * @param   mixed  $settings  New settings.
     *
     * @return  void
     */
    public static function end_all_when_off( $old, $settings ) {
        if ( empty( is_array( $settings ) ? ( $settings['mcp_enabled'] ?? '' ) : '' ) ) {
            Oauth_Tokens::revoke_all();
        }
    }

    /**
     * A new password ends that user's connections.
     *
     * @param   string  $password  New password.
     * @param   int     $user      User id.
     *
     * @return  void
     */
    public static function end_user_connections( $password, $user ) {
        Oauth_Tokens::revoke_user( (int) $user );
    }

    /**
     * A deleted user's connections end with them.
     *
     * @param   int  $user  User id.
     *
     * @return  void
     */
    public static function end_user_connections_on_delete( $user ) {
        Oauth_Tokens::revoke_user( (int) $user );
    }

    /**
     * Whether this request is for the Timetics MCP URL. Read from the raw request,
     * because WordPress may ask for the user before it has parsed the route.
     *
     * @return  bool
     */
    private static function is_mcp_request(): bool {
        // WordPress serves rest_route from the body first, then the query, then the path
        // (WP::parse_request()), so check them in that order.
        // phpcs:disable WordPress.Security.NonceVerification -- Routing only, nothing is saved.
        if ( isset( $_POST['rest_route'] ) ) {
            return self::is_mcp_route( sanitize_text_field( wp_unslash( $_POST['rest_route'] ) ) );
        }
        if ( isset( $_GET['rest_route'] ) ) {
            return self::is_mcp_route( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification

        // With plain links every route shares one path, so only rest_route can name it.
        $mcp = self::mcp_url();
        if ( false !== strpos( $mcp, '?' ) ) {
            return false;
        }

        $path = (string) wp_parse_url( self::request_uri(), PHP_URL_PATH );

        return untrailingslashit( $path ) === untrailingslashit( (string) wp_parse_url( $mcp, PHP_URL_PATH ) );
    }

    /**
     * Refuse a token request whose served route is not the MCP URL. The route WordPress
     * serves is the final word; is_mcp_request() only guesses it before routing. Calls the
     * tools make inside the MCP request (rest_do_request) keep working.
     *
     * @param   mixed  $result  Earlier answer.
     *
     * @return  mixed
     */
    public static function keep_token_on_mcp( $result ) {
        $served = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
        if ( null === self::$scope || self::is_mcp_route( $served ) ) {
            return $result;
        }

        return new WP_Error( 'rest_forbidden', __( 'This token only opens the WPTimetics MCP URL.', 'timetics' ), array( 'status' => 401 ) );
    }

    /**
     * Whether a REST route is the MCP route.
     *
     * @param   string  $route  REST route.
     *
     * @return  bool
     */
    private static function is_mcp_route( string $route ): bool {
        return '/timetics/v1/mcp' === '/' . trim( $route, '/' );
    }

    /**
     * Whether a PKCE verifier matches the challenge.
     *
     * @param   string  $verifier   From the token request.
     * @param   string  $challenge  From the Allow.
     *
     * @return  bool
     */
    private static function pkce_matches( string $verifier, string $challenge ): bool {
        if ( strlen( $verifier ) < 43 || strlen( $verifier ) > 128 ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE defines the challenge as base64url of a SHA-256.
        return hash_equals( $challenge, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) );
    }

    /**
     * The raw request URI.
     *
     * @return  string
     */
    private static function request_uri(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    }

    /**
     * An OAuth error body.
     *
     * @param   string  $code     Error code.
     * @param   string  $message  Description.
     *
     * @return  WP_REST_Response
     */
    private static function oauth_error( string $code, string $message = '' ): WP_REST_Response {
        return self::no_store(
            new WP_REST_Response(
                array_filter(
                    array(
                        'error'             => $code,
                        'error_description' => $message,
                    )
                ),
                400
            )
        );
    }

    /**
     * Tokens must never be cached.
     *
     * @param   WP_REST_Response  $response  Response.
     *
     * @return  WP_REST_Response
     */
    private static function no_store( WP_REST_Response $response ): WP_REST_Response {
        $response->header( 'Cache-Control', 'no-store' );
        $response->header( 'Pragma', 'no-cache' );

        return $response;
    }
}
