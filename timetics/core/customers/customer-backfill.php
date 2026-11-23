<?php
/**
 * Customer backfill
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each older customer account a row, so the Customers screen reads one table.
 *
 * Only adds rows: never changes users, roles or bookings. Runs a small batch per admin
 * page load, remembers the last user id, and is done only after a batch finds nobody left.
 */
class Customer_Backfill {

    const OPTION = 'timetics_customers_backfill';

    /**
     * Whether every older customer has been looked at.
     *
     * @return bool
     */
    public static function is_done() {
        return 'done' === get_option( self::OPTION );
    }

    /**
     * One batch per admin page load. Hooks pass an empty first argument, so this takes none.
     *
     * @return void
     */
    public static function run() {
        self::run_batch();
    }

    /**
     * Copy the next batch of users with the customer role.
     *
     * Admins and staff get the role automatically, so they are copied only once they booked.
     *
     * @param int $size Users per batch.
     *
     * @return bool Whether a batch ran.
     */
    public static function run_batch( $size = 200 ) {
        global $wpdb;

        if ( self::is_done() || ! Customer_Store::is_ready() ) {
            return false;
        }

        $after = absint( get_option( self::OPTION, 0 ) );
        $size  = max( 1, absint( $size ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_User_Query cannot page by "id after".
        $ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT u.ID FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = %s
                    WHERE u.ID > %d AND m.meta_value LIKE %s ORDER BY u.ID ASC LIMIT %d",
                    $wpdb->get_blog_prefix() . 'capabilities',
                    $after,
                    '%' . $wpdb->esc_like( '"timetics-customer"' ) . '%',
                    $size
                )
            )
        );
        // phpcs:enable

        $booked = self::users_with_bookings( $ids );

        foreach ( $ids as $id ) {
            $user = get_userdata( $id );

            if ( ! $user || ! in_array( 'timetics-customer', $user->roles, true ) || ( array_intersect( array( 'administrator', 'timetics-staff' ), $user->roles ) && ! in_array( $id, $booked, true ) ) ) {
                continue;
            }

            Customer_Store::ensure_for_user( $user );
        }

        update_option( self::OPTION, count( $ids ) < $size ? 'done' : (string) end( $ids ) );

        return true;
    }

    /**
     * Which of these users are a booking's customer or owner, in any status.
     *
     * @param array $ids User ids.
     *
     * @return array User ids.
     */
    private static function users_with_bookings( array $ids ) {
        global $wpdb;

        if ( ! $ids ) {
            return array();
        }

        $in = implode( ',', array_fill( 0, count( $ids ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above.
        $found = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'timetics-booking'
                WHERE m.meta_key IN ( '_tt_booking_customer', '_tt_booking_owner_user' ) AND m.meta_value IN ({$in})",
                array_map( 'strval', $ids )
            )
        );
        // phpcs:enable

        return array_map( 'intval', $found );
    }
}
