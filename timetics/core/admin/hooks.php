<?php
/**
 * Admin hooks
 *
 * @package Timetics
 */
namespace Timetics\Core\Admin;

defined( 'ABSPATH' ) || exit;

use Timetics\Utils\Singleton;


class Hooks {
    use Singleton;

    /**
     * Initialize admin hooks
     *
     * @return void
     */
    public function init() {
        add_action('admin_notices', [ $this, 'add_woocommerce_notice_on_admin_dashboard' ] );

        // Dismiss the notice
        add_action('wp_ajax_dismiss_woocommerce_notice', [ $this, 'dismiss_woocommerce_notice'] );

        add_filter('plugin_action_links', [ $this, 'add_settings_plugin_tab' ], 10, 2);

        add_action( 'admin_init', [ $this, 'redirect_onboard' ] );

        // Update user roles and permissions for multisite.
        add_action( 'wp_initialize_site', [ $this, 'update_user_role_on_insert_new_site' ], 110 );

        add_action( 'set_user_role', [ $this, 'set_roles' ], 10, 2 );

        add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_customers' ] );
        add_action( 'admin_init', [ $this, 'block_admin_for_customers' ] );

        add_filter( 'timetics_menu', [ $this, 'update_busyness_menu' ] );

        add_filter( 'timetics_settings', [ $this, 'set_google_auth_url' ] );

        add_filter( 'timetics_get_settings', [ $this, 'set_email_default_content' ] );

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        do_action('timetics_admin_init');
    }

    /**
     * Add admin notice for
     *
     * @return  void
     */
    public function add_woocommerce_notice_on_admin_dashboard() {

        $woocommerce_notice_dismiss = get_user_meta( get_current_user_id(), 'timetics_woocommerce_notice_dismissed', true );

        if ( $woocommerce_notice_dismiss ) {
            return;
        }

        if ( ! timetics_get_option( 'wc_integration' ) ) {
            return;
        }

        if ( function_exists( 'WC' ) ) {
            return;
        }

        $notice = '<div class="notice notice-danger is-dismissible error">';
        $notice .= sprintf('<p><strong>%s</strong></p>', __( 'Timetics requires WooCommerce to enable woocommerce integration. You can download <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a> here.', 'timetics' ) );
        $notice .= '</div>';

        echo wp_kses_post( $notice );

        $admin_ajax_url = admin_url('admin-ajax.php');

        ?>
            <script>
                jQuery(document).ready(function($) {
                    $('.notice.is-dismissible').on('click', '.notice-dismiss', function() {
                        var data = {
                            action: 'dismiss_woocommerce_notice',
                        };

                        $.post('<?php echo esc_url( $admin_ajax_url ); ?>', data, function(response) {
                            console.log(response);
                        });
                    });
                });
            </script>
        <?php
    }

    /**
     * Update admin notice
     *
     * @return void
     */
    public function dismiss_woocommerce_notice() {
        // Store the notice state in user meta
        update_user_meta( get_current_user_id(), 'timetics_woocommerce_notice_dismissed', true );

        wp_send_json_success('Notice dismissed successfully.');
    }

    /**
     * Add settings on plugin action row
     *
     * @param   array  $actions
     * @param   string  $plugin_file
     *
     * @return  array
     */
    public function add_settings_plugin_tab( $actions, $plugin_file ) {
        // Check if the plugin file matches your target plugin
        if ( 'timetics/timetics.php' === $plugin_file ) {
            // Add your custom tab
            $settings_tab = array(
                'timetics-settings' => sprintf( '<a href="%s">%s</a>', esc_url( admin_url('admin.php?page=timetics#/settings') ), __( 'Settings', 'timetics' ) ),
            );
            // Merge the custom tab with existing actions
            $actions = array_merge( $settings_tab, $actions );
        }
        return $actions;
    }

    /**
     * Redirect onboarding page after activating
     *
     * @return  void
     */
    public function redirect_onboard() {
        $onboarding_setup = get_option( 'timetics_onboard_settings' );

        if ( ! $onboarding_setup ) {
            return;
        }

        update_option( 'timetics_onboard_setup', true );
        update_option( 'timetics_onboard_settings', false );

        wp_safe_redirect( admin_url('admin.php?page=timetics#/onboard') );
        exit;
    }

    /**
     * Update site admin roles whne new site created
     *
     * @param  WP_Site   $site_object
     *
     * @return void
     */
    public function update_user_role_on_insert_new_site( $site_object ) {
        global $wpdb;

        $roles = wp_roles()->roles;
        $blog_id = $site_object->blog_id;
        $roles_option_key = $wpdb->get_blog_prefix( $blog_id ) . 'user_roles';

        update_blog_option( $blog_id, $roles_option_key, $roles );
    }

    /**
     * Update user role timetics staff and customer if user is admin
     *
     * @param   integer  $user_id
     * @param   string  $role
     *
     * @return  void
     */
    public function set_roles( $user_id, $role ) {
        if ( 'administrator' !== $role ) {
            return;
        }

        $user = get_userdata( $user_id );
        $user->add_role('timetics-staff');
        $user->add_role('timetics-customer');
    }

    /**
     * Hide the WP admin toolbar for customer-only users.
     *
     * @param   bool  $show
     *
     * @return  bool
     */
    public function hide_admin_bar_for_customers( $show ) {
        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            return $show;
        }

        if ( $this->is_customer_only( $user ) ) {
            return false;
        }

        return $show;
    }

    /**
     * Redirect customer-only users away from /wp-admin/.
     *
     * @return  void
     */
    public function block_admin_for_customers() {
        if ( wp_doing_ajax() ) {
            return;
        }

        $user = wp_get_current_user();

        if ( $user && $user->exists() && $this->is_customer_only( $user ) ) {
            wp_safe_redirect( home_url() );
            exit;
        }
    }

    /**
     * Whether the given user's only Timetics-relevant role is timetics-customer.
     *
     * @param   \WP_User  $user
     *
     * @return  bool
     */
    private function is_customer_only( $user ) {
        $roles = (array) $user->roles;

        return in_array( 'timetics-customer', $roles, true )
            && ! array_intersect( $roles, [ 'administrator', 'editor', 'author', 'contributor', 'timetics-staff' ] );
    }

    /**
     * Update busyness menu item
     *
     * @param   array  $menu_items
     *
     * @return array
     */
    public function update_busyness_menu($menu_items) {
        $busyness_category = timetics_get_busyness_category();

        if ( ! $busyness_category ) {
            return $menu_items;
        }

        $busyness = timetics_get_busyness( $busyness_category );
        $new_menu_items = [];

        foreach ( $menu_items as $menu ) {
            if ( empty( $menu['id'] ) ) {
                continue;
            }

            if ( ! empty( $busyness[$menu['id']] ) ) {
                $menu['title'] = $busyness[$menu['id']];
            }

            $new_menu_items[] = $menu;
        }

        return $new_menu_items;
    }

    /**
     * Set google auth url
     *
     * @param   array  $settings
     *
     * @return  array
     */
    public function set_google_auth_url( $settings ) {
        $settings['google_auth_redirect_uri'] = timetics_get_auth_redirect_uri( 'google-auth' );

        return $settings;
    }

    /**
     * Set default settings
     *
     * @param   array  $settings  [$settings description]
     *
     * @return  mixed
     */
    public function set_email_default_content( $settings ) {


        $admin_email = get_option( 'admin_email' );

        /* translators: {%customer_name%} is a token replaced at runtime with the recipient customer's name */
        $customer_greeting = __( 'Hi {%customer_name%}', 'timetics' );
        /* translators: {%host_name%} is a token replaced at runtime with the recipient host's name */
        $host_greeting = __( 'Hi {%host_name%}', 'timetics' );
        $new_meeting_msg = __( 'A new meeting has been scheduled.', 'timetics' );
        /* translators: {%meeting_title%} is a token replaced at runtime with the meeting title */
        $canceled_msg = __( '{%meeting_title%} has been canceled.', 'timetics' );
        /* translators: {%meeting_title%} is a token replaced at runtime with the meeting title */
        $rescheduled_msg = __( '{%meeting_title%} has been rescheduled', 'timetics' );
        /* translators: {%meeting_title%}, {%meeting_date%}, {%meeting_time%} are tokens replaced at runtime with the meeting details */
        $reminder_msg = __( '{%meeting_title%} at {%meeting_date%} {%meeting_time%}', 'timetics' );

        $defaults = [

            'booking_created_customer_email_from'       => $admin_email,
            'booking_created_customer_email_title'      => sprintf('%s', __( 'New meeting scheduled!', 'timetics' )),
            'booking_created_customer_email_body'       => sprintf('<p>%s</p><p>%s</p>', $customer_greeting, $new_meeting_msg),

            'booking_created_host_email_from'           => $admin_email,
            'booking_created_host_email_title'          => sprintf('%s', __( 'New meeting scheduled!', 'timetics' )),
            'booking_created_host_email_body'           => sprintf('<p>%s</p><p>%s</p>', $host_greeting, $new_meeting_msg),


            'booking_canceled_customer_email_from'      => $admin_email,
            'booking_canceled_customer_email_title'     => sprintf('%s', __( 'Meeting cancelled', 'timetics' )),
            'booking_canceled_customer_email_body'      => sprintf('<p>%s</p><p>%s</p>', $customer_greeting, $canceled_msg),

            'booking_canceled_host_email_from'      => $admin_email,
            'booking_canceled_host_email_title'     => sprintf('%s', __( 'Meeting cancelled', 'timetics' )),
            'booking_canceled_host_email_body'      => sprintf('<p>%s</p><p>%s</p>', $host_greeting, $canceled_msg),

            'booking_rescheduled_customer_email_from'   => $admin_email,
            'booking_rescheduled_customer_email_title'  => sprintf('%s', __( 'Meeting rescheduled!', 'timetics' )),
            'booking_rescheduled_customer_email_body'   => sprintf('<p>%s</p><p>%s</p>', $host_greeting, $rescheduled_msg),

            'booking_rescheduled_host_email_from'   => $admin_email,
            'booking_rescheduled_host_email_title'  => sprintf('%s', __('Meeting rescheduled!', 'timetics' )),
            'booking_rescheduled_host_email_body'   => sprintf('<p>%s</p><p>%s</p>', $host_greeting, $rescheduled_msg),

            'booking_reminder_customer_email_from'      => $admin_email,
            'booking_reminder_customer_email_title'     => sprintf('%s', __( 'Meeting time reminder', 'timetics' )),
            'booking_reminder_customer_email_body'      => sprintf('<p>%s</p><p>%s</p>', $customer_greeting, $reminder_msg),

            'booking_reminder_host_email_from'          => $admin_email,
            'booking_reminder_host_email_title'         => sprintf('%s', __( 'Meeting time reminder', 'timetics' )),
            'booking_reminder_host_email_body'          => sprintf('<p>%s</p><p>%s</p>', $host_greeting, $reminder_msg),
        ];

        $settings = wp_parse_args( $settings, $defaults );

        return $settings;
    }
}
