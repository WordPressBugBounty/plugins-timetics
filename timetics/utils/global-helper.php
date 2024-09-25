<?php
use function Timetics\timetics;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Integrations\Google\Client;
use Timetics\Core\Integrations\Google\Service\Calendar;

/**
 * Get timezone list.
 *
 * @since 1.0.0
 *
 * @return  array
 */
if ( ! function_exists( 'timetics_get_timezone_list' ) ) {
    function timetics_get_timezone_list() {
        $tzlist = wp_timezone_choice( 'UTC' );
        $doc    = new DOMDocument();
        $doc->loadHTML( $tzlist );

        $elements  = $doc->getElementsByTagName( 'option' );
        $timezones = [];

        if ( ! empty( $elements ) ) {
            foreach ( $elements as $element ) {
                $data = [
                    'value' => $element->getAttribute( 'value' ),
                    'name'  => $element->textContent, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
                ];

                $timezones[] = $data;
            }
        }

        return $timezones;
    }
}

if ( ! function_exists( 'timetics_get_google_access_token' ) ) {
    /**
     * Get google access token
     *
     * @param   integer  $user_id  Authenticate user id
     *
     * @since 1.0.0
     *
     * @return  string
     */
    function timetics_get_google_access_token( $user_id = 0 ) {
        $data = timetics_get_google_auth( $user_id );

        if ( ! $data ) {
            return false;
        }

        if (  ( $data['expires_in'] - 30 ) < time() ) {
            $refresh_toekn = timetics_get_google_refresh_token( $user_id );
            $client        = timetics_get_google_client();

            $data = $client->fetch_access_token_with_refresh_token( $refresh_toekn );

            if ( ! $data ) {
                return false;
            }

            timetics_update_google_auth( $user_id, $data );
        }

        return $data['access_token'];
    }
}

if ( ! function_exists( 'timetics_get_google_refresh_token' ) ) {

    /**
     * Get google auth refresh token
     *
     * @param   integer  $user_id  Authenticate user id
     *
     * @return  string
     */
    function timetics_get_google_refresh_token( $user_id ) {
        return get_user_meta( $user_id, 'timetics_google_refresh_token', true );
    }
}

if ( ! function_exists( 'timetics_get_auth_redirect_uri' ) ) {
    /**
     * Get auth redirect uri
     *
     * @param   string  $auth
     *
     * @return  string
     */
    function timetics_get_auth_redirect_uri( $auth = '' ) {
        return site_url( 'timetics-integration/' . $auth );
    }
}

if ( ! function_exists( 'timetics_update_google_auth' ) ) {
    /**
     * Update google auth token
     *
     * @param   integer  $user_id
     * @param   array  $data
     *
     * @return void
     */
    function timetics_update_google_auth( $user_id = 0, $data = [] ) {
        if ( ! empty( $data['code'] ) ) {
            update_user_meta( $user_id, 'timetics_google_auth_code', $data['code'] );
        }

        if ( ! empty( $data['refresh_token'] ) ) {
            update_user_meta( $user_id, 'timetics_google_refresh_token', $data['refresh_token'] );
        }

        $data['expires_in'] = $data['expires_in'] + time();

        update_user_meta( $user_id, 'timetics_google_auth', $data );
    }
}

if ( ! function_exists( 'timetics_get_google_auth' ) ) {
    /**
     * Get google auth
     *
     * @param   integer  $user_id
     *
     * @return  array
     */
    function timetics_get_google_auth( $user_id = 0 ) {
        return get_user_meta( $user_id, 'timetics_google_auth', true );
    }
}

if ( ! function_exists( 'timetics_get_google_client' ) ) {
    /**
     * Get google auth client
     *
     * @return Timetics\Core\Integrations\Google\Client
     */
    function timetics_get_google_client() {
        $client_id     = timetics_get_option( 'google_app_client_id' );
        $client_secret = timetics_get_option( 'google_app_client_secret' );

        $redirect_uri = timetics_get_auth_redirect_uri( 'google-auth' );

        $client = new Client();
        $client->add_scope( Calendar::scope() );
        $client->set_auth_config(
            [
                'client_id'      => $client_id,
                'client_secrete' => $client_secret,
            ]
        );

        $client->set_redirect_uri( $redirect_uri );

        return $client;
    }
}

if ( ! function_exists( 'timetics_get_google_auth_url' ) ) {
    /**
     * Get google auth url
     *
     * @return  string
     */
    function timetics_get_google_auth_url() {
        $client = timetics_get_google_client();

        return $client->get_auth_url();
    }
}

if ( ! function_exists( 'timetics_get_posts' ) ) {
    /**
     * Get posts with date range
     *
     * @param   array  $args
     *
     * @return  Object
     */
    function timetics_get_posts( $args = [] ) {
        $defaults = [
            'post_type'   => 'post',
            'post_status' => 'publish',
            'nopaging'    => true,
            'date_range'  => '',
        ];

        $args = wp_parse_args( $args, $defaults );

        // Date range found.
        if ( $args['date_range'] ) {
            $from_date = $args['date_range']['start'];
            $to_date   = $args['date_range']['end'];

            $args['date_query'] = [
                'relation' => 'AND',
                [
                    'before'    => [
                        'year'  => gmdate( 'Y', strtotime( $to_date ) ),
                        'month' => gmdate( 'm', strtotime( $to_date ) ),
                        'day'   => gmdate( 'd', strtotime( $to_date ) ),
                    ],
                    'after'     => [
                        'year'  => gmdate( 'Y', strtotime( $from_date ) ),
                        'month' => gmdate( 'm', strtotime( $from_date ) ),
                        'day'   => gmdate( 'd', strtotime( $from_date ) ),
                    ],
                    'inclusive' => true,
                ],
            ];
        }

        // The Query
        $posts = new WP_Query( $args );

        return $posts;
    }
}

if ( ! function_exists( 'timetics_count_posts()' ) ) {
    /**
     * Count post with date range or without date range
     *
     * @param   array  $args  Required query params
     *
     * @return  integer
     */
    function timetics_count_posts( $args = [] ) {
        $posts = timetics_get_posts( $args );

        return $posts->post_count;
    }
}

if ( ! function_exists( 'timetics_count_users' ) ) {
    /**
     * Count user by role and date range
     *
     * @param   array  $args  Required query params
     *
     * @return  integer
     */
    function timetics_count_users( $args = [] ) {
        $defaults = [
            'role'        => '',
            'date_range'  => '',
            'count_total' => true,
        ];

        $args = wp_parse_args( $args, $defaults );

        // Date range found.
        if ( $args['date_range'] ) {
            $from_date = $args['date_range']['start'];
            $to_date   = $args['date_range']['end'];

            $args['date_query'] = [
                'relation' => 'AND',
                [
                    'before'    => [
                        'year'  => gmdate( 'Y', strtotime( $to_date ) ),
                        'month' => gmdate( 'm', strtotime( $to_date ) ),
                        'day'   => gmdate( 'd', strtotime( $to_date ) ),
                    ],
                    'after'     => [
                        'year'  => gmdate( 'Y', strtotime( $from_date ) ),
                        'month' => gmdate( 'm', strtotime( $from_date ) ),
                        'day'   => gmdate( 'd', strtotime( $from_date ) ),
                    ],
                    'inclusive' => true,
                ],
            ];
        }

        // The Query
        $user_query = new WP_User_Query( $args );

        // Return total users.
        return $user_query->total_users;
    }
}

if ( ! function_exists( 'timetics_get_total_sale' ) ) {
    /**
     * Get total sale with date range or without date range
     *
     * @param   array  $args Sales Required Args
     *
     * @return  integer
     */
    function timetics_get_total_sale( $args = [] ) {
        $defaults = [
            'post_type'   => 'timetics-booking',
            'post_status' => 'approved',
        ];

        $args = wp_parse_args( $args, $defaults );

        $posts = timetics_get_posts( $args );

        $total = 0;

        foreach ( $posts->posts as $post ) {
            $booking = new Booking( $post->ID );
            $total += floatval( $booking->get_total() );
        }

        return $total;
    }
}

if ( ! function_exists( 'timetics_get_staff_integrations' ) ) {
    /**
     * Get all staff integrations
     *
     * @return array
     */
    function timetics_get_staff_integrations( $user_id = 0 ) {
        $google_auth        = timetics_get_google_access_token( $user_id );
        $google_credentials = timetics_get_google_credentials();
        $is_setup           = ! empty( $google_credentials['client_id'] ) && ! empty( $google_credentials['client_secret'] );
        $current_user_id    = ( $user_id == get_current_user_id() ) ? true : false;

        $integrations = [
            [
                'id'           => 'google-calendar-meet',
                'name'         => esc_html__( 'Google Meet', 'timetics' ),
                'description'  => esc_html__( 'Connect your Meet to sync your booked events.', 'timetics' ),
                'auth_url'     => timetics_get_google_auth_url(),
                'connected'    => ! empty( $google_auth ),
                'setup'        => $is_setup,
                'current_user' => $current_user_id,
            ],
            [
                'id'           => 'google-calendar',
                'name'         => esc_html__( 'Google Calendar', 'timetics' ),
                'description'  => esc_html__( 'Connect your Meet to sync your booked events.', 'timetics' ),
                'auth_url'     => timetics_get_google_auth_url(),
                'connected'    => ! empty( $google_auth ),
                'setup'        => $is_setup,
                'current_user' => $current_user_id,
            ],
        ];

        $integrations = apply_filters( 'timetics_staff_integrations', $integrations, $user_id );

        return $integrations;
    }
}

if ( ! function_exists( 'timetics_get_google_credentials' ) ) {
    /**
     * Get google auth credentials
     *
     * @return  array
     */
    function timetics_get_google_credentials() {
        $client_id     = timetics_get_option( 'google_app_client_id' );
        $client_secret = timetics_get_option( 'google_app_client_secret' );

        return [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ];
    }
}

if ( ! function_exists( 'timetics_get_default_timezone' ) ) {
    /**
     * Get default timezone
     *
     * @return  string
     */
    function timetics_get_default_timezone() {
        $timezone = get_option( 'timezone_string' );
        return $timezone;
    }
}

if ( ! function_exists( 'timetics_get_currencies' ) ) {
    /**
     * Get currency list
     *
     * @return  array
     */
    function timetics_get_currencies() {
        $currencies = require_once dirname( __FILE__ ) . '/currency.php';

        return $currencies;
    }
}

if ( ! function_exists( 'timetics_update_default_settings' ) ) {

    /**
     * Update default settings
     *
     * @return void
     */
    function timetics_update_default_settings() {
        $settings = [
            'google_auth_redirect_uri' => timetics_get_auth_redirect_uri( 'google-auth' ),
            'default_booking_status'   => 'pending',
            'currency'                 => 'USD',
            'primary_color'            => '#3161F1',
            'secondary_color'          => '#6188ff',
        ];

        $settings = apply_filters( 'timetics_default_settings', $settings );

        foreach ( $settings as $key => $value ) {
            timetics_update_option( $key, $value );
        }
    }
}

if ( ! function_exists( 'timetics_google_setup' ) ) {

    /**
     * Checking google auth is configured or not
     *
     * @return  bool
     */
    function timetics_google_setup() {
        $client_id     = timetics_get_option( 'google_app_client_id' );
        $client_secret = timetics_get_option( 'google_app_client_secret' );

        if ( $client_id && $client_secret ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'timetics_get_post_status' ) ) {

    /**
     * Get post status
     *
     * @return  array
     */
    function timetics_get_post_status() {
        return [
            'approved',
            'pending',
            'cancel',
            'completed',
        ];
    }
}

if ( ! function_exists( 'timetics_get_payment_methods' ) ) {
    /**
     * Get payment methods
     *
     * @return array
     */
    function timetics_get_payment_methods() {
        $payment_methods = [
            [
                'name'   => esc_html__( 'Stripe', 'timetics' ),
                'status' => timetics_get_option( 'stripe_status' ),
            ],
            [
                'name'   => esc_html__( 'Local Pay', 'timetics' ),
                'status' => timetics_get_option( 'local_pay_status' ),
            ],
            [
                'name'   => esc_html__( 'Woocommerce', 'timetics' ),
                'status' => timetics_is_woocommerce_active(),
            ],
        ];

        return apply_filters( 'timetics_payment_methods', $payment_methods );
    }
}

if ( ! function_exists( 'timetics_replace_placeholder' ) ) {
    /**
     * Replace string with placeholder
     *
     * @param   array  $placeholders
     * @param   string  $text
     *
     * @return  string
     */
    function timetics_replace_placeholder( $text, $placeholders = [] ) {

        if ( ! $placeholders ) {
            return $text;
        }

        foreach ( $placeholders as $placeholder => $replace_with ) {
            $text = str_replace( $placeholder, $replace_with, $text );
        }

        return $text;
    }
}

if ( ! function_exists( 'timetics_register_cron' ) ) {
    /**
     * Register timetics cron
     *
     * @return  void
     */
    function timetics_register_cron() {
        wp_schedule_event( time(), 'daily', 'timetics_booking_clear_schedule' );
    }
}

if ( ! function_exists( 'timetics_is_woocommerce_active' ) ) {

    /**
     * Check woocommerce is active or not
     *
     * @return  bool
     */
    function timetics_is_woocommerce_active() {

        if ( function_exists( 'WC' ) && timetics_get_option( 'wc_integration' ) ) {
            return true;
        }

        return false;
    }
}

if ( ! function_exists( 'timetics_add_woocommerce_product_cat' ) ) {
    /**
     * Add woocommerce product category
     *
     * @return  void
     */
    function timetics_add_woocommerce_product_cat() {
        $category_name = __( 'Timetics Meeting', 'timetics' );

        // Prepare category arguments
        $category_args = array(
            'taxonomy'             => 'product_cat',
            'cat_name'             => $category_name,
            'category_description' => __( 'Timetics meeting', 'timetics' ),
            'slug'                 => 'timetics-meeting',
        );

        // Insert the category
        wp_insert_term( $category_args['cat_name'], $category_args['taxonomy'], $category_args );
    }
}

if ( ! function_exists( 'timetics_is_valid_timezone' ) ) {
    /**
     * Check a timezone is valid or not
     *
     * @param   string  $timezone
     *
     * @return  bool
     */
    function timetics_is_valid_timezone( $timezone ) {
        try {
            new DateTimeZone( $timezone );
        } catch ( Exception $e ) {
            return false;
        }

        return true;
    }
}

if ( ! function_exists( 'timetics_datetime' ) ) {

    /**
     * Timetics date time with timezone
     *
     * @param   int | string  $date
     * @param   string  $timezone
     *
     * @return  string
     */
    function timetics_datetime( $format, $date, $timezone = '' ) {
        $date = is_int( $date ) ? gmdate( 'Y-m-d g:ia', $date ) : $date;

        if ( ! $timezone ) {
            $timezone = 'UTC';
        }

        // Create a DateTime object with the provided timestamp and time zone
        $datetime = new \DateTime( $date, new \DateTimeZone( $timezone ) );

        // Get the converted timestamp
        return $datetime->format( $format );
    }
}

if ( ! function_exists( 'timetics_convert_timezone' ) ) {

    /**
     * Convert date and time fron timezone to another timezone
     *
     * @param   string  $format
     * @param   string  $date_time
     * @param   string  $from
     * @param   string  $to
     *
     * @return  \DateTime
     */
    function timetics_convert_timezone( $date_time_string, $from, $to ) {

        if ( empty( $from ) || empty( $to ) ) {
            return new DateTime( $date_time_string );
        }

        $date_time_string = is_int( $date_time_string ) ? gmdate( 'Y-m-d g:ia', $date_time_string ) : gmdate( 'Y-m-d g:ia', strtotime( $date_time_string ) );

        $datetime = new DateTime( $date_time_string, new DateTimeZone( $from ) );
        $datetime->setTimezone( new DateTimeZone( $to ) );

        return $datetime;
    }
}

if ( ! function_exists( 'timetics_generate_username' ) ) {
    /**
     * Generate unique username
     *
     * @param   string  $email
     *
     * @return  string
     */
    function timetics_generate_username( $email ) {
        $username = strtok( $email, '@' );

        if ( username_exists( $username ) ) {
            $username = $username . wp_rand( 10, 100 );
        }

        return $username;
    }
}

if ( ! function_exists( 'timetics_is_json' ) ) {
    /**
     * Check a string is valid json or not
     *
     * @param   string  $json_string
     *
     * @return  bool
     */
    function timetics_is_json( $json_string ) {
        if ( ! is_string( $json_string ) ) {
            return false;
        }

        $data = json_decode( $json_string, true );

        return $data !== null && json_last_error() === JSON_ERROR_NONE;
    }
}

if ( ! function_exists( 'timetics_is_google_meet_connected' ) ) {

    /**
     * Check google meet is connected or not
     *
     * @param   integer  $user_id
     *
     * @return  bool
     */
    function timetics_is_google_meet_connected( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $google_auth = timetics_get_google_access_token( $user_id );

        return ! empty( $google_auth );
    }
}

if ( ! function_exists( 'timetics_is_zoom_connected' ) ) {

    /**
     * Check google meet is connected or not
     *
     * @param   integer  $user_id
     *
     * @return  bool
     */
    function timetics_is_zoom_connected( $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! function_exists( 'timetics_get_zoom_access_token' ) ) {
            return false;
        }

        $zoom_auth = timetics_get_zoom_access_token( $user_id );

        return ! empty( $zoom_auth );
    }
}

if ( ! function_exists( 'timetics_get_current_user' ) ) {
    /**
     * Get current user data
     *
     * @return  array
     */
    function timetics_get_current_user() {
        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        $user_data = [
            'id'        => $user->ID,
            'username'  => $user->user_login,
            'email'     => $user->user_email,
            'roles'     => $user-> roles,
        ];
        return $user_data;
    }
}