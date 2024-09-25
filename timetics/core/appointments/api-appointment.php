<?php
/**
 * Apointment api
 *
 * @since 1.0.0
 *
 * @package Timetics
 */
namespace Timetics\Core\Appointments;

use Timetics\Base\Api;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Staffs\Staff;
use Timetics\Utils\Singleton;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Api_Appointment class
 *
 * @since 1.0.0
 */
class Api_Appointment extends Api {

    use Singleton;

    /**
     * Store api namespace
     *
     * @since 1.0.0
     *
     * @var string $namespace
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @since 1.0.0
     *
     * @var string $rest_base
     */
    protected $rest_base = 'appointments';

    /**
     * Register rest routes.
     *
     * @since 1.0.0
     *
     * @return  void
     */
    public function register_routes() {
        /*
         * Register route
         */
        register_rest_route( $this->namespace, $this->rest_base, [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => function () {
                    return true;
                },
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => function () {
                    return current_user_can( 'read_meeting' );
                },
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => function () {
                    return current_user_can( 'read_meeting' );
                },
            ],
        ] );

        /**
         * Register route
         *
         * @var void
         */
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<appointment_id>[\d]+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => function () {
                    return true;
                },
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => function () {
                    return current_user_can( 'read_meeting' );
                },
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => function () {
                    return current_user_can( 'read_meeting' );
                },
            ],
        ] );

        register_rest_route( $this->namespace, $this->rest_base . '/search', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'search_items'],
                'permission_callback' => function () {
                    return current_user_can( 'edit_posts' );
                },
            ],
        ] );

        register_rest_route( $this->namespace, $this->rest_base . '/filter', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'filter_items'],
                'permission_callback' => function () {
                    return true;
                },
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<appointment_id>[\d]+)' . '/duplicate', [
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'duplicate_item'],
                'permission_callback' => function () {
                    return current_user_can( 'edit_meeting' );
                },
            ],
        ] );
    }

    /**
     * Get all appointments
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_items( $request ) {

        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $type     = ! empty( $request['type'] ) ? sanitize_text_field( $request['type'] ) : '';

        $args = [
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'type'           => $type,
        ];

        if ( ! current_user_can( 'edit_meeting' ) ) {
            $args['staff'] = get_current_user_id();
        }

        $appoint = Appointment::all( $args );

        $items = [];

        foreach ( $appoint['items'] as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'data'        => [
                'total' => $appoint['total'],
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Search appointment
     *
     * @param   Object  $request
     *
     * @return JSON
     */
    public function search_items( $request ) {

        // Prepare search args.
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $search   = ! empty( $request['search'] ) ? sanitize_text_field( $request['search'] ) : '';

        // Get search.
        $appointments = new \WP_Query(
            array(
                'post_type'      => 'timetics-appointment',
                'posts_per_page' => $per_page,
                'paged'          => $paged,
                'orderby'        => 'ID',
                'order'          => 'DESC',

                // @codingStandardsIgnoreStart
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_tt_apointment_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_apointment_type',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_apointment_description',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_apointment_location',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_apointment_duration',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_tt_apointment_schedule',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                ),
                // @codingStandardsIgnoreEnd
            )
        );

        // Prepare items for response.
        $items = [];

        foreach ( $appointments->posts as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        $data = [
            'success' => 1,
            'status'  => 200,
            'data'    => [
                'total' => $appointments->found_posts,
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    public function filter_items( $request ) {

        $per_page   = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged      = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $staff      = ! empty( $request['staff_id'] ) ? intval( $request['staff_id'] ) : 0;
        $category   = ! empty( $request['category'] ) ? intval( $request['category'] ) : 0;
        $visibility = ! empty( $request['visibility'] ) ? sanitize_text_field( $request['visibility'] ) : '';

        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;

        $appoint = Appointment::all( [
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'visibility'     => $visibility,
            'staff'          => $staff,
            'category'       => $category,
        ] );

        $items = [];

        foreach ( $appoint['items'] as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        $data = [
            'success' => 1,
            'status'  => 200,
            'data'    => [
                'total' => $appoint['total'],
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Create appointment
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON Newly created appointment data
     */
    public function create_item( $request ) {

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */

        $meetings_count = Appointment::all();
        $data           = json_decode( $request->get_body(), true );

        $response = [
            'success'     => 0,
            'status_code' => 403,
            'message'     => esc_html__( 'Something went wrong', 'timetics' ),
            'data'        => [],
        ];

        if ( ! empty( $data['price'] ) && apply_filters( 'timetics/staff/appointment/price_check', false, $data['price'] ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'price_check' ), 403 );
        }

        if ( apply_filters( 'timetics/staff/appointment/count_check', false, $meetings_count ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'count_check' ), 403 );
        }

        $type = ! empty( $data['type'] ) ? sanitize_text_field( $data['type'] ) : '';

        if ( apply_filters( 'timetics/staff/appointment/type_check', false, $type ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'type_check' ), 403 );
        }

        $categories = ! empty( $data['categories'] ) ? $data['categories'] : '';

        if ( apply_filters( 'timetics/staff/appointment/category_check', false, $categories ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'category_check' ), 403 );
        }

        $staff     = ! empty( $data['staff'] ) ? array_map( 'intval', $data['staff'] ) : [];

        if ( apply_filters( 'timetics/staff/appointment/staff_check', false, $staff ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'staff_check' ), 403 );
        }

        $custom_fields         = ! empty( $data['custom_fields'] ) ? $data['custom_fields'] : [];

        if ( apply_filters( 'timetics/staff/appointment/custom_field_check', false, $custom_fields ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'custom_field_check' ), 403 );
        }

        $recurring_limit         = ! empty( $data['recurring_limit'] ) ? $data['recurring_limit'] : [];

        if ( apply_filters( 'timetics/staff/appointment/recurring_limit_check', false, $recurring_limit ) == true ) {

            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'recurring_limit_check' ), 403 );
        }

		// End

        return $this->save_appointment( $request );
    }

    /**
     * Update appointment
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON  Updated appointment data
     */
    public function update_item( $request ) {
        
        $appointment_id = (int) $request['appointment_id'];
        $appoint        = new Appointment( $appointment_id );

        $data = json_decode( $request->get_body(), true );

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $response = [
            'success'     => 0,
            'status_code' => 502,
            'message'     => esc_html__( 'Something went wrong', 'timetics' ),
            'data'        => [],
        ];

        if ( !empty($data['availability']) && $data['availability'] && apply_filters('timetics/staff/meeting/availability', false)) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'availability_update' ), 403 );
        }

        if ( ! empty( $data['price'] ) && apply_filters( 'timetics/staff/appointment/price_check', false, $data['price'] ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'price_check' ), 403 );
        }

        $categories = ! empty( $data['categories'] ) ? $data['categories'] : '';

        if ( apply_filters( 'timetics/staff/appointment/category_check', false, $categories ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'category_check' ), 403 );
        }

        $staff     = ! empty( $data['staff'] ) ? array_map( 'intval', $data['staff'] ) : [];

        if ( apply_filters( 'timetics/staff/appointment/staff_check', false, $staff ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'staff_check' ), 403 );
        }

        $custom_fields         = ! empty( $data['custom_fields'] ) ? $data['custom_fields'] : [];

        if ( apply_filters( 'timetics/staff/appointment/custom_field_check', false, $custom_fields ) == true ) {
            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'custom_field_check' ), 403 );
        }

        $recurring_limit         = ! empty( $data['recurring_limit'] ) ? $data['recurring_limit'] : [];

        if ( apply_filters( 'timetics/staff/appointment/recurring_limit_check', false, $recurring_limit ) == true ) {

            return new WP_HTTP_Response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'recurring_limit_check' ), 403 );
        }

        // End.

        if ( ! $appoint->is_appointment() ) {

            $response = [
                'success'     => 0,
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid appointment id.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $response, 404 );
        }

        return $this->save_appointment( $request, $appointment_id );
    }

    /**
     * Get single appointment
     *
     * @param   WP_Rest_Requesr  $request
     *
     * @return  JSON Single appointment data
     */
    public function get_item( $request ) {
        $appoinment_id = (int) $request['appointment_id'];
        $appoint       = new Appointment( $appoinment_id );

        if ( ! $appoint->is_appointment() ) {

            $data = [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid appointment id.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 404 );
        }

        $response = [
            'status_code' => 200,
            'message'     => esc_html__( 'Successfully retrieved appointments', 'timetics' ),
            'data'        => $this->prepare_item( $appoint ),
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Delete single appointment
     *
     * @param   WP_Rest_Request  $request
     *
     * @return
     */
    public function delete_item( $request ) {

        $appoinment_id = (int) $request['appointment_id'];
        $appoint       = new Appointment( $appoinment_id );

        $current_user_id = get_current_user_id();

        if ( $appoint->get_author() != $current_user_id ) {
            $data = [
                'success' => 0,
                'message' => __( 'You are not allowed to delete this meeting.', 'timetics' ),
            ];

            return new WP_HTTP_Response( $data, 403 );
        }

        if ( ! $appoint->is_appointment() ) {
            return [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid appointment id.', 'timetics' ),
                'data'        => [],
            ];
        }

        $appoint->delete();

        $response = [
            'status_code' => 201,
            'message'     => esc_html__( 'Successfully deleted appointment', 'timetics' ),
            'data'        => [
                'item' => $appoinment_id,
            ],
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Delete multiples
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function bulk_delete( $request ) {

        $appointments = json_decode( $request->get_body(), true );

        foreach ( $appointments as $appoint ) {
            $appoint = new Appointment( $appoint );

            if ( ! $appoint->is_appointment() ) {
                $data = [
                    'success' => 0,
                    'status'  => 404,
                    'message' => esc_html__( 'Invalid appointment id.', 'timetics' ),
                    'data'    => [],
                ];

                return new WP_HTTP_Response( $data, 404 );
            }

            $appoint->delete();
        }

        return rest_ensure_response( [
            'success' => 1,
            'status'  => 201,
            'message' => esc_html__( 'Successfully deleted all appointments', 'timetics' ),
            'data'    => [
                'items' => $appointments,
            ],
        ] );
    }

    /**
     * Duplicate appointment
     *
     * @since 1.0.0
     *
     * @param object $request
     *
     * @return  JSON
     */
    public function duplicate_item( $request ) {

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */

        $meetings_count = Appointment::all();

        $response = [
            'success'     => 0,
            'status_code' => 502,
            'message'     => esc_html__( 'Something went wrong', 'timetics' ),
            'data'        => [],
        ];

        if ( apply_filters( 'timetics/staff/appointment/count_check', false, $meetings_count ) == true ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'count_check' ) );
        }

        $custom_fields         = ! empty( $data['custom_fields'] ) ? $data['custom_fields'] : [];

        if ( apply_filters( 'timetics/staff/appointment/custom_field_check', false, $custom_fields ) == true ) {
            return rest_ensure_response( apply_filters( 'timetics/admin/appointment/error_data', $response, 'custom_field_check' ) );
        }

        $appoinment_id = (int) $request['appointment_id'];
        $appoint       = new Appointment( $appoinment_id );

        if ( ! $appoint->is_appointment() ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 404,
                    'message'     => esc_html__( 'Invalid appointment id.', 'timetics' ),
                    'data'        => [],
                ],
                404
            );
        }

        $appoint->duplicate();

        $item = $this->prepare_item( $appoint );

        $response = [
            'success'     => 1,
            'status_code' => 201,
            'message'     => esc_html__( 'Successfully duplicated appointment', 'timetics' ),
            'data'        => $item,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Save appointment
     *
     * @param   WP_Rest_Request  $request
     * @param   integer  $id     Appointment id
     *
     * @return  JSON  Updated appoitment data
     */
    public function save_appointment( $request, $id = 0 ) {
        $appoint = new Appointment( $id );

        $data = json_decode( $request->get_body(), true );

        $data = apply_filters( 'timetics_meeting_data', $data );

        $name                  = ! empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $appoint->get_name();
        $type                  = ! empty( $data['type'] ) ? sanitize_text_field( $data['type'] ) : $appoint->get_type();
        $description           = ! empty( $data['description'] ) ? sanitize_text_field( $data['description'] ) : '';
        $staff                 = ! empty( $data['staff'] ) ? array_map( 'intval', $data['staff'] ) : $appoint->get_staff();
        $locations             = ! empty( $data['locations'] ) ? $data['locations'] : $appoint->get_locations();
        $duration              = ! empty( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : '';
        $schedule              = ! empty( $data['schedule'] ) ? $data['schedule'] : $appoint->get_schedule();
        $blocked_schedule      = ! empty( $data['blocked_schedule'] ) ? $data['blocked_schedule'] : $appoint->get_blocked_schedule();
        $price                 = ! empty( $data['price'] ) ? $data['price'] : '';
        $categories            = ! empty( $data['categories'] ) ? $data['categories'] : '';
        $buffer_time           = ! empty( $data['buffer_time'] ) ? $data['buffer_time'] : '';
        $timezone              = ! empty( $data['timezone'] ) ? $data['timezone'] : '';
        $availability          = ! empty( $data['availability'] ) ? $data['availability'] : '';
        $visibility            = ! empty( $data['visibility'] ) ? strtolower( $data['visibility'] ) : 'enabled';
        $notifications         = ! empty( $data['notifications'] ) ? $data['notifications'] : '';
        $fleunt_crm_webhook    = ! empty( $data['fleunt_crm_webhook'] ) ? $data['fleunt_crm_webhook'] : '';
        $fluent_hook_overwrite = ! empty( $data['fluent_hook_overwrite'] ) ? (bool) $data['fluent_hook_overwrite'] : false;
        $pabbly_hook_overwrite = ! empty( $data['pabbly_hook_overwrite'] ) ? (bool) $data['pabbly_hook_overwrite'] : false;
        $zapier_hook_overwrite = ! empty( $data['zapier_hook_overwrite'] ) ? (bool) $data['zapier_hook_overwrite'] : false;
        $pabbly_webook         = ! empty( $data['pabbly_webook'] ) ? $data['pabbly_webook'] : '';
        $zapier_webook         = ! empty( $data['zapier_webook'] ) ? $data['zapier_webook'] : '';
        $min_notice_time       = ! empty( $data['min_notice_time'] ) ? $data['min_notice_time'] : '';
        $custom_fields         = ! empty( $data['custom_fields'] ) ? $data['custom_fields'] : [];
        $guest_enabled         = ! empty( $data['guest_enabled'] ) ? intval( $data['guest_enabled'] ) : false;
        $guest_limit           = ! empty( $data['guest_limit'] ) ? intval( $data['guest_limit'] ) : 1;
        $capacity              = ! empty( $data['capacity'] ) ? intval( $data['capacity'] ) : 1;
        $appointment_id        = ! empty( $data['appointment'] ) ? intval( $data['appointment'] ) : 1;
        $action                = $id ? 'update' : 'created';

        if ( $id ) {
            $current_user_id = get_current_user_id();
            $dulicate = $appoint->get_duplicate_nuber();

            if ( $appoint->get_author() != $current_user_id ) {
                $data = [
                    'success' => 0,
                    'message' => __( 'You are not allowed to update this meeting.', 'timetics' ),
                ];

                return new WP_HTTP_Response( $data, 403 );
            }

            if ( $dulicate && strpos( $name, '-Duplicate' ) == 0 ) {
                $appoint->update([
                    'duplicate' => 0
                ]);
            }
        }

        if ( is_array( $price ) ) {
            $ticket_quantity = 0;
            foreach ( $price as &$ticket ) {
                if ( empty( $ticket['ticket_price'] ) ) {
					$ticket['ticket_price'] = 0;
                }
				if ( ! empty( $ticket['ticket_quantity'] ) ) {
                    $ticket_quantity += intval( $ticket['ticket_quantity'] );
                }
            }

            $capacity = $ticket_quantity;
        }

        $validate_data = [
            'name'             => $name,
            'type'             => $type,
            'locations'        => $locations,
            'schedule'         => $schedule,
            'blocked_schedule' => $blocked_schedule,
            'staff'            => $staff,

        ];
        // Validate input data.
        $validate = $this->validate( $validate_data, [
            'name',
            'type',
            'locations',
            'schedule',
            'staff',
        ] );

        if ( ! timetics_is_valid_timezone( $timezone ) ) {
            return new WP_Error( 'timezone_error', __( 'Your timezone is invaid.', 'timetics' ) );
        }

        $lolcation_errors = $this->get_location_errors( $locations, $staff );

        if ( $lolcation_errors ) {
            $data = [
                'status_code' => 409,
                'success'     => 0,
                'message'     => $lolcation_errors,
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 409 );
        }

        if ( is_wp_error( $validate ) ) {
            $data = [
                'status_code' => 409,
                'success'     => 0,
                'message'     => $validate->get_error_messages(),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 409 );
        }

        // Save appointment.
        $appointment_data = [
            'name'                  => str_replace( '-Duplicate', '', $name ),
            'description'           => $description,
            'type'                  => $type,
            'locations'             => $locations,
            'staff'                 => $staff,
            'duration'              => $duration,
            'price'                 => $price,
            'capacity'              => $capacity,
            'schedule'              => $schedule,
            'blocked_schedule'      => $blocked_schedule,
            'categories'            => $categories,
            'timezone'              => $timezone,
            'availability'          => $availability,
            'visibility'            => $visibility,
            'buffer_time'           => $buffer_time,
            'notifications'         => $notifications,
            'fleunt_crm_webhook'    => $fleunt_crm_webhook,
            'fluent_hook_overwrite' => $fluent_hook_overwrite,
            'pabbly_hook_overwrite' => $pabbly_hook_overwrite,
            'zapier_hook_overwrite' => $zapier_hook_overwrite,
            'pabbly_webook'         => $pabbly_webook,
            'zapier_webook'         => $zapier_webook,
            'min_notice_time'       => $min_notice_time,
            'custom_fields'         => $custom_fields,
            'guest_enabled'         => $guest_enabled,
            'guest_limit'           => $guest_limit,

        ];

        $appointment_data = apply_filters( 'timetics_meeting_insert_data', $data, $appointment_data );

        $appoint->set_props( $appointment_data );
        $appoint->save();

        // Assign meeting category.
        wp_set_post_terms( $appoint->get_id(), $categories, 'timetics-meeting-category' );

        do_action( 'timetics_meeting_after_insert', $appoint, $data );

        // Prepare response data.
        $item = $this->prepare_item( $appoint );

        $response = [
            'status_code' => 201,
            'success'     => 1,
            'message'     => sprintf( esc_html__( 'Succssfully %s appointment', 'timetics' ), $action ),
            'data'        => $item,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Prepare item for response
     *
     * @param   integer  $appoinment_id
     *
     * @return  array
     */
    public function prepare_item( $appoint_id, $timezone = '' ) {
        $appointment   = new Appointment( $appoint_id );
        $dulicate      = $appointment->get_duplicate_nuber();

        $dulicate_text = $dulicate ? ' -Duplicate' : '';
        $custom_fields = $appointment->get_custom_fields();
        $data          = [
            'id'                    => $appointment->get_id(),
            'name'                  => $appointment->get_name() . $dulicate_text,
            'image'                 => $appointment->get_image(),
            // 'link'        => $appointment->get_link(),
            'description'           => $appointment->get_description(),
            'type'                  => $appointment->get_type(),
            'locations'             => $appointment->get_locations(),
            'schedule'              => $appointment->get_schedule(),
            'blocked_schedule'      => $appointment->get_blocked_schedule(),
            'price'                 => $appointment->get_price(),
            'categories'            => $appointment->get_category_ids(),
            'staff'                 => $appointment->get_staff(),
            'buffer_time'           => $appointment->get_buffer_time(),
            'timezone'              => $appointment->get_timezone(),
            'availability'          => $appointment->get_availability(),
            'visibility'            => $appointment->get_visibility(),
            'duration'              => $appointment->get_duration(),
            'notifications'         => $appointment->get_notifications(),
            'capacity'              => $appointment->get_capacity(),
            'fluent_hook_overwrite' => $appointment->get_fluent_hook_overwrite(),
            'fleunt_crm_webhook'    => $appointment->get_fleunt_crm_webhook(),
            'pabbly_hook_overwrite' => $appointment->get_pabbly_hook_overwrite(),
            'pabbly_webook'         => $appointment->get_pabbly_webook(),
            'zapier_hook_overwrite' => $appointment->get_zapier_hook_overwrite(),
            'zapier_webook'         => $appointment->get_zapier_webook(),
            'min_notice_time'       => $appointment->get_min_notice_time(),
            'custom_fields'         => $custom_fields ?: [],
            'permalink'             => get_permalink( $appointment->get_id() ),
            'guest_enabled'         => $appointment->get_guest_enabled(),
            'guest_limit'           => $appointment->get_guest_limit(),
            'author'                => $appointment->get_author(),
        ];

        return apply_filters( 'timetics_meeting_json_data', $data, $appointment );
    }

    /**
     * Validate location with zoom and google connection
     *
     * @param   array  $locations
     * @param   array  $staffs
     *
     * @return  array
     */
    public function get_location_errors( $locations, $staffs ) {

        $errors = [];

        foreach ( $staffs as $staff_id ) {
            $staff    = new Staff( $staff_id );
            foreach ( $locations as $location ) {
                switch ( $location['location_type'] ) {
                case 'google-meet':
                    if ( ! timetics_is_google_meet_connected( $staff_id ) ) {
                        $errors[] = sprintf( '%s %s', $staff->get_display_name(), esc_html__( 'is not connected to google meet. Please connect to google meet then try again', 'timetics' ) );
                    }
                    break;
                case 'zoom':
                    if ( ! timetics_is_zoom_connected( $staff_id ) ) {
                        $errors[] = sprintf( '%s %s', $staff->get_display_name(), esc_html__( 'is not connected to zoom. Please connect to zoom then try again', 'timetics' ) );
                    }
                    break;
                }
            }
        }

        return $errors;
    }
}
