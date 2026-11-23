<?php
/**
 * Api reports class
 *
 * @package Timetics
 */

namespace Timetics\Core\Reports;

defined( 'ABSPATH' ) || exit;

use Timetics\Base\Api;
use Timetics\Core\Customers\Customer_Backfill;
use Timetics\Core\Customers\Customer_Store;
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

        // Statuses that count as a real, confirmed booking for reporting.
        // Deliberately NOT tied to the "default_booking_status" setting —
        // that option controls what status a brand-new booking starts in,
        // not which bookings count as revenue. Reusing it here made a
        // booking silently drop out of totals the moment an admin approved
        // it on a site where new bookings default to "pending".
        $counted_statuses = apply_filters( 'timetics/report/counted_statuses', [ 'approved', 'completed' ] );

        // Staff only see their own numbers, administrators see the whole site.
        $booking_scope  = $this->get_booking_scope_args();
        $customer_ids   = $this->get_customer_ids();

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
                    $total_booking = timetics_count_posts( array_merge( [
                        'post_type'   => 'timetics-booking',
                        'post_status' => $counted_statuses,
                        'date_range'  => $date_range,
                    ], $booking_scope ) );

                    $reports['total_booking'] = $total_booking;
                    break;

                case 'total_earning':
                    $total_earning = number_format( timetics_get_total_sale( array_merge( [
                        'post_type'   => 'timetics-booking',
                        'post_status' => $counted_statuses,
                        'date_range'  => $date_range,
                    ], $booking_scope ) ), 2 );

                    $reports['total_earning'] = $total_earning;
                    break;

                case 'total_customer':
                        $reports['total_customer'] = $this->count_customers( $data, $customer_ids );
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
        $counted_statuses = apply_filters( 'timetics/report/counted_statuses', [ 'approved', 'completed' ] );
        $reports = [];

        // Staff only see their own numbers, administrators see the whole site.
        $booking_scope = $this->get_booking_scope_args();

        while ( $current_date <= $end_timestamp ) {
            $current_report_date = gmdate( 'Y-m-d', $current_date );

            $date_range = [
                'start' => $current_report_date,
                'end'   => $current_report_date,
            ];

            $booking_report = [
                'cancel'    => timetics_count_posts( array_merge( [
                    'post_type'   => 'timetics-booking',
                    'post_status' => 'cancel',
                    'date_range'  => $date_range,
                ], $booking_scope ) ),
                'completed' => timetics_count_posts( array_merge( [
                    'post_type'   => 'timetics-booking',
                    'post_status' => $counted_statuses,
                    'date_range'  => $date_range,
                ], $booking_scope ) ),
            ];

            $reports[$current_report_date] = $booking_report;

            $current_date = strtotime( '+1 day', $current_date );
        }

        return $reports;
    }

    /**
     * Get the booking query args that limit a report to what the current user may see
     *
     * @return  array Empty for administrators, who see every booking.
     */
    protected function get_booking_scope_args() {
        if ( timetics_can_view_all_data() ) {
            return [];
        }

        return ['post__in' => timetics_get_visible_booking_ids( get_current_user_id() )];
    }

    /**
     * Contacts added in the report's range. Copied older customers keep their sign-up date.
     *
     * @param array      $data         Report request: start_date, end_date.
     * @param array|null $customer_ids Rows the user may count, null for all.
     *
     * @return int
     */
    protected function count_customers( $data, $customer_ids ) {
        Customer_Backfill::run_batch();

        return Customer_Store::count(
            array(
                'from' => $data['start_date'],
                'to'   => $data['end_date'],
                'ids'  => $customer_ids,
            )
        );
    }

    /**
     * Contact rows the current user may count
     *
     * @return  array|null Null for administrators, who count every contact.
     */
    protected function get_customer_ids() {
        if ( timetics_can_view_all_data() ) {
            return null;
        }

        return Customer_Store::ids_for_bookings( timetics_get_visible_booking_ids( get_current_user_id() ) );
    }
}
