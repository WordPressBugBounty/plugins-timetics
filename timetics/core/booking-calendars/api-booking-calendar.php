<?php
/**
 * Booking Calendar
 *
 * @package Timetics
 */

namespace Timetics\Core\BookingCalendars;

use Timetics\Base\Api;
use Timetics\Core\Appointments\Appointment;
use Timetics\Utils\Singleton;
use DateTimeZone;
use DateTime;

class Api_Booking_Calendar extends Api {
    use Singleton;

    /**
     * Store api namespace
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @var string
     */
    protected $rest_base = 'booking-calendars';

    /**
     * Register rest routes
     *
     * @return  void
     */
    public function register_routes() {

        register_rest_route(
            $this->namespace, $this->rest_base, array(
                array(
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_booking_calendars' ),
                    'permission_callback' => '__return_true',
                ),
            )
        );
    }

    /**
     * Get calendar data
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_booking_calendars( $request ) {
        $meeting_id   = ! empty( $request['meeting_id'] ) ? intval( $request['meeting_id'] ) : 0;
        $booking_date = ! empty( $request['booking_date'] ) ? sanitize_text_field( $request['booking_date'] ) : '';
        $start_time   = ! empty( $request['start_time'] ) ? sanitize_text_field( $request['start_time'] ) : '';
        $end_time     = ! empty( $request['end_time'] ) ? sanitize_text_field( $request['end_time'] ) : '';
        $location     = ! empty( $request['location'] ) ? sanitize_text_field( $request['location'] ) : '';

        $meeting = new Appointment( $meeting_id );

        $google_url = $this->get_google_calendar_url(
            array(
				'date'        => $booking_date,
				'start_time'  => $start_time,
				'end_time'    => $end_time,
				'location'    => $location,
				'title'       => $meeting->get_name(),
				'description' => $meeting->get_description(),
				'timezone'    => $meeting->get_timezone(),
            )
        );

        $calendars = array();

        if ( $google_url ) {
            $calendars[] = array(
                'id'   => 'google',
                'name' => esc_html__( 'Google', 'timetics' ),
                'url'  => esc_url_raw( $google_url ),
            );
        }

        // Filter to ensure calendar links can be added from timetics-pro also
        $calendars = apply_filters( 'timetics_booking_calendar_urls', $calendars, $request );

        $data = array(
            'success'     => 1,
            'status_code' => 200,
            'calendars'   => $calendars,
        );

        return rest_ensure_response( $data );
    }

    /**
     * Get Google Calendar URL that will be used to add event to Google Calendar
     *
     * @param array $args {
     *     @type string $date        Booking date in 'Y-m-d' format.
     *     @type string $start_time  Start time in 'H:i' format.
     *     @type string $end_time    End time in 'H:i' format.
     *     @type string $location    Event location.
     *     @type string $title       Event title.
     *     @type string $description Event description.
     *     @type string $timezone    Event timezone.
     * }
     *
     * @return string Google Calendar URL
     */
    private function get_google_calendar_url( $args = array() ) {
        $defaults = array(
            'date'        => '',
            'start_time'  => '',
            'end_time'    => '',
            'location'    => '',
            'title'       => '',
            'description' => '',
            'timezone'    => '',
        );

        $args = wp_parse_args( $args, $defaults );

        if ( empty( $args['date'] ) || empty( $args['start_time'] ) || empty( $args['end_time'] ) ) {
            return '';
        }

        // Combine and convert to UTC datetime strings
        try {
            $date = $args['date'];
            $start_time = $args['start_time'];
            $end_time = $args['end_time'];
            $timezone_string = $args['timezone'] ?: 'UTC'; // e.g., 'Asia/Dhaka'
            $timezone = new DateTimeZone($timezone_string);
    
            // Try 24-hour format first
            $start = DateTime::createFromFormat('Y-m-d H:i', "$date $start_time", $timezone);
            $end   = DateTime::createFromFormat('Y-m-d H:i', "$date $end_time", $timezone);
        
            if ( ! $start ) {
                $start = DateTime::createFromFormat('Y-m-d h:i A', "$date $start_time", $timezone);
            }
            if ( ! $end ) {
                $end = DateTime::createFromFormat('Y-m-d h:i A', "$date $end_time", $timezone);
            }
        
            if ( ! $start || ! $end ) {
                return '';
            }

            // Convert to UTC for Google Calendar
            $start_utc = clone $start;
            $end_utc = clone $end;
            $start_utc->setTimezone(new DateTimeZone('UTC'));
            $end_utc->setTimezone(new DateTimeZone('UTC'));
        
            $start_utc_str = $start_utc->format('Ymd\THis\Z');
            $end_utc_str   = $end_utc->format('Ymd\THis\Z');
        
        } catch (\Throwable $e) {
            return '';
        }

        $params = array(
            'action'     => 'TEMPLATE',
            'text'       => $args['title'],
            'dates'      => $start_utc_str . '/' . $end_utc_str,
            'details'    => $args['description'],
            'location'   => $args['location'],
            'sf'         => 'true',
            'output'     => 'xml',
            'ctz'        => $timezone_string,
        );

        return 'https://calendar.google.com/calendar/render?' . http_build_query( $params );
    }
}
