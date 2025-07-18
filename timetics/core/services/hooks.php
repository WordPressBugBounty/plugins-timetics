<?php
/**
 * Service hooks
 *
 * @package Timetics
 */

namespace Timetics\Core\Services;

use Timetics\Utils\Singleton;
use Timetics\Core\Services\Service_Taxonomy;

/**
 * Class Hooks
 */
class Hooks {
    use Singleton;

    /**
     * Store category
     *
     * @var Object
     */
    protected $category;
    /**
     * Initialoze
     *
     * @return void
     */
    public function init() {
        add_action( 'init', array( $this, 'boot' ), 5);
    }

    /**
     * Boot the service
     *
     * @return void
     */
    public function boot() {
        $this->category = new Service_Taxonomy();
    }
}
