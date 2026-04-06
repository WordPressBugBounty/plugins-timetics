<?php
/**
 * Staff Onboard Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Staffs;

defined( 'ABSPATH' ) || exit;

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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page parameter check, no form processing
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        if ( 'staff-onboard' !== $page ) {
            return;
        }

        $template = TIMETICS_PLUGIN_DIR . '/templates/staff/onboard.php';
        
        if ( file_exists( $template ) ) {
            include $template;
        }
        
        $output = ob_get_clean();
        
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $output;
        
        exit;
    }
}
