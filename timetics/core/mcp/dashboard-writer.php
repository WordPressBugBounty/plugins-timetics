<?php
/**
 * Changes besides bookings
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Changes to meetings, categories, customers, staff and coupons. Owner only.
 *
 * Saves through the dashboard's own routes, so their checks, filters and hooks run
 * exactly as in the dashboard.
 */
class Dashboard_Writer {

    /**
     * Call a dashboard route as the current user.
     *
     * @param   string  $method  HTTP method.
     * @param   string  $path    Path under timetics/v1.
     * @param   array   $params  Query for GET, JSON body otherwise.
     *
     * @return  array|WP_Error  The route's data, or why it refused.
     */
    public static function route( string $method, string $path, array $params = array() ) {
        $request = new WP_REST_Request( $method, '/timetics/v1/' . ltrim( $path, '/' ) );

        if ( 'GET' === $method ) {
            $request->set_query_params( $params );
        } else {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $params ) );
        }

        $response = rest_do_request( $request );
        // Some routes answer with objects, such as a WP_Term.
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        if ( $response->is_error() || ! is_array( $data ) || self::refused( $data ) ) {
            $message = is_array( $data ) ? ( $data['message'] ?? '' ) : '';
            $message = is_array( $message ) ? implode( ' ', array_map( 'strval', $message ) ) : (string) $message;

            return new WP_Error( 'refused', '' !== $message ? wp_strip_all_tags( $message ) : __( 'The site refused this change.', 'timetics' ) );
        }

        return is_array( $data['data'] ?? null ) ? $data['data'] : array();
    }

    /**
     * Preview a change, or save it when the confirm token comes back.
     *
     * @param   string  $tool   Tool name.
     * @param   array   $input  Tool input, with confirm_token on the second call.
     * @param   int     $user   Who is asking.
     * @param   array   $spec   id: record changed, 0 when creating.
     *                          read: fn() returns the record now, array() when creating, or a WP_Error.
     *                          preview: fn( $change, $state ) returns what the user should see, or a WP_Error.
     *                          save: fn( $change, $state ) returns the result, or a WP_Error.
     *
     * @return  array|WP_Error
     */
    public static function change( string $tool, array $input, int $user, array $spec ) {
        if ( ! Mcp_Access::can_change( $user ) ) {
            return new WP_Error( 'access_denied', __( 'This connection may only view. Nothing was changed. To make changes, connect the app again and keep "Make changes" ticked.', 'timetics' ) );
        }
        if ( ! Mcp_Access::is_owner( $user ) ) {
            return new WP_Error( 'forbidden', __( 'Only the site owner can change this.', 'timetics' ) );
        }

        $token  = (string) ( $input['confirm_token'] ?? '' );
        $change = array_diff_key( $input, array( 'confirm_token' => true ) );
        $id     = (int) $spec['id'];

        if ( '' !== $token ) {
            $done = Confirmation::execute(
                $token,
                $user,
                $tool,
                $change,
                $spec['read'],
                function ( array $payload ) use ( $spec, $change ) {
                    // Built again: the preview is what the user agreed to.
                    $state   = $spec['read']();
                    $preview = is_wp_error( $state ) ? $state : $spec['preview']( $change, $state );
                    if ( is_wp_error( $preview ) ) {
                        return $preview;
                    }
                    if ( ! Confirmation::matches_preview( $payload, $preview ) ) {
                        return self::changed();
                    }

                    return $spec['save']( $change, $state );
                }
            );

            return is_wp_error( $done ) && 'booking_changed' === $done->get_error_code() ? self::changed() : $done;
        }

        $state = $spec['read']();
        if ( is_wp_error( $state ) ) {
            return $state;
        }

        $open = Confirmation::open_operation( $tool, $user, $id, $change );
        if ( $open ) {
            return $open;
        }

        $preview = $spec['preview']( $change, $state );
        if ( is_wp_error( $preview ) ) {
            return $preview;
        }

        return array(
            'preview'            => true,
            'confirm_token'      => Confirmation::issue( $tool, $user, $id, $change, $state, $preview ),
            'expires_in_seconds' => Confirmation::TOKEN_LIFETIME,
            'next_step'          => __( 'Nothing is saved yet. Show this to the user. Only after they say yes, call again with the same fields and confirm_token.', 'timetics' ),
        ) + $preview;
    }

    /**
     * Whether a route's answer says it refused. The routes use three shapes, and some
     * refuse with HTTP 200.
     *
     * @param   array  $data  Route answer.
     *
     * @return  bool
     */
    private static function refused( array $data ): bool {
        return ( array_key_exists( 'success', $data ) && empty( $data['success'] ) )
            || (int) ( $data['status_code'] ?? 0 ) >= 400
            || (int) ( $data['status'] ?? 0 ) >= 400;
    }

    /**
     * The error for a record that changed between preview and confirm.
     *
     * @return  WP_Error
     */
    private static function changed(): WP_Error {
        return new WP_Error( 'changed_since_preview', __( 'This changed after the preview. Nothing was saved. Ask for a new preview.', 'timetics' ) );
    }
}
