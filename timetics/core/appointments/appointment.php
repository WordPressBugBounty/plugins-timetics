<?php
/**
 * Class Apointment
 *
 * @since 1.0.0
 *
 * @package Timetics
 */
namespace Timetics\Core\Appointments;

use DateTime;
use Timetics\Base\PostModel;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Bookings\Booking_Entry;
use Timetics\Core\Staffs\Staff;
use WP_Query;

/**
 * Class Appointments.
 *
 * @since 1.0.0
 */
class Appointment extends PostModel {
    /**
     * Store post type
     *
     * @since 1.0.0
     *
     * @var string $post_type
     */
    public $post_type = 'timetics-appointment';

    /**
     * Store timetics custom taxonomy
     *
     * @since 1.0.0
     *
     * @var string $taxonomy
     */
    protected $taxonomy = 'timetics-meeting-category';

    /**
     * Store id
     *
     * @since 1.0.0
     *
     * @var int $id
     */
    protected $id;

    /**
     * Store post metadata
     *
     * @since 1.0.0
     *
     * @var array $data
     */
    public $data = [
        'name'                    => '',
        'type'                    => '',
        'description'             => '',
        'staff'                   => '',
        'locations'               => '',
        'duration'                => '',
        'schedule'                => '',
        'blocked_schedule'        => '',
        'price'                   => '',
        'buffer_time'             => '',
        'timezone'                => '',
        'availability'            => '',
        'visibility'              => '',
        'capacity'                => '',
        'seat_plan'               => '',
        'duplicate'               => 0,
        'custom_fields'           => [],
        'wc_product_id'           => 0,
        'seat_plan_settings'      => '',
        'fleunt_crm_webhook'      => '',
        'fluent_hook_overwrite'   => '',
        'pabbly_hook_overwrite'   => '',
        'zapier_hook_overwrite'   => '',
        'pabbly_webook'           => '',
        'zapier_webook'           => '',
        'min_notice_time'         => '',
        'notifications'           => [
            'booking_created_email_form'     => '',
            'booking_created_email_title'    => '',
            'booking_created_email_body'     => '',
            'booking_canceled_email_form'    => '',
            'booking_canceled_email_title'   => '',
            'booking_canceled_email_body'    => '',
            'booking_reschedule_email_form'  => '',
            'booking_reschedule_email_title' => '',
            'booking_reschedule_email_body'  => '',
            'booking_reminder_email_form'    => '',
            'booking_reminder_email_title'   => '',
            'booking_reminder_email_body'    => '',
            'booking_reminder_time'          => 30,
        ],
        'permalink'               => '',
        'recurring'               => false,
        'recurring_limit'         => 0,
        'recurring_interval'      => 1,
        'recurring_interval_name' => 'day',
        'guest_enabled' => false,
        'guest_limit' => 1,
    ];

    /**
     * Store meta key prefix
     *
     * @since 1.0.0
     *
     * @var string $prefix
     */
    public $prefix = '_tt_apointment_';

    /**
     * Appointments Constructor
     *
     * @since 1.0.0
     *
     * @param int $appointment Optional. Default 0.
     * @return void
     */
    public function __construct( $appointment = 0 ) {
        if ( $appointment instanceof self ) {
            $this->set_id( $appointment->get_id() );
        } elseif ( ! empty( $appointment->ID ) ) {
            $this->set_id( $appointment->ID );
        } elseif ( is_numeric( $appointment ) && $appointment > 0 ) {
            $this->set_id( $appointment );
        }
    }

    /**
     * Get appoint id.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get appointment slug
     *
     * @return  mixed
     */
    public function  get_slug() {
        $post = get_post( $this->get_id() );

        if ( $post ) {
           return $post->post_name;
        }

        return false;
    }

    /**
     * Get woocommerce product id
     *
     * @return  integer
     */
    public function get_wc_product_id() {
        return $this->get_prop( 'wc_product_id' );
    }

    /**
     * Get name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_name() {
        return $this->get_prop( 'name' );
    }

    /**
     * Get coach
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function get_staff() {
        $staff_ids = $this->get_prop( 'staff' );
        $staffs    = [];

        if ( $staff_ids ) {
            foreach ( $staff_ids as $staff_id ) {
                $staff = new Staff( $staff_id );

                if ( ! $staff->is_staff() ) {
                    continue;
                }

                $staffs[] = $staff->get_data();
            }
        }
        return $staffs;
    }

    /**
     * Get staff ids
     *
     * @return  array
     */
    public function get_staff_ids() {
        return $this->get_prop( 'staff' );
    }

    /**
     * Get duplicate number
     *
     * @return integer
     */
    public function get_duplicate_nuber() {
        return $this->get_prop( 'duplicate' );
    }

    /**
     * Get capacity
     *
     * @return  integer
     */
    public function get_capacity() {
        return $this->get_prop( 'capacity' );
    }

    /**
     * Get location
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_locations() {
        return $this->get_prop( 'locations' );
    }

    /**
     * Get Permalink
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_appointment_permalink() {
        return get_post_permalink( $this->id );
    }

    /**
     * Get duration
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_duration() {
        return $this->get_prop( 'duration' );
    }

    /**
     * Get interval for the duration
     *
     * @return integer
     */
    public function get_interval() {
        $duration = $this->get_duration();
        if ( $duration ) {
            $duration = explode( ' ', $duration );
            $duration = 'hr' === $duration[1] ? intval( $duration[0] ) * 60 * 60 : intval( $duration[0] ) * 60;
        }

        return $duration;
    }

    /**
     * Get schedule
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function get_schedule() {
        return $this->get_prop( 'schedule' );
    }

    /**
     * Get blocked schedule
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function get_blocked_schedule() {
        return $this->get_prop( 'blocked_schedule' );
    }

    /**
     * Get price
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_price() {
        return $this->get_prop( 'price' );
    }

    /**
     * Get type
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_type() {
        return $this->get_prop( 'type' );
    }

    /**
     * Get description
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_description() {
        return $this->get_prop( 'description' );
    }

    public function get_buffer_time() {
        return $this->get_prop( 'buffer_time' );
    }

    public function get_timezone() {
        return $this->get_prop( 'timezone' );
    }

    public function get_availability() {
        return $this->get_prop( 'availability' );
    }

    public function get_visibility() {
        return $this->get_prop( 'visibility' );
    }
    public function get_fleunt_crm_webhook() {
        return $this->get_prop( 'fleunt_crm_webhook' );
    }
    public function get_fluent_hook_overwrite() {
        return $this->get_prop( 'fluent_hook_overwrite' );
    }
    public function get_pabbly_webook() {
        return $this->get_prop( 'pabbly_webook' );
    }
    public function get_pabbly_hook_overwrite() {
        return $this->get_prop( 'pabbly_hook_overwrite' );
    }
    public function get_zapier_webook() {
        return $this->get_prop( 'zapier_webook' );
    }
    public function get_zapier_hook_overwrite() {
        return $this->get_prop( 'zapier_hook_overwrite' );
    }
    public function get_min_notice_time() {
        return $this->get_prop( 'min_notice_time' );
    }
    public function get_guest_enabled() {
        return $this->get_prop( 'guest_enabled' );
    }
    public function get_guest_limit() {
        return $this->get_prop( 'guest_limit' );
    }

    /**
     * Get Custom fields
     *
     * @return  array
     */
    public function get_custom_fields() {
        return $this->get_prop( 'custom_fields' );
    }

    /**
     * Get notifications
     *
     * @return  array
     */
    public function get_notifications() {
        return $this->get_prop( 'notifications' );
    }

    /**
     * Get appointment categories
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function get_categories() {
        $categories = wp_get_post_terms( $this->id, $this->taxonomy );

        if ( is_wp_error( $categories ) ) {
            return [];
        }

        return $categories;
    }

    /**
     * Get category ids
     *
     * @return  array
     */
    public function get_category_ids() {
        $categories = $this->get_categories();

        $ids = [];

        foreach ( $categories as $category ) {
            $ids[] = $category->term_id;
        }

        return $ids;
    }

    /**
     * Get post thumbnail.
     *
     * @param string  $size Image size
     *
     * @return string
     */
    public function get_image( $size = 'post-thumbnail' ) {
        return get_the_post_thumbnail_url( $size );
    }

    /**
     * Get permalink
     *
     * @return string
     */
    public function get_link() {
        return get_post_permalink( $this->id );
    }

    /**
     * Get Seat plan for a meeting
     *
     * @return  array
     */
    public function get_seats() {
        return $this->get_prop( 'seat_plan' );
    }

    /**
     * Get appointment author
     *
     * @return  integer
     */
    public function get_author() {
        $post = get_post( $this->id );

        return $post->post_author;
    }

    /**
     * Get seat plan settings
     *
     * @return  array
     */
    public function get_seat_settings() {
        return $this->get_prop( 'seat_plan_settings' );
    }

    // Recurring options.

    /**
     * Check it is recurring or not
     *
     * @return  bool
     */
    public function is_recurring() {
        return boolval( $this->get_prop( 'recurring' ) );
    }

    /**
     * Get recurring limit
     *
     * @return  integer
     */
    public function get_recurring_limit() {
        return intval( $this->get_prop( 'recurring_limit' ) );
    }

    /**
     * Get recurring interval
     *
     * @return  integer
     */
    public function get_recurring_interval() {
        return intval( $this->get_prop( 'recurring_interval' ) );
    }

    /**
     * Get recurring interval name
     *
     * @return  string
     */
    public function get_recurring_interval_name() {
        return $this->get_prop( 'recurring_interval_name' );
    }

    /**
     * Get appointment data
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    public function get_prop( $prop = '' ) {
        return $this->get_metadata( $prop );
    }

    /**
     * Get metadata
     *
     * @since 1.0.0
     *
     * @param string $prop Optional. Default empty string.
     *
     * @return  mixed
     */
    private function get_metadata( $prop = '' ) {
        $meta_key = $this->prefix . $prop;
        return get_post_meta( $this->id, $meta_key, true );
    }

    /**
     * Set appointment id
     *
     * @since 1.0.0
     *
     * @param int $id
     *
     * @return void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set props
     *
     * @param array  $data Metadata array. Default empty array.
     *
     * @return  void
     */
    public function set_props( $data = [] ) {
        $this->data = $data;
    }

    /**
     * Save appointment
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save() {

        $args = [
            'post_title'  => $this->data['name'],
            'post_type'   => $this->post_type,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];

        if ( ! empty( $this->id ) ) {
            $args['ID'] = $this->id;
        }

        // Insert or Update appointment.
        $appoint_id = wp_insert_post( $args );

        if ( ! is_wp_error( $appoint_id ) ) {
            $this->set_id( $appoint_id );
            $this->save_metadata();
        }
    }

    /**
     * Update appointment meta data.
     *
     * @since 1.0.0
     *
     * @return  void
     */
    private function save_metadata() {
        foreach ( $this->data as $key => $value ) {
            // Prepare meta key.
            $meta_key = $this->prefix . $key;

            // Update appointment meta data.
            update_post_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Update meeting data
     *
     * @param   array  $args meeting data
     *
     * @return  bool
     */
    public function update( $args = [] ) {

        foreach ( $args as $key => $value ) {
            if ( ! array_key_exists( $key, $this->data ) ) {
                continue;
            }

            // Prepare meta key.
            $meta_key = $this->prefix . $key;

            // Update appointment meta data.
            update_post_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Delete appointment
     *
     * @return bool | WP_Error
     */
    public function delete() {
        return wp_delete_post( $this->id, true );
    }

    /**
     * Check the appoint is valid or not
     *
     * @return bool
     */
    public function is_appointment() {
        $post = get_post( $this->id );

        if ( $post && $this->post_type === $post->post_type ) {
            return true;
        }

        return false;
    }

    /**
     * Get all appointments
     *
     * @param array $args Appointments args. Default empty array.
     *
     * @return array
     */
    public static function all( $args = [] ) {
        $defaults = [
            'post_type'      => ( new self )->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'paged'          => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );

        if ( ! empty( $args['staff'] ) ) {
            $args['meta_query'][] = [
                'key'     => '_tt_apointment_staff',
                'value'   => $args['staff'],
                'compare' => 'LIKE',
            ];
        }

        if ( ! empty( $args['visibility'] ) ) {
            $args['meta_query'][] = [
                'key'     => '_tt_apointment_visibility',
                'value'   => $args['visibility'],
                'compare' => 'LIKE',
            ];
        }

        if ( ! empty( $args['type'] ) ) {
            $args['meta_query'][] = [
                'key'     => '_tt_apointment_type',
                'value'   => $args['type'],
                'compare' => 'LIKE',
            ];
        }

        if ( ! empty( $args['category'] ) ) {
            $args['tax_query'][] = [
                'taxonomy'         => 'timetics-meeting-category',
                'terms'            => $args['category'],
                'include_children' => false,
            ];
        }

        $post = new WP_Query( $args );

        return [
            'total' => $post->found_posts,
            'items' => $post->posts,
        ];
    }

    /**
     * Duplicate appointment
     *
     * @since 1.0.0
     *
     * @return  void
     */
    public function duplicate() {
        $this->set_props( [
            'name'                  => $this->get_name(),
            'description'           => $this->get_description(),
            'type'                  => $this->get_type(),
            'locations'             => $this->get_locations(),
            'staff'                 => $this->get_staff_ids(),
            'duplicate'             => intval( $this->get_duplicate_nuber() ) + 1,
            'duration'              => $this->get_duration(),
            'price'                 => $this->get_price(),
            'capacity'              => $this->get_capacity(),
            'schedule'              => $this->get_schedule(),
            'categories'            => $this->get_categories(),
            'timezone'              => $this->get_timezone(),
            'availability'          => $this->get_availability(),
            'visibility'            => $this->get_visibility(),
            'buffer_time'           => $this->get_buffer_time(),
            'notifications'         => $this->get_notifications(),
            'fluent_hook_overwrite' => $this->get_fluent_hook_overwrite(),
            'fleunt_crm_webhook'    => $this->get_fleunt_crm_webhook(),
            'pabbly_hook_overwrite' => $this->get_pabbly_hook_overwrite(),
            'pabbly_webook'         => $this->get_pabbly_webook(),
            'zapier_webook'         => $this->get_zapier_webook(),
            'min_notice_time'       => $this->get_min_notice_time(),
            'zapier_hook_overwrite' => $this->get_zapier_hook_overwrite(),
            'guest_enabled'         => $this->get_guest_enabled(),
            'guest_limit'           => $this->get_guest_limit(),
        ] );

        $this->set_id( 0 );
        $this->save();
    }

    /**
     * Prepare meeting schedule
     *
     * @param   string  $start
     * @param   string  $end
     * @param   integer  $staff_id
     *
     * @return  array
     */
    public function prepare_schedule( $start, $end, $staff_id, $timze_zone = '' ) {
        $start    = new \DateTime( $start );
        $end      = new \DateTime( $end );
        $schedule = [];

        // Prepare schedule for the current meeting
        for ( $date = $start; $date <= $end; $date->modify( '+1 day' ) ) {
            $schedule[] = $this->get_schedule_by_date( $date->format( 'Y-m-d' ), $staff_id, $timze_zone );
        }

        return $schedule;
    }

    /**
     * Get meeting schedule by date
     *
     * @param   string  $date      [$date description]
     * @param   integer  $staff_id  [$staff_id description]
     *
     * @return  array
     */
    private function get_schedule_by_date( $date, $staff_id, $timze_zone = '' ) {
        $day_name         = ucfirst( date( 'D', strtotime( $date ) ) );
        $schedule         = $this->get_schedule();
        $interval         = $this->get_interval();
        $tartget_schedule = [];

        $slots = [];

        if ( ! empty( $schedule[$staff_id] ) ) {
            $tartget_schedule = $schedule[$staff_id][$day_name];
        }

        foreach ( $tartget_schedule as $day ) {
            $start = strtotime( $day['start'] );
            $end   = strtotime( $day['end'] );

            for ( $time = $start; $time <= $end; $time += $interval ) {
                $booked_entry = $this->get_booking_entries( $date, $time, $staff_id );
                $status       = 'available';
                $capacity     = $this->get_capacity();
                $booked       = 0;

                if ( $booked_entry ) {
                    if ( $booked_entry->get_booked() >= $capacity ) {
                        $status = 'unavailable';
                    }

                    $booked = $booked_entry->get_booked();
                }

                $datetime = $this->convert_timezone( $time, $timze_zone );

                $slot = [
                    'status'     => $status,
                    'start_time' => $datetime->format( 'g:ia' ),
                    'booked'     => $booked,
                ];

                $slot = apply_filters( 'timetics_booking_slot', $slot, $this, $booked_entry );

                $slots[] = $slot;
            }
        }

        return [
            'date'   => $date,
            'status' => empty( $slots ) ? 'unavailable' : 'available',
            'slots'  => $slots,
        ];
    }

    /**
     * Get booked entry status
     *
     * @param   string  $date
     * @param   string  $time
     * @param   interger  $staff_id
     *
     * @return  bool
     */
    private function get_booking_entries( $date, $time, $staff_id ) {
        $time            = gmdate( 'h:i a', $time );
        $booking_entries = new Booking_Entry();
        $entries         = $booking_entries->find( [
            'meeting_id' => $this->id,
            'staff_id'   => $staff_id,
            'date'       => $this->convert_timezone( $date, $this->get_timezone())->format('Y-m-d'),
        ] );

        foreach ( $entries as $entry ) {
            $booking      = new Booking( $entry->get_booking_id() );

            if ( $entry->get_start() == $time ) {
                return $entry;
            }
        }

        return false;
    }

    /**
     * Convert timestamp in target timezone
     *
     * @param   integer  $timestamp
     * @param   string  $timze_zone
     *
     * @return  DateTime
     */
    public function convert_timezone( $date, $timze_zone ) {
        $date = is_int( $date ) ? gmdate( 'Y-m-d g:ia', $date ) : $date;

        // Create a DateTime object with the provided timestamp and time zone
        $datetime = new \DateTime( $date, new \DateTimeZone( $this->get_timezone() ) );

        // Convert the time zone
        $datetime->setTimezone( new \DateTimeZone( $timze_zone ) );

        // Get the converted timestamp
        return $datetime;
    }
}
