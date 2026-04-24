<?php
/**
 * Phone Number Faker Class
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData\Provider;

/**
 * Date time faker class
 */
class DateTime {
    /**
     * Get genrated date
     *
     * @return  string
     */
    public static function date( $format = 'Y-m-d' ) {
        return gmdate( $format, self::timestamp() );
    }

    /**
     * Get generated time
     *
     * @return  string
     */
    public static function time( $format = 'h:i a' ) {
        return gmdate( $format, self::timestamp() );
    }

    /**
     * Get generated timestamp
     *
     * @return  string
     */
    private static function timestamp() {
        $start_timestamp = strtotime( '1970-01-01' );
        $end_timestamp   = strtotime( '2030-12-31' );

        // Generate a random timestamp within the valid range
        $random_timestamp = wp_rand( $start_timestamp, $end_timestamp );

        // Format the timestamp into a valid date format
        return $random_timestamp;
    }
}
