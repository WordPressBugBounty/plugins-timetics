<?php
/**
 * Who may use MCP
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use Timetics\Core\Bookings\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Who may use the tools. These limits apply to MCP only, not to the dashboard.
 */
class Mcp_Access {

    /**
     * Whether a user owns the site.
     *
     * @param   int  $user  User id.
     *
     * @return  bool
     */
    public static function is_owner( int $user ): bool {
        return $user > 0 && user_can( $user, 'manage_options' );
    }

    /**
     * Whether a user is staff and not an owner. Staff hold manage_timetics, not manage_options.
     *
     * @param   int  $user  User id.
     *
     * @return  bool
     */
    public static function is_staff( int $user ): bool {
        return $user > 0 && ! self::is_owner( $user ) && user_can( $user, 'manage_timetics' );
    }

    /**
     * Whether a user may run MCP tools at all.
     *
     * @param   int|null  $user  User id, the current user by default.
     *
     * @return  bool
     */
    public static function can_use_tools( $user = null ): bool {
        $user = (int) ( $user ?? get_current_user_id() );

        return self::is_owner( $user ) || self::is_staff( $user );
    }

    /**
     * Whether a user may run the tools that change bookings. A sign-in token allowed
     * to view only may not; a login or Application Password keeps the user's access.
     *
     * @param   int  $user  User id.
     *
     * @return  bool
     */
    public static function can_change( int $user ): bool {
        $scope = Oauth_Server::token_scope();

        return self::can_use_tools( $user ) && ( null === $scope || in_array( Oauth_Tokens::WRITE, $scope, true ) );
    }

    /**
     * Whether a user may reach one booking. Staff reach what their dashboard shows them.
     *
     * @param   int  $user  User id.
     * @param   int  $id    Post id.
     *
     * @return  bool
     */
    public static function can_see_booking( int $user, int $id ): bool {
        if ( 'timetics-booking' !== get_post_type( $id ) ) {
            return false;
        }

        if ( self::is_owner( $user ) ) {
            return true;
        }

        return self::is_staff( $user ) && in_array( $id, array_map( 'intval', Booking::get_visible_ids_for_user( $user ) ), true );
    }
}
