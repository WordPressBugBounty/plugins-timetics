<?php
/**
 * Extension Icon class
 *
 * @package Timetics
 */

namespace Timetics\Core\Addon;

defined( 'ABSPATH' ) || exit;

/**
 * Class Extension_Icon
 *
 * Loads SVG/PNG icons for Arraytics plugins from assets/images/addons/.
 */
class Extension_Icon {

    /**
     * Get the icon for a given plugin by name.
     *
     * Returns raw SVG markup for .svg files, or a URL string for image files.
     *
     * @param string $name Plugin/extension slug (e.g. 'eventin').
     * @return string SVG markup or image URL, empty string if not found.
     */
    public static function get( string $name ): string {
        return self::load( $name );
    }

    /**
     * Load icon file from assets/images/addons/.
     *
     * @param string $file_name File name without extension.
     * @return string
     */
    private static function load( string $file_name ): string {
        $extensions = [ 'svg', 'png', 'webp', 'jpg' ];
        $base_path  = TIMETICS_PLUGIN_DIR . 'assets/images/addons/';
        $base_url   = TIMETICS_ASSETS_URL . 'images/addons/';

        foreach ( $extensions as $ext ) {
            $file = $base_path . $file_name . '.' . $ext;
            if ( file_exists( $file ) ) {
                return 'svg' === $ext
                    ? file_get_contents( $file ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    : $base_url . $file_name . '.' . $ext;
            }
        }

        return '';
    }
}
