<?php
namespace Timetics\Core\Staffs\Calendars;

use Timetics\Core\Bookings\Booking;

/**
 * Calendar sync with timetics booking
 */
class CalendarSyncFacade {
    /**
     * Store staff id
     *
     * @var integer
     */
    private static $staff_id;

    /**
     * Sync booking
     *
     * @param   string  $teamMemberId  [$teamMemberId description]
     * @param   array   $bookingData   [$bookingData description]
     *
     * @return  bool                   [return description]
     */
    public static function syncBooking(string $teamMemberId, array $bookingData): bool {
        $services = CalendarSyncFactory::getSyncServices( $teamMemberId );
        $status = true;

        foreach ( $services as $service ) {
            $status = $status && $service->syncToCalendar( $bookingData );
        }

        return $status;
    }

    /**
     * Fetch booking with calendar services
     *
     * @param   string  $teamMemberId
     *
     * @return  array
     */
    public static function fetchBookings( string $teamMemberId ): array {
        $services = CalendarSyncFactory::getSyncServices($teamMemberId);
        $allBookings = [];
        self::$staff_id = $teamMemberId;

        foreach ( $services as $service ) {
            $allBookings = array_merge( $allBookings, $service->fetchBookings( $teamMemberId ) );

            self::create_bookings( $allBookings );
        }

        return $allBookings;
    }

    /**
     * Create bookings with calendar events
     *
     * @param   array  $bookings  Booking list from calendar
     *
     * @return  bool
     */
    private static function create_bookings( $bookings ) {
        if ( $bookings ) {
            foreach ( $bookings as $booking_data ) {
                $booking = new Booking();
                $booking->set_props([
                    'staff'       => self::$staff_id,
                    'start_date'  => $booking_data['start_date'],
                    'start_time'  => $booking_data['start_time'],
                    'end_date'    => $booking_data['end_date'],
                    'end_time'    => $booking_data['end_time'],
                    'description' => $booking_data['description'],
                    'post_status' => 'pending'
                ]);
                $booking->save();
            }
        }
    }
}

