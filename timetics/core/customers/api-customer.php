<?php
/**
 * Api Customer
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use Timetics\Base\Api;
use Timetics\Core\Bookings\Api_Booking;
use Timetics\Utils\Singleton;
use WP_HTTP_Response;
use WP_User_Query;

/**
 * Class Api_customer
 */
class Api_Customer extends Api {
    use Singleton;

    /**
     * Store namespace
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @var string
     */
    protected $rest_base = 'customers';

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
                    'callback'            => [$this, 'get_items'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_item'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'bulk_delete'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
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
            $this->namespace, '/' . $this->rest_base . '/(?P<customer_id>[\d]+)', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_item'],
                    'permission_callback' => function () {
                        return current_user_can( 'timetics-customer' );
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'update_item'],
                    'permission_callback' => function () {
                        return current_user_can( 'timetics-customer' );
                    },
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'delete_item'],
                    'permission_callback' => function () {
                        return current_user_can( 'timetics-customer' );
                    },
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

        /**
         * Register route for booking single customer
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, '/' . $this->rest_base . '/(?P<customer_id>[\d]+)/bookings', [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_bookings'],
                    'permission_callback' => function () {
                        return current_user_can( 'timetics-customer' );
                    },
                ],
            ]
        );

    }
    /**
     * Get all customers
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_items( $request ) {
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;

        $customer = Customer::all(
            [
                'number' => $per_page,
                'paged'  => $paged,
            ]
        );

        $items = [];

        foreach ( $customer['items'] as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $items = apply_filters( 'timetics/admin/customer/get_items', $items );

        $data = [
            'success' => 1,
            'data'    => [
                'total' => $customer['total'],
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Get single customer
     *
     * @param   WP_Rest_Requesr  $request
     *
     * @return  JSON Single customer data
     */
    public function get_item( $request ) {
        $customer_id = (int) $request['customer_id'];
        $customer    = new Customer( $customer_id );

        if ( ! $customer->is_customer() ) {
            return [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid customer id.', 'timetics' ),
                'data'        => [],
            ];
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $item = apply_filters( 'timetics/admin/customer/get_item', $this->prepare_item( $customer ) );

        $response = [
            'status_code' => 200,
            'data'        => $item,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Search customer
     *
     * @return JSON
     */
    public function search_items( $request ) {

        // Prepare search args.
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $search   = ! empty( $request['search'] ) ? sanitize_text_field( $request['search'] ) : '';

        // Get search.
        $users = new WP_User_Query(
            array(
                'role'   => 'timetics-customer',
                'number' => $per_page,
                'paged'  => $paged,

                // @codingStandardsIgnoreStart
                'meta_query' => array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_timetics_customer_first_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_timetics_customer_last_name',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_timetics_customer_email',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                    array(
                        'key'     => '_timetics_customer_phone',
                        'value'   => $search,
                        'compare' => 'LIKE',
                    ),
                ),
                // @codingStandardsIgnoreEnd
            )
        );

        // Prepare items for response.
        $items = [];

        foreach ( $users->get_results() as $item ) {
            $items[] = $this->prepare_item( $item->ID );
        }

        $data = [
            'success' => 1,
            'status'  => 200,
            'data'    => [
                'total' => $users->get_total(),
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Create Customer
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON | WP_Error
     */
    public function create_item( $request ) {
        return $this->save_customer( $request );
    }

    /**
     * Update customer
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON | WP_Error
     */
    public function update_item( $request ) {
        $customer_id = (int) $request['customer_id'];
        $customer    = new Customer( $customer_id );

        if ( ! $customer->is_customer() ) {
            $data = [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid customer id.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 404 );
        }

        return $this->save_customer( $request, $customer_id );
    }

    /**
     * Delete customer
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function delete_item( $request ) {
        $customer_id = (int) $request['customer_id'];
        $customer    = new Customer( $customer_id );

        if ( ! $customer->is_customer() ) {
            $data = [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid customer id.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 404 );
        }

        $customer->delete();

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/customer/delete_item', $customer );

        $response = [
            'status_code' => 200,
            'message'     => esc_html__( 'Successfully deleted customer', 'timetics' ),
            'data'        => [],
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
        $customers = json_decode( $request->get_body(), true );

        foreach ( $customers as $customer ) {
            $customer = new customer( $customer );

            if ( ! $customer->is_customer() ) {
                $data = [
                    'success' => 1,
                    'status'  => 404,
                    'message' => esc_html__( 'Invalid customer id.', 'timetics' ),
                    'data'    => [],
                ];

                return new WP_HTTP_Response( $data, 404 );
            }

            $customer->delete();
        }

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/customer/bulk_delete', $customers );

        return [
            'success' => 1,
            'status'  => 200,
            'message' => esc_html__( 'Successfully deleted customer', 'timetics' ),
            'data'    => [
                'items' => $customers,
            ],
        ];
    }

    /**
     * Save customer
     *
     * @param   WP_Rest_Request  $request
     * @param   integer  $id       [$id description]
     *
     * @return  JSON | WP_Error
     */
    public function save_customer( $request, $id = 0 ) {
        $data = json_decode( $request->get_body(), true );

        $first_name = ! empty( $data['first_name'] ) ? $data['first_name'] : '';
        $last_name  = ! empty( $data['last_name'] ) ? $data['last_name'] : '';
        $email      = ! empty( $data['email'] ) ? $data['email'] : '';
        $phone      = ! empty( $data['phone'] ) ? $data['phone'] : '';
        $image      = ! empty( $data['image'] ) ? intval( $data['image'] ) : '';
        $password   = ! empty( $data['password'] ) ? $data['password'] : '';
        $action     = $id ? 'updated' : 'created';

        // Validate input data.
        $validate = $this->validate(
            $data, [
                'first_name',
                'last_name',
                'email',
            ]
        );

        if ( is_wp_error( $validate ) ) {
            $data = [
                'success'     => 0,
                'status_code' => 409,
                'message'     => $validate->get_error_messages(),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 409 );
        }

        // Save customer data.
        $customer = new Customer( $id );

        $customer->set_props(
            [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'user_name'  => $this->generate_username( $email ),
                'phone'      => $phone,
                'image'      => $image,
                'password'   => $password,
            ]
        );

        $customer->save();

        if ( is_wp_error( $customer->error ) ) {
            return [
                'success' => 0,
                'status'  => 409,
                'message' => $customer->error->get_error_message(),
                'data'    => [],
            ];
        }
        // Prepare for response.
        $item = $this->prepare_item( $customer );

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action( 'timetics/admin/customer/create_item', $item );

        $data = [
            'success' => 1,
            'status'  => 200,
            /* translators: Action */
            'message' => sprintf( esc_html__( 'Successfully %s customer', 'timetics' ), $action ),
            'data'    => $item,
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Generate username from email
     *
     * @param   string  $email
     *
     * @return  string
     */
    public function generate_username( $email ) {
        $username = strtok( $email, '@' );

        if ( username_exists( $username ) ) {
            $username = $username . wp_rand( 10, 100 );
        }

        return $username;
    }

    /**
     * Prepare item
     *
     * @param   integer | customer  $customer_id  customer Id
     *
     * @return  array customer data
     */
    public function prepare_item( $customer ) {
        $customer = new Customer( $customer );

        return [
            'id'            => $customer->get_id(),
            'full_name'     => $customer->get_display_name(),
            'first_name'    => $customer->get_first_name(),
            'last_name'     => $customer->get_last_name(),
            'email'         => $customer->get_email(),
            'phone'         => $customer->get_phone(),
            'image'         => $customer->get_image(),
            'password'      => $customer->get_password(),
            'total_booking' => $customer->get_total_booking(),
        ];
    }

    /**
     * Get booking list for single customer
     *
     * @param   integer | customer  $customer_id  customer Id
     *
     * @return  array booking data
     */
    public function get_bookings( $request ) {
        $customer_id = (int) $request['customer_id'];
        $customer    = new Customer( $customer_id );

        if ( ! $customer->is_customer() ) {
            $data = [
                'status_code' => 404,
                'message'     => esc_html__( 'Invalid customer id.', 'timetics' ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 404 );
        }

        return $this->get_booking_list( $request, $customer_id );
    }

    /**
     * Save customer
     *
     * @param   WP_Rest_Request  $request
     * @param   integer  $id       [$id description]
     *
     * @return  JSON | WP_Error
     */
    public function get_booking_list( $request, $customer_id = 0 ) {

        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;

        $args = [
            'post_type'      => 'timetics-booking',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'post_status'    => 'any',
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_tt_booking_customer',
                    'value'   => $customer_id,
                    'compare' => '=',
                ),

            ),
        ];

        $bookings = new \WP_Query( $args );

        // Prepare items for response.
        $items   = [];
        $booking = new Api_Booking();

        foreach ( $bookings->posts as $item ) {
            $items[] = $booking->prepare_item( $item->ID );
        }

        $data = [
            'success' => 1,
            'status'  => 200,
            'data'    => [
                'total' => $bookings->found_posts,
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );

    }

}
