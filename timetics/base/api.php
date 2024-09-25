<?php
/**
 * Abstract api class
 *
 * @package Timetics
 */
namespace Timetics\Base;

use WP_Error;

/**
 * Abstract class Api.
 *
 * @since 1.0.0
 */
abstract class Api extends \WP_REST_Controller {

    /**
     * Api Constructor function.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register routes
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register_routes() {
        // Need to override the function on child class.
    }

    /**
     * Validate input fields.
     *
     * @since 1.0.0
     *
     * @param array $data Accepts data in array format.
     * @param array $fields Optional. Default empty array.
     *
     * @return  bool | WP_error
     */
    public function validate( $data, $fields = [] ) {
        $error = new WP_Error();

        foreach ( $fields as $field ) {
            if ( empty( $data[ $field ] ) ) {
                /* translators: Error Message */
                $error->add( $field . '_error', sprintf( esc_html__( '%s can\'t be empty', 'timetics' ), $field ) );
            }
        }

        if ( ! empty( $error->errors ) ) {
            return $error;
        }

        return true;
    }

}
