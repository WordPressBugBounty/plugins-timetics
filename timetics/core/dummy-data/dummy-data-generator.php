<?php
namespace Timetics\Core\DummyData;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Staffs\Staff;
use Timetics\Core\Customers\Customer;
use TimeticsPro\Core\SeatPlan\SeatPlan;

/**
 * Dummy Data Generator
 *
 * @package Timetics
 */
class Dummy_Data_Generator {

    /**
     * @var \Faker\Generator
     */
    private $faker;

    /**
     * Constructor
     *
     * @param   object  $faker  Faker instance.
     */
    public function __construct($faker = null) {
        if ( is_null( $faker ) ) {
            $this->faker = Factory::create();
        } else {
            $this->faker = $faker;
        }
    }

    /**
     * Generate dummy data
     *
     * @param   int  $total  Total number of items to generate.
     *
     * @return  void
     */
    public function generate() {
        $this->generate_appointments();
    }

    /**
     * Generate dummy appointments
     *
     * @param   int  $total  Total number of appointments to generate.
     *
     * @return  void
     */
    public function generate_appointments( $type = 'One-to-One', $total = 1, $capacity = 5 ) {
        $items      = $total;
        $factory    = $this->faker;
        $factory    = Factory::create();
        $visibility = ['enabled', 'disabled'];
        $staff      = $this->get_staff();

        for ( $i = 1; $i <= $items; $i++ ) {
            $meeting = new Appointment();

            $appointment_data = [
                'name'         => $this->get_meeting_title(),
                'description'  => $factory->paragraph( 7 ),
                'type'         => $type,
                'locations'    => $this->get_locations( $factory->address ),
                'staff'        => [$staff],
                'duration'     => $this->get_duration(),
                'price'        => $this->get_price(),
                'capacity'     => $capacity,
                'visibility'   => 'enabled',
                'schedule'     => $this->get_schedule( $staff ),
                'timezone'     => 'Asia/Dhaka',
                'availability' => $this->get_availability(),
            ];

            $meeting->set_props( $appointment_data );
            $meeting->save();

            if ( 'seat' === $type ) {
                $this->generate_seat_plan( $meeting->get_id() );
            }
        }
    }

    /**
     * Generate dummy staff data
     *
     * @param   int  $total  Total number of staff to generate.
     *
     * @return  void
     */
    private function generte_staff( $total = 2 ) {
        $items   = $total;
        $factory = $this->faker;

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
     * Get meeting
     *
     * @return  integer
     */
    private function get_meeting() {
        $meetings = $this->get_meeting_ids();

        return $meetings[array_rand( $meetings )];
    }

    /**
     * Get meeting title
     *
     * @return  string
     */
    private function get_meeting_title() {
        $meeting_titles = [
            "Project Kickoff Meeting",
            "Weekly Standup",
            "Client Demo Presentation",
            "Marketing Strategy Session",
            "Design Review Meeting",
            "Sprint Planning",
            "Product Roadmap Discussion",
            "Team Retrospective",
            "Sales Performance Review",
            "Budget Planning Meeting",
            "Technical Architecture Review",
            "Hiring Panel Interview",
            "Customer Feedback Review",
            "Quarterly Business Review",
            "Onboarding Session",
            "Security & Compliance Briefing",
            "Feature Launch Meeting",
            "Investor Update Call",
            "Support Team Sync",
            "Content Planning Workshop"
        ];

        return $meeting_titles[ array_rand( $meeting_titles ) ];
    }

    /**
     * Get staff id
     *
     * @return  integer
     */
    private function get_staff() {
        $users = get_users( ['role' => 'administrator'] );

        if ( ! empty( $users ) ) {
            return $users[0]->ID;
        }

        return 1;
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
                'ticket_price'    => 0,
                'ticket_quantity' => '20',
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
        $unit    = 'min';

        $duration = "$number $unit";

        return $duration;
    }

    /**
     * Generate seat plan
     *
     * @param   int  $meeting_id  Meeting ID.
     *
     * @return  void
     */
    private function generate_seat_plan( $meeting_id ) {
        if ( ! class_exists( 'TimeticsPro' ) ) {
            return;
        }

        $meeting = new Appointment( $meeting_id );
        $seat_capacity = 20;
        $seat_ids      = [];
        $positions     = $this->get_positions();

        for( $i = 0; $i < $seat_capacity; $i++) {
            $position = $positions[$i];

            $data = [
                'angle'       => "0",
                'cursor'      => "pointer",
                'fill'        => "black",
                'fontSize'    => null,
                'height'      => 20,
                'label'       => "",
                'number'      => "1",
                'positionX'   => $position[0],
                'positionY'   => $position[1],
                'price'       => "71",
                'radius'      => "10",
                'scaleX'      => 1,
                'scaleY'      => 1,
                'shapeType'   => "chair",
                'stroke'      => "black",
                'strokeWidth' => 1,
                'text'        => null,
                'ticketType'  => "default",
                'type'        => "path",
                'width'       => 20,
                'zoomX'       => 1.6342283377796114,
                'zoomY'       => 1.6342283377796114,
                'meeting'     => $meeting_id,
            ];

            $seat_ids[] = SeatPlan::insert( $data );
        }

        $config = [
            'canvasDimensions' => [
                'width' => 1000,
                'height' => 1000,
            ],
            'selectColor' => '#000000',
            'unavailableColor' => '#dd4d4d',
            'canvasBgImage' => '',
        ];

        $meeting->update(
            [
                'seat_plan'          => $seat_ids,
                'seat_plan_settings' => $config,
            ]
        );
    }

    /**
     * Get seat positions
     *
     * @return  array
     */
    private function get_positions() {
        $positions = [
            ["15", "200"],  ["50", "200"],  ["85", "200"],  ["120", "200"],  ["155", "200"],
            ["15", "235"],  ["50", "235"],  ["85", "235"],  ["120", "235"],  ["155", "235"],
            ["15", "270"],  ["50", "270"],  ["85", "270"],  ["120", "270"],  ["155", "270"],
            ["15", "305"],  ["50", "305"],  ["85", "305"],  ["120", "305"],  ["155", "305"]
        ];

        return $positions;
    }
}
