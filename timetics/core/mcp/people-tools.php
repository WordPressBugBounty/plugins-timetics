<?php
/**
 * Customer and staff tools
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer_Store;
use Timetics\Core\Staffs\Staff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Customers and staff. Writes are owner only and save through Dashboard_Writer.
 */
class People_Tools {

    const ROWS     = 50;
    const BOOKINGS = 10;

    /**
     * Customer and staff tools.
     *
     * @return  array  Same shape as Abilities::tools().
     */
    public static function tools(): array {
        $id      = array(
            'type'    => 'integer',
            'minimum' => 1,
        );
        $name    = array(
            'type'      => 'string',
            'minLength' => 1,
            'maxLength' => 100,
        );
        $email   = array(
            'type'      => 'string',
            'format'    => 'email',
            'maxLength' => 254,
        );
        $phone   = array(
            'type'      => 'string',
            'maxLength' => 50,
        );
        $token   = array( 'type' => 'string' );
        $confirm = __( 'The first call saves nothing and returns a preview and confirm_token: only after the user says yes call again with the same fields and confirm_token.', 'timetics' );
        $owner   = array( 'owner' => true );
        $object  = function ( array $required, array $properties ) {
            return array(
                'type'                 => 'object',
                'required'             => $required,
                'additionalProperties' => false,
                'properties'           => $properties,
            );
        };

        return array(
            'timetics/list-customers' => $owner + array(
                'readonly'    => true,
                'label'       => __( 'List customers', 'timetics' ),
                'description' => __( 'Owner only. Customers newest first, 50 at a time, with how many bookings each has. To search, use find-customer.', 'timetics' ),
                'input'       => array(
                    'type'                 => 'object',
                    'default'              => new \stdClass(),
                    'additionalProperties' => false,
                    'properties'           => array(
                        'offset' => array(
                            'type'    => 'integer',
                            'minimum' => 0,
                        ),
                    ),
                ),
                'run'         => array( self::class, 'list_customers' ),
            ),
            'timetics/get-customer'   => array(
                'readonly'    => true,
                'label'       => __( 'Get a customer', 'timetics' ),
                'description' => __( 'A customer with contact, booking count and latest bookings. Staff see only customers of their own bookings.', 'timetics' ),
                'input'       => $object( array( 'id' ), array( 'id' => $id ) ),
                'run'         => function ( $input, $user ) {
                    return self::get_customer( (int) $input['id'], $user );
                },
            ),
            'timetics/create-customer' => $owner + array(
                'label'       => __( 'Add a customer', 'timetics' ),
                'description' => __( 'Owner only. Saves a customer contact. With the guest account setting on, a new email also gets a login with no password. It sends no email.', 'timetics' ) . ' ' . $confirm,
                'input'       => $object(
                    array( 'first_name', 'last_name', 'email' ),
                    array(
                        'first_name'    => $name,
                        'last_name'     => $name,
                        'email'         => $email,
                        'phone'         => $phone,
                        'confirm_token' => $token,
                    )
                ),
                'run'         => array( self::class, 'create_customer' ),
            ),
            'timetics/update-customer' => $owner + array(
                'label'       => __( 'Change a customer', 'timetics' ),
                'description' => __( 'Owner only. Change name or phone. The email cannot be changed here. Bookings already made keep the contact they were made with.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => $object(
                    array( 'id' ),
                    array(
                        'id'            => $id,
                        'first_name'    => $name,
                        'last_name'     => $name,
                        'phone'         => $phone,
                        'confirm_token' => $token,
                    )
                ),
                'run'         => array( self::class, 'update_customer' ),
            ),
            'timetics/get-staff'      => array(
                'readonly'    => true,
                'label'       => __( 'Get a staff member', 'timetics' ),
                'description' => __( 'A staff member with their meetings and weekly hours in each. Staff see contact details only for themselves.', 'timetics' ),
                'input'       => $object( array( 'id' ), array( 'id' => $id ) ),
                'run'         => function ( $input, $user ) {
                    return self::get_staff( (int) $input['id'], $user );
                },
            ),
            'timetics/update-staff'   => $owner + array(
                'label'       => __( 'Change a staff member', 'timetics' ),
                'description' => __( 'Owner only. Change name or phone. Email and password are changed in the dashboard. For hours use update-working-hours.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => $object(
                    array( 'id' ),
                    array(
                        'id'            => $id,
                        'first_name'    => $name,
                        'last_name'     => $name,
                        'phone'         => $phone,
                        'confirm_token' => $token,
                    )
                ),
                'run'         => array( self::class, 'update_staff' ),
            ),
        );
    }

    /**
     * Customers, newest first: every contact, guests included.
     *
     * @param   array  $input  offset.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error  items, total, offset, next_offset.
     */
    public static function list_customers( array $input, int $user ) {
        if ( ! Mcp_Access::is_owner( $user ) ) {
            return new WP_Error( 'forbidden', __( 'Only the site owner can list every customer. Use find-customer.', 'timetics' ) );
        }

        $offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
        $result = Customer_Store::query(
            array(
                'per_page' => self::ROWS,
                'offset'   => $offset,
            )
        );
        $next   = $offset + count( $result['items'] );

        return array(
            'items'       => self::customer_rows( $result['items'] ),
            'total'       => $result['total'],
            'offset'      => $offset,
            'next_offset' => $next < $result['total'] ? $next : null,
        );
    }

    /**
     * A customer with their latest bookings this user can see.
     *
     * @param   int  $id    Customer contact id.
     * @param   int  $user  User id.
     *
     * @return  array|WP_Error
     */
    public static function get_customer( int $id, int $user ) {
        $row     = self::visible_row( $id );
        $visible = Mcp_Access::is_owner( $user ) || Booking_Reader::is_own_customer( $id, $user );

        // Staff get the same answer for a customer they cannot see as for none.
        if ( ! $row || ! $visible ) {
            return new WP_Error( 'not_found', __( 'No such customer.', 'timetics' ) );
        }

        $args = array(
            'post_type'      => 'timetics-booking',
            'post_status'    => Booking_Reader::STORED_STATUSES,
            'posts_per_page' => self::BOOKINGS,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bookings live in post meta.
            'meta_query'     => Booking_Reader::contact_clause( $row ),
        );
        if ( ! Mcp_Access::is_owner( $user ) ) {
            $own              = Booking::get_visible_ids_for_user( $user );
            $args['post__in'] = $own ? $own : array( 0 );
        }

        return self::customer_rows( array( $row ) )[0] + array(
            'latest_bookings' => Booking_Reader::rows( array_map( 'intval', get_posts( $args ) ), $user ),
        );
    }

    /**
     * Preview or save a new customer.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function create_customer( array $input, int $user ) {
        return Dashboard_Writer::change(
            'timetics/create-customer',
            $input,
            $user,
            array(
                'id'      => 0,
                'read'    => function () {
                    return array();
                },
                'preview' => function ( $change ) {
                    $row = self::visible_row( (int) ( Customer_Store::find_by_email( (string) $change['email'] )['id'] ?? 0 ) );
                    if ( $row ) {
                        /* translators: %d: customer id */
                        return new WP_Error( 'customer_exists', sprintf( __( 'This email is already customer %d.', 'timetics' ), $row['id'] ) );
                    }

                    return array(
                        'will_create' => self::contact( $change ),
                        // The route never changes an existing login.
                        'notice'      => email_exists( (string) $change['email'] ) ? __( 'This email already has a login on the site. The customer is saved as a contact and the login is not changed.', 'timetics' ) : '',
                    );
                },
                'save'    => function ( $change ) {
                    $saved = Dashboard_Writer::route( 'POST', 'customers', self::contact( $change ) );

                    return is_wp_error( $saved ) ? $saved : array( 'customer' => self::customer_rows( array( Customer_Store::get( (int) $saved['id'] ) ) )[0] );
                },
            )
        );
    }

    /**
     * Preview or save a change to a customer's name or phone.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function update_customer( array $input, int $user ) {
        $id = (int) $input['id'];

        // Bookings and logins rely on the email, and a new one could clash with another contact.
        if ( isset( $input['email'] ) ) {
            return new WP_Error( 'email_locked', __( 'A customer\'s email cannot be changed here. A login\'s email can be changed in Users.', 'timetics' ) );
        }

        return Dashboard_Writer::change(
            'timetics/update-customer',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    $row = self::visible_row( $id );

                    return $row ? self::contact_of( $row ) : new WP_Error( 'not_found', __( 'No such customer.', 'timetics' ) );
                },
                'preview' => function ( $change, $state ) {
                    $after = array_merge( $state, self::contact( $change ) );
                    if ( $after === $state ) {
                        return new WP_Error( 'nothing_to_change', __( 'Send at least one field to change.', 'timetics' ) );
                    }

                    return array(
                        'before' => $state,
                        'after'  => $after,
                    );
                },
                'save'    => function ( $change, $state ) use ( $id ) {
                    $saved = Dashboard_Writer::route( 'PUT', 'customer-records/' . $id, array_merge( $state, self::contact( $change ) ) );

                    return is_wp_error( $saved ) ? $saved : array( 'customer' => self::customer_rows( array( Customer_Store::get( $id ) ) )[0] );
                },
            )
        );
    }

    /**
     * A staff member with their meetings and hours.
     *
     * @param   int  $id    Staff id.
     * @param   int  $user  User id.
     *
     * @return  array|WP_Error
     */
    public static function get_staff( int $id, int $user ) {
        $staff = new Staff( $id );
        if ( ! get_userdata( $id ) || ! Meeting_Tools::is_host( $id ) ) {
            return new WP_Error( 'not_found', __( 'No such staff member.', 'timetics' ) );
        }

        $row = array(
            'id'    => $id,
            'name'  => Meeting_Tools::person_name( $id ),
            'is_me' => $id === $user,
        );

        // As list-staff: the dashboard gives contact details to owners only.
        if ( Mcp_Access::is_owner( $user ) || $id === $user ) {
            $row['email'] = (string) get_userdata( $id )->user_email;
            $row['phone'] = (string) $staff->get_phone();
        }

        $row['meetings'] = array();
        foreach ( Booking_Reader::list_meetings( '' )['items'] as $meeting ) {
            if ( in_array( $id, $meeting['staff_ids'], true ) ) {
                $full              = Meeting_Tools::read_meeting( (int) $meeting['id'] );
                $hours             = array_column( $full['staff'], 'weekly_hours', 'id' );
                $row['meetings'][] = array(
                    'id'           => $full['id'],
                    'name'         => $full['name'],
                    'timezone'     => $full['timezone'],
                    'weekly_hours' => $hours[ $id ],
                );
            }
        }

        return $row;
    }

    /**
     * Preview or save a change to a staff member.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function update_staff( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/update-staff',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    $person = get_userdata( $id );
                    $staff  = new Staff( $id );
                    if ( ! $person || ! $staff->is_staff() ) {
                        return new WP_Error( 'not_found', __( 'No such staff member.', 'timetics' ) );
                    }

                    return array(
                        'first_name' => (string) $person->first_name,
                        'last_name'  => (string) $person->last_name,
                        'email'      => (string) $person->user_email,
                        'phone'      => (string) $staff->get_phone(),
                        'image'      => $staff->get_image(),
                        'schedule'   => $staff->get_schedule(),
                    );
                },
                'preview' => function ( $change, $state ) {
                    $after = array_merge( $state, self::contact( $change ) );
                    if ( $after === $state ) {
                        return new WP_Error( 'nothing_to_change', __( 'Send at least one field to change.', 'timetics' ) );
                    }

                    $shown = array(
						'first_name' => true,
						'last_name' => true,
						'phone' => true,
					);

                    return array(
                        'before' => array_intersect_key( $state, $shown ),
                        'after'  => array_intersect_key( $after, $shown ),
                    );
                },
                'save'    => function ( $change, $state ) use ( $id ) {
                    // The route blanks what the body leaves out, and never gets a password here.
                    $saved = Dashboard_Writer::route( 'PUT', 'staffs/' . $id, array_merge( $state, self::contact( $change ) ) );

                    return is_wp_error( $saved ) ? $saved : array( 'staff' => self::get_staff( $id, get_current_user_id() ) );
                },
            )
        );
    }

    /**
     * Customer rows with their counted bookings, in two queries for the page.
     *
     * @param   array  $rows  Contact rows.
     *
     * @return  array
     */
    private static function customer_rows( array $rows ): array {
        $counts = Customer_Store::booking_counts( $rows );

        return array_map(
            function ( $row ) use ( $counts ) {
                return array( 'id' => (int) $row['id'] ) + self::contact_of( $row ) + array( 'booking_count' => $counts[ (int) $row['id'] ] );
            },
            $rows
        );
    }

    /**
     * A contact as the routes take it.
     *
     * @param   array  $row  Contact row.
     *
     * @return  array
     */
    private static function contact_of( array $row ): array {
        return array(
            'first_name' => (string) $row['first_name'],
            'last_name'  => (string) $row['last_name'],
            'email'      => (string) $row['email'],
            'phone'      => (string) $row['phone'],
        );
    }

    /**
     * A contact that is not removed from the Customers list.
     *
     * @param   int  $id  Contact id.
     *
     * @return  array|null
     */
    private static function visible_row( int $id ) {
        $row = $id ? Customer_Store::get( $id ) : null;

        return $row && null === $row['removed_at'] ? $row : null;
    }

    /**
     * The contact fields of a tool input.
     *
     * @param   array  $input  Tool input.
     *
     * @return  array
     */
    private static function contact( array $input ): array {
        return array_map( 'strval', array_intersect_key( $input, array_flip( array( 'first_name', 'last_name', 'email', 'phone' ) ) ) );
    }
}
