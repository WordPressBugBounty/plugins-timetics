<?php
/** Booking-scoped WhatsApp consent. @package Timetics */
namespace Timetics\Core\Bookings;

defined( 'ABSPATH' ) || exit;

class Whatsapp_Consent {
    public static function normalize_phone( $raw ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $digits = preg_replace( '/\D/', '', $raw );
        return preg_match( '/^[1-9][0-9]{6,14}$/', $digits ) ? '+' . $digits : '';
    }

    public static function parse_opt_in( $raw ) {
        if ( true === $raw || 1 === $raw || '1' === $raw || 'true' === $raw ) {
            return 1;
        }
        if ( false === $raw || 0 === $raw || '0' === $raw || 'false' === $raw || '' === $raw || null === $raw ) {
            return 0;
        }
        return new \WP_Error( 'invalid_whatsapp_consent', __( 'Choose whether to receive WhatsApp updates.', 'timetics' ), [ 'status' => 422 ] );
    }

    /** Resolve submitted consent without borrowing permission from a customer profile. */
    public static function prepare( array $data, Booking $booking, $phone_mode = 'optional' ) {
        $has_consent = array_key_exists( 'whatsapp_opt_in', $data );
        $has_phone   = array_key_exists( 'phone', $data );
        $old_opt_in  = $booking->get_id() ? $booking->get_whatsapp_opt_in() : false;
        $old_number  = $booking->get_id() ? $booking->get_whatsapp_number() : '';
        $opt_in      = $has_consent ? self::parse_opt_in( $data['whatsapp_opt_in'] ) : ( $old_opt_in ? 1 : 0 );
        if ( is_wp_error( $opt_in ) ) {
            return $opt_in;
        }
        if ( 'off' === $phone_mode ) {
            if ( $has_consent && $opt_in ) {
                return new \WP_Error( 'whatsapp_phone_field_off', __( 'WhatsApp updates are unavailable for this meeting.', 'timetics' ), [ 'status' => 422 ] );
            }
            return [ 'whatsapp_opt_in' => 0, 'whatsapp_number' => '' ];
        }
        $number_changed = $has_phone && '' !== trim( (string) $data['phone'] ) && self::normalize_phone( (string) $data['phone'] ) !== $old_number;
        if ( $has_consent && $opt_in && ! apply_filters( 'timetics_whatsapp_enabled', false ) && ( ! $old_opt_in || $number_changed ) ) {
            return new \WP_Error( 'whatsapp_unavailable', __( 'WhatsApp updates are not available.', 'timetics' ), [ 'status' => 422 ] );
        }
        if ( ! $opt_in ) {
            return [ 'whatsapp_opt_in' => 0, 'whatsapp_number' => '' ];
        }
        $submitted = $has_phone ? self::normalize_phone( $data['phone'] ) : '';
        if ( $has_consent && $opt_in ) {
            if ( $has_phone && '' !== trim( (string) $data['phone'] ) && '' === $submitted ) {
                return new \WP_Error( 'invalid_whatsapp_number', __( 'Enter a valid phone number to get WhatsApp updates.', 'timetics' ), [ 'status' => 422 ] );
            }
            $number = $submitted ?: $old_number;
        } else {
            $number = $old_number;
            if ( $submitted && $submitted !== $old_number ) {
                return new \WP_Error( 'whatsapp_consent_required', __( 'Confirm WhatsApp updates for this phone number.', 'timetics' ), [ 'status' => 422 ] );
            }
        }
        if ( '' === $number ) {
            return new \WP_Error( 'invalid_whatsapp_number', __( 'Enter a valid phone number to get WhatsApp updates.', 'timetics' ), [ 'status' => 422 ] );
        }
        return [ 'whatsapp_opt_in' => 1, 'whatsapp_number' => $number ];
    }

    public static function customer_number( Booking $booking ) {
        return $booking->get_whatsapp_opt_in() ? self::normalize_phone( $booking->get_whatsapp_number() ) : '';
    }
}
