<?php
/**
 * MCP hooks
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use Timetics\Utils\Singleton;

defined( 'ABSPATH' ) || exit;

/**
 * Boots MCP when its setting is on. Off means neither tools nor URL exist.
 */
class Hooks {

    use Singleton;

    /**
     * Register hooks. The Abilities API needs WordPress 6.9, the plugin does not.
     *
     * @return  void
     */
    public function init() {
        // The settings card is how an owner turns MCP on, so it works while MCP is off.
        Mcp_Setup::register();

        if ( ! timetics_get_option( 'mcp_enabled' ) || ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        add_action( 'wp_abilities_api_categories_init', array( Abilities::class, 'register_category' ) );
        add_action( 'wp_abilities_api_init', array( Abilities::class, 'register_abilities' ) );
        add_action( 'mcp_adapter_init', array( Mcp_Server::class, 'create' ) );
        add_action( 'init', array( Confirmation::class, 'register' ) );
        Oauth_Server::register();
    }
}
