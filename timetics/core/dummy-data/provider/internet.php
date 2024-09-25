<?php
/**
 * Internet Faker Class
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData\Provider;

/**
 * Internet Faker Class
 */
class Internet {
    /**
     * Store Internet Free Domain
     *
     * @var array
     */
    protected static $domains = ['gmail.com', 'yahoo.com', 'hotmail.com'];

    /**
     * Store random character
     *
     * @var string
     */
    protected static $str = 'abcdefghijklmnopqrstuvwxyz12345_-.';

    /**
     * Get username
     *
     * @return  string
     */
    public static function username() {
        $strLength = strlen( self::$str );

        // Generate a random start position within the valid range
        $start_pos = mt_rand( 0, max( 0, $strLength - 5 ) );

        // Extract the random substring using substr()
        $random_substring = substr( self::$str, $start_pos, 5 );

        return $random_substring . '_' . time();
    }

    /**
     * Get generated email
     *
     * @return  string
     */
    public static function email() {
        $email = self::username() . '@' . self::domain();

        return $email;
    }

    /**
     * Get generated password
     *
     * @return  string
     */
    public static function password() {
        $password = uniqid() . '_' . time();

        return $password;
    }

    /**
     * Get domain name
     *
     * @return  string
     */
    private static function domain() {
        return self::$domains[array_rand( self::$domains )];
    }
}
