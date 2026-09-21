<?php
/**
 * Class Api Settings
 *
 * @package Timetics
 */
namespace Timetics\Core\Settings;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
use Timetics\Core\Admin\Hooks;
use Timetics\Utils\Singleton;

class Api_Settings extends Api {
    use Singleton;

    /**
     * Store api namespace
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @var string
     */
    protected $rest_base = 'settings';

    /**
     * Register rest route
     *
     * @return  void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace, $this->rest_base, [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_settings'],
                    'permission_callback' => function () {
                        // The public booking app needs a small, explicitly safe
                        // settings payload. get_settings() filters it by role.
                        return true;
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'update_settings'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ],
            ]
        );

        register_rest_route(
            $this->namespace, $this->rest_base . '/business', [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'setup_business'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ],
            ]
        );

        register_rest_route(
            $this->namespace, $this->rest_base . '/business/categories', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_busyness_categories'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ],
            ]
        );
    }

    /**
     * Get settings
     *
     * @return  JSON
     */
    public function get_settings() {
        $settings = apply_filters( 'timetics_settings', timetics_get_settings() );

        // The booking and staff interfaces need non-sensitive display and scheduling
        // settings. Never send credentials or webhook URLs to non-administrators.
        if ( ! current_user_can( 'manage_options' ) ) {
            $settings = $this->get_staff_safe_settings( $settings );
        }

        $data = [
            'status_code' => 200,
            'success'     => 1,
            'message'     => esc_html__( 'Get all settings', 'timetics' ),
            'data'        => $settings,
        ];

        return rest_ensure_response( $settings );
    }

    /**
     * Return only settings that staff need to use the admin interface.
     *
     * This is deliberately an allow-list. New settings remain private until they
     * have been reviewed and explicitly added here.
     *
     * @param array $settings Plugin settings.
     *
     * @return array
     */
    private function get_staff_safe_settings( $settings ) {
        $safe_keys = [
            'availability',
            'apple_calendar',
            'blocked_days',
            'busyness_category',
            'calendar_locale',
            'currency',
            'custom_fields',
            'default_booking_status',
            'guest_enabled',
            'guest_limit',
            'google_calendar',
            'locale_timezone',
            'paypal_status',
            'primary_color',
            'remainder_time',
            'secondary_color',
            'slot_interval',
            'stripe_status',
            'outlook_calendar',
            'wc_integration',
            'wc_checkout_url',
            'zoom_connection_type',
        ];

        return array_intersect_key( (array) $settings, array_flip( $safe_keys ) );
    }

    /**
     * Update settings
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function update_settings( $request ) {
        $options = json_decode( $request->get_body(), true );

        if ( ! is_array( $options ) ) {
            return new \WP_Error(
                'timetics_invalid_settings',
                __( 'Settings must be sent as a JSON object.', 'timetics' ),
                [ 'status' => 400 ]
            );
        }

        /**
         * Filter the settings payload before any of it is checked or saved.
         *
         * Runs before the checks below, so a listener's result passes through
         * them like the raw request does. Add-ons use it to clean their own
         * keys. It is an extension point, not the sanitization for core keys.
         *
         * @since 1.0.63
         *
         * @param array $options Settings payload from the request body.
         */
        $filtered = apply_filters( 'timetics_settings_update_params', $options );

        // A listener returning a non-array must not make the save below write nothing.
        $options = is_array( $filtered ) ? $filtered : $options;

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $data = [
            'status_code' => 200,
            'success'     => 1,
            'message'     => esc_html__( 'Settings successfully updated', 'timetics' ),
            'data'        => timetics_get_settings(),
        ];

        // custom domain
        if (!empty($options['custom_domain_url']) && isset($options['custom_domain_url'])) {
            $custom_domain_data = [
                'custom_domain_url' => $options['custom_domain_url'],
                'network_slug' => $options['network_slug'],
            ];
            do_action('timetics_custom_domain_data', $custom_domain_data);
        }


        if ( !empty($options['schedule']) && $options['schedule'] && apply_filters('timetics/staff/member/availability', false)) {
            return rest_ensure_response( apply_filters( 'timetics/admin/staff/error_data', $data, 'availability_update' ) );
        }

        if ( !empty($options['availability']) && $options['availability'] && apply_filters('timetics/staff/meeting/availability', false)) {
            return rest_ensure_response( apply_filters( 'timetics/admin/appointment/error_data', $data, 'availability_update' ) );
        }

        if ( ! empty( $options['custom_fields'] ) && apply_filters( 'timetics/admin/settings/custom_fields', false, $options['custom_fields'] ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'custom_fields', timetics_get_settings() ) );
        }

        if ( ! empty( $options['paypal_status'] ) && $options['paypal_status'] && apply_filters( 'timetics/admin/settings/paypal', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'paypal', timetics_get_settings() ) );
        }

        if ( ! empty( $options['zoom_client_secret'] ) && $options['zoom_client_secret'] && apply_filters( 'timetics/admin/settings/zoom', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'zoom', timetics_get_settings() ) );
        }

        if ( ! empty( $options['fluentcrm_webhook'] ) && $options['fluentcrm_webhook'] && apply_filters( 'timetics/admin/settings/fluentcrm_webhook', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'fluent_crm', timetics_get_settings() ) );
        }

        if ( ! empty( $options['pabbly_webhook'] ) && $options['pabbly_webhook'] && apply_filters( 'timetics/admin/settings/pabbly_webhook', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'pablly', timetics_get_settings() ) );
        }

        if ( ! empty( $options['zapier_webhook'] ) && $options['zapier_webhook'] && apply_filters( 'timetics/admin/settings/zapier_webhook', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'zapier', timetics_get_settings() ) );
        }

        if ( ! empty( $options['flowmattic_webhook'] ) && $options['flowmattic_webhook'] && apply_filters( 'timetics/admin/settings/flowmattic_webhook', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'flowmattic', timetics_get_settings() ) );
        }

        if (!empty($options['google_app_client_id']) && $options['google_app_client_id'] && apply_filters('timetics/admin/settings/google_calendar', false)) {
            return rest_ensure_response(apply_filters('timetics/admin/settings/error_data', $data, 'google-calendar', timetics_get_settings()));
        }

        if ( ! empty( $options['google_app_client_secret'] ) && $options['google_app_client_secret'] && apply_filters( 'timetics/admin/settings/google-calendar', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'google-calendar', timetics_get_settings() ) );
        }
        
        if ( ! empty( $options['outlook_calendar'] ) && $options['outlook_calendar'] && apply_filters( 'timetics/admin/settings/outlook_calendar', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'outlook', timetics_get_settings() ) );
        }

        if ( ! empty( $options['apple_calendar'] ) && $options['apple_calendar'] && apply_filters( 'timetics/admin/settings/apple_calendar', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'paypal', timetics_get_settings() ) );
        }

        if ( ! empty( $options['uncanny_automator'] ) && $options['uncanny_automator'] && apply_filters( 'timetics/admin/settings/uncanny_automator', false ) ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/settings/error_data', $data, 'uncanny_automator', timetics_get_settings() ) );
        }

        if (!empty($options['twillo_message']) && $options['twillo_message'] && apply_filters('timetics/admin/settings/twillo_messaging', false)) {
            return rest_ensure_response(apply_filters('timetics/admin/settings/error_data', $data, 'twillo_messaging', timetics_get_settings()));
        }

        if ( $options ) {
            foreach ( $options as $key => $value ) {
                // Webhook URLs are pasted from FlowMattic, so keep them a URL.
                if ( 'flowmattic_webhook' === $key ) {
                    $value = esc_url_raw( $value );
                }

                // Stored as the strings `yes`/`no`: this
                // option defaults to on, and timetics_get_option() treats an
                // empty value as "unset" and hands back the default, so a
                // boolean false could never switch it off.
                if ( 'uncanny_automator' === $key ) {
                    $value = $value && 'no' !== $value ? 'yes' : 'no';
                }

                // Clamp: the cleanup cron only runs every 5 minutes, so a
                // lower value would silently do nothing and 0/negative would
                // expire bookings instantly.
                if ( 'unpaid_booking_expiry_minutes' === $key ) {
                    $value = max( 5, absint( $value ) );
                }

                timetics_update_option( $key, $value );
            }
        }

        $data['data'] = timetics_get_settings();

        return rest_ensure_response( $data );
    }

    /**
     * Business setup
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  void
     */
    public function setup_business( $request ) {
        $data = json_decode( $request->get_body(), true );

        $email = ! empty( $data['email'] ) ? $data['email'] : '';

        $body = array(
            'email' => $email,
        );

        $response_user = wp_remote_post( 'https://arraytics.com/?fluentcrm=1&route=contact&hash=0d9cd3d1-514d-4e1a-9147-09dfd5f9e997', ['body' => $body] );

        $response = [
            'status'  => 200,
            'success' => 1,
            'message' => __( 'Successfully updated business', 'timetics' ),
        ];

        return rest_ensure_response( $response );
    }

    /**
     *  Get busyness categories
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_busyness_categories( $request ) {
        $busyness_categories = timetics_get_busyness_categories();

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'data'        => $busyness_categories,
        ];

        return rest_ensure_response( $response );
    }

}
