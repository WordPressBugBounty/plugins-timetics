<?php
/**
 * Google Calendar Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Google\Service;

/**
 * Class Calendar
 */
class Calendar {
    const TIMETICS_TIMEZONE_URI   = 'https://www.googleapis.com/calendar/v3/users/me/settings/timezone';
    const TIMETICS_CALENDAR_EVENT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';


    /**
     * Get events from the calendar for the last 3 months.
     *
     * @param int $user_id Team member ID.
     * @param array $api_filters Additional API filters for google calendar API
     *
     * @return array List of calendar events.
     */
    public function get_events( $user_id, $api_filters = array() ) {
        $access_token = timetics_get_google_access_token($user_id);

        if ( ! $access_token ) {
            return ['error' => 'Access token not found or expired.'];
        }

        // Define the time range for the last 3 months
        $three_months_ago = date('c', strtotime('-3 months'));
        $three_months_ahead = date('c', strtotime('+3 months')); // 3 months ahead date-time in RFC3339 format

        $filters = array(
            'timeMin' => rawurlencode($three_months_ago),
            'timeMax' => rawurlencode($three_months_ahead),
            'orderBy' => 'startTime',
            'singleEvents' => 'true',
        );

        // add additional api filters if needed
        $filters = array_merge( $filters, $api_filters );

        // API URL with time range filter
        $api_url = add_query_arg( $filters, self::TIMETICS_CALENDAR_EVENT);

        // Set headers
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
        ];

        // Fetch data from Google Calendar API
        $response = wp_remote_get( $api_url, $args );
        if ( is_wp_error( $response ) ) {
            return [];
        }

        $body   = wp_remote_retrieve_body( $response );
        $events = json_decode($body, true);

        if ( empty( $events['items'] ) ) {
            return [];
        }

        // Filter required fields
        $filtered_events = [];
        foreach ( $events['items'] as $event ) {
            if ( empty( $event['start'] ) ) {
                continue;
            }
            
            $start = ! empty($event['start']['dateTime']) ? $event['start']['dateTime'] : $event['start']['date'];
            $end = ! empty($event['end']['dateTime']) ? $event['end']['dateTime'] : $event['end']['date'];

            $timezone = $event['start']['timeZone'] ?? wp_timezone_string();
            $timezone = new \DateTimeZone( $timezone );

            $start_dt = new \DateTime( $start );
            $start_dt->setTimezone( $timezone );

            $end_dt = new \DateTime( $end );
            $end_dt->setTimezone( $timezone );

            $filtered_events[] = [
                'id'         => $event['id'] ?? '',
                'start_date' => $start_dt->format( 'Y-m-d' ),
                'start_time' => $start_dt->format( 'H:i:s' ),
                'end_date'   => $end_dt->format( 'Y-m-d' ),
                'end_time'   => $end_dt->format( 'H:i:s' ),
                'summary'    => $event['summary'] ?? '',
                'description'=> $event['description'] ?? '',
            ];
        }

        return $filtered_events;
    }

    /**
     * Get event by ID
     *
     * @param   string  $event_id
     *
     * @return JSON | WP_Error
     */
    public function get_event( $event_id , $user_id = null ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        $access_token = timetics_get_google_access_token( $user_id );

        $data = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ];

        $response = wp_remote_get(self::TIMETICS_CALENDAR_EVENT . '/' . $event_id, $data);

        if ( is_wp_error( $response ) ) {
            return ['error' => $response->get_error_message()];
        }

        $body   = wp_remote_retrieve_body( $response );
        $event  = json_decode($body, true);

        return $event;
    }

    /**
     * Create event
     *
     * @param   array  $args  Event data
     *
     * @return JSON | WP_Error
     */
    public function create_event( $args = [] ) {
        $defaults = [
            'summary'      => '',
            'description'  => '',
            'location'     => '',
            'start'        => '',
            'end'          => '',
            'attendees'    => [],
            'google_meet'  => true,
            'access_token' => '',
        ];

        $args = apply_filters( 'timetics/booking/create/google-event', $args);

        $args = wp_parse_args( $args, $defaults );
        $data = $this->prepare_request_data( $args );

        $query_params = build_query( [
            'conferenceDataVersion' => '1',
            // 'sendUpdates'           => 'all',
        ] );

        $response = wp_remote_post( self::TIMETICS_CALENDAR_EVENT . '?' . $query_params, $data );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 != $status_code ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data;
    }

    /**
     * Update calender event
     *
     * @param   array  $args
     *
     * @return array
     */
    public function update_event( $event_id, $args ) {
        $defaults = [
            'summary'      => '',
            'description'  => '',
            'location'     => '',
            'start'        => '',
            'end'          => '',
            'attendees'    => [],
            'google_meet'  => true,
            'access_token' => '',
            'method'       => 'PUT',
        ];

        $args         = wp_parse_args( $args, $defaults );
        $query_params = build_query( [
            'conferenceDataVersion' => '1',
            'sendUpdates'           => 'all',
        ] );

        $data           = $this->prepare_request_data( $args );
        $data['method'] = 'PUT';

        $response = wp_remote_post( self::TIMETICS_CALENDAR_EVENT . '/' . $event_id . '?' . $query_params, $data );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 != $status_code ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data;
    }

    /**
     * Get timzeson
     *
     * @return  string | WP_Error
     */
    public function get_timezone( $access_token ) {
        $data = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
        ];

        $response = wp_remote_get( self::TIMETICS_TIMEZONE_URI, $data );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            return $data['value'];
        }

        return $response;
    }

    /**
     * Delete google calendar event
     *
     * @param   string  $event_id
     *
     * @return array
     */
    public function delete_event( $event_id, $access_token ) {
        $query_params = build_query( [
            'conferenceDataVersion' => '1',
            'sendUpdates'           => 'all',
        ] );

        $response = wp_remote_post( self::TIMETICS_CALENDAR_EVENT . '/' . $event_id . '?' . $query_params, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ],
            'method'  => 'DELETE',
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 != $status_code ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return $data;
    }

    /**
     * Get timezone offset
     *
     * @param   string  $timezone
     *
     * @return string
     */
    public function get_timezone_offset( $timezone ) {
        $current       = timezone_open( $timezone );
        $utc_time      = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
        $offset_insecs = timezone_offset_get( $current, $utc_time );
        $hours_and_sec = gmdate( 'H:i', abs( $offset_insecs ) );

        return stripos( $offset_insecs, '-' ) === false ? "+{$hours_and_sec}" : "-{$hours_and_sec}";
    }

    /**
     * Prepare time for calendar event
     *
     * @param   array  $data
     *
     * @return array
     */
    private function prepare_time( $data, $access_token ) {
        $start_date = isset( $data['start']['date'] ) ? $data['start']['date'] : gmdate( 'Y-m-d' );
        $start_time = isset( $data['start']['time'] ) ? $data['start']['time'] : gmdate( 'H:i:s' );
        $end_date   = isset( $data['end']['date'] ) ? $data['end']['date'] : gmdate( 'Y-m-d' );
        $end_time   = isset( $data['end']['time'] ) ? $data['end']['time'] : gmdate( 'H:i:s' );
        $timezone        = isset( $data['timezone'] ) ? $data['timezone'] : 'Asia/Dhaka';
        $timezone_offset = $this->get_timezone_offset( $timezone );

        $start_date_time = $start_date . 'T' . $this->convertTo24HourFormat( $start_time ) . $timezone_offset;
        $end_date_time   = $end_date . 'T' . $this->convertTo24HourFormat( $end_time ) . $timezone_offset;

        $date_time = [
            'start' => [
                'dateTime' => $start_date_time,
                'timeZone' => $timezone,
            ],
            'end'   => [
                'dateTime' => $end_date_time,
                'timeZone' => $timezone,
            ],
        ];

        return $date_time;
    }

    /**
     * Convet 12 hours format to 24 hours format
     *
     * @param   string  $time
     *
     * @return string
     */
    public function convertTo24HourFormat( $time ) {
        return date('H:i:s', strtotime( $time ) );
    }

    /**
     * Prepare event create requested data
     *
     * @param   array  $args
     *
     * @return array
     */
    private function prepare_request_data( $args = [] ) {
        $access_token = $args['access_token'];
        $date         = $this->prepare_time(
            [
                'start'    => $args['start'],
                'end'      => $args['end'],
                'timezone' => $args['timezone'],
            ],
            $access_token
        );

        $args['start'] = [$date['start']];
        $args['end']   = [$date['end']];

        if ( $args['google_meet'] ) {
            $args['conferenceData'] = [
                'createRequest' => [
                    'requestId'             => 'sample123',
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        unset( $args['access_token'] );
        $data = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json; charset=utf-8',
            ],
            'body'    => json_encode( $args ),
        ];

        return $data;
    }

    /**
     * Get google calendar auth scope
     *
     * @return  string
     */
    public static function scope() {
        return 'https://www.googleapis.com/auth/calendar';
    }
}
