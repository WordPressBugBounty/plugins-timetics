<?php
/**
 * Customer privacy
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use Timetics\Core\Admin\Notification_Flow_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's Export and Erase Personal Data tools, for a customer's email.
 *
 * Erase is partial: past and cancelled bookings lose the customer's details, active
 * upcoming ones keep them so the meeting can happen, and the WordPress account is
 * left to WordPress. Bookings are found by row id, so the row is blanked last.
 */
class Customer_Privacy {

    const PER_PAGE = 50;

    /**
     * Booking meta holding the customer's details, without the `_tt_booking_` prefix.
     */
    const PERSONAL_KEYS = array(
        'customer_lname',
        'customer_phone',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address_1',
        'address_2',
        'city',
        'state',
        'post_code',
        'country',
        'description',
        'custom_form_data',
        'whatsapp_number',
        'whatsapp_opt_in',
        'whatsapp_opt_in_at',
        'whatsapp_opt_in_source',
        'whatsapp_opt_out_at',
        'owner_source',
    );

    /**
     * Statuses whose meeting will not happen, whatever its date.
     */
    const DONE_STATUSES = array( 'cancel', 'cancelled', 'failed', 'trash', 'completed' );

    /**
     * Register the exporter and eraser.
     *
     * @return void
     */
    public static function init() {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
    }

    /**
     * Add the exporter
     *
     * @param array $exporters Exporters.
     *
     * @return array
     */
    public static function register_exporter( $exporters ) {
        $exporters['timetics'] = array(
            'exporter_friendly_name' => __( 'WPTimetics customer and bookings', 'timetics' ),
            'callback'               => array( __CLASS__, 'export' ),
        );

        return $exporters;
    }

    /**
     * Add the eraser
     *
     * @param array $erasers Erasers.
     *
     * @return array
     */
    public static function register_eraser( $erasers ) {
        $erasers['timetics'] = array(
            'eraser_friendly_name' => __( 'WPTimetics customer and bookings', 'timetics' ),
            'callback'             => array( __CLASS__, 'erase' ),
        );

        return $erasers;
    }

    /**
     * Export the contact row and one page of bookings.
     *
     * @param string $email Email.
     * @param int    $page  Page, from 1.
     *
     * @return array data, done.
     */
    public static function export( $email, $page = 1 ) {
        $email = Customer_Store::normalize_email( $email );
        $row   = $email ? Customer_Store::find_by_email( $email ) : null;
        $data  = array();

        if ( $row && 1 === (int) $page ) {
            $data[] = array(
                'group_id'    => 'timetics-customer',
                'group_label' => __( 'WPTimetics customer', 'timetics' ),
                'item_id'     => 'timetics-customer-' . $row['id'],
                'data'        => array(
                    array( 'name' => __( 'First name', 'timetics' ), 'value' => $row['first_name'] ),
                    array( 'name' => __( 'Last name', 'timetics' ), 'value' => $row['last_name'] ),
                    array( 'name' => __( 'Email', 'timetics' ), 'value' => $row['email'] ),
                    array( 'name' => __( 'Phone', 'timetics' ), 'value' => $row['phone'] ),
                ),
            );
        }

        $ids = $email ? self::booking_ids( $email, $row, 'all', ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE ) : array();

        foreach ( $ids as $id ) {
            $fields = array(
                __( 'Meeting', 'timetics' )    => get_post_meta( $id, '_tt_booking_appointment_name', true ),
                __( 'Date', 'timetics' )       => get_post_meta( $id, '_tt_booking_start_date', true ) . ' ' . get_post_meta( $id, '_tt_booking_start_time', true ),
                __( 'Status', 'timetics' )     => get_post_status( $id ),
                __( 'First name', 'timetics' ) => get_post_meta( $id, '_tt_booking_customer_fname', true ),
                __( 'Last name', 'timetics' )  => get_post_meta( $id, '_tt_booking_customer_lname', true ),
                __( 'Email', 'timetics' )      => get_post_meta( $id, '_tt_booking_customer_email', true ),
                __( 'Phone', 'timetics' )      => get_post_meta( $id, '_tt_booking_customer_phone', true ),
                __( 'WhatsApp', 'timetics' )   => get_post_meta( $id, '_tt_booking_whatsapp_number', true ),
                __( 'Notes', 'timetics' )      => get_post_meta( $id, '_tt_booking_description', true ),
            );

            $item = array();

            foreach ( $fields as $name => $value ) {
                if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                    $item[] = array( 'name' => $name, 'value' => (string) $value );
                }
            }

            $data[] = array(
                'group_id'    => 'timetics-bookings',
                'group_label' => __( 'WPTimetics bookings', 'timetics' ),
                'item_id'     => 'timetics-booking-' . $id,
                'data'        => $item,
            );
        }

        return array(
            'data' => $data,
            'done' => count( $ids ) < self::PER_PAGE,
        );
    }

    /**
     * Erase one batch. Bookings first, the contact row once none are left to erase.
     *
     * @param string $email Email.
     * @param int    $page  Page; unused, erased bookings drop out of the next batch.
     *
     * @return array items_removed, items_retained, messages, done.
     */
    public static function erase( $email, $page = 1 ) {
        $email  = Customer_Store::normalize_email( $email );
        $result = array(
            'items_removed'  => false,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => true,
        );

        if ( ! $email ) {
            return $result;
        }

        $row = Customer_Store::find_by_email( $email );
        $ids = self::booking_ids( $email, $row, 'erasable' );

        foreach ( $ids as $id ) {
            self::erase_booking( $id, $row );
        }

        $result['items_removed'] = (bool) $ids;

        if ( count( $ids ) === self::PER_PAGE ) {
            $result['done'] = false;

            return $result;
        }

        $kept = count( self::booking_ids( $email, $row, 'kept', 0, PHP_INT_MAX ) );

        if ( $kept ) {
            $result['items_retained'] = true;
            $result['messages'][]     = sprintf(
                /* translators: %d: number of upcoming bookings */
                _n(
                    'Older contact details were erased. Contact details were kept on %d upcoming booking. Run the erase again after it finishes or is cancelled.',
                    'Older contact details were erased. Contact details were kept on %d upcoming bookings. Run the erase again after those bookings finish or are cancelled.',
                    $kept,
                    'timetics'
                ),
                $kept
            );
        }

        if ( $row ) {
            Customer_Store::erase_contact( $row['id'] );
            $result['items_removed'] = true;
        }

        if ( email_exists( $email ) ) {
            $result['messages'][] = __( 'WPTimetics did not change the WordPress account with this email.', 'timetics' );
        }

        $result['messages'][] = __( 'Calendar events already sent to Google Calendar or other services were not changed.', 'timetics' );

        return $result;
    }

    /**
     * Clear one booking's customer details. Date, meeting and amount stay.
     *
     * @param int        $id  Booking id.
     * @param array|null $row The customer's row.
     *
     * @return void
     */
    private static function erase_booking( $id, $row ) {
        foreach ( self::PERSONAL_KEYS as $key ) {
            delete_post_meta( $id, '_tt_booking_' . $key );
        }

        // A placeholder copy: an empty one would make an older booking read its account again.
        update_post_meta( $id, '_tt_booking_customer_fname', __( 'Deleted customer', 'timetics' ) );
        update_post_meta( $id, '_tt_booking_customer_email', 'deleted-' . ( $row ? $row['id'] : 'b' . $id ) . '@example.invalid' );
        update_post_meta( $id, '_tt_booking_customer', 0 );
        update_post_meta( $id, '_tt_booking_owner_user', 0 );
        update_post_meta( $id, '_tt_booking_erased', current_time( 'mysql', true ) );

        if ( $row ) {
            update_post_meta( $id, '_tt_booking_customer_record', (int) $row['id'] );
        }

        // Drops queued notifications, whose stored data still holds the old details.
        Notification_Flow_Guard::purge( $id );
    }

    /**
     * Bookings of this customer: their typed email, their row, or their account's older bookings.
     *
     * @param string     $email  Normalized email.
     * @param array|null $row    The customer's row.
     * @param string     $which  all, erasable (not erased, meeting done), or kept (not erased, upcoming).
     * @param int        $offset Offset.
     * @param int        $limit  Limit.
     *
     * @return array Booking ids.
     */
    private static function booking_ids( $email, $row, $which, $offset = 0, $limit = self::PER_PAGE ) {
        global $wpdb;

        $match = $wpdb->prepare( "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} e WHERE e.post_id = p.ID AND e.meta_key = '_tt_booking_customer_email' AND e.meta_value = %s )", $email );

        if ( $row ) {
            $match .= $wpdb->prepare( " OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = p.ID AND r.meta_key = '_tt_booking_customer_record' AND r.meta_value = %s )", (string) $row['id'] );
        }

        $user = get_user_by( 'email', $email );

        if ( $user ) {
            // An older booking has no typed copy; it is the account's.
            $match .= $wpdb->prepare(
                " OR ( EXISTS ( SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id = p.ID AND c.meta_key = '_tt_booking_customer' AND c.meta_value = %s )
                AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} n WHERE n.post_id = p.ID AND n.meta_key = '_tt_booking_customer_email' AND n.meta_value <> '' ) )",
                (string) $user->ID
            );
        }

        $where = "p.post_type = 'timetics-booking' AND ( {$match} )";

        if ( 'all' !== $which ) {
            $done   = "'" . implode( "','", self::DONE_STATUSES ) . "'";
            $today  = current_time( 'Y-m-d' );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $done is this class's constant list.
            $past   = $wpdb->prepare( "( p.post_status IN ( {$done} ) OR sd.meta_value IS NULL OR sd.meta_value < %s )", $today );
            $where .= " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} x WHERE x.post_id = p.ID AND x.meta_key = '_tt_booking_erased' )";
            $where .= 'erasable' === $which ? " AND {$past}" : " AND NOT {$past}";
        }

        // Preparing the already prepared parts again is safe: prepare() hides their literal % signs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Each part is prepared above.
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = '_tt_booking_start_date' WHERE {$where} ORDER BY p.ID ASC LIMIT %d OFFSET %d", $limit, $offset ) ) );
    }
}
