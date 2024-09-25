<?php
/**
 * Taxonomy Base Class
 *
 * @since 1.0.0
 *
 * @package Timetics
 */
namespace Timetics\Base;

/**
 * Taxonomy Class.
 *
 * @since 1.0.0
 */
abstract class Taxonomy {
    /**
     * Store taxonomy name
     *
     * @since 1.0.0
     *
     * @var string $name
     */
    protected $name;

    /**
     * Store custom post type
     *
     * @since 1.0.0
     *
     * @var string $cpt
     */
    protected $cpt;

    /**
     * Store taxonomy args.
     *
     * @since 1.0.0
     *
     * @var array $args
     */
    protected $args;

    /**
     * Taxonomy Constructor.
     *
     * @since 1.0.0
     *
     * @return  void
     */
    public function __construct() {
        $this->set_props();
        add_action( 'init', [ $this, 'register_taxonomy' ] );
    }

    /**
     * Regisetr custom taxonomy
     *
     * @since 1.0.0
     *
     * @return  void
     */
    public function register_taxonomy() {
        register_taxonomy( $this->name, $this->cpt, $this->args );
    }

    /**
     * Set config
     *
     * @since 1.0.0
     *
     * @return void
     */
    abstract public function set_props();
}
