<?php
/**
 * Faker api
 *
 * @package Timetics
 */
namespace Timetics\Core\DummyData;

use Timetics\Base\Api;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Customers\Customer;
use Timetics\Core\DummyData\Factory;
use Timetics\Core\Staffs\Staff;
use Timetics\Utils\Singleton;

class Api_Faker extends Api {
    use Singleton;

    /**
     * Store api namespace
     *
     * @var string
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @var string
     */
    protected $rest_base = 'faker';

    /**
     * Register rest routes
     *
     * @return  void
     */
    public function register_routes() {
        /**
         * Register route
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, $this->rest_base, [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'generate_faker'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
                    },
                ],
            ]
        );
    }

    /**
     * Get all bookings
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON
     */
    public function generate_faker( $request ) {
        $this->generate_staff();
        $this->generate_customer();
        $this->genere_meeting();
        $this->generate_booking();

        $data = [
            'status'      => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully generated dummy data', 'timetics' ),
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Generate meetings
     *
     * @return  void
     */
    private function genere_meeting() {
        $items      = 1;
        $factory    = Factory::create();
        $visibility = ['enabled', 'disabled'];
        $staff      = $this->get_staff();

        for ( $i = 1; $i <= $items; $i++ ) {
            $meeting = new Appointment();

            $appointment_data = [
                'name'         => $factory->sentence,
                'description'  => $factory->paragraph( 7 ),
                'type'         => 'One-to-One',
                'locations'    => $this->get_locations( $factory->address ),
                'staff'        => [$staff],
                'duration'     => $this->get_duration(),
                'price'        => $this->get_price(),
                'capacity'     => "1",
                'visibility'   => $visibility[array_rand( $visibility )],
                'schedule'     => $this->get_schedule( $staff ),
                'timezone'     => 'Asia/Dhaka',
                'availability' => $this->get_availability(),
            ];

            $meeting->set_props( $appointment_data );
            $meeting->save();
        }
    }

    /**
     * Generate staff
     *
     * @return  void
     */
    private function generate_staff() {
        $items   = 1;
        $factory = Factory::create();

        for ( $i = 1; $i <= $items; $i++ ) {
            $staff = new Staff();
            $args  = [
                'first_name' => $factory->first_name,
                'last_name'  => $factory->last_name,
                'user_email' => $factory->email,
                'user_login' => $factory->username,
                'phone'      => $factory->phone_number,
                'schedule'   => timetics_get_option( 'availability' ),
                'user_pass'  => $factory->password,
            ];

            $staff->create( $args );
        }
    }

    /**
     * Generate customer
     *
     * @return void
     */
    private function generate_customer() {
        $items   = 1;
        $factory = Factory::create();

        for ( $i = 1; $i <= $items; $i++ ) {
            $customer = new Customer();
            $args     = [
                'first_name' => $factory->first_name,
                'last_name'  => $factory->last_name,
                'email'      => $factory->email,
                'user_name'  => $factory->username,
                'phone'      => $factory->phone_number,
                'password'   => $factory->password,
            ];

            $customer->set_props( $args );
            $customer->save();
        }
    }

    private function generate_booking() {
        $item    = 1;
        $factory = Factory::create();

        for ( $i = 1; $i <= $item; $i++ ) {
            $booking  = new Booking();
            $customer = new Customer( $this->get_customer() );
            $staff    = new Staff( $this->get_staff() );
            $meeting  = new Appointment( $this->get_meeting() );

            $args = [
                'customer'            => $customer->get_id(),
                'appointment'         => $meeting->get_id(),
                'staff'               => $staff->get_id(),
                'customer_fname'      => $customer->get_first_name(),
                'customer_lname'      => $customer->get_last_name(),
                'customer_email'      => $customer->get_email(),
                'customer_phone'      => $customer->get_phone(),
                'staff_fname'         => $staff->get_first_name(),
                'staff_lname'         => $staff->get_last_name(),
                'staff_email'         => $staff->get_email(),
                'meeting_name'        => $meeting->get_name(),
                'meeting_description' => $meeting->get_description(),
                'meeting_type'        => $meeting->get_type(),
                'description'         => $meeting->get_description(),
                'start_date'          => $factory->date,
                'date'                => $factory->date,
                'end_date'            => $factory->date,
                'start_time'          => $factory->time,
                'end_time'            => $factory->time,
                'order_total'         => $meeting->get_price(),
                'post_status'         => 'completed',
                'location'            => $factory->address,
                'location_type'       => 'in-person',
                'timezone'            => 'Asia/Dhaka',
            ];

            $booking->set_props( $args );

            $booking->save();
        }
    }

    /**
     * Get meeting
     *
     * @return  integer
     */
    private function get_meeting() {
        $meetings = $this->get_meeting_ids();

        return $meetings[array_rand( $meetings )];
    }

    /**
     * Get staff id
     *
     * @return  integer
     */
    private function get_staff() {
        $staff = $this->get_staff_ids();

        return $staff[array_rand( $staff )];
    }

    /**
     * Get customer id
     *
     * @return  integer
     */
    private function get_customer() {
        $customers = $this->get_customer_ids();

        return $customers[array_rand( $customers )];
    }

    /**
     * Get staff ids
     *
     * @return  array
     */
    private function get_staff_ids() {
        $staff = new Staff();

        $users = $staff->all();

        $ids = [];

        foreach ( $users['items'] as $user ) {
            $ids[] = $user->ID;
        }

        return $ids;
    }

    /**
     * Get all meeting ids
     *
     * @return  array
     */
    private function get_meeting_ids() {
        $ids      = [];
        $meeting  = new Appointment();
        $meetings = $meeting->all();

        foreach ( $meetings['items'] as $meeting_ob ) {
            $ids[] = $meeting_ob->ID;
        }

        return $ids;
    }

    /**
     * Get customer ids
     *
     * @return array
     */
    private function get_customer_ids() {
        $customer = new Customer();

        $users = $customer->all();

        $ids = [];

        foreach ( $users['items'] as $user ) {
            $ids[] = $user->ID;
        }

        return $ids;
    }

    /**
     * Generate meeting schedule
     *
     * @return  array
     */
    private function get_schedule( $staff ) {
        return [
            $staff => [
                'Sun' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Mon' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Tue' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Wed' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Thu' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Fri' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
                'Sat' => [
                    [
                        'start' => '9:00am',
                        'end'   => '5:00pm',
                    ],
                ],
            ],
        ];
    }

    /**
     * Generate locations
     *
     * @param   string  $address
     *
     * @return  string
     */
    private function get_locations( $address ) {
        return [
            [
                'location'      => $address,
                'location_type' => 'in-person-meeting',
            ],
        ];
    }

    /**
     * Generate availability
     *
     * @return  array
     */
    private function get_availability() {
        return [
            'start' => gmdate( 'Y-m-d' ),
            'end'   => gmdate( 'Y-m-d', strtotime( '+1 month', time() ) ),
        ];
    }

    /**
     * Get generate price
     *
     * @return  array
     */
    private function get_price() {
        return [
            [
                'ticket_name'     => 'default',
                'ticket_price'    => random_int( 30, 100 ),
                'ticket_quantity' => '1',
            ],
        ];
    }

    /**
     * Get meeting duration
     *
     * @return  string
     */
    private function get_duration() {
        $numbers = [15, 30, 60];
        $number  = $numbers[array_rand( $numbers )];
        $unit    = 'minute';

        $duration = "$number $unit";

        return $duration;
    }
}
