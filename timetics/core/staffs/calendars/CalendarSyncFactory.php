<?php
namespace Timetics\Core\Staffs\Calendars;

class CalendarSyncFactory {
    /**
     * Get calendar services
     *
     * @param   string  $teamMemberId  Integer
     *
     * @return  array     Calendar services list
     */
    public static function getSyncServices(string $teamMemberId): array {
        // Fetch all calendars configured for this team member
        $calendars = timetics_connected_platforms( $teamMemberId );

        $services = [];

        foreach ( $calendars as $calendar ) {
            switch ( $calendar ) {
                case 'google-calendar':
                    $services[] = new GoogleCalendarSync();
                    break;

                default:
                    // Do nothing for unsupported platforms
                    break;
            }
        }

        return array_filter( $services ); // Remove any `null` values
    }
}
