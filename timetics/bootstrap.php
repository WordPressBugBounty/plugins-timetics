<?php
/**
 * Bootstrap Class
 *
 * @package Timetics
 */
namespace Timetics;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap class.
 *
 * @since 1.0.0
 */
final class Bootstrap {
    /**
     * @var Bootstrap The Actual Timetics instance
     * @since 1.0.0
     */
    private static $instance;


	private $file;
	public $version;

    /**
     * Throw Error While Trying To Clone Object
     *
     * @since 1.0.0
     * @return void
     */
    public function __clone() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'timetics' ), '1.0.0' );
    }

    /**
     * Disabling Un-serialization Of This Class
     */
    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'timetics' ), '1.0.0' );
    }

    /**
     * The actual TimeTics instance
     *
     * @since 1.0.0
     * @param string $file
     * @return void
     */
    public static function instantiate( $file = '' ) {

        // Return if already instantiated
        if ( self::instantiated() ) {
            return self::$instance;
        }

        self::prepare_instance( $file );

        self::$instance->initialize_constants();
        self::$instance->define_tables();
        self::$instance->include_files();
        self::$instance->initialize_components();

        return self::$instance;
    }

    /**
     * Return If The Main Class has Already Been Instantiated Or Not
     *
     * @since 1.0.0
     * @return boolean
     */
    private static function instantiated() {
        if ( ( null !== self::$instance ) && ( self::$instance instanceof Bootstrap ) ) {
            return true;
        }

        return false;
    }

    /**
     * Prepare Singleton Instance
     *
     * @since 1.0.0
     * @param string $file
     * @return void
     */
    private static function prepare_instance( $file = '' ) {
        self::$instance          = new self();
        self::$instance->file    = $file;
        self::$instance->version = \Timetics::get_version();
    }

    /**
     * Assets Directory URL
     *
     * @since 1.0.0
     * @return void
     */
    public function get_assets_url() {
        return trailingslashit( TIMETICS_PLUGIN_URL . 'assets' );
    }

    /**
     * Assets Directory Path
     *
     * @since 1.0.0
     * @return void
     */
    public function get_assets_dir() {
        return trailingslashit( TIMETICS_PLUGIN_DIR . 'assets' );
    }

    /**
     * Plugin Directory URL
     *
     * @return void
     */
    public function get_plugin_url() {
        return trailingslashit( plugin_dir_url( TIMETICS_PLUGIN_FILE ) );
    }

    /**
     * Plugin Directory Path
     *
     * @return void
     */
    public function get_plugin_dir() {
        return \Timetics::get_plugin_dir();
    }

    /**
     * Plugin Basename
     *
     * @return void
     */
    public function get_plugin_basename() {
        return plugin_basename( TIMETICS_PLUGIN_FILE );
    }

    /**
     * Setup Plugin Constants
     *
     * @since 1.0.0
     * @return void
     */
    private function initialize_constants() {
        // Plugin Version
        self::$instance->define( 'TIMETICS_VERSION', \Timetics::get_version() );

        // Plugin Main File
        self::$instance->define( 'TIMETICS_PLUGIN_FILE', $this->file );

        // Plugin File Basename
        self::$instance->define( 'TIMETICS_PLUGIN_BASE', $this->get_plugin_basename() );

        // Plugin Main Directory Path
        self::$instance->define( 'TIMETICS_PLUGIN_DIR', $this->get_plugin_dir() );

        // Plugin Main Directory URL
        self::$instance->define( 'TIMETICS_PLUGIN_URL', $this->get_plugin_url() );

        // Plugin Assets Directory URL
        self::$instance->define( 'TIMETICS_ASSETS_URL', $this->get_assets_url() );

        // Plugin Assets Directory Path
        self::$instance->define( 'TIMETICS_ASSETS_DIR', $this->get_assets_dir() );


    }

    /**
     * Define constant if not already set.
     *
     * @since 1.0.0
     * @param string      $name  Constant name.
     * @param string|bool $value Constant value.
     */
    private function define( $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * Define DB Tables Required For This Plugin
     *
     * @since 1.0.0
     * @return void
     */
    private function define_tables() {
        // To Be Implemented
    }

    /**
     * Include All Required Files
     *
     * @since 1.0.0
     * @return void
     */
    private function include_files() {
        // require_once TIMETICS_PLUGIN_DIR . 'includes/post-types.php';
        // require_once TIMETICS_PLUGIN_DIR . 'includes/widgets/manifest.php';
        // require_once TIMETICS_PLUGIN_DIR . 'includes/template-functions.php';
        // require_once TIMETICS_PLUGIN_DIR . 'includes/shortcodes.php';
        // require_once TIMETICS_PLUGIN_DIR . 'includes/install.php';

        /**
		 * Core helpers.
		 */
		require_once TIMETICS_PLUGIN_DIR . 'utils/global-helper.php';
    }

    /**
     * Initialize All Components
     *
     * @since 1.0.0
     * @return void
     */
    private function initialize_components() {

        // Register scripts and styles first
        if ( $this->is_request( 'admin' ) ) {
            add_action( 'admin_enqueue_scripts', [ $this, 'admin_scripts' ] );
        }

        if ( $this->is_request( 'frontend' ) ) {
            add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );
        }

        // Register admin menu
        Core\Admin\Menu::instance()->init();
        Core\Base::instance()->init();
        Core\Services\Hooks::instance()->init();
        Base\Custom_Endpoint::instance()->init();

        global $current_screen;

        $this->handle_buy_pro_module();

        if ( function_exists('WC') ) {
            Core\Integrations\Woocommerce\Hooks::instance()->init();
        }
    }

    /**
     * demo url for set pro button
     *
     * @return void
     */
    public function demo_url(){
        return "https://arraytics.com/timetics/";
    }

    /**
     * Register scripts and styles for admin
     *
     * @return void
     */
    public function admin_scripts( $handle ) {
        if ( 'toplevel_page_timetics' !== $handle ) {
            return;
        }
        global $wp_locale;

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_style( 'timetics-admin-style', TIMETICS_ASSETS_URL . 'css/admin.css', [], TIMETICS_VERSION, 'all' );
        wp_enqueue_style( 'timetics-vendor', TIMETICS_ASSETS_URL . 'css/vendor.css', [], TIMETICS_VERSION, 'all' );
        wp_enqueue_script( 'timetics-flatpickr-scripts', TIMETICS_ASSETS_URL . 'js/vendors/flatpickr.js', [ 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data' ], TIMETICS_VERSION, true );
        wp_enqueue_script( 'timetics-packages', TIMETICS_ASSETS_URL . 'js/packages.js', [ 'timetics-flatpickr-scripts' ], TIMETICS_VERSION, true );

        $calendar_locale = timetics_get_option('calendar_locale');
        if (!empty($calendar_locale)) {
            wp_enqueue_script( 'calendar-locale', TIMETICS_ASSETS_URL . "js/local/{$calendar_locale}.js", [ 'jquery', 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data' ], TIMETICS_VERSION, true );
        }

        wp_enqueue_script( 'timetics-dashboard-scripts', TIMETICS_ASSETS_URL . 'js/dashboard.js', [ 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data', 'wp-color-picker' ], TIMETICS_VERSION, true );


        $localize_obj = array(
            'site_url'            => site_url(),
            'admin_url'           => admin_url(),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'timezone'            => timetics_get_timezone_list(),
            'stripe_pub_key'      => timetics_get_option( 'stripe_pub_key' ),
            'date_format'         => get_option( 'date_format' ),
            'date_format_string'  => date_i18n( get_option( 'date_format' ) ),
            'time_format'         => get_option( 'time_format' ),
            'time_format_string'  => date_i18n( get_option( 'time_format' ) ),
            'start_of_week'       => get_option( 'start_of_week', 0 ),

            'default_timezone'    => timetics_get_default_timezone(),
            'currency_list'       => timetics_get_currencies(),
            'current_user_id'     => get_current_user_id(),
            'timeticsPro'         => class_exists('TimeticsPro') ? true : false,
            'demo_url'            =>  $this->demo_url(),
            'current_user'        => timetics_get_current_user(),
		);

        $localize_obj = apply_filters( 'timetics_admin_localize_data', $localize_obj );

        wp_localize_script( 'timetics-dashboard-scripts', 'timetics', $localize_obj );
    }

    /**
     * Register scripts and styles for frontend
     *
     * @return void
     */
    public function frontend_scripts() {
        wp_register_style( 'timetics-vendor', TIMETICS_ASSETS_URL . 'css/vendor.css', [], TIMETICS_VERSION, 'all' );
        wp_register_style( 'timetics-frontend', TIMETICS_ASSETS_URL . 'css/frontend.css', [], TIMETICS_VERSION, 'all' );

        wp_register_script( 'timetics-flatpickr-scripts', TIMETICS_ASSETS_URL . 'js/vendors/flatpickr.js', [ 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data' ], TIMETICS_VERSION, true );
        wp_enqueue_script( 'timetics-packages', TIMETICS_ASSETS_URL . 'js/packages.js', [ 'timetics-flatpickr-scripts' ], TIMETICS_VERSION, true );

        wp_register_script( 'timetics-frontend-scripts', TIMETICS_ASSETS_URL . 'js/frontend.js', [ 'jquery', 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data' ], TIMETICS_VERSION, true );

        $calendar_locale = timetics_get_option('calendar_locale');
        if (!empty($calendar_locale)) {
            wp_register_script( 'calendar-locale', "https://npmcdn.com/flatpickr/dist/l10n/{$calendar_locale}.js", [ 'jquery', 'wp-plugins', 'wp-i18n', 'wp-element', 'wp-dom', 'wp-data' ], TIMETICS_VERSION, true );
        }

        // load text domain
        wp_set_script_translations( 'timetics-frontend-scripts', 'timetics',  TIMETICS_PLUGIN_DIR . 'languages/' );

        global $wp_locale;

        $stripe_pub_key     = timetics_get_option( 'stripe_pub_key' );
        $stripe_secret_key  = timetics_get_option( 'stripe_secret_key' );
        $stripe_configure   = $stripe_pub_key && $stripe_secret_key;

        $localize_obj = array(
            'site_url'            => site_url(),
            'nonce'               => wp_create_nonce( 'wp_rest' ),
            'date_format'         => get_option( 'date_format' ),
            'time_format'         => get_option( 'time_format' ),
            'stripe_pub_key'      => $stripe_pub_key,
            'currency'            => apply_filters( 'timetics_currency', timetics_get_option( 'currency', 'USD' ) ),
            'stripe_configure'    => $stripe_configure,
            'current_user_id'     => get_current_user_id(),
            'start_of_week'       => get_option( 'start_of_week', 0 ),
            'currency_list'       => timetics_get_currencies(),
            'locale_name'        => strtolower( str_replace( '_', '-', get_locale() ) )

        );

        $localize_obj = apply_filters( 'timetics_frontned_localize_data', $localize_obj );

        wp_localize_script( 'timetics-frontend-scripts', 'timetics', $localize_obj );
    }

    /**
     * What type of request
     *
     * @param string $type admin,frontend, ajax, cron
     * @return boolean
     */
    private function is_request( $type ) {
        switch ( $type ) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined( 'DOING_AJAX' );
            case 'cron':
                return defined( 'DOING_CRON' );
            case 'frontend':
                return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' ) && ! $this->is_rest_api_request();
        }
    }

    /**
     * Returns if the request is non-legacy REST API request.
     *
     * @return boolean
     */
    private function is_rest_api_request() {
        $server_request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : false;

        if ( ! $server_request ) {
            return false;
        }

        $rest_prefix        = trailingslashit( rest_get_url_prefix() );
        $is_rest_request    = ( false !== strpos( $server_request, $rest_prefix ) );

		return apply_filters( 'timetics_is_rest_api_request', $is_rest_request );
    }


    /**
	 * Show buy-pro menu if pro plugin not active
	 *
	 * @return void
	 */
	public function handle_buy_pro_module() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';

        if ( 'timetics' !== $page ) {
            return;
        }

		/**
		 * Show banner (codename: jhanda)
		 */
		$filter_string = 'timetics,timetics-free-only';

		\Wpmet\Libs\Banner::instance('timetics')
			// ->is_test(true)
			->set_filter(ltrim($filter_string, ','))
			->set_api_url('https://demo.themewinter.com/public/jhanda')
			->set_plugin_screens('edit-timetics')
			->set_plugin_screens('timetics_page_timetics_sales_report')
			->set_plugin_screens('edit-timetics-attendee')
  			->set_plugin_screens('timetics_page_timetics_get_help')
 			->call();
		// show get-help and upgrade-to-premium menu.
		// $this->handle_get_help_and_upgrade_menu();
	}

}

/**
 * Returns The Instance Of Timetics.
 * The main function that is responsible for returning Timetics instance.
 *
 * @since 1.0.0
 * @return Timetics
 */
function timetics() {
    return Bootstrap::instantiate();
}
