<?php
/**
 * Google Calendar Sync Class
 *
 * @package timetics
 */
namespace Timetics\Core\Staffs\Calendars;

use Timetics\Core\Integrations\Google\Service\Calendar;

/**
 * Google calendar sync
 */
class GoogleCalendarSync implements CalendarSyncInterface {

    /**
     * Sync booking to calendar
     *
     * @param   array  $bookingData  [$bookingData description]
     *
     * @return  bool                 [return description]
     */
    public function syncToCalendar( array $bookingData ): bool {
        // Implement Google Calendar API call
        return true;
    }

    /**
     * Sync with google calendar
     *
     * @param   string  $teamMemberId  Team member id
     *
     * @return  array   Calendar Event List
     */
    public function fetchBookings( string $teamMemberId ): array {
        // Fetch bookings from Google Calendar API

        $calendar = new Calendar();
        $events   = $calendar->get_events( $teamMemberId );

        if ( ! $events ) {
            return [];
        }

        return $events;
    }
}
