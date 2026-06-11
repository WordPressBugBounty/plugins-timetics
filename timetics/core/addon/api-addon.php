<?php
/**
 * Addon REST API Controller
 *
 * @package Timetics
 */

namespace Timetics\Core\Addon;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
use Timetics\Utils\Singleton;
use Arraytics\ToolsSdk\PluginManager;
use WP_REST_Request;

/**
 * Class Api_Addon
 *
 * Handles GET (list) and PUT (status update) for Arraytics plugins
 * displayed on the About Us page.
 *
 * @since 1.0.0
 */
class Api_Addon extends Api {

    use Singleton;

    /**
     * REST namespace.
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * REST base route.
     *
     * @var string
     */
    protected $rest_base = 'addons';

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_items' ],
                    'permission_callback' => [ $this, 'get_items_permissions_check' ],
                    'args'                => [
                        'type' => [
                            'description' => __( 'Filter by extension type: module, addon, plugin, or all.', 'timetics' ),
                            'type'        => 'string',
                            'enum'        => [ 'module', 'addon', 'plugin', 'all' ],
                            'default'     => 'all',
                        ],
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_item' ],
                    'permission_callback' => [ $this, 'update_item_permissions_check' ],
                ],
            ]
        );
    }

    /**
     * Permission check for GET.
     *
     * @return bool
     */
    public function get_items_permissions_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    /**
     * Permission check for PUT/POST.
     *
     * @return bool
     */
    public function update_item_permissions_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    /**
     * GET /timetics/v1/addons
     *
     * Returns the addon list filtered by ?type=module|addon|plugin|all.
     *
     * @param WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_items( $request ) {
        $type       = ! empty( $request['type'] ) ? sanitize_key( $request['type'] ) : 'all';
        $extensions = timetics_extension();

        $type_map = [
            'module' => [ $extensions, 'get_modules' ],
            'addon'  => [ $extensions, 'get_addons' ],
            'plugin' => [ $extensions, 'get_plugins' ],
            'all'    => [ $extensions, 'get' ],
        ];

        if ( ! isset( $type_map[ $type ] ) ) {
            return $this->send_error(
                __( 'Invalid extension type.', 'timetics' ),
                [ 'status' => 400 ]
            );
        }

        $items = array_values( call_user_func( $type_map[ $type ] ) );

        return rest_ensure_response(
            [
                'success' => true,
                'data'    => $items,
            ]
        );
    }

    /**
     * PUT /timetics/v1/addons
     *
     * Updates the status of an Arraytics plugin (install/activate/deactivate/upgrade).
     *
     * @param WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function update_item( $request ) {
        $params = json_decode( $request->get_body(), true );

        $name   = isset( $params['name'] )   ? sanitize_text_field( $params['name'] )   : '';
        $status = isset( $params['status'] ) ? sanitize_text_field( $params['status'] ) : '';

        $valid_statuses = [ 'install', 'activate', 'deactivate', 'upgrade' ];

        if ( empty( $name ) ) {
            return $this->send_error(
                __( 'Please enter an extension name.', 'timetics' ),
                [ 'status' => 422 ]
            );
        }

        if ( empty( $status ) || ! in_array( $status, $valid_statuses, true ) ) {
            return $this->send_error(
                /* translators: %s: status value */
                sprintf( __( 'Invalid status "%s" provided.', 'timetics' ), $status ),
                [ 'status' => 422 ]
            );
        }

        $extension = timetics_extension()->find( $name );

        if ( ! $extension ) {
            return $this->send_error(
                /* translators: %s: plugin name */
                sprintf( __( 'Extension "%s" not found.', 'timetics' ), $name ),
                [ 'status' => 404 ]
            );
        }

        // Redirect for upgrade (premium) actions.
        if ( 'upgrade' === $status ) {
            return rest_ensure_response(
                [
                    'success'      => true,
                    'data'         => [ 'redirect_url' => $extension['upgrade_link'] ],
                    'message'      => __( 'Redirecting to upgrade page.', 'timetics' ),
                ]
            );
        }

        // All registered extensions are type=plugin — delegate to PluginManager.
        $slug = isset( $extension['slug'] ) ? $extension['slug'] : $name;

        // Our-Plugins download_url wins over the wordpress.org slug lookup, so a
        // non-wordpress.org URL (e.g. GitHub release zip) is not shadowed.
        $download_url = ! empty( $extension['download_url'] ) ? $extension['download_url'] : '';

        switch ( $status ) {
            case 'install':
                if ( ! function_exists( 'WP_Filesystem' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                WP_Filesystem();
                $result = $download_url
                    ? $this->install_from_url( $download_url )
                    : PluginManager::install_plugin( $slug );
                break;
            case 'activate':
                $result = PluginManager::activate_plugin( $slug );
                break;
            case 'deactivate':
                $result = PluginManager::deactivate_plugin( $slug );
                break;
            default:
                $result = false;
        }

        if ( false === $result || is_wp_error( $result ) ) {
            $message = is_wp_error( $result )
                ? $result->get_error_message()
                /* translators: %s: action name */
                : sprintf( __( 'Could not %s the extension.', 'timetics' ), $status );

            return $this->send_error( $message, [ 'status' => 500 ] );
        }

        return rest_ensure_response(
            [
                'success' => true,
                'data'    => [
                    'name'   => $name,
                    'status' => $status,
                ],
                /* translators: %s: action name */
                'message' => sprintf( __( 'Extension %s successfully.', 'timetics' ), $status . 'd' ),
            ]
        );
    }

    /**
     * Install a plugin from an explicit download URL.
     *
     * The URL must be HTTPS and its host (or a subdomain of it) must be in the
     * trusted-domain allowlist. This lets us install Arraytics plugins hosted
     * outside wordpress.org (e.g. GitHub release zips).
     *
     * @param string $url Absolute HTTPS download URL.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    private function install_from_url( string $url ) {
        $allowed_hosts = [
            'wordpress.org',
            'downloads.wordpress.org',
            'arraytics.com',
            'themewinter.com',
            'github.com',
        ];

        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['scheme'] ) || 'https' !== strtolower( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return new \WP_Error(
                'invalid_download_url',
                __( 'Download URL must use HTTPS from a trusted domain.', 'timetics' )
            );
        }

        $host    = strtolower( $parsed['host'] );
        $trusted = false;

        foreach ( $allowed_hosts as $allowed ) {
            if ( $host === $allowed || substr( $host, - ( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) {
                $trusted = true;
                break;
            }
        }

        if ( ! $trusted ) {
            return new \WP_Error(
                'invalid_download_url',
                __( 'Download URL must use HTTPS from a trusted domain.', 'timetics' )
            );
        }

        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/misc.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result ? true : false;
    }

    /**
     * Return a standardised error response.
     *
     * @param string $message Human-readable error message.
     * @param array  $data    Additional data (e.g. ['status' => 422]).
     * @return \WP_REST_Response
     */
    private function send_error( string $message, array $data = [] ): \WP_REST_Response {
        return rest_ensure_response(
            [
                'success' => false,
                'message' => $message,
                'data'    => $data,
            ]
        );
    }
}
