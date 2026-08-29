<?php
/**
 * Extentions class
 * 
 * @package Arraytics\ToolsSdk
 */
namespace Arraytics\ToolsSdk;

/**
 * Plugin manager
 */
class PluginManager {

    /**
     * Check if a plugin is installed.
     *
     * @param string $slug The slug of the plugin.
     * @return bool True if installed, false otherwise.
     */
    public static function is_installed( $slug ) {
        // Ensure the get_plugins function is available
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        if ( is_array( $plugins ) ) {
            foreach( $plugins as $plugin ) {
                if ( $plugin['TextDomain'] === $slug ) {
                    return true;
                }
            }
        }

        return false;
    }
    
    /**
     * Check if a plugin is active.
     *
     * @param string $slug The slug of the plugin.
     * @return bool True if activated, false otherwise.
     */
    public static function is_activated( $slug ) {
        if ( ! self::is_installed( $slug ) ) {
            return false;
        }

        return is_plugin_active( self::get_plugin_path( $slug ) );
    }

    /**
     * Install a plugin from the WordPress repository by slug.
     *
     * @param string $slug The slug of the plugin.
     * @return bool True if installation succeeds, false otherwise.
     */
    public static function install_plugin( $slug ) {
        if ( self::is_installed( $slug ) ) {
            return true; // Already installed
        }

        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/misc.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin      = new \Automatic_Upgrader_Skin();
        $upgrader  = new \Plugin_Upgrader($skin);
        $result    = $upgrader->install('https://downloads.wordpress.org/plugin/' . $slug . '.latest-stable.zip');

        return $result ? true : false;
    }

    /**
     * Activate a plugin by slug.
     *
     * @param string $slug The slug of the plugin.
     * @return bool True if activated, false otherwise.
     */
    public static function activate_plugin( $slug ) {
        if ( ! self::is_installed( $slug ) ) {
            return false; // Plugin not installed
        }

        $plugin_path = self::get_plugin_path( $slug );
        $result      = activate_plugin( $plugin_path );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    /**
     * Deactivate a plugin by slug.
     *
     * @param string $slug The slug of the plugin.
     * @return bool True if deactivated, false otherwise.
     */
    public static function deactivate_plugin( $slug ) {
        if ( ! self::is_activated( $slug ) ) {
            return false; // Plugin not activated
        }

        $plugin_path = self::get_plugin_path( $slug );

        deactivate_plugins( $plugin_path );

        return true;
    }

    /**
     * Get the plugin path by slug.
     *
     * @param string $slug The slug of the plugin.
     * @return string The path to the plugin file.
     */
    private static function get_plugin_path( $slug ) {
        $plugins = get_plugins();

        if ( is_array( $plugins ) ) {
            foreach( $plugins as $plugin_path => $plugin ) {
                if ( $plugin['TextDomain'] === $slug ) {
                    return $plugin_path;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if the provided file is the main plugin file.
     *
     * @param string $file Path to the file.
     * @return bool True if it is the main plugin file, false otherwise.
     */
    private static function is_main_plugin_file( $file ) {

        $plugin_data = get_file_data( $file, ['Name' => 'Plugin Name'] );

        return ! empty( $plugin_data['Name'] );
    }

    /**
     * Get plugin name by slug.
     *
     * @param string $slug Plugin slug (e.g., 'woocommerce).
     * @return string|null Returns plugin name if found, or null if not found.
     */
    public static function get_plugin_name_by_slug( $slug ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( strpos( $plugin_file, $slug ) !== false ) {
                return $plugin_data['Name'];
            }
        }

        return null;
    }
}
