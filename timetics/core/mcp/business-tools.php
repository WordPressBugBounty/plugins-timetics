<?php
/**
 * Business numbers and settings tools
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use DateTime;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's numbers and the safe part of the settings, for the owner. Both only read.
 */
class Business_Tools {

    /**
     * Settings the AI may see. An allow-list, so a key added later stays private.
     */
    const SETTINGS = array( 'currency', 'default_booking_status', 'slot_interval', 'remainder_time', 'guest_enabled', 'guest_limit', 'phone_default_country', 'calendar_locale', 'availability', 'blocked_days' );

    /**
     * Stats and settings tools.
     *
     * @return  array  Same shape as Abilities::tools().
     */
    public static function tools(): array {
        $date = array(
            'type'    => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
        );

        return array(
            'timetics/get-stats'    => array(
                'readonly'    => true,
                'owner'       => true,
                'label'       => __( 'Get business numbers', 'timetics' ),
                'description' => __( 'Owner only. The dashboard numbers for a date range: approved and completed bookings, earnings and new customers, each with the period of the same length before.', 'timetics' ),
                'input'       => array(
                    'type'                 => 'object',
                    'required'             => array( 'date_from', 'date_to' ),
                    'additionalProperties' => false,
                    'properties'           => array(
                        'date_from' => $date,
                        'date_to'   => $date,
                    ),
                ),
                'run'         => array( self::class, 'get_stats' ),
            ),
            'timetics/get-settings' => array(
                'readonly'    => true,
                'owner'       => true,
                'label'       => __( 'Get booking settings', 'timetics' ),
                'description' => __( 'Owner only. Currency, date and time format, timezone, booking rules, site hours and blocked days. Never keys or passwords. Settings are changed in the dashboard.', 'timetics' ),
                'input'       => array(
                    'type'                 => 'object',
                    'default'              => new \stdClass(),
                    'additionalProperties' => false,
                ),
                'run'         => array( self::class, 'get_settings' ),
            ),
        );
    }

    /**
     * Numbers for a range and the range before it, from the dashboard's report route.
     *
     * @param   array  $input  date_from, date_to.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function get_stats( array $input, int $user ) {
        if ( ! Mcp_Access::is_owner( $user ) ) {
            return new WP_Error( 'forbidden', __( 'Only the site owner can see business numbers.', 'timetics' ) );
        }

        $from = DateTime::createFromFormat( '!Y-m-d', (string) $input['date_from'] );
        $to   = DateTime::createFromFormat( '!Y-m-d', (string) $input['date_to'] );
        if ( ! $from || ! $to || $from > $to ) {
            return new WP_Error( 'invalid_range', __( 'Send date_from on or before date_to, as YYYY-MM-DD.', 'timetics' ) );
        }

        $days        = (int) $from->diff( $to )->days + 1;
        $before_to   = ( clone $from )->modify( '-1 day' );
        $before_from = ( clone $before_to )->modify( '-' . ( $days - 1 ) . ' days' );

        $now    = self::totals( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) );
        $before = self::totals( $before_from->format( 'Y-m-d' ), $before_to->format( 'Y-m-d' ) );
        if ( is_wp_error( $now ) || is_wp_error( $before ) ) {
            return is_wp_error( $now ) ? $now : $before;
        }

        return array(
            'date_from' => $from->format( 'Y-m-d' ),
            'date_to'   => $to->format( 'Y-m-d' ),
            'currency'  => (string) timetics_get_option( 'currency', '' ),
            'now'       => $now,
            'before'    => $before + array(
                'date_from' => $before_from->format( 'Y-m-d' ),
                'date_to'   => $before_to->format( 'Y-m-d' ),
            ),
        );
    }

    /**
     * The safe part of the settings.
     *
     * @param   array  $input  Unused.
     * @param   int    $user   User id.
     *
     * @return  array|WP_Error
     */
    public static function get_settings( array $input, int $user ) {
        if ( ! Mcp_Access::is_owner( $user ) ) {
            return new WP_Error( 'forbidden', __( 'Only the site owner can see the settings.', 'timetics' ) );
        }

        $settings = array();
        foreach ( self::SETTINGS as $key ) {
            $settings[ $key ] = timetics_get_option( $key, null );
        }

        return $settings + array(
            'timezone'    => wp_timezone_string(),
            'date_format' => (string) get_option( 'date_format' ),
            'time_format' => (string) get_option( 'time_format' ),
        );
    }

    /**
     * Totals for one range.
     *
     * @param   string  $from  Y-m-d.
     * @param   string  $to    Y-m-d.
     *
     * @return  array|WP_Error  bookings, earnings, new_customers.
     */
    private static function totals( string $from, string $to ) {
        $asked = array();
        foreach ( array( 'total_booking', 'total_earning', 'total_customer' ) as $report ) {
            $asked[] = array(
                'report'     => $report,
                'start_date' => $from,
                'end_date'   => $to,
            );
        }

        $data = Dashboard_Writer::route( 'POST', 'reports/overview', $asked );
        if ( is_wp_error( $data ) ) {
            return $data;
        }

        return array(
            'bookings'      => (int) ( $data['total_booking'] ?? 0 ),
            // The route formats money for display, like 1,250.00.
            'earnings'      => (float) str_replace( ',', '', (string) ( $data['total_earning'] ?? '0' ) ),
            'new_customers' => (int) ( $data['total_customer'] ?? 0 ),
        );
    }
}
