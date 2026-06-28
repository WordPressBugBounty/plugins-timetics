<?php

namespace Ens\Base;

use BadMethodCallException;
use Ens\Base\PostModel;

/**
 * ForwardCalls Trait
 *
 * @since 1.0.0
 *
 * @package ENS
 */
trait ForwardCalls {

    /**
     * Handle dynamic method
     *
     * @since 1.0.0
     *
     * @param   PostModel  $model
     * @param   string $method
     * @param   mixed $params
     *
     * @return  mixed
     */
    protected static function forward_call_to_static( PostModel $model, $method, $params ) {
        try {
            $post = new Post( $model );

            return $post->$method( ...$params );

        } catch ( BadMethodCallException $e ) {
            static::throw_bad_method_call_exception( $method );
        }
    }

    /**
     * Throw a bad method call exception for the given method.
     *
     * @since 1.0.0
     *
     * @param  string  $method
     * @return void
     *
     * @throws \BadMethodCallException
     */
    protected static function throw_bad_method_call_exception( $method ) {
        throw new BadMethodCallException( esc_html( sprintf(
            // translators: %1$s is the class name, %2$s is the method name
            'Call to undefined method %1$s::%2$s()',
            static::class,
            $method
        ) ) );
    }
}