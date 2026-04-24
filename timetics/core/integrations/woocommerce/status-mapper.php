<?php
namespace Timetics\Core\Integrations\Woocommerce;

/**
 * Class Status_Mapper
 *
 * Maps booking statuses to WooCommerce order statuses and vice versa
 *
 */
class Status_Mapper {

    /**
     * Track currently syncing booking-order pairs in this request
     *
     * @var array
     */
    private static $syncing_pairs = [];

    /**
     * Map Timetics booking status to WooCommerce order status
     *
     * Mapping: ( Timetics -> Woocommerce )
     *
     * - pending -> pending
     * - approved -> processing
     * - completed -> completed
     * - cancel -> cancelled
     * - failed -> failed
     *
     * @param string $booking_status The Timetics booking status
     * @return string The WooCommerce order status (without 'wc-' prefix)
     */
    public static function booking_to_order_status( $booking_status ) {
        $mapping = [
            'pending'   => 'pending',
            'approved'  => 'processing',
            'completed' => 'completed',
            'cancel'    => 'cancelled',
            'failed'    => 'failed',
        ];

        return isset( $mapping[ $booking_status ] ) ? $mapping[ $booking_status ] : $booking_status;
    }

    /**
     * Map WooCommerce order status to Timetics booking status
     *
     * Mapping:
     * - pending -> pending
     * - processing -> approved
     * - on-hold -> pending
     * - completed -> completed
     * - cancelled -> cancel
     * - refunded -> cancel
     * - failed -> failed
     *
     * @param string $order_status The WooCommerce order status (may or may not include 'wc-' prefix)
     * @return string The Timetics booking status
     */
    public static function order_to_booking_status( $order_status ) {
        // Remove 'wc-' prefix if present
        $order_status = str_replace( 'wc-', '', $order_status );

        $mapping = [
            'pending'    => 'pending',
            'processing' => 'approved',
            'on-hold'    => 'pending',
            'completed'  => 'completed',
            'cancelled'  => 'cancel',
            'refunded'   => 'cancel',
            'failed'     => 'failed',
        ];

        return isset( $mapping[ $order_status ] ) ? $mapping[ $order_status ] : $order_status;
    }

    /**
     * Check if a status transition should trigger sync
     *
     * @param  string $from_status The previous status
     * @param  string $to_status The new status
     *
     * @return bool   Whether the transition should be synced
     */
    public static function should_sync_status( $from_status, $to_status ) {
        if ( $from_status === $to_status ) {
            return false;
        }

        return true;
    }

    /**
     * Get the WooCommerce order ID from booking meta
     *
     * @param int $booking_id The booking ID
     *
     * @return int|false The WooCommerce order ID or false if not found
     */
    public static function get_order_id_from_booking( $booking_id ) {
        return get_post_meta( $booking_id, '_tt_wc_order_id', true );
    }

    /**
     * Store the WooCommerce order ID in booking meta
     *
     * @param  int  $booking_id The booking ID
     * @param  int  $order_id The WooCommerce order ID
     *
     * @return bool Whether the meta was updated
     */
    public static function set_order_id_for_booking( $booking_id, $order_id ) {
        return update_post_meta( $booking_id, '_tt_wc_order_id', $order_id );
    }

    /**
     * Get the booking ID from WooCommerce order meta
     *
     * @param  int $order_id The WooCommerce order ID
     * @return int|false The booking ID or false if not found
     */
    public static function get_booking_id_from_order( $order_id ) {
        return get_post_meta( $order_id, '_tt_booking_id', true );
    }

    /**
     * Store the booking ID in WooCommerce order meta
     *
     * @param  int  $order_id The WooCommerce order ID
     * @param  int  $booking_id The booking ID
     * @return bool Whether the meta was updated
     */
    public static function set_booking_id_for_order( $order_id, $booking_id ) {
        return update_post_meta( $order_id, '_tt_booking_id', $booking_id );
    }

    /**
     * Check if a booking-order pair is currently being synced
     *
     * @param int $booking_id The booking ID
     * @param int $order_id The WooCommerce order ID
     *
     * @return bool Whether this pair is currently syncing
     */
    public static function is_syncing_pair( $booking_id, $order_id ) {
        $pair_key = "{$booking_id}_{$order_id}";
        return isset( self::$syncing_pairs[ $pair_key ] );
    }

    /**
     * Mark a booking-order pair as currently syncing
     *
     * @param int $booking_id The booking ID
     * @param int $order_id The WooCommerce order ID
     * @return void
     */
    public static function begin_sync_pair( $booking_id, $order_id ) {
        $pair_key = "{$booking_id}_{$order_id}";
        self::$syncing_pairs[ $pair_key ] = true;
    }

    /**
     * Mark a booking-order pair as finished syncing
     *
     * @param int $booking_id The booking ID
     * @param int $order_id The WooCommerce order ID
     * @return void
     */
    public static function end_sync_pair( $booking_id, $order_id ) {
        $pair_key = "{$booking_id}_{$order_id}";
        unset( self::$syncing_pairs[ $pair_key ] );
    }
}
