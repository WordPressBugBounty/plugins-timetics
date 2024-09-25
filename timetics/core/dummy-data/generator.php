<?php
/**
 * Faker Generator
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData;

/**
 * Faker generator class
 */
class Generator {
    /**
     * Store providers
     *
     * @var array
     */
    protected $providers = [];

    /**
     * Store formaters
     *
     * @var array
     */
    protected $formatters = [];

    /**
     * @param   string  $attribute
     *
     * @return  mixex
     */
    public function __get( $attribute ) {
        return $this->format( $attribute );
    }

    /**
     * @param   string  $method_name
     * @param   array  $arguments
     *
     * @return  mixed
     */
    public function __call( $method_name, $attributes ) {
        return $this->format( $method_name, $attributes );
    }

    /**
     * Add provider to providers
     *
     * @param   Object  $provider
     *
     * @return  void
     */
    public function add_provider( $provider ) {
        array_unshift( $this->providers, $provider );
    }

    /**
     * Get all providers
     *
     * @return  array
     */
    public function get_provider() {
        return $this->providers;
    }

    /**
     * Format faker data
     *
     * @param   string  $formatter
     * @param   array  $arguments
     *
     * @return  mixed
     */
    public function format( $formatter, $arguments = [] ) {
        return call_user_func_array( $this->get_formatter( $formatter ), $arguments );
    }

    /**
     * @param   string  $formatter
     *
     * @return  Callable
     */
    public function get_formatter( $formatter ) {
        if ( isset( $this->formatters[$formatter] ) ) {
            return $this->formatters[$formatter];
        }

        foreach ( $this->providers as $provider ) {
            if ( method_exists( $provider, $formatter ) ) {
                $this->formatters[$formatter] = [$provider, $formatter];

                return $this->formatters[$formatter];
            }
        }

        throw new \InvalidArgumentException( sprintf( 'Unknown formatter "%s"', wp_kses( $formatter, 'post' ) ) );
    }
}
