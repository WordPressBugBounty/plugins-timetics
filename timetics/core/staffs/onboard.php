<?php
/**
 * Staff Onboard Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Staffs;

use Timetics\Utils\Singleton;

/**
 * Onboard Class
 */
class Onboard {
    use Singleton;

    /**
     * Initialize the class
     *
     * @return  void
     */
    public function init() {
        add_action( 'admin_menu', [ $this, 'add_page' ] );
        add_action( 'admin_init', [ $this, 'setup_onboard_page' ] );
    }

    /**
     * Add dashboard page for staff onboard
     *
     * @return  void
     */
    public function add_page() {
        add_dashboard_page( '', '', 'manage_timetics', 'staff-onboard', '' );
    }

    /**
     * Setup onboard page  template
     *
     * @return  void
     */
    public function setup_onboard_page() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';

        if ( 'staff-onboard' !== $page ) {
            return;
        }
        ob_start();
        include_once TIMETICS_PLUGIN_DIR . '/templates/staff/onboard.php';
        exit();
    }
}
