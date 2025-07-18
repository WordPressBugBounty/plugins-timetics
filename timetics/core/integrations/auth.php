<?php
/**
 * Authentication for integrations
 *
 * @package Timetics
 */

namespace Timetics\Core\Integrations;

use Timetics\Core\Integrations\Google\Service\Calendar;
use Timetics\Utils\Singleton;

/**
 * Auth Class
 *
 * @since 1.0.0
 */
class Auth {
    use Singleton;

    /**
     * Initialize
     *
     * @return  void
     */
    public function init() {
        add_action( 'template_redirect', [ $this, 'authenticate' ] );
    }

    /**
     * Authenticate integration
     *
     * @return  void
     */
    public function authenticate() {
        $query_var = get_query_var( 'timetics-integration', false );
        $user_id   = get_current_user_id();

        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';

        if ( ! $query_var ) {
            return;
        }

        switch ( $query_var ) {
			case 'google-auth':
				$this->google_auth( $code );
                break;
        }

        do_action('timetics_integration_auth', $query_var, $code );

        $redirect_url = admin_url('admin.php?page=timetics#/my-profile');

        if ( current_user_can('manage_options') ) {
            $redirect_url = admin_url('admin.php?page=timetics#/settings');
        }

        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Authentication for google
     *
     * @param   string  $code
     *
     * @return  void
     */
    public function google_auth( $code = '' ) {
        $client = timetics_get_google_client();

        // If no code is provided, redirect to Google's authorization page
        if ( empty($code) ) {
            $client->add_scope( Calendar::scope() );
            $auth_url = $client->get_auth_url();
            wp_redirect($auth_url);
            exit;
        }

        try {
            $client->add_scope( Calendar::scope() );
            $data = $client->fetch_access_token_with_auth_code( $code );
            $data['code'] = $code;

            timetics_update_google_auth( get_current_user_id(), $data );

            wp_redirect(admin_url('admin.php?page=timetics#/settings'));
            exit;
        } catch (\Exception $e) {
            $error_message = sprintf(
                '<p>%s</p><p><a href="%s" class="button button-primary">Go Back</a></p>',
                esc_html( $e->getMessage() ),
                esc_url( admin_url('admin.php?page=timetics#/settings') )
            );

            wp_die($error_message, esc_html__('Google Auth Error', 'timetics'), array('back_link' => true));
        }
    }

    /**
     * Authentication for zoom
     *
     * @param   string  $code
     *
     * @return void
     */
    public function zoom_auth( $code = '' ) {
        // Override this function to authenticate zoom
    }
}
