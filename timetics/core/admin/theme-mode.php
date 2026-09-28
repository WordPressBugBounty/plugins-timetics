<?php
/**
 * Per-user light/dark preference for the Timetics admin.
 *
 * @package Timetics
 */
namespace Timetics\Core\Admin;

defined( 'ABSPATH' ) || exit;

use Timetics\Utils\Singleton;

/**
 * The preference is stored against the WordPress user, not in plugin options,
 * so one admin choosing dark does not impose it on everyone else who logs in.
 *
 * It is printed on <body> by PHP, before any script runs, so a dark mode user
 * gets no white flash while React mounts. PHP cannot resolve 'system' on the
 * server, so it prints the preference and a CSS media query settles it; once
 * React mounts it swaps in the resolved value.
 *
 * Saving needs no controller of our own: register_meta() with show_in_rest
 * makes core's POST /wp/v2/users/me accept the field, and a logged-in user may
 * always edit their own profile.
 */
class Theme_Mode {
    use Singleton;

    /**
     * User meta key holding the preference.
     */
    const META_KEY = 'timetics_theme_mode';

    /**
     * The only values that may ever be stored or printed.
     */
    const MODES = array( 'light', 'dark', 'system' );

    /**
     * Used when nothing is stored, and when anything invalid is.
     */
    const DEFAULT_MODE = 'system';

    /**
     * The one admin screen Timetics owns.
     */
    const SCREEN = 'toplevel_page_timetics';

    /**
     * Register hooks.
     *
     * Base::init() runs on plugins_loaded, so hooking init here is on time.
     *
     * @return void
     */
    public function init() {
        add_action( 'init', array( $this, 'register_meta' ) );
        add_filter( 'admin_body_class', array( $this, 'body_class' ) );
    }

    /**
     * Expose the preference on core's user REST endpoint.
     *
     * @return void
     */
    public function register_meta() {
        register_meta(
            'user',
            self::META_KEY,
            array(
                'type'              => 'string',
                'single'            => true,
                'default'           => self::DEFAULT_MODE,
                'show_in_rest'      => true,
                'sanitize_callback' => array( __CLASS__, 'sanitize' ),
                'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
                    return current_user_can( 'edit_user', $object_id );
                },
            )
        );
    }

    /**
     * Force any value into one of the three modes.
     *
     * This value ends up inside a class attribute on <body>, so it is a trust
     * boundary. The REST endpoint belongs to core, so the payload shape is not
     * ours to control: arrays and nulls fall through quietly.
     *
     * @param mixed $value Raw value from REST, the database, or WP-CLI.
     *
     * @return string One of self::MODES.
     */
    public static function sanitize( $value ) {
        if ( ! is_string( $value ) ) {
            return self::DEFAULT_MODE;
        }

        return in_array( $value, self::MODES, true ) ? $value : self::DEFAULT_MODE;
    }

    /**
     * The preference for a user.
     *
     * Sanitises on the way out as well as in: meta can be written by WP-CLI or
     * other code, so reading it is just as much a trust boundary.
     *
     * @param int $user_id 0 for the current user.
     *
     * @return string One of self::MODES.
     */
    public static function get_for_user( $user_id = 0 ) {
        $user_id = $user_id ? $user_id : get_current_user_id();

        if ( ! $user_id ) {
            return self::DEFAULT_MODE;
        }

        return self::sanitize( get_user_meta( $user_id, self::META_KEY, true ) );
    }

    /**
     * Whether the feature is visible to anyone.
     *
     * On by default. The filter stays so a site owner who does not want dark
     * mode can turn the whole thing off in one line:
     *
     *     add_filter( 'timetics_enable_dark_mode', '__return_false' );
     *
     * @return bool
     */
    public static function is_enabled() {
        return (bool) apply_filters( 'timetics_enable_dark_mode', true );
    }

    /**
     * The mode handed to JavaScript.
     *
     * While the feature is off this must be 'light', never the stored value:
     * JS resolves 'system' itself and would turn a dark OS into a dark admin.
     *
     * @return string One of self::MODES.
     */
    public static function get_for_localize() {
        return self::is_enabled() ? self::get_for_user() : 'light';
    }

    /**
     * Add the theme class to <body> on the Timetics screen only.
     *
     * @param string $classes Space separated classes from WordPress.
     *
     * @return string
     */
    public function body_class( $classes ) {
        if ( ! self::is_enabled() || ! $this->is_timetics_screen() ) {
            return $classes;
        }

        return $classes . ' timetics-theme-' . self::get_for_user();
    }

    /**
     * Whether the current request is rendering the Timetics admin screen.
     *
     * get_current_screen() is unavailable early in the request.
     *
     * @return bool
     */
    private function is_timetics_screen() {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && self::SCREEN === $screen->base;
    }
}
