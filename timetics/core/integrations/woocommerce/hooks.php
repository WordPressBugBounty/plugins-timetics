<?php
/**
 * WooCommerce Hooks Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Woocommerce;

defined( 'ABSPATH' ) || exit;

use Timetics\Core\Admin\Notification;
use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
use Timetics\Core\Bookings\Booking_Lock;
use Timetics\Core\Customers\Customer;
use Timetics\Utils\Singleton;
use Timetics\Core\Emails\New_Event_Email;
use Timetics\Core\Emails\New_Event_Customer_Email;
use Timetics\Core\Emails\Cancel_Event_Email;
use Timetics\Core\Emails\Cancel_Event_Customer_Email;
use Timetics\Core\Integrations\Woocommerce\Status_Mapper;

/**
 * Class Hooks
 */
class Hooks {
    use Singleton;

    /**
     * Cart item data key holding the booking id. Only add_booking_to_cart() sets it.
     */
    const CART_KEY = 'timetics_booking_id';

    /**
     * Initialize
     *
     * @return  void
     */
    public function init() {
        Cart_Api::instance();

        add_action( 'woocommerce_init', [$this, 'load_woocommerce_required_files'] );

        // The paid work runs on the order status change, so this page only shows the time.
        add_action( 'woocommerce_thankyou', [$this, 'announce_booking'], 20 );

        add_action( 'woocommerce_before_calculate_totals', array( $this, 'price_booking_lines' ) );

        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'keep_booking_cart_alone' ), 10, 2 );

        // Runs on the cart page and before an order is made, in classic and block checkout.
        add_action( 'woocommerce_check_cart_items', array( $this, 'drop_stale_booking_lines' ) );

        // Both run before payment, so an order that is never paid is still linked.
        add_action( 'woocommerce_checkout_order_created', array( $this, 'link_order_to_booking' ) );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'link_order_to_booking' ) );

        // Sync booking status when WooCommerce order status changes
        add_action( 'woocommerce_order_status_changed', [$this, 'sync_booking_status_from_order'], 10, 3 );

        add_filter( 'timetics_get_settings', [$this, 'add_woocommerce_currency_support'] );

        add_filter( 'woocommerce_product_query', [$this, 'modify_woocommerce_shop'] );

        add_action( 'woocommerce_coupon_options_usage_restriction', [$this, 'ensure_meeting_products_exist'], 5, 2 );
        add_action( 'woocommerce_coupon_options_usage_restriction', [$this, 'add_meeting_coupon_restriction_option'], 10, 2 );

        add_action( 'timetics_meeting_after_insert', [$this, 'clear_meeting_products_cache'], 99, 2 );
        add_action( 'before_delete_post', [$this, 'maybe_clear_cache_on_meeting_delete'], 10, 2 );

        add_action( 'woocommerce_coupon_options_save', [$this, 'save_coupon_option'], 10, 2 );

        add_filter( 'woocommerce_coupon_get_products', [$this, 'assign_meeting_coupon_products'], 10, 2 );

        add_filter( 'timetics_settings', [$this, 'add_wc_checkout_url'] );

        add_filter( 'woocommerce_checkout_fields', [$this, 'hide_checkout_fields'] );

        add_filter( 'woocommerce_checkout_posted_data', [$this, 'modify_order_data'] );

        add_filter( 'timetics_currency', [ $this, 'support_woocommerce_currency' ] );

        add_action( 'admin_init', [ $this, 'create_term_timetics_meeting' ] );

        add_action( 'wp_head', [ $this, 'hide_regular_price_checkout_css' ] );

        // Show meeting details under the product in the cart and on checkout
        add_filter( 'woocommerce_get_item_data', [ $this, 'add_meeting_details_to_cart_item' ], 10, 2 );

        // Store meeting details on the order line item ( thank you page, emails, my account, admin )
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_meeting_details_to_order_item' ], 10, 4 );

        // Sync order status when booking status changes (bidirectional sync)
        add_action( 'transition_post_status', [ $this, 'sync_order_status_from_booking' ], 10, 3 );
    }

    /**
     * Load all required files and functions
     *
     * @return  void
     */
    public function load_woocommerce_required_files() {
        if ( ! WC()->is_rest_api_request() ) {
            return;
        }

        WC()->frontend_includes();

        if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
    }

    /**
     * Add product data on woocommerce product data
     *
     * @return  void
     */
    public function add_product_data_store( $stores ) {
        $stores['product'] = 'Timetics\Core\Integrations\Woocommerce\Product_Data_Store';

        return $stores;
    }

    /**
     * Tell the page which time this order booked, the way the success screen
     * does (assets/src/frontend/helper/announceBookings.js), so the Aisentic
     * chat marks its booking card as booked. A WooCommerce booking never shows
     * the success screen: the visitor lands on this thank you page instead.
     * Only ids, date and time, on the order's own thank you page.
     *
     * @param   integer  $order_id
     *
     * @return  void
     */
    public function announce_booking( $order_id ) {
        $booking_id = (int) Status_Mapper::get_booking_id_from_order( $order_id );

        if ( ! $booking_id || in_array( get_post_status( $booking_id ), [ false, 'cancel', 'failed', 'trash' ], true ) ) {
            return;
        }

        $booking = new Booking( $booking_id );
        $time    = strtotime( (string) $booking->get_start_time() );

        if ( false === $time ) {
            return;
        }

        $bookings = wp_json_encode(
            [
                [
                    'id'         => $booking_id,
                    'meeting_id' => (int) $booking->get_appointment(),
                    'staff_id'   => (int) $booking->get_staff_id(),
                    'date'       => (string) $booking->get_start_date(),
                    'time'       => gmdate( 'H:i', $time ),
                    'timezone'   => (string) $booking->get_timezone(),
                ],
            ]
        );

        printf(
            '<script>window.timeticsBookings = ( window.timeticsBookings || [] ).concat( %1$s ); window.dispatchEvent( new CustomEvent( "timetics:bookings", { detail: %1$s } ) );</script>',
            $bookings // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() of ids, a date, a time and a timezone.
        );
    }

    /**
     * Whether a booking still waits for its WooCommerce payment.
     *
     * @param   Booking  $booking
     *
     * @return  bool
     */
    public static function is_waiting( Booking $booking ) {
        return timetics_is_woocommerce_active()
            && $booking->is_booking()
            && 'woocommerce' === strtolower( (string) $booking->get_payment_method() )
            && (float) $booking->get_total() > 0
            && 'completed' !== $booking->get_payment_status()
            && 'cancel' !== $booking->get_status();
    }

    /**
     * Put a booking in the cart as the only line, tagged with its id.
     *
     * The cart is emptied first: the order status sync changes the whole order,
     * so a booking order must not carry other products.
     *
     * @param   Booking  $booking  A waiting booking ( is_waiting() ).
     *
     * @return  bool  Whether the line was added.
     */
    public static function add_booking_to_cart( Booking $booking ) {
        $meeting    = new Appointment( $booking->get_appointment() );
        $product_id = $meeting->get_wc_product_id();
        $wc_product = $product_id ? wc_get_product( $product_id ) : false;

        if ( ! $wc_product ) {
            $product_id = ( new Product() )->create( $meeting );
            $wc_product = $product_id ? wc_get_product( $product_id ) : false;
        }

        if ( ! $wc_product ) {
            return false;
        }

        // WooCommerce refuses a product without a price; the line is repriced from the booking anyway.
        if ( ! $wc_product->get_price() ) {
            update_post_meta( $product_id, '_regular_price', wc_format_decimal( $booking->get_total() ) );
            update_post_meta( $product_id, '_price', wc_format_decimal( $booking->get_total() ) );
        }

        WC()->cart->empty_cart();

        $key = WC()->cart->add_to_cart( $product_id, self::seat_count( $booking ), 0, array(), array( self::CART_KEY => (int) $booking->get_id() ) );

        return (bool) $key;
    }

    /**
     * Quantity of a booking line: one per seat, so a per-product coupon discounts each seat.
     *
     * @param   Booking  $booking
     *
     * @return  int
     */
    private static function seat_count( Booking $booking ) {
        $seats = $booking->get_seat();

        return is_array( $seats ) && $seats ? count( $seats ) : 1;
    }

    /**
     * The booking a cart line was added for, or null for any other line.
     *
     * The product must still be that booking's meeting product.
     *
     * @param   array  $cart_item  Cart line, or the cart values of an order line.
     *
     * @return  Booking|null
     */
    private function booking_of_line( $cart_item ) {
        // A cart filled before this version has the old "booking_id" key. That key was put
        // on every product, so the product check below is what keeps it off shop products.
        $booking_id = (int) ( $cart_item[ self::CART_KEY ] ?? $cart_item['booking_id'] ?? 0 );

        if ( ! $booking_id || empty( $cart_item['product_id'] ) ) {
            return null;
        }

        $booking = new Booking( $booking_id );

        if ( ! $booking->is_booking() ) {
            return null;
        }

        $meeting = new Appointment( $booking->get_appointment() );

        return (int) $meeting->get_wc_product_id() === (int) $cart_item['product_id'] ? $booking : null;
    }

    /**
     * The booking in the current cart, or null when the cart holds none.
     *
     * @return  Booking|null
     */
    private function cart_booking() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return null;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $booking = $this->booking_of_line( $cart_item );

            if ( $booking ) {
                return $booking;
            }
        }

        return null;
    }

    /**
     * Price each booking line from its saved booking: total / seats, one seat per quantity.
     *
     * The quantity is set back to the seat count, so changing it in the cart never
     * changes what the customer pays.
     *
     * @param   \WC_Cart  $cart
     *
     * @return  void
     */
    public function price_booking_lines( $cart ) {
        foreach ( $cart->get_cart() as $key => $cart_item ) {
            $booking = $this->booking_of_line( $cart_item );

            if ( ! $booking ) {
                continue;
            }

            $seats = self::seat_count( $booking );

            if ( $seats !== (int) $cart_item['quantity'] ) {
                $cart->set_quantity( $key, $seats, false );
            }

            $price = (float) $booking->get_total() / $seats;

            $cart_item['data']->set_price( $price );
            $cart_item['data']->set_regular_price( $price );
            $cart_item['data']->set_sale_price( $price );
        }
    }

    /**
     * Refuse other products while the cart holds a booking still waiting for payment.
     *
     * Runs only for the add to cart form and the Store API; WC_Cart::add_to_cart()
     * itself skips it. A line whose booking no longer waits is removed instead.
     *
     * @param   bool  $passed
     * @param   int   $product_id
     *
     * @return  bool
     */
    public function keep_booking_cart_alone( $passed, $product_id ) {
        if ( ! $passed || ! WC()->cart ) {
            return $passed;
        }

        foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
            $booking = $this->booking_of_line( $cart_item );

            if ( ! $booking ) {
                continue;
            }

            if ( ! self::is_waiting( $booking ) ) {
                WC()->cart->remove_cart_item( $key );
                continue;
            }

            wc_add_notice( __( 'Please pay for your booking, or remove it from the cart, before adding other products.', 'timetics' ), 'error' );

            return false;
        }

        return $passed;
    }

    /**
     * Remove a booking line whose booking can no longer be paid, before an order is made.
     *
     * Cancelled ( by a person or by the unpaid cleanup ), already paid, or the
     * integration turned off since it went in the cart.
     *
     * @return  void
     */
    public function drop_stale_booking_lines() {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
            $booking = $this->booking_of_line( $cart_item );

            if ( ! $booking || self::is_waiting( $booking ) ) {
                continue;
            }

            WC()->cart->remove_cart_item( $key );
            wc_add_notice( __( 'Your booking can no longer be paid, so it was removed from your cart. Please book again.', 'timetics' ), 'error' );
        }
    }

    /**
     * Write the booking -> order link once the new order has an id.
     *
     * @param   \WC_Order  $order
     *
     * @return  void
     */
    public function link_order_to_booking( $order ) {
        $booking_id = (int) $order->get_meta( '_tt_booking_id' );

        if ( $booking_id ) {
            Status_Mapper::set_order_id_for_booking( $booking_id, $order->get_id() );
        }
    }

    /**
     * Approve a booking whose order was just paid, then send what a new booking sends.
     *
     * Runs once: it is only called while the booking is not marked paid yet.
     *
     * @param   Booking      $booking
     * @param   integer      $order_id
     * @param   \WC_Order    $order
     *
     * @return  void
     */
    private function finish_paid_booking( $booking, $order_id, $order ) {
        Status_Mapper::set_order_id_for_booking( $booking->get_id(), $order_id );

        // The money is in, but the booking may have lost its time while it waited
        // ( cancelled order, then paid late ). Approve it only if the time is ours.
        $outcome = $this->bring_back_booking(
            $booking,
            array(
                'post_status'    => timetics_get_option( 'default_booking_status', 'approved' ),
                'payment_status' => 'completed',
                'payment_method' => 'woocommerce',
            )
        );

        if ( 'ok' !== $outcome ) {
            $this->report_booking_not_brought_back( $booking, $order_id, $order, $outcome, true );
            return;
        }

        $booking->create_event();

        if ( timetics_get_option( 'booking_created_host' ) ) {
            ( new New_Event_Email( $booking ) )->send();
        }

        if ( timetics_get_option( 'booking_created_customer' ) ) {
            ( new New_Event_Customer_Email( $booking ) )->send();
        }

        timetics_notify( 'booking_created', Notification::get_hook_data( $booking, true ) );

        // Paid, as after Stripe or PayPal, so "booking paid" automations run for it too.
        do_action( 'timetics_booking_payment', $booking );
    }

    /**
     * Support woocommerce currency
     *
     * @param   array  $settings
     *
     * @return  array
     */
    public function add_woocommerce_currency_support( $settings ) {

        if ( ! function_exists( 'WC' ) ) {
            return $settings;
        }

        $wc_integration = timetics_get_option( 'wc_integration' );

        if ( ! $wc_integration ) {
            return $settings;
        }

        $settings['currency'] = get_woocommerce_currency();

        return $settings;
    }

    /**
     * Hide timetics meeting from woocommerce shop page
     *
     * @param   Object  $query
     *
     * @return  Object
     */
    public function modify_woocommerce_shop( $query ) {
        $category_slug = 'timetics-meeting'; // Replace 'category-slug' with the desired category slug

        // Get product category ID
        $category = get_term_by( 'slug', $category_slug, 'product_cat' );

        if ( $category ) {
            $category_id = $category->term_id;

            // Exclude products assigned to the category
            $query->set( 'tax_query', array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                    'operator' => 'NOT IN',
                ),
            ) );
        }

        return $query;
    }

    /**
     * Set coupon products
     *
     * @param   integer  $coupon_id
     * @param   Object  $coupon
     *
     * @return  void
     */
    public function save_coupon_option( $coupon_id, $coupon ) {
        // Verify nonce for security
        if ( ! isset( $_POST['timetics_coupon_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['timetics_coupon_nonce'] ) ), 'timetics_coupon_action' ) ) {
            return;
        }

        $meeting_ids = ! empty( $_POST['timetics_meeting_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['timetics_meeting_ids'] ) ) : [];

        $coupon_products = $coupon->get_product_ids();
        $coupon_products = array_merge( $coupon_products, $meeting_ids );
        $coupon->update_meta_data( 'timetics_meeting_ids', $meeting_ids );

        $coupon->set_product_ids( $coupon_products );
        $coupon->save();
    }

    /**
     * Add woocommerce coupon restriction option
     *
     * @param   int  $coupon_id
     * @param   Object  $coupon
     *
     * @return  void
     */
    public function add_meeting_coupon_restriction_option( $coupon_id, $coupon ) {
        include_once TIMETICS_PLUGIN_DIR . '/templates/woocommerce/coupon-restriction-option.php';
    }

    /**
     * Ensure all meeting products exist in WooCommerce
     *
     * @param   int     $coupon_id
     * @param   Object  $coupon
     *
     * @return  void
     */
    public function ensure_meeting_products_exist( $coupon_id, $coupon ) {
        $cache_key = 'timetics_meeting_products_synced';
        if ( get_transient( $cache_key ) ) {
            return;
        }

        if ( ! term_exists( 'timetics-meeting', 'product_cat' ) ) {
            timetics_add_woocommerce_product_cat();
        }

        $args = [
            'post_type'      => 'timetics-appointment',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta query is necessary for filtering meetings by meta fields
            'meta_query'     => [
                [
                    'key'     => 'wc_product_id',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $meetings = get_posts( $args );

        if ( empty( $meetings ) ) {
            set_transient( $cache_key, true, DAY_IN_SECONDS );
            return;
        }

        $product_creator = new Product();

        foreach ( $meetings as $meeting ) {
            $appointment = new Appointment( $meeting->ID );

            if ( ! $appointment->get_id() ) {
                continue;
            }

            if ( empty( $appointment->get_name() ) ) {
                continue;
            }

            $product_id = $appointment->get_wc_product_id();
            if ( ! $product_id ) {
                $price = $appointment->get_price();
                if ( is_array( $price ) ) {
                    $price = '';
                }

                $product_id = $product_creator->create( $appointment );
            }
        }

        set_transient( $cache_key, true, DAY_IN_SECONDS );
    }

    /**
     * Clear meeting products cache when new meeting is created
     *
     * @param   mixed  $appointment  Appointment object or ID
     * @param   mixed  $data        Meeting data array
     *
     * @return  void
     */
    public function clear_meeting_products_cache( $appointment = null, $data = null ) {
        delete_transient( 'timetics_meeting_products_synced' );
    }

    /**
     * Clear meeting products cache when a meeting is deleted
     *
     * @param   int     $post_id
     * @param   Object  $post
     *
     * @return  void
     */
    public function maybe_clear_cache_on_meeting_delete( $post_id, $post ) {
        if ( 'timetics-appointment' === $post->post_type ) {
            delete_transient( 'timetics_meeting_products_synced' );
        }
    }

    /**
     * Add checkout url on settings
     *
     * @param   array  $settings
     *
     * @return  array
     */
    public function add_wc_checkout_url( $settings ) {
        if ( ! timetics_is_woocommerce_active() ) {
            return $settings;
        }

        $settings['wc_checkout_url'] = wc_get_checkout_url();

        return $settings;
    }

    /**
     * Hide all fields from checkout page
     *
     * @param   array  $fields
     *
     * @return  array
     */
    public function hide_checkout_fields( $fields ) {
        // The booking form already took the customer's details; modify_order_data() fills them in.
        if ( ! $this->cart_booking() ) {
            return $fields;
        }

        $fields['billing'] = array();

        // Hide all shipping fields
        $fields['shipping'] = array();

        $fields['order'] = array();

        return $fields;
    }

    /**
     * Modify order posted data
     *
     * @param   array  $data
     *
     * @return  array
     */
    public function modify_order_data( $data ) {
        $booking = $this->cart_booking();

        if ( ! $booking ) {
            return $data;
        }

        $customer = Customer::from_booking( $booking );

        $first_name = $customer->get_first_name();
        $last_name  = $customer->get_last_name();
        $email      = $customer->get_email();
        $phone      = $customer->get_phone();

        if ( $first_name ) {
            $data['billing_first_name'] = $first_name;
        }

        if ( $last_name ) {
            $data['billing_last_name'] = $last_name;
        }

        if ( $email ) {
            $data['billing_email'] = $email;
        }

        if ( $phone ) {
            $data['billing_phone'] = $phone;
        }

        return $data;
    }

    /**
     * Suport woocmmerce currency
     *
     * @param   string  $currency
     *
     * @return  string
     */
    public function support_woocommerce_currency( $currency ) {
        if ( ! timetics_is_woocommerce_active() ) {
            return $currency;
        }

        return get_woocommerce_currency();
    }

    /**
     * Create a category if woocommerce loaded
     *
     * @return void
     */
    public function create_term_timetics_meeting() {
        if ( term_exists( 'timetics-meeting', 'product_cat' ) ) {
            return;
        }

        // Create new woocommerce product category for timetics meeting
        timetics_add_woocommerce_product_cat();
    }

    public function hide_regular_price_checkout_css() {
        if ( is_checkout() || is_cart() ) {
            if ( $this->cart_booking() ) {
            ?>
            <style type="text/css">
                .wc-block-components-order-summary .wc-block-components-order-summary-item__individual-prices {
                    display: none;
                }
		.wc-block-cart-item__prices,
		.woocommerce-cart-form__cart-item.cart_item .product-price {
			display: none;
		}
		.wc-block-cart-item__quantity,
		.woocommerce-cart-form__cart-item.cart_item .product-quantity {
			display: none;
		}
            </style>
            <?php
            }
        }
    }

    /**
     * Format and prepare meeting details
     *
     * @param   array  $session_data
     *
     * @return  array|false
     */
    private function prepare_meeting_details( $session_data ) {
        if ( ! $session_data || ! is_array( $session_data ) ) {
            return false;
        }

        $start_date        = isset( $session_data['start_date'] ) ? $session_data['start_date'] : '';
        $start_time        = isset( $session_data['start_time'] ) ? $session_data['start_time'] : '';
        $customer_timezone = isset( $session_data['timezone'] ) ? $session_data['timezone'] : '';
        $meeting_timezone  = isset( $session_data['meeting_timezone'] ) ? $session_data['meeting_timezone'] : '';
        $duration          = isset( $session_data['duration'] ) ? $session_data['duration'] : '';
        $location_type     = isset( $session_data['location_type'] ) ? $session_data['location_type'] : '';

        if ( ! $start_date || ! $start_time ) {
            return false;
        }

        // Get WordPress date and time formats
        $date_format = get_option( 'date_format', 'F j, Y' );
        $time_format = get_option( 'time_format', 'g:i a' );

        // Convert timezone if both customer and meeting timezones are available
        $datetime_to_display = null;

        if ( $customer_timezone && $meeting_timezone && timetics_is_valid_timezone( $customer_timezone ) && timetics_is_valid_timezone( $meeting_timezone ) ) {
            $datetime_to_display = timetics_convert_timezone( $start_date . ' ' . $start_time, $customer_timezone, $meeting_timezone );
            $display_timezone = $meeting_timezone;
        } else {
            $datetime_to_display = new \DateTime( $start_date . ' ' . $start_time );
            $display_timezone = $customer_timezone ?: 'UTC';
        }

        $formatted_date = $datetime_to_display->format( $date_format );
        $formatted_time = $datetime_to_display->format( $time_format );

        // Build location type label
        $location_label = '';
        if ( $location_type ) {
            $location_label = ucfirst( str_replace( '-', ' ', $location_type ) );
        }

        return [
            'formatted_date'  => $formatted_date,
            'formatted_time'  => $formatted_time,
            'duration'        => $duration,
            'location_label'  => $location_label,
            'timezone'        => $customer_timezone,
            'display_timezone' => $display_timezone,
        ];
    }

    /**
     * Meeting details as label => value pairs, ready to display.
     *
     * Shared by the cart / checkout item data and the order line item meta so the
     * customer sees the same details before and after paying.
     *
     * @param   Booking  $booking
     *
     * @return  array
     */
    private function get_meeting_detail_pairs( $booking ) {
        $meeting = new Appointment( $booking->get_appointment() );
        $details = $this->prepare_meeting_details(
            array(
                'start_date'       => $booking->get_start_date(),
                'start_time'       => $booking->get_start_time(),
                'timezone'         => $booking->get_timezone(),
                'meeting_timezone' => $meeting->get_timezone(),
                'duration'         => $meeting->get_duration(),
                'location_type'    => $booking->get_location_type(),
            )
        );

        if ( ! $details ) {
            return [];
        }

        $time = $details['formatted_time'];

        if ( $time && $details['display_timezone'] ) {
            $time .= ' (' . $details['display_timezone'] . ')';
        }

        return array_filter( [
            __( 'Date', 'timetics' )     => $details['formatted_date'],
            __( 'Time', 'timetics' )     => $time,
            __( 'Duration', 'timetics' ) => $details['duration'],
            __( 'Location', 'timetics' ) => $details['location_label'],
            __( 'Timezone', 'timetics' ) => $details['timezone'],
            __( 'Sessions', 'timetics' ) => $this->series_summary( $booking ),
        ] );
    }

    /**
     * "4 (23/11/2026, 30/11/2026, ...)" for a recurring series, empty for one booking.
     *
     * The line carries the whole series price, so the customer sees every date they pay for.
     *
     * @param   Booking  $booking  The first booking of the series.
     *
     * @return  string
     */
    private function series_summary( $booking ) {
        $series = $booking->get_series_place();

        if ( ! $series ) {
            return '';
        }

        $date_format = get_option( 'date_format', 'F j, Y' );
        $dates       = array();

        // Dates are in the meeting's timezone, like the Date line above.
        foreach ( $series['sessions'] as $session ) {
            $dates[] = date_i18n( $date_format, strtotime( $session['date'] ) );
        }

        return sprintf( '%d (%s)', $series['size'], implode( ', ', $dates ) );
    }

    /**
     * Show meeting details under the product in the cart and on checkout.
     *
     * WooCommerce runs this filter for both the classic templates and the Store API,
     * so one filter covers the classic and the block based cart / checkout. The block
     * checkout is the default since WooCommerce 8.3, and the old
     * woocommerce_review_order_before_payment hook this replaced never ran there.
     *
     * @param   array  $item_data
     * @param   array  $cart_item
     *
     * @return  array
     */
    public function add_meeting_details_to_cart_item( $item_data, $cart_item ) {
        $booking = $this->booking_of_line( $cart_item );

        if ( ! $booking ) {
            return $item_data;
        }

        foreach ( $this->get_meeting_detail_pairs( $booking ) as $label => $value ) {
            $item_data[] = [
                'key'   => $label,
                'value' => $value,
            ];
        }

        return $item_data;
    }

    /**
     * Store meeting details as order line item meta, and link the order to its booking.
     *
     * The line item meta makes WooCommerce show the details everywhere an order is
     * shown: thank you page, order emails, My Account and the admin order screen.
     * Classic and block checkout both build line items through this hook.
     *
     * @param   \WC_Order_Item_Product  $item
     * @param   string                  $cart_item_key
     * @param   array                   $values         The cart line.
     * @param   \WC_Order               $order
     *
     * @return  void
     */
    public function add_meeting_details_to_order_item( $item, $cart_item_key, $values, $order ) {
        $booking = $this->booking_of_line( $values );

        if ( ! $booking ) {
            return;
        }

        foreach ( $this->get_meeting_detail_pairs( $booking ) as $label => $value ) {
            $item->add_meta_data( $label, $value, true );
        }

        $item->add_meta_data( '_tt_booking_id', $booking->get_id(), true );

        // Not saved here: in the classic checkout the order has no id yet. WooCommerce
        // saves it right after the line items, then link_order_to_booking() runs.
        $order->update_meta_data( '_tt_booking_id', $booking->get_id() );
    }

    /**
     * Update a booking, taking back its time first when it gave that up.
     *
     * A booking that still holds its place is just updated. One that gave it up
     * ( order cancelled, payment declined ) takes the place back and changes its
     * status in one step under the booking lock. Done as two steps, a second
     * request could come in between, see a booking that still looks cancelled and
     * take the place again.
     *
     * @param   Booking  $booking  The booking the order belongs to.
     * @param   array    $update   What to save on the booking, including its new status.
     *
     * @return  string  'ok', 'taken' ( someone else holds the time ) or 'busy' ( the lock was not free ).
     */
    private function bring_back_booking( $booking, $update ) {
        if ( $booking->holds_slot() ) {
            $booking->update( $update );

            return 'ok';
        }

        $result = Booking_Lock::run(
            function () use ( $booking, $update ) {
                // Read again: another request may have brought it back while this one waited.
                $fresh = new Booking( $booking->get_id() );

                if ( ! $fresh->holds_slot() ) {
                    // A booking cancelled by an older version has no released flag, only
                    // the cancel status. Flag it so the place is really taken back.
                    $fresh->mark_slot_released();

                    if ( ! $fresh->take_slot_back() ) {
                        return 'taken';
                    }
                }

                $booking->update( $update );

                return 'ok';
            }
        );

        return is_wp_error( $result ) ? 'busy' : $result;
    }

    /**
     * Tell the admin a booking was not brought back by its order.
     *
     * The order is never refunded here; the admin decides ( refund, or move the
     * booking to another time ). Only an order that was really paid sends an email.
     *
     * @param   Booking      $booking   The booking the order belongs to.
     * @param   integer      $order_id  WooCommerce order ID.
     * @param   object|bool  $order     The order, or false when it cannot be loaded.
     * @param   string       $outcome   'taken' or 'busy', from bring_back_booking().
     * @param   bool         $paid      Whether the order was paid.
     *
     * @return  void
     */
    private function report_booking_not_brought_back( $booking, $order_id, $order, $outcome, $paid ) {
        $busy = 'busy' === $outcome;

        if ( $paid && $busy ) {
            /* translators: 1: WooCommerce order ID, 2: booking ID */
            $text = __( 'Order #%1$d was paid, but booking #%2$d was not approved because the site was saving other bookings at that moment. To try once more, change the order to On hold and then back to Processing ( setting Processing again does nothing, the status has not changed ). Or approve the booking by hand.', 'timetics' );
        } elseif ( $paid ) {
            /* translators: 1: WooCommerce order ID, 2: booking ID */
            $text = __( 'Order #%1$d was paid, but booking #%2$d could not get its time back because someone else took it. The booking was not approved. Refund the order, or move the booking to another time.', 'timetics' );
        } elseif ( $busy ) {
            /* translators: 1: WooCommerce order ID, 2: booking ID */
            $text = __( 'Booking #%2$d of order #%1$d stays cancelled because the site was saving other bookings at that moment. To try once more, change the order to a different status, for example On hold.', 'timetics' );
        } else {
            /* translators: 1: WooCommerce order ID, 2: booking ID */
            $text = __( 'Booking #%2$d of order #%1$d stays cancelled because its time was taken by someone else.', 'timetics' );
        }

        $message = sprintf( $text, $order_id, $booking->get_id() );

        if ( $order ) {
            $order->add_order_note( $message );
        }

        if ( $paid ) {
            timetics_safe_mail( get_option( 'admin_email' ), __( 'A paid booking was not approved', 'timetics' ), $message );
        }
    }

    /**
     * Sync booking status when WooCommerce order status changes
     *
     * @param int    $order_id   The WooCommerce order ID
     * @param string $old_status The previous order status (without 'wc-' prefix)
     * @param string $new_status The new order status (without 'wc-' prefix)
     *
     * @return void
     */
    public function sync_booking_status_from_order( $order_id, $old_status, $new_status ) {
        // Get the booking ID from order meta
        $booking_id = Status_Mapper::get_booking_id_from_order( $order_id );

        if ( ! $booking_id ) {
            return;
        }

        // Check if this pair is already syncing (prevents reverse sync infinite loop)
        if ( Status_Mapper::is_syncing_pair( $booking_id, $order_id ) ) {
            return;
        }

        // Check if sync is needed
        if ( ! Status_Mapper::should_sync_status( $old_status, $new_status ) ) {
            return;
        }

        // Mark this pair as syncing to prevent reverse sync
        Status_Mapper::begin_sync_pair( $booking_id, $order_id );

        try {
            $booking = new Booking( $booking_id );

            if ( ! $booking->is_booking() ) {
                return;
            }

            // Map WooCommerce status to Timetics booking status
            $booking_status = Status_Mapper::order_to_booking_status( $new_status );

            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

            $paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
            $was_paid      = in_array( $old_status, $paid_statuses, true );

            // Only the move into a paid status is the payment. An older order can be paid while its
            // booking is still marked unpaid; completing it weeks later must not send new-booking emails.
            if ( ! $was_paid && in_array( $booking_status, array( 'approved', 'completed' ), true ) && $order && $order->is_paid() && 'completed' !== $booking->get_payment_status() ) {
                $this->finish_paid_booking( $booking, $order_id, $order );
                return;
            }

            // Get current booking status
            $current_status = $booking->get_status();

            // A cancelled / refunded order is terminal, so release the slot it was
            // holding and let it appear as free again.
            //
            // We deliberately do NOT release on a 'failed' order. A WooCommerce
            // booking is created in the 'failed' state while awaiting payment, and
            // a failed order is retryable — the customer can re-pay. Releasing on
            // 'failed' would free the slot, and a successful retry
            // ( failed -> processing/approved ) would then revive the booking
            // without re-holding the slot, causing an overbooking. A genuinely
            // abandoned order is cancelled by WooCommerce's own unpaid-order
            // handling ( hold-stock timeout ), which maps to 'cancel' and releases
            // here.
            //
            // This runs before the "status unchanged" short-circuit below. A refund
            // after a cancel ( cancel -> cancel ) frees nothing: release_slot() only
            // frees a place the booking still holds.
            if ( 'cancel' === $booking_status ) {
                $booking->release_slot();
            }

            // Only update if status has actually changed
            if ( $current_status === $booking_status ) {
                return;
            }

            // Update booking status
            // This will trigger transition_post_status, but our pair lock prevents reverse sync
            if ( in_array( $booking_status, array( 'cancel', 'failed' ), true ) ) {
                $booking->update( array( 'post_status' => $booking_status ) );
            } else {
                // A cancelled order can be reopened or paid late ( slow bank, manual change
                // by an admin ). Bringing the booking back needs its time back first; if
                // that fails, leave the booking cancelled and tell the admin.
                $outcome = $this->bring_back_booking( $booking, array( 'post_status' => $booking_status ) );

                if ( 'ok' !== $outcome ) {
                    $paid  = $order ? $order->is_paid() : in_array( $new_status, array( 'processing', 'completed' ), true );

                    $this->report_booking_not_brought_back( $booking, $order_id, $order, $outcome, $paid );

                    return;
                }
            }

            // Send cancellation emails if status changed to 'cancel'
            if ( 'cancel' === $booking_status ) {
                $booking->delete_event();

                $is_email_to_customer = timetics_get_option( 'booking_canceled_customer' );
                $is_email_to_host     = timetics_get_option( 'booking_canceled_host' );

                if ( $is_email_to_host ) {
                    $cancel_event_email = new Cancel_Event_Email( $booking );
                    $cancel_event_email->send();
                }

                if ( $is_email_to_customer ) {
                    $customer_cancel_event_email = new Cancel_Event_Customer_Email( $booking );
                    $customer_cancel_event_email->send();
                }

                timetics_notify( 'booking_canceled', Notification::get_hook_data( $booking ) );
            }

        } finally {
            // Always clear the sync pair flag
            Status_Mapper::end_sync_pair( $booking_id, $order_id );
        }
    }

    /**
     * Sync WooCommerce order status when booking status changes
     *
     * @param string  $new_status The new booking status
     * @param string  $old_status The previous booking status
     * @param WP_Post $post The booking post object
     *
     * @return void
     */
    public function sync_order_status_from_booking( $new_status, $old_status, $post ) {
        // Only process timetics-booking posts
        if ( 'timetics-booking' !== $post->post_type ) {
            return;
        }

        $booking_id = $post->ID;

        $order_id = Status_Mapper::get_order_id_from_booking( $booking_id );

        if ( ! $order_id ) {
            return;
        }

        if ( Status_Mapper::is_syncing_pair( $booking_id, $order_id ) ) {
            return;
        }

        if ( ! Status_Mapper::should_sync_status( $old_status, $new_status ) ) {
            return;
        }

        Status_Mapper::begin_sync_pair( $booking_id, $order_id );

        try {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                return;
            }

            // Map Timetics booking status to WooCommerce order status
            $wc_status = Status_Mapper::booking_to_order_status( $new_status );

            // Get current order status (without 'wc-' prefix)
            $current_wc_status = str_replace( 'wc-', '', $order->get_status() );

            if ( $current_wc_status === $wc_status ) {
                return;
            }

            $order->set_status( $wc_status );
            $order->save();

        } finally {
            // Always clear the sync pair flag
            Status_Mapper::end_sync_pair( $booking_id, $order_id );
        }
    }
}
