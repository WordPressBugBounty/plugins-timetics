<?php
/**
 * Customer resolver
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, for a new booking, its customer row, its WordPress customer and its owner.
 *
 * Anyone can type any email on the public form, so a matching email is never
 * proof of identity: it never links a row, sets an owner, or changes an account.
 * Without the booking lock, the unique email key and wp_insert_user() still stop duplicates.
 */
class Customer_Resolver {

    /**
     * Resolve the customer for a new booking.
     *
     * @param array $contact            Validated form data: email, first_name, last_name, phone.
     * @param bool  $staff_action       Whether staff or an admin is booking on someone's behalf.
     * @param bool  $may_create_account Whether a new email may get an account. False on an edit:
     *                                  nobody would get its set-password link.
     *
     * @return array|WP_Error record_id, wp_user_id, owner_user, owner_source.
     */
    public static function resolve( array $contact, $staff_action, $may_create_account = true ) {
        $record_id = Customer_Store::find_or_create( $contact );

        if ( is_wp_error( $record_id ) ) {
            return $record_id;
        }

        // A contact the admin removed comes back to the Customers list when they book again.
        Customer_Store::restore( $record_id );

        $result = array(
            'record_id'    => $record_id,
            'wp_user_id'   => 0,
            'owner_user'   => 0,
            'owner_source' => '',
        );

        $email      = Customer_Store::normalize_email( $contact['email'] );
        $current_id = get_current_user_id();
        $current    = $current_id ? get_userdata( $current_id ) : false;

        // The login session is the proof: their own account email means they book for
        // themselves, staff included. The email is compared here, never taken from the request.
        $is_self = $current && strtolower( $current->user_email ) === $email;

        if ( $is_self || ( $current_id && ! $staff_action ) ) {
            $result['owner_user']   = $current_id;
            $result['owner_source'] = 'authenticated';

            // A stranger may have made the row with this email first; on the owner's first
            // link it takes their profile, never the form, so the stranger's details go.
            if ( $is_self && Customer_Store::link_user( $record_id, $current_id ) ) {
                Customer_Store::update_contact(
                    $record_id,
                    array(
                        'first_name' => (string) $current->first_name,
                        'last_name'  => (string) $current->last_name,
                        'phone'      => (string) get_user_meta( $current_id, '_timetics_customer_phone', true ),
                    )
                );
            }

            if ( $is_self ) {
                $result['wp_user_id'] = $current_id;
            }

            return $result;
        }

        if ( email_exists( $email ) ) {
            return $result;
        }

        $user_id = $may_create_account ? self::maybe_create_account( $contact, $email ) : 0;

        if ( $user_id ) {
            Customer_Store::link_user( $record_id, $user_id );

            $result['wp_user_id']   = $user_id;
            $result['owner_user']   = $user_id;
            $result['owner_source'] = 'created_account';
        }

        return $result;
    }

    /**
     * Create a WordPress account for a new email, when the admin allows it.
     *
     * Bookings call this under Booking_Lock, so two at once cannot both make an account for one email.
     *
     * @param array  $contact Form data.
     * @param string $email   Normalized email that has no account yet.
     *
     * @return int New user id, or 0.
     */
    private static function maybe_create_account( array $contact, $email ) {
        $role = timetics_guest_wp_user_role();

        // An unsafe role leaves the guest as a row; it must never hand out power.
        // timetics-customer is added to every new account, so it must be safe too.
        if ( ! timetics_guest_wp_user_enabled() || ! $role || ! timetics_is_safe_customer_role( 'timetics-customer' ) ) {
            return 0;
        }

        $user_id = wp_insert_user(
            array(
                'user_login' => $email,
                'user_email' => $email,
                'user_pass'  => wp_generate_password(),
                'first_name' => isset( $contact['first_name'] ) ? sanitize_text_field( $contact['first_name'] ) : '',
                'last_name'  => isset( $contact['last_name'] ) ? sanitize_text_field( $contact['last_name'] ) : '',
                'role'       => $role,
            )
        );

        if ( is_wp_error( $user_id ) ) {
            return 0;
        }

        // Customer lists and reports find accounts by this role, whatever role the admin picked.
        if ( 'timetics-customer' !== $role ) {
            ( new \WP_User( $user_id ) )->add_role( 'timetics-customer' );
        }

        // A hint for a future cleanup tool. Not permission to delete: the user may own other data later.
        update_user_meta( $user_id, '_timetics_guest_created', '1' );

        return (int) $user_id;
    }
}
