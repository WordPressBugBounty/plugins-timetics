<?php
/**
 * Api customer records
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
use Timetics\Core\Bookings\Api_Booking;
use Timetics\Utils\Singleton;
use WP_Error;

/**
 * The admin Customers screen: one entry per contact row.
 *
 * Ids here are row ids, not WordPress user ids; /customers/{id} keeps user ids for My Account.
 * Staff see only the contacts of bookings they can see.
 */
class Api_Customer_Record extends Api {
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
    protected $rest_base = 'customer-records';

    /**
     * Register rest routes
     *
     * @return void
     */
    public function register_routes() {
        $staff = function () {
            return current_user_can( 'manage_timetics' );
        };

        $admin = function () {
            return current_user_can( 'manage_options' );
        };

        $staff_row = function ( $request ) {
            return current_user_can( 'manage_timetics' ) && $this->can_see( (int) $request['id'] );
        };

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => $staff,
                ),
                array(
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'hide_items' ),
                    'permission_callback' => $admin,
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => $staff_row,
                ),
                array(
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'hide_item' ),
                    'permission_callback' => $admin,
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[\d]+)/bookings',
            array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_bookings' ),
                    'permission_callback' => $staff_row,
                ),
            )
        );
    }

    /**
     * List or search contacts
     *
     * @param \WP_REST_Request $request Request: search, per_page, paged.
     *
     * @return \WP_REST_Response
     */
    public function get_items( $request ) {
        // The list must not wait for page loads to finish copying older customers.
        Customer_Backfill::run_batch();

        $result = Customer_Store::query(
            array(
                'search'   => sanitize_text_field( (string) $request['search'] ),
                'per_page' => $request['per_page'] ? min( 100, absint( $request['per_page'] ) ) : 20,
                'paged'    => $request['paged'] ? absint( $request['paged'] ) : 1,
                'ids'      => $this->allowed_ids(),
            )
        );

        $counts = Customer_Store::booking_counts( $result['items'] );
        $items  = array();

        foreach ( $result['items'] as $row ) {
            $items[] = $this->prepare_row( $row, $counts[ (int) $row['id'] ] );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => array(
                    'total'   => $result['total'],
                    'items'   => $items,
                    'syncing' => ! Customer_Backfill::is_done(),
                ),
            )
        );
    }

    /**
     * One contact
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $row = $this->visible_row( (int) $request['id'] );

        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $counts = Customer_Store::booking_counts( array( $row ) );

        return rest_ensure_response( array( 'success' => 1, 'data' => $this->prepare_row( $row, $counts[ (int) $row['id'] ] ) ) );
    }

    /**
     * Change a contact's name and phone.
     *
     * Email and account link are never taken from the request: bookings and accounts
     * rely on them, and a changed email could clash with another row.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $row = $this->visible_row( (int) $request['id'] );

        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $body = json_decode( $request->get_body(), true );

        if ( ! is_array( $body ) ) {
            return new WP_Error( 'invalid_body', __( 'Invalid request.', 'timetics' ), array( 'status' => 400 ) );
        }

        $fields = array_intersect_key( $body, array_flip( array( 'first_name', 'last_name', 'phone' ) ) );

        if ( array_key_exists( 'first_name', $fields ) && '' === trim( (string) $fields['first_name'] ) ) {
            return new WP_Error( 'first_name_required', __( 'First name is required.', 'timetics' ), array( 'status' => 400 ) );
        }

        Customer_Store::update_contact( $row['id'], $fields );

        return $this->get_item( $request );
    }

    /**
     * Take one contact out of the list. Bookings and accounts stay.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function hide_item( $request ) {
        $row = $this->visible_row( (int) $request['id'] );

        if ( is_wp_error( $row ) ) {
            return $row;
        }

        Customer_Store::hide( $row['id'] );

        return rest_ensure_response( array( 'success' => 1, 'message' => __( 'Contact removed from the Customers list.', 'timetics' ) ) );
    }

    /**
     * Take several contacts out of the list. The body is a JSON array of row ids.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function hide_items( $request ) {
        $ids = json_decode( $request->get_body(), true );

        if ( ! is_array( $ids ) ) {
            return new WP_Error( 'invalid_body', __( 'Invalid request.', 'timetics' ), array( 'status' => 400 ) );
        }

        $ids = array_filter( array_map( 'absint', $ids ) );

        foreach ( $ids as $id ) {
            Customer_Store::hide( $id );
        }

        return rest_ensure_response( array( 'success' => 1, 'data' => array( 'items' => array_values( $ids ) ) ) );
    }

    /**
     * A contact's bookings: those made under its row, plus its account's older bookings.
     *
     * @param \WP_REST_Request $request Request: per_page, paged.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function get_bookings( $request ) {
        $row = $this->visible_row( (int) $request['id'] );

        if ( is_wp_error( $row ) ) {
            return $row;
        }

        $mine = array(
            array(
                'key'   => '_tt_booking_customer_record',
                'value' => (int) $row['id'],
            ),
        );

        if ( ! empty( $row['wp_user_id'] ) ) {
            $mine['relation'] = 'OR';
            $mine[]           = array(
                'relation' => 'AND',
                array(
                    'key'   => '_tt_booking_customer',
                    'value' => (int) $row['wp_user_id'],
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_tt_booking_customer_record',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_tt_booking_customer_record',
                        'value'   => array( '', '0' ),
                        'compare' => 'IN',
                    ),
                ),
            );
        }

        $args = array(
            'post_type'      => 'timetics-booking',
            'post_status'    => 'any',
            'posts_per_page' => $request['per_page'] ? min( 100, absint( $request['per_page'] ) ) : 20,
            'paged'          => $request['paged'] ? absint( $request['paged'] ) : 1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bookings are found by their customer meta.
            'meta_query'     => $mine,
        );

        // Staff may see this contact through one booking; that is no reason to show them the rest.
        if ( ! timetics_can_view_all_data() ) {
            $args['post__in'] = timetics_get_visible_booking_ids( get_current_user_id() );
        }

        $query = new \WP_Query( $args );
        $items = array();

        foreach ( $query->posts as $post ) {
            $items[] = Api_Booking::instance()->prepare_item( $post->ID, false );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => array(
                    'total' => $query->found_posts,
                    'items' => $items,
                ),
            )
        );
    }

    /**
     * Response shape of one contact
     *
     * @param array $row           Row.
     * @param int   $total_booking Counted bookings.
     *
     * @return array
     */
    public function prepare_row( array $row, $total_booking = 0 ) {
        $user = empty( $row['wp_user_id'] ) ? false : get_userdata( (int) $row['wp_user_id'] );

        return array(
            'id'            => (int) $row['id'],
            'wp_user_id'    => $user ? (int) $user->ID : 0,
            'first_name'    => $row['first_name'],
            'last_name'     => $row['last_name'],
            'full_name'     => trim( $row['first_name'] . ' ' . $row['last_name'] ),
            'email'         => $row['email'],
            'phone'         => $row['phone'],
            'image'         => get_avatar_url( $row['email'] ),
            'total_booking' => (int) $total_booking,
            'has_account'   => (bool) $user,
            // The account's email can change in Users; the contact keeps the one it was made with.
            'account_email' => $user && strtolower( $user->user_email ) !== $row['email'] ? $user->user_email : '',
            'created_at'    => $row['created_at'],
        );
    }

    /**
     * Row ids this user may see, or null for every row.
     *
     * @return array|null
     */
    private function allowed_ids() {
        if ( timetics_can_view_all_data() ) {
            return null;
        }

        return Customer_Store::ids_for_bookings( timetics_get_visible_booking_ids( get_current_user_id() ) );
    }

    /**
     * Whether this user may see the row
     *
     * @param int $id Row id.
     *
     * @return bool
     */
    private function can_see( $id ) {
        $allowed = $this->allowed_ids();

        return null === $allowed || in_array( $id, $allowed, true );
    }

    /**
     * A row that is not hidden
     *
     * @param int $id Row id.
     *
     * @return array|WP_Error
     */
    private function visible_row( $id ) {
        $row = Customer_Store::get( $id );

        if ( ! $row || null !== $row['removed_at'] ) {
            return new WP_Error( 'customer_not_found', __( 'Customer not found.', 'timetics' ), array( 'status' => 404 ) );
        }

        return $row;
    }
}
