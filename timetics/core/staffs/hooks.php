<?php
/**
 * Handling Staff Related Hooks
 *
 * @package Timetics
 */
namespace Timetics\Core\Staffs;

use Timetics\Core\Staffs\Onboard;
use Timetics\Utils\Singleton;

class Hooks {
    use Singleton;

    /**
     * Intialie
     *
     * @return void
     */
    public function init() {
        add_filter( 'retrieve_password_notification_email', [$this, 'reset_email_content'], 10, 4 );
        add_filter( 'retrieve_password_title', [$this, 'reset_email_title'] );

        add_action( 'after_password_reset', [$this, 'update_staff_status'] );

        add_filter( 'user_row_actions', [$this, 'user_row_action'], 10, 2 );

        add_action( 'admin_init', [$this, 'make_staff'] );

        add_action( 'init', [$this, 'setup_staff_onboard'] );

        add_filter( 'login_redirect', [$this, 'login_redirect'], 10, 3 );

        add_action( 'wp_ajax_timetics_staff_onboard_skip', [$this, 'timetics_staff_onboard_skip'] );
    }

    /**
     * Reset user activation and reset password link
     *
     * @param   array  $config      [$config description]
     * @param   string  $key         [$key description]
     * @param   [type]  $user_login  [$user_login description]
     * @param   [type]  $user_data   [$user_data description]
     *
     * @return  [type]               [return description]
     */
    public function reset_email_content( $config, $key, $user_login, $user_data ) {
        $headers = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

        $local     = get_locale();
        $reset_url = site_url( "wp-login.php?action=rp&key={$key}&login={$user_login}&wp_lang={$local}" );

        $reset_url = apply_filters( 'timetics_staff_password_reset_url', $reset_url, $key, $user_login, $local );

        ob_start();
        include TIMETICS_PLUGIN_DIR . '/templates/emails/email-header.php';
        include TIMETICS_PLUGIN_DIR . '/templates/emails/new-staff.php';
        include TIMETICS_PLUGIN_DIR . '/templates/emails/email-footer.php';
        $message = ob_get_clean();

        $config['message'] = $message;
        $config['headers'] = $headers;

        return $config;
    }

    /**
     * Update staff status after updating password
     *
     * @param   Object  $user
     *
     * @return  void
     */
    public function update_staff_status( $user ) {
        $staff = new Staff( $user->ID );

        if ( ! $staff->is_staff() ) {
            return;
        }

        $staff->update(
            [
                'status' => 1,
            ]
        );
    }

    /**
     * Show row actions for make staff
     *
     * @param   array  $actions
     * @param   Object $user_object
     *
     * @return void
     */
    public function user_row_action( $actions, $user_object ) {
        $is_staff    = user_can( $user_object, 'timetics-staff' );
        $button_text = $is_staff ? esc_html__( 'Remove from staff', 'timetics' ) : esc_html__( 'Make staff', 'timetics' );
        $action      = $is_staff ? 'removestaff' : 'makestaff';

        $actions['staff'] = "<a class='staff' href='" . wp_nonce_url( "users.php?action=$action&amp;users=$user_object->ID", 'bulk-users' ) . "'>" . $button_text . '</a>';

        return $actions;
    }

    /**
     * Make staff
     *
     * @return mixed
     */
    public function make_staff() {
    
        $action  = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
        $user_id = isset( $_GET['users'] ) ? intval( $_GET['users'] ) : 0;
        $nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $user    = get_userdata( $user_id );
    
        if ( 'makestaff' === $action ) {

            if ( !wp_verify_nonce( $nonce, 'bulk-users' ) ) {
                return;
            }

            $capability = current_user_can( 'manage_options' );
                if( !$capability) return;

                $user->add_role( 'timetics-staff' );
                $staff = new Staff( $user_id );
                $staff->update(
                    [
                        'status' => 1,
                    ]
                );
        }

        if ( 'removestaff' === $action ) {

            if ( !wp_verify_nonce( $nonce, 'bulk-users' ) ) {
                return;
            }

            $capability = current_user_can( 'manage_options' );
            if( !$capability ) return;

            $user->remove_role( 'timetics-staff' );
        }
    }

    /**
     * Setup staff onboard page
     *
     * @return  void
     */
    public function setup_staff_onboard() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';

        if ( 'staff-onboard' === $page ) {
            Onboard::instance()->init();
        }
    }

    /**
     * Setup redirect uri
     *
     * @param   string  $reidrect_to
     * @param   string  $requeted_redirect
     * @param   WP_User  $user Login user
     *
     * @return void
     */
    public function login_redirect( $reidrect_to, $requeted_redirect, $user ) {
        global $user;

        $roles        = isset( $user->roles ) ? $user->roles : [];

        if ( is_wp_error( $user ) ) {
            return $reidrect_to;
        }

        $onboard_skip = get_user_meta( $user->ID, 'timetics_staff_onboard_skip', true );

        if ( $onboard_skip ) {
            return $reidrect_to;
        }

        if ( ! in_array( 'timetics-staff', $roles ) ) {
            return $reidrect_to;
        }

        $integrations = timetics_get_staff_integrations( $user->ID );

        if ( ! $integrations[0]['connected'] ) {
            $reidrect_to = admin_url( 'admin.php?page=staff-onboard' );
        }

        return $reidrect_to;
    }

    /**
     * Update password reset email title
     *
     * @param   string  $title
     *
     * @return  string
     */
    public function reset_email_title( $title ) {
        $title = sprintf( esc_html__( 'Welcome to %s onboarding!', 'timetics' ), get_bloginfo( 'name' ) );

        return $title;
    }

    /**
     * Staff onboard skip
     *
     * @return void
     */
    public function timetics_staff_onboard_skip() {
        update_user_meta( get_current_user_id(), 'timetics_staff_onboard_skip', true );

        wp_send_json_success( __( 'Staff onboard skipped successfully.', 'timetics' ) );
    }
}