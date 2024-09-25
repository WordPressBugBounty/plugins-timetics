<?php
/**
 * Service Api
 *
 * @package Timetics
 */
namespace Timetics\Core\Services;

use Timetics\Base\Api;
use Timetics\Utils\Singleton;
use WP_REST_Request;

class Api_Service extends Api {
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
    protected $rest_base = 'services';

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
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
        );

        /**
         * Register route
         *
         * @var void
         */
        register_rest_route(
            $this->namespace, '/' . $this->rest_base . '/(?P<service_id>[\d]+)', [
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				],
			]
        );
    }

    /**
     * Get all services
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_items( $request ) {
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;

        $services = Service::all(
            [
				'per_page' => $per_page,
				'paged'    => $paged,
			]
        );

        $items = [];

        foreach ( $services as $item ) {
            $items[] = $this->prepare_item( $item->term_id );
        }

        $data = [
            'success' => 1,
            'data'    => [
                'total' => $this->count(),
                'items' => $items,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Create service
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON Newly created service data
     */
    public function create_item( $request ) {
        return $this->save_service( $request );
    }

    /**
     * Update service
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON  Updated service data
     */
    public function update_item( $request ) {
        $service_id = (int) $request['service_id'];
        $service       = new Service( $service_id );

        if ( ! $service->is_service() ) {
            return [
                'message' => esc_html__( 'Invalid service id.', 'timetics' ),
                'data'    => [
                    'status' => 404,
                ],
            ];
        }

        return $this->save_service( $request, $service_id );
    }

    /**
     * Get single service
     *
     * @param   WP_Rest_Requesr  $request
     *
     * @return  JSON Single service data
     */
    public function get_item( $request ) {
        $service_id = (int) $request['service_id'];
        $service       = new Service( $service_id );

        if ( ! $service->is_service() ) {
            return [
                'message'     => esc_html__( 'Invalid service id.', 'timetics' ),
                'data'        => [
                    'status'  => 404,
                ],
            ];
        }

        $response = [
            'status_code' => 200,
            'data'        => $this->prepare_item( $service ),
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Delete single service
     *
     * @param   WP_Rest_Request  $request
     *
     * @return
     */
    public function delete_item( $request ) {
        $service_id = (int) $request['service_id'];
        $service       = new Service( $service_id );

        if ( ! $service->is_service() ) {
            return [
                'message'     => esc_html__( 'Invalid service id.', 'timetics' ),
                'data'        => [
                    'status' => 404,
                ],
            ];
        }

        $service->delete();

        $response = [
            'message'     => esc_html__( 'Successfully deleted service', 'timetics' ),
            'data'        => [
                'status'  => 200,
            ],
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Save service
     *
     * @param   WP_Rest_Request  $request
     * @param   integer  $id Service Id
     *
     * @return  JSON  Updated service data
     */
    public function save_service( $request, $id = 0 ) {
        $data = json_decode( $request->get_body(), true );

        $name     = ! empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
        $parent   = ! empty( $data['parent'] ) ? sanitize_text_field( $data['parent'] ) : '';
        $staff    = ! empty( $data['staff'] ) ? sanitize_text_field( $data['staff'] ) : '';
        $location = ! empty( $data['location'] ) ? sanitize_text_field( $data['location'] ) : '';
        $duration = ! empty( $data['duration'] ) ? sanitize_text_field( $data['duration'] ) : '';
        $price    = ! empty( $data['price'] ) ? sanitize_text_field( $data['price'] ) : '';
        $seat     = ! empty( $data['seat'] ) ? intval( $data['seat'] ) : 0;
        $color    = ! empty( $data['color'] ) ? sanitize_text_field( $data['color'] ) : '';

        // Validate input data.
        $validate = $this->validate(
            $data, [
				'name',
			]
        );

        if ( is_wp_error( $validate ) ) {
            return [
                'success' => 0,
                'message' => $validate->get_error_messages(),
                'data'    => [],
            ];
        }

        // Save service.
        $service = new Service( $id );

        $service->set_props(
            [
				'name'     => $name,
				'parent'   => $parent,
				'location' => $location,
				'duration' => $duration,
				'price'    => $price,
				'seat'     => $seat,
				'color'    => $color,
			]
        );

        $service->save();

        if ( is_wp_error( $service->error ) ) {
            return [
                'message' => $service->error->get_error_message(),
                'data'    => [
                    'status' => 409,
                ],
            ];
        }

        // Prepare response data.
        $item = $this->prepare_item( $service );

        $response = [
            'success' => 1,
            'message' => esc_html__( 'Succssfully created service', 'timetics' ),
            'data'    => $item,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Prepare item for response
     *
     * @param   integer  $service_id
     *
     * @return  array
     */
    public function prepare_item( $service_id ) {
        $service = new Service( $service_id );

        return [
            'id'       => $service->get_id(),
            'name'     => $service->get_name(),
            'parent'   => $service->get_parent(),
            'duration' => $service->get_duration(),
            'location' => $service->get_location(),
            'staff'    => $service->get_staff(),
            'seat'     => $service->get_seat(),
            'price'    => $service->get_price(),
            'color'    => $service->get_color(),
        ];
    }

    /**
     * Count total term
     *
     * @return  integer
     */
    public function count() {
        $total_term = wp_count_terms(
            [
				'taxonomy' => 'timetics-service',
			]
        );

        if ( is_wp_error( $total_term ) ) {
            return 0;
        }

        return $total_term;
    }
}
