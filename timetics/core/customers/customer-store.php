<?php
/**
 * Customer store
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Guest contacts, one row per normalized email.
 *
 * A row is contact details only. Its `wp_user_id` says which account the
 * contact belongs to; it never says who owns a booking (that lives on the booking).
 */
class Customer_Store {

    const DB_VERSION     = '2';
    const VERSION_OPTION = 'timetics_customers_db_version';

    /**
     * Table name
     *
     * @return string
     */
    public static function table() {
        global $wpdb;

        return $wpdb->prefix . 'timetics_customers';
    }

    /**
     * Create or update the table, then store the schema version.
     *
     * @return bool Whether the table exists now.
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        // dbDelta needs this exact layout: two spaces after PRIMARY KEY, one field per line.
        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                wp_user_id bigint(20) unsigned DEFAULT NULL,
                email varchar(191) NOT NULL,
                first_name varchar(191) NOT NULL DEFAULT '',
                last_name varchar(191) NOT NULL DEFAULT '',
                phone varchar(64) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                removed_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email (email),
                UNIQUE KEY wp_user_id (wp_user_id)
            ) {$collate};"
        );

        // A failed CREATE (no database rights, say) is tried again on the next load, not marked done.
        if ( ! self::is_ready() ) {
            add_action( 'admin_notices', array( __CLASS__, 'install_notice' ) );

            return false;
        }

        update_option( self::VERSION_OPTION, self::DB_VERSION );

        return true;
    }

    /**
     * Whether the table and its newest column exist.
     *
     * @return bool
     */
    public static function is_ready() {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check; a cached answer would hide a missing table.
        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
        return (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'removed_at'" );
    }

    /**
     * Hooks that keep rows in step with WordPress users.
     *
     * @return void
     */
    public static function init() {
        add_action( 'deleted_user', array( __CLASS__, 'unlink_user' ) );
        add_action( 'admin_init', array( Customer_Backfill::class, 'run' ) );
    }

    /**
     * A deleted account leaves its row as a guest contact, so bookings keep their customer.
     *
     * @param int $user_id Deleted user id.
     *
     * @return void
     */
    public static function unlink_user( $user_id ) {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table.
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET wp_user_id = NULL, updated_at = %s WHERE wp_user_id = %d", current_time( 'mysql', true ), absint( $user_id ) ) );
    }

    /**
     * Tell the admin why guest bookings fail: each one needs a row in the missing table.
     *
     * @return void
     */
    public static function install_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'WPTimetics could not create its customers table, so bookings cannot be saved. Please check that the database user can create tables.', 'timetics' )
        );
    }

    /**
     * Run install() when the stored schema is older. wp.org updates skip activation.
     *
     * @return bool Whether install() ran.
     */
    public static function maybe_upgrade() {
        if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::DB_VERSION, '>=' ) ) {
            return false;
        }

        self::install();

        return true;
    }

    /**
     * Get a row by id
     *
     * @param int $id Row id.
     *
     * @return array|null
     */
    public static function get( $id ) {
        return self::find_by( 'id', absint( $id ) );
    }

    /**
     * Get a row by email, any case
     *
     * @param string $email Email.
     *
     * @return array|null
     */
    public static function find_by_email( $email ) {
        $email = self::normalize_email( $email );

        return $email ? self::find_by( 'email', $email ) : null;
    }

    /**
     * Get the row linked to a WordPress user
     *
     * @param int $user_id User id.
     *
     * @return array|null
     */
    public static function find_by_user( $user_id ) {
        $user_id = absint( $user_id );

        return $user_id ? self::find_by( 'wp_user_id', $user_id ) : null;
    }

    /**
     * Get the row for an email, creating it when missing.
     *
     * An existing row is returned untouched: a public booking must not rewrite
     * a stored contact. Never creates a WordPress user.
     *
     * @param array $contact email, first_name, last_name, phone.
     *
     * @return int|WP_Error Row id.
     */
    public static function find_or_create( array $contact ) {
        global $wpdb;

        $email = self::normalize_email( isset( $contact['email'] ) ? $contact['email'] : '' );

        if ( ! $email ) {
            return new WP_Error( 'timetics_invalid_customer_email', __( 'A valid email is required.', 'timetics' ), array( 'status' => 400 ) );
        }

        $row = self::find_by_email( $email );

        if ( $row ) {
            return (int) $row['id'];
        }

        $now = current_time( 'mysql', true );

        // A same-email race fails on the unique key; that is handled below, not an error to log.
        $suppressed = $wpdb->suppress_errors( true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'email'      => $email,
                'first_name' => self::text( $contact, 'first_name' ),
                'last_name'  => self::text( $contact, 'last_name' ),
                'phone'      => self::text( $contact, 'phone' ),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $wpdb->suppress_errors( $suppressed );

        if ( $inserted ) {
            return (int) $wpdb->insert_id;
        }

        // Another request may have saved the same email between our read and insert.
        $row = self::find_by_email( $email );

        if ( $row ) {
            return (int) $row['id'];
        }

        return new WP_Error( 'timetics_customer_not_saved', __( 'The customer could not be saved.', 'timetics' ), array( 'status' => 500 ) );
    }

    /**
     * Link a row to a WordPress user, only when it has no link yet.
     *
     * @param int $id      Row id.
     * @param int $user_id User id.
     *
     * @return bool Whether the link was set.
     */
    public static function link_user( $id, $user_id ) {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; only an empty link may be set.
        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET wp_user_id = %d, updated_at = %s WHERE id = %d AND wp_user_id IS NULL", absint( $user_id ), current_time( 'mysql', true ), absint( $id ) ) );

        return 1 === $updated;
    }

    /**
     * Change a row's name or phone. Email and account link are not editable here.
     *
     * @param int   $id     Row id.
     * @param array $fields first_name, last_name, phone.
     *
     * @return bool
     */
    public static function update_contact( $id, array $fields ) {
        global $wpdb;

        $data = array_intersect_key( $fields, array_flip( array( 'first_name', 'last_name', 'phone' ) ) );

        foreach ( $data as $key => $value ) {
            $data[ $key ] = self::text( $data, $key );
        }

        $data['updated_at'] = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
        return false !== $wpdb->update( self::table(), $data, array( 'id' => absint( $id ) ) );
    }

    /**
     * The row for an account, made from its profile when missing.
     *
     * An unlinked row with the account's email may have been made by a stranger, so it is
     * neither linked nor duplicated: only the owner booking with their own email links it.
     *
     * @param \WP_User|int $user User.
     *
     * @return int Row id, or 0 when the account has no row of its own.
     */
    public static function ensure_for_user( $user ) {
        global $wpdb;

        $user = $user instanceof \WP_User ? $user : get_userdata( absint( $user ) );

        if ( ! $user ) {
            return 0;
        }

        $row = self::find_by_user( $user->ID );

        if ( $row ) {
            return (int) $row['id'];
        }

        $email = self::normalize_email( $user->user_email );

        if ( ! $email || self::find_by_email( $email ) ) {
            return 0;
        }

        $now        = current_time( 'mysql', true );
        $registered = '0000-00-00 00:00:00' !== $user->user_registered && $user->user_registered ? $user->user_registered : $now;
        $suppressed = $wpdb->suppress_errors( true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table. The unique keys stop a duplicate from a parallel request.
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'wp_user_id' => $user->ID,
                'email'      => $email,
                'first_name' => sanitize_text_field( (string) $user->first_name ),
                'last_name'  => sanitize_text_field( (string) $user->last_name ),
                'phone'      => sanitize_text_field( (string) get_user_meta( $user->ID, '_timetics_customer_phone', true ) ),
                'created_at' => $registered,
                'updated_at' => $now,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $wpdb->suppress_errors( $suppressed );

        if ( $inserted ) {
            return (int) $wpdb->insert_id;
        }

        $row = self::find_by_user( $user->ID );

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Take a contact out of the Customers list. The row stays: bookings keep pointing at it.
     *
     * @param int $id Row id.
     *
     * @return bool Whether the row exists.
     */
    public static function hide( $id ) {
        return self::set_removed( $id, current_time( 'mysql', true ) );
    }

    /**
     * Bring a hidden contact back, as when they book again.
     *
     * @param int $id Row id.
     *
     * @return bool Whether the row exists.
     */
    public static function restore( $id ) {
        return self::set_removed( $id, null );
    }

    /**
     * Blank a contact for a privacy erase. The row stays, so bookings keep their link to it.
     *
     * @param int $id Row id.
     *
     * @return bool
     */
    public static function erase_contact( $id ) {
        global $wpdb;

        $id  = absint( $id );
        $now = current_time( 'mysql', true );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
        return false !== $wpdb->update(
            self::table(),
            array(
                'first_name' => '',
                'last_name'  => '',
                'phone'      => '',
                'email'      => 'deleted-' . $id . '@example.invalid',
                'wp_user_id' => null,
                'removed_at' => $now,
                'updated_at' => $now,
            ),
            array( 'id' => $id ),
            null,
            array( '%d' )
        );
    }

    /**
     * Visible contacts, newest first.
     *
     * @param array $args search, per_page, paged or offset, ids (null for all, else the only row ids allowed).
     *
     * @return array total, items (rows).
     */
    public static function query( array $args = array() ) {
        global $wpdb;

        $args  = wp_parse_args( $args, array( 'search' => '', 'per_page' => 20, 'paged' => 1, 'ids' => null ) );
        $where = self::where( $args );

        if ( null === $where ) {
            return array( 'total' => 0, 'items' => array() );
        }

        $table    = self::table();
        $per_page = max( 1, absint( $args['per_page'] ) );
        $offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;

        // Preparing the already prepared where clause again is safe: prepare() hides its literal % signs.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; the where clause is prepared by where().
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
        // phpcs:enable

        return array( 'total' => $total, 'items' => $items ? $items : array() );
    }

    /**
     * Count visible contacts, optionally added within a date range.
     *
     * @param array $args from, to (Y-m-d, both days included), ids (as in query()).
     *
     * @return int
     */
    public static function count( array $args = array() ) {
        global $wpdb;

        $where = self::where( $args );

        if ( null === $where ) {
            return 0;
        }

        if ( ! empty( $args['from'] ) ) {
            $where .= $wpdb->prepare( ' AND created_at >= %s', gmdate( 'Y-m-d 00:00:00', strtotime( $args['from'] ) ) );
        }

        if ( ! empty( $args['to'] ) ) {
            $where .= $wpdb->prepare( ' AND created_at <= %s', gmdate( 'Y-m-d 23:59:59', strtotime( $args['to'] ) ) );
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; prepared above.
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
    }

    /**
     * Counted bookings per row, for one page of rows, in two queries.
     *
     * A row counts its own bookings, plus its account's older bookings that have no row.
     *
     * @param array $rows Rows from query().
     *
     * @return array Row id => count.
     */
    public static function booking_counts( array $rows ) {
        global $wpdb;

        $counts = array_fill_keys( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ), 0 );
        $users  = array();

        foreach ( $rows as $row ) {
            if ( ! empty( $row['wp_user_id'] ) ) {
                $users[ (int) $row['wp_user_id'] ] = (int) $row['id'];
            }
        }

        if ( ! $counts ) {
            return $counts;
        }

        $statuses = (array) apply_filters( 'timetics/report/counted_statuses', array( 'approved', 'completed' ) );
        $in_stat  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $in_rows  = implode( ',', array_fill( 0, count( $counts ), '%s' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above.
        $own = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.meta_value AS id, COUNT(DISTINCT p.ID) AS total FROM {$wpdb->postmeta} m
                INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'timetics-booking' AND p.post_status IN ({$in_stat})
                WHERE m.meta_key = '_tt_booking_customer_record' AND m.meta_value IN ({$in_rows}) GROUP BY m.meta_value",
                array_merge( $statuses, array_map( 'strval', array_keys( $counts ) ) )
            ),
            ARRAY_A
        );

        foreach ( $own as $item ) {
            $counts[ (int) $item['id'] ] += (int) $item['total'];
        }

        if ( $users ) {
            $in_users = implode( ',', array_fill( 0, count( $users ), '%s' ) );
            $legacy   = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.meta_value AS id, COUNT(DISTINCT p.ID) AS total FROM {$wpdb->postmeta} m
                    INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'timetics-booking' AND p.post_status IN ({$in_stat})
                    LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = '_tt_booking_customer_record'
                    WHERE m.meta_key = '_tt_booking_customer' AND m.meta_value IN ({$in_users}) AND ( r.meta_value IS NULL OR r.meta_value IN ('', '0') )
                    GROUP BY m.meta_value",
                    array_merge( $statuses, array_map( 'strval', array_keys( $users ) ) )
                ),
                ARRAY_A
            );

            foreach ( $legacy as $item ) {
                $counts[ $users[ (int) $item['id'] ] ] += (int) $item['total'];
            }
        }
        // phpcs:enable

        return $counts;
    }

    /**
     * Rows a staff member may see: the contacts of the given bookings.
     *
     * @param array $booking_ids Bookings the staff member can see.
     *
     * @return array Row ids.
     */
    public static function ids_for_bookings( array $booking_ids ) {
        global $wpdb;

        $booking_ids = array_filter( array_map( 'absint', $booking_ids ) );

        if ( ! $booking_ids ) {
            return array();
        }

        $in    = implode( ',', $booking_ids );
        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Ids are integers.
        $rows = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_tt_booking_customer_record' AND post_id IN ({$in})" );

        // Older bookings have no row id; their account's row stands for them.
        $users = $wpdb->get_col(
            "SELECT t.id FROM {$table} t INNER JOIN {$wpdb->postmeta} m ON m.meta_key = '_tt_booking_customer' AND m.meta_value = t.wp_user_id
            LEFT JOIN {$wpdb->postmeta} r ON r.post_id = m.post_id AND r.meta_key = '_tt_booking_customer_record'
            WHERE m.post_id IN ({$in}) AND ( r.meta_value IS NULL OR r.meta_value IN ('', '0') )"
        );
        // phpcs:enable

        return array_values( array_unique( array_filter( array_map( 'intval', array_merge( $rows, $users ) ) ) ) );
    }

    /**
     * Delete one row. Bookings and WordPress accounts are left alone.
     *
     * @param int $id Row id.
     *
     * @return bool
     */
    public static function delete( $id ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
        return 1 === $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /**
     * Lowercase, trimmed email, or '' when it is not an email.
     *
     * @param string $email Email.
     *
     * @return string
     */
    public static function normalize_email( $email ) {
        $email = strtolower( trim( (string) $email ) );

        // The column holds 191 characters; a longer email would be cut on save and never found again.
        return strlen( $email ) <= 191 && is_email( $email ) ? $email : '';
    }

    /**
     * Prepared where clause for visible rows.
     *
     * @param array $args search, ids.
     *
     * @return string|null Null when the allowed ids are empty: nothing to show.
     */
    private static function where( array $args ) {
        global $wpdb;

        $where = 'removed_at IS NULL';

        if ( isset( $args['ids'] ) && null !== $args['ids'] ) {
            $ids = array_filter( array_map( 'absint', (array) $args['ids'] ) );

            if ( ! $ids ) {
                return null;
            }

            $where .= ' AND id IN (' . implode( ',', $ids ) . ')';
        }

        $search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

        if ( '' !== $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND ( email LIKE %s OR phone LIKE %s OR CONCAT_WS( ' ', first_name, last_name ) LIKE %s )", $like, $like, $like );
        }

        return $where;
    }

    /**
     * Set or clear removed_at
     *
     * @param int         $id   Row id.
     * @param string|null $when Time, or null to bring the row back.
     *
     * @return bool Whether the row exists.
     */
    private static function set_removed( $id, $when ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
        $wpdb->update( self::table(), array( 'removed_at' => $when ), array( 'id' => absint( $id ) ), null, array( '%d' ) );

        return (bool) self::get( $id );
    }

    /**
     * One row by a column
     *
     * @param string $column Column, from this class only.
     * @param mixed  $value  Value.
     *
     * @return array|null
     */
    private static function find_by( $column, $value ) {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; column comes from this class.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s", $value ), ARRAY_A );

        return $row ? $row : null;
    }

    /**
     * Sanitized text field from an array
     *
     * @param array  $data Source.
     * @param string $key  Key.
     *
     * @return string
     */
    private static function text( array $data, $key ) {
        return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
    }
}
