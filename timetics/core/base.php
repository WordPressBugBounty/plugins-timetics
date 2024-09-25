<?php
/**
 * Appoints base file
 *
 * @since 1.0.0
 *
 * @package Timetics
 */

namespace Timetics\Core;

use Hooks;
use Timetics\Core\Appointments\ApiAppointmentTaxonomy;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\DummyData\Api_Faker;
use Timetics\Core\Services\Service;
use Timetics\Core\Staff\Staff;
use Timetics\Core\Staffs\Staff as StaffsStaff;
use Timetics\Utils\Singleton;

/**
 * Base Class
 *
 * @since 1.0.0
 */
class Base {

    use Singleton;

    /**
     * Initialize all modules.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init() {
        // Initialize classes.
        Appointments\Api_Appointment::instance();
        Staffs\Api_Staff::instance();
        Services\Api_Service::instance();
        Bookings\Api_Booking::instance();
        Bookings\Hooks::instance()->init();
        Customers\Api_Customer::instance();
        Staffs\Hooks::instance()->init();
        Settings\Api_Settings::instance();
        Integrations\Auth::instance()->init();
        Reports\Api_Report::instance();
        Integrations\Stripe\Api_Stripe::instance();
        Frontend\Shortcode::instance()->init();
        EnqueueInline\Enqueue_Inline::instance()->init();
        Admin\Hooks::instance()->init();
        Appointments\Hooks::instance()->init();
        Api_Faker::instance();
        ApiAppointmentTaxonomy::instance();
    }
}


