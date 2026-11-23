<?php
/**
 * Pro compatibility for guest customers
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use Timetics\Core\Appointments\Appointment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Free updates from wp.org on its own, so it can run beside a Pro that reads
 * every customer as a WordPress user. Only the bookings that Pro would get wrong
 * stop until it is updated.
 */
class Pro_Compat {

    /**
     * Register the admin notice
     *
     * @return void
     */
    public static function init() {
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
    }

    /**
     * Whether the active Pro, if any, handles guest customers.
     *
     * Read at request time, after every plugin has loaded.
     *
     * @return bool
     */
    public static function guest_supported() {
        if ( ! apply_filters( 'timetics_pro_active', class_exists( 'TimeticsPro' ) ) ) {
            return true;
        }

        $version = defined( 'TIMETICS_PRO_GUEST_CUSTOMERS_API_VERSION' ) ? (int) TIMETICS_PRO_GUEST_CUSTOMERS_API_VERSION : 0;

        return (int) apply_filters( 'timetics_pro_guest_customers_api_version', $version ) >= 1;
    }

    /**
     * Whether an old Pro would mishandle this new booking.
     *
     * Old Pro reads the customer as a WordPress user. A guest has none, so on a repeating
     * meeting its later sessions lose the guest's details, and a per-customer coupon limit
     * never counts them. Attendees get accounts whatever the switch says. A plain single
     * booking works, so it goes through: sites keep taking bookings until Pro is updated.
     *
     * @param array $data Booking request.
     *
     * @return bool
     */
    public static function blocks( array $data ) {
        if ( self::guest_supported() ) {
            return false;
        }

        if ( ! empty( $data['attendees'] ) ) {
            return true;
        }

        $user  = wp_get_current_user();
        $email = isset( $data['email'] ) ? Customer_Store::normalize_email( $data['email'] ) : '';

        // A logged-in user booking with their own email keeps a WordPress user, which old Pro reads fine.
        if ( $user->ID && strtolower( $user->user_email ) === $email ) {
            return false;
        }

        $meeting = new Appointment( isset( $data['appointment'] ) ? absint( $data['appointment'] ) : 0 );

        return $meeting->is_recurring() || '' !== trim( (string) ( $data['coupon_code'] ?? '' ) );
    }

    /**
     * Error for a blocked booking. The visitor sees only a short message.
     *
     * @return WP_Error
     */
    public static function error() {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Lets a host see why bookings stopped without the dashboard.
        error_log( 'WPTimetics refused a booking: Timetics Pro must be updated to take guest bookings.' );

        return new WP_Error(
            'timetics_pro_update_required',
            __( 'This booking option is unavailable right now. Please contact us to book.', 'timetics' ),
            array( 'status' => 503 )
        );
    }

    /**
     * Tell admins why guest bookings stopped
     *
     * @return void
     */
    public static function admin_notice() {
        if ( self::guest_supported() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // The upload or update screen still runs the old Pro while it installs the new one.
        if ( isset( $GLOBALS['pagenow'] ) && 'update.php' === $GLOBALS['pagenow'] ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'Update Timetics Pro to 1.0.47 or newer. Until then, guests cannot book repeating meetings, use coupons or add attendees.', 'timetics' )
        );
    }
}
