<?php
/**
 * Booking-links email: a customer's upcoming bookings with their reschedule
 * and cancel links.
 *
 * Fired by the Aisentic chat through `timetics_send_manage_links`. The chat
 * must never learn whether an email has bookings, so nothing here returns or
 * prints anything: only the inbox owner sees the list.
 *
 * @package Timetics
 */

namespace Timetics\Core\Bookings;

use Timetics\Core\Admin\Notification;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Emails\Manage_Links_Customer_Email;
use Timetics\Core\Staffs\Staff;
use Timetics\Utils\Singleton;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Class Manage_Links_Sender
 */
class Manage_Links_Sender {
    use Singleton;

    /**
     * Most bookings one email lists.
     */
    const MAX = 10;

    /**
     * One email per address in this window, whoever asks. Stops this hook
     * from being used to flood a customer's inbox.
     */
    const THROTTLE_SECONDS = 10 * MINUTE_IN_SECONDS;

    /**
     * Initialization
     *
     * @return void
     */
    public function init() {
        add_action( 'timetics_send_manage_links', [ $this, 'send' ], 10, 2 );
    }

    /**
     * Email the customer their upcoming bookings: the normal Timetics email,
     * plus the automation hook for owners who build their own flow.
     *
     * Silent when the email is unknown or has no upcoming bookings.
     * Callers must fire `timetics_send_manage_links` outside the visitor's
     * request (after the response is sent, or from cron), so the time it
     * takes can't tell the visitor whether the email has bookings.
     *
     * @param string $email Email the visitor gave in the chat.
     * @param array  $hint  Which booking they likely mean.
     *
     * @return void
     */
    public function send( $email, $hint = [] ) {
        if ( ! is_email( $email ) ) {
            return;
        }

        // Set for unknown emails too, so the throttle says nothing about
        // whether the email has bookings.
        $throttle = 'timetics_manage_links_' . md5( strtolower( trim( $email ) ) );
        if ( get_transient( $throttle ) ) {
            return;
        }
        set_transient( $throttle, 1, self::THROTTLE_SECONDS );

        $rows = $this->upcoming( $email, is_array( $hint ) ? $hint : [] );
        if ( ! $rows ) {
            return;
        }

        $customer = get_user_by( 'email', $email );
        if ( ! $customer instanceof \WP_User ) {
            return;
        }

        $rows_html = self::rows_html( $rows );

        ( new Manage_Links_Customer_Email( $customer, $rows_html ) )->send();

        do_action(
            'timetics_gln_hook',
            'manage_links_requested',
            [
                'customer_email'         => esc_html( $customer->user_email ),
                'customer_name'          => esc_html( $customer->display_name ),
                'upcoming_bookings'      => $rows_html,
                // Anchor for delay nodes. The SDK reads `<key>_timestamp` as
                // true UTC; without it a delay falls back to current_time().
                'requested_at_timestamp' => time(),
            ]
        );
    }

    /**
     * The booking rows as email HTML, every value escaped: the automation SDK
     * inserts tag values without escaping them.
     *
     * @param array $rows Rows from upcoming().
     *
     * @return string
     */
    public static function rows_html( array $rows ) {
        $button = 'display: inline-block; padding: 9px 11px 10px 11px; margin: 10px 8px 0 0; border-radius: 5px; font-weight: 600; font-size: 13px; border: 1px solid #D8DCE2; text-decoration: none; color: #3161F1;';
        $html   = '';

        foreach ( $rows as $row ) {
            $booking = $row['booking'];
            $meeting = new Appointment( $booking->get_appointment() );
            $staff   = new Staff( $booking->get_staff_id() );
            $when    = timetics_format_email_datetime( $booking->get_start_date(), $booking->get_start_time() );

            $html .= '<div style="border: 1px solid ' . ( $row['highlight'] ? '#3161F1' : '#E2ECF4' ) . '; border-radius: 12px; padding: 20px; margin: 16px 0;">';
            if ( $row['highlight'] ) {
                $html .= '<p style="font-size: 12px; font-weight: 600; color: #3161F1; margin: 0 0 8px;">' . esc_html__( 'Most likely the one you mean', 'timetics' ) . '</p>';
            }
            $html .= '<p style="font-weight: 600; font-size: 15px; color: #0C274A; margin: 0 0 5px;">' . esc_html( $meeting->get_name() ) . '</p>';
            $html .= '<p style="font-size: 14px; color: #556880; margin: 0;">' . esc_html(
                sprintf(
                    /* translators: 1: Time, 2: Day, 3: Date, 4: Time zone */
                    __( '%1$s, %2$s %3$s (%4$s)', 'timetics' ),
                    $when['time'],
                    $when['day'],
                    $when['date'],
                    $booking->get_timezone()
                )
            ) . '</p>';
            if ( $staff->get_display_name() ) {
                $html .= '<p style="font-size: 14px; color: #556880; margin: 0;">' . esc_html( $staff->get_display_name() ) . '</p>';
            }
            $html .= '<a style="' . $button . '" href="' . esc_url( $booking->get_manage_url( 'reschedule' ) ) . '">' . esc_html__( 'Reschedule', 'timetics' ) . '</a>';
            $html .= '<a style="' . $button . '" href="' . esc_url( $booking->get_manage_url( 'cancel' ) ) . '">' . esc_html__( 'Cancel', 'timetics' ) . '</a>';
            $html .= '</div>';
        }

        return $html;
    }

    /**
     * Upcoming bookings of the customer with this email, soonest first.
     *
     * When exactly one booking matches the hint it goes first, highlighted.
     *
     * @param string $email Customer email, any case.
     * @param array  $hint  [ 'service' => words of the meeting name, 'date' => 'Y-m-d' or '' ].
     *
     * @return array[] Up to MAX rows of [ 'booking' => Booking, 'highlight' => bool ].
     */
    public function upcoming( $email, $hint = [] ) {
        $user = get_user_by( 'email', (string) $email );
        if ( ! $user ) {
            return [];
        }

        $today    = new \DateTimeImmutable( 'today', wp_timezone() );
        $tomorrow = $today->modify( '+1 day' )->format( 'Y-m-d' );

        // Bookings around today are checked one by one against the clock in
        // their own timezone, so the ones already over never take a place in
        // the list. Later days need no check.
        $near  = $this->query( $user->ID, [ $today->modify( '-1 day' )->format( 'Y-m-d' ), $tomorrow ], 'BETWEEN' );
        $later = $this->query( $user->ID, $tomorrow, '>', self::MAX );

        // The query orders by date only, so bookings on the last date it
        // reached may be cut at any time of day. Take that whole day.
        if ( count( $later ) === self::MAX ) {
            $later = array_merge( $later, $this->query( $user->ID, end( $later )->get_start_date(), '=' ) );
        }

        $bookings = [];
        $now      = time();
        foreach ( array_merge( $near, $later ) as $booking ) {
            $start = Notification::get_booking_timestamp( $booking );
            if ( $start > $now ) {
                $bookings[ $booking->get_id() ] = [ $start, $booking ];
            }
        }

        uasort(
            $bookings,
            function ( $a, $b ) {
                return $a[0] <=> $b[0];
            }
        );

        $rows = [];
        foreach ( array_slice( $bookings, 0, self::MAX ) as $item ) {
            $rows[] = [
                'booking'   => $item[1],
                'highlight' => false,
            ];
        }

        return $this->hint_first( $rows, $hint );
    }

    /**
     * Approved and pending bookings of a customer by start date.
     *
     * @param int          $customer_id User id.
     * @param string|array $date        Date, or [ from, to ] for BETWEEN.
     * @param string       $compare     Meta compare.
     * @param int          $limit       Most bookings.
     *
     * @return Booking[] By start date.
     */
    private function query( $customer_id, $date, $compare, $limit = 50 ) {
        // Note: 50 per day range is far above one customer's bookings; raise it if that ever stops being true.
        $query = new WP_Query(
            [
                'post_type'      => 'timetics-booking',
                'post_status'    => [ 'approved', 'pending' ],
                'posts_per_page' => $limit,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bookings are post meta.
                'meta_query'     => [
                    'relation' => 'AND',
                    [
                        'key'   => '_tt_booking_customer',
                        'value' => (int) $customer_id,
                    ],
                    'start'    => [
                        'key'     => '_tt_booking_start_date',
                        'value'   => $date,
                        'compare' => $compare,
                        'type'    => 'DATE',
                    ],
                ],
                'orderby'        => [ 'start' => 'ASC' ],
            ]
        );

        update_meta_cache( 'post', $query->posts );

        return array_map(
            function ( $id ) {
                return new Booking( $id );
            },
            $query->posts
        );
    }

    /**
     * Move the one booking that matches the hint to the top.
     *
     * Every hint word must be in the meeting name, and the date must match
     * when given. No match or several matches leave the list as it is.
     *
     * @param array $rows Rows, soonest first.
     * @param array $hint Hint.
     *
     * @return array
     */
    private function hint_first( array $rows, $hint ) {
        $service = $hint['service'] ?? '';
        $date    = $hint['date'] ?? '';
        $words   = array_filter( preg_split( '/\s+/', $this->lower( is_string( $service ) ? $service : '' ) ) );
        $date    = is_string( $date ) ? $date : '';

        if ( ! $words && '' === $date ) {
            return $rows;
        }

        $matches = [];
        foreach ( $rows as $index => $row ) {
            $booking = $row['booking'];
            $name    = $this->lower( ( new Appointment( $booking->get_appointment() ) )->get_name() );

            $named = true;
            foreach ( $words as $word ) {
                if ( false === strpos( $name, $word ) ) {
                    $named = false;
                    break;
                }
            }

            if ( $named && ( '' === $date || $booking->get_start_date() === $date ) ) {
                $matches[] = $index;
            }
        }

        if ( 1 !== count( $matches ) ) {
            return $rows;
        }

        $top              = $rows[ $matches[0] ];
        $top['highlight'] = true;
        unset( $rows[ $matches[0] ] );

        return array_merge( [ $top ], array_values( $rows ) );
    }

    /**
     * Lowercase, multibyte safe.
     *
     * @param string $text Text.
     *
     * @return string
     */
    private function lower( $text ) {
        $text = trim( (string) $text );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
    }
}
