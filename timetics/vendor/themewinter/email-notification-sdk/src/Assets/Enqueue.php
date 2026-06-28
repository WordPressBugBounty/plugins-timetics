<?php

namespace Ens\Assets;

use Ens\Config;
use Ens\Utils\Helpers;

/**
 * Class Enqueue
 *
 * @package ENS
 *
 * @since 1.0.0
 */
class Enqueue {

    /**
     * Config
     *
     * @var Config
     */
    private $identifier;

    /**
     * Initializing enqueue assets
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init($identifier) {
        $this->identifier = $identifier;
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_assets'],999 );
    }

    /**
     * Enqueue admin scripts and pass localized data
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function enqueue_assets() {
        $admin_script_handler = Helpers::get_config_data( $this->identifier,'admin_script_handler' );
        $plugin_slug          = Helpers::get_config_data( $this->identifier,'plugin_slug' );

        $general_prefix = Helpers::get_config_data( $this->identifier,'general_prefix' );

        wp_localize_script( $admin_script_handler, $general_prefix . '_ens_data', [
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'api_version' => 'v1',
            'plugin_slug' => $plugin_slug,
            'base_url'    => site_url(),
            'triggers'    => apply_filters( 'ens_'.$this->identifier.'_available_actions', [] )
        ] );
    }
}
