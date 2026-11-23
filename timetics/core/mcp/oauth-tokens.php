<?php
/**
 * Sign-in tokens
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Connections made through connector sign-in: codes, tokens and the grant behind them.
 *
 * One option per connection and one per token, all without autoload. Only SHA-256
 * hashes of secrets are stored, so a database copy cannot be replayed.
 */
class Oauth_Tokens {

    const READ  = 'bookings.read';
    const WRITE = 'bookings.write';

    const CODE_LIFETIME    = 600;
    const ACCESS_LIFETIME  = HOUR_IN_SECONDS;
    const REFRESH_LIFETIME = 30 * DAY_IN_SECONDS;

    const GRANT_PREFIX = 'timetics_mcp_grant_';
    const TOKEN_PREFIX = 'timetics_mcp_token_';
    const CODE_PREFIX  = 'timetics_mcp_code_';

    /**
     * Save a one-time code for an Allow.
     *
     * @param   array  $grant  client_id, client_name, user, scope, redirect_uri, challenge, resource.
     *
     * @return  string  The code.
     */
    public static function issue_code( array $grant ): string {
        $code = self::secret();
        add_option( self::CODE_PREFIX . self::hash( $code ), wp_json_encode( $grant + array( 'exp' => time() + self::CODE_LIFETIME ) ), '', false );

        return $code;
    }

    /**
     * Use a code. Only the request that deletes the row wins, so a code works once.
     *
     * @param   string  $code  The code.
     *
     * @return  array|null  What was allowed, or null when unknown, used or expired.
     */
    public static function take_code( string $code ) {
        $name  = self::CODE_PREFIX . self::hash( $code );
        $grant = self::read( $name );

        if ( ! $grant || ! delete_option( $name ) || $grant['exp'] < time() ) {
            return null;
        }

        return $grant;
    }

    /**
     * Start a connection and give its first tokens.
     *
     * @param   array  $grant  From the code: client_id, client_name, user, scope, resource.
     *
     * @return  array  Token response fields.
     */
    public static function start( array $grant ): array {
        return (array) self::rotate(
            array(
                'id'          => self::secret( 16 ),
                'user'        => (int) $grant['user'],
                'client_id'   => (string) $grant['client_id'],
                'client_name' => (string) $grant['client_name'],
                'scope'       => (string) $grant['scope'],
                'resource'    => (string) $grant['resource'],
                'created'     => time(),
                'last_used'   => 0,
            ),
            null
        );
    }

    /**
     * Swap a refresh token for new tokens. A refresh token that was already swapped
     * ends the connection, because only a stolen copy would be used twice. Of two
     * requests at once, only the first gets tokens (see swap()).
     *
     * @param   string  $refresh    Refresh token.
     * @param   string  $client_id  Client asking.
     *
     * @return  array|null  Token response fields, or null when refused.
     */
    public static function refresh( string $refresh, string $client_id ) {
        $token = self::read( self::TOKEN_PREFIX . self::hash( $refresh ) );
        if ( ! $token || 'refresh' !== $token['kind'] ) {
            return null;
        }

        $raw   = (string) get_option( self::GRANT_PREFIX . $token['grant'], '' );
        $grant = self::decode( $raw );
        if ( ! $grant || $grant['client_id'] !== $client_id ) {
            return null;
        }

        if ( $grant['refresh'] !== self::hash( $refresh ) || $token['exp'] < time() ) {
            self::revoke( $grant['id'] );
            return null;
        }

        return self::rotate( $grant, $raw );
    }

    /**
     * Who a token belongs to and what it may do.
     *
     * @param   string  $access    Access token.
     * @param   string  $resource  The URL it is used on.
     *
     * @return  array|null  user and scope, or null when the token does not open this URL.
     */
    public static function user_for( string $access, string $resource ) {
        $token = self::read( self::TOKEN_PREFIX . self::hash( $access ) );
        if ( ! $token || 'access' !== $token['kind'] || $token['exp'] < time() ) {
            return null;
        }

        $raw   = (string) get_option( self::GRANT_PREFIX . $token['grant'], '' );
        $grant = self::decode( $raw );
        if ( ! $grant || $grant['access'] !== self::hash( $access ) || $grant['resource'] !== $resource || ! get_userdata( $grant['user'] ) ) {
            return null;
        }

        // Written at most every 5 minutes, so a busy chat does not write on every call.
        // Skipped when a refresh or an End got there first: the time is only for display.
        if ( $grant['last_used'] < time() - 300 ) {
            self::swap( self::GRANT_PREFIX . $grant['id'], $raw, array( 'last_used' => time() ) + $grant );
        }

        return array(
            'user'  => (int) $grant['user'],
            'scope' => explode( ' ', $grant['scope'] ),
        );
    }

    /**
     * Connections a user may see: every one for the owner, their own for staff.
     *
     * @param   int  $viewer  User asking.
     *
     * @return  array[]  id, client_name, user, user_name, can_change, created, last_used, and
     *                   last_used_text in the site's date and time format and timezone.
     */
    public static function connections( int $viewer ): array {
        $list   = array();
        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        foreach ( self::rows( self::GRANT_PREFIX ) as $grant ) {
            if ( ! Mcp_Access::is_owner( $viewer ) && $grant['user'] !== $viewer ) {
                continue;
            }

            $user   = get_userdata( $grant['user'] );
            $list[] = array(
                'id'             => $grant['id'],
                'client_name'    => $grant['client_name'],
                'user'           => $grant['user'],
                'user_name'      => $user ? $user->display_name : '',
                'can_change'     => in_array( self::WRITE, explode( ' ', $grant['scope'] ), true ),
                'created'        => $grant['created'],
                'last_used'      => $grant['last_used'],
                'last_used_text' => $grant['last_used'] ? (string) wp_date( $format, $grant['last_used'] ) : '',
            );
        }

        usort(
            $list,
            function ( $a, $b ) {
                return $b['created'] <=> $a['created'];
            }
        );

        return $list;
    }

    /**
     * The apps that have a connection.
     *
     * @return  string[]  Client ids.
     */
    public static function client_ids(): array {
        return array_values( array_unique( array_column( self::rows( self::GRANT_PREFIX ), 'client_id' ) ) );
    }

    /**
     * The user a connection belongs to.
     *
     * @param   string  $id  Connection id.
     *
     * @return  int  0 when the connection does not exist.
     */
    public static function owner_of( string $id ): int {
        $grant = self::read( self::GRANT_PREFIX . sanitize_key( $id ) );

        return $grant ? (int) $grant['user'] : 0;
    }

    /**
     * End one connection and its tokens.
     *
     * @param   string  $id  Connection id.
     *
     * @return  void
     */
    public static function revoke( string $id ) {
        $grant = self::read( self::GRANT_PREFIX . sanitize_key( $id ) );
        if ( ! $grant ) {
            return;
        }

        // The connection row first: without it no token works, whatever a refresh does next.
        delete_option( self::GRANT_PREFIX . $grant['id'] );
        delete_option( self::TOKEN_PREFIX . $grant['access'] );
        delete_option( self::TOKEN_PREFIX . $grant['refresh'] );
    }

    /**
     * End every connection of one user.
     *
     * @param   int  $user  User id.
     *
     * @return  void
     */
    public static function revoke_user( int $user ) {
        foreach ( self::rows( self::GRANT_PREFIX ) as $grant ) {
            if ( $grant['user'] === $user ) {
                self::revoke( $grant['id'] );
            }
        }
    }

    /**
     * End every connection, and drop unused codes.
     *
     * @return  void
     */
    public static function revoke_all() {
        foreach ( array( self::GRANT_PREFIX, self::TOKEN_PREFIX, self::CODE_PREFIX ) as $prefix ) {
            foreach ( array_keys( self::rows( $prefix, true ) ) as $name ) {
                delete_option( $name );
            }
        }
    }

    /**
     * Remove expired codes and tokens, and connections whose refresh token ran out.
     *
     * @return  void
     */
    public static function cleanup() {
        foreach ( array( self::CODE_PREFIX, self::TOKEN_PREFIX ) as $prefix ) {
            foreach ( self::rows( $prefix, true ) as $name => $row ) {
                if ( $row['exp'] < time() ) {
                    delete_option( $name );
                }
            }
        }

        foreach ( self::rows( self::GRANT_PREFIX ) as $grant ) {
            if ( ! self::read( self::TOKEN_PREFIX . $grant['refresh'] ) ) {
                self::revoke( $grant['id'] );
            }
        }
    }

    /**
     * Replace a connection's tokens with new ones.
     *
     * @param   array        $grant  Connection row.
     * @param   string|null  $raw    The row as read, or null for a new connection.
     *
     * @return  array|null  Token response fields, or null when the row changed since it was read.
     */
    private static function rotate( array $grant, $raw ) {
        $old         = $grant['access'] ?? '';
        $old_refresh = $grant['refresh'] ?? '';
        $access      = self::secret();
        $refresh     = self::secret();

        $grant['access']  = self::hash( $access );
        $grant['refresh'] = self::hash( $refresh );

        $name  = self::GRANT_PREFIX . $grant['id'];
        $saved = null === $raw ? add_option( $name, wp_json_encode( $grant ), '', false ) : self::swap( $name, $raw, $grant );
        if ( ! $saved ) {
            return null;
        }

        if ( $old ) {
            delete_option( self::TOKEN_PREFIX . $old );
        }

        // The old refresh row stays a day, so a second use of it is noticed. Kept for its
        // full 30 days, every hourly refresh would leave one more row behind.
        $retired = $old_refresh ? self::read( self::TOKEN_PREFIX . $old_refresh ) : null;
        if ( $retired ) {
            $retired['exp'] = min( $retired['exp'], time() + DAY_IN_SECONDS );
            update_option( self::TOKEN_PREFIX . $old_refresh, wp_json_encode( $retired ), false );
        }
        add_option( self::TOKEN_PREFIX . $grant['access'], wp_json_encode( self::token( 'access', $grant['id'], self::ACCESS_LIFETIME ) ), '', false );
        add_option( self::TOKEN_PREFIX . $grant['refresh'], wp_json_encode( self::token( 'refresh', $grant['id'], self::REFRESH_LIFETIME ) ), '', false );

        return array(
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_LIFETIME,
            'refresh_token' => $refresh,
            'scope'         => $grant['scope'],
        );
    }

    /**
     * A token row.
     *
     * @param   string  $kind      access or refresh.
     * @param   string  $grant     Connection id.
     * @param   int     $lifetime  Seconds.
     *
     * @return  array
     */
    private static function token( string $kind, string $grant, int $lifetime ): array {
        return array(
            'kind'  => $kind,
            'grant' => $grant,
            'exp'   => time() + $lifetime,
        );
    }

    /**
     * Write a connection row only if it is still exactly as it was read. Two requests
     * can read the same row; the one whose write lands second changes nothing, and a
     * row that was deleted (an End) stays deleted.
     *
     * @param   string  $name  Option name.
     * @param   string  $raw   The row as read.
     * @param   array   $row   The row to save.
     *
     * @return  bool  Whether it was saved.
     */
    private static function swap( string $name, string $raw, array $row ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- update_option() cannot write only when the row is unchanged. The cache is cleared below.
        $saved = $wpdb->update(
            $wpdb->options,
            array( 'option_value' => wp_json_encode( $row ) ),
            array(
                'option_name'  => $name,
                'option_value' => $raw,
            )
        );
        wp_cache_delete( $name, 'options' );

        return 1 === $saved;
    }

    /**
     * Read one row.
     *
     * @param   string  $name  Option name.
     *
     * @return  array|null
     */
    private static function read( string $name ) {
        return self::decode( (string) get_option( $name, '' ) );
    }

    /**
     * A stored row as an array.
     *
     * @param   string  $raw  Option value.
     *
     * @return  array|null
     */
    private static function decode( string $raw ) {
        $value = json_decode( $raw, true );

        return is_array( $value ) ? $value : null;
    }

    /**
     * Every row with a prefix.
     *
     * @param   string  $prefix  Option name prefix.
     * @param   bool    $named   Key the result by option name.
     *
     * @return  array[]
     */
    private static function rows( string $prefix, bool $named = false ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lists this module's own rows, which no API indexes.
        $found = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
        $rows  = array();

        foreach ( $found as $row ) {
            $value = json_decode( $row->option_value, true );
            if ( is_array( $value ) ) {
                $rows[ $row->option_name ] = $value;
            }
        }

        return $named ? $rows : array_values( $rows );
    }

    /**
     * A random secret in hex.
     *
     * @param   int  $bytes  Length in bytes.
     *
     * @return  string
     */
    private static function secret( int $bytes = 32 ): string {
        return bin2hex( random_bytes( $bytes ) );
    }

    /**
     * Hash of a secret, which is all that is stored.
     *
     * @param   string  $secret  The secret.
     *
     * @return  string
     */
    private static function hash( string $secret ): string {
        return hash( 'sha256', $secret );
    }
}
