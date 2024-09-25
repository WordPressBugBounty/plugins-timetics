<?php
/**
 * Factory Class
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData;

/**
 * Facotry Class
 */
class Factory {
    /**
     * Store default providers
     *
     * @var Array
     */
    protected static $providers = [
        'Person',
        'PhoneNumber',
        'Address',
        'Lorem',
        'Internet',
        'DateTime',
    ];

    /**
     * Create a new generator
     *
     * @return  Generator
     */
    public static function create() {
        $generator = new Generator();

        $providers = self::$providers;

        foreach ( $providers as $provider ) {
            $provider_class = self::get_provider_class( $provider );
            $generator->add_provider( new $provider_class() );
        }

        return $generator;
    }

    /**
     * @param   string $provider
     *
     * @return  string
     */
    public static function get_provider_class( $provider ) {
        $provider_class = 'Timetics\\Core\\DummyData\\Provider\\' . $provider;

        if ( class_exists( $provider_class ) ) {
            return $provider_class;
        }

        return null;
    }
}
