<?php
/**
 * Password meta cleanup
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plain-text `_timetics_customer_password` user meta older versions saved.
 *
 * Nothing reads it: logins use WordPress's hashed password. Deletes only that exact key,
 * in small batches, and is done only after a batch finds none left. Values are never read
 * out, logged or copied. Copies in old backups are out of its reach.
 */
class Password_Meta_Cleanup {

    const META_KEY = '_timetics_customer_password';

    const OPTION = 'timetics_password_meta_cleanup';

    /**
     * Whether every value is gone
     *
     * @return bool
     */
    public static function is_done() {
        return 'done' === get_option( self::OPTION );
    }

    /**
     * Delete one batch.
     *
     * @param int $size Rows per batch.
     *
     * @return bool Whether a batch ran without a database error.
     */
    public static function run_batch( $size = 500 ) {
        global $wpdb;

        if ( self::is_done() ) {
            return false;
        }

        $size = max( 1, absint( $size ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only ids are read, never the values.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d", self::META_KEY, $size ), ARRAY_A );

        if ( $rows ) {
            $ids = implode( ',', array_map( 'absint', wp_list_pluck( $rows, 'umeta_id' ) ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ids are integers; the key is checked again.
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s AND umeta_id IN ({$ids})", self::META_KEY ) );

            // A failed delete is tried again on the next run, never marked done.
            if ( false === $deleted ) {
                return false;
            }

            foreach ( array_unique( wp_list_pluck( $rows, 'user_id' ) ) as $user_id ) {
                wp_cache_delete( (int) $user_id, 'user_meta' );
            }
        }

        if ( count( $rows ) < $size ) {
            update_option( self::OPTION, 'done' );
        }

        return true;
    }
}
