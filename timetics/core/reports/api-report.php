<?php
/**
 * Api reports class
 *
 * @package Timetics
 */

namespace Timetics\Core\Reports;

use Timetics\Base\Api;
use Timetics\Utils\Singleton;

/**
 * Class Api Reports
 */
class Api_Report extends Api {
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
    protected $rest_base = 'reports';

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
            $this->namespace, $this->rest_base . '/overview', [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'get_overview'],
                    'permission_callback' => function () {
                        return current_user_can( 'manage_timetics' );
                    },
                ],
            ]
        );
    }

    /**
     * Get overview
     *
     * @param   WP_Rest_Request
     *
     * @return  JSON
     */
    public function get_overview( $request ) {
        $input_data = json_decode( $request->get_body(), true );

        /**
         * Added temporary for leagacy sass. It will remove in future.
         */
        $data = apply_filters('timetics/overview/report/get_overview', $this->generate_reports( $input_data ) );

        $response = [
            'status_code' => 200,
            'success'     => 1,
            'data'        => $data,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Generate reports based date
     *
     * @param   array  $input_data
     *
     * @return  array
     */
    public function generate_reports( $input_data = [] ) {
        $reports = [];
        $default_booking_status = timetics_get_option( 'default_booking_status', 'approved' );
        if ( $input_data ) {
            foreach ( $input_data as $data ) {
                if ( empty( $data['report'] ) || empty( $data['start_date'] ) || empty( $data['end_date'] ) ) {
                    continue;
                }

                $date_range = [
                    'start' => $data['start_date'],
                    'end'   => $data['end_date'],
                ];

                switch ( $data['report'] ) {
                case 'total_booking':
                    $total_booking = timetics_count_posts( [
                        'post_type'   => 'timetics-booking',
                        'post_status' => $default_booking_status,
                        'date_range'  => $date_range,
                    ] );

                    $reports['total_booking'] = $total_booking;
                    break;

                case 'total_earning':
                    $total_earning = number_format( timetics_get_total_sale( [
                        'post_type'   => 'timetics-booking',
                        'post_status' => $default_booking_status,
                        'date_range'  => $date_range,
                    ] ), 2 );

                    $reports['total_earning'] = $total_earning;
                    break;

                case 'total_customer':
                    $total_customer = timetics_count_users( [
                        'role'       => 'timetics-customer',
                        'date_range' => $date_range,
                    ] );

                    $reports['total_customer'] = $total_customer;
                    break;

                case 'booking_reports':
                    $reports['booking_reports'] = $this->generate_date_range_reports( $date_range );
                    break;
                }
            }
        }

        return $reports;
    }

    /**
     * Generate report for a date range and get every date reports
     *
     * @return array
     */
    public function generate_date_range_reports( $date ) {
        $start_date = $date['start'];
        $end_date   = $date['end'];

        $current_date  = strtotime( $start_date );
        $end_timestamp = strtotime( $end_date );
        $default_booking_status = timetics_get_option( 'default_booking_status', 'approved' );
        $reports = [];

        while ( $current_date <= $end_timestamp ) {
            $current_report_date = date( 'Y-m-d', $current_date );

            $date_range = [
                'start' => $current_report_date,
                'end'   => $current_report_date,
            ];

            $booking_report = [
                'cancel'    => timetics_count_posts( [
                    'post_type'   => 'timetics-booking',
                    'post_status' => 'cancel',
                    'date_range'  => $date_range,
                ] ),
                'completed' => timetics_count_posts( [
                    'post_type'   => 'timetics-booking',
                    'post_status' => $default_booking_status,
                    'date_range'  => $date_range,
                ] ),
            ];

            $reports[$current_report_date] = $booking_report;

            $current_date = strtotime( '+1 day', $current_date );
        }

        return $reports;
    }
}
