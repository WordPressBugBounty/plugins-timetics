<?php
/**
 * Post Model Class
 *
 * @package Timetics
 */

namespace Timetics\Base;

use Timetics\Base\ForwardCalls;

/**
 * Class Post Model
 */
abstract class PostModel {
    use ForwardCalls;

    /**
     * Store post type
     *
     * @var string
     */
    public $post_type;
    /**
     * Store prefix
     *
     * @var string
     */
    public $prefix;

    /**
     * Store data for the current post
     *
     * @var array
     */
    public $data = [];

    /**
     * Get the post property dynamically
     *
     * @param   string  $name  Property name of the current post
     *
     * @return  mixed
     */
    public function __get( $name ) {
        if ( isset( $this->data[$name] ) ) {
            return $this->data[$name];
        }

        throw new \Exception( sprintf( esc_html__( 'Undefined property %s', 'timetics' ), esc_attr( $name ) ) );
    }

    /**
     * Set dynamic property
     *
     * @param   string  $key
     * @param   mixed  $value
     *
     * @return  void
     */
    public function __set( $key, $value ) {
        if ( ! isset( $this->data[$key] ) ) {
            throw new \Exception( sprintf( esc_html__( 'Undefined property %s', 'timetics' ), esc_attr( $key ) ) );
        }

        $this->data[$key] = $value;
    }

    /**
     * Debug info for current object
     *
     * @return mixed
     */
    public function __debugInfo() {
        return $this->data;
    }

    /**
     * Handle dynamic static method call
     *
     * @param   string  $method Method name for dynamic call
     * @param   mixed  $params  method params
     *
     * @return  string
     */
    public static function __callStatic( $method, $params ) {
        return static::forwardCallToStatic( new static , $method, $params );
    }

    /**
     * Handle dynamic method call
     *
     * @param   string  $method Method name for dynamic call
     * @param   mixed  $params  method params
     *
     * @return  string
     */
    public function __call( $method, $params ) {
        return static::forwardCallToStatic(  ( new static ), $method, $params );
    }

    /**
     * Get property of current post
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
     * @return  array
     */
    public function toArray() {
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
     * @return  array
     */
    public static function all( $data = [] ) {
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
            $meta['relation'] = 'AND';
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
