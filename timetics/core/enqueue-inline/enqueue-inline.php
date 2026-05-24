<?php

/**
 * Inline Enqueue class
 *
 * @package Timetics
 */

namespace Timetics\Core\EnqueueInline;

defined( 'ABSPATH' ) || exit;

use Timetics\Utils\Singleton;

/**
 * Class Enqueue_Inline
 */
class Enqueue_Inline
{
    use Singleton;

    /**
     * Template path for dynamic color CSS.
     */
    const COLOR_TEMPLATE = __DIR__ . '/templates/dynamic-colors.php';

    /**
     * Initialize the shortcode class
     *
     * @return  void
     */
    public function init()
    {
        add_action( 'wp_enqueue_scripts', array( $this, 'custom_inline_css' ), 20 );
    }

    /**
     * Build and attach the dynamic color CSS to the frontend stylesheet.
     */
    public function custom_inline_css()
    {
        $primary_color   = timetics_get_option( 'primary_color' );
        $secondary_color = timetics_get_option( 'secondary_color' );

        if ( empty( $primary_color ) && empty( $secondary_color ) ) {
            return;
        }

        $custom_css = $this->render_color_css( $primary_color, $secondary_color );

        if ( '' === trim( $custom_css ) ) {
            return;
        }

        wp_register_style( 'timetics-custom-css', false, array(), TIMETICS_VERSION );
        wp_enqueue_style( 'timetics-custom-css' );
        wp_add_inline_style( 'timetics-frontend', $custom_css );
    }

    /**
     * Render the CSS template with the given colors.
     *
     * @param string $primary_color
     * @param string $secondary_color
     *
     * @return string
     */
    protected function render_color_css( $primary_color, $secondary_color )
    {
        if ( ! file_exists( self::COLOR_TEMPLATE ) ) {
            return '';
        }

        $primary_color   = sanitize_hex_color( $primary_color ) ?: $primary_color;
        $secondary_color = sanitize_hex_color( $secondary_color ) ?: $secondary_color;

        ob_start();
        include self::COLOR_TEMPLATE;
        return ob_get_clean();
    }
}
