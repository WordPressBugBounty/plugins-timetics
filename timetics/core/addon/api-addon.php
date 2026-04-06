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

        switch ( $status ) {
            case 'install':
                if ( ! function_exists( 'WP_Filesystem' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                WP_Filesystem();
                $result = PluginManager::install_plugin( $slug );
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
