<?php
namespace Timetics\Core\Staffs\Calendars;

/**
 * Calendar sync interface
 */
interface CalendarSyncInterface
{
    /**
     * Timetics booking to calendar
     *
     * @param   array  $bookingData  Booking data
     *
     * @return  bool
     */
    public function syncToCalendar(array $bookingData): bool;

    /**
     * Fetch calendar events to timetics booking
     *
     * @param   string  $teamMemberId  Team member id
     *
     * @return  array  Calendar booking data
     */
    public function fetchBookings(string $teamMemberId): array;
}
