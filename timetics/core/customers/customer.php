<?php
/**
 * Cutomer Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Customers;

use Timetics\Core\Bookings\Booking;

/**
 * Customer class
 *
 * @since 1.0.0
 */
class Customer {
    /**
     * Store customer id
     *
     * @var integer
     */
    protected $id;

    /**
     * Store meta prefix
     *
     * @var string
     */
    protected $meta_prefix = '_timetics_customer_';

    /**
     * Store error
     *
     * @var Object
     */
    public $error;

    /**
     * Guest row when this customer comes from the customer table
     *
     * @var array|null
     */
    protected $record = null;

    /**
     * Customer data
     *
     * @var array
     */
    protected $data = [
        'image'      => '',
        'first_name' => '',
        'last_name'  => '',
        'email'      => '',
        'phone'      => '',
		'password'   => '',
        'quantity'   => []
    ];

    /**
     * Customer constructor
     *
     * @return void
     */
    public function __construct( $customer = 0 ) {
        if ( $customer instanceof self ) {
            $this->set_id( $customer->get_id() );
        } elseif ( ! empty( $customer->ID ) ) {
            $this->set_id( $customer->ID );
        } elseif ( is_numeric( $customer ) && $customer > 0 ) {
            $this->set_id( $customer );
        }
    }

    /**
     * Customer from a guest row
     *
     * @param int $record_id Row id in the customer table.
     *
     * @return self
     */
    public static function from_record( $record_id ) {
        $customer = new self();
        $row      = Customer_Store::get( $record_id );

        $customer->record = $row ? $row : array( 'id' => 0 );

        return $customer;
    }

    /**
     * The customer as this booking knows them: the details typed for it.
     *
     * Older bookings kept no copy, so they get their WordPress customer.
     * A guest copy reports id 0, so callers cannot act on a matched account.
     *
     * @param Booking $booking Booking.
     *
     * @return self
     */
    public static function from_booking( Booking $booking ) {
        if ( $booking->is_legacy_customer() ) {
            return new self( $booking->get_customer_id() );
        }

        $snapshot = $booking->get_customer_snapshot();
        $customer = new self();

        $customer->record = array(
            'id'         => $booking->get_customer_record_id(),
            'wp_user_id' => null,
            'email'      => $snapshot['email'],
            'first_name' => $snapshot['first_name'],
            'last_name'  => $snapshot['last_name'],
            'phone'      => $snapshot['phone'],
        );

        return $customer;
    }

    /**
     * Whether this customer is a guest row
     *
     * @return bool
     */
    public function is_guest() {
        return null !== $this->record;
    }

    /**
     * Guest row id
     *
     * @return int 0 for a WordPress customer.
     */
    public function get_record_id() {
        return $this->is_guest() ? absint( $this->record['id'] ) : 0;
    }

    /**
     * WordPress account the guest row is linked to
     *
     * @return int
     */
    public function get_wp_user_id() {
        if ( $this->is_guest() ) {
            return empty( $this->record['wp_user_id'] ) ? 0 : absint( $this->record['wp_user_id'] );
        }

        return absint( $this->id );
    }

    /**
     * Get customer id
     *
     * A guest returns 0 even when linked: old callers pass this id to WordPress
     * user functions, and a link is not permission to act on that account.
     *
     * @return integer
     */
    public function get_id() {
        return $this->is_guest() ? 0 : $this->id;
    }

    /**
     * Get first name
     *
     * @return  string
     */
    public function get_first_name() {
        if ( $this->is_guest() ) {
            return $this->record_field( 'first_name' );
        }

		return get_user_meta( $this->id, 'first_name', true );
    }

    /**
     * Get last name
     *
     * @return  string
     */
    public function get_last_name() {
        if ( $this->is_guest() ) {
            return $this->record_field( 'last_name' );
        }

		return get_user_meta( $this->id, 'last_name', true );
    }

    /**
     * A field of the guest row
     *
     * @param string $field Column.
     *
     * @return string
     */
    protected function record_field( $field ) {
        return isset( $this->record[ $field ] ) ? (string) $this->record[ $field ] : '';
    }

    /**
     * Get customer display name
     *
     * @return string
     */
    public function get_display_name() {
        return trim( $this->get_first_name() . ' ' . $this->get_last_name() );
    }

    /**
     * Get email
     *
     * @return  string
     */
    public function get_email() {
        if ( $this->is_guest() ) {
            return $this->record_field( 'email' );
        }

        $customer = get_userdata( $this->id );

        if ( $customer ) {
            return $customer->user_email;
        }

        return '';
    }

    /**
     * Get phone
     *
     * @return  string
     */
    public function get_phone() {
        if ( $this->is_guest() ) {
            return $this->record_field( 'phone' );
        }

        return $this->get_prop( 'phone' );
    }

    /**
     * Get customer image
     *
     * @return  string
     */
    public function get_image() {
        if ( $this->is_guest() ) {
            return get_avatar_url( $this->record_field( 'email' ) );
        }

        $image = $this->get_prop( 'image' );

        if ( ! $image ) {
            return get_avatar_url( $this->id );
        }

        return wp_get_attachment_image_url( $image );
    }

	/**
     * Get password
     *
     * @return  string
     */
    public function get_password() {
		$user = get_user_by( 'id', $this->id );
        return $user->user_pass;
    }

    /**
     * Get quantity
     *
     * @return  array
     */
    public function get_quantity() {
		$all_quantity = $this->get_prop( 'quantity' );
        $quantities = [];
        if ( is_array( $all_quantity ) ){
            foreach( $all_quantity as $key => $quantity){
                $quantities[$key] = $quantity;
            }
        }
        return $quantities;

    }

    /**
     * Get Attendees
     *
     * @return  array
     */
    public function get_attendees() {
		$all_attendees = $this->get_prop( 'attendees' );
        $attendees = [];

        if ( is_array( $all_attendees ) ) {
            foreach( $all_attendees as $key => $attendee ) {
                $attendees[] = [
                    'name'  => $attendee['name'],
                    'email' => $attendee['email']
                ];
            }
        }
        return $attendees;

    }

    /**
     * Get prop
     *
     * @param  string
     *
     * @return  string
     */
    public function get_prop( $prop = '' ) {
        return $this->get_metadata( $prop );
    }

    /**
     * Get customer metadata
     *
     * @param   string  $prop
     *
     * @return string
     */
    public function get_metadata( $prop = '' ) {
        // A guest has no user meta; reading id 0 would return nothing anyway.
        if ( $this->is_guest() ) {
            return '';
        }

		if( $prop == 'first_name' || $prop == 'last_name' ) {
			$meta_key = $prop;
		} else {
			$meta_key = $this->meta_prefix . $prop;
		}

        return get_user_meta( $this->id, $meta_key, true );

    }

    // Setters.
    /**
     * Set customer id
     *
     * @param   integer  $id
     *
     * @return  void
     */
    public function set_id( $id ) {
        $this->id = $id;
    }

    /**
     * Set props
     *
     * @param   array  $args  Customer Data
     *
     * @return  void
     */
    public function set_props( $args = [] ) {
        $this->data = wp_parse_args( $args, $this->data );
    }

    /**
     * Update meta data
     *
     * @return  void
     */
    public function save_metadata() {
        foreach ( $this->data as $key => $value ) {
            // WordPress keeps the login password hashed; a copy here would sit in plain text.
            if ( 'password' === $key ) {
                continue;
            }

            // Prepare meta key.
			if( $key == 'first_name' || $key == 'last_name' ) {
				$meta_key = $key;
			} else {
				$meta_key = $this->meta_prefix . $key;
			}

            // Update appointment meta data.
            update_user_meta( $this->id, $meta_key, $value );
        }
    }

    /**
     * Check user is valid staff
     *
     * @return  bool
     */
    public function is_customer() {
        if ( $this->is_guest() ) {
            return $this->get_record_id() > 0;
        }

        $user = get_userdata( $this->id );

        if ( $user ) {
            return in_array( 'timetics-customer', $user->roles, true );
        }

        return true;
    }

    /**
     * Save customer
     *
     * @return void
     */
    public function save() {
        $args = [
            'first_name' => $this->data['first_name'],
            'last_name'  => $this->data['last_name'],
            'user_login' => $this->data['email'],
            'user_email' => $this->data['email'],
        ];

        $user_data = get_user_by( 'email', $this->data['email'] );

        // Only when adding a customer: an edit of an account without the role must save, not stop here.
        if ( ! $this->id && $user_data && ! in_array( 'timetics-customer', $user_data->roles, true ) ) {
            $user_data->add_role( 'timetics-customer' );
            Customer_Store::ensure_for_user( $user_data );

            return $user_data->ID;
        }

		if ( $this->id ) {
			$args['ID']  		= $this->id;
			$args['user_pass']  = $this->data['password'];
			$customer_id 		= wp_update_user( $args );
		} else {
			$args['role'] 		= 'timetics-customer';
			$args['user_pass']  = wp_generate_password();
			$customer_id  		= wp_insert_user( $args );
		}

        if ( is_wp_error( $customer_id ) ) {
            $this->error = $customer_id;
            return;
        }

        $this->set_id( $customer_id );
        $this->save_metadata();

        // The Customers screen lists rows only.
        Customer_Store::ensure_for_user( $customer_id );
    }

    /**
     * Delete a guest row only. Its bookings keep their own copy of the details,
     * and a linked WordPress account is not touched.
     *
     * @return bool
     */
    public function delete_record() {
        return $this->is_guest() && Customer_Store::delete( $this->get_record_id() );
    }

    /**
     * Delete customer
     *
     * @return  bool
     */
    public function delete() {
        // Removing a customer only hides the contact: its bookings and account stay.
        $row = $this->is_guest() ? Customer_Store::get( $this->get_record_id() ) : Customer_Store::find_by_user( $this->id );

        return $row ? Customer_Store::hide( $row['id'] ) : false;
    }

    /**
     * Make customer using email
     *
     * @param  array Customer data
     *
     * @return Customer
     */
    public function make( $args = [] ) {
        $defaults = [
            'first_name' => '',
            'last_name'  => '',
            'email'      => '',
            'phone'      => '',
			'password'   => '',
        ];

        $args = wp_parse_args( $args, $defaults );

        // email_exists() can be filtered, so it may name an account get_user_by() cannot find.
        $user = email_exists( $args['email'] ) ? get_user_by( 'email', $args['email'] ) : false;

        if ( $user ) {

            /**
             * Filters whether this save may change an existing customer's account.
             * MCP turns it off, so what staff type through an AI app links the
             * booking and never rewrites a stored contact.
             *
             * @param bool $may_edit Whether the current user may edit customers.
             * @param int  $user_id  The existing customer.
             */
            $may_edit = apply_filters( 'timetics_customer_make_may_edit', current_user_can( 'manage_timetics' ), $user->ID );

            // The booking form is public: anyone can type any email. Only the
            // owner or an admin may change that account, so a guest booking
            // just links to it and leaves it alone.
            if ( get_current_user_id() !== $user->ID && ! $may_edit ) {
                $this->set_id( $user->ID );

                return $user->ID;
            }

            if ( ! in_array( 'timetics-customer', $user->roles, true ) ) {
                $user->add_role( 'timetics-customer' );
                $this->set_id( $user->ID );

                return $user->ID;
            }

            $this->set_id( $user->ID );
            $this->set_props( $args );
            $this->save_metadata( $args );

        } else {
            $this->set_props( $args );
            $this->save();
        }
    }

    /**
     * Get total booking for a customer
     *
     * @return  integer
     */
    public function get_total_booking() {
        $bookings = $this->booking_query();

        return $bookings->found_posts;
    }

    /**
     * Get all bookings by customer
     *
     * @return array
     */
    public function get_bookings() {
        $bookings = $this->booking_query();
        $data = [];

        if ( ! empty( $bookings->posts ) ) {
            foreach( $bookings->posts as $booking ) {
                $object = new Booking( $booking->ID );
                $data[] = $object->to_array();
            }
        }

        return $data;
    }

    /**
     * Get query for bookings
     *
     * @return  WP_Query
     */
    private function booking_query() {
        // Not tied to "default_booking_status" (that's what status a NEW
        // booking starts in, not which bookings count here) — otherwise an
        // approved booking can vanish from the customer's count/history the
        // moment it's approved, on any site where new bookings default to
        // pending. See core/reports/api-report.php for the same fix.
        $counted_statuses = apply_filters( 'timetics/report/counted_statuses', [ 'approved', 'completed' ] );

        $query = new \WP_Query(
            [
				'post_type'   => 'timetics-booking',
				'post_status' => $counted_statuses,
                // @codingStandardsIgnoreStart
				'meta_key'    => $this->is_guest() ? '_tt_booking_customer_record' : '_tt_booking_customer',
				'meta_value'  => $this->is_guest() ? $this->get_record_id() : $this->id,
                // @codingStandardsIgnoreEnd
			]
        );

        return $query;
    }

    /**
     * Get all customer
     *
     * @param   array  $args  Customer
     *
     * @return  array
     */
    public static function all( $args = [] ) {
        $defaults = [
            'role'   => 'timetics-customer',
            'number' => 20,
            'paged'  => 1,
        ];

        $args = wp_parse_args( $args, $defaults );

        $users = new \WP_User_Query( $args );

        return [
            'total' => $users->get_total(),
            'items' => $users->get_results(),
        ];
    }
}
