<?php
/**
 * MCP reads
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use DateTime;
use DateTimeZone;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Api_Booking;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer_Store;
use Timetics\Core\Staffs\Staff;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the read tools. Output is an allow-list, so a key added to a booking later
 * never reaches the AI. Its own queries, because Booking::all() has no date or status
 * filter and prepare_item() holds the booking's security token.
 */
class Booking_Reader {

    /**
     * Most bookings a list counts. Past this, the total is reported as unknown.
     */
    const MAX_MATCHES = 500;

    /**
     * Most rows in one list answer.
     */
    const ROWS = 50;

    /**
     * Most free times in one answer.
     */
    const FREE_TIMES = 200;

    /**
     * Most days in one schedule answer.
     */
    const SCHEDULE_DAYS = 31;

    /**
     * Most staff-and-day rows in one schedule answer.
     */
    const SCHEDULE_ROWS = 20;

    /**
     * Most meetings read for a schedule.
     */
    const SCHEDULE_MEETINGS = 200;

    /**
     * Most days of free times in one answer.
     */
    const FREE_DAYS = 14;

    /**
     * Most staff members checked for free times when no one is named.
     */
    const FREE_STAFF = 10;

    /**
     * Most customers in one answer.
     */
    const CUSTOMERS = 20;

    /**
     * Most meetings in one answer.
     */
    const MEETINGS = 50;

    /**
     * Most characters of a name in a row.
     */
    const NAME_LENGTH = 100;

    /**
     * Most characters of a note.
     */
    const NOTE_LENGTH = 2000;

    /**
     * Most custom fields on one booking.
     */
    const CUSTOM_FIELDS = 30;

    /**
     * Most characters of one custom field.
     */
    const FIELD_LENGTH = 500;

    /**
     * Statuses WordPress can query, the same bookings the dashboard lists. It ignores
     * unregistered ones, so a `failed` booking is on neither.
     */
    const STORED_STATUSES = array( 'pending', 'approved', 'completed', 'cancel' );

    /**
     * Statuses a tool can ask for.
     *
     * @return  string[]
     */
    public static function statuses(): array {
        return array( 'pending', 'approved', 'completed', 'cancel', 'canceled', 'cancelled' );
    }

    /**
     * Find bookings.
     *
     * @param   array  $filters  date_from, date_to, staff_id, meeting_id, status, customer, offset.
     * @param   int    $user     User id.
     *
     * @return  array|WP_Error   items, total, limited, today, offset, next_offset.
     */
    public static function list( array $filters, int $user ) {
        $from = (string) ( $filters['date_from'] ?? '' );
        $to   = (string) ( $filters['date_to'] ?? '' );

        if ( $from && $to && $from > $to ) {
            return new WP_Error( 'invalid_range', __( 'date_from is after date_to.', 'timetics' ) );
        }

        $matches = self::find( $filters, $user, self::status_filter( $filters['status'] ?? '' ) );
        $found   = $matches['found'];

        // With a range, earliest first. Without one, the latest bookings matter most.
        if ( $from || $to ) {
            asort( $found );
        } else {
            arsort( $found );
        }

        $limited = count( $found ) > self::MAX_MATCHES || $matches['limited'];
        $offset  = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        $ids     = array_slice( array_keys( array_slice( $found, 0, self::MAX_MATCHES, true ) ), $offset, self::ROWS );
        $next    = $offset + count( $ids );

        return array(
            'items'       => array_map(
                function ( $id ) use ( $user ) {
                    return self::row( $id, $user );
                },
                $ids
            ),
            'total'       => $limited ? null : count( $found ),
            'limited'     => $limited,
            'today'       => wp_date( 'Y-m-d' ),
            'offset'      => $offset,
            'next_offset' => $next < min( count( $found ), self::MAX_MATCHES ) ? $next : null,
        );
    }

    /**
     * Bookings as list rows, for a write's answer.
     *
     * @param   int[]  $ids   Booking ids.
     * @param   int    $user  User id.
     *
     * @return  array[]
     */
    public static function rows( array $ids, int $user ): array {
        return array_map(
            function ( $id ) use ( $user ) {
                return self::row( (int) $id, $user );
            },
            array_values( $ids )
        );
    }

    /**
     * Whether a customer contact already has an unflagged booking this staff member can see.
     * Only then may their stored contact show in that staff member's answers.
     *
     * @param   int  $customer  Customer contact id.
     * @param   int  $user      Staff user id.
     *
     * @return  bool
     */
    public static function is_own_customer( int $customer, int $user ): bool {
        $row = $customer > 0 ? Customer_Store::get( $customer ) : null;

        if ( ! $row ) {
            return false;
        }

        $ids = self::booking_ids(
            array(
                array(
                    'key'     => '_tt_booking_mcp_typed',
                    'compare' => 'NOT EXISTS',
                ),
                self::contact_clause( $row ),
            ),
            $user,
            self::STORED_STATUSES,
            1
        );

        return (bool) $ids;
    }

    /**
     * Meta query for a contact's bookings: made under its row, or its login's older bookings.
     *
     * @param   array  $row  Contact row.
     *
     * @return  array
     */
    public static function contact_clause( array $row ): array {
        $clause = array(
            'relation' => 'OR',
            array(
                'key'   => '_tt_booking_customer_record',
                'value' => (int) $row['id'],
            ),
        );

        if ( ! empty( $row['wp_user_id'] ) ) {
            $clause[] = array(
                'relation' => 'AND',
                array(
                    'key'   => '_tt_booking_customer',
                    'value' => (int) $row['wp_user_id'],
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_tt_booking_customer_record',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_tt_booking_customer_record',
                        'value'   => array( '', '0' ),
                        'compare' => 'IN',
                    ),
                ),
            );
        }

        return $clause;
    }

    /**
     * The contact id behind a booking: its row, or for an older booking its login's row.
     *
     * @param   Booking  $booking  Booking.
     *
     * @return  int  0 when the booking has none.
     */
    private static function contact_id( Booking $booking ): int {
        if ( $booking->get_customer_record_id() ) {
            return $booking->get_customer_record_id();
        }

        $row = Customer_Store::find_by_user( (int) $booking->get_customer_id() );

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Find bookings by every filter, on the meeting's clock.
     *
     * @param   array     $filters   See list().
     * @param   int       $user      User id.
     * @param   string[]  $statuses  Post statuses.
     *
     * @return  array  `found` as booking id => start timestamp, `limited`.
     */
    private static function find( array $filters, int $user, array $statuses ): array {
        $from       = (string) ( $filters['date_from'] ?? '' );
        $to         = (string) ( $filters['date_to'] ?? '' );
        $text       = trim( (string) ( $filters['customer'] ?? '' ) );
        $meta_query = array( 'relation' => 'AND' );

        // A booking keeps the customer's date, which can be two days off the meeting's
        // (UTC+14 against UTC-12), so look two days wider each way and check below.
        if ( $from ) {
            $meta_query[] = self::date_clause( '>=', $from, '-2 days' );
        }

        if ( $to ) {
            $meta_query[] = self::date_clause( '<=', $to, '+2 days' );
        }

        foreach ( array( 'staff_id' => 'staff', 'meeting_id' => 'appointment' ) as $filter => $key ) {
            if ( ! empty( $filters[ $filter ] ) ) {
                $meta_query[] = array(
                    'key'   => '_tt_booking_' . $key,
                    'value' => (int) $filters[ $filter ],
                );
            }
        }

        if ( '' !== $text ) {
            $meta_query[] = self::text_clause( $text, $user );
        }

        // Four times the limit, because the wider dates and text can match extra rows.
        $ids   = self::booking_ids( $meta_query, $user, $statuses, self::MAX_MATCHES * 4 );
        $found = array();

        update_meta_cache( 'post', $ids );

        foreach ( $ids as $id ) {
            $moment = self::moment( $id );
            $date   = $moment ? $moment->format( 'Y-m-d' ) : '';

            if ( ( $from && ( ! $date || $date < $from ) ) || ( $to && ( ! $date || $date > $to ) ) ) {
                continue;
            }

            if ( '' !== $text && ! self::matches( $text, self::customer( $id, $user ) ) ) {
                continue;
            }

            $found[ $id ] = $moment ? $moment->getTimestamp() : 0;
        }

        return array(
            'found'   => $found,
            'limited' => count( $ids ) >= self::MAX_MATCHES * 4,
        );
    }

    /**
     * Working hours and bookings, per staff member and day. Hours belong to a meeting,
     * so a day lists each meeting's hours in that meeting's own timezone.
     *
     * @param   array  $filters  date_from, date_to, staff_id, offset.
     * @param   int    $user     User id.
     *
     * @return  array|WP_Error   items, limited, today, offset, next_offset.
     */
    public static function get_schedule( array $filters, int $user ) {
        $from = (string) ( $filters['date_from'] ?? '' );
        $to   = (string) ( $filters['date_to'] ?? '' );

        if ( $from > $to ) {
            return new WP_Error( 'invalid_range', __( 'date_from is after date_to.', 'timetics' ) );
        }

        if ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS >= self::SCHEDULE_DAYS ) {
            return new WP_Error(
                'range_too_long',
                /* translators: %d: number of days */
                sprintf( __( 'Ask for at most %d days at a time.', 'timetics' ), self::SCHEDULE_DAYS )
            );
        }

        $staff_id = (int) ( $filters['staff_id'] ?? 0 );

        if ( Mcp_Access::is_staff( $user ) ) {
            if ( $staff_id && $staff_id !== $user ) {
                return new WP_Error( 'forbidden', __( 'Staff can see only their own schedule.', 'timetics' ) );
            }

            $staff_id = $user;
        }

        $days    = self::schedule_hours( $from, $to, $staff_id );
        $matches = self::find(
            array(
                'date_from' => $from,
                'date_to'   => $to,
                'staff_id'  => $staff_id,
            ),
            $user,
            array( 'pending', 'approved', 'completed' )
        );

        asort( $matches['found'] );

        foreach ( array_keys( $matches['found'] ) as $id ) {
            $moment = self::moment( $id );
            $person = (int) self::meta( $id, 'staff' );

            if ( $moment ) {
                $key                          = $moment->format( 'Y-m-d' ) . '|' . $person;
                $days[ $key ]['bookings'][]   = self::schedule_booking( $id, $moment, $user );
            }
        }

        ksort( $days );

        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        $next   = $offset + self::SCHEDULE_ROWS;
        $rows   = array();

        foreach ( array_slice( $days, $offset, self::SCHEDULE_ROWS, true ) as $key => $day ) {
            list( $date, $person ) = explode( '|', $key );

            $rows[] = array(
                'date'          => $date,
                'staff_id'      => (int) $person,
                'staff'         => self::person_name( (int) $person ),
                'working_hours' => $day['working_hours'] ?? array(),
                'bookings'      => $day['bookings'] ?? array(),
            );
        }

        return array(
            'items'       => $rows,
            'limited'     => $matches['limited'],
            'today'       => wp_date( 'Y-m-d' ),
            'offset'      => $offset,
            'next_offset' => $next < count( $days ) ? $next : null,
        );
    }

    /**
     * Each meeting's working hours in a date range, by day and staff member.
     *
     * @param   string  $from      First date.
     * @param   string  $to        Last date.
     * @param   int     $staff_id  One staff member, 0 for all.
     *
     * @return  array   `date|staff` => `working_hours`.
     */
    private static function schedule_hours( string $from, string $to, int $staff_id ): array {
        $days     = array();
        $meetings = Appointment::all( array( 'posts_per_page' => self::SCHEDULE_MEETINGS ) );

        foreach ( $meetings['items'] as $post ) {
            $meeting = new Appointment( $post->ID );

            foreach ( (array) $meeting->get_schedule() as $person => $week ) {
                if ( ( $staff_id && (int) $person !== $staff_id ) || ! is_array( $week ) ) {
                    continue;
                }

                for ( $day = strtotime( $from ), $last = strtotime( $to ); $day <= $last; $day += DAY_IN_SECONDS ) {
                    $date = gmdate( 'Y-m-d', $day );

                    if ( ! self::is_open_on( $meeting, $date ) ) {
                        continue;
                    }

                    foreach ( (array) ( $week[ gmdate( 'D', $day ) ] ?? array() ) as $hours ) {
                        $days[ $date . '|' . (int) $person ]['working_hours'][] = array(
                            'meeting_id' => (int) $meeting->get_id(),
                            'meeting'    => self::short( (string) $meeting->get_name(), self::NAME_LENGTH ),
                            'timezone'   => (string) $meeting->get_timezone(),
                            'from'       => (string) ( $hours['start'] ?? '' ),
                            'to'         => (string) ( $hours['end'] ?? '' ),
                        );
                    }
                }
            }
        }

        return $days;
    }

    /**
     * A booking in a schedule: when and who, no contact or note.
     *
     * @param   int       $id      Booking id.
     * @param   DateTime  $moment  Its start on the meeting's clock.
     * @param   int       $user    User id.
     *
     * @return  array
     */
    private static function schedule_booking( int $id, DateTime $moment, int $user ): array {
        $customer = self::customer( $id, $user );

        return array(
            'id'       => $id,
            'time'     => $moment->format( 'g:ia' ),
            'timezone' => self::zone( $id ),
            'status'   => self::status_name( (string) get_post_status( $id ) ),
            'meeting'  => self::meeting_name( $id ),
            'customer' => self::short( trim( $customer['first_name'] . ' ' . $customer['last_name'] ), self::NAME_LENGTH ),
        );
    }

    /**
     * Whether a meeting takes bookings on a date. The booking form's calendar stops at
     * the meeting's availability dates, so the tools stop there too.
     *
     * @param   Appointment  $meeting  The meeting.
     * @param   string       $date     Y-m-d.
     *
     * @return  bool
     */
    private static function is_open_on( Appointment $meeting, string $date ): bool {
        $window = (array) $meeting->get_availability();
        $start  = (string) ( $window['start'] ?? '' );
        $end    = (string) ( $window['end'] ?? '' );

        return ( '' === $start || $date >= $start ) && ( '' === $end || $date <= $end );
    }

    /**
     * One booking. Another staff member's booking and a missing one answer the same,
     * so an id cannot be probed.
     *
     * @param   int  $id    Booking id.
     * @param   int  $user  User id.
     *
     * @return  array|WP_Error
     */
    public static function get( int $id, int $user ) {
        if ( ! Mcp_Access::can_see_booking( $user, $id ) ) {
            return new WP_Error( 'not_found', __( 'No such booking.', 'timetics' ) );
        }

        $booking  = new Booking( $id );
        $moment   = self::moment( $id );
        $customer = self::customer( $id, $user );
        $series   = $booking->get_series_place();
        $staff_id = (int) $booking->get_staff_id();
        $meeting  = new Appointment( (int) $booking->get_appointment() );
        $note     = self::scalar_text( $booking->get_description() );
        $fields   = self::custom_fields( $booking->get_custom_fields() );
        $cut      = array();

        if ( mb_strlen( $note ) > self::NOTE_LENGTH ) {
            $cut[] = 'note';
        }

        if ( $fields['cut'] ) {
            $cut[] = 'custom_fields';
        }

        $result = array(
            'id'                => $id,
            'date'              => $moment ? $moment->format( 'Y-m-d' ) : '',
            'time'              => $moment ? $moment->format( 'g:ia' ) : '',
            'timezone'          => self::zone( $id ),
            'customer_timezone' => self::meta( $id, 'timezone' ),
            'duration'          => self::scalar_text( $meeting->get_duration() ),
            'status'            => self::status_name( (string) $booking->get_status() ),
            'meeting'           => array(
                'id'   => (int) $booking->get_appointment(),
                'name' => self::meeting_name( $id ),
            ),
            'staff'             => array(
                'id'   => $staff_id,
                'name' => self::staff_name( $id ),
            ),
            'customer'          => array(
                'name'  => self::short( trim( $customer['first_name'] . ' ' . $customer['last_name'] ), self::NAME_LENGTH ),
                'email' => self::short( $customer['email'], 254 ),
                'phone' => self::short( $customer['phone'], 50 ),
            ),
            'location_type'     => self::short( self::scalar_text( $booking->get_location_type() ), self::NAME_LENGTH ),
            'note'              => mb_substr( $note, 0, self::NOTE_LENGTH ),
            'custom_fields'     => $fields['fields'],
            'cancel_reason'     => self::short( self::scalar_text( $booking->get_cancel_reason() ), self::FIELD_LENGTH ),
            'payment'           => array(
                'subtotal'        => $booking->get_order_subtotal(),
                'total'           => $booking->get_total(),
                'currency'        => timetics_get_option( 'currency', 'USD' ),
                'payment_status'  => $booking->get_payment_status(),
                'coupon_code'     => $booking->get_coupon_code(),
                'coupon_discount' => $booking->get_coupon_discount(),
            ),
        );

        if ( $series ) {
            $result['series'] = array(
                'session' => $series['position'],
                'of'      => $series['size'],
            );
        }

        // Staff must not learn the stored customer behind what they typed.
        if ( ! $customer['typed'] ) {
            $result['customer']['id'] = self::contact_id( $booking );
        }

        if ( Mcp_Access::is_owner( $user ) ) {
            $result['staff']['email'] = self::short( self::meta( $id, 'staff_email' ), 254 );
        }

        if ( $cut ) {
            $result['truncated'] = $cut;
            $result['notice']    = __( 'Some long text was shortened. The dashboard has the full text.', 'timetics' );
        }

        return $result;
    }

    /**
     * Free times of a meeting, from the booking form's own list.
     *
     * @param   array  $filters  meeting_id, staff_id, date_from, date_to, offset.
     *
     * @return  array|WP_Error   timezone, items, offset, next_offset.
     */
    public static function get_availability( array $filters ) {
        $meeting = new Appointment( (int) ( $filters['meeting_id'] ?? 0 ) );

        if ( ! $meeting->is_appointment() ) {
            return new WP_Error( 'not_found', __( 'No such meeting.', 'timetics' ) );
        }

        $from = (string) ( $filters['date_from'] ?? '' );
        $to   = (string) ( $filters['date_to'] ?? '' );

        if ( $from > $to ) {
            return new WP_Error( 'invalid_range', __( 'date_from is after date_to.', 'timetics' ) );
        }

        if ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS >= self::FREE_DAYS ) {
            return new WP_Error(
                'range_too_long',
                /* translators: %d: number of days */
                sprintf( __( 'Ask for at most %d days at a time.', 'timetics' ), self::FREE_DAYS )
            );
        }

        $timezone = $meeting->get_timezone();
        $staff    = ! empty( $filters['staff_id'] ) ? array( (int) $filters['staff_id'] ) : array_slice( array_map( 'intval', (array) $meeting->get_staff_ids() ), 0, self::FREE_STAFF );
        $now      = new DateTime( 'now', new DateTimeZone( $timezone ) );
        $items    = array();

        foreach ( $staff as $staff_id ) {
            $entries = Api_Booking::instance()->get_entries(
                array(
                    'meeting_id' => $meeting->get_id(),
                    'staff_id'   => $staff_id,
                    'start_date' => $from,
                    'end_date'   => $to,
                    'timezone'   => $timezone,
                )
            );

            if ( is_wp_error( $entries ) ) {
                return $entries;
            }

            foreach ( $entries['data']['days'] as $day ) {
                if ( ! self::is_open_on( $meeting, $day['date'] ) ) {
                    continue;
                }

                foreach ( $day['slots'] as $slot ) {
                    $start = new DateTime( $day['date'] . ' ' . $slot['start_time'], new DateTimeZone( $timezone ) );

                    if ( 'available' !== $slot['status'] || $start < $now ) {
                        continue;
                    }

                    $item = array(
                        'date'     => $day['date'],
                        'time'     => $slot['start_time'],
                        'staff_id' => $staff_id,
                    );

                    if ( $meeting->get_effective_capacity() > 1 ) {
                        $item['seats_left'] = $meeting->get_effective_capacity() - (int) $slot['booked'];
                    }

                    $items[] = $item;
                }
            }
        }

        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        $next   = $offset + self::FREE_TIMES;

        return array(
            'timezone'    => $timezone,
            'items'       => array_slice( $items, $offset, self::FREE_TIMES ),
            'offset'      => $offset,
            'next_offset' => $next < count( $items ) ? $next : null,
        );
    }

    /**
     * Meetings, in short fields.
     *
     * @param   string  $search  Part of a name.
     *
     * @return  array   items, limited.
     */
    public static function list_meetings( string $search ): array {
        $meetings = Appointment::all(
            array(
                's'              => $search,
                'posts_per_page' => self::MEETINGS,
            )
        );

        return array(
            'items'   => array_map(
                function ( $post ) {
                    return self::meeting_row( new Appointment( $post->ID ) );
                },
                $meetings['items']
            ),
            'limited' => $meetings['total'] > self::MEETINGS,
        );
    }

    /**
     * Staff members. Staff get no email, as the dashboard's staff list gives owners only.
     *
     * @param   int  $user  User id.
     *
     * @return  array  items, limited.
     */
    public static function list_staff( int $user ): array {
        $staff = Staff::all( array( 'number' => self::MEETINGS ) );

        return array(
            'items'   => array_map(
                function ( $person ) use ( $user ) {
                    $row = array(
                        'id'    => (int) $person->ID,
                        'name'  => self::person_name( (int) $person->ID ),
                        'is_me' => (int) $person->ID === $user,
                    );

                    if ( Mcp_Access::is_owner( $user ) ) {
                        $row['email'] = $person->user_email;
                    }

                    return $row;
                },
                $staff['items']
            ),
            'limited' => $staff['total'] > self::MEETINGS,
        );
    }

    /**
     * Find customers. An owner searches every customer. Staff search only the customer
     * fields of bookings they can see, and a flagged booking gives them nothing.
     *
     * @param   string  $text  Part of a name, email or phone.
     * @param   int     $user  User id.
     *
     * @return  array   items, limited.
     */
    public static function find_customer( string $text, int $user ): array {
        return Mcp_Access::is_owner( $user ) ? self::find_any_customer( $text ) : self::find_own_customer( $text, $user );
    }

    /**
     * Every customer, for an owner.
     *
     * @param   string  $text  Search text.
     *
     * @return  array
     */
    private static function find_any_customer( string $text ): array {
        $result = Customer_Store::query(
            array(
                'search'   => self::words( $text )[0] ?? '',
                'per_page' => self::MAX_MATCHES,
            )
        );
        $items  = array();

        foreach ( $result['items'] as $row ) {
            $details = array(
                'first_name' => (string) $row['first_name'],
                'last_name'  => (string) $row['last_name'],
                'email'      => (string) $row['email'],
                'phone'      => (string) $row['phone'],
            );

            if ( self::matches( $text, $details ) ) {
                $items[] = self::customer_row( (int) $row['id'], $details );
            }
        }

        return array(
            'items'   => array_slice( $items, 0, self::CUSTOMERS ),
            'limited' => count( $items ) > self::CUSTOMERS || $result['total'] > self::MAX_MATCHES,
        );
    }

    /**
     * Customers of a staff member's visible, unflagged bookings.
     *
     * @param   string  $text  Search text.
     * @param   int     $user  User id.
     *
     * @return  array
     */
    private static function find_own_customer( string $text, int $user ): array {
        $ids   = self::booking_ids(
            array(
                'relation' => 'AND',
                array(
                    'key'     => '_tt_booking_mcp_typed',
                    'compare' => 'NOT EXISTS',
                ),
                self::text_clause( $text, 0 ),
            ),
            $user,
            self::STORED_STATUSES,
            self::MAX_MATCHES
        );
        $items = array();

        update_meta_cache( 'post', $ids );

        foreach ( $ids as $id ) {
            $details = self::customer( $id, 0 );

            if ( ! self::matches( $text, $details ) ) {
                continue;
            }

            $customer_id = self::contact_id( new Booking( $id ) );
            $key         = $customer_id > 0 ? $customer_id : $details['email'];

            // Newest booking first, so the newest details of a customer win.
            if ( ! isset( $items[ $key ] ) ) {
                $items[ $key ] = self::customer_row( $customer_id, $details );
            }
        }

        return array(
            'items'   => array_values( array_slice( $items, 0, self::CUSTOMERS, true ) ),
            'limited' => count( $items ) > self::CUSTOMERS,
        );
    }

    /**
     * Run a booking query. Staff see only what their dashboard shows them.
     *
     * @param   array     $meta_query  Meta query.
     * @param   int       $user        User id.
     * @param   string[]  $statuses    Post statuses.
     * @param   int       $limit       Most ids.
     *
     * @return  int[]
     */
    private static function booking_ids( array $meta_query, int $user, array $statuses, int $limit ): array {
        $args = array(
            'post_type'      => 'timetics-booking',
            'post_status'    => $statuses,
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bookings live in post meta.
            'meta_query'     => $meta_query,
        );

        if ( ! Mcp_Access::is_owner( $user ) ) {
            $visible          = Booking::get_visible_ids_for_user( $user );
            $args['post__in'] = $visible ? $visible : array( 0 );
        }

        return array_map( 'intval', get_posts( $args ) );
    }

    /**
     * Meta clause for a date, moved by some days.
     *
     * @param   string  $compare  Compare operator.
     * @param   string  $date     Y-m-d.
     * @param   string  $shift    Date modifier.
     *
     * @return  array
     */
    private static function date_clause( string $compare, string $date, string $shift ): array {
        return array(
            'key'     => '_tt_booking_start_date',
            'value'   => gmdate( 'Y-m-d', strtotime( $date . ' ' . $shift ) ),
            'compare' => $compare,
        );
    }

    /**
     * Meta clause for customer text. It finds candidates by the first word, and matches()
     * then checks the rest, because a full name spans two meta keys.
     *
     * @param   string  $text  Search text.
     * @param   int     $user  User id. Staff also search what they typed.
     *
     * @return  array
     */
    private static function text_clause( string $text, int $user ): array {
        $word    = self::words( $text )[0] ?? '';
        $clauses = array( 'relation' => 'OR' );
        $keys    = array( 'customer_fname', 'customer_lname', 'customer_email', 'customer_phone' );

        if ( Mcp_Access::is_staff( $user ) ) {
            $keys[] = 'mcp_typed';
        }

        foreach ( $keys as $key ) {
            $clauses[] = array(
                'key'     => '_tt_booking_' . $key,
                'value'   => $word,
                'compare' => 'LIKE',
            );
        }

        return $clauses;
    }

    /**
     * Lowercase words of a search.
     *
     * @param   string  $text  Search text.
     *
     * @return  string[]
     */
    private static function words( string $text ): array {
        return array_values( array_filter( (array) preg_split( '/\s+/', mb_strtolower( trim( $text ) ) ), 'strlen' ) );
    }

    /**
     * Whether every word of a search is in a customer's details.
     *
     * @param   string  $text     Search text.
     * @param   array   $details  first_name, last_name, email, phone.
     *
     * @return  bool
     */
    private static function matches( string $text, array $details ): bool {
        $haystack = mb_strtolower( $details['first_name'] . ' ' . $details['last_name'] . ' ' . $details['email'] . ' ' . $details['phone'] );

        foreach ( self::words( $text ) as $word ) {
            if ( false === mb_strpos( $haystack, $word ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Customer details this user may see. On a flagged booking staff get only what
     * they typed, because the linked customer's stored contact is not theirs to read.
     *
     * @param   int  $id    Booking id.
     * @param   int  $user  User id.
     *
     * @return  array  first_name, last_name, email, phone, typed.
     */
    private static function customer( int $id, int $user ): array {
        $typed = Mcp_Access::is_staff( $user ) ? get_post_meta( $id, '_tt_booking_mcp_typed', true ) : '';

        if ( is_array( $typed ) ) {
            return array(
                'first_name' => (string) ( $typed['first_name'] ?? '' ),
                'last_name'  => (string) ( $typed['last_name'] ?? '' ),
                'email'      => (string) ( $typed['email'] ?? '' ),
                'phone'      => (string) ( $typed['phone'] ?? '' ),
                'typed'      => true,
            );
        }

        return array(
            'first_name' => self::meta( $id, 'customer_fname' ),
            'last_name'  => self::meta( $id, 'customer_lname' ),
            'email'      => self::meta( $id, 'customer_email' ),
            'phone'      => self::meta( $id, 'customer_phone' ),
            'typed'      => false,
        );
    }

    /**
     * A customer in a find-customer answer.
     *
     * @param   int    $id       Customer id.
     * @param   array  $details  From customer().
     *
     * @return  array
     */
    private static function customer_row( int $id, array $details ): array {
        return array(
            'id'    => $id,
            'name'  => self::short( trim( $details['first_name'] . ' ' . $details['last_name'] ), self::NAME_LENGTH ),
            'email' => self::short( $details['email'], 254 ),
            'phone' => self::short( $details['phone'], 50 ),
        );
    }

    /**
     * A booking in a list: short fields only, because a row limit alone does not limit size.
     *
     * @param   int  $id    Booking id.
     * @param   int  $user  User id.
     *
     * @return  array
     */
    private static function row( int $id, int $user ): array {
        $moment   = self::moment( $id );
        $customer = self::customer( $id, $user );

        return array(
            'id'       => $id,
            'date'     => $moment ? $moment->format( 'Y-m-d' ) : '',
            'time'     => $moment ? $moment->format( 'g:ia' ) : '',
            'timezone' => self::zone( $id ),
            'status'   => self::status_name( (string) get_post_status( $id ) ),
            'meeting'  => self::meeting_name( $id ),
            'staff'    => self::staff_name( $id ),
            'customer' => self::short( trim( $customer['first_name'] . ' ' . $customer['last_name'] ), self::NAME_LENGTH ),
            'email'    => self::short( $customer['email'], 254 ),
            'phone'    => self::short( $customer['phone'], 50 ),
        );
    }

    /**
     * The name of a booking's meeting. Pro's series sessions carry no copy of it.
     *
     * @param   int  $id  Booking id.
     *
     * @return  string
     */
    private static function meeting_name( int $id ): string {
        $name = self::meta( $id, 'appointment_name' );

        return self::short( '' !== $name ? $name : (string) get_the_title( (int) self::meta( $id, 'appointment' ) ), self::NAME_LENGTH );
    }

    /**
     * The name of a booking's staff member. The booking copies only first and last
     * names, so a staff member without them needs their display name.
     *
     * @param   int  $id  Booking id.
     *
     * @return  string
     */
    private static function staff_name( int $id ): string {
        $name = trim( self::meta( $id, 'staff_fname' ) . ' ' . self::meta( $id, 'staff_lname' ) );

        return '' !== $name ? self::short( $name, self::NAME_LENGTH ) : self::person_name( (int) self::meta( $id, 'staff' ) );
    }

    /**
     * A staff member's name from their account.
     *
     * @param   int  $id  User id.
     *
     * @return  string
     */
    private static function person_name( int $id ): string {
        $person = get_userdata( $id );

        if ( ! $person ) {
            return '';
        }

        $name = trim( $person->first_name . ' ' . $person->last_name );

        return self::short( '' !== $name ? $name : (string) $person->display_name, self::NAME_LENGTH );
    }

    /**
     * A meeting in a list.
     *
     * @param   Appointment  $meeting  The meeting.
     *
     * @return  array
     */
    private static function meeting_row( Appointment $meeting ): array {
        return array(
            'id'        => (int) $meeting->get_id(),
            'name'      => self::short( (string) $meeting->get_name(), self::NAME_LENGTH ),
            'type'      => (string) $meeting->get_type(),
            'duration'  => (string) $meeting->get_duration(),
            'capacity'  => $meeting->get_effective_capacity(),
            'prices'    => array_map(
                function ( $ticket ) {
                    return array(
                        'name'  => self::short( (string) ( $ticket['ticket_name'] ?? '' ), self::NAME_LENGTH ),
                        'price' => $ticket['ticket_price'] ?? 0,
                    );
                },
                array_values( array_filter( (array) $meeting->get_price(), 'is_array' ) )
            ),
            'staff_ids' => array_map( 'intval', (array) $meeting->get_staff_ids() ),
            'locations' => array_map(
                function ( $location ) {
                    return array(
                        'type'     => self::short( (string) ( $location['location_type'] ?? '' ), self::NAME_LENGTH ),
                        'location' => self::short( self::scalar_text( $location['location'] ?? '' ), self::FIELD_LENGTH ),
                    );
                },
                array_values( array_filter( (array) $meeting->get_locations(), 'is_array' ) )
            ),
            'timezone'  => (string) $meeting->get_timezone(),
        );
    }

    /**
     * When a booking happens, in its meeting's timezone. A booking keeps the customer's.
     *
     * @param   int  $id  Booking id.
     *
     * @return  DateTime|null  Null when the booking holds no readable date.
     */
    private static function moment( int $id ) {
        $date = self::meta( $id, 'start_date' );
        $time = self::meta( $id, 'start_time' );
        $to   = self::zone( $id );
        $from = self::meta( $id, 'timezone' );

        $from = '' !== $from ? $from : $to;

        if ( ! $date || ! timetics_is_valid_timezone( $from ) ) {
            return null;
        }

        try {
            return timetics_convert_timezone( $date . ' ' . $time, $from, $to );
        } catch ( \Exception $e ) {
            return null;
        }
    }

    /**
     * The timezone a booking is shown in: its meeting's, or the site's without one.
     *
     * @param   int  $id  Booking id.
     *
     * @return  string
     */
    private static function zone( int $id ): string {
        $zone = (string) get_post_meta( (int) self::meta( $id, 'appointment' ), '_tt_apointment_timezone', true );

        return timetics_is_valid_timezone( $zone ) ? $zone : wp_timezone_string();
    }

    /**
     * Post statuses for a status filter.
     *
     * @param   string  $status  Asked status.
     *
     * @return  string[]
     */
    private static function status_filter( string $status ): array {
        return '' === $status ? self::STORED_STATUSES : array( self::status_name( $status ) );
    }

    /**
     * One name for a status, whichever spelling it came in.
     *
     * @param   string  $status  Status.
     *
     * @return  string
     */
    private static function status_name( string $status ): string {
        return in_array( $status, array( 'canceled', 'cancelled' ), true ) ? 'cancel' : $status;
    }

    /**
     * Custom fields, cut to the limits.
     *
     * @param   mixed  $raw  Stored as an array or as JSON.
     *
     * @return  array  fields, cut.
     */
    private static function custom_fields( $raw ): array {
        $all    = is_array( $raw ) ? $raw : (array) json_decode( (string) $raw, true );
        $fields = array();
        $cut    = count( $all ) > self::CUSTOM_FIELDS;

        foreach ( array_slice( $all, 0, self::CUSTOM_FIELDS, true ) as $key => $value ) {
            $text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
            $cut  = $cut || mb_strlen( $text ) > self::FIELD_LENGTH;

            $fields[ self::short( (string) $key, self::NAME_LENGTH ) ] = mb_substr( $text, 0, self::FIELD_LENGTH );
        }

        return array(
            'fields' => $fields,
            'cut'    => $cut,
        );
    }

    /**
     * A booking's stored value as text.
     *
     * @param   int     $id   Booking id.
     * @param   string  $key  Key without the prefix.
     *
     * @return  string
     */
    private static function meta( int $id, string $key ): string {
        return self::scalar_text( get_post_meta( $id, '_tt_booking_' . $key, true ) );
    }

    /**
     * A value as text. Empty for anything that is not a single value.
     *
     * @param   mixed  $value  The value.
     *
     * @return  string
     */
    private static function scalar_text( $value ): string {
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * Cut text to a length.
     *
     * @param   string  $text    The text.
     * @param   int     $length  Most characters.
     *
     * @return  string
     */
    private static function short( string $text, int $length ): string {
        return mb_substr( $text, 0, $length );
    }
}
