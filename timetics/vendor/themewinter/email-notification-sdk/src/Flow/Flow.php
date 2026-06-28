<?php

namespace Ens\Flow;

use Ens\Base\PostModel;
use Ens\Utils\Helpers;
use WP_Query;

/**
 * Class Flow
 *
 * @since 1.0.0
 *
 * @package Ens
 */
class Flow extends PostModel {

    /**
     * Store post type
     *
     * @since 1.0.0
     *
     * @var string $post_type
     */
    public $post_type;

    /**
     * Store id
     *
     * @since 1.0.0
     *
     * @var int $id
     */
    protected $id;

    /**
     * Store post metadata
     *
     * @since 1.0.0
     *
     * @var array $data
     */
    public $data = [
        'name'        => '',
        'trigger'     => '',
        'flow_config' => '',
        'status'      => '',
    ];

    /**
     * Store meta key prefix
     *
     * @since 1.0.0
     *
     * @var string $prefix
     */
    public $prefix;

    public $identifier;

    /**
     * Flow Constructor
     *
     * @since 1.0.0
     *
     * @param int $flow Optional. Default 0.
     * @return void
     */
    public function __construct( $identifier,$flow = 0) {
        if ( $flow instanceof self ) {
            $this->set_id( $flow->get_id() );
        } elseif ( !empty( $flow->ID ) ) {
            $this->set_id( $flow->ID );
        } elseif ( is_numeric( $flow ) && $flow > 0 ) {
            $this->set_id( $flow );
        }

        $this->identifier = $identifier;
        $prefix_for_cpt  = $identifier;
        $this->post_type = $prefix_for_cpt . '-flow';

        $this->prefix = '_' . $prefix_for_cpt . '_notification_flow_';
    }

    /**
     * Get flow id.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get flow name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_name() {
        return get_post_field( 'post_title', $this->id );
    }

    /**
     * Get flow controls.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function get_trigger() {
        return $this->get_prop( 'trigger' );
    }

    /**
     * Get flow status
     *
     * @since 1.0.0
     *
     * @return  integer
     */
    public function get_status() {
        $post_status = get_post_status( $this->id );

        return $post_status;
    }

    /**
     * Get flow config
     *
     * @since 1.0.0
     *
     * @return  integer
     */
    public function get_flow_config() {
        return $this->get_prop( 'flow_config' );
    }

    /**
     * Get flow data
     *
     * @since 1.0.0
     *
     * @param   string  $prop
     *
     * @return  mixed
     */
    public function get_prop( $prop = '' ) {
        return $this->get_metadata( $prop );
    }

    /**
     * Get metadata
     *
     * @since 1.0.0
     *
     * @param string $prop Optional. Default empty string.
     *
     * @return  mixed
     */
    private function get_metadata( $prop = '' ) {
        $meta_key = $this->prefix . $prop;
        return get_post_meta( $this->id, $meta_key, true );
    }

    /**
     * Set flow id
     *
     * @since 1.0.0
     *
     * @param int $id
     *
     * @return void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set props
     *
     * @since 1.0.0
     *
     * @param array  $data Metadata array. Default empty array.
     *
     * @return  void
     */
    public function set_props( $data = [] ) {
        $this->data = $data;
    }

    /**
     * Save flow
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function save() {
        $args = [
            'post_title'  => $this->data['name'],
            'post_type'   => $this->post_type,
            'post_status' => $this->data['status'],
            'post_author' => get_current_user_id(),
        ];

        if ( !empty( $this->id ) ) {
            $args['ID'] = $this->id;
        }

        $flow_id = wp_insert_post( $args );
        if ( !is_wp_error( $flow_id ) ) {
            $this->set_id( $flow_id );
            $this->save_metadata();
        }

        return $flow_id;
    }

    /**
     * Update notification flow meta data
     *
     * @since 1.0.0
     *
     * @return  void
     */
    private function save_metadata() {
        foreach ( $this->data as $key => $value ) {
            if ( !in_array( $key, ['status', 'name'] ) ) {
                if ( !array_key_exists( $key, $this->data ) ) {
                    continue;
                }

                $meta_key = $this->prefix . $key;

                if ( !$value ) {
                    $value = $this->get_prop( $key );
                }
                update_post_meta( $this->id, $meta_key, $value );
            }
        }
    }

    /**
     * Delete flow
     *
     * @since 1.0.0
     *
     * @return bool | WP_Error
     */
    public function delete() {
        return wp_delete_post( $this->id, true );
    }

    /**
     * Check the flow is valid or not
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function is_flow() {
        $post = get_post( $this->id );

        if ( $post && $this->post_type === $post->post_type ) {
            return true;
        }

        return false;
    }

    /**
     * Get all flows
     *
     * @since 1.0.0
     *
     * @param array $args Flow args. Default empty array.
     *
     * @return array
     */
    public function all( $args = [] ) {
        $prefix_for_cpt = '_' . $this->identifier . '_notification_flow_';

        $defaults = [
            'post_type'      => $this->post_type,
            'posts_per_page' => 20,
            'paged'          => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ];

        $args = wp_parse_args( $args, $defaults );

        if ( !empty( $args['trigger'] ) ) {
            $args['meta_query'][] = [
                'key'     => $prefix_for_cpt . 'trigger',
                'value'   => $args['trigger'],
                'compare' => '=',
            ];
        }

        if ( !empty( $args['search_key'] ) ) {
            $args['s'] = $args['search_key'];
        }

        $post = new WP_Query( $args );

        return [
            'total' => $post->found_posts,
            'items' => $post->posts,
        ];
    }

    /**
     * clone flow
     *
     * @since 1.0.0
     *
     * @return  void
     */
    public function clone () {
        $this->set_props( $this->get_data() );

        $this->set_id( 0 );
        $this->save();
    }

    /**
     * Fetch status wise total flow count
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function total_flows_group_by_status() {
        $args = [
            'post_type'      => $this->post_type,
            'posts_per_page' => -1,
            'post_status'    => 'any',
        ];

        $query = new WP_Query( $args );

        $all_status = Helpers::ens_get_post_status();

        $status_count = [];
        foreach ( $all_status as $status ) {
            $status_count[$status] = 0;
        }

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $post_id = get_the_ID();
                $status  = get_post_status( $post_id );
                if ( isset( $status_count[$status] ) ) {
                    $status_count[$status]++;
                } else {
                    $status_count[$status] = 1;
                }
            }

            wp_reset_postdata();
        }

        return $status_count;
    }

    /**
     * Get all data for a flow
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function get_data() {
        return [
            'name'        => $this->get_name(),
            'trigger'     => $this->get_trigger(),
            'flow_config' => $this->get_flow_config(),
            'status'      => $this->get_status(),
        ];
    }
}