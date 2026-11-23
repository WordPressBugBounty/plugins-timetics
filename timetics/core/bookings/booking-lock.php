<?php
/**
 * Booking lock
 *
 * @package Timetics
 */
namespace Timetics\Core\Bookings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lets one request at a time check and save a slot.
 *
 * One lock for the whole site, so bookings wait for each other even on different
 * staff. Split it per staff member if busy sites start seeing slot_busy.
 */
class Booking_Lock {

    /**
     * Run work while holding the lock.
     *
     * @param   callable  $work  The check and save. Exceptions pass through.
     *
     * @return  mixed  What $work returned. Without the lock, a slot_busy or
     *                 lock_unavailable WP_Error, and $work does not run.
     */
    public static function run( callable $work ) {
        global $wpdb;

        /**
         * Filters how many seconds a booking waits for another one to finish saving.
         *
         * @param int $timeout Seconds.
         */
        $timeout = absint( apply_filters( 'timetics_booking_lock_timeout', 10 ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL lock has no WordPress API.
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name(), $timeout ) );

        if ( '0' === (string) $got ) {
            return new WP_Error( 'slot_busy', __( 'Another booking is being saved. Please try again.', 'timetics' ), array( 'status' => 409 ) );
        }

        // Anything but 1 is a database problem. Saving anyway could double book with
        // nobody knowing, so the booking stops and the site's log says why.
        if ( '1' !== (string) $got ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Site owners need the reason.
            error_log( 'WPTimetics booking lock unavailable: ' . ( '' !== $wpdb->last_error ? $wpdb->last_error : 'GET_LOCK returned NULL' ) );

            return new WP_Error( 'lock_unavailable', __( 'Bookings cannot be saved right now. Please try again later or contact us.', 'timetics' ), array( 'status' => 503 ) );
        }

        try {
            return $work();
        } finally {
            self::release();
        }
    }

    /**
     * Release the lock. Safe to call when this request no longer holds it.
     *
     * @return  void
     */
    public static function release() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL lock has no WordPress API.
        $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name() ) );
    }

    /**
     * The lock's name, per site because sites can share a database.
     * Hashed because MySQL refuses names over 64 characters.
     *
     * @return  string
     */
    public static function name() {
        global $wpdb;

        return 'timetics_booking_' . md5( $wpdb->prefix );
    }
}
