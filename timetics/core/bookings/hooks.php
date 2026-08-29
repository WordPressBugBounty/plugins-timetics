<?php
/**
 * Booking related hooks
 *
 * @package Timetics
 */

namespace Timetics\Core\Bookings;

defined( 'ABSPATH' ) || exit;

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
        add_action( 'timetics_booking_remainder', [$this, 'send_reminder_email'], 10, 2 );
        add_action( 'timetics_booking_clear_schedule', [$this, 'clear_booking_schedule'] );

        add_action( 'before_delete_post', [$this, 'release_slot_on_delete'] );

        add_action( 'init', [$this, 'register_booking_status'] );
        add_action( 'init', [$this, 'maybe_migrate_reminder_schedules'], 99 );

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
        // Runs on update as well as create. Any reminder queued for the old
        // date/time is dropped first, otherwise the `wp_next_scheduled()` guard
        // below keeps the stale event and the new time is never scheduled.
        self::clear_reminders( $booking_id );

        $booking = new Booking( $booking_id );

        $date = $booking->get_start_date();
        $time = $booking->get_start_time();

        $booking_timezone = $booking->get_timezone();

        if ( ! $booking_timezone || ! timetics_is_valid_timezone( $booking_timezone ) ) {
            $booking_timezone = timetics_reminder_fallback_timezone();
        }

        $booking_datetime  = new \DateTime( $date . ' ' . $time, new \DateTimeZone( $booking_timezone ) );
        $booking_timestamp = $booking_datetime->getTimestamp();

        $reminder_time = timetics_get_option( 'remainder_time' );

        if ( ! $reminder_time ) {
            return;
        }

        $queued = [];

        foreach ( $reminder_time as $reminder ) {
            $offset   = 0;
            $duration = isset( $reminder['duration-time'] ) ? intval( $reminder['duration-time'] ) : 0;
            $type     = isset( $reminder['custom_duration_type'] ) ? $reminder['custom_duration_type'] : '';

            switch ( $type ) {
            case 'min':
                $offset = $duration * MINUTE_IN_SECONDS;
                break;
            case 'hour':
                $offset = $duration * HOUR_IN_SECONDS;
                break;
            case 'day':
                $offset = $duration * DAY_IN_SECONDS;
                break;
            }

            $reminder_timestamp = intval( $booking_timestamp ) - $offset;

            // Never schedule a reminder in the past. WP-Cron fires past-due
            // events on the next page load, which caused reminder emails to be
            // sent unexpectedly — and in bursts when a backlog flushed — even
            // though no new booking or action had occurred.
            if ( $reminder_timestamp <= time() ) {
                continue;
            }

            // The same offset configured twice is one reminder, not two.
            if ( isset( $queued[ $offset ] ) ) {
                continue;
            }

            $queued[ $offset ] = true;

            // The offset travels in the cron args so every configured reminder
            // is a distinct event. Sharing one arg list made WP-Cron treat them
            // as the same hook: the old `wp_next_scheduled()` guard let only the
            // first list entry through, and even without it
            // wp_schedule_single_event() silently drops a duplicate falling
            // within 10 minutes of one already queued.
            wp_schedule_single_event( $reminder_timestamp, 'timetics_booking_remainder', [$booking_id, $offset] );
        }
    }

    /**
     * Send booking reminder email
     *
     * @param   integer  $booking_id
     * @param   integer  $offset      Seconds before the meeting this reminder was queued for.
     *                                Part of the cron args only so each configured reminder is
     *                                a distinct event; not used when composing the email.
     *
     * @return  void
     */
    public function send_reminder_email( $booking_id, $offset = 0 ) {
        // The cron event outlives the booking, so re-check it here: a booking
        // cancelled or deleted after the reminder was scheduled must not get a
        // reminder for a meeting that no longer exists.
        $status = get_post_status( $booking_id );

        if ( ! $status || in_array( $status, ['cancel', 'cancelled', 'failed', 'trash'], true ) ) {
            return;
        }

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
     * Remove every reminder cron event queued for a booking.
     *
     * @param   integer  $booking_id
     *
     * @return  integer  Number of events removed.
     */
    public static function clear_reminders( $booking_id ) {
        $removed = 0;

        foreach ( self::find_reminders( $booking_id ) as $timestamp => $args ) {
            wp_unschedule_event( $timestamp, 'timetics_booking_remainder', $args );
            $removed++;
        }

        return $removed;
    }

    /**
     * Every reminder cron event queued for a booking, as timestamp => args.
     *
     * Walks the cron store rather than calling wp_next_scheduled() with a fixed
     * arg list: a booking has one event per configured reminder, each carrying
     * its own offset, so there is no single arg list to look up. Events queued
     * before the offset was added carry only [ booking_id ], so matching is on
     * the first argument to cover both shapes.
     *
     * @param   integer  $booking_id
     *
     * @return  array
     */
    private static function find_reminders( $booking_id ) {
        $booking_id = (int) $booking_id;
        $cron       = _get_cron_array();
        $found      = [];

        if ( ! is_array( $cron ) ) {
            return $found;
        }

        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks['timetics_booking_remainder'] ) || ! is_array( $hooks['timetics_booking_remainder'] ) ) {
                continue;
            }

            foreach ( $hooks['timetics_booking_remainder'] as $event ) {
                $args = isset( $event['args'] ) ? (array) $event['args'] : [];

                if ( empty( $args ) || (int) $args[0] !== $booking_id ) {
                    continue;
                }

                $found[ $timestamp ] = $args;
            }
        }

        return $found;
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
            // Not wp_next_scheduled() with a fixed arg list: a booking now has one
            // event per configured reminder, each carrying its own offset, so a
            // single-arg lookup misses all of them.
            foreach ( self::find_reminders( $booking->ID ) as $timestamp => $args ) {
                if ( $timestamp < time() ) {
                    wp_unschedule_event( $timestamp, 'timetics_booking_remainder', $args );
                }
            }
        }
    }

    /**
     * Migrate any outstanding cron events scheduled with the legacy
     * `timetics_booking_remainder_{id}` hook name to the unified
     * `timetics_booking_remainder` hook with the booking id as an argument.
     *
     * Runs once per plugin version.
     *
     * @return void
     */
    public function maybe_migrate_reminder_schedules() {
        $version = defined( 'TIMETICS_VERSION' ) ? TIMETICS_VERSION : '0';

        if ( get_option( 'timetics_reminder_cron_migrated' ) === $version ) {
            return;
        }

        $cron = _get_cron_array();

        if ( ! is_array( $cron ) ) {
            update_option( 'timetics_reminder_cron_migrated', $version, false );
            return;
        }

        $changed = false;

        foreach ( $cron as $timestamp => $hooks ) {
            if ( ! is_array( $hooks ) ) {
                continue;
            }

            foreach ( $hooks as $hook => $events ) {
                if ( strpos( $hook, 'timetics_booking_remainder_' ) !== 0 ) {
                    continue;
                }

                $booking_id = (int) substr( $hook, strlen( 'timetics_booking_remainder_' ) );

                if ( ! $booking_id ) {
                    unset( $cron[ $timestamp ][ $hook ] );
                    $changed = true;
                    continue;
                }

                $args = [$booking_id];
                $key  = md5( serialize( $args ) );

                $cron[ $timestamp ]['timetics_booking_remainder'][ $key ] = [
                    'schedule' => false,
                    'args'     => $args,
                ];

                unset( $cron[ $timestamp ][ $hook ] );
                $changed = true;
            }

            if ( empty( $cron[ $timestamp ] ) ) {
                unset( $cron[ $timestamp ] );
            }
        }

        if ( $changed ) {
            _set_cron_array( $cron );
        }

        update_option( 'timetics_reminder_cron_migrated', $version, false );

        $this->maybe_reschedule_reminders( $version );
    }

    /**
     * Clear and re-schedule all booking reminder cron events with
     * corrected timezone-aware timestamps.
     *
     * Runs once per plugin version after the timezone fix.
     *
     * @param   string  $version
     *
     * @return  void
     */
    private function maybe_reschedule_reminders( $version ) {
        $migration_key = 'timetics_reminder_tz_migrated';

        if ( get_option( $migration_key ) === $version ) {
            return;
        }

        $cron = _get_cron_array();

        if ( is_array( $cron ) ) {
            $changed = false;

            foreach ( $cron as $timestamp => $hooks ) {
                if ( ! is_array( $hooks ) ) {
                    continue;
                }

                if ( isset( $hooks['timetics_booking_remainder'] ) ) {
                    unset( $cron[ $timestamp ]['timetics_booking_remainder'] );
                    $changed = true;
                }

                if ( empty( $cron[ $timestamp ] ) ) {
                    unset( $cron[ $timestamp ] );
                }
            }

            if ( $changed ) {
                _set_cron_array( $cron );
            }
        }

        $all = Booking::all(
            [
                'posts_per_page' => -1,
                'post_status'    => [ 'approved', 'pending' ],
                'start_date'     => gmdate( 'Y-m-d' ),
            ]
        );

        if ( ! empty( $all['items'] ) ) {
            foreach ( $all['items'] as $booking ) {
                $this->register_schedule( $booking->ID );
            }
        }

        update_option( $migration_key, $version, false );
    }

    /**
     * Give a booking's slot back when its post is permanently deleted.
     *
     * Only the REST controller released the entry; deletes from the posts
     * screen, WP-CLI or wp_delete_post() left it blocking the slot for good.
     * Hooked to permanent deletion, not trash, so a restore keeps its slot.
     *
     * @param   integer  $post_id
     *
     * @return  void
     */
    public function release_slot_on_delete( $post_id ) {
        if ( 'timetics-booking' !== get_post_type( $post_id ) ) {
            return;
        }

        ( new Booking( $post_id ) )->release_slot();
    }

    /**
     * Update bookked entry if reschedule
     *
     * @deprecated 1.0.62 Ran after the booking already held its new time, so it
     *                    looked up the slot moved *into*, not the one left behind.
     *                    Use Booking::release_slot_at() with the previous slot.
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
        // Define label_count translations for each status
        $label_counts = array(
            /* translators: %s: Number of approved bookings */
            'approved' => _n_noop(
                'Approved <span class="count">(%s)</span>',
                'Approved <span class="count">(%s)</span>',
                'timetics'
            ),
            
            /* translators: %s: Number of pending bookings */
            'pending' => _n_noop(
                'Pending <span class="count">(%s)</span>',
                'Pending <span class="count">(%s)</span>',
                'timetics'
            ),
            /* translators: %s: Number of cancelled bookings */
            'cancel' => _n_noop(
                'Cancelled <span class="count">(%s)</span>',
                'Cancelled <span class="count">(%s)</span>',
                'timetics'
            ),
            /* translators: %s: Number of completed bookings */
            'completed' => _n_noop(
                'Completed <span class="count">(%s)</span>',
                'Completed <span class="count">(%s)</span>',
                'timetics'
            ),
        );

        // Register each status
        foreach ( $label_counts as $status => $label_count ) {
            register_post_status( $status, array(
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => false,
                'show_in_admin_status_list' => false,
                'label_count'               => $label_count,
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
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta query is necessary for filtering bookings by payment method
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

        // Delegate to the booking so the shared _tt_booking_slot_released flag
        // applies: a booking already freed by make_payment() / WooCommerce sync
        // becomes a no-op here, so this cleanup can never decrement the counter a
        // second time. ( This runs inline on every admin_init, not via wp-cron;
        // the previous inline decrement here re-ran each time and could drive
        // group-meeting counters negative. )
        $booking->release_slot();
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
