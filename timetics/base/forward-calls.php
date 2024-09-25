<?php
/**
 * ForwardCalls Trait
 *
 * @package Timetics
 */
namespace Timetics\Base;

use BadMethodCallException;
use Timetics\Base\PostModel;

/**
 * ForwardCalls Trait
 */
trait ForwardCalls {
    /**
     * Handle dynamic method
     *
     * @param   PostModel  $model
     * @param   string $method
     * @param   mixed $params
     *
     * @return  mixed
     */
    protected static function forwardCallToStatic( PostModel $model, $method, $params ) {
        try {
            $post = new Post( $model );

            return $post->$method( ...$params );

        } catch ( BadMethodCallException $e ) {
            static::throwBadMethodCallException( $method );
        }
    }

    /**
     * Throw a bad method call exception for the given method.
     *
     * @param  string  $method
     * @return void
     *
     * @throws \BadMethodCallException
     */
    protected static function throwBadMethodCallException( $method ) {
        throw new BadMethodCallException( sprintf(
            esc_html__( 'Call to undefined method %s::%s()', 'timetics' ), static::class, esc_html( $method )
        ) ); 
    }
}
