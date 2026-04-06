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
            /* translators: %1$s: Class name, %2$s: Method name */
            esc_html__( 'Call to undefined method %1$s::%2$s()', 'timetics' ), static::class, esc_html( $method )
        ) );
    }
}
