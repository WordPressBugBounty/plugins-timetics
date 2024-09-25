<?php
/**
 * Booking entry
 *
 * @package Timetics
 */

namespace Timetics\Core\Bookings;

/**
 * Class Booking Entry
 */
class Booking_Entry {

    /**
     * Store post type
     *
     * @var string
     */
    private $post_type = 'timetics-schedule';

    /**
     * Store prefix
     *
     * @var string
     */
    private $prefix = '_timetics_schedule_';

    /**
     * Store entry id
     *
     * @var string
     */
    private $id;

    /**
     * Store entries
     *
     * @var array
     */
    private $entries = [];

    /**
     * Store entry data
     *
     * @var array
     */
    private $data = [
        'meeting_id'   => '',
        'event_id'     => '',
        'staff_id'     => '',
        'speaker_id'   => '',
        'customer_id'  => '',
        'booking_id'   => '',
        'booked'       => '',
        'date'         => '',
        'start'        => '',
        'end'          => '',
        'seats'        => '',
        'google_event' => '',
        'zoom'         => '',
    ];

    /**
     * Constructor for Booking Entry
     *
     * @param   integer | Object  $entry
     *
     * @return void
     */
    public function __construct( $entry = 0 ) {
        if ( $entry instanceof self ) {
            $this->set_id( $entry->get_id() );
        } elseif ( ! empty( $entry->ID ) ) {
            $this->set_id( $entry->ID );
        } elseif ( is_numeric( $entry ) && $entry > 0 ) {
            $this->set_id( $entry );
        }
    }

    /**
     * Get booking entry id
     *
     * @return  integer
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get meeting id
     *
     * @return  integer
     */
    public function get_meeting_id() {
        return $this->get_prop( 'meeting_id' );
    }    
    
    /**
     * Get event id
     *
     * @return  integer
     */
    public function get_event_id() {
        return $this->get_prop( 'event_id' );
    }

    /**
     * Get staff id
     *
     * @return  integer
     */
    public function get_staff_id() {
        return $this->get_prop( 'staff_id' );
    }

    /**
     * Get customer id
     *
     * @return  integer
     */
    public function get_customer_id() {
        return $this->get_prop( 'customer_id' );
    }

    /**
     * Get booking id
     *
     * @return  integer
     */
    public function get_booking_id() {
        return $this->get_prop( 'booking_id' );
    }    

    /**
     * Get booked
     *
     * @return  integer
     */
    public function get_booked() {
        return $this->get_prop( 'booked' );
    }

    /**
     * Get start time
     *
     * @return  string
     */
    public function get_start() {
        return $this->get_prop( 'start' );
    }

    /**
     * Get end time
     *
     * @return  string
     */
    public function get_end() {
        return $this->get_prop( 'end' );
    }

    /**
     * Get date
     *
     * @return  string
     */
    public function get_date() {
        return $this->get_prop( 'date' );
    }

    /**
     * Get google calender event with google meet link
     *
     * @return array
     */
    public function get_google_event() {
        return $this->get_prop( 'google_event' );
    }

    /**
     * Get zoom link
     *
     * @return  array
     */
    public function get_zoom() {
        return $this->get_prop( 'zoom' );
    }

    /**
     * Get seats
     *
     * @return  array
     */
    public function get_seats() {
        return $this->get_prop( 'seats' );
    }

    /**
     * Get entry data
     *
     * @return  array
     */
    public function get_data() {
        return [
            'id'          => $this->get_id(),
            'meeting_id'  => $this->get_meeting_id(),
            'staff_id'    => $this->get_staff_id(),
            'booking_id'  => $this->get_booking_id(),
            'event_id'    => $this->get_event_id(),
            'customer_id' => $this->get_customer_id(),
            'booked'      => $this->get_booked(),
            'date'        => $this->get_date(),
            'start'       => $this->get_start(),
            'end'         => $this->get_end(),
        ];
    }

    /**
     * Get prop
     *
     * @param   string  $name
     *
     * @return  mixed
     */
    public function get_prop( $name ) {
        $key = $this->prefix . $name;

        return get_post_meta( $this->id, $key, true );
    }

    /**
     * Find booking entry
     *
     * @param   array  $args
     *
     * @return array
     */
    public function find( $args = [] ) {
        $data = [
            'post_type'   => $this->post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
        ];

        if ( $args ) {
            $query = [
                'relation' => 'AND',
            ];
            foreach ( $args as $key => $value ) {
                $meta_key   = $this->prefix . $key;
                $meta_query = [
                    'key'     => $meta_key,
                    'value'   => $value,
                    'compare' => '=',
                ];

                $query[] = $meta_query;
            }

            // @codingStandardsIgnoreStart
            $data['meta_query'] = $query;
            // @codingStandardsIgnoreEnd
        }

        $entries = $this->collection( get_posts( $data ) );

        $this->entries = $entries;

        return $entries;
    }

    /**
     * Get all booking entries
     *
     * @return  array
     */
    public function all() {
        $entries = get_posts(
            [
                'post_type'   => $this->post_type,
                'post_status' => 'publish',
                'numberposts' => -1,
            ]
        );

        $this->entries = $entries;
        $entries       = $this->collection( $entries );

        return $entries;
    }

    /**
     * Get first entry from the entries
     *
     * @return  Object | null
     */
    public function first() {
        if ( $this->entries ) {
            return $this->entries[0];
        }

        return null;
    }

    /**
     * Get instance of the current entry
     *
     * @param   mixed  $entry
     *
     * @return Object
     */
    private function get_instance( $entry ) {
        return new self( $entry );
    }

    /**
     * Get collection of object
     *
     * @param   array  $data
     *
     * @return array
     */
    private function collection( $data = [] ) {
        return array_map( [$this, 'get_instance'], $data );
    }

    /**
     * Set entry id
     *
     * @param   integer  $id
     *
     * @return void
     */
    private function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Create booking entry
     *
     * @param   array  $args
     *
     * @return integer
     */
    public function create( $args = [] ) {
        $args = wp_parse_args( $args, $this->data );

        $entry = wp_insert_post(
            [
                'post_title'  => $this->post_type . $args['start'],
                'post_type'   => $this->post_type,
                'post_status' => 'publish',
                'post_author' => 1,
            ]
        );

        if ( ! is_wp_error( $entry ) ) {
            $this->set_id( $entry );
            $this->update_metadata( $args );
        }

        return $entry;
    }

    /**
     * Update booking entry
     *
     * @param   array  $args
     *
     * @return  void
     */
    public function update( $args ) {
        $this->update_metadata( $args );
    }

    /**
     * Update metadata
     *
     * @param   array  $data
     *
     * @return void
     */
    private function update_metadata( $data = [] ) {
        foreach ( $data as $key => $value ) {
            if ( ! array_key_exists( $key, $this->data ) ) {
                continue;
            }

            $meta_key = $this->prefix . $key;

            update_post_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Delete booking
     *
     * @return bool | WP_Error
     */
    public function delete() {
        return wp_delete_post( $this->id, true );
    }
}
