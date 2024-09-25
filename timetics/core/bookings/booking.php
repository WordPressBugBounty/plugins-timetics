<?php
/**
 * Booking Class
 *
 * @package Timetics
 */

namespace Timetics\Core\Bookings;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Customers\Customer;
use Timetics\Core\Integrations\Google\Service\Calendar;
use Timetics\Core\Staffs\Staff;
use TimeticsEvent\Core\Event_Bookings\Event_Booking;
use TimeticsEvent\Core\Events\Event;
use WP_Query;

/**
 * Class Booking
 */
class Booking {
    /**
     * Store booking post type
     *
     * @var string
     */
    protected $pos_type = 'timetics-booking';

    /**
     * Store booking meta prefix
     *
     * @var string
     */
    protected $meta_prefix = '_tt_booking_';

    /**
     * Store booking id
     *
     * @var string
     */
    protected $id;

    /**
     * Store appointment object
     *
     * @var Object
     */
    protected $appointment;

    /**
     * Store appointment object
     *
     * @var Object
     */
    protected $event;

    /**
     * Store booking data
     *
     * @var array
     */
    protected $data = [

        'post_status'           => '',
        'customer'              => '',
        'appointment'           => '',
        'appointment_name'      => '',
        'staff'                 => '',
        'speaker'               => '',
        'event'                 => '',
        'location'              => '',
        'description'           => '',
        'location_type'         => '',
        'payment_method'        => '',
        'payment_status'        => '',
        'payment_details'       => '',
        'order_total'           => '',
        'start_date'            => '',
        'end_date'              => '',
        'start_time'            => '',
        'end_time'              => '',
        'calendar_event'        => '',
        'random_id'             => '',
        'seats'                 => '',
        'guests'                => '',
        'zoom'                  => '',
        'custom_form_data'      => '',
        'timezone'              => '',
        'recurrence'            => [],
        'parent'                => 0,
        'cancel_reason'         => '',
        'custom_location_url'   => '',
    ];

    /**
     * Booking Constructor
     *
     * @return  void
     */
    public function __construct( $booking = 0 ) {
        if ( $booking instanceof self ) {
            $this->set_id( $booking->get_id() );
        } elseif ( ! empty( $booking->ID ) ) {
            $this->set_id( $booking->ID );
        } elseif ( is_numeric( $booking ) && $booking > 0 ) {
            $this->set_id( $booking );
        }
    }

    /**
     * Get id
     *
     * @return  integer
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get customer id
     *
     * @return integer
     */
    public function get_customer_id() {
        return $this->get_prop( 'customer' );
    }    
    
    /**
     * Get attendee id
     *
     * @return integer
     */
    public function get_attendees() {
        $attendees = $this->get_prop( 'attendees' );
        return $attendees ? $attendees : [];
    }    
    
    /**
     * Get custom url
     *
     * @return string
     */
    public function get_custom_location_url() {
        $custom_url = $this->get_prop( 'custom_location_url' );
        return $custom_url ? $custom_url : '';
    }

    /**
     * Get staff for the current booking
     *
     * @return integer
     */
    public function get_staff_id() {
        return $this->get_prop( 'staff' );
    }

    /**
     * Get speaker id
     *
     * @return  integer
     */
    public function get_speaker_id() {
        return $this->get_prop( 'speaker' );
    }    
    
    /**
     * Get booking type
     *
     * @return  string
     */
    public function get_type() {
        return $this->get_prop( 'type' );
    }

    /**
     * Get event id
     *
     * @return  integer
     */
    public function get_event_id() {
        return $this->get_prop( 'event' );
    }    

    /**
     * Get customer
     *
     * @return  User Object
     */
    public function get_customer() {
        return get_userdata( $this->get_customer_id() );
    }

    /**
     * Get seats of a booking
     *
     * @return  array
     */
    public function get_seat() {
        return $this->get_prop( 'seats' );
    }

    /**
     * Get guests
     *
     * @return  array
     */
    public function get_guests() {
        return $this->get_prop( 'guests' );
    }

    /**
     * Get custom fields
     *
     * @return  array
     */
    public function get_custom_fields() {
        return $this->get_prop( 'custom_form_data' );
    }

    /**
     * Get billing first name
     *
     * @return  string
     */
    public function get_billing_first_name() {
        return $this->get_prop( 'first_name' );
    }

    /**
     * Get location
     *
     * @return string
     */
    public function get_location() {
        return $this->get_prop( 'location' );
    }

    /**
     * Get location type
     *
     * @return string
     */
    public function get_location_type() {
        return $this->get_prop( 'location_type' );
    }    
    
    /**
     * Get booking time
     *
     * @return string
     */
    public function get_booking_time() {
        return $this->get_prop( 'booking_time' );
    }

    /**
     * Get last name
     *
     * @return  string
     */
    public function get_billing_last_name() {
        return $this->get_prop( 'last_name' );
    }

    /**
     * Get email
     *
     * @return  string
     */
    public function get_billing_email() {
        return $this->get_prop( 'email' );
    }

    /**
     * Get billing phone
     *
     * @return  string
     */
    public function get_billing_phone() {
        return $this->get_prop( 'phone' );
    }

    /**
     * Get timezone
     *
     * @return  string
     */
    public function get_timezone() {
        return $this->get_prop( 'timezone' );
    }

    /**
     * Get description
     *
     * @return string
     */
    public function get_description() {
        return $this->get_prop( 'description' );
    }

    /**
     * Get billing address
     *
     * @param   string  $adress_type  Booking billing address
     *
     * @return  string
     */
    public function get_billing_address( $adress_type = 'address_1' ) {
        return $this->get_prop( $adress_type );
    }

    /**
     * Get billing city
     *
     * @return string
     */
    public function get_billing_city() {
        return $this->get_prop( 'city' );
    }

    /**
     * Get billing state
     *
     * @return  string
     */
    public function get_billing_state() {
        return $this->get_prop( 'state' );
    }

    /**
     * Get billing post code
     *
     * @return  string
     */
    public function get_billing_post_code() {
        return $this->get_prop( 'post_code' );
    }

    /**
     * Get billing
     *
     * @return  string
     */
    public function get_billing_country() {
        return $this->get_prop( 'country' );
    }

    /**
     * Get payment method
     *
     * @return  string
     */
    public function get_payment_method() {
        return $this->get_prop( 'payment_method' );
    }

    /**
     * Get appointment
     *
     * @return Appointment
     */
    public function get_appointment() {
        return $this->get_prop( 'appointment' );
    }

    /**
     * Get appointment name
     *
     * @return  string
     */
    public function get_appointment_name() {
        return $this->get_prop( 'appointment_name' );
    }

    /**
     * Get calendar event
     *
     * @return array
     */
    public function get_event() {
        return $this->get_prop( 'calendar_event' );
    }

    /**
     * Get post status
     *
     * @return  string
     */
    public function get_status() {
        return get_post( $this->id )->post_status;
    }

    /**
     * Get total
     *
     * @return  integer
     */
    public function get_total() {
        return $this->get_prop( 'order_total' );
    }

    /**
     * Get start date
     *
     * @return  string
     */
    public function get_start_date() {
        return $this->get_prop( 'start_date' );
    }
    /**
     * Get start date
     *
     * @return  string
     */
    public function get_date() {
        return $this->get_prop( 'date' );
    }

    /**
     * Get end date
     *
     * @return  string
     */
    public function get_end_date() {
        return $this->get_prop( 'end_date' );
    }

    /**
     * Get start time
     *
     * @return  string
     */
    public function get_start_time() {
        return $this->get_prop( 'start_time' );
    }

    /**
     * Get end time
     *
     * @return  string
     */
    public function get_end_time() {
        return $this->get_prop( 'end_time' );
    }

    /**
     * Get random id for the current booking
     *
     * @return  string
     */
    public function get_random_id() {
        return $this->get_prop( 'random_id' );
    }

    /**
     * Get payment details
     *
     * @return  array
     */
    public function get_payment_details() {
        return $this->get_prop( 'payment_details' );
    }

    /**
     * Get zoom meeting details
     *
     * @return  array
     */
    public function get_zoom() {
        return $this->get_prop( 'zoom' );
    }

    /**
     * Get recurrences
     *
     * @return  array
     */
    public function get_recurrence() {
        return $this->get_prop( 'recurrence' );
    }

    /**
     * Get parent
     *
     * @return  integer
     */
    public function get_parent() {
        return $this->get_prop( 'parent' );
    }

    /**
     * Get booking cancel reason
     *
     * @return  integer
     */
    public function get_cancel_reason() {
        return $this->get_prop( 'cancel_reason' );
    }

    /**
     * Get booking data
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    private function get_prop( $prop = '' ) {
        return $this->get_metadata( $prop );
    }

    /**
     * Get metadata
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    private function get_metadata( $prop = '' ) {
        $meta_key = $this->meta_prefix . $prop;

        return get_post_meta( $this->id, $meta_key, true );
    }

    /**
     * Generate random id
     *
     * @return  string
     */
    private function generate_randon_id() {
        return chr( rand( ord( 'A' ), ord( 'Z' ) ) ) . rand( 575639, 575639 + 400 );
    }

    // Setter.

    /**
     * Set booking id
     *
     * @param   integer  $id
     *
     * @return  void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set data
     *
     * @param   array  $args  Booking Args
     *
     * @return void
     */
    public function set_props( $args = [] ) {
        $this->data        = wp_parse_args( $args, $this->data );
        $this->appointment = new Appointment( $this->data['appointment'] );
        if ( isset( $this->data['type'] ) && 'timetics-event' ==  $this->data['type'] ) {
        $this->event       = new Event( $this->data['event'] );
        }

    }

    /**
     * Save metada for booking
     *
     * @return  void
     */
    public function save_metadata( $args = [] ) {
        foreach ( $args as $key => $value ) {

            // If a key not exists on data it will skip to save the data.
            if ( ! array_key_exists( $key, $this->data ) ) {
                continue;
            }

            // Prepare meta key.
            $meta_key = $this->meta_prefix . $key;

            if ( ! $value ) {
                $value = $this->get_prop( $key );
            }

            // Update appointment meta data.
            update_post_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Save appointment
     *
     * @return void
     */
    public function save( $type = '' ) {
        $args = [
            'post_title'  => $this->appointment->get_name(),
            'post_type'   => $this->pos_type,
            'post_status' => $this->data['post_status'],
            'post_author' => get_current_user_id(),
        ];

        if ( 'timetics-event' == $type ) {
            $args = [
                'post_title'  => $this->event->get_name(),
                'post_type'   => $this->pos_type,
                'post_status' => $this->data['post_status'],
                'post_author' => get_current_user_id(),
            ];
        }

        $updated = false;

        if ( ! empty( $this->id ) ) {
            $this->send_notification( 'update' );
            $args['ID'] = $this->id;
            $updated    = true;
        }

        // Insert or Update appointment.
        $booking_id              = wp_insert_post( $args );
        $this->data['random_id'] = $this->generate_randon_id();
        $this->data = apply_filters( 'timetics/event/booking/all_data', $this->data);

        if ( ! is_wp_error( $booking_id ) ) {
            $this->set_id( $booking_id );
            $this->save_metadata( $this->data );
        }

        if ( ! $updated ) {
            $this->send_notification( 'create' );
        }
    }

    /**
     * Update booking
     *
     * @param   array  $args
     *
     * @return  bool
     */
    public function update( $args = [] ) {
        $post = get_post( $this->id )->to_array();
        $args = wp_parse_args( $args, $post );

        $updated = wp_update_post( $args );

        if ( ! is_wp_error( $updated ) ) {
            $this->save_metadata( $args );
        }

        return $updated;
    }

    /**
     * Delete booking
     *
     * @return bool | WP_Error
     */
    public function delete() {
        // Set action for sending notification.
        $this->send_notification( 'delete' );

        return wp_delete_post( $this->id, true );
    }

    /**
     * Check is a booking or not
     *
     * @return  bool
     */
    public function is_booking() {
        $post = get_post( $this->id );

        if ( $post && $this->pos_type === $post->post_type ) {
            return true;
        }

        return false;
    }

    /**
     * Get all bookings
     *
     * @param   array  $args  Bookings args
     *
     * @return  array
     */
    public static function all( $args = [] ) {

        $status = [
            'approved',
            'pending',
            'cancel',
            'completed',
        ];
        $defaults = [
            'post_type'      => 'timetics-booking',
            'posts_per_page' => 20,
            'post_status'    => 'any',
            'paged'          => 1,
        ];

        $args = wp_parse_args( $args, $defaults );

        $args = apply_filters( 'timetics/admin/bookings/all', $defaults, $args);

        if ( ! empty( $args['start_date'] ) ) {
            //_tt_booking_start_date
            $args['meta_query'] = [
                [
                    'key'     => '_tt_booking_start_date',
                    'value'   => $args['start_date'],
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ];
        }

        if ( ! empty( $args['meeting'] ) ) {
            // @codingStandardsIgnoreStart
            $args['meta_query'] = [
                [
                    'key'     => '_tt_booking_appointment',
                    'value'   => $args['meeting'],
                    'compare' => '=',
                ],
            ];
            // @codingStandardsIgnoreEnd
        }

        if ( ! empty( $args['staff'] ) ) {
            // @codingStandardsIgnoreStart
            $args['meta_query'] = [
                [
                    'key'     => '_tt_booking_staff',
                    'value'   => $args['staff'],
                    'compare' => '=',
                ],
            ];
            // @codingStandardsIgnoreEnd
        }
        
        $post = new WP_Query( $args );

        return [
            'total' => $post->found_posts,
            'items' => $post->posts,
        ];
    }

    /**
     * Create event after creating a booking
     *
     * @return bool
     */
    public function create_event() {

        if ( ! timetics_google_setup() ) {
            return;
        }

        if ('timetics-event' == $this->get_type()) {
            Event_Booking::instance()->create_online_event($this->get_id());
        }else {
             $this->create_appointment_event();
        }

    }

    public function create_appointment_event() {
        $data = [
            'summary'     => timetics_get_option( 'booking_created_customer_email_title' ),
            'description' => timetics_get_option( 'booking_created_customer_email_body' ),
        ];

        $calendar   = new Calendar();
        $event_data = $this->prepare_event( $data );

        if ( ! $event_data ) {
            return;
        }

        $booking_entry = new Booking_Entry();
        $meeting       = new Appointment( $this->get_appointment() );

        $date_time = timetics_convert_timezone( $this->get_start_date() .' '. $this->get_start_time(), $this->get_timezone(), $meeting->get_timezone() );

        $entries = $booking_entry->find(
            [
                'staff_id'   => $this->get_staff_id(),
                'meeting_id' => $this->get_appointment(),
                'date'       => $date_time->format('Y-m-d'),
                'start'      => $date_time->format('h:i a'),
            ]
        );

        if ( $entries ) {
            $entry = $booking_entry->first();

            $event = $entry->get_google_event();

            if ( ! $event ) {
                $event = $calendar->create_event( $event_data );
                $entry->update( [
                    'google_event' => $event,
                ] );
            }
        }

        if ( $event ) {
            update_post_meta( $this->id, $this->meta_prefix . 'calendar_event', $event );
        }
    }

    /**
     * Update calendar event
     *
     * @return bool
     */
    public function update_event() {
        if ( ! timetics_google_setup() ) {
            return;
        }

        $data = [
            'summary'     => timetics_get_option( 'booking_rescheduled_customer_email_title' ),
            'description' => timetics_get_option( 'booking_rescheduled_customer_email_body' ),
        ];

        $calendar       = new Calendar();
        $event_data     = $this->prepare_event( $data );
        $calendar_event = $this->get_event();

        if ( ! is_array( $calendar_event ) ) {
            return;
        }

        if ( ! empty( $calendar_event['id'] ) ) {
            // Update calendar event.
            $event = $calendar->update_event( $calendar_event['id'], $event_data );

            // update calendar event data.
            update_post_meta( $this->id, $this->meta_prefix . 'calendar_event', $event );
        }
    }

    /**
     * Delete calendar event
     *
     * @return
     */
    public function delete_event() {
        if ( ! timetics_google_setup() ) {
            return;
        }

        $calendar       = new Calendar();
        $calendar_event = $this->get_event();
        $access_token   = timetics_get_google_access_token( $this->get_staff_id() );

        if ( ! is_array( $calendar_event ) ) {
            return;
        }

        if ( ! $access_token ) {
            return;
        }

        if ( ! empty( $calendar_event['id'] ) ) {
            // Update calendar event.
            $event = $calendar->delete_event( $calendar_event['id'], $access_token );

            // update calendar event data.
            update_post_meta( $this->id, $this->meta_prefix . 'calendar_event', $event );
        }
    }

    /**
     * Prepare event data
     *
     * @return  array
     */
    private function prepare_event( $data = [] ) {

        $meeting_id    = $this->get_appointment();
        $staff_id      = $this->get_staff_id();
        $customer_id   = $this->get_customer_id();
        $location_type = $this->get_location_type();
        $access_token  = timetics_get_google_access_token( $staff_id );

        if ( ! $access_token ) {
            return false;
        }

        $meeting  = new Appointment( $meeting_id );
        $staff    = new Staff( $staff_id );
        $customer = new Customer( $customer_id );

        $time     = gmdate( 'h:i a', strtotime( $this->get_start_time() ) );
        $date     = gmdate( 'd F Y', strtotime( $this->get_start_date() ) );
        $location = $this->get_location();

        $placeholders = [
            '{%meeting_title%}'    => $meeting->get_name(),
            '{%meeting_date%}'     => $date,
            '{%meeting_time%}'     => $time,
            '{%meeting_location%}' => $location,
            '{%meeting_duration%}' => $meeting->get_duration(),
            '{%host_name%}'        => $staff->get_display_name(),
            '{%host_email%}'       => $staff->get_email(),
            '{%customer_name%}'    => $customer->get_display_name(),
            '{%customer_email%}'   => $customer->get_email(),
        ];

        $summary     = ! empty( $data['summary'] ) ? timetics_replace_placeholder( $data['summary'], $placeholders ) : $meeting->get_name();
        $description = ! empty( $data['description'] ) ? timetics_replace_placeholder( $data['description'], $placeholders ) : $meeting->get_description();

        $description = apply_filters( 'timetics_booking_event_data', $description, $summary, $this );

        $event = [
            'summary'      => $summary,
            'description'  => $description,
            'start'        => [
                'date' => $this->get_start_date(),
                'time' => $this->get_start_time(),
            ],
            'end'          => [
                'date' => $this->get_end_date(),
                'time' => $this->get_end_time(),
            ],
            'attendees'    => [
                ['email' => $staff->get_email()],
                ['email' => $customer->get_email()],
            ],
            'timezone'     => $this->get_timezone(),
            'google_meet'  => 'google-meet' === $location_type,
            'access_token' => $access_token,
        ];

        return $event;
    }

    /**
     * Get all data of an object
     *
     * @return  array
     */
    public function to_array() {
        $data = [];

        foreach ( $this->data as $key => $value ) {

            $data[$key] = $this->get_prop( $key );
        }

        return $data;
    }

    /**
     * Send notification after booking
     *
     * @return  void
     */
    private function send_notification( $action ) {

        do_action( 'timetics_booking_notification', $this, $action );
    }
}
