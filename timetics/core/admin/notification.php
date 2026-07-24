<?php
/**
 * Notification class — boots themewinter/email-notification-sdk and registers
 * Timetics triggers so the React automation builder can wire up email flows.
 *
 * @package Timetics
 */

namespace Timetics\Core\Admin;

use Ens\Core\SDK;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer;
use Timetics\Core\Staffs\Staff;
use Timetics\Utils\Singleton;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/notification-seeder.php';

class Notification {

    use Singleton;

    /**
     * Boot SDK and register trigger definitions.
     *
     * @return void
     */
    public function init() {
        if ( ! class_exists( SDK::class ) ) {
            return;
        }

        SDK::get_instance()
            ->setup(
                array(
                    'plugin_name'          => 'Timetics',
                    'plugin_slug'          => 'timetics',
                    'general_prefix'       => 'tt',
                    'hook_prefix'          => 'timetics',
                    'text_domain'          => 'timetics',
                    'admin_script_handler' => 'timetics-dashboard-scripts',
                    'sub_menu_filter_hook' => 'timetics_menu',
                    'sub_menu_details'     => array(
                        'id'         => 'timetics-automation',
                        'title'      => __( 'Automation', 'timetics' ),
                        'link'       => '/automation',
                        'capability' => apply_filters( 'timetics_menu_permission_automation', 'manage_options' ),
                        'position'   => apply_filters( 'timetics_menu_position_automation', 11 ),
                    ),
                )
            )
            ->init();

        add_filter( 'rest_pre_dispatch', array( $this, 'check_notification_flow_permission' ), 10, 3 );
        add_filter( 'ens_tt_available_actions', array( $this, 'register_triggers' ) );
        add_filter( 'timetics_notification_sdk_email_body', array( $this, 'wrap_email_body' ), 10, 1 );
        add_filter( 'timetics_notification_sdk_to_emails', array( $this, 'expand_custom_email' ), 10, 2 );

        add_action( 'admin_init', array( 'Timetics\Core\Admin\Notification_Seeder', 'maybe_seed' ), 20 );
    }

    /**
     * Block non-admins from the SDK notification-flow REST endpoints.
     *
     * The SDK's FlowAPI registers these routes with permission_callback => true,
     * so we enforce the capability here from the consumer plugin.
     *
     * @param  mixed            $result  Short-circuit value (null to continue).
     * @param  \WP_REST_Server  $server
     * @param  \WP_REST_Request $request
     * @return mixed WP_Error on failure, original $result otherwise.
     */
    public function check_notification_flow_permission( $result, $server, $request ) {
        if ( strpos( $request->get_route(), '/timetics/v1/notification-flow' ) === 0 ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return new \WP_Error(
                    'rest_forbidden',
                    __( 'Sorry, you are not allowed to do that.', 'timetics' ),
                    array( 'status' => 403 )
                );
            }
        }

        return $result;
    }

    /**
     * Register the 3 booking triggers with their tag fields and receivers.
     *
     * @param  array $actions
     * @return array
     */
    public function register_triggers( $actions ) {
        $trigger_data = array(
            array(
                'label' => __( 'Meeting Title', 'timetics' ),
                'value' => 'meeting_title',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Meeting Date', 'timetics' ),
                'value' => 'meeting_date',
                'type'  => 'date',
            ),
            array(
                'label' => __( 'Meeting Time', 'timetics' ),
                'value' => 'meeting_time',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Meeting Location', 'timetics' ),
                'value' => 'meeting_location',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Google Meet Link', 'timetics' ),
                'value' => 'meeting_meet_link',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Meeting Duration', 'timetics' ),
                'value' => 'meeting_duration',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Host Name', 'timetics' ),
                'value' => 'host_name',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Host Email', 'timetics' ),
                'value' => 'host_email',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Customer Name', 'timetics' ),
                'value' => 'customer_name',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Customer Email', 'timetics' ),
                'value' => 'customer_email',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Login URL', 'timetics' ),
                'value' => 'login_url',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Login Username', 'timetics' ),
                'value' => 'login_username',
                'type'  => 'string',
            ),
            array(
                'label' => __( 'Set Password URL', 'timetics' ),
                'value' => 'set_password_url',
                'type'  => 'string',
            ),
        );

        $email_receivers = array(
            array(
                'label' => __( 'Customer Email', 'timetics' ),
                'value' => 'customer_email',
            ),
            array(
                'label' => __( 'Host Email', 'timetics' ),
                'value' => 'host_email',
            ),
            array(
                'label' => __( 'Custom Email', 'timetics' ),
                'value' => 'custom_email',
            ),
        );

        // Resolves against hook_data['meeting_date_timestamp'] (see get_hook_data),
        // a true UTC timestamp, so a delay node anchored to it schedules correctly.
        $delay_dependencies = array(
            array(
                'label' => __( 'Meeting Date', 'timetics' ),
                'value' => 'meeting_date',
            ),
        );

        $actions = array(
            array(
                'trigger_label'      => __( 'After Booking Confirmation', 'timetics' ),
                'trigger_value'      => 'booking_created',
                'trigger_data'       => $trigger_data,
                'delay_dependencies' => $delay_dependencies,
                'email_receivers'    => $email_receivers,
            ),
            array(
                'trigger_label'      => __( 'After Booking Cancellation', 'timetics' ),
                'trigger_value'      => 'booking_canceled',
                'trigger_data'       => $trigger_data,
                // Offering Meeting Date here as well, not just on booking_created.
                // An empty list left a delay node with nothing to anchor to, and
                // the SDK then falls back to current_time( 'timestamp' ) — which is
                // UTC plus the site's GMT offset, handed straight to
                // wp_schedule_single_event() where a true UTC timestamp is expected.
                // The email then fires GMT-offset hours away from the intended time.
                'delay_dependencies' => $delay_dependencies,
                'email_receivers'    => $email_receivers,
            ),
            array(
                'trigger_label'      => __( 'After Booking Rescheduled', 'timetics' ),
                'trigger_value'      => 'booking_rescheduled',
                'trigger_data'       => $trigger_data,
                'delay_dependencies' => $delay_dependencies,
                'email_receivers'    => $email_receivers,
            ),
        );

        return $actions;
    }

    /**
     * Expand a comma-separated custom_email setting into an array of addresses.
     *
     * Only expands when the email value matches hook_data['custom_email'],
     * so customer/host emails pass through untouched.
     *
     * Hooked to `timetics_notification_sdk_to_emails`.
     *
     * @param  string $email     Raw email value being sent.
     * @param  array  $hook_data Full hook data payload.
     * @return string|array      Single email or array of emails.
     */
    public function expand_custom_email( $email, $hook_data ) {
        if ( ! isset( $hook_data['custom_email'] ) || $email !== $hook_data['custom_email'] ) {
            return $email;
        }

        if ( strpos( $email, ',' ) === false ) {
            return sanitize_email( $email );
        }

        return array_values(
            array_filter(
                array_map( 'sanitize_email', array_map( 'trim', explode( ',', $email ) ) )
            )
        );
    }

    /**
     * Wrap the SDK email body in Timetics' branded HTML template.
     *
     * Hooked to `notification_sdk_email_body`.
     *
     * @param  string $message Raw email body HTML from the SDK.
     * @return string          Full HTML email.
     */
    public function wrap_email_body( $message ) {
        $template_path = TIMETICS_PLUGIN_DIR . '/templates/emails/email-notification-wrapper.html';

        if ( ! file_exists( $template_path ) ) {
            return $message;
        }

        $template      = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $primary_color = timetics_get_option( 'primary_color', '#3161F1' );
        $company_name  = timetics_get_option( 'business_name', get_bloginfo( 'name' ) );

        $show_powered_by = apply_filters( 'timetics_show_email_powered_by', true );
        $powered_by_html = '';
        if ( $show_powered_by ) {
            $powered_by_html = '<p style="margin:8px 0 0;font-size:11px;color:#b0bec9;font-style:italic;">'
                . esc_html__( 'Powered by Timetics', 'timetics' )
                . '</p>';
        }

        $variables = array(
            '{{MESSAGE}}'            => wp_kses_post( $message ),
            '{{COMPANY_NAME}}'       => esc_html( $company_name ),
            '{{PRIMARY_COLOR}}'      => esc_attr( $primary_color ),
            '{%powered_by_section%}' => $powered_by_html,
        );

        return str_replace( array_keys( $variables ), array_values( $variables ), $template );
    }

    /**
     * Build the hook data payload from a Booking object.
     * Called from any file that needs to fire a timetics_gln_hook action.
     *
     * @param  Booking $booking
     * @return array
     */
    public static function get_hook_data( Booking $booking ) {
        $meeting  = new Appointment( $booking->get_appointment() );
        $staff    = new Staff( $booking->get_staff_id() );
        $customer = new Customer( $booking->get_customer_id() );

        $formatted = timetics_format_email_datetime( $booking->get_start_date(), $booking->get_start_time() );

        $booking_timestamp = self::get_booking_timestamp( $booking );

        /* Pull the Google Meet link from the stored calendar event so it can be inserted as the {%meeting_meet_link%} tag in automation emails.
        */
        $calendar_event = $booking->get_event();
        $meet_link      = ( is_array( $calendar_event ) && ! empty( $calendar_event['hangoutLink'] ) ) ? $calendar_event['hangoutLink'] : '';

        $set_password_url = '';
        $customer_user    = get_user_by( 'email', $customer->get_email() );
        if ( $customer_user ) {
            $reset_key = get_password_reset_key( $customer_user );
            if ( ! is_wp_error( $reset_key ) ) {
                $set_password_url = network_site_url(
                    'wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode( $customer_user->user_login ),
                    'login'
                );
            }
        }

        return array(
            // The booking id lets delayed flows be re-validated or re-scheduled
            // when the booking changes after the flow started. `post_id` is the
            // key the email-notification-sdk itself looks for; `booking_id` is
            // the readable alias used inside Timetics.
            'post_id'                => $booking->get_id(),
            'booking_id'             => $booking->get_id(),
            // Not $booking->get_status(): the cancel-by-delete path fires this
            // trigger after the post row is gone, where get_post() returns null.
            'booking_status'         => (string) get_post_status( $booking->get_id() ),
            'customer_email'         => $customer->get_email(),
            'host_email'             => $staff->get_email(),
            'custom_email'           => apply_filters( 'timetics_custom_notification_email', timetics_get_option( 'custom_notification_email', get_option( 'admin_email' ) ) ),
            'meeting_title'          => $meeting->get_name(),
            'meeting_date'           => $formatted['date'],
            'meeting_date_timestamp' => $booking_timestamp,
            'meeting_time'           => $formatted['time'],
            'meeting_location'       => $booking->get_location(),
            'meeting_meet_link'      => $meet_link,
            'meeting_duration'       => $meeting->get_duration(),
            'host_name'              => $staff->get_display_name(),
            'customer_name'          => $customer->get_display_name(),
            'login_url'              => wp_login_url(),
            'login_username'         => $customer->get_email(),
            'set_password_url'       => $set_password_url,
        );
    }

    /**
     * Resolve a booking's start date/time to a real UTC timestamp.
     *
     * The stored date and time are wall-clock values in the booking's own
     * timezone, so they must be interpreted in that timezone — strtotime()
     * would read them as server time and shift every delay by the offset.
     *
     * @param  Booking $booking
     * @return int Unix timestamp, 0 when the booking has no start date.
     */
    public static function get_booking_timestamp( Booking $booking ) {
        $date = $booking->get_start_date();
        $time = $booking->get_start_time();

        if ( ! $date ) {
            return 0;
        }

        $timezone = $booking->get_timezone();

        if ( ! $timezone || ! timetics_is_valid_timezone( $timezone ) ) {
            $timezone = timetics_reminder_fallback_timezone();
        }

        try {
            $datetime = new \DateTime( $date . ' ' . $time, new \DateTimeZone( $timezone ) );
        } catch ( \Exception $e ) {
            return 0;
        }

        return $datetime->getTimestamp();
    }
}
