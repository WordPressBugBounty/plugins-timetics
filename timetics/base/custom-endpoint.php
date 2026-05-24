<?php
/**
 * Register Custom Endpoint
 *
 * @package Timetics
 */
namespace Timetics\Base;

defined( 'ABSPATH' ) || exit;

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
        add_action( 'init', [$this, 'maybe_flush_rules'], 99 );
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
    }

    /**
     * Flush rewrite rules once per plugin version (after upgrade or fresh install).
     *
     * @return void
     */
    public function maybe_flush_rules() {
        $stored = get_option( 'timetics_rewrite_version' );

        if ( defined( 'TIMETICS_VERSION' ) && $stored !== TIMETICS_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'timetics_rewrite_version', TIMETICS_VERSION, false );
        }
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
