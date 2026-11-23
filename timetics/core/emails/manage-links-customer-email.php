<?php
namespace Timetics\Core\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Booking-links email: a customer's upcoming bookings with reschedule and
 * cancel buttons, sent when they ask for it in the AI chat.
 */
class Manage_Links_Customer_Email extends Email {

    /**
     * Customer the list belongs to.
     *
     * @var object user_email and display_name.
     */
    public $customer;

    /**
     * Escaped booking rows, from Manage_Links_Sender::rows_html().
     *
     * @var string
     */
    public $rows_html;

    /**
     * Constructor
     *
     * @param string $email     Inbox the links go to.
     * @param string $name      Name the bookings were made with.
     * @param string $rows_html Escaped booking rows.
     */
    public function __construct( $email, $name, $rows_html ) {
        // Shaped like the WP_User the template reads: a guest has no account.
        $this->customer  = (object) array(
            'user_email'   => $email,
            'display_name' => $name,
        );
        $this->rows_html = $rows_html;

        parent::__construct();
    }

    /**
     * Get email recipient
     *
     * @return string
     */
    public function get_recipient() {
        return $this->customer->user_email;
    }

    /**
     * Get email subject
     *
     * @return string
     */
    public function get_subject() {
        return __( 'Your upcoming bookings', 'timetics' );
    }

    /**
     * Get email template
     *
     * @return string
     */
    public function get_template() {
        return TIMETICS_PLUGIN_DIR . '/templates/emails/manage-links.php';
    }
}
