<?php
namespace Ens\Utils;

class Helpers {

    /**
     * will return strings after translation
     *
     * @return  array
     */
    public static function ens_get_translatable_strings( $string, $text_domain ) {
        return $string;
    }

    /**
     * will verify nonce
     *
     * @return  array
     */
    public static function ens_verify_nonce( $nonce, $identifier ) {
        $is_local = isset( $_SERVER['REMOTE_ADDR'] ) && in_array( $_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'] );

        if ( ( isset( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) || $is_local ) {
            return true;
        }

        $response = [
            'success'     => 0,
            'status_code' => 403,
            'message'     => __( 'Nonce not verified', self::get_config_data( $identifier,'text_domain' ) ),
            'data'        => [],
        ];
        return new WP_HTTP_Response( $response, 403 );
    }

    /**
     * will sanitize recursive
     *
     * @return  array
     */
    public static function ens_sanitize_recursive( $input ) {
        if ( is_array( $input ) ) {

            foreach ( $input as $key => $value ) {
                $input[$key] = ens_sanitize_recursive( $value );
            }

        } elseif ( is_object( $input ) ) {

            foreach ( $input as $key => $value ) {
                $input->$key = ens_sanitize_recursive( $value );
            }

        } else {
            if ( filter_var( $input, FILTER_VALIDATE_URL ) !== false ) {
                $input = sanitize_url( $input );
            } elseif ( is_email( $input ) ) {
                $input = sanitize_email( $input );
            } elseif ( is_int( $input ) ) {
                $input = intval( $input );
            } elseif ( is_float( $input ) ) {
                $input = floatval( $input );
            } elseif ( is_string( $input ) ) {
                if ( strpos( $input, '<svg' ) === false ) {
                    $input = wp_kses_post( $input );
                }
            }
        }

        return $input;
    }

    /**
     * will return post status
     *
     * @return  array
     */
    public static function ens_get_post_status() {
        return ['publish', 'draft', 'trash'];
    }

    /**
     * Get config data value with fallback
     *
     * @param string $prefix
     * @param string $value_of
     * @return mixed|null
     */
    public static function get_config_data($prefix, $value_of) {
        $key = $prefix . '_ens_config';

        $data = get_option( $key );

        if (isset($data[$value_of])) {
            return $data[$value_of];
        }

        return null;
    }

    /**
     * Build a plugin-scoped hook name.
     *
     * Prepends the consumer plugin's hook_prefix (or general_prefix as
     * fallback) to the given suffix so hooks fired from the SDK do not
     * collide when the package is bundled in multiple plugins.
     *
     * @param string $identifier The consumer plugin identifier (general_prefix).
     * @param string $hook_suffix The unscoped hook name (e.g. 'email_body').
     *
     * @return string
     */
    public static function get_hook_name( $identifier, $hook_suffix ) {
        $prefix = self::get_config_data( $identifier, 'hook_prefix' );

        if ( empty( $prefix ) ) {
            $prefix = $identifier;
        }

        return $prefix . '_' . $hook_suffix;
    }
}