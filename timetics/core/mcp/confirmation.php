<?php
/**
 * MCP confirmation
 *
 * @package Timetics
 */
namespace Timetics\Core\Mcp;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a write run once for one approved preview.
 *
 * A token proves the preview and the change match, not that a person said yes. Rows
 * are read straight from the table, because get_option() may hold a stale copy and the
 * first claim is a raw insert it never saw.
 */
class Confirmation {

    const TOKEN_LIFETIME = 600;
    const CLAIM_GRACE    = 300;
    const RESOLVED_KEEP  = DAY_IN_SECONDS;
    const OPERATION_META = '_tt_booking_mcp_operation';
    const CLEANUP_HOOK   = 'timetics_mcp_cleanup';
    const CLEANUP_BATCH  = 500;

    /**
     * Register the daily cleanup.
     *
     * @return  void
     */
    public static function register() {
        add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );

        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /**
     * Make a token for a preview.
     *
     * @param   string  $tool     Tool name.
     * @param   int     $user     Who previewed.
     * @param   int     $booking  Booking changed, 0 when creating.
     * @param   array   $change   The change asked for.
     * @param   array   $state    The booking's state now.
     * @param   array   $preview  What the preview showed. expected_bookings counts a series.
     *
     * @return  string
     */
    public static function issue( string $tool, int $user, int $booking, array $change, array $state, array $preview ): string {
        return self::sign(
            array(
                'op'       => wp_generate_uuid4(),
                'tool'     => $tool,
                'user'     => $user,
                'booking'  => $booking,
                'change'   => self::hash( $change ),
                'state'    => self::hash( $state ),
                'preview'  => self::hash( $preview ),
                'expected' => (int) ( $preview['expected_bookings'] ?? 1 ),
                'exp'      => time() + self::TOKEN_LIFETIME,
            )
        );
    }

    /**
     * Check a token belongs to this user, tool and change. Expiry is left to execute(),
     * because a retry of a finished write must still get its result.
     *
     * @param   string  $token   Token.
     * @param   int     $user    Who is calling.
     * @param   string  $tool    Tool name.
     * @param   array   $change  The change sent with the token.
     *
     * @return  array|WP_Error  The token's payload.
     */
    public static function verify( string $token, int $user, string $tool, array $change ) {
        $parts   = explode( '.', $token );
        $payload = 2 === count( $parts ) && hash_equals( self::signature( $parts[0] ), $parts[1] )
            ? json_decode( (string) hex2bin( $parts[0] ), true )
            : null;

        $is_valid = is_array( $payload )
            && $payload['user'] === $user
            && $payload['tool'] === $tool
            && hash_equals( $payload['change'], self::hash( $change ) );

        return $is_valid ? $payload : self::invalid();
    }

    /**
     * Whether what was just checked is what the token's preview showed.
     *
     * @param   array  $payload  Token payload.
     * @param   array  $preview  The preview built now.
     *
     * @return  bool
     */
    public static function matches_preview( array $payload, array $preview ): bool {
        return hash_equals( $payload['preview'], self::hash( $preview ) );
    }

    /**
     * Run a confirmed write, or answer with what an earlier call left.
     *
     * @param   string    $token   Token.
     * @param   int       $user    Who is calling.
     * @param   string    $tool    Tool name.
     * @param   array     $change  The change.
     * @param   callable  $check   Checks role and access, returns the state now or a WP_Error.
     * @param   callable  $work    Receives the payload, returns booking_ids and result fields,
     *                             or a WP_Error when nothing was saved. Exceptions pass through.
     *
     * @return  array|WP_Error
     */
    public static function execute( string $token, int $user, string $tool, array $change, callable $check, callable $work ) {
        $payload = self::verify( $token, $user, $tool, $change );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $row = self::read_row( $payload['op'] );
        if ( $row ) {
            return self::answer_from( $row, $user );
        }

        return self::holding_lock(
            $payload['op'],
            function () use ( $payload, $user, $check, $work ) {
                $row = self::read_row( $payload['op'] );
                if ( $row ) {
                    return self::answer_from( $row, $user );
                }

                if ( $payload['exp'] < time() ) {
                    return self::invalid();
                }

                $changed = self::recheck( $payload, $check );
                if ( $changed ) {
                    return $changed;
                }

                $claimed = self::claim( $payload );
                if ( is_wp_error( $claimed ) ) {
                    return $claimed;
                }
                if ( ! $claimed ) {
                    return self::answer_from( (array) self::read_row( $payload['op'] ), $user );
                }

                return self::run_claimed( $payload, $work );
            }
        );
    }

    /**
     * Run the access check again and compare the state with the preview's. Booking tools
     * also call it once they hold the booking lock, which can take seconds to get.
     *
     * @param   array     $payload  Token payload.
     * @param   callable  $check    Returns the state now or a WP_Error.
     *
     * @return  WP_Error|null  Why the change must stop, or null.
     */
    public static function recheck( array $payload, callable $check ) {
        $state = $check();
        if ( is_wp_error( $state ) ) {
            return $state;
        }

        return hash_equals( $payload['state'], self::hash( (array) $state ) )
            ? null
            : new WP_Error( 'booking_changed', __( 'The booking changed after the preview. Nothing was saved. Ask for a new preview.', 'timetics' ) );
    }

    /**
     * Claim an operation. INSERT IGNORE because add_option() overwrites on a duplicate.
     *
     * @param   array  $payload  Token payload.
     *
     * @return  bool|WP_Error  False when this operation is already claimed, an error when
     *                         another preview of the same change is still open.
     */
    public static function claim( array $payload ) {
        $row = array(
            'state'      => 'claimed',
            'op'         => $payload['op'],
            'tool'       => $payload['tool'],
            'user'       => $payload['user'],
            'booking'    => $payload['booking'],
            'change'     => $payload['change'],
            'claimed_at' => time(),
        );

        if ( ! self::insert_row( self::option_name( $payload['op'] ), (string) wp_json_encode( $row ) ) ) {
            return false;
        }

        $open = self::open_key( $payload['tool'], $payload['user'], $payload['booking'], $payload['change'] );
        if ( ! self::insert_row( $open, $payload['op'] ) ) {
            self::delete_row( self::option_name( $payload['op'] ) );

            return self::in_progress();
        }

        return true;
    }

    /**
     * Whether a new preview of this change must be refused, because an earlier one has
     * no known outcome yet.
     *
     * @param   string  $tool     Tool name.
     * @param   int     $user     Who is asking.
     * @param   int     $booking  Booking changed, 0 when creating.
     * @param   array   $change   The change.
     *
     * @return  WP_Error|null
     */
    public static function open_operation( string $tool, int $user, int $booking, array $change ) {
        $operation = self::read_value( self::open_key( $tool, $user, $booking, self::hash( $change ) ) );
        $row       = $operation ? self::read_row( $operation ) : null;

        if ( ! $row || 'claimed' !== $row['state'] ) {
            return null;
        }

        $result = self::resolve_unfinished( $row );

        return is_wp_error( $result ) ? $result : null;
    }

    /**
     * Work out what happened to a claim that has no result yet. Saving has no
     * transaction here, so a dead write may have left part of its work: the answer is
     * never "nothing was saved", only a warning to check, given once.
     *
     * @param   array  $row  Saved claim.
     *
     * @return  array|WP_Error  The finished row, or the state as an error.
     */
    public static function resolve_unfinished( array $row ) {
        global $wpdb;

        if ( time() - $row['claimed_at'] < self::CLAIM_GRACE ) {
            return self::in_progress();
        }

        // Before MySQL 5.7.5 a second lock silently dropped the first, so a free lock proves nothing.
        if ( ! self::can_prove_write_ended( (string) $wpdb->db_server_info() ) ) {
            return self::unknown( $row );
        }

        return self::holding_lock(
            $row['op'],
            function () use ( $row ) {
                $row = self::read_row( $row['op'] );

                if ( ! $row ) {
                    return self::in_progress();
                }
                if ( 'claimed' !== $row['state'] ) {
                    return $row;
                }

                $row['found'] = self::work_of( $row['op'] );

                // The writer is gone, so the warning is said once. A new preview then shows
                // the bookings as they are now, and a person approves from that.
                return self::unknown( self::finish( $row, 'unknown' ) );
            }
        );
    }

    /**
     * Whether a free lock proves the write ended.
     *
     * @param   string  $server_info  Database server version text.
     *
     * @return  bool
     */
    public static function can_prove_write_ended( string $server_info ): bool {
        if ( false !== stripos( $server_info, 'mariadb' ) ) {
            return true;
        }

        return version_compare( (string) strtok( $server_info, '-' ), '5.7.5', '>=' );
    }

    /**
     * Remove rows with a known outcome older than a day. Open rows stay, or the
     * protection against a duplicate would expire.
     *
     * @return  void
     */
    public static function cleanup() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rows are written directly too.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", $wpdb->esc_like( 'timetics_mcp_op_' ) . '%', self::CLEANUP_BATCH ) );

        foreach ( $rows as $row ) {
            $saved = json_decode( $row->option_value, true );
            if ( isset( $saved['resolved_at'] ) && (int) $saved['resolved_at'] < time() - self::RESOLVED_KEEP ) {
                self::delete_row( $row->option_name );
            }
        }
    }

    /**
     * Option name of an operation.
     *
     * @param   string  $operation  Operation id.
     *
     * @return  string
     */
    public static function option_name( string $operation ): string {
        return 'timetics_mcp_op_' . $operation;
    }

    /**
     * Name of the lock a write holds while it runs. Under MySQL's 64 character limit.
     *
     * @param   string  $operation  Operation id.
     *
     * @return  string
     */
    public static function lock_name( string $operation ): string {
        return 'timetics_op_' . md5( $operation );
    }

    /**
     * Bookings a write marked as its own.
     *
     * @param   string  $operation  Operation id.
     *
     * @return  int[]
     */
    public static function work_of( string $operation ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery -- Must read what is committed now.
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id", self::OPERATION_META, $operation ) ) );
    }

    /**
     * Run something while holding an operation's lock. Writer and resolver both need it,
     * and each reads the row inside, so one operation is only ever decided by one of them.
     *
     * @param   string    $operation  Operation id.
     * @param   callable  $work       What to run.
     *
     * @return  mixed  What it returned, or a WP_Error when the lock is not available.
     */
    private static function holding_lock( string $operation, callable $work ) {
        global $wpdb;

        $lock = self::lock_name( $operation );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL lock has no WordPress API.
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );

        if ( '0' === (string) $got ) {
            return self::in_progress();
        }
        if ( '1' !== (string) $got ) {
            return new WP_Error( 'lock_unavailable', __( 'This change could not be checked safely right now. Try again.', 'timetics' ) );
        }

        try {
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL lock has no WordPress API.
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    /**
     * Run the write of a claimed operation, marking every booking it saves. A crash
     * leaves the claim open on purpose.
     *
     * @param   array     $payload  Token payload.
     * @param   callable  $work     The write.
     *
     * @return  array|WP_Error
     */
    private static function run_claimed( array $payload, callable $work ) {
        global $wpdb;

        // wpdb reconnects without saying so, and a new connection no longer holds the lock.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A MySQL lock has no WordPress API.
        $holds = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name( $payload['op'] ) ) );
        if ( '1' !== (string) $holds ) {
            return self::in_progress();
        }

        // Priority 1, before listeners that may fail. Set here and never read from a request.
        $mark = function ( $booking_id ) use ( $payload ) {
            update_post_meta( (int) $booking_id, self::OPERATION_META, $payload['op'] );
        };
        add_action( 'timetics_after_booking_create', $mark, 1 );
        add_action( 'timetics_after_booking_schedule', $mark, 1 );

        try {
            $result = $work( $payload );
        } finally {
            remove_action( 'timetics_after_booking_create', $mark, 1 );
            remove_action( 'timetics_after_booking_schedule', $mark, 1 );
        }

        $row = (array) self::read_row( $payload['op'] );

        if ( is_wp_error( $result ) ) {
            $row['error'] = array(
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            );
            self::finish( $row, 'failed' );

            return $result;
        }

        $row['result'] = self::without_contact( $result );
        self::finish( $row, 'done' );

        return $result;
    }

    /**
     * Answer a call whose operation already has a row. A finished write comes first, with
     * no state check, because the write itself changed the booking.
     *
     * @param   array  $row   Saved row.
     * @param   int    $user  Who is calling.
     *
     * @return  array|WP_Error
     */
    private static function answer_from( array $row, int $user ) {
        if ( 'claimed' === ( $row['state'] ?? '' ) ) {
            $row = self::resolve_unfinished( $row );
            if ( is_wp_error( $row ) ) {
                return $row;
            }
        }

        switch ( $row['state'] ?? '' ) {
            case 'done':
                $ids = array_map( 'intval', (array) ( $row['result']['booking_ids'] ?? array() ) );
                foreach ( $ids as $id ) {
                    if ( ! Mcp_Access::can_see_booking( $user, $id ) ) {
                        return new WP_Error( 'not_found', __( 'No such booking.', 'timetics' ) );
                    }
                }

                // The kept result has no contact details, so the rows are read again.
                if ( isset( $row['result']['bookings'] ) ) {
                    $row['result']['bookings'] = Booking_Reader::rows( $ids, $user );
                }

                return $row['result'];
            case 'failed':
                return new WP_Error( $row['error']['code'], $row['error']['message'] );
            case 'unknown':
                return self::unknown( $row );
        }

        return self::in_progress();
    }

    /**
     * Save an outcome and let new previews of this change through.
     *
     * @param   array   $row    Saved row.
     * @param   string  $state  done, failed or unknown.
     *
     * @return  array  The row as saved.
     */
    private static function finish( array $row, string $state ): array {
        global $wpdb;

        $row['state']       = $state;
        $row['resolved_at'] = time();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- These rows are read directly, so no cache holds them.
        $wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $row ) ), array( 'option_name' => self::option_name( $row['op'] ) ) );
        self::delete_row( self::open_key( $row['tool'], $row['user'], $row['booking'], $row['change'] ) );

        return $row;
    }

    /**
     * Drop contact details from a result before keeping it.
     *
     * @param   array  $data  Result.
     *
     * @return  array
     */
    private static function without_contact( array $data ): array {
        foreach ( array( 'customer', 'email', 'email_to', 'phone', 'note' ) as $private ) {
            unset( $data[ $private ] );
        }

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $data[ $key ] = self::without_contact( $value );
            }
        }

        return $data;
    }

    /**
     * Option name that marks one user's change as having an open operation.
     *
     * @param   string  $tool         Tool name.
     * @param   int     $user         User.
     * @param   int     $booking      Booking, 0 when creating.
     * @param   string  $change_hash  Hash of the change.
     *
     * @return  string
     */
    private static function open_key( string $tool, int $user, int $booking, string $change_hash ): string {
        return 'timetics_mcp_open_' . md5( $tool . '|' . $user . '|' . $booking . '|' . $change_hash );
    }

    /**
     * Read an operation's row.
     *
     * @param   string  $operation  Operation id.
     *
     * @return  array|null
     */
    private static function read_row( string $operation ) {
        $value = self::read_value( self::option_name( $operation ) );

        return null === $value ? null : json_decode( $value, true );
    }

    /**
     * Read one option straight from the table.
     *
     * @param   string  $name  Option name.
     *
     * @return  string|null
     */
    private static function read_value( string $name ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must see the row as it is now.
        return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    }

    /**
     * Insert a row unless it exists. Only one concurrent caller gets true.
     *
     * @param   string  $name   Option name.
     * @param   string  $value  Value.
     *
     * @return  bool
     */
    private static function insert_row( string $name, string $value ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic insert is the whole point.
        return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )", $name, $value ) );
    }

    /**
     * Delete one row.
     *
     * @param   string  $name  Option name.
     *
     * @return  void
     */
    private static function delete_row( string $name ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- These rows are read directly, so no cache holds them.
        $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
    }

    /**
     * Hash of an array whatever order its keys are in.
     *
     * @param   array  $data  Data.
     *
     * @return  string
     */
    private static function hash( array $data ): string {
        return hash( 'sha256', (string) wp_json_encode( self::sorted( $data ) ) );
    }

    /**
     * Sort keys at every level.
     *
     * @param   array  $data  Data.
     *
     * @return  array
     */
    private static function sorted( array $data ): array {
        ksort( $data );

        return array_map(
            function ( $value ) {
                return is_array( $value ) ? self::sorted( $value ) : $value;
            },
            $data
        );
    }

    /**
     * Sign a payload.
     *
     * @param   array  $payload  Payload.
     *
     * @return  string
     */
    private static function sign( array $payload ): string {
        $body = bin2hex( (string) wp_json_encode( $payload ) );

        return $body . '.' . self::signature( $body );
    }

    /**
     * Signature of a token body.
     *
     * @param   string  $body  Token body.
     *
     * @return  string
     */
    private static function signature( string $body ): string {
        return hash_hmac( 'sha256', $body, wp_salt( 'auth' ) . 'timetics_mcp' );
    }

    /**
     * The error for a wrong or expired token.
     *
     * @return  WP_Error
     */
    private static function invalid(): WP_Error {
        return new WP_Error( 'confirm_invalid', __( 'That confirmation is wrong, expired or for another change. Ask for a new preview.', 'timetics' ) );
    }

    /**
     * The error for a write that may still be running.
     *
     * @return  WP_Error
     */
    private static function in_progress(): WP_Error {
        return new WP_Error( 'operation_in_progress', __( 'This change may still be running. Do not repeat it, and check again in a few minutes.', 'timetics' ) );
    }

    /**
     * The error for a write whose outcome cannot be told.
     *
     * @param   array  $row  Saved claim.
     *
     * @return  WP_Error
     */
    private static function unknown( array $row ): WP_Error {
        $booking = (int) $row['booking'];
        $found   = array_map( 'intval', (array) ( $row['found'] ?? array() ) );
        $tools   = array( Booking_Writer::CREATE_TOOL, Booking_Writer::UPDATE_TOOL, Booking_Writer::RESCHEDULE_TOOL, Booking_Writer::CANCEL_TOOL );

        // Other tools keep the id of a meeting, customer or coupon here, not a booking.
        if ( ! in_array( $row['tool'] ?? '', $tools, true ) ) {
            $message = __( 'The earlier attempt may or may not have saved this change. Check it before trying again.', 'timetics' );
        } elseif ( $booking ) {
            /* translators: %d: booking id */
            $message = sprintf( __( 'The earlier attempt may or may not have changed booking %d. Check it before trying again.', 'timetics' ), $booking );
        } elseif ( $found ) {
            /* translators: %s: booking ids */
            $message = sprintf( __( 'The earlier attempt saved booking %s, maybe not completely. Check it before trying again.', 'timetics' ), implode( ', ', $found ) );
        } else {
            $message = __( 'The earlier attempt may have saved a booking. Check list-bookings before trying again.', 'timetics' );
        }

        return new WP_Error( 'outcome_unknown', $message );
    }
}
