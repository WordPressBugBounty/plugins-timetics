<?php
/**
 * Google Calendar Sync Class
 *
 * Handles two-way synchronization between Google Calendar and Timetics appointments.
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations\Google\Service;

defined( 'ABSPATH' ) || exit;

use Timetics\Core\Appointments\Api_Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Appointments\Appointment;
use Timetics\Utils\Singleton;
use DateTime;
use DateTimeZone;

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
            //
            // Booking -> Google is handled by Booking::create_event() /
            // update_event() / delete_event(), which run on the staff's token,
            // invite both host and customer, honour the meeting's location type
            // and are wired to reschedule and cancellation. This class only
            // reads from Google; pushing here as well produced a second,
            // duplicate event for every booking.
            add_filter( 'timetics/admin/booking/get_items', array( $this, 'get_events_from_google' ) );
            add_filter( 'timetics_schedule_data_for_selected_date', array( $this, 'block_timeslots_by_google_events' ), 10, 5 );
            add_filter( 'timetics_is_slot_available', array( $this, 'reject_slot_overlapping_google_event' ), 10, 3 );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging is properly guarded by WP_DEBUG checks
                error_log( 'Timetics Google Calendar Sync: ' . $e->getMessage() );
            }
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
                    'random_id' => 'I' . ( $event['id'] ?? '' ),
                    'appointment' => array(
                        'id' => $event['id'] ?? '',
                        'name' => $event['summary'] ?? '',
                        'timezone' => $event['timezone'] ?? '',
                    ),
                );
            }

            // Merge with existing bookings
            return array_merge( $bookings, $google_events );
        } catch ( \Throwable $e ) {
            return $bookings;
        }
    }

    /**
     * Block timeslots by Google events
     *
     * @param array $data
     * @param int $staff_id
     * @param int $meeting_id
     * @param string $timezone
     *
     * @return array Modified bookings array with Google Calendar events
     */
    public function block_timeslots_by_google_events( $data, $staff_id, $meeting_id, $timezone ) {
        try {
            if ( ! timetics_get_option( 'google_calendar_overlap', false ) ) {
                return $data;
            }

            if ( empty( $data ) ) {
                return $data;
            }

            // Fetch Google events for the full date range in one request
            $first_day = reset( $data );
            $last_day  = end( $data );

            $date_obj_start = new DateTime( $first_day['date'] . ' 00:00:00', new DateTimeZone( $timezone ) );
            $date_obj_end   = new DateTime( $last_day['date'] . ' 23:59:59', new DateTimeZone( $timezone ) );

            $google_events = $this->calendar->get_events( $staff_id, [
                // UTC "Z" form: an unescaped "+hh:mm" offset reaches Google as a
                // space and the bounds are silently rejected.
                'timeMin'      => Calendar::to_rfc3339_utc( $date_obj_start ),
                'timeMax'      => Calendar::to_rfc3339_utc( $date_obj_end ),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'timeZone'     => $timezone,
            ]);

            /**
             * Filter the Google events used for slot blocking.
             *
             * Also the seam the test suite uses to exercise this logic without
             * calling the Google API.
             *
             * @param array  $google_events
             * @param int    $staff_id
             * @param string $timezone       Timezone the slots are rendered in.
             */
            $google_events = apply_filters( 'timetics_google_overlap_events', $google_events, $staff_id, $timezone );

            // if theres no google calendar event for the staff member, return the data as it is
            if ( is_wp_error( $google_events ) || empty( $google_events ) ) {
                return $data;
            }

            $busy = $this->get_busy_intervals( $google_events, $timezone );

            if ( ! $busy ) {
                return $data;
            }

            // A slot occupies the meeting's full duration, but the slot array
            // only carries a start time, so the length comes from the meeting.
            $duration = $this->get_meeting_duration_in_seconds( $meeting_id );

            foreach ( $data as $index => $time_slot_data ) {
                if ( empty( $time_slot_data['slots'] ) ) {
                    continue;
                }

                $date          = $time_slot_data['date'];
                $updated_slots = [];

                foreach ( $time_slot_data['slots'] as $slot ) {
                    $slot_start = $this->to_timestamp( $date . ' ' . $slot['start_time'], $timezone );

                    if ( ! $slot_start ) {
                        $updated_slots[] = $slot;
                        continue;
                    }

                    $slot_end = $slot_start + $duration;
                    $blocked  = false;

                    foreach ( $busy as $interval ) {
                        // Standard half-open interval overlap. The previous
                        // check only tested the slot's start instant, so an
                        // event beginning mid-slot was missed entirely and the
                        // slot stayed bookable.
                        if ( $slot_start < $interval['end'] && $slot_end > $interval['start'] ) {
                            $blocked = true;
                            break;
                        }
                    }

                    // Slots unavailable for other reasons (capacity) are kept so
                    // that turning this setting on does not change the response
                    // shape for them.
                    if ( $blocked ) {
                        continue;
                    }

                    $updated_slots[] = $slot;
                }

                $data[ $index ]['slots'] = $updated_slots;

                // A day whose slots were all blocked out must not still present
                // itself as available, or the calendar offers a date that opens
                // onto an empty time list.
                if ( empty( $updated_slots ) ) {
                    $data[ $index ]['status'] = 'unavailable';
                }
            }

            return $data;
        } catch (\Throwable $e) {
            // Silenty reverts to normal behavior incase of error
            return $data;
        }
    }

    /**
     * Reject a booking whose slot collides with a Google Calendar event.
     *
     * The slot listing filter only affects what the UI shows; without this a
     * request posted straight to the REST endpoint could still take a slot the
     * host has blocked out, which is the actual double booking.
     *
     * Fails open on purpose: if the setting is off, the token is missing or the
     * API errors, the booking proceeds. A Google outage must not stop every
     * booking on the site.
     *
     * @param bool        $available
     * @param Appointment $meeting
     * @param array       $booking_data
     *
     * @return bool
     */
    public function reject_slot_overlapping_google_event( $available, $meeting, $booking_data ) {
        try {
            if ( ! $available ) {
                return $available;
            }

            if ( ! timetics_get_option( 'google_calendar_overlap', false ) ) {
                return $available;
            }

            $staff_id   = ! empty( $booking_data['staff_id'] ) ? intval( $booking_data['staff_id'] ) : 0;
            $start_date = ! empty( $booking_data['start_date'] ) ? $booking_data['start_date'] : '';
            $start_time = ! empty( $booking_data['start_time'] ) ? $booking_data['start_time'] : '';
            $timezone   = ! empty( $booking_data['timezone'] ) ? $booking_data['timezone'] : timetics_wp_timezone_string();

            if ( ! $staff_id || ! $start_date || ! $start_time ) {
                return $available;
            }

            $slot_start = $this->to_timestamp( $start_date . ' ' . $start_time, $timezone );

            if ( ! $slot_start ) {
                return $available;
            }

            $slot_end = $slot_start + $this->get_meeting_duration_in_seconds( $meeting->get_id() );

            $day_start = new DateTime( $start_date . ' 00:00:00', new DateTimeZone( $timezone ) );
            $day_end   = new DateTime( $start_date . ' 23:59:59', new DateTimeZone( $timezone ) );

            $google_events = $this->calendar->get_events( $staff_id, [
                'timeMin'      => Calendar::to_rfc3339_utc( $day_start ),
                'timeMax'      => Calendar::to_rfc3339_utc( $day_end ),
                'orderBy'      => 'startTime',
                'singleEvents' => 'true',
                'timeZone'     => $timezone,
            ] );

            $google_events = apply_filters( 'timetics_google_overlap_events', $google_events, $staff_id, $timezone );

            if ( is_wp_error( $google_events ) || empty( $google_events ) || ! empty( $google_events['error'] ) ) {
                return $available;
            }

            foreach ( $this->get_busy_intervals( $google_events, $timezone ) as $interval ) {
                if ( $slot_start < $interval['end'] && $slot_end > $interval['start'] ) {
                    return false;
                }
            }

            return $available;
        } catch ( \Throwable $e ) {
            return $available;
        }
    }

    /**
     * Reduce Google events to absolute busy intervals.
     *
     * Working in UTC timestamps keeps the comparison correct when the visitor's
     * timezone differs from the calendar's, and lets multi-day and
     * cross-midnight events be handled without any per-day special casing.
     *
     * @param array  $google_events Events as returned by Calendar::get_events().
     * @param string $timezone      Timezone the slots are rendered in.
     *
     * @return array<int, array{start:int,end:int}>
     */
    private function get_busy_intervals( $google_events, $timezone ) {
        $intervals = [];

        foreach ( $google_events as $event ) {
            if ( ! is_array( $event ) || empty( $event['start_date'] ) ) {
                continue;
            }

            if ( ! empty( $event['all_day'] ) ) {
                // Date-only bounds carry no timezone of their own, so a day
                // blocked out in Google means midnight to midnight for whoever
                // is looking at the calendar. Google's end date is exclusive,
                // which is exactly the half-open interval wanted here.
                $start = $this->to_timestamp( $event['start_date'] . ' 00:00:00', $timezone );
                $end   = $this->to_timestamp( ( $event['end_date'] ?? $event['start_date'] ) . ' 00:00:00', $timezone );

                // Guard against a malformed event whose end is not after its
                // start, which would otherwise block nothing or everything.
                if ( $start && ( ! $end || $end <= $start ) ) {
                    $end = $start + DAY_IN_SECONDS;
                }
            } elseif ( isset( $event['start_timestamp'], $event['end_timestamp'] ) ) {
                $start = (int) $event['start_timestamp'];
                $end   = (int) $event['end_timestamp'];
            } else {
                // Timed event without absolute bounds — older payload shape, or
                // a fixture. Resolve the wall-clock values in the event's own
                // timezone when it has one.
                $event_tz = ! empty( $event['timezone'] ) ? $event['timezone'] : $timezone;

                $start = $this->to_timestamp( $event['start_date'] . ' ' . ( $event['start_time'] ?? '00:00:00' ), $event_tz );
                $end   = $this->to_timestamp(
                    ( $event['end_date'] ?? $event['start_date'] ) . ' ' . ( $event['end_time'] ?? '00:00:00' ),
                    $event_tz
                );
            }

            if ( ! $start || ! $end || $end <= $start ) {
                continue;
            }

            $intervals[] = [
                'start' => $start,
                'end'   => $end,
            ];
        }

        return $intervals;
    }

    /**
     * Resolve a wall-clock string in a given timezone to a UTC timestamp.
     *
     * @param string $datetime e.g. "2026-08-10 3:00pm" or "2026-08-10 15:00:00".
     * @param string $timezone
     *
     * @return int Timestamp, or 0 when the value cannot be parsed.
     */
    private function to_timestamp( $datetime, $timezone ) {
        try {
            $tz = new DateTimeZone( $timezone );
        } catch ( \Exception $e ) {
            $tz = new DateTimeZone( 'UTC' );
        }

        try {
            return ( new DateTime( $datetime, $tz ) )->getTimestamp();
        } catch ( \Exception $e ) {
            return 0;
        }
    }

    /**
     * Length of a booking for the given meeting, in seconds.
     *
     * Slots are emitted with a start time only, so the end has to be derived
     * from the meeting itself.
     *
     * @param int $meeting_id
     *
     * @return int
     */
    private function get_meeting_duration_in_seconds( $meeting_id ) {
        $meeting  = new Appointment( $meeting_id );
        $duration = (int) $meeting->get_interval();

        return $duration > 0 ? $duration : 30 * MINUTE_IN_SECONDS;
    }
}
