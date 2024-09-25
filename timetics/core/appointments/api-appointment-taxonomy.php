<?php
/**
 * Appointments taxonomy api
 *
 * @package Timetics
 */
namespace Timetics\Core\Appointments;

use Timetics\Base\Api;
use Timetics\Utils\Singleton;
use WP_Error;

class ApiAppointmentTaxonomy extends Api {
    use Singleton;

    /**
     * Store api namespace
     *
     * @since 1.10.0
     *
     * @var string $namespace
     */
    protected $namespace = 'timetics/v1';

    /**
     * Store rest base
     *
     * @since 1.10.0
     *
     * @var string $rest_base
     */
    protected $rest_base = 'appointments';

    /**
     * Store taxonomy name
     *
     * @since 1.10.0
     *
     * @var string
     */
    protected $taxonomy = 'timetics-meeting-category';

    /**
     * Register rest routes.
     *
     * @since 1.10.0
     *
     * @return  void
     */
    public function register_routes() {
        /*
         * Register route
         */
        register_rest_route( $this->namespace, $this->rest_base . '/categories', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => function () {
                    return true;
                },
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => function () {
                    return current_user_can( 'manage_timetics' );
                },
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'bulk_delete'],
                'permission_callback' => function () {
                    return current_user_can( 'manage_timetics' );
                },
            ],
        ] );

        /*
         * Register route
         */
        register_rest_route( $this->namespace, $this->rest_base . '/categories' . '/(?P<category_id>[\d]+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => function () {
                    return true;
                },
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => function () {
                    return current_user_can( 'manage_timetics' );
                },
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => function () {
                    return current_user_can( 'manage_timetics' );
                },
            ],
        ] );
    }

    /**
     * Get categories
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_items( $request ) {
        $per_page = ! empty( $request['per_page'] ) ? intval( $request['per_page'] ) : 20;
        $paged    = ! empty( $request['paged'] ) ? intval( $request['paged'] ) : 1;
        $offset   = ( $paged - 1 ) * $per_page;
        $taxonomy = $this->taxonomy;

        $total_terms = wp_count_terms( $taxonomy );

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'offset'     => $offset,
            'number'     => $per_page,
            'hide_empty' => false,
        ] );

        $terms = array_map( function ( $term ) {
            $posts_ids         = $this->get_post_ids( $term->term_id );
            $term->meeting_ids = $posts_ids;
            $term->permalink   = get_term_link( $term );
            return $term;
        }, $terms );

        $data = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Category list', 'timetics' ),
            'data'        => [
                'total'    => $total_terms,
                'category' => $terms,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Get category
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function get_item( $request ) {
        $id            = intval( $request['category_id'] );
        $category = get_term( $id );

        $posts = $this->get_post_ids( $category->term_id );
        $post_data = [];
        if(!empty($posts)){
           foreach($posts as $post){
            $post_data[] = $this->prepare_get_item($post);
           }
        }
        $category->meetings = $post_data;
        $data = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Category list', 'timetics' ),
            'data'        => [
                'category' => $category,
            ],
        ];

        return rest_ensure_response( $data );
    }

    /**
     * Create category
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  void
     */
    public function create_item( $request ) {
        $data = json_decode( $request->get_body(), true );

        $category_name = ! empty( $data['category_name'] ) ? sanitize_text_field( $data['category_name'] ) : '';
        $parent        = ! empty( $data['parent'] ) ? intval( $data['parent'] ) : 0;
        $meeting_ids   = ! empty( $data['meeting_ids'] ) ? array_map( 'intval', $data['meeting_ids'] ) : [];

        $category = wp_insert_term( $category_name, $this->taxonomy, [
            'parent' => $parent,
        ] );

        if ( is_wp_error( $category ) ) {
            return new WP_Error( $category->get_error_code(), $category->get_error_message() );
        }

        $this->assign_meetings( $category['term_id'], $meeting_ids );

        $category = get_term( $category['term_id'] );

        $posts = $this->get_post_ids( $category->term_id );
        $category->meeting_ids = $posts;
        $category->permalink   = get_term_link($category->term_id );

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Category successfully created', 'timetics' ),
            'data'        => $category,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Update category
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function update( $request ) {
        $id            = intval( $request['category_id'] );
        $data          = json_decode( $request->get_body(), true );
        $category_name = ! empty( $data['category_name'] ) ? sanitize_text_field( $data['category_name'] ) : '';
        $parent        = ! empty( $data['parent'] ) ? intval( $data['parent'] ) : 0;
        $meeting_ids   = ! empty( $data['meeting_ids'] ) ? array_map( 'intval', $data['meeting_ids'] ) : [];

        $args = [
            'name'   => $category_name,
            'parent' => $parent,
        ];

        $category = wp_update_term( $id, $this->taxonomy, $args );

        if ( is_wp_error( $category ) ) {
            return new WP_Error( $category->get_error_code(), $category->get_error_message() );
        }

        $this->assign_meetings( $category['term_id'], $meeting_ids );

        $category = get_term( $category['term_id'] );

        $posts = $this->get_post_ids( $category->term_id );
        $category->meeting_ids = $posts;
        $category->permalink   = get_term_link( $category->term_id );

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Category successfully updated', 'timetics' ),
            'data'        => $category,
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Delete category
     *
     * @param   WP_Rest  $request
     *
     * @return  JSON
     */
    public function delete( $request ) {
        $id = intval( $request['category_id'] );

        $category = wp_delete_term( $id, $this->taxonomy );

        if ( ! $category ) {
            return new WP_Error( 'category_not_exist', __( 'Category doesn\'t exist', 'timetics' ) );
        }

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => __( 'Successfully deleted, appointment category', 'timetics' ),
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Delete multiple category
     *
     * @param   WP_Rest_Request  $request
     *
     * @return  JSON
     */
    public function bulk_delete( $request ) {
        $data = json_decode( $request->get_body(), true );

        $ids     = ! empty( $data['ids'] ) ? array_map( 'intval', $data['ids'] ) : [];
        $counter = 0;

        if ( ! $ids ) {
            return new WP_Error( 'cagetory_ids_empty', __( 'Category id required', 'timetics' ) );
        }

        foreach ( $ids as $id ) {
            $delete = wp_delete_term( $id, $this->taxonomy );

            if ( $delete ) {
                $counter++;
            }
        }

        if ( $counter < 1 ) {
            return new WP_Error( 'cagetory_not_exist', __( 'No category exists.', 'timetics' ) );
        }

        $response = [
            'success'     => 1,
            'status_code' => 200,
            'message'     => sprintf( __( 'Successfully deleted %d out of %d categories', 'timetics' ), $counter, count( $ids ) ),
        ];

        return rest_ensure_response( $response );
    }

    /**
     * Assign post to the selected category
     *
     * @param   integer  $category_id  Category id that need to be assign
     * @param   array  $meeting_ids  Meeting ids that will be assign
     *
     * @return void
     */
    private function assign_meetings( $category_id, $meeting_ids ) {
        // first remove objects from existing category.
        $posts = $this->get_post_ids( $category_id );

        foreach ( $posts as $meeting_id ) {
            wp_remove_object_terms( $meeting_id, $category_id, $this->taxonomy );
        }


        foreach ( $meeting_ids as $meeting_id ) {
            $terms = get_the_terms( $meeting_id, $this->taxonomy );
            $categories = [$category_id];

            if ( $terms && ! is_wp_error( $terms ) ) {
                $categories = array_merge( $categories, array_column( $terms, 'term_id' ) );
            }

            wp_set_post_terms( $meeting_id, $categories, $this->taxonomy );
        }
    }

    /**
     * Get posts ids
     *
     * @return  array
     */
    private function get_post_ids( $term_id ) {
        $args = [
            'post_type' => 'timetics-appointment',
            'fields'    => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'timetics-meeting-category',
                    'terms'    => $term_id,
                ],
            ],
        ];

        $posts = get_posts( $args );

        return $posts;
    }

    public function prepare_get_item( $appoint_id, $timezone = '' ) {
        $appointment   = new Appointment( $appoint_id );
         $data          = [
            'id'                    => $appointment->get_id(),
            'name'                  => $appointment->get_name(),
            'image'                 => $appointment->get_image(),
            'link'                  => $appointment->get_link(),
            'description'           => $appointment->get_description(),
            'type'                  => $appointment->get_type(),
            'locations'             => $appointment->get_locations(),
            'price'                 => $appointment->get_price(),
        ];

        return $data;
    }
}
