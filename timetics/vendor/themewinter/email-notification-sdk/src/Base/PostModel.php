<?php

namespace Ens\Base;

use Ens\Base\ForwardCalls;
use Ens\Config;

/**
 * Post Model Class
 *
 * @since 1.0.0
 *
 * @package ENS
 */
abstract class PostModel {
    use ForwardCalls;

    /**
     * Store post type
     *
     * @since 1.0.0
     *
     * @var string
     */
    public $post_type;

    /**
     * Store prefix
     *
     * @since 1.0.0
     *
     * @var string
     */
    public $prefix;

    /**
     * Store data for the current post
     *
     * @since 1.0.0
     *
     * @var array
     */
    public $data = [];

    /**
     * Get the post property dynamically
     *
     * @since 1.0.0
     *
     * @param   string  $name  Property name of the current post
     *
     * @return  mixed
     */
    public function __get( $name ) {
        if ( isset( $this->data[$name] ) ) {
            return $this->data[$name];
        }

        // translators: %s is the name of the property
        throw new \Exception( esc_html( sprintf( 'Undefined property %s', $name ) ) );
    }

    /**
     * Set dynamic property
     *
     * @since 1.0.0
     *
     * @param   string  $key
     * @param   mixed  $value
     *
     * @return  void
     */
    public function __set( $key, $value ) {
        if ( !isset( $this->data[$key] ) ) {
            // translators: %s is the name of the property
            throw new \Exception( esc_html( sprintf( 'Undefined property %s', $key ) ) );
        }

        $this->data[$key] = $value;
    }

    /**
     * Debug info for current object
     *
     * @since 1.0.0
     *
     * @return mixed
     */
    public function __debugInfo() {
        return $this->data;
    }

    /**
     * Handle dynamic static method call
     *
     * @since 1.0.0
     *
     * @param   string  $method Method name for dynamic call
     * @param   mixed  $params  method params
     *
     * @return  string
     */
    public static function __callStatic( $method, $params ) {
        return static::forward_call_to_static( new static , $method, $params );
    }

    /**
     * Handle dynamic method call
     *
     * @since 1.0.0
     *
     * @param   string  $method Method name for dynamic call
     * @param   mixed  $params  method params
     *
     * @return  string
     */
    public function __call( $method, $params ) {
        return static::forward_call_to_static( ( new static ), $method, $params );
    }

    /**
     * Get property of current post
     *
     * @since 1.0.0
     *
     * @return mixed
     */
    public function get_prop( $name = '' ) {
        $key = $this->prefix . $name;

        return get_post_meta( $this->id, $key, true );
    }

    /**
     * Convert object property to an Array
     *
     * @since 1.0.0
     *
     * @return  array
     */
    public function to_array() {
        $items       = [];
        $data        = $this->data;
        $items['id'] = $this->id;

        unset( $data['id'] );

        foreach ( $data as $key => $value ) {
            $prop = $this->get_prop( $key );

            $items[$key] = '' === $prop ? $value : $prop;
        }

        return $items;
    }

    /**
     * Get all models
     *
     * @since 1.0.0
     *
     * @return  array
     */
    public function all( $data = [] ) {
        $models = [];
        $model  = new static();
        $meta   = [];

        if ( $data ) {
            foreach ( $data as $key => $value ) {
                $meta_data = [
                    'key'     => $model->prefix . $key,
                    'value'   => $value,
                    'compare' => '=',
                ];

                $meta[] = $meta_data;
            }
        }

        $args = [
            'post_type' => $model->post_type,
        ];

        if ( $meta ) {
            $meta['relation']   = 'AND';
            $args['meta_query'] = $meta;
        }

        $posts = get_posts( $args );

        foreach ( $posts as $post ) {
            $object = new static();
            $model->load( $object, $post );
            $models[] = $object;
        }

        return $models;
    }
}
