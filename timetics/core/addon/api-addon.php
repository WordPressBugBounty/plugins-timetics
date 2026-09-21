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

        // The Ask AI setup dialog lets the user edit the account email. Reject a bad one before anything is installed.
        $email = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';

        if ( isset( $params['email'] ) && ! is_email( $email ) ) {
            return $this->send_error(
                __( 'Enter a valid email address.', 'timetics' ),
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

        // PluginManager checks no capabilities, so require what doing this by hand in Plugins needs.
        $needs_install = 'install' === $status || ( 'activate' === $status && ! PluginManager::is_installed( $slug ) );

        if ( ! current_user_can( $needs_install ? 'install_plugins' : 'activate_plugins' ) ) {
            return $this->send_error(
                __( 'Sorry, you are not allowed to manage plugins on this site.', 'timetics' ),
                [ 'status' => 403 ]
            );
        }

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
                // Activate can be reached on a plugin that was never installed
                // (onboarding offers it in one click), so install on demand.
                if ( ! PluginManager::is_installed( $slug ) ) {
                    if ( ! function_exists( 'WP_Filesystem' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }
                    WP_Filesystem();
                    $install = $download_url
                        ? $this->install_from_url( $download_url )
                        : PluginManager::install_plugin( $slug );

                    if ( false === $install || is_wp_error( $install ) ) {
                        return $this->send_error(
                            is_wp_error( $install )
                                ? $install->get_error_message()
                                : __( 'Plugin installation failed.', 'timetics' ),
                            [ 'status' => 500 ]
                        );
                    }
                }

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

        $data = [
            'name'   => $name,
            'status' => $status,
        ];

        /*
         * Registration only runs when the caller sent explicit consent, which
         * today means the onboarding checkbox, the dashboard banner button or
         * the Ask AI setup dialog. Activating from About Us installs the plugin
         * and stops there, so no identity leaves the site without the user
         * opting in. Strict: a "1" or "true" string never counts as agreement.
         */
        if ( 'aisentic' === $name && 'activate' === $status && true === ( $params['consent'] ?? null ) && PluginManager::is_activated( $slug ) ) {
            // Snapshot before the handshake so the caller can tell a fresh
            // registration (tokens just granted) from re-activating a site that
            // was already connected (no new tokens).
            $was_registered = timetics_aisentic_is_registered();

            $this->register_aisentic_site( $email );

            $is_registered = timetics_aisentic_is_registered();

            // The banner needs to know whether the handshake actually landed so
            // it can show an error instead of silently disappearing.
            $data['aisentic_registered'] = $is_registered;

            // True only when this request is what connected the site, so the
            // "150K tokens added" message never fires on a plain re-activation.
            $data['aisentic_newly_registered'] = $is_registered && ! $was_registered;
        }

        return rest_ensure_response(
            [
                'success' => true,
                'data'    => $data,
                /* translators: %s: action name */
                'message' => sprintf( __( 'Extension %s successfully.', 'timetics' ), $status . 'd' ),
            ]
        );
    }

    /**
     * Record the user's consent and hand the identity to Aisentic.
     *
     * Values come from timetics_aisentic_identity() so they match what the
     * consent UI showed. Aisentic swallows provider errors and skips the call
     * when it already has an api key, so this never affects the activation
     * response.
     *
     * @param string $email Email the user typed, empty to use their account email.
     * @return void
     */
    private function register_aisentic_site( $email = '' ) {
        // Older Aisentic builds have no listener for the action below, so the
        // handshake would go nowhere. Skip instead of storing consent for a
        // registration that cannot happen.
        if ( ! class_exists( 'Aisentic\Api\Services\Registration_Service' ) ) {
            return;
        }

        $identity = timetics_aisentic_identity( $email );

        // No email means nothing to register with, and Aisentic would reject
        // the call anyway. Fail closed rather than inventing a value.
        if ( empty( $identity['email'] ) ) {
            return;
        }

        // Proof of consent: who agreed, when, and for which email. Also lets
        // the banner tell "declined" apart from "never asked".
        update_option(
            'timetics_aisentic_consent',
            [
                'agreed'  => true,
                'time'    => gmdate( 'c' ),
                'user_id' => get_current_user_id(),
                'email'   => $identity['email'],
            ],
            false
        );

        /**
         * Fires after the user opts in to connecting the site with Aisentic.
         *
         * Aisentic's Timetics integration listens for this, registers the site
         * with its provider and marks itself connected.
         *
         * @param string $account_name Account name shown in the consent UI.
         * @param string $email        Account email shown in the consent UI.
         * @param string $site_url     Site URL to register with the provider.
         */
        do_action( 'timetics/aisentic/register_site', $identity['name'], $identity['email'], $identity['site_url'] );
    }

    /**
     * Install a plugin from an explicit download URL.
     *
     * The URL must be HTTPS and its host (or a subdomain of it) must be in the
     * trusted-domain allowlist.
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
