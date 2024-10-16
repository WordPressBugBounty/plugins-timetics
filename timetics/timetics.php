<?php

/**
 * Plugin Name:       Timetics
 * Plugin URI:        https://arraytics.com/timetics/
 * Description:       Schedule, Appointment and Seat Booking plugin.
 * Version:           1.0.26
 * Requires at least: 5.2
 * Requires PHP:      7.3
 * Author:            Arraytics
 * Author URI:        https://arraytics.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       timetics
 * Domain Path:       /languages

 * Timetics is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.

 * Timetics Essential is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with Timetics. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package Timetics
 * @category Core
 * @author Arraytics
 * @version 1.0.10
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Main Plugin Requirements Checker
 *
 * @since 1.0.0
 */
final class Timetics {

    /**
     * Static Property To Hold Singleton Instance
     *
     * @var Timetics The Timetics Requirement Checker Instance
     */
    private static $instance;

    /**
     * Plugin Current Production Version
     *
     * @return string
     */
    public static function get_version() {
        return '1.0.26';
    }

    /**
     * Requirements Array
     *
     * @since 1.0.0
     * @var array
     */
    private $requirements = array(
        'php' => array(
            'name'    => 'PHP',
            'minimum' => '7.3',
            'exists'  => true,
            'met'     => false,
            'checked' => false,
            'current' => false,
        ),
        'wp'  => array(
            'name'    => 'WordPress',
            'minimum' => '5.2',
            'exists'  => true,
            'checked' => false,
            'met'     => false,
            'current' => false,
        ),
    );

    /**
     * Singleton Instance
     *
     * @return Timetics
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Setup Plugin Requirements
     *
     * @since 1.0.0
     */
    private function __construct() {
        // Always load translation.
        add_action( 'plugins_loaded', array( $this, 'load_text_domain' ) );

        // Initialize plugin functionalities or quit.
        $this->requirements_met() ? $this->initialize_modules() : $this->quit();
    }

    /**
     * Load Localization Files
     *
     * @since 1.0
     * @return void
     */
    public function load_text_domain() {
        $locale = apply_filters( 'plugin_locale', get_user_locale(), 'timetics' );

        unload_textdomain( 'timetics' );
        load_textdomain( 'timetics', WP_LANG_DIR . '/timetics/timetics-' . $locale . '.mo' );
        load_plugin_textdomain( 'timetics', false, self::get_plugin_dir() . 'languages/' );
    }

    /**
     * Initialize Plugin Modules
     *
     * @since 1.0.0
     * @return void
     */
    private function initialize_modules() {
        require_once dirname( __FILE__ ) . '/autoloader.php';
        require_once dirname( __FILE__ ) . '/core/settings/settings.php';

        require_once dirname( __FILE__ ) . '/utils/global-helper.php';

        // block for showing banner.
		require_once plugin_dir_path( __FILE__ ) . '/utils/notice/notice.php';
		require_once plugin_dir_path( __FILE__ ) . '/utils/banner/banner.php';
		require_once plugin_dir_path( __FILE__ ) . '/utils/pro-awareness/pro-awareness.php';

        // Include the bootstrap file if not loaded.
        if ( ! class_exists( 'Timetics\Bootstrap' ) ) {
            require_once self::get_plugin_dir() . 'bootstrap.php';
        }

        // init notice class.
		\Oxaim\Libs\Notice::init();

		// init pro menu class.
		\Wpmet\Libs\Pro_Awareness::init();

        // Initialize the bootstraper if exists.
        if ( class_exists( 'Timetics\Bootstrap' ) ) {

            // Initialize all modules through plugins_loaded.
            add_action( 'plugins_loaded', array( $this, 'init' ) );

            register_activation_hook( self::get_plugin_file(), array( $this, 'activate' ) );
            register_deactivation_hook( self::get_plugin_file(), array( $this, 'deactivate' ) );
        }
    }

    /**
     * Check If All Requirements Are Fulfilled
     *
     * @return boolean
     */
    private function requirements_met() {
        $this->prepare_requirement_versions();

        $passed  = true;
        $to_meet = wp_list_pluck( $this->requirements, 'met' );

        foreach ( $to_meet as $met ) {
            if ( empty( $met ) ) {
                $passed = false;
                continue;
            }
        }

        return $passed;
    }

    /**
     * Requirement Version Prepare
     *
     * @since 1.0.0
     *
     * @return void
     */
    private function prepare_requirement_versions() {
        foreach ( $this->requirements as $dependency => $config ) {
            switch ( $dependency ) {
            case 'php':
                $version = phpversion();
                break;
            case 'wp':
                $version = get_bloginfo( 'version' );
                break;
            default:
                $version = false;
            }

            if ( ! empty( $version ) ) {
                $this->requirements[$dependency]['current'] = $version;
                $this->requirements[$dependency]['checked'] = true;
                $this->requirements[$dependency]['met']     = version_compare( $version, $config['minimum'], '>=' );
            }
        }
    }

    /**
     * Initialize everything
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init() {
        Timetics\Bootstrap::instantiate( self::get_plugin_file() );
    }

    /**
     * Called Only Once While Activation
     *
     * @return void
     */
    public function activate() {
        // Insert new role.
        Timetics\Base\Role::instance()->init();

        // Update default settings.
        timetics_update_default_settings();

        // Register cron
        timetics_register_cron();

        // Create new woocommerce product category for timetics meeting
        timetics_add_woocommerce_product_cat();

        // Update option for onboard settings.
        $timetics_onboard_setup = get_option( 'timetics_onboard_setup' );

        if ( ! $timetics_onboard_setup ) {
            update_option( 'timetics_onboard_settings', true );
        }
    }

    /**
     * Called Only Once While Deactivation
     *
     * @return void
     */
    public function deactivate() {
    }

    /**
     * Quit Plugin Execution
     *
     * @return void
     */
    private function quit() {
        add_action( 'admin_head', array( $this, 'show_plugin_requirements_not_met_notice' ) );
    }

    /**
     * Show Error Notice For Missing Requirements
     *
     * @return void
     */
    public function show_plugin_requirements_not_met_notice() {
        printf( '<div>Minimum requirements for Timetics are not met. Please update requirements to continue.</div>' );
    }

    /**
     * Plugin Main File
     *
     * @return string
     */
    public static function get_plugin_file() {
        return __FILE__;
    }

    /**
     * Plugin Base Directory Path
     *
     * @return string
     */
    public static function get_plugin_dir() {
        return trailingslashit( plugin_dir_path( self::get_plugin_file() ) );
    }
}

Timetics::get_instance();
