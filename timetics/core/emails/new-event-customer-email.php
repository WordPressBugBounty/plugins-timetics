<?php
/**
 * New event email class
 *
 * @package Timetics
 */
namespace Timetics\Core\Emails;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer;
use Timetics\Core\Staffs\Staff;

class New_Event_Customer_Email extends Email {

    /**
     * Store booking object
     *
     * @var Object
     */
    public $booking;

    /**
     * Store staff object
     *
     * @var Object
     */
    public $staff;

    /**
     * Store customer object
     *
     * @var Object
     */
    public $customer;

    /**
     * Store meeting object
     *
     * @var Object
     */
    public $meeting;

    /**
     * New Event Email Constructor
     *
     * @return void
     */
    public function __construct( Booking $booking ) {
        $this->booking  = $booking;
        $this->meeting  = new Appointment( $booking->get_appointment() );
        $this->staff    = new Staff( $booking->get_staff_id() );
        $this->customer = new Customer( $booking->get_customer_id() );

        parent::__construct();
    }

    /**
     * Get email recipient
     *
     * @return string
     */
    public function get_recipient() {
        return $this->customer->get_email();
    }

    /**
     * Get new event email
     *
     * @return string
     */
    public function get_subject() {
        return $this->meeting->get_name();
    }

    /**
     * Get email title
     *
     * @return string
     */
    public function get_title() {
        return $this->meeting->get_name();
    }

    /**
     * Get template new event email
     *
     * @return  string
     */
    public function get_template() {
        return TIMETICS_PLUGIN_DIR . '/templates/emails/new-event-customer.php';
    }
}
