<?php
/**
 * Google Calendar Sync Class
 *
 * Handles two-way synchronization between Google Calendar and Timetics appointments.
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations\Google\Service;

use Timetics\Core\Appointments\Api_Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer;
use Timetics\Core\Appointments\Appointment;
use WP_Error;
use Timetics\Utils\Singleton;

/**
 * Class Google_Calendar_Sync
 */
class Google_Calendar_Sync {
    use Singleton;

    /**
     * Google Calendar service instance
     *
     * @var Calendar
     */
    private $calendar;

    /**
     * Appointment API instance
     *
     * @var Api_Appointment
     */
    private $appointment_api;

    /**
     * Meta key for storing Google Event ID
     */
    const EVENT_ID_META_KEY = 'tt_google_calendar_event_id';

    /**
     * Meta key for storing sync status
     */
    const SYNC_STATUS_META_KEY = 'tt_google_calendar_sync_status';

    /**
     * Meta key for storing ETag
     */
    const ETAG_META_KEY = 'tt_google_calendar_etag';

    /**
     * Constructor
     */
    public function __construct() {
        try {
            $this->calendar = new Calendar();
            $this->appointment_api = new Api_Appointment();

            // Add hooks
            add_action( 'timetics_after_booking_schedule', array( $this, 'sync_booking_to_google_calendar' ), 10, 4 );
            add_filter( 'timetics/admin/booking/get_items', array( $this, 'get_events_from_google' ) );
        } catch ( \Throwable $e ) {
            error_log( $e->getMessage() );
        }
    }

    /**
     * Get all Google Event IDs that were created by Timetics
     *
     * @return array
     */
    private function get_timetics_google_event_ids() {
        $booking = new Booking();
        return $booking->get_all_google_event_ids();
    }

    /**
     * Sync booking to Google Calendar
     *
     * @param int    $booking_id  Booking ID
     * @param int    $customer_id Customer ID
     * @param int    $meeting_id  Meeting ID
     * @param array  $data        Booking data
     *
     * @return void|WP_Error
     */
    public function sync_booking_to_google_calendar( $booking_id, $customer_id, $meeting_id, $data ) {
        try {
            if ( ! is_numeric( $booking_id ) || ! is_numeric( $customer_id ) || ! is_numeric( $meeting_id ) ) {
                return new WP_Error( 'invalid_parameters', 'Invalid parameters provided for Google Calendar sync.' );
            }

            $booking = new Booking( $booking_id );
            $meeting = new Appointment( $meeting_id );
            $customer = new Customer( $customer_id );

            // Get the current user's access token
            $user_id = get_current_user_id();
            $access_token = timetics_get_google_access_token( $user_id );

            if ( empty( $access_token ) ) {
                return new WP_Error( 'no_access_token', 'No Google Calendar access token found. Please reconnect your Google account.' );
            }

            // Prepare event data
            $event_data = array(
                'access_token' => sanitize_text_field( $access_token ),
                'summary' => sanitize_text_field( $meeting->get_name() ),
                'description' => sanitize_text_field( $meeting->get_description() ),
                'start' => array(
                    'dateTime' => $booking->get_start_date(),
                    'timeZone' => wp_timezone_string(),
                ),
                'end' => array(
                    'dateTime' => $booking->get_end_date(),
                    'timeZone' => wp_timezone_string(),
                ),
                'attendees' => array(
                    array( 'email' => $customer->get_email() ),
                ),
                'reminders' => array(
                    'useDefault' => true,
                ),
                'guestsCanInviteOthers' => false,
                'guestsCanModify' => false,
                'guestsCanSeeOtherGuests' => false,
                'timezone' => wp_timezone_string(),
            );

            // Check if this booking already has a Google Event ID
            $event_id = $booking->get_google_event_id();

            if ( $event_id ) {
                // Update existing event
                $result = $this->calendar->update_event( $event_id, $event_data );
            } else {
                // Create new event
                $result = $this->calendar->create_event( $event_data );

                // Save the event ID for future updates
                if ( ! empty( $result['id'] ) ) {
                    $booking->set_google_event_id( $result['id'] );
                }
            }

            if ( is_wp_error( $result ) ) {
                $booking->set_sync_status( 'error' );
                return $result;
            }

            $booking->set_sync_status( 'synced' );
        } catch ( \Exception $e ) {
            return new WP_Error( 'google_calendar_sync_error', $e->getMessage() );
        }
    }

    /**
     * Get events from Google Calendar
     * Only returns events that were not created by Timetics
     *
     * @param array $bookings Existing bookings array
     *
     * @return array Modified bookings array with Google Calendar events
     */
    public function get_events_from_google( $bookings ) {
        try {
            // Get Google Calendar events
            $events = $this->calendar->get_events( get_current_user_id() );

            if ( is_wp_error( $events ) || empty( $events ) ) {
                return $bookings;
            }

            // Get all Timetics-created Google Event IDs
            $timetics_event_ids = $this->get_timetics_google_event_ids();

            $google_events = array();

            foreach ( $events as $event ) {
                // Skip events that were created by Timetics
                if ( in_array( $event['id'] ?? null, $timetics_event_ids, true ) ) {
                    continue;
                }

                // mapping google events data to timetics booking format
                $google_events[] = array(
                    'id' => $event['id'] ?? '',
                    'order_total' => '',
                    'title' => $event['summary'] ?? '',
                    'description' => $event['description'] ?? '',
                    'date' => $event['start_date'] ?? '',
                    'start_date' => $event['start_date'] ?? '',
                    'end_date' => $event['end_date'] ?? '',
                    'start_time' => $event['start_time'] ?? '',
                    'end_time' => $event['end_time'] ?? '',
                    'source' => 'google',
                    'status' => 'approved',
                    'random_id' => 'I' . $event['id'] ?? '',
                    'appointment' => array(
                        'id' => $event['id'] ?? '',
                        'name' => $event['summary'] ?? '',
                    ),
                );
            }

            // Merge with existing bookings
            return array_merge( $bookings, $google_events );
        } catch ( \Throwable $e ) {
            return $bookings;
        }
    }
}
