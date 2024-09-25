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
    const TIMETICS_CALENDAR_EVENT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events/';

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

        $response = wp_remote_post( self::TIMETICS_CALENDAR_EVENT . $event_id . '?' . $query_params, $data );

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

        $response = wp_remote_post( self::TIMETICS_CALENDAR_EVENT . $event_id . '?' . $query_params, [
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
