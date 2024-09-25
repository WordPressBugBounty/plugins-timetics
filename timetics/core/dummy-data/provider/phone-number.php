<?php
/**
 * Phone Number Faker Class
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData\Provider;

/**
 * Class Phone Number
 */
class PhoneNumber {

    /**
     * Get generated phone number
     *
     * @return  string
     */
    public static function phone_number() {
        $areaCode   = mt_rand( 100, 999 );
        $prefix     = mt_rand( 100, 999 );
        $lineNumber = mt_rand( 1000, 9999 );

        return "($areaCode) $prefix-$lineNumber";
    }
}
