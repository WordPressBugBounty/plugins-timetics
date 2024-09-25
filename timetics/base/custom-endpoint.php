<?php
/**
 * Register Custom Endpoint
 *
 * @package Timetics
 */
namespace Timetics\Base;
use Timetics\Utils\Singleton;

class Custom_Endpoint {
    use Singleton;

    /**
     * Initialize
     *
     * @return  void
     */
    public function init() {
        add_action( 'init', [$this, 'register'] );
    }

    /**
     * Register all custom endpoints
     *
     * @return  void
     */
    public function register() {
        $endpoints = $this->get_endpoints();

        foreach ( $endpoints as $endpoint ) {
            add_rewrite_endpoint( $endpoint, EP_ALL );
        }

        // Flush rewrite rules after register all custom endpoints.
        flush_rewrite_rules( true );
    }

    /**
     * Get all endpoints
     *
     * @return  array
     */
    public function get_endpoints() {
        /**
         * All endpoints that have to be register
         */
        return [
            'timetics-integration',
        ];
    }
};
