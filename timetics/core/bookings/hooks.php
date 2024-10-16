<?php
/**
 * Booking related hooks
 *
 * @package Timetics
 */

namespace Timetics\Core\Bookings;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Emails\Customer_Booking_Reminder_Email;
use Timetics\Core\Emails\Staff_Booking_Reminder_Email;
use Timetics\Utils\Singleton;

/**
 * Class Hooks
 */
class Hooks {
    use Singleton;

    /**
     * Initialization
     *
     * @return  void
     */
    public function init() {
        add_action( 'timetics_after_booking_create', [$this, 'register_schedule'] );
        add_action( 'init', [$this, 'run_booking_schedule'] );
        add_action( 'timetics_booking_clear_schedule', [$this, 'clear_booking_schedule'] );

        add_action( 'timetics_after_booking_create', [$this, 'reschedule_booking'], 10, 4 );

        add_action( 'init', [$this, 'register_booking_status'] );

        add_action('woocommerce_before_calculate_totals', [ $this, 'timetics_variation_ticket_total_price' ] );

        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'timetics_add_cart_item_data' ], 10, 2 );

        add_action( 'admin_init', [$this, 'delete_booking_before_paid'] );
    }

    /**
     * Register cron job for schedule a reminder email
     *
     * @param   integer  $booking_id
     *
     * @return  void
     */
    public function register_schedule( $booking_id ) {
        $booking = new Booking( $booking_id );

        $date = $booking->get_start_date();
        $time = $booking->get_start_time();

        $booking_timestamp = strtotime( $date . ' ' . $time );

        $reminder_time = timetics_get_option( 'remainder_time' );

        if ( ! $reminder_time ) {
            return;
        }

        foreach ( $reminder_time as $time ) {
            $timestamp = '';
            $duration  = $time['duration-time'];

            switch ( $time['custom_duration_type'] ) {
            case 'min':
                $timestamp = $duration * 60;
                break;
            case 'hour':
                $timestamp = $duration * 60 * 60;
                break;
            case 'day':
                $timestamp = ( $duration * 24 ) * 60 * 60;
                break;
            }

            $timestamp = $booking_timestamp - $timestamp;

            if ( ! wp_next_scheduled( 'timetics_booking_remainder_' . $booking_id ) ) {
                wp_schedule_single_event( $timestamp, 'timetics_booking_remainder_' . $booking_id, [$booking_id] );
            }
        }
    }

    /**
     * Booking schedule
     *
     * @return  void
     */
    public function run_booking_schedule() {
        $bookins = Booking::all();

        if ( ! $bookins ) {
            return;
        }

        // Run cron action.
        foreach ( $bookins['items'] as $booking ) {
            add_action( 'timetics_booking_remainder_' . $booking->ID, [$this, 'send_reminder_email'] );
        }
    }

    /**
     * Send booking reminder email
     *
     * @param   integer  $booking_id
     *
     * @return  void
     */
    public function send_reminder_email( $booking_id ) {
        $booking_reminder_customer = timetics_get_option( 'booking_reminder_customer' );
        $booking_reminder_host     = timetics_get_option( 'booking_reminder_host' );

        $booking = new Booking( $booking_id );

        if ( $booking_reminder_customer ) {
            $customer_reminder = new Customer_Booking_Reminder_Email( $booking );
            $customer_reminder->send();
        }

        if ( $booking_reminder_host ) {
            $staff_reminder = new Staff_Booking_Reminder_Email( $booking );
            $staff_reminder->send();
        }

    }

    /**
     * Clear cron job schedule
     *
     * @return
     */
    public function clear_booking_schedule() {
        $bookins = Booking::all();

        if ( ! $bookins ) {
            return;
        }

        // Run cron action.
        foreach ( $bookins['items'] as $booking ) {
            $timestamp = wp_next_scheduled( 'timetics_booking_remainder_' . $booking->ID );

            if ( $timestamp && $timestamp < time() ) {
                wp_unschedule_event( $timestamp, 'timetics_booking_remainder_' . $booking->ID );
            }
        }
    }

    /**
     * Update bookked entry if reschedule
     *
     * @param   integer  $booking_id
     * @param   integer  $customer_id
     * @param   integer  $meeting_id
     * @param   array  $data
     * @param   integer  $booking_entry
     *
     * @return  void
     */
    public function reschedule_booking( $booking_id, $customer_id, $meeting_id, $data ) {
        $reschedule    = ! empty( $data['reschedule'] ) ? $data['reschedule'] : false;
        $booking       = new Booking( $booking_id );
        $meeting       = new Appointment( $meeting_id );
        $booking_entry = new Booking_Entry();

        if ( ! $reschedule ) {
            return;
        }

        $entries = $booking_entry->find(
            [
                'staff_id'   => $booking->get_staff_id(),
                'meeting_id' => $meeting->get_id(),
                'date'       => $booking->get_start_date(),
                'start'      => $booking->get_start_time(),
            ]
        );

        if ( ! $entries ) {
            return;
        }

        $entry         = $booking_entry->first();
        $booked_seat   = ! empty( $booking->get_seat() ) ? $booking->get_seat() : [];
        $existing_seat = ! empty( $entry->get_seats() ) ? $entry->get_seats() : [];

        if ( 'one-to-one' === strtolower( $meeting->get_type() ) ) {
            $entry->delete();
        } else {
            $booked = intval( $entry->get_booked() ) - 1;

            $entry->update( [
                'booked' => $booked,
                'seats'  => array_values( array_diff( $existing_seat, $booked_seat ) ),
            ] );
        }
    }

    /**
     * Register booking statuses
     *
     * @return  void
     */
    public function register_booking_status() {
        $statuses = timetics_get_post_status();

        foreach ( $statuses as $status ) {

            register_post_status( $status, array(
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => false,
                'show_in_admin_status_list' => false,
                'label_count'               => _n_noop( "$status (%s)", "$status (%s)", 'timetics' ),
            ) );
        }
    }

    /**
     * Delete bookings if unpaid before 30 mins
     *
     * @return void
     */
    public function delete_booking_before_paid() {
        $args = [
            'post_type'   => 'timetics-booking',
            'numberposts' => -1,
            'meta_query'  => array(
                'relation' => 'OR',
                array(
                    'key'     => '_tt_booking_payment_method',
                    'value'   => 'stripe',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_tt_booking_payment_method',
                    'value'   => 'paypal',
                    'compare' => '=',
                ),
            ),
        ];

        $bookings = get_posts( $args );

        foreach ( $bookings as $booking ) {
            $booking = new Booking( $booking->ID );

            if ( 'completed' != $booking->get_status() && $this->is_booking_payment_expire( $booking ) ) {
                $this->update_booking_entry( $booking->get_id() );
            }
        }
    }

    /**
     * Check booking payment time expaire or not
     *
     * @param   Object  $booking
     *
     * @return  bool
     */
    public function is_booking_payment_expire( $booking ) {
        // Booking date and time
        $post             = get_post( $booking->get_id() );
        $booking_datetime = $post->post_date;

        // Convert the booking date and time to a DateTime object
        $booking_datetime_object = new \DateTime( $booking_datetime );

        // Calculate 30 minutes from the booking date and time
        $target_datetime = clone $booking_datetime_object;
        $target_datetime->modify( '+30 minutes' );

        // Get the current date and time
        $current_datetime = new \DateTime();

        // Check if 30 minutes have passed
        if ( $current_datetime > $target_datetime ) {
            return true;
        }

        return false;
    }

    /**
     * Update booking entry if payment time expire
     *
     * @param   integer  $booking_id
     *
     * @return  void
     */
    public function update_booking_entry( $booking_id ) {
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

            return new \WP_HTTP_Response( $data, 403 );
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
    }

    	/**
	 * Change price for cart item
	 */
	public function timetics_variation_ticket_total_price( $cart_object ) {
		foreach ( $cart_object->cart_contents as $key => $value ) {
			if ( ! empty( $value['booking_id'] ) && $value['booking_id'] !== 0 ) {
			$order_total = !empty( $value['_timetics_variation_total_price'] ) ? $value['_timetics_variation_total_price'] : 0;

            $value['data']->get_price();
            $value['data']->set_price($order_total);
            $value['data']->set_regular_price($order_total);
			$value['data']->set_sale_price($order_total);

			}
		}

	}

    /**
	 * add booking_id as cart item data
	 *
	 * @param   integer  $booking_id
	 *
	 * @return  void
	 */
	public function timetics_add_cart_item_data( $cart_item_data ) {
        $session_data = WC()->session->get( 'timetics_data' );
        $booking_id  = $session_data['booking_id'];
        $booking     = new Booking( $booking_id );
        $total_price = floatval($booking->get_total()); // Ensure $total_price is a float
        
        if ( is_array( $booking->get_seat() ) ) {
            $total_quantity = count( $booking->get_seat() );
        } else {
            $total_quantity = 1;
        }
    
        if( ! empty( $booking_id ) && $total_price !== 0 ) {
            $cart_item_data['_timetics_variation_total_quantity'] = $total_quantity;
            $cart_item_data['booking_id'] = $booking_id;
    
            // For balancing the cart item price
            $cart_item_data['_timetics_variation_total_price'] = $total_price / $total_quantity;
        }
    
        return $cart_item_data;
    }
}
