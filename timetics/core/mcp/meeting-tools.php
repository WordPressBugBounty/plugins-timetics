<?php
/**
 * Meeting and category tools
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Staffs\Staff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Meetings, their categories and the hours staff work in them. Writes are owner only
 * and save through Dashboard_Writer, so they save exactly as the dashboard does.
 */
class Meeting_Tools {

    const TAXONOMY = 'timetics-meeting-category';
    const DAYS     = array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' );

    /**
     * Meeting, category and working-hours tools.
     *
     * @return  array  Same shape as Abilities::tools().
     */
    public static function tools(): array {
        $id      = array(
            'type'    => 'integer',
            'minimum' => 1,
        );
        $ids     = array(
            'type'  => 'array',
            'items' => $id,
        );
        $minutes = array(
            'type'    => 'integer',
            'minimum' => 0,
        );
        $period  = array(
            'type'                 => 'object',
            'required'             => array( 'start', 'end' ),
            'additionalProperties' => false,
            'properties'           => array(
                'start' => array( 'type' => 'string' ),
                'end'   => array( 'type' => 'string' ),
            ),
        );
        $hours   = array(
            'type'                 => 'object',
            'additionalProperties' => false,
            'description'          => __( 'Day => list of periods like {"start":"9:00am","end":"5:00pm"}, on the meeting\'s clock. An empty list is a day off.', 'timetics' ),
            'properties'           => array_fill_keys(
                self::DAYS,
                array(
                    'type'  => 'array',
                    'items' => $period,
                )
            ),
        );
        $fields  = array(
            'name'             => array(
                'type'      => 'string',
                'minLength' => 1,
                'maxLength' => 200,
            ),
            'description'      => array(
                'type'      => 'string',
                'maxLength' => 2000,
            ),
            'type'             => array(
                'type' => 'string',
                'enum' => array( 'one-to-one', 'group' ),
            ),
            'duration_minutes' => array(
                'type'    => 'integer',
                'minimum' => 5,
            ),
            'prices'           => array(
                'type'        => 'array',
                'minItems'    => 1,
                'description' => __( 'Ticket names and prices, one for most meetings. quantity is how many people can book that ticket at one time; a group meeting\'s capacity is their total. Send every ticket again when changing one.', 'timetics' ),
                'items'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'price' ),
                    'additionalProperties' => false,
                    'properties'           => array(
                        'name'     => array( 'type' => 'string' ),
                        'price'    => array(
                            'type'    => 'number',
                            'minimum' => 0,
                        ),
                        'quantity' => array(
                            'type'    => 'integer',
                            'minimum' => 1,
                        ),
                    ),
                ),
            ),
            'staff_ids'        => $ids + array( 'minItems' => 1 ),
            'weekly_hours'     => $hours,
            'locations'        => array(
                'type'        => 'array',
                'minItems'    => 1,
                'description' => __( 'Like in-person-meeting with an address, google-meet or zoom. The site checks each staff member connected Google or Zoom.', 'timetics' ),
                'items'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'type' ),
                    'additionalProperties' => false,
                    'properties'           => array(
                        'type'     => array( 'type' => 'string' ),
                        'location' => array( 'type' => 'string' ),
                    ),
                ),
            ),
            'timezone'         => array(
                'type'        => 'string',
                'description' => __( 'Like Asia/Dhaka. The site timezone when left out.', 'timetics' ),
            ),
            'category_ids'     => $ids,
            'published'        => array(
                'type'        => 'boolean',
                'description' => __( 'Shown on the booking page.', 'timetics' ),
            ),
            'buffer_before'    => $minutes,
            'buffer_after'     => $minutes,
            'confirm_token'    => array( 'type' => 'string' ),
        );
        $confirm = __( 'The first call saves nothing and returns a preview and confirm_token: only after the user says yes call again with the same fields and confirm_token.', 'timetics' );
        $owner   = array( 'owner' => true );
        $by_id   = function ( bool $confirmable = false ) use ( $id ) {
            $properties = array( 'id' => $id );
            if ( $confirmable ) {
                $properties['confirm_token'] = array( 'type' => 'string' );
            }

            return array(
                'type'                 => 'object',
                'required'             => array( 'id' ),
                'additionalProperties' => false,
                'properties'           => $properties,
            );
        };
        $category = array(
            'name'          => array(
                'type'      => 'string',
                'minLength' => 1,
                'maxLength' => 200,
            ),
            'meeting_ids'   => $ids + array( 'description' => __( 'Meetings in this category. Replaces the whole list.', 'timetics' ) ),
            'confirm_token' => array( 'type' => 'string' ),
        );

        return array(
            'timetics/get-meeting'          => array(
                'readonly'    => true,
                'label'       => __( 'Get a meeting', 'timetics' ),
                'description' => __( 'One meeting in full: prices, staff with their weekly hours, locations, buffers, categories and whether it is published.', 'timetics' ),
                'input'       => $by_id(),
                'run'         => function ( $input ) {
                    return self::read_meeting( (int) $input['id'] );
                },
            ),
            'timetics/create-meeting'       => $owner + array(
                'label'       => __( 'Create a meeting', 'timetics' ),
                'description' => __( 'Owner only. Add a meeting. weekly_hours is given to every staff member; change one person later with update-working-hours.', 'timetics' ) . ' ' . $confirm,
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'name', 'duration_minutes', 'staff_ids', 'weekly_hours', 'locations' ),
                    'additionalProperties' => false,
                    'properties'           => $fields,
                ),
                'run'         => array( self::class, 'create_meeting' ),
            ),
            'timetics/update-meeting'       => $owner + array(
                'label'       => __( 'Change a meeting', 'timetics' ),
                'description' => __( 'Owner only. Change only the fields given. weekly_hours replaces the hours of every staff member of the meeting. Bookings already made keep their time.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'id' ),
                    'additionalProperties' => false,
                    'properties'           => array( 'id' => $id ) + $fields,
                ),
                'run'         => array( self::class, 'update_meeting' ),
            ),
            'timetics/copy-meeting'         => $owner + array(
                'label'       => __( 'Copy a meeting', 'timetics' ),
                'description' => __( 'Owner only. Make a copy of a meeting, with the same staff, hours and prices. Change the copy with update-meeting.', 'timetics' ) . ' ' . $confirm,
                'input'       => $by_id( true ),
                'run'         => array( self::class, 'copy_meeting' ),
            ),
            'timetics/delete-meeting'       => $owner + array(
                'label'       => __( 'Hide a meeting', 'timetics' ),
                'description' => __( 'Owner only. Takes a meeting off the booking page. Never deletes it: that is done in the dashboard. Refused while upcoming bookings use it.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => $by_id( true ),
                'run'         => array( self::class, 'hide_meeting' ),
            ),
            'timetics/update-working-hours' => $owner + array(
                'label'       => __( 'Change when a staff member works', 'timetics' ),
                'description' => __( 'Owner only. A staff member\'s weekly hours in one meeting, or in all their meetings when meeting_id is left out. A day left out keeps its hours. The preview lists upcoming bookings outside the new hours, which keep their time.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'staff_id', 'weekly_hours' ),
                    'additionalProperties' => false,
                    'properties'           => array(
                        'staff_id'      => $id,
                        'meeting_id'    => $id,
                        'weekly_hours'  => $hours,
                        'confirm_token' => array( 'type' => 'string' ),
                    ),
                ),
                'run'         => array( self::class, 'update_working_hours' ),
            ),
            'timetics/list-categories'      => array(
                'readonly'    => true,
                'label'       => __( 'List meeting categories', 'timetics' ),
                'description' => __( 'Meeting categories with the meetings in each.', 'timetics' ),
                'input'       => array(
                    'type'                 => 'object',
                    'default'              => new \stdClass(),
                    'additionalProperties' => false,
                ),
                'run'         => function () {
                    return self::list_categories();
                },
            ),
            'timetics/create-category'      => $owner + array(
                'label'       => __( 'Create a meeting category', 'timetics' ),
                'description' => __( 'Owner only.', 'timetics' ) . ' ' . $confirm,
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'name' ),
                    'additionalProperties' => false,
                    'properties'           => $category,
                ),
                'run'         => array( self::class, 'create_category' ),
            ),
            'timetics/update-category'      => $owner + array(
                'label'       => __( 'Change a meeting category', 'timetics' ),
                'description' => __( 'Owner only. Rename it, or set which meetings are in it.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'id' ),
                    'additionalProperties' => false,
                    'properties'           => array( 'id' => $id ) + $category,
                ),
                'run'         => array( self::class, 'update_category' ),
            ),
            'timetics/delete-category'      => $owner + array(
                'label'       => __( 'Delete a meeting category', 'timetics' ),
                'description' => __( 'Owner only. The meetings stay, only the category goes.', 'timetics' ) . ' ' . $confirm,
                'destructive' => true,
                'input'       => $by_id( true ),
                'run'         => array( self::class, 'delete_category' ),
            ),
        );
    }

    /**
     * A meeting in full, without webhooks or notification settings.
     *
     * @param   int  $id  Meeting id.
     *
     * @return  array|WP_Error
     */
    public static function read_meeting( int $id ) {
        $meeting = new Appointment( $id );
        if ( ! $id || ! $meeting->is_appointment() ) {
            return new WP_Error( 'not_found', __( 'No such meeting.', 'timetics' ) );
        }

        $schedule = (array) $meeting->get_schedule();
        $staff    = array();
        foreach ( array_map( 'intval', (array) $meeting->get_staff_ids() ) as $person ) {
            $staff[] = array(
                'id'           => $person,
                'name'         => self::person_name( $person ),
                'weekly_hours' => self::hours_of( (array) ( $schedule[ $person ] ?? array() ) ),
            );
        }

        return array(
            'id'               => $id,
            'name'             => (string) $meeting->get_name(),
            'description'      => (string) $meeting->get_description(),
            'type'             => (string) $meeting->get_type(),
            'duration_minutes' => (int) $meeting->get_duration(),
            'prices'           => array_map(
                function ( $ticket ) {
                    return array(
                        'name'     => (string) ( $ticket['ticket_name'] ?? '' ),
                        'price'    => (float) ( $ticket['ticket_price'] ?? 0 ),
                        'quantity' => (int) ( $ticket['ticket_quantity'] ?? 0 ),
                    );
                },
                array_values( array_filter( (array) $meeting->get_price(), 'is_array' ) )
            ),
            'capacity'         => (int) $meeting->get_effective_capacity(),
            'staff'            => $staff,
            'locations'        => array_map(
                function ( $location ) {
                    return array(
                        'type'     => (string) ( $location['location_type'] ?? '' ),
                        'location' => is_scalar( $location['location'] ?? '' ) ? (string) ( $location['location'] ?? '' ) : '',
                    );
                },
                array_values( array_filter( (array) $meeting->get_locations(), 'is_array' ) )
            ),
            'timezone'         => (string) $meeting->get_timezone(),
            'category_ids'     => array_map( 'intval', wp_get_post_terms( $id, self::TAXONOMY, array( 'fields' => 'ids' ) ) ),
            'published'        => 'disabled' !== strtolower( (string) $meeting->get_visibility() ),
            'buffer_before'    => (int) $meeting->get_buffer_time_before_in_seconds() / MINUTE_IN_SECONDS,
            'buffer_after'     => (int) $meeting->get_buffer_time_after_in_seconds() / MINUTE_IN_SECONDS,
        );
    }

    /**
     * Preview or save a new meeting.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function create_meeting( array $input, int $user ) {
        return Dashboard_Writer::change(
            'timetics/create-meeting',
            $input,
            $user,
            array(
                'id'      => 0,
                'read'    => function () {
                    return array();
                },
                'preview' => function ( $change ) {
                    $body = self::meeting_body( $change, array() );

                    return is_wp_error( $body ) ? $body : array( 'will_create' => self::describe( $change ) );
                },
                'save'    => function ( $change ) {
                    $body  = self::meeting_body( $change, array() );
                    $saved = is_wp_error( $body ) ? $body : Dashboard_Writer::route( 'POST', 'appointments', $body );

                    return is_wp_error( $saved ) ? $saved : array( 'meeting' => self::read_meeting( (int) $saved['id'] ) );
                },
            )
        );
    }

    /**
     * Preview or save a change to a meeting.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function update_meeting( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/update-meeting',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    return self::read_meeting( $id );
                },
                'preview' => function ( $change, $state ) use ( $id, $user ) {
                    $fields = array_diff_key( $change, array( 'id' => true ) );
                    if ( ! $fields ) {
                        return new WP_Error( 'nothing_to_change', __( 'Send at least one field to change.', 'timetics' ) );
                    }

                    // Unpublishing here hides the meeting just as delete-meeting does, so the same rule applies.
                    $in_use = isset( $fields['published'] ) && ! $fields['published'] && $state['published'] ? self::refuse_hiding( $id, $user ) : null;
                    if ( $in_use ) {
                        return $in_use;
                    }

                    $body = self::meeting_body( $fields, $state );
                    if ( is_wp_error( $body ) ) {
                        return $body;
                    }

                    return array(
                        'meeting' => $state['name'],
                        'before'  => array_intersect_key( $state, self::describe( $fields ) ),
                        'after'   => self::describe( $fields ),
                        'notice'  => self::staff_without_hours( $fields, $state ),
                    );
                },
                'save'    => function ( $change, $state ) use ( $id ) {
                    $body  = self::meeting_body( array_diff_key( $change, array( 'id' => true ) ), $state );
                    $saved = is_wp_error( $body ) ? $body : Dashboard_Writer::route( 'PUT', 'appointments/' . $id, $body );

                    return is_wp_error( $saved ) ? $saved : array( 'meeting' => self::read_meeting( $id ) );
                },
            )
        );
    }

    /**
     * Preview or make a copy of a meeting.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function copy_meeting( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/copy-meeting',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    return self::read_meeting( $id );
                },
                'preview' => function ( $change, $state ) {
                    return array( 'will_copy' => $state['name'] );
                },
                'save'    => function () use ( $id ) {
                    $copy = Dashboard_Writer::route( 'POST', 'appointments/' . $id . '/duplicate' );

                    return is_wp_error( $copy ) ? $copy : array( 'meeting' => self::read_meeting( (int) $copy['id'] ) );
                },
            )
        );
    }

    /**
     * Preview or take a meeting off the booking page.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function hide_meeting( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/delete-meeting',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    return self::read_meeting( $id );
                },
                'preview' => function ( $change, $state ) use ( $id, $user ) {
                    $in_use = self::refuse_hiding( $id, $user );
                    if ( $in_use ) {
                        return $in_use;
                    }
                    if ( ! $state['published'] ) {
                        return new WP_Error( 'already_hidden', __( 'This meeting is already off the booking page.', 'timetics' ) );
                    }

                    return array(
                        'will_hide' => $state['name'],
                        'notice'    => __( 'The meeting stays in the dashboard and can be shown again there.', 'timetics' ),
                    );
                },
                'save'    => function () use ( $id ) {
                    $saved = Dashboard_Writer::route( 'PUT', 'appointments/' . $id, array( 'visibility' => 'disabled' ) );

                    return is_wp_error( $saved ) ? $saved : array( 'meeting' => self::read_meeting( $id ) );
                },
            )
        );
    }

    /**
     * Preview or save a staff member's weekly hours in their meetings.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function update_working_hours( array $input, int $user ) {
        $staff = (int) $input['staff_id'];

        return Dashboard_Writer::change(
            'timetics/update-working-hours',
            $input,
            $user,
            array(
                'id'      => $staff,
                'read'    => function () use ( $input, $staff ) {
                    $meetings = self::meetings_of( $staff, (int) ( $input['meeting_id'] ?? 0 ) );
                    if ( ! $meetings ) {
                        return new WP_Error( 'not_found', __( 'This staff member is not in that meeting, or in any meeting.', 'timetics' ) );
                    }

                    $state = array();
                    foreach ( $meetings as $meeting ) {
                        $schedule                          = (array) $meeting->get_schedule();
                        $state[ (int) $meeting->get_id() ] = self::hours_of( (array) ( $schedule[ $staff ] ?? array() ) );
                    }

                    return $state;
                },
                'preview' => function ( $change, $state ) use ( $staff, $user ) {
                    $new = self::clean_hours( (array) $change['weekly_hours'] );
                    if ( is_wp_error( $new ) ) {
                        return $new;
                    }

                    $meetings = array();
                    foreach ( $state as $meeting => $hours ) {
                        $after    = array_merge( $hours, $new );
                        $upcoming = self::upcoming(
                            array(
                                'meeting_id' => $meeting,
                                'staff_id'   => $staff,
                            ),
                            $user
                        );
                        if ( is_wp_error( $upcoming ) ) {
                            return $upcoming;
                        }
                        $outside    = array_values(
                            array_filter(
                                $upcoming,
                                function ( $row ) use ( $after ) {
                                    return ! self::within( $after, $row['date'], $row['time'] );
                                }
                            )
                        );
                        $meetings[] = array(
                            'meeting'                 => get_the_title( $meeting ),
                            'before'                  => $hours,
                            'after'                   => $after,
                            'bookings_outside_hours'  => $outside,
                        );
                    }

                    return array(
                        'staff'    => self::person_name( $staff ),
                        'meetings' => $meetings,
                    );
                },
                'save'    => function ( $change, $state ) use ( $staff ) {
                    $new = self::clean_hours( (array) $change['weekly_hours'] );

                    foreach ( $state as $meeting => $hours ) {
                        $schedule           = (array) ( new Appointment( $meeting ) )->get_schedule();
                        $schedule[ $staff ] = self::stored_hours( array_merge( $hours, $new ) );
                        $saved              = Dashboard_Writer::route( 'PUT', 'appointments/' . $meeting, array( 'schedule' => $schedule ) );
                        if ( is_wp_error( $saved ) ) {
                            return $saved;
                        }
                    }

                    return array(
                        'meetings' => array_map(
                            function ( $meeting ) {
                                return self::read_meeting( (int) $meeting );
                            },
                            array_keys( $state )
                        ),
                    );
                },
            )
        );
    }

    /**
     * Categories with their meetings.
     *
     * @return  array  items.
     */
    public static function list_categories(): array {
        $terms = get_terms(
            array(
                'taxonomy'   => self::TAXONOMY,
                'hide_empty' => false,
            )
        );

        return array(
            'items' => is_wp_error( $terms ) ? array() : array_map( array( self::class, 'category_row' ), $terms ),
        );
    }

    /**
     * Preview or save a new category.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function create_category( array $input, int $user ) {
        return Dashboard_Writer::change(
            'timetics/create-category',
            $input,
            $user,
            array(
                'id'      => 0,
                'read'    => function () {
                    return array();
                },
                'preview' => function ( $change ) {
                    $missing = self::missing_meetings( (array) ( $change['meeting_ids'] ?? array() ) );

                    return $missing ? $missing : array(
                        'will_create' => array(
                            'name'     => $change['name'],
                            'meetings' => self::meeting_names( (array) ( $change['meeting_ids'] ?? array() ) ),
                        ),
                    );
                },
                'save'    => function ( $change ) {
                    $saved = Dashboard_Writer::route(
                        'POST',
                        'appointments/categories',
                        array(
                            'category_name' => $change['name'],
                            'meeting_ids'   => array_map( 'intval', (array) ( $change['meeting_ids'] ?? array() ) ),
                        )
                    );

                    return is_wp_error( $saved ) ? $saved : array( 'category' => self::category_row( get_term( (int) $saved['term_id'], self::TAXONOMY ) ) );
                },
            )
        );
    }

    /**
     * Preview or save a change to a category.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function update_category( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/update-category',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    return self::read_category( $id );
                },
                'preview' => function ( $change, $state ) {
                    if ( ! isset( $change['name'] ) && ! isset( $change['meeting_ids'] ) ) {
                        return new WP_Error( 'nothing_to_change', __( 'Send a new name or meeting_ids.', 'timetics' ) );
                    }

                    $missing = self::missing_meetings( (array) ( $change['meeting_ids'] ?? array() ) );

                    return $missing ? $missing : array(
                        'before' => $state,
                        'after'  => array(
                            'name'     => $change['name'] ?? $state['name'],
                            'meetings' => isset( $change['meeting_ids'] ) ? self::meeting_names( (array) $change['meeting_ids'] ) : $state['meetings'],
                        ),
                    );
                },
                'save'    => function ( $change, $state ) use ( $id ) {
                    // The route empties a category's meetings when the body leaves them out.
                    $saved = Dashboard_Writer::route(
                        'PUT',
                        'appointments/categories/' . $id,
                        array(
                            'category_name' => $change['name'] ?? $state['name'],
                            'meeting_ids'   => array_map( 'intval', (array) ( $change['meeting_ids'] ?? $state['meeting_ids'] ) ),
                        )
                    );

                    return is_wp_error( $saved ) ? $saved : array( 'category' => self::read_category( $id ) );
                },
            )
        );
    }

    /**
     * Preview or delete a category. Its meetings stay.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function delete_category( array $input, int $user ) {
        $id = (int) $input['id'];

        return Dashboard_Writer::change(
            'timetics/delete-category',
            $input,
            $user,
            array(
                'id'      => $id,
                'read'    => function () use ( $id ) {
                    return self::read_category( $id );
                },
                'preview' => function ( $change, $state ) {
                    return array(
                        'will_delete' => $state,
                        'notice'      => __( 'The meetings in it stay.', 'timetics' ),
                    );
                },
                'save'    => function () use ( $id ) {
                    $done = Dashboard_Writer::route( 'DELETE', 'appointments/categories/' . $id );

                    return is_wp_error( $done ) ? $done : array( 'deleted' => $id );
                },
            )
        );
    }

    /**
     * The error for taking a meeting off the booking page while bookings still use it.
     *
     * @param   int  $id    Meeting id.
     * @param   int  $user  User id.
     *
     * @return  WP_Error|null
     */
    private static function refuse_hiding( int $id, int $user ) {
        $upcoming = self::upcoming( array( 'meeting_id' => $id ), $user );
        if ( is_wp_error( $upcoming ) ) {
            return $upcoming;
        }

        $count = count( $upcoming );
        if ( ! $count ) {
            return null;
        }

        /* translators: %d: number of bookings */
        return new WP_Error( 'has_bookings', sprintf( _n( '%d upcoming booking uses this meeting. Move or cancel it first.', '%d upcoming bookings use this meeting. Move or cancel them first.', $count, 'timetics' ), $count ) );
    }

    /**
     * Every upcoming pending and approved booking, as list rows. The guards count these,
     * so a list the reader could not finish is an error, never a short list.
     *
     * @param   array  $filters  meeting_id, staff_id.
     * @param   int    $user     User id.
     *
     * @return  array[]|WP_Error
     */
    public static function upcoming( array $filters, int $user ) {
        $rows = array();

        foreach ( array( 'pending', 'approved' ) as $status ) {
            $offset = 0;
            do {
                $found = Booking_Reader::list(
                    $filters + array(
                        'date_from' => wp_date( 'Y-m-d' ),
                        'status'    => $status,
                        'offset'    => $offset,
                    ),
                    $user
                );
                if ( is_wp_error( $found ) ) {
                    return $found;
                }
                if ( $found['limited'] ) {
                    return new WP_Error( 'too_many_bookings', __( 'This meeting has too many upcoming bookings to check here. Make this change in the dashboard.', 'timetics' ) );
                }

                $rows   = array_merge( $rows, $found['items'] );
                $offset = $found['next_offset'];
            } while ( null !== $offset );
        }

        return $rows;
    }

    /**
     * The route body for the fields given. The update route keeps what is left out.
     *
     * @param   array  $fields  Tool fields.
     * @param   array  $state   The meeting now, array() when creating.
     *
     * @return  array|WP_Error
     */
    private static function meeting_body( array $fields, array $state ) {
        // The route refuses a new meeting without a type.
        $body = $state ? array() : array( 'type' => 'one-to-one' );

        foreach ( array( 'name', 'description', 'type' ) as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $body[ $key ] = (string) $fields[ $key ];
            }
        }
        if ( isset( $fields['duration_minutes'] ) ) {
            $body['duration'] = (int) $fields['duration_minutes'] . ' min';
        }
        if ( isset( $fields['prices'] ) ) {
            $body['price'] = array_map(
                function ( $price ) {
                    // The route sets capacity to the total of these, as the dashboard sends them.
                    return array(
                        'ticket_name'     => (string) ( $price['name'] ?? __( 'Default', 'timetics' ) ),
                        'ticket_price'    => (float) $price['price'],
                        'ticket_quantity' => (string) (int) ( $price['quantity'] ?? 1 ),
                    );
                },
                (array) $fields['prices']
            );
        }
        if ( isset( $fields['locations'] ) ) {
            $body['locations'] = array_map(
                function ( $location ) {
                    return array(
                        'location_type' => (string) $location['type'],
                        'location'      => (string) ( $location['location'] ?? '' ),
                    );
                },
                (array) $fields['locations']
            );
        }
        if ( isset( $fields['category_ids'] ) ) {
            $body['categories'] = array_map( 'intval', (array) $fields['category_ids'] );
        }
        if ( isset( $fields['published'] ) ) {
            $body['visibility'] = $fields['published'] ? 'enabled' : 'disabled';
        }
        foreach ( array( 'buffer_before', 'buffer_after' ) as $key ) {
            if ( isset( $fields[ $key ] ) ) {
                $body[ 'buffer_time_' . substr( $key, 7 ) . '_value' ] = (int) $fields[ $key ];
                $body[ 'buffer_time_' . substr( $key, 7 ) . '_unit' ]  = 'min';
            }
        }

        $timezone = (string) ( $fields['timezone'] ?? ( $state['timezone'] ?? '' ) );
        if ( '' === $timezone ) {
            $timezone = wp_timezone_string();
        }
        if ( ! timetics_is_valid_timezone( $timezone ) ) {
            return new WP_Error( 'invalid_timezone', __( 'Use a timezone like Asia/Dhaka.', 'timetics' ) );
        }
        $body['timezone'] = $timezone;

        $staff = array_map( 'intval', (array) ( $fields['staff_ids'] ?? array_column( $state['staff'] ?? array(), 'id' ) ) );
        foreach ( $staff as $person ) {
            if ( ! self::is_host( $person ) ) {
                /* translators: %d: user id */
                return new WP_Error( 'not_staff', sprintf( __( 'User %d is not a staff member.', 'timetics' ), $person ) );
            }
        }
        if ( isset( $fields['staff_ids'] ) ) {
            $body['staff'] = $staff;
        }

        if ( isset( $fields['weekly_hours'] ) || isset( $fields['staff_ids'] ) ) {
            $hours = isset( $fields['weekly_hours'] ) ? self::clean_hours( (array) $fields['weekly_hours'] ) : array();
            if ( is_wp_error( $hours ) ) {
                return $hours;
            }

            $kept = array_column( $state['staff'] ?? array(), 'weekly_hours', 'id' );
            foreach ( $staff as $person ) {
                $body['schedule'][ $person ] = self::stored_hours( isset( $fields['weekly_hours'] ) ? $hours : (array) ( $kept[ $person ] ?? array() ) );
            }
        }

        if ( isset( $body['categories'] ) && self::missing_terms( $body['categories'] ) ) {
            return new WP_Error( 'not_found', __( 'A category given does not exist.', 'timetics' ) );
        }

        return $body;
    }

    /**
     * Fields as the preview shows them, in the tool's own words.
     *
     * @param   array  $fields  Tool fields.
     *
     * @return  array
     */
    private static function describe( array $fields ): array {
        $shown = array_diff_key(
            $fields, array(
				'confirm_token' => true,
				'id' => true,
            )
        );

        if ( isset( $shown['staff_ids'] ) ) {
            $shown['staff'] = array_map( array( self::class, 'person_name' ), array_map( 'intval', $shown['staff_ids'] ) );
            unset( $shown['staff_ids'] );
        }

        return $shown;
    }

    /**
     * A warning for staff added without hours, who would offer no times.
     *
     * @param   array  $fields  Tool fields.
     * @param   array  $state   The meeting now.
     *
     * @return  string
     */
    private static function staff_without_hours( array $fields, array $state ): string {
        if ( empty( $fields['staff_ids'] ) || isset( $fields['weekly_hours'] ) ) {
            return '';
        }

        $new = array_diff( array_map( 'intval', $fields['staff_ids'] ), array_column( $state['staff'], 'id' ) );
        if ( ! $new ) {
            return '';
        }

        /* translators: %s: staff names */
        return sprintf( __( '%s will have no hours in this meeting, so no free times. Set them with update-working-hours.', 'timetics' ), implode( ', ', array_map( array( self::class, 'person_name' ), $new ) ) );
    }

    /**
     * Check and tidy weekly hours from the AI.
     *
     * @param   array  $hours  Day => periods.
     *
     * @return  array|WP_Error  Day => periods with times like 9:00am.
     */
    private static function clean_hours( array $hours ) {
        $clean = array();

        foreach ( $hours as $day => $periods ) {
            if ( ! in_array( $day, self::DAYS, true ) ) {
                /* translators: %s: day name */
                return new WP_Error( 'invalid_hours', sprintf( __( 'Unknown day %s. Use Sun, Mon, Tue, Wed, Thu, Fri, Sat.', 'timetics' ), $day ) );
            }

            $clean[ $day ] = array();
            foreach ( (array) $periods as $period ) {
                $start = strtotime( '2000-01-01 ' . ( $period['start'] ?? '' ) . ' UTC' );
                $end   = strtotime( '2000-01-01 ' . ( $period['end'] ?? '' ) . ' UTC' );
                if ( false === $start || false === $end || $start >= $end ) {
                    return new WP_Error( 'invalid_hours', __( 'Each period needs a start before its end, like 9:00am to 5:00pm.', 'timetics' ) );
                }

                $clean[ $day ][] = array(
                    'start' => gmdate( 'g:ia', $start ),
                    'end'   => gmdate( 'g:ia', $end ),
                );
            }
        }

        return $clean;
    }

    /**
     * Hours as the dashboard stores them: days off are left out.
     *
     * @param   array  $hours  Day => periods.
     *
     * @return  array
     */
    private static function stored_hours( array $hours ): array {
        return array_filter( $hours );
    }

    /**
     * Stored hours with every day named, so a day off shows as an empty list.
     *
     * @param   array  $stored  Day => periods.
     *
     * @return  array
     */
    private static function hours_of( array $stored ): array {
        $hours = array();

        foreach ( self::DAYS as $day ) {
            $hours[ $day ] = array_values(
                array_map(
                    function ( $period ) {
                        return array(
                            'start' => (string) ( $period['start'] ?? '' ),
                            'end'   => (string) ( $period['end'] ?? '' ),
                        );
                    },
                    array_filter( (array) ( $stored[ $day ] ?? array() ), 'is_array' )
                )
            );
        }

        return $hours;
    }

    /**
     * Whether a booking's time falls inside weekly hours.
     *
     * @param   array   $hours  Day => periods.
     * @param   string  $date   Y-m-d.
     * @param   string  $time   Like 3:00pm.
     *
     * @return  bool
     */
    private static function within( array $hours, string $date, string $time ): bool {
        $at = strtotime( '2000-01-01 ' . $time . ' UTC' );

        foreach ( (array) ( $hours[ gmdate( 'D', strtotime( $date . ' UTC' ) ) ] ?? array() ) as $period ) {
            if ( $at >= strtotime( '2000-01-01 ' . $period['start'] . ' UTC' ) && $at < strtotime( '2000-01-01 ' . $period['end'] . ' UTC' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meetings a staff member is in.
     *
     * @param   int  $staff    User id.
     * @param   int  $meeting  One meeting, or 0 for all.
     *
     * @return  Appointment[]
     */
    private static function meetings_of( int $staff, int $meeting ): array {
        $ids = $meeting ? array( $meeting ) : array_map(
            function ( $post ) {
                return (int) $post->ID;
            },
            Appointment::all( array( 'posts_per_page' => -1 ) )['items']
        );
        $in  = array();

        foreach ( $ids as $id ) {
            $found = new Appointment( $id );
            if ( $found->is_appointment() && in_array( $staff, array_map( 'intval', (array) $found->get_staff_ids() ), true ) ) {
                $in[] = $found;
            }
        }

        return $in;
    }

    /**
     * A category with its meetings.
     *
     * @param   int  $id  Term id.
     *
     * @return  array|WP_Error
     */
    private static function read_category( int $id ) {
        $term = get_term( $id, self::TAXONOMY );

        return $term instanceof \WP_Term ? self::category_row( $term ) : new WP_Error( 'not_found', __( 'No such category.', 'timetics' ) );
    }

    /**
     * A category row.
     *
     * @param   \WP_Term  $term  Term.
     *
     * @return  array
     */
    private static function category_row( $term ): array {
        $ids = array_map(
            'intval',
            get_posts(
                array(
                    'post_type'      => 'timetics-appointment',
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Categories are terms.
                    'tax_query'      => array(
                        array(
                            'taxonomy' => self::TAXONOMY,
                            'terms'    => $term->term_id,
                        ),
                    ),
                )
            )
        );

        return array(
            'id'          => (int) $term->term_id,
            'name'        => (string) $term->name,
            'meeting_ids' => $ids,
            'meetings'    => self::meeting_names( $ids ),
        );
    }

    /**
     * An error when a meeting id does not exist.
     *
     * @param   int[]  $ids  Meeting ids.
     *
     * @return  WP_Error|null
     */
    private static function missing_meetings( array $ids ) {
        foreach ( $ids as $id ) {
            if ( ! ( new Appointment( (int) $id ) )->is_appointment() ) {
                /* translators: %d: meeting id */
                return new WP_Error( 'not_found', sprintf( __( 'No meeting %d.', 'timetics' ), $id ) );
            }
        }

        return null;
    }

    /**
     * Whether any category id does not exist.
     *
     * @param   int[]  $ids  Term ids.
     *
     * @return  bool
     */
    private static function missing_terms( array $ids ): bool {
        foreach ( $ids as $id ) {
            if ( ! get_term( $id, self::TAXONOMY ) instanceof \WP_Term ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Meeting names.
     *
     * @param   int[]  $ids  Meeting ids.
     *
     * @return  string[]
     */
    private static function meeting_names( array $ids ): array {
        return array_map(
            function ( $id ) {
                return (string) get_the_title( (int) $id );
            },
            array_values( $ids )
        );
    }

    /**
     * Whether a user can host meetings: staff, or an owner who hosts their own.
     *
     * @param   int  $id  User id.
     *
     * @return  bool
     */
    public static function is_host( int $id ): bool {
        return ( new Staff( $id ) )->is_staff() || Mcp_Access::is_owner( $id );
    }

    /**
     * A staff member's name.
     *
     * @param   int  $id  User id.
     *
     * @return  string
     */
    public static function person_name( int $id ): string {
        $person = get_userdata( $id );
        if ( ! $person ) {
            return '';
        }

        $name = trim( $person->first_name . ' ' . $person->last_name );

        return '' !== $name ? $name : (string) $person->display_name;
    }
}
