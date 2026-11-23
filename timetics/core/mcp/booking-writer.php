<?php
/**
 * MCP writes
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use DateTime;
use DateTimeZone;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Api_Booking;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Bookings\Booking_Lock;
use Timetics\Core\Bookings\Whatsapp_Consent;
use Timetics\Core\Customers\Customer_Store;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the write tools. Each one first answers with a preview and a token, and saves
 * only when called again with that token and the same fields. Saves go through the
 * booking routes, so emails, calendar sync and Pro's hooks run as for the dashboard.
 */
class Booking_Writer {

    const CREATE_TOOL     = 'timetics/create-booking';
    const UPDATE_TOOL     = 'timetics/update-booking';
    const RESCHEDULE_TOOL = 'timetics/reschedule-booking';
    const CANCEL_TOOL     = 'timetics/cancel-booking';

    const STATUS_CHANGES = array(
        'pending'  => array( 'approved', 'completed' ),
        'approved' => array( 'completed' ),
    );
    const ACTIVE         = array( 'pending', 'approved' );
    const TYPED_META     = '_tt_booking_mcp_typed';

    /**
     * Preview or save a new booking.
     *
     * @param   array  $input  meeting_id, staff_id, date, time, customer, note, location_type,
     *                         and confirm_token on the second call.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error
     */
    public static function create( array $input, int $user ) {
        $token = (string) ( $input['confirm_token'] ?? '' );
        unset( $input['confirm_token'] );

        $check = function () use ( $input, $user ) {
            $refused = self::refuse_create( $input, $user );

            return $refused ? $refused : array();
        };

        if ( '' !== $token ) {
            return Confirmation::execute(
                $token,
                $user,
                self::CREATE_TOOL,
                $input,
                $check,
                function ( $payload ) use ( $input, $user, $check ) {
                    return Booking_Lock::run(
                        function () use ( $input, $payload, $user, $check ) {
                            $changed = Confirmation::recheck( $payload, $check );

                            return $changed ? $changed : self::save_create( $input, $payload, $user );
                        }
                    );
                }
            );
        }

        $refused = $check();
        if ( is_wp_error( $refused ) ) {
            return $refused;
        }

        $open = Confirmation::open_operation( self::CREATE_TOOL, $user, 0, $input );
        if ( $open ) {
            return $open;
        }

        $prepared = self::prepare_create( $input, $user );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        return array(
            'preview'            => true,
            'confirm_token'      => Confirmation::issue( self::CREATE_TOOL, $user, 0, $input, array(), $prepared['preview'] ),
            'expires_in_seconds' => Confirmation::TOKEN_LIFETIME,
            'emails'             => self::emails_on( 'created' ),
            'payment'            => __( 'Pay later. Nothing is charged.', 'timetics' ),
            'next_step'          => self::next_step(),
        ) + $prepared['preview'];
    }

    /**
     * Whether this user may book this meeting under this staff member.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   Who is asking.
     *
     * @return  WP_Error|null
     */
    private static function refuse_create( array $input, int $user ) {
        if ( ! Mcp_Access::can_change( $user ) ) {
            return self::access_denied();
        }

        if ( Mcp_Access::is_staff( $user ) && (int) ( $input['staff_id'] ?? 0 ) !== $user ) {
            return new WP_Error( 'forbidden', __( 'Staff can book only under their own name.', 'timetics' ) );
        }

        $meeting = new Appointment( (int) ( $input['meeting_id'] ?? 0 ) );
        if ( ! $meeting->is_appointment() ) {
            return new WP_Error( 'not_found', __( 'No such meeting.', 'timetics' ) );
        }

        return self::refuse_seat_plan( $meeting );
    }

    /**
     * Seats are picked on a map, which a chat cannot show.
     *
     * @param   Appointment  $meeting  Meeting.
     *
     * @return  WP_Error|null
     */
    private static function refuse_seat_plan( Appointment $meeting ) {
        return 'seat' === $meeting->get_type()
            ? new WP_Error( 'use_dashboard', __( 'Seat plan meetings are booked in the dashboard.', 'timetics' ) )
            : null;
    }

    /**
     * Work out the booking the save would make, with every check it would run. Saves
     * nothing and creates no customer.
     *
     * @param   array  $input  Tool input.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error  body for the booking route, typed contact, preview.
     */
    private static function prepare_create( array $input, int $user ) {
        $meeting = new Appointment( (int) $input['meeting_id'] );
        $start   = self::meeting_time( $meeting, (string) $input['date'], (string) $input['time'] );
        if ( is_wp_error( $start ) ) {
            return $start;
        }

        $customer = self::customer_for( (array) ( $input['customer'] ?? array() ), $user );
        if ( is_wp_error( $customer ) ) {
            return $customer;
        }

        $location = self::location_of( $meeting, (string) ( $input['location_type'] ?? '' ) );
        $end      = ( clone $start )->modify( '+' . (int) $meeting->get_interval() . ' seconds' );
        $body     = $customer['send'] + array(
            'appointment'    => $meeting->get_id(),
            'staff'          => (int) $input['staff_id'],
            'start_date'     => $start->format( 'Y-m-d' ),
            'date'           => $start->format( 'Y-m-d' ),
            'end_date'       => $end->format( 'Y-m-d' ),
            'start_time'     => $start->format( 'g:ia' ),
            'end_time'       => $end->format( 'g:ia' ),
            'timezone'       => $meeting->get_timezone(),
            // AI never takes money, and price, payment status and WhatsApp consent are never sent.
            'payment_method' => 'local',
            'location_type'  => $location['location_type'],
            'location'       => $location['location'],
            'description'    => sanitize_textarea_field( (string) ( $input['note'] ?? '' ) ),
        );

        /**
         * Filters the booking request a create-booking call sends. Timetics Pro adds series
         * and coupon input here. Return a WP_Error to refuse.
         *
         * @param array $body  Booking request.
         * @param array $input Tool input.
         * @param int   $user  Who is asking.
         */
        $body = apply_filters( 'timetics_mcp_create_body', $body, $input, $user );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // The route runs this filter again under the same lock, so a picked host is the same.
        /** This filter is documented in core/bookings/api-booking.php */
        $booked = apply_filters( 'timetics_booking_appointment_data', $body, 0 );
        if ( is_wp_error( $booked ) ) {
            return $booked;
        }
        if ( ! is_array( $booked ) ) {
            return new WP_Error( 'invalid_booking_data', __( 'Invalid booking data.', 'timetics' ) );
        }

        $staff = (int) ( $booked['staff'] ?? 0 );
        if ( Mcp_Access::is_staff( $user ) && $staff !== $user ) {
            return new WP_Error( 'forbidden', __( 'This meeting picks its own host, and staff can book only under their own name.', 'timetics' ) );
        }

        $refused = self::refuse_unoffered( $meeting, $staff, $start );
        if ( ! $refused ) {
            $refused = Api_Booking::instance()->validate_booking( $meeting->get_id(), $booked );
        }
        if ( ! $refused ) {
            $refused = self::refuse_phone( $meeting, $booked );
        }
        if ( $refused ) {
            return $refused;
        }

        $price = function () use ( $booked, $body ) {
            return Api_Booking::instance()->price_booking( $booked, $body['email'] );
        };

        /**
         * Filters the price of a create-booking call. Timetics Pro prices a coupon here
         * without taking its use, which only the save may do.
         *
         * @param array|WP_Error|null $pricing Null to use $price.
         * @param callable            $price   Prices the booking the normal way.
         */
        $pricing = apply_filters( 'timetics_mcp_price', null, $price );
        $pricing = null === $pricing ? $price() : $pricing;
        if ( is_wp_error( $pricing ) ) {
            return $pricing;
        }
        if ( ! is_array( $pricing ) || ! isset( $pricing['subtotal'], $pricing['discount'], $pricing['total'], $pricing['coupon_code'] ) ) {
            return new WP_Error( 'invalid_booking_pricing', __( 'The booking price could not be worked out.', 'timetics' ) );
        }

        $preview = array(
            'meeting'           => array(
                'id'   => $meeting->get_id(),
                'name' => (string) $meeting->get_name(),
            ),
            'staff'             => array(
                'id'   => $staff,
                'name' => self::person_name( $staff ),
            ),
            'date'              => $start->format( 'Y-m-d' ),
            'time'              => $start->format( 'g:ia' ),
            'end_time'          => $end->format( 'g:ia' ),
            'timezone'          => $meeting->get_timezone(),
            'location_type'     => $location['location_type'],
            'customer'          => $customer['show'],
            'note'              => $body['description'],
            'status'            => (string) timetics_get_option( 'default_booking_status', 'approved' ),
            'price'             => array(
                'subtotal' => $pricing['subtotal'],
                'discount' => $pricing['discount'],
                'total'    => $pricing['total'],
                'coupon'   => $pricing['coupon_code'],
                'currency' => (string) timetics_get_option( 'currency', 'USD' ),
            ),
            'expected_bookings' => 1 + count( (array) ( $booked['recurring_dates'] ?? array() ) ),
        );

        if ( ! empty( $booked['recurring_dates'] ) ) {
            $preview['series_dates'] = array_values( array_merge( array( $start->format( 'Y-m-d' ) ), (array) $booked['recurring_dates'] ) );
        }

        if ( $staff !== (int) $input['staff_id'] ) {
            $preview['notice'] = __( 'This meeting picks its host by turn, so the host differs from the one asked for.', 'timetics' );
        }

        return array(
            'body'    => $body,
            'typed'   => $customer['typed'],
            'preview' => $preview,
        );
    }

    /**
     * Save a confirmed booking. Runs inside the booking lock, so nothing changes between
     * the last check and the save. The route takes the same lock again, which MySQL allows.
     *
     * @param   array  $input    Tool input.
     * @param   array  $payload  Token payload.
     * @param   int    $user     Who is asking.
     *
     * @return  array|WP_Error  booking_ids and result fields.
     */
    private static function save_create( array $input, array $payload, int $user ) {
        $prepared = self::prepare_create( $input, $user );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        if ( ! Confirmation::matches_preview( $payload, $prepared['preview'] ) ) {
            return new WP_Error( 'preview_changed', __( 'The time, host or price changed after the preview. Nothing was saved. Ask for a new preview.', 'timetics' ) );
        }

        $mail_before = did_action( 'wp_mail_failed' );
        $typed       = $prepared['typed'];
        $save_typed  = function ( $booking_id ) use ( $typed ) {
            update_post_meta( (int) $booking_id, self::TYPED_META, wp_slash( $typed ) );
        };

        // Priority 1 on both hooks, so each booking is private before any listener can fail.
        // Pro saves a series' later sessions with the schedule hook only.
        if ( $typed ) {
            add_action( 'timetics_after_booking_create', $save_typed, 1 );
            add_action( 'timetics_after_booking_schedule', $save_typed, 1 );
        }

        $request = new WP_REST_Request( 'POST', '/timetics/v1/bookings' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $prepared['body'] ) );

        try {
            $failed = self::failure(
                self::keeping_customers(
                    function () use ( $request ) {
                        return Api_Booking::instance()->create_item( $request );
                    }
                )
            );
        } finally {
            remove_action( 'timetics_after_booking_create', $save_typed, 1 );
            remove_action( 'timetics_after_booking_schedule', $save_typed, 1 );
        }

        if ( $failed ) {
            return $failed;
        }

        $ids = Confirmation::work_of( $payload['op'] );

        // An older Pro saves later sessions without the schedule hook, so the first lists them.
        foreach ( $ids as $id ) {
            $ids = array_merge( $ids, array_map( 'intval', (array) ( new Booking( $id ) )->get_recurrence() ) );
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );

        foreach ( $typed ? $ids : array() as $id ) {
            $save_typed( $id );
        }

        return array(
            'booking_ids'  => $ids,
            'bookings'     => Booking_Reader::rows( $ids, $user ),
            'notification' => self::sent( 'created', $mail_before ),
        );
    }

    /**
     * Who the customer is. The booking keeps what was typed; an existing contact with the
     * email gets the booking, and neither that contact nor any login is rewritten.
     *
     * @param   array  $typed  first_name, last_name, email, phone.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error  send, show, typed (booking meta for a staff walk-in, else empty).
     */
    private static function customer_for( array $typed, int $user ) {
        $typed = array(
            'first_name' => sanitize_text_field( (string) ( $typed['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) ( $typed['last_name'] ?? '' ) ),
            'email'      => sanitize_email( (string) ( $typed['email'] ?? '' ) ),
            'phone'      => sanitize_text_field( (string) ( $typed['phone'] ?? '' ) ),
        );

        if ( '' === $typed['first_name'] || ! is_email( $typed['email'] ) ) {
            return new WP_Error( 'invalid_customer', __( 'A customer needs a first name and a valid email.', 'timetics' ) );
        }

        $row      = Customer_Store::find_by_email( $typed['email'] );
        $existing = $row ? (int) $row['id'] : 0;

        if ( ! Mcp_Access::is_staff( $user ) ) {
            $show = $typed;

            if ( $existing ) {
                $show += array(
                    'existing_customer_id' => $existing,
                    'notice'               => __( 'This email is already a customer. The booking is added to them and shows what you typed; their saved details stay as they are.', 'timetics' ),
                );
            }

            return array(
                'send'  => $typed,
                'show'  => $show,
                'typed' => array(),
            );
        }

        // Every staff walk-in keeps what was typed, also for a new email, so the answers
        // cannot tell staff whether an email already belongs to someone.
        $is_own = $existing && Booking_Reader::is_own_customer( $existing, $user );

        return array(
            'send'  => $typed,
            'show'  => $typed,
            'typed' => $is_own ? array() : $typed,
        );
    }

    /**
     * Run a save that links existing customers without rewriting them. The routes let
     * anyone with manage_timetics, staff included, overwrite a customer from the request.
     *
     * @param   callable  $save  The save.
     *
     * @return  mixed  What it returned.
     */
    private static function keeping_customers( callable $save ) {
        add_filter( 'timetics_customer_make_may_edit', '__return_false' );

        try {
            return $save();
        } finally {
            remove_filter( 'timetics_customer_make_may_edit', '__return_false' );
        }
    }

    /**
     * A meeting location. The first one when none is asked for.
     *
     * @param   Appointment  $meeting  Meeting.
     * @param   string       $type     Asked location type.
     *
     * @return  array  location_type, location.
     */
    private static function location_of( Appointment $meeting, string $type ): array {
        $locations = array_values( array_filter( (array) $meeting->get_locations(), 'is_array' ) );

        foreach ( $locations as $location ) {
            if ( '' === $type || ( $location['location_type'] ?? '' ) === $type ) {
                return array(
                    'location_type' => (string) ( $location['location_type'] ?? '' ),
                    'location'      => is_scalar( $location['location'] ?? '' ) ? (string) ( $location['location'] ?? '' ) : '',
                );
            }
        }

        // validate_booking() refuses a type the meeting does not have.
        return array(
            'location_type' => $type,
            'location'      => '',
        );
    }

    /**
     * Refuse a time the booking form would not offer. The form's list is the one place
     * that checks working hours, notice, other meetings of the staff member and Pro's
     * calendar busy times.
     *
     * @param   Appointment  $meeting  Meeting.
     * @param   int          $staff    Staff member.
     * @param   DateTime     $start    Start, in the meeting's timezone.
     *
     * @return  WP_Error|null
     */
    private static function refuse_unoffered( Appointment $meeting, int $staff, DateTime $start ) {
        $date = $start->format( 'Y-m-d' );
        $free = Booking_Reader::get_availability(
            array(
                'meeting_id' => $meeting->get_id(),
                'staff_id'   => $staff,
                'date_from'  => $date,
                'date_to'    => $date,
            )
        );

        if ( is_wp_error( $free ) ) {
            return $free;
        }

        foreach ( $free['items'] as $item ) {
            if ( $item['date'] === $date && $item['time'] === $start->format( 'g:ia' ) && (int) $item['staff_id'] === $staff ) {
                return null;
            }
        }

        return new WP_Error( 'slot_not_offered', __( 'That time is not free. Check get-availability for times that are.', 'timetics' ) );
    }

    /**
     * The route's phone rule, so a missing phone fails in the preview and not after Allow.
     *
     * @param   Appointment  $meeting  Meeting.
     * @param   array        $body     Booking request.
     *
     * @return  WP_Error|null
     */
    private static function refuse_phone( Appointment $meeting, array $body ) {
        $whatsapp = apply_filters( 'timetics_whatsapp_enabled', false );
        $phone    = trim( (string) $body['phone'] );
        $required = 'attendee-call' === $body['location_type'] || ( $whatsapp && 'required' === $meeting->get_phone_field_mode() );

        if ( ( $required || ( $whatsapp && '' !== $phone ) ) && '' === Whatsapp_Consent::normalize_phone( $phone ) ) {
            return new WP_Error( 'invalid_booking_phone', __( 'This meeting needs a valid phone number.', 'timetics' ) );
        }

        return null;
    }

    /**
     * Preview or save a change of status or note.
     *
     * @param   array  $input  id, status, note, and confirm_token on the second call.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error
     */
    public static function update( array $input, int $user ) {
        return self::run_change(
            self::UPDATE_TOOL,
            $input,
            $user,
            array( 'status', 'note' ),
            function ( array $change, array $state ) {
                if ( ! isset( $change['status'] ) && ! isset( $change['note'] ) ) {
                    return new WP_Error( 'nothing_to_change', __( 'Send a status or a note to change.', 'timetics' ) );
                }
                if ( isset( $change['status'] ) && ! in_array( $change['status'], self::STATUS_CHANGES[ $state['status'] ] ?? array(), true ) ) {
                    return new WP_Error( 'invalid_status_change', __( 'Allowed: pending to approved or completed, and approved to completed. Use cancel-booking to cancel.', 'timetics' ) );
                }
                if ( isset( $change['note'] ) && '' === trim( (string) $change['note'] ) ) {
                    return new WP_Error( 'nothing_to_change', __( 'A note cannot be emptied here. Use the dashboard.', 'timetics' ) );
                }

                return null;
            },
            function ( array $change, array $state ) {
                $to   = array_diff_key( $change, array( 'id' => true ) );
                $from = array( 'status' => $state['status'] );

                return array(
                    'from'         => array_intersect_key( $from, $to ),
                    'to'           => $to,
                    'notification' => __( 'No email is sent for this change.', 'timetics' ),
                );
            },
            function ( array $change ) {
                $id   = (int) $change['id'];
                $body = self::body_of( $id );

                if ( isset( $change['status'] ) ) {
                    $body['status'] = $change['status'];
                }
                if ( isset( $change['note'] ) ) {
                    $body['description'] = sanitize_textarea_field( (string) $change['note'] );
                }

                $failed = self::save_update( $id, $body );

                return $failed ? $failed : array( 'notification' => 'none' );
            }
        );
    }

    /**
     * Preview or move a booking. It keeps its staff member, status, price and the
     * customer's timezone.
     *
     * @param   array  $input  id, date, time, and confirm_token on the second call.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error
     */
    public static function reschedule( array $input, int $user ) {
        return self::run_change(
            self::RESCHEDULE_TOOL,
            $input,
            $user,
            array( 'date', 'time' ),
            function ( array $change, array $state ) {
                if ( ! in_array( $state['status'], self::ACTIVE, true ) ) {
                    return new WP_Error( 'invalid_status_change', __( 'Only a pending or approved booking can move.', 'timetics' ) );
                }

                return self::refuse_seat_plan( new Appointment( (int) $state['appointment'] ) );
            },
            function ( array $change ) {
                $move = self::prepare_move( (int) $change['id'], $change );
                if ( is_wp_error( $move ) ) {
                    return $move;
                }

                return $move['preview'] + array( 'notification' => self::emails_on( 'rescheduled' ) );
            },
            function ( array $change ) {
                $id   = (int) $change['id'];
                $move = self::prepare_move( $id, $change );
                if ( is_wp_error( $move ) ) {
                    return $move;
                }

                $mail_before = did_action( 'wp_mail_failed' );
                $failed      = self::save_update( $id, $move['to'] + self::body_of( $id ) );

                return $failed ? $failed : array( 'notification' => self::sent( 'rescheduled', $mail_before ) );
            }
        );
    }

    /**
     * Work out and check a move. The new time must be one the form offers.
     *
     * @param   int    $id      Booking id.
     * @param   array  $change  date, time, in the meeting's timezone.
     *
     * @return  array|WP_Error  to (route fields), preview.
     */
    private static function prepare_move( int $id, array $change ) {
        $booking = new Booking( $id );
        $meeting = new Appointment( (int) $booking->get_appointment() );
        $start   = self::meeting_time( $meeting, (string) $change['date'], (string) $change['time'] );
        if ( is_wp_error( $start ) ) {
            return $start;
        }

        $zone = new DateTimeZone( $meeting->get_timezone() );
        $now  = timetics_convert_timezone( $booking->get_start_date() . ' ' . $booking->get_start_time(), $booking->get_timezone(), $meeting->get_timezone() );

        if ( $now->format( 'Y-m-d g:ia' ) === $start->format( 'Y-m-d g:ia' ) ) {
            return new WP_Error( 'nothing_to_change', __( 'The booking is already at that time.', 'timetics' ) );
        }

        $refused = self::refuse_unoffered( $meeting, (int) $booking->get_staff_id(), $start );
        if ( $refused ) {
            return $refused;
        }

        // Saved in the customer's own timezone, so their emails keep showing their clock.
        $customer_zone = timetics_is_valid_timezone( (string) $booking->get_timezone() ) ? new DateTimeZone( $booking->get_timezone() ) : $zone;
        $local         = ( clone $start )->setTimezone( $customer_zone );
        $local_end     = ( clone $local )->modify( '+' . (int) $meeting->get_interval() . ' seconds' );

        return array(
            'to'      => array(
                'start_date' => $local->format( 'Y-m-d' ),
                'date'       => $local->format( 'Y-m-d' ),
                'end_date'   => $local_end->format( 'Y-m-d' ),
                'start_time' => $local->format( 'g:ia' ),
                'end_time'   => $local_end->format( 'g:ia' ),
                'timezone'   => $customer_zone->getName(),
            ),
            'preview' => array(
                'from'     => array(
                    'date' => $now->format( 'Y-m-d' ),
                    'time' => $now->format( 'g:ia' ),
                ),
                'to'       => array(
                    'date' => $start->format( 'Y-m-d' ),
                    'time' => $start->format( 'g:ia' ),
                ),
                'timezone' => $meeting->get_timezone(),
            ),
        );
    }

    /**
     * Preview or cancel a booking, or a session and the later ones of its series. Money
     * stays where it is.
     *
     * @param   array  $input  id, reason, scope, and confirm_token on the second call.
     * @param   int    $user   Who is asking.
     *
     * @return  array|WP_Error
     */
    public static function cancel( array $input, int $user ) {
        return self::run_change(
            self::CANCEL_TOOL,
            $input,
            $user,
            array( 'reason', 'scope' ),
            function ( array $change, array $state ) {
                if ( 'cancel' === $state['status'] ) {
                    return new WP_Error( 'booking_cancel_error', __( 'This booking is already canceled.', 'timetics' ) );
                }

                return in_array( $state['status'], self::ACTIVE, true )
                    ? null
                    : new WP_Error( 'invalid_status_change', __( 'Only a pending or approved booking can be canceled.', 'timetics' ) );
            },
            function ( array $change ) use ( $user ) {
                $preview = array( 'notification' => self::emails_on( 'canceled' ) );

                if ( (float) ( new Booking( (int) $change['id'] ) )->get_total() > 0 ) {
                    $preview['refund'] = __( 'A paid booking is not refunded by this. Refunds are done where the payment was taken.', 'timetics' );
                }

                if ( 'later' !== ( $change['scope'] ?? 'this' ) ) {
                    return $preview;
                }

                $later = self::later_sessions( (int) $change['id'], $user );

                return is_wp_error( $later ) ? $later : $preview + array( 'also_canceled' => Booking_Reader::rows( $later, $user ) );
            },
            function ( array $change ) use ( $user ) {
                $id    = (int) $change['id'];
                $later = array();
                $body  = array(
                    'status'        => 'cancel',
                    'cancel_reason' => sanitize_textarea_field( (string) ( $change['reason'] ?? '' ) ),
                ) + self::body_of( $id );

                // Timetics Pro cancels the later sessions when it reads this.
                if ( 'later' === ( $change['scope'] ?? 'this' ) ) {
                    $body['cancel_scope'] = 'later';
                    $later                = self::later_sessions( $id, $user );
                    if ( is_wp_error( $later ) ) {
                        return $later;
                    }
                }

                try {
                    $mail_before = did_action( 'wp_mail_failed' );
                    $failed      = self::save_update( $id, $body );
                    if ( $failed ) {
                        return $failed;
                    }
                    $result = array( 'notification' => self::sent( 'canceled', $mail_before ) );
                } catch ( \Throwable $e ) {
                    // Emails go out after the save, so a failing one must not read as a failed cancel.
                    if ( 'cancel' !== get_post_status( $id ) ) {
                        throw $e;
                    }
                    $result = array( 'notification' => 'error' );
                }

                return $later ? $result + self::series_outcome( $id, $later ) : $result;
            }
        );
    }

    /**
     * Which later sessions a series cancel reached, read from the bookings themselves:
     * a listener that failed half way may have stopped the rest.
     *
     * @param   int    $id     First booking.
     * @param   int[]  $later  Later sessions from the preview.
     *
     * @return  array  booking_ids, and not_canceled with a warning when some are still booked.
     */
    private static function series_outcome( int $id, array $later ): array {
        $left = array();
        foreach ( $later as $session ) {
            clean_post_cache( $session );
            if ( 'cancel' !== get_post_status( $session ) ) {
                $left[] = $session;
            }
        }

        $outcome = array( 'booking_ids' => array_merge( array( $id ), $later ) );
        if ( $left ) {
            $outcome['not_canceled'] = $left;
            /* translators: %d: number of sessions */
            $outcome['warning'] = sprintf( _n( '%d later session is still booked. Cancel it on its own.', '%d later sessions are still booked. Cancel them one at a time.', count( $left ), 'timetics' ), count( $left ) );
        }

        return $outcome;
    }

    /**
     * The later sessions a series cancel would also cancel, all of which this user must
     * be able to reach.
     *
     * @param   int  $id    Booking id.
     * @param   int  $user  Who is asking.
     *
     * @return  int[]|WP_Error
     */
    private static function later_sessions( int $id, int $user ) {
        /**
         * Filters the later, still active sessions of a booking's series. Timetics Pro
         * answers it. Null means this site has no series.
         *
         * @param int[]|null $later Session booking ids.
         * @param int        $id    Booking id.
         */
        $later = apply_filters( 'timetics_mcp_later_sessions', null, $id );

        if ( ! is_array( $later ) ) {
            return new WP_Error( 'not_supported', __( 'This site cannot cancel a series. Cancel one booking at a time.', 'timetics' ) );
        }

        $later = array_values( array_map( 'intval', $later ) );

        foreach ( $later as $session ) {
            if ( ! Mcp_Access::can_see_booking( $user, $session ) ) {
                return new WP_Error( 'forbidden', __( 'A later session of this series is not yours. Ask the site owner.', 'timetics' ) );
            }
        }

        return $later;
    }

    /**
     * The flow every tool on one booking shares: who may reach it, a preview that saves
     * nothing, and a confirmed call that runs once, inside the booking lock.
     *
     * @param   string    $tool      Tool name.
     * @param   array     $input     Tool input.
     * @param   int       $user      Who is asking.
     * @param   string[]  $fields    Input the tool reads besides id. Anything else is refused.
     * @param   callable  $refuse    Given change and state, returns a WP_Error to stop, else null.
     * @param   callable  $describe  Given change and state, returns preview fields or a WP_Error.
     * @param   callable  $work      Given change, saves and returns result fields or a WP_Error.
     *
     * @return  array|WP_Error
     */
    private static function run_change( string $tool, array $input, int $user, array $fields, callable $refuse, callable $describe, callable $work ) {
        $extra = array_diff( array_keys( $input ), array_merge( array( 'id', 'confirm_token' ), $fields ) );
        if ( $extra ) {
            /* translators: %s: field names */
            return new WP_Error( 'invalid_field', sprintf( __( 'This tool cannot change: %s. Nothing was saved.', 'timetics' ), implode( ', ', $extra ) ) );
        }

        $token  = (string) ( $input['confirm_token'] ?? '' );
        $change = array_diff_key( $input, array( 'confirm_token' => true ) );
        $id     = (int) ( $input['id'] ?? 0 );

        $check = function () use ( $id, $user, $change, $refuse ) {
            if ( ! Mcp_Access::can_change( $user ) ) {
                return self::access_denied();
            }
            if ( ! Mcp_Access::can_see_booking( $user, $id ) ) {
                return new WP_Error( 'not_found', __( 'No such booking.', 'timetics' ) );
            }

            $state   = self::state_of( $id );
            $refused = $refuse( $change, $state );

            return $refused ? $refused : $state;
        };

        if ( '' !== $token ) {
            return Confirmation::execute(
                $token,
                $user,
                $tool,
                $change,
                $check,
                function ( $payload ) use ( $work, $describe, $check, $change, $id, $user ) {
                    $done = Booking_Lock::run(
                        function () use ( $work, $describe, $check, $change, $id, $payload ) {
                            // Read again under the lock: the booking or the user's access may
                            // have changed while this waited, and the preview is what was agreed.
                            $changed = Confirmation::recheck( $payload, $check );
                            if ( $changed ) {
                                return $changed;
                            }

                            $preview = $describe( $change, self::state_of( $id ) );
                            if ( is_wp_error( $preview ) ) {
                                return $preview;
                            }
                            if ( ! Confirmation::matches_preview( $payload, $preview ) ) {
                                return new WP_Error( 'preview_changed', __( 'The booking or its series changed after the preview. Nothing was saved. Ask for a new preview.', 'timetics' ) );
                            }

                            return $work( $change );
                        }
                    );

                    if ( is_wp_error( $done ) ) {
                        return $done;
                    }

                    // A series cancel lists its sessions too, so a retry checks access to each.
                    $ids = $done['booking_ids'] ?? array( $id );

                    return array(
                        'booking_ids' => $ids,
                        'bookings'    => Booking_Reader::rows( $ids, $user ),
                    ) + $done;
                }
            );
        }

        $state = $check();
        if ( is_wp_error( $state ) ) {
            return $state;
        }

        $open = Confirmation::open_operation( $tool, $user, $id, $change );
        if ( $open ) {
            return $open;
        }

        $preview = $describe( $change, $state );
        if ( is_wp_error( $preview ) ) {
            return $preview;
        }

        return array(
            'preview'            => true,
            'confirm_token'      => Confirmation::issue( $tool, $user, $id, $change, $state, $preview ),
            'expires_in_seconds' => Confirmation::TOKEN_LIFETIME,
            'booking'            => Booking_Reader::rows( array( $id ), $user )[0],
            'next_step'          => self::next_step(),
        ) + $preview;
    }

    /**
     * What must still be true when the change is confirmed.
     *
     * @param   int  $id  Booking id.
     *
     * @return  array
     */
    private static function state_of( int $id ): array {
        $state = array( 'status' => (string) get_post_status( $id ) );

        foreach ( array( 'appointment', 'staff', 'start_date', 'start_time', 'timezone', 'description', 'order_total' ) as $key ) {
            $state[ $key ] = (string) get_post_meta( $id, '_tt_booking_' . $key, true );
        }

        return $state;
    }

    /**
     * The update request for a booking as it is. Fields left empty keep their stored
     * value (Booking::save_metadata()), so the note is not sent and keeps its line breaks.
     * The customer goes as this booking saved them, so the route changes no contact.
     *
     * @param   int  $id  Booking id.
     *
     * @return  array
     */
    private static function body_of( int $id ): array {
        $booking = new Booking( $id );
        $contact = $booking->get_customer_snapshot();

        return array(
            'first_name'    => $contact['first_name'],
            'last_name'     => $contact['last_name'],
            'email'         => $contact['email'],
            'phone'         => $contact['phone'],
            'appointment'   => (int) $booking->get_appointment(),
            'staff'         => (int) $booking->get_staff_id(),
            'start_date'    => (string) $booking->get_start_date(),
            'end_date'      => (string) $booking->get_end_date(),
            'start_time'    => (string) $booking->get_start_time(),
            'end_time'      => (string) $booking->get_end_time(),
            'timezone'      => (string) $booking->get_timezone(),
            'location_type' => (string) $booking->get_location_type(),
            // Sent always: a privileged save with no status resets it to the default.
            'status'        => (string) $booking->get_status(),
        );
    }

    /**
     * Save through the dashboard's update route. The total is held as it is: the route
     * prices a privileged save again, so a $50 booking would follow a later price change.
     *
     * @param   int    $id    Booking id.
     * @param   array  $body  Request body.
     *
     * @return  WP_Error|null
     */
    private static function save_update( int $id, array $body ) {
        $total = ( new Booking( $id ) )->get_total();
        $hold  = function () use ( $total ) {
            return $total;
        };

        $request = new WP_REST_Request( 'PUT', '/timetics/v1/bookings/' . $id );
        $request->set_url_params( array( 'booking_id' => $id ) );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        add_filter( 'timetics_booking_order_total', $hold, PHP_INT_MAX );

        try {
            return self::failure(
                self::keeping_customers(
                    function () use ( $request ) {
                        return Api_Booking::instance()->update_item( $request );
                    }
                )
            );
        } finally {
            remove_filter( 'timetics_booking_order_total', $hold, PHP_INT_MAX );
        }
    }

    /**
     * The error in a route's answer, if any. The routes answer in three shapes.
     *
     * @param   mixed  $response  WP_Error, WP_HTTP_Response or array.
     *
     * @return  WP_Error|null
     */
    private static function failure( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = $response instanceof WP_HTTP_Response ? $response->get_data() : (array) $response;
        if ( ! empty( $data['success'] ) ) {
            return null;
        }

        $message = $data['message'] ?? '';
        $message = is_array( $message ) ? implode( ' ', $message ) : (string) $message;

        return new WP_Error( 'save_failed', '' !== $message ? $message : __( 'The booking could not be saved.', 'timetics' ) );
    }

    /**
     * A date and time read on the meeting's clock.
     *
     * @param   Appointment  $meeting  Meeting.
     * @param   string       $date     Y-m-d.
     * @param   string       $time     Like 3:00pm or 15:00.
     *
     * @return  DateTime|WP_Error
     */
    private static function meeting_time( Appointment $meeting, string $date, string $time ) {
        $zone   = timetics_is_valid_timezone( (string) $meeting->get_timezone() ) ? $meeting->get_timezone() : wp_timezone_string();
        $moment = DateTime::createFromFormat( '!Y-m-d', $date, new DateTimeZone( $zone ) );
        $clock  = strtotime( '2000-01-01 ' . $time . ' UTC' );

        if ( ! $moment || $moment->format( 'Y-m-d' ) !== $date || false === $clock ) {
            return new WP_Error( 'invalid_time', __( 'Send the date as YYYY-MM-DD and the time like 3:00pm.', 'timetics' ) );
        }

        return $moment->setTime( (int) gmdate( 'G', $clock ), (int) gmdate( 'i', $clock ) );
    }

    /**
     * Who gets an email for an event, by the site's email settings.
     *
     * @param   string  $event  created, rescheduled or canceled.
     *
     * @return  array  customer, host as booleans.
     */
    private static function emails_on( string $event ): array {
        return array(
            'customer' => (bool) timetics_get_option( 'booking_' . $event . '_customer' ),
            'host'     => (bool) timetics_get_option( 'booking_' . $event . '_host' ),
        );
    }

    /**
     * What a result says about email. Sending was started, whether it arrived is not known.
     * Timetics catches a broken mail setup and reports it through wp_mail_failed.
     *
     * @param   string  $event        created, rescheduled or canceled.
     * @param   int     $mail_before  wp_mail_failed count before the save.
     *
     * @return  string  triggered, none or error.
     */
    private static function sent( string $event, int $mail_before ): string {
        if ( did_action( 'wp_mail_failed' ) > $mail_before ) {
            return 'error';
        }

        return in_array( true, self::emails_on( $event ), true ) ? 'triggered' : 'none';
    }

    /**
     * A staff member's name.
     *
     * @param   int  $id  User id.
     *
     * @return  string
     */
    private static function person_name( int $id ): string {
        $person = get_userdata( $id );

        return $person ? self::filled( trim( $person->first_name . ' ' . $person->last_name ), (string) $person->display_name ) : '';
    }

    /**
     * The first value that is not empty.
     *
     * @param   string  $value     Preferred value.
     * @param   string  $fallback  Used when it is empty.
     *
     * @return  string
     */
    private static function filled( string $value, string $fallback ): string {
        return '' !== $value ? $value : $fallback;
    }

    /**
     * What the AI must do with a preview.
     *
     * @return  string
     */
    private static function next_step(): string {
        return __( 'Nothing is saved yet. Show this to the user. Only after they say yes, call again with the same fields and confirm_token.', 'timetics' );
    }

    /**
     * The error for a connection that may only view.
     *
     * @return  WP_Error
     */
    private static function access_denied(): WP_Error {
        return new WP_Error( 'access_denied', __( 'This connection may only view bookings. Nothing was changed. To make changes, connect the app again and keep "Make changes" ticked.', 'timetics' ) );
    }
}
