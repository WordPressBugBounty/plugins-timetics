<?php
/**
 * WooCommerce Hooks Class
 *
 * @package Timetics
 */
namespace Timetics\Core\Integrations\Woocommerce;

defined( 'ABSPATH' ) || exit;

use Timetics\Core\Appointments\Appointment;
use Timetics\Core\Bookings\Booking;
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
     * Initialize
     *
     * @return  void
     */
    public function init() {
        Cart_Api::instance();

        add_action( 'woocommerce_init', [$this, 'load_woocommerce_required_files'] );

        add_action( 'woocommerce_thankyou', [$this, 'update_booking_payment_status'] );

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

        add_action( 'timetics_after_booking_create', [$this, 'add_product_to_cart'], 10, 4 );

        add_filter( 'timetics_currency', [ $this, 'support_woocommerce_currency' ] );

        add_action( 'admin_init', [ $this, 'create_term_timetics_meeting' ] );

        add_action( 'wp_head', [ $this, 'hide_regular_price_checkout_css' ] );

        // Display meeting details on checkout page
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'display_meeting_details_on_checkout' ] );

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
     * Update booking payment status
     *
     * @param   integer  $order_id
     *
     * @return  void
     */
    public function update_booking_payment_status( $order_id ) {

        $order = wc_get_order( $order_id );

        if ( ! $order->is_paid() ) {
            // Mark booking as failed if payment was not completed
            $session_data = WC()->session->get( 'timetics_data' );
            if ( $session_data ) {
                $booking = new Booking( $session_data['booking_id'] );
                Status_Mapper::set_order_id_for_booking( $session_data['booking_id'], $order_id );
            }
            WC()->session->set( 'timetics_data', null );
            return;
        }

        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! $session_data ) {
            return;
        }

        $booking = new Booking( $session_data['booking_id'] );

        // Get the default booking status (e.g., 'approved')
        $default_booking_status = timetics_get_option( 'default_booking_status', 'approved' );

        $booking->update( [
            'post_status'    => $default_booking_status,
            'payment_status' => 'completed',
            'payment_method' => 'woocommerce',
        ] );

        // Store the order ID in booking meta and booking id in woocommerce order meta for status syncing
        Status_Mapper::set_order_id_for_booking( $session_data['booking_id'], $order_id );
        Status_Mapper::set_booking_id_for_order( $order_id, $session_data['booking_id'] );

        $booking->create_event();
        $is_email_to_customer = timetics_get_option( 'booking_created_customer');
        $is_email_to_host     = timetics_get_option( 'booking_created_host');
        if( $is_email_to_host ){
            $new_event_email = new New_Event_Email( $booking );
            $new_event_email->send();
        }

        if( $is_email_to_customer ){
            $new_event_customer_email = new New_Event_Customer_Email( $booking );
            $new_event_customer_email->send();
        }

        WC()->session->set( 'timetics_data', null );

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
        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! $session_data ) {
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
        $session_data = WC()->session->get( 'timetics_data' );

        if ( ! $session_data ) {
            return $data;
        }

        $booking  = new Booking( $session_data['booking_id'] );
        $customer = new Customer( $booking->get_customer_id() );

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
     * Add to cart timetics appointment
     *
     * @param   integer  $booking_id
     * @param   integer  $customer_id
     * @param   integer  $meeting_id
     * @param   array  $data
     *
     * @return  void
     */
    public function add_product_to_cart( $booking_id, $customer_id, $meeting_id, $data ) {
        if ( 'woocommerce' !== $data['payment_method'] ) {
            return;
        }

        $booking    = new Booking( $booking_id );
        $meeting_id = $booking->get_appointment();
        if( !empty($data['seats']) && is_array( $data['seats'])) {
            $quantity   = count( $data['seats'] );
        }else {
            $quantity   = 1;
        }
        $price      = $booking->get_total();

        // Get meeting details for checkout display
        $appointment = new Appointment( $meeting_id );
        $start_date  = $booking->get_start_date();
        $start_time  = $booking->get_start_time();
        $end_time    = $booking->get_end_time();
        $timezone    = $booking->get_timezone();
        $location    = $booking->get_location();
        $location_type = $booking->get_location_type();
        $duration    = $appointment->get_duration();
        $meeting_timezone = $appointment->get_timezone();

        // Set session for timetics data for woocommerce.
        WC()->session->set( 'timetics_data', [
            'booking_id'      => $booking_id,
            'meeting_id'      => $meeting_id,
            'price'           => $price,
            'start_date'      => $start_date,
            'start_time'      => $start_time,
            'end_time'        => $end_time,
            'timezone'        => $timezone,
            'meeting_timezone' => $meeting_timezone,
            'location'        => $location,
            'location_type'   => $location_type,
            'duration'        => $duration,
        ] );

        // Remove all items from cart.
        WC()->cart->empty_cart();

        // Preparing for add to cart.
        $meeting    = new Appointment( $meeting_id );
        $product_id = $meeting->get_wc_product_id();


        if ( ! $product_id ) {
            $product    = new Product();
            $product_id = $product->create( $meeting );
        }

        $wc_product = wc_get_product( $product_id );
        if ( ! $wc_product->get_price() ) {
            update_post_meta( $product_id, '_regular_price',  wc_format_decimal( $price ) );
            update_post_meta( $product_id, '_price',  wc_format_decimal( $price ) );
        }

        if ( ! $product_id ) {
            return;
        }

        WC()->cart->add_to_cart( $product_id, $quantity );
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
        $session = WC()->session;
        if ( (is_checkout() || is_cart()) && $session ) {
            $timetics_data = $session->get('timetics_data');
            if( isset($timetics_data) ) {
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
     * Display meeting details on checkout page
     *
     * @return  void
     */
    public function display_meeting_details_on_checkout() {
        if ( ! is_checkout() ) {
            return;
        }

        $session_data = WC()->session->get( 'timetics_data' );
        $details      = $this->prepare_meeting_details( $session_data );

        if ( ! $details ) {
            return;
        }

        $timetics_formatted_date  = $details['formatted_date'];
        $timetics_formatted_time  = $details['formatted_time'];
        $timetics_duration        = $details['duration'];
        $timetics_location_label  = $details['location_label'];
        $timetics_timezone        = $details['timezone'];
        $timetics_display_timezone = $details['display_timezone'];

        include TIMETICS_PLUGIN_DIR . '/templates/woocommerce/meeting-details-checkout.php';
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

            // Get current booking status
            $current_status = $booking->get_status();

            // Only update if status has actually changed
            if ( $current_status === $booking_status ) {
                return;
            }

            // Update booking status
            // This will trigger transition_post_status, but our pair lock prevents reverse sync
            $booking->update( [ 'post_status' => $booking_status ] );

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
