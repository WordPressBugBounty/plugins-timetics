<?php
namespace Ens\Flow;

use Ens\Config;
use Ens\Utils\Helpers;
use WP_HTTP_Response;
use WP_REST_Controller;

/**
 * Class FlowAPI
 *
 * @package Ens\Flow
 *
 * @since 1.0.0
 */
class FlowAPI extends WP_REST_Controller {

    /**
     * Namespace
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $namespace;

    /**
     * Rest base
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $rest_base = 'notification-flow';

    protected $identifier;

    /**
     * FlowAPI constructor.
     *
     * @since 1.0.0
     */
    public function init($identifier) {
        $this->identifier = $identifier;
        $plugin_slug     = Helpers::get_config_data( $this->identifier,'plugin_slug' );
        $this->namespace = $plugin_slug . '/v1';

        add_action( 'rest_api_init', [$this, 'register_routes'] );
    }

    /**
     * Register API endpoints routes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            $this->rest_base,
            [
                [ // Create
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'create_item'],
                    'permission_callback' => function () {
                        // return current_user_can( 'manage_options' );
                        return true;
                    },
                ],
                [ // Bulk delete
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'bulk_delete'],
                    'permission_callback' => function () {
                        // return current_user_can( 'manage_options' );
                        return true;
                    },
                ],
                [ // show list
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'get_items'],
                    'permission_callback' => function () {
                        // return current_user_can( 'manage_options' );
                        return true;
                    },
                ],
            ]
        );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<flow_id>[\d]+)', [
            [ // Get single flow
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => function () {
                    // return current_user_can( 'manage_options' );
                    return true;
                },
            ],
            [ // Update single flow
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update_item'],
                'permission_callback' => function () {
                    // return current_user_can( 'manage_options' );
                    return true;
                },
            ],
            [ // Delete single flow
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => function () {
                    // return current_user_can( 'manage_options' );
                    return true;
                },
            ],
        ] );

        register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<flow_id>[\d]+)' . '/clone', [
            [ // Clone flow
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'clone_item'],
                'permission_callback' => function () {
                    // return current_user_can( 'manage_options' );
                    return true;
                },
            ],
        ] );
    }

    /**
     * Create flow
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON  Created flow data
     */
    public function create_item( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        return $this->save_flow( $request );
    }

    /**
     * Update flow
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON  Updated flow data
     */
    public function update_item( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $flow_id = (int) $request['flow_id'];
        $flow    = new Flow( $this->identifier, $flow_id );

        if ( !$flow->is_flow() ) {

            $response = [
                'success'     => 0,
                'status_code' => 422,
                'message'     => __( 'Invalid flow id.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $response, 422 );
        }
        return $this->save_flow( $request, $flow_id );
    }

    /**
     * Get flow
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return JSON  Flow data
     */
    public function get_item( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $flow_id = (int) $request['flow_id'];
        $flow    = new Flow( $this->identifier, $flow_id );

        if ( !$flow->is_flow() ) {

            $data = [
                'success'     => 0,
                'status_code' => 422,
                'message'     => __( 'Invalid flow id.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 422 );
        }

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully retrieved flow', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            'data'        => $this->prepare_item( $flow_id ),
        ];

        return rest_ensure_response( $response, 200 );
    }

    /**
     * Get all flows
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_items( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $per_page   = !empty( $request['per_page'] ) ? intval( $request['per_page'] ) : -1;
        $paged      = !empty( $request['paged'] ) ? intval( $request['paged'] ) : -1;
        $trigger    = !empty( $request['trigger'] ) ? sanitize_text_field( $request['trigger'] ) : '';
        $status     = !empty( $request['status'] ) ? sanitize_text_field( $request['status'] ) : 'any';
        $search_key = !empty( $request['search_key'] ) ? sanitize_text_field( $request['search_key'] ) : '';

        $flow = new Flow($this->identifier);
        $flows = $flow->all( [
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'trigger'        => $trigger,
            'post_status'    => $status,
            'search_key'     => $search_key,
        ] );

        $items = [];

        foreach ( $flows['items'] as $item ) {
            $single_flow = $this->prepare_item( $item->ID );
            $items[]     = $single_flow;
        }

        $flow = new Flow( $this->identifier );
        $status_count = $flow->total_flows_group_by_status();

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Showing filtered flow list.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            'data'        => [
                'total'        => $flows['total'],
                'status_count' => $status_count,
                'items'        => $items,
            ],
        ];

        return rest_ensure_response( $data, 200 );
    }

    /**
     * Delete flow
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function delete_item( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $flow_id = (int) $request['flow_id'];
        $flow    = new Flow( $this->identifier, $flow_id );

        if ( !$flow->is_flow() ) {

            $data = [
                'success'     => 0,
                'status_code' => 422,
                'message'     => __( 'Invalid flow id.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
                'data'        => [],
            ];

            return new WP_HTTP_Response( $data, 422 );
        }

        $flow->delete();

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully deleted flow', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            'data'        => [],
        ];

        return rest_ensure_response( $response, 200 );
    }

    /**
     * Bulk delete flows
     *
     * @since 1.0.0
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function bulk_delete( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $flow_ids = json_decode( $request->get_body(), true );

        foreach ( $flow_ids as $flow_id ) {
            $flow = new Flow( $this->identifier, $flow_id );

            if ( !$flow->is_flow() ) {
                $data = [
                    'success'     => 0,
                    'status_code' => 422,
                    'message'     => __( 'Invalid flow id.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
                    'data'        => [],
                ];

                return new WP_HTTP_Response( $data, 422 );
            }

            $flow->delete();
        }

        return rest_ensure_response( [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully deleted all flows', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            'data'        => [
                'items' => $flow_ids,
            ],
        ], 200 );
    }

    /**
     * Clone flow
     *
     * @since 1.0.0
     *
     * @param  Object  $request
     *
     * @return JSON
     */
    public function clone_item( $request ) {
        $response = Helpers::ens_verify_nonce( $request->get_header( 'x_wp_nonce' ), $this->identifier );
        if ( $response instanceof WP_HTTP_Response ) {
            return $response;
        }

        $flow_id = (int) $request['flow_id'];
        $flow    = new Flow( $this->identifier, $flow_id );

        if ( !$flow->is_flow() ) {
            return new WP_HTTP_Response(
                [
                    'success'     => 0,
                    'status_code' => 422,
                    'message'     => __( 'Invalid flow id.', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
                    'data'        => [],
                ],
                422
            );
        }

        $flow->clone();

        $item = $this->prepare_item( $flow );

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully cloned requested flow', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            'data'        => $item,
        ];

        return rest_ensure_response( $response, 200 );
    }

    /**
     * Save flow
     *
     * @since 1.0.0
     *
     * @param WP_Rest_Request $request
     * @param int             $id
     *
     * @return WP_REST_Response
     */
    public function save_flow( $request, $id = 0 ) {
        $flow         = new Flow( $this->identifier, $id );
        $request_body = $request->get_body();
        $data         = !is_null( $request_body ) ? json_decode( $request_body, true ) : [];

        // Check if the input data is empty
        if ( empty( $data ) ) {
            return rest_ensure_response( [
                'status_code' => 400,
                'success'     => 0,
                'message'     => __( 'No data provided to save the flow', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            ], 400 );
        }

        $name    = !empty( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $flow->get_name();
        $trigger = !empty( $data['trigger'] ) ? sanitize_text_field( $data['trigger'] ) : $flow->get_trigger();

        $flow_config = !empty( $data['flow_config'] ) ? Helpers::ens_sanitize_recursive( $data['flow_config'] ) : $flow->get_flow_config();
        $status      = ( isset( $data['status'] ) && !empty( $data['status'] ) ) ? sanitize_text_field( strtolower( $data['status'] ) ) : ( $flow->get_status() ? $flow->get_status() : 'draft' );

        $action = $id ? 'updated' : 'created';

        // Check if the input data is empty
        if ( empty( $name ) || empty( $trigger ) ) {
            return rest_ensure_response( [
                'status_code' => 400,
                'success'     => 0,
                'message'     => __( 'Flow name & trigger is required', Helpers::get_config_data( $this->identifier,'text_domain' ) ),
            ], 400 );
        }

        // preparing flow data.
        $flow_data = [
            'name'        => $name,
            'trigger'     => $trigger,
            'flow_config' => $flow_config,
            'status'      => $status,
        ];

        $flow->set_props( $flow_data );
        $flow_id = $flow->save();

        // Prepare response data.
        $item = $this->prepare_item( $flow_id );

        $response = [
            'status_code' => 200,
            'success'     => 1,
            // translators: %s is the name of the action
            'message'     => esc_html( sprintf( __( 'Successfully %s notification flow', Helpers::get_config_data( $this->identifier,'text_domain' ) ), $action ) ),
            'data'        => $item,
        ];

        return rest_ensure_response( $response, 200 );
    }

    /**
     * Prepare item for response
     *
     * @since 1.0.0
     *
     * @param   integer  $flow_id
     *
     * @return  array
     */
    public function prepare_item( $flow_id ) {
        $flow = new Flow( $this->identifier, $flow_id );
        $data = [
            'id'          => $flow->get_id(),
            'name'        => $flow->get_name(),
            'trigger'     => $flow->get_trigger(),
            'flow_config' => empty( $flow->get_flow_config() ) ? [] : $flow->get_flow_config(),
            'status'      => $flow->get_status(),
        ];

        return apply_filters( Helpers::get_hook_name( $this->identifier, 'ens_flow_prepare_item' ), $data, $flow_id );
    }
}