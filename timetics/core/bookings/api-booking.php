<?php
/**
 * Booking api
 *
 * @package Timetics
 */
namespace Timetics\Core\Bookings;

use Error;
use Timetics\Base\Api;
use Timetics\Core\Appointments\Api_Appointment;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Customers\Customer;
use Timetics\Core\Emails\Cancel_Event_Customer_Email;
use Timetics\Core\Emails\Cancel_Event_Email;
use Timetics\Core\Emails\New_Event_Customer_Email;
use Timetics\Core\Emails\New_Event_Email;
use Timetics\Core\Emails\Update_Event_Customer_Email;
use Timetics\Core\Emails\Update_Event_Email;
use Timetics\Core\Integrations\Stripe\StripePayment;
use Timetics\Core\Staffs\Staff;
use Timetics\Utils\Singleton;
use TimeticsPro\Core\SeatPlan\SeatPlan;
use WP_Error;
use WP_HTTP_Response;
use WP_Query;

class Api_Booking extends Api {
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
    protected $rest_base = 'bookings';

    /**
     * Booking Type
     *
     * @var string
     */
    protected $type = '';

    /**
     * Register rest routes
     *
     * @return  void
     */
    public function register_routes() {
        /**
         * Register route
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, $this->rest_base, [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_items'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_item'],
                    'permission_callback' => function () {
                        return true;
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'bulk_delete'],
                    'permission_callback' => function () {
                        return current_user_can( 'edit_booking' );
                    },
                ],
            ]
        );

        /**
         * Register route
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, '/' . $this->rest_base . '/(?P<booking_id>[\d]+)', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_item'],
                    'permission_callback' => [$this, 'get_item_permission_callback'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'update_item'],
                    'permission_callback' => [$this, 'update_item_permission_callback'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_item'],
                    'permission_callback' => function () {
                        return current_user_can( 'edit_booking' );
                    },
                ],
            ]
        );

        register_rest_route(
            $this->namespace, '/' . $this->rest_base . '/(?P<booking_id>[\d]+)/payment', [
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'make_payment'],
                    'permission_callback' => [$this, 'make_payment_permission_callback'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace, '/' . $this->rest_base . '/(?P<booking_id>[\d]+)/payment-intent', [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'bind_payment_intent'],
                    'permission_callback' => [$this, 'make_payment_permission_callback'],
                ],
            ]
        );

        register_rest_route(
            $this->namespace, $this->rest_base . '/search', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'search_items'],
                    'permission_callback' => function () {
                        return current_user_can( 'edit_posts' );
                    },
                ],
            ]
        );

        register_rest_route(
            $this->namespace, $this->rest_base . '/entries', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_entries'],
                    'permission_callback' => function () {
                        return true;
                    },
                ],
            ]
        );

        register_rest_route(
            $this->namespace, $this->rest_base . '/payment_methods', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_payment_methods'],
                    'permission_callback' => function () {
                        return true;
                    },
                ],
            ]
        );
    }

    /**
     * Get all bookings
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function get_items( $request ) {
        $per_page   = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged      = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $meeting_id = ! empty( $request['meeting_id'] ) ? intval( $request['meeting_id'] ) : 0;
        $start_date = ! empty( $request['start_date'] ) ? $request['start_date'] : '';

        $args = [
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'meeting'        => $meeting_id,
        ];

        $args = apply_filters( 'timetics/add/item/data', $args, $request );

        if ( $start_date ) {
            $args['start_date'] = $start_date;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            $allowed_ids      = Booking::get_visible_ids_for_user( get_current_user_id() );
            $args['post__in'] = ! empty( $allowed_ids ) ? $allowed_ids : [ 0 ];
        }

        $bookings = Booking::all( $args );
        $items    = [];

        foreach ( $bookings['items'] as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $items = apply_filters( 'timetics/admin/booking/get_items', $items );

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'data'        => [
                'total' => $bookings['total'],
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Get single booking
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_item( $request ) {
        $booking_id = (int) $request['booking_id'];
        $booking    = new Booking( $booking_id );

        if ( ! $booking->is_booking() ) {
            return [
                'success'     => 0,
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid booking id.', 'timetics' ),
                'data'        => [],
            ];
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/booking/get_item', $this->prepare_item( $booking ) );

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'data'        => $this->prepare_item( $booking ),
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Create booking
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function create_item( $request ) {

        $bookings_count = Booking::all();

        $response = [
            'success'     => 0,
            'status_code' => 502,
            'message'     => esc_html__( 'Something went wrong', 'timetics' ),
            'data'        => [],
        ];

        if ( apply_filters( 'timetics/staff/booking/count_check', false, $bookings_count ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/booking/error_data', $response, 'count_check' ), 403 );
        }

        $data = json_decode( $request->get_body(), true );

        if ( apply_filters( 'timetics/booking/appointment/type_check', false, $request ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/booking/error_data', $response, 'type_check' ), 403 );
        }

        $recurring_booking = ! empty( $data['recurring_dates'] ) ? $data['recurring_dates'] : [];

        if ( $recurring_booking && apply_filters( 'timetics/booking/appointment/recurring_check', false, $recurring_booking ) == true ) {
            $response = [
                'status_code' => 403,
                'success'     => 0,
                'message'     => esc_html__( 'Recurring booking limit exit', 'timetics' ),
            ];

            return new WP_HTTP_Response( $response, 403 );
        } // End.

        return $this->save_bookings( $request );
    }

    /**
     * Update booking
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function update_item( $request ) {

        $booking_id = (int) $request['booking_id'];
        $booking    = new Booking( $booking_id );

        if ( ! $booking->is_booking() ) {
            return [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid booking id.', 'timetics' ),
                'data'        => [],
            ];
        }

        if ( apply_filters( 'timetics/booking/appointment/custom_form_data', false, $request ) == true ) {
            $response = [
                'status_code' => 409,
                'success'     => 0,
                'message'     => esc_html__( 'Custom Field Booking Restricted ', 'timetics' ),
            ];

            return new WP_HTTP_Response( $response, 403 );
        }

        return $this->save_bookings( $request, $booking_id );
    }

    /**
     * Delete booking
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function delete_item( $request ) {

        $booking_id = (int) $request['booking_id'];

        $delete = $this->delete( $booking_id );

        if ( ! $delete ) {
            $data = [
                'success'     => 1,
                'status_code' => 409,
                'message'     => esc_html__( 'Something went wrong, Please try again.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 409 );
        }

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => esc_html__( 'Successfully deleted booking', 'timetics' ),
            'data'        => [],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Delete multiples
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function bulk_delete( $request ) {

        $bookings = json_decode( $request->get_body(), true );

        foreach ( $bookings as $booking ) {
            $delete = $this->delete( $booking );

            if ( ! $delete ) {
                return [
                    'success'     => 0,
                    'status_code' => 404,
                    'message'     => esc_html__( 'Invalid booking id.', 'timetics' ),
                    'data'        => [],
                ];
            }
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/booking/bulk_delete', $bookings );

        return [
            'success'     => 1,
            'status_code' => 200,
            'message'     => esc_html__( 'Successfully deleted booking', 'timetics' ),
        ];
    }

    /**
     * Get payment methods
     *
     * @return array
     */
    public function get_payment_methods() {

        $payment_methods = timetics_get_payment_methods();

        return [
            'success'     => 1,
            'status_code' => 200,
            'data'        => $payment_methods,
        ];
    }

    /**
     * Search bookings
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function search_items( $request ) {

        // Prepare search args.
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $search   = ! empty( $request['search'] ) ? sanitize_text_field( $request['search'] ) : '';

        // Get search.
        $booking = new WP_Query(
            array(
                'post_type'      => 'timetics-booking',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'post_status'    => 'any',

                // @codingStandardsIgnoreStart
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_tt_booking_customer_fname',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_customer_lname',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_customer_email',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_customer_phone',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_staff_fname',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_staff_lname',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_staff_email',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_meeting_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_meeting_description',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_booking_meeting_type',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                ),
                // @codingStandardsIgnoreEnd
            )
        );

        // Prepare items for response.
        $items = [];

        foreach ( $booking->posts as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $items = apply_filters( 'timetics/admin/booking/search_items', $items );

        $data = [
            'success' => 1,
            'status'  => 200,
            'data'    => [
                'total' => $booking->found_posts,
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Get all booking entries
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function get_entries( $request ) {
        $staff_id   = ! empty( $request['staff_id'] ) ? intval( $request['staff_id'] ) : 0;
        $meeting_id = ! empty( $request['meeting_id'] ) ? intval( $request['meeting_id'] ) : 0;
        $start_date = ! empty( $request['start_date'] ) ? sanitize_text_field( $request['start_date'] ) : 0;
        $timezone   = ! empty( $request['timezone'] ) ? sanitize_text_field( $request['timezone'] ) : 0;
        $end_date   = ! empty( $request['end_date'] ) ? sanitize_text_field( $request['end_date'] ) : 0;

        $meeting = new Appointment( $meeting_id );

        // Validate timezone.
        if ( ! timetics_is_valid_timezone( $timezone ) ) {
            return new WP_Error( 'timezone_error', __( 'Your booking timezone is invalid', 'timetics' ) );
        }

        // Validate meeting timezone.
        if ( ! timetics_is_valid_timezone( $meeting->get_timezone() ) ) {
            return new WP_Error( 'timezone_error', __( 'Your meeting timezone is invalid. Please update your meeting timezone with proper timezone.', 'timetics' ) );
        }

        $days = $meeting->prepare_schedule( $start_date, $end_date, $staff_id, $timezone );
        $days = apply_filters( 'timetics_schedule_data_for_selected_date', $days, $staff_id, $meeting_id,  $timezone );

        $data = [
            'today'                 => gmdate( 'Y-m-d' ),
            'availability_timezone' => $meeting->get_timezone(),
            'days'                  => $days,
        ];

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $data = apply_filters( 'timetics/admin/booking/get_entries', $data );

        return [
            'success'     => true,
            'status_code' => 200,
            'message'     => esc_html__( 'Get all entries', 'timetics' ),
            'data'        => $data,
        ];
    }

    /**
     * Make payment transaction for the current booking
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function make_payment( $request ) {
        $booking_id             = intval( $request['booking_id'] );
        $booking                = new Booking( $booking_id );
        $data                   = json_decode( $request->get_body(), true );
        $data                   = is_array( $data ) ? $data : [];
        $client_status          = ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';
        $payment_method         = ! empty( $data['payment_method'] ) ? sanitize_text_field( $data['payment_method'] ) : '';
        $default_booking_status = timetics_get_option( 'default_booking_status', 'approved' );
        $type                   = $booking->get_type();

        if ( ! $booking->is_booking() ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 404,
                    'message'     => esc_html__( 'Invalid booking id.', 'timetics' ),
                ],
                404
            );
        }

        // Idempotency: refuse re-approval of a booking that already finalized.
        $current_status     = (string) $booking->get_status();
        $finalized_statuses = [ 'approved', 'completed', 'failed', 'cancelled', 'cancel' ];
        if ( in_array( $current_status, $finalized_statuses, true ) ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 409,
                    'message'     => esc_html__( 'Booking has already been finalized.', 'timetics' ),
                ],
                409
            );
        }

        $verified_status   = 'pending';
        $payment_details   = '';
        $stored_intent_id  = '';

        if ( 'stripe' === $payment_method ) {
            $client_details = ! empty( $data['payment_details'] ) ? $data['payment_details'] : [];
            $intent_id      = is_array( $client_details ) && ! empty( $client_details['id'] )
                ? sanitize_text_field( (string) $client_details['id'] )
                : '';

            if ( '' === $intent_id || strpos( $intent_id, 'pi_' ) !== 0 ) {
                if ( 'failed' === $client_status ) {
                    $verified_status = 'failed';
                } else {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 0,
                            'status_code' => 400,
                            'message'     => esc_html__( 'Missing payment intent.', 'timetics' ),
                        ],
                        400
                    );
                }
            } else {
                $intent = ( new StripePayment() )->retrieve_payment_intent( $intent_id );

                if ( is_wp_error( $intent ) || ! is_array( $intent ) || empty( $intent['id'] ) ) {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 0,
                            'status_code' => 502,
                            'message'     => esc_html__( 'Cannot verify payment with Stripe.', 'timetics' ),
                        ],
                        502
                    );
                }

                $expected_amount   = (int) round( (float) $booking->get_total() * 100 );
                $expected_currency = strtolower( (string) apply_filters( 'timetics_currency', timetics_get_option( 'currency', 'USD' ) ) );
                $intent_status     = isset( $intent['status'] ) ? (string) $intent['status'] : '';
                $intent_amount     = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
                $intent_currency   = isset( $intent['currency'] ) ? strtolower( (string) $intent['currency'] ) : '';
                $meta_booking_id   = isset( $intent['metadata']['booking_id'] ) ? (int) $intent['metadata']['booking_id'] : 0;
                $meta_token        = isset( $intent['metadata']['security_token'] ) ? (string) $intent['metadata']['security_token'] : '';
                $stored_token      = (string) $booking->get_security_token();

                $mismatch = (
                    'succeeded'        !== $intent_status                                        ||
                    $expected_amount   !== $intent_amount                                        ||
                    $expected_currency !== $intent_currency                                      ||
                    $booking_id        !== $meta_booking_id                                      ||
                    '' === $stored_token                                                          ||
                    '' === $meta_token                                                            ||
                    ! hash_equals( $stored_token, $meta_token )
                );

                if ( $mismatch ) {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 0,
                            'status_code' => 402,
                            'message'     => esc_html__( 'Payment verification failed.', 'timetics' ),
                        ],
                        402
                    );
                }

                // Replay protection: this booking can be bound to exactly one
                // PaymentIntent. A second call with a different intent fails.
                $bound = $booking->get_stripe_payment_intent_id();
                if ( '' !== $bound && $bound !== $intent['id'] ) {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 0,
                            'status_code' => 409,
                            'message'     => esc_html__( 'Payment intent does not match this booking.', 'timetics' ),
                        ],
                        409
                    );
                }

                $stored_intent_id = $intent['id'];
                $verified_status  = 'succeeded';
                $payment_details  = $intent;
            }
        } elseif ( 'failed' === $client_status ) {
            // Marking the user's own attempt as failed never grants access; safe to honor.
            $verified_status = 'failed';
        }
        // Other payment methods (cash, on-site, etc.) stay pending here. They
        // are approved through their own authenticated/admin paths.
        $post_status = 'succeeded' === $verified_status
            ? $default_booking_status
            : ( 'failed' === $verified_status ? 'failed' : 'pending' );

        $finalizing = 'succeeded' === $verified_status && '' !== $stored_intent_id;

        if ( $finalizing ) {
            $claimed = add_post_meta( $booking_id, '_tt_stripe_payment_intent_id', $stored_intent_id, true );
            if ( false === $claimed ) {
                $existing = (string) get_post_meta( $booking_id, '_tt_stripe_payment_intent_id', true );
                if ( $existing !== $stored_intent_id ) {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 0,
                            'status_code' => 409,
                            'message'     => esc_html__( 'Payment intent does not match this booking.', 'timetics' ),
                        ],
                        409
                    );
                }

                if ( 'pending' !== (string) $booking->get_status() ) {
                    return new WP_HTTP_Response(
                        [
                            'success'     => 1,
                            'status_code' => 200,
                            'message'     => esc_html__( 'Payment already finalized.', 'timetics' ),
                        ],
                        200
                    );
                }
            }
        }

        $update = $booking->update(
            [
                'post_status'     => $post_status,
                'payment_status'  => $verified_status,
                'payment_details' => $payment_details,
                'payment_method'  => $payment_method,
            ]
        );

        if ( is_wp_error( $update ) ) {
            // Roll back the claim so a retry can finalize cleanly.
            if ( $finalizing ) {
                delete_post_meta( $booking_id, '_tt_stripe_payment_intent_id', $stored_intent_id );
            }
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 409,
                    /* translators: Action */
                    'message'     => $update->get_error_message(),
                ],
                409
            );
        }

        if ( $default_booking_status === $post_status ) {
            // Rotate the security token so the same one cannot drive a second
            // approval after this booking has finalized.
            $booking->rotate_security_token();

            $booking->create_event();

            if( 'timetics-event' == $type ){
                return;
            }

            $is_email_to_customer = timetics_get_option( 'booking_created_customer');
            $is_email_to_host     = timetics_get_option( 'booking_created_host');

            if ( $is_email_to_host ) {
                $new_event_email = new New_Event_Email( $booking );
                $new_event_email->send();
            }

            if ( $is_email_to_customer ) {
                $new_event_customer_email = new New_Event_Customer_Email( $booking );
                $new_event_customer_email->send();
            }

            do_action( 'timetics_booking_payment', $booking );

        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/booking/make_payment', $post_status );

        $data = [
            'success'     => 1,
            'status_code' => 200,
            /* translators: Action */
            'message'     => sprintf( esc_html__( 'Payment %s', 'timetics' ), $post_status ),
        ];

        return new WP_HTTP_Response( $data, 200 );
    }

    /**
     * Save booking
     *
     * @param   WP_Rest_Request  $request
     * @param   integer  $id       Booking id
     *
     * @return  JSON
     */
    public function save_bookings( $request, $id = 0 ) {
        $data = json_decode( $request->get_body(), true );

        if( isset( $data['type'] ) &&  'timetics-event' == $data['type'] ) {
            $this->type = $data['type'];
           return apply_filters('timetics_booking_event', $data, $id );
        }else {
            return  $this->booking_appointment($data, $id);
        }
    }

     /**
     * Booking Appointment
     *
     * @param   array  $data    All the data of booking
     * @param   integer $id     Booking id
     *
     * @return JSON
     */
    protected function booking_appointment ($data, $id) {
        $first_name      = ! empty( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
        $last_name       = ! empty( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
        $email           = ! empty( $data['email'] ) ? sanitize_text_field( $data['email'] ) : '';
        $phone           = ! empty( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';

        // Fallback: when built-in phone field absent (e.g., non attendee-call location),
        // pick phone from custom form field so customer record still gets it.
        if ( empty( $phone ) && ! empty( $data['custom_form_data'] ) ) {
            $custom_form = is_array( $data['custom_form_data'] ) ? $data['custom_form_data'] : (array) json_decode( wp_json_encode( $data['custom_form_data'] ), true );
            foreach ( [ 'phone', 'Phone', 'phone_number', 'mobile', 'contact_number' ] as $key ) {
                if ( ! empty( $custom_form[ $key ] ) ) {
                    $phone = sanitize_text_field( $custom_form[ $key ] );
                    break;
                }
            }
        }
        $city            = ! empty( $data['city'] ) ? sanitize_text_field( $data['city'] ) : '';
        $state           = ! empty( $data['state'] ) ? sanitize_text_field( $data['state'] ) : '';
        $post_code       = ! empty( $data['post_code'] ) ? sanitize_text_field( $data['post_code'] ) : '';
        $country         = ! empty( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '';
        $payment_method  = ! empty( $data['payment_method'] ) ? sanitize_text_field( $data['payment_method'] ) : '';
        $address_1       = ! empty( $data['address_1'] ) ? sanitize_text_field( $data['address_1'] ) : '';
        $address_2       = ! empty( $data['address_2'] ) ? sanitize_text_field( $data['address_2'] ) : '';
        $appointment     = ! empty( $data['appointment'] ) ? intval( $data['appointment'] ) : 0;
        $staff_id        = ! empty( $data['staff'] ) ? intval( $data['staff'] ) : 0;
        $start_date      = ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : '';
        $date            = ! empty( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '';
        $end_date        = ! empty( $data['end_date'] ) ? sanitize_text_field( $data['end_date'] ) : $start_date;
        $start_time      = ! empty( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : '';
        $end_time        = ! empty( $data['end_time'] ) ? sanitize_text_field( $data['end_time'] ) : '';
        $client_status   = ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';
        $location        = ! empty( $data['location'] ) ? sanitize_text_field( $data['location'] ) : '';
        $location_type   = ! empty( $data['location_type'] ) ? sanitize_text_field( $data['location_type'] ) : '';
        $description     = ! empty( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '';
        $timezone        = ! empty( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : '';
        $recurring_dates = ! empty( $data['recurring_dates'] ) ? $data['recurring_dates'] : [];
        $seats           = ! empty( $data['seats'] ) ? $data['seats'] : [];
        $cancel_reason   = ! empty( $data['cancel_reason'] ) ? $data['cancel_reason'] : [];
        $booking_time    = ! empty( $data['booking_createAt'] ) ? $data['booking_createAt'] : '';
        $action          = $id ? 'updated' : 'created';

        $is_privileged    = current_user_can( 'manage_timetics' ) || current_user_can( 'edit_booking' );
        $server_total     = (int) $this->calculate_order_total( $data );
        $default_status   = timetics_get_option( 'default_booking_status', 'approved' );
        $payment_method_l = strtolower( $payment_method );

        if ( $is_privileged ) {
            $status = '' !== $client_status ? $client_status : $default_status;
        } elseif ( 'created' === $action ) {
            if ( $server_total > 0 && 'stripe' === $payment_method_l ) {
                $status = 'pending';
            } elseif ( $server_total > 0 && 'woocommerce' === $payment_method_l ) {
                $status = 'failed';
            } else {
                $status = $default_status;
            }
        } else {
            $current_status = ( new Booking( $id ) )->get_status();
            if ( 'cancel' === $client_status ) {
                $status = 'cancel';
            } else {
                $status = $current_status;
            }
        }
        $appointment_token = ! empty( $data['appointment_token'] ) ? sanitize_text_field( $data['appointment_token'] ) : '';

        if ( $id ) {
            $email_validation = $this->validate_email_change_permission( $id, $email );

            if ( is_wp_error( $email_validation ) ) {
                $error_code = $email_validation->get_error_code();
                $error_response = [
                    'success'     => 0,
                    'status_code' => $error_code,
                    'message'     => $email_validation->get_error_message(),
                ];
                return new WP_HTTP_Response( $error_response, $error_code );
            }

            // Use the validated email from the security check
            $email = $email_validation;
        }

        $validate = $this->validate(
            $data, [
                'first_name',
                'email',
                'payment_method',
                'appointment',
                'start_date',
                'start_time',
                'end_time',
            ]
        );

        if ( is_wp_error( $validate ) ) {
            $data = [
                'status_code' => 403,
                'success'     => 0,
                'message'     => $validate->get_error_messages(),
            ];
            return new WP_HTTP_Response( $data, 403 );
        }

        $customer           = new Customer();
        $meeting            = new Appointment( $appointment );
        $staff              = new Staff( $staff_id );
        $booking            = new Booking( $id );
        $booking_entry      = new Booking_Entry();

        // Validate booking

         $validation =  $this->validate_booking( $appointment, $data );
         if(is_wp_error($validation)){
            return $validation;
         }



        if ( 'created' === $action && ! $this->is_available_slot( $meeting, [
            'staff_id'   => $staff->get_id(),
            'start_date' => $start_date,
            'start_time' => $start_time,
            'timezone'   => $timezone,
        ] ) ) {
            /* translators: %s: Time slot */
            return new WP_Error( 'time_slot_error', sprintf( __( '%s time slot is not available', 'timetics' ), $start_time ) );
        }

        if ( $meeting->is_recurring() ) {
            $valid_recurrence = apply_filters( 'timetics_validate_recurring_booking', $recurring_dates, $start_time, $staff->get_id(), $meeting->get_id() );

            if ( ! $valid_recurrence ) {
                $recurring_error = [
                    'status_code' => 403,
                    'success'     => 0,
                    'message'     => __( 'Couldn\'t possible to book. Plese try another time.', 'timetics' ),
                ];

                return new WP_HTTP_Response( $recurring_error, 403 );
            }
        }

        $customer->make(
            [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
            ]
        );

        // Update booking schedule.
        if ( $id ) {

            $entries = $booking_entry->find(
                [
                    'staff_id'   => $booking->get_staff_id(),
                    'meeting_id' => $booking->get_appointment(),
                    'date'       => $booking->get_start_date(),
                    'start'      => $booking->get_start_time(),
                ]

            );

            if ( $entries ) {
                $entry = $booking_entry->first();

                if ( 'one-to-one' == strtolower( $meeting->get_type() ) ) {
                    $entry->delete();
                } else {
                    $booked      = intval( $entry->get_booked() ) - 1;
                    $booked_data = apply_filters( 'timetics_booking_update_schedule', $entry, ['booked' => $booked], $data, $booking );
                    $entry->update( $booked_data );
                }
            }
        }

        if ( $id && $booking->get_status() == 'cancel' && $status == 'cancel' ) {
            return new WP_Error( 'booking_cancel_error', __( 'This booking alreay canceled', 'timetics' ) );
        }

        $booking_props = [
            'customer'            => $customer->get_id(),
            'appointment'         => $meeting->get_id(),
            'appointment_name'    => $meeting->get_name(),
            'staff'               => $staff->get_id(),
            'customer_fname'      => $customer->get_first_name(),
            'customer_lname'      => $customer->get_last_name(),
            'customer_email'      => $customer->get_email(),
            'customer_phone'      => $customer->get_phone(),
            'staff_fname'         => $staff->get_first_name(),
            'staff_lname'         => $staff->get_last_name(),
            'staff_email'         => $staff->get_email(),
            'meeting_name'        => $meeting->get_name(),
            'meeting_description' => $meeting->get_description(),
            'meeting_type'        => $meeting->get_type(),
            'booking_time'        => $booking_time,
            'description'         => $description,
            'start_date'          => $start_date,
            'date'                => $date,
            'end_date'            => $end_date,
            'start_time'          => $start_time,
            'end_time'            => $end_time,
            'order_total'         => $this->calculate_order_total( $data ),
            'post_status'         => $status,
            'location'            => $location,
            'location_type'       => $location_type,
            'timezone'            => $timezone,
            'cancel_reason'       => $cancel_reason,
        ];

        if ( $id ) {
            $old_start_date = $booking->get_start_date();
            $old_start_time = $booking->get_start_time();
            $old_end_time   = $booking->get_end_time();
        }

        if( 'created' == $action ){
            $booking_props['security_token'] = $booking->generate_security_token();
        }

        $booking->set_props( $booking_props );


        $booking = apply_filters( 'timetics/bookings/booking/set', $booking );

        $booking->save();

        // Fire when booking is completed.
        do_action( 'timetics_after_booking_create', $booking->get_id(), $customer->get_id(), $meeting->get_id(), $data );

        // Create or update calendar event.
        if ( $id ) {
            if ( 'cancel' === $status ) {
                $booking->delete_event();
                $is_email_to_customer = timetics_get_option( 'booking_canceled_customer');
                $is_email_to_host     = timetics_get_option( 'booking_canceled_host');

                if ( $is_email_to_host ) {
                    $cancel_event_email = new Cancel_Event_Email( $booking );
                    $cancel_event_email->send();
                }

                if ( $is_email_to_customer ) {
                    $customer_cancel_event_email = new Cancel_Event_Customer_Email( $booking );
                    $customer_cancel_event_email->send();
                }

                /**
                 * Added temporary for leagacy sass. It will remove in future.
                 */
                do_action( 'timetics/admin/booking/after_delete_item', $booking );
            } else {
                // Check if the booking date/time was actually changed
                $date_time_changed = (
                    $old_start_date !== $start_date ||
                    $old_start_time !== $start_time ||
                    $old_end_time   !== $end_time
                );

                $booking->update_event();

                if ( $date_time_changed ) {
                    $is_email_to_reschedule_customer = timetics_get_option( 'booking_rescheduled_customer');
                    $is_email_to_reschedule_host     = timetics_get_option( 'booking_rescheduled_host');

                    if ( $is_email_to_reschedule_host ) {
                        $update_event_email = new Update_Event_Email( $booking );
                        $update_event_email->send();
                    }

                    if ( $is_email_to_reschedule_customer ) {
                        $update_event_customer_email = new Update_Event_Customer_Email( $booking );
                        $update_event_customer_email->send();
                    }
                }
            }
        }

        // Convert booking time to staff/meeting time.
        $date_time = timetics_convert_timezone( $start_date . ' ' . $start_time, $timezone, $meeting->get_timezone() );
        $end_time  = timetics_convert_timezone( $start_date . ' ' . $end_time, $timezone, $meeting->get_timezone() );

        // Create booking schedule.
        $entries = $booking_entry->find(
            [
                'staff_id'   => $staff->get_id(),
                'meeting_id' => $meeting->get_id(),
                'date'       => $date_time->format( 'Y-m-d' ),
                'start'      => $date_time->format( 'h:i a' ),
            ]
        );

        if ( $entries ) {
            $entry = $booking_entry->first();

            if ( 'cancel' === $status ) {
                $booked = intval( $entry->get_booked() ) - 1;
            } else {
                $booked = intval( $entry->get_booked() ) + 1;
            }

            $booked_data = apply_filters( 'timetics_booking_update_schedule', $entry, ['booked' => $booked], $data, $booking );

            if ( 'cancel' === $status && 'one-to-one' == strtolower( $meeting->get_type() ) ) {
                $entry->delete();
            } else {
                $entry->update( $booked_data );
            }

        } else {
            $book_entry_data = [
                'meeting_id'  => $meeting->get_id(),
                'staff_id'    => $staff->get_id(),
                'customer_id' => $customer->get_id(),
                'booking_id'  => $booking->get_id(),
                'booked'      => 1,
                'date'        => $date_time->format( 'Y-m-d' ),
                'start'       => $date_time->format( 'h:i a' ),
                'end'         => $end_time->format( 'h:i a' ),
            ];

            $book_entry_data = apply_filters( 'timetics_booking_schedule', $book_entry_data, $data );
            $booking_entry->create( $book_entry_data );
        }

        // Fire after booking schedule create.
        do_action( 'timetics_after_booking_schedule', $booking->get_id(), $customer->get_id(), $meeting->get_id(), $data );

        $data = [
            'success'     => 1,
            'status_code' => 200,
            /* translators: Action */
            'message'     => sprintf( esc_html__( 'Successfully %s booking', 'timetics' ), $action ),
            'data'        => $this->prepare_item( $booking ),
        ];

        return new WP_HTTP_Response( $data, 200 );
    }

    /**
     * Prepare item for response
     *
     * @param   integer  $booking_id
     *
     * @return array
     */
    public function prepare_item( $booking_id ) {
        $booking          = new Booking( $booking_id );
        $appointment      = new Appointment( $booking->get_appointment() );
        $staff            = new Staff( $booking->get_staff_id() );
        $customer         = new Customer( $booking->get_customer_id() );
        $meeting_timezone = $appointment->get_timezone();
        $booking_timezone = $booking->get_timezone();

        $start_date_time = timetics_convert_timezone( $booking->get_start_date() . ' ' . $booking->get_start_time(), $booking_timezone, $meeting_timezone );
        $end_date_time   = timetics_convert_timezone( $booking->get_end_date() . ' ' . $booking->get_end_time(), $booking_timezone, $meeting_timezone );
        $date            = timetics_datetime( 'Y-m-d', $booking->get_date(), $meeting_timezone );

        $event     = $booking->get_event();
        $join_link = 'google-meet' === $booking->get_location_type() && ! empty( $event['hangoutLink'] ) ? $event['hangoutLink'] : '';

        $booking_title = $appointment->is_appointment() ? $appointment->get_name() : $booking->get_appointment_name();

        $payment_details_raw = $booking->get_payment_details();
        $payment_details     = is_array( $payment_details_raw ) ? $payment_details_raw : [];

        $response = [
            'id'              => $booking->get_id(),
            'random_id'       => $booking->get_random_id(),
            'status'          => $booking->get_status(),
            'order_total'     => $booking->get_total(),
            'start_date'      => $start_date_time->format( 'Y-m-d' ),
            'end_date'        => $end_date_time->format( 'Y-m-d' ),
            'date'            => $date,
            'start_time'      => $start_date_time->format( 'h:i a' ),
            'end_time'        => $end_date_time->format( 'h:i a' ),
            'booking_time'    => $booking->get_booking_time(),
            'location'        => $booking->get_location(),
            'location_type'   => $booking->get_location_type(),
            'description'     => $booking->get_description(),
            'cancel_reason'   => $booking->get_cancel_reason(),
            'security_token'  => $booking->get_security_token(),
            'payment_method'  => $booking->get_payment_method(),
            'payment_status'  => $booking->get_payment_status(),
            'payment_details' => $payment_details,
            'customer'      => [
                'id'         => $customer->get_id(),
                'full_name'  => $customer->get_display_name(),
                'first_name' => $customer->get_first_name(),
                'last_name'  => $customer->get_last_name(),
                'email'      => $customer->get_email(),
                'phone'      => $customer->get_phone(),
            ],
            'appointment'   => [
                'id'        => $appointment->get_id(),
                'name'      => $booking_title,
                'duration'  => $appointment->get_duration(),
                'type'      => $appointment->get_type(),
                'price'     => $appointment->get_price(),
                'locations' => $appointment->get_locations(),
                'timezone'  => $appointment->get_timezone(),
                'permalink' => $appointment->get_appointment_permalink(),
            ],
            'staff'         => [
                'id'         => $staff->get_id(),
                'full_name'  => $staff->get_display_name(),
                'first_name' => $staff->get_first_name(),
                'last_name'  => $staff->get_last_name(),
                'email_name' => $staff->get_email(),
                'phone'      => $staff->get_phone(),
                'image'      => $staff->get_image(),
            ],
        ];

        if ( $join_link ) {
            $response['meeting_link'] = $join_link;
        }

        return apply_filters( 'timetics_booking_json_data', $response, $booking );
    }

    /**
     * Delete booking
     *
     * @param   integer  $booking_id
     *
     * @return  bool
     */
    private function delete( $booking_id ) {
        $booking = new Booking( $booking_id );
        $meeting = new Appointment( $booking->get_appointment() );

        if ( ! $booking->is_booking() ) {
            return false;
        }

        $current_user_id = get_current_user_id();

        if (
            $meeting->is_appointment()
            && ! user_can( $current_user_id, 'manage_options' )
            && $meeting->get_author() != $current_user_id
        ) {
            $data = [
                'success' => 0,
                'message' => __( 'You are not allowed to delete this booking.', 'timetics' ),
            ];

            return new WP_HTTP_Response( $data, 403 );
        }

        $booking_entry = new Booking_Entry();

        $date_time = timetics_convert_timezone( $booking->get_start_date() . ' ' . $booking->get_start_time(), $booking->get_timezone(), $meeting->get_timezone() );

        $entries = $booking_entry->find(
            [
                'staff_id'   => $booking->get_staff_id(),
                'meeting_id' => $booking->get_appointment(),
                'date'       => $date_time->format( 'Y-m-d' ),
                'start'      => $date_time->format( 'h:i a' ),
            ]
        );

        if ( $entries ) {
            $entry = $booking_entry->first();

            if ( 'one-to-one' == strtolower( $meeting->get_type() ) ) {
                $entry->delete();
            } else {
                $booked        = intval( $entry->get_booked() ) - 1;
                $booked_seat   = ! empty( $booking->get_seat() ) ? $booking->get_seat() : [];
                $existing_seat = ! empty( $entry->get_seats() ) ? $entry->get_seats() : [];

                $entry->update( [
                    'booked' => $booked,
                    'seats'  => array_values( array_diff( $existing_seat, $booked_seat ) ),
                ] );
            }
        }

        $recurrences = $booking->get_recurrence();
        $booking->delete_event();
        $booking->delete();

        $is_email_to_customer = timetics_get_option( 'booking_canceled_customer');
        $is_email_to_host     = timetics_get_option( 'booking_canceled_host');

        if ( $is_email_to_host ) {
            $cancel_event_email = new Cancel_Event_Email( $booking );
            $cancel_event_email->send();
        }

        if ( $is_email_to_customer ) {

            $customer_cancel_event_email = new Cancel_Event_Customer_Email( $booking );
            $customer_cancel_event_email->send();
        }



        do_action( 'timetics_after_booking_delete', $recurrences );

        return true;
    }

    public function is_available_slot( $meeting, $booking_data = [] ) {
        $start_date       = $booking_data['start_date'];
        $start_time       = $booking_data['start_time'];
        $booking_timezone = $booking_data['timezone'];
        $booking_entry    = new Booking_Entry();
        $meeting_id       = $meeting->get_id();
        $staff_id         = $booking_data['staff_id'];

        $time            = is_string( $start_time ) ? strtotime( $start_time ) : $start_time;
        $time            = gmdate( 'H:i', $time );
        $booking_entries = new Booking_Entry();
        $meeting         = new Appointment( $meeting_id );

        $entries = $booking_entries->find( [
            'meeting_id' => $meeting_id,
            'staff_id'   => $staff_id,
            'date'       => $start_date,
        ] );

        $booked = false;

        foreach ( $entries as $entry ) {
            $booking      = new Booking( $entry->get_booking_id() );
            $booking_time = timetics_convert_timezone( $booking->get_start_date() . ' ' . $entry->get_start(), $booking->get_timezone(), $booking_timezone )->format( 'H:i' );

            if ( $booking_time == $time ) {
                $booked = $entry;
                break;
            }
        }

        if ( $booked && $booked->get_booked() >= $meeting->get_capacity() ) {
            return false;
        }

        return true;
    }

    /**
     * Validates a booking.
     *
     * @param int $appointment_id The ID of the appointment.
     * @param array $data The data for the booking.
     * @throws None
     * @return mixed Returns an error response if the validation fails, otherwise returns nothing.
     */
    public function validate_booking($appointment_id, $data) {
        $meeting = new Appointment($appointment_id);
        $all_seats = (array) $meeting->get_seats();
        $meeting_price = $meeting->get_price();
        $meeting_locations = (array) $meeting->get_locations();
        $total_price = 0;

        $staff_id        = ! empty( $data['staff'] ) ? intval( $data['staff'] ) : 0;
        $order_total     = ! empty( $data['order_total'] ) ? floatval( $data['order_total'] ) : 0;
        $location_type   = ! empty( $data['location_type'] ) ? sanitize_text_field( $data['location_type'] ) : '';
        $start_date     = ! empty( $data['start_date'] ) ? sanitize_text_field( $data['start_date'] ) : '';
        $timezone       = ! empty( $data['timezone'] ) ? sanitize_text_field( $data['timezone'] ) : '';
        $start_time       = ! empty( $data['start_time'] ) ? sanitize_text_field( $data['start_time'] ) : '';
        $status       = ! empty( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';
        $seats           = ! empty( $data['seats'] ) ? $data['seats'] : [];
        $timeslots       = $meeting->get_avilable_timeslots( $start_date, $staff_id, $timezone );
        $meeting_has_buffer_time = $meeting->get_buffer_time_after_in_seconds() > 0 || $meeting->get_buffer_time_before_in_seconds() > 0;

        if ( ! $meeting->is_appointment() ) {
            return $this->create_error_response( __( 'Invalid meeting.', 'timetics' ), 422 );
        }

        if ( 'cancel' !== $status ) {
            if ( ! $meeting_has_buffer_time && ! in_array( gmdate( 'g:ia', strtotime( $start_time ) ), $timeslots ) ) {
                return $this->create_error_response( __( 'Invalid timeslot.', 'timetics' ), 422 );
            }

            // Check if the staff is matched
            if ( ! in_array( $staff_id, $meeting->get_staff_ids() ) ) {
                return $this->create_error_response(__('Team member not matched', 'timetics'), 403);

            }
            // Check if the location type is matched
            if ( ! in_array( $location_type, array_column( $meeting_locations, 'location_type' ) ) ) {
                return  $this->create_error_response(__('Location type not matched', 'timetics'), 403);
            }
        }
    }

    /**
     * Creates an error response with the given message and status code.
     *
     * @param string $message The error message.
     * @param int $status_code The HTTP status code.
     * @return WP_HTTP_Response The error response.
     */
    public function create_error_response($message, $status_code) {
        return new WP_Error( 'timezone_error', $message, ['status' => $status_code] );
    }

    /**
     * Calculate order total
     *
     * @param   array  $data  Request data
     *
     * @return  integer
     */
    private function calculate_order_total($data) {
        $seats          = ! empty( $data['seats'] ) ? $data['seats'] : [];
        $meeting_id =   ! empty( $data['appointment'] ) ? $data['appointment'] : 0;
        $total_price = 0;

        if ( class_exists( SeatPlan::class ) && $seats ) {
            foreach( $seats as $seat ) {
                $seat_object = SeatPlan::find( $seat );
                $total_price += $seat_object->price;
            }

            return $total_price;
        }

        $meeting = new Appointment( $meeting_id );

        $prices = $meeting->get_price();

        if ( $prices && is_array( $prices ) ) {
            return $prices[0]['ticket_price'];
        }

        return 0;
    }

    /**
     * Update item permission callback
     * @param WP_REST_Request $request
     * @return bool
     */
    public function update_item_permission_callback($request){
        $nonce = $request->get_header('X-WP-Nonce');

        $booking_id = (int) $request->get_param('booking_id');
        $appointment_token = $request->get_param('appointment_token');

        $booking = new Booking($booking_id);

        if (!$booking->is_booking()) {
            return false;
        }

        // Guests: must provide a valid token (constant-time compare).
        if ( ! empty( $appointment_token ) ) {
            $stored_token = (string) $booking->get_security_token();
            if ( '' !== $stored_token && hash_equals( $stored_token, (string) $appointment_token ) ) {
                return true;
            }
        }

        if (empty($booking_id) || ! wp_verify_nonce($nonce, 'wp_rest')) {
            return false;
        }

        // Allow booking owner or admins/managers.
        if ( (int) $booking->get_customer_id() === get_current_user_id() || current_user_can( 'manage_timetics' )) {
            return true;
        }
    
        return false;
    }

    /**
     * Get item permission callback
     * @param WP_Rest_Request $request
     * @return bool
     */
    public function get_item_permission_callback($request){
        $nonce = $request->get_header('X-WP-Nonce');
        $booking_id = (int) $request->get_param('booking_id');
        $appointment_token = $request->get_param('appointment_token');

        $booking = new Booking($booking_id);

        if (!$booking->is_booking()) {
            return false;
        }

        // Guests: must provide a valid token (constant-time compare).
        if ( ! empty( $appointment_token ) ) {
            $stored_token = (string) $booking->get_security_token();
            if ( '' !== $stored_token && hash_equals( $stored_token, (string) $appointment_token ) ) {
                return true;
            }
        } 

        if (wp_verify_nonce($nonce, 'wp_rest') && current_user_can( 'manage_timetics' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Validate email change permission during booking update.
     *
     * Prevents non-admin users from reassigning bookings to other users
     * by changing the email address. Follows the principle of least privilege.
     *
     * @param int    $booking_id The ID of the booking being updated.
     * @param string $new_email  The new email address from the request.
     *
     * @return string|WP_Error Returns the validated email on success, WP_Error on failure.
     */
    private function validate_email_change_permission( $booking_id, $new_email ) {
        // Admin users have full permission to change email addresses
        if ( current_user_can( 'manage_timetics' ) ) {
            return $new_email;
        }

        $existing_booking = new Booking( $booking_id );

        if ( ! $existing_booking->is_booking() ) {
            return new WP_Error( 404, __( 'Booking not found.', 'timetics' ) );
        }

        // Get original customer email
        $existing_customer = new Customer( $existing_booking->get_customer_id() );
        $original_email = $existing_customer->get_email();

        if ( empty( $original_email ) ) {
            return new WP_Error( 500, __( 'Unable to verify booking ownership.', 'timetics' ) );
        }

        // Check if email is being changed (case-insensitive comparison)
        $is_email_changed = ! empty( $new_email ) && strtolower( trim( $new_email ) ) !== strtolower( trim( $original_email ) );

        if ( $is_email_changed ) {
            return new WP_Error( 403, __( 'You are not allowed to change the email address for this booking.', 'timetics' ) );
        }

        return $original_email;
    }

    /**
     * Bind a Stripe PaymentIntent to a booking by writing the booking_id and security_token into the PaymentIntent's metadata.
     *
     * @param \WP_REST_Request $request
     * @return \WP_HTTP_Response
     */
    public function bind_payment_intent( $request ) {
        $booking_id = (int) $request['booking_id'];
        $booking    = new Booking( $booking_id );

        if ( ! $booking->is_booking() ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 404,
                    'message'     => esc_html__( 'Invalid booking id.', 'timetics' ),
                ],
                404
            );
        }

        $body      = json_decode( $request->get_body(), true );
        $body      = is_array( $body ) ? $body : [];
        $intent_id = ! empty( $body['payment_intent_id'] ) ? sanitize_text_field( (string) $body['payment_intent_id'] ) : '';

        if ( '' === $intent_id || strpos( $intent_id, 'pi_' ) !== 0 ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 400,
                    'message'     => esc_html__( 'Invalid payment intent id.', 'timetics' ),
                ],
                400
            );
        }

        $stripe = new StripePayment();

        $bound = $booking->get_stripe_payment_intent_id();
        if ( '' !== $bound && $bound !== $intent_id ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 409,
                    'message'     => esc_html__( 'Booking already bound to another payment intent.', 'timetics' ),
                ],
                409
            );
        }

        $intent = $stripe->retrieve_payment_intent( $intent_id );

        if ( is_wp_error( $intent ) || ! is_array( $intent ) || empty( $intent['id'] ) ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 502,
                    'message'     => esc_html__( 'Cannot verify payment intent with Stripe.', 'timetics' ),
                ],
                502
            );
        }

        $expected_amount   = (int) round( (float) $booking->get_total() * 100 );
        $expected_currency = strtolower( (string) apply_filters( 'timetics_currency', timetics_get_option( 'currency', 'USD' ) ) );
        $intent_amount     = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
        $intent_currency   = isset( $intent['currency'] ) ? strtolower( (string) $intent['currency'] ) : '';
        $intent_meta_book  = isset( $intent['metadata']['booking_id'] ) ? (int) $intent['metadata']['booking_id'] : 0;

        if ( $expected_amount <= 0 || $intent_amount !== $expected_amount || $intent_currency !== $expected_currency ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 409,
                    'message'     => esc_html__( 'Payment intent does not match this booking.', 'timetics' ),
                ],
                409
            );
        }

        if ( 0 !== $intent_meta_book && $booking_id !== $intent_meta_book ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 409,
                    'message'     => esc_html__( 'Payment intent is bound to another booking.', 'timetics' ),
                ],
                409
            );
        }

        $result = $stripe->update_payment_intent(
            $intent_id,
            [
                'booking_id'     => $booking_id,
                'security_token' => (string) $booking->get_security_token(),
            ]
        );

        if ( is_wp_error( $result ) ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 502,
                    'message'     => $result->get_error_message(),
                ],
                502
            );
        }

        return new WP_HTTP_Response(
            [
                'success'     => 1,
                'status_code' => 200,
                'message'     => esc_html__( 'Payment intent bound.', 'timetics' ),
            ],
            200
        );
    }

    public function make_payment_permission_callback( $request ) {

        $booking_id        = (int) $request->get_param('booking_id');
        $appointment_token = sanitize_text_field( $request->get_param('appointment_token') );

        if ( empty( $booking_id ) || empty( $appointment_token ) ) {
            return false;
        }

        $booking = new Booking( $booking_id );

        if ( ! $booking->is_booking() ) {
            return false;
        }

        $stored_token = $booking->get_security_token();

        if ( empty( $stored_token ) ) {
            return false;
        }

        // constant-time comparison
        if ( ! hash_equals( $stored_token, $appointment_token ) ) {
            return false;
        }
        if ( 'pending' !== (string) $booking->get_status() ) {
            return false;
        }

        return true;
    }

}
