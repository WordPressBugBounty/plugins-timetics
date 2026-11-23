<?php
/**
 * MCP setup card
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * What the settings card needs: whether MCP is on, what is missing, and the URL.
 * Registered even when MCP is off, because the card is how an owner turns it on.
 */
class Mcp_Setup {

    const ADAPTER_SLUG  = 'mcp-adapter';
    const ADAPTER_FILE  = 'mcp-adapter/mcp-adapter.php';
    const ADAPTER_CLASS = 'WP\\MCP\\Core\\McpAdapter';

    /**
     * Register the routes.
     *
     * @return  void
     */
    public static function register() {
        add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
    }

    /**
     * Owner-only routes for the card.
     *
     * @return  void
     */
    public static function register_routes() {
        $owner = function () {
            return current_user_can( 'manage_options' );
        };

        register_rest_route(
            'timetics/v1',
            '/mcp-setup',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => function () {
                        return rest_ensure_response( array( 'data' => self::status() ) );
                    },
                    'permission_callback' => $owner,
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( self::class, 'switch_mcp' ),
                    'permission_callback' => $owner,
                ),
            )
        );

        register_rest_route(
            'timetics/v1',
            '/mcp-setup/adapter',
            array(
                'methods'             => 'POST',
                'callback'            => array( self::class, 'add_adapter' ),
                'permission_callback' => function () {
                    return current_user_can( 'activate_plugins' );
                },
            )
        );
    }

    /**
     * What the card shows.
     *
     * @return  array  enabled, wordpress_ok, adapter (active, installed or missing), server
     *                 (the adapter took the Timetics server), can_install, public_https, mcp_url.
     */
    public static function status(): array {
        $active  = self::adapter_active();
        $enabled = (bool) timetics_get_option( 'mcp_enabled' );
        $server  = Mcp_Server::is_running();

        return array(
            'enabled'      => $enabled,
            'wordpress_ok' => function_exists( 'wp_register_ability' ),
            'adapter'      => $active ? 'active' : ( self::adapter_installed() ? 'installed' : 'missing' ),
            'server'       => $server,
            'can_install'  => current_user_can( 'install_plugins' ),
            'public_https' => self::is_public_https( home_url() ),
            'mcp_url'      => $enabled && $active && $server ? Oauth_Server::mcp_url() : '',
        );
    }

    /**
     * Turn MCP on or off. Off ends every connection (Oauth_Server::end_all_when_off).
     *
     * @param   WP_REST_Request  $request  Body: enabled.
     *
     * @return  \WP_REST_Response|WP_Error
     */
    public static function switch_mcp( WP_REST_Request $request ) {
        $body = json_decode( $request->get_body(), true );
        if ( ! is_array( $body ) || ! isset( $body['enabled'] ) ) {
            return new WP_Error( 'invalid_body', __( 'Send enabled as true or false.', 'timetics' ), array( 'status' => 400 ) );
        }

        $on = (bool) $body['enabled'];
        if ( $on && ! function_exists( 'wp_register_ability' ) ) {
            return new WP_Error( 'wordpress_too_old', __( 'AI apps need WordPress 6.9 or newer.', 'timetics' ), array( 'status' => 409 ) );
        }

        timetics_update_option( 'mcp_enabled', $on );

        return rest_ensure_response(
            array(
                'data'    => self::status(),
                'message' => $on ? __( 'AI apps can now connect.', 'timetics' ) : __( 'AI apps are turned off, and every connection has ended.', 'timetics' ),
            )
        );
    }

    /**
     * Install the MCP Adapter from wordpress.org if needed, and activate it.
     *
     * @return  \WP_REST_Response|WP_Error
     */
    public static function add_adapter() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ( ! self::adapter_installed() ) {
            if ( ! current_user_can( 'install_plugins' ) ) {
                return new WP_Error( 'cannot_install', __( 'Ask a site administrator to install the MCP Adapter plugin.', 'timetics' ), array( 'status' => 403 ) );
            }

            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $info = plugins_api( 'plugin_information', array( 'slug' => self::ADAPTER_SLUG ) );
            if ( is_wp_error( $info ) || empty( $info->download_link ) ) {
                return new WP_Error( 'adapter_not_found', __( 'The MCP Adapter could not be found on wordpress.org.', 'timetics' ), array( 'status' => 502 ) );
            }

            $installed = ( new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() ) )->install( $info->download_link );
            if ( true !== $installed ) {
                return new WP_Error( 'adapter_not_installed', __( 'The MCP Adapter could not be installed.', 'timetics' ), array( 'status' => 502 ) );
            }
            wp_clean_plugins_cache();
        }

        $active = activate_plugin( self::ADAPTER_FILE );
        if ( is_wp_error( $active ) ) {
            return new WP_Error( $active->get_error_code(), $active->get_error_message(), array( 'status' => 500 ) );
        }

        // activate_plugin() can return null without activating, so read the plugin list.
        if ( ! is_plugin_active( self::ADAPTER_FILE ) ) {
            return new WP_Error( 'adapter_not_active', __( 'The MCP Adapter was installed but could not be activated. Activate it from the Plugins page.', 'timetics' ), array( 'status' => 500 ) );
        }

        // The class loads on the next request, so this answer says active from the plugin list.
        return rest_ensure_response(
            array(
                'data'    => array( 'adapter' => 'active' ) + self::status(),
                'message' => __( 'The MCP Adapter is active.', 'timetics' ),
            )
        );
    }

    /**
     * Whether sign-in can work here: an https address the AI app's servers can reach.
     *
     * @param   string  $url  Site address.
     *
     * @return  bool
     */
    public static function is_public_https( string $url ): bool {
        $parts = wp_parse_url( $url );
        $host  = strtolower( (string) ( $parts['host'] ?? '' ) );

        if ( 'https' !== ( $parts['scheme'] ?? '' ) || '' === $host || 'localhost' === $host || preg_match( '/\.(local|test|localhost|invalid|example)$/', $host ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }

        return true;
    }

    /**
     * Whether the MCP Adapter plugin itself is on. WooCommerce and others bundle an
     * older copy of the class, so the class alone does not prove it.
     *
     * @return  bool
     */
    private static function adapter_active(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active( self::ADAPTER_FILE );
    }

    /**
     * Whether the adapter plugin is installed, active or not.
     *
     * @return  bool
     */
    private static function adapter_installed(): bool {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return array_key_exists( self::ADAPTER_FILE, get_plugins() );
    }
}
