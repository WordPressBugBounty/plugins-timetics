<?php
namespace Ens\Core;

use Ens\Assets\Enqueue;
use Ens\Flow\FlowAPI;
use Ens\Flow\FlowCPT;
use Ens\Hook\ActionListener;
use Ens\Whatsapp\MetaCloudProvider;
use Ens\Whatsapp\TemplatesAPI;

/**
 * Class SDK
 *
 * @package Ens
 *
 * @since 1.0.0
 */
class SDK {

    /**
     * @var SDK|null
     */
    protected static $instance = null;

    public $identifier;

    public static function get_instance() {
        self::$instance = new self();
        
        return self::$instance;
    }

    /**
     * Pass config data before init.
     *
     * @since 1.0.0
     *
     * @param array $config
     *
     * @return SDK
     */
    public function setup( $config ) {
        if(isset($config['general_prefix'])){
            $this->identifier = $config['general_prefix'];
            update_option( $this->identifier.'_ens_config', $config );
        }

        return $this;
    }

    /**
     * Initialize SDK.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function init() {
        ( new FlowCPT() )->register($this->identifier);
        ( new FlowAPI() )->init($this->identifier);
        ( new ActionListener() )->register($this->identifier);
        ( new Enqueue() )->init($this->identifier);
        // Added for adding whatsapp support
        ( new MetaCloudProvider( $this->identifier ) )->register();
        ( new TemplatesAPI() )->init( $this->identifier );
    }
}